<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
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

    // Page props carry personal data: keep them encrypted in history.state so they die with the session,
    // and keep authenticated pages out of the HTTP cache / bfcache so Back cannot replay them after logout.
    // The service worker keeps its own offline copies in Cache Storage, which ignores these headers.
    public function handle(Request $request, Closure $next)
    {
        Inertia::encryptHistory();

        $response = parent::handle($request, $next);

        $isPage = $response->headers->has('X-Inertia')
            || str_contains((string) $response->headers->get('Content-Type'), 'text/html');
        if ($request->user() && $isPage) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

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
