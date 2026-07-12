<?php

namespace App\Services\Markets;

use App\Enums\AssetClass;
use Illuminate\Support\Str;

/**
 * Map our symbols onto TradingView's: Tadawul tickers carry a .SR
 * suffix, crypto trades against USD, US tickers resolve as-is.
 */
class TradingViewSymbol
{
    public static function for(string $symbol, ?AssetClass $assetClass = null): string
    {
        return match (true) {
            str_ends_with($symbol, '.SR') => 'TADAWUL:'.Str::before($symbol, '.SR'),
            $assetClass === AssetClass::Crypto => Str::before($symbol, '-').'USD',
            default => $symbol,
        };
    }
}
