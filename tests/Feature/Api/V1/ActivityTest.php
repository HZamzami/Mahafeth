<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_lists_the_users_events_paginated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        ActivityEvent::record($user, ActivityType::LoggedIn, ['ip' => '127.0.0.1']);
        ActivityEvent::record($user, ActivityType::PasswordChanged, []);

        $response = $this->getJson('/api/v1/activity');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'type', 'category', 'label', 'icon', 'color', 'created_at']], 'links', 'meta']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_activity_filters_by_category(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        ActivityEvent::record($user, ActivityType::LoggedIn, ['ip' => '127.0.0.1']);
        ActivityEvent::record($user, ActivityType::GoalSaved, ['name' => 'Retirement']);

        $response = $this->getJson('/api/v1/activity?category=security');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.type', ActivityType::LoggedIn->value);
    }

    public function test_activity_only_returns_the_authenticated_users_events(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        ActivityEvent::record($other, ActivityType::LoggedIn, ['ip' => '127.0.0.1']);

        $response = $this->getJson('/api/v1/activity');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
