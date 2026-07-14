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
                'date' => '2026-07-14',
                'items' => [
                    [
                        'type' => 'new',
                        'title' => __('Start your profile from a preset'),
                        'body' => __('Pick Conservative, Balanced, or Growth and the investor questionnaire fills itself, leaving you one tap from your Investment Policy Statement. Every answer stays editable.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('Analytics, ready when you are'),
                        'body' => __('The analytics page now remembers its heavy calculations between visits, so charts appear instantly and refresh automatically after every new sync or analysis.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A more polished app'),
                        'body' => __('Across the app, cards now settle in one after another, totals count up when a page opens, and cards respond gently under the pointer. Same numbers, better presence.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A brand-new front door'),
                        'body' => __('The welcome page was rebuilt from scratch with a live product preview, floating health-score and alert cards, and a lot more personality. Signed-in users skip it entirely and land on their dashboard.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Sign in with Face ID or fingerprint'),
                        'body' => __('Add a passkey from Settings and sign in with your face, fingerprint, or device screen lock. Faster than a password, and safer too.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Your dashboard, even offline'),
                        'body' => __('The installed app now remembers your latest dashboard, so opening it without a connection shows your last portfolio view with a clear offline notice instead of an error page.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A faster-feeling dashboard'),
                        'body' => __('Heavier cards now load in the background behind smooth placeholders, so the dashboard and analytics pages appear instantly instead of waiting for every chart.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('You stay signed in'),
                        'body' => __('Mahafeth now keeps you signed in on your device, so opening the app takes you straight to your portfolio instead of the login screen.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Custom alerts'),
                        'body' => __('Set your own limits on volatility, concentration, drawdown, or the health score from your profile settings — Mahafeth watches them with every analysis and notifies you the moment one is crossed.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('The advisor writes its answers live'),
                        'body' => __('Mahafeth AI now streams its reply into the chat as it thinks, so you start reading the answer within seconds instead of waiting for the whole response.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A snappier AI advisor'),
                        'body' => __('Your question now appears in the chat instantly and the answer is composed in the background — keep browsing while Mahafeth AI thinks, and retry with one tap if it fails.'),
                    ],
                    [
                        'type' => 'fixed',
                        'title' => __('More reliable insight generation'),
                        'body' => __('Generating insights no longer fails after a long wait, and asking Mahafeth AI from a stock or news item no longer sends the question twice.'),
                    ],
                    [
                        'type' => 'fixed',
                        'title' => __('Native-app polish'),
                        'body' => __('On installed phones the bottom bar now keeps proper spacing from the page, and switching pages visibly acknowledges every tap — the pressed tab tints, the page dims, and a progress strip shows below the status bar.'),
                    ],
                    [
                        'type' => 'fixed',
                        'title' => __('Stability sweep across the app'),
                        'body' => __('Greetings now follow Saudi time, a delisted holding can no longer break your portfolio analysis, and every page was verified against empty accounts and Arabic.'),
                    ],
                ],
            ],
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
