<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Models\Asset;
use App\Services\Markets\SymbolSearch;
use Illuminate\Http\JsonResponse;

class ExploreController extends Controller
{
    public function search(SearchRequest $request, SymbolSearch $symbolSearch): JsonResponse
    {
        $query = $request->validated('query');

        $owned = Asset::where('symbol', 'like', "%{$query}%")
            ->orWhere('name', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->map(fn (Asset $asset): array => [
                'symbol' => $asset->symbol,
                'name' => $asset->localizedName(),
                'exchange' => '',
                'country' => $asset->country ?? '',
                'currency' => $asset->currency,
                'type' => $asset->asset_class->value,
                'owned' => true,
            ])
            ->all();

        $searched = array_map(
            fn (array $match): array => [...$match, 'owned' => false],
            $symbolSearch->search($query),
        );

        $results = collect([...$owned, ...$searched])->unique('symbol')->values()->all();

        return response()->json(['data' => $results]);
    }
}
