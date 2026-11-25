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
    @vite(['resources/css/app.css', 'resources/css/portal.css', 'resources/css/account.css', 'resources/css/admin-shell.css', 'resources/js/language.js', 'resources/js/account-menu.js'])
</head>
<body class="portal-page admin-page">
    <div class="app-shell admin-app-shell">
        <div id="sidebarScrim" class="sidebar-scrim" aria-hidden="true"></div>
        <aside id="appSidebar" class="app-sidebar" aria-label="Main navigation">
            <a class="sidebar-brand" href="{{ route('dashboard') }}"><img class="logo" src="{{ e($logoPath) }}" alt=""><div><b>{{ $companyName }}</b><span>FINANCE &amp; OPERATIONS</span></div></a>
            <nav class="tabs admin-sidebar-nav" aria-label="Dashboard navigation">
                <span class="nav-group-label">OVERVIEW</span>
                <a href="{{ route('dashboard', ['view' => 'overview']) }}" title="Executive Overview"><span class="nav-icon">📊</span><span class="nav-label">Executive Overview</span></a>
                <span class="nav-group-label">COST &amp; REPORTS</span>
                <a href="{{ route('dashboard', ['view' => 'metric']) }}" title="Activity-Based Costing"><span class="nav-icon">⚖️</span><span class="nav-label">Activity-Based Costing</span></a>
                <a href="{{ route('dashboard', ['view' => 'equipmentCost']) }}" title="Equipment Cost"><span class="nav-icon">🚜</span><span class="nav-label">Equipment Cost</span></a>
                <a href="{{ route('dashboard', ['view' => 'inventory']) }}" title="Inventory Valuation"><span class="nav-icon">📦</span><span class="nav-label">Inventory Valuation</span></a>
                <a href="{{ route('dashboard', ['view' => 'cost']) }}" title="Report Cost (COGP)"><span class="nav-icon">📑</span><span class="nav-label">Report Cost (COGP)</span></a>
                <a href="{{ route('dashboard', ['view' => 'variance']) }}" title="Cost Variance Analysis"><span class="nav-icon">📈</span><span class="nav-label">Cost Variance Analysis</span></a>
                <a href="{{ route('dashboard', ['view' => 'financial']) }}" title="Financial Statements"><span class="nav-icon">📑</span><span class="nav-label">Financial Statements</span></a>
                <span class="nav-group-label">TRANSACTIONS</span>
                <a href="{{ route('dashboard', ['view' => 'glReview']) }}" title="GL Transaction Review"><span class="nav-icon">🧾</span><span class="nav-label">GL Transaction Review</span></a>
                <span class="nav-group-label">DATA &amp; CONFIGURATION</span>
                <a href="{{ route('dashboard', ['view' => 'integration']) }}" title="Integration &amp; Data Sources"><span class="nav-icon">🔄</span><span class="nav-label">Integration &amp; Data Sources</span></a>
                <a href="{{ route('dashboard', ['view' => 'mapping']) }}" title="Allocation Mapping"><span class="nav-icon">🧭</span><span class="nav-label">Allocation Mapping</span></a>
                <a href="{{ route('dashboard', ['view' => 'config']) }}" title="Configuration"><span class="nav-icon">⚙️</span><span class="nav-label">Configuration</span></a>
                <a class="admin-nav-link active" href="{{ route('admin.index') }}" aria-current="page" title="Administrator"><span class="nav-icon">👥</span><span class="nav-label">Administrator</span></a>
            </nav>
            <div class="sidebar-account">
                <span class="sidebar-account-avatar" title="{{ auth()->user()->name }} · Administrator">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="sidebar-account-copy"><b>{{ auth()->user()->name }}</b><span>{{ auth()->user()->email }}</span><small>Administrator</small></span>
            </div>
        </aside>
        <div class="app-main admin-app-main">
            <header class="app-header admin-topbar">
                <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="true"><span></span><span></span><span></span></button>
                <div class="admin-header-title"><b>Administrator</b><small>USER &amp; ACCESS MANAGEMENT</small></div>
                <div class="admin-top-actions"><div class="language-switch" role="group" aria-label="Language"><button type="button" data-locale-switch="id">ID</button><button type="button" data-locale-switch="en">EN</button></div>@include('components.account-menu')<a class="soft-button" href="{{ route('dashboard') }}">← Dashboard</a></div>
            </header>
            <main class="admin-main">
        <div class="admin-heading"><div><span class="admin-eyebrow">PENGATURAN SISTEM</span><h1>Administrator</h1><p>Kelola akun pengguna dan akses ke FinOps Dashboard.</p></div><span class="admin-secure">◈ Admin workspace</span></div>

        @if (session('status')) <div class="portal-alert success" role="status">{{ session('status') }}</div> @endif
        @if ($errors->any()) <div class="portal-alert error" role="alert">{{ $errors->first() }}</div> @endif

        <nav class="admin-tabs" aria-label="Administrator sections"><a class="{{ $activeTab === 'users' ? 'active' : '' }}" href="{{ route('admin.index', ['tab' => 'users']) }}">Pengguna</a><a class="{{ $activeTab === 'roles' ? 'active' : '' }}" href="{{ route('admin.index', ['tab' => 'roles']) }}">Role &amp; Akses Halaman</a></nav>

        @if($activeTab === 'users')
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
                    <label>Peran<select name="role">@foreach($roles as $role)<option value="{{ $role->slug }}">{{ $role->name }}</option>@endforeach</select></label>
                    <label>Kata sandi sementara<input name="password" type="password" minlength="12" autocomplete="new-password" required><small>Minimal 12 karakter.</small></label>
                    <label>Ulangi kata sandi<input name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required></label>
                    <div class="form-action"><button class="primary-button" type="submit">Buat akun</button></div>
                </form>
            </details>
        </section>

        <section class="admin-panel user-list-panel">
            <div class="user-list-heading"><div><h2>Pengguna</h2><p>{{ number_format($users->total()) }} akun · Cari dan kelola status atau peran.</p></div>
                <form method="get" action="{{ route('admin.index') }}" class="user-filters"><input type="hidden" name="tab" value="users"><label class="sr-only" for="user-search">Cari nama atau email</label><input id="user-search" name="search" value="{{ $search }}" placeholder="Cari nama atau email"><label class="sr-only" for="user-role">Filter peran</label><select id="user-role" name="role"><option value="">Semua peran</option>@foreach($roles as $role)<option value="{{ $role->slug }}" @selected($roleFilter === $role->slug)>{{ $role->name }}</option>@endforeach</select><button class="soft-button" type="submit">Filter</button></form>
            </div>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
                @forelse ($users as $user)
                    <tr class="user-row"><td><div class="user-identity"><i>{{ strtoupper(substr($user->name, 0, 1)) }}</i><span><b>{{ $user->name }}</b><small>{{ $user->email }}</small></span></div></td><td><span class="role-pill {{ $user->hasAdministratorRole() ? 'role-admin' : '' }}">{{ $rolesBySlug[$user->role] ?? ($user->hasAdministratorRole() ? 'Administrator' : ucfirst($user->role)) }}</span></td><td><span class="state-pill {{ $user->is_active ? 'state-active' : 'state-inactive' }}"><i></i>{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span></td><td>{{ $user->created_at?->format('d M Y') ?? '—' }}</td><td><details class="user-edit"><summary>Edit</summary><form method="post" action="{{ route('admin.users.update', $user) }}" class="user-edit-form">
                        @csrf @method('PUT')
                        <label>Nama<input name="name" value="{{ $user->name }}" required></label>
                        <label>Email<input name="email" type="email" value="{{ $user->email }}" required></label>
                        <label>Peran<select name="role">@foreach($roles as $role)<option value="{{ $role->slug }}" @selected($user->role === $role->slug || ($role->slug === 'viewer' && $user->role === 'user') || ($role->slug === 'administrator' && $user->hasAdministratorRole()))>{{ $role->name }}</option>@endforeach</select></label>
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
        @else
        <section class="admin-role-intro"><div><h2>Kelola role dan izin akses</h2><p>Tentukan halaman yang dapat dibuka dan apakah role hanya dapat melihat atau juga mengubah data.</p></div><span>{{ $roles->count() }} role tersedia</span></section>
        <section class="admin-panel role-create-panel"><details><summary><span class="create-icon">＋</span><span><b>Buat role baru</b><small>Atur nama, deskripsi, dan izin per halaman.</small></span><span class="summary-action">Buka form</span></summary><form method="post" action="{{ route('admin.roles.store') }}" class="role-form">@csrf<div class="role-fields"><label>Nama role<input name="name" required maxlength="80" placeholder="Contoh: Finance Reviewer"></label><label>Kode role<input name="slug" required maxlength="32" pattern="[A-Za-z0-9_-]+" placeholder="finance-reviewer"></label><label class="role-description">Deskripsi<input name="description" maxlength="255" placeholder="Tujuan role ini"></label></div><div class="permissions-table-wrap"><table class="permissions-table"><thead><tr><th>Halaman</th><th>Akses</th></tr></thead><tbody>@foreach($pages as $slug => $page)<tr><td><b>{{ $page['label'] }}</b><small>{{ $page['group'] }}</small></td><td><select name="page_access[{{ $slug }}]"><option value="">Tidak ada akses</option><option value="read">Lihat</option><option value="edit">Lihat &amp; edit</option></select></td></tr>@endforeach</tbody></table></div><button class="primary-button" type="submit">Buat role</button></form></details></section>
        <section class="role-card-grid">@foreach($roles as $role)<article class="admin-panel role-card"><div class="role-card-heading"><div><span class="role-pill {{ $role->is_system ? 'role-admin' : '' }}">{{ $role->is_system ? 'Role sistem' : 'Role khusus' }}</span><h3 data-locale-ignore>{{ $role->name }}</h3><p data-locale-ignore>{{ $role->description ?: 'Tidak ada deskripsi.' }}</p></div><span class="role-assignment-count">{{ $role->users_count ?? $role->users()->count() }} akun</span></div>@if($role->slug === 'administrator')<div class="system-role-access"><b>Akses halaman</b><p>@foreach($role->page_access ?? [] as $pageKey => $permission){{ $pages[$pageKey]['label'] ?? $pageKey }}{{ $permission === 'edit' ? ' · Edit' : ' · Lihat' }}@if(!$loop->last), @endif @endforeach</p><small>Administrator memiliki akses penuh dan dilindungi. Role Viewer dapat diatur; buat role khusus untuk aturan lain.</small></div>@else<form method="post" action="{{ route('admin.roles.update', $role) }}" class="role-edit-form">@csrf @method('PUT')<label>Nama role<input name="name" value="{{ $role->name }}" required maxlength="80"></label><label>Deskripsi<input name="description" value="{{ $role->description }}" maxlength="255"></label><div class="permissions-table-wrap"><table class="permissions-table"><thead><tr><th>Halaman</th><th>Akses</th></tr></thead><tbody>@foreach($pages as $slug => $page)<tr><td><b>{{ $page['label'] }}</b><small>{{ $page['group'] }}</small></td><td><select name="page_access[{{ $slug }}]"><option value="" @selected(!isset($role->page_access[$slug]))>Tidak ada akses</option><option value="read" @selected(($role->page_access[$slug] ?? null) === 'read')>Lihat</option><option value="edit" @selected(($role->page_access[$slug] ?? null) === 'edit')>Lihat &amp; edit</option></select></td></tr>@endforeach</tbody></table></div><div class="role-actions"><button class="primary-button" type="submit">Simpan izin</button></form><form method="post" action="{{ route('admin.roles.delete', $role) }}" onsubmit="return confirm(document.documentElement.lang === 'en' ? 'Delete this role?' : 'Hapus role ini?')">@csrf @method('DELETE')<button class="danger-button" type="submit" @disabled($role->users_count > 0 || $role->is_system)>Hapus role</button></form></div>@endif</article>@endforeach</section>
        <p class="admin-footnote">Administrator memiliki akses penuh dan role sistem tidak dapat diubah. Akses khusus langsung berlaku untuk akun yang memakai role tersebut.</p>
        @endif
            </main>
        </div>
    </div>
    @include('components.change-password-dialog')
    <script>
        (() => {
            const toggle = document.getElementById('sidebarToggle');
            const scrim = document.getElementById('sidebarScrim');
            if (window.innerWidth <= 760) document.body.classList.remove('sidebar-collapsed', 'tablet-sidebar-expanded');
            const closeMobile = () => {
                document.body.classList.remove('sidebar-open');
                toggle.setAttribute('aria-expanded', 'false');
            };
            toggle.addEventListener('click', () => {
                const width = window.innerWidth;
                if (width <= 760) {
                    const open = !document.body.classList.contains('sidebar-open');
                    document.body.classList.toggle('sidebar-open', open);
                    toggle.setAttribute('aria-expanded', String(open));
                } else if (width <= 1050) {
                    const open = !document.body.classList.contains('tablet-sidebar-expanded');
                    document.body.classList.toggle('tablet-sidebar-expanded', open);
                    toggle.setAttribute('aria-expanded', String(open));
                } else {
                    const expanded = document.body.classList.contains('sidebar-collapsed');
                    document.body.classList.toggle('sidebar-collapsed', !expanded);
                    toggle.setAttribute('aria-expanded', String(expanded));
                }
            });
            scrim.addEventListener('click', closeMobile);
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) closeMobile();
            });
            window.addEventListener('resize', () => {
                document.body.classList.remove('sidebar-open', 'tablet-sidebar-expanded');
                if (window.innerWidth <= 760) document.body.classList.remove('sidebar-collapsed');
                toggle.setAttribute('aria-expanded', String(window.innerWidth > 1050));
            });
            toggle.setAttribute('aria-expanded', String(window.innerWidth > 1050));
        })();
    </script>
</body>
</html>
