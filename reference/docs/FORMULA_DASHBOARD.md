# Katalog rumus dan sumber kolom dashboard

Versi pemeriksaan: 29 Oktober 2026. Dokumen ini menjelaskan **perilaku kode saat ini**, bukan pengesahan kebijakan akuntansi. Untuk hosting dan batasan keamanan lihat [IT_HANDOVER.md](IT_HANDOVER.md). Tim Accounting perlu menyetujui rumus dan merekonsiliasinya dengan GL, TB, workbook, serta data timbangan sebelum produksi.

## 1. Prinsip umum

- Amount GL = nilai **Amount in reporting currency** (kolom L pada XLSX). Tidak menggunakan kolom I/J/K sebagai pengganti, termasuk jika L nol/kosong. Reporting currency diperlakukan sebagai **USD penuh**, tanpa konversi kurs dan tanpa pengali ribuan.
- Qty batubara dalam MT; OB/Waste dalam BCM sesuai sumber. Perhitungan mempertahankan presisi yang tersimpan; pembulatan pada layar tidak mengubah perhitungan. Umumnya amount dan qty tanpa desimal, sementara rate/persentase pada Cost per MT dan rate accrual ditampilkan tiga desimal. Pemisah ribuan adalah koma.
- **MTD** = bulan terpilih. **YTD** = penjumlahan Januari sampai bulan terpilih untuk transaksi/pergerakan; pada inventory posisi akhir tetap closing bulan terpilih. P&L YTD mengikuti tahun kalender. Perbandingan bulan di tabel tertentu tetap MTD walaupun mode global YTD.
- Parent akun = jumlah akun anak yang memiliki parent tersebut. Grand total = jumlah akun detail/leaf, agar parent dan anak tidak dihitung dua kali.
- Data kosong dan data nol tidak selalu sama. Beberapa tampilan menampilkan `—` ketika data pembagi tidak ada atau nol. Data sumber tertentu memang dapat menyimpan nol; jangan menyimpulkan bahwa nol berarti data telah lengkap.

## 2. Report Cost — COGP dan Direct Expense

Sumber: `computeCostMatrix` di `server.js`; definisi dan hierarki akun di `data/cost_matrix_definition.json`. Hanya akun kepala 5–9 masuk laporan, tetapi akun lain tetap dapat tersimpan dalam GL. Untuk bulan dengan GL, transaksi GL bulan itu menggantikan biaya histori Report Cost pada bulan yang sama; bulan tanpa GL dapat memakai histori workbook. Accrual dashboard ditambahkan terpisah. Histori kapitalisasi dan adjustment berlaku pada bulan histori yang tidak digantikan GL.

Untuk akun kepala **7–9**, seluruh amount dialokasikan ke GA tanpa memandang Costing to/Valuation to. Untuk kepala **5–6**:

| Kolom laporan | Aturan jumlah amount transaksi per akun dan periode |
| --- | --- |
| COGP Mining | `Costing to` Mining/Pit. |
| COGP Coal Hauling BUA to WP | `Costing to` Coal Hauling dan rute BUA → WP. |
| COGP Coal Hauling BUA to Port | `Costing to` Coal Hauling dan rute BUA → Port. |
| COGP Coal Hauling WP to Port | `Costing to` Coal Hauling dan rute WP → Port. |
| COGP Washing Plant | `Costing to` Wash/WP. |
| COGP BP PORT | `Costing to` Port/Jetty. |
| Direct Expense Mining, Coal Hauling, Washing Plant, BP PORT | Hanya ketika `Costing to = Direct Expense`; alokasi sesuai `Valuation to`. |
| Direct Expense GA | `Valuation to = Not Valuated` pada Direct Expense **ditambah** Costing to GA/Admin/Overhead serta akun kepala 7–9. |
| Selling Expense | Costing to Selling/Marketing, setelah aturan kepala 7–9. |

**Check** (`cek`) pada implementasi saat ini bernilai **0**; bukan perhitungan rekonsiliasi. `Total COGP = Mining + seluruh Coal Hauling + Washing Plant + BP PORT`. `Total Direct/period expense = lima bucket Valuation to + Costing GA + Selling`. `ALL Cost = total Valuation + total Costing`, per akun. Kolom tiga rute adalah rincian dari total hauling, bukan tambahan biaya di atasnya.

`Unallocated hauling = total Costing Coal Hauling − (BUA→WP + BUA→Port + WP→Port)`. Jika rute tidak dikenal, nilai masih masuk total hauling/ALL Cost, tetapi tidak masuk kolom rute tertentu. Jika Costing to atau Valuation to tidak dikenali, transaksi dapat tidak teralokasi pada bucket sehingga total tampil bukan otomatis sama dengan jumlah seluruh amount GL; review dimensi dan notifikasi sumber.

## 3. Cost Variance Analysis — per akun dan lokasi

Sumber dua kali pembacaan Report Cost **MTD**: bulan dipilih dan bulan kalender sebelumnya. September dapat memakai workbook historis dan Oktober GL/accrual, sesuai data tersedia. Lokasi/kategori mengikuti kolom pada bagian 2, plus ALL Cost.

| Kolom | Rumus / perilaku |
| --- | --- |
| Previous month | Nilai akun dan kategori pada bulan sebelumnya. |
| Current month | Nilai akun dan kategori pada bulan terpilih. |
| Variance USD | `Current − Previous`. |
| Variance % | `(Current − Previous) ÷ abs(Previous) × 100`. Previous nol/tidak tersedia: `—`. |
| Remark | Teks manual, disimpan dengan kunci `(bulan terpilih, kategori, kode akun)` di `cost_variance_remarks.json`; tidak mengubah nilai biaya. |

Persentase kenaikan merah, penurunan hijau. Filter pencarian/zero memengaruhi baris terlihat, bukan grand total. Budget dalam contoh yang dikirim pengguna belum menjadi data atau rumus dashboard.

## 4. Produksi dan Executive Overview

`production-summary`: DB sheet menyediakan angka bulanan; volume YTD = jumlah bulan tersedia. Bila kolom Coal Getting DB bulan berjalan kosong, nilai harian sheet Produksi (`Coal Getting Actual`, kolom S) dapat mengisi bulan tersebut. Pergerakan sheet Produksi dijumlahkan berdasarkan kolom rute; baris recap akhir bulan tidak dijumlahkan sebagai hari lain. Feed/reject harian yang berulang memakai nilai recap, bukan dijumlahkan per hari. Worksheet DB ditetapkan tahun 2026 dalam parser saat ini. Data DB kosong tidak otomatis sama dengan nol.

KPI:

- `Total ALL Cost` = grand total `allCost` Report Cost.
- `Total Production Volume` = Coal Getting DB untuk periode atau sumber produksi fallback; YTD menjumlahkan bulan. `Total Netto KG ÷ 1,000` hanya pada jalur data tiket lama, tidak mengalikan angka sheet yang sudah MT.
- `Average Cost/MT = ALL Cost ÷ Coal Getting MT`, hanya jika pembagi tersedia dan bukan nol; volume YTD yang tidak lengkap menonaktifkan rate.
- `Total Inventory Value = jumlah ending value seluruh lokasi inventory`.
- `Gross Profit` dan `Operating Profit / Before Interest` menggunakan financial statement bagian 8. Nilai biaya KPI dari Report Cost tidak otomatis sama dengan nilai P&L buku.
- Top Cost Drivers adalah jumlah `allCost` akun leaf berdasarkan kelompok kode dalam `computeCostPerMT`, bukan jumlah parent plus anak.

## 5. Cost per Metric Ton

Pada tabel **Coal Mining Cost by Stage**:

| Tahap | From Valuation to | From Costing to | Total Cost | Pembagi Stage Cost/MT (jika DB tersedia) |
| --- | --- | --- | --- | --- |
| Mining | `valMining` | `costMining` | keduanya dijumlahkan | Coal Getting MT |
| Coal Hauling | `valHauling` | `costHauling` | keduanya dijumlahkan | MT BUA→WP + BUA→Port + WP→Port |
| Washing Plant | `valWP` | `costWP` | keduanya dijumlahkan | Feed Washing Plant MT |
| BP PORT | `valPort` | `costPort` | keduanya dijumlahkan | Coal Loading Port (BLC) MT |
| General Admin | `valNotValuated` | `costGA` | `directGA` | Coal Getting MT |
| Selling Expense | 0 | `costSelling` | `costSelling` | Coal Loading Port (BLC) MT |

`Stage Cost/MT = Total Cost tahap ÷ volume tahap`; `Cost/MT Total Production = Total Cost tahap ÷ Coal Getting MT`. Pembagi nol/tidak tersedia menghasilkan nilai tidak tersedia. Untuk YTD, volume tahap harus tersedia untuk seluruh bulan yang dibutuhkan; pembagi Coal Hauling memerlukan tiga rute. Kode fallback lama ketika DB tidak ada menggunakan perkiraan persentase dari total: Mining 45%, hauling 85%, washing 35%, port 40%, kecuali ada volume kategori tiket yang dipakai. **Ini asumsi sistem lama, bukan angka timbangan terverifikasi; perlu diputuskan sebelum produksi.**

### Monthly Cost Comparison

Bulan pilihan dibandingkan dengan bulan kalender sebelumnya. Januari dibanding Desember tahun sebelumnya; sumber ABC saat ini hanya dipetakan ke 2026, sehingga bulan luar cakupan dapat tidak tersedia.

- Historis sebelum Oktober 2026: biaya dan qty aktivitas/counterpart berasal dari snapshot `ABC Monthly`. `Rate = total cost aktivitas atau counterpart ÷ total qty` ketika qty positif. Qty counterpart yang tidak tersedia tidak ditebak.
- Mulai Oktober, biaya aktivitas/counterpart diambil dari Cost Summary dashboard dan qty aktivitas dari metrik produksi dashboard jika tersedia. Metrik yang digunakan di `public/app.js` antara lain OB = Blasting + Freedig, Coal Getting = Coal Getting, hauling sesuai rute, Washplant = Wash Coal, Loading BLC = Port Loading, dan overhead BUA/JKT = Coal Getting. Untuk counterpart dengan lebih dari satu entitas, volume per entitas tidak otomatis dibagi dari total rute.
- `Change USD/unit = Current rate − Previous rate`; `Change % = Change ÷ abs(Previous rate) × 100`, bila pembagi tersedia dan bukan nol. Warna merah untuk kenaikan, hijau untuk penurunan. Baris remark pada tabel ABC contoh saat ini belum menyimpan teks; remark yang dapat disimpan tersedia pada DB Cost Summary dan Cost Variance Analysis.
- **COGP Comparison** mengambil rate dari snapshot sheet `Calc COGP-COGS`; belum dihitung ulang secara lengkap dari GL dashboard. Nol mulai Oktober dianggap tidak tersedia pada tampilan. Bandingkan dengan workbook: sumber ini tidak selalu sama dengan card Cost/MT tahap di atas.

### DB Cost Summary by Activity

Ringkasan menjumlahkan baris per aktivitas; Entity Breakdown mengelompokkan aktivitas per BP/HRS/SMA. Komponen berulang dari sheet dijumlahkan sekali tiap baris. Jika ada komponen tanpa nilai bulan, total kelompok ditampilkan tidak tersedia. Angka per bulan sumber DB diubah pada tampilan dashboard untuk bulan Oktober dan seterusnya **yang memiliki GL**:

- BP Overhead BUA diganti `Direct Expense Mining` dari Report Cost; BP OVH JKT & PC diganti `directGA`.
- Direct Expense Washing Plant ditambahkan satu kali ke BP Washplant; Direct Expense BP PORT ditambahkan satu kali ke BP Loading BLC. Komponen DB lainnya tetap.
- `Change % = (nilai bulan ini − nilai bulan pembanding) ÷ abs(nilai bulan pembanding) × 100`; pembagi nol/tidak ada = `—`.
- Remark disimpan lokal per aktivitas atau per entitas-aktivitas. Ini adalah overlay dashboard; Google Sheet **tidak** ditulis ulang.

## 6. Accrual Input

Manual: `Amount USD = Qty × Rate`, dihitung lagi di server. Otomatis menggunakan bulan aktif konfigurasi dan snapshot volume DB/Produksi:

| COA | Item | Perhitungan saat ini |
| --- | --- | --- |
| 5000404–5000411 | Mining Operation Cost | Diambil dari detail sumber DB per aktivitas/rute; nominal bulanan dikumpulkan per akun dan kategori. Qty diturunkan dari nominal/rate sumber dan rate gabungan dapat berupa rata-rata tertimbang. Satuan/currency sumber yang berbeda perlu diverifikasi. |
| 5000104 | Barge Loading Conveyor Rent | `max(Port Loading BLC MT × 1.332, USD 15,000)` jika data volume angka tersedia; nilai 0 yang valid tetap memicu minimum. |
| 5000105 | Wash Plant Rent | `Washing Plant Feed MT × USD 13.487/MT`; dibuat bila qty positif. |
| 5000109 | Land and Road Rent | BUA→Port MT × USD 0.700 dan WP→Port MT × USD 0.700, sebagai dua baris kategori/rute. |

Accrual di Report Cost ditambahkan pada akun biaya dan kategori. Di Financial Statements sistem membentuk jurnal virtual `Dr akun biaya / Cr 1530003 Accrued Expense`; ini tidak mengubah file GL atau posting D365. Snapshot otomatis disimpan saat sinkronisasi dan dapat diteruskan untuk periode sebelumnya. Reversal tidak otomatis; Accounting harus mengatur dan merekonsiliasi agar tagihan aktual dan accrual tidak dihitung dua kali. Pemetaan beban pada FS mengikuti kategori kode FS, dan perlakuan kapitalisasi produksi perlu review.

## 7. Inventory Valuation — Monthly Weighted Average

Sumber: `scripts/inventory_engine.js` dan `computeInventoryValuation` di `server.js`. Lokasi yang dihitung: BUA, Non Wash, Wash, High Ash, BP PORT, Transit BP PORT, ISP KUNZ dan Transit ISP KUNZ. Tidak ada lokasi Refeed terpisah dalam daftar implementasi saat ini.

Untuk setiap lokasi per bulan:

| Kolom | Rumus |
| --- | --- |
| Opening MT / USD | Closing lokasi yang sama bulan sebelumnya; bulan pertama dari saldo awal model. |
| Inflow MT | Jumlah baris `receipt` (termasuk Coal Mined BUA). |
| Inflow Value USD | Nilai receipt ditambah cost yang dialokasikan ke stok. |
| Add Cost | Jumlah baris `cost` yang dicatat untuk lokasi, tanpa qty. BUA Coal Mined memiliki nilai biaya sebagai receipt tersendiri. |
| Adjustment MT / USD | Jumlah adjustment bertanda, termasuk audit, kapitalisasi, reject atau survey sesuai lokasi dan urutan. |
| Available MT / USD | Saldo setelah penerimaan, cost dan adjustment sebelum outflow. |
| Moving Average / Transfer Rate | `Available USD ÷ Available MT`; implementasi mengembalikan 0 jika qty Available nol dan menandai issue bila masih ada nilai. |
| Outflow MT / USD | Qty perpindahan atau penjualan; `Outflow USD = Outflow MT × rate Available lokasi asal`. Stok penerima menerima qty dan USD yang sama. |
| Closing MT / USD | `Opening + receipts + added cost + signed adjustments − outflows`. |
| Closing Rate | `Closing USD ÷ Closing MT`, atau 0 jika closing qty nol. |
| Total Stock MT / Value | Jumlah ending MT / USD dari lokasi yang disertakan; grafik komposisi memakai bobot qty MT. |
| Total average USD/MT | `Total ending value USD ÷ total ending qty MT`, atau 0 jika qty nol. |
| COGS shipped | Jumlah nilai outflow yang ditandai penjualan dari port, transit port, KUNZ dan transit KUNZ; berbeda dari COGS di FS buku. |

Urutan penting: BUA memasukkan audit, coal mined, adjustment coal produced/end stock/capitalization sebelum Available; survey closing setelah transfer. Adjustment qty-only tidak mengubah nilai dan tidak menghitung ulang transfer sebelumnya. Reject Wash mengurangi qty tanpa mengurangi nilai; qty reject yang dialihkan ke High Ash ditambahkan sesuai input tanpa nilai. High Ash memiliki saldo sendiri. Transit memakai rata-rata saldo transit masing-masing, bukan otomatis rate stok lain.

Biaya yang masuk stock dari mapping GL/histori: BUA = COGP Mining; Non Wash = hauling BUA→WP; Wash = COGP Washing Plant; Port = hauling BUA→Port + WP→Port + COGP BP PORT, kecuali override historis `portAddedCostUSD`. Direct Expense tidak ditambahkan dalam mapping ini. Untuk tiap bulan dengan GL, inventory memakai `computeCostMatrix(null, rowsGL)` dan histori biaya bulan tersebut diganti oleh GL; **accrual dashboard terpisah tidak ikut otomatis dalam perhitungan inventory ini**. Bulan tanpa GL dapat memakai biaya Excel sementara. Jika data qty/biaya tidak lengkap, dummy, histori sementara, split tidak lengkap, atau ada issue stok, hasil ditandai provisional.

YTD inventory: opening = opening bulan pertama dalam rentang tahun, movement/adjustment/cost dijumlahkan sejak Januari atau awal model, closing tetap bulan terpilih. Rate transfer YTD = total outflow USD ÷ total outflow MT bila ada outflow, bukan rata-rata sederhana rate bulanan.

## 8. Financial Statements dan TB

Sumber: `scripts/financial_engine.js` dan `financial_baseline.json`. Histori Jan–Sep 2026 dan pembanding 2025 menggunakan sumber baseline; transaksi GL dan jurnal accrual dihitung dari Oktober 2026. GL sebelum Oktober tidak ditambahkan ulang ke FS baseline. `MTD` pada ringkasan P&L = bulan terpilih; `YTD` = baseline YTD + movement setelah baseline sampai periode terpilih. Neraca adalah posisi akhir, bukan jumlah saldo bulanan.

### Pemetaan P&L saat ini

Kepala 3 → Coal Sales; kepala 41 → Cost of Coal Sold; 400 → General Admin (kredit COGM mengurangi biaya); kepala 5 → General Admin kecuali Costing to Selling/Marketing; `6000003/6000004` → royalty; kepala 6 lain → Selling Expense; `7000001–7000003`, `7006001/2`, `7007001` → Finance Income; `7002002` → realised FX; `7002001` → unrealised FX; kelompok `7001`, `7003`, `7004`, kepala 8 → Finance Cost; kepala 9 → General Admin. Pemetaan ini mengikuti implementasi sumber FS, **tidak identik** dengan aturan GA Report Cost. Debit pada akun beban tampil negatif, kredit pendapatan positif.

| Kolom / subtotal | Rumus |
| --- | --- |
| Gross Profit | `Coal Sales + Cost of Coal Sold` (COGS bertanda negatif). |
| Gross Profit Margin % | `Gross Profit ÷ Coal Sales × 100`; implementasi ringkasan mengembalikan 0 bila revenue nol. |
| Profit before interest | `Gross Profit + Selling Expense + Royalty + General Admin` (beban bertanda negatif). Nama internal `operatingProfitEBITDA` tidak otomatis menjadikannya EBITDA tersertifikasi. |
| Profit before tax | `Profit before interest + Finance Income + Finance Cost + Realised FX + Unrealised FX`. |
| Profit for year | `Profit before tax + Income Tax` (expense bertanda negatif). |
| Net Profit Margin % | `Profit before tax ÷ Coal Sales × 100`; 0 jika revenue nol pada ringkasan. |

Neraca memakai mapping COA baseline ke `bsRowId`, dengan tanda kredit dibalik pada kategori liabilitas/ekuitas. `Cash and equivalents = cash on hand + cash in banks + time deposits`; `Total current assets` = cash + receivables + advances/prepayments/taxes + inventory buku + derivative asset. `Total non-current assets` = restricted cash + mining properties + exploration + fixed assets + retirement asset + deferred tax asset. `Total Assets = current + non-current`.

`Total current liabilities` dan `Total non-current liabilities` menjumlahkan baris komponennya; `Total Liabilities = current + non-current`; `Total Liabilities and Equity = Total Liabilities + Total Equity`. `Balance difference = Total Assets − Total Liabilities and Equity`. Kode tidak membuat akun penyeimbang otomatis. Accrual dashboard menambah debit akun biaya dan kredit 1530003 pada FS/TB virtual; nilai GL asli tetap. Penambahan akun biaya mengikuti mapping P&L di atas. `GL ledger difference` menjumlahkan nilai reporting seluruh baris GL dan adjustment dalam rentang; jurnal accrual berpasangan seharusnya net nol.

`Inventory reconciliation = inventory buku FS dibanding total nilai coal inventory model`; keduanya berasal dari sumber berbeda dan tidak otomatis dipaksa sama. Cash Flow lanjutan belum aktif.

## 9. Catatan audit dan rujukan kode

Rumus terutama berada di `server.js`, `scripts/inventory_engine.js`, `scripts/financial_engine.js`, `scripts/sheets_db.js`, `scripts/sheets_production.js` dan `public/app.js`. Rumus dashboard bergantung pada kualitas dan kelengkapan sheet/GL, mapping akun dan asumsi periode. Sebelum implementasi IT atau perubahan rumus, rekonsiliasi contoh riil untuk masing-masing kategori, nilai nol, data hilang, multi-bulan, accrual/reversal dan pergantian tahun. Uji otomatis dijalankan dengan `npm test`.
