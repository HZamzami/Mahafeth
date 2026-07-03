<?php

namespace App\Services\Insights;

use App\Contracts\InsightGenerator;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use Illuminate\Support\Number;

/**
 * Deterministic, offline insight generator. Used in tests and as a demo
 * fallback (MAHAFETH_AI_FAKE=true) so a live demo can never fail on a
 * network call. Derives its text directly from the snapshot metrics.
 */
class FakeInsightGenerator implements InsightGenerator
{
    public function generate(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale): array
    {
        $metrics = $snapshot->metrics ?? [];
        $largest = $metrics['largest_position'] ?? null;

        $summary = __('Your portfolio health score is :score/100. Your largest position, :name, makes up :weight of the unified portfolio, and your annualized volatility of :volatility is a key driver of the score.', [
            'score' => $snapshot->health_score ?? '—',
            'name' => $largest['name'] ?? __('your largest holding'),
            'weight' => Number::percentage(($largest['weight'] ?? 0) * 100, 1),
            'volatility' => Number::percentage(($metrics['volatility'] ?? 0) * 100, 1),
        ], $locale);

        if ($riskProfile !== null) {
            $summary .= ' '.__('Your investor profile targets :target volatility, so risk alignment is a priority.', [
                'target' => Number::percentage($riskProfile->target_volatility * 100, 1),
            ], $locale);
        }

        $recommendations = [
            [
                'title' => __('Reduce your largest position', [], $locale),
                'body' => __('Trimming :name toward the optimizer\'s suggested weight would meaningfully lower concentration risk.', [
                    'name' => $largest['name'] ?? __('your largest holding', [], $locale),
                ], $locale),
                'priority' => 'high',
            ],
            [
                'title' => __('Rebalance toward the efficient frontier', [], $locale),
                'body' => __('The optimal allocation of your current assets offers a better return for less risk. Review the suggested allocation on the Analytics page.', [], $locale),
                'priority' => 'medium',
            ],
            [
                'title' => __('Broaden diversification', [], $locale),
                'body' => __('Your holdings behave like :effective independent positions. Adding assets with low correlation to your existing ones would improve the diversification score.', [
                    'effective' => number_format($metrics['effective_holdings'] ?? 0, 1),
                ], $locale),
                'priority' => 'medium',
            ],
        ];

        return ['summary' => $summary, 'recommendations' => $recommendations];
    }
}
