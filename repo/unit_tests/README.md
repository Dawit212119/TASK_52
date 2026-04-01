# unit_tests

This directory contains acceptance-facing unit test execution wrappers and resources for standard 3.3.4.

## Script
- `run_unit_tests.sh`: runs backend PHPUnit `Unit` tests and frontend Vitest unit tests, then emits a combined JUnit XML artifact.

## Coverage intent
- Core logic correctness
- Boundary and illegal input handling
- State transition behavior
- Exception handling stability
