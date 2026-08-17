<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use App\Models\CompanyFiling;
use App\Models\NewsItem;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class HoldingDetailResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $stats
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, CompanyFiling>  $filings
     * @param  Collection<int, NewsItem>  $news
     */
    public function __construct(
        private readonly Asset $asset,
        private readonly array $stats,
        private readonly float $quantity,
        private readonly ?float $avgCost,
        private readonly ?float $value,
        private readonly ?float $pl,
        private readonly ?float $plPct,
        private readonly float $realized,
        private readonly ?float $weight,
        private readonly ?float $sectorExposure,
        private readonly Collection $transactions,
        private readonly Collection $filings,
        private readonly Collection $news,
    ) {
        parent::__construct($asset);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'symbol' => $this->asset->symbol,
            'name' => $this->asset->localizedName(),
            'asset_class' => $this->asset->asset_class->value,
            'sector' => $this->asset->sector,
            'currency' => $this->asset->currency,
            'shariah_status' => $this->asset->shariah_status->value,
            ...$this->stats,
            'quantity' => $this->quantity,
            'avg_cost' => $this->avgCost,
            'value' => $this->value,
            'pl' => $this->pl,
            'pl_pct' => $this->plPct,
            'realized' => $this->realized,
            'weight' => $this->weight,
            'sector_exposure' => $this->sectorExposure,
            'transactions' => $this->transactions->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'quantity' => (float) $transaction->quantity,
                'price' => (float) $transaction->price,
                'executed_at' => $transaction->executed_at->toIso8601String(),
            ])->all(),
            'filings' => $this->filings->map(fn (CompanyFiling $filing): array => [
                'type' => $filing->typeLabel(),
                'headline' => $filing->localizedHeadline(),
                'source' => $filing->source,
                'url' => $filing->url,
                'published_at' => $filing->published_at->toIso8601String(),
            ])->all(),
            'news' => $this->news->map(fn (NewsItem $item): array => [
                'headline' => $item->localizedHeadline(),
                'source' => $item->source,
                'url' => $item->url,
                'published_at' => $item->published_at->toIso8601String(),
            ])->all(),
        ];
    }
}
