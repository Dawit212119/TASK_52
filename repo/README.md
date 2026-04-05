# VetOps Dockerized Fullstack

VetOps runs as a LAN-first on-prem stack using Docker Compose.

- `backend/` - Laravel API (`/api/v1`)
- `frontend/` - Vue 3 + Vite app built and served by Nginx
- `db` service - MySQL 8.4 with persistent named volume

This setup uses **Option A routing**: frontend Nginx proxies `/api` to backend service.

## Prerequisites

- Docker Engine 24+
- Docker Compose v2 (`docker compose`)
- Node.js is optional (only needed if you want `npm run ...` helper scripts)

## Zero-local-setup quick start (Docker-only)

From the `repo/` directory (`cd repo` after cloning):

```bash
docker compose up -d --build
```

This project is configured to boot with defaults from `docker-compose.yml` only, so no local `.env` editing is required for first run.

**What starts on `docker compose up`:** `db`, `db-test`, `backend`, `frontend` (Nginx on **8080**), and `frontend-dev` (Vite on **5173**). To save resources, stop Vite only: `docker compose stop frontend-dev`.

## One-command first run

```bash
npm run bootstrap
```

`bootstrap` does:

1. Creates missing `.env` files from examples (if you want explicit files).
2. Generates `APP_KEY` in `.env` when blank.
3. Executes `docker compose up -d --build`.

## Production-like compose usage

```bash
npm run up
```

App endpoint:

- Frontend: `http://localhost:8080`
- API via proxy: `http://localhost:8080/api/v1/...`

No host DB port is exposed. Backend is internal-only by default.

## Hot-reload frontend (Vite)

`frontend-dev` is defined in `docker-compose.yml` and **starts with** `docker compose up -d --build` like the other services.

- **Built UI (production image):** `http://localhost:8080`
- **Vite dev server:** `http://localhost:5173` (live reload; `./frontend` bind-mounted)

API from Vite is proxied to `backend:8000` as `/api/v1`.

## Service and network model

- `frontend` is reachable on host port `8080` (configurable via `FRONTEND_PORT`).
- `backend` listens on internal network port `8000` (healthchecked).
- `db` uses internal-only port `3306` (healthchecked).
- Named volumes:
  - `vetops_db_data` - MySQL data
  - `vetops_backend_storage` - Laravel storage (uploads/logs/cache)
  - `vetops_backend_bootstrap_cache` - Laravel bootstrap cache

## Operations commands

From the `repo/` directory (`cd repo` after cloning):

- `npm run up` - build and start stack
- `npm run down` - stop stack
- `npm run logs` - tail compose logs
- `npm run rebuild` - no-cache rebuild
- `npm run migrate` - run Laravel migrations
- `npm run seed` - run DB seeders
- `npm run test` - backend tests + frontend tests (`frontend-dev` container for Vitest)

## Verification checklist

After startup:

1. `docker compose ps` shows healthy `db`, `db-test`, `backend`, `frontend`, and usually `frontend-dev`.
2. `http://localhost:8080` loads SPA.
3. `http://localhost:8080/api/v1/health` returns JSON.
4. Login endpoint responds through proxy:
   - `POST http://localhost:8080/api/v1/auth/login`
5. Migrations completed in backend logs.
6. `storage/` is writable and persisted in `vetops_backend_storage` volume.

Optional writable check:

```bash
docker compose exec backend sh -lc "mkdir -p storage/app/public && echo ok > storage/app/public/.write-test && ls -l storage/app/public/.write-test"
```

---

## Static-Only Review Path (No Docker Required)

For code review, audit verification, and running tests without Docker, use the following instructions.

### Prerequisites for static review

- PHP 8.2+ with extensions: mbstring, openssl, pdo_sqlite, json, tokenizer, xml
- Composer 2.x
- Node.js 18+ and npm (for frontend)
- SQLite3 (used automatically in test environment)

### Backend: install and test

```bash
cd backend
cp .env.testing .env
composer install --no-interaction
php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan test
```

All backend tests run against SQLite in-memory by default (see `phpunit.xml` and `.env.testing`).

### Frontend: install and test

```bash
cd frontend
npm ci
npm run test        # Vitest unit tests
npm run lint        # ESLint check
npm run type-check  # TypeScript check (if configured)
```

### Direct test commands

| Suite | Command | Working dir |
|-------|---------|-------------|
| Backend all | `php artisan test` | `backend/` |
| Backend feature | `php artisan test --testsuite=Feature` | `backend/` |
| Backend unit | `php artisan test --testsuite=Unit` | `backend/` |
| Frontend unit | `npm run test` | `frontend/` |

### Key entry point map

| Layer | Entry point | Path |
|-------|------------|------|
| API routes | Route definitions | `backend/routes/api.php` |
| Console commands | Artisan schedules | `backend/routes/console.php` |
| Middleware bootstrap | App middleware stack | `backend/bootstrap/app.php` |
| Domain schema | All domain tables | `backend/database/migrations/2026_03_30_001000_create_vetops_domain_tables.php` |
| Auth controller | Login/logout/me | `backend/app/Http/Controllers/AuthController.php` |
| RBAC seeder | Roles and permissions | `backend/database/seeders/RbacSeeder.php` |
| API controllers | Domain logic | `backend/app/Http/Controllers/Api/` |
| Support services | Auth, audit, scope | `backend/app/Support/` |
| Frontend API layer | API module definitions | `frontend/src/api/modules.ts` |
| Frontend views | Page components | `frontend/src/views/` |
| Frontend modules | Feature modules | `frontend/src/components/modules/` |

---

## Audit Verification Checklist

Use this checklist to verify project compliance without running Docker:

### Authentication & Authorization
- [ ] `AuthController.php` — CAPTCHA challenge flow after N failed attempts (not static token)
- [ ] `AuthRateLimiter.php` — per-workstation rate limiting
- [ ] `CaptchaVerifier.php` + `CaptchaChallengeService.php` — real challenge generation/verification
- [ ] `EnsurePermission.php` — gate-based RBAC middleware
- [ ] `FacilityScope.php` — facility-level object authorization
- [ ] `RbacSeeder.php` — role-permission matrix

### Data Integrity
- [ ] `reviews.visit_order_id` is `string(64)` — see migration `2026_04_05_000001_fix_visit_order_id_type_to_string.php`
- [ ] `ContentReviewsController::createReview` — validates `visit_order_id` as string
- [ ] `ContentReviewsController::createPublicReview` — token-bound public review with encrypted phone

### Import/Dedup
- [ ] `ImportDedupController::commitImport` — stores `facility_scope_json` on import jobs
- [ ] `ImportDedupController::getImportReport` — enforces object-level facility scope
- [ ] `ImportDedupController::dedupMerge` — real merge with provenance (merge events + items)
- [ ] Migration `2026_04_05_000003_create_dedup_merge_history_tables.php`

### Inventory
- [ ] `service_orders.reservation_strategy` — per-order strategy (migration `2026_04_05_000005`)
- [ ] `InventoryController::reserveServiceOrder` — persists strategy on order, rejects mismatch
- [ ] `InventoryController::closeServiceOrder` — uses order's stored strategy

### Audit
- [ ] `AnalyticsAuditController::auditLogById` — blocks non-admin access to null-facility events
- [ ] `AuditLogger.php` — partition-based audit logging
- [ ] `AuditMutations.php` — automatic mutation audit middleware

### Tests
- [ ] `tests/Feature/Domain/ContentReviewsAuditApiTest.php` — review flow, type tests, audit
- [ ] `tests/Feature/Domain/ImportDedupApiTest.php` — import conflicts, dedup merge, authorization
- [ ] `tests/Feature/Domain/InventoryApiTest.php` — reservation strategy, facility scope
- [ ] `tests/Feature/Domain/MasterDataApiTest.php` — versioning, facility scope
- [ ] `tests/Feature/Auth/AuthApiTest.php` — CAPTCHA challenge flow tests

---

## Troubleshooting

- **Build fails: `auth.docker.io` / `no such host` / `i/o timeout`**
  - Your machine cannot reach **Docker Hub** (DNS, firewall, VPN, or regional limits). Fixes:
    1. Restore general internet/DNS (try another network or set Docker Desktop → Settings → Docker Engine → DNS such as `8.8.8.8`).
    2. In `repo/.env`, set a **mirror prefix** for image builds (see `repo/.env.example`):  
       `DOCKER_IMAGE_REGISTRY_PREFIX=docker.m.daocloud.io/library/`  
       and optionally:  
       `MYSQL_IMAGE=docker.m.daocloud.io/library/mysql:8.4`  
       then `docker compose build --no-cache` and `docker compose up -d`.
    3. Or configure **registry mirrors** in Docker Desktop’s daemon JSON (official Docker docs).

- **`db` unhealthy / `dependency failed to start: container vetops-db-1 is unhealthy`**
  - Check logs: `docker compose logs db --tail 100`.
  - If logs say **`--initialize specified but the data directory has files in it`** or **data directory is unusable**, the volume has a partial or foreign DB layout. From `repo/`, run **`npm run reset:mysql-volumes`** (wipes app + test DB data), then `npm run up`.
  - **Wrong or changed `MYSQL_ROOT_PASSWORD` vs existing volume:** MySQL only reads the root password from the first init. Fix: same reset as above, or manually: `docker compose -p vetops down` then `docker volume rm vetops_vetops_db_data vetops_vetops_db_test_data`.
  - **Stale volume from an older DB engine** (e.g. PostgreSQL): use **`npm run reset:mysql-volumes`**.
  - First MySQL startup can take **up to ~90s**; healthchecks allow that via `start_period`.

- **Backend unhealthy**
  - Check logs: `docker compose logs backend --tail 150` (migrations, seeders, or cache steps often fail first).
  - The **`vetops_vetops_backend_bootstrap_cache`** volume can hold an old `config.php` (e.g. wrong `DB_*` after switching engines). The entrypoint clears config before migrations; if you still see wrong DB errors, remove that volume and `docker compose up -d --build` again.
  - If you use custom env values, verify `.env` credentials match MySQL (`MYSQL_*` / `DB_*`).
  - Optional: set `RUN_SEEDERS=true` in `repo/.env` when you want seed data (default in compose is off; matches `RUN_SEEDERS=false` in `.env.example`).
  - Ensure `APP_KEY` is set (or let the container generate one when unset).

- **Migration failures on startup**
  - Temporarily set `RUN_MIGRATIONS=false` to isolate boot issues.
  - Run migrations manually: `npm run migrate`.

- **CORS/auth issues on LAN terminals**
  - Update `FRONTEND_ALLOWED_ORIGINS` to exact URL(s) used by clients.

- **Frontend cannot reach API**
  - Validate Nginx proxy path `/api` and backend health.
  - Confirm backend container is healthy before frontend starts.

- **Permission errors under storage/**
  - Restart backend container to re-apply entrypoint permissions.
  - Validate mounted volume ownership with `docker compose exec backend ls -la storage`.

## Security and data handling notes

- No secrets are hardcoded in Dockerfiles/compose.
- API traffic is proxied internally; DB remains internal-only.
- Laravel query builder and validation paths remain in effect.
- Upload checksum integrity job available: `vetops:integrity:check-uploads`.
- Audit archive scheduler is configured with 7-year retention baseline.
- CAPTCHA uses server-side generated math challenges, not static bypass tokens.

## Backup and restore (Docker volumes)

### Backup

- MySQL volume: `vetops_db_data`
- Upload/storage volume: `vetops_backend_storage`

Recommended cadence:

- Daily DB backup + daily storage backup
- Weekly restore drill to a staging host
- Keep retention aligned with audit policy requirements

### Restore

1. Stop stack: `npm run down`.
2. Restore `vetops_db_data` and `vetops_backend_storage` from backup media.
3. Start stack: `npm run up`.
4. Run integrity check:
   - `docker compose exec backend php artisan vetops:integrity:check-uploads`
5. Verify health and critical flows through frontend proxy.

## Acceptance testing (standard 3.3.4)

Mandatory harness (this folder — `repo/` in the Git clone):

- `unit_tests/` — unit test wrappers (backend PHPUnit Unit + frontend Vitest)
- `API_tests/` — API functional test wrappers (backend PHPUnit Feature)
- `test_reports/` — timestamped execution outputs (JUnit, logs, `summary.json`; generated locally, not committed)
- `run_tests.sh` / `prepare_test_env.sh` — one-click runner and environment prep

From the `repo/` directory (`cd repo` after cloning):

```bash
bash run_tests.sh
```

See `docs/test_plan.md`, `docs/test_traceability_matrix.md`, and `docs/test_report_template.md` when those files are present.

## Additional references

- API contract: `docs/api-spec.md` (if included in this clone)
- System design: `docs/design.md` (if included in this clone)
- Decisions/questions: `docs/questions.md` (if included in this clone)
- Handoff status/risk report: `HANDOFF_REPORT.md`
