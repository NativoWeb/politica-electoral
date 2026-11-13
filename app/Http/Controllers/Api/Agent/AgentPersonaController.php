<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Concerns\DeliversAgentContactData;
use App\Http\Controllers\Controller;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AgentFicha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// The ficha of one person for the WhatsApp agent, as JSON or PDF (AgentFicha). It carries the person's contact data
// even without autoriza_datos (owner's decision): each delivery is audited, without values, and costs in the daily
// contact quota 1 for the person (or its nexos) plus 1 per other person with data in it (AgentFicha::build). Like a page
// of /votantes or /mapa, a ficha the quota left cannot cover is refused whole (429), never cut.
class AgentPersonaController extends Controller
{
    use DeliversAgentContactData;

    public function __construct(private readonly AgentContactQuota $quota, private readonly AgentFicha $ficha) {}

    public function ficha(Request $request, string $id): JsonResponse
    {
        [$data, $conDatos] = $this->ficha->build($request, $id);
        $counts = $this->counts($data, $conDatos);
        if ($blocked = $this->overQuota($request, 'agent.persona.ficha', [], $counts, $data['id'])) {
            return $blocked;
        }
        $this->deliver($request, 'agent.persona.ficha', [], $counts, $data['id']);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'con_datos' => $counts['con_datos'],
                'cupo_restante' => $this->quota->remaining($request->user()->id),
            ],
        ]);
    }

    public function fichaPdf(Request $request, string $id): Response
    {
        [$data, $conDatos] = $this->ficha->build($request, $id);
        $counts = $this->counts($data, $conDatos);
        if ($blocked = $this->overQuota($request, 'agent.persona.ficha_pdf', [], $counts, $data['id'])) {
            return $blocked;
        }

        try {
            $response = Pdf::loadView('reports.ficha', [
                'f' => $data,
                'date' => Carbon::now('America/Bogota')->format('d/m/Y \a \l\a\s H:i'),
            ])->setPaper('letter')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isPhpEnabled', false)
                ->download('ficha-'.(Str::slug(Str::limit((string) $data['nombre'], 60, '')) ?: 'persona').'.pdf');
        } catch (Throwable $e) {
            $this->quota->release($request->user()->id, $counts['con_datos']);

            throw $e;
        }
        $this->deliver($request, 'agent.persona.ficha_pdf', [], $counts, $data['id']);
        $response->headers->set('X-Rows-With-Data', (string) $counts['con_datos']);

        return $response;
    }

    protected function auditEntityType(): string
    {
        return 'persona';
    }

    // What the audit row keeps: the kind of ficha and which sections it had, never a value.
    private function counts(array $data, int $conDatos): array
    {
        return [
            'source' => $data['source'],
            'datos_autorizados' => $data['datos_autorizados'],
            'con_datos' => $conDatos,
            'secciones' => AgentFicha::secciones($data),
        ];
    }
}
