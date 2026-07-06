<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Insights\ClaudeInsightGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_the_prompt_contains_the_health_score_and_component_scores(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        $prompt = (new ClaudeInsightGenerator)->buildPrompt($snapshot, $profile, 'en');

        $this->assertStringContainsString('"health_score": 39', $prompt);
        $this->assertStringContainsString('investor_profile', $prompt);
        $this->assertStringContainsString('total_value_sar', $prompt);
        $this->assertStringContainsString('English', $prompt);
    }

    public function test_the_prompt_carries_the_shariah_constraint(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();
        $profile->update(['constraints' => ['shariah_required' => true]]);

        $prompt = (new ClaudeInsightGenerator)->buildPrompt($snapshot, $profile->fresh(), 'en');

        $this->assertStringContainsString('shariah_required', $prompt);
        $this->assertStringContainsString('constraints', $prompt);
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
