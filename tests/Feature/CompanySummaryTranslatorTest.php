<?php

namespace Tests\Feature;

use App\Jobs\TranslateCompanySummaryJob;
use App\Services\Markets\CompanySummaryTranslator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompanySummaryTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['mahafeth.ai.fake' => false, 'mahafeth.ai.api_key' => 'test-key']);
    }

    public function test_it_serves_english_and_queues_the_translation(): void
    {
        Queue::fake();

        $translator = app(CompanySummaryTranslator::class);

        // First view: English now, translation queued.
        $result = $translator->toArabic('AAPL', 'Apple designs smart devices.');
        $this->assertSame('Apple designs smart devices.', $result['text']);
        $this->assertTrue($result['pending']);
        Queue::assertPushed(TranslateCompanySummaryJob::class, 1);

        // Repeat views while it is still queued do not pile up jobs.
        $translator->toArabic('AAPL', 'Apple designs smart devices.');
        Queue::assertPushed(TranslateCompanySummaryJob::class, 1);
    }

    public function test_the_queued_translation_caches_and_is_served_next_view(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'تطور شركة Apple الأجهزة الذكية.']],
            ]),
        ]);

        $translator = app(CompanySummaryTranslator::class);

        // The job runs and caches the Arabic.
        $translator->translate('AAPL', 'Apple designs smart devices.');

        $result = $translator->toArabic('AAPL', 'Apple designs smart devices.');
        $this->assertSame('تطور شركة Apple الأجهزة الذكية.', $result['text']);
        $this->assertFalse($result['pending']);
        Http::assertSentCount(1);
    }

    public function test_fake_mode_returns_the_english_summary_without_calling_the_api(): void
    {
        config(['mahafeth.ai.fake' => true]);
        Http::fake();
        Queue::fake();

        $result = app(CompanySummaryTranslator::class)->toArabic('AAPL', 'Apple designs smart devices.');
        $this->assertSame('Apple designs smart devices.', $result['text']);
        $this->assertFalse($result['pending']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_failed_translation_caches_english_briefly_so_the_card_stops_waiting(): void
    {
        // Every attempt 500s, so the job's retries are exhausted and it
        // caches the English fallback rather than leaving the card polling.
        Http::fake(['api.anthropic.com/*' => Http::response(status: 500)]);

        $translator = app(CompanySummaryTranslator::class);
        $translator->translate('AAPL', 'Apple designs smart devices.');

        $result = $translator->toArabic('AAPL', 'Apple designs smart devices.');
        $this->assertSame('Apple designs smart devices.', $result['text']);
        $this->assertFalse($result['pending']);
    }
}
