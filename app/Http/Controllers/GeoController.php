<?php

namespace App\Http\Controllers;

use App\Services\Geo\ComunasGeoJson;
use App\Services\Geo\MunicipiosGeoJson;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GeoController extends Controller
{
    // GET /api/geo/municipios.geojson?simplified=1 (default) | 0 for the full geometry.
    public function municipios(Request $request): Response
    {
        return $this->cached($request, MunicipiosGeoJson::get($request->boolean('simplified', true)));
    }

    // GET /api/geo/comunas.geojson?municipio=68001[&simplified=0]: empty collection for a municipio without comunas.
    public function comunas(Request $request): Response
    {
        $data = $request->validate(['municipio' => ['required', 'string', 'regex:/^\d{5}$/']]);

        return $this->cached($request, ComunasGeoJson::get($data['municipio'], $request->boolean('simplified', true)));
    }

    private function cached(Request $request, array $data): Response
    {
        $headers = [
            'ETag' => $data['etag'],
            'Cache-Control' => 'private, max-age=3600, must-revalidate',
            'Vary' => 'Cookie',
        ];

        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match')));
        if (in_array($data['etag'], $sent, true) || in_array('W/'.$data['etag'], $sent, true)) {
            return response('', 304, $headers);
        }

        return response($data['body'], 200, $headers + ['Content-Type' => 'application/geo+json']);
    }
}
