<?php

namespace App\Actions;

use App\Contracts\InsightGenerator;
use App\Models\AiInsight;
use App\Models\User;

/**
 * Generates (or regenerates) the AI explanation for a user's latest
 * portfolio snapshot in the given locale, and persists it.
 */
class GenerateInsights
{
    public function __construct(private InsightGenerator $generator) {}

    public function handle(User $user, string $locale): ?AiInsight
    {
        $snapshot = $user->latestSnapshot();

        if ($snapshot === null) {
            return null;
        }

        $result = $this->generator->generate($snapshot, $user->riskProfile, $locale);

        return AiInsight::updateOrCreate(
            ['portfolio_snapshot_id' => $snapshot->id, 'locale' => $locale],
            ['summary' => $result['summary'], 'recommendations' => $result['recommendations']],
        );
    }
}
