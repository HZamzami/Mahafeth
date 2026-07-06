<?php

use App\Actions\ImportHoldings;
use App\Actions\SyncConnection;
use App\Enums\ConnectionStatus;
use App\Models\Institution;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Imports\AlinmaCapitalStatementParser;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $statement;

    /** @var list<string> */
    public array $importNotices = [];

    /**
     * Authorize a new Open Banking connection and pull its data.
     */
    public function connect(int $institutionId, SyncConnection $syncConnection, PortfolioAnalyzer $analyzer): void
    {
        $institution = Institution::findOrFail($institutionId);

        $connection = Auth::user()->connections()->firstOrCreate([
            'institution_id' => $institution->id,
        ]);

        $syncConnection->handle($connection);
        $analyzer->analyze(Auth::user());

        $this->modal('connect-'.$institution->id)->close();
    }

    /**
     * Re-sync an existing connection.
     */
    public function sync(int $connectionId, SyncConnection $syncConnection, PortfolioAnalyzer $analyzer): void
    {
        $connection = Auth::user()->connections()->findOrFail($connectionId);

        $syncConnection->handle($connection);
        $analyzer->analyze(Auth::user());
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
    }

    public function disconnect(int $connectionId, PortfolioAnalyzer $analyzer): void
    {
        Auth::user()->connections()->findOrFail($connectionId)->update([
            'status' => ConnectionStatus::Disconnected,
        ]);

        $analyzer->analyze(Auth::user());
    }

    public function with(): array
    {
        return [
            'institutions' => Institution::orderBy('name')->get(),
            'connections' => Auth::user()->connections()->with('accounts')->get()->keyBy('institution_id'),
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
                class="flex items-center gap-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-lg"
                    style="background-color: {{ $institution->color }}20">
                    <flux:icon.building-library class="size-6" style="color: {{ $institution->color }}" />
                </div>

                <div class="flex-1">
                    <div class="flex items-center gap-2">
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
                    </flux:text>
                </div>

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
                        {{ __('Disconnect') }}
                    </flux:button>
                @elseif ($institution->provider === 'import')
                    <flux:modal.trigger name="import-{{ $institution->id }}">
                        <flux:button size="sm" variant="primary">{{ __('Import statement') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:modal.trigger name="connect-{{ $institution->id }}">
                        <flux:button size="sm" variant="primary">{{ __('Connect') }}</flux:button>
                    </flux:modal.trigger>
                @endif
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

            <flux:modal name="connect-{{ $institution->id }}" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Connect :institution', ['institution' => $institution->localizedName()]) }}
                        </flux:heading>
                        <flux:text class="mt-2">
                            {{ __('Mahafeth will securely access your accounts, holdings, and transactions at :institution through Open Banking. Your credentials are never shared with us.', ['institution' => $institution->localizedName()]) }}
                        </flux:text>
                    </div>

                    <div class="flex items-center gap-3 rounded-lg bg-neutral-50 p-3 dark:bg-zinc-800">
                        <flux:icon.lock-closed class="size-5 text-emerald-600 dark:text-emerald-400" />
                        <flux:text class="text-xs">{{ __('Read-only access. You can disconnect at any time.') }}</flux:text>
                    </div>

                    <div class="flex gap-2">
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" wire:click="connect({{ $institution->id }})"
                            wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="connect">{{ __('Authorize') }}</span>
                            <span wire:loading wire:target="connect">{{ __('Linking…') }}</span>
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endforeach
    </div>
</div>
