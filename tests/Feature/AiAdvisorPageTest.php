<?php

namespace Tests\Feature;

use App\Actions\GenerateChatReply;
use App\Actions\GenerateInsights;
use App\Actions\SyncConnection;
use App\Contracts\ChatResponder;
use App\Jobs\GenerateChatReplyJob;
use App\Models\AiChatMessage;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee(__('View the action plan'))
            ->assertSee(__('Discuss this'))
            ->assertSee(__('Show the math'))
            ->assertSee(__('Start with one of these, or ask your own question.'))
            ->assertSeeHtml('data-flux-composer');
    }

    public function test_sending_a_message_persists_the_exchange_and_shows_the_answer(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        // The sync queue runs the reply job inline.
        Volt::test('advisor.index')
            ->set('message', 'What is my risk?')
            ->call('send')
            ->assertSet('message', '')
            ->assertSee('What is my risk?');

        $this->assertSame(2, $user->chatMessages()->count());
        $this->assertSame('user', $user->chatMessages()->oldest('id')->first()->role);
        $this->assertFalse(Cache::has(GenerateChatReplyJob::awaitingCacheKey($user)));

        // The fake responder routes "risk" to the volatility answer.
        $reply = $user->chatMessages()->latest('id')->first();
        $this->assertSame('assistant', $reply->role);
        $this->assertStringContainsString('volatility', $reply->content);
    }

    public function test_sending_a_message_queues_the_reply_and_shows_the_typing_indicator(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        Queue::fake();

        Volt::test('advisor.index')
            ->set('message', 'What is my risk?')
            ->call('send')
            ->assertSet('message', '')
            ->assertSee('What is my risk?')
            ->assertSee(__('Mahafeth AI is thinking…'));

        $this->assertSame(1, $user->chatMessages()->count());
        $this->assertTrue(Cache::has(GenerateChatReplyJob::awaitingCacheKey($user)));
        Queue::assertPushed(GenerateChatReplyJob::class, 1);
    }

    public function test_sends_are_blocked_while_a_reply_is_pending(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinute());
        Queue::fake();

        Volt::test('advisor.index')
            ->set('message', 'Another question')
            ->call('send')
            ->assertSet('message', 'Another question')
            ->assertSee(__('Mahafeth AI is still answering — please wait for the reply to finish.'));

        $this->assertSame(0, $user->chatMessages()->count());
        Queue::assertNothingPushed();
    }

    public function test_an_unreachable_assistant_flags_the_failure_and_keeps_the_message(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        $this->app->bind(ChatResponder::class, fn () => new class implements ChatResponder
        {
            public function respond(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals, array $history): string
            {
                throw new \RuntimeException('api down');
            }
        });

        $user->chatMessages()->create(['role' => 'user', 'content' => 'What is my risk?', 'locale' => 'en']);
        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinutes(5));

        try {
            (new GenerateChatReplyJob($user, 'en'))->handle(app(GenerateChatReply::class));
            $this->fail('The job should rethrow the responder exception.');
        } catch (\RuntimeException) {
        }

        // The user message persisted, so nothing is lost on retry.
        $this->assertSame(1, $user->chatMessages()->count());
        $this->assertFalse(Cache::has(GenerateChatReplyJob::awaitingCacheKey($user)));
        $this->assertTrue(Cache::has(GenerateChatReplyJob::failedCacheKey($user)));

        Volt::test('advisor.index')
            ->assertSee(__('The assistant could not be reached — your message was not lost, please try sending it again.'))
            ->assertSee(__('Retry'));
    }

    public function test_retry_requeues_the_reply_for_the_last_unanswered_message(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        $user->chatMessages()->create(['role' => 'user', 'content' => 'What is my risk?', 'locale' => 'en']);
        Cache::put(GenerateChatReplyJob::failedCacheKey($user), true, now()->addMinutes(10));
        Queue::fake();

        Volt::test('advisor.index')->call('retry');

        Queue::assertPushed(GenerateChatReplyJob::class, 1);
        $this->assertFalse(Cache::has(GenerateChatReplyJob::failedCacheKey($user)));
        $this->assertTrue(Cache::has(GenerateChatReplyJob::awaitingCacheKey($user)));
        $this->assertSame(1, $user->chatMessages()->count());
    }

    public function test_retry_does_nothing_when_the_last_message_was_answered(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        $user->chatMessages()->create(['role' => 'user', 'content' => 'What is my risk?', 'locale' => 'en']);
        AiChatMessage::factory()->assistant()->create(['user_id' => $user->id]);
        Cache::put(GenerateChatReplyJob::failedCacheKey($user), true, now()->addMinutes(10));
        Queue::fake();

        Volt::test('advisor.index')->call('retry');

        Queue::assertNothingPushed();
    }

    public function test_polling_renders_the_reply_when_it_arrives(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        $user->chatMessages()->create(['role' => 'user', 'content' => 'What is my risk?', 'locale' => 'en']);
        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinutes(5));

        $component = Volt::test('advisor.index')
            ->assertSee(__('Mahafeth AI is thinking…'));

        // The queued job lands the reply and clears the flag; the next
        // poll picks both up and scrolls the thread.
        AiChatMessage::factory()->assistant()->create(['user_id' => $user->id, 'content' => 'Your annualized volatility is 18%.']);
        Cache::forget(GenerateChatReplyJob::awaitingCacheKey($user));

        $component->call('$refresh')
            ->assertSee('Your annualized volatility is 18%.')
            ->assertDontSee(__('Mahafeth AI is thinking…'))
            ->assertDispatched('chat-updated');
    }

    public function test_a_cleared_chat_never_receives_an_orphan_reply(): void
    {
        $user = $this->analyzedUser();

        (new GenerateChatReplyJob($user, 'en'))->handle(app(GenerateChatReply::class));

        $this->assertSame(0, $user->chatMessages()->count());
    }

    public function test_an_overlong_message_shows_a_specific_error(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('advisor.index')
            ->set('message', str_repeat('a', 1001))
            ->call('send')
            ->assertSee(__('That message is too long — please keep it under 1,000 characters.'));

        $this->assertSame(0, $user->chatMessages()->count());
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

    public function test_clearing_the_chat_resets_the_awaiting_and_failed_state(): void
    {
        $user = $this->analyzedUser();
        AiChatMessage::factory()->create(['user_id' => $user->id]);
        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinutes(5));
        Cache::put(GenerateChatReplyJob::failedCacheKey($user), true, now()->addMinutes(10));

        $this->actingAs($user);
        Volt::test('advisor.index')
            ->call('clearChat')
            ->assertSet('error', null);

        $this->assertFalse(Cache::has(GenerateChatReplyJob::awaitingCacheKey($user)));
        $this->assertFalse(Cache::has(GenerateChatReplyJob::failedCacheKey($user)));
    }

    public function test_another_users_messages_never_render(): void
    {
        $user = $this->analyzedUser();
        $other = User::factory()->create();
        AiChatMessage::factory()->create(['user_id' => $other->id, 'content' => 'private message of another user']);

        $this->actingAs($user);

        Volt::test('advisor.index')->assertDontSee('private message of another user');
    }

    public function test_assistant_markdown_renders_bold_while_raw_html_is_stripped(): void
    {
        $user = $this->analyzedUser();
        AiChatMessage::factory()->assistant()->create([
            'user_id' => $user->id,
            'content' => 'Your **health score** is 50. <script>alert(1)</script><a href="javascript:alert(1)">click</a>',
        ]);

        $this->actingAs($user);

        Volt::test('advisor.index')
            ->assertSee('<strong>health score</strong>', escape: false)
            ->assertDontSee('<script>', escape: false)
            ->assertDontSee('javascript:alert', escape: false);
    }

    public function test_user_messages_render_escaped_and_never_as_markup(): void
    {
        $user = $this->analyzedUser();
        AiChatMessage::factory()->create([
            'user_id' => $user->id,
            'content' => '<b>injected</b> **not bold**',
        ]);

        $this->actingAs($user);

        Volt::test('advisor.index')
            ->assertSee('<b>injected</b> **not bold**')
            ->assertDontSee('<b>injected</b>', escape: false);
    }

    public function test_the_ask_deep_link_sends_the_seeded_question_and_answers_it(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        $question = 'Explain this disclosure and what it means for my portfolio: "Apple Inc. files Form 10-Q" (AAPL). Key excerpt: Revenue of $93.4B.';

        // The question is sent during mount, so the sync queue answers it
        // before the page even renders.
        $this->get('/advisor?ask='.urlencode($question))
            ->assertOk()
            ->assertSee('Apple Inc. files Form 10-Q');

        $this->assertSame(2, $user->chatMessages()->count());

        // The fake responder recognizes the disclosure and cites the held weight.
        $reply = $user->chatMessages()->latest('id')->first();
        $this->assertSame('assistant', $reply->role);
        $this->assertStringContainsString('AAPL', $reply->content);
    }

    public function test_the_ask_deep_link_renders_with_the_question_and_typing_indicator_while_queued(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        Queue::fake();

        $this->get('/advisor?ask='.urlencode('What is my risk?'))
            ->assertOk()
            ->assertSee('What is my risk?')
            ->assertSee(__('Mahafeth AI is thinking…'));

        $this->assertSame(1, $user->chatMessages()->count());
        Queue::assertPushed(GenerateChatReplyJob::class, 1);
    }

    public function test_the_ask_deep_link_is_not_resent_on_refresh(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);
        Queue::fake();

        $url = '/advisor?ask='.urlencode('What is my risk?');
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $this->assertSame(1, $user->chatMessages()->count());
        Queue::assertPushed(GenerateChatReplyJob::class, 1);
    }

    public function test_the_ask_deep_link_is_ignored_without_a_snapshot(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Queue::fake();

        $this->get('/advisor?ask='.urlencode('What is my risk?'))->assertOk();

        $this->assertSame(0, $user->chatMessages()->count());
        Queue::assertNothingPushed();
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
