# API_tests

This directory contains API functional test execution wrappers and resources for standard 3.3.4.

## Script
- `run_api_tests.sh`: runs backend Laravel `Feature` tests (API-facing behavior) and outputs JUnit XML.

## Coverage intent
- Valid request/response behavior
- Missing/invalid parameter handling
- Permission and authorization checks
- Data mutation side effects and persistence assertions
