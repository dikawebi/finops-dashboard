<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->hasAdministratorRole() && $this->is_active;
    }

    public function hasAdministratorRole(): bool
    {
        return in_array($this->role, ['admin', 'administrator'], true);
    }

    public function dashboardRole(): BelongsTo
    {
        return $this->belongsTo(DashboardRole::class, 'role', 'slug');
    }

    public function canAccessPage(string $page, bool $edit = false): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->hasAdministratorRole()) {
            return true;
        }

        $role = $this->dashboardRole;
        if (! $role && $this->role === 'user') {
            $role = DashboardRole::query()->find('viewer');
        }
        $access = $role?->page_access ?? [];
        $permission = $access[$page] ?? null;

        return $edit ? $permission === 'edit' : in_array($permission, ['read', 'edit'], true);
    }
}
