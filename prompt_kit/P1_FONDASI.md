# Prompt Kit — P1 Fondasi Laravel (BP-LARAVEL-DASH)

## STATUS P1 — ✅ SELESAI (30 Okt 2026)

| Tugas | Status | Bukti |
|---|---|---|
| P1.1 Project Laravel | ✅ | Laravel **11.57.1** di `finops-dashboard/laravel/`; `.env` = mysql/`finops_dashboard`, TZ `Asia/Jakarta`, cache `file`, queue `sync`, APP_NAME=FinOps-Dashboard, APP_URL http://localhost:8000 |
| P1.2 Migration 14 tabel | ✅ | `2024_01_02_000001_create_dashboard_tables.php`; `migrate:fresh` sukses di MySQL 8.0.30 — 14 tabel dashboard terverifikasi via `SHOW TABLES` |
| P1.3 Model Eloquent (14) | ✅ | `app/Models/*.php` dengan cast `decimal:6` + `scopePeriod`; verifikasi via `test_models.php` → **ALL 14 MODELS OK** (semua count = 0) |
| P1.4 Branding dasar | ✅ | `resources/views/welcome.blade.php`: font Plus Jakarta Sans (bunny.net), token warna `#0066FF`/slate, header logo BP |

Skema dibangun dari struktur data aktual (gl_data.json 12.583 baris × 29 kolom D365,
cost_matrix_definition.json 163 akun, 9 lokasi inventory, 20 metrik produksi) — konsisten
dengan FDD §4.3. Angka amount: `DECIMAL(19,6)`, period `VARCHAR(7)` 'YYYY-MM' indexed.

> Catatan lingkungan (pengalaman sesi): shell PowerShell — hindari `$` inline & `2>nul`;
> mysql wajib `--password=` eksplisit; versi composer tanpa tanda kutip.

---

## KONTEKS UMUM (paste di awal setiap sesi)

```
Kamu sedang membangun sistem baru dari nol: "PT Borneo Prima — Accounting & Mining
Cost Dashboard" (kode project BP-LARAVEL-DASH) menggunakan Laravel 11 + MySQL,
target deployment shared hosting, TANPA autentikasi pada rilis awal.

Folder project: D:\apps\finops-dashboard — project Laravel dibuat di subfolder laravel/.

Aturan yang tidak boleh dilanggar:
1. Semua angka kalkulasi WAJIB menghasilkan nilai identik dengan baseline figures
   di baseline/reference_dump/*.json (toleransi diff = 0).
2. Aturan bisnis hanya berasal dari reference/docs/FORMULA_DASHBOARD.md —
   JANGAN meng-improvisasi atau menambah aturan sendiri.
3. Kontrak API mengikuti FDD §5 (18 endpoint, param period=YYYY-MM & mode=mtd|ytd)
   — bentuk URL, payload, dan struktur respons dibekukan.
4. Nilai amount di DB wajib DECIMAL(19,6);gunakan bcmath/decimal, BUKAN float PHP mentah.
5. gl_entries.period bertipe VARCHAR(7) 'YYYY-MM' (indexed). Import GL hanya
   menggantikan bulan yang diimpor (upsert per bulan, tanpa duplikat).
6. Ukuran gaya kode: konvensi Laravel standar, controller tipis, logika di Services.

Skema 14 tabel database (sumber kebenaran): FDD §4.3
  gl_entries, gl_import_batches, monthly_metrics, daily_production, db_cost_summary,
  system_configs, financial_baselines, inventory_openings, inventory_movements,
  accruals, accrual_history, cost_accounts, cost_variance_remarks, db_cost_remarks.
```

---

## TUGAS P1.1 — Buat project Laravel

```
Di D:\apps\finops-dashboard jalankan (versi TANPA tanda kutip di cmd):
  composer create-project laravel/laravel laravel 11.* --no-interaction --prefer-dist
Setelah selesai:
- php artisan --version → catat versi
- Set .env: DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_PORT=3306,
  DB_DATABASE=finops_dashboard, DB_USERNAME=root, DB_PASSWORD= (kosong),
  CACHE_STORE=file, QUEUE_CONNECTION=sync, SESSION_DRIVER=file,
  APP_LOCALE=id, APP_TIMEZONE=Asia/Jakarta, APP_URL=http://localhost:8000,
  APP_NAME=FinOps-Dashboard
Riwayat: mysql via `mysql -u root --password= -e "CREATE DATABASE IF NOT EXISTS
finops_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"` (pwd kosong,
--password= WAJIB eksplisit).
```

**Verifikasi:** `php artisan migrate:fresh` sukses + halaman welcome Laravel tampil.

## TUGAS P1.2 — Migration 14 tabel

```
Buat 14 migration sesuai skema FDD §4.2–4.3 (kolom mengikuti struktur data yang
akan diimpor dari data_snapshot/data_backup_2026-10-30.zip — baca JSON di zip
tersebut untuk menurunkan kolom):
- gl_entries: 29 kolom gaya D365 (journalId, accountCode, accountName, costCenter,
  amount DECIMAL(19,6), period VARCHAR(7) 'YYYY-MM' + index, valuationTo,
  costingTo, directExpenseCategory, dst — turunkan dari gl_data.json)
- gl_import_batches: filename, rows, periodCovered, importedAt
- monthly_metrics: period, metricKey, value DECIMAL(19,6), unit
- daily_production: date, coalGettingMT DECIMAL(19,6), metadata JSON
- db_cost_summary: period, entity, activity, amount DECIMAL(19,6)
- system_configs: key UNIQUE, value JSON
- financial_baselines: accountCode, ytd25, ytd26, per-bulan Jan..Sep 2026 (DECIMAL)
- inventory_openings: location (9 lokasi), qtyMT, costPerMT, period
- inventory_movements: period, location, movementType, qtyMT, amount, source
- accruals: period, accountCode, description, qty, rate, amount DECIMAL(19,6),
  source enum('manual','db')
- accrual_history: snapshot snapshot dari automatic_accrual_history.json
- cost_accounts: accountCode UNIQUE, accountName, parentCode, headAccount,
  isParent — dari cost_matrix_definition.json (163 akun)
- cost_variance_remarks: period, category, accountCode, remark TEXT(2000)
- db_cost_remarks: period, entityActivityKey, remark TEXT(2000)
Tambahkan index: period pada tabel per-period; UNIQUE KEY gabungan sesuai
kebutuhan upsert (mis. gl_entries(journalId,accountCode,period)).
```

**Verifikasi:** `php artisan migrate:fresh` sukses; `php artisan db:show` menampilkan 14+ tabel.

## TUGAS P1.3 — Model Eloquent + relasi

```
Buat model Eloquent untuk 14 tabel di atas, satu per tabel, dengan:
- $guarded = [] (mass assignment terbuka untuk seeder/internal)
- cast amount & qty ke decimal:12,6
- relasi eksplisit bila nyata (GlEntry ↔ GlImportBatch; CostAccount parent/children
  self-referencing; Accrual ↔ CostAccount)
- siapkan scope: scopePeriod($q,$period), scopeMode TIDAK PERLU (mode ditangani
  service layer, bukan model)
```

**Verifikasi:** `php artisan tinker --execute='GlEntry::count();'` tidak error (boleh 0).

## TUGAS P1.4 — Branding dasar

```
Sesuaikan root layout default (view welcome / matikan Vite jika belum dibutuhkan):
- font: Plus Jakarta Sans via https://fonts.bunny.net (link tag)
- CSS variables: --primary:#0066FF; --text-main:#0F172A; --bg:#f1f5f9;
  --border:#e2e8f0; font-family:"Plus Jakarta Sans"
- header acuan (layout akhir seperti screenshot 01_overview.png di reference/docs):
  logo BP + teks "PT BORNEO PRIMA" + subtitel uppercase biru
```

**Verifikasi:** visual (bisa ditunda ke P5 final).

---

## Batasan tambahan untuk sesi P1

- JANGAN membuat controller/endpoint dulu (P4).
- JANGAN menyentuh folder D:\apps\mining-accounting-dashboard (aplikasi operasional).
- Semua file JSON sumber hanya dibaca dari data_snapshot/ copy — bukan dari folder operasional.
