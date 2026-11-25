<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ElectoralCampaign;
use App\Models\ElectoralCampaignGoal;
use App\Services\Geo\ComunasGeoJson;
use App\Services\Geo\MunicipiosGeoJson;
use App\Services\Reportes\DescargaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsCampanasScenario;
use Tests\TestCase;

// Tablero de la campaña (Fase 5) on SQLite with synthetic fichas. Scenario: departamental campaign, factor_registro
// 1,0 and 50 votos por líder; A = Girón (votantes 100, líderes 10; 50 fichas, 5 líderes), B = Lebrija (only votos 200;
// 220 fichas), C = Vélez (no goal; 7 fichas), Bucaramanga with 100 fichas, 50 of them with comuna, and a goal in Comuna 1.
class CampanasTableroTest extends TestCase
{
    use BuildsCampanasScenario;

    private ElectoralCampaign $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampanas();
        $this->flag(true);
        Carbon::setTestNow(Carbon::parse('2026-11-25 15:00:00', 'UTC'));

        $this->c = $this->campana(['name' => 'Gobernación 2027', 'cargo' => 'gobernacion', 'factor_registro' => 1.0, 'votos_por_lider' => 50,
            'election_date' => '2027-10-31']);
        $this->meta($this->giron, ['votantes_meta' => 100, 'lideres_meta' => 10]);
        $this->meta($this->lebrija, ['votos_meta' => 200]);
        $this->meta($this->comuna1, ['votantes_meta' => 60]);

        $this->fichas(45, $this->giron);
        $this->fichas(5, $this->giron, ['es_lider' => true]);
        $this->fichas(220, $this->lebrija);
        $this->fichas(7, $this->velez);
        $this->fichas(30, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'auto']);
        $this->fichas(20, $this->bucaramanga, ['comuna_id' => $this->comuna2, 'comuna_source' => 'auto']);
        $this->fichas(50, $this->bucaramanga);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function meta(string $unit, array $values, ?ElectoralCampaign $c = null): void
    {
        ElectoralCampaignGoal::create(['electoral_campaign_id' => ($c ?? $this->c)->id, 'geographic_unit_id' => $unit] + $values);
    }

    private function fichas(int $n, string $geo, array $extra = [], string $grupo = Campaign::DEFAULT_ID): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = array_merge([
                'id' => (string) Str::uuid(), 'campaign_id' => $grupo, 'geographic_unit_id' => $geo, 'nombre' => 'FICHA SECRETA '.Str::random(5),
                'cedula' => '9'.random_int(100000000, 999999999), 'telefono' => '3109998877', 'municipio' => '', 'tipo' => 'votante',
                'is_active' => true, 'fallecido' => false, 'es_lider' => false,
                'created_at' => '2026-10-05 15:00:00', 'updated_at' => '2026-10-05 15:00:00',
            ], $extra);
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('lideres')->insert($chunk);
        }
    }

    private function tablero($user = null, string $query = ''): array
    {
        return $this->actingAs($user ?? $this->manager())->getJson("/campanas-electorales/{$this->c->id}/tablero{$query}")->assertOk()->json('data');
    }

    private function t(array $data, string $id): array
    {
        return collect($data['territorios'])->firstWhere('id', $id);
    }

    // ---------------------------------------------------------------- formulas

    public function test_a_both_goals_give_half_and_half_and_index_50(): void
    {
        $a = $this->t($this->tablero(), $this->giron);

        $this->assertSame(['registrados' => 50, 'meta' => 100, 'meta_calculada' => false, 'pct' => 0.5, 'falta' => 50], $a['votantes']);
        $this->assertSame(['registrados' => 5, 'meta' => 10, 'meta_calculada' => false, 'pct' => 0.5, 'falta' => 5], $a['lideres']);
        $this->assertSame(50, $a['indice']);
        $this->assertSame(['indice' => 2, 'votantes' => 2, 'lideres' => 2], $a['tramo']);
        $this->assertSame('ok', $a['estado']);
    }

    public function test_b_goals_computed_from_the_votes_goal_and_passing_the_goal_adds_nothing(): void
    {
        $b = $this->t($this->tablero(), $this->lebrija);

        $this->assertSame(['registrados' => 220, 'meta' => 200, 'meta_calculada' => true, 'pct' => 1.1, 'falta' => 0], $b['votantes']);
        $this->assertSame(['registrados' => 0, 'meta' => 4, 'meta_calculada' => true, 'pct' => 0, 'falta' => 4], $b['lideres']);
        $this->assertSame(60, $b['indice']);
        $this->assertSame(['indice' => 2, 'votantes' => 4, 'lideres' => 0], $b['tramo']);
    }

    public function test_c_without_goal_is_sin_meta_and_has_no_index(): void
    {
        $c = $this->t($this->tablero(), $this->velez);

        $this->assertSame('sin_meta', $c['estado']);
        $this->assertNull($c['indice']);
        $this->assertSame(['registrados' => 7, 'meta' => null, 'meta_calculada' => false, 'pct' => null, 'falta' => null], $c['votantes']);
        $this->assertSame(['indice' => null, 'votantes' => null, 'lideres' => null], $c['tramo']);
    }

    public function test_comunas_are_insuficiente_under_60_percent_and_ok_at_70(): void
    {
        $d = $this->tablero();
        $c1 = $this->t($d, $this->comuna1);
        $bga = $this->t($d, $this->bucaramanga);

        $this->assertSame('insuficiente', $c1['estado']);
        $this->assertSame('Solo 50 de 100 fichas de Bucaramanga (50 %) tienen comuna', $c1['motivo']);
        $this->assertSame(['con' => 50, 'total' => 100], $bga['cobertura_comuna']);
        $this->assertSame('ok', $bga['estado'], 'A municipio is never insuficiente');
        $this->assertSame(60, $bga['votantes']['meta'], 'The municipio adds up the goals of its comunas');
        $this->assertSame(30, $bga['votantes']['registrados'], 'and only the fichas of the comunas with a goal count against it');
        $this->assertSame(['votantes' => 50, 'lideres' => null], $bga['sin_comuna']);
        $this->assertSame(['votantes' => 20, 'lideres' => null], $bga['otras_comunas']);
        $this->assertSame('sin_meta', $this->t($d, $this->comuna2)['estado']);
        $this->assertSame(1, $d['totales']['territorios']['insuficientes']);
        $this->assertContains(['tipo' => 'cobertura_comuna', 'texto' => 'Solo 50 de 100 fichas de Bucaramanga (50 %) tienen comuna: las comunas se ven rayadas hasta que haya más datos.'], $d['avisos']);

        $sin = DB::table('lideres')->where('geographic_unit_id', $this->bucaramanga)->whereNull('comuna_id')->limit(20)->pluck('id');
        DB::table('lideres')->whereIn('id', $sin)->update(['comuna_id' => $this->comuna1, 'comuna_source' => 'puesto']);
        $d = $this->tablero(null, '?actualizar=1');
        $this->assertSame('ok', $this->t($d, $this->comuna1)['estado']);
        $this->assertSame(['con' => 70, 'total' => 100], $this->t($d, $this->bucaramanga)['cobertura_comuna']);
        $this->assertSame(['auto' => 30, 'texto' => 0, 'direccion' => 0, 'puesto' => 20, 'manual' => 0], $this->t($d, $this->comuna1)['por_origen']);
        $this->assertSame([], array_values(array_filter($d['avisos'], fn ($a) => $a['tipo'] === 'cobertura_comuna')));
    }

    public function test_the_total_index_comes_from_the_totals_not_an_average(): void
    {
        $t = $this->tablero()['totales'];

        // Goals: Girón 100 + Lebrija 200 + Comuna 1 of Bucaramanga 60 = 360, with 50 + 220 + 30 fichas = 300.
        // Falta adds what each one lacks (50 + 0 + 30 = 80): Lebrija's 20 over its goal do not cover Girón (not 360 - 300 = 60).
        $this->assertSame(['registrados' => 377, 'registrados_con_meta' => 300, 'meta' => 360, 'meta_calculada' => true, 'pct' => 0.8333, 'falta' => 80], $t['votantes']);
        $this->assertSame(['registrados' => 5, 'registrados_con_meta' => 5, 'meta' => 14, 'meta_calculada' => true, 'pct' => 0.3571, 'falta' => 9], $t['lideres']);
        $this->assertSame(64, $t['indice'], 'round(100 × (0,6 × 300/360 + 0,4 × 5/14)); the average of 50, 60 and 50 would be 53');
        $this->assertSame(['total' => 4, 'con_meta' => 3, 'sin_meta' => 1, 'insuficientes' => 1], $t['territorios']);
    }

    public function test_contract_texts_ranking_and_map(): void
    {
        $d = $this->tablero();

        $this->assertSame('El índice va de 0 a 100: el 60 % sale del avance en votantes registrados y el 40 % del avance en líderes. Pasar la meta no suma más.', $d['formula']['texto']);
        $this->assertSame(5, $d['umbrales']['k']);
        $this->assertSame(0.6, $d['umbrales']['cobertura_comuna_min']);
        $this->assertTrue($d['ranking']['una_lista']);
        $this->assertSame([$this->lebrija, $this->giron, $this->bucaramanga], $d['ranking']['mejores'], 'Lebrija 60, then Girón and Bucaramanga 50: more falta first');
        $this->assertSame([], $d['ranking']['por_apoyar']);
        $this->assertSame('municipios', $d['mapa']['capa']);
        $this->assertSame([['id' => $this->bucaramanga, 'codigo' => '68001', 'nombre' => 'Bucaramanga']], $d['mapa']['con_comunas']);
        $this->assertSame(['tipo' => 'sin_metas', 'texto' => '1 de 4 territorios no tiene meta: aparece en gris.'],
            collect($d['avisos'])->firstWhere('tipo', 'sin_metas') ? ['tipo' => 'sin_metas', 'texto' => collect($d['avisos'])->firstWhere('tipo', 'sin_metas')['texto']] : null);
        $this->assertNull(collect($d['avisos'])->firstWhere('tipo', 'sin_lideres'));
        $this->assertSame($this->c->id, $d['campana']['id']);
        $this->assertNull($d['soloLectura']);
        $this->assertTrue($d['can']['edit']);
        $this->assertSame([], array_values(array_diff(['campana', 'soloLectura', 'can', 'generado_at', 'formula', 'umbrales', 'totales', 'territorios',
            'ranking', 'evolucion', 'avisos', 'mapa'], array_keys($d))));
    }

    public function test_one_text_for_a_single_territory_without_comunas_and_a_comunas_layer_for_bucaramanga(): void
    {
        $alc = $this->campana(['name' => 'Alcaldía Girón', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->giron]);
        $d = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$alc->id}/tablero")->assertOk()->json('data.mapa');
        $this->assertSame(['capa' => null, 'con_comunas' => [], 'sin_poligono' => [], 'texto' => 'Esta campaña es de un solo territorio: mira el avance arriba.'], $d);

        $bga = $this->campana(['name' => 'Alcaldía Bga', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->bucaramanga]);
        $this->assertSame('comunas', $this->actingAs($this->manager())->getJson("/campanas-electorales/{$bga->id}/tablero")->json('data.mapa.capa'));

        $jal = $this->campana(['name' => 'JAL 1', 'cargo' => 'jal', 'scope_level' => 'comuna'], [$this->comuna1]);
        $this->meta($this->comuna1, ['votantes_meta' => 40], $jal);
        $j = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$jal->id}/tablero")->assertOk()->json('data');
        $this->assertNull($j['mapa']['capa']);
        $this->assertCount(1, $j['territorios']);
        $this->assertSame(30, $j['territorios'][0]['votantes']['registrados']);
        $this->assertSame('insuficiente', $j['territorios'][0]['estado'], 'The JAL of a comuna sees the coverage of its municipio');
        $this->assertSame(30, array_sum(array_filter(array_column($j['evolucion']['semanas'], 'nuevos'))));
    }

    public function test_ranking_with_more_than_10_territories_has_top_5_and_bottom_5_and_skips_tiny_goals(): void
    {
        $munis = [];
        for ($i = 1; $i <= 12; $i++) {
            $munis[$i] = $this->geo('municipality', sprintf('Muni %02d', $i), $this->soto);
            $this->meta($munis[$i], ['votantes_meta' => 100]);
            $this->fichas($i * 5, $munis[$i]);
        }
        $tiny = $this->geo('municipality', 'Muni chica', $this->soto);
        $this->meta($tiny, ['votantes_meta' => 10]);
        $this->fichas(10, $tiny);
        $d = $this->tablero();

        $this->assertFalse($d['ranking']['una_lista']);
        $this->assertCount(5, $d['ranking']['mejores']);
        $this->assertCount(5, $d['ranking']['por_apoyar']);
        $this->assertSame($munis[1], $d['ranking']['por_apoyar'][0], '5 of 100 is the lowest');
        $this->assertNotContains($tiny, [...$d['ranking']['mejores'], ...$d['ranking']['por_apoyar']], 'Goal under 20: out of the ranking');
        $this->assertSame('Las metas muy pequeñas no entran al ranking', $d['ranking']['nota']);
        $this->assertSame([$munis[12], $this->lebrija, $munis[11], $this->giron, $munis[10]], $d['ranking']['mejores'],
            'Ties: more falta first (Muni 12 lacks 40, Lebrija 0), then the name (Girón and Muni 10 both lack 50)');
    }

    // ---------------------------------------------------------------- evolution

    public function test_weeks_are_monday_to_sunday_in_bogota_with_antes_masiva_and_k(): void
    {
        $alc = $this->campana(['name' => 'Alc Vélez', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'election_date' => '2027-10-31'], [$this->velez]);
        $this->meta($this->velez, ['votantes_meta' => 1000], $alc);
        DB::table('lideres')->where('geographic_unit_id', $this->velez)->delete();
        $this->fichas(6, $this->velez, ['created_at' => '2026-11-16 03:00:00']);   // Sunday 15 in Bogotá
        $this->fichas(7, $this->velez, ['created_at' => '2026-11-17 03:00:00']);   // Monday 16 in Bogotá
        $this->fichas(8, $this->velez, ['created_at' => '2025-09-18 12:00:00']);   // before the start
        $this->fichas(301, $this->velez, ['created_at' => '2026-10-27 15:00:00']); // one massive day
        $this->fichas(3, $this->velez, ['created_at' => '2026-10-13 15:00:00']);   // under k
        $e = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$alc->id}/tablero")->assertOk()->json('data.evolucion');
        $semanas = array_column($e['semanas'], null, 'inicio');

        $this->assertSame('2025-09-22', $e['desde']);
        $this->assertSame('2025-09-22', $e['semanas'][0]['inicio']);
        $this->assertSame('2026-11-23', end($e['semanas'])['inicio'], 'Up to the current week');
        $this->assertSame(26, $e['semanas_defecto']);
        $this->assertSame(6, $semanas['2026-11-09']['nuevos']);
        $this->assertSame(7, $semanas['2026-11-16']['nuevos']);
        $this->assertSame(8, $e['antes']);
        $this->assertSame('8 ya estaban registrados antes del 22 sep 2025 (carga inicial del sistema anterior)', $e['antes_texto']);
        $this->assertSame(['inicio' => '2026-10-26', 'nuevos' => 301, 'masiva' => true], $semanas['2026-10-26']);
        $this->assertFalse($semanas['2026-11-09']['masiva']);
        $this->assertNull($semanas['2026-10-12']['nuevos'], '3 fichas: menos de 5');
        $this->assertSame(0, $semanas['2026-10-05']['nuevos']);
        $this->assertSame('Incluye un día con más de 300 registros (posible carga de archivo)', $e['masiva_texto']);
        // Last 4 complete weeks (26 oct – 22 nov): 301 + 0 + 6 + 7 = 314 / 4.
        $this->assertSame(79, $e['promedio_4']);
        // 2026-11-25 → 2027-10-31: 340 days = 49 weeks; falta 1000 - 325 = 675 → 14 per week.
        $this->assertSame(['semanas' => 49, 'por_semana' => 14,
            'texto' => 'Faltan 49 semanas para el 31 oct 2027: hay que registrar 14 por semana. Las últimas 4 semanas: 79 por semana.'], $e['ritmo_necesario']);
    }

    public function test_no_rhythm_without_election_date_or_votantes_goal(): void
    {
        $sin = $this->campana(['name' => 'Sin fecha', 'cargo' => 'asamblea', 'election_date' => null]);
        $this->meta($this->giron, ['votantes_meta' => 100], $sin);
        $this->assertNull($this->actingAs($this->manager())->getJson("/campanas-electorales/{$sin->id}/tablero")->json('data.evolucion.ritmo_necesario'));

        $sinMeta = $this->campana(['name' => 'Sin meta', 'cargo' => 'asamblea', 'election_date' => '2027-10-31']);
        $d = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$sinMeta->id}/tablero")->json('data');
        $this->assertNull($d['evolucion']['ritmo_necesario']);
        $this->assertSame(0, $d['totales']['territorios']['con_meta']);
        $this->assertNull(collect($d['avisos'])->firstWhere('tipo', 'sin_metas'), 'Without any goal the screen shows its empty state, not the notice');
    }

    // ---------------------------------------------------------------- territory detail

    public function test_territory_detail_with_weeks_k_and_comunas(): void
    {
        $this->fichas(3, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'puesto']);
        $d = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->comuna1}")->assertOk()->json('data');

        $this->assertSame($this->comuna1, $d['territorio']['id']);
        $this->assertNull($d['territorio']['por_origen']['puesto'], '3 by polling station: menos de 5');
        $this->assertSame(30, $d['territorio']['por_origen']['auto']);
        $this->assertNull($d['por_puesto_texto']);
        $this->assertTrue($d['pocos_registros']);
        $this->assertSame(33, collect($d['semanas'])->firstWhere('inicio', '2026-10-05')['nuevos']);
        $this->assertSame([], $d['comunas']);

        $bga = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->bucaramanga}")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->comuna1, $this->comuna2, $this->corregimiento1], $bga['comunas']);
        $this->assertFalse($bga['pocos_registros']);
        $this->assertSame(103, collect($bga['semanas'])->firstWhere('inicio', '2026-10-05')['nuevos']);
    }

    public function test_territory_detail_says_how_many_came_by_polling_station(): void
    {
        $this->fichas(12, $this->bucaramanga, ['comuna_id' => $this->comuna1, 'comuna_source' => 'puesto']);

        $this->actingAs($this->manager())->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->comuna1}")
            ->assertOk()->assertJsonPath('data.por_puesto_texto', 'Incluye 12 por puesto de votación');
    }

    public function test_territory_detail_404_for_invalid_foreign_or_outside_units(): void
    {
        $u = $this->manager();
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/no-es-uuid")->assertNotFound();
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->bogota}")->assertNotFound();
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/".Str::uuid())->assertNotFound();
        $alc = $this->campana(['name' => 'Alc Girón', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->giron]);
        $this->actingAs($u)->getJson("/campanas-electorales/{$alc->id}/tablero/territorio/{$this->lebrija}")->assertNotFound();
    }

    // ---------------------------------------------------------------- what counts

    public function test_subgroup_inactive_and_dead_fichas_do_not_count(): void
    {
        $this->fichas(9, $this->giron, [], $this->campX);
        $this->fichas(4, $this->giron, ['is_active' => false]);
        $this->fichas(3, $this->giron, ['fallecido' => true, 'es_lider' => true]);

        $a = $this->t($this->tablero(), $this->giron);
        $this->assertSame(50, $a['votantes']['registrados']);
        $this->assertSame(5, $a['lideres']['registrados']);
    }

    public function test_no_suggestion_and_no_personal_data_in_the_json(): void
    {
        $this->grupoSinResultados();
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['ve_resultados_electorales' => false]);
        $raw = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$this->c->id}/tablero")->assertOk()->getContent();
        $territorio = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->giron}")->getContent();

        foreach ([$raw, $territorio] as $json) {
            $this->assertStringNotContainsString('sugerencia', $json);
            $this->assertStringNotContainsString('FICHA SECRETA', $json);
            $this->assertStringNotContainsString('3109998877', $json);
            foreach (DB::table('lideres')->limit(50)->pluck('cedula') as $cedula) {
                $this->assertStringNotContainsString((string) $cedula, $json);
            }
        }
    }

    public function test_no_leaders_notice_when_nobody_is_marked(): void
    {
        DB::table('lideres')->update(['es_lider' => false]);
        $d = $this->tablero();

        $this->assertSame(['tipo' => 'sin_lideres', 'texto' => 'Aún no hay fichas marcadas como líder. Márcalas con la casilla "Es líder" en la ficha. Mientras tanto, el índice llega como máximo a 60.'],
            collect($d['avisos'])->firstWhere('tipo', 'sin_lideres'));
        $this->assertSame(30, $this->t($d, $this->giron)['indice'], '0,6 × 0,5 + 0,4 × 0');
    }

    // ---------------------------------------------------------------- cache

    public function test_saving_goals_changes_the_board_at_once_and_actualizar_sees_a_new_ficha(): void
    {
        $u = $this->manager();
        $this->assertSame(100, $this->t($this->tablero($u), $this->giron)['votantes']['meta']);

        $this->actingAs($u)->putJson("/campanas-electorales/{$this->c->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votantes_meta' => 80]]])->assertOk();
        $this->assertSame(80, $this->t($this->tablero($u), $this->giron)['votantes']['meta'], 'New goals: new cache key');

        $this->actingAs($u)->putJson("/campanas-electorales/{$this->c->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votantes_meta' => null, 'lideres_meta' => null]]])->assertOk();
        $this->assertSame('sin_meta', $this->t($this->tablero($u), $this->giron)['estado'], 'A removed goal is not served from an older key');

        $this->fichas(1, $this->velez);
        $this->assertSame(7, $this->t($this->tablero($u), $this->velez)['votantes']['registrados'], 'Cached for 5 minutes');
        $this->assertSame(8, $this->t($this->tablero($u, '?actualizar=1'), $this->velez)['votantes']['registrados']);
        $this->assertSame(8, $this->t($this->tablero($u), $this->velez)['votantes']['registrados'], 'actualizar refreshed the cache');
    }

    public function test_the_7th_actualizar_in_a_minute_is_429(): void
    {
        $u = $this->manager();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero?actualizar=1")->assertOk();
        }
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero?actualizar=1")
            ->assertStatus(429)->assertJson(['success' => false]);
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero")->assertOk();
    }

    public function test_two_users_one_after_the_other_never_see_each_others_figures(): void
    {
        $todo = $this->tablero($this->viewer());
        $giron = $this->tablero($this->viewer(Campaign::DEFAULT_ID, [$this->giron]));
        $todoOtraVez = $this->tablero($this->viewer());

        $this->assertSame([$this->giron], array_column($giron['territorios'], 'id'));
        $this->assertSame(50, $giron['totales']['votantes']['registrados']);
        $this->assertSame(50, $giron['totales']['indice']);
        $this->assertSame(['tipo' => 'territorio_usuario', 'texto' => 'Ves solo tus municipios: Girón.'], collect($giron['avisos'])->firstWhere('tipo', 'territorio_usuario'));
        $this->assertNull($giron['mapa']['capa']);
        $this->assertSame(377, $todo['totales']['votantes']['registrados']);
        $this->assertSame($todo['totales'], $todoOtraVez['totales']);
        $this->assertNull(collect($todo['avisos'])->firstWhere('tipo', 'territorio_usuario'));
    }

    public function test_a_territory_user_only_sees_their_municipios_and_comunas(): void
    {
        $u = $this->manager(Campaign::DEFAULT_ID, [$this->bucaramanga, $this->giron]);
        $d = $this->tablero($u);

        $this->assertEqualsCanonicalizing([$this->giron, $this->bucaramanga, $this->comuna1, $this->comuna2, $this->corregimiento1], array_column($d['territorios'], 'id'));
        $this->assertSame(150, $d['totales']['votantes']['registrados']);
        $this->assertSame('territorio', $d['soloLectura']['motivo']);
        $this->assertFalse($d['can']['edit']);
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->lebrija}")->assertNotFound();
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero/territorio/{$this->comuna1}")->assertOk();

        $ajena = $this->campana(['name' => 'Alc Lebrija', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->lebrija]);
        foreach (['tablero', 'tablero/territorio/'.$this->lebrija, 'tablero/exportar.xlsx', 'tablero/exportar.pdf'] as $path) {
            $this->actingAs($u)->getJson("/campanas-electorales/{$ajena->id}/{$path}")->assertNotFound();
        }
    }

    // ---------------------------------------------------------------- access

    public function test_flag_off_is_404_for_everyone_and_outside_beta_too(): void
    {
        $paths = ['tablero', 'tablero/territorio/'.$this->giron, 'tablero/exportar.xlsx', 'tablero/exportar.pdf'];
        $this->flag(false);
        foreach ($paths as $path) {
            $this->actingAs($this->superadmin())->getJson("/campanas-electorales/{$this->c->id}/{$path}")->assertNotFound();
            $this->actingAs($this->manager())->getJson("/campanas-electorales/{$this->c->id}/{$path}")->assertNotFound();
        }
        $beta = $this->manager();
        $this->flag(true, [(string) Str::uuid()]);
        foreach ($paths as $path) {
            $this->actingAs($beta)->getJson("/campanas-electorales/{$this->c->id}/{$path}")->assertNotFound();
        }
        $this->flag(true, [$beta->id]);
        $this->actingAs($beta)->getJson("/campanas-electorales/{$this->c->id}/tablero")->assertOk();
    }

    public function test_without_permission_403_in_json_and_a_redirect_on_a_visit(): void
    {
        $u = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes']);
        foreach (['tablero', 'tablero/territorio/'.$this->giron, 'tablero/exportar.xlsx', 'tablero/exportar.pdf'] as $path) {
            $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/{$path}")->assertForbidden();
        }
        $this->actingAs($u)->get("/campanas-electorales/{$this->c->id}/tablero")->assertRedirect('/');
    }

    public function test_a_group_outside_the_visible_ones_is_404(): void
    {
        $ajena = $this->campana(['name' => 'De otro grupo'], null, $this->campY);
        foreach (['tablero', 'tablero/territorio/'.$this->giron, 'tablero/exportar.xlsx', 'tablero/exportar.pdf'] as $path) {
            $this->actingAs($this->manager())->getJson("/campanas-electorales/{$ajena->id}/{$path}")->assertNotFound();
        }
        $this->actingAs($this->manager())->getJson('/campanas-electorales/no-uuid/tablero')->assertNotFound();
    }

    public function test_a_subgroup_campaign_is_read_only_and_counts_the_subgroup_fichas(): void
    {
        $sub = $this->campana(['name' => 'De Alejandro'], null, $this->campX);
        $this->meta($this->giron, ['votantes_meta' => 10], $sub);
        $this->fichas(6, $this->giron, [], $this->campX);
        $d = $this->tablero(null, '');
        $s = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$sub->id}/tablero")->assertOk()->json('data');

        $this->assertSame('subgrupo', $s['soloLectura']['motivo']);
        $this->assertSame(6, $this->t($s, $this->giron)['votantes']['registrados'], 'The owner group only');
        $this->assertSame(50, $this->t($d, $this->giron)['votantes']['registrados']);
    }

    public function test_an_archived_campaign_answers_with_a_notice(): void
    {
        $arch = $this->campana(['name' => 'Vieja'], null, Campaign::DEFAULT_ID, ElectoralCampaign::ARCHIVED);
        $d = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$arch->id}/tablero")->assertOk()->json('data');

        $this->assertSame(['tipo' => 'archivada', 'texto' => 'Campaña archivada: el tablero muestra los datos de hoy.'], $d['avisos'][0]);
        $this->assertSame('archivada', $d['soloLectura']['motivo']);
    }

    public function test_the_page_renders_the_tablero_component(): void
    {
        $this->withoutVite();
        $this->actingAs($this->manager())->get("/campanas-electorales/{$this->c->id}/tablero")->assertOk()
            ->assertInertia(fn ($p) => $p->component('CampanasElectorales/Tablero', false)
                ->where('totales.indice', 64)->has('territorios', 7)->where('campana.id', $this->c->id));
    }

    public function test_the_geojson_is_open_to_campaign_viewers_and_closed_without_any_view(): void
    {
        Cache::put(MunicipiosGeoJson::key(true), ['body' => '{"type":"FeatureCollection","features":[]}', 'etag' => '"m1"']);
        Cache::put(ComunasGeoJson::key('68001', true), ['body' => '{"type":"FeatureCollection","features":[]}', 'etag' => '"c1"']);
        $soloCampanas = $this->eventUser(Campaign::DEFAULT_ID, ['view.campanas']);
        $gestor = $this->eventUser(Campaign::DEFAULT_ID, ['manage.campanas']);
        $nada = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes']);

        foreach ([$soloCampanas, $gestor] as $u) {
            $this->actingAs($u)->get('/api/geo/municipios.geojson')->assertOk()->assertHeader('Content-Type', 'application/geo+json');
            $this->actingAs($u)->get('/api/geo/comunas.geojson?municipio=68001')->assertOk();
        }
        $this->actingAs($nada)->getJson('/api/geo/municipios.geojson')->assertForbidden();
        $this->actingAs($nada)->getJson('/api/geo/comunas.geojson?municipio=68001')->assertForbidden();
    }

    // ---------------------------------------------------------------- exports

    private function leerXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tab').'.xlsx';
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        return $sheets;
    }

    public function test_the_excel_has_three_sheets_equal_to_the_json_and_is_audited_first(): void
    {
        $u = $this->manager();
        $this->fichas(3, $this->velez, ['created_at' => '2026-11-03 15:00:00']);
        $json = $this->tablero($u, '?actualizar=1');
        $res = $this->actingAs($u)->get("/campanas-electorales/{$this->c->id}/tablero/exportar.xlsx")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('tablero-gobernacion-2027.xlsx', $res->headers->get('Content-Disposition'));
        $audit = DB::table('audit_logs')->where('action', 'campanas.tablero.excel')->first();
        $this->assertNotNull($audit, 'The audit row is written before the file is streamed');
        $this->assertSame($this->c->id, json_decode($audit->metadata, true)['electoral_campaign_id']);
        $x = $this->leerXlsx($res->streamedContent());

        $this->assertSame(['Resumen', 'Territorios', 'Evolución'], array_keys($x));
        $resumen = array_column(array_filter($x['Resumen'], fn ($r) => count($r) >= 2), 1, 0);
        $this->assertSame($json['totales']['votantes']['registrados'], $resumen['Votantes registrados']);
        $this->assertSame($json['totales']['votantes']['meta'], $resumen['Meta de votantes']);
        $this->assertSame($json['totales']['indice'], $resumen['Índice (0 a 100)']);
        $this->assertEqualsWithDelta($json['totales']['lideres']['pct'], $resumen['Avance líderes'], 0.0001);

        $this->assertSame(['Municipio', 'Comuna', 'Meta votantes', 'Registrados', '%', 'Faltan', 'Meta líderes', 'Líderes', '%', 'Faltan', 'Índice', 'Estado', 'Meta calculada'], $x['Territorios'][0]);
        $filas = array_slice($x['Territorios'], 1, count($json['territorios']));
        foreach ($json['territorios'] as $i => $t) {
            $f = $filas[$i];
            $this->assertSame($t['padre_id'] ? $t['nombre'] : '', $f[1]);
            $this->assertSame([$t['votantes']['meta'] ?? '', $t['votantes']['registrados'], $t['votantes']['falta'] ?? '', $t['lideres']['meta'] ?? '',
                $t['lideres']['registrados'], $t['indice'] ?? ''], [$f[2], $f[3], $f[5], $f[6], $f[7], $f[10]], $t['nombre']);
        }
        $giron = collect($filas)->first(fn ($f) => $f[0] === 'Girón');
        $this->assertSame([100, 50, 0.5, 50, 10, 5, 0.5, 5, 50, 'Con meta', 'No'], array_slice($giron, 2));
        $this->assertSame('Pocas fichas con comuna', collect($filas)->first(fn ($f) => $f[1] === 'Comuna 1 - Norte')[11]);

        $semanas = array_column(array_filter($x['Evolución'], fn ($r) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r[0] ?? ''))), 1, 0);
        $this->assertSame('menos de 5', $semanas['2026-11-02'], '3 fichas that week');
        $this->assertSame(377, $semanas['2026-10-05']);
        $this->assertCount(count($json['evolucion']['semanas']), $semanas);
    }

    public function test_the_pdf_is_a_pdf_without_personal_permission_and_audited(): void
    {
        $u = $this->manager();
        $this->assertFalse($u->hasPermission('export.datos_personales'));
        $res = $this->actingAs($u)->get("/campanas-electorales/{$this->c->id}/tablero/exportar.pdf")->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'campanas.tablero.pdf')->count());
    }

    public function test_the_excel_of_a_territory_user_has_only_their_municipios(): void
    {
        $u = $this->viewer(Campaign::DEFAULT_ID, [$this->giron]);
        $x = $this->leerXlsx($this->actingAs($u)->get("/campanas-electorales/{$this->c->id}/tablero/exportar.xlsx")->assertOk()->streamedContent());

        $municipios = array_values(array_filter(array_column(array_slice($x['Territorios'], 1), 0), fn ($m) => $m !== '' && ! str_starts_with($m, 'Fuente')));
        $this->assertSame(['Girón'], $municipios);
        $this->assertSame(50, array_column(array_filter($x['Resumen'], fn ($r) => count($r) >= 2), 1, 0)['Votantes registrados']);
    }

    public function test_the_6th_download_in_a_minute_and_two_at_once_are_429(): void
    {
        $u = $this->manager();
        for ($i = 0; $i < DescargaGuard::POR_MINUTO; $i++) {
            $this->actingAs($u)->get("/campanas-electorales/{$this->c->id}/tablero/exportar.xlsx")->assertOk()->streamedContent();
        }
        $this->actingAs($u)->getJson("/campanas-electorales/{$this->c->id}/tablero/exportar.pdf")->assertStatus(429)->assertJson(['motivo' => 'seguidas']);

        $otra = $this->manager();
        $lock = Cache::lock('reporte-descarga:'.$otra->id, DescargaGuard::LOCK_SEGUNDOS);
        $lock->get();
        try {
            $this->actingAs($otra)->getJson("/campanas-electorales/{$this->c->id}/tablero/exportar.xlsx")
                ->assertStatus(429)->assertJson(['motivo' => 'en_curso', 'message' => DescargaGuard::EN_CURSO]);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, DB::table('audit_logs')->where('user_id', $otra->id)->count(), 'Nothing audited when refused');
    }

    // ---------------------------------------------------------------- QA 2026-11-25

    public function test_a_municipio_with_goals_in_its_comunas_counts_only_those_comunas(): void
    {
        $alc = $this->campana(['name' => 'Alcaldía Bga', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->bucaramanga]);
        $this->meta($this->comuna1, ['votantes_meta' => 20], $alc);
        $this->meta($this->comuna2, ['votantes_meta' => 30], $alc);
        DB::table('lideres')->where('comuna_id', $this->comuna2)->delete();
        $this->fichas(4, $this->bucaramanga, ['comuna_id' => $this->corregimiento1, 'comuna_source' => 'manual']);
        $d = $this->actingAs($this->manager())->getJson("/campanas-electorales/{$alc->id}/tablero")->assertOk()->json('data');
        $bga = $this->t($d, $this->bucaramanga);

        // Comuna 1: 30 of 20; Comuna 2: 0 of 30. 30 of 50 = 60 %, not "Meta cumplida" with the 50 without comuna.
        $this->assertSame(['registrados' => 30, 'meta' => 50, 'meta_calculada' => false, 'pct' => 0.6, 'falta' => 30], $bga['votantes']);
        $this->assertSame(60, $bga['indice']);
        $this->assertSame(2, $bga['tramo']['votantes']);
        $this->assertSame(['votantes' => 50, 'lideres' => null], $bga['sin_comuna']);
        $this->assertSame(['votantes' => 4, 'lideres' => null], $bga['otras_comunas'], 'The corregimiento has no goal');
        $this->assertSame(['registrados' => 84, 'registrados_con_meta' => 30, 'meta' => 50, 'meta_calculada' => false, 'pct' => 0.6, 'falta' => 30],
            $d['totales']['votantes'], 'The card follows the same rule; registrados is still every ficha');
        $this->assertArrayNotHasKey('sin_comuna', $this->t($this->tablero(), $this->giron), 'Only municipios whose goal comes from comunas');
    }

    public function test_with_the_goal_met_the_rhythm_says_so(): void
    {
        $alc = $this->campana(['name' => 'Alc Lebrija', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'election_date' => '2027-10-31'], [$this->lebrija]);
        $this->meta($this->lebrija, ['votantes_meta' => 100], $alc);

        $this->actingAs($this->manager())->getJson("/campanas-electorales/{$alc->id}/tablero")->assertOk()
            ->assertJsonPath('data.evolucion.ritmo_necesario.texto', 'La meta de votantes ya se cumplió.')
            ->assertJsonPath('data.evolucion.ritmo_necesario.por_semana', 0);
    }

    public function test_the_rhythm_uses_the_sum_of_what_each_territory_lacks(): void
    {
        DB::table('electoral_campaigns')->where('id', $this->c->id)->update(['election_date' => '2026-12-31']);
        $this->c->refresh();
        $d = $this->tablero();

        // 2026-11-25 → 2026-12-31: 36 days = 6 weeks. Falta 80 → 14 per week; netting Lebrija's excess (60) would say 10.
        $this->assertSame(80, $d['totales']['votantes']['falta']);
        $this->assertSame(['semanas' => 6, 'por_semana' => 14], array_intersect_key($d['evolucion']['ritmo_necesario'], ['semanas' => 1, 'por_semana' => 1]));
    }

    public function test_the_pdf_card_puts_the_fichas_of_territories_with_goal_against_the_goal(): void
    {
        $datos = null;
        $this->app['events']->listen('composing: exports.tablero-campana', function ($view) use (&$datos) {
            $datos = $view->getData();
        });
        $this->actingAs($this->manager())->get("/campanas-electorales/{$this->c->id}/tablero/exportar.pdf")->assertOk();
        $this->assertNotNull($datos);
        $html = view('exports.tablero-campana', $datos)->render();

        $this->assertStringContainsString('300 de 360', $html);
        $this->assertStringNotContainsString('377 de 360', $html);
        $this->assertStringContainsString('En total hay 377, contando los territorios sin meta.', $html);
        $this->assertStringContainsString('Faltan 80', $html);
    }
}
