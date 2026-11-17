<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f8fbff">
    @php($system = \App\Models\SystemConfig::get('system', []))
    @php($companyName = $system['companyName'] ?? 'PT Borneo Prima')
    @php($logoPath = $system['logoPath'] ?? '/images/logo-bp.svg')
    <title>Masuk — {{ $companyName }} FinOps</title>
    <link rel="icon" href="{{ e($logoPath) }}">
    @vite(['resources/css/portal.css', 'resources/js/language.js'])
</head>
<body class="portal-page login-page">
    <main class="login-layout">
        <div class="language-switch login-language-switch" role="group" aria-label="Language"><button type="button" data-locale-switch="id">ID</button><button type="button" data-locale-switch="en">EN</button></div>
        <section class="login-form-side">
            <div class="login-form-wrap">
                <a class="bp-brand" href="/" aria-label="PT Borneo Prima FinOps">
                    <img src="{{ e($logoPath) }}" alt="">
                    <span><b>{{ mb_strtoupper($companyName) }}</b><small>FINANCE &amp; OPERATIONS</small></span>
                </a>
                <h1>Selamat Datang</h1>
                <p class="login-intro">Masuk untuk melihat laporan biaya, produksi, persediaan, dan kinerja keuangan perusahaan.</p>

                @if (session('status'))
                    <div class="portal-alert success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="portal-alert error" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="post" action="{{ route('login.store') }}" class="login-form">
                    @csrf
                    <label for="email">Alamat email</label>
                    <div class="login-input"><span aria-hidden="true">✉</span><input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="nama@borneoprima.com" autocomplete="username" inputmode="email" autocapitalize="none" required autofocus></div>
                    <label for="password">Kata sandi</label>
                    <div class="login-input"><span aria-hidden="true">▣</span><input id="password" name="password" type="password" placeholder="Masukkan kata sandi" autocomplete="current-password" required><button class="password-toggle" type="button" aria-label="Tampilkan kata sandi" aria-pressed="false">Lihat</button></div>
                    <div class="login-options"><label class="remember-option"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya di perangkat ini</label><span>Lupa sandi? Hubungi administrator</span></div>
                    <button class="login-submit" type="submit">Masuk ke Dashboard <span aria-hidden="true">→</span></button>
                </form>

                <div class="login-foot"><span>🔒 Login aman dengan akun terdaftar</span><span>© {{ date('Y') }} {{ $companyName }}</span></div>
            </div>
        </section>
        <aside class="login-visual-side" aria-label="Informasi FinOps Dashboard">
            <img class="login-visual-image" src="/images/login-side.jpg" alt="Operasi tambang PT Borneo Prima" fetchpriority="high" decoding="async"><div class="login-visual-shade"></div>
            <div class="login-visual-content">
                <div class="visual-eyebrow">INFORMASI OPERASIONAL &amp; KEUANGAN</div>
                <h2>Visibilitas menyeluruh atas kinerja operasional dan keuangan.</h2>
                <p class="visual-lead">Dapatkan informasi untuk mendukung keputusan terkait produksi, biaya, persediaan, dan hasil keuangan.</p>
                <div class="visual-feature"><span>↗</span><div><b>Kinerja biaya dan keuangan</b><small>Tinjau COGP, beban langsung, varians biaya, dan laporan keuangan.</small></div></div>
                <div class="visual-feature"><span>▤</span><div><b>Data perusahaan terintegrasi</b><small>Konsolidasi General Ledger D365 dan data produksi Google Sheets.</small></div></div>
                <div class="visual-feature"><span>◉</span><div><b>Visibilitas produksi dan persediaan</b><small>Pantau pergerakan produksi, posisi stok, dan valuasi persediaan.</small></div></div>
                <a class="company-link" href="https://borneoprima.com/" target="_blank" rel="noopener">borneoprima.com ↗</a>
            </div>
        </aside>
    </main>
    <script>
        const toggle = document.querySelector('.password-toggle');
        toggle?.addEventListener('click', () => {
            const input = document.querySelector('#password');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Sembunyi' : 'Lihat';
            toggle.setAttribute('aria-pressed', String(show));
            toggle.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
        });
    </script>
</body>
</html>
