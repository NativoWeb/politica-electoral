<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

// Opt-in: a lider counted twice in the Líderes section (BUG-04) and the export showing other rows than the page.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=MapaPoliticoUnifiedRowsPostgresTest
class MapaPoliticoUnifiedRowsPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const MUNICIPIO = 'Municipio Unificado Málaga PG';

    private string $mun;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => self::MUNICIPIO]);

        $this->user = User::create([
            'name' => 'Unificado PG', 'email' => 'unif-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'territory_scope' => [$this->mun], 'permissions' => ['view.votantes', 'view.mapa', 'write.data'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    private function lider(string $nombre, string $cargo, ?string $campaignId = null, string $municipio = self::MUNICIPIO, bool $withGeo = true): void
    {
        DB::table('lideres')->insert([
            'id' => (string) Str::uuid(), 'campaign_id' => $campaignId ?? Campaign::DEFAULT_ID, 'geographic_unit_id' => $withGeo ? $this->mun : null,
            'nombre' => $nombre, 'municipio' => $municipio, 'cargo' => $cargo, 'is_active' => true,
            'partido' => 'Centro Democrático', 'observacion' => "Nota de {$nombre}",
            'cedula' => '88'.random_int(10000000, 99999999), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function alcaldiaCandidate(string $fullName): void
    {
        $eventId = DB::table('contests')->whereNotNull('electoral_event_id')->value('electoral_event_id');
        $this->assertNotNull($eventId);
        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => 'Alcaldía unificada', 'electoral_event_id' => $eventId,
            'office_id' => DB::table('offices')->where('name', 'Alcaldía')->value('id'), 'geographic_unit_id' => $this->mun,
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert([
            'id' => $personId, 'first_name' => 'Zzunif', 'last_name' => 'Candidato',
            'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        ]);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $personId, 'contest_id' => $contestId, 'outcome' => 'elected']);
    }

    private function page(string $query = ''): array
    {
        return $this->actingAs($this->user)->get("/mapa-politico?municipio={$this->mun}{$query}")
            ->assertOk()->viewData('page')['props'];
    }

    // Section => names, grouped like the page does (one entry per tipo label of the row).
    private function sections(array $data): array
    {
        $groups = [];
        foreach ($data as $row) {
            foreach (array_map('trim', explode(',', $row['tipo_registro'])) as $tipo) {
                $groups[$tipo][] = $row['nombre'];
            }
        }

        return $groups;
    }

    public function test_a_directorio_lider_is_listed_once_in_lideres_and_once_in_directorio(): void
    {
        $this->lider('Zzunif Dora Directorio', 'Directorio Municipal');
        $this->lider('Zzunif Leo Lider', 'Coordinador Municipal');

        $props = $this->page();
        $dora = collect($props['data'])->where('nombre', 'Zzunif Dora Directorio');

        $this->assertCount(1, $dora);
        $this->assertSame('Líderes, Directorio Municipal', $dora->first()['tipo_registro']);

        $sections = $this->sections($props['data']);
        $this->assertSame(['Zzunif Dora Directorio', 'Zzunif Leo Lider'], $sections['Líderes']);
        $this->assertSame(['Zzunif Dora Directorio'], $sections['Directorio Municipal']);
        $this->assertSame(2, $props['sectionCounts']['Líderes']);
        $this->assertSame(1, $props['sectionCounts']['Directorio Municipal']);
    }

    public function test_a_candidate_unified_with_a_directorio_lider_has_each_tipo_once(): void
    {
        $this->alcaldiaCandidate('Zzunif Ana Alcaldesa');
        $this->lider('Zzunif Ana Alcaldesa', 'Directorio Municipal');

        $props = $this->page();
        $ana = collect($props['data'])->where('nombre', 'Zzunif Ana Alcaldesa');

        $this->assertCount(1, $ana);
        $this->assertSame('Alcaldía, Líderes, Directorio Municipal', $ana->first()['tipo_registro']);
        $this->assertSame(Campaign::DEFAULT_ID, $ana->first()['campaign_id']);
        $this->assertSame(['Zzunif Ana Alcaldesa'], $this->sections($props['data'])['Líderes']);
        $this->assertSame(1, $props['sectionCounts']['Líderes']);
    }

    public function test_a_namesake_of_another_campaign_still_gets_its_own_row(): void
    {
        $sub = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $sub, 'name' => 'Sub unificada', 'slug' => 'sub-unif-'.Str::random(6), 'parent_id' => Campaign::DEFAULT_ID,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lider('Zzunif Dora Directorio', 'Directorio Municipal');
        $this->lider('Zzunif Dora Directorio', 'Directorio Municipal', $sub);

        $rows = collect($this->page()['data'])->where('nombre', 'Zzunif Dora Directorio');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([Campaign::DEFAULT_ID, $sub], $rows->pluck('campaign_id')->all());
        $this->assertSame(['Líderes, Directorio Municipal'], $rows->pluck('tipo_registro')->unique()->values()->all());
    }

    public function test_a_lider_whose_municipio_text_differs_still_unifies_with_the_candidate_by_municipio_id(): void
    {
        $this->alcaldiaCandidate('Zzunif Ciro Rosales');
        $this->lider('Zzunif Ciro Rosales', 'Directorio Municipal', null, 'MALAGA (texto viejo)');

        $props = $this->page();
        $ciro = collect($props['data'])->where('nombre', 'Zzunif Ciro Rosales');

        $this->assertCount(1, $ciro);
        $this->assertSame('Alcaldía, Líderes, Directorio Municipal', $ciro->first()['tipo_registro']);
        $this->assertSame(self::MUNICIPIO, $ciro->first()['municipio']);
    }

    public function test_without_municipio_id_the_text_matches_without_accents_and_campaigns_stay_apart(): void
    {
        $sub = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $sub, 'name' => 'Sub acentos', 'slug' => 'sub-acentos-'.Str::random(6), 'parent_id' => Campaign::DEFAULT_ID,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // A lider without municipio id is only listed for a user without territory restriction.
        $this->user->forceFill(['territory_scope' => null])->save();
        $this->alcaldiaCandidate('Zzunif Marta Penagos');
        $this->lider('Zzunif Marta Penagos', 'Directorio Municipal', null, 'municipio unificado malaga pg', false);
        $this->lider('Zzunif Marta Penagos', 'Directorio Municipal', $sub, 'Municipio Unificado Malaga PG');

        $rows = collect($this->actingAs($this->user)->get('/mapa-politico?search=Zzunif+Marta')->assertOk()
            ->viewData('page')['props']['data'])->where('nombre', 'Zzunif Marta Penagos');

        $this->assertCount(2, $rows);
        $this->assertSame('Alcaldía, Líderes, Directorio Municipal', $rows->firstWhere('campaign_id', Campaign::DEFAULT_ID)['tipo_registro']);
        $this->assertSame('Líderes, Directorio Municipal', $rows->firstWhere('campaign_id', $sub)['tipo_registro']);
    }

    public function test_namesake_lideres_with_different_cedulas_keep_separate_rows_on_the_page_and_in_the_export(): void
    {
        foreach ([['9905653065', '3001110001', 'Directorio Municipal'], ['9905653068', '3001110002', 'Coordinador']] as [$cedula, $telefono, $cargo]) {
            DB::table('lideres')->insert([
                'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun,
                'nombre' => 'Zzunif Jaime Franco', 'municipio' => self::MUNICIPIO, 'cargo' => $cargo, 'is_active' => true,
                'cedula' => $cedula, 'telefono' => $telefono, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = collect($this->page()['data'])->where('nombre', 'Zzunif Jaime Franco');
        $this->assertEqualsCanonicalizing(
            ['9905653065|3001110001|Líderes, Directorio Municipal', '9905653068|3001110002|Líderes'],
            $rows->map(fn ($r) => "{$r['cedula']}|{$r['telefono']}|{$r['tipo_registro']}")->all(),
        );

        Excel::fake();
        $this->actingAs($this->user)
            ->get("/exportar/excel?source=mapa-politico&municipio={$this->mun}&columns=nombre,telefono")->assertOk();
        $exported = null;
        Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) use (&$exported) {
            $exported = $export->array();

            return true;
        });
        $this->assertEqualsCanonicalizing(['3001110001', '3001110002'],
            collect($exported)->where(0, 'Zzunif Jaime Franco')->pluck(1)->all());
    }

    public function test_a_candidate_matching_only_a_subcampaign_lider_is_not_tagged_as_lider_of_the_own_campaign(): void
    {
        $sub = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $sub, 'name' => 'Sub enriquecer', 'slug' => 'sub-enr-'.Str::random(6), 'parent_id' => Campaign::DEFAULT_ID,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->alcaldiaCandidate('Zzunif Solo Sub');
        $this->lider('Zzunif Solo Sub', 'Coordinador', $sub);

        $props = $this->page();
        $rows = collect($props['data'])->where('nombre', 'Zzunif Solo Sub');

        $this->assertCount(2, $rows);
        $this->assertSame('Alcaldía', $rows->first(fn ($r) => ! isset($r['campaign_id']))['tipo_registro']);
        $this->assertSame('Líderes', $rows->firstWhere('campaign_id', $sub)['tipo_registro']);
        $this->assertSame(['Zzunif Solo Sub'], $this->sections($props['data'])['Líderes']);
    }

    public function test_the_export_without_filters_is_not_capped_like_the_page_and_has_no_duplicates(): void
    {
        $this->user->forceFill(['territory_scope' => null])->save();
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun,
                'nombre' => sprintf('Zzcap Lider %03d', $i), 'municipio' => self::MUNICIPIO, 'is_active' => true,
                'cargo' => $i % 2 ? 'Directorio Municipal' : 'Coordinador', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('lideres')->insert($rows);

        $page = collect($this->actingAs($this->user)->get('/mapa-politico?tipo=lideres,directorio')->assertOk()
            ->viewData('page')['props']['data'])->filter(fn ($r) => str_contains($r['tipo_registro'], 'Líderes'));
        $this->assertLessThanOrEqual(200, $page->count());

        Excel::fake();
        $this->actingAs($this->user)->get('/exportar/excel?source=mapa-politico&tipo=lideres,directorio&columns=nombre,tipo')->assertOk();
        $exported = null;
        Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) use (&$exported) {
            $exported = $export->array();

            return true;
        });

        $mine = collect($exported)->filter(fn ($r) => str_starts_with($r[0], 'Zzcap Lider'));
        $this->assertCount(205, $mine);
        $this->assertCount(205, $mine->pluck(0)->unique());
        $this->assertSame(102, $mine->where(1, 'Líderes, Directorio Municipal')->count());
        $this->assertSame(103, $mine->where(1, 'Líderes')->count());
    }

    public function test_the_excel_export_has_the_rows_of_the_page_with_the_same_filters(): void
    {
        $this->alcaldiaCandidate('Zzunif Ana Alcaldesa');
        $this->lider('Zzunif Ana Alcaldesa', 'Directorio Municipal');
        $this->lider('Zzunif Dora Directorio', 'Directorio Municipal');
        $this->lider('Zzunif Leo Lider', 'Coordinador Municipal');

        foreach (['', '&partido='.rawurlencode('Centro Democrático'), '&tipo=lideres,directorio', '&tipo=directorio'] as $filters) {
            $page = collect($this->page($filters)['data'])
                ->map(fn ($r) => $r['nombre'].'|'.$r['municipio'].'|'.$r['tipo_registro'])->sort()->values()->all();

            Excel::fake();
            $this->actingAs($this->user)
                ->get("/exportar/excel?source=mapa-politico&municipio={$this->mun}{$filters}&columns=nombre,municipio,tipo,observacion")
                ->assertOk();
            $exported = null;
            Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) use (&$exported) {
                $exported = $export->array();

                return true;
            });

            $this->assertNotEmpty($page, "filters: {$filters}");
            $this->assertSame($page, collect($exported)->map(fn ($r) => "{$r[0]}|{$r[1]}|{$r[2]}")->sort()->values()->all(), "filters: {$filters}");
        }

        $this->assertContains('Nota de Zzunif Ana Alcaldesa', array_column($exported, 3));
    }
}
