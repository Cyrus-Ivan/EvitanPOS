# Evitan POS

**Evitan POS** is an offline-first retail Point of Sale desktop app for a single business installation. It runs entirely on the local machine — no server, no cloud, no network dependency — and packages Laravel + React into a native desktop executable via NativePHP.

The schema stays sync-compatible (UUID primary keys everywhere) so multi-branch and cloud sync remain possible later, but they are explicitly **out of scope** for the MVP.

## Highlights

- **Point of sale** — product grid and search (name / SKU / barcode), barcode scanner support, cart with quantity editing, amount/percentage discounts, cash / cashless / others payment types, cash-received and automatic change, and an optional checkout comment. Out-of-stock products stay visible but disabled.
- **Ledger-based inventory** — categories, items, and variants with an **append-only** inventory ledger as the single source of stock truth. Current stock is ledger-sum reconciled against a snapshot; **negative inventory is never allowed**. Low-stock thresholds highlight rows that need restocking.
- **CSV import / export** — bulk catalog upsert by SKU with "replace stock count" and "add to current stock" modes, downloadable template, validation messages, and upload history. Every import funnels through the ledger.
- **Immutable sales history** — completed transactions and line items never change. Selling price and item cost are snapshotted at time of sale, so later price edits never rewrite past sales. The transaction number is a continuous, gapless, zero-padded running serial (`0000000001`, never resets — the BIR-compliant shape) that doubles as the receipt number.
- **Returns & defects** — partial and multiple returns (restore stock) and defect write-offs (do not restore stock), recorded in a dedicated `sale_returns` family that references the original sale without ever mutating it.
- **Receipt printing** — driverless ESC/POS straight to the device (USB / Bluetooth / network socket), 58mm and 80mm, auto-print, cash-drawer kick, and reprint. A printing failure **never** rolls back a completed sale.
- **Reports** — daily / weekly / monthly / yearly sales on one page with drill-down buckets, a stacked-by-item net-sales chart, and PDF export. Totals are always recomputed from sales minus returns minus defects; COGS and profit are Owner-only.
- **Roles & permissions** — Owner > Admin > Cashier, enforced at routes, actions, and UI. **Item cost is Owner-only** everywhere, including reports and API responses.
- **Admin & ops** — user management (create / archive / reset password / change role), login history (login, logout, crash, forced logout, power loss), an immutable audit log of every significant action (Admin+ view, cost redacted for non-Owners), and Owner-only SQLite backup / restore — including to and from an external drive — with an automatic safety backup before every restore.

## Tech stack

- **Laravel 12** (backend) — pinned to 12 for NativePHP compatibility.
- **React 19 + TypeScript** over **Inertia**, from the Laravel React starter kit.
- **Tailwind CSS 4** + **shadcn/ui** components.
- **NativePHP (Electron)** to package the app as a native desktop executable.
- **SQLite** as the only database (`database/nativephp.sqlite`), with foreign-key enforcement on.

## Requirements

- PHP **8.2+** and Composer
- Node.js **24** and npm
- SQLite

## Getting started

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (creates/migrates database/nativephp.sqlite)
php artisan migrate
```

Run the app in development:

```bash
# Web dev — Laravel server + queue + logs + Vite, all at once
composer dev

# Desktop dev — NativePHP (Electron) shell + Vite
composer native:dev
```

Default development login: **`owner` / `password`**.

## Common commands

```bash
php artisan test          # Pest / PHPUnit test suite
npm run lint              # ESLint (auto-fix)
npm run format            # Prettier
./vendor/bin/pint         # Laravel Pint (PHP formatting)
npm run build             # Production frontend build
php artisan native:build  # Build the native desktop executable
```

## Architecture & invariants

A few non-negotiable rules shape the entire codebase — violating them is a correctness bug, not a style choice:

- **Immutable business history** — historical transactions, transaction items, captured prices/costs, the inventory ledger, audit logs, and login history are append-only and never edited.
- **Never delete business records** — categories, items, and variants are **archived**; users are **soft-deleted**. Archived entities stay visible in all historical records.
- **Inventory is ledger-derived** — there is no stock-adjustment table; every movement (sales, returns, defects, manual adjustments, CSV imports) is a ledger entry. Stock never goes negative.
- **UUID primary keys everywhere** — human-readable transaction numbers are a separate value.
- **Permissions enforced at every layer** — exactly one Owner (never archivable/deletable); item cost is Owner-only.
- **Printing never rolls back a sale** — the sale commits, the user sees a clear error, and the receipt can be reprinted.

Deeper documentation lives in the repo:

- [`BRAINSTORM.md`](BRAINSTORM.md) — full functional specification
- [`ROADMAP.md`](ROADMAP.md) — phased build plan
- [`CLAUDE.md`](CLAUDE.md) — invariants and working guidance
- [`docs/`](docs/) — after-sales and receipt-printing design of record

## Project status

MVP under active development, building the `ROADMAP.md` phases in order. **Feature-complete through Phase 16**: the full commerce chain (Setup, DB schema, Auth, Authorization, Settings, Inventory, Ledger, CSV Import/Export, POS, Transactions, Returns/Defects, Printing, Reports) and the full admin/ops track (User Management, Login History, Audit Logs, Backup/Restore). Remaining: UI Polish, Testing, and Release Preparation (Phases 17–19).

This summary is approximate — confirm current progress against `git log` and the code.

## License

This codebase is **private** and not licensed for redistribution. It is derived from the [Laravel + React starter kit](https://laravel.com/docs/starter-kits), which is open-source under the MIT license.
