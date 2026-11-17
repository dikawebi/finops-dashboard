<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\DashboardRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdministratorController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $role = $request->query('role');

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($role === 'administrator', fn ($query) => $query->whereIn('role', ['admin', 'administrator']))
            ->when($role === 'viewer', fn ($query) => $query->whereIn('role', ['user', 'viewer']))
            ->when($role && ! in_array($role, ['administrator', 'viewer'], true), fn ($query) => $query->where('role', $role))
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.index', [
            'users' => $users,
            'search' => $search,
            'roleFilter' => $role,
            'roles' => DashboardRole::withCount('users')->orderBy('name')->get(),
            'rolesBySlug' => DashboardRole::pluck('name', 'slug'),
            'pages' => DashboardRole::pages(),
            'activeTab' => $request->query('tab') === 'roles' ? 'roles' : 'users',
            'stats' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'admins' => User::whereIn('role', ['admin', 'administrator'])->where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', 'exists:dashboard_roles,slug'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'is_active' => true,
            'password' => Hash::make($data['password']),
        ]);

        return to_route('admin.index')->with('status', 'Akun pengguna berhasil dibuat.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'exists:dashboard_roles,slug'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
        ]);

        if ($user->is($request->user()) && ($data['role'] !== 'administrator' || ! $data['is_active'])) {
            return back()->withErrors(['user' => 'Anda tidak dapat menonaktifkan atau menurunkan peran akun sendiri.'])->withInput();
        }

        if (in_array($user->role, ['admin', 'administrator'], true) && $user->is_active
            && ($data['role'] !== 'administrator' || ! $data['is_active'])
            && User::whereIn('role', ['admin', 'administrator'])->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['user' => 'Administrator aktif terakhir tidak dapat dinonaktifkan atau diturunkan perannya.'])->withInput();
        }

        $user->fill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'is_active' => (bool) $data['is_active'],
        ]);

        if (filled($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return to_route('admin.index')->with('status', 'Perubahan akun berhasil disimpan.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:32', 'unique:dashboard_roles,slug', Rule::notIn(['admin', 'administrator'])],
            'name' => ['required', 'string', 'max:80', 'unique:dashboard_roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'page_access' => ['nullable', 'array'],
            'page_access.*' => ['nullable', Rule::in(['read', 'edit'])],
        ]);

        DashboardRole::create([
            'slug' => strtolower($data['slug']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'page_access' => $this->allowedPageAccess($data['page_access'] ?? []),
            'is_system' => false,
        ]);

        return to_route('admin.index', ['tab' => 'roles'])->with('status', 'Role berhasil dibuat.');
    }

    public function updateRole(Request $request, DashboardRole $role): RedirectResponse
    {
        abort_if($role->slug === 'administrator', 403, 'The Administrator system role cannot be changed.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('dashboard_roles', 'name')->ignore($role->slug, 'slug')],
            'description' => ['nullable', 'string', 'max:255'],
            'page_access' => ['nullable', 'array'],
            'page_access.*' => ['nullable', Rule::in(['read', 'edit'])],
        ]);

        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'page_access' => $this->allowedPageAccess($data['page_access'] ?? []),
        ]);

        return to_route('admin.index', ['tab' => 'roles'])->with('status', 'Hak akses role berhasil diperbarui.');
    }

    public function deleteRole(DashboardRole $role): RedirectResponse
    {
        abort_if($role->is_system, 403, 'System roles cannot be deleted.');
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Role masih digunakan oleh akun. Pindahkan akun ke role lain sebelum menghapusnya.']);
        }
        $role->delete();

        return to_route('admin.index', ['tab' => 'roles'])->with('status', 'Role berhasil dihapus.');
    }

    private function allowedPageAccess(array $permissions): array
    {
        $allowed = [];
        foreach (array_keys(DashboardRole::pages()) as $page) {
            if (in_array($permissions[$page] ?? null, ['read', 'edit'], true)) {
                $allowed[$page] = $permissions[$page];
            }
        }
        return $allowed;
    }
}
