<?php

namespace App\Services\OpenBanking;

use App\Contracts\OpenBankingProvider;
use App\Models\Institution;
use App\Services\Prices\SimulatedPriceProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Simulated Open Banking provider used until real aggregator APIs are
 * integrated. Returns a fixed, realistic catalog of accounts and holdings
 * per institution slug, and reproducible GBM price series.
 *
 * The demo data is tech-tilted (Apple leads the brokerage account) so the
 * analyzers have real findings to report, without wrecking the score.
 */
class FakeOpenBankingProvider implements OpenBankingProvider
{
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
                    ['symbol' => 'AAPL', 'quantity' => 420.0, 'avg_cost' => 148.30],
                    ['symbol' => 'MSFT', 'quantity' => 260.0, 'avg_cost' => 262.10],
                    ['symbol' => 'NVDA', 'quantity' => 180.0, 'avg_cost' => 205.75],
                    ['symbol' => 'GOOGL', 'quantity' => 300.0, 'avg_cost' => 104.60],
                    ['symbol' => 'JPM', 'quantity' => 220.0, 'avg_cost' => 128.40],
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
        'alinma-bank' => [
            'accounts' => [
                ['external_id' => 'INMA-001', 'name' => 'Alinma Current Account', 'type' => 'cash', 'currency' => 'SAR'],
            ],
            'holdings' => [
                'INMA-001' => [
                    ['symbol' => 'CASH-SAR', 'quantity' => 185000.0, 'avg_cost' => 1.0],
                ],
            ],
        ],
    ];

    public function __construct(
        private SimulatedPriceProvider $priceProvider,
        private AssetCatalog $assetCatalog,
    ) {}

    public function fetchAccounts(Institution $institution): array
    {
        return self::INSTITUTIONS[$institution->slug]['accounts'] ?? [];
    }

    public function fetchHoldings(Institution $institution, string $accountExternalId): array
    {
        $holdings = self::INSTITUTIONS[$institution->slug]['holdings'][$accountExternalId] ?? [];

        return array_map(fn (array $holding): array => [
            'asset' => $this->assetCatalog->metadata($holding['symbol']),
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
                    'type' => $holding['symbol'] === 'CASH-SAR' ? 'deposit' : 'buy',
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount' => round($quantity * $price, 4),
                    'executed_at' => Carbon::now()->subDays($daysAgo)->startOfDay(),
                ];
            }

            // Semiannual dividends on equities (a ~3% annual yield on cost),
            // so purification analysis has real income to work from.
            if ($this->assetCatalog->metadata($holding['symbol'])['asset_class'] === 'equity') {
                foreach ([90, 270] as $daysAgo) {
                    $transactions[] = [
                        'symbol' => $holding['symbol'],
                        'type' => 'dividend',
                        'quantity' => null,
                        'price' => null,
                        'amount' => round($holding['quantity'] * $holding['avg_cost'] * 0.015, 4),
                        'executed_at' => Carbon::now()->subDays($daysAgo)->startOfDay(),
                    ];
                }
            }
        }

        return $transactions;
    }

    public function fetchPrices(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->priceProvider->fetchDailyCloses($symbols, $from, $to);
    }

    public function benchmarks(): array
    {
        return [
            $this->assetCatalog->metadata('TASI'),
            $this->assetCatalog->metadata('SPY'),
        ];
    }
}
