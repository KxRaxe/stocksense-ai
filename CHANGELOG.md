# Changelog

StockSense AI was built in phases, each committed to `develop`. This is what each one added.

## First release (the MVP)

### Phase 7: hardening and release

- **Production stack** (`compose.prod.yaml`): code and built assets inside the images, OPcache that never re-reads files, errors to the log and never the page, every container unprivileged, only the web port published, Redis behind a password, the database migrated and the roles seeded before the app starts. The ML image is 1.2 GB (CPU-only XGBoost), down from 2.8 GB.
- **Strict start-up check** (`php artisan app:check`): every container refuses to start with debug on, the development secret, a well-known database password, an unusable encryption key, developer tooling on, or demo accounts allowed. The ML service refuses the development token too.
- **Security**: a nonce-based Content-Security-Policy and the usual browser headers; rate limits on downloads, forecasts, recalculation, uploads and set-up emails, with a message instead of an error page; a second sign-in limit in nginx; spreadsheet formula-injection guard in exports; trusted-proxy support; demo seeders that refuse to run in production; a test that fails if any route is reachable without a login unless listed; local-disk file routes off. Dependency audits are clean.
- **End-to-end tests** (Playwright, 61 tests against the production stack): sign-in, roles, security headers and the policy on 17 pages, onboarding by emailed link with no personal data, the whole journey from importing sales to accepting a recommendation and exporting a report, accessibility (axe) and keyboard.
- **Load tests** (k6): 25 and 100 concurrent users, report downloads, forecast run time. 100 users at about 99 requests a second: no failures, 95% of page loads under 511 ms.
- **Fixes the end-to-end run found**: a start-up check that accepted an unusable key; the policy blocking the theme script on every page; nginx answering 502 after the app was recreated; a bare "429" page for someone locked out; low-contrast text (amber "Low" badges, tab labels).
- **CI**: dependency audits, a build of the production images that boots through the strict check and probes headers and exposure, and the end-to-end suite on pull requests to `main`.
- **Documentation**: architecture, deployment, security, testing and results, user guide.

### Phase 6: dashboard, reports, settings, audit log

Dashboard with headline figures, weekly sales, forecast with its range, category shares, urgent products and movers (each role sees only its part). Four reports (sales, inventory status, forecast accuracy, replenishment history) on screen, as Excel (real numbers and formats, an About sheet) and as PDF. Owner-only system settings (stock-advice rules, horizons, schedules) that apply immediately, including to running queue workers. Owner-only read-only audit log.

### Phase 5: replenishment and notifications

Reorder advice from the forecast and the stock position: safety stock from the service level and the forecast's own error, reorder point, order-up-to level, five risk levels, a sentence of reasoning for each. Accept, change quantity, dismiss and cancel, each recorded; accepted quantities count as on order until the goods arrive. Critical-stock alerts (once a day per product), a digest, forecast and import notifications, in the app and by email, per-person preferences, a bell. Emails never contain personal data.

### Phase 4: forecasting

A pooled XGBoost quantile model (median and 10th and 90th percentiles) trained across all products, validated by rolling-origin backtest against "the same week last year" and "a recent average", with a plain-average fallback and a low-confidence flag for products with under a year of history. A JSON contract shared and tested by both services. Forecast, accuracy and per-product pages; scheduled runs.

### Phase 3: sales and imports

Manual sales entry, CSV and Excel import with a preview, duplicate detection, a downloadable error report and undo, on a shared import engine (also used for products); a synthetic demo shop of 50 products and 30,000 sales.

### Phase 2: products, categories, inventory

Catalog, a stock ledger with cached levels per location (ready for branches), restocks and stock counts, low-stock flags.

### Phase 1: users and roles

Owner, Manager and Inventory staff with a written permission matrix, user management by the Owner, an audit trail.

### Phase 0: foundation

Laravel + Inertia React starter, PostgreSQL, Redis and Horizon, the FastAPI service, Docker, CI.
