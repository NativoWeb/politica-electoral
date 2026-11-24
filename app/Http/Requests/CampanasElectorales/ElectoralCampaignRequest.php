<?php

namespace App\Http\Requests\CampanasElectorales;

use App\Models\ElectoralCampaign;
use App\Services\CampanasElectorales\ElectoralCampaignAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Body of POST /campanas-electorales and PUT /campanas-electorales/{id} (docs/campanas-electorales.md). On a PUT the
// campaign is found (404/403) before the body is looked at. campaign_id, status and the authors never come from here.
class ElectoralCampaignRequest extends FormRequest
{
    public ?ElectoralCampaign $campana = null;

    public function authorize(ElectoralCampaignAccess $access): bool
    {
        if ($id = $this->route('id')) {
            $this->campana = $access->find((string) $id, true);
        }

        return true;
    }

    public function rules(): array
    {
        $anio = config('campanas_electorales.anio');
        $f = config('campanas_electorales.factores');

        return [
            'name' => ['required', 'string', 'max:120'],
            'cargo' => ['required', 'string', Rule::in(array_keys(ElectoralCampaign::CARGOS))],
            'election_year' => ['required', 'integer', "between:{$anio['min']},{$anio['max']}"],
            'election_date' => ['nullable', 'date_format:Y-m-d'],
            'candidate_person_id' => ['nullable', 'uuid'],
            'candidate_name' => ['nullable', 'string', 'max:160'],
            'political_organization_id' => ['nullable', 'uuid'],
            'scope_level' => ['nullable', 'string', Rule::in([ElectoralCampaign::SCOPE_DEPARTMENT, ElectoralCampaign::SCOPE_MUNICIPALITY, ElectoralCampaign::SCOPE_COMUNA])],
            'territorio_ids' => ['nullable', 'array', 'max:200'],
            'territorio_ids.*' => ['uuid'],
            'factor_votos' => ['nullable', 'numeric', "between:{$f['factor_votos']['min']},{$f['factor_votos']['max']}", 'decimal:0,2'],
            'factor_registro' => ['nullable', 'numeric', "between:{$f['factor_registro']['min']},{$f['factor_registro']['max']}", 'decimal:0,2'],
            'votos_por_lider' => ['nullable', 'integer', "between:{$f['votos_por_lider']['min']},{$f['votos_por_lider']['max']}"],
        ];
    }

    public function messages(): array
    {
        $anio = config('campanas_electorales.anio');

        return [
            'name.required' => 'Escribe el nombre.',
            'name.max' => 'El nombre puede tener hasta 120 letras.',
            'cargo.required' => 'Elige el cargo.',
            'cargo.in' => 'Elige el cargo.',
            'election_year.*' => "Escribe un año entre {$anio['min']} y {$anio['max']}.",
            'election_date.*' => 'Escribe una fecha válida.',
            'candidate_person_id.*' => 'Elige un candidato de la lista.',
            'candidate_name.max' => 'El nombre del candidato puede tener hasta 160 letras.',
            'political_organization_id.*' => 'Elige un partido de la lista.',
            'scope_level.*' => 'Ese alcance no corresponde al cargo.',
            'territorio_ids.*' => 'Elige el territorio de la lista.',
            'territorio_ids.*.*' => 'Elige el territorio de la lista.',
            'factor_votos.*' => 'Escribe un número entre 0,50 y 3,00.',
            'factor_registro.*' => 'Escribe un número entre 0,10 y 3,00.',
            'votos_por_lider.*' => 'Escribe un número entero entre 5 y 1.000.',
        ];
    }

    /**
     * The campaign's columns and its territory units, after the rules that need the database.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    public function datos(ElectoralCampaignAccess $access, string $grupoId): array
    {
        $v = $this->validated();
        $c = $this->campana;
        $errores = [];

        $name = trim(preg_replace('/\s+/u', ' ', (string) $v['name']));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre.']);
        }
        $duplicada = ElectoralCampaign::query()->where('campaign_id', $grupoId)->where('status', ElectoralCampaign::ACTIVE)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($c, fn ($q) => $q->whereKeyNot($c->id))
            ->exists();
        if ($duplicada) {
            $errores['name'] = 'Ya tienes una campaña activa con ese nombre.';
        }

        if (! empty($v['election_date']) && (int) substr($v['election_date'], 0, 4) !== (int) $v['election_year']) {
            $errores['election_date'] = 'La fecha debe ser del año de la elección.';
        }

        $personId = $v['candidate_person_id'] ?? null;
        if ($personId !== null) {
            if (! $access->campaign()->seesElectoralResults()) {
                $errores['candidate_person_id'] = 'Tu grupo no tiene acceso a resultados electorales: escribe el nombre del candidato.';
            } elseif (! DB::table('persons')->where('id', $personId)->whereNull('deleted_at')->whereNull('merged_into_id')->exists()) {
                $errores['candidate_person_id'] = 'Elige un candidato de la lista.';
            }
        }
        $partyId = $v['political_organization_id'] ?? null;
        if ($partyId !== null && ! DB::table('political_organizations')->where('id', $partyId)->whereNull('merged_into_id')->exists()) {
            $errores['political_organization_id'] = 'Elige un partido de la lista.';
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $scope = $v['scope_level'] ?? ElectoralCampaign::CARGOS[$v['cargo']]['scopes'][0];
        $territorio = $access->territorio()->normalizar($v['cargo'], $scope, $v['territorio_ids'] ?? []);
        $candidateName = trim((string) ($v['candidate_name'] ?? ''));
        $f = config('campanas_electorales.factores');
        $factor = fn (string $key) => array_key_exists($key, $v) && $v[$key] !== null
            ? $v[$key]
            : ($c ? $c->{$key} : $f[$key]['defecto']);

        return [[
            'name' => $name,
            'cargo' => $v['cargo'],
            'election_year' => (int) $v['election_year'],
            'election_date' => $v['election_date'] ?? null,
            'candidate_person_id' => $personId,
            'candidate_name' => $personId === null && $candidateName !== '' ? $candidateName : null,
            'political_organization_id' => $partyId,
            'scope_level' => $scope,
            'factor_votos' => round((float) $factor('factor_votos'), 2),
            'factor_registro' => round((float) $factor('factor_registro'), 2),
            'votos_por_lider' => (int) $factor('votos_por_lider'),
        ], $territorio];
    }
}
