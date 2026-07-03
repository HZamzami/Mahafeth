# Mahafeth Analytics Engine Reference

See `project-concept.md` for what the platform is. This is the quantitative core.

## Computation pipeline (dependency order)
Open Banking data → portfolio normalization → historical price retrieval → return calculation → covariance/correlation matrix → {diversification analysis, risk metrics, expected return} → risk decomposition → efficient frontier engine → Portfolio Health Score → AI explanation layer → personalized recommendations → news + alerts + monitoring.

## Returns
- Simple return: `Rt = (Pt − Pt−1) / Pt−1` (daily/weekly/monthly)
- Log return: `rt = ln(Pt / Pt−1)` — time-additive, better statistical properties
- Expected portfolio return: `E(Rp) = Σ wi·E(Ri)`

## Risk & volatility
- Asset variance: `σ²i = Var(Ri)`; covariance: `Cov(i,j) = E[(Ri−μi)(Rj−μj)]`
- Covariance matrix Σ: variances on diagonal, covariances elsewhere — the central object
- Portfolio variance: `σ²p = wᵀΣw`; volatility: `σp = √(wᵀΣw)` (Markowitz)
- Beta: `β = Cov(Rp,Rm)/Var(Rm)` — systematic risk (β>1 more volatile than market)
- VaR: `VaR = μ − zα·σ·√T`; CVaR = average loss beyond VaR threshold
- Max drawdown: `MDD = (Peak − Trough)/Peak`
- Sharpe: `(Rp−Rf)/σp`; Sortino: `(Rp−Rf)/σdown` (downside-only)

## Correlation assessment
- Correlation: `ρij = Cov(i,j)/(σi·σj)`, range −1..+1
- Average pairwise correlation (lower = better diversified)
- Stress correlation: `ρstress = ρ + δ(1−ρ)` — assets correlate more in crises
- PCA / hidden factors: `Σ = VΛVᵀ` — if PC1 explains ~70% of variance, portfolio is driven by one common factor (hidden concentration)

## Diversification & concentration
- HHI: `Σwi²` (lower = more diversified)
- Effective number of holdings: `ENB = 1/HHI` (e.g., HHI 0.20 → 5 effective holdings even with 50 stocks)
- Diversification ratio: `DR = (Σwiσi)/σp` (higher = better)
- Largest position: `max(wi)`; plus sector / country / currency concentration by grouped weights

## Risk decomposition
Systematic (β, undiversifiable) vs unsystematic (total − systematic, diversifiable); also sector, geographic, currency, liquidity, concentration, tail risk, factor exposure.

## Efficient frontier engine
Minimize `wᵀΣw` subject to `Σwi = 1`, `E(Rp) = target`, `wi ≥ 0`. Tangency portfolio maximizes Sharpe. Capital Market Line: `E(R) = Rf + Sharpe·σ`. Outputs: optimal allocation, current-vs-optimal comparison, **efficiency gap**.

## Risk alignment (vs IPS)
`Risk Alignment Score = 100 × max[0, 1 − |σp − σtarget| / σtarget]` — compares actual portfolio volatility to the user's target from their IPS.

## Portfolio Health Score (0–100)
Weighted composite: `Σ (component weight × component score)`. Example weights:
Diversification 25% (HHI, ENB, DR) · Risk Alignment 20% (vs IPS) · Correlation 15% (avg/hidden correlation, PCA) · Performance 15% (Sharpe, Sortino, expected return) · Drawdown 15% (MDD) · Concentration 10% (largest positions).

## System inputs
- **User (IPS):** risk questionnaire, time horizon, goals, liquidity needs, constraints, target return
- **Portfolio:** holdings, weights, cost basis, current prices, asset classes, sectors, countries, currencies
- **Market:** historical prices, benchmark returns, risk-free rate, market index, analyst estimates (optional), news feed
