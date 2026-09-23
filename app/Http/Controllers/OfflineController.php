<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineController extends Controller
{
    /**
     * Download all data for a municipio for offline use.
     * Returns: personas (alcalde+concejales), lideres, nexos, gobernador candidates.
     */
    public function download(string $municipioId)
    {
        $mun = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $municipioId)
            ->select('g.id', 'g.canonical_name as name', 'prov.canonical_name as provincia')
            ->first();

        abort_if(!$mun, 404, 'Municipio no encontrado');

        $votesSubQuery = DB::table('electoral_results')
            ->whereIn('metric_type', ['votes', 'nominal_votes'])
            ->where('geographic_unit_id', $municipioId)
            ->select('candidacy_id', DB::raw('SUM(value) as votos'))
            ->groupBy('candidacy_id');

        // Alcaldía candidates
        $alcaldiaOffice = DB::table('offices')->where('name', 'Alcaldía')->value('id');
        $alcaldes = [];
        if ($alcaldiaOffice) {
            $alcaldes = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.office_id', $alcaldiaOffice)
                ->where('con.geographic_unit_id', $municipioId)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoinSub($votesSubQuery, 'res', 'res.candidacy_id', '=', 'c.id')
                ->select(
                    'p.id', 'p.full_name as nombre',
                    DB::raw("'{$mun->name}' as municipio"),
                    DB::raw("'{$mun->provincia}' as provincia"),
                    'po.canonical_name as partido',
                    'c.outcome', 'ce.endorsement_type as tipo_aval',
                    DB::raw('COALESCE(res.votos, 0) as votos'),
                    DB::raw("'Alcaldía' as tipo_registro"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Alcalde Electo' ELSE 'Candidato Alcaldía' END as cargo")
                )
                ->orderByDesc('res.votos')
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        // Concejo candidates
        $concejoCorp = DB::table('corporations')->where('name', 'Concejo')->value('id');
        $concejales = [];
        if ($concejoCorp) {
            $concejales = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.corporation_id', $concejoCorp)
                ->where('con.geographic_unit_id', $municipioId)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoinSub($votesSubQuery, 'res', 'res.candidacy_id', '=', 'c.id')
                ->select(
                    'p.id', 'p.full_name as nombre',
                    DB::raw("'{$mun->name}' as municipio"),
                    DB::raw("'{$mun->provincia}' as provincia"),
                    'po.canonical_name as partido',
                    'c.outcome', 'ce.endorsement_type as tipo_aval',
                    DB::raw('COALESCE(res.votos, 0) as votos'),
                    DB::raw("'Concejo' as tipo_registro"),
                    DB::raw("CASE WHEN c.outcome = 'elected' THEN 'Concejal Electo' ELSE 'Candidato Concejo' END as cargo")
                )
                ->orderByDesc('res.votos')
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        // Líderes
        $lideres = DB::table('lideres')
            ->where('geographic_unit_id', $municipioId)
            ->select('id', 'nombre', 'municipio', 'provincia', 'cargo', 'partido',
                'telefono', 'email', 'observacion', 'barrio', 'direccion', 'zona')
            ->orderBy('nombre')
            ->get()->map(fn ($r) => (array) $r)->toArray();

        // Nexos for all persons + líderes of this municipio
        $personIds = array_merge(
            array_column($alcaldes, 'id'),
            array_column($concejales, 'id'),
            array_column($lideres, 'id')
        );
        $nexos = [];
        if (!empty($personIds)) {
            $nexos = DB::table('nexos_familiares')
                ->whereIn('person_id', $personIds)
                ->select('id', 'person_id as personId', 'nombre', 'parentesco', 'cargo', 'edad', 'gustos', 'observaciones')
                ->orderBy('nombre')
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        // Gobernador (same for all municipios)
        $gobOffice = DB::table('offices')->where('name', 'Gobernación')->value('id');
        $gobernador = [];
        if ($gobOffice) {
            $gobVotesSubQuery = DB::table('electoral_results')
                ->whereIn('metric_type', ['votes', 'nominal_votes'])
                ->select('candidacy_id', DB::raw('SUM(value) as votos'))
                ->groupBy('candidacy_id');

            $gobernador = DB::table('candidacies as c')
                ->join('contests as con', 'c.contest_id', '=', 'con.id')
                ->join('persons as p', 'c.person_id', '=', 'p.id')
                ->where('con.office_id', $gobOffice)
                ->leftJoin('candidacy_endorsements as ce', fn ($j) => $j->on('ce.candidacy_id', '=', 'c.id')->where('ce.is_primary', true))
                ->leftJoin('political_organizations as po', 'ce.organization_id', '=', 'po.id')
                ->leftJoinSub($gobVotesSubQuery, 'res', 'res.candidacy_id', '=', 'c.id')
                ->select('p.id', 'p.full_name as nombre', 'po.canonical_name as partido',
                    'c.outcome', DB::raw('COALESCE(res.votos, 0) as votos'))
                ->orderByDesc('res.votos')
                ->get()->map(fn ($r) => (array) $r)->toArray();
        }

        // Merge personas (alcaldes + concejales)
        $personas = array_merge($alcaldes, $concejales);

        return response()->json([
            'municipioId' => $municipioId,
            'municipioName' => $mun->name,
            'provincia' => $mun->provincia,
            'downloadedAt' => now()->toISOString(),
            'personas' => $personas,
            'lideres' => $lideres,
            'nexos' => $nexos,
            'gobernador' => $gobernador,
        ]);
    }
}
