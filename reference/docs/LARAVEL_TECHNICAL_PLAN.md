# Rencana Migrasi & Development ke Laravel — Mining Accounting Dashboard

Versi: 1.0 — 30 Oktober 2026
Status: PLANNING (belum dieksekusi)
Referensi: [FORMULA_DASHBOARD.md](FORMULA_DASHBOARD.md) · [IT_HANDOVER.md](IT_HANDOVER.md)

---

## 1. Ringkasan Eksekutif

Migrasi dashboard akuntansi pertambangan dari **Node.js murni (server.js)** ke **Laravel 11 (PHP 8.2+)**, target deployment **shared hosting**, **tanpa autentikasi/otorisasi login** pada fase awal.

- **Scope**: konversi seluruh fitur dashboard (7 tab), mempertahankan 100% logika perhitungan akuntansi.
- **Non-scope (fase awal)**: autentikasi, role/permission, multi-user locking, queue/background worker, Redis.
- **Estimasi total**: 15–22 hari kerja (1 developer) atau 8–12 hari (2 developer paralel).
- **Prinsip utama**: hasil perhitungan Laravel **harus identik** dengan Node.js — divalidasi lewat paralel run & diff otomatis.

---

## 2. Keputusan Arsitektur

| Aspek | Keputusan | Alasan |
|---|---|---|
| Framework | Laravel 11 (PHP 8.2+) | Target user; LTS-ish, blade, Eloquent |
| Database | MySQL (shared hosting) | Data GL & metrik harus relasional |
| Sistem penjadwalan | Tidak ada (manual trigger) | Shared hosting tak punya scheduler andal |
| Queue | Laravel `sync` driver | Tak butuh worker daemon |
| Cache | `file` driver | Fallback default shared hosting |
| Session/State | `database` atau `file` | Tanpa Redis |
| Frontend | Blade + asset JS/CSS yang sudah ada | Frontend vanilla sudah matang; dioptimalkan bertahap |
| Excel | `maatwebsite/excel` (PhpSpreadsheet) | Pengganti `scripts/xlsx_parser.js` |
| HTTP client | Laravel `Http` facade | Pengganti `fetch()` untuk Google Sheets |
| Parsing angka | `NumberFormatter` + parser kustom porting dari `gl_import.js` | Format akuntansi (-1bakumi, (1.234,56), USD/Rp prefix) harus identik |

**File JSON yang dipertahankan (tetap file, bukan DB):**
- Semua file history/audit (`cost_report_history.json`, `mining_capitalization_history.json`, `cost_report_adjustments.json`, `inventory_historical_costs.json`, dll) → `storage/app/data/` read-only
- `config.json` → dipindah ke tabel `system_configs` atau `.env` + tabel

**Data yang pindah ke MySQL:**
- `gl_data.json` → tabel `gl_entries`
- `production_daily_sheets.json` → tabel `monthly_metrics` + `daily_production`
- `inventory_model.json` → tabel `inventory_openings`
- `financial_baseline.json` → tabel `financial_baselines`
- `cost_matrix_definition.json` → **tabel `cost_accounts`** (master COA di DB, seeder dari JSON sebagai sumber seed awal; file HTML tetap dibagikan sebagai referensi)
- `accruals.json` → tabel `accruals`
- `cost_variance_remarks.json`, `db_cost_remarks.json` → tabel `remarks`

---

## 3. Struktur Database (14 Tabel)

```sql
-- === DATA TRANSAKSI ===
gl_entries            -- dari gl_data.json (29 kolom D365)
gl_import_batches     -- riwayat import (audit sederhana)

-- === DATA PRODUKSI ===
monthly_metrics       -- dari production_daily_sheets.json db.metrics (coalGetting, hauling, dst per bulan)
daily_production      -- dari sheet Produksi (harian per rute)
db_cost_summary       -- dari db.costSummary (aktivitas + entitas per bulan)

-- === KONFIGURASI ===
system_configs        -- dari config.json (key-value; clientSecret dihilangkan/ENV)
financial_baselines   -- dari financial_baseline.json

-- === INVENTORY ===
inventory_openings    -- dari inventory_model.json (8 lokasi)
inventory_movements   -- dari inventory_movements.json per lokasi/bulan (opsional fase lanjut)

-- === AKRUAL & CATATAN ===
accruals              -- dari accruals.json (manual) + otomatis
accrual_history       -- dari automatic_accrual_history.json

-- === MASTER (seed dari JSON, read-only) ===
cost_accounts         -- dari cost_matrix_definition.json (163 akun)

-- === CATATAN PENGGUNA ===
cost_variance_remarks -- dari cost_variance_remarks.json
db_cost_remarks       -- dari db_cost_remarks.json
```

**Konsep penting:**
- `gl_entries.period` disimpan sebagai `VARCHAR(7)` 'YYYY-MM' (di-index) — menggantikan `glPeriod()` dari `inventory_engine.js`.
- Amount tipe `DECIMAL(19,6)` — mempertahankan presisi 6 desimal sesuai `toFixed(6)` di accrual.
- Bulan GL yang diimpor **menggantikan** bulan yang sama (upsert), bukan menambah — replikasi `saveGLData()` di server.js.

---

## 4. Struktur Aplikasi Laravel

```
laravel-mining-dashboard/
├── app/
│   ├── Http/Controllers/
│   │   ├── OverviewController.php          # get KPI + daily chart
│   │   ├── CostMatrixController.php        # /api/cost-matrix
│   │   ├── CostPerMtController.php         # /api/cost-per-mt
│   │   ├── InventoryController.php         # /api/inventory-valuation + opening
│   │   ├── FinancialController.php         # /api/financial-statements
│   │   ├── ProductionController.php        # /api/production-summary + db-cost-summary
│   │   ├── AccrualController.php           # /api/accruals (CRUD)
│   │   ├── RemarkController.php            # /api/cost-variance-remarks + db-cost
│   │   ├── GlImportController.php          # /api/upload-gl* + gl-data DELETE
│   │   ├── SyncD365Controller.php          # /api/sync/d365
│   │   ├── SyncSheetsController.php        # /api/sync/sheets
│   │   └── ConfigController.php            # /api/config
│   ├── Services/                           # ⭐ porting logika dari server.js/scripts
│   │   ├── CostMatrixService.php           # 📌 computeCostMatrix()
│   │   ├── ProductionService.php           # 📌 computeProductionSummary()
│   │   ├── InventoryValuationService.php   # 📌 computeInventoryValuation()
│   │   ├── CostPerMtService.php            # 📌 computeCostPerMT()
│   │   ├── FinancialStatementService.php   # 📌 computeFinancialStatements()
│   │   ├── GlParserService.php             # 📌 parseXlsx + parseDelimitedText + cleanNumeric
│   │   ├── GlAllocationService.php         # 📌 aturan kepala 5-9 / rute hauling / Direct Expense
│   │   ├── AccrualService.php              # 📌 accrualRows + dbAccrualRows + rentals
│   │   ├── SheetsProductionParser.php      # 📌 sheets_production.js
│   │   ├── SheetsDbParser.php              # 📌 sheets_db.js (parse gviz JSON)
│   │   └── ConstantLocation.php            # 📌 LOCATIONS, ROUTE_FIELDS, ACCRUAL_CATEGORIES
│   ├── Models/
│   │   ├── GlEntry.php
│   │   ├── MonthlyMetric.php
│   │   ├── DailyProduction.php
│   │   ├── DbCostSummary.php
│   │   ├── InventoryOpening.php
│   │   ├── FinancialBaseline.php
│   │   ├── Accrual.php
│   │   ├── CostAccount.php
│   │   ├── CostVarianceRemark.php
│   │   └── DbCostRemark.php
│   └── Support/
│       ├── NumericFormat.php               # 📌 cleanNumeric porting
│       ├── PeriodHelper.php                # 📌 glPeriod, periodsBetween, validPeriod
│       └── HaulingRoute.php                # 📌 haulingRoute dari hauling_routes.js
├── database/
│   ├── migrations/                         # 14 tabel di atas
│   └── seeders/
│       ├── CostAccountSeeder.php           # dari cost_matrix_definition.json
│       ├── InventoryOpeningSeeder.php      # dari inventory_model.json
│       ├── FinancialBaselineSeeder.php     # dari financial_baseline.json
│       ├── GlEntrySeeder.php               # dari gl_data.json (one-time)
│       ├── MonthlyMetricSeeder.php         # dari production_daily_sheets.json (one-time)
│       └── HistoricalFileSeeder.php        # copy semua history JSON → storage/app/data/
├── resources/
│   ├── data/                               # file JSON read-only (master + history)
│   └── views/
│       ├── layout/app.blade.php
│       ├── overview.blade.php
│       ├── cost-matrix.blade.php
│       ├── cost-mt.blade.php
│       ├── inventory.blade.php
│       ├── financial.blade.php
│       ├── integration.blade.php
│       └── partials/modals.blade.php
├── playwright/tests/                       # ⭐ integration test
│   ├── ApiContractTest.spec.js             # diff endpoint lama vs baru
│   └── FormulaCorrectnessTest.spec.js
├── .env                                    # DB creds, DIHILANGKAN clientSecret
└── composer.json
```

---

## 5. Fase & Timeline (8 Fase)

### **Fase 0 — Persiapan & Persetujuan (0.5 hari)**
- [ ] Ambil snapshot ZIP project Node.js saat ini (baseline pembanding)
- [ ] Backup `data/` (semua JSON) ke folder aman terpisah
- [ ] Jalankan `node server.js` di port 3000 → catat output semua endpoint sebagai **golden reference** (`reference_dump/*.json`)
- [ ] Verifikasi PHP 8.2+, Composer, extension `mbstring`, `xml`, `gd`, `sqlite` tersedia di mesin dev
- [ ] Setup repo baru; copy `FORMULA_DASHBOARD.md`, `IT_HANDOVER.md` ke repo Laravel sebagai referensi

### **Fase 1 — Fondasi Laravel (1–2 hari)**
- [ ] `composer create-project laravel/laravel`
- [ ] Konfigurasi MySQL + `php artisan migrate`
- [ ] Buat 14 migration + model Eloquent
- [ ] Konfigurasi cache `file`, queue `sync`, session `file`
- [ ] Setup testing database (`phpunit.xml` → DB test terpisah)
- [ ] Buat route stub untuk semua 12 endpoint API (return dummy)

### **Fase 2 — Data Layer & Seeder (2–3 hari)**
- [ ] **Seeder master**: `CostAccountSeeder` (163 akun), `InventoryOpeningSeeder`, `FinancialBaselineSeeder`, `HistoricalFileSeeder`
- [ ] **Parser & import GL**: porta `gl_import.js` + `xlsx_parser.js` → `GlParserService.php` + `GlImportController`
- [ ] **Impor data one-time**: dari `gl_data.json` + `production_daily_sheets.json` → tabel DB
- [ ] **Perintah Artisan khusus**: `php artisan gl:import {file}` dan `php artisan data:seed-from-json`
- [ ] Uji: hasil query harus identik dengan filter Node.js (sample 10 akun × 2 bulan)

### **Fase 3 — Engine Perhitungan (3–5 hari)** ⭐ fase paling kritis
Urutan porting `Services/` (satu-satu, tiap service divalidasi):

| Sentral Utama | Fungsi Asli di Node.js | Kompleksitas |
|---|---|---|
| `CostMatrixService` | `computeCostMatrix()` | ⭐⭐⭐ tertinggi (banyak aturan alokasi) |
| `InventoryValuationService` | `computeInventoryValuation()` | ⭐⭐⭐ (moving average, 8 lokasi, transfer) |
| `AccrualService` | `accrualRows()` + `dbAccrualRows()` | ⭐⭐ |
| `ProductionService` | `computeProductionSummary()` | ⭐⭐ |
| `CostPerMtService` | `computeCostPerMT()` | ⭐⭐ |
| `FinancialStatementService` | `computeFinancialStatements()` | ⭐⭐⭐ (P&L mapping + Neraca) |

- [ ] Porting `CostMatrixService.php` (rules: kepala 7-9→GA, 5-6→Costing/Valuation, rute hauling, Direct Expense, Not Valuated→GA, accrual merge, kapitalisasi, dsb)
- [ ] Porting `InventoryValuationService.php` (`inventory_engine.js`, urutan: opening → receipt → addcost → adjust → transfer → outflow → closing)
- [ ] Porting `CostPerMtService.php` (6 stage, fallback persen historis bila DB kosong)
- [ ] Porting `FinancialStatementService.php` (P&L + Neraca, mtap kepala, accrual jurnal virtual Dr/Cr 1530003, GL budget baseline)
- [ ] **Testing kritis**: untuk setiap service, bandingkan output `reference_dump` dengan hasil Laravel → target **selisih abs = 0** untuk semua angka

### **Fase 4 — API Layer (1–2 hari)**
Mapping endpoint 1:1 dengan Node.js di `server.js`:

| Node.js Route | Laravel Route | Controller |
|---|---|---|
| `GET /api/config` | `GET /api/config` | `ConfigController@index` |
| `POST /api/config` | `POST /api/config` | `ConfigController@update` |
| `GET /api/cost-matrix` | `GET /api/cost-matrix` | `CostMatrixController@index` |
| `GET /api/production-summary` | `GET /api/production-summary` | `ProductionController@index` |
| `GET /api/inventory-valuation` | `GET /api/inventory-valuation` | `InventoryController@show` |
| `POST /api/inventory-opening` | `POST /api/inventory-opening` | `InventoryController@updateOpening` |
| `GET /api/cost-per-mt` | `GET /api/cost-per-mt` | `CostPerMtController@index` |
| `GET /api/financial-statements` | `GET /api/financial-statements` | `FinancialController@index` |
| `POST /api/upload-gl` | `POST /api/upload-gl` | `GlImportController@store` |
| `POST /api/upload-gl-excel` | `POST /api/upload-gl-excel` | `GlImportController@storeExcel` |
| `DELETE /api/gl-data` | `DELETE /api/gl-data` | `GlImportController@delete` |
| `GET /api/accounts` | `GET /api/accounts` | `AccrualController@accounts` |
| `GET/POST/DELETE /api/accruals` | idem | `AccrualController` |
| `POST /api/sync/sheets` | `POST /api/sync/sheets` | `SyncSheetsController@store` |
| `POST /api/sync/d365` | `POST /api/sync/d365` | `SyncD365Controller@store` |
| `GET /api/db-cost-summary` | `GET /api/db-cost-summary` | `ProductionController@dbCostSummary` |
| `GET /api/excel-cost-example` | `GET /api/excel-cost-example` | `ProductionController@excelCostExample` |
| `GET/POST /api/cost-variance-remarks` | idem | `RemarkController@variance` |
| `GET/POST /api/db-cost-summary/remark` | idem | `RemarkController@dbCost` |

- Validasi form request replikasi persis aturan `server.js` (frasa error bahasa Inggris dipermanen agar frontend tidak perlu diubah)
- Query param `period=YYYY-MM` & `mode=mtd|ytd` identik

### **Fase 5 — Frontend (2–3 hari)**
- [ ] Copy `public/` Node.js → `resources/views/` + `public/` Laravel
- [ ] `index.html` → `layout/app.blade.php` + 7 partial tab
- [ ] Ganti semua hardcoded URL `http://localhost:3000` → helper `asset()` / `route()`
- [ ] `app.js`, `financial.js`, `inventory.js` → tetap vanilla, taruh di `public/js/`
- [ ] Kompres `source-badge` HTML (sudah dikerjakan sebelumnya) dipertahankan
- [ ] Uji semua tab: request/response JSON harus identik dengan versi lama

### **Fase 6 — Integrasi Google Sheets (1–2 hari)**
- [ ] Porting `sheets_production.js` → `SheetsProductionParser.php` (CSV Produksi, gviz DB, Dashboard Excel, ABC Monthly, Calc COGP-COGS)
- [ ] Porting `sheets_db.js` → `SheetsDbParser.php`
- [ ] Endpoint `/api/sync/sheets` (sinkron = 5 sheet, snapshot warna, accrual otomatis)
- [ ] Spesifikasi: bila gagal sub-sheet opsional, jatuh balik ke snapshot lama (seperti perilaku Node.js)
- [ ] Catat/simulasi single-entry log; write-back ke Google Sheet tetap **tidak ada** (seperti kondisi asli)

### **Fase 7 — Paralel Run & Validasi End-to-End (2–3 hari)** ⭐ penentu GO/NO-GO
- [ ] Jalankan Node.js lama di port **3000**, Laravel via `php artisan serve` di port **8000**
- [ ] Jalankan script diff otomatis (
  bandingkan keduanya untuk period `2026-01` s/d `2026-10`, mode `mtd` + `ytd` puluhan endpoint):
  ```bash
  python scripts/diff_endpoints.py --base http://localhost:3000 --lar http://localhost:8000 --out diff_report.html
  ```
- [ ] Target: **selisih 0.00** untuk semua numerik; perbedaan hanya boleh pada metadata waktu (`syncedAt` dsb)
- [ ] Uji manual UI: klik semua tombol, impor GL dummy, tambah accrual, simpan remark, cek perhitungan di layar
- [ ] Simak `IT_HANDOVER.md` bagian 6 (selisih USD 3.001 neraca) — pastikan Laravel menghasilkan selisih identik dengan Node.js (bukan "hilang") → bukti konversi jujur

### **Fase 8 — Deployment Shared Hosting & Cutover (1–2 hari)**
- [ ] Siapkan akun shared hosting (domain `/public_html/<sub>`) → ubah `DocumentRoot` ke folder `/public` jika boleh set via cPanel; jika tidak, gunakan trik `.htaccess` di public_html yang redirect semua request ke `public/index.php`
- [ ] Upload proyek via Git/FTP **tanpa** `node_modules`/`vendor` yang sudah di-composer di server (shared hosting biasanya tidak bisa `composer install`)
- [ ] `php artisan key:generate`, `php artisan migrate`, `php artisan db:seed` di server (via cPanel terminal / SSH bila ada; kalau tidak: local → dump SQL → import via cPanel phpMyAdmin)
- [ ] Set permission: `chmod 755 storage bootstrap/cache`, `chmod 644 .env`
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`
- [ ] Uji 1usai cutover: semua tab, sync Google Sheets dari IP hosting (perluas Google Sheets sharing ke siapa saja dengan link)
- [ ] Node.js lama **di-keep 30 hari** sebagai fallback, kemudian diarsipkan

---

## 6. Jadwal Timeline Ringkas

| Fase | Durasi | Akumulasi | Milestone |
|---|---|---|---|
| 0. Persiapan | 0.5 h | 0.5 h | Golden reference & data aman |
| 1. Fondasi | 1.5 h | 2 h | Laravel jalan + 14 tabel |
| 2. Data layer | 2.5 h | 4.5 h | GL bisa diimpor ke DB |
| 3. Engine | 4 h | 8.5 h | Semua rumus porting (selisih 0) |
| 4. API | 1.5 h | 10 h | Semua endpoint responsif |
| 5. Frontend | 2.5 h | 12.5 h | UI Laravel selesai |
| 6. Sheets sync | 1.5 h | 14 h | Sinkronisasi Google Sheets jalan |
| 7. Paralel run | 2.5 h | 16.5 h | **GO/NO-GO** |
| 8. Deployment | 1.5 h | **18 h** | Live di shared hosting |

**Estimasi 18 hari kerja** (1 developer fokus penuh). Dengan 2 developer: ~10–12 hari (Fase 3 diparalel 2 orang, Fase 5 diparalel dengan Fase 6).

---

## 7. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | **Salah hitung** (presisi PHP vs JS: `float` vs `decimal`) | Fatal akurat laporan | Gunakan `bcmath` / cast `DECIMAL`, **jangan** double PHP biasa untuk amount. Validasi paralel-run wajib |
| 2 | Parser XLSX kolom L berbeda hasil | numpy import gagal atau salah angka | Uji dengan `sample_gl_dynamics365.xlsx` & GL file riil + bandingkan jumlah per akun |
| 3 | Eksekusi PHP lamban saat GL besar (>20k baris import) | Timeout shared hosting (biasanya 30 s) | Gunakan chunk insert, optimasi query; dokumentasikan batas |
| 4 | Shared hosting blok outbound HTTPS | Sync Google Sheets gagal | Cek dulu sebelum fase 6; fallback: user upload manual CSV |
| 5 | Melupakan aturan dimensi sulit (kepala 7–9 → GA, rute hauling, Not Valuated→GA, dst) | Alokasi COGP salah | Replikasi unit test `server.js` (`test_gl_regression`) ke PHPUnit; verifikasi menggunakan 163 akun |
| 6 | Baseline financial tidak dipindah/malformed | P&L/O neraca salah dari Jan-Sep | Validasi baseline YTD vs GL data; periksa rumus per bulan |
| 7 | Konkurensi import (2 orang impor bersamaan) | Data OLAP tercampur | Fase awal disiplin SOP satu orang impor; di-roadmap multi-user |
| 8 | Parameter periode/mode antar endpoint tidak konsisten | YTD jadi MTD | Unit test khusus dr perbandingan mode |

---

## 8. Hal yang DIKELUARKAN dari Scope (fase awal)

**Sengaja tidak dibangun** (mengikuti keputusan user + status Node.js saat ini):
- ❌ Autentikasi/login (akan ditambahkan di fase lanjut, lihat IT_HANDOVER §3)
- ❌ Role/permission per endpoint
- ❌ Audit trail lengkap (hanya log import GL)
- ❌ Multi-user concurrency locking
- ❌ Cash flow report (belum ada di Node.js)
- ❌ Budget variance (belum ada sumber budget)
- ❌ Write-back ke Google Sheets
- ❌ CSRF/SSO/TLS — pra-pemakaian adalah via jaringan internal/LAN

---

## 9. Checklist Sebelum LIVE

- [ ] Diff endpoint = 0 untuk seluruh bulan + 2 mode
- [ ] Import GL Excel sample → row count & sum per akun identik
- [ ] Import GL CSV/paste → identik
- [ ] Tambah accrual manual → muncul di Report Cost & FS dengan nilai tepat
- [ ] Sync Google Sheets → Produksi diperbarui & accrual otomatis dibuat
- [ ] Ubah opening balance → semua bulan berikutnya rekalkasi
- [ ] Hapus GL → saldo report cost ter-reset, histori tersisa
- [ ] Cek badge `.source-badge` tampil di semua card
- [ ] Export CSV Report Cost muncul valid
- [ ] Jalankan `php artisan test` → semua pass
- [ ] Backup otomatis DB harian di on (cron cPanel)

---

## 10. Langkah Hari Pertama (Action Item)

Jika plan ini disetujui:
1. Backup semua data sekarang: zip folder `data/` → simpan.
2. Jalankan Node.js → dump semua endpoint ke `reference_dump/*.json` dengan script `scripts/test_connection.js` (yang sudah ada, diperluas untuk menyimpan body).
3. `composer create-project laravel/laravel laravel-mining-dashboard "11.*"`
4. Buat migration 14 tabel sesuai skema bagian 3.
5. Lakukan Fase 2: seeder + GL import parser.

Dokumen ini disimpan sebagai `LARAVEL_MIGRATION_PLAN.md` di root project.
