<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonContactPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PersonCrudController extends Controller
{
    public function index(Request $request)
    {
        $query = Person::query()->whereNull('merged_into_id');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                  ->orWhere('normalized_name', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('identity_status', $status);
        }

        $persons = $query->withCount(['candidacies', 'contactPoints', 'aliases'])
            ->orderBy('full_name')
            ->paginate(50)
            ->through(fn ($p) => [
                'id' => $p->id,
                'firstName' => $p->first_name,
                'lastName' => $p->last_name,
                'fullName' => $p->full_name,
                'gender' => $p->gender,
                'birthDate' => $p->birth_date?->format('Y-m-d'),
                'status' => $p->identity_status,
                'classification' => $p->data_classification,
                'candidacies' => $p->candidacies_count,
                'contacts' => $p->contact_points_count,
                'aliases' => $p->aliases_count,
            ]);

        return Inertia::render('Admin/Personas', [
            'personas' => $persons,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function show(string $id)
    {
        if (!\Illuminate\Support\Str::isUuid($id)) abort(404);
        $person = Person::with(['aliases', 'contactPoints', 'candidacies.contest.electoralEvent', 'candidacies.organization'])
            ->findOrFail($id);

        return response()->json([
            'id' => $person->id,
            'firstName' => $person->first_name,
            'lastName' => $person->last_name,
            'fullName' => $person->full_name,
            'gender' => $person->gender,
            'birthDate' => $person->birth_date?->format('Y-m-d'),
            'status' => $person->identity_status,
            'classification' => $person->data_classification,
            'aliases' => $person->aliases->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->alias_name,
                'type' => $a->alias_type,
            ]),
            'contacts' => $person->contactPoints->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'value' => $c->value_raw,
                'label' => $c->label,
                'isCurrent' => $c->is_current,
            ]),
            'candidacies' => $person->candidacies->map(fn ($c) => [
                'id' => $c->id,
                'event' => $c->contest?->electoralEvent?->name,
                'party' => $c->organization?->canonical_name,
                'outcome' => $c->outcome,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'nullable|in:male,female,other',
            'birth_date' => 'nullable|date',
            'data_classification' => 'nullable|in:public,internal,confidential,restricted',
        ]);

        $fullName = trim($data['first_name'] . ' ' . $data['last_name']);

        $person = Person::create([
            ...$data,
            'full_name' => $fullName,
            'normalized_name' => Str::upper(Str::ascii($fullName)),
            'identity_status' => 'unverified',
        ]);

        return back()->with('success', "Persona «{$person->full_name}» creada.");
    }

    public function update(Request $request, string $id)
    {
        $person = Person::findOrFail($id);

        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'nullable|in:male,female,other',
            'birth_date' => 'nullable|date',
            'identity_status' => 'nullable|in:unverified,confirmed,duplicate,merged',
            'data_classification' => 'nullable|in:public,internal,confidential,restricted',
        ]);

        $fullName = trim($data['first_name'] . ' ' . $data['last_name']);

        $person->update([
            ...$data,
            'full_name' => $fullName,
            'normalized_name' => Str::upper(Str::ascii($fullName)),
        ]);

        return back()->with('success', "Persona «{$person->full_name}» actualizada.");
    }

    public function destroy(string $id)
    {
        $person = Person::findOrFail($id);
        $person->delete();
        return back()->with('success', "Persona «{$person->full_name}» eliminada.");
    }

    // --- Aliases ---

    public function storeAlias(Request $request, string $personId)
    {
        $data = $request->validate([
            'alias_name' => 'required|string|max:255',
            'alias_type' => 'nullable|in:nickname,maiden,electoral,typo,other',
        ]);

        PersonAlias::create([
            'person_id' => $personId,
            'alias_name' => $data['alias_name'],
            'normalized_alias' => Str::upper(Str::ascii($data['alias_name'])),
            'alias_type' => $data['alias_type'] ?? 'other',
        ]);

        return back()->with('success', 'Alias agregado.');
    }

    public function destroyAlias(string $personId, string $aliasId)
    {
        PersonAlias::where('person_id', $personId)->where('id', $aliasId)->delete();
        return back()->with('success', 'Alias eliminado.');
    }

    // --- Contacts ---

    public function storeContact(Request $request, string $personId)
    {
        $data = $request->validate([
            'type' => 'required|in:phone,email,whatsapp,other',
            'value' => 'required|string|max:255',
            'label' => 'nullable|string|max:100',
        ]);

        PersonContactPoint::create([
            'person_id' => $personId,
            'type' => $data['type'],
            'value_raw' => $data['value'],
            'label' => $data['label'],
            'is_current' => true,
            'data_classification' => 'confidential',
        ]);

        return back()->with('success', 'Contacto agregado.');
    }

    public function destroyContact(string $personId, string $contactId)
    {
        PersonContactPoint::where('person_id', $personId)->where('id', $contactId)->delete();
        return back()->with('success', 'Contacto eliminado.');
    }
}
