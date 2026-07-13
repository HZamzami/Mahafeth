<?php

namespace App\Support;

/**
 * The product changelog behind the "What's New" page: curated bilingual
 * release notes in user language, versioned with the code they describe.
 * Each shipped feature adds one entry here (plus its ar.json strings) in
 * the same commit. Not generated from git — commit messages are developer
 * shorthand, and untranslatable.
 */
class Changelog
{
    /**
     * Release groups, newest first. Types: new | improved | fixed.
     *
     * @return list<array{date: string, items: list<array{type: string, title: string, body: string}>}>
     */
    public static function entries(): array
    {
        return [
            [
                'date' => '2026-07-13',
                'items' => [
                    [
                        'type' => 'new',
                        'title' => __('Investment plans'),
                        'body' => __('Start investing at the right risk from day one: tell Mahafeth your starting amount and it proposes an allocation matched to your investor profile, with a growth projection and a concrete buy list.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('The Explore page'),
                        'body' => __('Stock search got its own home with today\'s top gainers, losers, and most active movers, plus the instruments you recently viewed.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Real company disclosures'),
                        'body' => __('Quarterly reports, annual reports, and material events now come live from official SEC EDGAR filings, linked to the actual documents.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('News you can trust'),
                        'body' => __('The news feed now shows live market headlines only — every story links to its source.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('Faster everywhere'),
                        'body' => __('Pages preload as you reach for them, the app answers every tap instantly, and the holdings list renders more than ten times faster.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A calmer look'),
                        'body' => __('A brighter, cooler light theme and a softer dark theme that is easier on your eyes at night.'),
                    ],
                ],
            ],
            [
                'date' => '2026-07-12',
                'items' => [
                    [
                        'type' => 'new',
                        'title' => __('Financials & analyst ratings on stock pages'),
                        'body' => __('Every stock page now shows quarterly revenue and profit, the company profile, analyst buy/hold/sell consensus, and 12-month price targets.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Activity log'),
                        'body' => __('One place for everything that happens in your account: alerts, syncs, health-score changes, and security events.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('A better-grounded AI advisor'),
                        'body' => __('The advisor answers from your actual portfolio analysis and can look up current market facts when it needs them.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('Native currency prices'),
                        'body' => __('Instruments quote in their own market currency by default, with a one-tap toggle to riyals.'),
                    ],
                    [
                        'type' => 'fixed',
                        'title' => __('Arabic polish'),
                        'body' => __('Cleaner Arabic across sign-in, registration, and market pages.'),
                    ],
                ],
            ],
        ];
    }

    /**
     * The date of the newest release group, for the unseen-dot check.
     */
    public static function latestDate(): string
    {
        return static::entries()[0]['date'];
    }
}
