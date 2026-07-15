<?php

use App\Actions\ImportHoldings;
use App\Actions\SyncConnection;
use App\Enums\ActivityType;
use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Holding;
use App\Services\Analytics\HoldingsSummarizer;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\OpenBanking\AssetCatalog;
use App\Services\Imports\HoldingsStatementParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Account $account;

    public bool $manual = false;

    // Add-holding form.
    public string $addSymbol = '';

    public ?string $addQuantity = null;

    public ?string $addAvgCost = null;

    // Add-cash form.
    public string $cashCurrency = 'SAR';

    public ?string $cashAmount = null;

    // Inline edit.
    public ?int $editingId = null;

    public ?string $editQuantity = null;

    public ?string $editAvgCost = null;

    public $statement;

    /** @var list<string> */
    public array $importNotices = [];

    public function mount(Account $account): void
    {
        abort_unless($account->connection->user_id === Auth::id(), 404);

        $this->account = $account;
        $this->manual = $account->connection->isManual();
    }

    /**
     * Re-value the whole portfolio after any change to this account.
     */
    private function reanalyze(PortfolioAnalyzer $analyzer): void
    {
        $analyzer->analyze(Auth::user());
    }

    public function addHolding(ImportHoldings $importHoldings, PortfolioAnalyzer $analyzer, AssetCatalog $catalog): void
    {
        $this->guardManual();

        $this->validate([
            'addSymbol' => ['required', 'string'],
            'addQuantity' => ['required', 'numeric', 'min:0.00000001'],
            'addAvgCost' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! $catalog->has($this->addSymbol)) {
            $this->addError('addSymbol', __('Pick an instrument from the list.'));

            return;
        }

        $importHoldings->intoAccount($this->account, [[
            'symbol' => $this->addSymbol,
            'quantity' => (float) $this->addQuantity,
            'avg_cost' => (float) ($this->addAvgCost ?? 0),
        ]]);

        $this->reanalyze($analyzer);
        $this->reset('addSymbol', 'addQuantity', 'addAvgCost');
        $this->dispatch('toast', message: __('Added to :account.', ['account' => $this->account->name]));
    }

    public function addCash(ImportHoldings $importHoldings, PortfolioAnalyzer $analyzer): void
    {
        $this->guardManual();

        $this->validate([
            'cashCurrency' => ['required', 'in:SAR,USD'],
            'cashAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $importHoldings->intoAccount($this->account, [[
            'symbol' => 'CASH-'.$this->cashCurrency,
            'quantity' => (float) $this->cashAmount,
            'avg_cost' => 1.0,
        ]]);

        $this->reanalyze($analyzer);
        $this->reset('cashAmount');
        $this->dispatch('toast', message: __('Cash added.'));
    }

    public function startEdit(int $holdingId): void
    {
        $holding = $this->ownedHolding($holdingId);

        $this->editingId = $holding->id;
        $this->editQuantity = (string) $holding->quantity;
        $this->editAvgCost = (string) $holding->avg_cost;
    }

    public function saveEdit(PortfolioAnalyzer $analyzer): void
    {
        $this->guardManual();

        $this->validate([
            'editQuantity' => ['required', 'numeric', 'min:0.00000001'],
            'editAvgCost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $holding = $this->ownedHolding((int) $this->editingId);
        $holding->update([
            'quantity' => (float) $this->editQuantity,
            'avg_cost' => (float) ($this->editAvgCost ?? 0),
        ]);

        $this->reanalyze($analyzer);
        $this->reset('editingId', 'editQuantity', 'editAvgCost');
    }

    public function removeHolding(int $holdingId, PortfolioAnalyzer $analyzer): void
    {
        $this->guardManual();

        $this->ownedHolding($holdingId)->delete();
        $this->reanalyze($analyzer);
        $this->dispatch('toast', message: __('Position removed.'));
    }

    public function importCsv(HoldingsStatementParser $parser, ImportHoldings $importHoldings, PortfolioAnalyzer $analyzer): void
    {
        $this->guardManual();

        $this->validate(['statement' => ['required', 'file', 'max:1024']]);

        if (! in_array(strtolower($this->statement->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            $this->addError('statement', __('Upload a CSV or text file exported from your broker.'));

            return;
        }

        if (! RateLimiter::attempt('import-holdings:'.Auth::id(), maxAttempts: 10, callback: fn () => true)) {
            $this->addError('statement', __('Too many imports. Please wait a minute and try again.'));

            return;
        }

        $result = $parser->parse($this->statement->get());

        if ($result['rows'] === []) {
            $this->addError('statement', $result['errors'][0] ?? __('No holdings found in the file.'));

            return;
        }

        $importHoldings->intoAccount($this->account, $result['rows']);
        $this->reanalyze($analyzer);

        $this->importNotices = $result['errors'];
        $this->reset('statement');
        $this->modal('import-holdings')->close();
        $this->dispatch('toast', message: __(':count holdings imported.', ['count' => count($result['rows'])]));
    }

    /**
     * Re-pull a demo (institution-backed) account's canned data.
     */
    public function sync(SyncConnection $syncConnection, PortfolioAnalyzer $analyzer): void
    {
        $connection = $this->account->connection->load('latestConsent');

        if ($connection->isManual()) {
            return;
        }

        if ($connection->source === 'api' && ! ($connection->latestConsent?->isActive() ?? false)) {
            $this->dispatch('toast', message: __('The consent for this connection has expired. Please reauthorize access.'));

            return;
        }

        $syncConnection->handle($connection);
        $analyzer->analyze(Auth::user());
        $this->dispatch('toast', message: __('Synced and re-analyzed.'));
    }

    /**
     * Delete a manual account outright, or disconnect a demo account and
     * revoke its consent as an Open Banking revocation would.
     */
    public function deleteAccount(PortfolioAnalyzer $analyzer): void
    {
        $connection = $this->account->connection;

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
        $this->redirectRoute('connections', navigate: true);
    }

    private function ownedHolding(int $holdingId): Holding
    {
        return $this->account->holdings()->findOrFail($holdingId);
    }

    private function guardManual(): void
    {
        abort_unless($this->manual, 403);
    }

    public function with(HoldingsSummarizer $summarizer, AssetCatalog $catalog): array
    {
        return [
            'summary' => $summarizer->forAccount($this->account),
            'instruments' => $this->manual ? $catalog->investable() : [],
        ];
    }
}; ?>

<div class="stagger-children relative mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" class="rtl:hidden" :href="route('connections')" wire:navigate>
            {{ __('Accounts') }}</flux:button>
        <flux:button size="sm" variant="ghost" icon="arrow-right" class="hidden rtl:inline-flex" :href="route('connections')" wire:navigate>
            {{ __('Accounts') }}</flux:button>

        <div class="mt-3 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl" class="text-balance">{{ $account->name }}</flux:heading>
                    @unless ($manual)
                        <flux:badge size="sm" color="zinc">{{ __('Demo — sample data') }}</flux:badge>
                    @endunless
                </div>
                <flux:text class="mt-1">{{ $account->type->label() }} · {{ $account->currency }}</flux:text>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                @unless ($manual)
                    <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="sync"
                        wire:loading.attr="disabled" :tooltip="__('Sync')" :aria-label="__('Sync')" />
                @endunless
                <flux:button size="sm" variant="subtle" icon="trash" wire:click="deleteAccount"
                    wire:confirm="{{ $manual ? __('Delete this account? This cannot be undone.') : __('Remove this demo account?') }}"
                    :tooltip="$manual ? __('Delete account') : __('Remove')"
                    :aria-label="$manual ? __('Delete account') : __('Remove')" />
            </div>
        </div>

        <div class="mt-4 flex items-center justify-between rounded-xl bg-neutral-50 px-4 py-3 dark:bg-zinc-800/60">
            <flux:text class="text-xs font-medium uppercase tracking-widest">{{ __('Total value') }}</flux:text>
            <flux:heading size="lg" dir="ltr">⃁ {{ Number::format($summary['totalValue'], 0) }}</flux:heading>
        </div>
    </div>

    @if ($importNotices !== [])
        <flux:callout wire:transition color="amber" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Some statement lines were skipped') }}</flux:callout.heading>
            <flux:callout.text>
                @foreach ($importNotices as $notice)
                    <div>{{ $notice }}</div>
                @endforeach
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Manual account: the in-house editor to add stocks, crypto, and cash. --}}
    @if ($manual)
        <div class="card p-5">
            <flux:heading size="lg">{{ __('Add to this account') }}</flux:heading>

            <form wire:submit="addHolding" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
                <flux:select wire:model="addSymbol" :label="__('Instrument')" :placeholder="__('Choose…')">
                    @foreach ($instruments as $instrument)
                        <flux:select.option value="{{ $instrument['symbol'] }}">
                            {{ $instrument['name'] }} ({{ $instrument['symbol'] }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="addQuantity" type="number" step="any" min="0" dir="ltr" class="sm:w-28"
                    :label="__('Quantity')" placeholder="100" />
                <flux:input wire:model="addAvgCost" type="number" step="any" min="0" dir="ltr" class="sm:w-28"
                    :label="__('Avg cost')" placeholder="0" />
                <flux:button type="submit" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
            </form>
            <flux:error name="addSymbol" />
            <flux:error name="addQuantity" />

            <flux:separator class="my-4" variant="subtle" />

            <form wire:submit="addCash" class="grid gap-3 sm:grid-cols-[auto_1fr_auto] sm:items-end">
                <flux:select wire:model="cashCurrency" :label="__('Cash')" class="sm:w-28">
                    <flux:select.option value="SAR">SAR</flux:select.option>
                    <flux:select.option value="USD">USD</flux:select.option>
                </flux:select>
                <flux:input wire:model="cashAmount" type="number" step="any" min="0" dir="ltr"
                    :label="__('Amount')" placeholder="50000" />
                <flux:button type="submit" variant="filled" icon="banknotes">{{ __('Add cash') }}</flux:button>
            </form>
            <flux:error name="cashAmount" />

            <div class="mt-4">
                <flux:modal.trigger name="import-holdings">
                    <flux:button size="sm" variant="ghost" icon="document-arrow-up">{{ __('Import a CSV instead') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>
    @endif

    {{-- Holdings breakdown for this account. --}}
    <div class="flex flex-col card">
        @forelse ($summary['rows'] as $row)
            <div wire:key="holding-{{ $row['holdingId'] }}"
                class="flex items-center gap-3 px-5 py-3.5 {{ ! $loop->last ? 'border-b border-neutral-100 dark:border-zinc-800' : '' }}">
                <div class="min-w-0 flex-1">
                    <flux:text class="font-medium !text-zinc-800 dark:!text-white">{{ $row['name'] }}</flux:text>
                    <flux:text class="text-xs" dir="ltr">
                        @if ($row['assetClass'] === AssetClass::Cash)
                            {{ __('Cash') }}
                        @else
                            {{ $row['symbol'] }} · {{ rtrim(rtrim(number_format($row['quantity'], 4), '0'), '.') }}
                            @if ($row['avgCost'] > 0)
                                @ {{ Number::format($row['avgCost'], 2) }}
                            @endif
                        @endif
                    </flux:text>
                </div>

                <div class="text-end">
                    <flux:text class="font-medium tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                        ⃁ {{ Number::format($row['value'], 0) }}</flux:text>
                    <flux:text class="text-xs tabular-nums" dir="ltr">{{ Number::percentage($row['weight'] * 100, 1) }}</flux:text>
                </div>

                @if ($manual)
                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button size="xs" variant="subtle" icon="pencil-square"
                            wire:click="startEdit({{ $row['holdingId'] }})" :aria-label="__('Edit')" />
                        <flux:button size="xs" variant="subtle" icon="trash"
                            wire:click="removeHolding({{ $row['holdingId'] }})"
                            wire:confirm="{{ __('Remove this position?') }}" :aria-label="__('Remove')" />
                    </div>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center gap-2 p-10 text-center">
                <flux:icon.inbox class="size-6 text-neutral-400" />
                <flux:text class="text-sm">
                    {{ $manual ? __('This account is empty — add a position above or import a CSV.') : __('This demo account has no holdings.') }}
                </flux:text>
            </div>
        @endforelse
    </div>

    {{-- Edit modal (manual only). --}}
    @if ($manual)
        <flux:modal name="edit-holding" :open="$editingId !== null" wire:model.self="editingId" class="md:w-80">
            <form wire:submit="saveEdit" class="space-y-4">
                <flux:heading size="lg">{{ __('Edit position') }}</flux:heading>
                <flux:input wire:model="editQuantity" type="number" step="any" min="0" dir="ltr" :label="__('Quantity')" />
                <flux:input wire:model="editAvgCost" type="number" step="any" min="0" dir="ltr" :label="__('Avg cost')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="import-holdings" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Import a CSV') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Upload a holdings statement exported from your broker — positions merge into this account.') }}
                    </flux:text>
                </div>

                <flux:file-upload wire:model="statement" accept=".csv,text/csv" :label="__('Holdings statement (CSV)')">
                    <flux:file-upload.dropzone icon="document-arrow-up"
                        :heading="__('Drop your CSV here or click to browse')" :text="__('Up to 1 MB')" />
                </flux:file-upload>
                @if ($statement)
                    <flux:file-item icon="document-text" :heading="$statement->getClientOriginalName()"
                        :size="$statement->getSize()">
                        <x-slot:actions>
                            <flux:button size="xs" variant="subtle" icon="x-mark"
                                wire:click="$set('statement', null)" :aria-label="__('Dismiss')" />
                        </x-slot:actions>
                    </flux:file-item>
                @endif
                <flux:error name="statement" />

                <flux:text class="text-xs">
                    {{ __('Needs a symbol and quantity column — average cost is optional. Common header names (ticker, qty, cost) and Arabic headers are understood.') }}
                    <a class="underline" href="{{ asset('samples/holdings-template.csv') }}" download>
                        {{ __('Download a template') }}</a>
                </flux:text>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="importCsv" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="importCsv, statement">{{ __('Import') }}</span>
                        <span wire:loading wire:target="importCsv, statement">{{ __('Importing…') }}</span>
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
