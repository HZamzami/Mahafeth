<?php

namespace App\Console\Commands;

use App\Contracts\ChatResponder;
use App\Contracts\InsightGenerator;
use App\Jobs\GenerateChatReplyJob;
use App\Jobs\GenerateInsightsJob;
use App\Services\Insights\ClaudeChatResponder;
use App\Services\Insights\ClaudeInsightGenerator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-shot health check for the AI subsystem, meant to be run on the
 * environment that is misbehaving: config, bindings, queue, egress to the
 * Claude API, and (with --live) one minimal real request per model.
 */
class AiDoctor extends Command
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    /**
     * Model families that accept the adaptive thinking + effort parameters
     * the app sends. Anything else (notably claude-haiku-*) rejects the
     * request bodies with a 400.
     */
    private const ADAPTIVE_FAMILIES = ['claude-opus-4-', 'claude-sonnet-4-6', 'claude-fable-5'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:ai-doctor {--live : Also make one minimal real API call per configured model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose the AI subsystem: config, bindings, queue, connectivity, and optionally live model pings';

    private bool $healthy = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->checkConfig();
        $this->checkModels();
        $this->checkQueue();
        $this->checkConnectivity();

        if ($this->option('live')) {
            $this->pingModel('insights', (string) config('mahafeth.ai.model'));
            $this->pingModel('chat', (string) config('mahafeth.ai.chat_model'));
        }

        $this->newLine();
        $this->components->info($this->healthy
            ? 'AI subsystem looks healthy.'
            : 'Problems found. Fix the failures above and run again.');

        return $this->healthy ? self::SUCCESS : self::FAILURE;
    }

    private function pass(string $message): void
    {
        $this->components->twoColumnDetail($message, '<fg=green>OK</>');
    }

    private function warnDetail(string $message, string $detail): void
    {
        $this->components->twoColumnDetail($message, '<fg=yellow>WARN</>');
        $this->line("         <fg=yellow>{$detail}</>");
    }

    private function failCheck(string $message, string $detail): void
    {
        $this->healthy = false;
        $this->components->twoColumnDetail($message, '<fg=red>FAIL</>');
        $this->line("         <fg=red>{$detail}</>");
    }

    private function checkConfig(): void
    {
        $key = (string) config('mahafeth.ai.api_key');
        $fake = (bool) config('mahafeth.ai.fake');

        if ($key === '') {
            $this->failCheck('ANTHROPIC_API_KEY', 'Not set. Both AI features silently fall back to the offline fake generators.');
        } else {
            $this->pass('ANTHROPIC_API_KEY ('.Str::mask($key, '*', 10).')');
        }

        if ($fake) {
            $this->warnDetail('MAHAFETH_AI_FAKE', 'Set to true: the app is deliberately using the offline fake generators.');
        } else {
            $this->pass('MAHAFETH_AI_FAKE=false');
        }

        $insights = app(InsightGenerator::class) instanceof ClaudeInsightGenerator ? 'Claude' : 'Fake';
        $chat = app(ChatResponder::class) instanceof ClaudeChatResponder ? 'Claude' : 'Fake';

        $this->components->twoColumnDetail('InsightGenerator binding', $insights);
        $this->components->twoColumnDetail('ChatResponder binding', $chat);
    }

    private function checkModels(): void
    {
        foreach (['model' => 'insights', 'chat_model' => 'chat'] as $configKey => $label) {
            $model = (string) config("mahafeth.ai.{$configKey}");

            if (Str::startsWith($model, self::ADAPTIVE_FAMILIES)) {
                $this->pass("{$label} model {$model}");
            } else {
                $this->failCheck(
                    "{$label} model {$model}",
                    'The app sends adaptive thinking (and effort for chat), which this model rejects with a 400. Use claude-sonnet-4-6 or an Opus 4.6+ model.',
                );
            }
        }
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->failCheck(
                'QUEUE_CONNECTION=sync',
                'AI jobs run inside web requests (up to 120s), saturating the server until the edge proxy returns "upstream connect error". Set QUEUE_CONNECTION=database and run a queue worker (queue:work --timeout=200).',
            );
        } else {
            $this->pass("QUEUE_CONNECTION={$connection}");
        }

        // Payload JSON escapes backslashes, so match on the base names.
        $aiJobs = [class_basename(GenerateInsightsJob::class), class_basename(GenerateChatReplyJob::class)];

        $pending = DB::table('jobs')->count();
        $stale = DB::table('jobs')->where('created_at', '<', now()->subMinutes(10)->timestamp)->count();

        if ($stale > 0) {
            $this->failCheck(
                "{$pending} queued jobs ({$stale} older than 10 minutes)",
                'Jobs are piling up: the queue worker is probably not running on this environment.',
            );
        } else {
            $this->pass("Queued jobs: {$pending}");
        }

        $failed = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->get()
            ->filter(fn (object $job): bool => Str::contains($job->payload, $aiJobs));

        if ($failed->isNotEmpty()) {
            $excerpt = Str::limit((string) str($failed->first()->exception)->before("\n"), 200);
            $this->warnDetail(
                "{$failed->count()} AI jobs failed in the last 24h",
                "Latest: {$excerpt}",
            );
        } else {
            $this->pass('No failed AI jobs in the last 24h');
        }
    }

    private function checkConnectivity(): void
    {
        try {
            // Any HTTP status proves egress works; only transport-level
            // failures (refused, DNS, TLS, timeout) matter here.
            $response = Http::timeout(10)->connectTimeout(10)->get('https://api.anthropic.com/');

            $this->pass('Egress to api.anthropic.com (HTTP '.$response->status().')');
        } catch (ConnectionException $exception) {
            $this->failCheck(
                'Egress to api.anthropic.com',
                'Cannot reach the Claude API from this environment: '.$exception->getMessage(),
            );
        }
    }

    private function pingModel(string $label, string $model): void
    {
        $started = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('mahafeth.ai.api_key'),
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout(30)
                ->connectTimeout(10)
                ->post(self::API_URL, [
                    'model' => $model,
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);
        } catch (ConnectionException $exception) {
            $this->failCheck("Live {$label} ping ({$model})", $exception->getMessage());

            return;
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($response->successful()) {
            $this->pass("Live {$label} ping ({$model}, {$ms}ms)");
        } else {
            $error = $response->json('error.message') ?? Str::limit($response->body(), 200);
            $this->failCheck(
                "Live {$label} ping ({$model}, HTTP {$response->status()})",
                (string) $error,
            );
        }
    }
}
