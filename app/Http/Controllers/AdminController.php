<?php

namespace App\Http\Controllers;

use App\Models\ImportJob;
use App\Models\PoliticalOrganization;
use App\Models\Role;
use App\Models\SourceFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        return Inertia::render('Admin/Users', [
            'users' => User::with('role')->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'roleCode' => $u->role?->code,
                'isActive' => $u->is_active,
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
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $data['role_id'],
            'is_active' => true,
        ]);

        return back()->with('success', 'Usuario creado.');
    }

    public function toggleUser(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => !$user->is_active]);
        return back();
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
