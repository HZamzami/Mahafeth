<?php

namespace App\Models;

use Database\Factories\AiInsightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInsight extends Model
{
    /** @use HasFactory<AiInsightFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'portfolio_snapshot_id',
        'locale',
        'summary',
        'recommendations',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommendations' => 'array',
        ];
    }

    public function portfolioSnapshot(): BelongsTo
    {
        return $this->belongsTo(PortfolioSnapshot::class);
    }
}
