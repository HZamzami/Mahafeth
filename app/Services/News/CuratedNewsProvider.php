<?php

namespace App\Services\News;

use App\Contracts\NewsProvider;
use Illuminate\Support\Carbon;

/**
 * Curated bilingual headlines tagged to the demo asset catalog. Used until
 * a live news API is configured, and keeps the feed timestamps fresh each
 * time the refresh command runs.
 */
class CuratedNewsProvider implements NewsProvider
{
    public function fetchLatest(): array
    {
        $items = [
            [
                'headline' => 'Apple earnings preview: services growth in focus as hardware demand cools',
                'headline_ar' => 'ترقّب نتائج آبل: نمو الخدمات في دائرة الضوء مع تباطؤ الطلب على الأجهزة',
                'source' => 'Market Pulse',
                'minutes' => 4,
                'symbols' => ['AAPL'],
                'sectors' => ['Technology'],
                'hours_ago' => 3,
            ],
            [
                'headline' => 'Tech mega-caps now move in lockstep — what high correlation means for concentrated portfolios',
                'headline_ar' => 'أسهم التقنية الكبرى تتحرك كتلة واحدة — ماذا يعني الارتباط المرتفع للمحافظ المركّزة',
                'source' => 'Alpha Insights',
                'minutes' => 7,
                'symbols' => ['AAPL', 'MSFT', 'NVDA', 'GOOGL'],
                'sectors' => ['Technology'],
                'hours_ago' => 9,
            ],
            [
                'headline' => 'Bitcoin volatility spikes as ETF flows reverse for the first time this quarter',
                'headline_ar' => 'تقلبات بيتكوين ترتفع مع انعكاس تدفقات الصناديق لأول مرة هذا الربع',
                'source' => 'Chain Report',
                'minutes' => 5,
                'symbols' => ['BTC', 'ETH'],
                'sectors' => null,
                'hours_ago' => 14,
            ],
            [
                'headline' => 'Aramco maintains dividend as oil prices stabilize above budget assumptions',
                'headline_ar' => 'أرامكو تحافظ على التوزيعات مع استقرار أسعار النفط فوق افتراضات الميزانية',
                'source' => 'Tadawul Daily',
                'minutes' => 3,
                'symbols' => ['2222.SR'],
                'sectors' => ['Energy'],
                'hours_ago' => 22,
            ],
            [
                'headline' => 'Saudi banks report strong quarter as lending margins widen',
                'headline_ar' => 'البنوك السعودية تسجّل ربعًا قويًا مع اتساع هوامش الإقراض',
                'source' => 'Tadawul Daily',
                'minutes' => 4,
                'symbols' => ['1120.SR', '1010.SR'],
                'sectors' => ['Financials'],
                'hours_ago' => 30,
            ],
            [
                'headline' => 'Fed holds rates steady; growth stocks rally on dovish tone',
                'headline_ar' => 'الفيدرالي يثبّت الفائدة؛ وأسهم النمو ترتفع مع نبرة تيسيرية',
                'source' => 'Market Pulse',
                'minutes' => 6,
                'symbols' => ['SPY', 'AAPL', 'MSFT', 'NVDA'],
                'sectors' => ['Technology'],
                'hours_ago' => 40,
            ],
        ];

        return array_map(fn (array $item): array => [
            'headline' => $item['headline'],
            'headline_ar' => $item['headline_ar'],
            'source' => $item['source'],
            'minutes' => $item['minutes'],
            'symbols' => $item['symbols'],
            'sectors' => $item['sectors'],
            'published_at' => Carbon::now()->subHours($item['hours_ago']),
        ], $items);
    }
}
