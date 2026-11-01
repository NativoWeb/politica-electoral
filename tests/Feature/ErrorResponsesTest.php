<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// 404 and 429 answers in Spanish: a missing record and one of a campaign the user cannot see look the same and
// never name the model; the throttle message and page are in Spanish. SQLite.
class ErrorResponsesTest extends TestCase
{
    use BuildsEventosScenario;

    private const NOT_FOUND = ['message' => 'No se encontró lo que busca.'];

    private User $manager;

    private User $operator;

    private CampaignEvent $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R10_CONSULTA_EJECUTIVA');
        $this->foreign = $this->makeEvent($this->campY);
    }

    public function test_a_missing_event_and_one_of_another_campaign_give_the_same_json_404(): void
    {
        $missing = (string) Str::uuid();

        foreach (["/eventos/{$missing}", "/eventos/{$this->foreign->id}"] as $url) {
            $this->actingAs($this->manager)->getJson($url)->assertNotFound()->assertExactJson(self::NOT_FOUND);
            $this->actingAs($this->manager)->putJson($url, ['nombre' => 'X'])->assertNotFound()->assertExactJson(self::NOT_FOUND);
            $this->actingAs($this->operator)->postJson("{$url}/asistentes", $this->attendeePayload($this->provisionalText()->id))
                ->assertNotFound()->assertExactJson(self::NOT_FOUND);
        }
        $this->actingAs($this->manager)->getJson('/eventos/no-es-uuid')->assertNotFound()->assertExactJson(self::NOT_FOUND);
    }

    public function test_the_html_404_does_not_tell_them_apart_either(): void
    {
        $bodies = [];
        foreach ([(string) Str::uuid(), $this->foreign->id] as $id) {
            $body = $this->actingAs($this->operator)->get("/eventos/{$id}/planilla.pdf")->assertNotFound()->getContent();
            $this->assertStringContainsString('No encontramos esta página', $body);
            $this->assertStringNotContainsString('No query results', $body);
            $this->assertStringNotContainsString('CampaignEvent', $body);
            $bodies[] = $body;
        }
        $this->assertSame($bodies[0], $bodies[1]);
    }

    public function test_any_model_404_is_generic_and_messages_the_app_wrote_are_kept(): void
    {
        Route::get('/_test/usuario/{id}', fn (string $id) => User::findOrFail($id));
        Route::get('/_test/propio', fn () => abort(404, 'Municipio no encontrado'));

        $this->getJson('/_test/usuario/'.Str::uuid())->assertNotFound()->assertExactJson(self::NOT_FOUND);
        $this->getJson('/_test/propio')->assertNotFound()->assertJsonPath('message', 'Municipio no encontrado');
        $this->get('/e/'.Str::random(43))->assertNotFound()->assertSee('Este enlace ya no funciona');
    }

    public function test_the_throttle_answers_in_spanish_as_json_and_as_a_page(): void
    {
        $event = $this->makeEvent(Campaign::DEFAULT_ID);
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->operator)->get("/eventos/{$event->id}/planilla.pdf?paginas=1")->assertOk();
        }

        $this->actingAs($this->operator)->getJson("/eventos/{$event->id}/planilla.pdf")->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['success' => false, 'message' => 'Demasiadas solicitudes. Espere un momento e intente de nuevo.']);

        $page = $this->actingAs($this->operator)->get("/eventos/{$event->id}/planilla.pdf")->assertStatus(429);
        $page->assertSee('Error 429')->assertSee('Demasiadas solicitudes. Espere un momento e intente de nuevo.')
            ->assertSee('Puede intentar de nuevo en')->assertDontSee('Too Many Attempts');
    }

    public function test_a_429_message_written_by_the_app_is_shown_on_the_page(): void
    {
        $html = view('errors.429', ['exception' => new HttpException(429, 'Hay demasiados registros en este momento.')])->render();

        $this->assertStringContainsString('Hay demasiados registros en este momento.', $html);
        $this->assertStringNotContainsString('Espere un momento e intente de nuevo', $html);
    }
}
