<?php

namespace App\Models;

use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-defined alert threshold on one portfolio metric. Evaluated by
 * AlertEvaluator alongside the built-in alerts, so custom alerts share the
 * same dashboard rendering, dismissal, and notification paths.
 */
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    /**
     * The metrics a rule can watch. Ratio metrics store the threshold as a
     * decimal fraction and alert when the value rises above it; the health
     * score stores whole points and alerts when it falls below.
     *
     * @var array<string, array{direction: string, unit: string, label: string, key: string}>
     */
    public const METRICS = [
        'volatility' => [
            'direction' => 'above',
            'unit' => 'percent',
            'label' => 'Annualized volatility',
            'key' => 'Custom alert: portfolio volatility of :value is above your :threshold limit.',
        ],
        'largest_position' => [
            'direction' => 'above',
            'unit' => 'percent',
            'label' => 'Largest position weight',
            'key' => 'Custom alert: your largest position is :value of the portfolio — above your :threshold limit.',
        ],
        'max_drawdown' => [
            'direction' => 'above',
            'unit' => 'percent',
            'label' => 'Maximum drawdown',
            'key' => 'Custom alert: a maximum drawdown of :value is above your :threshold limit.',
        ],
        'health_score' => [
            'direction' => 'below',
            'unit' => 'points',
            'label' => 'Health score',
            'key' => 'Custom alert: your health score of :value fell below your :threshold floor.',
        ],
        'allocation_drift' => [
            'direction' => 'above',
            'unit' => 'percent',
            'label' => 'Allocation drift vs plan',
            'key' => 'Custom alert: your largest drift from the plan is :value — above your :threshold limit.',
        ],
    ];

    protected $fillable = ['metric', 'threshold', 'enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
