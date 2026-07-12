<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEvent>
 */
class ActivityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ActivityType::LoggedIn,
            'params' => ['ip' => fake()->ipv4()],
        ];
    }
}
