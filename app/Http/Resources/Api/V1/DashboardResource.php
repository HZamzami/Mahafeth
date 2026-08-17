<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PortfolioSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function __construct(
        private readonly PortfolioSnapshot $snapshot,
        private readonly ?PortfolioSnapshot $previous,
    ) {
        parent::__construct($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metrics = $this->snapshot->metrics ?? [];

        return [
            'has_snapshot' => true,
            'as_of' => $this->snapshot->as_of->toDateString(),
            'total_value' => $this->snapshot->total_value,
            'total_value_delta' => $this->previous !== null
                ? round($this->snapshot->total_value - $this->previous->total_value, 2)
                : null,
            'health_score' => $this->snapshot->health_score,
            'health_score_delta' => $this->previous?->health_score !== null && $this->snapshot->health_score !== null
                ? $this->snapshot->health_score - $this->previous->health_score
                : null,
            'component_scores' => $this->snapshot->component_scores,
            'top_holdings' => $this->topHoldings($metrics['holdings'] ?? []),
            'allocations' => $metrics['allocations'] ?? null,
        ];
    }

    /**
     * The 5 largest holdings by weight, from the already-computed snapshot.
     *
     * @param  array<string, array{name: string, value: float, weight: float, currency: string}>  $holdings
     * @return list<array{symbol: string, name: string, value: float, weight: float, currency: string}>
     */
    private function topHoldings(array $holdings): array
    {
        uasort($holdings, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return collect(array_slice($holdings, 0, 5, preserve_keys: true))
            ->map(fn (array $holding, string $symbol): array => [
                'symbol' => $symbol,
                'name' => $holding['name'],
                'value' => $holding['value'],
                'weight' => $holding['weight'],
                'currency' => $holding['currency'],
            ])
            ->values()
            ->all();
    }
}
