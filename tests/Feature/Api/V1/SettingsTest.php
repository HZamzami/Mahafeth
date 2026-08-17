<?php

namespace Tests\Feature\Api\V1;

use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_be_viewed_and_updated(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/settings/profile')->assertOk()->assertJsonPath('data.name', 'Old Name');

        $response = $this->putJson('/api/v1/settings/profile', [
            'name' => 'New Name',
            'email' => $user->email,
            'notify_alerts' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $response->assertJsonPath('data.notify_alerts', false);
    }

    public function test_updating_email_clears_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/settings/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_password_can_be_updated_with_correct_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/settings/password', [
            'current_password' => 'password',
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-strong-password', $user->fresh()->password));
    }

    public function test_password_update_rejects_wrong_current_password(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/settings/password', [
            'current_password' => 'wrong',
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ])->assertStatus(422);
    }

    public function test_alert_rules_can_be_created_toggled_and_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $create = $this->postJson('/api/v1/settings/alert-rules', [
            'metric' => 'volatility',
            'threshold' => 25,
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.threshold', 25);
        $ruleId = $create->json('data.id');

        $this->getJson('/api/v1/settings/alert-rules')->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/settings/alert-rules/{$ruleId}/toggle")->assertOk()->assertJsonPath('data.enabled', false);

        $this->deleteJson("/api/v1/settings/alert-rules/{$ruleId}")->assertOk();
        $this->assertDatabaseMissing('alert_rules', ['id' => $ruleId]);
    }

    public function test_an_alert_rule_cannot_be_toggled_by_another_user(): void
    {
        $owner = User::factory()->create();
        $rule = AlertRule::factory()->for($owner)->create(['metric' => 'volatility']);

        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/settings/alert-rules/{$rule->id}/toggle")->assertNotFound();
    }

    public function test_zakat_hawl_date_can_be_saved_and_cleared(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/settings/zakat', ['hawl_month' => 9, 'hawl_day' => 1])
            ->assertOk()
            ->assertJsonPath('data.hawl_month', 9);

        $this->deleteJson('/api/v1/settings/zakat')
            ->assertOk()
            ->assertJsonPath('data.hawl_month', null);
    }

    public function test_sessions_lists_tokens_and_marks_the_current_one(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('iPhone');

        // A real token header, not Sanctum::actingAs()'s fake guard, so
        // currentAccessToken() resolves to the actual persisted token.
        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/settings/sessions');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.is_current', true);
    }

    public function test_a_specific_session_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Old iPad');
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/settings/sessions/{$token->accessToken->id}")->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_account_can_be_deleted_with_correct_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/settings/account', ['current_password' => 'password'])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_account_deletion_requires_the_correct_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/settings/account', ['current_password' => 'wrong'])->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
