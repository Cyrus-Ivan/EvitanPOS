# ROADMAP.md

# Evitan POS MVP Development Roadmap

This roadmap prioritizes building a **working application as early as possible**, while minimizing refactoring. Each phase should produce a usable milestone before moving to the next.

---

# Phase 0 — Project Initialization

## Goal

Set up the project foundation.

## Tasks

* Create Laravel NativePHP project
* Install React starter
* Configure SQLite
* Configure UUID generation
* Enable SQLite foreign keys
* Create project structure
* Configure routing
* Create layouts
* Create navigation sidebar
* Create reusable UI components
* Configure dark/light theme if desired
* Create notification/toast system
* Create modal component
* Create confirmation dialog component

**Milestone**

Application launches with navigation.

---

# Phase 1 — Database Foundation

## Goal

Build every database table before implementing business logic.

## Tables

* Users
* Categories
* Items
* Variants
* Transactions
* Transaction Items
* Inventory Ledger
* Audit Logs
* Login History
* Import History
* Settings

Implement

* UUIDs
* Soft Deletes
* Foreign Keys
* Indexes
* Constraints

**Milestone**

Database schema complete.

---

# Phase 2 — Authentication

## Goal

Users can securely access the application.

## Features

* Login
* Logout
* Password hashing
* Session management
* Single logged-in user
* Crash detection
* Forced logout detection
* Login history
* Password change

Implement

* Owner
* Admin
* Cashier

Seed default Owner account.

**Milestone**

Secure authentication working.

---

# Phase 3 — Authorization

## Goal

Protect every page.

Implement role permissions.

Cashier

* POS
* Sales
* View Inventory
* Own Login History

Admin

* Everything except Item Cost
* User Management (Cashier only)

Owner

* Full Access

Protect

* Routes
* Components
* Buttons
* Actions

**Milestone**

Permissions complete.

---

# Phase 4 — Settings

## Goal

Owner can configure the business.

Features

* Business Name
* Address
* TIN
* Receipt Footer

Persist in SQLite.

**Milestone**

Business identity configured.

---

# Phase 5 — Inventory Foundation

## Goal

Manage products.

Build

Categories

Items

Variants

Features

* Create
* Edit
* Archive
* Restore
* Search
* Pagination
* Filters

Rules

* SKU uniqueness
* Barcode uniqueness
* Owner-only Item Cost

**Milestone**

Inventory management complete.

---

# Phase 6 — Ledger System

## Goal

Implement inventory computation.

Build

Inventory Ledger

Stock Snapshot

Functions

* Compute stock
* Update snapshot
* Prevent negative inventory
* Stock history page

Implement

* Adjustment entries
* Sale entries
* Return entries
* Defect entries

Return/void entries restore stock; defect entries are 0-movement audit rows. See `docs/returns-refunds-voids-design.md`.

Ledger becomes append-only.

**Milestone**

Inventory calculations complete.

---

# Phase 7 — Inventory Import / Export

## Goal

Bulk inventory management.

Features

Download Template

CSV Upload

CSV Export

Upload History

Validation

Support

* Replace Stock Count
* Add To Current Stock

Generate Ledger entries automatically.

**Milestone**

Inventory migration tools complete.

---

# Phase 8 — POS

## Goal

Cashier can sell products.

Build

Product Grid

Cart

Search

Barcode Scanner

Quantity Editor

Discount

Payment Type

Cash Received

Automatic Change

Checkout

Rules

* No negative inventory
* Out of stock disabled

**Milestone**

Products can be sold.

---

# Phase 9 — Transactions

## Goal

Store completed sales.

Create

Transactions

Transaction Items

Generate

Transaction Number

Receipt Number

Store

Historical Selling Price

Historical Item Cost

Historical Discounts

Generate Ledger entries.

`transaction_items` holds sale lines only; `transactions` and `transaction_items` are immutable (append-only, no `updated_at`). Returns/voids/defects live in `sale_returns` — see `docs/returns-refunds-voids-design.md`.

**Milestone**

Sales become permanent.

---

# Phase 10 — Returns, Refunds, Voids & Defects

## Goal

Support after-sales workflow.

**Implement per `docs/returns-refunds-voids-design.md` (authoritative schema + rules).**

Features

Partial Return

Multiple Return

Void

Defect

Validation

* Never exceed sold quantity

Returns

* Restore stock

Voids

* Untouched transactions only; reverse all lines

Defects

* Do not restore stock

Reports compute totals using returns.

**Milestone**

Returns workflow complete.

---

# Phase 11 — Receipt Printing

## Goal

Physical receipt support.

Support

58mm

80mm

Auto print

Open cash drawer

Printing failures

* Never cancel sale

Reprint

**Milestone**

Printing complete.

---

# Phase 12 — Reports

## Goal

Basic business reporting.

Implement

Daily Sales

Monthly Sales

Reports must compute

Sales

Returns

Defects

instead of relying on stored totals.

**Milestone**

Reports complete.

---

# Phase 13 — User Management

## Goal

Manage employees.

Features

Create User

Archive User (soft delete; restorable)

Reset Password (also signs the target out — forced_logout)

Update Role (name is immutable after creation)

Permissions

Owner

* Admin
* Cashier

Admin

* Cashier

**Milestone**

Employee management complete.

---

# Phase 14 — Login History

## Goal

Audit authentication.

Display

Login

Logout

Crash

Forced Logout

Power Loss

Cashier

Own History

Admin

Everyone

Owner

Everyone

**Milestone**

Authentication auditing complete.

---

# Phase 15 — Audit Logs

## Goal

Track important actions.

Log

User Changes

Inventory Changes

Price Changes

CSV Imports

Backups

Restores

Settings

Transactions

Password Reset

Audit logs are immutable.

**Milestone**

Business audit complete.

---

# Phase 16 — Database Backup

## Goal

Protect business data.

Owner Only

Backup SQLite

Restore SQLite

Automatic backup before restore.

**Milestone**

Recovery complete.

---

# Phase 17 — UI Polish

## Goal

Improve usability.

Review

Loading states

Skeletons

Empty states

Error pages

Confirmation dialogs

Success notifications

Validation messages

Keyboard navigation

Keyboard shortcuts

Responsive layouts

Accessibility

**Milestone**

Production-ready interface.

---

# Phase 18 — Testing

## Goal

Verify the MVP.

Authentication

Inventory

Ledger

Sales

Returns

Printing

CSV Import

Reports

Permissions

Backup

Restore

Negative inventory

Archived records

Crash recovery

Receipt generation

Audit logs

**Milestone**

Feature complete.

---

# Phase 19 — Release Preparation

## Goal

Prepare production build.

Review

Application Icon

Installer

Version Number

Database Migration

Default Owner Account

Printer Testing

SQLite Optimization

Build NativePHP Executable

Release Notes

Create first production release.

---

# Development Order Summary

```
Project Setup
        │
        ▼
Database Schema
        │
        ▼
Authentication
        │
        ▼
Authorization
        │
        ▼
Settings
        │
        ▼
Inventory
        │
        ▼
Inventory Ledger
        │
        ▼
CSV Import / Export
        │
        ▼
POS
        │
        ▼
Transactions
        │
        ▼
Returns & Defects
        │
        ▼
Receipt Printing
        │
        ▼
Reports
        │
        ▼
User Management
        │
        ▼
Login History
        │
        ▼
Audit Logs
        │
        ▼
Backup & Restore
        │
        ▼
UI Polish
        │
        ▼
Testing
        │
        ▼
Production Release
```

---

# Guiding Principle

Each phase should leave the application in a usable state. Avoid starting multiple major modules simultaneously. Complete one feature end-to-end—including database, backend, frontend, validation, permissions, audit logging, and tests—before moving to the next. This incremental approach reduces regressions, makes debugging easier, and results in a stable MVP throughout development rather than only at the end.

