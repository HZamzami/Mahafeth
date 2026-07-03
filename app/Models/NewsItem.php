<?php

namespace App\Models;

use Database\Factories\NewsItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model
{
    /** @use HasFactory<NewsItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'headline',
        'headline_ar',
        'source',
        'minutes',
        'symbols',
        'sectors',
        'published_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'sectors' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Get the headline in the current locale.
     */
    public function localizedHeadline(): string
    {
        return app()->getLocale() === 'ar' ? $this->headline_ar : $this->headline;
    }
}
