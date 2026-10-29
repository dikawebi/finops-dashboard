# FDD — Functional Design Document
# Pengembangan Sistem Accounting & Mining Cost Dashboard PT Borneo Prima (Laravel)

| Field | Isi |
|---|---|
| **Nama Project** | PT Borneo Prima — Accounting & Mining Cost Dashboard |
| **Kode Project** | `BP-LARAVEL-DASH` |
| **Versi Dokumen** | 1.1 — 30 Oktober 2026 |
| **Status** | DRAFT — MENUNGGU PERSETUJUAN |
| **Pemilik Bisnis** | Tim Accounting |
| **Pemilik Teknis** | Tim IT |
| **Dokumen Rujukan** | `FORMULA_DASHBOARD.md` (katalog rumus resmi) · `IT_HANDOVER.md` (operasional & keamanan) · `LARAVEL_TECHNICAL_PLAN.md` (rencana teknis pendukung) |
| **Dokumen turunan** | Dokumen ini juga menjadi basis **project tracker** (lihat bagian 10, WBS) |

**Catatan framing:** rencana ini adalah **pengembangan sistem baru dari nol** menggunakan Laravel 11 — bukan migrasi sistem lama. Katalog rumus, aturan alokasi, tata letak layar, dan sumber data yang sudah dipakai operasional dipakai sebagai **input spesifikasi**, agar sistem baru lahir dengan perilaku akuntansi yang sudah disepakati bisnis.

**Keputusan kebijakan utama:**
1. Aturan akun (master 163 akun dari `cost_matrix_definition.json`) diimplementasikan **sebagai tabel database** `cost_accounts` — tidak ada dependensi file JSON di kode.
2. **Layout mengikuti desain dashboard operasional yang berjalan** (7 tab) — pengguna tidak perlu pembelajaran ulang.
3. **Branding identik dengan project asset-tagging**: warna `#0066FF`, font Plus Jakarta Sans, logo `logo-bp.svg` PT Borneo Prima, palet slate — sudah divalidasi visual (lihat Lampiran A).

---

## 1. Tujuan & Latar Belakang

PT Borneo Prima membutuhkan sistem dashboard akuntansi yang menyatukan data GL (Microsoft Dynamics 365), metrik produksi (Google Sheets), inventory (moving average), dan laporan keuangan ke dalam satu aplikasi web internal. Sistem dibangun **dari awal** dengan Laravel sebagai platform:

1. **Akurasi terkawal** — seluruh perhitungan mengikuti katalog rumus resmi (`FORMULA_DASHBOARD.md`): aturan alokasi COGP + Direct Expense, moving average inventory, mapping P&L/Neraca, jurnal virtual accrual.
2. **Arsitektur modern** — Laravel 11 (PHP 8.2+), MySQL, struktur MVC, ORM, validasi, dan testing bawaan; diadopsi dari ekosistem yang sama dengan project asset-tagging perusahaan.
3. **Integritas data** — semua transaksi & master tersimpan di MySQL (bukan file), dengan auditability import GL.
4. **Deployment internal sederhana** — target shared hosting / server internal, tanpa autentikasi pada rilis awal (keputusan pemilik; keamanan ditangani di jaringan internal/LAN).

**Prinsip yang dipegang:**
- Angka laporan harus **terverifikasi terhadap baseline figures** proses berjalan (target selisih = 0).
- **Layout mengikuti desain yang disetujui** — tanpa perubahan struktur tab, tabel, dan form dari mockup operasional.
- Data awal (GL, metrik produksi, baseline financial, saldo inventory) di-load sekali dari data operasional resmi pada tanggal data freeze.

---

## 2. Scope

### 2.1 In-Scope

| # | Modul | Deskripsi |
|---|---|---|
| F1 | Executive Overview | 6 KPI card, alokasi biaya, top cost drivers, grafik produksi harian |
| F2 | Report Cost (COGP) | Matriks 163 akun, grup COGP + Direct Expense, search/filter, expand-collapse, export CSV, hapus GL |
| F3 | Cost Variance Analysis | Compare 2 bulan per akun + kategori, remark tersimpan |
| F4 | Cost per Metric Ton | 6 tahap (Mining, Hauling 3 rute, WP, Port, GA, Selling), monthly comparison (ABC/COGP), DB cost summary |
| F5 | Inventory Valuation | Moving average 9 lokasi, opening balance edit, grafik komposisi, ledger & riwayat |
| F6 | Financial Statements | P&L + Balance Sheet dari baseline financial + GL berjalan, rekonsiliasi inventory |
| F7 | Integration Center | Status D365 & Google Sheets, tombol sync, import GL (Excel/CSV/paste), input accrual, 15 tiket terakhir |

### 2.2 Out-of-Scope (rilis awal)

- Autentikasi/otorisasi/login (roadmap: fasilitas berikutnya, lihat IT_HANDOVER §3)
- Audit trail lengkap & multi-user locking
- Cash flow report lanjutan & budget variance (belum ada sumber data)
- Write-back ke Google Sheets
- D365 live posting (hanya pull data)

### 2.3 Standarisasi Kontrak (API & Parameter)

- URL, parameter (`period=YYYY-MM`, `mode=mtd|ytd`), payload, dan respons API distandarkan sejak desain ini (bagian 5) dan **dibekukan** sebagai kontrak agar frontend, laporan, dan integrasi berikutnya konsisten.

---

## 3. Desain Visual & Branding (Sesuai asset-tagging)

Brand token yang diambil dari `D:\apps\asset-tagging`:

| Token | Nilai | Sumber |
|---|---|---|
| Primary | `#0066FF` | `AdministratorPanelProvider` `Color::hex('#0066FF')` |
| Logo | `logo-bp.svg` | asset-tagging `public/images/logo-bp.svg` |
| Font | Plus Jakarta Sans (400–800) | `->font('Plus Jakarta Sans')` + bunny.net |
| Text utama | `#0F172A` | brand-logo blade |
| Border / garis | `#e2e8f0` | styles di login-concepts |
| Latar app | `#f1f5f9` | body token |
| Panel gelap | `#0F172A` | side panel login |
| Teks subtitel brand | uppercase, tracking `0.08em`, warna primary | brand-logo blade |
| Success / Danger | `#16A34A` / `#dc2626` | token umum |
| Radius | kartu 8–20px, pill 999px | konsistensi asset-tagging |

**Kontrak branding:**
- Header: logo BP + "PT BORNEO PRIMA" + subtitel "Accounting & Mining Cost Dashboard".
- Footer: "PT Borneo Prima • Coal Production Accounting & Cost Dashboard".
- Semua aksi utama (tombol primary, tab aktif, link) memakai `#0066FF`.
- **Layout & komponen (grid card, tabel akuntansi, modal) mengikuti desain mockup operasional;** aplikasi berjalan saat ini (dengan branding yang sudah diterapkan) menjadi acuan visual resmi — lihat Lampiran A.
- Badge sumber data (`.source-badge`) dipertahankan sebagai fitur dokumentasi hidup.

> Status: token branding di atas telah diterapkan ke mockup operasional aktif (`public/styles.css`, `public/index.html`, `public/images/logo-bp.svg`) untuk pengesahan visual sebelum development; file acuan yang sama dipakai saat membangun frontend Laravel.

---

## 4. Arsitektur & Sumber Data

### 4.1 Arsitektur

```
Browser (Blade + app.js vanilla, layout sesuai desain disetujui)
        │  fetch JSON (kontrak API §5)
        ▼
Laravel 11 (routing, validasi, controller)
        │
        ├── Services/  (implementasi kalkulasi sesuai FORMULA_DASHBOARD.md)
        │      CostMatrixService, InventoryValuationService, CostPerMtService,
        │      FinancialStatementService, ProductionService, GlParserService,
        │      AccrualService, SheetsProductionParser, SheetsDbParser
        │
        ├── MySQL (14 tabel — transaksi & master)
        │
        └── storage/app/data/ (JSON read-only: history/audit, snapshot analisis)
```

### 4.2 Peta Data: DB vs JSON vs Input

| No | Artefak | Penyimpanan | Sumber Input | Arah Data |
|---|---|---|---|---|
| 1 | GL transaksi | **DB** `gl_entries` | Upload Excel (kolom L wajib) / CSV-TSV-paste / sync OData D365 | Replace per bulan yang diimpor (upsert); accrual & histori tidak ikut terhapus |
| 2 | Metrik produksi bulanan (sheet DB) | **DB** `monthly_metrics` | Sync Google Sheets (DB) | Merge by period |
| 3 | Produksi harian (sheet Produksi) | **DB** `daily_production` | Sync Google Sheets (Produksi, kolom S = Coal Getting) | Merge by date |
| 4 | DB Cost Summary / aktivitas | **DB** `db_cost_summary` | Sync Google Sheets | Merge by entity-activity |
| 5 | Dashboard Excel, ABC Monthly, Calc COGP-COGS (snapshot analisis) | JSON read-only `storage/app/data/` | Sync Google Sheets (opsional, boleh gagal → pakai snapshot terakhir) | Replace snapshot |
| 6 | Master 163 akun (hierarki) | **DB** `cost_accounts` | Initial load dari data master COA resmi (satu kali; SALDO SELALU dari GL, master hanya nama/hierarki) | Read-only |
| 7 | Saldo awal inventory (9 lokasi) | **DB** `inventory_openings` | Form "First Period Opening Balance" (`POST /api/inventory-opening`) | Upsert, rekalkulasi bulan berikut |
| 8 | Inventory movements | **DB** `inventory_movements` | Dihitung per bulan dari GL + sheet; movement dummy via ledger input | Recalculasi per bulan |
| 9 | Baseline financial | **DB** `financial_baselines` | Initial load dari workbook financial resmi (satu kali) | Read-only |
| 10 | Konfigurasi sistem | **DB** `system_configs` | Form config `POST /api/config` (D365 creds, period) | Update; secret hanya di `.env` |
| 11 | Accrual manual | **DB** `accruals` | Form accrual `POST /api/accruals` (amount = qty × rate) | Insert/delete |
| 12 | Accrual otomatis (BLC rent, WP rent, Land/Road rent) | **DB** `accruals` dengan `source='db'` | Hitung dari metrik produksi saat sync; snapshot history → JSON read-only | Insert saat sync |
| 13 | Remarks variance & DB cost | **DB** `cost_variance_remarks`, `db_cost_remarks` | User UI (max 2000 char) | Upsert by key |
| 14 | Kapitalisasi mining & adjustment history | JSON read-only | Workbook Jan-Sep (referensi) | Read-only |

**Keputusan eksplisit:** master akun (163 akun) masuk **database** (tabel `cost_accounts`) — dipilih karena dipakai banyak endpoint (`/api/accounts`, Report Cost, drop-down accrual) dan menghindari pembacaan file tiap request.

### 4.3 Tabel Database (14)

`gl_entries`, `gl_import_batches`, `monthly_metrics`, `daily_production`, `db_cost_summary`, `system_configs`, `financial_baselines`, `inventory_openings`, `inventory_movements`, `accruals`, `accrual_history`, `cost_accounts`, `cost_variance_remarks`, `db_cost_remarks`.

Aturan kunci: `gl_entries.period` sebagai `YYYY-MM` (indexed), amount `DECIMAL(19,6)`, import GL **hanya menggantikan bulan yang diimpor** (upsert per bulan, tidak boleh membuat duplikat).

### 4.4 Initial Data Load

| Data | Asal | Metode |
|---|---|---|
| GL history | Export GL D365 (Excel) | Artisan command import (parser kolom L "Amount in reporting currency"; amount USD penuh, tanpa konversi) |
| Metrik produksi & harian | Google Sheets operasional | Sync awal via `/api/sync/sheets` |
| Baseline financial | Workbook accounting resmi | Seeder/artisan one-time loader |
| Saldo inventory & master akun | Data master resmi | Seeder one-time |

---

## 5. Kontrak API

| Endpoint | Method | Sumber Data |
|---|---|---|
| `/api/config` | GET/POST | `system_configs` |
| `/api/cost-matrix` | GET | `gl_entries` + `cost_accounts` + `accruals` + history JSON |
| `/api/production-summary` | GET | `monthly_metrics` + `daily_production` + `db_cost_summary` |
| `/api/inventory-valuation` | GET | Semua tabel inventory + `gl_entries` |
| `/api/inventory-opening` | POST | `inventory_openings` |
| `/api/cost-per-mt` | GET | cost-matrix + produksi |
| `/api/financial-statements` | GET | `financial_baselines` + `gl_entries` + `accruals` |
| `/api/upload-gl`, `/api/upload-gl-excel` | POST | parser → `gl_entries` (+ `gl_import_batches`) |
| `/api/gl-data` | DELETE | reset `gl_entries` |
| `/api/accounts` | GET | `cost_accounts` (kepala 5–9, non-parent) |
| `/api/accruals` | GET/POST/DELETE | `accruals` |
| `/api/sync/sheets`, `/api/sync/d365` | POST | parser Google Sheets / OData D365 |
| `/api/db-cost-summary`, `/api/excel-cost-example` | GET | `db_cost_summary` + snapshot JSON |
| `/api/cost-variance-remarks`, `/api/db-cost-summary/remark` | GET/POST | tabel remarks |

Semua parameter `period=YYYY-MM` dan `mode=mtd|ytd` dipertahankan konsisten; validasi dan pesan error distandarkan pada kontrak ini.

---

## 6. Fungsi Non-Teknis

| Aspek | Spec |
|---|---|
| Browser target | Chromium/Edge modern, Firefox modern |
| Responsivitas | Desktop-first; tidak diwajibkan mobile |
| Bahasa UI | Inggris; dokumen pendukung bahasa Indonesia |
| Presisi tampilan | Amount USD penuh, tanpa desimal, koma ribuan; rate 3 desimal di layar; kalkulasi internal presisi penuh |
| Performa | Halaman muat < 3 s pada GL ≈ 20k baris; import GL 5k baris < 60 s |
| Logging | Laravel log; auditability riwayat import GL di `gl_import_batches` |

---

## 7. Kriteria Penerimaan (Acceptance Criteria)

### 7.1 Verifikasi Kebenaran Angka (wajib lolos)

1. **Baseline figures** diambil dari proses berjalan (workbook accounting + GL D365) untuk periode 2026-01 … 2026-10, mode MTD dan YTD → disimpan sebagai `reference_dump/` sebagai dasar verifikasi.
2. Sistem menghasilkan nilai identik (toleransi = 0) untuk: `/api/cost-matrix`, `/api/inventory-valuation`, `/api/cost-per-mt`, `/api/financial-statements`, `/api/production-summary`, `/api/db-cost-summary` (diabaikan hanya field timestamp).
3. Unit test per rumus (PHPUnit disusun dari katalog `FORMULA_DASHBOARD.md`): distribusi kepala akun (7–9→GA, 5–6 mengikuti Costing to / Valuation to), rute hauling, Direct Expense, moving average inventory, mapping P&L/Neraca dengan jurnal virtual accrual (Dr/Cr 1530003) — semua pass.
4. Gap rekonsiliasi yang telah diketahui **USD 3.001** (neraca Oktober, sesuai temuan IT_HANDOVER §6) harus muncul dengan angka yang sama — bukti implementasi aturan yang jujur & konsisten, bukan perbedaan yang dibuang diam-diam.

### 7.2 UAT Fungsional (Skenario Pengguna)

| UAT No | Skenario | Harapan |
|---|---|---|
| UAT-01 | Upload `sample_gl_dynamics365.xlsx` | Row count & sum per akun = baseline |
| UAT-02 | Import CSV 2 bulan | Hanya 2 bulan tsb yang tergantikan; bulan lain di DB tetap utuh |
| UAT-03 | Paste TSV + koma lokal (1.234,56), "(19,166)" | Dikonversi utuh tanpa perubahan nilai |
| UAT-04 | Import file kosong/file tidak valid | Tolak dengan pesan error, tidak merusak DB |
| UAT-05 | Tambah accrual manual | Amount di Report Cost & FS muncul persis; jurnal virtual Dr/Cr 1530003 di FS |
| UAT-06 | Hapus accrual | Report Cost & FS dihitung ulang |
| UAT-07 | Edit opening balance | Seluruh bulan berikutnya dikalkulasi ulang; grafik & card ter-update |
| UAT-08 | Hapus GL | Saldo reset; histori workbook & accrual tetap |
| UAT-09 | Sync Google Sheets | Metrik & daily diperbarui, accrual otomatis dibuat, snapshot history tersimpan |
| UAT-10 | Simpan remarks variance & DB cost | Setelah reload, nilai tersimpan |
| UAT-11 | Export CSV Report Cost | 163 akun (termasuk akun saldo 0) dengan grand total |
| UAT-12 | Search/expand/filter Report Cost | Sesuai desain yang disetujui |
| UAT-13 | Ganti mode MTD↔YTD | Semua tab bergerak sesuai |
| UAT-14 | Refresh Ctrl+F5 | Tidak ada regresi |

### 7.3 Branding acceptance

- Header/footer memakai logo BP + "PT Borneo Prima" + subtitel blue uppercase.
- Warna primary `#0066FF` konsisten pada tab aktif, tombol utama, KPI cost card.
- Screenshot perbandingan vs asset-tagging dilampirkan saat UAT (lihat Lampiran A).

---

## 8. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi | Pemilik |
|---|---|---|---|---|
| R1 | Presisi angka (PHP float) | Angka laporan meleset | Wajib bcmath/DECIMAL(19,6); UAT-01..07 | Dev |
| R2 | Kompleksitas aturan alokasi (kepala 7-9→GA, rute hauling, Not Valuated→GA, Direct Expense) | Alokasi COGP salah | Unit test per rumus dari katalog + verifikasi baseline | Dev |
| R3 | Parser Excel kolom L berbeda interpretasi | Import salah | Unit test file riil + compare sum per akun | Dev |
| R4 | Shared hosting limit (timeout/memory/composer) | Deployment gagal | Pre-check awal; build vendor lokal; chunk insert | IT |
| R5 | Google Sheets sharing/IP blocking | Sync gagal | Cek akses sebelum fase 6; fallback import manual CSV | IT |
| R6 | Data operasional berubah selama pengembangan | Baseline figures kadaluarsa | Data freeze tanggal tetap; baseline figure di-refresh terjadwal; label tiap verifikasi | Dev+Acc |
| R7 | Kebocoran credential (D365 secret) | Data leak | Secret hanya di `.env`, tidak pernah masuk kode/repo; rotasi secret setelah go-live | IT |

---

## 9. Estimasi & Timeline (dalam hari kerja)

### 9.1 Estimasi per Fase

| Fase | Durasi (hari kerja) | Isi ringkas |
|---|---|---|
| P0 Persiapan | 0,5 hari | Finalisasi FDD + kumpulkan baseline figures & data operasional awal |
| P1 Fondasi | 1,5 hari | Project Laravel + 14 migration/seeder + branding dasar |
| P2 Data Layer | 2,5 hari | Seeder master + importer GL + initial data load |
| P3 Engine | 4 hari | Implementasi 6 service kalkulasi sesuai FORMULA_DASHBOARD.md (angka = baseline) |
| P4 API | 1,5 hari | 18 endpoint sesuai kontrak §5 |
| P5 Frontend | 2,5 hari | Blade + asset + branding final (layout desain disetujui) |
| P6 Sheets Sync | 1,5 hari | Parser 5 sheet + sync endpoint |
| P7 Validasi & UAT | 2,5 hari | Verifikasi angka vs baseline + UAT-01..14 |
| P8 Deploy | 1,5 hari | Shared hosting + go-live |
| **Total** | **18 hari kerja** | (2 dev: 10–12 hari kerja) |

### 9.2 Estimasi Kalender

| Skenario | Beban | Durasi Efektif | Durasi Kalender (± buffer 15%) |
|---|---|---|---|
| 1 developer (fokus penuh) | 18 hari kerja | 18 hari kerja | **± 4,5 minggu** (5 minggu dengan buffer) |
| 2 developer (paralel P3 & P5/P6) | 10–12 hari kerja | 11 hari kerja | **± 2,5–3 minggu** |
| 1 developer (paruh waktu, ½ effort) | 18 hari kerja | 36 hari kerja | ± 9 minggu (tidak direkomendasikan) |

> Titik mulai mengikuti tanggal persetujuan FDD (keputusan D5 — data freeze). Setiap hari kerja = Senin–Jumat, tidak termasuk libur nasional.

### 9.3 Milestone (offset dari awal proyek, skenario 1 dev)

| Milestone | Fase | Target Hari Ke- | Kriteria Kelulusan |
|---|---|---|---|
| **M1** | Akhir P2 | **Hari ke-4,5** | Data operasional masuk DB & tampil di dashboard — demo sebelum lanjut engine |
| **M2** | Akhir P3 | **Hari ke-8,5** | Semua angka laporan = baseline figures — gate paling kritis |
| **M3** | Akhir P7 | **Hari ke-16,5** | Verifikasi zero-diff + UAT lengkap — **GO/NO-GO** |
| **M4** | Akhir P8 | **Hari ke-18** | Live di shared hosting |

---

## 10. WBS — Basis Project Tracker

Kolom status (`— / 🔄 / ✅`) dimutakhirkan setiap hari. Struktur ini siap dikutip ke Jira/Trello/Notion.

| WBS | Task | Depend | Owner | Est (hari kerja) | Status |
|---|---|---|---|---|---|
| 0.1 | Finalisasi FDD + pengumpulan baseline figures (`reference_dump/`) | – | Dev+Acc | 0,3 | — |
| 0.2 | Backup/snapshot data operasional (GL export, sheets) sebagai initial load | 0.1 | Dev | 0,2 | — |
| 1.1 | `composer create-project` Laravel 11 | 0.1 | Dev | 0,3 | — |
| 1.2 | Migration 14 tabel (+ index) | 1.1 | Dev | 0,7 | — |
| 1.3 | Model Eloquent + relasi | 1.2 | Dev | 0,3 | — |
| 1.4 | Konfigurasi .env (cache file, queue sync) | 1.1 | Dev | 0,2 | — |
| 1.5 | Setup branding token #0066FF + font + logo (validasi visual) | – | Dev+UI | 0,2 | **✅** |
| 2.1 | Seeder `CostAccountSeeder` (163 akun → `cost_accounts`) | 1.3 | Dev | 0,4 | — |
| 2.2 | Seeder baseline (financial, inventory opening, history files) | 1.3 | Dev | 0,5 | — |
| 2.3 | `GlParserService` (cleanNumeric + delimiter + xlsx kolom L) | 1.3 | Dev | 0,8 | — |
| 2.4 | `/api/upload-gl` + `/api/upload-gl-excel` + batch log | 2.3 | Dev | 0,6 | — |
| 2.5 | Initial data load (GL, metrik produksi) via artisan command | 2.4 | Dev | 0,3 | — |
| 3.1 | `CostMatrixService` (aturan COGP + Direct Expense) | 2.5 | Dev | 1,2 | — |
| 3.2 | `InventoryValuationService` (moving average 9 lokasi) | 3.1 | Dev | 1,2 | — |
| 3.3 | `AccrualService` (manual + auto rentals) | 3.1 | Dev | 0,5 | — |
| 3.4 | `ProductionService` + parser sheet DB | 2.5 | Dev | 0,6 | — |
| 3.5 | `CostPerMtService` (6 stage) | 3.1,3.4 | Dev | 0,3 | — |
| 3.6 | `FinancialStatementService` (P&L + Neraca + jurnal virtual accrual) | 3.1,3.3 | Dev | 1,0 | — |
| 3.7 | PHPUnit test per rumus + verifikasi vs baseline figures | 3.1–3.6 | Dev | 0,7 | — |
| 4.1 | Routing + controllers + form request validasi (18 endpoint) | 3.7 | Dev | 1,0 | — |
| 4.2 | Standarisasi error/exception sesuai kontrak §5 | 4.1 | Dev | 0,3 | — |
| 5.1 | Blade layout + 7 tab partial (layout desain disetujui) | 1.5,4.1 | Dev | 1,2 | — |
| 5.2 | Asset frontend (app.js, styles.css, badge sumber data) | 5.1 | Dev | 0,8 | — |
| 5.3 | Uji UI manual semua tab | 5.2 | Dev+Acc | 0,5 | — |
| 6.1 | `SheetsProductionParser` + `SheetsDbParser` (gviz) | 3.4 | Dev | 0,7 | — |
| 6.2 | `/api/sync/sheets` (5 sheet + fallback snapshot + accrual otomatis) | 6.1 | Dev | 0,6 | — |
| 6.3 | `/api/sync/d365` OData connector | – | Dev | 0,3 | — |
| 7.1 | Verifikasi otomatis vs baseline figures (period 01-10, MTD/YTD) | 5.3,6.2 | Dev | 1,0 | — |
| 7.2 | UAT-01..14 sesuai bagian 7.2 | 7.1 | Acc+Dev | 1,0 | — |
| 7.3 | Screenshot branding comparison | 5.3 | UI | 0,2 | — |
| 8.1 | Pre-check shared hosting (PHP ext, composer, HTTPS out) | – | IT | 0,3 | — |
| 8.2 | Deploy + migrate + seed + .env | 7.2 | IT | 0,5 | — |
| 8.3 | Smoke test go-live | 8.2 | IT | 0,3 | — |
| | **Total estimasi** | | | **±19 hari kerja** | |

**Milestone checkpoint:**
- **M1** (akhir P2): "Data operasional masuk DB & tampil" — demo sebelum lanjut engine.
- **M2** (akhir P3): "Semua angka = baseline figures" — **gate paling kritis**.
- **M3** (akhir P7): "Verifikasi zero-diff + UAT lengkap" — **GO/NO-GO**.
- **M4** (akhir P8): "Live di shared hosting".

---

## 11. Keputusan yang Dibutuhkan dari Pemilik (Untuk Pengesahan FDD)

| # | Keputusan | Opsi | Disetujui (isi) |
|---|---|---|---|
| D1 | Estimasi 18 hari kerja (1 dev) / 10-12 hari kerja (2 dev), total WBS ±19 hari | Terima / ubah scope | |
| D2 | Autentikasi ditunda ke release berikutnya (sesuai keputusan awal) | Terima / wajibkan sekarang | |
| D3 | Branding sesuai asset-tagging (#0066FF + Jakarta Sans + logo BP) | Terima / ubah | |
| D4 | Shared hosting: pastikan PHP 8.2+, MySQL, outbound HTTPS | Konfirmasi ketersediaan | |
| D5 | Tanggal data freeze & sumber baseline figures (periode 2026-01..2026-10) | Tentukan tanggal | |
| D6 | D365 sync tipe pull saja (tombol status/sync manual, tanpa live posting) | Terima | |

**Approval sign-off:**

| Peran | Nama | Tanda Tangan | Tanggal |
|---|---|---|---|
| Pemilik Bisnis (Accounting) | ___________ | ___________ | ____ |
| Pemilik Teknis (IT) | ___________ | ___________ | ____ |
| Project Manager | ___________ | ___________ | ____ |

---

*Lampiran: screenshot dashboard acuan visual (Lampiran A), `LARAVEL_TECHNICAL_PLAN.md` (rencana teknis pendukung), `FORMULA_DASHBOARD.md` (rumus resmi), `IT_HANDOVER.md` (operasional & keamanan).*
