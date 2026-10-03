<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeCredentials;
use App\Models\ImportJob;
use App\Models\PoliticalOrganization;
use App\Models\Role;
use App\Models\SourceFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Index', [
            'stats' => [
                'users' => User::count(),
                'roles' => Role::count(),
                'partidos' => PoliticalOrganization::count(),
                'imports' => ImportJob::count(),
                'sourceFiles' => SourceFile::count(),
            ],
        ]);
    }

    public function users()
    {
        $provincias = DB::table('geographic_units as p')
            ->where('p.type', 'province')
            ->orderBy('p.canonical_name')
            ->get(['p.id', 'p.canonical_name as name'])
            ->map(function ($prov) {
                $prov->municipios = DB::table('geographic_units')
                    ->where('type', 'municipality')
                    ->where('parent_id', $prov->id)
                    ->orderBy('canonical_name')
                    ->get(['id', 'canonical_name as name'])
                    ->toArray();
                return $prov;
            })
            ->toArray();

        $municipios = DB::table('geographic_units')
            ->where('type', 'municipality')
            ->orderBy('canonical_name')
            ->get(['id', 'canonical_name as name'])
            ->toArray();

        return Inertia::render('Admin/Users', [
            'users' => User::with('role')->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'roleCode' => $u->role?->code,
                'isActive' => $u->is_active,
                'territoryScope' => $u->territory_scope,
                'createdAt' => $u->created_at?->format('Y-m-d'),
            ]),
            'roles' => Role::where('is_active', true)
                ->whereIn('code', ['R01_SUPERADMIN', 'R09_CAMPO'])
                ->orderBy('level', 'desc')
                ->get(['id', 'name', 'code'])
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->code === 'R09_CAMPO' ? 'Operador' : $r->name,
                    'code' => $r->code,
                ]),
            'municipios' => $municipios,
            'provincias' => $provincias,
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role_id' => 'nullable|exists:roles,id',
            'territory_scope' => 'nullable|array',
            'territory_scope.*' => 'uuid',
        ]);

        $plainPassword = \Illuminate\Support\Str::random(10);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($plainPassword),
            'role_id' => $data['role_id'],
            'territory_scope' => ! empty($data['territory_scope']) ? $data['territory_scope'] : null,
            'is_active' => true,
            'must_change_password' => true,
        ]);

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
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role_id' => 'nullable|exists:roles,id',
            'password' => 'nullable|string|min:8',
            'territory_scope' => 'nullable|array',
            'territory_scope.*' => 'uuid',
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role_id = $data['role_id'] ?: null;
        $user->territory_scope = ! empty($data['territory_scope']) ? $data['territory_scope'] : null;
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with('success', 'Usuario actualizado.');
    }

    public function toggleUser(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => !$user->is_active]);
        return back();
    }

    public function destroyUser(string $id)
    {
        $user = User::findOrFail($id);
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
