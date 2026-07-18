# Phase 18 — Findings Log

Working log for the Phase 18 final review (see `docs/phase-18-plan.md` for the session plan,
operating rules, and the meaning of each column). One row per finding; IDs are never reused.
S8 consumes this table in severity order; S9 writes the Phase 19 handoff list at the top.

**Severity:** Blocker / High / Medium / Low / Accepted-risk. Seed findings outside S1's scope
keep `TBD (Sx)` until their owning session confirms severity.

---

## Phase 19 handoff list

*(written by S9 at phase close — placeholder)*

---

## Findings

| ID | Area | Severity | Description | Evidence | Proposed fix | Status |
| --- | --- | --- | --- | --- | --- | --- |
| F-01 | Invariants | High | `Transaction`, `TransactionItem`, `InventoryLedger`, `SaleReturn`, `SaleReturnItem`, `LoginHistory` have **no model-level immutability guard** — unlike `AuditLog`, which throws on update/delete. Immutability is by convention only, and untested (S1 confirmed: the only immutability test in the suite covers `audit_logs`). Note: model events do **not** fire on bulk builder ops (`Model::query()->update()`) or raw SQL, so even `AuditLog`'s existing guard is bypassable that way; and `transaction_items` / `sale_return_items` FKs `cascadeOnDelete` from their parent, so a parent hard-delete silently removes lines. | `app/Models/AuditLog.php:28-37` (the pattern); the six models lacking it; `tests/Feature/Audit/AuditLoggingTest.php:217` (only existing test); `database/migrations/2026_07_08_000006_create_transaction_items_table.php:19` | **S1 decision — enforcement shape:** (a) add the `booted()` update/delete guard to all six models for parity and friendly errors, (b) add SQLite `BEFORE UPDATE` / `BEFORE DELETE … RAISE(ABORT)` triggers on all **7** append-only tables (incl. `audit_logs`) in one migration as the hard backstop that model events cannot provide, (c) per-table immutability tests. Restore is a whole-file copy, so triggers never interfere with backup/restore. | Open |
| F-02 | Invariants | Medium | Transaction serial generator is count-based with a UNIQUE-index retry loop; only 2 tests exist — none proving no gap after a failed/rolled-back checkout, none exercising the collision-retry path. S1 walked the mechanism: the number is derived and claimed **inside** the committing `DB::transaction`, so rollback/crash cannot leave a gap — the gaps risk is test coverage, not design. | `app/Support/Sales/TransactionNumberGenerator.php:34`, `app/Http/Controllers/PosController.php:130-141` | S5 tests: failed checkout (oversell / validation) leaves no gap and the next sale takes the next serial; collision-retry path (seed a UNIQUE violation, assert retry succeeds). | Open |
| F-03 | Security | n/a | Audit-log payload **cost redaction** for non-Owners has no test. **S2: stale** — the test exists: `tests/Feature/Audit/AuditLogDisplayTest.php:35` asserts cost stripped at depth for Admin and visible for Owner. | `app/Http/Controllers/AuditLogController.php:80-97` | None needed. | Closed (S2 — test already exists) |
| F-04 | Security | Accepted-risk | Printer `device_path` and `network_host` are Owner-set unconstrained free text → arbitrary-local-file-write / SSRF-shaped edge. | `app/Support/Printing/RawDeviceConnector.php`, `NetworkConnector.php`, `PrinterSettingsUpdateRequest.php` | **S2 decision: accept.** Owner-only route (`role:owner`), local desktop app running as the operator's own OS user — no privilege boundary is crossed (the Owner already owns the machine), and the payload is raw ESC/POS bytes. A path allowlist would break legitimate setups (`/dev/usb/lpN`, `/dev/rfcommN`, Windows `COM3`, arbitrary LAN printer hosts). Validation already caps length at 255. | Accepted (S2) |
| F-05 | Security | High | Working `.env` ships `APP_DEBUG=true`; friendly error pages only render when `!app.debug`, so a packaged build with debug on leaks stack traces. S2 adds: the packaged build must also **generate a fresh `APP_KEY` per install** (never ship the working `.env`'s key), and pin `APP_ENV=production`. Session config is otherwise sane for offline desktop (`SESSION_DRIVER=database`, no cookie-domain exposure). | `bootstrap/app.php:54`, `.env:2-4` | Phase 19 handoff list entry (S9): pin `APP_DEBUG=false`, `APP_ENV=production`, per-install `APP_KEY` generation. | Open (release gate) |
| F-06 | Tooling | TBD (S4) | No `tsc --noEmit` gate, no JS test runner, and `npm run lint` runs eslint with `--fix` (mutating, not a check). | `package.json` scripts | S6 wires `types` (`tsc --noEmit`) and `lint:check` (eslint without `--fix`) scripts. | Open |
| F-07 | Accuracy | Low | Money columns are `decimal(12,2)` — on SQLite that is NUMERIC affinity (REAL floats under the hood). **S3 audit: the architecture is sound.** Every arithmetic path (checkout, discount, refund apportionment, report aggregation, receipt subtotal) works in integer centavos; SQL `SUM()` floats are rounded back to centavos, and float64 error at realistic magnitudes is far below half a centavo. Remaining exposure is test coverage only. | S3 trace: `PosController::persistSale`, `ProcessSaleReturn::linePaidShares`/`lineRefund`, `ReportsController::aggregate`, `ReceiptContent` | S5 asserts the S3 fixtures (appendix below) cent-exact end-to-end: checkout → returns → report. | Open (tests only) |
| F-08 | Security | n/a | Cleared surfaces (for the record, so S2 doesn't re-sweep): no shell interpolation (`SystemPrinterConnector` uses argv arrays), all raw SQL parameter-bound, no `$guarded = []`, all routes middleware-covered, CSV import validation solid (mimes/size/UTF-8/cell caps), backup restore checks SQLite magic + blocks path traversal. | exploration pass, 2026-07-17 | — | Recorded |
| F-09 | Invariants | Medium | Both serial generators derive the next number from a **row count**, not `MAX(number)`. If a transaction row is ever hard-deleted (manual SQL, partial data loss), `count()+1` lands on an already-used serial **forever** — every checkout exhausts the 3-attempt retry and fails permanently, with no self-healing. (`SaleReturnNumberGenerator` has the same shape but per-day scope, so it self-heals at midnight.) | `app/Support/Sales/TransactionNumberGenerator.php:34`, `app/Support/Sales/SaleReturnNumberGenerator.php:26`, `app/Http/Controllers/PosController.php:130-141` | Derive from `MAX(CAST(transaction_number AS INTEGER)) + 1` (and the per-day MAX for returns) — still gapless for committed sales, still backstopped by the UNIQUE index + retry, but self-healing after any row loss. | Open |
| F-10 | Invariants | Low | Restoring an **older** backup over a newer DB regresses the serial with the data (internally consistent — count and MAX regress together), but serials already printed on paper receipts from the lost newer sales get **reissued** to different future sales: duplicate physical receipt numbers in the wild, a BIR-compliance hazard. | `app/Support/Backup/DatabaseBackupManager.php:133-152` | Likely Accepted-risk (inherent to whole-file restore) + a restore-confirmation warning naming the consequence; add to the Phase 19 handoff notes. Decide in S8. | Open |
| F-11 | Invariants | Low | `StockLedger::reconcile()` — the documented "ledger is source of truth" repair path — has **no callers** anywhere in `app/`; there is no drift detection and no reachable repair surface. At runtime the snapshot silently wins (all stock reads and the never-negative check read the snapshot). No automatic drift source exists in code today (S1 verified the single write path), so this is belt-and-braces. | `app/Support/Inventory/StockLedger.php:91-103` | Add an `inventory:reconcile` artisan command (all variants) and invoke a reconcile sweep after a successful restore; S5 adds the snapshot-equals-ledger-sum assertion after each flow. | Open |
| F-12 | Code-quality | Low | Stale comment: the checkout retry loop says the transaction number "carries a **daily** sequence" — copied from the returns flow; the transaction serial is continuous and never resets. | `app/Http/Controllers/PosController.php:127-129` | Fix the comment in S8 alongside any F-09 change. | Open |
| F-13 | Invariants | Low | POS checkout records its audit entry **after** the sale's `DB::transaction` commits; if the audit insert throws (disk full, DB error), the user gets a 500 for a sale that actually committed — inviting a retry and a duplicate sale — and the "every significant action writes an audit entry" invariant silently breaks. `ProcessSaleReturn` already does it right: its audit write is inside the transaction (`app/Support/Sales/ProcessSaleReturn.php:181`). | `app/Http/Controllers/PosController.php:143-151` | Move the `audit->record()` call inside the `persistSale` closure (parity with returns; the retry loop remains correct — a rolled-back attempt rolls back its audit row too). | Open |
| F-14 | Accuracy | High | `APP_TIMEZONE=UTC` while the business operates in Philippine time (UTC+8). **S3 confirmed on three surfaces:** (a) **receipts print UTC time** — `ReceiptContent` converts `created_at` to `config('app.timezone')` = UTC (`app/Support/Printing/ReceiptContent.php:67-69`), so a customer-facing receipt shows a time 8 hours off; (b) report day/week/month buckets are UTC (`strftime` over stored-UTC `created_at`, Carbon windows in UTC) — a 07:00 local sale lands on the previous business day; (c) the return-number daily bucket is the UTC day. | `.env:5`, `config/app.php:68`, `app/Support/Printing/ReceiptContent.php:67-69`, `ReportsController` bucket queries, `SaleReturnNumberGenerator.php:26` | Set `APP_TIMEZONE=Asia/Manila` (pre-release: no stored data to misread; the receipt's existing `->timezone()` call becomes a no-op going forward and stays correct). Phase 19 note: decide whether packaged installs should derive TZ from the machine instead of pinning. S8 implements + a report-boundary test. | Open |
| F-18 | Accuracy | Low | `ProcessSaleReturn::linePaidShares()` iterates `$transaction->items()->get()` with **no ORDER BY** — the last-line-absorbs-remainder scheme relies on SQLite returning rows in stable rowid order for a line's paid share to be identical across successive return events. In practice SQLite is stable, but it isn't contractual; a changed order could shift a centavo between lines mid-history. | `app/Support/Sales/ProcessSaleReturn.php:86,210-237` | Add an explicit stable ordering (`->orderBy('id')`) to the sold-lines query. | Open |
| F-19 | Accuracy | Low | SQLite runtime config: `busy_timeout` is null (lock contention raises SQLITE_BUSY immediately instead of waiting — NativePHP runs several PHP workers against one file) and `journal_mode` is the default DELETE rollback journal. Power-loss mid-checkout is atomic under the current setup (S3 verified: transaction rolls back, invariants hold). **Phase 19 interaction:** `DatabaseBackupManager` explicitly depends on single-file DELETE-journal mode for its plain file-copy backup — enabling WAL during "SQLite Optimization" without checkpointing first would silently produce stale/incomplete backups. | `config/database.php:41-42`, `app/Support/Backup/DatabaseBackupManager.php:11` | S8: set `busy_timeout` (~5000 ms). Phase 19 handoff: if WAL is adopted, backup must `PRAGMA wal_checkpoint(TRUNCATE)` before copying (or copy the `-wal`/`-shm` sidecars). | Open |
| F-15 | Security | Medium | Starter-kit **profile page** survives with two spec violations: (a) any user can rename their own `name` **and `username`** freely (`ProfileUpdateRequest` allows both), contradicting Phase 13's "name is immutable after creation" and bypassing User Management; (b) `DELETE settings/profile` lets any non-Owner **self-delete** (soft-delete) outside User Management. Neither action writes an audit entry, breaking the "every user change is audited" invariant. | `app/Http/Controllers/Settings/ProfileController.php:26-32,37-61`, `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `resources/js/pages/settings/profile.tsx` | Remove the delete-account route + UI section; make name/username read-only on the profile page (identity is managed via Phase 13 flows; password change already lives at `settings/password`). S8 implements + tests. | Open |
| F-16 | Security | Low | CSV export does not escape cells beginning with `=`, `+`, `-`, `@` (item/category/variant names, description) — spreadsheet apps execute them as formulas (CSV/formula injection). Context limits impact: only Admin+ can name items, and the file is opened by the same business's staff. | `app/Http/Controllers/Inventory/InventoryExportController.php` (row builder; `fputcsv(..., escape: '')`) | Two options for S8: (a) escape on export (prefix `'`) — but that corrupts the import round-trip (re-import would rename the product); or (b) reject leading `=`/`+`/`@` (and lone leading `-`) in name fields at validation time — product names never legitimately start with those. Prefer (b). | Open |
| F-17 | Security | Medium | Restore accepts **any valid SQLite file** (16-byte magic check only). Restoring a foreign-but-valid SQLite DB replaces the live database and bricks the app (login fails, no in-app path back to the pre-restore safety backup — recovery requires manual file copying, beyond a non-technical owner). | `app/Support/Backup/DatabaseBackupManager.php:139-141,168-180` | Before overwriting the live file, open the candidate read-only (PDO sqlite) and verify the expected schema is present (e.g. `users`, `transactions`, `settings` tables in `sqlite_master`); abort with a clear error if not. S8 implements + tests. | Open |

---

## S1 clean notes (audited, no finding warranted)

Scope: immutability enforcement, serial gap scenarios, ledger↔snapshot drift, single-write-path
bypass search (2026-07-18, baseline 364 tests green).

- **Serial rollback / crash:** clean. The serial is computed and claimed inside the same
  `DB::transaction` that inserts the sale ([PosController.php:130-141](../app/Http/Controllers/PosController.php),
  `persistSale`). There is no reserve-then-commit window: a rolled-back or crashed checkout
  never claims a number, so committed serials stay gapless by construction. Remaining exposure
  is test coverage (F-02) and count-vs-MAX self-healing (F-09).
- **Ledger single write path:** clean. Every `inventory_ledger` insert and every
  `stock_snapshots` write in `app/` lives in `StockLedger::record()` /
  `reconcile()`; the only callers of `record()` are `PosController` (sale),
  `StockAdjustmentController` (manual adjustment), `CsvInventoryImporter` (import), and
  `ProcessSaleReturn` (return/defect). No other code touches either table.
- **Bypass search:** clean. No `update()` / `delete()` / `DB::table(...)` **writes** against
  `transactions`, `transaction_items`, `inventory_ledger`, `sale_returns`,
  `sale_return_items`, `login_history`, or `audit_logs` outside their single write paths.
  `ReportsController`'s `DB::table('transaction_items')` uses are read-only aggregations.
- **Snapshot↔ledger disagreement semantics:** at runtime the snapshot wins everywhere (POS
  grid, lookup, export, item pages, and the never-negative check in `StockLedger::record()` all
  read the snapshot). Restore swaps the whole DB file, so both tables regress together and stay
  mutually consistent. No automatic drift source found; the unreachable repair path is F-11.
- **Audit write path:** `AuditRecorder::record()` is the single write path into `audit_logs`;
  the returns flow appends its audit row atomically inside the same transaction as the business
  write. The one asymmetry (POS records after commit) is F-13.

---

## S2 appendix — route × role authorization matrix

Verified against `routes/web.php`, `routes/settings.php`, `routes/auth.php` (2026-07-18).
Roles: **G**uest / **C**ashier / **A**dmin / **O**wner. ✓ = allowed, — = 403/redirect.

| Routes | G | C | A | O | Enforcement |
| --- | --- | --- | --- | --- | --- |
| `GET/POST login` | ✓ | — | — | — | `guest` middleware (authed users redirected) |
| `POST logout`, `confirm-password` | — | ✓ | ✓ | ✓ | `auth` |
| POS: `pos`, `pos/lookup`, `pos/checkout` | — | ✓ | ✓ | ✓ | `auth`; payload is cost-free by whitelist |
| Sales: `sales`, `sales/{t}`, `sales/{t}/print` | — | ✓ | ✓ | ✓ | `auth`; detail cost key Owner-gated in controller |
| `POST sales/{t}/returns` | — | ✓ return only | ✓ +defect | ✓ | `auth`; defect elevated to Admin+ **in controller** (by submitted type — deliberate, tested) |
| `login-history` | — | own rows only | all | all | `auth`; own-only scoping server-side |
| Inventory read: `inventory`, `categories`, `stock-history` | — | ✓ view-only | ✓ | ✓ | `auth`; cost stripped via model trait |
| Inventory writes, import/export, `stock.adjust` | — | — | ✓ | ✓ | `role:admin` |
| `reports`, `reports/export`, `audit-logs` | — | — | ✓ no cost/COGS/profit | ✓ | `role:admin`; cost keys omitted/redacted per gate |
| Users: `users*` | — | — | ✓ Cashiers only | ✓ Admin+Cashier | `role:admin` + per-action `UserPolicy` |
| `business-settings`, `printer-settings*`, `backup*` | — | — | — | ✓ | `role:owner` |
| Settings: `profile`, `password`, `appearance` | — | ✓ | ✓ | ✓ | `auth` — **but see F-15** (rename + self-delete) |

Spec check: Cashier = POS + Sales + view-Inventory + own Login History ✓; Admin = everything
except cost + Cashier-only user management ✓; Owner = full ✓. Routes protected only in the
controller (not middleware): defect elevation and `UserPolicy` actions — both deliberate,
documented in the route file, and covered by tests. No route relies solely on a FormRequest
`authorize()`.

## S2 appendix — cost-visibility surfaces (mechanism + test)

| Surface | Hiding mechanism | Test |
| --- | --- | --- |
| Inventory pages (items index/edit) | `HidesCostFromNonOwners` on `Variant` + `ItemRequest` write-guard | `InventoryCostAuthorizationTest`, `ItemManagementTest` |
| POS grid / scan lookup | `presentProduct()` whitelist (no cost key ever) | `PosGridTest` |
| POS checkout | prices/cost snapshotted server-side; client never sends/receives money | `CheckoutTest` |
| Sales detail (+ returns block) | `Gate::allows('view-item-cost')`, key absent for non-Owners | `SalesHistoryTest`, `SaleReturnTest` |
| Reports page + PDF export | `canViewCost` flag; COGS/profit keys omitted | `SalesReportTest` |
| CSV export | cost column dropped from header set + gate | `InventoryExportTest` |
| Audit-log payloads | recursive `redact()` strips `cost` at any depth | `AuditLogDisplayTest:35` (F-03 closed) |
| Receipts | receipts never carry cost | `ReceiptContentTest` |
| Shared Inertia props | `User` carries no cost fields; `password` is `$hidden`; no `remember_token` column exists | n/a |

Every surface has both a mechanism and a test — no new findings from the sweep.

## S2 clean notes

- **`/security-review` skill substitution:** the skill is diff-based and the only pending diff
  was S1's docs commit, so it had no product code to review. S2 ran the equivalent manually
  over the full codebase (authz matrix, cost sweep, injection checks, backup edges, config
  review above). S9 should run the skill over the accumulated S5–S8 diff instead, where it has
  a real diff to bite on.
- **Session/auth config:** `SESSION_DRIVER=database` (offline-safe), no cookie-domain exposure,
  password the only secret on `users` and `$hidden`; no `remember_token` column exists, so the
  shared `auth.user` Inertia prop leaks nothing.
- **Backup/restore:** path traversal blocked (`resolveBackupPath`), SQLite magic checked
  (foreign-file gap → F-17), pre-restore safety backup taken; backup files live in the app's
  own storage directory with default desktop-user permissions — fine for the threat model.
- **Old-backup restore / serial regression** was already logged as F-10 (S1).
- **CSV import:** validation confirmed solid per F-08; formula injection is an **export**-side
  issue only (F-16).

---

## S3 appendix — assumption dispositions

| Assumption | Disposition |
| --- | --- |
| Crash detection fails **open** (no boot id → session allowed) | **Accepted.** A boot id is minted on every native launch (`ApplicationBooted`); it is only absent in terminal/browser dev (where that event never fires) or after a cache wipe. Failing closed would force-logout on every request in dev — the open default is correct for this architecture. |
| Single-session enforcement under a simultaneous-login race | **Accepted.** The packaged app is single-window with one cookie jar — concurrent logins are structurally impossible there; `SessionManager::forceLogout` additionally evicts DB session rows defensively. The multi-browser dev scenario is out of scope. |
| Count-based serial vs `MAX(number)+1` | Already logged as **F-09** (Medium). |
| Snapshot as cache: stale UI → oversell? | **Accepted (safe by design).** The grid renders snapshot values that can go stale, but checkout re-validates under the snapshot row lock inside `StockLedger::record()` — an oversell attempt throws, rolls back atomically, and surfaces a clear error. No oversell is possible; stale display self-corrects on reload. |
| SQLite durability / power loss mid-checkout | Verified atomic under the default DELETE journal (transaction fully rolls back; serial/gapless and ledger/snapshot invariants hold). Runtime tuning + WAL/backup interaction → **F-19**. |
| Float money end-to-end | **Clean.** PHP `decimal:2` casts return strings; all arithmetic happens in integer centavos (`centavos()`/`pesos()` helpers in POS, returns, reports, receipts). Client-side cart math is display-only — the server recomputes everything. Fixture tests → S5 (F-07). |
| Unnamed-variant fallback | **Clean.** Backend labels all fall back to the item name (`presentProduct`, `SalesController::itemLabel`, `ReceiptContent::itemLabel`, `ProcessSaleReturn::soldLabel`); inventory UI renders `variant.name \|\| item.name`; CSV export's blank variant cell is the intended round-trip representation. |
| Report attribution rules | **Verified.** Gross/COGS attributed to the sale's date; returns/defects reduce the period the *event* happened in (matches the design doc); returned goods' cost credited back, defected goods' cost stays in COGS; un-costed sales split out so null costs never inflate profit — the costed/un-costed split reconciles to net by construction. Windows are inclusive `whereBetween` over `startOfDay`/`endOfDay`; boundary correctness in local time depends on **F-14**. |

## S3 appendix — money fixtures for S5 (hand-verified)

All values in centavos. Shares follow `linePaidShares` (proportional, last line absorbs the
rounding remainder); per-line refunds follow `lineRefund` (rounded share per event, final
outstanding units true up to the paid share exactly).

**Fixture A — 10% discount over 3 unequal lines, staggered partial returns**
Lines: A = 3 × 3335 = 10005, B = 1 × 9999, C = 2 × 5000 = 10000 → subtotal 30004.
Discount 10% → 3000 (30004 × 10% = 3000.4 rounds to 3000); total **27004**.
Paid shares: A = 9005, B = 8999, C = 9000 (sum = 27004 exactly).
Returning A one unit at a time: refunds 3002, 3002, then final 3001 — cumulative 9005 exactly.

**Fixture B — float-classic 0.10 + 0.20**
Lines: 1 × 10 + 1 × 20 → total 30. Report gross must be exactly 0.30 (no 0.30000000000000004).

**Fixture C — percentage discount with sub-cent result**
1 line: 3 × 33 = 99; discount 33.33% → round(32.9967) = 33; total **66**.
Three single-unit returns: 22, 22, 22 (cumulative 66 exactly).

**Fixture D — amount discount ₱100.00 over ₱300.05**
Lines: A = 10000, B = 10000, C = 10005 → subtotal 30005; discount 10000 → total **20005**.
Paid shares: A = 6667, B = 6667, C = 6671 (sum = 20005 exactly). Full return of C refunds 6671.

End-to-end assertion for each: checkout stores the exact totals above; returns refund the exact
shares; the report period covering the events shows gross/returns/net matching these numbers to
the centavo, and profit only over costed lines.
