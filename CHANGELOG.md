# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [calendar versioning](https://calver.org/)
(`YYYY.MM.PATCH`). See [RELEASING.md](RELEASING.md) for how a version is cut
and deployed.

## [Unreleased]

Upgrade notes:

- Runs migration `031` (application settings). Deploy with `migration_mode`
  set to `run`.

### Added

- A superadmin switch for who may set up and run the platform integration:
  `off`, `superadmin` (the default) or `on`, read and changed through
  `GET`/`PUT /superadmin/platform-integration`. Every change is written to the
  audit log. Connections that already exist keep syncing in every mode.

### Changed

- Saving, testing, importing and deleting a platform connection
  answer `403` with code `platform_integration_not_enabled` unless the switch
  allows the caller. `GET /events/{id}/platform-connection` now returns an
  `access` object (`mode`, `can_use`) so the client can show a notice instead.
  Until it is set to `on`, only superadmins can use the integration.

## [2026.10.0] - 2026-10-20

Adds the integration with the ZmierzymyCzas platform. It stays inactive until
the `BZ_PLATFORM_*` variables are set, so this release can ship before the
platform goes live.

Upgrade notes:

- Runs migrations `027` to `030` (identity codes, platform connections,
  participant sync, webhook receipts and push queue). Deploy with
  `migration_mode` set to `run`.
- To turn the integration on later, set `BZ_PLATFORM_API_URL`,
  `BZ_PLATFORM_APP_KEY` and `BZ_PLATFORM_TOKEN_KEY` (see `.example.env`),
  keep a copy of the token key outside the server, and add the
  `pull-platform-participants.php` and `push-platform-changes.php` cron
  jobs.
- The screens for these features ship in the next biuro_zawodow-client
  release. The API stays compatible with the client in production, so it
  goes first.

### Added

- Per-event connection to the ZmierzymyCzas platform, stored encrypted.
  Event managers can save, validate and test it through dedicated endpoints
  that never return the secret; token key rotation is documented.
- Participant import from the platform, on demand for event managers or
  scheduled from the command line, with retries, locking and a trace of each
  pull. Platform participant lists map onto participants and field mappings.
- Webhooks for critical changes on the platform, recorded as receipts.
- Check-ins and corrections are queued and pushed back to the platform from
  the command line. Withdrawals made on the platform and the push status are
  shown to the race office.
- Participant identity codes, resolved when a code is scanned.
- `/health` reports the deployed `version` and `commit`.

### Changed

- The platform connection reports its last update time in UTC.

### Fixed

- CORS preflight allows `PUT`, so the platform connection can be saved from
  the browser.

### Security

- Scan replies hide the identity code from callers who may not see it.
- Webhooks without a token are rejected the same way as any other
  unauthorised request.

## [2026.09.0] - 2026-09-20

First tagged release. It marks the code running in production on 2026-09-20;
earlier production deploys were not versioned.

### Added

- JWT authentication with a password reset flow.
- Role-based access control: `superadmin`, `admin`, `editor`, `scanner`,
  `scanner_plus`.
- Management of organizations, events, users and participants.
- Participant CSV import with column mapping.
- QR codes for participants: preview, single send and bulk send by e-mail.
- CSV export of event data and activity logs.
- Structured audit logs and redacted JSONL server logs for superadmins.
- Optional Sentry error reporting.
- Swagger UI (`/docs`) and OpenAPI JSON (`/openapi.json`) outside production.
- API hardening: strict CORS, JSON body validation and size limits, rate
  limiting on login and password reset, server-side filtering of sensitive
  data.

[Unreleased]: https://github.com/Aberratio/biuro_zawodow-api/compare/v2026.10.0...HEAD
[2026.10.0]: https://github.com/Aberratio/biuro_zawodow-api/compare/v2026.09.0...v2026.10.0
[2026.09.0]: https://github.com/Aberratio/biuro_zawodow-api/releases/tag/v2026.09.0
