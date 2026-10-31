<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeCredentials;
use App\Models\ImportJob;
use App\Models\PoliticalOrganization;
use App\Models\Role;
use App\Models\SourceFile;
use App\Models\User;
use App\Support\CurrentCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class AdminController extends Controller
{
    // Roles a campaign may hand out (Administrador de campaña, Operador and the read-only ones). The rest
    // (superadmin, data admin, analysts, auditor...) are platform roles that only a superadmin assigns.
    public const CAMPAIGN_ROLES = ['R02_ADMIN_FUNCIONAL', 'R09_CAMPO', 'R10_CONSULTA', 'R12_REPORTES'];

    // Roles offered by the Users page (the read-only ones are assignable through the API until the UI offers them).
    private const LISTED_ROLES = ['R01_SUPERADMIN', 'R02_ADMIN_FUNCIONAL', 'R09_CAMPO'];

    private function campaign(): CurrentCampaign
    {
        return app(CurrentCampaign::class);
    }

    // Superadmin manages every user; anyone else only the users of their own campaign (not its subcampaigns).
    private function managedUsers()
    {
        $query = User::query();
        if (! $this->campaign()->isSuperadmin()) {
            $query->where('campaign_id', $this->campaign()->ownId());
        }

        return $query;
    }

    private const PERMISSIONS = [
        ['key' => 'view.gobernador', 'label' => 'Ver Gobernador', 'group' => 'Vistas'],
        ['key' => 'view.mapa', 'label' => 'Ver Mapa Político', 'group' => 'Vistas'],
        ['key' => 'view.votantes', 'label' => 'Ver Votantes', 'group' => 'Vistas'],
        ['key' => 'view.admin', 'label' => 'Ver panel Admin', 'group' => 'Vistas'],
        ['key' => 'write.data', 'label' => 'Editar datos (líderes, personas)', 'group' => 'Acciones'],
        ['key' => 'manage.users', 'label' => 'Gestionar usuarios', 'group' => 'Administración'],
        ['key' => 'manage.territory', 'label' => 'Gestionar territorio', 'group' => 'Administración'],
        ['key' => 'manage.partidos', 'label' => 'Gestionar partidos', 'group' => 'Administración'],
        ['key' => 'audit.view', 'label' => 'Ver auditoría', 'group' => 'Administración'],
    ];

    // The form only offers what the actor may hand out: a superadmin everything, anyone else the permissions they hold.
    private function grantablePermissions(): array
    {
        $held = request()->user()->resolvedPermissions();
        if ($this->campaign()->isSuperadmin() || in_array('*', $held, true)) {
            return self::PERMISSIONS;
        }

        return array_values(array_filter(self::PERMISSIONS, fn ($p) => in_array($p['key'], $held, true)));
    }

    private function assignableRoles()
    {
        $query = Role::where('is_active', true);
        if (! $this->campaign()->isSuperadmin()) {
            $query->whereIn('code', self::CAMPAIGN_ROLES)
                ->where('level', '<=', (int) request()->user()->role?->level);
        }

        return $query;
    }

    // The admin home links to platform screens; any other campaign lands on its users, or on the map without manage.users.
    public function index()
    {
        if (! $this->campaign()->canEditElectoral()) {
            return redirect(request()->user()->hasPermission('manage.users') ? '/admin/usuarios' : '/mapa-politico');
        }

        return Inertia::render('Admin/Index', [
            'stats' => [
                'users' => $this->managedUsers()->count(),
                'roles' => Role::count(),
                'partidos' => PoliticalOrganization::count(),
                'imports' => ImportJob::count(),
                'sourceFiles' => SourceFile::count(),
            ],
        ]);
    }

    public function users()
    {
        $displayNames = ['Soto' => 'Área Metropolitana'];

        $provincias = DB::table('geographic_units as p')
            ->where('p.type', 'province')
            ->orderBy('p.canonical_name')
            ->get(['p.id', 'p.canonical_name as name'])
            ->map(function ($prov) use ($displayNames) {
                $prov->name = $displayNames[$prov->name] ?? $prov->name;
                $prov->municipios = DB::table('geographic_units')
                    ->where('type', 'municipality')
                    ->where('parent_id', $prov->id)
                    ->orderBy('canonical_name')
                    ->get(['id', 'canonical_name as name'])
                    ->values()
                    ->all();
                return $prov;
            })
            ->filter(fn ($prov) => count($prov->municipios) > 0)
            ->values()
            ->all();

        $municipios = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->orderBy('canonical_name')
            ->get(['id', 'canonical_name as name'])
            ->toArray();

        $search = request('search', '');
        $query = $this->managedUsers()->with(['role', 'campaign:id,name'])->orderBy('name');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $paginated = $query->paginate(10)->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $paginated->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'roleCode' => $u->role?->code,
                'isActive' => $u->is_active,
                'permissions' => $u->permissions,
                'territoryScope' => $u->territory_scope,
                'campaignId' => $u->campaign_id,
                'campaignName' => $u->campaign?->name,
                'createdAt' => $u->created_at?->format('Y-m-d'),
            ]),
            'filters' => ['search' => $search],
            'campaigns' => $this->campaign()->isSuperadmin()
                ? DB::table('campaigns')->orderBy('name')->get(['id', 'name', 'parent_id'])
                : [],
            'roles' => $this->assignableRoles()
                ->whereIn('code', self::LISTED_ROLES)
                ->orderBy('level', 'desc')
                ->get(['id', 'name', 'code'])
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->code === 'R09_CAMPO' ? 'Operador' : $r->name,
                    'code' => $r->code,
                ]),
            'municipios' => $municipios,
            'provincias' => $provincias,
            'availablePermissions' => $this->grantablePermissions(),
            'permissionLabels' => array_column(self::PERMISSIONS, 'label', 'key'),
        ]);
    }

    // Only a superadmin may grant superadmin or '*', or touch a superadmin; territory-restricted users cannot manage users at all.
    // Anyone else works inside their own campaign (managedUsers) and can neither hand out nor touch a role above
    // their own or outside CAMPAIGN_ROLES, nor grant permissions they do not have.
    private function authorizeUserWrite(?User $target = null, ?string $roleId = null, ?array $permissions = null): void
    {
        $actor = request()->user();
        if ($this->campaign()->isSuperadmin()) {
            return;
        }
        abort_if($actor->hasTerritoryScopeRestriction(), 403, 'Un usuario con territorio restringido no puede gestionar usuarios.');
        abort_if($target?->role?->code === 'R01_SUPERADMIN', 403, 'Solo un superadministrador puede modificar a otro superadministrador.');
        abort_if($roleId && Role::where('id', $roleId)->value('code') === 'R01_SUPERADMIN', 403, 'Solo un superadministrador puede asignar ese rol.');
        abort_if(in_array('*', $permissions ?? [], true), 403, 'Solo un superadministrador puede otorgar todos los permisos.');

        abort_if($target?->role_id && ! $this->assignableRoles()->whereKey($target->role_id)->exists(), 403, 'No puedes modificar a un usuario con ese rol.');
        abort_if($target && array_diff($target->permissions ?? [], $actor->resolvedPermissions()) !== [], 403, 'No puedes modificar a un usuario con permisos que no tienes.');
        abort_if($roleId && ! $this->assignableRoles()->whereKey($roleId)->exists(), 403, 'No puedes asignar ese rol.');

        $granted = array_diff($permissions ?? [], $target?->permissions ?? []);
        abort_if(array_diff($granted, $actor->resolvedPermissions()) !== [], 403, 'No puedes otorgar permisos que no tienes.');
    }

    // Superadmin picks the campaign (its own by default); anyone else always creates in their own campaign.
    private function campaignRule(): array
    {
        return $this->campaign()->isSuperadmin() ? ['nullable', 'uuid', 'exists:campaigns,id'] : ['exclude'];
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role_id' => 'nullable|exists:roles,id',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'territory_scope' => 'nullable|array',
            'territory_scope.*' => 'uuid',
            'campaign_id' => $this->campaignRule(),
        ]);
        $this->authorizeUserWrite(null, $data['role_id'] ?? null, $data['permissions'] ?? null);

        $plainPassword = \Illuminate\Support\Str::random(10);

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($plainPassword),
            'role_id' => $data['role_id'],
            'permissions' => ! empty($data['permissions']) ? $data['permissions'] : null,
            'territory_scope' => ! empty($data['territory_scope']) ? $data['territory_scope'] : null,
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->forceFill(['campaign_id' => $data['campaign_id'] ?? $this->campaign()->ownId()])->save();

        Mail::to($user->email)->send(new WelcomeCredentials(
            userName: $user->name,
            userEmail: $user->email,
            plainPassword: $plainPassword,
            loginUrl: url('/login'),
        ));

        return back()->with('success', 'Usuario creado. Se enviaron las credenciales por correo.');
    }

    public function updateUser(Request $request, string $id)
    {
        $user = $this->managedUsers()->findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role_id' => 'nullable|exists:roles,id',
            'password' => 'nullable|string|min:8',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'territory_scope' => 'nullable|array',
            'territory_scope.*' => 'uuid',
            'campaign_id' => $this->campaignRule(),
        ]);
        $this->authorizeUserWrite($user, $data['role_id'] ?? null, $data['permissions'] ?? null);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role_id = $data['role_id'] ?: null;
        $user->permissions = ! empty($data['permissions']) ? $data['permissions'] : null;
        $user->territory_scope = ! empty($data['territory_scope']) ? $data['territory_scope'] : null;
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        if (! empty($data['campaign_id'])) {
            $user->campaign_id = $data['campaign_id'];
        }
        $user->save();

        return back()->with('success', 'Usuario actualizado.');
    }

    public function toggleUser(string $id)
    {
        $user = $this->managedUsers()->findOrFail($id);
        $this->authorizeUserWrite($user);
        if ($user->id === request()->user()->id) {
            return back()->withErrors(['toggle' => 'No puedes desactivar tu propia cuenta']);
        }
        $user->update(['is_active' => !$user->is_active]);
        return back();
    }

    public function destroyUser(string $id)
    {
        $user = $this->managedUsers()->findOrFail($id);
        $this->authorizeUserWrite($user);
        if ($user->id === request()->user()->id) {
            return back()->withErrors(['delete' => 'No puedes eliminar tu propia cuenta']);
        }
        $user->delete();
        return back()->with('success', 'Usuario eliminado.');
    }

    public function imports()
    {
        return Inertia::render('Admin/Imports', [
            'imports' => SourceFile::orderByDesc('created_at')->get()->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->original_name,
                'size' => $f->size_bytes,
                'type' => $f->file_type,
                'hash' => substr($f->sha256_hash ?? '', 0, 12),
                'date' => $f->created_at?->format('Y-m-d H:i'),
            ]),
        ]);
    }

    public function catalogs()
    {
        return Inertia::render('Admin/Catalogs', [
            'partidos' => PoliticalOrganization::orderBy('canonical_name')->get(['id', 'canonical_name', 'acronym', 'type', 'status', 'color_hex']),
            'oficios' => DB::table('offices')->orderBy('name')->get(['id', 'name', 'type']),
            'corporaciones' => DB::table('corporations')->orderBy('name')->get(['id', 'name', 'scope']),
        ]);
    }
}
