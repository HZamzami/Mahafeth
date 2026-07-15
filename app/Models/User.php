<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\ObligationKind;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

class User extends Authenticatable implements HasLocalePreference, HasPasskeys // implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, InteractsWithPasskeys, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'locale',
        'notify_alerts',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_alerts' => 'array',
            'email_verified_at' => 'datetime',
            'notify_alerts' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Notification mails render in the user's preferred locale.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? config('app.locale');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function riskProfile(): HasOne
    {
        return $this->hasOne(RiskProfile::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }

    public function obligationSettlements(): HasMany
    {
        return $this->hasMany(ObligationSettlement::class);
    }

    /**
     * The date through which an obligation kind was last settled.
     */
    public function settledThrough(ObligationKind $kind): ?Carbon
    {
        $latest = $this->obligationSettlements()->where('kind', $kind)->max('settled_through');

        return $latest !== null ? Carbon::parse($latest) : null;
    }

    /**
     * Get the user's latest portfolio snapshot.
     */
    public function latestSnapshot(): ?PortfolioSnapshot
    {
        return $this->portfolioSnapshots()->latest('as_of')->first();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
