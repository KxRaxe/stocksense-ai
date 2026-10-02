# Forecasting methodology

How StockSense AI turns a shop's sales history into forecasts, how that is measured, and where it falls short. Organised along the CRISP-DM stages (business understanding, data, preparation, modelling, evaluation, deployment) so it can be cited in the capstone report.

All code is in `ml/app/`. The figures quoted come from the synthetic demo shop (`ml/scripts/generate_synthetic.py`); they describe how the method behaves, **not how accurate it will be for a real shop**, whose demand will be noisier in some ways and simpler in others.

## 1. Business understanding

A small shop owner has to decide how much of each product to reorder and when. The forecast answers the first half of that: *how many of this product will I sell over the next few weeks?* The replenishment recommendation (Phase 5) combines the answer with stock on hand, lead time and a target service level.

What the system must do, from the proposal:

- Forecast sales per product with XGBoost, weekly and monthly.
- Report MAE, RMSE and MAPE, so the forecast can be judged.
- Treat products with under 12 months of history as less reliable.
- Stay advisory: it never orders anything.

A forecast is only worth having if it beats a cheap alternative, so every run is compared with two: *the same period last year* and *the average of the last few periods*.

## 2. Data understanding

**Input.** Units sold per product per week or month. Laravel builds it from the `sales` table (`SeriesBuilder`):

- Weeks run Monday to Sunday; months are calendar months.
- Each product's series starts at its first sale and runs to the last **complete** period. The week or month in progress is dropped: a half-finished period looks like a collapse in demand.
- Quiet periods are filled with zero. A week with no sales is information, not missing data.
- Only active products with at least one sale are included.
- Each period also carries the average selling price. Nothing personal is sent: a series is a product, its category name, and numbers.

**Synthetic data.** The demo shop has 50 products in the proposal's five categories and two years of daily sales, built from a fixed seed so it is identical everywhere. Patterns differ by category on purpose: paydays and December lift food, back-to-school lifts stationery in June, the dry season lifts hardware, some hardware items sell intermittently, and five products are under a year old.

**A limit worth knowing.** Sales are not demand. When a product is out of stock, nothing sells, so the history understates what people wanted. The generator includes occasional stockouts (about 3% of product-days), and the model does not correct for them. See limitations.

## 3. Data preparation

The series become a grid: a row per period, a column per product, empty before a product existed. Working on the grid keeps products with different start dates aligned without a loop per product.

**Scaling.** Each product's history is divided by its own average (its *scale*) before the model sees it. A value of 1.0 then means "a typical period for this product". Without this, one model could not learn from a product selling 2 a week and another selling 200: the big one would dominate. With it, they share a pattern, and products with short histories borrow strength from the rest. Predictions are multiplied back by the scale. The scale is computed only from the periods the model is allowed to see.

**No peeking.** Every history feature at period *t* is computed from periods before *t* (lags and rolling statistics are shifted by one). The same functions build the features for training and for forecasting, so a feature means the same thing in both. `ml/tests/test_features.py` changes the future and checks that no feature up to that point moves, and `ml/tests/test_evaluation.py` checks that a model trained as of a past date does not change when everything after that date is replaced with nonsense.

## 4. Features

| Group | Features | Why |
|---|---|---|
| Recent demand | Lags (weekly: 1, 2, 3, 4, 8, 12, 52; monthly: 1, 2, 3, 6, 12) | What sold lately, and a year ago |
| Trend and noise | Rolling mean (weekly 4, 8, 12; monthly 3, 6), rolling standard deviation (weekly 4, 8; monthly 3, 6) | Level and volatility |
| Intermittency | Share of the last 8 weeks (6 months) with no sales | Slow movers sell in bursts |
| Maturity | Periods since the product's first | Telling a launch from a staple |
| Calendar | Week or month of the year, month, quarter | Seasonality |
| Philippine retail calendar | December share, Christmas rush (15-24 Dec), back-to-school (mid May to June), paydays in the period (the 15th and the last day of the month), days in period | The events that move demand here; each is the *share of the period's days* inside the window, so a week that straddles an edge is handled smoothly |
| Product | Category, log of the product's average demand, log of its typical price (as known at the time) | Who it is |

Missing values (the first weeks of a product's life, where there is no lag yet) are left empty: XGBoost handles them natively.

## 5. Modelling

**One pooled model per granularity.** A single XGBoost model is trained across all products. Per-product models would each have to learn seasonality from 50 to 100 points; pooling lets them share it, and is what makes forecasting a young product at all reasonable.

**Quantile regression.** The model is trained with XGBoost's quantile-error objective for the 10th, 50th and 90th percentiles at once. The median is the forecast; the 10th and 90th bracket it ("expected to sell at least this much nine times in ten, and at most this much nine times in ten"). The three outputs are sorted so the range can never be upside down, and clipped at zero.

**Several periods ahead.** Forecasts are made recursively: predict the next period, treat the median as if it had happened, predict the one after. The range for each period comes from the model's quantiles for that period, so it does not compound. (It is therefore a little optimistic about far-off weeks: the uncertainty in the earlier steps is not carried forward.)

**Settings.** 250 trees, depth 5, learning rate 0.05, row and column subsampling of 0.8, L2 regularisation of 2. Several alternatives were tried on the synthetic shop (shallower or deeper trees, more trees, heavier subsampling); none was better by more than the run-to-run noise, so the defaults stay. The features mattered far more than the settings.

**The fallback.** A product with under a year of history (52 weeks or 12 months, the proposal's rule) is not forecast by the model. It gets the average of its last 4 weeks (3 months), held flat, with a range of that average plus or minus 1.28 times the product's recent spread (the 80% interval if demand were normal). It is flagged **low confidence** in the data and on screen, and moves to the model by itself once it has a year of history. Young products *are* used to train the model, so what it learns about them is not wasted.

**Model registry.** Each run saves the model it trained (`.ubj` file) with a small JSON describing it: what it was trained on, its features and settings, and the accuracy measured. The version string is stored with every forecast Laravel saves, so any number can be traced to the model that made it. The five most recent per granularity are kept.

## 6. Evaluation

**Rolling-origin backtest.** Pick a point in history, pretend it is "now", train on what was known then, forecast the next few periods, and compare with what actually happened. Repeat from several points, each time letting the model see a bit more (an expanding window). The windows do not overlap and the newest ends at the latest data, so the numbers describe how the model does on recent, unseen periods. A normal run uses 3 windows of 8 weeks (or 3 months).

**Who is scored.** Only products with at least a year of history *at that point*, because those are the ones the model is trusted with. The two baselines are scored on exactly the same product-periods.

**Baselines.**

- *Seasonal naive*: the same period last year. (For a product that did not exist a year earlier, its recent average, so the baseline is defined everywhere.)
- *Moving average*: the average of the last 4 weeks (3 months), held flat.

**Measures.**

| Measure | Meaning | Caveat |
|---|---|---|
| MAE | Average miss, in units | Depends on product size |
| RMSE | Like MAE but large misses count extra | Dominated by the biggest products |
| MAPE | Average miss as a percentage of what sold | Undefined when nothing sold, so periods with no sales are left out; flatters slow movers |
| WAPE | Total miss as a percentage of total units sold | The headline figure: weights products by volume and survives zeros |
| Coverage | Share of actual values inside the 10th-90th percentile range | The target is 80% |

**Typical error per product.** From the backtest, each product's root-mean-square forecast error in units per period is kept (`residual_std`). Products with too few scored periods fall back to the spread of their own recent history. Phase 5 uses it to size safety stock.

### Results on the synthetic shop

Weekly, 8 weeks ahead, 4 test windows (1,440 product-weeks):

| | MAE | RMSE | MAPE | WAPE |
|---|---:|---:|---:|---:|
| Forecasting model | 9.3 | 15.8 | 25.5% | **15.9%** |
| Same week last year | 11.4 | 19.3 | 30.6% | 19.5% |
| Recent average | 10.4 | 16.9 | 30.1% | 17.7% |

By category (WAPE), the model beats last year's number in four of the five categories. In school and office supplies the two are level or the model is slightly worse (28.8% against 28.4% here; 31.6% against 28.8% in a normal 3-window run): it is strongly seasonal, so last year's value is already a good guide there. Interval coverage was 70%, short of the 80% target.

Monthly, 3 months ahead, 3 windows (405 product-months): model 9.2%, same month last year 9.3%, recent average 14.6%. With only two years of history the monthly model has little to learn beyond "copy last year", and it says so: its most important feature is the value twelve months ago. Coverage was about 60%.

`ml/tests/test_model_quality.py` guards the weekly result: the model must beat both baselines on MAE, RMSE and WAPE, beat last year's number by at least 10%, win in at least four of five categories, keep WAPE under 25%, and keep coverage between 55% and 95%.

Reproduce with `docker compose exec ml python -m scripts.accuracy_report week 8 --folds 4`.

## 7. Deployment

- **Service.** FastAPI, internal to the Docker network; only Laravel calls it, with a shared secret in the `X-Internal-Token` header. `POST /forecast` measures, trains and forecasts; `POST /backtest` measures only, with more detail per window; `GET /models` lists saved models.
- **Contract.** `contracts/*.schema.json` (generated from `ml/app/schemas.py`) and the example payloads in `contracts/fixtures/` are shared by both sides' tests. The ML service additionally enforces what a schema cannot say: periods consecutive and on week or month boundaries, every series ending on the same period, horizons within limits.
- **Runs.** A run is a queued job (`RunForecastJob`, queue `ml`, its own Horizon supervisor with a 15-minute limit), started by a person or by the scheduler (Mondays 02:00 weekly, the 1st 03:00 monthly). Each run retrains from scratch on the latest sales, which at this scale takes under a minute.
- **Storage.** `forecast_runs` keeps the accuracy, feature importance and per-product error of every run; `forecasts` keeps the numbers of the latest five completed runs per granularity.

## 8. Limitations and next steps

1. **Stockouts hide demand.** Sales while a product is out of stock are zero, so the model learns "low demand" where there was none. A fix is to treat stockout periods as missing and exclude them from training and scoring. The stock ledger already has what is needed.
2. **Intervals are too narrow** (about 70% coverage for the weekly 80% range). Widening them by a calibration factor learned from the backtest residuals is a small change worth making.
3. **No price or promotion effects.** The model sees a product's typical price but not price changes. If a shop runs promotions, recording them would let the model learn their effect.
4. **Intermittent products are hard.** Items that sell a few units a few times a year are forecast poorly by any method; their WAPE is highest. A purpose-built method (such as Croston's) or forecasting them at monthly granularity would help.
5. **Recursive range is optimistic** for the later periods of a horizon, as noted above.
6. **Monthly forecasts need more history** than the two years in the demo; with three or four, seasonality is learned rather than copied.
7. **One location.** Forecasts are made per product at the default location. The series keys (`product:location`) and `location_id` columns are in place for forecasting per branch later; that work is listed in [future-multi-branch.md](future-multi-branch.md).
8. **No automatic drift watch.** Accuracy over time is shown on the Accuracy page, but nothing alerts when it worsens. A threshold alert would fit the notifications work in Phase 5.
9. **Synthetic evidence.** Everything above was measured on generated data. The proposal's evaluation on real SME data replaces these figures; the import module loads it with no code change.
