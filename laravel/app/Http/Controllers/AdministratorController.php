<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.index', [
            'users' => $users,
            'search' => $search,
            'roleFilter' => $role,
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
            'role' => ['required', Rule::in(['administrator', 'viewer'])],
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
            'role' => ['required', Rule::in(['administrator', 'viewer'])],
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
}
