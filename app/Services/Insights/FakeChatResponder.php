<?php

namespace App\Services\Insights;

use App\Contracts\ChatResponder;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use Illuminate\Support\Number;

/**
 * Deterministic, offline chat responder. Used in tests and as a demo
 * fallback (MAHAFETH_AI_FAKE=true) so a live demo can never fail on a
 * network call. Routes on keywords in the latest user message and
 * derives its answers directly from the snapshot metrics.
 */
class FakeChatResponder implements ChatResponder
{
    public function respond(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals, array $history): string
    {
        $metrics = $snapshot->metrics ?? [];
        $message = mb_strtolower(end($history)['content'] ?? '');

        if ($this->mentions($message, ['health', 'score', 'درجة', 'صحة'])) {
            $components = collect($snapshot->component_scores ?? [])->sort();

            return __('Your portfolio health score is :score/100. The weakest component is :component at :value/100 — improving it moves the overall score the most.', [
                'score' => $snapshot->health_score ?? '—',
                'component' => __(ucfirst(str_replace('_', ' ', (string) $components->keys()->first())), [], $locale),
                'value' => $components->first() ?? '—',
            ], $locale);
        }

        if ($this->mentions($message, ['risk', 'var', 'loss', 'مخاطر', 'خسارة'])) {
            $largest = $metrics['largest_position'] ?? null;

            return __('Your annualized volatility is :volatility and the potential loss at 95% confidence (VaR) is :var. Your largest position, :name, is :weight of the portfolio and drives much of that risk.', [
                'volatility' => Number::percentage(($metrics['volatility'] ?? 0) * 100, 1),
                'var' => Number::percentage(($metrics['var_95'] ?? 0) * 100, 1),
                'name' => $largest['name'] ?? __('your largest holding', [], $locale),
                'weight' => Number::percentage(($largest['weight'] ?? 0) * 100, 1),
            ], $locale);
        }

        if ($this->mentions($message, ['diversif', 'correlat', 'تنويع', 'ارتباط'])) {
            return __('Your holdings behave like :effective independent positions, with an average correlation of :correlation. Adding assets that move differently from your existing ones is the most direct way to improve diversification.', [
                'effective' => number_format($metrics['effective_holdings'] ?? 0, 1),
                'correlation' => number_format($metrics['average_correlation'] ?? 0, 2),
            ], $locale);
        }

        if ($this->mentions($message, ['recommendation', 'how do i', 'توصية', 'كيف'])) {
            return __('Start on the Analytics page: the suggested allocation there shows the exact trades that move you toward the optimal mix, closing an efficiency gap of :gap.', [
                'gap' => Number::percentage(($metrics['frontier']['efficiency_gap'] ?? 0) * 100, 1),
            ], $locale);
        }

        return __('Your portfolio health score is :score/100. Ask me about your risk, your diversification, or any recommendation and I will explain it using your own numbers.', [
            'score' => $snapshot->health_score ?? '—',
        ], $locale);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function mentions(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
