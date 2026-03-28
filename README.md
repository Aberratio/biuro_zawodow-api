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
- Local access to create a database and import SQL

### Setup steps

1. Create the local env file:

```powershell
Copy-Item .env.example .env
```

2. Create the database:

```powershell
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS biuro_zawodow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

3. Import schema and seed data:

```powershell
mysql -u root -p biuro_zawodow < database/init/001_init.sql
```

4. Create the default local API user:

```powershell
mysql -u root -p -e "CREATE USER IF NOT EXISTS 'biuro_user'@'localhost' IDENTIFIED BY 'biuro_pass';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON biuro_zawodow.* TO 'biuro_user'@'localhost'; FLUSH PRIVILEGES;"
```

5. Start the API:

```powershell
php -S localhost:8080 router.php
```

6. Verify it works:

```powershell
Invoke-RestMethod http://localhost:8080/health
```

If you already have old plaintext user passwords in the database, run:

```powershell
php database/migrations/002_hash_user_passwords.php
```

## Configuration

Default local config in `.env.example`:

```env
APP_URL=http://localhost:8080
APP_KEY=change-this-local-secret
APP_CORS_ORIGIN=http://localhost:8080,http://localhost:5173
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=biuro_zawodow
DB_USERNAME=biuro_user
DB_PASSWORD=biuro_pass
```

If your frontend runs on a different origin, update `APP_CORS_ORIGIN`.
Set a strong `APP_KEY` because it signs auth tokens.

## Authentication

- Public: `GET /docs`, `GET /openapi.json`, `GET /health`, `POST /auth/login`
- Protected: `GET /auth/me`, `GET /bootstrap`, `GET /participants`, `GET /participants/{id}`, `POST /participants`

Login example:

```powershell
Invoke-RestMethod http://localhost:8080/auth/login -Method Post -ContentType 'application/json' -Body '{"email":"super@biurozawodow.pl","password":"demo123"}'
```

Use the returned token as:

```text
Authorization: Bearer <access_token>
```

Default seeded demo password remains `demo123`, but it is now stored as a hash.

## Swagger Docs

- Swagger UI: `GET /docs`
- OpenAPI JSON: `GET /openapi.json`

## Available Endpoints

- `POST /auth/login`
- `GET /auth/me`
- `GET /docs`
- `GET /openapi.json`
- `GET /health`
- `GET /bootstrap`
- `GET /participants`
- `GET /participants/{id}`
- `POST /participants`
