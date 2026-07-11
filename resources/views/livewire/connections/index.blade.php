<?php

use App\Actions\ImportHoldings;
use App\Actions\SyncConnection;
use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Models\Institution;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Imports\AlinmaCapitalStatementParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $statement;

    /** @var list<string> */
    public array $importNotices = [];

    /**
     * Re-sync an existing connection. API connections require an active
     * Open Banking consent.
     */
    public function sync(int $connectionId, SyncConnection $syncConnection, PortfolioAnalyzer $analyzer): void
    {
        $connection = Auth::user()->connections()->with('latestConsent')->findOrFail($connectionId);

        if ($connection->source === 'api' && ! ($connection->latestConsent?->isActive() ?? false)) {
            session()->flash('error', __('The consent for this connection has expired. Please reauthorize access.'));

            return;
        }

        $syncConnection->handle($connection);
        $analyzer->analyze(Auth::user());

        $this->dispatch('toast', message: __('Synced and re-analyzed.'));
    }

    /**
     * Import a holdings statement for institutions without API access.
     */
    public function import(
        int $institutionId,
        AlinmaCapitalStatementParser $parser,
        ImportHoldings $importHoldings,
        PortfolioAnalyzer $analyzer,
    ): void {
        $this->validate(
            ['statement' => ['required', 'file', 'mimes:csv,txt', 'max:1024']],
            ['statement.required' => __('Choose a statement file to import.')],
        );

        if (! RateLimiter::attempt('import-holdings:'.Auth::id(), maxAttempts: 10, callback: fn () => true)) {
            $this->addError('statement', __('Too many imports. Please wait a minute and try again.'));

            return;
        }

        $institution = Institution::findOrFail($institutionId);
        $result = $parser->parse($this->statement->get());

        if ($result['rows'] === []) {
            $this->addError('statement', $result['errors'][0] ?? __('No holdings found in the file.'));

            return;
        }

        $importHoldings->handle(Auth::user(), $institution, $result['rows']);
        $analyzer->analyze(Auth::user());

        $this->importNotices = $result['errors'];
        $this->reset('statement');
        $this->modal('import-'.$institution->id)->close();

        $this->dispatch('toast', message: __(':count holdings imported.', ['count' => count($result['rows'])]));
    }

    /**
     * Revoke access: the consent is marked revoked and the connection
     * disconnected, exactly as an Open Banking revocation would behave.
     */
    public function disconnect(int $connectionId, PortfolioAnalyzer $analyzer): void
    {
        $connection = Auth::user()->connections()->findOrFail($connectionId);

        $connection->update(['status' => ConnectionStatus::Disconnected]);

        Auth::user()->consents()
            ->where('connection_id', $connection->id)
            ->where('status', ConsentStatus::Active)
            ->update(['status' => ConsentStatus::Revoked, 'revoked_at' => now()]);

        $analyzer->analyze(Auth::user());

        $this->dispatch('toast', message: __('Access revoked.'));
    }

    public function with(): array
    {
        return [
            'institutions' => Institution::orderBy('name')->get(),
            'connections' => Auth::user()->connections()->with(['accounts', 'latestConsent'])->get()->keyBy('institution_id'),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Connected Sources') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Securely link your investment accounts via Open Banking to build your unified portfolio.') }}
        </flux:text>
    </div>

    @if (session('status'))
        <flux:callout color="emerald" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (session('error'))
        <flux:callout color="red" icon="exclamation-triangle">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($importNotices !== [])
        <flux:callout color="amber" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Some statement lines were skipped') }}</flux:callout.heading>
            <flux:callout.text>
                @foreach ($importNotices as $notice)
                    <div>{{ $notice }}</div>
                @endforeach
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-4">
        @foreach ($institutions as $institution)
            @php($connection = $connections->get($institution->id))
            @php($isConnected = $connection?->status === \App\Enums\ConnectionStatus::Connected)

            <div
                class="flex flex-col gap-4 card p-5 sm:flex-row sm:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-lg"
                        style="background-color: {{ $institution->color }}20">
                        <flux:icon.building-library class="size-6" style="color: {{ $institution->color }}" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $institution->localizedName() }}</flux:heading>
                            @if ($isConnected)
                                <flux:badge color="emerald" size="sm">{{ __('Connected') }}</flux:badge>
                            @elseif ($connection?->status === \App\Enums\ConnectionStatus::Disconnected)
                                <flux:badge color="zinc" size="sm">{{ __('Disconnected') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text class="text-sm">
                            {{ $institution->type->label() }}
                            @if ($isConnected && $connection->last_synced_at !== null)
                                &bull; {{ __('Last sync: :time', ['time' => $connection->last_synced_at->diffForHumans()]) }}
                            @endif
                            @if ($isConnected && $connection->latestConsent?->isActive())
                                &bull;
                                <span class="{{ $connection->latestConsent->daysUntilExpiry() < 14 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                    {{ __('Consent expires in :days days', ['days' => $connection->latestConsent->daysUntilExpiry()]) }}
                                </span>
                            @endif
                        </flux:text>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap gap-2">
                @if ($isConnected)
                        @if ($institution->provider === 'import')
                            <flux:modal.trigger name="import-{{ $institution->id }}">
                                <flux:button size="sm" variant="outline">{{ __('Import statement') }}</flux:button>
                            </flux:modal.trigger>
                        @else
                            <flux:button size="sm" variant="outline" wire:click="sync({{ $connection->id }})"
                                wire:loading.attr="disabled">
                                {{ __('Sync') }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="subtle" wire:click="disconnect({{ $connection->id }})"
                            wire:loading.attr="disabled">
                            {{ $institution->provider === 'import' ? __('Disconnect') : __('Revoke access') }}
                        </flux:button>
                    @elseif ($institution->provider === 'import')
                        <flux:modal.trigger name="import-{{ $institution->id }}">
                            <flux:button size="sm" variant="primary">{{ __('Import statement') }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button size="sm" variant="primary"
                            :href="route('connections.consent', $institution)" wire:navigate>
                            {{ __('Connect') }}</flux:button>
                    @endif
                </div>
            </div>

            @if ($institution->provider === 'import')
                <flux:modal name="import-{{ $institution->id }}" class="md:w-96">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">
                                {{ __('Import :institution statement', ['institution' => $institution->localizedName()]) }}
                            </flux:heading>
                            <flux:text class="mt-2">
                                {{ __('Brokerage APIs are not yet part of Saudi Open Banking, so upload your holdings statement as CSV and Mahafeth will fold it into your unified portfolio.') }}
                            </flux:text>
                        </div>

                        <flux:input type="file" wire:model="statement" accept=".csv,text/csv"
                            :label="__('Holdings statement (CSV)')" />
                        <flux:error name="statement" />

                        <flux:text class="text-xs">
                            {{ __('Expected columns: symbol, quantity, avg_cost.') }}
                            <a class="underline" href="{{ asset('samples/alinma-capital-holdings.csv') }}" download>
                                {{ __('Download a sample file') }}</a>
                        </flux:text>

                        <div class="flex gap-2">
                            <flux:spacer />
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button variant="primary" wire:click="import({{ $institution->id }})"
                                wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="import, statement">{{ __('Import') }}</span>
                                <span wire:loading wire:target="import, statement">{{ __('Importing…') }}</span>
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif

        @endforeach
    </div>
</div>
