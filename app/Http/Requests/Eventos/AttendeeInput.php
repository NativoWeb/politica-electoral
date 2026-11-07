<?php

namespace App\Http\Requests\Eventos;

use App\Support\Cedula;
use Illuminate\Support\Str;

// Fields of an attendee registration, shared by the public QR form and the operator mode: both normalize
// and validate the same way before RegisterAttendee runs.
class AttendeeInput
{
    public const FIELDS = [
        'client_uuid', 'nombres', 'apellidos', 'cedula', 'celular',
        'barrio', 'fijo', 'fecha_nacimiento', 'direccion',
    ];

    public static function normalize(array $input): array
    {
        $text = fn ($v) => is_string($v) ? (trim(preg_replace('/\s+/u', ' ', $v)) ?: null) : $v;

        foreach (['nombres', 'apellidos', 'barrio', 'direccion'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $text($input[$field]);
            }
        }
        if (isset($input['cedula']) && is_string($input['cedula'])) {
            $input['cedula'] = Cedula::normalize($input['cedula']) ?: null;
        }
        foreach (['celular', 'fijo'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = self::phone($input[$field]);
            }
        }
        foreach (['fijo', 'fecha_nacimiento', 'direccion'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }

        return $input;
    }

    // Digits only, without the +57 country code.
    public static function phone(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }

        return $digits !== '' ? $digits : null;
    }

    public static function barrioKey(?string $barrio): ?string
    {
        if ($barrio === null || trim($barrio) === '') {
            return null;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($barrio)))), 120, '');
    }

    public static function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'cedula' => ['required', 'string', 'regex:/^\d{5,12}$/'],
            'celular' => ['required', 'string', 'regex:/^3\d{9}$/'],
            'barrio' => ['required', 'string', 'max:120'],
            'fijo' => ['nullable', 'string', 'regex:/^\d{7,10}$/'],
            'fecha_nacimiento' => ['bail', 'nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/', self::existingDate(...), 'after:1900-01-01', 'before_or_equal:'.now('America/Bogota')->subYears(18)->toDateString()],
            'direccion' => ['nullable', 'string', 'max:255'],
        ];
    }

    // A well-formed date that is not on the calendar (2026-02-31) gets its own message, not the format one.
    public static function existingDate(string $attribute, mixed $value, \Closure $fail): void
    {
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (! checkdate($month, $day, $year)) {
            $fail('Esa fecha no existe.');
        }
    }

    public static function messages(): array
    {
        return [
            'nombres.required' => 'Escriba los nombres.',
            'nombres.max' => 'Los nombres no pueden tener más de 100 caracteres.',
            'apellidos.required' => 'Escriba los apellidos.',
            'apellidos.max' => 'Los apellidos no pueden tener más de 100 caracteres.',
            'cedula.required' => 'Escriba el número de cédula.',
            'cedula.regex' => 'La cédula debe tener solo números (entre 5 y 12 dígitos).',
            'celular.required' => 'Escriba el número de celular.',
            'celular.regex' => 'El celular debe tener 10 dígitos y empezar por 3.',
            'barrio.required' => 'Escriba el barrio.',
            'barrio.max' => 'El barrio no puede tener más de 120 caracteres.',
            'fijo.regex' => 'El teléfono fijo debe tener entre 7 y 10 dígitos.',
            'fecha_nacimiento.regex' => 'La fecha de nacimiento debe tener el formato AAAA-MM-DD.',
            'fecha_nacimiento.before_or_equal' => 'El registro es solo para mayores de 18 años.',
            'fecha_nacimiento.after' => 'Revise la fecha de nacimiento.',
            'direccion.max' => 'La dirección no puede tener más de 255 caracteres.',
            'client_uuid.required' => 'Falta el identificador del registro. Recargue la página.',
            'client_uuid.uuid' => 'Identificador de registro inválido. Recargue la página.',
            '*.string' => 'El campo :attribute debe ser un texto.',
        ];
    }

    public static function attributes(): array
    {
        return [
            'client_uuid' => 'identificador del registro',
            'nombres' => 'nombres',
            'apellidos' => 'apellidos',
            'cedula' => 'cédula',
            'celular' => 'celular',
            'barrio' => 'barrio',
            'fijo' => 'teléfono fijo',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'direccion' => 'dirección',
        ];
    }
}
