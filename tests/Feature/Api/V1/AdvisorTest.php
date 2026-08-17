<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\GenerateChatReplyJob;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_are_listed_oldest_first_paginated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        AiChatMessage::factory()->for($user)->create(['role' => 'user', 'content' => 'Hi']);
        AiChatMessage::factory()->for($user)->create(['role' => 'assistant', 'content' => 'Hello']);

        $response = $this->getJson('/api/v1/advisor/messages');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'role', 'content', 'created_at']], 'links', 'meta']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_sending_a_message_persists_it_and_queues_a_reply(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/advisor/messages', ['content' => 'How is my portfolio doing?']);

        $response->assertStatus(202);
        $response->assertJsonPath('data.role', 'user');
        $this->assertDatabaseHas('ai_chat_messages', ['user_id' => $user->id, 'role' => 'user', 'content' => 'How is my portfolio doing?']);
        Queue::assertPushed(GenerateChatReplyJob::class, fn (GenerateChatReplyJob $job): bool => $job->user->is($user));
    }

    public function test_sending_a_message_while_a_reply_is_in_flight_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinute());

        $this->postJson('/api/v1/advisor/messages', ['content' => 'Another question'])->assertStatus(409);
    }

    public function test_status_reflects_the_awaiting_and_partial_cache_flags(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/advisor/messages', ['content' => 'How is my portfolio doing?']);

        $response = $this->getJson('/api/v1/advisor/status');

        $response->assertOk();
        $response->assertJsonPath('data.awaiting', true);
        $response->assertJsonPath('data.failed', false);
    }
}
