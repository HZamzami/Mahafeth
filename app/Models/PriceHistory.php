<?php

namespace App\Models;

use Database\Factories\PriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    /** @use HasFactory<PriceHistoryFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'asset_id',
        'date',
        'close',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'close' => 'float',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Get the latest closing price per asset.
     *
     * @param  list<int>  $assetIds
     * @return array<int, float> asset_id => close
     */
    public static function latestCloses(array $assetIds): array
    {
        $latestDates = static::selectRaw('asset_id, MAX(date) AS max_date')
            ->whereIn('asset_id', $assetIds)
            ->groupBy('asset_id');

        return static::joinSub($latestDates, 'latest', function ($join) {
            $join->on('price_histories.asset_id', '=', 'latest.asset_id')
                ->on('price_histories.date', '=', 'latest.max_date');
        })
            ->pluck('close', 'price_histories.asset_id')
            ->map(fn ($close): float => (float) $close)
            ->all();
    }
}
