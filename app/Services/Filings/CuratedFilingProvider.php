<?php

namespace App\Services\Filings;

use App\Contracts\FilingProvider;
use Illuminate\Support\Carbon;

/**
 * Curated bilingual company filings for the demo: quarterly and annual
 * reports, dividends, and announcements for the symbols users actually
 * hold. A live SEC EDGAR or Tadawul provider can replace this behind
 * the same contract.
 */
class CuratedFilingProvider implements FilingProvider
{
    public function fetchLatest(): array
    {
        $filings = [
            [
                'headline' => 'Apple Inc. files Form 10-Q for fiscal Q3 2026',
                'headline_ar' => 'أبل تودع تقرير الربع الثالث من السنة المالية 2026 (نموذج 10-Q)',
                'symbol' => 'AAPL',
                'type' => 'quarterly_report',
                'source' => 'SEC',
                'url' => 'https://www.sec.gov/cgi-bin/browse-edgar?action=getcompany&CIK=0000320193&type=10-Q',
                'excerpt' => 'Revenue of $93.4B, up 6% year over year, with Services reaching a record $26.8B (+12%). EPS of $1.48 vs $1.35 a year ago. Greater China revenue declined 3%. Board declared a dividend of $0.26 per share. Gross margin 46.9%.',
                'excerpt_ar' => 'إيرادات 93.4 مليار دولار بنمو 6% على أساس سنوي، مع بلوغ إيرادات الخدمات رقماً قياسياً عند 26.8 مليار دولار (+12%). ربحية السهم 1.48 دولار مقابل 1.35 دولار قبل عام. تراجعت إيرادات الصين الكبرى 3%. أعلن المجلس توزيعات نقدية قدرها 0.26 دولار للسهم. هامش الربح الإجمالي 46.9%.',
                'hours_ago' => 8,
            ],
            [
                'headline' => 'Saudi Aramco announces Q2 2026 interim results',
                'headline_ar' => 'أرامكو السعودية تعلن النتائج المالية الأولية للربع الثاني 2026',
                'symbol' => '2222.SR',
                'type' => 'quarterly_report',
                'source' => 'Tadawul',
                'url' => 'https://www.saudiexchange.sa/wps/portal/saudiexchange/newsandreports',
                'excerpt' => 'Net income of SAR 104.9B, down 4% versus Q2 2025 on lower realized crude prices. Free cash flow SAR 79.5B. Base dividend maintained at SAR 0.35 per share plus performance-linked component. Gearing at 6.3%.',
                'excerpt_ar' => 'صافي دخل 104.9 مليار ريال بانخفاض 4% مقارنة بالربع الثاني 2025 نتيجة انخفاض أسعار الخام المحققة. تدفقات نقدية حرة 79.5 مليار ريال. الحفاظ على التوزيعات الأساسية عند 0.35 ريال للسهم إضافة إلى المكوّن المرتبط بالأداء. نسبة المديونية 6.3%.',
                'hours_ago' => 26,
            ],
            [
                'headline' => 'stc Group announces cash dividend for Q2 2026',
                'headline_ar' => 'مجموعة stc تعلن توزيعات أرباح نقدية عن الربع الثاني 2026',
                'symbol' => '7010.SR',
                'type' => 'dividend',
                'source' => 'Tadawul',
                'url' => 'https://www.saudiexchange.sa/wps/portal/saudiexchange/newsandreports',
                'excerpt' => 'Quarterly cash dividend of SAR 0.55 per share under the approved dividend policy. Eligibility for shareholders of record at end of trading on 15 July 2026; distribution on 5 August 2026.',
                'excerpt_ar' => 'توزيعات نقدية ربع سنوية قدرها 0.55 ريال للسهم وفق سياسة التوزيعات المعتمدة. الأحقية لمساهمي نهاية تداول يوم 15 يوليو 2026، والتوزيع في 5 أغسطس 2026.',
                'hours_ago' => 50,
            ],
            [
                'headline' => 'Al Rajhi Bank board recommends capital increase via bonus shares',
                'headline_ar' => 'مجلس إدارة مصرف الراجحي يوصي بزيادة رأس المال عبر منح أسهم مجانية',
                'symbol' => '1120.SR',
                'type' => 'announcement',
                'source' => 'Tadawul',
                'url' => 'https://www.saudiexchange.sa/wps/portal/saudiexchange/newsandreports',
                'excerpt' => 'Board recommends increasing capital from SAR 40B to SAR 50B through one bonus share for every four held, capitalized from retained earnings, subject to regulatory and general assembly approval.',
                'excerpt_ar' => 'أوصى المجلس بزيادة رأس المال من 40 إلى 50 مليار ريال عبر منح سهم مجاني لكل أربعة أسهم مملوكة، تُرسمل من الأرباح المبقاة، وذلك بعد موافقة الجهات التنظيمية والجمعية العامة.',
                'hours_ago' => 76,
            ],
            [
                'headline' => 'Microsoft files Form 8-K: AI infrastructure capex program expanded',
                'headline_ar' => 'مايكروسوفت تودع نموذج 8-K: توسعة برنامج الإنفاق الرأسمالي على بنية الذكاء الاصطناعي',
                'symbol' => 'MSFT',
                'type' => 'announcement',
                'source' => 'SEC',
                'url' => 'https://www.sec.gov/cgi-bin/browse-edgar?action=getcompany&CIK=0000789019&type=8-K',
                'excerpt' => 'Material commitment disclosed: capital expenditure guidance for FY2027 raised to $115B, primarily data-center capacity for AI workloads. Management notes near-term pressure on free cash flow and operating margin of roughly 150bps.',
                'excerpt_ar' => 'إفصاح عن التزام جوهري: رفع توجيهات الإنفاق الرأسمالي للسنة المالية 2027 إلى 115 مليار دولار، معظمها لسعة مراكز البيانات لأحمال الذكاء الاصطناعي. وتشير الإدارة إلى ضغط قصير الأجل على التدفقات النقدية الحرة وهامش التشغيل بنحو 150 نقطة أساس.',
                'hours_ago' => 100,
            ],
            [
                'headline' => 'NVIDIA annual report highlights customer concentration risk',
                'headline_ar' => 'التقرير السنوي لإنفيديا يبرز مخاطر تركّز العملاء',
                'symbol' => 'NVDA',
                'type' => 'annual_report',
                'source' => 'SEC',
                'url' => 'https://www.sec.gov/cgi-bin/browse-edgar?action=getcompany&CIK=0001045810&type=10-K',
                'excerpt' => 'Form 10-K risk factors: four hyperscale customers represent 46% of data-center revenue. Full-year revenue $148B (+42%), gross margin 74.2%. Export-control restrictions on advanced accelerators cited as a material uncertainty.',
                'excerpt_ar' => 'عوامل المخاطر في نموذج 10-K: أربعة عملاء من مزودي الحوسبة السحابية الكبار يمثلون 46% من إيرادات مراكز البيانات. إيرادات السنة الكاملة 148 مليار دولار (+42%) وهامش إجمالي 74.2%. وذُكرت قيود ضوابط التصدير على المسرّعات المتقدمة كعامل عدم يقين جوهري.',
                'hours_ago' => 130,
            ],
        ];

        return array_map(function (array $filing): array {
            $filing['published_at'] = Carbon::now()->subHours($filing['hours_ago']);
            unset($filing['hours_ago']);

            return $filing;
        }, $filings);
    }
}
