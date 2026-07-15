<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\ShariahStatus;
use App\Services\Markets\AssetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_catalogued_symbol_uses_curated_metadata(): void
    {
        $asset = app(AssetResolver::class)->resolve('AAPL');

        $this->assertSame('AAPL', $asset->symbol);
        $this->assertSame('Apple Inc.', $asset->name);
        $this->assertSame(AssetClass::Equity, $asset->asset_class);
        $this->assertSame(ShariahStatus::Compliant, $asset->shariah_status);
    }

    public function test_an_uncatalogued_symbol_maps_from_a_search_result(): void
    {
        $asset = app(AssetResolver::class)->resolve('TSLA', [
            'symbol' => 'TSLA',
            'name' => 'Tesla, Inc.',
            'exchange' => 'NASDAQ',
            'country' => 'United States',
            'currency' => 'USD',
            'type' => 'Common Stock',
        ]);

        $this->assertSame('Tesla, Inc.', $asset->name);
        $this->assertSame(AssetClass::Equity, $asset->asset_class);
        $this->assertSame('USD', $asset->currency);
        $this->assertNull($asset->country);
        $this->assertNull($asset->sector);
        $this->assertSame(ShariahStatus::Unknown, $asset->shariah_status);
    }

    public function test_instrument_types_map_to_asset_classes(): void
    {
        $etf = app(AssetResolver::class)->resolve('VOO', ['name' => 'Vanguard S&P 500 ETF', 'type' => 'ETF']);
        $crypto = app(AssetResolver::class)->resolve('DOGE', ['name' => 'Dogecoin', 'type' => 'Digital Currency']);

        $this->assertSame(AssetClass::Fund, $etf->asset_class);
        $this->assertSame(AssetClass::Crypto, $crypto->asset_class);
    }

    public function test_a_saudi_symbol_defaults_to_riyals_without_metadata(): void
    {
        $asset = app(AssetResolver::class)->resolve('4321.SR');

        $this->assertSame('SAR', $asset->currency);
        $this->assertSame(AssetClass::Equity, $asset->asset_class);
    }
}
