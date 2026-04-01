# Traceability matrix — automated harness

Maps high-level capability areas to the suites executed by `repo/run_tests.sh`.  
Update this table when you add suites or change scope.

| Area | Evidence (suite / location) |
|------|-------------------------------|
| Auth API (login, rate limit, logout, session) | Backend `tests/Feature/Auth/*` via `repo/API_tests` |
| RBAC / permission gates | Backend `tests/Feature/Auth/RbacAuthorizationTest.php` |
| Domain: master data | Backend `tests/Feature/Domain/MasterDataApiTest.php` |
| Domain: rentals | Backend `tests/Feature/Domain/RentalsApiTest.php` |
| Domain: inventory | Backend `tests/Feature/Domain/InventoryApiTest.php` |
| Domain: content, reviews, audit partitions | Backend `tests/Feature/Domain/ContentReviewsAuditApiTest.php` |
| Domain: imports / dedup | Backend `tests/Feature/Domain/ImportDedupApiTest.php` |
| Full API route smoke | Backend `tests/Feature/Api/AllApiEndpointsTest.php` |
| Frontend units | Frontend Vitest under `repo/frontend/src/**/*.test.ts` via `repo/unit_tests` |
| Backend unit (non-HTTP) | Backend `tests/Unit/*` via `repo/unit_tests` |

> **Note:** E2E and manual UAT are not part of this matrix unless added under `repo/unit_tests/` or `repo/API_tests/`.
