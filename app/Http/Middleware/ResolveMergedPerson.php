<?php

namespace App\Http\Middleware;

use App\Models\Person;
use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// A route {id} that is a merged person (old link, offline queue) is swapped for its survivor before the controller
// authorizes or writes anything. Lider ids and other ids pass through unchanged. An electoral person (not a ficha) is a
// 403 for a grupo político that does not see the electoral results, on every route that resolves one.
class ResolveMergedPerson
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $id = $route?->parameter('id');
        if (is_string($id) && Str::isUuid($id)) {
            $route->setParameter('id', Person::resolveId($id));
            if (! $this->campaign->seesElectoralResults()
                && ! DB::table('lideres')->where('id', $id)->exists()
                && DB::table('persons')->where('id', $id)->exists()) {
                CurrentCampaign::denyElectoralResults();
            }
        }

        return $next($request);
    }
}
