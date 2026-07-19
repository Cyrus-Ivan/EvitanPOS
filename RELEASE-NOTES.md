# Evitan POS — Release Notes

## v1.1.0 (unreleased)

- **Initial setup wizard.** A fresh install no longer ships a seeded
  `owner` / `password` account. On first launch the app opens a pre-login
  wizard that creates the Owner account (your own name, username, and
  password), the business identity (name, address, TIN, receipt footer), the
  business timezone and currency symbol, and — optionally, skippable — the
  receipt printer. One atomic submit; the app then signs you straight in.
  Existing installs and restored backups never see the wizard.
- **New settings: timezone & currency symbol.** Both are editable later under
  Business Settings and apply across reports, receipts, and the POS. Defaults:
  `Asia/Manila` and `₱`.
- Forced password change remains for accounts whose password an admin reset.

### First launch (v1.1.0+)

1. The app creates its database automatically and opens the setup wizard.
2. Create your Owner account and enter your business details.
3. Configure the printer now or skip it — it lives in Business Settings.
4. You land signed in on the POS screen; add categories, items, and stock.

## v1.0.0 (2026-07-19)

First production release. **Linux only** (AppImage + Debian package), built with
NativePHP (Electron) over Laravel 12 + React 19 + SQLite.

### What's in the box

- **Point of sale** — product grid with search (name / SKU / barcode), barcode
  scanner support, cart with quantity editing, amount/percentage discounts,
  cash / cashless / others payment types, cash-received with automatic change,
  keyboard checkout (F9 / Enter). Out-of-stock products stay visible but
  disabled; inventory can never go negative.
- **Ledger-based inventory** — categories, items, and variants; every stock
  movement (sales, returns, manual adjustments, CSV imports) is an append-only
  ledger entry; snapshots are re-checked against the ledger on every launch.
- **CSV import / export** — bulk catalog upsert by SKU with replace/add stock
  modes, template download, validation, and upload history.
- **Immutable sales history** — snapshotted prices/costs, and a continuous,
  gapless, zero-padded transaction serial that doubles as the receipt number
  (BIR-compliant shape). Immutability is enforced by database triggers.
- **Returns & defects** — partial and multiple returns (restore stock), defect
  write-offs (no stock restore), cent-exact discount-aware refunds.
- **Receipt printing** — driverless ESC/POS to USB / Bluetooth / network / OS
  print queue, 58mm and 80mm, auto-print, cash-drawer kick, reprint. A print
  failure never cancels a completed sale.
- **Reports** — daily / weekly / monthly / yearly with drill-down buckets,
  stacked-by-item net-sales chart, PDF export. Totals always recomputed as
  sales − returns − defects; profit/COGS are Owner-only.
- **Admin & ops** — user management (Owner > Admin > Cashier), login history
  with crash detection, immutable audit logs, Owner-only database backup and
  restore (including to/from external drives) with automatic safety backups.

### Installing

- **AppImage:** `chmod +x evitan-pos-1.0.0.AppImage && ./evitan-pos-1.0.0.AppImage`
- **Debian/Ubuntu:** `sudo dpkg -i evitan-pos_1.0.0_amd64.deb`

### First launch

1. The app creates its database and seeds the initial account automatically.
2. Sign in with **`owner` / `password`**.
3. You will be required to **set a new password** before anything else works.
4. Configure the business identity (name, address, TIN, receipt footer) and
   printer under Business Settings, then add categories, items, and stock.

### Where your data lives

Everything is stored per user account under `~/.config/evitan-pos/`:

| What | Where |
| --- | --- |
| Database | `~/.config/evitan-pos/database/database.sqlite` |
| Backups | `~/.config/evitan-pos/storage/backups/` |
| Logs (rotating, 3 days) | `~/.config/evitan-pos/storage/logs/` |
| Per-install encryption key | `~/.config/evitan-pos/storage/app/app.key` |

Back up regularly from **Database Backup** — ideally to an external drive.
After restoring a backup, restart the app when prompted: migrations and a
stock-ledger reconcile run on launch to finish the restore.

### Known limitations

- **Single terminal, single store.** Multi-branch and cloud sync are out of
  scope (the schema is sync-ready for a future version).
- **BIR ATP series is not configurable.** The receipt serial is continuous and
  gapless, but a real Authority-To-Print registration needs a configurable
  series prefix and starting serial — planned, not built.
- **Raw-USB printers without a device file** (no `/dev/usb/lp*`, no OS queue)
  are not supported yet; use USB-as-device-file, Bluetooth, network, or the OS
  print queue. Test your printer with **Business Settings → Test print**
  before going live.
- **Timezone is fixed to Asia/Manila** (receipts, report buckets, daily
  sequences).
- **No auto-update.** Install new releases manually over the old one — your
  data directory is preserved.
- **Restoring an older backup** permanently discards sales made after it was
  created, and their printed receipt serials will be reissued to future sales.

### Manual release checklist (per install)

- [ ] Physical receipt printer: configure transport, run a test print, one
      real sale print, one reprint, cash-drawer kick.
- [ ] First-launch flow: initial setup wizard completed (v1.0.0: forced owner
      password change).
- [ ] Backup created and restored once; app restarted after restore.
