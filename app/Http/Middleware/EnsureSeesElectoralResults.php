<?php

namespace App\Http\Middleware;

use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Pages and endpoints made only of shared electoral results (Gobernador, Senado, Cámara, Asamblea, Concejo, Municipio,
// Persona, Partido, Análisis, reports): 403 for a grupo político without ve_resultados_electorales.
class EnsureSeesElectoralResults
{
    public function __construct(private readonly CurrentCampaign $campaign) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->campaign->abortUnlessSeesElectoralResults();

        return $next($request);
    }
}
