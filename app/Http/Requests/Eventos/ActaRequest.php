<?php

namespace App\Http\Requests\Eventos;

use App\Rules\NombreSinNumeros;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Body of POST /eventos/{id}/actas and PUT /eventos/{id}/actas/{acta} (docs/eventos-api.md §14).
class ActaRequest extends FormRequest
{
    use AuthorizesEventActa;

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'sitio' => ['nullable', 'string', 'max:255'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i'],
            'introduccion' => ['nullable', 'string', 'max:5000'],
            'orden_dia' => ['nullable', 'array', 'max:60'],
            'orden_dia.*' => ['nullable', 'string', 'max:1000'],
            'desarrollo' => ['nullable', 'array', 'max:60'],
            'desarrollo.*.titulo' => ['nullable', 'string', 'max:1000'],
            'desarrollo.*.texto' => ['nullable', 'string', 'max:20000'],
            'participantes' => ['nullable', 'array', 'max:300'],
            'participantes.*.id' => ['nullable', 'uuid'],
            'participantes.*.nombre' => ['required', 'string', 'max:200', $this->nombreDeFicha(...)],
            'participantes.*.perfil' => ['nullable', 'string', 'max:200'],
            'participantes.*.municipio' => ['nullable', 'string', 'max:120'],
            'participantes.*.telefono' => ['nullable', 'string', 'max:60'],
            'participantes.*.cedula' => ['nullable', 'string', 'max:20'],
            'participantes.*.lider_id' => ['nullable', 'uuid'],
            'participantes.*.attendee_id' => ['nullable', 'uuid'],
            'participantes.*.sugerencia_rechazada' => ['nullable', 'uuid'],
            'compromisos' => ['nullable', 'array', 'max:150'],
            'compromisos.*.id' => ['nullable', 'uuid'],
            'compromisos.*.descripcion' => ['required', 'string', 'max:5000'],
            'compromisos.*.fecha_limite' => ['nullable', 'date_format:Y-m-d'],
            'compromisos.*.recordar_el' => ['nullable', 'date_format:Y-m-d'],
            'compromisos.*.responsables' => ['nullable', 'array', 'max:15'],
            'compromisos.*.responsables.*.nombre' => ['required', 'string', 'max:200'],
            'compromisos.*.responsables.*.telefono' => ['nullable', 'string', 'max:60'],
            'compromisos.*.responsables.*.lider_id' => ['nullable', 'uuid'],
            'compromisos.*.responsables.*.sugerencia_rechazada' => ['nullable', 'uuid'],
            'cierre' => ['nullable', 'string', 'max:2000'],
        ];
    }

    // A participant typed by hand becomes a ficha when the acta is confirmed, so its name follows the ficha's rule. One
    // linked to a ficha or to an attendee, or a saved row sent back with its name unchanged, is not checked again.
    public function nombreDeFicha(string $attribute, mixed $value, Closure $fail): void
    {
        $participante = $this->input('participantes.'.(explode('.', $attribute)[1] ?? ''));
        if (! is_array($participante) || filled($participante['lider_id'] ?? null) || filled($participante['attendee_id'] ?? null)) {
            return;
        }
        $id = $participante['id'] ?? null;
        // Only a row of this same acta counts as «saved»: an id of another acta does not skip the rule.
        $acta = $this->route('acta');
        $actual = is_string($id) && Str::isUuid($id) && is_string($acta) && Str::isUuid($acta)
            ? DB::table('event_acta_participantes')->where('id', $id)->where('acta_id', $acta)->value('nombre')
            : null;
        (new NombreSinNumeros($actual))->validate($attribute, $value, $fail);
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'Escriba el título del acta.',
            'participantes.*.nombre.required' => 'Cada participante necesita el nombre (quite las filas vacías).',
            'compromisos.*.descripcion.required' => 'Cada compromiso necesita la descripción (quite las filas vacías).',
            'compromisos.*.responsables.*.nombre.required' => 'Cada responsable necesita el nombre (quite los vacíos).',
            'hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'hora_fin.date_format' => 'La hora de fin debe tener el formato HH:MM.',
        ];
    }
}
