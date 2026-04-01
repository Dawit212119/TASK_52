# VetOps Dockerized Fullstack

VetOps runs as a LAN-first on-prem stack using Docker Compose.

- `backend/` - Laravel API (`/api/v1`)
- `frontend/` - Vue 3 + Vite app built and served by Nginx
- `db/` - PostgreSQL with persistent named volume

This setup uses **Option A routing**: frontend Nginx proxies `/api` to backend service.

## Prerequisites

- Docker Engine 24+
- Docker Compose v2 (`docker compose`)
- Node.js is optional (only needed if you want `npm run ...` helper scripts)

## Zero-local-setup quick start (Docker-only)

From the repository root:

```bash
docker compose up -d --build
```

This project is configured to boot with defaults from `docker-compose.yml` and image entrypoints, so no local `.env` editing is required for first run.

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

## Local dev profile (hot reload frontend)

A dev profile is provided in `docker-compose.override.yml`:

```bash
docker compose --profile dev up -d --build db backend frontend-dev
```

Dev endpoints:

- Vite hot reload: `http://localhost:5173`
- API from Vite (proxied): `/api/v1` to `backend:8000`

## Service and network model

- `frontend` is reachable on host port `8080` (configurable via `FRONTEND_PORT`).
- `backend` listens on internal network port `8000` (healthchecked).
- `db` uses internal-only port `5432` (healthchecked).
- Named volumes:
  - `vetops_db_data` - PostgreSQL data
  - `vetops_backend_storage` - Laravel storage (uploads/logs/cache)
  - `vetops_backend_bootstrap_cache` - Laravel bootstrap cache

## Operations commands

From the repository root:

- `npm run up` - build and start stack
- `npm run down` - stop stack
- `npm run logs` - tail compose logs
- `npm run rebuild` - no-cache rebuild
- `npm run migrate` - run Laravel migrations
- `npm run seed` - run DB seeders
- `npm run test` - backend tests + frontend tests (dev profile runner)

## Verification checklist

After startup:

1. `docker compose ps` shows healthy `db`, `backend`, `frontend`.
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

## Troubleshooting

- **Backend unhealthy**
  - If you use custom env values, verify `.env` credentials.
  - Check backend logs: `docker compose logs backend`.
  - Ensure `APP_KEY` is set (or regenerate via `npm run bootstrap`).

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

## Backup and restore (Docker volumes)

### Backup

- PostgreSQL volume: `vetops_db_data`
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

Mandatory harness lives in this repository root:

- `unit_tests/` — unit test wrappers (backend PHPUnit Unit + frontend Vitest)
- `API_tests/` — API functional test wrappers (backend PHPUnit Feature)
- `test_reports/` — timestamped execution outputs (JUnit, logs, `summary.json`)
- `run_tests.sh` / `prepare_test_env.sh` — one-click runner and environment prep

From the repository root:

```bash
bash run_tests.sh
```

See `docs/test_plan.md`, `docs/test_traceability_matrix.md`, and `docs/test_report_template.md` when those files are present.

## Additional references

- API contract: `docs/api-spec.md` (if included in this clone)
- System design: `docs/design.md` (if included in this clone)
- Decisions/questions: `docs/questions.md` (if included in this clone)
- Handoff status/risk report: `HANDOFF_REPORT.md`
