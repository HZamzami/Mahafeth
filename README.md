<div align="center">
  <img src="public/icons/icon-512.png" alt="Mahafeth" width="96" height="96" />

  # Mahafeth · محافظ

  **From scattered portfolios to one investment vision**
  من محافظ متفرقة إلى رؤية استثمارية واحدة

  An AI portfolio analyst that unifies every account you own, scores its health with
  institutional-grade math, and explains what to do about it in plain language.

  [Open the live app →](https://mahafeth-production-frnege.laravel.cloud/)
</div>

---

## The problem

Investors hold money across brokerages, banks, crypto exchanges, funds, and retirement
accounts. Each app shows one slice, so:

- **No whole picture.** Your true allocation, concentration, and total return live in
  five different tabs and a spreadsheet.
- **Hidden risk.** Cross-asset correlation, sector concentration, and a risk level that
  no longer matches your goals stay invisible when every account is viewed alone.
- **Data without guidance.** Plenty of numbers, almost no interpretation, so the next
  decision is still a guess.

## What Mahafeth does

Mahafeth connects your accounts through Open Banking, merges them into a single
portfolio, and runs it through three layers:

1. **Aggregate.** Holdings, transactions, prices, and asset metadata from every source,
   normalized into one unified portfolio (with manual accounts and CSV import for
   anything an API cannot reach).
2. **Analyze.** An institutional analytics engine computes diversification, correlation,
   risk, an efficient frontier, and a single Portfolio Health Score.
3. **Explain.** Generative AI turns the math into a personal report, ranked
   recommendations, and answers to your questions, moving from diagnosis to action.

## Feature highlights

- **Unified portfolio** across connected (Open Banking) and manual accounts, valued live
  in one base currency with cost basis and realized/unrealized profit and loss.
- **Portfolio Health Score (0–100)** built from diversification, risk alignment,
  correlation, performance, drawdown, and concentration subscores, each explained.
- **Institutional analytics tabs:** efficient frontier and the gap to optimal,
  rebalancing plan, risk decomposition, and a correlation map with hidden-factor (PCA)
  detection.
- **What-if simulator:** test a buy or sell and watch your health score, concentration,
  volatility, and correlation move before you commit real money.
- **AI advisor:** a chat-first advisor plus a written insight report, grounded in your
  actual portfolio and your Investment Policy Statement.
- **Continuous monitoring:** portfolio-linked news, SEC EDGAR filings for US holdings,
  market movers, a dividend income calendar, and drift alerts when a position wanders
  off your target plan.
- **Built for the region:** fully bilingual Arabic and English with proper RTL, Shariah
  compliance flags, and a Zakat calculator tied to your Hijri hawl with a purification
  ledger.
- **Feels native:** installable PWA with an offline mode, and passwordless sign-in with
  passkeys.

## The analytics engine

The core is a real quantitative pipeline, not chart decoration:

Open Banking data → normalization → price history → returns → covariance and correlation
matrices → risk and diversification metrics → risk decomposition → efficient frontier →
Portfolio Health Score → the AI explanation layer.

It covers log returns, a full covariance and correlation matrix, volatility, beta, Value
at Risk and CVaR, Sharpe and Sortino, maximum drawdown, HHI and effective number of
holdings, the diversification ratio, PCA hidden-factor share, stress correlation, a
Markowitz efficient frontier with a tangency portfolio, and a risk-alignment score
against the investor's target. See `.claude/docs/analytics-engine.md` for the formulas.

## Tech

Laravel 12 and PHP 8.4, Livewire 4 with Volt single-file components, Flux UI, Tailwind
CSS 4, and PostgreSQL 17. The AI layer runs on Claude, with a larger model for the
written insight, a mid-tier model for the advisor chat, and a fast model for
translation. Deployed on Laravel Cloud; developed in DDEV.

## Local development

Runs entirely in [DDEV](https://ddev.com), so the only host requirements are Docker and
the ddev CLI (macOS, Windows via WSL2, and Linux behave the same).

```bash
git clone <repo-url> mahafeth && cd mahafeth
ddev start
ddev composer install
cp .env.example .env
ddev artisan key:generate
ddev artisan migrate
ddev npm install
ddev npm run dev
```

In `.env`, switch the default `DB_CONNECTION=sqlite` to the ddev PostgreSQL container:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=db
DB_USERNAME=db
DB_PASSWORD=db
```

The app is served at <https://mahafeth.ddev.site>. Vite HMR is exposed through the ddev
router on port 5173 (already wired in `.ddev/config.yaml` and `vite.config.js`); run
`npm run dev` inside the container with `ddev npm run dev`, not on the host.

## Testing and formatting

```bash
ddev artisan test --compact   # PHPUnit suite
ddev exec vendor/bin/pint     # Laravel Pint code style
```

Tests run against a dedicated `testing` PostgreSQL database, created automatically by a
ddev `post-start` hook and isolated from your dev data.
