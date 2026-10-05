# StockSense AI

An AI-assisted sales forecasting and inventory replenishment recommendation system for small and medium-sized enterprises (SMEs) across diverse product categories. It is a decision-support tool: it recommends, people decide, and it never places orders itself.

**Stack:** Laravel 13 + React 19 (Inertia 3, TypeScript, Tailwind 4, Vite+ / Vite 8, Wayfinder, Fortify) · PostgreSQL 17 · Redis + Horizon · Python/FastAPI + XGBoost (forecasting service) · everything runs in Docker.

**Documentation:** [architecture](docs/architecture.md) · [user guide](docs/user-guide.md) · [deployment](docs/deployment.md) · [security](docs/security.md) · [testing and results](docs/testing.md) · [forecasting method](docs/ml-methodology.md) · [replenishment method](docs/replenishment-methodology.md) · [multi-branch plan](docs/future-multi-branch.md)

## Quick start

Requires Docker with Compose v2. Nothing else needs to be installed on the host.

```bash
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

`db:seed` loads the demo shop (about 35 seconds) and the three demo accounts below. Without it there is nobody to sign in as.

The first start installs Composer and npm dependencies inside the containers, so allow a few minutes. After that, `npm install` runs only when `package-lock.json` changes.

The first page load after Vite starts or restarts can take up to about 30 seconds while it compiles the app over the Windows bind mount, and the page stays blank (dark) until then. Later loads take a few seconds. If the page stays blank for longer, check `docker compose logs vite`.

| What | URL |
|---|---|
| Web app | http://localhost:8080 |
| Vite dev server (hot reload) | http://localhost:5173 |
| ML service docs (Swagger) | http://localhost:8001/docs |
| Mailpit (catches all outgoing email) | http://localhost:8026 |
| PostgreSQL (for DB tools) | `localhost:5433`, db/user `stocksense`, password `secret` |

## Services

| Service | Role |
|---|---|
| `app` | php-fpm running Laravel (also has Node, so run `artisan`, `composer` and `npm` here) |
| `web` | nginx in front of php-fpm |
| `vite` | Vite dev server with hot reload (file polling is enabled for Windows bind mounts) |
| `horizon` | Queue workers: one supervisor for `default` and `imports`, and one for `ml` with a long time limit, since a forecast run trains a model |
| `scheduler` | Runs Laravel's scheduled tasks |
| `ml` | Internal forecasting service (FastAPI + XGBoost). Only Laravel calls it |
| `db` / `redis` / `mailpit` | PostgreSQL 17, Redis 7, local mail catcher |

## Everyday commands

Run these from the repository root. They all execute inside the containers.

```bash
docker compose exec app php artisan test                 # Laravel tests (real PostgreSQL, database stocksense_test)
docker compose exec app composer lint                    # Pint: format PHP (lint:check to only check)
docker compose exec app composer types:check             # Larastan / PHPStan level 7
docker compose exec app npm run check                    # Vite+: oxlint + oxfmt (check:fix to fix)
docker compose exec app npm run types:check              # TypeScript
npm test --prefix web                                    # Vitest component tests (run on the host; see the note below)
docker compose exec ml pytest                            # ML tests (about two minutes: they train real models)
docker compose exec ml python -m scripts.accuracy_report week 8   # how the model compares with the baselines
docker compose exec ml python -m scripts.export_contracts         # rewrite contracts/ after changing app/schemas.py
docker compose exec app php artisan forecast:run week --sync     # make a forecast now, in the terminal
docker compose exec ml ruff check app tests scripts      # ML lint
docker compose exec ml mypy app                          # ML types
docker compose logs -f horizon                           # follow a service's logs
docker compose restart horizon scheduler                 # after changing PHP code or installing packages (see below)
docker compose down                                      # stop (add -v to also delete the database volume)
```

An existing database volume predates the test database. If tests fail with `database "stocksense_test" does not exist`, create it once:

```bash
docker compose exec db createdb -U stocksense stocksense_test
```

## Users and roles

There is no public sign-up. The Owner creates accounts under **Users**; the new person gets an email with a link to choose their own password. Nobody is ever emailed a password, and the email contains no personal details.

| | Owner | Manager | Inventory staff |
|---|:-:|:-:|:-:|
| Users, settings, audit log | ✓ | | |
| Products and categories | ✓ | ✓ | view |
| Stock levels | ✓ | ✓ | view and edit |
| Sales entry and import | ✓ | ✓ | ✓ |
| Run forecasts | ✓ | ✓ | |
| Recommendations | ✓ decide | ✓ decide | view |
| Reports | ✓ all | ✓ all | inventory only |

The matrix lives in `web/app/Enums/Role.php` and is enforced on the server. A test (`RolePermissionMatrixTest`) checks it against the proposal's table.

**Demo accounts** (local development only; created by `php artisan db:seed`, never in production). All use the password `password`:

| Email | Role |
|---|---|
| `owner@stocksense.test` | Owner |
| `manager@stocksense.test` | Manager |
| `staff@stocksense.test` | Inventory staff |

Reset the development database to this state at any time:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

**First Owner in production** (there are no demo accounts there):

```bash
docker compose exec app php artisan app:create-owner "Full Name" owner@example.com
# Add --print-link to print the password link instead of emailing it.
```

Deactivated users cannot sign in and are signed out immediately; their history is kept. Owners cannot deactivate themselves or change their own role, and the system always keeps at least one active Owner.

## Products, categories and stock

- **Categories** group products and carry a target service level (for example 95%), which later drives how much safety stock to hold. A category cannot be deleted while any product, even an archived one, uses it.
- **Products** have a SKU (saved in capitals, unique regardless of capitals), a unit, cost and price, and the reordering inputs: lead time, minimum order quantity, pack size, and an optional reorder point and safety stock. Products are **archived**, never deleted, so sales and stock history stay intact.
- **Stock** is a ledger. Every change (opening stock, restock, stock count, and later sales) is one row in `stock_movements`, and `inventory_levels.on_hand` always equals the sum of those rows. Only `StockService` changes stock, inside a database transaction that locks the row, so two people recording stock at once cannot overwrite each other. The product page shows the ledger.
- **Low-stock flags** compare stock on hand with the product's reorder point. Products without one are never flagged "low". Once forecasts exist, the system will calculate reorder points itself.
- Everyone can record a **restock** (goods received) or a **stock count** (sets stock to what was counted and records the difference with a reason). Only the Owner and Manager can change products and categories.
- **Branches** are not built, but stock is already kept per location. See [docs/future-multi-branch.md](docs/future-multi-branch.md).

The demo data (`php artisan db:seed`) is described under *Demo data* below.

### Importing a product list

**Products > Import from a file** (Owner and Manager) loads a whole catalogue from a CSV or Excel (.xlsx) file. It follows the same upload, check, import, review and undo steps as the sales import below, so it is described here only where it differs. Download the example file from the upload page to see the layout.

- **Columns.** `sku`, `name` and `category` are required. `unit`, `unit_cost`, `unit_price`, `lead_time_days`, `moq`, `pack_size`, `reorder_point`, `safety_stock` and `opening_stock` are optional, and common header variations ("Item Code", "SRP", "Case Size", "On Hand") are recognised. A new product that is not given a value starts with the New product form's defaults: unit `pc`, cost and price 0, lead time 7 days, minimum order and pack size 1, no reorder point or safety stock, no stock. Each number is held to the same limits as the product form.
- **SKUs that already exist** are either *skipped* (the default, so a file that overlaps your catalogue is safe) or *updated*. An update changes only the cells the file gives: an empty cell leaves that product's value as it is. Updating an archived product brings it back. A SKU that appears twice in one file is imported the first time and reported as a problem the second.
- **Categories** are matched by name, ignoring capitals. A name that does not exist is reported as a problem, or, if you tick *Create categories that don't exist yet*, created with a 95% service level.
- **Opening stock** is for new products only. It is written to the stock ledger as an opening-stock movement tied to the import, like any other stock change. The stock of a product that already exists changes only through restocks and stock counts, so the column is ignored for updates, and the preview says so.
- **Undo** archives the products the import created (products are never deleted, so their history stays whole). Changes an import made to products that already existed are not reverted; to bring undone products back, upload the file again and choose to update existing SKUs. **View the imported products** on the result page lists exactly what the import created.

Product changes made by an import are recorded in the audit log under the name of the person who uploaded the file.

## Sales and imports

Sales history is what the forecasts learn from. There are two ways to get it in.

**Entering sales by hand** (**Sales > Record sales**): pick a date, then add a line for each product sold. Choosing a product fills in its usual price. Each line takes that product off the shelf, and the whole sheet is saved together or not at all. A hand-entered sale can be deleted, which puts its stock back; the deletion is recorded in the audit log.

**Importing a file** (**Sales > Import from a file**): upload a CSV or Excel (.xlsx) file with one row per sale. Download the example file from the upload page to see the layout.

1. **Upload.** The system reads the file, finds the header row, and guesses which column is which from the header names (`date`, `sku`, `quantity` and an optional `unit_price`, plus common variations such as "Qty Sold" or "Product Code").
2. **Check.** The preview shows how many rows will be imported, how many were already imported before, and which have a problem and why, with a sample of the file. If the guess was wrong, choose the columns and the date layout yourself (YYYY-MM-DD, MM/DD/YYYY or DD/MM/YYYY) and update the preview. Nothing is saved until you confirm.
3. **Import.** Confirming queues the work. The file is imported a slice at a time by the background workers, so even a large file never ties up the page; the screen shows progress and updates itself.
4. **Review.** The result shows what was imported, what was skipped, and what failed. Download the **error report** (a CSV of every failed row with the reason) to fix and upload again.
5. **Undo.** Any import can be undone: its sales are removed and, if it took stock off, the stock is put back. Both are recorded.

Things worth knowing:

- **Should these sales change stock levels?** Choose *No* for past history (the current stock count already reflects those sales) and *Yes* for sales that have not been taken off your stock yet, such as yesterday's cash register export.
- **Repeats are skipped.** A row is treated as already imported when an earlier import holds a sale for the same product, day, quantity and price, so uploading an overlapping file is safe. Identical rows within one file are all kept (two customers can buy the same thing on the same day), and sales entered by hand are never matched.
- **Dates** can be text in the chosen layout or real Excel date cells. A blank price uses the product's current price; prices like `₱1,250.50` are understood.
- **Limits** are set in `web/config/imports.php`: 10 MB and 50,000 rows per file.
- **Everyone who can enter sales can import them**, matching the access matrix. Stock-affecting imports are logged with who started them.
- **For developers:** sales and products share one import engine (`web/app/Services/Imports`: file reading, the stored rows, the queue job, the batch lifecycle) and one controller and set of React components. Each kind of import is an `ImportDefinition` (its columns, the choices it asks for, how rows are checked and saved, how it is undone) registered in the `ImportType` enum, with routes and a thin page per kind. An import can only be opened through its own kind's routes, so each keeps its own permission.

## Forecasting

Forecasts say how many of each product are expected to sell, week by week (8 weeks ahead) or month by month (3 months ahead), with a range around each number. They are **advice only**: nothing is ordered for anyone. Owners and Managers can see them (**Forecasts** in the sidebar) and start a run; inventory staff cannot.

- **Forecasts page.** Pick Weekly or Monthly. Every forecast product is listed with what is expected next period, the total over the horizon, what actually sold in the same number of recent periods, and how the two compare. Filter by category or by confidence, search, or sort by what is most expected. Open a product for a chart of its recent sales followed by the forecast and its likely range (8 times in 10), plus the exact numbers.
- **Run forecast** queues a run on the `ml` queue, and the page updates itself when it finishes (a minute or two for 50 products). Only one run per granularity goes at a time, so pressing the button twice, or a scheduled run landing on a manual one, does no harm. A run that is stuck for over an hour is written off so it cannot block the next. A run that fails says why (service unreachable, no sales history, ...) and the previous good forecast stays on screen.
- **Schedule.** The `scheduler` container refreshes weekly forecasts early on Monday (02:00) and monthly ones on the 1st (03:00), Asia/Manila time. The Owner can change the day and time, or switch it off, under **System settings** (see below); `web/config/forecasting.php` holds the defaults.
- **Accuracy page.** Every run replays the recent past: the model is shown only what was known at the time, asked to forecast, and compared with what really sold, several times over. The same is done for two simple guesses, **the same period last year** and **a recent average**, because a forecast only earns its place by beating them. The page shows MAE, RMSE, MAPE and WAPE for all three, the breakdown by category, what the model leans on (in plain words), and how accuracy has moved from run to run. WAPE (total error as a percentage of total units sold) is the headline figure, since MAPE is distorted by quiet periods.
- **Low confidence.** A product with under a year of sales history (52 weeks or 12 months) is too young for the model to have seen its seasons. It gets a plain recent average instead, is flagged **Low** confidence, and the product page says so. As its history grows past a year it moves to the model automatically.
- **What is forecast.** Active products that have sold at least once, from sales at the default location. The current week or month is left out until it is over, since a half-finished period looks like a slump.
- **Failures are safe.** Errors shown to people never contain stack traces, addresses or secrets; the detail goes to the log.

How it works is in [docs/ml-methodology.md](docs/ml-methodology.md): the features, the model, the validation and, honestly, the limits. In short, one XGBoost model learns the pattern across all products (so young products borrow strength from old ones), predicting a median and a 10th and 90th percentile; each product's history is first divided by its own average so a fast mover and a slow mover look alike.

Laravel and the ML service talk through a small JSON contract. Its schemas and example payloads live in `contracts/` and are generated from the Python models (`scripts/export_contracts.py`). **Both sides test against the same files**: Laravel checks the requests it builds and the example response it parses, and the ML tests check the service's answers, so a change to one side that breaks the other fails a test. The ML service accepts calls only with the shared secret in `ML_INTERNAL_TOKEN` (set the same value on both sides; change it from the development default before deploying).

Training is capped at 4 threads (`ML_THREADS` on the `ml` service changes it). The data is small, so more threads speed nothing up, and on a busy machine they made a run an order of magnitude slower.

To judge a change to the model without going through Laravel:

```bash
docker compose exec ml python -m scripts.accuracy_report week 8 --folds 4
```

## Replenishment recommendations

From the forecast and the stock on hand, **Recommendations** (in the sidebar) says what to reorder, how many, and by when, with the reasoning in plain words. Like forecasts, it is **advice only**: nothing is ordered for anyone. The Owner and Manager can decide; inventory staff can look.

- **Risk levels.** *Critical* (expected to run out before an order could arrive), *Low* (at or below the reorder point: order now), *Watch* (will reach it within a week), and *Overstock* (months of cover; information only). The cards at the top count each and narrow the list when pressed. Tabs switch between **To decide**, **Overstock** and **Decided**; filter by risk, category or search.
- **Each card** shows how much to order and by when, on hand, on order, the demand expected while the order arrives, safety stock, reorder point and days of cover, then a sentence working it out (for example *"Order 130 now: 10 on hand is below the 70 expected over the 7-day lead time."*). Pack size and minimum order are respected.
- **Decisions.** *Accept*, *Change quantity* or *Dismiss*, each with an optional note; an accepted order can later be *Cancelled*. Every decision records who, when and why, and goes to the audit log. An accepted quantity counts as **on order**, so the product is not recommended again while it is on its way; recording the restock (goods received) clears it. Dismissing leaves a product alone for 7 days.
- **Refreshing.** The advice is recalculated every morning at 06:00 (Asia/Manila), after every forecast run, and with **Recalculate**. A forecast more than 14 days old is flagged. The review period, overstock threshold, snooze days and times are shop-wide settings the Owner can change under **System settings**; `web/config/replenishment.php` holds the defaults.

```bash
docker compose exec app php artisan recommendations:generate --sync   # recalculate now and print what changed
```

The rules (and where they stop being reliable) are in [docs/replenishment-methodology.md](docs/replenishment-methodology.md), with a worked example you can check by hand.

## Notifications

Alerts reach people in the app (the bell in the header, and the **Notifications** page) and by email, so nobody has to keep an eye on the screen.

| Notification | Who gets it |
|---|---|
| Critical stock alert (at most once a day for each product) | Owner, Manager |
| Replenishment digest: counts by level and the most urgent items | Owner, Manager (daily, weekly on Mondays, or never) |
| Forecast ready or failed, with its headline accuracy | Whoever started it, and the Owner |
| Import finished with problems | Whoever uploaded the file |

Each person chooses, under **Settings → Notifications**, which of these they get, whether in the app, by email, or both, and how often the digest comes. The digest goes out at 07:00 after the morning refresh; `php artisan notifications:digest` sends it by hand (add `--weekly` to include the Monday digest on any day).

**Emails never contain personal data.** They open with a generic greeting, carry only product and stock figures, and link back to the app; no names, email addresses or file names. `EmailPrivacyTest` renders every email for a user with a distinctive name and address and searches the output for them, so a new email must be added to its dataset. In development all mail lands in Mailpit at http://localhost:8026.

## Dashboard

The first page after signing in. It shows what each person is allowed to see, and nothing else, so an inventory staff member does not see forecasts and someone with no permissions gets a note to ask the Owner.

- **Headline figures:** sales for the last 30 days against the 30 before, stock value at cost, how many products need ordering (critical and low), and the forecast's typical error against the same week last year. The last three link to Inventory, Recommendations and the accuracy page.
- **Charts:** revenue for each of the last 26 whole weeks (this week is left out, as a half-finished week looks like a slump); units sold with what the forecast expects next and its likely range (all products together, so the range adds up each product's own range and is wider than the true range for the total); and each category's share of the revenue.
- **Lists:** the most urgent products to order, the best sellers by units, and the slowest sellers: products in stock that barely sell, which is money sitting on the shelf.

Sales are over a rolling 30 days rather than a calendar month, which would be nearly empty early in the month.

## Reports

**Reports** in the sidebar has four. Each opens on screen with filters and can be downloaded as **Excel** or **PDF**, with the same figures as the screen.

| Report | Who | Filters |
|---|---|---|
| Sales: units, revenue and share of the total for each product | Owner, Manager | period, category |
| Inventory status: stock on hand and on order, value at cost, stock level and the advice for each product, as of now | Owner, Manager, inventory staff | category |
| Forecast accuracy: MAE, RMSE, MAPE and WAPE for each run, for all products and by category, against the two baselines | Owner, Manager | period, weekly or monthly, category |
| Replenishment history: every recommendation raised in a period, what was decided, by whom, and the quantity | Owner, Manager | period, category |

- The Excel file has the table on its first sheet with real numbers, dates and number formats (so it can be sorted, filtered and added up) and an **About** sheet with what it covers, when it was made, the headline figures and the notes needed to read it. The PDF is landscape A4 with the same, cut off at 1,000 rows (the Excel file has them all).
- A period can cover at most three years. A mistyped or impossible filter in the address falls back to the default and says what was wrong.
- Every export is recorded in the audit log.

## System settings

Shop-wide settings, **Owner only**, under **System settings** (not to be confused with each person's own notification and account settings under their name): the default service level for new categories, the review period, the overstock threshold, how long dismissed advice stays hidden, when to warn that the forecast is old, how far ahead weekly and monthly forecasts look, and the schedule (when forecasts are refreshed, when the advice is refreshed and when the digest email goes out, each switchable).

Only changes are stored; a setting with no change uses the default from the config files, and **Restore defaults** removes every change. They apply straight away, including to the queue workers, which pick them up before each job without a restart. Times are in the shop's time zone, and a change to a schedule takes effect from its next run. The digest must be sent after the advice is refreshed. Every change is in the audit log with the old and new values.

## Audit log

**Audit log** (Owner only) lists who did what, and when, newest first: sign-ins, product and category changes, user changes, sales and imports, forecast runs, recommendation decisions, settings changes and report exports. Filter by area, person, period and a word in the description; open an entry's details to see what changed from and to. It is read-only: nothing can edit or delete an entry. Secrets (passwords, tokens, keys) are never shown.

## Demo data

`php artisan db:seed` (never in production) loads a realistic shop so every screen has something to show: 5 categories, 50 products, **two years of daily sales** (about 30,000 rows, October 2024 to September 2026), and the deliveries that kept the shelves stocked. It takes about 35 seconds and sends the sales through the real import above, so seeding doubles as a test of it. The result is a stock ledger that adds up to today's stock levels, with some products low or out of stock.

The data is made by `ml/scripts/generate_synthetic.py` from a fixed seed, so it is identical on every machine, and the CSV files are kept in `web/database/data/`. The patterns differ by category on purpose, so forecasts can be judged under varied conditions: paydays and December for food, back-to-school in June for stationery, the dry season for hardware, slow and intermittent hardware items, and five products with under a year of history. It also simulates restocking, so stock history includes the occasional stockout.

To regenerate it (only needed if you change the generator; a test fails if the files fall out of step):

```bash
MSYS_NO_PATHCONV=1 docker compose exec ml python scripts/generate_synthetic.py --out /data
```

`web/database/data/sales-sample-with-errors.csv` is a small file with deliberate mistakes (an unknown SKU, an impossible date, a bad quantity and price) for trying out the preview and error report.

## Working with the queue workers

Background jobs (imports, forecast runs, recommendations and notification emails) run in the `horizon` container. A worker loads the code once when it starts, so **after you change PHP code or install a package, restart it**:

```bash
docker compose restart horizon scheduler
```

If you forget, an import fails with an error such as "class not found". The import page then shows it as failed with a message, and nothing is lost: the sales already imported stay, and the import can be undone. A forecast run fails the same way, with its own message, and can simply be started again. Horizon's own dashboard is at `/horizon` (Owner only).

## Testing

| Layer | Run | What it covers |
|---|---|---|
| PHP (Pest, on PostgreSQL) | `docker compose exec app php artisan test` | Business rules, every permission, pages, imports, forecasting, replenishment, notifications, reports, settings, security controls |
| Frontend (Vitest) | `npm test` in `web/` | Every screen: forms, dialogs, charts, tables, role-dependent content |
| ML service (pytest) | `docker compose exec ml python -m pytest` | Features without leakage, metrics, the model's accuracy, the API, the contract |
| Static analysis | `composer lint:check`, `composer types:check`, `npm run check`, `npm run types:check`, `ruff`, `mypy` | Style and types |
| **End to end** (Playwright) | see [e2e/README.md](e2e/README.md) | Sign-in, roles, security headers and the content security policy, onboarding, the whole sales-to-decision journey, accessibility, against the production stack |
| **Load** (k6) | see [tests/load/README.md](tests/load/README.md) | 25 and 100 concurrent users, report downloads, forecast run time |

Run only one PHP suite at a time: they share a test database. The frontend tests run on the host rather than in Docker because installing npm packages inside the containers is very slow on some Windows setups; CI runs them in a clean Linux environment either way. Containers pick up new npm packages when the `vite` service next restarts.

What is tested, the results (accuracy, load, accessibility), and what is **not** tested are in [docs/testing.md](docs/testing.md).

## Running in production

`compose.prod.yaml` is the production stack: the code and the built assets are inside the images, nothing is exposed but the web port, every container runs unprivileged, and the application **refuses to start** with a configuration that must not go to production (debug on, development secrets, well-known passwords, an unusable key). In short:

```bash
cp .env.production.example .env       # fill in every REQUIRED value
docker compose -f compose.prod.yaml up -d --build --wait
docker compose -f compose.prod.yaml exec app php artisan app:create-owner "Full Name" owner@example.com
```

Put a reverse proxy that ends HTTPS in front. Everything else (TLS, updating, backups, what the start-up check does, a checklist before real use) is in [docs/deployment.md](docs/deployment.md). What protects the system, and what does not, is in [docs/security.md](docs/security.md).

## Repository layout

```
web/         Laravel + Inertia React app
ml/          FastAPI forecasting service (XGBoost)
contracts/   JSON Schemas shared by Laravel and the ML service
docker/      PHP and nginx images (development and production) and Postgres init files
e2e/         Playwright end-to-end tests and the throwaway stack they run on
tests/load/  k6 load tests
docs/        Architecture, deployment, security, testing evidence, methodology, user guide
compose.yaml       Development stack (bind mounts, Vite, Mailpit)
compose.prod.yaml  Production stack
.github/     CI workflow
```

## Notes

- **Mailpit is on port 8026**, not its default 8025, to avoid clashing with other local projects.
- **Accounts:** there is no public sign-up. The Owner creates user accounts. Users get email verification, optional two-factor authentication, and password confirmation for sensitive actions.
- **npm installs:** `web/node_modules` on the host is only for editor IntelliSense. Containers use their own Linux copy in a Docker volume. Commit `package-lock.json` whenever dependencies change.
- **Email policy:** emails never contain personal data. They use a generic greeting and only product, stock and forecast figures. A test renders every email as sent and searches it for a user's name and address.
- **Security headers** come from the application (`SecurityHeaders` middleware): a Content-Security-Policy with a per-response nonce, plus framing, sniffing, referrer and permissions headers. The policy is off in development, because the Vite dev server serves scripts from another port, and on in production.
- **Multi-branch support is planned, not built.** The data model already carries a `location_id` so branches can be added later without migrating data.

## Branching

`main` holds released work. Day-to-day work happens on `develop`, and each implementation phase is committed and pushed there. CI runs on every push and pull request: lint, types and tests for each part, dependency audits, and a build of the production images that starts them through the strict check. The end-to-end suite runs on pull requests to `main`.
