<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $roleCode = $user?->role?->code;

        $permissions = $user ? $user->resolvedPermissions() : [];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->name,
                    'roleCode' => $roleCode,
                    'isSuperadmin' => $roleCode === 'R01_SUPERADMIN',
                    'territoryScope' => $user->territory_scope,
                    'permissions' => $permissions,
                    'canWrite' => in_array('*', $permissions) || in_array('write.data', $permissions),
                    'whatsapp' => $this->whatsappLink($user),
                ] : null,
            ],
            'agent' => $user ? [
                'whatsappNumber' => (string) config('agent.whatsapp_number', ''),
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }

    // Only the last 4 digits travel in the shared props, and only once the link is verified.
    private function whatsappLink(User $user): array
    {
        $linked = $user->whatsapp_phone !== null && $user->whatsapp_verified_at !== null;

        return [
            'linked' => $linked,
            'phoneLast4' => $linked ? substr($user->whatsapp_phone, -4) : null,
            'verifiedAt' => $linked ? $user->whatsapp_verified_at->toIso8601String() : null,
        ];
    }
}
