# biuro_zawodow-api

Small PHP API for the `biuro_zawodow` project. It serves local frontend development, reads data from MySQL, exposes bootstrap data for the app, and handles participant endpoints.

## Table of Contents

- [Quick Commands](#quick-commands)
- [First Setup](#first-setup)
- [Configuration](#configuration)
- [Authentication](#authentication)
- [Swagger Docs](#swagger-docs)
- [Available Endpoints](#available-endpoints)

## Quick Commands

If your local backend and database are already set up, run only this:

```powershell
cd biuro_zawodow-api
php -S localhost:8080 router.php
```

Check that the API is up:

```powershell
Invoke-RestMethod http://localhost:8080/health
```

Open Swagger docs:

```text
http://localhost:8080/docs
```

Frontend base URL:

```text
http://localhost:8080
```

## First Setup

### What you need

- PHP 8.2+
- PHP extensions: `pdo`, `pdo_mysql`
- MySQL 8+
- Composer
- Local access to create a database and import SQL

### Setup steps

1. Create the local env file:

```powershell
Copy-Item .env.example .env
```

2. Install PHP dependencies:

```powershell
composer install
```

3. Create the database:

```powershell
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS biuro_zawodow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

4. Import schema and seed data:

```powershell
mysql -u root -p biuro_zawodow < database/init/001_init.sql
```

5. Create the default local API user:

```powershell
mysql -u root -p -e "CREATE USER IF NOT EXISTS 'biuro_user'@'localhost' IDENTIFIED BY 'biuro_pass';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON biuro_zawodow.* TO 'biuro_user'@'localhost'; FLUSH PRIVILEGES;"
```

6. Start the API:

```powershell
php -S localhost:8080 router.php
```

7. Verify it works:

```powershell
Invoke-RestMethod http://localhost:8080/health
```

If you already have old plaintext user passwords in the database, run:

```powershell
php database/migrations/002_hash_user_passwords.php
```

If you already have the database but not the password reset table, run:

```powershell
php database/migrations/008_create_password_resets.php
```

## Configuration

Default local config in `.env.example`:

```env
APP_URL=http://localhost:8080
APP_FRONTEND_URL=http://localhost:5173
APP_KEY=change-this-local-secret
APP_CORS_ORIGIN=http://localhost:8080,http://localhost:5173
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=biuro_zawodow
DB_USERNAME=biuro_user
DB_PASSWORD=biuro_pass
MAIL_DRIVER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@biurozawodow.local
MAIL_FROM_NAME=Biuro Zawodow
```

If your frontend runs on a different origin, update `APP_CORS_ORIGIN`.
Set a strong `APP_KEY` because it signs auth tokens.
`APP_FRONTEND_URL` is used in password reset emails.

For local SMTP testing you can use Mailpit or MailHog, for example:

```powershell
docker run --rm -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Then open:

```text
http://localhost:8025
```

## Authentication

- Public: `GET /docs`, `GET /openapi.json`, `GET /health`, `POST /auth/login`, `POST /auth/forgot-password`, `POST /auth/reset-password`
- Protected: `GET /auth/me`, `POST /auth/change-password`, `GET /bootstrap`, `GET /participants`, `GET /participants/{id}`, `POST /participants`

Login example:

```powershell
Invoke-RestMethod http://localhost:8080/auth/login -Method Post -ContentType 'application/json' -Body '{"email":"super@biurozawodow.pl","password":"demo123"}'
```

Use the returned token as:

```text
Authorization: Bearer <access_token>
```

Default seeded demo password remains `demo123`, but it is now stored as a hash.

Password reset flow:

- `POST /auth/forgot-password` always returns the same neutral message
- the API stores only a hash of the reset token
- reset links are valid for 60 minutes
- if SMTP is not configured and `APP_DEBUG=true`, the endpoint returns a configuration error
- newly created users also receive a setup email and choose their own password from the link instead of getting a password from an admin

## Swagger Docs

- Swagger UI: `GET /docs`
- OpenAPI JSON: `GET /openapi.json`

## Available Endpoints

- `POST /auth/login`
- `POST /auth/forgot-password`
- `POST /auth/reset-password`
- `GET /auth/me`
- `POST /auth/change-password`
- `GET /docs`
- `GET /openapi.json`
- `GET /health`
- `GET /bootstrap`
- `GET /participants`
- `GET /participants/{id}`
- `POST /participants`
