const storageKey = 'finops.dashboard.language';
const translations = [
    ['Executive Overview', 'Ikhtisar Eksekutif'], ['Report Cost (COGP)', 'Laporan Biaya (COGP)'], ['Cost Variance Analysis', 'Analisis Varians Biaya'], ['Cost per Metric Ton', 'Biaya per Metrik Ton'], ['Equipment Cost', 'Biaya Peralatan'], ['Inventory Valuation', 'Valuasi Persediaan'], ['Financial Statements', 'Laporan Keuangan'], ['GL Transaction Review', 'Tinjauan Transaksi GL'], ['Integration & Data Sources', 'Integrasi & Sumber Data'], ['Allocation Mapping', 'Pemetaan Alokasi'], ['Configuration', 'Konfigurasi'], ['OVERVIEW', 'IKHTISAR'], ['COST & REPORTS', 'BIAYA & LAPORAN'], ['DATA & CONFIGURATION', 'DATA & PENGATURAN'],
    ['Operations & Financial Overview', 'Ikhtisar Operasional & Keuangan'], ['Microsoft Dynamics 365 General Ledger and Google Sheets weighbridge data', 'Data buku besar Microsoft Dynamics 365 dan timbangan Google Sheets'], ['Reporting period controls', 'Kontrol periode laporan'], ['Main navigation', 'Navigasi utama'], ['Dashboard navigation', 'Navigasi dashboard'], ['Period', 'Periode'], ['Current month', 'Bulan ini'], ['View', 'Tampilan'], ['Toggle navigation', 'Buka/tutup navigasi'], ['Previous month', 'Bulan sebelumnya'], ['Next month', 'Bulan berikutnya'], ['Selected reporting month', 'Pilih bulan laporan'], ['Data freeze', 'Batas data'], ['User', 'Pengguna'], ['Administrator', 'Administrator'], ['Logout', 'Keluar'], ['Sign out', 'Keluar'], ['Finance & Operations', 'Keuangan & Operasional'],
    ['Sign in', 'Masuk'], ['Welcome back', 'Selamat datang kembali'], ['Welcome', 'Selamat datang'], ['Internal employee portal', 'Portal internal karyawan'], ['Sign in to view cost, production, inventory, and company financial performance.', 'Masuk untuk melihat laporan biaya, produksi, persediaan, dan kinerja keuangan perusahaan.'], ['Email address', 'Alamat email'], ['Password', 'Kata sandi'], ['Enter your password', 'Masukkan kata sandi'], ['Show password', 'Tampilkan kata sandi'], ['Hide password', 'Sembunyikan kata sandi'], ['Show', 'Lihat'], ['Hide', 'Sembunyikan'], ['Remember me on this device', 'Ingat saya di perangkat ini'], ['Forgot your password? Contact your administrator', 'Lupa kata sandi? Hubungi administrator'], ['Sign in to Dashboard', 'Masuk ke Dashboard'], ['Secure sign-in with a registered account', 'Login aman dengan akun terdaftar'], ['Operational decisions,', 'Keputusan operasional,'], ['based on connected data.', 'berdasarkan data yang terhubung.'], ['One dashboard to monitor production and financial performance.', 'Satu dashboard untuk melihat kondisi produksi dan keuangan secara menyeluruh.'], ['Operational costs at a glance', 'Biaya operasional terpantau'], ['COGP, direct expense, and variance analysis.', 'COGP, direct expense, dan analisis varians.'], ['Connected operational data', 'Data operasional terhubung'], ['D365, Google Sheets, and GL transaction synchronization.', 'Sinkronisasi D365, Google Sheets, dan transaksi GL.'], ['Production and inventory insights', 'Informasi produksi dan stok'], ['Daily volumes, production flow, and inventory valuation.', 'Volume harian, alur produksi, dan valuasi persediaan.'],
    ['System settings', 'Pengaturan sistem'], ['Manage user accounts and access to FinOps Dashboard.', 'Kelola akun pengguna dan akses ke FinOps Dashboard.'], ['Admin workspace', 'Ruang kerja administrator'], ['Account summary', 'Ringkasan akun'], ['Total accounts', 'Total akun'], ['Active accounts', 'Akun aktif'], ['Registered on the dashboard', 'Terdaftar di dashboard'], ['Can sign in to the dashboard', 'Dapat masuk ke dashboard'], ['User management access', 'Akses pengelolaan pengguna'], ['Deactivated', 'Dinonaktifkan'], ['Sign-in access is disabled', 'Akses login dihentikan'], ['Add user', 'Tambah pengguna'], ['Create a new account and assign a role.', 'Buat akun baru dan tentukan perannya.'], ['Open form', 'Buka form'], ['Full name', 'Nama lengkap'], ['Login email', 'Email login'], ['Role', 'Peran'], ['Viewer', 'Pembaca'], ['Temporary password', 'Kata sandi sementara'], ['At least 12 characters.', 'Minimal 12 karakter.'], ['Confirm password', 'Ulangi kata sandi'], ['Create account', 'Buat akun'], ['Users', 'Pengguna'], ['Search and manage account status or role.', 'Cari dan kelola status atau peran.'], ['Search name or email', 'Cari nama atau email'], ['All roles', 'Semua peran'], ['Filter', 'Filter'], ['Status', 'Status'], ['Created', 'Dibuat'], ['Actions', 'Aksi'], ['Active', 'Aktif'], ['Inactive', 'Nonaktif'], ['Edit', 'Ubah'], ['Save changes', 'Simpan perubahan'], ['New password', 'Kata sandi baru'], ['Optional · at least 12 characters', 'Opsional · minimal 12 karakter'], ['No accounts match these filters.', 'Tidak ada akun yang cocok dengan filter.'], ['Inactive accounts cannot sign in. Only active administrators can open this page.', 'Akun nonaktif tidak dapat masuk. Hanya administrator aktif yang dapat membuka halaman ini.'], ['Back to dashboard', 'Kembali ke dashboard'], ['Role & Page Access', 'Role & Akses Halaman'], ['Manage roles and page permissions', 'Kelola role dan izin akses'], ['Choose which pages a role can open and whether it can view or edit data.', 'Tentukan halaman yang dapat dibuka dan apakah role hanya dapat melihat atau juga mengubah data.'], ['roles available', 'role tersedia'], ['Create a role', 'Buat role baru'], ['Set a name, description, and page permissions.', 'Atur nama, deskripsi, dan izin per halaman.'], ['Role name', 'Nama role'], ['Role code', 'Kode role'], ['Description', 'Deskripsi'], ['Purpose of this role', 'Tujuan role ini'], ['Page', 'Halaman'], ['Access', 'Akses'], ['No access', 'Tidak ada akses'], ['Read', 'Lihat'], ['Read & edit', 'Lihat & edit'], ['Create role', 'Buat role'], ['System role', 'Role sistem'], ['Custom role', 'Role khusus'], ['accounts', 'akun'], ['Page access', 'Akses halaman'], ['System roles are protected. Create a custom role for different access rules.', 'Role sistem dilindungi. Buat role khusus untuk aturan akses yang berbeda.'], ['Save permissions', 'Simpan izin'], ['Delete role', 'Hapus role'], ['System administrators have full access and system roles cannot be changed. Custom role permissions apply immediately to assigned accounts.', 'Administrator memiliki akses penuh dan role sistem tidak dapat diubah. Akses khusus langsung berlaku untuk akun yang memakai role tersebut.'], ['Reports', 'Laporan'], ['Transactions', 'Transaksi'], ['Data & Setup', 'Data & Pengaturan'],
    ['Operations & Financial Overview', 'Ikhtisar Operasional & Keuangan'], ['Production', 'Produksi'], ['Main flow', 'Alur utama'], ['Direct route', 'Rute langsung'], ['Coal Mining', 'Penambangan Batu Bara'], ['Washing Plant', 'Pabrik Pencucian'], ['Mother Vessel', 'Kapal Induk'], ['Top Cost Drivers', 'Komponen Biaya Terbesar'], ['Daily Production Volume (MT)', 'Volume Produksi Harian (MT)'], ['Daily Cost - COGP & GA', 'Biaya Harian - COGP & GA'], ['Cost Allocation: COGP & Direct Expense', 'Alokasi Biaya: COGP & Beban Langsung'], ['Cost Variance Analysis', 'Analisis Varians Biaya'], ['Cost per Metric Ton Analysis', 'Analisis Biaya per Metrik Ton'], ['Monthly Inventory Valuation', 'Valuasi Persediaan Bulanan'], ['Financial Statements', 'Laporan Keuangan'], ['Integration & Real-Time Data Sources', 'Integrasi & Sumber Data Real-Time'], ['D365 Allocation Mapping', 'Pemetaan Alokasi D365'], ['Report Cost: COGP & Direct Expense', 'Laporan Biaya: COGP & Beban Langsung'], ['Accounts beginning 7–9 are allocated to GA.', 'Akun dengan awalan 7–9 dialokasikan ke GA.'], ['Direct Expense', 'Beban Langsung'], ['All Cost', 'Total Biaya'], ['Account Code', 'Kode Akun'], ['Account Name', 'Nama Akun'], ['Grand Total', 'Total Keseluruhan'], ['Monthly Performance', 'Kinerja Bulanan'], ['Monthly Cost Comparison', 'Perbandingan Biaya Bulanan'], ['Activity-Based Cost', 'Biaya Berbasis Aktivitas'], ['Activity-Based Cost by Counterpart', 'Biaya Berbasis Aktivitas per Mitra'], ['Inventory & Employee Cost', 'Biaya Persediaan & Karyawan'], ['Cost Breakdown', 'Rincian Biaya'], ['Cost mix', 'Komposisi biaya'], ['Cost / MT', 'Biaya / MT'], ['Production unavailable', 'Data produksi tidak tersedia'], ['Employee cost is not allocated to units', 'Biaya karyawan belum dialokasikan ke unit'], ['Source', 'Sumber'], ['Last sync', 'Sinkronisasi terakhir'], ['Synced', 'Tersinkron'], ['No data available.', 'Tidak ada data.'], ['No staging data available.', 'Data staging tidak tersedia.'], ['Data cannot be loaded', 'Data tidak dapat dimuat'], ['Loading dashboard data…', 'Memuat data dashboard…'], ['Loading GL transactions', 'Memuat transaksi GL'], ['Preparing all rows. Please wait…', 'Menyiapkan semua baris. Mohon tunggu…'], ['No results found', 'Tidak ada hasil'], ['Search', 'Cari'], ['Clear filters', 'Hapus filter'], ['Refresh', 'Muat ulang'], ['Show all', 'Tampilkan semua'], ['Show all accounts', 'Tampilkan semua akun'], ['Parent accounts only', 'Hanya akun induk'], ['Hide zero balances', 'Sembunyikan saldo nol'], ['Rows per page', 'Baris per halaman'], ['Page', 'Halaman'], ['Previous', 'Sebelumnya'], ['Next', 'Berikutnya'], ['Export Excel', 'Ekspor Excel'], ['Export CSV', 'Ekspor CSV'], ['Save', 'Simpan'], ['Cancel', 'Batal'], ['Close', 'Tutup'], ['Import', 'Impor'], ['Preview', 'Pratinjau'], ['Refresh data', 'Muat ulang data'], ['Month', 'Bulan'], ['Date', 'Tanggal'], ['Account', 'Akun'], ['Account Name', 'Nama Akun'], ['Voucher', 'Voucher'], ['Journal', 'Jurnal'], ['Description', 'Deskripsi'], ['Currency', 'Mata Uang'], ['Amount', 'Jumlah'], ['Details', 'Detail'], ['All accounts', 'Semua akun'], ['Transactions', 'Transaksi'], ['of', 'dari'], ['rows', 'baris'], ['Source: Google Sheets or uploaded data', 'Sumber: Google Sheets atau data yang diunggah'], ['Update: Manual sync', 'Pembaruan: Sinkronisasi manual'], ['Google Sheets Data', 'Data Google Sheets'],
    ['Microsoft Dynamics 365 and Google Sheets production sources.', 'Sumber data produksi Microsoft Dynamics 365 dan Google Sheets.'], ['D365 OData Connector', 'Konektor D365 OData'], ['Configured', 'Terkonfigurasi'], ['Not configured', 'Belum dikonfigurasi'], ['Sync All to Staging', 'Sinkronkan Semua ke Staging'], ['Sync Production', 'Sinkronkan Produksi'], ['View GL Data', 'Lihat Data GL'], ['View Staging', 'Lihat Staging'], ['Local Records', 'Catatan Lokal'], ['GL records', 'catatan GL'], ['Auto Sync', 'Sinkronisasi Otomatis'], ['Disabled', 'Nonaktif'], ['Enabled', 'Aktif'], ['Current Allocation Rules', 'Aturan Alokasi Saat Ini'], ['Add Mapping', 'Tambah Pemetaan'], ['Import Mapping Master', 'Impor Master Pemetaan'], ['Preview / Import D365 GL Staging', 'Pratinjau / Impor Staging GL D365'], ['Import to GL', 'Impor ke GL'], ['Choose a date range or preview all GL staging rows.', 'Pilih rentang tanggal atau pratinjau semua baris staging GL.'], ['Read-only view of all raw OData fields plus typed staging metadata.', 'Tampilan baca-saja untuk seluruh field OData mentah beserta metadata staging bertipe.'], ['Read-only preview of all GL fields for', 'Pratinjau baca-saja seluruh field GL untuk'], ['Choose a mapping file first.', 'Pilih file pemetaan terlebih dahulu.'], ['Could not save mapping.', 'Pemetaan tidak dapat disimpan.'], ['Mapping saved.', 'Pemetaan berhasil disimpan.'], ['Preview failed:', 'Pratinjau gagal:'], ['Import failed:', 'Impor gagal:'], ['Previewing D365 GL staging…', 'Mempratinjau staging GL D365…'], ['Importing D365 GL staging…', 'Mengimpor staging GL D365…'], ['Failed', 'Gagal'], ['Running', 'Berjalan'], ['Waiting', 'Menunggu'], ['Complete', 'Selesai'], ['Stopped', 'Dihentikan'], ['D365 sync progress', 'Progres sinkronisasi D365'], ['Stop Sync', 'Hentikan Sinkronisasi'], ['All entity operations have returned. Review the per-entity status below.', 'Semua proses entity telah selesai. Periksa status setiap entity di bawah.'], ['of', 'dari'], ['entities completed', 'entity selesai'], ['entities complete', 'entity selesai'], ['Staging', 'Staging'], ['Fetched', 'Diambil'], ['Inserted', 'Ditambahkan'], ['Updated', 'Diperbarui'], ['Current:', 'Saat ini:'], ['Data source', 'Sumber data'], ['Save All Changes', 'Simpan Semua Perubahan'], ['Editable fields are marked with an input. Sensitive credentials and nested setup details remain read-only.', 'Field yang dapat diedit ditandai dengan input. Kredensial sensitif dan pengaturan bertingkat hanya dapat dibaca.'], ['Account Mapping', 'Pemetaan Akun'], ['Financial Statements', 'Laporan Keuangan'], ['Inventory valuation period', 'Periode valuasi persediaan'], ['Not synced', 'Belum disinkronkan'], ['October', 'Oktober'], ['January', 'Januari'], ['February', 'Februari'], ['March', 'Maret'], ['April', 'April'], ['May', 'Mei'], ['June', 'Juni'], ['July', 'Juli'], ['August', 'Agustus'], ['September', 'September'], ['November', 'November'], ['December', 'Desember'],
    ['No', 'Tidak'], ['Yes', 'Ya'], ['Select', 'Pilih'], ['Choose', 'Pilih'], ['Location', 'Lokasi'], ['Department', 'Departemen'], ['Activity', 'Aktivitas'], ['Costing To', 'Costing To'], ['Valuation To', 'Valuation To'], ['Priority', 'Prioritas'], ['Parent', 'Induk'], ['Child', 'Anak'], ['Included', 'Termasuk'], ['Reference only', 'Referensi saja'], ['Unknown', 'Tidak diketahui'], ['Root account', 'Akun utama'], ['Hierarchy source', 'Sumber hierarki'], ['matching hierarchy branches', 'cabang hierarki yang cocok'], ['accounts loaded', 'akun dimuat'], ['rules', 'aturan'], ['Active only', 'Hanya yang aktif'], ['All statuses', 'Semua status'], ['Clear', 'Bersihkan'], ['Apply', 'Terapkan'], ['Updated successfully.', 'Berhasil diperbarui.'], ['Could not save', 'Tidak dapat menyimpan'], ['Changes saved.', 'Perubahan tersimpan.'], ['Language', 'Bahasa'], ['English', 'Bahasa Inggris'], ['Indonesian', 'Bahasa Indonesia'], ['User', 'Pengguna'], ['Sign out of account', 'Keluar dari akun'], ['Your role does not have access to this dashboard page or action.', 'Role Anda tidak memiliki akses ke halaman atau tindakan ini.'], ['No dashboard pages are assigned to this account.', 'Tidak ada halaman dashboard yang diberikan ke akun ini.'],
    ['User account created successfully.', 'Akun pengguna berhasil dibuat.'], ['User account changes saved.', 'Perubahan akun berhasil disimpan.'], ['Role created successfully.', 'Role berhasil dibuat.'], ['Role permissions updated.', 'Hak akses role berhasil diperbarui.'], ['This role is assigned to user accounts. Reassign them before deleting it.', 'Role masih digunakan oleh akun. Pindahkan akun ke role lain sebelum menghapusnya.'], ['Role deleted successfully.', 'Role berhasil dihapus.'], ['You cannot deactivate your own account or downgrade your role.', 'Anda tidak dapat menonaktifkan atau menurunkan peran akun sendiri.'], ['The last active administrator cannot be deactivated or downgraded.', 'Administrator aktif terakhir tidak dapat dinonaktifkan atau diturunkan perannya.'], ['Delete this role?', 'Hapus role ini?'], ['Administrator has full access and is protected. The Viewer role can be configured; create custom roles for other rules.', 'Administrator memiliki akses penuh dan dilindungi. Role Viewer dapat diatur; buat role khusus untuk aturan lain.'], ['Email or password is incorrect.', 'Email atau kata sandi tidak benar.'], ['Too many attempts. Try again in', 'Terlalu banyak percobaan. Coba lagi dalam'], ['seconds.', 'detik.'], ['You have signed out of the dashboard.', 'Anda sudah keluar dari dashboard.'], ['Administrator access is required.', 'Akses administrator diperlukan.'], ['This role has read-only access.', 'Role ini hanya memiliki akses baca.'],
];

translations.push(
    ['Production flow Illustrative geography · Select a Location for Details', 'Alur produksi · Ilustrasi geografis · Pilih lokasi untuk melihat detail'],
    ['Illustrative geography', 'Ilustrasi geografis'],
    ['Select a Location for Details', 'Pilih lokasi untuk melihat detail'],
    ['Daily Production chart. Hover, focus, or select a bar to inspect daily values.', 'Grafik produksi harian. Arahkan kursor, fokus, atau pilih batang untuk melihat nilainya.'],
    ['Daily Google Sheets COGP accrual and D365 GL GA costs. Hover, focus, or select a date for details.', 'Akrual COGP Google Sheets dan biaya GA dari GL D365 per hari. Arahkan kursor, fokus, atau pilih tanggal untuk melihat detail.'],
    ['Hover, focus, or select a date to inspect cost values.', 'Arahkan kursor, fokus, atau pilih tanggal untuk melihat nilai biaya.'],
    ['Hover, focus, or select a bar to inspect daily values.', 'Arahkan kursor, fokus, atau pilih batang untuk melihat nilai harian.'],
    ['Direct Expense by Valuation to; Not Valuated is included in GA. Selling Expense has its own column.', 'Beban Langsung dialokasikan berdasarkan Valuation to; transaksi tanpa Valuation to masuk ke GA. Beban Penjualan memiliki kolom tersendiri.'],
    ['From Costing to: Mining, 3 Coal Hauling routes, Washing Plant, BP PORT', 'Berdasarkan Costing to: Mining, 3 rute Coal Hauling, Washing Plant, BP PORT'],
    ['Daily recorded coal volume (MT):', 'Volume batu bara harian yang tercatat (MT):'],
    ['Daily Cost — COGP & GA', 'Biaya Harian — COGP & GA'],
    ['View daily cost details', 'Lihat rincian biaya harian'],
    ['Group by', 'Kelompokkan berdasarkan'],
    ['Production view', 'Tampilan produksi'],
    ['Coal Production', 'Produksi Batu Bara'],
    ['Coal Hauling', 'Pengangkutan Batu Bara'],
    ['Selling', 'Penjualan'],
    ['COGP accrual total (USD)', 'Total akrual COGP (USD)'],
    ['Of which: fuel (USD)', 'Termasuk bahan bakar (USD)'],
    ['GA GL movement (USD)', 'Mutasi GL GA (USD)'],
    ['COGP: Google Sheets · Produksi + DB', 'COGP: Google Sheets · Produksi + DB'],
    ['No baseline JSON is used.', 'Data baseline JSON tidak digunakan.'],
    ['Fuel is included in COGP.', 'Bahan bakar termasuk dalam COGP.'],
    ['is calculated from synced Google Sheets production and DB rates; GA is summed from dated D365 GL.', 'dihitung dari data produksi Google Sheets yang tersinkron dan tarif DB; GA dijumlahkan dari GL D365 berdasarkan tanggal.'],
    ['Synced Google Sheets Production', 'data Produksi Google Sheets yang tersinkron'],
    ['D365 GL Oct + Jan–Sep history', 'GL D365 Oktober + histori Januari–September'],
    ['D365 GL sync', 'Sinkronisasi GL D365'],
    ['Production unavailable', 'Data produksi tidak tersedia'],
    ['Employee cost is not allocated to units', 'Biaya karyawan belum dialokasikan ke unit'],
    ['Inventory & Employee Cost', 'Biaya Persediaan & Karyawan'],
    ['Inhouse Mining', 'Penambangan Internal'],
    ['Location details', 'Rincian lokasi'],
    ['Daily', 'Harian'],
    ['Monthly', 'Bulanan'],
    ['Year to date', 'Year to date'],
    ['Year-to-date', 'Year-to-date'],
    ['Mined coal', 'Batu bara hasil tambang'],
    ['Overburden removal', 'Pengupasan lapisan penutup'],
    ['Select a location', 'Pilih lokasi'],
    ['No production data for this period.', 'Tidak ada data produksi untuk periode ini.'],
    ['Production flow', 'Alur produksi'],
    ['Location', 'Lokasi'],
    ['Stockpile', 'Stockpile'],
    ['Port', 'Pelabuhan'],
    ['Washing Plant', 'Pabrik Pencucian'],
    ['Mother Vessel', 'Kapal Induk']
);

const sourceByNode = new WeakMap();
const sourceByAttribute = new WeakMap();

function locale() {
    return localStorage.getItem(storageKey) === 'en' ? 'en' : 'id';
}

function transform(value, target) {
    const dictionary = target === 'en'
        ? translations.map(([en, id]) => [id, en])
        : translations.map(([en, id]) => [en, id]);
    let output = value;
    for (const [from, to] of dictionary.sort((a, b) => b[0].length - a[0].length)) {
        if (from.toLocaleLowerCase() === output.trim().toLocaleLowerCase()) {
            const leading = output.match(/^\s*/)?.[0] || '';
            const trailing = output.match(/\s*$/)?.[0] || '';
            output = `${leading}${to}${trailing}`;
            break;
        }
        const escaped = from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const pattern = /^\w+$/.test(from) ? `\\b${escaped}\\b` : escaped;
        output = output.replace(new RegExp(pattern, 'gi'), to);
    }
    if (target === 'id') {
        output = output.replace(/\b(\d+) of (\d+) transactions\b/gi, '$1 dari $2 transaksi')
            .replace(/\bPage (\d+) of (\d+)\b/gi, 'Halaman $1 dari $2')
            .replace(/\b(\d+) rows\b/gi, '$1 baris')
            .replace(/\b(\d+) accounts\b/gi, '$1 akun')
            .replace(/\b(\d+) rules\b/gi, '$1 aturan')
            .replace(/\b(\d+) roles available\b/gi, '$1 role tersedia');
    } else {
        output = output.replace(/\b(\d+) dari (\d+) transaksi\b/gi, '$1 of $2 transactions')
            .replace(/\bHalaman (\d+) dari (\d+)\b/gi, 'Page $1 of $2')
            .replace(/\b(\d+) baris\b/gi, '$1 rows')
            .replace(/\b(\d+) akun\b/gi, '$1 accounts')
            .replace(/\b(\d+) aturan\b/gi, '$1 rules')
            .replace(/\b(\d+) role tersedia\b/gi, '$1 roles available');
    }
    return output;
}

function translateTextNode(node, target) {
    if (!node.nodeValue || !node.parentElement) return;
    const element = node.parentElement;
    if (element.closest('script,style,noscript,textarea,code,pre,.num,.negative-number,[data-locale-ignore]')) return;
    if (element.closest('td') && !element.closest('button,a,label,summary')) return;

    let record = sourceByNode.get(node);
    if (!record) record = { source: node.nodeValue, rendered: node.nodeValue };
    else if (node.nodeValue !== record.rendered) record.source = node.nodeValue;
    const result = transform(record.source, target);
    record.rendered = result;
    sourceByNode.set(node, record);
    if (node.nodeValue !== result) node.nodeValue = result;
}

function translateAttribute(element, attribute, target) {
    const current = element.getAttribute(attribute);
    if (current === null) return;
    let records = sourceByAttribute.get(element);
    if (!records) { records = new Map(); sourceByAttribute.set(element, records); }
    let record = records.get(attribute);
    if (!record) record = { source: current, rendered: current };
    else if (current !== record.rendered) record.source = current;
    const result = transform(record.source, target);
    record.rendered = result;
    records.set(attribute, record);
    if (current !== result) element.setAttribute(attribute, result);
}

function visit(root, target) {
    if (root.nodeType === Node.TEXT_NODE) { translateTextNode(root, target); return; }
    if (root.nodeType === Node.ELEMENT_NODE) {
        ['placeholder', 'title', 'aria-label', 'aria-description'].forEach(attribute => translateAttribute(root, attribute, target));
    }
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(node => translateTextNode(node, target));
    if (root.nodeType === Node.ELEMENT_NODE) root.querySelectorAll('*').forEach(element => ['placeholder', 'title', 'aria-label', 'aria-description'].forEach(attribute => translateAttribute(element, attribute, target)));
}

function applyLocale(target = locale()) {
    document.documentElement.lang = target;
    visit(document.head, target);
    visit(document.body, target);
    document.querySelectorAll('[data-locale-switch]').forEach(button => {
        const active = button.dataset.localeSwitch === target;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', String(active));
    });
    window.dispatchEvent(new CustomEvent('finops:locale-change', { detail: { locale: target } }));
}

document.querySelectorAll('[data-locale-switch]').forEach(button => button.addEventListener('click', () => {
    localStorage.setItem(storageKey, button.dataset.localeSwitch === 'en' ? 'en' : 'id');
    applyLocale();
}));

const observer = new MutationObserver(records => {
    const target = locale();
    records.forEach(record => {
        if (record.type === 'characterData') translateTextNode(record.target, target);
        else record.addedNodes.forEach(node => { if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) visit(node, target); });
        if (record.type === 'attributes') translateAttribute(record.target, record.attributeName, target);
    });
});
observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label', 'aria-description'] });
applyLocale();
