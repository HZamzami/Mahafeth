<?php

namespace App\Contracts;

interface FundamentalsProvider
{
    /**
     * Company profile, quarterly results, analyst consensus, and key
     * statistics for one equity symbol, or null when unavailable.
     *
     * @return ?array{
     *     profile: array{summary: ?string, sector: ?string, industry: ?string, employees: ?int, website: ?string, city: ?string, country: ?string},
     *     quarters: list<array{label: string, revenue: ?float, earnings: ?float}>,
     *     headline: array{quarterLabel: ?string, revenue: ?float, revenueChange: ?float, netIncome: ?float, netIncomeChange: ?float, eps: ?float, epsChange: ?float, netMargin: ?float},
     *     ratings: ?array{buy: int, hold: int, sell: int, total: int, consensus: ?string},
     *     priceTarget: ?array{low: float, mean: float, high: float, current: ?float},
     *     stats: array{marketCap: ?float, trailingPE: ?float, trailingEps: ?float, dividendYield: ?float, debtToEquity: ?float},
     *     currency: ?string,
     *     financialCurrency: ?string
     * }
     */
    public function fetch(string $symbol): ?array;
}
