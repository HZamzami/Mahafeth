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
                'date' => '2026-07-15',
                'items' => [
                    [
                        'type' => 'new',
                        'title' => __('Build your accounts your way'),
                        'body' => __('Add an account for each broker or bank, name it yourself, and fill it either by uploading a CSV statement or by adding stocks, crypto, and cash by hand. Open any account to see exactly what it holds. Ready-made sample portfolios now live under a clearly labelled Demo accounts section.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Try the trade before you make it'),
                        'body' => __('A What if? panel on every instrument and holding page simulates a buy or sell and shows how your health score, concentration, volatility, and correlation would shift before you commit real money.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('See your dividend rhythm'),
                        'body' => __('A new income calendar on the Holdings page charts the dividends you received month by month and projects the year ahead from positions you still hold.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Your money or the market\'s?'),
                        'body' => __('The performance chart now separates what you deposited from what the market earned, so a growing balance no longer hides a flat return behind fresh contributions.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Zakat that knows your hawl'),
                        'body' => __('Set your zakat anniversary as a Hijri date and Mahafeth counts down to it, reminds you a week ahead with your estimated amount, and lets you mark it paid for the cycle.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Purification that knows what you paid'),
                        'body' => __('Stock purification is now a ledger, not a running total: mark what you donated and the card resets, accruing only new impure income since that date. Assets with a published purification rate accrue just their impure share.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Your plan keeps watch'),
                        'body' => __('Mahafeth now compares your live allocation to your investment plan every day and raises an alert when any position drifts more than five points off target, so rebalancing becomes something the platform remembers for you.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Know why your score moved'),
                        'body' => __('When your health score changes, the score card now explains which components moved and the metric behind each one, so a drop from 74 to 68 comes with a reason instead of a mystery.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('See what moved your portfolio today'),
                        'body' => __('A new Daily Move card on the dashboard breaks each day\'s change into the holdings and currencies behind it, so you can tell at a glance whether it was Aramco, the dollar, or your own deposits.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('Clearer, warmer Arabic everywhere'),
                        'body' => __('Every Arabic string in the app was reviewed against one glossary: direct requests instead of stiff boilerplate, active voice, proper Arabic quotes, correct tanween, and one consistent term for every financial concept.'),
                    ],
                ],
            ],
            [
                'date' => '2026-07-14',
                'items' => [
                    [
                        'type' => 'new',
                        'title' => __('Take Mahafeth for a spin'),
                        'body' => __('A Try the demo button on the welcome page opens a ready-made portfolio, complete with history, alerts, and a health score. No signup, and demo accounts clean themselves up after two days.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Sign in with Face ID or fingerprint'),
                        'body' => __('Add a passkey from Settings and sign in with your face, fingerprint, or device screen lock. Faster than a password, and safer too.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Custom alerts'),
                        'body' => __('Set your own limits on volatility, concentration, drawdown, or the health score from your profile settings — Mahafeth watches them with every analysis and notifies you the moment one is crossed.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Your dashboard, even offline'),
                        'body' => __('The installed app now remembers your latest dashboard, so opening it without a connection shows your last portfolio view with a clear offline notice instead of an error page.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Your week, summarized'),
                        'body' => __('Every Sunday morning Mahafeth sends a short week-in-review: how your health score and portfolio value moved, and which alerts are active. Off by turning off alert notifications in your profile.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('See where you are signed in'),
                        'body' => __('A new Sessions section in settings lists every device signed in to your account, and one button signs out everywhere else.'),
                    ],
                    [
                        'type' => 'new',
                        'title' => __('Start your profile from a preset'),
                        'body' => __('Pick Conservative, Balanced, or Growth and the investor questionnaire fills itself, leaving you one tap from your Investment Policy Statement. Every answer stays editable.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A brand-new front door'),
                        'body' => __('The welcome page was rebuilt from scratch with a live product preview, floating health-score and alert cards, and a lot more personality. Signed-in users skip it entirely and land on their dashboard.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('The advisor writes its answers live'),
                        'body' => __('Mahafeth AI now streams its reply into the chat as it thinks, so you start reading the answer within seconds instead of waiting for the whole response.'),
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
                        'title' => __('A better-grounded AI advisor'),
                        'body' => __('The advisor answers from your actual portfolio analysis and can look up current market facts when it needs them.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('Native currency prices'),
                        'body' => __('Instruments quote in their own market currency by default, with a one-tap toggle to riyals.'),
                    ],
                ],
            ],
            [
                'date' => '2026-07-11',
                'items' => [
                    [
                        'type' => 'new',
                        'title' => __('Your holdings, in one place'),
                        'body' => __('A holdings page lists every position across your connected accounts, each opening a full instrument screen with a live market chart and the details behind it.'),
                    ],
                    [
                        'type' => 'improved',
                        'title' => __('A phone-first experience'),
                        'body' => __('A bottom tab bar, interactive charts, and native-feeling touches make Mahafeth read like an app built for your phone, not a website squeezed onto it.'),
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
