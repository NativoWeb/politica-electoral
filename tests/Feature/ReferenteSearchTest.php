<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// GET /mapa-politico/search-lideres: the search behind the referente fields and the referidos picker. SQLite.
class ReferenteSearchTest extends TestCase
{
    use BuildsEventosScenario;

    private const URL = '/mapa-politico/search-lideres';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
    }

    public function test_finds_by_name_case_insensitively_with_the_fields_the_form_needs(): void
    {
        $id = $this->lider(Campaign::DEFAULT_ID, 'Maria Fernanda Rueda Gomez', '1098765432', ['cargo' => 'Concejal']);
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa']);

        $this->actingAs($user)->getJson(self::URL.'?q=fernanda rue')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $id, 'nombre' => 'Maria Fernanda Rueda Gomez', 'cedula' => '1098765432',
                'municipio' => 'Girón', 'telefono' => '3000000000', 'cargo' => 'Concejal',
            ]]]);
    }

    public function test_finds_by_cedula_typed_with_dots(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Pedro Cedula', '1098765432');
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes']);

        $this->actingAs($user)->getJson(self::URL.'?q='.urlencode('1.098.765'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.cedula', '1098765432');
    }

    public function test_excludes_the_record_being_edited_inactive_ones_and_short_queries(): void
    {
        $self = $this->lider(Campaign::DEFAULT_ID, 'Zzref Propio', '111');
        $this->lider(Campaign::DEFAULT_ID, 'Zzref Inactivo', '222', ['is_active' => false]);
        $other = $this->lider(Campaign::DEFAULT_ID, 'Zzref Otro', '333');
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa']);

        $this->actingAs($user)->getJson(self::URL.'?q=zzref&exclude_id='.$self)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);
        $this->actingAs($user)->getJson(self::URL.'?q=z')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_only_returns_the_own_campaign(): void
    {
        $own = $this->lider(Campaign::DEFAULT_ID, 'Zzcamp Propia', '444');
        $this->lider($this->campX, 'Zzcamp Subcampaña', '555');
        $this->lider($this->campY, 'Zzcamp Ajena', '666');

        $ids = $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['view.mapa']))
            ->getJson(self::URL.'?q=zzcamp')->assertOk()->json('data.*.id');
        $this->assertSame([$own], $ids);

        $this->actingAs($this->eventUser($this->campY, ['view.mapa']))
            ->getJson(self::URL.'?q=444')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_only_returns_the_users_territory(): void
    {
        $inside = $this->lider(Campaign::DEFAULT_ID, 'Zzterr Giron', '777');
        $this->lider(Campaign::DEFAULT_ID, 'Zzterr Velez', '888', ['geographic_unit_id' => $this->velez, 'municipio' => 'Vélez']);
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa'], 'R09_CAMPO', [$this->giron]);

        $this->assertSame([$inside], $this->actingAs($user)->getJson(self::URL.'?q=zzterr')->assertOk()->json('data.*.id'));
    }

    public function test_requires_the_map_or_votantes_permission(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Zzperm Persona', '999');
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);

        $this->getJson(self::URL.'?q=zzperm')->assertUnauthorized();
        $this->actingAs($user)->getJson(self::URL.'?q=zzperm')->assertForbidden();
    }
}
