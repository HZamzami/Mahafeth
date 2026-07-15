<?php

namespace App\Actions;

use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\User;

/**
 * Creates a user-owned account with no linked institution: a manual
 * Connection the analytics pipeline treats as connected, plus one Account
 * the user fills by CSV or by hand. Returns the Account.
 */
class CreateManualAccount
{
    public function handle(User $user, string $label, AccountType $type, string $currency): Account
    {
        $connection = $user->connections()->create([
            'institution_id' => null,
            'label' => $label,
            'source' => 'manual',
            'status' => ConnectionStatus::Connected,
            'last_synced_at' => now(),
        ]);

        return $connection->accounts()->create([
            'external_id' => 'MANUAL',
            'name' => $label,
            'type' => $type,
            'currency' => $currency,
        ]);
    }
}
