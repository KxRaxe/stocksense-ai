# Testing

How StockSense AI is tested, what each kind of test is for, how to run it, and what it found. The evidence is organised by the quality characteristics of **ISO/IEC 25010**, the standard the thesis evaluates against.

## The layers

| Layer | Tests | Tool | What it proves | Runs |
|---|---:|---|---|---|
| PHP unit and feature | 1,537 | Pest, on a real PostgreSQL | Business rules, every permission, every page's data, imports, forecasting plumbing, replenishment maths, notifications, reports and exports, settings, security controls | CI on every push |
| Frontend components and pages | 360 | Vitest + Testing Library | Every screen's rendering and behaviour: forms, dialogs, charts, tables, filters, role-dependent content | CI on every push |
| ML service | 204 | pytest | Features without leakage, metric maths, baselines, the model's accuracy, the API, the contract | CI on every push |
| Contract | both sides | JSON Schema | Laravel's requests and the ML service's answers match the same schemas and examples | Inside the PHP and ML suites |
| Static analysis | | Larastan (level 7), Pint, `tsc`, oxlint, ruff, mypy | Types and style, before anything runs | CI on every push |
| **End to end** | **72** | Playwright, in a real browser, against the production stack | The whole product as a person uses it, including roles, onboarding, security headers and accessibility | CI on pull requests to `main` |
| **Load** | 3 scripts | k6, against the production stack | Speed and stability under many users at once | By hand (see below) |
| Dependency audits | 3 | composer, npm, pip-audit | No known vulnerabilities in what is depended on | CI on every push |

```bash
# PHP (in the app container; needs the dev stack: docker compose up -d)
docker compose exec app php artisan test
docker compose exec app composer lint:check && docker compose exec app composer types:check

# Frontend
docker compose exec app npm test && docker compose exec app npm run check && docker compose exec app npm run types:check

# ML
docker compose exec ml python -m pytest

# End to end and load: see e2e/README.md and tests/load/README.md
```

Run only **one PHP suite at a time**: they share a test database, and two overlapping runs deadlock while migrating. The full suite takes about ten minutes on a laptop.

## How the tests are written

- **Real database, real queue semantics.** Tests run on PostgreSQL (not SQLite) because the product relies on it: weekly bucketing with `date_trunc`, and partial unique indexes that guarantee one open recommendation per product and one active forecast run per granularity even when two workers race. A race test proves the second one is refused.
- **Tests state the rule.** Test names read as sentences ("keeps staff from cancelling and from refreshing", "counts a changed quantity and a cancelled order as accepted"), so a failure says what stopped being true.
- **Role matrices, not spot checks.** Every route is tried by every role. Another test walks the route table and fails if any route is reachable without a login and is not on a short allowlist.
- **Controls are tested by removing them.** For security controls that could silently regress, the tests were also run with the control switched off to see that they fail (the spreadsheet formula guard, for one: without it the injection tests fail).
- **Faithful emails.** Emails are rendered and sent through the mail layer and the *real output* is searched for a user's name and address, not just the template. A further test fails if any notification that sends mail is missing from that suite.
- **Both sides of the ML contract.** The Python models export JSON Schemas and example payloads into `contracts/`; Laravel validates what it sends and parses the example answer; the ML tests validate what the service answers. Change either side wrongly and a test fails.
- **A clock you control.** Tests that depend on the date travel to a fixed Monday, so they do not drift.

## Evidence by quality characteristic (ISO/IEC 25010)

### Functional suitability: does it do what the proposal says?

| Objective | Evidence |
|---|---|
| Forecast sales with XGBoost, weekly and monthly, with an interval | `ml/tests` (features, model, forecaster, API); the live run in the E2E journey forecasts all 50 products |
| Report MAE, RMSE, MAPE (and WAPE) against baselines | The accuracy page and report; `test_model_quality.py` fails the build if the model stops beating both baselines |
| Flag products with under 12 months of history as less reliable | Forecasting tests (fallback to a recent average, `low_confidence`), shown on the forecast pages |
| Recommend when and how much to reorder, with the reasoning | `ReplenishmentCalculatorTest` (every edge case: no demand, overrides, minimum order and pack rounding, on order, overstock), `RecommendationGeneratorTest`, `e2e/05-journey` |
| Advisory only; every decision recorded | The accept, adjust, dismiss and cancel tests; the audit log; nothing in the code places an order |
| Alerts by email and in the app | Notification tests for recipients, preferences and once-a-day dedupe; the real emails in `e2e/05-journey` |
| Role-based access (Owner, Manager, Inventory staff) | `RolePermissionMatrixTest` against the proposal's table; `e2e/03-roles` |
| Import sales and products from spreadsheets with validation | Import tests with valid, malformed and duplicate files; `e2e/05-journey` (preview, problems, error report) |
| Dashboard and four exportable reports | `DashboardDataTest`, `ReportDefinitionsTest`, `ReportPagesTest` (Excel files opened and read back); `e2e/05-journey` downloads both formats |

### Performance efficiency

Measured with k6 against the **production stack** on a development laptop (12 logical cores, 8 GB given to Docker), with the database, Redis, the ML service, k6 and the browser all sharing the same machine, so these are a floor, not what dedicated hardware would give. The data is the demo shop: 50 products and 30,084 sales.

| Test | Load | Result |
|---|---|---|
| Browsing a working day (`pages.js`) | up to **25 people** at once, pausing 1 to 3 s between pages | 1,012 requests, **0 failed**, page loads **median 44 ms, 95% under 69 ms**, 99% under 92 ms. Slowest page (dashboard) 95% under 81 ms |
| Stress (`pages.js`, `VUS=100 THINK=0.3`) | up to **100 people** at once, about **99 requests a second** | 14,352 requests, **0 failed**; median 201 ms, **95% under 511 ms**, 99% under 1.4 s |
| Report downloads (`exports.js`) | six a minute, Excel and PDF | **Excel 95% under 120 ms; PDF 95% under 300 ms**; 0 failed |
| Forecast run (`forecast-run.js`) | weekly and monthly runs over all 50 products, three people browsing meanwhile | **2 to 5 s** on the server (about 9 s counting the page's own polling); pages stayed up, 95% under 2 s during the run, 0 failed |
| Memory | whole stack at rest | about 770 MB; peak while forecasting: ML service 170 MB, queue worker 418 MB |

The thresholds the scripts hold the app to (under 1% failures, 95% of page loads under 1 s) are met with a wide margin. What the numbers do **not** show: a larger shop. The demo has 50 products and two years of daily sales. Forecast time grows with products and history; the model is pooled across products and the data small enough that this is unlikely to bite below a few thousand products, but that has not been measured.

The speed comes from ordinary choices, not tricks: assets are built, compressed and cached for a year; OPcache never re-reads files; configuration, routes and views are cached; queries aggregate in the database (`date_trunc`, `sum`) rather than in PHP; and slow work (imports, forecasts, recommendations, emails) is on queues, never in a web request.

### Reliability

- **Failures are safe and visible.** A forecast that fails (service down, no history) keeps the previous good forecast on screen and says why in plain words; a crashed worker's run is written off after an hour so it cannot block the next. Imports can be undone; a failed import reports its rows.
- **Races are guaranteed away by the database**, not hoped away: partial unique indexes, tested with a competing writer.
- **Idempotent work.** Re-running recommendations updates rows in place; alerts are deduplicated per product per day; the migration container is safe to run on every start.
- **Recovery is rehearsed.** Backups are `pg_dump`; the procedure to restore is in [deployment.md](deployment.md) (and should be tried on your own data).
- **Redeploying is survivable.** Running the production stack found that nginx kept a stale address for the app container after it was recreated (a 502 until nginx was restarted). It now looks the address up again, and recreating the app container on the running stack was checked to recover within seconds. (That check was done by hand, not by an automated test.)

### Security

See [security.md](security.md) for each control and the test that proves it. In short, tested: roles on every route, no route reachable without a login by accident, sign-in and action throttles, a nonce-based content security policy exercised in a real browser on 17 pages, security headers, cookie flags, spreadsheet formula injection, no personal data in any email as sent, nothing private reachable (`/.env`, `/.git`, `vendor/`), and a start-up check that refuses unsafe configuration. Dependency audits (`composer`, `npm`, `pip-audit`) find no known vulnerabilities. **No independent penetration test has been done.**

The end-to-end suite earned its place here. Running it against the production stack found, before any user did:

1. the start-up check accepted an encryption key Laravel could not use (it checked length, not usability);
2. the content security policy blocked the starter kit's inline theme script on every page (fixed with a per-response nonce rather than a weaker policy);
3. nginx served 502s after the app container was recreated;
4. a locked-out person saw a bare "429" error page instead of a message;
5. nginx's own flood limit counted page views, and would have locked out a shop's staff behind one address.

### Usability and accessibility

- Automated checks with **axe (WCAG 2.1 A and AA)** on ten pages in `e2e/06-accessibility`, **in both light and dark mode**: **no serious or critical violations**. It found low-contrast text (amber "Low" badges, muted tab labels on a muted background at 4.4:1 against the required 4.5:1), which was fixed.
- Keyboard checks: focus is visible, and dialogs trap focus and close with Escape.
- Plain language is a design rule, tested where it can be: errors name what to do; every recommendation carries a sentence of reasoning; emails and reports explain how to read them.
- Automated checks cannot find every problem (they cannot judge whether a label makes sense, or whether the page is pleasant to use with a screen reader). **A screen-reader pass and the user acceptance questionnaire with real staff are not done** and belong to the thesis's survey work.

### Compatibility and portability

- Everything runs in Docker from one command; the same images run in CI and in production. With the images built, the stack came up from nothing (no volumes, no data) in **35 seconds** and the demo data loaded in 15.
- The ML service and Laravel interoperate through a versioned JSON contract, so either can be upgraded or replaced independently.
- Tested in Chromium. Other browsers have not been tested.

### Maintainability

- Typed throughout (Larastan level 7, `tsc` strict, mypy strict), linted and formatted in CI.
- Single sources of truth, each guarded by a test: the access matrix (one enum, parity with the frontend's types and with the proposal's table), the settings schema, the notification types, the report registry.
- Dependencies are locked and audited; images build from the locks.
- Adding a report, a setting or a notification means writing it and adding it to one list; the tests that walk those lists pick it up (every email must be added to the privacy test's dataset, and the test fails to remind you if a mail notification is missing).
- Documentation lives beside the code: [architecture](architecture.md), [ML methodology](ml-methodology.md), [replenishment methodology](replenishment-methodology.md), [deployment](deployment.md), [security](security.md), [user guide](user-guide.md).

## Forecast accuracy

Reported on the synthetic shop (50 products, 24 months of daily sales, built to resemble Philippine retail: paydays, December and back-to-school peaks, a dry-season lift for hardware, intermittent slow movers, five products with under a year of history). Details and the honest limits are in [ml-methodology.md](ml-methodology.md).

| Weekly, 8 weeks ahead, 4 test windows (1,440 product-weeks) | MAE | RMSE | MAPE | WAPE |
|---|---:|---:|---:|---:|
| Forecasting model | 9.3 | 15.8 | 25.5% | **15.9%** |
| Same week last year | 11.4 | 19.3 | 30.6% | 19.5% |
| Recent average | 10.4 | 16.9 | 30.1% | 17.7% |

The model beats both baselines overall and beats last year's number in four of five categories. In the live application, each run replays three windows and reports the same figures on its Accuracy page (the last end-to-end run measured 16.3% against 19.7% and 18.4%). A test fails the build if these slip: the model must beat both baselines on MAE, RMSE and WAPE, beat last year's by at least 10%, win four of five categories, keep WAPE under 25% and its interval coverage between 55% and 95%.

**Be careful with these numbers.** They are on *synthetic* data, which is generated with patterns the model can find; real shops are messier. They show the method works and is correctly validated (no leakage, rolling-origin), not what accuracy a particular shop will see. Run it on real data through the importer and read the Accuracy page.

## User acceptance testing

People test it too. The [student testing manual](manual/testing-manual.html) ([PDF](manual/StockSense-AI-Testing-Manual.pdf)) takes a tester from installing WSL 2 and Docker Desktop on Windows to running the app, then through 138 test cases covering every function and all three roles, each with steps, the expected result and pass/fail/blocked boxes. It ends with an ISO/IEC 25010 rating sheet (the eight characteristics, 1 to 5), a bug report form and a sign-off sheet. Testers use an ID instead of their name. The manual's screenshots and PDF are rebuilt from the running app with `npm run manual` in `e2e/`.

## What is not tested

- **Real shop data.** Everything above is on the synthetic dataset.
- **Scale beyond the demo.** Hundreds of thousands of sales rows, thousands of products and many concurrent Owners have not been measured.
- **Browsers other than Chromium, and mobile layouts** beyond a manual look.
- **Email delivery to a real mailbox.** Emails are verified in a mail catcher; deliverability (SPF, DKIM, spam filters) depends on the SMTP service chosen.
- **A penetration test**, a screen-reader review, and the **user acceptance survey** with respondents.
- **Backup restore under a real failure.** The procedure is documented and was exercised on test data only.
