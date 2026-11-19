<?php

namespace App\Support\Configuracion;

use App\Http\Controllers\ExportController;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Services\Eventos\Actas\CompromisoAlertas;

// Business values the superadmin may change in Centro de configuración (/admin/configuracion/ajustes). The default
// lives here (or in config, for the ones that already had an env var) and the table ajustes keeps only what was
// changed. Security rules (grupo político and territory isolation, Ley 1581 consent and retention, session and
// password times, rate limits) are deliberately NOT here: a wrong value would expose personal data.
class AjusteRegistry
{
    public const GRUPOS = [
        'eventos' => 'Eventos y asistentes',
        'compromisos' => 'Compromisos de las actas',
        'mapa' => 'Mapa político',
        'exportaciones' => 'Exportaciones',
        'whatsapp' => 'WhatsApp',
        'modulos' => 'Módulos',
    ];

    // tipo: int | bool | time | text | select. time = "HH:MM" on quarters of an hour (the scheduler checks every 15 min).
    public static function definiciones(): array
    {
        return [
            'asistentes.edad_minima' => [
                'grupo' => 'eventos', 'tipo' => 'int', 'min' => AttendeeInput::MIN_AGE, 'max' => 18,
                'default' => AttendeeInput::MIN_AGE, 'unidad' => 'años',
                'etiqueta' => 'Edad mínima para registrarse en un evento',
                'descripcion' => 'Formulario del QR, operador e importaciones. No baja de 14: menores de esa edad no dan su propia autorización de datos.',
            ],
            'compromisos.hora_resumen' => [
                'grupo' => 'compromisos', 'tipo' => 'time', 'default' => '07:00',
                'etiqueta' => 'Hora del correo diario de compromisos',
                'descripcion' => 'Hora de Bogotá, en cuartos de hora (07:00, 07:15...).',
            ],
            'compromisos.dias_por_vencer' => [
                'grupo' => 'compromisos', 'tipo' => 'int', 'min' => 1, 'max' => 30,
                'default' => CompromisoAlertas::DIAS, 'unidad' => 'días',
                'etiqueta' => 'Días para avisar que un compromiso está por vencer',
                'descripcion' => 'Alertas de la pantalla de compromisos y del correo diario.',
            ],
            'mapa.tamano_pagina' => [
                'grupo' => 'mapa', 'tipo' => 'int', 'min' => 50, 'max' => 500,
                'default' => MapaPoliticoController::SECTION_LIMIT, 'unidad' => 'fichas',
                'etiqueta' => 'Fichas por sección antes de "Ver más"',
                'descripcion' => 'Cuántas fichas carga cada sección del Mapa político de una vez.',
            ],
            'mapa.export_max_filas' => [
                'grupo' => 'exportaciones', 'tipo' => 'int', 'min' => 100, 'max' => MapaPoliticoController::EXPORT_MAX_ROWS,
                'default' => MapaPoliticoController::EXPORT_MAX_ROWS, 'unidad' => 'filas',
                'etiqueta' => 'Máximo de filas por sección al exportar el Mapa político',
                'descripcion' => 'Excel y PDF del Mapa político. Más filas tarda más en generarse.',
            ],
            'votantes.export_max_filas' => [
                'grupo' => 'exportaciones', 'tipo' => 'int', 'min' => 1000, 'max' => 100000,
                'default' => ExportController::VOTANTES_EXCEL_MAX_ROWS, 'unidad' => 'filas',
                'etiqueta' => 'Máximo de filas al exportar Votantes a Excel',
                'descripcion' => 'Por encima del máximo el archivo avisa que se cortó.',
            ],
            'whatsapp.max_destinatarios' => [
                'grupo' => 'whatsapp', 'tipo' => 'int', 'min' => 1, 'max' => 50000,
                'default' => (int) config('services.n8n.whatsapp_max_recipients', 5000), 'unidad' => 'personas',
                'etiqueta' => 'Máximo de destinatarios por envío de WhatsApp',
                'descripcion' => 'Un envío con más personas pide dividirlo con filtros.',
            ],
            'modulos.campanas_electorales' => [
                'grupo' => 'modulos', 'tipo' => 'bool',
                'default' => (bool) config('features.campanas_electorales.enabled', false),
                'etiqueta' => 'Módulo Campañas electorales visible',
                'descripcion' => 'Apagado, el módulo no existe para nadie. Encendido, lo ven quienes tengan su permiso (y, si hay usuarios beta configurados en el servidor, solo ellos).',
            ],
        ];
    }

    public static function existe(string $clave): bool
    {
        return array_key_exists($clave, self::definiciones());
    }

    public static function definicion(string $clave): array
    {
        return self::definiciones()[$clave] + ['clave' => $clave];
    }

    // Coerces a stored or submitted value to its type; null when it cannot be one.
    public static function normalizar(string $clave, mixed $valor): mixed
    {
        $def = self::definicion($clave);

        return match ($def['tipo']) {
            'int' => is_numeric($valor) && (int) $valor == $valor ? (int) $valor : null,
            'bool' => is_bool($valor) ? $valor : (in_array($valor, [1, 0, '1', '0', 'true', 'false'], true) ? filter_var($valor, FILTER_VALIDATE_BOOLEAN) : null),
            'time' => is_string($valor) && preg_match('/^([01]\d|2[0-3]):(00|15|30|45)$/', $valor) ? $valor : null,
            default => is_scalar($valor) ? (string) $valor : null,
        };
    }

    // Laravel rules of the value of one ajuste.
    public static function reglas(string $clave): array
    {
        $def = self::definicion($clave);

        return match ($def['tipo']) {
            'int' => ['required', 'integer', 'min:'.$def['min'], 'max:'.$def['max']],
            'bool' => ['required', 'boolean'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'],
            'select' => ['required', 'string', 'in:'.implode(',', array_keys($def['opciones'] ?? []))],
            default => ['required', 'string', 'max:'.($def['max'] ?? 500)],
        };
    }
}
