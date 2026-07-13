<?php

namespace App\Models;

use Database\Factories\InvestmentPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed starter portfolio for one investor: the allocation matched
 * to their IPS target volatility, the concrete buy orders for a starting
 * amount, and the Monte Carlo growth projection. One live plan per user.
 */
class InvestmentPlan extends Model
{
    /** @use HasFactory<InvestmentPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'monthly_contribution',
        'weights',
        'orders',
        'metrics',
        'forecast',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'monthly_contribution' => 'float',
            'weights' => 'array',
            'orders' => 'array',
            'metrics' => 'array',
            'forecast' => 'array',
        ];
    }
}
