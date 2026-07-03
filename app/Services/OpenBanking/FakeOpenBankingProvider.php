<?php

namespace App\Services\OpenBanking;

use App\Contracts\OpenBankingProvider;
use App\Models\Institution;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Simulated Open Banking provider used until real aggregator APIs are
 * integrated. Returns a fixed, realistic catalog of accounts and holdings
 * per institution slug, and reproducible GBM price series.
 *
 * The demo data is intentionally imperfect — tech-heavy with an oversized
 * Apple position — so every analyzer downstream has something to report.
 */
class FakeOpenBankingProvider implements OpenBankingProvider
{
    /**
     * Asset metadata and price-simulation parameters, keyed by symbol.
     *
     * @var array<string, array{name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, start: float, drift: float, vol: float, loading: float}>
     */
    private const ASSETS = [
        'AAPL' => ['name' => 'Apple Inc.', 'name_ar' => 'آبل', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'start' => 130.0, 'drift' => 0.18, 'vol' => 0.28, 'loading' => 0.80],
        'MSFT' => ['name' => 'Microsoft Corporation', 'name_ar' => 'مايكروسوفت', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'start' => 250.0, 'drift' => 0.16, 'vol' => 0.26, 'loading' => 0.80],
        'NVDA' => ['name' => 'NVIDIA Corporation', 'name_ar' => 'إنفيديا', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'start' => 180.0, 'drift' => 0.35, 'vol' => 0.45, 'loading' => 0.75],
        'GOOGL' => ['name' => 'Alphabet Inc.', 'name_ar' => 'ألفابت', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'start' => 100.0, 'drift' => 0.14, 'vol' => 0.30, 'loading' => 0.78],
        '2222.SR' => ['name' => 'Saudi Aramco', 'name_ar' => 'أرامكو السعودية', 'asset_class' => 'equity', 'sector' => 'Energy', 'country' => 'SA', 'currency' => 'SAR', 'start' => 8.2, 'drift' => 0.08, 'vol' => 0.18, 'loading' => 0.35],
        '1120.SR' => ['name' => 'Al Rajhi Bank', 'name_ar' => 'مصرف الراجحي', 'asset_class' => 'equity', 'sector' => 'Financials', 'country' => 'SA', 'currency' => 'SAR', 'start' => 20.0, 'drift' => 0.10, 'vol' => 0.22, 'loading' => 0.40],
        '7010.SR' => ['name' => 'stc Group', 'name_ar' => 'مجموعة stc', 'asset_class' => 'equity', 'sector' => 'Telecom', 'country' => 'SA', 'currency' => 'SAR', 'start' => 10.5, 'drift' => 0.07, 'vol' => 0.20, 'loading' => 0.35],
        'BTC' => ['name' => 'Bitcoin', 'name_ar' => 'بيتكوين', 'asset_class' => 'crypto', 'sector' => null, 'country' => null, 'currency' => 'USD', 'start' => 28000.0, 'drift' => 0.40, 'vol' => 0.65, 'loading' => 0.35],
        'ETH' => ['name' => 'Ethereum', 'name_ar' => 'إيثيريوم', 'asset_class' => 'crypto', 'sector' => null, 'country' => null, 'currency' => 'USD', 'start' => 1800.0, 'drift' => 0.35, 'vol' => 0.75, 'loading' => 0.40],
        'SPY' => ['name' => 'S&P 500 Index', 'name_ar' => 'مؤشر ستاندرد آند بورز 500', 'asset_class' => 'fund', 'sector' => null, 'country' => 'US', 'currency' => 'USD', 'start' => 380.0, 'drift' => 0.10, 'vol' => 0.16, 'loading' => 1.00],
        'TASI' => ['name' => 'Tadawul All Share Index', 'name_ar' => 'مؤشر تداول العام', 'asset_class' => 'fund', 'sector' => null, 'country' => 'SA', 'currency' => 'SAR', 'start' => 11000.0, 'drift' => 0.08, 'vol' => 0.14, 'loading' => 0.50],
    ];

    /**
     * Accounts and holdings per institution slug.
     *
     * @var array<string, array{accounts: list<array{external_id: string, name: string, type: string, currency: string}>, holdings: array<string, list<array{symbol: string, quantity: float, avg_cost: float}>>}>
     */
    private const INSTITUTIONS = [
        'derayah' => [
            'accounts' => [
                ['external_id' => 'DRY-001', 'name' => 'Derayah Global Brokerage', 'type' => 'brokerage', 'currency' => 'USD'],
            ],
            'holdings' => [
                'DRY-001' => [
                    ['symbol' => 'AAPL', 'quantity' => 1900.0, 'avg_cost' => 148.30],
                    ['symbol' => 'MSFT', 'quantity' => 260.0, 'avg_cost' => 262.10],
                    ['symbol' => 'NVDA', 'quantity' => 180.0, 'avg_cost' => 205.75],
                    ['symbol' => 'GOOGL', 'quantity' => 300.0, 'avg_cost' => 104.60],
                ],
            ],
        ],
        'alrajhi-capital' => [
            'accounts' => [
                ['external_id' => 'ARC-001', 'name' => 'Al Rajhi Capital Local Shares', 'type' => 'brokerage', 'currency' => 'SAR'],
            ],
            'holdings' => [
                'ARC-001' => [
                    ['symbol' => '2222.SR', 'quantity' => 1200.0, 'avg_cost' => 8.05],
                    ['symbol' => '1120.SR', 'quantity' => 900.0, 'avg_cost' => 19.40],
                    ['symbol' => '7010.SR', 'quantity' => 700.0, 'avg_cost' => 10.90],
                ],
            ],
        ],
        'rain' => [
            'accounts' => [
                ['external_id' => 'RAIN-001', 'name' => 'Rain Crypto Wallet', 'type' => 'crypto', 'currency' => 'USD'],
            ],
            'holdings' => [
                'RAIN-001' => [
                    ['symbol' => 'BTC', 'quantity' => 1.4, 'avg_cost' => 30500.00],
                    ['symbol' => 'ETH', 'quantity' => 12.0, 'avg_cost' => 1950.00],
                ],
            ],
        ],
    ];

    public function __construct(private PriceSeriesGenerator $priceSeriesGenerator) {}

    public function fetchAccounts(Institution $institution): array
    {
        return self::INSTITUTIONS[$institution->slug]['accounts'] ?? [];
    }

    public function fetchHoldings(Institution $institution, string $accountExternalId): array
    {
        $holdings = self::INSTITUTIONS[$institution->slug]['holdings'][$accountExternalId] ?? [];

        return array_map(fn (array $holding): array => [
            'asset' => $this->assetMetadata($holding['symbol']),
            'quantity' => $holding['quantity'],
            'avg_cost' => $holding['avg_cost'],
        ], $holdings);
    }

    public function fetchTransactions(Institution $institution, string $accountExternalId): array
    {
        $transactions = [];

        foreach (self::INSTITUTIONS[$institution->slug]['holdings'][$accountExternalId] ?? [] as $holding) {
            // Two deterministic buy lots per holding, split 60/40 around the average cost.
            foreach ([[0.6, 0.97, 420], [0.4, 1.05, 160]] as [$portion, $priceFactor, $daysAgo]) {
                $quantity = round($holding['quantity'] * $portion, 8);
                $price = round($holding['avg_cost'] * $priceFactor, 4);

                $transactions[] = [
                    'symbol' => $holding['symbol'],
                    'type' => 'buy',
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount' => round($quantity * $price, 4),
                    'executed_at' => Carbon::now()->subDays($daysAgo)->startOfDay(),
                ];
            }
        }

        return $transactions;
    }

    public function fetchPrices(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        $series = [];

        foreach ($symbols as $symbol) {
            $params = self::ASSETS[$symbol] ?? null;

            if ($params === null) {
                continue;
            }

            $series[$symbol] = $this->priceSeriesGenerator->generate(
                symbol: $symbol,
                from: $from,
                to: $to,
                startPrice: $params['start'],
                drift: $params['drift'],
                volatility: $params['vol'],
                factorLoading: $params['loading'],
            );
        }

        return $series;
    }

    public function benchmarks(): array
    {
        return [
            $this->assetMetadata('SPY'),
            $this->assetMetadata('TASI'),
        ];
    }

    /**
     * @return array{symbol: string, name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string}
     */
    private function assetMetadata(string $symbol): array
    {
        $params = self::ASSETS[$symbol];

        return [
            'symbol' => $symbol,
            'name' => $params['name'],
            'name_ar' => $params['name_ar'],
            'asset_class' => $params['asset_class'],
            'sector' => $params['sector'],
            'country' => $params['country'],
            'currency' => $params['currency'],
        ];
    }
}
