# Velodata Dashboard Laravel Backend

This repository contains the Laravel backend API for the Velodata Dashboard React application.

It replaces the older `material-dashboard-laravel-api` / `laravel-json-api-pro` repository history. The current project source of truth is the production-derived Laravel code that was recovered and verified locally on `2026-05-02`.

## Purpose

This backend supports:

- Manual and Google-assisted dashboard login
- Email 2FA for manual login
- User profile lookup and permission lookup
- User heartbeat and online-user tracking
- User management actions including update, ban, unban, delete, login history, and audit history
- CRUD endpoints for users, roles, permissions, items, categories, and tags
- Teaching/demo REST endpoints used by the dashboard project
- Server-sent events for profile/session state updates

The matching React frontend uses `REACT_APP_API_URL`, commonly:

```env
REACT_APP_API_URL=http://laravel.localhost/api/v2
```

## Important History

This repo was created as a clean backup target because the older GitHub repository had unrelated history from an earlier version of the project.

Do not merge the old repository history into this repo unless there is a very specific reason.

The branch that was verified as the good Laravel backend was:

```text
initial-import-last-two-years
```

It was pushed to this repository as:

```text
main
```

## Key Endpoints

Primary custom dashboard endpoints live under:

```text
/api/v2
```

`routes/api.php` is loaded by `App\Providers\RouteServiceProvider`, which is registered in `bootstrap/providers.php`. Preserve that wiring when refactoring or upgrading Laravel, because the React dashboard depends on these `/api/v2` routes.

Important routes include:

```text
POST /api/v2/VMD-login-user
POST /api/v2/VMD-verify-2fa
POST /api/v2/VMD-resend-2fa
POST /api/v2/VMD-get-user-data
POST /api/v2/VMD-get-user-permissions
POST /api/v2/VMD-user-heartbeat
POST /api/v2/VMD-get-online-users
POST /api/v2/VMD-get-login-history
POST /api/v2/VMD-get-audit-history
POST /api/v2/VMD-updateUser
POST /api/v2/VMD-ban-user
POST /api/v2/VMD-unbanUser
POST /api/v2/VMD-delete-user
```

Resource routes include:

```text
/api/v2/users
/api/v2/roles
/api/v2/permissions
/api/v2/items
/api/v2/categories
/api/v2/tags
```

SSE endpoint:

```text
GET /sse-profile-updates
```

## Local Development

Expected local Apache host:

```text
http://laravel.localhost
```

Expected XAMPP document root:

```text
C:\xampp\htdocs\laravel-json-api-pro\public
```

The `.htaccess` file in `public/` is required. Without it, Apache may answer `/api/...` requests itself instead of routing them through Laravel, which can look like a CORS problem.

Install dependencies:

```bash
composer install
```

If Composer fails on Windows/XAMPP because zip support is missing, enable this in `C:\xampp\php\php.ini`:

```ini
extension=zip
```

Then rerun:

```bash
composer install --prefer-dist
```

## Environment

The real `.env` file is not committed.

Important local values include:

```env
APP_URL=https://mx.velodata.org
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=laravel-api
DB_USERNAME=root
MAIL_MAILER=mailgun
MAIL_FROM_ADDRESS=no-reply@mg.velodata.org
MAIL_FROM_NAME="Velodata Security"
SSE_ALLOWED_ORIGINS=http://localhost:3000
```

Check `.env.example` before setting up a new machine. It may need to be updated if it drifts from the real development environment.

## Manual Login Verification

The backend was verified locally with:

```text
POST http://laravel.localhost/api/v2/VMD-login-user
```

Payload shape:

```json
{
  "email": "admin@jsonapi.com",
  "password": "secret",
  "google_id": null,
  "name": null,
  "picture": null,
  "username": null,
  "token": null,
  "method": "VMD-login-user-manually"
}
```

Expected successful manual-login gateway response:

```json
{
  "outcome": "2FA_REQUIRED",
  "user_id": 1,
  "email": "admin@jsonapi.com"
}
```

A working CORS preflight should return `204` with `Access-Control-Allow-Origin`.

## Things You Should Know

- This repository is intended to be the clean backup/source-of-truth repo going forward.
- Avoid merging unrelated history from the older GitHub repo.
- The dashboard currently uses custom `VMD-*` endpoints, not only stock Laravel JSON:API auth routes.
- `routes/api.php` is important even though this is a Laravel 11 app; it is loaded by the custom `RouteServiceProvider`.
- Manual login intentionally returns `2FA_REQUIRED` before completing the login.
- Localhost and loopback IPs are treated as Australian/Gold Coast login locations in `CustomController`.
- `vendor/` is ignored and should be rebuilt with Composer.
- `.env` is ignored and must stay out of GitHub.
- The local zip archive `laravel-json-api-pro-initial-import-last-two-years-90484c2.zip` should not be committed.
- If `/api/v2/VMD-login-user` returns an Apache-looking 404 or a browser CORS error, check branch, `public/.htaccess`, and Composer autoload before changing React.

## Useful Commands

```bash
php artisan route:list --path=VMD-login-user
php artisan route:list --path=VMD
php artisan --version
composer install --prefer-dist
```

## Related Project

React frontend repository:

```text
reactjs-dashboard
```

Frontend local URL:

```text
http://localhost:3000
```
