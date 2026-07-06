<?php

namespace App\Contracts;

use App\Models\Institution;
use Carbon\CarbonInterface;

interface OpenBankingProvider
{
    /**
     * Fetch the investment accounts held at the given institution.
     *
     * @return list<array{external_id: string, name: string, type: string, currency: string}>
     */
    public function fetchAccounts(Institution $institution): array;

    /**
     * Fetch the holdings of an account, including full asset metadata.
     *
     * @return list<array{
     *     asset: array{symbol: string, name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status?: string},
     *     quantity: float,
     *     avg_cost: float
     * }>
     */
    public function fetchHoldings(Institution $institution, string $accountExternalId): array;

    /**
     * Fetch the transaction history of an account.
     *
     * @return list<array{symbol: ?string, type: string, quantity: ?float, price: ?float, amount: float, executed_at: CarbonInterface}>
     */
    public function fetchTransactions(Institution $institution, string $accountExternalId): array;

    /**
     * Fetch daily closing prices for the given symbols.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, float>> symbol => [Y-m-d date => close]
     */
    public function fetchPrices(array $symbols, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Benchmark index assets available for comparison.
     *
     * @return list<array{symbol: string, name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status?: string}>
     */
    public function benchmarks(): array;
}
