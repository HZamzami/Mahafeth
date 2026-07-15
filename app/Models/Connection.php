<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'institution_id',
        'label',
        'status',
        'source',
        'access_token',
        'refresh_token',
        'last_synced_at',
    ];

    /**
     * A manual account is user-created and has no linked institution — its
     * holdings are entered by CSV or by hand rather than fetched.
     */
    public function isManual(): bool
    {
        return $this->institution_id === null;
    }

    /**
     * The name shown on the card: the user's label for a manual account, or
     * the linked institution's localized name for a demo/API connection.
     */
    public function displayName(): string
    {
        return $this->label ?? $this->institution?->localizedName() ?? '';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'last_synced_at' => 'datetime',
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

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function latestConsent(): HasOne
    {
        return $this->hasOne(Consent::class)->latestOfMany('granted_at');
    }
}
