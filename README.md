# FinOps-Dashboard — PT Borneo Prima Accounting & Mining Cost Dashboard (Laravel)

Folder pengembangan terpisah untuk implementasi FDD `BP-LARAVEL-DASH` (dibangun dari nol dengan Laravel 11).
Dokumen acuan: `reference/docs/FDD_LARAVEL_SYSTEM.md` (Bahasa Indonesia, sumber utama).

## Struktur Folder

| Folder | Isi | Status |
|---|---|---|
| `reference/docs/` | FDD (ID+EN), rencana teknis, FORMULA_DASHBOARD.md (rumus resmi), IT_HANDOVER.md, screenshot acuan branding | ✅ |
| `data_snapshot/` | Backup ZIP + extracted frozen JSON (tgl 2026-10-30) — sumber initial data load | ✅ |
| `baseline/reference_dump/` | **Baseline figures** — output API operasional utk periode 2026-01..2026-10 (MTD+YTD); standar angka verifikasi & test PHPUnit | 🔄 (sedang di-dump) |
| `baseline/dump_baseline.js` | Script pemerah baseline (bisa dijalankan ulang setelah data freeze refresh) | ✅ |
| `laravel/` | Project Laravel 11.57.1 + MySQL `finops_dashboard`, 14 tabel, 14 model, P2 data layer | ✅ (P1–P2 selesai) |
| `prompt_kit/` | Konteks & instruksi siap-pakai untuk setiap fase coding dengan AI | ✅ (P1–P2) |
| `test/` | Script verifikasi diff (Laravel vs baseline figures) | ❌ belum |

## Fire akses environment

- **PHP 8.3.3 + Composer** → sudah tersedia (Laragon: `C:\laragon`)
- **Node.js 24** → tersedia (untuk script baseline & tooling)
- **Database MySQL** → `finops_dashboard` (Laragon MySQL, root tanpa password default)
- **Server aplikasi acuan (operasional)** → http://localhost:3000 (port 3000, jangan diutak-atik datanya)

## Aturan Emas

1. **Angka = baseline figures.** Semua output engine wajib identik dengan `baseline/reference_dump/` — toleransi 0.
2. **Layout mengikuti desain acuan** (screenshot di `reference/docs/`) — tidak boleh diubah-ubah tanpa persetujuan.
3. **Branding asset-tagging**: #0066FF · Plus Jakarta Sans · logo-bp.svg.
4. **Data freeze: 2026-10-30.** Folder `data_snapshot/` dianggap read-only setelah tanggal ini.
5. Fase & gate mengikuti FDD §9–10 (M1 hari ke-4,5 · M2 hari ke-8,5 · M3 GO/NO-GO · M4 live).
