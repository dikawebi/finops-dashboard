# Prompt Kit — P3 Calculation Engine (BP-LARAVEL-DASH)

## Status

- ✅ P3.1 `CostMatrixService` awal: aturan alokasi Report Cost, MTD/YTD, BCMath.
- ✅ P3.2 `dashboard:cost-matrix` smoke command.
- ⏳ P3.3 Financial Statements dan Inventory Valuation.
- ⏳ P3.4 Regression diff terhadap 125 baseline dump (target diff 0).

## Aturan

1. Rumus hanya mengikuti `reference/docs/FORMULA_DASHBOARD.md` dan implementasi
   referensi; jangan membuat kebijakan akuntansi baru.
2. Amount selalu `gl_entries.amount_reporting`.
3. MTD hanya periode terpilih; YTD Januari sampai periode terpilih.
4. Parent adalah agregasi anak; grand total hanya leaf agar tidak double count.
5. Gunakan BCMath untuk seluruh agregasi nominal.
6. Belum membuat controller/endpoint; endpoint masuk P4.

## Verifikasi P3.1

```bash
php artisan migrate:fresh --force
php artisan dashboard:load-data
php artisan dashboard:cost-matrix 2026-10 --mode=mtd
php artisan dashboard:cost-matrix 2026-10 --mode=ytd
```

Output harus memiliki 163 account rows dan hanya memakai GL yang sudah dimuat.
Nilai baseline per field dibandingkan berikutnya melalui `dashboard:regression`.

## Berikutnya

Port `financial_engine.js` menjadi `FinancialStatementService`, port
`inventory_engine.js` menjadi `InventoryValuationService`, lalu bangun command
regression yang membaca `baseline/reference_dump/` dan melaporkan path/field
pertama yang berbeda.
