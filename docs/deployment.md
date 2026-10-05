# Deploying StockSense AI

This is how to run StockSense AI for real, on one machine, with Docker. It uses `compose.prod.yaml`, which differs from the development stack in the ways that matter: the code and the built assets are baked into the images, nothing is exposed but the web port, everything runs without privileges, and the application **refuses to start** with a configuration that must not go to production.

## What you need

- A Linux server (or any machine with Docker 24 or newer and Docker Compose v2) with **4 GB of memory** or more and about 5 GB of disk for the images.
- A **domain name** and a way to serve it over **HTTPS**: a reverse proxy or load balancer that ends TLS (Caddy, Traefik, nginx, a cloud load balancer). The stack serves plain HTTP on one port; without HTTPS, passwords and sessions cross the network in the clear.
- An **SMTP service** for email (set-up links for new people, stock alerts, the digest).

## First deployment

```bash
git clone https://github.com/KxRaxe/stocksense-ai.git && cd stocksense-ai
git checkout main                     # or the release you want
cp .env.production.example .env
```

Edit `.env`. Every value marked REQUIRED must be set; the stack will not start without them:

| Setting | What to put |
|---|---|
| `APP_URL` | The address people will use, `https://stock.example.com` |
| `APP_KEY` | `echo "base64:$(openssl rand -base64 32)"` |
| `DB_PASSWORD`, `REDIS_PASSWORD` | Long random values, `openssl rand -hex 24` |
| `ML_INTERNAL_TOKEN` | A long random value (at least 24 characters). The same value is given to the app and to the forecasting service |
| `MAIL_*` | Your SMTP service |
| `TRUSTED_PROXIES` | The reverse proxy's address, or `*` if the app can be reached only through it |

Then build and start:

```bash
docker compose -f compose.prod.yaml up -d --build --wait
```

The first build takes several minutes (it installs the dependencies and builds the JavaScript). When it finishes, the database has been migrated and the roles and permissions seeded, and the site answers on `HTTP_PORT` (80 by default).

Create the first Owner. There are **no demo accounts in production**, and the demo seeders refuse to run:

```bash
docker compose -f compose.prod.yaml exec app php artisan app:create-owner "Full Name" owner@example.com
# Add --print-link to print the password link instead of emailing it.
```

The Owner opens the link, chooses a password, signs in, and creates everyone else under **Users**. From **System settings** they can change how stock advice is worked out and when things run, and **Forecasts** is where the first forecast is run once there is a year or more of sales (imported under **Sales**).

### Put HTTPS in front

The stack does not do TLS. Point your reverse proxy at the web service's port and let it terminate HTTPS. A minimal Caddy example:

```
stock.example.com {
    reverse_proxy localhost:80
}
```

Keep `SESSION_SECURE_COOKIE=true` and `APP_URL=https://...`. With the proxy in front, set `TRUSTED_PROXIES` so the application knows the request was HTTPS and sees the visitor's real address (the sign-in throttle uses it). The application then also sends `Strict-Transport-Security`.

## What the start-up check does

Every application container (web app, queue worker, scheduler, migrations) runs `php artisan app:check --strict` when it starts and **exits** if anything must be fixed:

| Fails if | Why |
|---|---|
| `APP_ENV` is not `production`, or `APP_DEBUG` is on | Debug pages show code and settings to anyone who causes an error |
| `APP_KEY` is missing or not a usable key | It signs sessions and encrypts cookies, and the application cannot run without a key of the right length |
| `ML_INTERNAL_TOKEN` is the development value or shorter than 24 characters | It is the only thing protecting the forecasting endpoints. The ML service refuses to start with it too |
| The database password is a well-known one or shorter than 12 characters | |
| Inertia DevTools recording is on, or demo accounts are allowed (`ALLOW_DEMO_DATA`) | Both would expose data or create accounts with a known password |
| Session cookies are readable by scripts | |

It also **warns** (and starts anyway) when the address is not HTTPS, cookies are not marked secure, the content security policy is off, email goes to a log or the development catcher, jobs run in the request, or the cache is per process. Run it yourself any time:

```bash
docker compose -f compose.prod.yaml exec app php artisan app:check
```

## What is exposed, and what is not

Only the **web** service publishes a port. The database, Redis and the ML service are reachable only from inside the stack. Redis requires a password. Every container runs as an unprivileged user with `no-new-privileges`. The app container serves PHP only through the front controller (any other `.php` file is a 404), and nginx hides dotfiles, `vendor/` and `storage/` by simply not having them: the image contains only what runs.

## Updating

```bash
git pull
docker compose -f compose.prod.yaml up -d --build --wait
```

The migration container runs first, on every start, so a release that changes the database is applied before the new code serves a request. Roles and permissions are re-synced from code each time. Queue workers are given two minutes to finish running jobs when they are stopped. Expect a few seconds of errors while the app container is replaced; the web server finds the new container by itself (it does not remember the old address).

## Backups

Two things hold data: the **database** and the **import files** (in the `app_storage` volume). The saved forecasting models (`ml_models`) can be rebuilt by running a forecast.

```bash
# the database, to a file you keep somewhere else
docker compose -f compose.prod.yaml exec -T db pg_dump -U stocksense -Fc stocksense > stocksense-$(date +%F).dump

# restore into an empty database
docker compose -f compose.prod.yaml exec -T db pg_restore -U stocksense -d stocksense --clean --if-exists < stocksense-2026-10-03.dump
```

Run the dump on a schedule (cron on the host), copy it off the machine, and **try a restore** now and then: an untested backup is a hope.

## Running it

| Task | Command |
|---|---|
| See what is running | `docker compose -f compose.prod.yaml ps` |
| Application log | `docker compose -f compose.prod.yaml logs -f app` (errors, with no personal data) |
| Queue workers | `docker compose -f compose.prod.yaml logs -f horizon`; the Owner can open `/horizon` |
| Run a forecast now | `docker compose -f compose.prod.yaml exec app php artisan forecast:run week` |
| Recalculate advice now | `docker compose -f compose.prod.yaml exec app php artisan recommendations:generate --sync` |
| Send the digest now | `docker compose -f compose.prod.yaml exec app php artisan notifications:digest` |
| Stop everything, keeping the data | `docker compose -f compose.prod.yaml down` |

The scheduler container refreshes forecasts, recommendations and the digest on the schedule the Owner sets under **System settings** (defaults: weekly forecasts early on Monday, monthly ones on the 1st, advice every morning at 06:00, the digest at 07:00, Asia/Manila time).

## If it will not start

The container that failed says why in its first lines:

```bash
docker compose -f compose.prod.yaml logs app migrate
```

- `required variable ... is missing`: a REQUIRED value in `.env` is empty.
- `FAIL ...` lines from `app:check`: fix what it names (it also says how).
- The app starts but the site answers 502: the app container is not healthy yet, or has stopped; check its log.
- Emails do not arrive: check `MAIL_*`, then `docker compose -f compose.prod.yaml logs horizon` (emails are sent from the queue).

## Security checklist before real use

- [ ] HTTPS in front, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES` set
- [ ] `app:check` shows no failures and no warnings you do not understand
- [ ] All secrets are long, random and different; `.env` is not in git and is readable only by the people who deploy
- [ ] A real SMTP service; a test set-up email arrives and contains no personal details
- [ ] Backups run on a schedule, are copied off the machine, and a restore has been tried
- [ ] The first Owner has a strong password and has turned on two-factor authentication (**Settings → Security**)
- [ ] Only the web port is reachable from outside (check the host's firewall: Docker publishes ports around some firewalls)
- [ ] The host's operating system and Docker are kept up to date; so are these images (`git pull` and rebuild) when dependencies get security fixes (see [security.md](security.md))
