<?php

namespace App\Http\Controllers\Api\Agent;

use App\Actions\Votantes\AddContacto;
use App\Actions\Votantes\AddNexo;
use App\Actions\Votantes\AddReferido;
use App\Actions\Votantes\AddSeguimiento;
use App\Actions\Votantes\CreateVotante;
use App\Actions\Votantes\DeactivateVotante;
use App\Actions\Votantes\UpdateVotante;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditActivity;
use App\Http\Requests\Agent\AgentStoreContactoRequest;
use App\Http\Requests\Agent\AgentStoreNexoRequest;
use App\Http\Requests\Agent\AgentStoreReferidoRequest;
use App\Http\Requests\Agent\AgentStoreSeguimientoRequest;
use App\Http\Requests\Agent\AgentStoreVotanteRequest;
use App\Http\Requests\Agent\AgentUpdateVotanteRequest;
use App\Http\Requests\Agent\AgentVotanteRules;
use App\Models\Lider;
use App\Models\LiderSeguimiento;
use App\Support\ContactDataPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Write API of the WhatsApp agent, acting as the token owner. Territory checks live in the Actions.
class AgentVotanteController extends Controller
{
    public function store(AgentStoreVotanteRequest $request, CreateVotante $action): JsonResponse
    {
        $lider = $action->handle($request->validated());

        return $this->ok('Votante creado.', $this->summary($lider->refresh()), 201);
    }

    public function update(AgentUpdateVotanteRequest $request, string $id, UpdateVotante $action): JsonResponse
    {
        $lider = $action->handle($id, $request->validated());
        // AuditActivity stores them (masked) so the consent (autoriza_datos) is traceable value by value. On request():
        // the FormRequest is a copy with its own attribute bag, the middleware sees the original request.
        request()->attributes->set(AuditActivity::BEFORE_STATE, $action->before);
        request()->attributes->set(AuditActivity::AFTER_STATE, $action->after);

        return response()->json(array_filter([
            'success' => true, 'message' => 'Votante actualizado.', 'data' => $this->summary($lider), 'warning' => $action->warning,
        ], fn ($v) => $v !== null));
    }

    public function desactivar(string $id, DeactivateVotante $action): JsonResponse
    {
        $lider = $action->handle($id);

        return $this->ok('Votante desactivado.', ['id' => $lider->id, 'is_active' => false]);
    }

    public function storeContacto(AgentStoreContactoRequest $request, string $id, AddContacto $action): JsonResponse
    {
        $contacto = $action->handle($action->authorizeFor($id), $request->validated());

        // Never the value: contact data reaches the agent only through GET /votantes (quota and delivery audit).
        return $this->ok('Contacto creado.', $contacto->only(['id', 'lider_id', 'tipo', 'principal', 'estado']), 201);
    }

    public function storeReferido(AgentStoreReferidoRequest $request, string $id, AddReferido $action): JsonResponse
    {
        $action->handle($id, $request->validated('lider_id'));

        return $this->ok('Referido agregado.', ['referente_id' => $id, 'referido_id' => $request->validated('lider_id')], 201);
    }

    public function storeNexo(AgentStoreNexoRequest $request, string $id, AddNexo $action): JsonResponse
    {
        $action->authorizeFor($id);
        $nexoId = $action->handle($id, $request->validated());

        return $this->ok('Nexo familiar agregado.', ['id' => $nexoId, 'person_id' => $id], 201);
    }

    public function storeSeguimiento(AgentStoreSeguimientoRequest $request, string $id, AddSeguimiento $action): JsonResponse
    {
        $seguimiento = $action->handle($action->authorizeFor($id), $request->user(), $request->validated());

        return $this->ok('Seguimiento registrado.', $this->seguimiento($seguimiento->load('user:id,name')), 201);
    }

    public function seguimientos(Request $request, string $id, AddSeguimiento $action): JsonResponse
    {
        $action->authorizeFor($id, false);

        $items = LiderSeguimiento::with('user:id,name')
            ->where('lider_id', $id)
            ->orderByDesc('fecha')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (LiderSeguimiento $s) => $this->seguimiento($s))
            ->all();

        return response()->json(['success' => true, 'data' => $items]);
    }

    // Never contact data nor notes, even of who authorized them: those reach the agent only through GET /votantes.
    private function summary(Lider $lider): array
    {
        return ContactDataPolicy::withoutContactData($lider->only(['id', 'nombre', 'municipio', 'geographic_unit_id', 'is_active',
            ...array_keys(AgentVotanteRules::editable())]));
    }

    private function seguimiento(LiderSeguimiento $s): array
    {
        return [
            'id' => $s->id,
            'lider_id' => $s->lider_id,
            'tipo' => $s->tipo,
            'nota' => $s->nota,
            'fecha' => $s->fecha?->toIso8601String(),
            'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
        ];
    }

    private function ok(string $message, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
