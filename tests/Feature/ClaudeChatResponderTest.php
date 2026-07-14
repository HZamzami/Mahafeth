<?php

namespace Tests\Feature;

use App\Actions\GenerateChatReply;
use App\Contracts\ChatResponder;
use App\Models\AiChatMessage;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Insights\ClaudeChatResponder;
use App\Services\Insights\FakeChatResponder;
use App\Services\Insights\PortfolioContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeChatResponderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: PortfolioSnapshot, 1: RiskProfile}
     */
    private function snapshotAndProfile(): array
    {
        $user = User::factory()->create();
        $profile = RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $snapshot = PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'health_score' => 39,
            'metrics' => [
                'volatility' => 0.261,
                'sharpe' => -0.51,
                'largest_position' => ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'weight' => 0.365],
                'effective_holdings' => 4.4,
            ],
        ]);

        return [$snapshot, $profile];
    }

    public function test_the_fake_responder_is_bound_when_no_api_key_is_configured(): void
    {
        config(['mahafeth.ai.api_key' => null, 'mahafeth.ai.fake' => false]);

        $this->assertInstanceOf(FakeChatResponder::class, app(ChatResponder::class));
    }

    /**
     * Build an SSE body the way the Messages API streams it, interleaving
     * thinking and web-search events between the text deltas.
     *
     * @param  list<string>  $textDeltas
     */
    private function sseBody(array $textDeltas): string
    {
        $events = [
            'event: message_start',
            'data: '.json_encode(['type' => 'message_start', 'message' => ['role' => 'assistant']]),
            'event: content_block_delta',
            'data: '.json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'pondering…']]),
            'event: content_block_start',
            'data: '.json_encode(['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'server_tool_use', 'name' => 'web_search']]),
        ];

        foreach ($textDeltas as $index => $text) {
            $events[] = 'event: content_block_delta';
            $events[] = 'data: '.json_encode(['type' => 'content_block_delta', 'index' => 2 + $index, 'delta' => ['type' => 'text_delta', 'text' => $text]]);
        }

        $events[] = 'event: message_stop';
        $events[] = 'data: '.json_encode(['type' => 'message_stop']);

        return implode("\n", $events)."\n";
    }

    public function test_it_sends_the_portfolio_grounded_conversation_and_parses_the_streamed_reply(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        config(['mahafeth.ai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->sseBody(['Your volatility ', 'is 26.1%.'])),
        ]);

        $reply = app(ClaudeChatResponder::class)->respond($snapshot, $profile, 'ar', [], [
            ['role' => 'user', 'content' => 'What is my risk?'],
        ]);

        $this->assertSame('Your volatility is 26.1%.', $reply);

        Http::assertSent(function (Request $request) use ($snapshot): bool {
            $body = $request->data();
            $system = collect($body['system']);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('anthropic-version')
                && $body['model'] === config('mahafeth.ai.chat_model')
                && $body['max_tokens'] === (int) config('mahafeth.ai.chat_max_tokens')
                && $body['thinking'] === ['type' => 'adaptive']
                && $body['stream'] === true
                && ($body['tools'][0]['type'] ?? null) === 'web_search_20260209'
                && ($body['tools'][0]['max_uses'] ?? null) === 3
                && $body['messages'] === [['role' => 'user', 'content' => 'What is my risk?']]
                && str_contains($system->first()['text'], 'Arabic')
                && str_contains($system->first()['text'], $snapshot->as_of->toDateString())
                && str_contains($system->last()['text'], '0.261')
                && ($system->last()['cache_control']['type'] ?? null) === 'ephemeral';
        });
    }

    public function test_streaming_reports_progress_with_growing_text(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        config(['mahafeth.ai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        // Deltas above the flush threshold so every one reports progress.
        $first = str_repeat('a', 100);
        $second = str_repeat('b', 100);
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->sseBody([$first, $second])),
        ]);

        $seen = [];
        $reply = app(ClaudeChatResponder::class)->respond($snapshot, $profile, 'en', [], [
            ['role' => 'user', 'content' => 'hello'],
        ], function (string $partial) use (&$seen): void {
            $seen[] = $partial;
        });

        $this->assertSame($first.$second, $reply);
        $this->assertSame([$first, $first.$second], $seen);
    }

    public function test_an_empty_stream_throws(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        config(['mahafeth.ai.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody([]))]);

        $this->expectException(\RuntimeException::class);

        app(ClaudeChatResponder::class)->respond($snapshot, $profile, 'en', [], [
            ['role' => 'user', 'content' => 'hello'],
        ]);
    }

    public function test_the_fake_responder_streams_progressive_slices(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        $seen = [];
        $reply = (new FakeChatResponder)->respond($snapshot, $profile, 'en', [], [
            ['role' => 'user', 'content' => 'What is my risk?'],
        ], function (string $partial) use (&$seen): void {
            $seen[] = $partial;
        });

        $this->assertCount(2, $seen);
        $this->assertStringStartsWith($seen[0], $seen[1]);
        $this->assertStringStartsWith($seen[1], $reply);
        $this->assertTrue(mb_strlen($seen[0]) < mb_strlen($seen[1]));
    }

    public function test_the_history_window_caps_the_messages_sent_to_the_model(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();
        $user = $snapshot->user;

        AiChatMessage::factory()->count(15)->create(['user_id' => $user->id]);
        AiChatMessage::factory()->assistant()->count(15)->create(['user_id' => $user->id]);

        $spy = new class implements ChatResponder
        {
            public array $history = [];

            public function respond(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals, array $history, ?\Closure $onProgress = null): string
            {
                $this->history = $history;

                return 'ok';
            }
        };

        $user->chatMessages()->create(['role' => 'user', 'content' => 'one more question', 'locale' => 'en']);
        (new GenerateChatReply($spy, app(PortfolioContext::class)))->handle($user, 'en');

        $this->assertCount(20, $spy->history);
        $this->assertSame('one more question', end($spy->history)['content']);
    }
}
