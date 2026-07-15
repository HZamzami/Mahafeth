<?php

namespace App\Services\Filings;

use App\Contracts\FilingProvider;
use App\Enums\AssetClass;
use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Live company disclosures from SEC EDGAR for the US equities users hold:
 * quarterly reports (10-Q), annual reports (10-K), and material-event
 * reports (8-K). Keyless, but EDGAR's fair-use policy requires a
 * descriptive User-Agent. Tadawul offers no public disclosures API, so
 * Saudi symbols simply have no filings rather than synthetic ones.
 */
class EdgarFilingProvider implements FilingProvider
{
    private const FORM_TYPES = [
        '10-Q' => 'quarterly_report',
        '10-K' => 'annual_report',
        '8-K' => 'announcement',
    ];

    /**
     * An 8-K only says "material event"; its real subject lives in the SEC
     * item codes on the filing. Mapping the codes to plain language is what
     * turns a wall of identical "material event" lines into a feed that
     * says what actually happened. Ordered by headline value: the first
     * code present on a filing wins (so earnings beats a routine exhibit).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EIGHT_K_EVENTS = [
        '1.03' => ['files for bankruptcy or receivership', 'تشهر إفلاسها أو تخضع لحراسة قضائية'],
        '2.01' => ['completes an acquisition or disposal', 'تتم استحواذا أو تصرفا في أصول'],
        '2.02' => ['posts new quarterly results', 'تنشر نتائجها المالية الجديدة'],
        '1.01' => ['signs a material agreement', 'توقع اتفاقية جوهرية'],
        '5.02' => ['announces a leadership change', 'تعلن تغييرا في قيادتها'],
        '3.01' => ['faces a delisting notice', 'تتلقى إشعارا بشطب إدراجها'],
        '4.02' => ['flags an accounting restatement', 'تشير إلى إعادة عرض بياناتها المالية'],
        '5.07' => ['reports shareholder vote results', 'تعلن نتائج تصويت مساهميها'],
        '5.03' => ['amends its bylaws', 'تعدل نظامها الأساسي'],
        '3.02' => ['reports an equity issuance', 'تفصح عن إصدار أسهم'],
        '7.01' => ['shares a market disclosure', 'تنشر إفصاحا للسوق'],
        '8.01' => ['shares a company update', 'تشارك تحديثا عن الشركة'],
    ];

    private const PER_SYMBOL = 3;

    private const MAX_SYMBOLS = 20;

    public function fetchLatest(): array
    {
        $symbols = Asset::where('is_benchmark', false)
            ->where('asset_class', AssetClass::Equity)
            ->where('symbol', 'not like', '%.SR')
            ->pluck('symbol')
            ->take(self::MAX_SYMBOLS);

        if ($symbols->isEmpty()) {
            return [];
        }

        $companies = $this->tickerMap();
        $filings = [];

        foreach ($symbols as $symbol) {
            $company = $companies[strtoupper($symbol)] ?? null;

            if ($company !== null) {
                $filings = [...$filings, ...$this->filingsFor($symbol, $company['cik'], $company['name'])];
            }
        }

        usort($filings, fn (array $a, array $b): int => $b['published_at'] <=> $a['published_at']);

        return $filings;
    }

    /**
     * @return list<array{headline: string, headline_ar: string, symbol: string, type: string, source: string, url: ?string, excerpt: string, excerpt_ar: string, published_at: Carbon}>
     */
    private function filingsFor(string $symbol, int $cik, string $company): array
    {
        try {
            $recent = Cache::remember(
                'edgar:submissions:'.$cik,
                now()->addHours(6),
                fn (): array => Http::withUserAgent((string) config('services.edgar.user_agent'))
                    ->baseUrl(config('services.edgar.submissions_base_url'))
                    ->timeout(20)
                    ->get(sprintf('/submissions/CIK%010d.json', $cik))
                    ->throw()
                    ->json('filings.recent', []),
            );
        } catch (\Throwable $exception) {
            Log::warning('SEC EDGAR submissions fetch failed.', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $filings = [];
        $seenForms = [];

        foreach ($recent['form'] ?? [] as $index => $form) {
            // Keep only the latest of each report type per company: 8-Ks are
            // filed far more often than the periodic reports, so grabbing the
            // three most recent filings would bury every 10-Q and 10-K under
            // a stack of announcements.
            if (! isset(self::FORM_TYPES[$form]) || isset($seenForms[$form]) || count($filings) >= self::PER_SYMBOL) {
                continue;
            }

            $seenForms[$form] = true;
            $publishedAt = Carbon::parse($recent['filingDate'][$index]);
            [$headline, $headlineAr, $summary, $summaryAr] = $this->describe($form, $company, (string) ($recent['items'][$index] ?? ''));

            $filings[] = [
                'headline' => $headline,
                'headline_ar' => $headlineAr,
                'symbol' => $symbol,
                'type' => self::FORM_TYPES[$form],
                'source' => 'SEC EDGAR',
                'url' => sprintf(
                    'https://www.sec.gov/Archives/edgar/data/%d/%s/%s',
                    $cik,
                    str_replace('-', '', (string) $recent['accessionNumber'][$index]),
                    (string) $recent['primaryDocument'][$index],
                ),
                'excerpt' => sprintf(
                    '%s — Form %s filed with the SEC on %s. Open the filing for the full document.',
                    $summary,
                    $form,
                    $publishedAt->toFormattedDateString(),
                ),
                'excerpt_ar' => sprintf(
                    '%s — أُودع نموذج %s لدى هيئة الأوراق المالية الأمريكية بتاريخ %s. افتح الإيداع للاطلاع على المستند الكامل.',
                    $summaryAr,
                    $form,
                    $publishedAt->toDateString(),
                ),
                'published_at' => $publishedAt,
            ];
        }

        return $filings;
    }

    /**
     * Build the bilingual headline and one-line summary for a filing. EDGAR
     * metadata is English-only, so both languages come from templates; an
     * 8-K is further narrowed by its SEC item codes.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} headline, headline_ar, summary, summary_ar
     */
    private function describe(string $form, string $company, string $items): array
    {
        return match ($form) {
            '10-Q' => [
                sprintf('%s files its quarterly report (Form 10-Q)', $company),
                sprintf('%s تودع تقريرها الربعي (نموذج 10-Q)', $company),
                'Quarterly financial report',
                'التقرير المالي الربعي',
            ],
            '10-K' => [
                sprintf('%s files its annual report (Form 10-K)', $company),
                sprintf('%s تودع تقريرها السنوي (نموذج 10-K)', $company),
                'Annual financial report',
                'التقرير المالي السنوي',
            ],
            default => $this->eightK($company, $items),
        };
    }

    /**
     * Turn an 8-K's item codes into a specific, human headline.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function eightK(string $company, string $items): array
    {
        $codes = array_map('trim', explode(',', $items));

        foreach (self::EIGHT_K_EVENTS as $code => [$en, $ar]) {
            if (in_array($code, $codes, true)) {
                return [
                    sprintf('%s %s', $company, $en),
                    sprintf('%s %s', $company, $ar),
                    Str::ucfirst($en),
                    Str::ucfirst($ar),
                ];
            }
        }

        return [
            sprintf('%s reports a material event (Form 8-K)', $company),
            sprintf('%s تفصح عن حدث جوهري (نموذج 8-K)', $company),
            'Material event',
            'حدث جوهري',
        ];
    }

    /**
     * The SEC's ticker directory, mapping symbols to CIK numbers and
     * registrant names. One small file covers every listed company.
     *
     * @return array<string, array{cik: int, name: string}>
     */
    private function tickerMap(): array
    {
        try {
            return Cache::remember('edgar:tickers', now()->addWeek(), function (): array {
                $entries = Http::withUserAgent((string) config('services.edgar.user_agent'))
                    ->timeout(30)
                    ->get(config('services.edgar.tickers_url'))
                    ->throw()
                    ->json();

                $map = [];

                foreach ($entries as $entry) {
                    $name = (string) $entry['title'];

                    $map[strtoupper((string) $entry['ticker'])] = [
                        'cik' => (int) $entry['cik_str'],
                        // Some registrants are recorded in ALL CAPS
                        // ("NVIDIA CORP"); title-case those for headlines.
                        'name' => $name === strtoupper($name) ? Str::title($name) : $name,
                    ];
                }

                return $map;
            });
        } catch (\Throwable $exception) {
            Log::warning('SEC EDGAR ticker directory fetch failed.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
