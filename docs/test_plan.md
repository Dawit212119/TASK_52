# VetOps test plan (3.3.4 harness)

## Purpose

Describe what `run_tests.sh` executes and the environments it assumes.

## Scope

| Suite | Script | Description |
|-------|--------|-------------|
| Unit | `unit_tests/run_unit_tests.sh` | Backend PHPUnit `Unit` + frontend Vitest (`npm run test`) |
| API / feature | `API_tests/run_api_tests.sh` | Backend PHPUnit `Feature` (HTTP/API smoke) |

## Preconditions

- Run from repository root: `repo/` (same directory as `run_tests.sh`).
- `prepare_test_env.sh` is invoked by `run_tests.sh` before suites (Docker stack health, etc., per script).
- Output: `test_reports/<timestamp>/` with JUnit XML, logs, and `summary.json`.

## Success criteria

- Both suite scripts exit `0`.
- Aggregated JUnit: `failures == 0` and `errors == 0` (skipped allowed).

## Out of scope

- Browser E2E (not in this harness).
- Manual exploratory testing (document separately using `docs/test_report_template.md`).
