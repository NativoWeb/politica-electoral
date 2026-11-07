<?php

namespace Tests\Support;

use App\Services\Eventos\Ocr\OcrClient;
use App\Services\Eventos\Ocr\OcrException;
use App\Services\Eventos\Ocr\OcrResult;

// Stand-in for the Claude API in tests (they never call the real one): answers the queued pages in order (an array
// in the PlanillaPrompt schema, an OcrException to throw, or a callable(mime, page) returning either) and records
// every call without its content.
class FakeOcrClient implements OcrClient
{
    public array $calls = [];

    private array $queue = [];

    public function __construct(private ?\Closure $default = null) {}

    public function push(array|OcrException|\Closure ...$answers): self
    {
        array_push($this->queue, ...$answers);

        return $this;
    }

    public function read(string $content, string $mime, int $page, string $model): OcrResult
    {
        $this->calls[] = ['mime' => $mime, 'page' => $page, 'model' => $model, 'bytes' => strlen($content)];
        $answer = $this->queue === [] ? $this->default : array_shift($this->queue);
        if ($answer instanceof \Closure) {
            $answer = $answer($mime, $page);
        }
        if ($answer === null) {
            throw new \LogicException('FakeOcrClient: no answer queued.');
        }
        if ($answer instanceof OcrException) {
            throw $answer;
        }

        return new OcrResult($answer, $model, 3000, 1500, 0, 0);
    }

    // A page answer: $rows are [numero, nombres, apellidos, cedula, celular, ...] overrides; every value with
    // confidence 0.98 unless $row['confianza'][field] says otherwise.
    public static function sheet(array $rows, array $extra = []): array
    {
        return array_merge([
            'es_planilla' => true,
            'evento_id' => null,
            'codigo_evento' => null,
            'pagina' => 1,
            'total_paginas' => 1,
            'paginas_documento' => 1,
            'filas' => array_map(fn (array $row) => self::row($row), $rows),
        ], $extra);
    }

    public static function row(array $values): array
    {
        $confidence = $values['confianza'] ?? [];
        $values = array_merge([
            'numero' => 1, 'nombres' => 'ANA MARÍA', 'apellidos' => 'PÉREZ GÓMEZ', 'cedula' => '1098765432', 'celular' => '3001234567',
            'fijo' => null, 'fecha_nacimiento' => '02/04/1980', 'direccion' => 'CALLE 1 # 2-3', 'barrio' => 'EL POBLADO',
            'firma_presente' => true,
        ], array_diff_key($values, ['confianza' => true]));

        $row = ['numero' => $values['numero']];
        foreach (['nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio', 'firma_presente'] as $field) {
            $row[$field] = ['valor' => $values[$field], 'confianza' => $confidence[$field] ?? 0.98];
        }

        return $row;
    }
}
