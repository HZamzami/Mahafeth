<?php

namespace Tests\Feature;

use App\Actions\GenerateInsights;
use App\Actions\SyncConnection;
use App\Models\AiChatMessage;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AiAdvisorPageTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedUser(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        return $user;
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/advisor')->assertRedirect('/login');
    }

    public function test_the_sidebar_links_to_the_advisor(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee(__('AI Advisor'))
            ->assertSee(route('advisor'));
    }

    public function test_users_without_a_snapshot_see_the_connect_prompt(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/advisor')
            ->assertOk()
            ->assertSee(__('Connect accounts'));
    }

    public function test_the_insight_summary_and_recommendations_render_with_discuss_buttons(): void
    {
        $user = $this->analyzedUser();
        app(GenerateInsights::class)->handle($user, 'en');
        $this->actingAs($user);

        Volt::test('advisor.index')
            ->assertSee(__('Executive Summary'))
            ->assertSee(__('Discuss this'))
            ->assertSee(__('Start with one of these, or ask your own question.'));
    }

    public function test_sending_a_message_persists_the_exchange_and_shows_the_answer(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('advisor.index')
            ->set('message', 'What is my risk?')
            ->call('send')
            ->assertSet('message', '')
            ->assertSee('What is my risk?');

        $this->assertSame(2, $user->chatMessages()->count());
        $this->assertSame('user', $user->chatMessages()->oldest('id')->first()->role);

        // The fake responder routes "risk" to the volatility answer.
        $reply = $user->chatMessages()->latest('id')->first();
        $this->assertSame('assistant', $reply->role);
        $this->assertStringContainsString('volatility', $reply->content);
    }

    public function test_blank_messages_are_ignored(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('advisor.index')
            ->set('message', '   ')
            ->call('send');

        $this->assertSame(0, $user->chatMessages()->count());
    }

    public function test_discussing_a_recommendation_seeds_the_chat_with_it(): void
    {
        $user = $this->analyzedUser();
        $insight = app(GenerateInsights::class)->handle($user, 'en');
        $this->actingAs($user);

        Volt::test('advisor.index')->call('discuss', 0);

        $seeded = $user->chatMessages()->oldest('id')->first();

        $this->assertStringContainsString($insight->recommendations[0]['title'], $seeded->content);
        $this->assertSame('assistant', $user->chatMessages()->latest('id')->first()->role);
    }

    public function test_starter_chips_ask_the_suggested_question(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('advisor.index')->call('ask', 1);

        $this->assertSame(__('What is my biggest hidden risk?'), $user->chatMessages()->oldest('id')->first()->content);
    }

    public function test_clearing_the_chat_only_deletes_the_acting_users_messages(): void
    {
        $user = $this->analyzedUser();
        $other = User::factory()->create();
        AiChatMessage::factory()->count(2)->create(['user_id' => $user->id]);
        AiChatMessage::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user);
        Volt::test('advisor.index')->call('clearChat');

        $this->assertSame(0, $user->chatMessages()->count());
        $this->assertSame(1, $other->chatMessages()->count());
    }

    public function test_another_users_messages_never_render(): void
    {
        $user = $this->analyzedUser();
        $other = User::factory()->create();
        AiChatMessage::factory()->create(['user_id' => $other->id, 'content' => 'private message of another user']);

        $this->actingAs($user);

        Volt::test('advisor.index')->assertDontSee('private message of another user');
    }

    public function test_arabic_locale_produces_an_arabic_answer(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        app()->setLocale('ar');

        Volt::test('advisor.index')
            ->set('message', 'ما هي مخاطر محفظتي؟')
            ->call('send');

        $reply = $user->chatMessages()->latest('id')->first();

        $this->assertSame('ar', $reply->locale);
        $this->assertStringContainsString('تقلبك السنوي', $reply->content);
    }
}
