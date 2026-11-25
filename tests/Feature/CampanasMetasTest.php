<?php

namespace Tests\Feature;

use App\Models\ElectoralCampaign;
use App\Services\CampanasElectorales\DetalleCampana;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BuildsCampanasScenario;
use Tests\TestCase;

// Goals of an electoral campaign on SQLite with synthetic results: the batch (all or nothing), the suggestion per
// corporation (checked against what /asamblea and /concejo/{id} show), the progress, the Excel template and import,
// the history and the number of queries of the detail.
class CampanasMetasTest extends TestCase
{
    use BuildsCampanasScenario;

    private string $cdParty;

    private string $otroParty;

    private string $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampanas();
        $this->flag(true);
        $this->seedResultados();
    }

    private function seedResultados(): void
    {
        $this->cdParty = $this->partido('Centro Democrático');
        $this->otroParty = $this->partido('Partido Verde');

        $old = $this->evento('Territoriales 2019', '2019-10-27');
        $t23 = $this->evento('Territoriales 2023', '2023-10-29');
        $l26 = $this->evento('Legislativas 2026', '2026-03-08', 'legislative');
        $consulta = $this->evento('Consulta 2026', '2026-03-08', 'consultation');

        // Asamblea 2023: list votes + nominal votes of the party's candidates.
        $asam = $this->contienda($t23, 'corporations', 'Asamblea', $this->santander);
        $this->votos($asam, null, $this->cdParty, $this->giron, 100);
        $this->votos($asam, null, $this->cdParty, $this->lebrija, 40);
        $a = $this->candidatura($asam, $this->persona('Asambleísta CD'), $this->cdParty);
        $this->votos($asam, $a, null, $this->giron, 300, 'nominal_votes');
        $this->votos($asam, $a, null, $this->lebrija, 60, 'nominal_votes');
        $q = $this->candidatura($asam, $this->persona('Asambleísta Verde'), $this->otroParty);
        $this->votos($asam, $q, null, $this->giron, 500, 'nominal_votes');
        $this->votos($asam, null, $this->otroParty, $this->giron, 70);

        // Gobernación 2023: department totals only.
        $gob = $this->contienda($t23, 'offices', 'Gobernación', $this->santander);
        $g = $this->candidatura($gob, $this->persona('Gobernador CD'), $this->cdParty);
        $this->votos($gob, $g, null, $this->santander, 5000);
        $h = $this->candidatura($gob, $this->persona('Gobernador Verde'), $this->otroParty);
        $this->votos($gob, $h, null, $this->santander, 9000);

        // Concejo de Bucaramanga 2023 (and an older 2019 one that must not be used).
        $con = $this->contienda($t23, 'corporations', 'Concejo', $this->bucaramanga);
        foreach ([[$this->cdParty, 120], [$this->cdParty, 80], [$this->otroParty, 400]] as $i => [$party, $v]) {
            $cand = $this->candidatura($con, $this->persona("Concejal {$i}"), $party);
            $this->votos($con, $cand, null, $this->bucaramanga, $v);
        }
        $con19 = $this->contienda($old, 'corporations', 'Concejo', $this->bucaramanga);
        $this->votos($con19, $this->candidatura($con19, $this->persona('Concejal 2019'), $this->cdParty), null, $this->bucaramanga, 999);

        // Alcaldía 2023: Ana ran in Girón; a consulta of 2026 with an Alcaldía contest must not count as comparable.
        $alcGiron = $this->contienda($t23, 'offices', 'Alcaldía', $this->giron);
        $this->ana = $this->persona('Ana Alcaldesa');
        $this->votos($alcGiron, $this->candidatura($alcGiron, $this->ana, $this->cdParty), null, $this->giron, 2000);
        $alcBga = $this->contienda($t23, 'offices', 'Alcaldía', $this->bucaramanga);
        $this->votos($alcBga, $this->candidatura($alcBga, $this->persona('Alcalde Verde'), $this->otroParty), null, $this->bucaramanga, 3000);
        $alcConsulta = $this->contienda($consulta, 'offices', 'Alcaldía', $this->giron);
        $this->votos($alcConsulta, $this->candidatura($alcConsulta, $this->ana, $this->cdParty), null, $this->giron, 77777);

        // Senado 2026: the party TOTAL already holds its candidates.
        $sen = $this->contienda($l26, 'corporations', 'Senado', null);
        $this->votos($sen, null, $this->cdParty, $this->giron, 700);
        $s = $this->candidatura($sen, $this->persona('Senador CD'), $this->cdParty);
        $this->votos($sen, $s, null, $this->giron, 200, 'nominal_votes');
        $this->votos($sen, $s, null, $this->lebrija, 50, 'nominal_votes');
    }

    private function detalle(ElectoralCampaign $c, $user = null): array
    {
        return $this->actingAs($user ?? $this->manager())->getJson("/campanas-electorales/{$c->id}")->assertOk()->json('data');
    }

    private function fila(array $data, string $id): array
    {
        return collect($data['filas'])->firstWhere('id', $id);
    }

    // ---------------------------------------------------------------- suggestion

    public function test_gobernacion_total_from_gobernacion_and_municipios_from_asamblea(): void
    {
        $c = $this->campana(['name' => 'Gob', 'political_organization_id' => $this->cdParty]);
        $d = $this->detalle($c);

        $this->assertSame('Gobernación 2023', $d['sugerencias']['comparable']);
        $giron = $this->fila($d, $this->giron);
        $this->assertSame(['votos' => 440, 'lideres' => 9, 'votantes' => 440, 'base' => 400,
            'texto' => 'Sugerido: 440 (400 votos de Centro Democrático a Asamblea 2023 (lista y candidatos) × 1,10) (no hay Gobernación por municipio)'], $giron['sugerencia']);
        $this->assertSame(110, $this->fila($d, $this->lebrija)['sugerencia']['votos']);
        $this->assertNull($this->fila($d, $this->velez)['sugerencia']);
        $this->assertSame('Sin sugerencia: Centro Democrático no tuvo votos en Asamblea 2023 en este municipio', $this->fila($d, $this->velez)['sin_sugerencia']);
        $this->assertSame('Sin sugerencia: los resultados solo existen por municipio', $this->fila($d, $this->comuna1)['sin_sugerencia']);
        $this->assertSame(5500, $d['totales']['sugerencia']['votos']);
        $this->assertSame('Sugerido: 5.500 (5.000 votos de Centro Democrático a Gobernación 2023 × 1,10)', $d['totales']['sugerencia']['texto']);
        $this->assertNull($giron['meta'], 'The goal stays empty until someone writes it');
        $this->assertSame(0, DB::table('electoral_campaign_goals')->count());
    }

    public function test_gobernacion_without_a_result_of_its_own_sums_the_municipios_and_says_so(): void
    {
        $liga = $this->partido('Liga');
        $asam = DB::table('contests')->where('corporation_id', DB::table('corporations')->where('name', 'Asamblea')->value('id'))->value('id');
        $this->votos($asam, null, $liga, $this->giron, 50);
        $this->votos($asam, null, $liga, $this->lebrija, 30);
        $c = $this->campana(['name' => 'Gob Liga', 'political_organization_id' => $liga]);

        $this->assertSame([
            'votos' => 88, 'lideres' => 2, 'votantes' => 88, 'base' => 80,
            'texto' => 'Sugerido: 88 (80 votos de Liga a Asamblea 2023 (lista y candidatos) × 1,10) (sin votos en Gobernación 2023: suma de Asamblea por municipio)',
        ], $this->detalle($c)['totales']['sugerencia']);
    }

    public function test_asamblea_equals_what_the_asamblea_screen_shows_for_the_party(): void
    {
        $c = $this->campana(['name' => 'Asamblea', 'cargo' => 'asamblea', 'political_organization_id' => $this->cdParty]);
        $base = $this->fila($this->detalle($c), $this->giron)['sugerencia']['base'];

        $pantalla = null;
        $this->actingAs($this->manager())->get("/asamblea/{$this->giron}")->assertOk()->assertInertia(function (AssertableInertia $p) use (&$pantalla) {
            $props = $p->toArray()['props'];
            $lista = collect($props['votosLista'])->where('id', $this->cdParty)->sum('total');
            $pantalla = $lista + collect($props['resultados'])->where('partido', 'Centro Democrático')->sum('votos');
        });
        $this->assertSame(400, $pantalla);
        $this->assertSame($pantalla, $base, 'List votes + candidates, never only the list or twice');
    }

    public function test_concejo_equals_what_the_concejo_screen_shows_and_uses_the_latest_election(): void
    {
        $c = $this->campana(['name' => 'Concejo', 'cargo' => 'concejo', 'scope_level' => 'municipality', 'political_organization_id' => $this->cdParty], [$this->bucaramanga]);
        $d = $this->detalle($c);
        $s = $this->fila($d, $this->bucaramanga)['sugerencia'];

        $pantalla = null;
        $this->actingAs($this->manager())->get("/concejo/{$this->bucaramanga}")->assertOk()->assertInertia(function (AssertableInertia $p) use (&$pantalla) {
            $pantalla = collect($p->toArray()['props']['concejales'])->where('partido', 'Centro Democrático')->sum('votos');
        });
        $this->assertSame(200, $pantalla);
        $this->assertSame($pantalla, $s['base']);
        $this->assertSame('Concejo 2023', $d['sugerencias']['comparable']);
        $this->assertSame(220, $s['votos']);
    }

    public function test_senado_takes_the_party_total_and_only_without_it_the_candidates(): void
    {
        $c = $this->campana(['name' => 'Senado', 'cargo' => 'senado', 'political_organization_id' => $this->cdParty]);
        $d = $this->detalle($c);

        $this->assertSame(700, $this->fila($d, $this->giron)['sugerencia']['base'], 'Not 700 + 200');
        $this->assertSame(50, $this->fila($d, $this->lebrija)['sugerencia']['base']);
        $this->assertSame('Senado 2026', $d['sugerencias']['comparable']);
        $this->assertSame(750, $d['totales']['sugerencia']['base']);
    }

    public function test_the_candidate_counts_where_their_contest_was_held_and_the_party_elsewhere(): void
    {
        $giron = $this->campana(['name' => 'Alc Girón', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'candidate_person_id' => $this->ana,
            'political_organization_id' => $this->cdParty, 'factor_votos' => 1.5, 'votos_por_lider' => 100, 'factor_registro' => 0.5], [$this->giron]);
        $s = $this->fila($this->detalle($giron), $this->giron)['sugerencia'];
        $this->assertSame(['votos' => 3000, 'lideres' => 30, 'votantes' => 1500, 'base' => 2000,
            'texto' => 'Sugerido: 3.000 (2.000 votos de Ana Alcaldesa a Alcaldía 2023 × 1,50)'], $s, 'The consulta of 2026 is not the comparable');

        $bga = $this->campana(['name' => 'Alc Bga', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'candidate_person_id' => $this->ana,
            'political_organization_id' => $this->cdParty], [$this->bucaramanga]);
        $f = $this->fila($this->detalle($bga), $this->bucaramanga);
        $this->assertNull($f['sugerencia']);
        $this->assertSame('Sin sugerencia: Centro Democrático no tuvo votos en Alcaldía 2023 en este municipio', $f['sin_sugerencia']);

        $sinFuente = $this->campana(['name' => 'Sin fuente', 'cargo' => 'asamblea']);
        $this->assertSame('Sin sugerencia: elige el partido o el candidato de la campaña', $this->fila($this->detalle($sinFuente), $this->giron)['sin_sugerencia']);
    }

    public function test_a_candidacy_with_two_primary_endorsements_of_the_party_counts_once(): void
    {
        $asam = DB::table('contests')->where('corporation_id', DB::table('corporations')->where('name', 'Asamblea')->value('id'))->value('id');
        $b = $this->candidatura($asam, $this->persona('Doble aval'), $this->cdParty);
        DB::table('candidacy_endorsements')->insert(['id' => (string) Str::uuid(), 'candidacy_id' => $b, 'organization_id' => $this->cdParty, 'is_primary' => true]);
        $this->votos($asam, $b, null, $this->giron, 10, 'nominal_votes');
        $c = $this->campana(['name' => 'Asamblea', 'cargo' => 'asamblea', 'political_organization_id' => $this->cdParty]);

        $this->assertSame(410, $this->fila($this->detalle($c), $this->giron)['sugerencia']['base']);
    }

    public function test_jal_has_no_suggestion(): void
    {
        $c = $this->campana(['name' => 'JAL', 'cargo' => 'jal', 'scope_level' => 'comuna', 'political_organization_id' => $this->cdParty], [$this->comuna1]);
        $d = $this->detalle($c);

        $this->assertCount(1, $d['filas']);
        $this->assertNull($d['filas'][0]['sugerencia']);
        $this->assertSame('Sin sugerencia: los resultados solo existen por municipio', $d['filas'][0]['sin_sugerencia']);
        $this->assertNull($d['totales']['sugerencia']);
    }

    public function test_a_group_without_electoral_results_gets_no_suggestion_key_at_all(): void
    {
        $this->grupoSinResultados();
        $c = $this->campana(['name' => 'Gob Y', 'political_organization_id' => $this->cdParty], null, $this->campY);
        $user = $this->manager($this->campY);
        $json = $this->actingAs($user)->getJson("/campanas-electorales/{$c->id}")->assertOk();
        $d = $json->json('data');

        $this->assertFalse($d['sugerencias']['disponible']);
        $this->assertSame('Tu grupo no tiene acceso a resultados electorales: escribe las metas a mano', $d['sugerencias']['aviso']);
        foreach ($d['filas'] as $f) {
            $this->assertArrayNotHasKey('sugerencia', $f);
            $this->assertArrayNotHasKey('sin_sugerencia', $f);
        }
        $this->assertArrayNotHasKey('sugerencia', $d['totales']);
        // No base votes anywhere: no row nor total carries a base (a bare '440' could appear inside a random uuid).
        $this->assertStringNotContainsString('"base"', $json->getContent());
        $this->assertStringNotContainsString('Asamblea 2023', $json->getContent());

        $rows = $this->leerXlsx($this->actingAs($user)->get("/campanas-electorales/{$c->id}/metas/plantilla")->assertOk()->streamedContent());
        $this->assertSame(['68307', 'Girón', '', '', '', '', '', '', ''], array_map(fn ($v) => (string) $v, array_pad($this->filaXlsx($rows, '68307'), 9, '')));
    }

    // ---------------------------------------------------------------- progress

    public function test_progress_counts_the_owner_groups_active_living_fichas_and_its_lideres(): void
    {
        $this->ficha($this->cd, $this->giron, ['es_lider' => true]);
        $this->ficha($this->cd, $this->giron);
        $this->ficha($this->cd, $this->giron);
        $this->ficha($this->cd, $this->giron, ['is_active' => false, 'es_lider' => true]);
        $this->ficha($this->cd, $this->giron, ['fallecido' => true]);
        $this->ficha($this->campX, $this->giron, ['es_lider' => true]);
        $this->ficha($this->campX, $this->giron);
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'es_lider' => true]);
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna1]);
        $this->ficha($this->cd, $this->bucaramanga);
        $c = $this->campana(['name' => 'Gob']);

        $d = $this->detalle($c);
        $this->assertSame(['votantes' => 3, 'lideres' => 1], $this->fila($d, $this->giron)['avance']);
        $this->assertSame(['votantes' => 3, 'lideres' => 1], $this->fila($d, $this->bucaramanga)['avance']);
        $this->assertSame(['votantes' => 2, 'lideres' => 1], $this->fila($d, $this->comuna1)['avance']);
        $this->assertSame(['votantes' => 0, 'lideres' => 0], $this->fila($d, $this->comuna2)['avance']);
        $this->assertSame(['votantes' => 1, 'lideres' => 0], $this->fila($d, $this->bucaramanga)['sin_comuna']);
        $this->assertNull($this->fila($d, $this->giron)['sin_comuna']);
        $this->assertSame(['votantes' => 6, 'lideres' => 2], $d['totales']['avance']);
        $this->assertSame(DB::table('lideres')->where('campaign_id', $this->cd)->where('is_active', true)->where('fallecido', false)
            ->where('es_lider', true)->count(), $d['totales']['avance']['lideres']);
    }

    public function test_progress_counts_by_comuna_origin_and_comunas_plus_sin_comuna_add_up_to_the_municipio(): void
    {
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'auto']);
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'puesto', 'es_lider' => true]);
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'puesto']);
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna2, 'comuna_source' => 'texto']);
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->corregimiento1, 'comuna_source' => 'manual']);
        // Two kinds of "no comuna": a manual "none" and never assigned; both are counted once each in sin_comuna.
        $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => null, 'comuna_source' => 'manual']);
        $this->ficha($this->cd, $this->bucaramanga, ['es_lider' => true]);
        $c = $this->campana(['name' => 'Gob']);

        $d = $this->detalle($c);
        $c1 = $this->fila($d, $this->comuna1);
        $this->assertSame(['votantes' => 3, 'lideres' => 1], $c1['avance']);
        // k = 5 like the Tablero: 1 to 4 by one origin go out as null ("menos de 5"), 0 stays 0.
        $this->assertSame(['auto' => null, 'texto' => 0, 'direccion' => 0, 'puesto' => null, 'manual' => 0], $c1['avance_por_origen']);
        $this->assertNull($this->fila($d, $this->comuna2)['avance_por_origen']['texto']);
        $this->assertNull($this->fila($d, $this->corregimiento1)['avance_por_origen']['manual']);

        $bga = $this->fila($d, $this->bucaramanga);
        $this->assertSame(['votantes' => 7, 'lideres' => 2], $bga['avance']);
        $this->assertSame(['votantes' => 2, 'lideres' => 1], $bga['sin_comuna']);
        $this->assertNull($bga['avance_por_origen']['puesto']);
        $comunas = array_filter($d['filas'], fn ($f) => $f['padre_id'] === $this->bucaramanga);
        $this->assertSame($bga['avance']['votantes'], array_sum(array_map(fn ($f) => $f['avance']['votantes'], $comunas)) + $bga['sin_comuna']['votantes']);

        foreach (range(1, 4) as $i) {
            $this->ficha($this->cd, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'puesto']);
        }
        $this->assertSame(6, $this->fila($this->detalle($c), $this->comuna1)['avance_por_origen']['puesto'], 'From 5 on, the exact count');
    }

    // ---------------------------------------------------------------- batch

    public function test_batch_saves_keeps_missing_keys_clears_nulls_and_audits_each_cell(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gob', 'political_organization_id' => $this->cdParty]);
        $url = "/campanas-electorales/{$c->id}/metas";

        $this->actingAs($user)->putJson($url, ['metas' => [
            ['geographic_unit_id' => $this->giron, 'votos_meta' => 1000, 'lideres_meta' => 20],
            ['geographic_unit_id' => $this->lebrija, 'votantes_meta' => '300'],
        ]])->assertOk()->assertJsonPath('message', 'Metas guardadas')
            ->assertJsonPath('data.totales.votos_meta', 1000)->assertJsonPath('data.campana.metas', ['cargadas' => 2, 'total' => 4]);

        $giron = $this->fila($this->detalle($c), $this->giron);
        $this->assertSame(['votos_meta' => 1000, 'lideres_meta' => 20, 'votantes_meta' => null], $giron['meta']);
        $this->assertSame(20, $giron['sugerencia']['lideres'], 'Líderes from the goal written, not from the suggestion');
        $this->assertSame(1000, $giron['sugerencia']['votantes']);

        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->giron, 'lideres_meta' => null]]])->assertOk();
        $this->assertSame(['votos_meta' => 1000, 'lideres_meta' => null, 'votantes_meta' => null], $this->fila($this->detalle($c), $this->giron)['meta']);

        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => null]]])->assertOk();
        $this->assertSame(0, DB::table('electoral_campaign_goals')->where('geographic_unit_id', $this->giron)->count());
        $this->assertSame(1, DB::table('electoral_campaign_goals')->count());

        $logs = DB::table('audit_logs')->where('action', 'electoral_campaign.goals.updated')->orderBy('created_at')->get();
        $this->assertCount(3, $logs);
        $this->assertSame([$this->giron => ['votos_meta' => null, 'lideres_meta' => null], $this->lebrija => ['votantes_meta' => null]], json_decode($logs[0]->before_state, true));
        $this->assertSame([$this->giron => ['votos_meta' => 1000, 'lideres_meta' => 20], $this->lebrija => ['votantes_meta' => 300]], json_decode($logs[0]->after_state, true));

        $hist = $this->actingAs($user)->getJson("/campanas-electorales/{$c->id}/historial")->assertOk()->json('data');
        $this->assertSame('Metas guardadas', $hist[0]['accion_label']);
        $this->assertSame([['territorio' => 'Girón', 'campo' => 'votos_meta', 'campo_label' => 'Votos meta', 'antes' => 1000, 'despues' => null]], $hist[0]['cambios']);

        // An unchanged batch writes no audit.
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->lebrija, 'votantes_meta' => 300]]])->assertOk();
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'electoral_campaign.goals.updated')->count());
    }

    public function test_the_history_shows_no_empty_entries(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gob']);
        $url = "/campanas-electorales/{$c->id}/metas";
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->lebrija, 'votos_meta' => 10]]])->assertOk();
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->lebrija, 'votos_meta' => '10']]])->assertOk();
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => null, 'lideres_meta' => null]]])->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'electoral_campaign.goals.updated')->count(), 'A save without real changes is not recorded');

        // A user of Girón does not get an entry whose changes were all in Lebrija.
        $giron = $this->viewer($this->cd, [$this->giron]);
        $this->actingAs($giron)->getJson("/campanas-electorales/{$c->id}/historial")->assertOk()->assertJsonPath('data', []);
        $this->actingAs($user)->getJson("/campanas-electorales/{$c->id}/historial")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_invalid_numbers_save_nothing(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gob']);
        $url = "/campanas-electorales/{$c->id}/metas";

        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->giron, 'lideres_meta' => 10_000_001]]])
            ->assertStatus(422)->assertJsonPath('errors', ['metas.0.lideres_meta' => ['Escribe un número entero entre 0 y 10.000.000.']]);
        foreach (['abc', -5, 1.5, '1.5', true, [1]] as $malo) {
            $this->actingAs($user)->putJson($url, ['metas' => [
                ['geographic_unit_id' => $this->lebrija, 'votos_meta' => 10],
                ['geographic_unit_id' => $this->giron, 'votos_meta' => $malo],
            ]])->assertStatus(422)->assertJsonPath('errors', ['metas.1.votos_meta' => ['Escribe un número entero, 0 o más']]);
        }
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 0, 'lideres_meta' => 10_000_000]]])->assertOk();
        $this->assertSame(1, DB::table('electoral_campaign_goals')->count());
        $this->assertSame(0, (int) DB::table('electoral_campaign_goals')->value('votos_meta'));
    }

    public function test_units_outside_the_campaign_or_too_many_rows_are_refused(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Alc', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->giron]);
        $url = "/campanas-electorales/{$c->id}/metas";

        foreach ([$this->lebrija, $this->bogota, $this->santander, 'no-es-uuid'] as $unit) {
            $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $unit, 'votos_meta' => 5]]])
                ->assertStatus(422)->assertJsonValidationErrors('metas.0.geographic_unit_id');
        }
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 5], ['geographic_unit_id' => $this->giron, 'votos_meta' => 6]]])
            ->assertStatus(422);
        $this->actingAs($user)->putJson($url, ['metas' => array_fill(0, 301, ['geographic_unit_id' => $this->giron, 'votos_meta' => 1])])
            ->assertStatus(422)->assertJsonPath('errors.metas.0', 'Guarda hasta 300 territorios a la vez.');
        $this->actingAs($user)->putJson($url, ['metas' => []])->assertStatus(422);
        $this->assertSame(0, DB::table('electoral_campaign_goals')->count());
    }

    public function test_a_municipio_carries_its_own_goal_or_goals_in_its_comunas_never_both(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Alc Bga', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->bucaramanga]);
        $url = "/campanas-electorales/{$c->id}/metas";

        $this->actingAs($user)->putJson($url, ['metas' => [
            ['geographic_unit_id' => $this->bucaramanga, 'votos_meta' => 100],
            ['geographic_unit_id' => $this->comuna1, 'votos_meta' => 10],
        ]])->assertStatus(422)->assertJsonValidationErrors('metas.0');
        $this->assertSame(0, DB::table('electoral_campaign_goals')->count());

        $this->actingAs($user)->putJson($url, ['metas' => [
            ['geographic_unit_id' => $this->comuna1, 'votos_meta' => 10, 'lideres_meta' => 1],
            ['geographic_unit_id' => $this->corregimiento1, 'votos_meta' => 5],
        ]])->assertOk();
        $d = $this->detalle($c);
        $bga = $this->fila($d, $this->bucaramanga);
        $this->assertFalse($bga['editable']);
        $this->assertSame(['votos_meta' => 15, 'lideres_meta' => 1, 'votantes_meta' => null], $bga['suma_comunas']);
        $this->assertSame(3, $bga['comunas']);
        $this->assertSame(15, $d['totales']['votos_meta']);
        $this->assertSame(['cargadas' => 1, 'total' => 1], $d['campana']['metas']);

        // Now the municipio's own goal collides with the comunas already saved.
        $this->actingAs($user)->putJson($url, ['metas' => [['geographic_unit_id' => $this->bucaramanga, 'votos_meta' => 100]]])
            ->assertStatus(422)->assertJsonPath('errors', ['metas.0' => ['Bucaramanga tiene metas por comuna: deja vacía la meta del municipio o borra las de sus comunas.']]);
        $this->assertSame(0, DB::table('electoral_campaign_goals')->where('geographic_unit_id', $this->bucaramanga)->count());
    }

    public function test_the_detail_of_a_departamento_campaign_uses_few_queries_whatever_its_size(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gob', 'political_organization_id' => $this->cdParty, 'candidate_person_id' => $this->ana]);
        $this->ficha($this->cd, $this->giron, ['es_lider' => true]);
        $this->actingAs($user);
        $contar = function () use ($c) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = microtime(true);
            $this->getJson("/campanas-electorales/{$c->id}")->assertOk();
            $total = [count(DB::getQueryLog()), (microtime(true) - $start) * 1000];
            DB::flushQueryLog();
            $modulo = app(DetalleCampana::class);
            $modulo->detalle($modulo->access()->find($c->id));
            $propias = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$total[0], $propias, $total[1]];
        };

        [$total, $propias, $ms] = $contar();
        // Timing is measured on PostgreSQL (CampanasElectoralesPostgresTest); a cold SQLite run on a busy machine is not a measure.
        $this->assertLessThan(15, $propias, "Queries of the module: {$propias}");

        // Twenty more municipios with fichas and results: the same number of queries (no N+1).
        $asam = DB::table('contests')->where('corporation_id', DB::table('corporations')->where('name', 'Asamblea')->value('id'))->value('id');
        for ($i = 0; $i < 20; $i++) {
            $m = $this->unidad('municipality', "Municipio {$i}", $this->soto, '68'.str_pad((string) (500 + $i), 3, '0', STR_PAD_LEFT));
            $this->ficha($this->cd, $m, ['es_lider' => $i % 2 === 0]);
            $this->votos($asam, null, $this->cdParty, $m, 10 + $i);
        }
        [$total2, $propias2] = $contar();
        $this->assertSame($propias, $propias2);
        $this->assertLessThanOrEqual($total, $total2);
    }

    // ---------------------------------------------------------------- Excel

    public function test_the_template_lists_every_territory_with_the_suggestion_and_empty_goals(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gobernación 2027', 'political_organization_id' => $this->cdParty]);
        $res = $this->actingAs($user)->get("/campanas-electorales/{$c->id}/metas/plantilla")->assertOk();
        $this->assertStringContainsString('metas-gobernacion-2027.xlsx', $res->headers->get('Content-Disposition'));
        $rows = $this->leerXlsx($res->streamedContent());

        $this->assertSame(['CÓDIGO', 'TERRITORIO', 'MUNICIPIO', 'VOTOS SUGERIDOS', 'LÍDERES SUGERIDOS', 'VOTANTES SUGERIDOS', 'VOTOS META', 'LÍDERES META', 'VOTANTES META'], $rows[0]);
        $this->assertCount(1 + 7, $rows);
        $this->assertSame(['68307', 'Girón', '', 440, 9, 440], array_slice($this->filaXlsx($rows, '68307'), 0, 6));
        $this->assertSame(['68001-C01', 'Comuna 1 - Norte', 'Bucaramanga'], array_slice($this->filaXlsx($rows, '68001-C01'), 0, 3));
        $this->assertSame(['', '', ''], array_map(fn ($v) => (string) $v, array_slice(array_pad($this->filaXlsx($rows, '68307'), 9, ''), 6, 3)));
    }

    public function test_import_saves_the_file_and_empty_cells_change_nothing(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gob']);
        $this->actingAs($user)->putJson("/campanas-electorales/{$c->id}/metas", ['metas' => [['geographic_unit_id' => $this->lebrija, 'votos_meta' => 77]]])->assertOk();

        $file = $this->xlsx([
            ['CÓDIGO', 'TERRITORIO', 'MUNICIPIO', 'VOTOS SUGERIDOS', 'LÍDERES SUGERIDOS', 'VOTANTES SUGERIDOS', 'VOTOS META', 'LÍDERES META', 'VOTANTES META'],
            ['68307', 'Girón', '', 451, 10, 451, '38.821', 12.0, null],
            [68406, 'Lebrija', '', null, null, null, null, '', 5],
            ['68001-c01', 'Comuna 1', 'Bucaramanga', null, null, null, 0, null, null],
            [null, null, null, null, null, null, null, null, null],
            ['68861', 'Vélez', '', null, null, null, null, null, null],
        ]);
        $this->actingAs($user)->post("/campanas-electorales/{$c->id}/metas/importar", ['archivo' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Se cargaron 3 metas')->assertJsonPath('data.cargadas', 3);

        $metas = DB::table('electoral_campaign_goals')->get()->keyBy('geographic_unit_id');
        $this->assertSame([38821, 12, null], [(int) $metas[$this->giron]->votos_meta, (int) $metas[$this->giron]->lideres_meta, $metas[$this->giron]->votantes_meta]);
        $this->assertSame([77, 5], [(int) $metas[$this->lebrija]->votos_meta, (int) $metas[$this->lebrija]->votantes_meta]);
        $this->assertSame(0, (int) $metas[$this->comuna1]->votos_meta);
        $this->assertFalse($metas->has($this->velez));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'electoral_campaign.goals.imported')->count());
    }

    public function test_one_bad_row_and_nothing_is_saved(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gob']);
        $url = "/campanas-electorales/{$c->id}/metas/importar";
        $header = ['CÓDIGO', 'TERRITORIO', 'MUNICIPIO', 'VOTOS SUGERIDOS', 'LÍDERES SUGERIDOS', 'VOTANTES SUGERIDOS', 'VOTOS META', 'LÍDERES META', 'VOTANTES META'];

        $res = $this->actingAs($user)->post($url, ['archivo' => $this->xlsx([
            $header,
            ['68307', 'Girón', '', null, null, null, 100, null, null],
            ['68406', 'Lebrija', '', null, null, null, null, null, null],
            ['68861', 'Vélez', '', null, null, null, 'abc', -5, 1.5],
            ['99999', 'Otro', '', null, null, null, 1, null, null],
            ['68307', 'Girón otra vez', '', null, null, null, 1, null, null],
        ])], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'No se cargó nada. Corrige estas filas:');
        $this->assertSame([
            'Fila 4: "abc" no es un número.',
            'Fila 4: "-5" es menor que 0.',
            'Fila 4: "1,5" no es un número entero.',
            'Fila 5: el código 99999 no es de esta campaña.',
            'Fila 6: el código 68307 está repetido (fila 2).',
        ], $res->json('errors.archivo'));

        $this->actingAs($user)->post($url, ['archivo' => $this->xlsx([
            $header,
            ['68001', 'Bucaramanga', '', null, null, null, 100, null, null],
            ['68001-C01', 'Comuna 1', 'Bucaramanga', null, null, null, 10, null, null],
        ])], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'Fila 2: Bucaramanga tiene metas por comuna: deja vacía la meta del municipio o borra las de sus comunas.');

        $this->actingAs($user)->post($url, ['archivo' => $this->xlsx([['NOMBRE', 'VOTOS'], ['Girón', 5]])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', 'La primera fila debe tener las columnas CÓDIGO y VOTOS META, LÍDERES META o VOTANTES META: usa la plantilla de la campaña.');
        $this->actingAs($user)->post($url, ['archivo' => $this->xlsx([$header])], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($user)->post($url, ['archivo' => UploadedFile::fake()->createWithContent('metas.csv', "CODIGO,VOTOS META\n68307,5\n")], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', 'Sube el archivo .xlsx de la plantilla.');
        $this->actingAs($user)->post($url, ['archivo' => UploadedFile::fake()->createWithContent('metas.xlsx', 'esto no es un excel')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', 'No pudimos leer el archivo: sube el .xlsx de la plantilla.');
        $this->actingAs($user)->post($url, ['archivo' => UploadedFile::fake()->create('metas.xlsx', 2049)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', 'El archivo pesa más de 2 MB.');
        // PHP cut it before Laravel (upload_max_filesize): the same message, never "No se pudo subir el archivo archivo".
        $cortado = new UploadedFile(tempnam(sys_get_temp_dir(), 'metas'), 'metas.xlsx', null, UPLOAD_ERR_INI_SIZE, true);
        $this->actingAs($user)->post($url, ['archivo' => $cortado], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', 'El archivo pesa más de 2 MB.');
        $this->actingAs($user)->post($url, [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', 'Elige el archivo de Excel.');

        $this->assertSame(0, DB::table('electoral_campaign_goals')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'electoral_campaign.goals%')->count());
    }

    // ---------------------------------------------------------------- helpers

    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'metas').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'metas.xlsx', null, null, true);
    }

    private function leerXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    private function filaXlsx(array $rows, string $codigo): array
    {
        foreach ($rows as $row) {
            if ((string) ($row[0] ?? '') === $codigo) {
                return $row;
            }
        }
        $this->fail("No row {$codigo}");
    }
}
