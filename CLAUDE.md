# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack & Environment

- **Language/Runtime:** PHP (server-rendered pages) + vanilla JS frontend, served by XAMPP on Windows.
- **DB:** MySQL/MariaDB. Connection settings in [config.php](config.php): `localhost` / db `phan-mem-rap-may` / user `root` / empty password. PDO is exposed as `$pdo`.
- **Dependency:** Only `phpoffice/phpspreadsheet` (Composer). Install with `composer install` if `vendor/` is missing.
- **DB schema:** Import [sql/phan-mem-rap-may.sql](sql/phan-mem-rap-may.sql) via phpMyAdmin to seed tables + sample users.
- **Run:** Open `http://localhost/phan-mem-rap-may/dang-nhap.php` after starting Apache + MySQL in XAMPP. There is no build step, test runner, or linter — edit PHP/JS/CSS and refresh the browser.
- **Seeded users** (auto-inserted on first login if `users` table is empty, see [dang-nhap.php](dang-nhap.php)): `ketoan/123456`, `kythuat/123456`, `admin/admin123`.

## Domain Concepts (Vietnamese)

This is a PC-assembly workflow tool. Two roles collaborate on `donhang` (orders):

- **Kế toán (accountant)** creates orders ([ke-toan-tao-don.php](ke-toan-tao-don.php)) defining one or more `cấu hình` (configurations), each with a quantity of machines (`so_luong_may`) and the parts list (`linhkien`).
- **Kỹ thuật (technician)** picks an order from [dashboard-ky-thuat.php](dashboard-ky-thuat.php) → [nhap-serial.php](nhap-serial.php), scans/enters serial numbers per part, then later assigns each scanned serial to a specific machine slot. State is locked per `(order, machine, config)` so two technicians don't claim the same machine.

Key tables — see [sql/phan-mem-rap-may.sql](sql/phan-mem-rap-may.sql):
- `donhang` — order header (`id_donhang`, `ma_don_hang`, `ten_khach_hang`, `so_luong_may`).
- `chitiet_donhang` — every part instance (one row per serial slot). `ten_cauhinh` may be a comma-joined string when a part is shared across configs. The `imei` column is added at runtime by [xuat-file.php](xuat-file.php) if it doesn't exist — do not rely on it being present in the schema file.
- `trang_thai_lap_may` — per-machine lock (`id_donhang`, `so_may`, `config_name`, `user_id`); unique key on `(id_donhang, so_may, config_name)`. A trigger on `chitiet_donhang` DELETE removes the matching lock row.
- `users` — auth + role (`ketoan` | `kythuat` | `admin`).

### The "Space Hack" for shared components

When one physical part is shared across multiple configs (e.g. a MAIN used by both `Cấu hình 1` and `Cấu hình 2`), `ten_cauhinh` is stored as `"Cấu hình 1, Cấu hình 2"` and **trailing spaces encode the owner index**: 0 trailing spaces → first config owns it, 1 trailing space → second, etc. See `get_owner_config()` in [luu-serial-db.php](luu-serial-db.php#L27) and the mirror logic in [xuat-file.php](xuat-file.php). When editing code that touches `ten_cauhinh`, **preserve trailing whitespace** — `trim()` will break ownership.

## Authentication & Authorization

- Session-based. [dang-nhap.php](dang-nhap.php) sets `$_SESSION['user_id'|'username'|'fullname'|'user_role']`.
- All UI pages include [thanh-dieu-huong.php](thanh-dieu-huong.php) (the nav shell), which itself `require`s [phan-quyen.php](phan-quyen.php) — that is where the gate lives. Unauthenticated users are redirected to `dang-nhap.php`; logged-in users get role-checked against `$allowed_kythuat` / `$allowed_ketoan` whitelists.
- **To expose a new page to a role, add its filename to the matching `$allowed_*` array in [phan-quyen.php](phan-quyen.php)** and to the sidebar in [thanh-dieu-huong.php](thanh-dieu-huong.php) (wrap with `isAuthorized('your-page.php')`).
- Files whose names start with `ajax-`, `luu-`, or `xoa-` are exempt from the page whitelist (treated as backend XHR endpoints — they still require a valid session via `$_SESSION['user_id']`). `check_*.php`, `update_*.php`, and `fix_*.php` are also exempt.
- `admin` bypasses all checks. `hasPermission('kythuat')` returns true for both `kythuat` and `ketoan`.
- `isScanVerified()` currently always returns `true` — the second-factor scan-auth flow in [xac-thuc-quet-ma.php](xac-thuc-quet-ma.php) is bypassed by design (commented in [phan-quyen.php](phan-quyen.php)).

## Page / Endpoint Conventions

- **UI pages** (`*.php` at repo root) start with `require "thanh-dieu-huong.php";` (which pulls in auth + the nav), then `require "config.php"` for DB. They emit full HTML with inline `<?php ?>` and load matching `./css/<page>.css` + `./js/<page>.js`. There is no template engine and no router.
- **AJAX endpoints** (`ajax-*.php`, `luu-*.php`, `xoa-*.php`, `check_*.php`, `update_*.php`, `fix_*.php`) start with `session_start(); require "config.php"; header('Content-Type: application/json');` and return JSON via `echo json_encode([...])`. They typically read input from `$_POST` or `json_decode(file_get_contents('php://input'), true)`. Always check `$_SESSION['user_id']` before doing work.
- **Filenames are the URL.** The role gate in [phan-quyen.php](phan-quyen.php) keys off `basename($_SERVER['PHP_SELF'])` — renaming a file is a routing change and must be updated in `$allowed_*` and any links.

## Key Pages & Endpoints

**Accountant flow:**
- [ke-toan-tao-don.php](ke-toan-tao-don.php) — Create order (config groups + parts). Saves via POST to [luu-don-hang.php](luu-don-hang.php).
- [dashboard-ke-toan.php](dashboard-ke-toan.php) — Order list with status tabs; multi-select delete.
- [check-quality.php](check-quality.php) — QA verification; view assembly progress per order.
- [xuat-file.php](xuat-file.php) — Export to Excel (PhpSpreadsheet); implements the pooling/assignment algorithm that maps serials to machine slots.

**Technician flow:**
- [dashboard-ky-thuat.php](dashboard-ky-thuat.php) — Order queue (only orders with all serials entered appear here).
- [nhap-serial.php](nhap-serial.php) — Enter/scan serials per component. Auto-saves to `localStorage` (debounced 700 ms) and persists to DB via [luu-serial-db.php](luu-serial-db.php).
- [kho-hang.php](kho-hang.php) — Inventory/stock view.
- [kho-import-serial.php](kho-import-serial.php) — Bulk import serials from Excel.

**Locking:** [ajax-handle-lock.php](ajax-handle-lock.php) implements pessimistic per-machine locks (`check` / `acquire` / `release` actions). Config name matching uses `preg_replace` to normalize Vietnamese NFC/NFD, so string comparison is safe across keyboard inputs.

## Scanner Integration

The QR/barcode scanner is an **external** FastAPI service documented in [API_DOCUMENT.md](API_DOCUMENT.md) (base `http://localhost:9003`, or production `https://scanninh.rosaoffice.com`). It uses httponly cookies (`access_token` 30 min + `refresh_token` 30 days), so frontend `fetch` calls must use `credentials: "include"`.

[scanner-proxy.php](scanner-proxy.php) is a same-origin reverse proxy to `scanninh.rosaoffice.com` — exists to dodge CORS/cookie issues when running on localhost. JS that talks to the scanner should go through `scanner-proxy.php?path=...` rather than calling the upstream directly. Proxy debug log: [proxy_debug.txt](proxy_debug.txt).

## Logging & Debugging

- [luu-serial-db.php](luu-serial-db.php) appends every call to [debug_log.txt](debug_log.txt) (request body + HTTP_REFERER). Useful for tracing why a save misfired.
- [scanner-proxy.php](scanner-proxy.php) appends to `proxy_debug.txt`.
- Both files grow unbounded — truncate manually if they get large.
- [scratch/](scratch/) contains one-off debug/migration scripts (`debug_imei.php`, `debug_locks.php`, `test_db.php`, etc.) — not part of the production flow.
