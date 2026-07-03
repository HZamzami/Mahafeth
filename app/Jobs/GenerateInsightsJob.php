<?php

namespace App\Jobs;

use App\Actions\GenerateInsights;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInsightsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public string $locale) {}

    public function uniqueId(): string
    {
        return $this->user->id.':'.$this->locale;
    }

    public function handle(GenerateInsights $generateInsights): void
    {
        $generateInsights->handle($this->user, $this->locale);
    }
}
