<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($system = \App\Models\SystemConfig::get('system', []))
    @php($companyName = $system['companyName'] ?? 'PT Borneo Prima')
    @php($logoPath = $system['logoPath'] ?? '/images/logo-bp.svg')
    <title>Administrator — {{ $companyName }} FinOps</title>
    <link rel="icon" href="{{ e($logoPath) }}">
    @vite('resources/css/portal.css')
</head>
<body class="portal-page admin-page">
    <header class="admin-topbar">
        <a class="bp-brand" href="{{ route('dashboard') }}"><img src="{{ e($logoPath) }}" alt=""><span><b>{{ mb_strtoupper($companyName) }}</b><small>FINANCE &amp; OPERATIONS</small></span></a>
        <div class="admin-top-actions"><span class="admin-user"><i>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</i><span>{{ auth()->user()->name }}<small>Administrator</small></span></span><a class="soft-button" href="{{ route('dashboard') }}">← Dashboard</a><form method="post" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Keluar</button></form></div>
    </header>
    <main class="admin-main">
        <div class="admin-heading"><div><span class="admin-eyebrow">PENGATURAN SISTEM</span><h1>Administrator</h1><p>Kelola akun pengguna dan akses ke FinOps Dashboard.</p></div><span class="admin-secure">◈ Admin workspace</span></div>

        @if (session('status')) <div class="portal-alert success" role="status">{{ session('status') }}</div> @endif
        @if ($errors->any()) <div class="portal-alert error" role="alert">{{ $errors->first() }}</div> @endif

        <section class="admin-stats" aria-label="Ringkasan akun">
            <article><span>Total akun</span><strong>{{ number_format($stats['total']) }}</strong><small>Terdaftar di dashboard</small></article>
            <article><span>Akun aktif</span><strong>{{ number_format($stats['active']) }}</strong><small>Dapat masuk ke dashboard</small></article>
            <article><span>Administrator</span><strong>{{ number_format($stats['admins']) }}</strong><small>Akses pengelolaan pengguna</small></article>
            <article><span>Dinonaktifkan</span><strong>{{ number_format($stats['inactive']) }}</strong><small>Akses login dihentikan</small></article>
        </section>

        <section class="admin-panel create-user-panel">
            <details>
                <summary><span class="create-icon">＋</span><span><b>Tambah pengguna</b><small>Buat akun baru dan tentukan perannya.</small></span><span class="summary-action">Buka form</span></summary>
                <form method="post" action="{{ route('admin.users.store') }}" class="create-user-form">
                    @csrf
                    <label>Nama lengkap<input name="name" value="{{ old('name') }}" autocomplete="name" required></label>
                    <label>Email login<input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required></label>
                    <label>Peran<select name="role"><option value="viewer">Viewer</option><option value="administrator">Administrator</option></select></label>
                    <label>Kata sandi sementara<input name="password" type="password" minlength="12" autocomplete="new-password" required><small>Minimal 12 karakter.</small></label>
                    <label>Ulangi kata sandi<input name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required></label>
                    <div class="form-action"><button class="primary-button" type="submit">Buat akun</button></div>
                </form>
            </details>
        </section>

        <section class="admin-panel user-list-panel">
            <div class="user-list-heading"><div><h2>Pengguna</h2><p>{{ number_format($users->total()) }} akun · Cari dan kelola status atau peran.</p></div>
                <form method="get" action="{{ route('admin.index') }}" class="user-filters"><label class="sr-only" for="user-search">Cari nama atau email</label><input id="user-search" name="search" value="{{ $search }}" placeholder="Cari nama atau email"><label class="sr-only" for="user-role">Filter peran</label><select id="user-role" name="role"><option value="">Semua peran</option><option value="administrator" @selected($roleFilter === 'administrator')>Administrator</option><option value="viewer" @selected($roleFilter === 'viewer')>Viewer</option></select><button class="soft-button" type="submit">Filter</button></form>
            </div>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
                @forelse ($users as $user)
                    <tr class="user-row"><td><div class="user-identity"><i>{{ strtoupper(substr($user->name, 0, 1)) }}</i><span><b>{{ $user->name }}</b><small>{{ $user->email }}</small></span></div></td><td><span class="role-pill {{ $user->hasAdministratorRole() ? 'role-admin' : '' }}">{{ $user->hasAdministratorRole() ? 'Administrator' : 'Viewer' }}</span></td><td><span class="state-pill {{ $user->is_active ? 'state-active' : 'state-inactive' }}"><i></i>{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span></td><td>{{ $user->created_at?->format('d M Y') ?? '—' }}</td><td><details class="user-edit"><summary>Edit</summary><form method="post" action="{{ route('admin.users.update', $user) }}" class="user-edit-form">
                        @csrf @method('PUT')
                        <label>Nama<input name="name" value="{{ $user->name }}" required></label>
                        <label>Email<input name="email" type="email" value="{{ $user->email }}" required></label>
                        <label>Peran<select name="role"><option value="viewer" @selected(in_array($user->role, ['user', 'viewer'], true))>Viewer</option><option value="administrator" @selected($user->hasAdministratorRole())>Administrator</option></select></label>
                        <label>Status<select name="is_active"><option value="1" @selected($user->is_active)>Aktif</option><option value="0" @selected(!$user->is_active)>Nonaktif</option></select></label>
                        <label class="password-reset">Password baru <span>Opsional · minimal 12 karakter</span><input name="password" type="password" minlength="12" autocomplete="new-password"><input name="password_confirmation" type="password" minlength="12" autocomplete="new-password" placeholder="Ulangi password baru"></label>
                        <button class="primary-button" type="submit">Simpan perubahan</button>
                    </form></details></td></tr>
                @empty
                    <tr><td class="empty-users" colspan="5">Tidak ada akun yang cocok dengan filter.</td></tr>
                @endforelse
            </tbody></table></div>
            <div class="admin-pagination">{{ $users->links() }}</div>
        </section>
        <p class="admin-footnote">Akun nonaktif tidak dapat masuk. Hanya administrator aktif yang dapat membuka halaman ini.</p>
    </main>
</body>
</html>
