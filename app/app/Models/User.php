<?php

namespace App\Models;

use App\Support\Rbac;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Compatibility bridge for legacy call sites (and tests) that only set
        // `is_admin` without an explicit `role`. Authorization itself never
        // reads `is_admin` — see App\Support\Rbac and docs/RBAC.md.
        static::creating(function (User $user) {
            if ($user->role === null) {
                $user->role = $user->is_admin ? Rbac::ROLE_ADMIN : Rbac::ROLE_OPERATOR;
            }
            // Explicit, not left to the DB column default: Eloquent doesn't
            // refetch DB-defaulted columns after insert, so the in-memory
            // attribute would otherwise stay null (falsy) for the rest of
            // the request/test — which EnsureUserIsActive would treat as disabled.
            if ($user->is_active === null) {
                $user->is_active = true;
            }
        });
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, Rbac::permissionsForRole($this->role), true);
    }

    /**
     * True when this user is an active admin and no other active admin exists —
     * used to block demoting/disabling the last administrator.
     */
    public function isLastActiveAdmin(): bool
    {
        if (! $this->hasRole(Rbac::ROLE_ADMIN) || ! $this->is_active) {
            return false;
        }

        return ! static::query()
            ->where('role', Rbac::ROLE_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $this->id)
            ->exists();
    }
}
