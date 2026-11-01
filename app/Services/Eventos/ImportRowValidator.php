<?php

namespace App\Services\Eventos;

use App\Http\Requests\Eventos\AttendeeInput;
use App\Support\Cedula;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// Turns a sheet row into attendee fields and validates them with the same rules as the QR and the operator
// (AttendeeInput), with the differences of a paper sign-in sheet: cédula of 5 to 10 digits, the barrio may be empty,
// dates come as DD/MM/AAAA (or as an Excel date) and "Autorizó" says whether the person signed the authorization.
// "Autorizó" is not editable in the preview: only what the paper sheet says counts.
class ImportRowValidator
{
    public const EDITABLE = ['nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio'];

    // A number Excel showed in scientific notation: "1,09877E+09" lost digits, "1.098765432E9" did not.
    private const SCIENTIFIC = '/^(\d+)(?:[.,](\d+))?[eE]\+?(\d+)$/';

    private const SCIENTIFIC_MESSAGES = [
        'cedula' => 'La cédula llegó en notación científica (:valor); escríbala completa.',
        'celular' => 'El celular llegó en notación científica (:valor); escríbalo completo.',
        'fijo' => 'El teléfono fijo llegó en notación científica (:valor); escríbalo completo.',
    ];

    private const YES = ['si', 's', 'x', '1', 'true', 'verdadero', 'yes', 'autorizo', 'acepto', 'ok'];

    private const NO = ['no', 'n', '0', 'false', 'falso'];

    // Cells (by column index) → mapped fields as text, before validation. Nothing is dropped: a wrong value stays so
    // the preview can show it next to its error.
    public function map(array $cells, array $mapping): array
    {
        $get = fn (string $field) => isset($mapping[$field]) && $mapping[$field] !== null ? ($cells[$mapping[$field]] ?? null) : null;

        $nombres = self::text($get('nombres'));
        $apellidos = self::text($get('apellidos'));
        $completo = self::text($get('nombre_completo'));
        if ($completo !== null && $nombres === null && $apellidos === null) {
            [$nombres, $apellidos] = self::splitFullName($completo);
        }

        return [
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'cedula' => self::number($get('cedula')),
            'celular' => self::number($get('celular')),
            'fijo' => self::number($get('fijo')),
            'fecha_nacimiento' => self::date($get('fecha_nacimiento')),
            'direccion' => self::text($get('direccion')),
            'barrio' => self::text($get('barrio')),
            'autoriza' => self::text($get('autoriza')),
        ];
    }

    // ['data' => normalized values ready for RegisterAttendee, 'errors' => [field => message]] (errors empty = valid).
    public function validate(array $mapped): array
    {
        $autoriza = self::yesNo($mapped['autoriza'] ?? null);
        $input = self::normalized($mapped);

        $rules = array_intersect_key(AttendeeInput::rules(), array_flip(['nombres', 'apellidos', 'celular', 'fijo', 'fecha_nacimiento', 'direccion']));
        $rules['cedula'] = ['required', 'string', 'regex:/^\d{5,10}$/'];
        $rules['barrio'] = ['nullable', 'string', 'max:120'];

        $validator = Validator::make($input, $rules, array_merge(AttendeeInput::messages(), [
            'cedula.regex' => 'La cédula debe tener solo números (entre 5 y 10 dígitos).',
            'fecha_nacimiento.regex' => 'La fecha de nacimiento debe tener el formato DD/MM/AAAA.',
        ]), AttendeeInput::attributes());

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0];
        }
        foreach (self::SCIENTIFIC_MESSAGES as $field => $message) {
            $value = $mapped[$field] ?? null;
            if (is_string($value) && preg_match(self::SCIENTIFIC, trim($value))) {
                $errors[$field] = str_replace(':valor', trim($value), $message);
            }
        }

        return [
            'data' => array_merge($input, ['autoriza' => $autoriza === true]),
            'errors' => $errors,
        ];
    }

    // The values of a row that already passed validate(), ready for RegisterAttendee (no validation again).
    public static function data(array $mapped): array
    {
        return array_merge(self::normalized($mapped), ['autoriza' => self::yesNo($mapped['autoriza'] ?? null) === true]);
    }

    private static function normalized(array $mapped): array
    {
        $fecha = self::isoDate($mapped['fecha_nacimiento'] ?? null);

        return AttendeeInput::normalize([
            'nombres' => $mapped['nombres'] ?? null,
            'apellidos' => $mapped['apellidos'] ?? null,
            'cedula' => $mapped['cedula'] ?? null,
            'celular' => $mapped['celular'] ?? null,
            'fijo' => $mapped['fijo'] ?? null,
            'fecha_nacimiento' => $fecha ?? $mapped['fecha_nacimiento'] ?? null,
            'direccion' => $mapped['direccion'] ?? null,
            'barrio' => $mapped['barrio'] ?? null,
        ]);
    }

    // The cédula of a row as it will be stored (digits only), to compare rows among themselves and with the database.
    public static function cedulaOf(array $mapped): ?string
    {
        $value = Cedula::normalize($mapped['cedula'] ?? null);

        return $value === '' ? null : $value;
    }

    // A value typed in the preview, cleaned the same way as a cell of the file.
    public static function edited(string $field, mixed $value): ?string
    {
        return match ($field) {
            'cedula', 'celular', 'fijo' => self::number(is_string($value) ? $value : null),
            default => self::text(is_string($value) ? $value : null),
        };
    }

    // Nombres get one word with two or three words in total, two words with four or more; the rest are apellidos.
    public static function splitFullName(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name));
        $count = count($words);
        if ($count < 2) {
            return [$words[0] ?? null, null];
        }
        $take = $count >= 4 ? 2 : 1;

        return [implode(' ', array_slice($words, 0, $take)), implode(' ', array_slice($words, $take))];
    }

    // Numbers typed in Excel (1098765432 read as a float, "1098765432.0" or "1.098765432E9" from a CSV) back to
    // their digits. Scientific notation is only rebuilt when it is exact (as many decimals as the exponent): a rounded
    // "1,09877E+09" stays as it is and validate() asks for the full number. Thousands dots ("1.098.765.432") are left
    // to Cedula::normalize. A value with letters stays as it is.
    public static function number(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : (string) $value;
        }
        $text = trim((string) $value);
        if (preg_match(self::SCIENTIFIC, $text, $m)) {
            $decimals = $m[2] ?? '';

            return strlen($decimals) === (int) $m[3] && strlen($m[1].$decimals) <= 15 ? ltrim($m[1].$decimals, '0') : $text;
        }
        // "1098765432.0": five or more digits before the dot cannot be a thousands group.
        if (preg_match('/^(\d{5,})[.,]0+$/', $text, $m)) {
            return $m[1];
        }
        $cedula = Cedula::normalize($text);

        return $cedula === '' ? null : $cedula;
    }

    // An Excel date (serial number) becomes DD/MM/AAAA, like the text the template asks for.
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            if ($value > 0 && $value < 80000) {
                return ExcelDate::excelToDateTimeObject($value)->format('d/m/Y');
            }

            return (string) $value;
        }

        return trim((string) $value);
    }

    // DD/MM/AAAA, D-M-AAAA, DD.MM.AAAA or AAAA-MM-DD → AAAA-MM-DD; anything else → null (the validator reports it).
    public static function isoDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim(preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?.*$/', '', $value));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    // true, false or 'invalid'. An empty cell is a NO; only true lets a row be imported.
    public static function yesNo(?string $value): bool|string
    {
        if ($value === null || trim($value) === '') {
            return false;
        }
        $key = ImportColumns::normalize($value);
        if (in_array($key, self::YES, true)) {
            return true;
        }

        return in_array($key, self::NO, true) ? false : 'invalid';
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = sprintf('%.0f', $value);
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }
}
