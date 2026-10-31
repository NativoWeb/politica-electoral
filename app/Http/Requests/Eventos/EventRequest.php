<?php

namespace App\Http\Requests\Eventos;

use App\Support\CurrentCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Create (POST) and edit (PUT) an event. On PUT every field is optional and barrios, organizadores and figuras
// replace their lists only when sent. Campaign, status and public token never come from the request.
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $blankToNull = fn ($v) => is_string($v) && trim($v) === '' ? null : $v;
        foreach (['descripcion', 'ends_at', 'registration_closes_at'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $blankToNull($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        // On PUT a field may be left out, but when it is sent it cannot be empty (an empty string arrives as null).
        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $own = app(CurrentCampaign::class)->ownId();
        // Without starts_at in a PUT the controller compares against the stored one.
        $afterStart = array_filter(['sometimes', 'nullable', 'date', $this->has('starts_at') ? 'after_or_equal:starts_at' : null]);

        return [
            'nombre' => [...$required, 'string', 'max:200'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'event_type_id' => [...$required, 'uuid', Rule::exists('event_types', 'id')->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('campaign_id')->when($own, fn ($q) => $q->orWhere('campaign_id', $own)))],
            'starts_at' => [...$required, 'date'],
            'ends_at' => $afterStart,
            'registration_closes_at' => $afterStart,
            'sitio_encuentro' => [...$required, 'string', 'max:255'],
            'municipio_id' => [...$required, 'uuid', Rule::exists('geographic_units', 'id')->where('type', 'municipality')],
            'barrios' => ['sometimes', 'array', 'max:50'],
            'barrios.*' => ['required', 'string', 'max:120'],
            'organizadores' => ['sometimes', 'array', 'max:50'],
            'organizadores.*.lider_id' => ['nullable', 'uuid'],
            'organizadores.*.nombre' => ['required', 'string', 'max:200'],
            'organizadores.*.cedula' => ['nullable', 'string', 'max:20'],
            'organizadores.*.telefono' => ['nullable', 'string', 'max:30'],
            'organizadores.*.rol' => ['nullable', 'string', 'max:100'],
            'figuras' => ['sometimes', 'array', 'max:20'],
            'figuras.*.person_id' => ['nullable', 'uuid', Rule::exists('persons', 'id')],
            'figuras.*.nombre' => ['required', 'string', 'max:200'],
            'figuras.*.cargo' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Escriba el nombre del evento.',
            'nombre.max' => 'El nombre del evento no puede tener más de 200 caracteres.',
            'descripcion.max' => 'La descripción no puede tener más de 5000 caracteres.',
            'event_type_id.required' => 'Elija el tipo de evento.',
            'event_type_id.exists' => 'El tipo de evento no existe.',
            'starts_at.required' => 'Indique la fecha y hora de inicio del evento.',
            'starts_at.date' => 'La fecha y hora de inicio no es válida.',
            'ends_at.date' => 'La fecha y hora de fin no es válida.',
            'ends_at.after_or_equal' => 'La hora de fin no puede ser antes del inicio.',
            'registration_closes_at.date' => 'La fecha de cierre del registro no es válida.',
            'registration_closes_at.after_or_equal' => 'El cierre del registro no puede ser antes del inicio.',
            'sitio_encuentro.required' => 'Indique el sitio de encuentro.',
            'sitio_encuentro.max' => 'El sitio de encuentro no puede tener más de 255 caracteres.',
            'municipio_id.required' => 'Elija el municipio.',
            'municipio_id.exists' => 'El municipio no existe.',
            'barrios.max' => 'Puede indicar como máximo 50 barrios.',
            'barrios.*.required' => 'Ningún barrio puede quedar vacío.',
            'barrios.*.max' => 'Cada barrio puede tener como máximo 120 caracteres.',
            'organizadores.max' => 'Puede indicar como máximo 50 organizadores.',
            'organizadores.*.nombre.required' => 'Cada organizador necesita un nombre.',
            'figuras.max' => 'Puede indicar como máximo 20 figuras.',
            'figuras.*.nombre.required' => 'Cada figura necesita un nombre.',
            'figuras.*.person_id.exists' => 'La persona elegida no existe.',
            '*.uuid' => 'El valor elegido en :attribute no es válido.',
            '*.string' => 'El campo :attribute debe ser un texto.',
            '*.array' => 'El campo :attribute debe ser una lista.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre' => 'nombre del evento',
            'descripcion' => 'descripción',
            'event_type_id' => 'tipo de evento',
            'starts_at' => 'fecha y hora de inicio',
            'ends_at' => 'fecha y hora de fin',
            'registration_closes_at' => 'cierre del registro',
            'sitio_encuentro' => 'sitio de encuentro',
            'municipio_id' => 'municipio',
            'barrios' => 'barrios',
            'barrios.*' => 'barrio',
            'organizadores' => 'organizadores',
            'organizadores.*.lider_id' => 'líder organizador',
            'organizadores.*.nombre' => 'nombre del organizador',
            'organizadores.*.cedula' => 'cédula del organizador',
            'organizadores.*.telefono' => 'teléfono del organizador',
            'organizadores.*.rol' => 'rol del organizador',
            'figuras' => 'figuras',
            'figuras.*.person_id' => 'persona',
            'figuras.*.nombre' => 'nombre de la figura',
            'figuras.*.cargo' => 'cargo de la figura',
        ];
    }
}
