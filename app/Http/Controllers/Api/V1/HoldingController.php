<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HoldingDetailResource;
use App\Models\Asset;
use App\Models\CompanyFiling;
use App\Models\Holding;
use App\Models\NewsItem;
use App\Models\Transaction;
use App\Services\Analytics\DividendProjector;
use App\Services\Analytics\HoldingsSummarizer;
use App\Services\Analytics\RealizedGainCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingController extends Controller
{
    public function index(Request $request, HoldingsSummarizer $summarizer): JsonResponse
    {
        return response()->json(['data' => $summarizer->rows($request->user())]);
    }

    public function incomeCalendar(Request $request, DividendProjector $projector): JsonResponse
    {
        return response()->json(['data' => $projector->calendar($request->user()) ?? ['months' => [], 'trailing_total' => 0.0, 'projected_total' => 0.0]]);
    }

    public function show(Request $request, Asset $asset, RealizedGainCalculator $realizedGainCalculator): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->userHoldings($asset, $request)->exists(), 404);

        $holdings = $this->userHoldings($asset, $request)->get();
        $quantity = $holdings->sum(fn (Holding $holding): float => (float) $holding->quantity);
        $cost = $holdings->sum(fn (Holding $holding): float => $holding->quantity * $holding->avg_cost);

        $stats = $this->priceStats($asset);
        $price = $stats['price'];
        $value = $price !== null ? $quantity * $price : null;

        $metrics = $user->latestSnapshot()?->metrics;

        return response()->json([
            'data' => (new HoldingDetailResource(
                asset: $asset,
                stats: $stats,
                quantity: $quantity,
                avgCost: $quantity > 0 ? $cost / $quantity : null,
                value: $value,
                pl: $value !== null ? $value - $cost : null,
                plPct: $value !== null && $cost > 0 ? ($value - $cost) / $cost : null,
                realized: $realizedGainCalculator->forAsset($user, $asset),
                weight: $metrics['weights'][$asset->symbol] ?? null,
                sectorExposure: $asset->sector !== null ? ($metrics['allocations']['sector'][$asset->sector] ?? null) : null,
                transactions: Transaction::whereBelongsTo($asset)
                    ->whereHas('account.connection', fn ($query) => $query
                        ->whereBelongsTo($user)
                        ->where('status', ConnectionStatus::Connected))
                    ->latest('executed_at')
                    ->limit(6)
                    ->get(),
                filings: CompanyFiling::where('symbol', $asset->symbol)->latest('published_at')->limit(3)->get(),
                news: NewsItem::latest('published_at')->get()
                    ->filter(fn (NewsItem $item): bool => in_array($asset->symbol, $item->symbols ?? [], true))
                    ->take(3),
            ))->resolve($request),
        ]);
    }

    /**
     * Day change, trailing returns, and the 52-week range from stored closes,
     * in the asset's native currency.
     *
     * @return array{price: ?float, dayChange: ?float, dayChangePct: ?float, returns: array<string, ?float>, week52High: ?float, week52Low: ?float, week52Position: ?float}
     */
    private function priceStats(Asset $asset): array
    {
        $closes = $asset->priceHistories()
            ->where('date', '>=', now()->subDays(370))
            ->orderBy('date')
            ->pluck('close')
            ->map(fn (float $close): float => $close)
            ->values();

        if ($closes->count() < 2) {
            return [
                'price' => $closes->last(),
                'dayChange' => null,
                'dayChangePct' => null,
                'returns' => ['1W' => null, '1M' => null, '3M' => null, '1Y' => null],
                'week52High' => null,
                'week52Low' => null,
                'week52Position' => null,
            ];
        }

        $price = $closes->last();
        $previous = $closes->get($closes->count() - 2);

        $returnFor = function (int $tradingDays) use ($closes, $price): ?float {
            $base = $closes->get(max(0, $closes->count() - 1 - $tradingDays));

            return $base > 0 ? $price / $base - 1 : null;
        };

        $high = $closes->max();
        $low = $closes->min();

        return [
            'price' => $price,
            'dayChange' => $price - $previous,
            'dayChangePct' => $previous > 0 ? $price / $previous - 1 : null,
            'returns' => [
                '1W' => $returnFor(5),
                '1M' => $returnFor(21),
                '3M' => $returnFor(63),
                '1Y' => $returnFor(252),
            ],
            'week52High' => $high,
            'week52Low' => $low,
            'week52Position' => $high > $low ? ($price - $low) / ($high - $low) : null,
        ];
    }

    private function userHoldings(Asset $asset, Request $request)
    {
        return Holding::whereBelongsTo($asset)
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($request->user())
                ->where('status', ConnectionStatus::Connected));
    }
}
