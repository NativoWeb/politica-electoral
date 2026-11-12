<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeCredentials;
use App\Models\AuditLog;
use App\Models\ImportJob;
use App\Models\PoliticalOrganization;
use App\Models\Role;
use App\Models\SourceFile;
use App\Models\User;
use App\Models\WhatsAppLinkCode;
use App\Services\Agent\AgentIdentityService;
use App\Support\CurrentCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
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
        ['key' => 'view.eventos', 'label' => 'Ver eventos y asistentes', 'group' => 'Eventos'],
        ['key' => 'manage.eventos', 'label' => 'Gestionar eventos (crear, abrir, aprobar asistentes)', 'group' => 'Eventos'],
        ['key' => 'register.asistentes', 'label' => 'Registrar asistentes (modo operador)', 'group' => 'Eventos'],
        ['key' => 'delete.actas', 'label' => 'Eliminar actas confirmadas', 'group' => 'Eventos',
            'description' => 'Borrar un acta ya confirmada (por ejemplo, confirmada por error) y deshacer lo que creó. Requiere también Gestionar eventos.'],
        ['key' => 'view.empresarios', 'label' => 'Ver empresarios y gremios', 'group' => 'Empresarios'],
        ['key' => 'manage.empresarios', 'label' => 'Gestionar empresarios y gremios (crear, editar, borrar, cargar Excel, actas)', 'group' => 'Empresarios'],
        ['key' => 'use.whatsapp', 'label' => 'Asistente de WhatsApp', 'group' => 'WhatsApp',
            'description' => 'Vincular su número en Mi perfil y consultar o registrar datos con el asistente por WhatsApp.',
            'requiresWhatsapp' => true],
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
                ? DB::table('campaigns')->orderBy('name')->get(['id', 'name', 'parent_id', 'whatsapp_enabled'])
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
    // their own or outside CAMPAIGN_ROLES, nor grant or remove permissions they do not have. A permission the actor lacks
    // is kept as it is in the database ($target is always loaded from it), so the user can still be edited and toggled.
    // $permissions null = the request does not touch permissions (toggle, delete).
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
        abort_if($roleId && ! $this->assignableRoles()->whereKey($roleId)->exists(), 403, 'No puedes asignar ese rol.');

        $granted = array_diff($permissions ?? [], $target?->permissions ?? []);
        abort_if(array_diff($granted, $actor->resolvedPermissions()) !== [], 403, 'No puedes otorgar permisos que no tienes.');

        $removed = $permissions === null ? [] : array_diff($target?->permissions ?? [], $permissions);
        abort_if(array_diff($removed, $actor->resolvedPermissions()) !== [], 403, 'Solo el superadministrador puede quitar permisos que tú no tienes.');
    }

    // The target holds a permission the actor lacks: the actor may edit and toggle them, but not take over the account
    // (email, password) nor delete it.
    private function outranksActor(User $target): bool
    {
        return ! $this->campaign()->isSuperadmin()
            && array_diff($target->permissions ?? [], request()->user()->resolvedPermissions()) !== [];
    }

    // The WhatsApp assistant is only handed out to users whose campaign has WhatsApp enabled (superadmin included).
    // A user who already holds it keeps it when the campaign is switched off: the agent ignores it meanwhile.
    private function authorizeWhatsappGrant(?User $target, ?array $permissions, string $campaignId): void
    {
        $granted = array_diff($permissions ?? [], $target?->permissions ?? []);
        if (! in_array('use.whatsapp', $granted, true)) {
            return;
        }

        if (! DB::table('campaigns')->where('id', $campaignId)->value('whatsapp_enabled')) {
            throw ValidationException::withMessages([
                'permissions' => 'La campaña de este usuario no tiene WhatsApp activo; no se le puede dar el Asistente de WhatsApp.',
            ]);
        }
    }

    // Validated (unique) and compared in the same form it is stored in.
    private function normalizeEmailInput(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normalizeEmail($request->input('email'))]);
        }
    }

    // Case-insensitive, so an address stored with capitals before normalization cannot be taken again in lower case.
    private function uniqueEmail(?string $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
            $taken = User::query()->whereRaw('lower(email) = ?', [User::normalizeEmail((string) $value)])
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();
            if ($taken) {
                $fail('Ya existe un usuario con ese correo.');
            }
        };
    }

    // Superadmin picks the campaign (its own by default); anyone else always creates in their own campaign.
    private function campaignRule(): array
    {
        return $this->campaign()->isSuperadmin() ? ['nullable', 'bail', 'uuid', 'exists:campaigns,id'] : ['exclude'];
    }

    public function storeUser(Request $request)
    {
        $this->normalizeEmailInput($request);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', $this->uniqueEmail()],
            'role_id' => 'nullable|bail|uuid|exists:roles,id',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'territory_scope' => 'nullable|array',
            'territory_scope.*' => 'uuid',
            'campaign_id' => $this->campaignRule(),
        ]);
        $this->authorizeUserWrite(null, $data['role_id'] ?? null, $data['permissions'] ?? null);
        $this->authorizeWhatsappGrant(null, $data['permissions'] ?? null, $data['campaign_id'] ?? $this->campaign()->ownId());

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
        $this->normalizeEmailInput($request);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', $this->uniqueEmail($user->id)],
            'role_id' => 'nullable|bail|uuid|exists:roles,id',
            'password' => 'nullable|string|min:8',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'territory_scope' => 'nullable|array',
            'territory_scope.*' => 'uuid',
            'campaign_id' => $this->campaignRule(),
        ]);
        $this->authorizeUserWrite($user, $data['role_id'] ?? null, $data['permissions'] ?? []);
        abort_if($this->outranksActor($user) && (! empty($data['password']) || $data['email'] !== User::normalizeEmail($user->email)),
            403, 'No puedes cambiar el correo ni la contraseña de un usuario con permisos que no tienes.');
        $this->authorizeWhatsappGrant($user, $data['permissions'] ?? null, $data['campaign_id'] ?? $user->campaignId());
        $hadWhatsapp = in_array('use.whatsapp', $user->permissions ?? [], true);

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
        DB::transaction(function () use ($user, $hadWhatsapp) {
            $user->save();
            // Revoking the assistant ends its agent sessions and pending codes now; the linked number is kept, inactive.
            if ($hadWhatsapp && ! in_array('use.whatsapp', $user->permissions ?? [], true)) {
                $revoked = $user->tokens()->where('name', AgentIdentityService::TOKEN_NAME)->delete();
                WhatsAppLinkCode::active()->where('user_id', $user->id)->update(['expires_at' => now()]);
                if ($revoked > 0) {
                    AuditLog::create([
                        'user_id' => request()->user()->id,
                        'action' => 'AGENT_TOKEN_REVOKED',
                        'entity_type' => 'usuario',
                        'entity_id' => $user->id,
                        'metadata' => ['motivo' => 'permiso_retirado', 'ruta' => 'admin.users.update', 'tokens' => $revoked],
                        'ip_address' => request()->ip(),
                    ]);
                }
            }
        });

        return back()->with('success', 'Usuario actualizado.');
    }

    public function toggleUser(string $id)
    {
        $user = $this->managedUsers()->findOrFail($id);
        $this->authorizeUserWrite($user);
        if ($user->id === request()->user()->id) {
            return back()->withErrors(['toggle' => 'No puedes desactivar tu propia cuenta']);
        }
        // Deactivating a user with permissions the actor lacks is allowed; bringing them back is the superadmin's call.
        abort_if(! $user->is_active && $this->outranksActor($user), 403, 'Solo el superadmin puede reactivar a este usuario.');
        $user->update(['is_active' => !$user->is_active]);
        return back();
    }

    public function destroyUser(string $id)
    {
        $user = $this->managedUsers()->findOrFail($id);
        $this->authorizeUserWrite($user);
        abort_if($this->outranksActor($user), 403, 'Solo el superadministrador puede eliminar a un usuario con permisos que tú no tienes.');
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
