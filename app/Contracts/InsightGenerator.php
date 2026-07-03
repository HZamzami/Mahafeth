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
     * @return array{summary: string, recommendations: list<array{title: string, body: string, priority: string}>}
     */
    public function generate(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale): array;
}
