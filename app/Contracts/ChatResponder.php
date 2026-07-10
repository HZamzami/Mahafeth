<?php

namespace App\Contracts;

use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;

interface ChatResponder
{
    /**
     * Answer the latest user message, grounded in the portfolio snapshot.
     *
     * @param  list<array{name: string, target_amount: float, months: int, monthly_contribution: ?float, probability: float, probability_optimal: ?float}>  $goals
     * @param  list<array{role: string, content: string}>  $history  oldest first, ending with the newest user message
     */
    public function respond(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals, array $history): string;
}
