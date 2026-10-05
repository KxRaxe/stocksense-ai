# Security

What protects StockSense AI, how each protection is checked, and what it does not cover. The shop's data is commercially sensitive (sales, costs, stock) and the system holds staff accounts, but **no customer personal data**: sales rows hold only a product, a date, a quantity and a price. That keeps it within the spirit of the Data Privacy Act (RA 10173) by design: there is little personal data to protect, and what exists (staff names and email addresses) is never put in an email.

## Who may do what

| Control | How | Checked by |
|---|---|---|
| **Sign-in only, no public sign-up** | The Owner creates accounts; each person gets a link to choose their own password. Nobody is ever emailed a password | `e2e/01-sign-in`, `e2e/04-onboarding` |
| **Three roles with a written matrix** | Owner, Manager, Inventory staff; the matrix is one enum, copied into the database on every deploy | `RolePermissionMatrixTest` (against the proposal's table), `RouteAccessTest`, `e2e/03-roles` |
| **Enforced on the server, not just hidden** | Every page and action asks for a permission in its route; reports ask per report | Role tests send each role to each page and expect 200 or 403. `e2e/03-roles` also calls the server directly |
| **No route is protected by accident** | One test lists every route and fails if one can be reached without a login unless it is on a short, explained allowlist; another fails if a page that shows shop data has no permission | `RouteProtectionTest` |
| **Passwords** | In production at least 12 characters with upper and lower case, a number and a symbol, and not found in known data breaches; stored hashed with bcrypt (12 rounds) | Starter-kit tests |
| **Two-factor authentication** | Optional, per person (Settings → Security) | Starter-kit tests |
| **Deactivated people are shut out at once** | Checked on every request, so an open session ends | `UserManagementTest`, `e2e/04-onboarding` |
| **The system always has an Owner** | The last active Owner cannot be deactivated or demoted | `UserManagementTest` |

## Against guessing and abuse

| Control | How | Checked by |
|---|---|---|
| **Sign-in throttle** | Five tries a minute for each email address and IP address, then a clear message | `AuthenticationTest`, `RateLimitTest`, `e2e/01-sign-in` |
| **Second throttle in nginx** | Sign-in submissions are limited per IP address whatever the account, which stops one address trying a few passwords on many accounts | Config; exercised by every E2E run |
| **Limits on actions that cost something** | Report downloads (10 a minute for each person), forecasts, recalculating advice, uploads and set-up emails (6 a minute) | `RateLimitTest` |
| **People are told, not shown an error page** | A throttled action says "please wait N seconds" in the page | `RateLimitTest` |

## Against attacks on what the browser runs

| Control | How | Checked by |
|---|---|---|
| **Cross-site request forgery** | Every change carries a token (Laravel and Inertia default) | Framework; every form test |
| **Content Security Policy** | Scripts only from the site itself or carrying a per-response random **nonce**; no `eval`; no plugins; forms only to the site; the site cannot be framed | `SecurityHeadersTest`; `e2e/02-security` loads 17 pages, the charts and the dialogs in a real browser and fails on any blocked resource |
| **Other headers** | `X-Frame-Options`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, and `Strict-Transport-Security` over HTTPS | `SecurityHeadersTest`, `e2e/02-security` |
| **Cookies** | Session cookie `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS | `e2e/02-security`, `app:check` |
| **Injection into spreadsheets** | Text that starts with `=`, `+`, `-` or `@` (a product named `=HYPERLINK(...)`) is stored as plain text and marked so a spreadsheet will not run it | `SpreadsheetInjectionTest`; deleting the control makes the tests fail |
| **Output is escaped** | React and Blade escape by default; the PDF is built from escaped templates with remote files and PHP in the PDF library switched off | `ReportPagesTest` |
| **Uploads** | Imports accept `.csv`, `.txt` and `.xlsx` files up to a size limit, are read by the application itself and are never served back by link | Import tests |

## What leaves the building

| Control | How | Checked by |
|---|---|---|
| **No personal data in email** | Generic greeting, product and stock figures, a link back. Password links carry only a token. File names are left out | `EmailPrivacyTest` renders every email **as actually sent** and searches it for a user's name and address; `e2e/04-onboarding` and `e2e/05-journey` check the real emails in a mail catcher |
| **Secrets are not shown** | The audit log never displays values whose names suggest passwords, tokens or keys | `AuditLogTest` |
| **Errors do not leak** | Debug output is off in production (a start-up check enforces it); errors go to the log. Failed forecasts show a plain message, never a trace or an address | `app:check`, forecasting tests |

## The deployment

| Control | How | Checked by |
|---|---|---|
| **Refuses to start misconfigured** | `app:check --strict` runs when each container starts, and the ML service validates its own token: debug on, development secrets, well-known passwords, unusable keys, DevTools, demo accounts | `ProductionCheckTest`; the E2E stack boots through it. The end-to-end run once caught a key the check accepted but Laravel could not use, which is why the check now asks Laravel's own encrypter |
| **Nothing exposed but the web port** | The database, Redis and the ML service are not published; Redis needs a password | `compose.prod.yaml`; the CI smoke job |
| **Unprivileged containers** | Every container runs as a non-root user with `no-new-privileges`; images contain only what runs (no Node, Composer or git in the PHP image) | `compose.prod.yaml` |
| **Only the front controller runs PHP**; dotfiles, `vendor/` and `storage/` are not reachable; server version hidden | nginx configuration | `e2e/02-security` requests `/.env`, `/.git/config`, `/vendor/autoload.php` and others and expects 403 or 404 |
| **Developer tooling is off** | Inertia DevTools (which would record each page's data to disk) is disabled and the start-up check fails if it is on; the local-disk file routes are switched off | `RouteProtectionTest`, `ProductionCheckTest` |
| **Demo accounts cannot exist in production** | The demo seeders refuse to run there (even when named directly) unless a throwaway test stack allows it, and the start-up check fails if that is left on | `DemoDataSeederTest`, `ProductionCheckTest` |
| **Reproducible builds** | PHP, JavaScript and Python dependencies are locked (`composer.lock`, `package-lock.json`, `uv.lock`) and the images install from the locks | CI |

## Dependencies

`composer audit`, `npm audit` and `pip-audit` (on the Python lockfile) are run in CI on every push and report **no known vulnerabilities** at the time of writing. They find *known* problems in dependencies, not unknown ones: keep the project updated and rebuild the images when advisories appear.

## Accountability

Everything that changes shop data or settings is written to an **audit log** that only the Owner can read and nothing can edit or delete: sign-ins; product, category and user changes; sales entry and imports; forecast runs; each decision on a recommendation (who, when, a note); every change to the system settings (old and new value); and every report export. See **Audit log** in the app.

## What this does not cover

Be honest about the limits:

- **TLS** is the job of the reverse proxy in front; the stack serves HTTP. Misconfigure that and nothing here helps.
- **The host.** If someone can run commands on the server they can read the database and `.env`. Keep it patched, restrict SSH, and protect backups.
- **Email in transit and at rest** is whatever the SMTP service provides. The emails are deliberately free of personal data so that matters less.
- **Phishing and weak passwords** cannot be fixed by software. Two-factor authentication is available and recommended for the Owner.
- **A penetration test** has not been done. The tests above check each control; they are not a substitute for an independent attempt to break in.
- **Denial of service** is only mitigated by the rate limits. A determined attacker needs protection upstream (a CDN or the host's network).
- **Reporting a problem:** open an issue on the repository, or contact the maintainer directly for anything sensitive, rather than posting details publicly.
