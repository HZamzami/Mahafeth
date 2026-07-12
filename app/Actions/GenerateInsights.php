<?php

namespace App\Actions;

use App\Contracts\InsightGenerator;
use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\AiInsight;
use App\Models\User;
use App\Services\Insights\PortfolioContext;

/**
 * Generates (or regenerates) the AI explanation for a user's latest
 * portfolio snapshot in the given locale, and persists it.
 */
class GenerateInsights
{
    public function __construct(
        private InsightGenerator $generator,
        private PortfolioContext $context,
    ) {}

    public function handle(User $user, string $locale): ?AiInsight
    {
        $snapshot = $user->latestSnapshot();

        if ($snapshot === null) {
            return null;
        }

        $result = $this->generator->generate(
            $snapshot,
            $user->riskProfile,
            $locale,
            $this->context->goals($user, $snapshot),
        );

        $insight = AiInsight::updateOrCreate(
            ['portfolio_snapshot_id' => $snapshot->id, 'locale' => $locale],
            ['summary' => $result['summary'], 'recommendations' => $result['recommendations']],
        );

        // Record that a regeneration ran even when the model returned the
        // identical text, so staleness checks against the snapshot's
        // updated_at stay truthful.
        $insight->touch();

        ActivityEvent::record($user, ActivityType::InsightGenerated);

        return $insight;
    }
}
