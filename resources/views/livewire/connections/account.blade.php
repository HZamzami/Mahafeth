<?php

use App\Actions\ImportHoldings;
use App\Actions\RebuildAccountHoldings;
use App\Actions\RecordTransaction;
use App\Actions\SyncConnection;
use App\Enums\ActivityType;
use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Services\Analytics\HoldingsSummarizer;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Markets\SymbolSearch;
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

    // Record-transaction form.
    public string $txnType = 'buy';

    public string $txnQuery = '';

    public ?string $txnSymbol = null;

    public ?string $txnName = null;

    /** @var ?array{symbol: string, name: string, exchange?: string, country?: string, currency?: string, type?: string} */
    public ?array $txnMeta = null;

    public ?string $txnQuantity = null;

    public ?string $txnPrice = null;

    public string $txnCurrency = 'SAR';

    public ?string $txnAmount = null;

    public string $txnDate = '';

    public $statement;

    /** @var list<string> */
    public array $importNotices = [];

    public function mount(Account $account): void
    {
        abort_unless($account->connection->user_id === Auth::id(), 404);

        $this->account = $account;
        $this->manual = $account->connection->isManual();
        $this->txnDate = now()->toDateString();
    }

    /**
     * Re-value the whole portfolio after any change to this account.
     */
    private function reanalyze(PortfolioAnalyzer $analyzer): void
    {
        $analyzer->analyze(Auth::user());
    }

    /**
     * Reset the transaction form to a clean slate for the given type.
     */
    public function updatedTxnType(): void
    {
        $this->reset('txnQuery', 'txnSymbol', 'txnName', 'txnMeta', 'txnQuantity', 'txnPrice', 'txnAmount');
        $this->resetErrorBag();
    }

    /**
     * Choose an instrument from the search results. Catalogued instruments
     * carry no metadata (resolved from our catalog); market results pass their
     * search payload through so an uncatalogued symbol gets an Asset.
     *
     * @param  ?array{symbol: string, name: string, exchange?: string, country?: string, currency?: string, type?: string}  $meta
     */
    public function selectInstrument(string $symbol, string $name, ?array $meta = null): void
    {
        $this->txnSymbol = $symbol;
        $this->txnName = $name;
        $this->txnMeta = $meta;
        $this->txnQuery = '';
    }

    public function clearInstrument(): void
    {
        $this->reset('txnSymbol', 'txnName', 'txnMeta', 'txnQuery');
    }

    public function recordTransaction(RecordTransaction $recordTransaction, PortfolioAnalyzer $analyzer): void
    {
        $this->guardManual();

        $type = TransactionType::from($this->txnType);

        if (in_array($type, [TransactionType::Buy, TransactionType::Sell], true)) {
            $this->validate([
                'txnSymbol' => ['required', 'string'],
                'txnQuantity' => ['required', 'numeric', 'min:0.00000001'],
                'txnPrice' => ['required', 'numeric', 'min:0'],
                'txnDate' => ['required', 'date'],
            ]);

            $recordTransaction->handle($this->account, $type, [
                'symbol' => $this->txnSymbol,
                'meta' => $this->txnMeta,
                'quantity' => (float) $this->txnQuantity,
                'price' => (float) $this->txnPrice,
                'executed_at' => $this->txnDate,
            ]);
        } else {
            $this->validate([
                'txnCurrency' => ['required', 'in:SAR,USD'],
                'txnAmount' => ['required', 'numeric', 'min:0.01'],
                'txnDate' => ['required', 'date'],
            ]);

            $recordTransaction->handle($this->account, $type, [
                'currency' => $this->txnCurrency,
                'amount' => (float) $this->txnAmount,
                'executed_at' => $this->txnDate,
            ]);
        }

        $this->reanalyze($analyzer);
        $this->reset('txnQuery', 'txnSymbol', 'txnName', 'txnMeta', 'txnQuantity', 'txnPrice', 'txnAmount');
        $this->txnDate = now()->toDateString();
        $this->modal('record-transaction')->close();
        $this->dispatch('toast', message: __('Transaction recorded.'));
    }

    public function deleteTransaction(int $transactionId, RebuildAccountHoldings $rebuild, PortfolioAnalyzer $analyzer): void
    {
        $this->guardManual();

        $transaction = $this->account->transactions()->with('asset')->findOrFail($transactionId);
        $asset = $transaction->asset;

        $transaction->delete();
        $rebuild->forAsset($this->account, $asset);

        $this->reanalyze($analyzer);
        $this->dispatch('toast', message: __('Transaction deleted.'));
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

    private function guardManual(): void
    {
        abort_unless($this->manual, 403);
    }

    /**
     * Instrument matches for the Buy/Sell picker: the user's catalogued
     * universe first (instant, works offline), then live market results for
     * anything else, so any listed symbol can be added.
     *
     * @return array{catalog: list<array{symbol: string, name: string}>, market: list<array{symbol: string, name: string, exchange: string, country: string, currency: string, type: string}>}
     */
    private function instrumentMatches(AssetCatalog $catalog): array
    {
        $query = trim($this->txnQuery);

        if (! in_array($this->txnType, ['buy', 'sell'], true) || $query === '') {
            return ['catalog' => [], 'market' => []];
        }

        $catalogMatches = array_map(fn (array $item): array => [
            'symbol' => $item['symbol'],
            'name' => $item['name'],
        ], $catalog->investable($query));

        $catalogSymbols = array_column($catalogMatches, 'symbol');

        $market = mb_strlen($query) >= 2
            ? array_values(array_filter(
                app(SymbolSearch::class)->search($query),
                fn (array $match): bool => ! in_array($match['symbol'], $catalogSymbols, true),
            ))
            : [];

        return ['catalog' => $catalogMatches, 'market' => $market];
    }

    public function with(HoldingsSummarizer $summarizer, AssetCatalog $catalog): array
    {
        return [
            'summary' => $summarizer->forAccount($this->account),
            'transactions' => $this->manual
                ? $this->account->transactions()->with('asset')
                    ->orderByDesc('executed_at')->orderByDesc('id')->limit(100)->get()
                : collect(),
            'matches' => $this->manual ? $this->instrumentMatches($catalog) : ['catalog' => [], 'market' => []],
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
                @if ($manual)
                    <flux:modal.trigger name="record-transaction">
                        <flux:button size="sm" variant="primary" icon="plus">{{ __('Record transaction') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="sync"
                        wire:loading.attr="disabled" :tooltip="__('Sync')" :aria-label="__('Sync')" />
                @endif
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

    {{-- Holdings breakdown, derived from the ledger. --}}
    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Holdings') }}</flux:heading>
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
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center">
                    <flux:icon.inbox class="size-6 text-neutral-400" />
                    <flux:text class="text-sm">
                        @if ($manual)
                            {{ __('This account is empty — record a transaction or import a CSV to get started.') }}
                        @else
                            {{ __('This demo account has no holdings.') }}
                        @endif
                    </flux:text>
                    @if ($manual)
                        <flux:modal.trigger name="record-transaction">
                            <flux:button size="sm" variant="primary" icon="plus" class="mt-1">
                                {{ __('Record transaction') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Transaction ledger (manual only). --}}
    @if ($manual)
        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Transactions') }}</flux:heading>
                <flux:modal.trigger name="import-holdings">
                    <flux:button size="sm" variant="ghost" icon="document-arrow-up">{{ __('Import a CSV') }}</flux:button>
                </flux:modal.trigger>
            </div>

            <div class="flex flex-col card">
                @forelse ($transactions as $transaction)
                    @php($isCash = in_array($transaction->type, [TransactionType::Deposit, TransactionType::Withdrawal], true))
                    @php($isPositive = in_array($transaction->type, [TransactionType::Buy, TransactionType::Deposit], true))
                    <div wire:key="txn-{{ $transaction->id }}"
                        class="flex items-center gap-3 px-5 py-3.5 {{ ! $loop->last ? 'border-b border-neutral-100 dark:border-zinc-800' : '' }}">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $isPositive ? 'bg-teal-500/10' : 'bg-amber-500/10' }}">
                            <flux:icon @class([
                                'size-4',
                                'text-teal-700 dark:text-teal-300' => $isPositive,
                                'text-amber-700 dark:text-amber-300' => ! $isPositive,
                            ]) :name="match ($transaction->type) {
                                App\Enums\TransactionType::Buy => 'arrow-down-left',
                                App\Enums\TransactionType::Sell => 'arrow-up-right',
                                App\Enums\TransactionType::Deposit => 'plus',
                                default => 'minus',
                            }" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium !text-zinc-800 dark:!text-white">
                                {{ $transaction->type->label() }}
                                @unless ($isCash)
                                    · <bdi dir="ltr">{{ $transaction->asset->symbol }}</bdi>
                                @endunless
                            </flux:text>
                            <flux:text class="text-xs" dir="ltr">
                                {{ $transaction->executed_at->translatedFormat('d M Y') }}
                                @unless ($isCash)
                                    · {{ rtrim(rtrim(number_format($transaction->quantity, 4), '0'), '.') }}
                                    @ {{ Number::format($transaction->price, 2) }}
                                @endunless
                            </flux:text>
                        </div>
                        <flux:text class="shrink-0 font-medium tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                            {{ $isPositive ? '+' : '−' }}{{ Asset::symbolForCurrency($transaction->asset->currency) }}{{ Number::format($transaction->amount, 2) }}
                        </flux:text>
                        <flux:button size="xs" variant="subtle" icon="trash"
                            wire:click="deleteTransaction({{ $transaction->id }})"
                            wire:confirm="{{ __('Delete this transaction? Your holdings will be recalculated.') }}"
                            :aria-label="__('Delete')" />
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 p-10 text-center">
                        <flux:icon.receipt-percent class="size-6 text-neutral-400" />
                        <flux:text class="text-sm">{{ __('No transactions yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>

            <flux:text class="mt-2 text-xs">
                {{ __('Buys and sells build your positions with cost basis; deposits and withdrawals track cash separately.') }}
            </flux:text>
        </div>

        {{-- Record-transaction modal. --}}
        <flux:modal name="record-transaction" class="md:w-[28rem]">
            <form wire:submit="recordTransaction" class="space-y-5">
                <flux:heading size="lg">{{ __('Record transaction') }}</flux:heading>

                <flux:radio.group wire:model.live="txnType" variant="segmented" size="sm">
                    <flux:radio value="buy" :label="__('Buy')" />
                    <flux:radio value="sell" :label="__('Sell')" />
                    <flux:radio value="deposit" :label="__('Deposit')" />
                    <flux:radio value="withdrawal" :label="__('Withdraw')" />
                </flux:radio.group>

                @if (in_array($txnType, ['buy', 'sell']))
                    {{-- Instrument picker. --}}
                    @if ($txnSymbol !== null)
                        <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:avatar size="sm" color="auto" :name="$txnSymbol" :initials="mb_substr($txnSymbol, 0, 2)" />
                            <div class="min-w-0 flex-1">
                                <flux:text class="truncate font-medium !text-zinc-800 dark:!text-white"><bdi>{{ $txnName }}</bdi></flux:text>
                                <flux:text class="text-xs" dir="ltr">{{ $txnSymbol }}</flux:text>
                            </div>
                            <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="clearInstrument"
                                :aria-label="__('Change instrument')" />
                        </div>
                    @else
                        <div>
                            <flux:input icon="magnifying-glass" wire:model.live.debounce.350ms="txnQuery"
                                :label="__('Instrument')" :placeholder="__('Search a stock, fund, or crypto…')" />

                            @if (trim($txnQuery) !== '')
                                <div class="mt-2 max-h-64 divide-y divide-zinc-100 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700"
                                    wire:loading.class="opacity-60" wire:target="txnQuery">
                                    @forelse ($matches['catalog'] as $item)
                                        <button type="button" wire:key="cat-{{ $item['symbol'] }}"
                                            wire:click="selectInstrument('{{ $item['symbol'] }}', @js($item['name']))"
                                            class="flex w-full items-center gap-3 px-3 py-2.5 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700/50">
                                            <flux:avatar size="sm" color="auto" :name="$item['symbol']" :initials="mb_substr($item['symbol'], 0, 2)" />
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white"><bdi>{{ $item['name'] }}</bdi></span>
                                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400"><bdi dir="ltr">{{ $item['symbol'] }}</bdi></span>
                                            </span>
                                        </button>
                                    @empty
                                    @endforelse

                                    @foreach ($matches['market'] as $match)
                                        <button type="button" wire:key="mkt-{{ $match['symbol'] }}"
                                            wire:click="selectInstrument('{{ $match['symbol'] }}', @js($match['name']), @js($match))"
                                            class="flex w-full items-center gap-3 px-3 py-2.5 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700/50">
                                            <flux:avatar size="sm" color="auto" :name="$match['symbol']" :initials="mb_substr($match['symbol'], 0, 2)" />
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white"><bdi>{{ $match['name'] }}</bdi></span>
                                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400"><bdi dir="ltr">{{ $match['symbol'] }}{{ $match['exchange'] !== '' ? ' • '.$match['exchange'] : '' }}</bdi></span>
                                            </span>
                                        </button>
                                    @endforeach

                                    @if ($matches['catalog'] === [] && $matches['market'] === [])
                                        <flux:text class="p-4 text-center text-sm" wire:loading.remove wire:target="txnQuery">
                                            {{ __('No instruments found for :query.', ['query' => trim($txnQuery)]) }}</flux:text>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <flux:error name="txnSymbol" />
                    @endif

                    <div class="grid grid-cols-2 gap-3">
                        <flux:field>
                            <flux:label>{{ __('Quantity') }}</flux:label>
                            <flux:input wire:model="txnQuantity" type="number" step="any" min="0" dir="ltr" placeholder="100" />
                            <flux:error name="txnQuantity" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ $txnType === 'sell' ? __('Sale price') : __('Price') }}</flux:label>
                            <flux:input wire:model="txnPrice" type="number" step="any" min="0" dir="ltr" placeholder="0" />
                            <flux:error name="txnPrice" />
                        </flux:field>
                    </div>
                @else
                    <div class="grid grid-cols-[auto_1fr] gap-3">
                        <flux:field class="w-28">
                            <flux:label>{{ __('Currency') }}</flux:label>
                            <flux:select wire:model="txnCurrency">
                                <flux:select.option value="SAR">SAR</flux:select.option>
                                <flux:select.option value="USD">USD</flux:select.option>
                            </flux:select>
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Amount') }}</flux:label>
                            <flux:input wire:model="txnAmount" type="number" step="any" min="0" dir="ltr" placeholder="50000" />
                            <flux:error name="txnAmount" />
                        </flux:field>
                    </div>
                @endif

                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:date-picker wire:model="txnDate" :max="now()->toDateString()" />
                    <flux:error name="txnDate" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- CSV import modal. --}}
        <flux:modal name="import-holdings" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Import a CSV') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Upload a holdings statement exported from your broker — each position is added as an opening buy.') }}
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
