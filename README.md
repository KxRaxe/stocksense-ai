# StockSense AI

An AI-assisted sales forecasting and inventory replenishment recommendation system for small and medium-sized enterprises (SMEs) across diverse product categories. It is a decision-support tool: it recommends, people decide, and it never places orders itself.

**Stack:** Laravel 13 + React 19 (Inertia 3, TypeScript, Tailwind 4, Vite+ / Vite 8, Wayfinder, Fortify) · PostgreSQL 17 · Redis + Horizon · Python/FastAPI + XGBoost (forecasting service) · everything runs in Docker.

## Quick start

Requires Docker with Compose v2. Nothing else needs to be installed on the host.

```bash
docker compose up -d --build
docker compose exec app php artisan migrate
```

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
| `horizon` | Queue workers (queues: `default`, `imports`, `ml`) |
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
docker compose exec ml pytest                            # ML tests
docker compose exec ml ruff check app tests              # ML lint
docker compose exec ml mypy app                          # ML types
docker compose logs -f horizon                           # follow a service's logs
docker compose down                                      # stop (add -v to also delete the database volume)
```

An existing database volume predates the test database. If tests fail with `database "stocksense_test" does not exist`, create it once:

```bash
docker compose exec db createdb -U stocksense stocksense_test
```

## Repository layout

```
web/         Laravel + Inertia React app
ml/          FastAPI forecasting service (XGBoost)
contracts/   JSON Schemas shared by Laravel and the ML service
docker/      Dockerfile, nginx and Postgres init files
docs/        Architecture, ML methodology, testing evidence, user guide
.github/     CI workflow
```

## Notes

- **Mailpit is on port 8026**, not its default 8025, to avoid clashing with other local projects.
- **Accounts:** there is no public sign-up. The Owner creates user accounts. Users get email verification, optional two-factor authentication, and password confirmation for sensitive actions.
- **npm installs:** `web/node_modules` on the host is only for editor IntelliSense. Containers use their own Linux copy in a Docker volume. Commit `package-lock.json` whenever dependencies change.
- **Email policy:** emails never contain personal data. They use a generic greeting and only product, stock and forecast figures.
- **Multi-branch support is planned, not built.** The data model already carries a `location_id` so branches can be added later without migrating data.

## Branching

`main` holds released work. Day-to-day work happens on `develop`, and each implementation phase is committed and pushed there. CI runs on every push and pull request.
