<?php

namespace App\Models;

use Database\Factories\AiChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatMessage extends Model
{
    /** @use HasFactory<AiChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'content',
        'locale',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
