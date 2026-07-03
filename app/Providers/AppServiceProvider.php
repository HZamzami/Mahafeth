<?php

namespace App\Providers;

use App\Contracts\OpenBankingProvider;
use App\Services\OpenBanking\FakeOpenBankingProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OpenBankingProvider::class, FakeOpenBankingProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
