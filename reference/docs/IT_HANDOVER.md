# Handover IT — Mining Accounting Dashboard

Dokumen ini menjelaskan implementasi pada workspace per 29 Oktober 2026. Dokumen ini disiapkan untuk peninjauan tim IT; aplikasi belum dinyatakan siap produksi. Rincian rumus tersedia pada [FORMULA_DASHBOARD.md](FORMULA_DASHBOARD.md).

## 1. Tujuan dan fungsi

Dashboard web internal untuk Report Cost (COGP dan Direct Expense), analisis variance antarbulan, cost per metric ton, valuasi persediaan, financial statement, impor GL, accrual dan data produksi dari Google Sheets. Tampilan memakai bahasa Inggris dan satuan pelaporan USD penuh serta MT.

## 2. Komponen dan menjalankan lokal

- Backend: Node.js CommonJS, HTTP server bawaan dan modul native; entry point `server.js`. Frontend: HTML/CSS/JavaScript di `public/`, tanpa proses build.
- Data dan konfigurasi runtime: file JSON di `data/`. Skrip perhitungan, impor, parser XLSX dan pengujian di `scripts/`.
- Jalankan `node server.js`; secara default port **3000**, dapat diubah dengan environment variable `PORT`. Pengujian sebelumnya menggunakan **3001**. `start-dashboard.bat` menjalankan `node server.js` dan tidak menetapkan port.
- Perubahan backend memerlukan restart proses Node. Perubahan HTML/CSS/JS memerlukan reload browser. Pastikan hanya ada satu proses pada port tersebut.
- Jalankan pengujian `npm test` pada salinan lingkungan uji. Node 24 digunakan dalam sesi pengembangan; tim IT perlu menetapkan versi Node LTS, melakukan uji kompatibilitas dan memelihara pembaruan keamanan.
- `package.json` tidak menyatakan dependensi runtime npm eksternal. Aplikasi membaca data lokal dan memerlukan koneksi keluar HTTPS untuk sinkronisasi Google Sheets atau D365 yang dikonfigurasi.

## 3. Kondisi keamanan saat ini — sebelum hosting bersama

**Belum ada autentikasi, role/permission, isolasi pengguna atau audit trail.** Endpoint sensitif dapat dipanggil tanpa login: impor dan hapus GL, perubahan konfigurasi, saldo awal inventory, accrual, remark dan sinkronisasi. Respons API mengizinkan CORS `*`; API konfigurasi dapat mengembalikan isi konfigurasi. Aplikasi menggunakan HTTP dan mendengarkan port tanpa pembatasan host eksplisit. Jangan membuka instance ini langsung ke internet atau jaringan luas dalam kondisi sekarang.

Rekomendasi implementasi IT sebelum go-live:

1. SSO perusahaan (misalnya OIDC/Entra ID) dan session aman; TLS melalui reverse proxy, hostname internal dan kontrol akses jaringan. Jangan mengandalkan menyembunyikan tombol sebagai kontrol akses.
2. Otorisasi **di setiap endpoint server**, termasuk proteksi CSRF untuk sesi berbasis cookie dan kebijakan CORS yang dibatasi. Contoh role untuk disepakati: Viewer, Analyst (remark), Accounting Editor (impor/accrual), Administrator (konfigurasi). Tentukan izin ekspor dan hapus secara eksplisit.
3. Validasi unggahan: batas ukuran, tipe dan konten, pembatasan file XLSX/ZIP serta jumlah baris. Terapkan rate limiting dan keamanan header. Lindungi data sensitif dan terapkan pembatasan akses file.
4. Jangan menyimpan atau menampilkan token, secret, service-account key atau nilai konfigurasi sensitif di frontend, file yang dikirim, log atau repositori. Pindahkan ke secret manager atau environment dengan izin terbatas; ubah endpoint konfigurasi agar hanya mengembalikan field yang aman. Tinjau dan rotasi kredensial jika pernah tersebar.
5. Audit pengguna, waktu, periode dan hasil untuk impor, hapus GL, edit saldo awal, accrual, reversal, remark, sinkronisasi dan konfigurasi; pisahkan log audit dari data operasional.
6. Database transaksional diperlukan untuk multi-user: saat ini banyak file JSON ditulis utuh dan perubahan bersamaan berisiko saling menimpa. Impor GL menggunakan file sementara dan rename, tetapi itu tidak membuat seluruh operasi lain transaksional. Tambahkan locking/concurrency, migrasi data, indeks dan mekanisme rollback yang diuji.
7. Backup terenkripsi, retensi, pengujian restore, monitoring, alert, service restart, disaster recovery dan staging terpisah. Tetapkan pemilik layanan dan penanggung jawab akuntansi.

## 4. Data, persistensi dan handover

`data/` berisi data finansial sensitif; berikan hanya melalui kanal resmi yang aman, sesuai izin perusahaan. Jangan menempelkan isi GL, config atau kredensial ke tiket publik. Minta persetujuan pemilik data untuk data produksi dan buat dataset tersamarkan untuk staging.

| Berkas | Kegunaan |
| --- | --- |
| `gl_data.json` | Seluruh GL hasil impor; impor sukses mengganti semua baris GL. |
| `cost_matrix_definition.json` | Master akun dan hierarki Report Cost. |
| `cost_report_history.json`, `mining_capitalization_history.json`, `cost_report_adjustments.json` | Biaya historis dan penyesuaian laporan biaya. |
| `financial_baseline.json` | Dasar financial statement/TB yang disediakan pengguna. |
| `inventory_model.json`, `inventory_movements.json`, `inventory_movements_dummy.json`, `inventory_historical_costs.json` | Saldo awal, histori, dummy dan biaya untuk valuasi persediaan. |
| `production_daily_sheets.json` | Snapshot sinkronisasi produksi, DB dan sheet analisis; bukan write-back ke Google Sheets. |
| `accruals.json`, `automatic_accrual_history.json` | Accrual manual dan snapshot otomatis per bulan. |
| `cost_variance_remarks.json`, `db_cost_remarks.json` | Remark yang dibuat pengguna; file dapat belum ada sebelum penyimpanan pertama. |
| `config.json` | Konfigurasi dan status; perlakukan sebagai sensitif. |

Sertakan `server.js`, `package.json`, `public/`, `scripts/`, `data/` yang telah disetujui, dan dokumentasi ini. Folder `analysis/` berisi sumber/hasil analisis yang mungkin sensitif; tidak diperlukan untuk menjalankan server, tetapi dibutuhkan jika tim hendak menelusuri sumber historis. Periksa izin dan kebutuhan masing-masing file sebelum dibagikan. README lama mengandung informasi yang sudah tidak sesuai, termasuk port, perilaku impor dan Refeed; gunakan dokumen ini dan kode terbaru sebagai rujukan sampai README diperbarui.

## 5. Operasi dan integrasi

**Impor GL:** XLSX, CSV/TSV atau data ditempel. Header `Amount in reporting currency` wajib, untuk XLSX harus di kolom L. Baris divalidasi sebelum penggantian GL. Impor berhasil mengganti **seluruh** GL yang tersimpan, bukan menambah bulan; accrual dan data histori terpisah tetap ada. Data kosong/gagal tidak dimaksudkan mengganti GL. Siapkan backup dan prosedur rollback sebelum impor. Hapus GL mengosongkan `gl_data.json`, bukan seluruh sumber histori atau accrual.

**Google Sheets:** endpoint `POST /api/sync/sheets` membaca sheet Produksi, DB, Dashboard Excel, ABC Monthly dan Calc COGP-COGS. Produksi/DB tervalidasi; beberapa sheet pembanding opsional memakai snapshot lama bila gagal, sehingga perlu status kesegaran per sumber. Sinkronisasi saat ini dipicu manual; jangan menganggap konfigurasi interval sebagai scheduler yang sudah berjalan. ID sheet dan tahun pemetaan 2026 saat ini tertanam dalam kode. Tidak ada implementasi write-back otomatis ke Google Sheet. Jika dibutuhkan, IT perlu mendesain autentikasi server, hak akses terbatas, mapping sel, penanganan perubahan struktur, audit, serta transaksi/idempotensi; jangan menggunakan kredensial konektor chat sebagai kredensial server.

**Dynamics 365:** terdapat jalur percobaan client-credentials/OData pada `/api/sync/d365`, dan mode tanpa secret hanya memberi status kesiapan; itu bukan bukti koneksi live atau data berhasil disinkronkan. Verifikasi mapping field, scope, pagination, jumlah data, waktu transaksi, retry dan rekonsiliasi di sandbox D365 sebelum implementasi produksi. Periksa kebijakan keamanan dan jangan menggunakan kredensial contoh.

**Accrual:** accrual otomatis untuk bulan aktif, termasuk snapshot saat sync, dan accrual manual ikut Report Cost; FS membuat jurnal simulasi debit akun biaya dan kredit 1530003. Reversal dilakukan manual. Pastikan proses penutupan bulan, koreksi, snapshot dan deteksi duplikasi antara GL/tagihan dan accrual disetujui oleh Accounting. Jurnal dashboard bukan posting otomatis ke D365.

## 6. Validasi penerimaan dan hal yang perlu dituntaskan

- Bandingkan saldo per akun/lokasi, histori Jan–Sep, Oktober dan YTD terhadap GL/TB dan workbook referensi; uji saat impor berulang, gagal, file beberapa bulan dan pemisahan accrual.
- Rekonsiliasi neraca terhadap TB dan selisih diagnostik. Selisih **USD 3,001** sempat terlihat pada pemeriksaan Oktober 2026; harus diverifikasi ulang dengan data terbaru dan ditelusuri sebelum laporan dipakai untuk keputusan.
- Validasi perlakuan akuntansi accrual biaya produksi vs inventory/COGS, sign convention, reversal dan pengakuan laba rugi dengan Accounting. Inventory saat ini menghitung biaya GL melalui `computeCostMatrix(null, rows)` dan tidak otomatis memasukkan accrual dashboard yang hanya ditambahkan pada Report Cost/FS.
- Verifikasi qty per counterpart HRS/SMA/BP dan basis Cost/MT; sebagian volume belum tersedia per entitas. Ketika data DB tidak tersedia, kode masih memiliki persentase fallback volume lama; ini perlu diputuskan untuk produksi.
- Verifikasi klasifikasi activity/component pada DB, satuan dan tarif, mapping COA dan perlakuan biaya yang belum teralokasi; kontrol tahun sheet yang saat ini tetap 2026.
- Inventory memiliki periode biaya sementara Excel, data dummy/placeholder dan data sheet; tampilan menandai sebagian hasil sebagai provisional. Kunci/approval penutupan periode belum tersedia sebagai kontrol akses umum.
- Cash flow lanjutan belum diimplementasikan; varians anggaran belum memiliki sumber budget. Jangan menampilkan keduanya sebagai tersedia.
- Sepakati database, kebijakan akses file dan kredensial, proses deploy, backup/restore, owner akuntansi, test UAT dan tanggal go-live.

## 7. Endpoint utama untuk integrasi

Read: `/api/cost-matrix`, `/api/cost-per-mt`, `/api/production-summary`, `/api/inventory-valuation`, `/api/financial-statements`, `/api/accounts`, `/api/accruals`, `/api/db-cost-summary`, `/api/excel-cost-example`, `/api/cost-variance-remarks`. Query `period=YYYY-MM` dan `mode=mtd|ytd` tersedia pada endpoint dashboard terkait.

Write: `/api/upload-gl`, `/api/upload-gl-excel`, `/api/gl-data` (DELETE), `/api/accruals`, `/api/inventory-opening`, `/api/cost-variance-remarks`, `/api/db-cost-summary/remark`, `/api/config`, `/api/sync/sheets`, `/api/sync/d365`. Kontrak respons/validasi yang definitif ada pada `server.js`. Semua endpoint ini harus dilindungi sebelum multi-user.
