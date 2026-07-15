# Mahafeth Arabic Style Guide

Register: Modern Standard Arabic, warm and direct — a well-made Saudi fintech app
(الراجحي، STC Pay), not a legal document. Audience: Saudi retail investors.

## Glossary (one term per concept, everywhere)

| English | Arabic |
|---|---|
| portfolio | محفظة / محافظ |
| asset | أصل / أصول |
| position | مركز / مراكز |
| instrument | أداة مالية |
| holdings | الأصول المملوكة (singular: أصل — never حيازة) |
| health score | مؤشر صحة المحفظة |
| risk / risk tolerance | المخاطر / تحمّل المخاطر |
| volatility | التقلب (not تذبذب) |
| diversification / concentration / correlation | التنويع / التركز / الارتباط |
| rebalancing | إعادة التوازن |
| dividends | توزيعات الأرباح |
| benchmark | المؤشر المرجعي |
| stress test / efficient frontier | اختبار الضغط / الحد الكفء |
| Sharpe / Sortino | نسبة شارب / نسبة سورتينو (transliterated) |
| alert / insight / goal | تنبيه / رؤية / هدف |
| snapshot | لقطة |
| sync | مزامنة |
| dashboard / settings | لوحة التحكم / الإعدادات |
| demo | العرض التجريبي (never ديمو) |
| AI Advisor | المستشار الذكي |
| zakat / tatheer / hawl / nisab | الزكاة / التطهير / الحول / النصاب (fiqh terms — do not reword) |

Brand names stay as data (institution names, بيتكوين، إيثيريوم، ألفا إنسايتس).

## Style rules

- Verb-first sentences; rewrite from scratch, never patch English word order.
- Active voice: سنرسل لك رابطًا، not سيتم إرسال رابط. No bureaucratic يتم.
- No قم بـ: write اربط حساباتك، not قم بربط حساباتك.
- Possessive suffixes over الخاص بك: محفظتك، not المحفظة الخاصة بك.
- No يرجى / يرجى العلم: buttons and requests use the direct imperative (أدخل، انقر، اختر).
- Second person: masculine singular (أنت) throughout — existing convention.
- Quotes: guillemets «» only. Arabic comma ،. No tatweel.
- **Tanween is written wherever grammar requires it**: بدلًا، شكرًا، جدًا، معًا، بناءً على، مجانًا.
  Progressive "…ing" states: prefer يجري ربط الحسابات… over جارٍ ربط…; the bare
  form جار is always wrong.
- Hamza spelled correctly and consistently: إنشاء، أدخل، الأسعار.
- Numbers stay Western digits; the app renders them dir="ltr".
- Length: check the render site (button/tab/badge vs paragraph) before making a
  string longer than the current value.

## Workflow

New user-facing strings land in `lang/ar.json` in the same commit, following
this guide. Tests may assert Arabic output — grep `tests/` for the old value
when changing an existing string.
