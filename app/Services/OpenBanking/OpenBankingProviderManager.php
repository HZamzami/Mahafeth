<?php

namespace App\Services\OpenBanking;

use App\Contracts\OpenBankingProvider;
use App\Models\Institution;

/**
 * Resolves the Open Banking implementation for an institution based on its
 * provider key: real Alinma AIS when sandbox credentials are configured,
 * the simulated provider otherwise. Import-type institutions never sync
 * through a provider; their data arrives via statement upload.
 */
class OpenBankingProviderManager
{
    public function forInstitution(Institution $institution): OpenBankingProvider
    {
        return match ($institution->provider) {
            'alinma_ais' => $this->alinmaIsConfigured()
                ? app(AlinmaOpenBankingProvider::class)
                : app(FakeOpenBankingProvider::class),
            default => app(FakeOpenBankingProvider::class),
        };
    }

    private function alinmaIsConfigured(): bool
    {
        $config = config('services.alinma');

        return ! empty($config['base_url']) && ! empty($config['client_id']) && ! empty($config['client_secret']);
    }
}
