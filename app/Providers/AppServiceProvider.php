<?php

namespace App\Providers;

use App\Contracts\InsightGenerator;
use App\Contracts\NewsProvider;
use App\Contracts\OpenBankingProvider;
use App\Services\Insights\ClaudeInsightGenerator;
use App\Services\Insights\FakeInsightGenerator;
use App\Services\News\CuratedNewsProvider;
use App\Services\News\MarketauxNewsProvider;
use App\Services\OpenBanking\FakeOpenBankingProvider;
use Illuminate\Contracts\Foundation\Application;
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
    }
}
