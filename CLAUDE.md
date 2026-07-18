# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

The Laravel + React (Inertia) + NativePHP + SQLite app is **feature-complete through Phase 18** of `ROADMAP.md`: the full commerce chain (Setup, DB schema, Auth, Authorization, Settings, Inventory, Ledger, CSV Import/Export, POS, Transactions, Returns/Defects, Receipt Printing, Reports incl. PDF export), the full admin/ops track (User Management, Login History, Audit Logs, Database Backup/Restore incl. external drive), the UI polish pass, and the **Phase 18 final review** (396 backend tests + 9 Playwright E2E specs, invariants now enforced by DB triggers + model guards, business timezone Asia/Manila, all findings closed — see `docs/phase-18-findings.md`). Only **Phase 19 (Release Preparation)** remains — start from the **Phase 19 handoff list** at the top of `docs/phase-18-findings.md`. Void was dropped from Phase 10 — after-sales events are returns and defects only. Per-phase implementation notes live in the agent memory index, not here — treat this paragraph as approximate and confirm current progress against `git log` and the code.

`BRAINSTORM.md` is the full functional spec and `ROADMAP.md` is the phased build plan. `docs/returns-refunds-voids-design.md` (after-sales) and `docs/receipt-printing-design.md` (printing) are the designs of record for their areas — where `BRAINSTORM.md`/`ROADMAP.md` disagree with them, the design docs win.

Note: the product is named **Evitan POS**. Use "Evitan POS" for user-facing names unless told otherwise.

## What this is

Evitan POS is an **offline-first retail Point of Sale** desktop app for a **single business installation**. No server, no cloud, no network dependency. Multi-branch and cloud sync are explicitly out of scope for the MVP but the schema should stay sync-compatible (hence UUIDs everywhere).

## Stack

- **Laravel 12** (backend) + **React 19 / TypeScript** (frontend) over **Inertia**, from the Laravel React starter kit. Pinned to Laravel 12 for NativePHP compatibility.
- **NativePHP** to package it as a native desktop executable.
- **SQLite** as the only database (`database/nativephp.sqlite`, shared by terminal artisan and the NativePHP runtime). Foreign key enforcement is on.

Standard Laravel workflow applies: `php artisan migrate` (or `migrate:fresh --seed`), `php artisan test` (Pest/PHPUnit), `npm run dev` / `npm run build`, `npm run lint`, `./vendor/bin/pint`, and NativePHP's `php artisan native:serve` / `native:build`. Dev login is `owner` / `password`. Verify script details in `composer.json` / `package.json` if in doubt.

## Non-negotiable invariants

These rules span the entire codebase and are the reason the architecture looks the way it does. Violating them is a correctness bug, not a style choice. Full detail is in `BRAINSTORM.md`.

**Immutable business history**
- Historical transactions, transaction items, and their captured prices **never change**. Selling price / item cost edits must not retroactively alter past sales — prices and costs are snapshotted onto each transaction item at time of sale.
- The inventory ledger is **append-only**. Never edit or delete a ledger row. Corrections are new compensating entries.
- Audit logs and login history are immutable and append-only.

**Never delete business records**
- Categories, Items, Variants → **archived** (flag), not deleted. Hidden from default lists, still visible via Archived filter and in all historical records.
- Users → **soft deleted**. Archived users must still appear in Sales, Login History, and Audit Logs.

**Inventory is ledger-derived**
- There is **no stock-adjustment table**. All stock movement — sales, returns, defects, manual adjustments, CSV imports — is expressed as inventory ledger entries.
- Current stock = ledger sum reconciled against a stock snapshot that is updated on every ledger insert.
- **Negative inventory is never allowed.** Enforce at write time.
- Returns restore stock (new positive ledger entry); **defects do not** restore stock.

**Identity & keys**
- Every table uses a **UUID primary key**. The transaction number is a separate human-readable value: a **continuous, gapless, zero-padded running serial** (`0000000001`, never resets — the BIR-compliant CRM/POS shape) that serves as both the internal transaction id and the customer-facing receipt number (there is no separate `receipt_number` column).
- Uniqueness: username unique; **Item name unique** (case-insensitive, spanning archived items); SKU **required**, unique, and stored uppercase; barcode unique *when provided* (blank barcode is allowed and must not block selling). **Variant names are not unique** — different items may reuse "Small"/"Large".
- Every Item must have **at least one Variant**. Stock, price, SKU, and barcode live on the Variant, not the Item. A single-product item keeps exactly one **unnamed** variant (`name` is null) — the "no variant" case; UI and reports fall back to the item name.

**Roles & permissions** (Owner > Admin > Cashier)
- Exactly **one Owner**; the Owner can never be archived or deleted.
- **Item Cost is Owner-only** to view and edit — Admins and Cashiers must never see it, including in reports and API responses. Cashiers get view-only inventory and cannot see cost.
- Enforce permissions at every layer: routes, actions, components, and buttons.

**Point of sale**
- Printing and cash-drawer opening happen after checkout but a **printing failure must never cancel or roll back a completed sale** — the sale commits, the user sees a clear error, and the receipt can be reprinted.
- Out-of-stock products stay visible but disabled.
- Discounts: amount (capped at transaction total) or percentage (capped at 100%). Split payments are out of scope.

**Reporting**
- Reports **recompute** totals from sales minus returns minus defects. Never trust a single stored total for reporting.

**Auditability**
- Every significant action (auth events, user/inventory/price changes, imports/exports, backups, restores, settings changes) writes an audit log entry.

## Build order

Follow `ROADMAP.md` phases in sequence. The guiding principle is to complete each feature **end-to-end** (migration → backend → frontend → validation → permissions → audit logging → tests) before starting the next, keeping the app runnable at every phase. Do not open several major modules at once. Order: Setup → DB schema → Auth → Authorization → Settings → Inventory → Ledger → CSV Import/Export → POS → Transactions → Returns/Defects → Printing → Reports → User Management → Login History → Audit Logs → Backup/Restore → Polish → Testing → Release.
