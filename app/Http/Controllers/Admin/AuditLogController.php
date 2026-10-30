<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCampaign;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditLogController extends Controller
{
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    public function index(Request $request)
    {
        if (! $request->user()->hasPermission('audit.view')) {
            abort(403);
        }

        $campaign = app(CurrentCampaign::class);
        $query = AuditLog::with('user:id,name,email');
        $users = User::select('id', 'name', 'email')->orderBy('name');

        // Superadmin sees everything; anyone else only the events of the non-superadmin users of the campaigns they can see.
        if (! $campaign->isSuperadmin()) {
            $superadminRoles = Role::where('code', 'R01_SUPERADMIN')->select('id');
            $scope = fn ($q) => $q->whereIn('campaign_id', $campaign->visibleIds())
                ->where(fn ($r) => $r->whereNull('role_id')->orWhereNotIn('role_id', $superadminRoles));
            $query->whereIn('user_id', $scope(User::query())->select('id'));
            $scope($users);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('action')) {
            $query->where('action', 'ILIKE', '%'.$this->escapeLike($request->action).'%');
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $escaped = $this->escapeLike($request->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('action', 'ILIKE', "%{$escaped}%")
                    ->orWhere('entity_type', 'ILIKE', "%{$escaped}%")
                    ->orWhereRaw('metadata::text ILIKE ?', ["%{$escaped}%"]);
            });
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $users = $users->get();
        $entityTypes = AuditLog::distinct()->pluck('entity_type')->sort()->values();

        return Inertia::render('Admin/AuditLog', [
            'logs' => $logs,
            'filters' => $request->only('user_id', 'action', 'entity_type', 'date_from', 'date_to', 'search'),
            'users' => $users,
            'entityTypes' => $entityTypes,
        ]);
    }
}
