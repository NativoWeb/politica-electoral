<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Deleting a CONFIRMED acta with delete.actas (docs/eventos-api.md §14): undoes only what its confirmation created.
class EventActaDeleteTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    private User $deleter;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos']);
        $this->deleter = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'delete.actas']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID);
    }

    private function confirmedActa(array $participantes, ?CampaignEvent $event = null, ?User $user = null): string
    {
        $event ??= $this->event;
        $user ??= $this->manager;
        $id = $this->actingAs($user)->postJson("/eventos/{$event->id}/actas", [
            'titulo' => 'ACTA REUNIÓN PRUEBA',
            'participantes' => $participantes,
            'compromisos' => [['descripcion' => 'Gestionar cámaras', 'responsables' => [['nombre' => 'Responsable Texto', 'telefono' => '3205556677']]]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/eventos/{$event->id}/actas/{$id}/confirmar")->assertOk();

        return $id;
    }

    private function url(string $actaId, string $suffix = ''): string
    {
        return "/eventos/{$this->event->id}/actas/{$actaId}{$suffix}";
    }

    private function participanteLider(string $actaId, string $nombre): ?string
    {
        return DB::table('event_acta_participantes')->where('acta_id', $actaId)->where('nombre', $nombre)->value('lider_id');
    }

    public function test_deleting_a_confirmed_acta_undoes_only_what_it_created(): void
    {
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Carlos Existente', '91000111');
        $prev = (string) Str::uuid();
        DB::table('event_attendees')->insert([
            'id' => $prev, 'event_id' => $this->event->id, 'campaign_id' => Campaign::DEFAULT_ID, 'nombres' => 'Asistente', 'apellidos' => 'Previo',
            'cedula' => '55667788', 'source' => 'operador', 'link_status' => 'pendiente', 'autoriza_datos' => false,
            'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $actaId = $this->confirmedActa([
            ['nombre' => 'CARLOS', 'cedula' => '91000111'],
            ['nombre' => 'Asistente Previo', 'attendee_id' => $prev],
            ['nombre' => 'NUEVO LIBRE', 'telefono' => '300 111 2233 / 301 999 8877'],
            ['nombre' => 'NUEVO EDITADO', 'telefono' => '3002223344'],
            ['nombre' => 'NUEVO CON CONTACTO', 'telefono' => '3003334455'],
        ]);
        $libre = $this->participanteLider($actaId, 'NUEVO LIBRE');
        $editado = $this->participanteLider($actaId, 'NUEVO EDITADO');
        $conContacto = $this->participanteLider($actaId, 'NUEVO CON CONTACTO');
        $deAsistente = $this->participanteLider($actaId, 'Asistente Previo');
        $this->assertSame(4, DB::table('event_acta_participantes')->where('created_lider', true)->count());
        $this->assertSame(4, DB::table('event_acta_participantes')->where('created_attendee', true)->count());

        // Activity after the confirmation keeps those fichas.
        DB::table('lideres')->where('id', $editado)->update(['updated_at' => now()->addMinute()]);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $conContacto, 'tipo' => 'celular', 'valor' => '3159990000', 'principal' => false, 'estado' => 'activo']);

        $compromiso = DB::table('event_compromisos')->value('id');
        $png = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(4, 4), $png);
        $this->actingAs($this->manager)->post("/eventos/compromisos/{$compromiso}/avances", ['archivo' => new UploadedFile($png, 'foto.png', null, null, true)], ['Accept' => 'application/json'])->assertCreated();
        $file = DB::table('event_compromiso_avances')->whereNotNull('archivo_path')->value('archivo_path');

        $expected = [
            'participantes' => 5, 'asistentes' => 4, 'asistentes_conservados' => 1, 'fichas_borrar' => 1, 'fichas_conservar' => 3,
            'vinculadas' => 1, 'compromisos' => 1, 'responsables' => 1, 'avances' => 1,
        ];
        $this->actingAs($this->deleter)->get($this->url($actaId))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete_confirmed', true)->where('can.delete', false));
        $this->actingAs($this->deleter)->getJson($this->url($actaId, '/eliminar'))->assertOk()->assertExactJson(['success' => true, 'data' => $expected]);

        $this->actingAs($this->deleter)->deleteJson($this->url($actaId))->assertOk()->assertJsonPath('data', $expected);

        foreach (['event_actas', 'event_acta_participantes', 'event_compromisos', 'event_compromiso_responsables', 'event_compromiso_avances'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Storage::disk('local')->assertMissing($file);
        $this->assertSame(0, DB::table('event_attendees')->where('source', 'acta')->count(), 'The attendees the acta created are gone');
        $this->assertTrue(DB::table('event_attendees')->where('id', $prev)->exists(), 'An attendee that already existed stays');
        $this->assertTrue(DB::table('lideres')->where('id', $existing)->where('nombre', 'Carlos Existente')->exists(), 'An existing ficha is never touched');
        $this->assertFalse(DB::table('lideres')->where('id', $libre)->exists(), 'A created ficha without other activity is deleted');
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $libre)->count());
        $this->assertTrue(DB::table('lideres')->where('id', $editado)->exists(), 'Edited after: kept');
        $this->assertTrue(DB::table('lideres')->where('id', $conContacto)->exists(), 'Another contact: kept');
        $this->assertTrue(DB::table('lideres')->where('id', $deAsistente)->exists(), 'Still the ficha of an attendee that already existed: kept');

        $audit = DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CONFIRMADA_BORRADA')->value('metadata');
        $this->assertStringContainsString($libre, $audit);
        $this->assertStringNotContainsString('NUEVO', $audit, 'No personal data in the audit');
    }

    public function test_a_ficha_in_another_acta_is_kept(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'NUEVO COMPARTIDO', 'telefono' => '3004445566']]);
        $lider = $this->participanteLider($actaId, 'NUEVO COMPARTIDO');
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas", [
            'titulo' => 'OTRA ACTA', 'participantes' => [],
            'compromisos' => [['descripcion' => 'Otro', 'responsables' => [['nombre' => 'NUEVO COMPARTIDO', 'lider_id' => $lider]]]],
        ])->assertCreated();

        $this->actingAs($this->deleter)->deleteJson($this->url($actaId))->assertOk()
            ->assertJsonPath('data.fichas_borrar', 0)->assertJsonPath('data.fichas_conservar', 1);
        $this->assertTrue(DB::table('lideres')->where('id', $lider)->exists());
        $this->assertSame(1, DB::table('event_actas')->count());
    }

    public function test_a_ficha_that_referred_someone_is_kept(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'NUEVO REFERENTE', 'cedula' => '77001122']]);
        $lider = $this->participanteLider($actaId, 'NUEVO REFERENTE');
        $this->lider(Campaign::DEFAULT_ID, 'Referido', null, ['referente_documento' => '77001122']);

        $this->actingAs($this->deleter)->deleteJson($this->url($actaId))->assertOk()->assertJsonPath('data.fichas_conservar', 1);
        $this->assertTrue(DB::table('lideres')->where('id', $lider)->exists());
    }

    public function test_an_acta_confirmed_before_the_flags_only_deletes_itself(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'NUEVO ANTIGUO', 'telefono' => '3005556677']]);
        DB::table('event_acta_participantes')->update(['created_lider' => false, 'created_attendee' => false]);

        $this->actingAs($this->deleter)->deleteJson($this->url($actaId))->assertOk()
            ->assertJsonPath('data.asistentes', 0)->assertJsonPath('data.fichas_borrar', 0);
        $this->assertSame(0, DB::table('event_actas')->count());
        $this->assertSame(1, DB::table('event_attendees')->where('source', 'acta')->count());
        $this->assertSame(1, DB::table('lideres')->where('nombre', 'NUEVO ANTIGUO')->count());
    }

    public function test_permissions_campaign_and_territory(): void
    {
        $actaId = $this->confirmedActa([['nombre' => 'NUEVO', 'telefono' => '3006667788']]);

        $this->actingAs($this->manager)->getJson($this->url($actaId, '/eliminar'))->assertForbidden();
        $this->actingAs($this->manager)->deleteJson($this->url($actaId))->assertForbidden();
        $viewerWithDelete = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'delete.actas']);
        $this->actingAs($viewerWithDelete)->deleteJson($this->url($actaId))->assertForbidden();
        $this->actingAs($viewerWithDelete)->getJson($this->url($actaId, '/eliminar'))->assertForbidden();
        $this->actingAs($this->manager)->get($this->url($actaId))->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete_confirmed', false));

        $other = $this->eventUser($this->campY, ['manage.eventos', 'view.eventos', 'delete.actas']);
        $this->actingAs($other)->deleteJson($this->url($actaId))->assertNotFound();
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos', 'delete.actas'], 'R02_ADMIN_FUNCIONAL', [$this->velez]);
        $this->actingAs($outside)->deleteJson($this->url($actaId))->assertForbidden();

        // The parent campaign only reads the subcampaign's actas.
        $sub = $this->makeEvent($this->campX);
        $subActa = $this->confirmedActa([], $sub, $this->eventUser($this->campX, ['manage.eventos', 'view.eventos']));
        $this->actingAs($this->deleter)->deleteJson("/eventos/{$sub->id}/actas/{$subActa}")->assertForbidden();

        $this->assertSame(2, DB::table('event_actas')->count());
        $this->assertSame(1, DB::table('event_attendees')->where('source', 'acta')->count());

        // A borrador has no preview: it is deleted as a borrador, without delete.actas.
        $draft = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/actas", ['titulo' => 'BORRADOR'])->assertCreated()->json('data.id');
        $this->actingAs($this->deleter)->getJson($this->url($draft, '/eliminar'))->assertUnprocessable()->assertJsonValidationErrors('acta');
        $this->actingAs($this->manager)->deleteJson($this->url($draft))->assertOk();
    }
}
