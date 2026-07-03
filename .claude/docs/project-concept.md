# Mahafeth (محافظ) — Project Concept

**Tagline:** "From scattered portfolios to one investment vision" (من محافظ متفرقة إلى رؤية استثمارية واحدة). "Mahafeth" = Arabic for "portfolios".

## The Problem
Investors use many platforms (brokerages, banks, crypto exchanges, mutual funds, real-estate funds, retirement accounts), causing:
1. **Fragmented portfolios** — no holistic view of total investment position.
2. **Hidden risks** — concentration, high cross-asset correlation, and risk levels misaligned with goals go unnoticed when each app is viewed in isolation.
3. **Data without guidance** — abundant financial data but no personalized interpretation, making informed decisions hard.

## The Solution
An AI-powered portfolio analytics platform that:
- Securely connects investment accounts across institutions via **Open Banking** and aggregates them into **one unified portfolio**.
- Continuously evaluates it using **institutional portfolio-management techniques**: portfolio health scoring, diversification analysis, correlation assessment, risk profiling, and efficient-frontier analysis — uncovering hidden risks traditional investment apps can't show.
- Uses **generative AI** to translate quantitative analysis into simple, actionable recommendations and easy-to-read personalized reports instead of drowning users in complex metrics.
- Provides **continuous monitoring**: portfolio-linked news, earnings/market alerts, analyst price targets, and health-score tracking over time.

## User journey
Set goals → connect portfolios → unify data → analyze → report & recommendations.

## Three-layer architecture
1. **Data Layer (Open Banking):** holdings, transactions, historical prices, asset metadata (class/sector/country/currency), benchmarks. Used for merging portfolios, computing weights and returns, feeding analytics and reports.
2. **User Layer (IPS — Investment Policy Statement):** goals, time horizon, risk tolerance, liquidity needs, constraints, target return (via risk questionnaire).
3. **Intelligence Layer (AI):** personalized report, recommendations, news relevance, alerts, forecasts.

Between them sits the **Analytics Engine** (see `analytics-engine.md`) which computes diversification, correlation, and risk metrics, then risk decomposition → efficient frontier → Portfolio Health Score → AI explanation layer.

## AI Explanation Layer responsibilities
Explain each health subscore; interpret hidden risks; suggest rebalancing; explain risk/return trade-offs; compare portfolio to IPS; explain news relevance to the portfolio; generate a personal action plan. Principle: **from diagnosis to action** — don't just say "you have a problem", explain why and propose concrete steps (e.g., sector-concentration alerts, reduce-weight recommendations, geographic-diversification suggestions, rebalancing plans).

## Differentiation vs traditional investment apps
| Axis | Traditional apps | Mahafeth |
|---|---|---|
| Scope | one portfolio in one app | full portfolio across all platforms |
| Data | balances & returns | balances, assets, risk, correlation, goals |
| Analysis | basic charts | quantitative & institutional-grade |
| Recommendations | generic | personalized, portfolio-based, AI-backed |
| AI | limited/unlinked | explains results & suggests steps |
| Goal | track investments | optimize the whole portfolio |

Key framing: Mahafeth is not another dashboard — it builds an **intelligence layer on top of all the investor's portfolios**.

## Context & challenges
Built for a competition themed around Open Banking innovation (pitch deck is bilingual, Arabic-primary). Main challenges: obtaining reliable financial APIs (especially local Saudi platforms like Sahm, Derayah), normalizing data from multiple sources, data quality/completeness, Open Banking provider integration, financial-data privacy, recommendation accuracy, and simplifying complex analytics for users.
