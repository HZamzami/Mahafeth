<?php

namespace App\Models;

use App\Enums\ObligationKind;
use Database\Factories\ObligationSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's record of settling a religious financial obligation — zakat or
 * stock purification — through a given date. Outstanding amounts accrue
 * only after the latest settled_through, so a paid obligation stops
 * showing until new income or a new hawl brings it back.
 */
class ObligationSettlement extends Model
{
    /** @use HasFactory<ObligationSettlementFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'kind', 'amount', 'settled_through', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => ObligationKind::class,
            'amount' => 'float',
            'settled_through' => 'date',
        ];
    }
}
