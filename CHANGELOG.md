# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [calendar versioning](https://calver.org/)
(`YYYY.MM.PATCH`). See [RELEASING.md](RELEASING.md) for how a version is cut
and deployed.

## [Unreleased]

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

[Unreleased]: https://github.com/Aberratio/biuro_zawodow-api/compare/v2026.09.0...HEAD
[2026.09.0]: https://github.com/Aberratio/biuro_zawodow-api/releases/tag/v2026.09.0
