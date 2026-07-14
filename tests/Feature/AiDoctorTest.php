<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_configuration_passes(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-test-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'database']);
        Http::fake(['api.anthropic.com/*' => Http::response(['ok' => true])]);

        $this->artisan('mahafeth:ai-doctor')
            ->expectsOutputToContain('AI subsystem looks healthy.')
            ->assertSuccessful();
    }

    public function test_a_missing_api_key_reports_the_fake_fallback(): void
    {
        config(['mahafeth.ai.api_key' => null]);
        Http::fake(['api.anthropic.com/*' => Http::response(['ok' => true])]);

        $this->artisan('mahafeth:ai-doctor')
            ->expectsOutputToContain('fall back to the offline fake generators')
            ->expectsOutputToContain('Fake')
            ->assertFailed();
    }

    public function test_a_sync_queue_is_flagged_as_the_edge_error_cause(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-test-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'sync']);
        Http::fake(['api.anthropic.com/*' => Http::response(['ok' => true])]);

        $this->artisan('mahafeth:ai-doctor')
            ->expectsOutputToContain('upstream connect error')
            ->assertFailed();
    }

    public function test_an_incompatible_chat_model_is_flagged(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-test-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'database', 'mahafeth.ai.chat_model' => 'claude-haiku-4-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['ok' => true])]);

        $this->artisan('mahafeth:ai-doctor')
            ->expectsOutputToContain('rejects with a 400')
            ->assertFailed();
    }

    public function test_unreachable_api_is_reported_as_an_egress_failure(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-test-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'database']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('mahafeth:ai-doctor')
            ->expectsOutputToContain('Cannot reach the Claude API')
            ->assertFailed();
    }

    public function test_stale_queued_jobs_point_at_a_missing_worker(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-test-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'database']);
        Http::fake(['api.anthropic.com/*' => Http::response(['ok' => true])]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\GenerateInsightsJob"}',
            'attempts' => 0,
            'available_at' => now()->subMinutes(30)->timestamp,
            'created_at' => now()->subMinutes(30)->timestamp,
        ]);

        $this->artisan('mahafeth:ai-doctor')
            ->expectsOutputToContain('queue worker is probably not running')
            ->assertFailed();
    }

    public function test_the_live_flag_pings_both_configured_models(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-test-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'database']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

        $this->artisan('mahafeth:ai-doctor --live')
            ->expectsOutputToContain('Live insights ping (claude-opus-4-8')
            ->expectsOutputToContain('Live chat ping (claude-sonnet-4-6')
            ->assertSuccessful();

        Http::assertSentCount(3); // egress probe + two pings
    }

    public function test_a_live_ping_failure_surfaces_the_api_error_message(): void
    {
        config(['mahafeth.ai.api_key' => 'sk-ant-bad-key-12345', 'mahafeth.ai.fake' => false, 'queue.default' => 'database']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ], 401),
            'api.anthropic.com/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('mahafeth:ai-doctor --live')
            ->expectsOutputToContain('invalid x-api-key')
            ->assertFailed();
    }
}
