# VetOps Unified Operations Portal - System Design

## 1) Product Intent
VetOps is an on-premise operations platform for a multi-location veterinary hospital group. It unifies equipment rental operations, medical-supply inventory, internal content publishing, and post-visit service feedback under a single role-based Vue.js interface backed by Laravel + MySQL.

Primary goals:
- Run fully without internet access.
- Enforce operational controls (availability, stock variance, approval workflows).
- Maintain strong traceability (versioning + immutable ledgers + full audit).
- Keep workflows fast for frontline staff.

## 2) Users and Workspaces

### System Administrator
- User/role management, security policies, retention/configuration.
- Import/export administration, dedup rules, global settings.

### Clinic Manager
- Facility-wide operational dashboard.
- Approvals (stock variance > 5%, merge confirmation, content approval).
- Review response, appeal/hide workflow handling.

### Inventory Clerk
- Receive/issue/transfer/stocktake flows.
- Storeroom-level stock management.

### Technician/Doctor
- Rental checkout/return operations.
- Service order inventory reservations.

### Content Editor / Approver
- Draft, review, publish, rollback announcements/carousels.
- Audience targeting by facility, department, role, and tags.

## 3) High-Level Architecture

### Runtime Topology (Local LAN)
- Vue.js SPA client served from local web server.
- Laravel API application layer.
- MySQL as source of record for transactional and analytical snapshots.
- Local object/file storage on disk for uploads.
- Local scanner input (QR/barcode) attached to workstation.

### Logical Layers
1. Presentation layer (Vue.js)
2. Application/service layer (Laravel domain services)
3. Persistence layer (MySQL + immutable ledgers + version tables)
4. File subsystem (checksummed local storage)
5. Background jobs/schedulers (overdue transitions, analytics snapshots, integrity checks)

## 4) Core Domain Modules

### A) Rental Asset Management
Capabilities:
- Asset ledger search with status visibility: `available`, `rented`, `maintenance`, `deactivated`, `overdue`.
- Photo/spec preview and scanner lookup.
- Checkout capturing renter, expected return, deposit/fee terms.
- Double-booking prevention and overdue countdown.

Key rules:
- Default deposit: `max(20% of replacement cost, $50)`.
- Pricing supports daily/weekly rates in USD.
- Auto-status to `overdue` at 2 hours after scheduled return.

### B) Inventory and Storeroom Operations
Capabilities:
- Multi-storeroom by facility.
- Guided receive, issue, transfer, stocktake.
- Low-stock banners based on safety stock (default 14 days average usage).
- Reservation strategy toggle:
  - lock inventory at order creation
  - deduct on order close

Ledger model:
- Every inventory write posts to immutable stock ledger.
- Movement types: inbound, outbound, transfer, adjustment.
- ATP calculated from on-hand minus reservations and pending commitments.

Control rule:
- Stocktake variance > +/-5% requires manager approval and mandatory reason.

### C) Internal Publishing Workbench
Capabilities:
- Draft announcements/homepage carousel items.
- Approval routing and lifecycle states.
- Audience targeting by org dimensions and tags.
- Versioned rollback with preserved provenance.

### D) Visit Feedback and Moderation
Capabilities:
- Post-visit owner review on local tablet: rating, tags, text, up to 5 images.
- Manager/provider responses.
- Appeal/hide workflow for abusive content while keeping audit visibility.
- Dashboard metrics by clinic/provider.

Metrics:
- Average score.
- Negative-review rate.
- Median response time.

### E) Master Data + Ingestion Pipeline
Capabilities:
- Unified model for facilities, departments, providers, services, pricing, hours, addresses.
- CSV bulk import/export with row-level validation.
- Idempotent upsert on same external key.
- Versioned history with reversible changes.

### F) Deduplication and Entity Resolution
Capabilities:
- URL normalization for reference links.
- Near-duplicate detection with SimHash/MinHash for content.
- Key-field matching for services/providers.
- Manager-confirmed merges with conflict resolution preserving lineage.

## 5) Data Design

### Main Entity Groups
- Identity: users, roles, permissions, workstation profiles.
- Org structure: facilities, departments, addresses, hours.
- Clinical catalog: providers, services, prices.
- Rentals: assets, rental contracts, returns, pricing policies.
- Inventory: items, storerooms, stock ledger, stocktakes, reservations.
- Publishing: content items, versions, approval tasks, targeting mappings.
- Reviews: review records, media attachments, responses, moderation actions.
- Governance: audit events, import jobs, dedup candidates, merge decisions.

### Data Integrity Patterns
- Soft-delete for business records where required.
- Immutable append-only tables for stock ledger and critical audit trails.
- Version tables for reversible history (who/when/what changed).
- Foreign-key constraints and transaction boundaries for consistency.

### Priority Schema Additions From Questions Log

#### Q1) Rental asset ownership scope
Add explicit ownership timeline so each asset belongs to exactly one facility at any moment:
- `rental_asset_ownership_history(asset_id, facility_id, effective_from_utc, effective_to_utc, transfer_request_id, created_by_user_id)`
- Transfer workflow uses `asset_transfers` with states `requested -> approved|rejected -> completed`.
- Constraint: no overlapping active ownership windows for same asset.

#### Q5) Inventory reservation strategy consistency
Support mixed strategy per service but one ATP calculation path:
- `inventory_reservation_events(service_order_id, service_id, item_id, storeroom_id, event_type, qty, strategy, created_at_utc)`
- `event_type` values: `reserve`, `plan`, `consume`, `release`.
- ATP query subtracts both hard reservations and qualified planned demand according to status rules.

#### Q9) CSV import conflict handling
Prevent silent overwrite with row-level conflict table:
- `import_jobs` stores file, actor, entity, status, mode.
- `import_rows` stores validation outcome per row.
- `import_conflicts(import_job_id, entity, external_key, row_number, db_version, source_version, status, resolution_action, resolution_payload_json)`.
- Import commit can be partial or all-or-nothing depending on mode.

#### Q14) Review moderation rules
Formalize abuse policy and traceable moderation:
- `review_moderation_policies(version, category, rule_text, is_active)`
- `review_moderation_cases(review_id, reason_category, policy_version, status, requested_by_user_id, assigned_to_user_id, decision_by_user_id, decision_note)`.
- Category baseline: abusive language, harassment, privacy leak, spam, other.

#### Q15) Audit log scalability
Add physical partition metadata and archival process:
- `audit_events` partitioned by month/facility.
- `audit_event_partitions(partition_key, month_utc, facility_id, storage_tier, row_count, bytes_size, sealed_at_utc, archived_at_utc)`.
- Hot/warm/archive lifecycle while preserving 7-year retention.

## 6) Security and Compliance Design
- Local-only authentication (username/password).
- Password policy: min 12 chars, salted hash.
- Inactivity timeout default 15 min, configurable.
- Login throttle: max 10/10 min/workstation; CAPTCHA after 5 failures.
- CSRF protection for state-changing requests.
- Output encoding and strict input validation.
- Parameterized queries only.
- Sensitive field encryption at rest and role-based masking in responses.
- Uploads stored locally with checksum validation.
- Full-chain audit retention for 7 years.

## 7) Workflow Design Notes

### Rental Checkout
1. Search/scan asset.
2. Validate status and overlap conflicts.
3. Capture renter + expected return + pricing/deposit terms.
4. Commit checkout transaction + audit event.
5. Background scheduler marks overdue after threshold.

### Inventory Movements
1. Select facility/storeroom and operation type.
2. Validate quantity and policy constraints.
3. Post immutable ledger entries.
4. Recompute ATP and low-stock flags.
5. Emit alerts/banner updates.

### Content Approval
1. Editor submits draft.
2. Approver reviews target scope and content quality.
3. Approve/reject with rationale.
4. Publish and optionally rollback by version if needed.

### Review Moderation
1. Owner submits order-level review.
2. Manager/provider responds.
3. If abusive/disputed, initiate appeal/hide.
4. Keep moderation timeline auditable and queryable.

### Q1) Facility Transfer Workflow (Rental Assets)
1. Manager requests transfer to target facility with reason and effective time.
2. System verifies no open checkout, no active maintenance lock, and no ownership overlap.
3. Approver accepts or rejects request.
4. On approval, system closes current ownership row and opens new ownership row atomically.
5. Audit records actor, reason, before/after facility, and timestamps.

### Q5) Service Order Reservation Workflow
1. Service order created with configured strategy (`reserve_on_order_create` or `deduct_on_order_close`).
2. System writes reservation/planning events to reservation event stream.
3. ATP endpoint computes availability from stock ledger + reservation events.
4. On order close, planned demand is converted to outbound movement; reservations are reconciled/released.
5. Any shortage at close triggers manager exception flow.

### Q9) Import Conflict Workflow
1. User uploads CSV and triggers validate phase.
2. Validation checks schema, required fields, and `external_key` uniqueness.
3. Version check compares `source_version` with current DB version.
4. Non-conflicting rows can commit; conflicts move to `import_conflicts`.
5. Manager resolves conflict by overwrite, keep existing, or merge fields, then replays row.

### Q14) Review Moderation Workflow
1. Staff flags a review with reason category.
2. System creates moderation case linked to active policy version.
3. Assigned manager reviews evidence and chooses uphold/reject/escalate.
4. Visibility action (hide/unhide) is applied with reason and decision note.
5. Full moderation timeline remains queryable for audits and appeals.

### Q15) Audit Archival Workflow
1. Monthly job seals closed partitions.
2. Archive job moves partitions older than hot threshold to warm/archive tier.
3. Query service performs partition pruning by date/facility filters.
4. Reindex can run per partition after restore/import events.
5. Retention enforcer prevents deletion before 7-year policy horizon.

## 8) API and Integration Boundaries
- Frontend consumes Laravel REST APIs over local LAN.
- Scanner integration uses keyboard-wedge or local HID reader interpreted by UI input focus.
- No external SaaS dependencies required for core operation.
- Scheduled jobs run locally for analytics snapshots, overdue handling, and integrity checks.
- Additional boundary contracts:
  - Ownership transfer and moderation actions require manager/admin authorization.
  - Import pipeline exposes validate/commit/resolve phases to separate review from mutation.
  - Audit storage/archival interfaces are internal APIs consumed by scheduled jobs.

## 9) Operational and Deployment Considerations
- Deploy as local web app stack within facility network.
- Configure backups for MySQL + local upload storage.
- Provide disaster recovery runbook (restore DB/files, validate checksums, replay scheduled jobs).
- Capacity baseline: tune for peak multi-clinic shifts and bulk CSV imports.
- Observability: local logs + job monitoring + audit query screens for admins.

## 10) Acceptance Criteria Snapshot
- Role-based workspaces behave per permission matrix.
- Rental system blocks double-booking and flags overdue at +2h.
- Inventory ledger is immutable and enforces >5% stocktake approval.
- Safety-stock alerts trigger from 14-day usage baseline.
- Content approval and rollback flows preserve version/audit history.
- Review module supports media upload, response, and moderated appeal/hide workflow.
- Import pipeline is idempotent with row-level validation and reversible change history.
- Security controls (auth policy, throttle, CAPTCHA, masking, CSRF, audit retention) are active.
- Rental ownership transfer is facility-scoped, auditable, and prevents overlap.
- Mixed reservation strategies do not produce ATP inconsistencies.
- Import conflicts are detected explicitly and cannot silently overwrite records.
- Moderation decisions use policy categories and are fully auditable.
- Audit queries remain performant through partitioning/archival over 7-year retention.