<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'is_active',
        'must_change_password', 'permissions', 'territory_scope', 'preferred_density', 'executive_mode',
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
            'permissions' => 'array',
            'territory_scope' => 'array',
            'executive_mode' => 'boolean',
            'whatsapp_verified_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // A model built in memory (User::create) does not carry the column default until it is reloaded.
    public function campaignId(): string
    {
        if (! $this->campaign_id) {
            $this->campaign_id = DB::table('users')->where('id', $this->id)->value('campaign_id') ?? Campaign::DEFAULT_ID;
        }

        return $this->campaign_id;
    }

    // Bulk WhatsApp and the WhatsApp agent are only for users whose campaign has WhatsApp enabled.
    public function campaignHasWhatsapp(): bool
    {
        return (bool) DB::table('campaigns')->where('id', $this->campaignId())->value('whatsapp_enabled');
    }

    // Why the user may not log in or keep using the system, or null when they may. Only an explicit false
    // blocks, so a model built in memory without the column default is not locked out.
    public function accessBlockedReason(): ?string
    {
        if ($this->is_active === false) {
            return 'Tu cuenta está desactivada. Comunícate con el administrador.';
        }

        if ($this->role?->code !== 'R01_SUPERADMIN'
            && DB::table('campaigns')->where('id', $this->campaignId())->where('is_active', false)->exists()) {
            return 'Tu campaña está desactivada. Comunícate con el administrador de la plataforma.';
        }

        return null;
    }

    public function resolvedPermissions(): array
    {
        if ($this->role?->code === 'R01_SUPERADMIN') {
            return ['*'];
        }

        return $this->permissions ?? [];
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
