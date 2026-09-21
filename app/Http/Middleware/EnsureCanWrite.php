<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureCanWrite
{
    // Roles that can write data (level >= 2 or specific codes)
    private const WRITE_ROLES = [
        'R01_SUPERADMIN',
        'R02_ADMIN_FUNCIONAL',
        'R03_ADMIN_DATOS',
        'R07_GESTOR_INFO',
        'R08_COORDINADOR',
        'R09_CAMPO',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $roleCode = DB::table('roles')->where('id', $user->role_id)->value('code');

        if (!in_array($roleCode, self::WRITE_ROLES)) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'No tiene permisos para esta acción.'], 403);
            }
            abort(403, 'No tiene permisos para esta acción.');
        }

        return $next($request);
    }
}
