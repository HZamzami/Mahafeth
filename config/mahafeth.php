<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Analytics Engine
    |--------------------------------------------------------------------------
    |
    | Assumptions used across the portfolio analytics services. The risk-free
    | rate is annualized; the benchmark symbol must exist as an is_benchmark
    | asset with price history.
    |
    */

    // Tracks the SAMA repo rate (4.25% since December 2025).
    'risk_free_rate' => env('MAHAFETH_RISK_FREE_RATE', 0.0425),

    'benchmark_symbol' => env('MAHAFETH_BENCHMARK', 'TASI'),

    // Indices overlaid on the performance chart for comparison.
    'comparison_benchmarks' => ['TASI', 'SPY'],

    // All valuation happens in the base currency; native-currency prices are
    // converted at read time. SAR is pegged to the dollar at 3.75.
    'base_currency' => 'SAR',

    'fx_rates' => [
        'SAR' => 1.0,
        'USD' => 3.75,
    ],

    // Health-score drop (in points, between consecutive analyses) that
    // triggers an alert notification; the minimum order value emitted by
    // the rebalancing planner.
    'alert_score_drop_threshold' => env('MAHAFETH_ALERT_SCORE_DROP', 5),
    'min_trade_value' => env('MAHAFETH_MIN_TRADE_VALUE', 500),

    // Open Banking consent lifetime (KSA framework convention: 90 days)
    // and the account-information scopes requested at authorization.
    'consent_ttl_days' => env('MAHAFETH_CONSENT_TTL_DAYS', 90),
    'consent_scopes' => ['accounts', 'balances', 'transactions'],

    // One-tailed z-score for the VaR confidence level (1.645 ≈ 95%).
    'var_confidence' => 0.95,
    'var_z_score' => 1.645,

    // Trailing window (in years) of price history used by the analyzer.
    'analysis_window_years' => 1,

    // Zakat on the unified portfolio: rate applies to zakatable wealth at
    // market value once it meets the nisab threshold (SAR, approximates
    // the value of 85 grams of gold; adjust as the gold price moves).
    'zakat' => [
        'rate' => env('MAHAFETH_ZAKAT_RATE', 0.025),
        'nisab' => env('MAHAFETH_ZAKAT_NISAB', 35000.0),
    ],

    // Deterministic stress scenarios replayed on the live portfolio: each
    // combines a broad market shock with harder shocks on targeted sectors
    // or asset classes. Labels are translation keys resolved in the view.
    'stress_scenarios' => [
        'oil_correction' => [
            'label' => 'Oil price correction',
            'market' => -0.05,
            'targets' => [
                ['group' => 'sector', 'value' => 'Energy', 'shock' => -0.20],
            ],
        ],
        'rate_shock' => [
            'label' => 'Interest rate shock',
            'market' => -0.06,
            'targets' => [
                ['group' => 'sector', 'value' => 'Financials', 'shock' => -0.12],
            ],
        ],
        'tech_correction' => [
            'label' => 'Tech correction',
            'market' => -0.08,
            'targets' => [
                ['group' => 'sector', 'value' => 'Information Technology', 'shock' => -0.25],
            ],
        ],
        'crypto_winter' => [
            'label' => 'Crypto winter',
            'market' => -0.03,
            'targets' => [
                ['group' => 'asset_class', 'value' => 'crypto', 'shock' => -0.45],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Portfolio Health Score
    |--------------------------------------------------------------------------
    |
    | Component weights of the composite health score. Must sum to 1.
    |
    */

    'health_weights' => [
        'diversification' => 0.25,
        'risk_alignment' => 0.20,
        'correlation' => 0.15,
        'performance' => 0.15,
        'drawdown' => 0.15,
        'concentration' => 0.10,
    ],

    // Weight set used when the investor's IPS requires Shariah-compliant
    // investing: a seventh component enters and the rest are renormalized.
    'health_weights_shariah' => [
        'diversification' => 0.20,
        'risk_alignment' => 0.17,
        'correlation' => 0.13,
        'performance' => 0.13,
        'drawdown' => 0.13,
        'concentration' => 0.09,
        'shariah' => 0.15,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Explanation Layer
    |--------------------------------------------------------------------------
    |
    | The insight generator translates snapshot metrics into plain-language
    | summaries and recommendations via the Claude API. With `fake` enabled
    | (or no API key configured) a deterministic local generator is used —
    | handy for tests and as a demo fallback that cannot fail on network.
    |
    */

    'ai' => [
        'fake' => env('MAHAFETH_AI_FAKE', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MAHAFETH_AI_MODEL', 'claude-opus-4-8'),
        'max_tokens' => 8192,
        'timeout' => 120,
        'chat_max_tokens' => 1024,
        'chat_timeout' => 60,
    ],

];
