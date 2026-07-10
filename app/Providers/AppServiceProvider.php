<?php

namespace App\Providers;

use App\Contracts\ChatResponder;
use App\Contracts\InsightGenerator;
use App\Contracts\NewsProvider;
use App\Contracts\OpenBankingProvider;
use App\Contracts\PriceProvider;
use App\Services\Insights\ClaudeChatResponder;
use App\Services\Insights\ClaudeInsightGenerator;
use App\Services\Insights\FakeChatResponder;
use App\Services\Insights\FakeInsightGenerator;
use App\Services\News\CuratedNewsProvider;
use App\Services\News\MarketauxNewsProvider;
use App\Services\OpenBanking\FakeOpenBankingProvider;
use App\Services\Prices\SimulatedPriceProvider;
use App\Services\Prices\TwelveDataPriceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OpenBankingProvider::class, FakeOpenBankingProvider::class);

        $this->app->bind(PriceProvider::class, function (Application $app) {
            return empty($app->make('config')->get('services.twelvedata.key'))
                ? $app->make(SimulatedPriceProvider::class)
                : $app->make(TwelveDataPriceProvider::class);
        });

        $this->app->bind(NewsProvider::class, function (Application $app) {
            return empty($app->make('config')->get('services.marketaux.token'))
                ? $app->make(CuratedNewsProvider::class)
                : $app->make(MarketauxNewsProvider::class);
        });

        $this->app->bind(InsightGenerator::class, function (Application $app) {
            $config = $app->make('config')->get('mahafeth.ai');

            return $config['fake'] || empty($config['api_key'])
                ? $app->make(FakeInsightGenerator::class)
                : $app->make(ClaudeInsightGenerator::class);
        });

        $this->app->bind(ChatResponder::class, function (Application $app) {
            $config = $app->make('config')->get('mahafeth.ai');

            return $config['fake'] || empty($config['api_key'])
                ? $app->make(FakeChatResponder::class)
                : $app->make(ClaudeChatResponder::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Financial figures render with Western digits in both locales
        // (as Saudi financial apps conventionally do) — this also avoids
        // mixed-direction glitches around +/− signs in RTL layouts.
        Number::useLocale('en');

        // Abbreviated money amounts spell the scale through the translator,
        // so Arabic reads "3.0 مليون" instead of Number::abbreviate's
        // hardcoded Latin "3.0M".
        Number::macro('localizedAbbreviate', function (float $value, int $precision = 1): string {
            $absolute = abs($value);

            [$divisor, $key] = match (true) {
                $absolute >= 1e12 => [1e12, ':numberT'],
                $absolute >= 1e9 => [1e9, ':numberB'],
                $absolute >= 1e6 => [1e6, ':numberM'],
                $absolute >= 1e3 => [1e3, ':numberK'],
                default => [1.0, ':number'],
            };

            return __($key, ['number' => Number::format($value / $divisor, $divisor > 1 ? $precision : null, $divisor > 1 ? null : $precision)]);
        });

        // Surface background failures (analysis, insights, scheduled syncs)
        // at critical level so hosting alerts pick them up.
        Queue::failing(function (JobFailed $event): void {
            Log::critical('Queued job failed.', [
                'job' => $event->job->resolveName(),
                'connection' => $event->connectionName,
                'error' => $event->exception->getMessage(),
            ]);
        });
    }
}
