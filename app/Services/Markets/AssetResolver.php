<?php

namespace App\Services\Markets;

use App\Enums\AssetClass;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Services\OpenBanking\AssetCatalog;

/**
 * Turns a symbol into a persisted Asset. Catalogued symbols use our curated
 * metadata and Shariah classifications; anything else is built from a Twelve
 * Data symbol-search result so users can hold any instrument. Uncatalogued
 * names carry an unknown compliance status and no sector — we only vouch for
 * what the catalog covers.
 */
class AssetResolver
{
    public function __construct(private AssetCatalog $catalog) {}

    /**
     * Resolve (creating or updating) the Asset for a symbol. For uncatalogued
     * symbols, pass the matching search result as $meta to carry its name,
     * type, currency, and country onto the Asset.
     *
     * @param  ?array{name?: string, type?: string, currency?: string, country?: string}  $meta
     */
    public function resolve(string $symbol, ?array $meta = null): Asset
    {
        $attributes = $this->catalog->has($symbol)
            ? $this->catalog->metadata($symbol)
            : $this->metadataFromSearch($symbol, $meta);

        return Asset::updateOrCreate(['symbol' => $symbol], $attributes);
    }

    /**
     * Build Asset metadata from a symbol-search result (or bare symbol).
     *
     * @param  ?array{name?: string, type?: string, currency?: string, country?: string}  $meta
     * @return array{name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status: string, is_benchmark: bool}
     */
    private function metadataFromSearch(string $symbol, ?array $meta): array
    {
        // The country column holds ISO alpha-2 codes; search results give full
        // country names ("United States"), so keep only genuine two-letter codes.
        $rawCountry = $meta['country'] ?? '';
        $country = strlen($rawCountry) === 2 ? strtoupper($rawCountry) : null;
        $currency = ($meta['currency'] ?? '') !== '' ? $meta['currency'] : $this->currencyForSymbol($symbol);

        return [
            'name' => ($meta['name'] ?? '') !== '' ? $meta['name'] : $symbol,
            'name_ar' => null,
            'asset_class' => $this->assetClassFor($meta['type'] ?? '')->value,
            'sector' => null,
            'country' => $country,
            'currency' => $currency,
            'shariah_status' => ShariahStatus::Unknown->value,
            'is_benchmark' => false,
        ];
    }

    private function assetClassFor(string $type): AssetClass
    {
        return match ($type) {
            'ETF' => AssetClass::Fund,
            'Digital Currency', 'Crypto' => AssetClass::Crypto,
            default => AssetClass::Equity,
        };
    }

    private function currencyForSymbol(string $symbol): string
    {
        return str_ends_with($symbol, '.SR') ? 'SAR' : 'USD';
    }
}
