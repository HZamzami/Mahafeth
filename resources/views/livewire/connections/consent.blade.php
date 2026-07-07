<?php

use App\Actions\SyncConnection;
use App\Enums\ConsentStatus;
use App\Models\Institution;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public Institution $institution;

    /**
     * The bank-side authorization step of the Open Banking journey: the
     * user reviews the requested scopes and approves or denies access.
     */
    public function mount(Institution $institution): void
    {
        abort_if($institution->provider === 'import', 404);

        $this->institution = $institution;
    }

    public function approve(SyncConnection $syncConnection, PortfolioAnalyzer $analyzer): void
    {
        $connection = Auth::user()->connections()->firstOrCreate([
            'institution_id' => $this->institution->id,
        ]);

        Auth::user()->consents()->create([
            'institution_id' => $this->institution->id,
            'connection_id' => $connection->id,
            'scopes' => config('mahafeth.consent_scopes'),
            'status' => ConsentStatus::Active,
            'granted_at' => now(),
            'expires_at' => now()->addDays((int) config('mahafeth.consent_ttl_days')),
        ]);

        $syncConnection->handle($connection);
        $analyzer->analyze(Auth::user());

        session()->flash('status', __(':institution connected successfully.', ['institution' => $this->institution->localizedName()]));

        $this->redirectRoute('connections', navigate: true);
    }

    public function deny(): void
    {
        $this->redirectRoute('connections', navigate: true);
    }

    public function with(): array
    {
        return [
            'scopes' => [
                'accounts' => [__('Account details'), __('Names, types, and currencies of your accounts.')],
                'balances' => [__('Balances'), __('Current balances of the shared accounts.')],
                'transactions' => [__('Transactions'), __('Transaction history of the shared accounts.')],
            ],
            'ttlDays' => (int) config('mahafeth.consent_ttl_days'),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-md flex-col gap-6">
    <div class="card p-6">
        <div class="flex items-center gap-4">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-lg"
                style="background-color: {{ $institution->color }}20">
                <flux:icon.building-library class="size-6" style="color: {{ $institution->color }}" />
            </div>
            <div>
                <flux:heading size="lg">{{ $institution->localizedName() }}</flux:heading>
                <flux:text class="text-sm">{{ __('Authorization request from Mahafeth') }}</flux:text>
            </div>
        </div>

        <flux:text class="mt-4 text-sm">
            {{ __('Mahafeth is requesting read-only access to the following data through Open Banking:') }}
        </flux:text>

        <div class="mt-4 space-y-3">
            @foreach ($scopes as [$label, $description])
                <div class="flex items-start gap-3">
                    <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                    <div>
                        <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">{{ $label }}</flux:text>
                        <flux:text class="text-xs">{{ $description }}</flux:text>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center gap-3 rounded-lg bg-neutral-50 p-3 dark:bg-zinc-800">
            <flux:icon.clock class="size-5 shrink-0 text-teal-600" />
            <flux:text class="text-xs">
                {{ __('This consent is valid for :days days and can be revoked at any time from the Connections page.', ['days' => $ttlDays]) }}
            </flux:text>
        </div>

        <div class="mt-6 flex gap-2">
            <flux:button class="flex-1" variant="ghost" wire:click="deny">{{ __('Deny') }}</flux:button>
            <flux:button class="flex-1" variant="primary" wire:click="approve" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="approve">{{ __('Approve access') }}</span>
                <span wire:loading wire:target="approve">{{ __('Linking…') }}</span>
            </flux:button>
        </div>
    </div>

    <flux:text class="text-center text-xs">
        {{ __('Your credentials are never shared with Mahafeth. Access is read-only under the SAMA Open Banking framework.') }}
    </flux:text>
</div>
