<?php

namespace App\Services\Fx;

use App\Models\FxRate;
use Illuminate\Support\Facades\Cache;

/**
 * Base-currency conversion rates: fetched daily rates from the database
 * first, falling back to the static config peg so valuation never breaks
 * when no rates have been fetched yet.
 */
class FxRateService
{
    private const CACHE_KEY = 'fx-rates';

    /**
     * Base-currency units per one unit of the given currency.
     */
    public function rate(string $currency): float
    {
        return $this->all()[$currency] ?? 1.0;
    }

    /**
     * All known rates, keyed by currency code.
     *
     * @return array<string, float>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(30), function (): array {
            $configured = config('mahafeth.fx_rates', []);
            $fetched = FxRate::pluck('rate', 'currency')->all();

            return array_merge($configured, $fetched);
        });
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
