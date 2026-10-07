# biuro_zawodow-api

PHP 8.2 API for the `biuro_zawodow` project — a race-office / event check-in platform. It serves the React frontend, handles authentication, manages organizations, events, users and participants, and provides CSV import/export plus QR check-in workflows.

## Features

- JWT auth with password reset flow
- Role-based access control: `superadmin`, `admin`, `editor`, `scanner`, `scanner_plus`
- Organization, event, user and participant CRUD
- Participant CSV import with column mapping
- QR preview, single send and bulk send for event participants
- CSV export for event data and activity logs
- Structured audit logs and redacted JSONL server logs for superadmin
- Optional Sentry error reporting
- Swagger UI (`/docs`) and OpenAPI JSON (`/openapi.json`) on non-production environments
- API hardening: strict CORS, JSON body validation and size limits, rate limiting on login/password-reset, server-side filtering of sensitive data

## Requirements

- PHP 8.2+ with `pdo`, `pdo_mysql`, `zlib`
- MySQL 8+
- Composer

## Quick start

```powershell
cd biuro_zawodow-api
Copy-Item .example.env .env
composer install

$mysql = "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
& $mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS biuro_zawodow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
& $mysql -u root -p biuro_zawodow < database/init/001_init.sql
& $mysql -u root -p -e "CREATE USER IF NOT EXISTS 'biuro_user'@'localhost' IDENTIFIED BY 'biuro_pass'; GRANT ALL PRIVILEGES ON biuro_zawodow.* TO 'biuro_user'@'localhost'; FLUSH PRIVILEGES;"

php -S localhost:8080 router.php
```

Health check: `http://localhost:8080/health`
Swagger UI: `http://localhost:8080/docs`
OpenAPI JSON: `http://localhost:8080/openapi.json`

## Configuration

Key variables from `.example.env`:

| Variable | Purpose |
|---|---|
| `APP_URL` / `APP_FRONTEND_URL` | Backend origin / frontend origin (used in password reset emails) |
| `APP_KEY` | Signs auth tokens; must be strong outside local/dev/test |
| `APP_CORS_ORIGIN` | Allowed frontend origin(s); wildcard rejected outside local/dev/test |
| `LOG_DIR` | Defaults to `storage/logs`; use a private absolute path in production |
| `LOG_LEVEL`, `LOG_RETENTION_DAYS`, `AUDIT_RETENTION_DAYS`, `SLOW_REQUEST_MS` | Logging and audit tuning |
| `SENTRY_DSN` | Optional; empty/unavailable Sentry never blocks requests |
| `DB_*` | Database connection |
| `BZ_PLATFORM_API_URL`, `BZ_PLATFORM_APP_KEY`, `BZ_PLATFORM_TOKEN_KEY` (+ optional `BZ_PLATFORM_TOKEN_KEY_PREVIOUS`, `BZ_PLATFORM_TIMEOUT_SECONDS`) | Per-event platform connection. All three required values must be valid or the feature stays inactive (the API still boots). Key rotation: `php scripts/reencrypt-platform-tokens.php [--apply]` |
| `BZ_PLATFORM_PULL_TIMEOUT_SECONDS` | Optional timeout of a full participant list download from the platform, 5-120 seconds (default 60). The pull runs from the event details button and from `php scripts/pull-platform-participants.php` (`--event=ID`, `--force`, `--help`); cron entry, e.g. every 5 minutes: `*/5 * * * * php /path/to/api/scripts/pull-platform-participants.php >> /path/to/logs/platform-pull.log 2>&1` (the script only pulls connections that are due: 6 h, 1 h in the last week, 5 min on the event day) |
| `CENTRAL_MAILER_URL`, `CENTRAL_MAILER_API_KEY`, `CENTRAL_MAILER_BATCH_SIZE` | Central-mailer integration for QR emails; batch size should not exceed the mailer's `EMAIL_BATCH_MAX_RECIPIENTS` |

## Seeded local accounts

Seed data (`database/init/001_init.sql`) creates demo users, all with password `demo123`:

- `super@biurozawodow.pl` — superadmin
- `admin@sportevents.pl`, `admin@runpoland.pl` — admin (global organization access)
- `org.gniezno@sportevents.pl` — editor
- `skaner1@sportevents.pl` — scanner

## Database migrations

A fresh setup only needs `database/init/001_init.sql`. For an existing database:

```powershell
php scripts/run-migrations.php --baseline        # mark current schema as already migrated
php scripts/run-migrations.php --dry-run         # preview pending migrations
php scripts/run-migrations.php                   # apply pending migrations
php scripts/run-migrations.php --adopt-existing  # adopt migrations verifiable from current schema
```

Migrations `002`–`022` live in the migrations directory and cover password hashing, role changes, archiving, audit logs and related schema evolution.

## Auth and access model

Public endpoints: `GET /health`, `GET /docs` and `GET /openapi.json` (non-production only), `GET /qr-images/{token}.svg|.png`, `POST /auth/login`, `POST /auth/forgot-password`, `POST /auth/reset-password`.

All other endpoints require `Authorization: Bearer <access_token>`.

Roles:

- `superadmin` — full access to all organizations, events, users and participants
- `admin` — same as superadmin except superadmin-only operations
- `editor` — own organization only
- `scanner` — assigned events only, while the race office is open
- `scanner_plus` — like `scanner`, plus participant updates and package reassignment

## Main endpoint groups

Swagger is the source of truth for the full contract. Route groups:

- **auth** — login, forgot/reset password, `me`, change password
- **bootstrap & system** — `GET /bootstrap`, `GET /health`
- **organizations** — CRUD, event-limit
- **events** — CRUD, CSV exports (event, logs, participant changes), participant import (analyze/confirm/run), field mappings, manual participant add, bulk QR email send
- **users** — create, role update, event assignments, delete
- **participants** — CRUD, QR preview/send, check-in / undo check-in, scan
- **superadmin observability** — audit log (list/get/export), server logs (list/get/export), database info, logging status

## API behavior worth knowing

- `/bootstrap` and participant data are filtered server-side, not just in the frontend.
- Login and password reset are rate limited; JSON endpoints reject malformed/oversize bodies (CSV import endpoints allow larger payloads).
- Every response includes `X-Request-ID`, correlating audit entries, JSONL logs and Sentry events.
- Run `php scripts/maintain-logs.php` daily to compress logs older than two days and enforce retention.

## Testing

```powershell
composer test
```

Runs `scripts/run-tests.php`. Syntax checks: `php -l src/bootstrap.php` / `php -l public/index.php`.

## Deployment

Deploy the React build into `public/` behind Apache/XAMPP and keep `public/.htaccess` in place — it preserves PHP routing for API endpoints and rewrites frontend routes (e.g. `/events/:id`) to `index.html` to avoid `404` on refresh. Swagger (`/docs`, `/openapi.json`) is disabled automatically when `APP_ENV=production`.
