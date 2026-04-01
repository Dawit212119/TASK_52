# API Functional Test Catalog

| Test ID | Purpose | Expected Validation Type |
|---|---|---|
| A-AUTH-001 | Login/auth behavior contract | status codes + response envelope |
| A-AUTH-002 | RBAC denial/allow behavior | permission checks + forbidden responses |
| A-DOM-MASTER-001 | Master data API workflow | validation + state mutation |
| A-DOM-RENTAL-001 | Rental API state transitions | business rule + mutation checks |
| A-DOM-INV-001 | Inventory API functional paths | validation + ledger side effects |
| A-DOM-IMPORT-001 | Import/dedup API handling | idempotency/conflict behavior |
| A-DOM-CONTENT-001 | Content/review/audit API behavior | lifecycle + moderation checks |

Implementation source:
- `backend/tests/Feature/Auth/*.php`
- `backend/tests/Feature/Domain/*.php`
