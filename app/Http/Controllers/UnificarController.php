<?php

namespace App\Http\Controllers;

use App\Actions\Votantes\UnifyRecords;
use App\Models\Person;
use Illuminate\Http\Request;

// "Unificar con otra persona" in the ficha of the Mapa and of Votantes: search, preview and merge (UnifyRecords).
class UnificarController extends Controller
{
    public function buscar(Request $request, string $id, UnifyRecords $unify)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        return response()->json(['success' => true, 'data' => $unify->search($id, (string) ($data['q'] ?? ''))]);
    }

    public function vistaPrevia(Request $request, string $id, UnifyRecords $unify)
    {
        $data = $this->validated($request);

        return response()->json(['success' => true] + $unify->preview($id, $data['con'], $data['cedula'] ?? null));
    }

    public function unificar(Request $request, string $id, UnifyRecords $unify)
    {
        $data = $this->validated($request);
        $result = $unify->execute($id, $data['con'], $data['cedula'] ?? null);

        return response()->json(['success' => true, 'message' => 'Registros unificados.'] + $result);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'con' => 'required|uuid',
            'cedula' => 'nullable|string|max:20',
        ]);
        // A candidate merged meanwhile stands for its survivor, like the ficha's own id (person.resolve).
        $data['con'] = Person::resolveId($data['con']);

        return $data;
    }
}
