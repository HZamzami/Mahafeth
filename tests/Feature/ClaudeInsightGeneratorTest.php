<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Insights\ClaudeInsightGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ClaudeInsightGeneratorTest extends TestCase
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

    public function test_it_sends_metrics_profile_and_locale_and_parses_the_response(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => json_encode([
                        'summary' => 'Your portfolio needs attention.',
                        'recommendations' => [
                            ['title' => 'Trim AAPL', 'body' => 'Reduce to 10%.', 'priority' => 'high'],
                        ],
                    ])],
                ],
            ]),
        ]);

        $result = (new ClaudeInsightGenerator)->generate($snapshot, $profile, 'ar');

        $this->assertSame('Your portfolio needs attention.', $result['summary']);
        $this->assertSame('Trim AAPL', $result['recommendations'][0]['title']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $prompt = $body['messages'][0]['content'];

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('anthropic-version')
                && $body['model'] === config('mahafeth.ai.model')
                && $body['output_config']['format']['type'] === 'json_schema'
                && str_contains($prompt, '0.261')          // volatility
                && str_contains($prompt, 'AAPL')           // largest position
                && str_contains($prompt, 'balanced')       // risk profile
                && str_contains($prompt, 'Arabic');        // locale instruction
        });
    }

    public function test_a_connection_failure_is_retried_once_then_succeeds(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        $payload = [
            'content' => [
                ['type' => 'text', 'text' => json_encode(['summary' => 'Recovered.', 'recommendations' => []])],
            ],
        ];

        $attempts = 0;
        Http::fake(function () use (&$attempts, $payload) {
            if ($attempts++ === 0) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response($payload);
        });

        $result = (new ClaudeInsightGenerator)->generate($snapshot, $profile, 'en');

        $this->assertSame('Recovered.', $result['summary']);
        $this->assertSame(2, $attempts);
    }

    public function test_an_api_error_is_logged_with_the_anthropic_message(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ], 401),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Claude insight request failed'
                && $context['status'] === 401
                && $context['error']['message'] === 'invalid x-api-key');

        $this->expectException(RequestException::class);

        (new ClaudeInsightGenerator)->generate($snapshot, $profile, 'en');
    }

    public function test_the_prompt_contains_the_health_score_and_component_scores(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        $prompt = (new ClaudeInsightGenerator)->buildPrompt($snapshot, $profile, 'en');

        $this->assertStringContainsString('"health_score": 39', $prompt);
        $this->assertStringContainsString('investor_profile', $prompt);
        $this->assertStringContainsString('total_value_sar', $prompt);
        $this->assertStringContainsString('English', $prompt);
    }

    public function test_the_prompt_carries_goal_forecasts(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        $prompt = (new ClaudeInsightGenerator)->buildPrompt($snapshot, $profile, 'en', [
            ['name' => 'Retirement', 'target_amount' => 2_000_000.0, 'months' => 120, 'monthly_contribution' => 3000.0, 'probability' => 0.42, 'probability_optimal' => 0.61],
        ]);

        $this->assertStringContainsString('Retirement', $prompt);
        $this->assertStringContainsString('0.42', $prompt);
        $this->assertStringContainsString('probability_optimal', $prompt);
    }

    public function test_the_prompt_carries_the_shariah_constraint(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();
        $profile->update(['constraints' => ['shariah_required' => true]]);

        $prompt = (new ClaudeInsightGenerator)->buildPrompt($snapshot, $profile->fresh(), 'en');

        $this->assertStringContainsString('shariah_required', $prompt);
        $this->assertStringContainsString('constraints', $prompt);
    }

    public function test_the_prompt_omits_zakat_metrics(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();
        $snapshot->update(['metrics' => array_merge($snapshot->metrics, [
            'zakat' => ['zakat_due' => 74974.17, 'zakatable_value' => 2998966.86, 'below_nisab' => false],
        ])]);

        $prompt = (new ClaudeInsightGenerator)->buildPrompt($snapshot->fresh(), $profile, 'en');

        $this->assertStringNotContainsString('zakat', $prompt);
        $this->assertStringContainsString('0.261', $prompt);
    }

    public function test_an_unexpected_response_shape_throws(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'not json']]]),
        ]);

        $this->expectException(\RuntimeException::class);

        (new ClaudeInsightGenerator)->generate($snapshot, $profile, 'en');
    }
}
