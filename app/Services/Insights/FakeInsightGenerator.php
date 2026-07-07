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
    public function generate(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals = []): array
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

        if ($goals !== []) {
            $summary .= ' '.__('Your goal ":name" has a :probability chance of success at the current allocation.', [
                'name' => $goals[0]['name'],
                'probability' => Number::percentage($goals[0]['probability'] * 100, 0),
            ], $locale);
        }

        $recommendations = [];

        $shariah = $metrics['shariah'] ?? null;
        $shariahRequired = (bool) ($riskProfile?->constraints['shariah_required'] ?? false);

        if ($shariahRequired && $shariah !== null && $shariah['non_compliant_positions'] !== []) {
            $flagged = $shariah['non_compliant_positions'][0];

            $recommendations[] = [
                'title' => __('Replace non-compliant holdings', [], $locale),
                'body' => __(':name (:weight of your portfolio) is not Shariah-compliant, while your profile requires full compliance. Replacing it with a compliant alternative would restore the compliance component of your health score.', [
                    'name' => $flagged['name'],
                    'weight' => Number::percentage($flagged['weight'] * 100, 1),
                ], $locale),
                'priority' => 'high',
                'evidence' => [
                    ['metric' => __('Non-compliant weight', [], $locale), 'value' => Number::percentage($shariah['non_compliant_weight'] * 100, 1)],
                    ['metric' => __('Flagged position', [], $locale), 'value' => $flagged['name']],
                ],
            ];
        }

        $recommendations = [...$recommendations,
            [
                'title' => __('Reduce your largest position', [], $locale),
                'body' => __('Trimming :name toward the optimizer\'s suggested weight would meaningfully lower concentration risk.', [
                    'name' => $largest['name'] ?? __('your largest holding', [], $locale),
                ], $locale),
                'priority' => 'high',
                'evidence' => [
                    ['metric' => __('Largest position', [], $locale), 'value' => Number::percentage(($largest['weight'] ?? 0) * 100, 1)],
                    ['metric' => __('Concentration (HHI)', [], $locale), 'value' => number_format($metrics['hhi'] ?? 0, 2)],
                ],
            ],
            [
                'title' => __('Rebalance toward the efficient frontier', [], $locale),
                'body' => __('The optimal allocation of your current assets offers a better return for less risk. Review the suggested allocation on the Analytics page.', [], $locale),
                'priority' => 'medium',
                'evidence' => [
                    ['metric' => __('Efficiency gap', [], $locale), 'value' => Number::percentage(($metrics['frontier']['efficiency_gap'] ?? 0) * 100, 1)],
                    ['metric' => __('Sharpe ratio', [], $locale), 'value' => number_format($metrics['sharpe'] ?? 0, 2)],
                ],
            ],
            [
                'title' => __('Broaden diversification', [], $locale),
                'body' => __('Your holdings behave like :effective independent positions. Adding assets with low correlation to your existing ones would improve the diversification score.', [
                    'effective' => number_format($metrics['effective_holdings'] ?? 0, 1),
                ], $locale),
                'priority' => 'medium',
                'evidence' => [
                    ['metric' => __('Effective holdings', [], $locale), 'value' => number_format($metrics['effective_holdings'] ?? 0, 1)],
                    ['metric' => __('Average correlation', [], $locale), 'value' => number_format($metrics['average_correlation'] ?? 0, 2)],
                ],
            ],
        ];

        return ['summary' => $summary, 'recommendations' => $recommendations];
    }
}
