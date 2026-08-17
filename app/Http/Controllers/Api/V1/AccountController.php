<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ImportHoldings;
use App\Actions\RebuildAccountHoldings;
use App\Actions\RecordTransaction;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportStatementRequest;
use App\Http\Requests\Api\V1\RecordTransactionRequest;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\Account;
use App\Services\Analytics\HoldingsSummarizer;
use App\Services\Imports\HoldingsStatementParser;
use App\Services\Markets\SymbolSearch;
use App\Services\OpenBanking\AssetCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AccountController extends Controller
{
    public function show(Request $request, Account $account, HoldingsSummarizer $summarizer): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        return response()->json([
            'data' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency' => $account->currency,
                'is_manual' => $account->connection->isManual(),
                ...$summarizer->forAccount($account),
                'transactions' => $account->transactions()->with('asset')->latest('executed_at')->limit(50)->get()
                    ->map(fn ($transaction): array => [
                        'id' => $transaction->id,
                        'type' => $transaction->type->value,
                        'symbol' => $transaction->asset?->symbol,
                        'quantity' => $transaction->quantity !== null ? (float) $transaction->quantity : null,
                        'price' => $transaction->price !== null ? (float) $transaction->price : null,
                        'amount' => $transaction->amount !== null ? (float) $transaction->amount : null,
                        'executed_at' => $transaction->executed_at->toIso8601String(),
                    ]),
            ],
        ]);
    }

    public function storeTransaction(RecordTransactionRequest $request, Account $account, RecordTransaction $recordTransaction): JsonResponse
    {
        $this->authorizeAccount($request, $account);
        $this->guardManual($account);

        $type = TransactionType::from($request->validated('type'));
        $executedAt = $request->validated('executed_at') ?? now()->toDateString();

        if (in_array($type, [TransactionType::Buy, TransactionType::Sell], true)) {
            $recordTransaction->handle($account, $type, [
                'symbol' => $request->validated('symbol'),
                'meta' => $request->validated('meta'),
                'quantity' => (float) $request->validated('quantity'),
                'price' => (float) $request->validated('price'),
                'executed_at' => $executedAt,
            ]);
        } else {
            $recordTransaction->handle($account, $type, [
                'currency' => $request->validated('currency'),
                'amount' => (float) $request->validated('amount'),
                'executed_at' => $executedAt,
            ]);
        }

        AnalyzePortfolioJob::dispatch($request->user());

        return response()->json(['data' => ['message' => __('Transaction recorded.')]], 201);
    }

    public function destroyTransaction(Request $request, Account $account, int $transaction, RebuildAccountHoldings $rebuild): JsonResponse
    {
        $this->authorizeAccount($request, $account);
        $this->guardManual($account);

        $transactionModel = $account->transactions()->with('asset')->findOrFail($transaction);
        $asset = $transactionModel->asset;

        $transactionModel->delete();
        $rebuild->forAsset($account, $asset);

        AnalyzePortfolioJob::dispatch($request->user());

        return response()->json(['data' => ['message' => __('Transaction deleted.')]]);
    }

    public function import(ImportStatementRequest $request, Account $account, HoldingsStatementParser $parser, ImportHoldings $importHoldings): JsonResponse
    {
        $this->authorizeAccount($request, $account);
        $this->guardManual($account);

        if (! RateLimiter::attempt('import-holdings:'.$request->user()->id, maxAttempts: 10, callback: fn () => true)) {
            return response()->json(['message' => __('Too many imports. Please wait a minute and try again.')], 429);
        }

        $result = $parser->parse($request->file('statement')->get());

        if ($result['rows'] === []) {
            return response()->json([
                'message' => $result['errors'][0] ?? __('No holdings found in the file.'),
            ], 422);
        }

        $importHoldings->intoAccount($account, $result['rows']);
        AnalyzePortfolioJob::dispatch($request->user());

        return response()->json([
            'data' => ['imported' => count($result['rows']), 'notices' => $result['errors']],
        ], 201);
    }

    public function instrumentSearch(Request $request, AssetCatalog $catalog, SymbolSearch $symbolSearch): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        $currency = (string) $request->query('currency', 'SAR');

        if ($query === '') {
            return response()->json(['data' => ['catalog' => [], 'market' => []]]);
        }

        $catalogMatches = array_map(fn (array $item): array => [
            'symbol' => $item['symbol'],
            'name' => $item['name'],
        ], $catalog->investable($query, $currency));

        $catalogSymbols = array_column($catalogMatches, 'symbol');

        $market = mb_strlen($query) >= 2
            ? array_values(array_filter(
                $symbolSearch->search($query),
                fn (array $match): bool => $match['currency'] === $currency && ! in_array($match['symbol'], $catalogSymbols, true),
            ))
            : [];

        return response()->json(['data' => ['catalog' => $catalogMatches, 'market' => $market]]);
    }

    private function authorizeAccount(Request $request, Account $account): void
    {
        abort_unless($account->connection->user_id === $request->user()->id, 404);
    }

    private function guardManual(Account $account): void
    {
        abort_unless($account->connection->isManual(), 403);
    }
}
