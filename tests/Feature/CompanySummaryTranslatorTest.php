<?php

namespace Tests\Feature;

use App\Services\Markets\CompanySummaryTranslator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanySummaryTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['mahafeth.ai.fake' => false, 'mahafeth.ai.api_key' => 'test-key']);
    }

    public function test_it_translates_through_the_claude_api_and_caches_forever(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'تطور شركة Apple الأجهزة الذكية.']],
            ]),
        ]);

        $translator = app(CompanySummaryTranslator::class);

        $this->assertSame('تطور شركة Apple الأجهزة الذكية.', $translator->toArabic('AAPL', 'Apple designs smart devices.'));

        // The second call is served from cache without another API hit.
        $translator->toArabic('AAPL', 'Apple designs smart devices.');
        Http::assertSentCount(1);
    }

    public function test_fake_mode_returns_the_english_summary_without_calling_the_api(): void
    {
        config(['mahafeth.ai.fake' => true]);
        Http::fake();

        $this->assertSame(
            'Apple designs smart devices.',
            app(CompanySummaryTranslator::class)->toArabic('AAPL', 'Apple designs smart devices.'),
        );
        Http::assertNothingSent();
    }

    public function test_api_failures_fall_back_to_english_without_caching(): void
    {
        // The translator retries once per call, so the first call burns two
        // 500s; the third response proves the failure was never cached.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(status: 500)
                ->push(status: 500)
                ->push(['content' => [['type' => 'text', 'text' => 'ترجمة عربية.']]]),
        ]);

        $translator = app(CompanySummaryTranslator::class);

        $this->assertSame('Apple designs smart devices.', $translator->toArabic('AAPL', 'Apple designs smart devices.'));

        $this->assertSame('ترجمة عربية.', $translator->toArabic('AAPL', 'Apple designs smart devices.'));
    }
}
