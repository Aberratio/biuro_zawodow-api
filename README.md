# biuro_zawodow-api

PHP 8.2 API for the `biuro_zawodow` project. It serves the React frontend, exposes bootstrap data for the app, handles authentication, manages organizations, events, users and participants, and provides CSV import/export plus QR workflows.

## What is inside

- JWT based auth with password reset flow
- Role aware access control for `superadmin`, `admin`, `editor`, `scanner`, `scanner_plus`
- Organization, event, user and participant CRUD
- Participant CSV import with column mapping
- QR preview, single send and bulk send for event participants
- CSV export for event data and event activity logs
- Structured business audit logs and redacted JSONL server logs for superadmin
- Optional Sentry error reporting for PHP exceptions
- Swagger UI at `/docs` and OpenAPI JSON at `/openapi.json` on non-production environments
- Basic API hardening:
  - strict CORS configuration
  - JSON body validation and size limits
  - rate limiting for login and password reset endpoints
  - server side filtering of bootstrap and participant data

## Requirements

- PHP 8.2+
- PHP extensions: `pdo`, `pdo_mysql`, `zlib`
- MySQL 8+
- Composer

## Quick start

```powershell
cd biuro_zawodow-api
Copy-Item .env.example .env
composer install
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p -e "CREATE DATABASE IF NOT EXISTS biuro_zawodow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p biuro_zawodow < database/init/001_init.sql
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p -e "CREATE USER IF NOT EXISTS 'biuro_user'@'localhost' IDENTIFIED BY 'biuro_pass';"
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p -e "GRANT ALL PRIVILEGES ON biuro_zawodow.* TO 'biuro_user'@'localhost'; FLUSH PRIVILEGES;"
php -S localhost:8080 router.php
```

Health check:

```powershell
Invoke-RestMethod http://localhost:8080/health
```

Swagger UI:

```text
http://localhost:8080/docs
```

OpenAPI JSON:

```text
http://localhost:8080/openapi.json
```

## Local configuration

Default local config from `.env.example`:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_FRONTEND_URL=http://localhost:5173
APP_KEY=change-this-local-secret
APP_CORS_ORIGIN=http://localhost:8080,http://localhost:5173
APP_RELEASE=local

LOG_DIR=
LOG_LEVEL=info
LOG_RETENTION_DAYS=30
AUDIT_RETENTION_DAYS=730
SLOW_REQUEST_MS=2000

SENTRY_DSN=
SENTRY_ENVIRONMENT=local
SENTRY_TRACES_SAMPLE_RATE=0

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=biuro_zawodow
DB_USERNAME=biuro_user
DB_PASSWORD=biuro_pass

CENTRAL_MAILER_URL=http://localhost:8080
CENTRAL_MAILER_API_KEY=
CENTRAL_MAILER_TIMEOUT_SECONDS=10
CENTRAL_MAILER_BATCH_SIZE=500
```

Notes:

- `APP_URL` must match the backend origin.
- `APP_FRONTEND_URL` is used in password reset emails.
- `APP_KEY` signs auth tokens and must be strong outside local/dev/test.
- `APP_CORS_ORIGIN` must contain the frontend origin. Wildcard CORS is rejected outside local/dev/test.
- `LOG_DIR` defaults to `storage/logs`, outside the public webroot. Production should use a private absolute path.
- `SENTRY_DSN` is optional. Empty or unavailable Sentry never blocks API requests.
- `CENTRAL_MAILER_URL` points to the central-mailer REST API. `CENTRAL_MAILER_API_KEY` must match an active central-mailer client key for this application.
- `CENTRAL_MAILER_BATCH_SIZE` controls QR email batch chunking and should not exceed central-mailer's `EMAIL_BATCH_MAX_RECIPIENTS`.

## Seeded local accounts

The seed data from `database/init/001_init.sql` creates demo users. The default password is:

```text
demo123
```

Main seeded accounts:

- `super@biurozawodow.pl` - superadmin
- `admin@sportevents.pl` - admin with global organization access
- `admin@runpoland.pl` - admin with global organization access
- `org.gniezno@sportevents.pl` - editor
- `skaner1@sportevents.pl` - scanner

## Database setup notes

For a fresh local setup, importing `database/init/001_init.sql` is enough. If that database will later be managed by the migration runner, mark the current migration set as already applied:

```powershell
php scripts/run-migrations.php --baseline
```

If you are upgrading an older local database, run the migration runner instead of relying on API requests to patch the schema:

```powershell
php scripts/run-migrations.php --dry-run
php scripts/run-migrations.php
```

The directory currently contains:

- `002_hash_user_passwords.php`
- `003_add_admin_id_to_users.php`
- `004_add_event_limit_to_organizations.php`
- `005_create_admin_organization_assignments.php`
- `006_align_user_organization_relations.php`
- `007_add_event_participant_imports.php`
- `008_create_password_resets.php`
- `009_backfill_participant_qr_codes.php`
- `010_unify_participant_status.php`
- `011_add_event_archiving.php`
- `012_add_scanner_plus_role.php`
- `013_allow_multiple_admins_per_organization.php`
- `014_drop_admin_organization_assignments.php`
- `015_allow_duplicate_participant_bib_numbers.php`
- `016_add_important_participant_field_role.php`
- `017_add_event_participant_change_audit.php`
- `018_add_request_rate_limits_and_activity_event.php`
- `019_add_user_archiving_and_event_soft_delete.php`
- `020_add_event_sync_tables.php`
- `021_add_audit_logs.php`

## Auth and access model

Public endpoints:

- `GET /docs` (non-production only)
- `GET /openapi.json` (non-production only)
- `GET /health`
- `GET /qr-images/{token}.svg`
- `GET /qr-images/{token}.png`
- `POST /auth/login`
- `POST /auth/forgot-password`
- `POST /auth/reset-password`

Protected endpoints require:

```text
Authorization: Bearer <access_token>
```

Role overview:

- `superadmin` can access all organizations, events, users and participants
- `admin` can access all organizations, events, users and participants, except superadmin-only operations
- `editor` can access only their own organization
- `scanner` can access only assigned events while the race office is open
- `scanner_plus` can access assigned open events like `scanner`, plus update participant data and reassign packages

## Main endpoint groups

The full and current contract lives in Swagger. The most important route groups are:

- auth:
  - `POST /auth/login`
  - `POST /auth/forgot-password`
  - `POST /auth/reset-password`
  - `GET /auth/me`
  - `POST /auth/change-password`
- bootstrap and system:
  - `GET /bootstrap`
  - `GET /health`
- organizations:
  - `POST /organizations`
  - `GET /organizations/{id}`
  - `PATCH /organizations/{id}`
  - `DELETE /organizations/{id}`
  - `POST /organizations/{id}/event-limit`
- events:
  - `POST /events`
  - `GET /events/{id}`
  - `PATCH /events/{id}`
  - `DELETE /events/{id}`
  - `GET /events/{id}/export.csv`
  - `GET /events/{id}/logs/export.csv`
  - `GET /events/{id}/participant-changes/export.csv`
  - `POST /events/{id}/participant-imports/analyze`
  - `POST /events/{id}/participant-imports/confirm`
  - `POST /events/{id}/participant-imports/run`
  - `GET /events/{id}/participant-field-mappings`
  - `PATCH /events/{id}/participant-field-mappings`
  - `POST /events/{id}/participants/manual`
  - `POST /events/{id}/send-qr-emails`
- users:
  - `POST /users`
  - `PATCH /users/{id}/role`
  - `PATCH /users/{id}/event-assignments`
  - `DELETE /users/{id}`
- participants:
  - `GET /participants`
  - `POST /participants`
  - `GET /participants/{id}`
  - `PATCH /participants/{id}`
  - `DELETE /participants/{id}`
  - `GET /participants/{id}/qr-preview`
  - `POST /participants/{id}/send-qr-email`
  - `POST /participants/{id}/check-in`
  - `POST /participants/{id}/undo-check-in`
  - `POST /participants/scan`
- superadmin observability:
  - `GET /superadmin/audit`
  - `GET /superadmin/audit/{id}`
  - `GET /superadmin/audit/export.csv`
  - `GET /superadmin/server-logs`
  - `GET /superadmin/server-logs/{source}/{date}/{id}`
  - `GET /superadmin/server-logs/export.csv`
  - `GET /superadmin/logging-status`

## API behavior worth knowing

- `/bootstrap` is filtered on the server side, not only in the frontend.
- Login and password reset endpoints are rate limited.
- JSON endpoints reject malformed bodies and oversize payloads.
- CSV analyze/import endpoints accept larger payloads than standard JSON routes.
- Event and participant exports are generated as downloadable CSV files.
- Activity logs include user, participant and exact timestamp information where applicable.
- Every API response includes `X-Request-ID`, which correlates audit entries, JSONL server logs and Sentry events.
- Run `php scripts/maintain-logs.php` daily to compress logs older than two days and enforce retention.

## Development workflow

Start the backend:

```powershell
php -S localhost:8080 router.php
```

The router is defined in `router.php` and forwards dynamic requests to `public/index.php`.

If you deploy the React build into `public/` behind Apache/XAMPP, keep `public/.htaccess` in place. It preserves PHP handling for API endpoints and rewrites frontend routes like `/events/:id` or `/organizations/:id` to `index.html`, which prevents `404` after a browser refresh.

Useful checks:

```powershell
php -l src/bootstrap.php
php -l public/index.php
```

## Swagger

- UI: `GET /docs`
- JSON: `GET /openapi.json`

Swagger endpoints are enabled only when `APP_ENV` is not `production`.

Use Swagger as the source of truth for request and response payloads.

### Init DB on Windows

```bash
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" --default-character-set=utf8mb4 -u root -p biuro_zawodow < database/init/001_init.sql
```
