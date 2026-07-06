<?php

namespace App\Models;

use App\Enums\AssetClass;
use App\Enums\ShariahStatus;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'symbol',
        'name',
        'name_ar',
        'asset_class',
        'sector',
        'country',
        'currency',
        'shariah_status',
        'is_benchmark',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_class' => AssetClass::class,
            'shariah_status' => ShariahStatus::class,
            'is_benchmark' => 'boolean',
        ];
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /**
     * Get the asset name in the current locale.
     */
    public function localizedName(): string
    {
        return app()->getLocale() === 'ar' && $this->name_ar !== null ? $this->name_ar : $this->name;
    }
}
