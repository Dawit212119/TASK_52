# VetOps Backend (Laravel API)

Laravel backend for the VetOps Unified Operations Portal.

## Scope

- Versioned API base: `/api/v1`
- Authentication: local username/password + RBAC + inactivity timeout
- Persistence: MySQL (`DB_CONNECTION=mysql`)
- Core modules: master data, rentals, inventory, content workflow, reviews, analytics, imports/dedup, audit

## Key entry points

- API routes: `routes/api.php`
- Console jobs/schedules: `routes/console.php`
- Middleware bootstrap: `bootstrap/app.php`
- Domain schema: `database/migrations/2026_03_30_001000_create_vetops_domain_tables.php`

## Local environment

The backend is expected to run through Docker Compose from `repo/`.

Important env defaults are defined in:

- `backend/.env.example`
- `repo/.env.example`

Security-sensitive env keys:

- `VETOPS_AUTH_INACTIVITY_TIMEOUT_MINUTES`
- `VETOPS_AUTH_LOGIN_MAX_ATTEMPTS`
- `VETOPS_AUTH_LOGIN_DECAY_SECONDS`
- `VETOPS_AUTH_CAPTCHA_AFTER_FAILURES`
- `VETOPS_AUTH_CAPTCHA_TTL_MINUTES`
- `VETOPS_AUDIT_RETENTION_YEARS`

CAPTCHA note: the login flow uses challenge-based CAPTCHA (server-generated math
challenges bound to workstation + username). There is no shared static bypass
token. A testing-only bypass is handled internally in code (`CaptchaVerifier`)
and is gated on `APP_ENV=testing`.

## Data and audit notes

- Import jobs are row-validated and idempotent by `external_key`.
- Master/import changes write entity-version snapshots to `master_versions`.
- Rental overdue transition job: `vetops:rentals:mark-overdue`.
- Upload integrity job: `vetops:integrity:check-uploads`.
- Audit partitions archive via scheduler (`vetops:audit:archive`) using retention configuration.

## Tests

Backend tests are orchestrated from repository root wrappers:

- Unit wrapper: `unit_tests/run_unit_tests.sh`
- API wrapper: `API_tests/run_api_tests.sh`

Direct Laravel suites are in:

- `tests/Unit`
- `tests/Feature`
