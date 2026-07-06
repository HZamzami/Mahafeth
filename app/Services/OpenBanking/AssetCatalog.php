<?php

namespace App\Services\OpenBanking;

/**
 * Shared catalog of demo asset metadata and price-simulation parameters,
 * used by the simulated Open Banking providers and the statement import
 * path so metadata and Shariah classifications live in one place.
 */
class AssetCatalog
{
    /**
     * Asset metadata and price-simulation parameters, keyed by symbol.
     *
     * @var array<string, array{name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status: string, start: float, drift: float, vol: float, loading: float}>
     */
    private const ASSETS = [
        'AAPL' => ['name' => 'Apple Inc.', 'name_ar' => 'آبل', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'shariah_status' => 'compliant', 'start' => 130.0, 'drift' => 0.18, 'vol' => 0.28, 'loading' => 0.80],
        'MSFT' => ['name' => 'Microsoft Corporation', 'name_ar' => 'مايكروسوفت', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'shariah_status' => 'compliant', 'start' => 250.0, 'drift' => 0.16, 'vol' => 0.26, 'loading' => 0.80],
        'NVDA' => ['name' => 'NVIDIA Corporation', 'name_ar' => 'إنفيديا', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'shariah_status' => 'compliant', 'start' => 180.0, 'drift' => 0.35, 'vol' => 0.45, 'loading' => 0.75],
        'GOOGL' => ['name' => 'Alphabet Inc.', 'name_ar' => 'ألفابت', 'asset_class' => 'equity', 'sector' => 'Technology', 'country' => 'US', 'currency' => 'USD', 'shariah_status' => 'compliant', 'start' => 100.0, 'drift' => 0.14, 'vol' => 0.30, 'loading' => 0.78],
        'JPM' => ['name' => 'JPMorgan Chase & Co.', 'name_ar' => 'جي بي مورغان تشيس', 'asset_class' => 'equity', 'sector' => 'Financials', 'country' => 'US', 'currency' => 'USD', 'shariah_status' => 'non_compliant', 'start' => 135.0, 'drift' => 0.12, 'vol' => 0.24, 'loading' => 0.70],
        '2222.SR' => ['name' => 'Saudi Aramco', 'name_ar' => 'أرامكو السعودية', 'asset_class' => 'equity', 'sector' => 'Energy', 'country' => 'SA', 'currency' => 'SAR', 'shariah_status' => 'compliant', 'start' => 8.2, 'drift' => 0.08, 'vol' => 0.18, 'loading' => 0.35],
        '1120.SR' => ['name' => 'Al Rajhi Bank', 'name_ar' => 'مصرف الراجحي', 'asset_class' => 'equity', 'sector' => 'Financials', 'country' => 'SA', 'currency' => 'SAR', 'shariah_status' => 'compliant', 'start' => 20.0, 'drift' => 0.10, 'vol' => 0.22, 'loading' => 0.40],
        '7010.SR' => ['name' => 'stc Group', 'name_ar' => 'مجموعة stc', 'asset_class' => 'equity', 'sector' => 'Telecom', 'country' => 'SA', 'currency' => 'SAR', 'shariah_status' => 'compliant', 'start' => 10.5, 'drift' => 0.07, 'vol' => 0.20, 'loading' => 0.35],
        '1010.SR' => ['name' => 'Riyad Bank', 'name_ar' => 'بنك الرياض', 'asset_class' => 'equity', 'sector' => 'Financials', 'country' => 'SA', 'currency' => 'SAR', 'shariah_status' => 'non_compliant', 'start' => 25.0, 'drift' => 0.09, 'vol' => 0.21, 'loading' => 0.45],
        'BTC' => ['name' => 'Bitcoin', 'name_ar' => 'بيتكوين', 'asset_class' => 'crypto', 'sector' => null, 'country' => null, 'currency' => 'USD', 'shariah_status' => 'unknown', 'start' => 28000.0, 'drift' => 0.40, 'vol' => 0.65, 'loading' => 0.35],
        'ETH' => ['name' => 'Ethereum', 'name_ar' => 'إيثيريوم', 'asset_class' => 'crypto', 'sector' => null, 'country' => null, 'currency' => 'USD', 'shariah_status' => 'unknown', 'start' => 1800.0, 'drift' => 0.35, 'vol' => 0.75, 'loading' => 0.40],
        'CASH-SAR' => ['name' => 'Cash (SAR)', 'name_ar' => 'نقد (ريال سعودي)', 'asset_class' => 'cash', 'sector' => null, 'country' => 'SA', 'currency' => 'SAR', 'shariah_status' => 'compliant', 'start' => 1.0, 'drift' => 0.0, 'vol' => 0.0, 'loading' => 0.0],
        'SPY' => ['name' => 'S&P 500 Index', 'name_ar' => 'مؤشر ستاندرد آند بورز 500', 'asset_class' => 'fund', 'sector' => null, 'country' => 'US', 'currency' => 'USD', 'shariah_status' => 'unknown', 'start' => 380.0, 'drift' => 0.10, 'vol' => 0.16, 'loading' => 1.00],
        'TASI' => ['name' => 'Tadawul All Share Index', 'name_ar' => 'مؤشر تداول العام', 'asset_class' => 'fund', 'sector' => null, 'country' => 'SA', 'currency' => 'SAR', 'shariah_status' => 'unknown', 'start' => 11000.0, 'drift' => 0.08, 'vol' => 0.14, 'loading' => 0.50],
    ];

    public function has(string $symbol): bool
    {
        return isset(self::ASSETS[$symbol]);
    }

    /**
     * Asset metadata in the Open Banking provider contract shape.
     *
     * @return array{symbol: string, name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status: string}
     */
    public function metadata(string $symbol): array
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
            'shariah_status' => $params['shariah_status'],
        ];
    }

    /**
     * GBM price-simulation parameters for the symbol, or null if uncatalogued.
     *
     * @return ?array{start: float, drift: float, vol: float, loading: float}
     */
    public function simulationParams(string $symbol): ?array
    {
        $params = self::ASSETS[$symbol] ?? null;

        return $params === null ? null : [
            'start' => $params['start'],
            'drift' => $params['drift'],
            'vol' => $params['vol'],
            'loading' => $params['loading'],
        ];
    }
}
