# Prompt Kit — P2 Data Layer (BP-LARAVEL-DASH)

## Cara pakai
Paste bagian KONTEKS (dari `P1_FONDASI.md`) di awal sesi AI, kerjakan tugas satu-satu,
verifikasi tiap selesai, jangan lewati gate P2.6.

---

## STATUS P2 — ⏳ BERJALAN (mulai 30 Okt 2026)

| Tugas | Status | Bukti |
|---|---|---|
| P2.1 Seeder `CostAccountSeeder` | ✅ | 163 akun loaded, idempotent |
| P2.2 `GlParserService` | ✅ | `test_parser.php`: 12.583 rows, invalidDates=0, period 2026-10 |
| P2.3 `GlImportService` (upsert per bulan) | ✅ | 12.583 rows loaded; second run tetap 12.583, tidak duplikat |
| P2.4 Seeder `DashboardSeeder` (non-GL) | ✅ | 185 metrics, 1 baseline, 9 inventory openings, 4 configs |
| P2.5 Command `dashboard:load-data` | ✅ | End-to-end sukses dari `data_snapshot/extracted` |
| P2.6 Verifikasi angka vs baseline (GATE M1) | ✅ | `dashboard:verify` semua check OK; GL 2026-10 = 12.583 rows, total 0.000000 |

---

## PRINSIP DATA LAYER (tidak bisa ditawar)
1. **Satu-satunya sumber angka GL** = kolom `Amount in reporting currency` (col L).
2. Import GL **hanya menggantikan bulan yang diimpor** (upsert per bulan):
   hapus rows lama untuk periode tsb lalu insert baru; catat filename +
   row_count + months_covered di `gl_import_batches`. Jangan pernah menyentuh
   bulan lain.
3. Insert besar memakai chunk + DB transaction (aman untuk 12.583 row).
4. Sumber data dibaca **hanya** dari `data_snapshot/` — tidak pernah dari
   folder operasional `D:\apps\mining-accounting-dashboard`.
5. Kolom `period` selalu string `YYYY-MM`; tanggal Excel serial → date
   (serial 46301 = 2026-10-25).
6. Semua nilai uang memakai cast `decimal:6` di model; jangan aritmetika
   float PHP untuk angka laporan (P3 nanti pakai bcmath saat agregasi).

## TUGAS P2.1 — Seeder CostAccountSeeder
```
Sumber: data_snapshot/cost_matrix_definition.json (array 163 item
{code, name, isParent}).
Buat Database\Seeders\CostAccountSeeder yang:
- upsert ke tabel cost_accounts: account_code=code (string!),
  account_name=name, is_parent=bool, head_account=code[0]
- idempotent (updateOrCreate berdasarkan account_code)
- daftarkan di Database\Seeders\DatabaseSeeder.
```
**Verifikasi:** `php artisan db:seed` → count(cost_accounts)=163; re-run tidak
membuat duplikat.

## TUGAS P2.2 — GlParserService
```
Buat app\Services\GlParserService: parseFile(string $path): array
menerima path gl_data.json (array 12.583 × 29 kolom nama D365) dan
mengembalikan array row siap-insert dengan mapping:

  journal_number   <- "Journal number"
  voucher          <- "Voucher"
  entry_date       <- "Date" (Excel serial -> Y-m-d)
  year_closed      <- "Year closed"
  account_name     <- "AccountName"
  description      <- "Description"
  currency         <- "Currency"
  fuel_quantity    <- "Fuel quantity"
  amount_txn       <- "Amount in transaction currency"
  amount_idr       <- "Amount IDR"
  amount           <- "Amount"
  amount_reporting <- "Amount in reporting currency"   ⭐ sumber utama
  posting_type     <- "Posting type"
  posting_layer    <- "Posting layer"
  vendor_account   <- "Vendor account"
  customer_account <- "Customer account"
  invoice_no       <- "Invoice no"
  main_account     <- "MainAccount"
  activity         <- "Activity"
  cash_flow        <- "Cash_Flow"
  department       <- "Departmen"      (sengaja tanpa "t", nama kolom D365)
  group_activity   <- "Group_Activity"
  location         <- "Location"
  project          <- "Project"
  project_name     <- "Project name"
  cost_center      <- "Cost center"
  valuation_to     <- "Valuation to"
  costing_to       <- "Costing to"
  po_dt_number     <- "PO DT Number"
  gjae_id          <- "GJAE ID"
  created_datetime <- "Created date and time"

- turunkan `period` = YYYY-MM dari entry_date
- return statistik: totalRows, perPeriod (count per periode),
  invalidDates (baris yang Date-nya tidak terparse)
```
**Verifikasi:** via `php tinker-script test_parser.php` (pattern test_models.php):
totalRows=12583; period 2026-10 dan konsistensi jumlahnya dengan isi JSON sumber.

## TUGAS P2.3 — GlImportService (upsert per bulan)
```
Buat app\Services\GlImportService: import(array $rows, string $filename,
string $note = null): GlImportBatch
- group rows per period; untuk setiap period: DELETE gl_entries period=X
  lalu insert chunk baru — 1 DB transaction per periode; satu transaction
  besar untuk seluruh import agar atomik
- buat 1 row gl_import_batches (filename, source='seed', row_count,
  months_covered JSON, note, imported_at=now) dan set batch_id semua row
```
**Verifikasi:** import snapshot → gl_entries=12583; GROUP BY period cocok dengan
statistik parser; re-import → masih 12583 (tidak duplikat; rows lama tergantikan).

## TUGAS P2.4 — Seeder DashboardSeeder (non-GL)
```
Sumber: data_snapshot/production_daily_sheets.json, financial_baseline.json,
inventory_model.json, config.json, accruals.json.
Buat Database\Seeders\DashboardSeeder yang:
- monthly_metrics  <- production_daily_sheets.json .db.metrics
  (20 metric_key, upsert per (metric_key, period) dari .months{period:value})
- financial_baselines <- financial_baseline.json (1 row version=1; company,
  currency, base_period, gl_start_period, year, comparative_year, sections
  & accounts & policy JSON apa adanya)
- inventory_openings  <- inventory_model.json .openingBalances (9 lokasi:
  bua, nonWash, washed, highAsh, refeed, port, transitPort, kunz, transitKunz;
  period = startPeriod model, qty_mt/value_usd sesuai isi, cost_per_mt = value/qty)
- system_configs   <- config.json (key: system, d365Config, sheetsConfig,
  inventorySetup — value JSON apa adanya)
- accruals         <- accruals.json (kemungkinan kosong — entries:[]; kalau
  kosong, lewati dengan catatan di output)
- daily_production <- bila ada data harian di production_daily_sheets.json;
  kalau strukturnya tidak menyimpan daily rows, lewati dan catat.
Semua idempotent (upsert).
```
**Verifikasi:** counts: financial_baselines=1, inventory_openings=9,
system_configs=4, monthly_metrics = 20 × jumlah periode di file sumber.

## TUGAS P2.5 — Command dashboard:load-data
```
Buat App\Console\Commands\DashboardLoadData:
- signature: dashboard:load-data {--path= : folder JSON snapshot}
- default: D:\apps\finops-dashboard\data_snapshot
- urutan: cost_accounts -> non-GL (DashboardSeeder) -> GL (parser+import)
- output progres "Step x/5 ... OK (statistik)" per step
- gagal -> exit non-zero; DB state sebelum step yang gagal tidak terotak-atik
```
**Verifikasi:** `php artisan dashboard:load-data` sukses end-to-end; re-run aman.

## TUGAS P2.6 — Verifikasi angka vs baseline (GATE M1)
```
Buat command App\Console\Commands\DashboardVerify (atau script test_verify.php):
1. cost_accounts=163; inventory_openings=9; system_configs=4;
   gl_entries=12583; monthly_metrics == jumlah (metric,period) dari sumber.
2. SUM(amount_reporting) per period dari gl_entries dicatat sebagai angka
   acuan internal (dipakai ulang saat P3 menyusun service kalkulasi; identik
   dengan agregat dari dump baseline — USD 3,001 balance-sheet gap dites
   saat P3, bukan di sini).
3. HANYA jika semua cocok → tandai STATUS P2 ✅ di file ini, lalu minta
   approve user sebelum masuk P3.
```
**Verifikasi:** command lulus semua check; tabel perbandingan ditampilkan.

---

## Batasan tambahan untuk sesi P2
- JANGAN membuat controller/endpoint/service kalkulasi laporan (P3/P4).
- JANGAN mengubah isi JSON sumber; hanya baca dari data_snapshot/.
- Service di `app/Services/`, command di `app/Console/Commands/`,
  seeder di `database/seeders/`.
- Script verifikasi manual di root `laravel/` memakai prefix `test_`
  (konsisten dengan `test_models.php` dari P1).
