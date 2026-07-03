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

    'risk_free_rate' => env('MAHAFETH_RISK_FREE_RATE', 0.045),

    'benchmark_symbol' => env('MAHAFETH_BENCHMARK', 'SPY'),

    // Indices overlaid on the performance chart for comparison.
    'comparison_benchmarks' => ['SPY', 'TASI'],

    // All valuation happens in the base currency; native-currency prices are
    // converted at read time. SAR is pegged to the dollar at 3.75.
    'base_currency' => 'USD',

    'fx_rates' => [
        'USD' => 1.0,
        'SAR' => 1 / 3.75,
    ],

    // One-tailed z-score for the VaR confidence level (1.645 ≈ 95%).
    'var_confidence' => 0.95,
    'var_z_score' => 1.645,

    // Trailing window (in years) of price history used by the analyzer.
    'analysis_window_years' => 1,

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
    ],

];
