<?php

use App\Actions\CreateManualAccount;
use App\Enums\AccountType;
use App\Enums\ActivityType;
use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Models\ActivityEvent;
use App\Models\Institution;
use App\Services\Analytics\HoldingsSummarizer;
use App\Actions\SyncConnection;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    // Create-account form.
    public string $createName = '';

    public string $createType = 'brokerage';

    public string $createCurrency = 'SAR';

    public function createAccount(CreateManualAccount $createManualAccount): void
    {
        $this->validate([
            'createName' => ['required', 'string', 'max:60'],
            'createType' => ['required', 'in:brokerage,retirement,crypto,fund,savings,cash'],
            'createCurrency' => ['required', 'in:SAR,USD'],
        ]);

        $account = $createManualAccount->handle(
            Auth::user(),
            trim($this->createName),
            AccountType::from($this->createType),
            $this->createCurrency,
        );

        $this->redirectRoute('connections.account', $account, navigate: true);
    }

    /**
     * Re-sync a demo (institution-backed) connection to re-pull its data.
     */
    public function sync(int $connectionId, SyncConnection $syncConnection, PortfolioAnalyzer $analyzer): void
    {
        $connection = Auth::user()->connections()->with('latestConsent')->findOrFail($connectionId);

        // API-backed demo connections need a live Open Banking consent, exactly
        // as a real re-sync would.
        if ($connection->source === 'api' && ! ($connection->latestConsent?->isActive() ?? false)) {
            session()->flash('error', __('The consent for this connection has expired. Please reauthorize access.'));

            return;
        }

        $syncConnection->handle($connection);
        $analyzer->analyze(Auth::user());

        $this->dispatch('toast', message: __('Synced and re-analyzed.'));
    }

    /**
     * Remove an account. A manual account is deleted outright; a demo
     * connection is disconnected and its consent revoked, mirroring an Open
     * Banking revocation.
     */
    public function remove(int $connectionId, PortfolioAnalyzer $analyzer): void
    {
        $connection = Auth::user()->connections()->findOrFail($connectionId);

        if ($connection->isManual()) {
            $connection->delete();
        } else {
            $connection->update(['status' => ConnectionStatus::Disconnected]);

            Auth::user()->consents()
                ->where('connection_id', $connection->id)
                ->where('status', ConsentStatus::Active)
                ->update(['status' => ConsentStatus::Revoked, 'revoked_at' => now()]);

            ActivityEvent::record(Auth::user(), ActivityType::ConnectionDisconnected, [
                'institution' => $connection->institution->localizedName(),
            ]);
        }

        $analyzer->analyze(Auth::user());
        $this->dispatch('toast', message: __('Access revoked.'));
    }

    public function with(HoldingsSummarizer $summarizer): array
    {
        $connections = Auth::user()->connections()
            ->with(['accounts', 'institution', 'latestConsent'])
            ->get();

        $value = fn ($account): float => $account === null ? 0.0 : $summarizer->forAccount($account)['totalValue'];

        $yourAccounts = $connections
            ->filter(fn ($connection): bool => $connection->isManual())
            ->map(fn ($connection): array => [
                'connectionId' => $connection->id,
                'account' => $connection->accounts->first(),
                'name' => $connection->displayName(),
                'value' => $value($connection->accounts->first()),
            ])
            ->values();

        $demoAccounts = $connections
            ->filter(fn ($connection): bool => ! $connection->isManual() && $connection->status === ConnectionStatus::Connected)
            ->map(fn ($connection): array => [
                'connectionId' => $connection->id,
                'account' => $connection->accounts->first(),
                'institution' => $connection->institution,
                'value' => $value($connection->accounts->first()),
            ])
            ->values();

        $connectedInstitutionIds = $connections->pluck('institution_id')->filter()->all();

        return [
            'yourAccounts' => $yourAccounts,
            'demoAccounts' => $demoAccounts,
            // Ready-made institutions the user hasn't loaded yet. Import-only
            // brokerages are retired in favour of user-named accounts.
            'availableDemos' => Institution::where('provider', '!=', 'import')
                ->whereNotIn('id', $connectedInstitutionIds)
                ->orderBy('name')
                ->get(),
            'accountTypes' => AccountType::cases(),
        ];
    }
}; ?>

<div class="stagger-children relative mx-auto flex w-full max-w-3xl flex-col gap-8">
    <div>
        <flux:heading size="xl">{{ __('Accounts') }}</flux:heading>
        <flux:text class="mt-1 text-balance">
            {{ __('Add each of your investment accounts — name it, then fill it by uploading a statement or entering positions by hand.') }}
        </flux:text>
    </div>

    {{-- Your accounts --}}
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Your accounts') }}</flux:heading>
            <flux:modal.trigger name="new-account">
                <flux:button size="sm" variant="primary" icon="plus">{{ __('New account') }}</flux:button>
            </flux:modal.trigger>
        </div>

        @forelse ($yourAccounts as $item)
            <a href="{{ route('connections.account', $item['account']) }}" wire:navigate wire:key="acct-{{ $item['connectionId'] }}"
                class="flex items-center gap-4 card p-5 transition-colors hover:bg-neutral-50 dark:hover:bg-zinc-800/60">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-teal-500/10">
                    <flux:icon.wallet class="size-5 text-teal-700 dark:text-teal-300" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg">{{ $item['name'] }}</flux:heading>
                    <flux:text class="text-sm">{{ $item['account']?->type->label() }}</flux:text>
                </div>
                <div class="text-end" dir="ltr">
                    <flux:heading>⃁ {{ Number::format($item['value'], 0) }}</flux:heading>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400 rtl:rotate-180" />
            </a>
        @empty
            <div class="flex flex-col items-center gap-3 card-cta p-10 text-center">
                <flux:icon.wallet class="size-6 text-teal-700 dark:text-teal-300" />
                <flux:text class="max-w-72 text-sm">
                    {{ __('No accounts yet. Add your first — call it whatever your broker is — and fill it in.') }}
                </flux:text>
                <flux:modal.trigger name="new-account">
                    <flux:button size="sm" variant="primary" icon="plus">{{ __('New account') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endforelse
    </div>

    {{-- Demo accounts --}}
    <div class="flex flex-col gap-3">
        <div>
            <flux:heading size="lg">{{ __('Demo accounts') }}</flux:heading>
            <flux:text class="mt-1 text-sm">
                {{ __('Ready-made sample portfolios to explore the app instantly. View-only.') }}
            </flux:text>
        </div>

        @foreach ($demoAccounts as $item)
            <div wire:key="demo-{{ $item['connectionId'] }}" class="flex items-center gap-4 card p-5">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-lg"
                    style="background-color: {{ $item['institution']->color }}20">
                    <flux:icon.building-library class="size-5" style="color: {{ $item['institution']->color }}" />
                </div>
                <a href="{{ $item['account'] ? route('connections.account', $item['account']) : '#' }}" wire:navigate
                    class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $item['institution']->localizedName() }}</flux:heading>
                        <flux:badge size="sm" color="zinc">{{ __('Demo') }}</flux:badge>
                    </div>
                    <flux:text class="text-sm" dir="ltr">⃁ {{ Number::format($item['value'], 0) }}</flux:text>
                </a>
                <flux:button size="sm" variant="subtle" wire:click="sync({{ $item['connectionId'] }})"
                    wire:loading.attr="disabled">{{ __('Sync') }}</flux:button>
                <flux:button size="sm" variant="subtle" icon="x-mark" wire:click="remove({{ $item['connectionId'] }})"
                    wire:confirm="{{ __('Remove this demo account?') }}" :aria-label="__('Remove')" />
            </div>
        @endforeach

        @foreach ($availableDemos as $institution)
            <div wire:key="avail-{{ $institution->id }}" class="flex items-center gap-4 card p-5">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-lg"
                    style="background-color: {{ $institution->color }}20">
                    <flux:icon.building-library class="size-5" style="color: {{ $institution->color }}" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg">{{ $institution->localizedName() }}</flux:heading>
                    <flux:text class="text-sm">{{ $institution->type->label() }}</flux:text>
                </div>
                <flux:button size="sm" variant="outline" :href="route('connections.consent', $institution)" wire:navigate>
                    {{ __('Load demo account') }}</flux:button>
            </div>
        @endforeach
    </div>

    {{-- Create-account modal --}}
    <flux:modal name="new-account" class="md:w-96">
        <form wire:submit="createAccount" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New account') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Name it after the broker or bank it represents.') }}
                </flux:text>
            </div>

            <flux:input wire:model="createName" :label="__('Account name')" :placeholder="__('e.g. My Sahm account')"
                maxlength="60" />
            <flux:error name="createName" />

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="createType" :label="__('Type')">
                    @foreach ($accountTypes as $type)
                        <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="createCurrency" :label="__('Base currency')">
                    <flux:select.option value="SAR">SAR</flux:select.option>
                    <flux:select.option value="USD">USD</flux:select.option>
                </flux:select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
