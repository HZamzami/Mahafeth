<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    public function test_failed_queue_jobs_are_logged_at_critical_level(): void
    {
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Queued job failed.'
                && $context['job'] === 'App\Jobs\AnalyzePortfolioJob'
                && $context['error'] === 'boom');

        $job = $this->createStub(Job::class);
        $job->method('resolveName')->willReturn('App\Jobs\AnalyzePortfolioJob');

        event(new JobFailed('database', $job, new \RuntimeException('boom')));
    }
}
