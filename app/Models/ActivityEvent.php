<?php

namespace App\Models;

use App\Enums\ActivityType;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'params',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'params' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append an event to the user's activity feed. Params hold raw values
     * keyed for the type's translation string, so the feed renders in
     * whichever locale it is read in.
     *
     * @param  array<string, mixed>  $params
     */
    public static function record(User $user, ActivityType $type, array $params = []): self
    {
        return self::create([
            'user_id' => $user->id,
            'type' => $type,
            'params' => $params,
        ]);
    }
}
