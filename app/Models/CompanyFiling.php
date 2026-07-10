<?php

namespace App\Models;

use Database\Factories\CompanyFilingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyFiling extends Model
{
    /** @use HasFactory<CompanyFilingFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'headline',
        'headline_ar',
        'symbol',
        'type',
        'source',
        'url',
        'excerpt',
        'excerpt_ar',
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

    /**
     * Get the excerpt in the current locale.
     */
    public function localizedExcerpt(): string
    {
        return app()->getLocale() === 'ar' ? $this->excerpt_ar : $this->excerpt;
    }

    /**
     * The translated label for the filing type.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'quarterly_report' => __('Quarterly Report'),
            'annual_report' => __('Annual Report'),
            'dividend' => __('Dividend'),
            default => __('Announcement'),
        };
    }
}
