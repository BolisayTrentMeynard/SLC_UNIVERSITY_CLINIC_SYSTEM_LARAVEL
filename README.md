# SLC University Clinic System (Laravel)

Nurse and doctor portals for the school clinic: student records, nurse triage (Assess), doctor exam queue, Screening / Checkup / Consultation logs, statistics with CSV export, user management, school years and printable prescription slips.

This is the Laravel version of the team project. The team's Node.js version is the hosted one: <https://github.com/PauloMarcky/SLC_UNIVERSITY_CLINIC_SYSTEM>. Both use the same frontend pages and the same database design, and answer the same `/api/...` URLs.

## Requirements

- PHP 8.2 or newer (`php -v`) and Composer
- MySQL or MariaDB (XAMPP works: start only MySQL)

## Setup (from zero)

```powershell
composer install
copy .env.example .env
php artisan key:generate
```

1. Open `.env` and check `DB_USERNAME` / `DB_PASSWORD` (XAMPP default: `root` and blank).
2. Create the database (phpMyAdmin, or in the terminal):
   `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE clinic_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`
3. Build the tables and the two login accounts, then start the server:

```powershell
php artisan migrate
php artisan db:seed
php artisan serve
```

Open <http://127.0.0.1:8000>.

| Username | Portal | Password | Lands on |
|---|---|---|---|
| `nurse1` | Nurse | `clinic-dev-2026` | Assess |
| `doctor1` | Doctor | `clinic-dev-2026` | Exam Queue |

These accounts are for local development only. Change the passwords (User Management, doctor) before any real use.

## How the pieces fit

- **Frontend:** plain HTML, CSS and JavaScript. Scripts, styles and images are in `public/`; the staff pages are in `resources/pages/` and are only sent to a logged-in nurse or doctor (`PageController`).
- **API:** `routes/web.php`, one controller per area in `app/Http/Controllers/`, the business rules in `app/Services/`. Every `/api/...` answer is JSON, and errors look like `{ "error": "..." }`.
- **Database:** `database/sql/*.sql` are the same 15 migration files as the Node version. One Laravel migration (`2026_10_19_000000_run_clinic_sql_migrations.php`) runs them in order, so both versions build the same schema. Add a new schema change as the next numbered `.sql` file.
- **Login:** the nurse or doctor logs in at `/`; the session lasts 8 hours, every change needs the `X-CSRF-Token` from `/api/session`, and login is limited to 30 attempts per 10 minutes.
- **Roles:** the nurse registers and edits students, triages visits, and can read statistics (confidential visits are never counted for the nurse). The doctor examines and completes visits, decides Confidential and Screening, cancels prescriptions and manages users and school years.

## Security

- `SecurityHeaders` middleware: strict Content-Security-Policy (no inline or third-party scripts), `no-store`, `nosniff`, frame and referrer policies, and HSTS when `SESSION_SECURE_COOKIE=true`.
- `OriginCheck` middleware: login and every change from another site are refused (set `CLINIC_ORIGIN` to lock to one address).
- Confidential visits (and their prescriptions) are hidden from the nurse everywhere, including search, statistics and the printable slip.

## Tests

The tests use their own database and **wipe it every run**, so it must not be the real one.

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS clinic_laravel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan test
```

`phpunit.xml` points the tests at `clinic_laravel_test`, and the test base class refuses to run against any database whose name does not end in `_test`. The tests cover login and roles, page access, students, the nurse-to-doctor exam flow (free-text diagnosis, Confidential, Screening copy), prescriptions, statistics, security headers and the origin check.

## What is not in this version

- Certificate and notification features: the clinic asked to remove them, so no screen uses them.
- Inventory and `/api/medicines`: removed from the clinic's workflow (medicines are free text).
- `POST /api/visits` (direct doctor entry) and `GET /api/patients/{id}/record`: no screen calls them.

## Useful commands

```powershell
php artisan route:list          # all URLs
php artisan config:clear        # after changing .env
php artisan migrate:fresh --seed   # rebuild the database from scratch (deletes all data)
```
