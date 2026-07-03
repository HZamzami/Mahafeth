<?php

namespace App\Models;

use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use Database\Factories\RiskProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskProfile extends Model
{
    /** @use HasFactory<RiskProfileFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'answers',
        'risk_tolerance',
        'time_horizon',
        'target_return',
        'target_volatility',
        'liquidity_needs',
        'constraints',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'risk_tolerance' => RiskTolerance::class,
            'time_horizon' => TimeHorizon::class,
            'target_return' => 'float',
            'target_volatility' => 'float',
            'constraints' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
