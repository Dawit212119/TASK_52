# Business Logic Questions Log

## 1) Rental asset ownership scope

**Question:** Are rental assets shared across all facilities or restricted to a single facility?
**My Understanding:** Assets belong to a specific facility but may be transferred.
**Solution:** Add `facility_id` ownership with controlled transfer workflow and audit logging.

---

## 2) Double-booking prevention rules

**Question:** Does the system consider buffer time (cleaning/maintenance) between rentals?
**My Understanding:** Immediate reuse is unrealistic for medical equipment.
**Solution:** Introduce configurable buffer time per asset type before availability resets.

---

## 3) Overdue rental handling

**Question:** What actions occur after an asset becomes overdue beyond 2 hours?
**My Understanding:** Only status changes are defined, not consequences.
**Solution:** Implement escalation workflow (notifications, penalties, restrictions).

---

## 4) Deposit management

**Question:** How are deposits refunded or adjusted in case of damage?
**My Understanding:** Refund depends on asset condition.
**Solution:** Add deposit settlement flow with inspection report and adjustable refund logic.

---

## 5) Inventory reservation strategy

**Question:** How to handle conflicts between “lock at order creation” and “deduct at closure”?
**My Understanding:** Mixed strategies create inconsistent stock states.
**Solution:** Enforce per-service strategy but unify calculation via available-to-promise logic.

---

## 6) Multi-storeroom consistency

**Question:** How to prevent stock inconsistencies during concurrent updates?
**My Understanding:** Multiple clerks updating simultaneously can corrupt data.
**Solution:** Use database transactions and immutable stock ledger.

---

## 7) Stocktake variance approval

**Question:** What if a manager is unavailable to approve >±5% variance?
**My Understanding:** Workflow could block operations.
**Solution:** Allow pending state with restricted actions until approval.

---

## 8) Low-stock calculation

**Question:** How is “14 days of average usage” calculated?
**My Understanding:** Based on historical consumption but unclear timeframe.
**Solution:** Use rolling 30-day average usage for dynamic safety stock.

---

## 9) CSV import conflict handling

**Question:** What happens if multiple imports modify the same record?
**My Understanding:** Risk of silent overwrites.
**Solution:** Add version control and conflict detection requiring manual resolution.

---

## 10) Idempotent key definition

**Question:** What defines the unique external key for imports?
**My Understanding:** Not standardized across entities.
**Solution:** Require `external_id` with uniqueness constraints per entity.

---

## 11) Data version rollback

**Question:** Is rollback at field level or entire record level?
**My Understanding:** Likely full record rollback.
**Solution:** Store field-level changes but expose record-level rollback.

---

## 12) Content approval workflow

**Question:** Can approvers edit content or only approve/reject?
**My Understanding:** Minor edits may be required.
**Solution:** Allow edit + reapproval cycle with comments.

---

## 13) Content visibility conflicts

**Question:** How to resolve overlapping targeting rules (facility, role, department)?
**My Understanding:** Conflicts may lead to incorrect visibility.
**Solution:** Define precedence hierarchy (facility > department > role > tags).

---

## 14) Review moderation rules

**Question:** What qualifies as abusive content in reviews?
**My Understanding:** Not clearly defined, leading to inconsistent moderation.
**Solution:** Define moderation policy with categories and audit tracking.

---

## 15) Audit log scalability

**Question:** How to handle 7-year audit logs without performance degradation?
**My Understanding:** Large logs will slow queries.
**Solution:** Partition logs by date and archive older records.

---

# Immediate next step

Take **questions 1, 5, 9, 14, and 15** and design **database schemas + API endpoints** for each. If you hesitate or can’t define exact tables and flows, your system design isn’t concrete yet.
