<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'institution_id',
        'connection_id',
        'scopes',
        'status',
        'granted_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'status' => ConsentStatus::class,
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConsentStatus::Active && $this->expires_at->isFuture();
    }

    public function daysUntilExpiry(): int
    {
        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }
}
