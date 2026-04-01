# Test execution report — template

Fill this after a run or attach `repo/test_reports/<timestamp>/summary.md` from `repo/run_tests.sh`.

## Run metadata

- Date (UTC):
- Git revision / tag:
- Operator:
- Command: `bash run_tests.sh` (from `repo/`)

## Environment

- OS:
- Docker: (version; `docker compose` project name if relevant)

## Results summary

- Overall: PASS ⚠️ / FAIL ❌
- Paths: `repo/test_reports/<timestamp>/`

| Metric | Count |
|--------|-------|
| Total tests | |
| Passed | |
| Failed | |
| Errors | |
| Skipped | |

## Suite notes

### Unit (`repo/unit_tests`)

- Status:
- Log: `unit-tests.log`
- JUnit: `unit-tests.junit.xml`

### API / Feature (`repo/API_tests`)

- Status:
- Log: `api-tests.log`
- JUnit: `api-tests.junit.xml`

## Issues and follow-ups

- [ ] Issue 1 …
- [ ] Issue 2 …

## Sign-off

- Name / role:
- Date:
