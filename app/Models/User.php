<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'is_active',
        'must_change_password', 'territory_scope', 'preferred_density', 'executive_mode',
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
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'territory_scope' => 'array',
            'executive_mode' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function resolvedPermissions(): array
    {
        $perms = config('permissions.' . $this->role?->code, []);
        return in_array('*', $perms) ? ['*'] : $perms;
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->resolvedPermissions();
        return in_array('*', $perms) || in_array($permission, $perms);
    }

    public function hasTerritoryScopeRestriction(): bool
    {
        return ! empty($this->territory_scope) && $this->role?->code !== 'R01_SUPERADMIN';
    }

    public function allowedMunicipioNames(): ?array
    {
        if (! $this->hasTerritoryScopeRestriction()) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('geographic_units')
            ->whereIn('id', $this->territory_scope)
            ->pluck('canonical_name')
            ->toArray();
    }

    public function allowedGeoIds(): ?array
    {
        if (! $this->hasTerritoryScopeRestriction()) {
            return null;
        }

        return $this->territory_scope;
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function recentViews(): HasMany
    {
        return $this->hasMany(RecentView::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
