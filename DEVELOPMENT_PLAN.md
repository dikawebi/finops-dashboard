# FinOps Dashboard — Development Steps

Dokumen ini menjadi checklist kerja setelah data freeze 2026-10-30. Angka dashboard wajib direkonsiliasi dengan `baseline/reference_dump/` sebelum fase dinyatakan selesai.

## Status saat ini

| Fase | Fokus | Status | Bukti |
|---|---|---|---|
| P1 | Fondasi Laravel 11, schema, model, seed dasar | Selesai | Migration, model, seeder tersedia |
| P2 | Data layer dan frozen snapshot loader | Selesai | `dashboard:load-data`, `dashboard:verify` |
| P3.1 | Report Cost calculation engine | Berjalan | `CostMatrixService`, `dashboard:cost-matrix` |
| P3.2 | Smoke command | Selesai | `dashboard:cost-matrix` terdaftar |
| P3.3 | Financial Statements dan Inventory Valuation | Implemented | Baseline MTD/YTD tersedia melalui service dan API; visual parity masih berjalan |
| P3.4 | Regression terhadap baseline | Selesai | 80 baseline Cost Matrix, Cost/MT, Financial Statements, dan Inventory lulus; `dashboard:regression --suite=all` |
| P4 | Controller, endpoint, dan frontend Laravel | Implemented (parity pending) | Read-only endpoint dan frontend utama tersedia; beberapa kontrol/integrasi masih deferred |

## Development steps

### Step 1 — Fondasi dan data contract

1. Pastikan migration dapat dijalankan dari database kosong.
2. Pastikan semua model memiliki nama tabel, cast, dan relasi yang benar.
3. Pastikan seeder tidak mengubah snapshot sumber.
4. Verifikasi jumlah data dengan `dashboard:verify`.

### Step 2 — Data loading

1. Jalankan `php artisan migrate:fresh --force` pada database development.
2. Jalankan `php artisan dashboard:load-data`.
3. Pastikan 163 akun biaya, 12.583 GL entries, 185 monthly metrics, 1 financial baseline, dan 9 inventory openings masuk.
4. Jangan menggunakan database operasional port 3000 sebagai target loader.

### Step 3 — Calculation engine

1. Port rumus dari `reference/docs/FORMULA_DASHBOARD.md` secara bertahap.
2. Gunakan `amount_reporting` sebagai sumber nominal GL.
3. Gunakan BCMath untuk agregasi nominal.
4. Bedakan leaf, parent, dan grand total agar tidak terjadi double count.
5. Pastikan MTD hanya memakai bulan terpilih dan YTD memakai Januari sampai bulan terpilih.

### Step 4 — Regression gate

1. Jalankan regression satu periode terlebih dahulu:

   `php artisan dashboard:regression --period=2026-10 --mode=mtd`

2. Perbaiki mismatch berdasarkan path field pertama yang dilaporkan.
3. Ulangi untuk YTD dan periode historis.
4. Jalankan seluruh baseline setelah satu periode lulus:

   `php artisan dashboard:regression`

5. Fase P3.1/P3.4 hanya boleh ditandai selesai jika seluruh baseline lulus dalam toleransi yang disepakati.

### Step 5 — Financial Statements

1. Port struktur `financial_baseline.json` ke `FinancialStatementService`.
2. Implementasikan MTD/YTD untuk P&L dan balance sheet.
3. Tambahkan virtual journal accrual sesuai dokumentasi.
4. Pastikan cash flow tetap disabled karena belum memiliki sumber data.
5. Tambahkan command smoke dan baseline regression untuk financial statements.

### Step 6 — Inventory Valuation

1. Port opening balance dan movement dari snapshot ke `InventoryValuationService`.
2. Implementasikan transfer, outflow, adjustment, capitalization, reject wash, dan high ash sesuai urutan rumus.
3. Hitung quantity, value, dan cost per MT dengan guard pembagi nol.
4. Tandai data provisional/dummy secara eksplisit.
5. Tambahkan regression terhadap baseline inventory valuation.

### Step 7 — API dan UI

1. Buat controller read-only setelah engine lulus regression.
2. Tambahkan endpoint cost matrix, cost per MT, production summary, financial statements, dan inventory valuation.
3. Port layout/branding dari referensi tanpa mengubah aturan visual.
4. Tambahkan validasi period/mode dan response contract.
5. Jangan membuka endpoint mutasi sebelum autentikasi, authorization, audit trail, dan CSRF selesai.

### Step 8 — Release gate

1. Jalankan test suite dan seluruh regression.
2. Rekonsiliasi selisih balance sheet, terutama diagnostic variance USD 3,001 yang disebut di handover.
3. Verifikasi asumsi volume, mapping COA, inventory provisional, accrual, dan reversal dengan Accounting.
4. Dokumentasikan deployment, backup, rollback, dan konfigurasi environment.
5. Setelah repository Git tersedia, commit setiap fase dengan pesan lengkap lalu push ke remote.

## Next development plan

### Task berikutnya: visual parity, data-source QA, dan rekonsiliasi bisnis

Urutan kerja:

1. Bandingkan tujuh tab dengan referensi pada viewport desktop dan mobile.
2. Verifikasi inventory route/history, production chart, serta empty-state behavior.
3. Rekonsiliasi selisih Balance Sheet, mapping COA, volume fallback, inventory provisional, accrual, dan reversal dengan Accounting.
4. Aktifkan persistence remark setelah authorization/CSRF tersedia (ditunda sesuai scope proyek).
5. Jalankan `php artisan dashboard:regression --suite=all` setelah setiap perubahan engine.
6. Catat mismatch yang bersifat data/asumsi terpisah dari bug engine.

### Status setelah P3.4 lulus

1. Financial statement dan inventory service sudah membaca baseline resmi.
2. Cost/MT, DB Cost Summary, dan Production Summary sudah memiliki endpoint baseline-backed.
3. Regression command sudah mencakup empat domain dashboard.
4. Pekerjaan tersisa berfokus pada visual parity, integrasi data, dan release QA.
5. Filter location pada Cost Variance, export Excel-compatible, dan preview read-only GL sudah tersedia.

## Definition of done per fase

- Kode dan command tersedia.
- Test otomatis lulus.
- Regression terhadap baseline lulus atau mismatch sudah disetujui dan terdokumentasi.
- Dokumentasi diperbarui.
- Perubahan di-commit dan di-push bila repository/remote tersedia.
