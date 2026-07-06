<?php

namespace App\Contracts;

use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;

interface InsightGenerator
{
    /**
     * Generate a plain-language explanation of a portfolio snapshot in the
     * given locale: an executive summary plus a prioritized action plan.
     *
     * @param  list<array{name: string, target_amount: float, months: int, monthly_contribution: ?float, probability: float, probability_optimal: ?float}>  $goals
     * @return array{summary: string, recommendations: list<array{title: string, body: string, priority: string}>}
     */
    public function generate(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals = []): array;
}
