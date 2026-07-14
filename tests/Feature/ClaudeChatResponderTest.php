<?php

namespace Tests\Feature;

use App\Actions\GenerateChatReply;
use App\Contracts\ChatResponder;
use App\Models\AiChatMessage;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Insights\ClaudeChatResponder;
use App\Services\Insights\FakeChatResponder;
use App\Services\Insights\PortfolioContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeChatResponderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: PortfolioSnapshot, 1: RiskProfile}
     */
    private function snapshotAndProfile(): array
    {
        $user = User::factory()->create();
        $profile = RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $snapshot = PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'health_score' => 39,
            'metrics' => [
                'volatility' => 0.261,
                'sharpe' => -0.51,
                'largest_position' => ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'weight' => 0.365],
                'effective_holdings' => 4.4,
            ],
        ]);

        return [$snapshot, $profile];
    }

    public function test_the_fake_responder_is_bound_when_no_api_key_is_configured(): void
    {
        config(['mahafeth.ai.api_key' => null, 'mahafeth.ai.fake' => false]);

        $this->assertInstanceOf(FakeChatResponder::class, app(ChatResponder::class));
    }

    public function test_it_sends_the_portfolio_grounded_conversation_and_parses_the_reply(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        config(['mahafeth.ai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Your volatility is 26.1%.']],
            ]),
        ]);

        $reply = app(ClaudeChatResponder::class)->respond($snapshot, $profile, 'ar', [], [
            ['role' => 'user', 'content' => 'What is my risk?'],
        ]);

        $this->assertSame('Your volatility is 26.1%.', $reply);

        Http::assertSent(function (Request $request) use ($snapshot): bool {
            $body = $request->data();
            $system = collect($body['system']);

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('anthropic-version')
                && $body['model'] === config('mahafeth.ai.chat_model')
                && $body['max_tokens'] === (int) config('mahafeth.ai.chat_max_tokens')
                && $body['thinking'] === ['type' => 'adaptive']
                && ($body['tools'][0]['type'] ?? null) === 'web_search_20260209'
                && ($body['tools'][0]['max_uses'] ?? null) === 3
                && $body['messages'] === [['role' => 'user', 'content' => 'What is my risk?']]
                && str_contains($system->first()['text'], 'Arabic')
                && str_contains($system->first()['text'], $snapshot->as_of->toDateString())
                && str_contains($system->last()['text'], '0.261')
                && ($system->last()['cache_control']['type'] ?? null) === 'ephemeral';
        });
    }

    public function test_web_search_replies_concatenate_every_text_block(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        config(['mahafeth.ai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'thinking', 'thinking' => ''],
                    ['type' => 'text', 'text' => 'Let me check the latest close. '],
                    ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'Aramco price']],
                    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                    ['type' => 'text', 'text' => 'Aramco closed at 26.78 SAR.'],
                ],
            ]),
        ]);

        $reply = app(ClaudeChatResponder::class)->respond($snapshot, $profile, 'en', [], [
            ['role' => 'user', 'content' => 'What is Aramco trading at today?'],
        ]);

        $this->assertSame('Let me check the latest close. Aramco closed at 26.78 SAR.', $reply);
    }

    public function test_an_unexpected_response_shape_throws(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();

        config(['mahafeth.ai.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

        $this->expectException(\RuntimeException::class);

        app(ClaudeChatResponder::class)->respond($snapshot, $profile, 'en', [], [
            ['role' => 'user', 'content' => 'hello'],
        ]);
    }

    public function test_the_history_window_caps_the_messages_sent_to_the_model(): void
    {
        [$snapshot, $profile] = $this->snapshotAndProfile();
        $user = $snapshot->user;

        AiChatMessage::factory()->count(15)->create(['user_id' => $user->id]);
        AiChatMessage::factory()->assistant()->count(15)->create(['user_id' => $user->id]);

        $spy = new class implements ChatResponder
        {
            public array $history = [];

            public function respond(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals, array $history): string
            {
                $this->history = $history;

                return 'ok';
            }
        };

        $user->chatMessages()->create(['role' => 'user', 'content' => 'one more question', 'locale' => 'en']);
        (new GenerateChatReply($spy, app(PortfolioContext::class)))->handle($user, 'en');

        $this->assertCount(20, $spy->history);
        $this->assertSame('one more question', end($spy->history)['content']);
    }
}
