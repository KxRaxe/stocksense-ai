# End-to-end tests

Playwright tests that drive a real browser through the application, the way a person would, against the **production-like stack** (`compose.prod.yaml`): built assets, OPcache, the strict start-up check, the content security policy, Redis with a password, nginx in front. What is tested is what would be deployed.

| Spec | What it covers |
|---|---|
| `01-sign-in` | Pages ask for a login; no public sign-up; a wrong password is refused without saying which part was wrong; sign in and out (signing out leads to the front page); five wrong tries are throttled with a message |
| `02-security` | Security headers and the content security policy; nothing private is reachable (`/.env`, `/.git`, `vendor/`, DevTools); session cookie flags; assets are cached and not sniffed; **17 pages and the charts, dialogs and menus, and the public front page with its pictures, load with nothing blocked by the policy** |
| `03-roles` | For each of Owner, Manager and Inventory staff: what the menu shows, what the server opens (200), and what it refuses (403), checked against the access matrix; staff cannot start a forecast even by calling the server directly |
| `04-onboarding` | The Owner adds a person; they are emailed a set-up **link, not a password**; the email contains nothing personal; the link carries only a token; they choose a password, sign in with the right role; the link works once; deactivating them shuts them out at once |
| `05-journey` | The whole story as the Owner: import a sales file with a preview, import a file with problems and download its error report, run a forecast, see its accuracy, get recommendations with reasoning, accept one (it counts as on order), record the goods arriving (on order clears), export a report as Excel and PDF, filter it, change a system setting and restore it, find all of it in the audit log, and see the alerts in the app and by email with nothing personal in them |
| `06-accessibility` | axe (WCAG 2.1 A and AA) on eleven pages including the front page, **in light and in dark mode**; the theme switch flips the whole app and is remembered across page loads; keyboard focus and dialogs |

## Running them

You need Docker and Node 22 or newer. From `e2e/`:

```bash
npm install
npm run browsers      # downloads Chromium for Playwright, once

npm run stack:up      # builds and starts the production-like stack (the first build takes several minutes)
npm run stack:seed    # creates the demo accounts and a year and a half of demo sales
npm test              # about 1.5 minutes, most of it the forecast
npm run report        # opens the HTML report of the last run (screenshots and traces of failures)

npm run stack:down    # removes the stack and its data
```

The stack runs under its own project name (`stocksense-e2e`) on port **8090**, with a mail catcher at **8027**, so it can run next to the development stack, but both together need a few GB of memory: stop the development stack (`docker compose stop`) if the machine is tight.

Point the tests somewhere else with `E2E_BASE_URL` and `E2E_MAILPIT_URL`.

## Notes

- `stack.env` holds test values for a stack that is created and destroyed on one machine. They protect nothing and must never be used anywhere real. The stack still passes the production start-up check (`php artisan app:check --strict`): the three warnings it shows are about HTTPS and real email, which a local test stack does not have.
- The demo accounts (`owner@`, `manager@`, `staff@stocksense.test`, password `password`) exist only because the seed command is run with `ALLOW_DEMO_DATA=true`. The production stack refuses to create them, and the start-up check fails if that flag is left on.
- The tests run in order on one worker, because they share a database and some depend on what earlier ones did (the journey needs the forecast it makes). Each role signs in once, in `global-setup.ts`; signing in is rate limited and has its own tests.
- Every test cleans up what would affect another: settings are put back, the notification choice is restored, and the user the onboarding spec creates is deactivated.

## The student testing manual

`manual/` is not a test suite: it builds [`docs/manual`](../docs/manual) from the running **development** stack. It signs in as the demo Owner, takes the screenshots the manual shows, then prints `testing-manual.html` to `StockSense-AI-Testing-Manual.pdf`.

```bash
docker compose up -d            # in the repository root, with the demo shop seeded and a weekly forecast made
npm run manual                  # about two minutes
```

Edit the manual in `docs/manual/testing-manual.html`, then run `npm run manual` again to refresh the screenshots and the PDF.
