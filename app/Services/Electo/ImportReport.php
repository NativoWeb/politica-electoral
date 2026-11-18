<?php

namespace App\Services\Electo;

// Counters of an Electo import. Holds NO personal data: only counts, field names, DIVIPOLA codes and Electo ids.
class ImportReport
{
    public array $counts = [];

    public array $contradicciones = [];

    public array $flags = [];

    public array $municipiosNoReconocidos = [];

    public array $porCompletar = [];

    public array $tiempos = [];

    public array $avisos = [];

    public function __construct(public readonly string $runId, public readonly bool $dryRun, public readonly array $partes) {}

    public function count(string $entidad, string $accion, ?string $motivo = null, int $n = 1): void
    {
        $key = $motivo ? "{$accion}:{$motivo}" : $accion;
        $this->counts[$entidad][$key] = ($this->counts[$entidad][$key] ?? 0) + $n;
    }

    public function contradiccion(string $campo, int $n = 1): void
    {
        $this->contradicciones[$campo] = ($this->contradicciones[$campo] ?? 0) + $n;
    }

    public function flag(string $flag, int $n = 1): void
    {
        $this->flags[$flag] = ($this->flags[$flag] ?? 0) + $n;
    }

    // A record left with an empty field because Electo's catalog is missing (tabla.campo => records).
    public function porCompletar(string $tabla, string $campo): void
    {
        $this->porCompletar["{$tabla}.{$campo}"] = ($this->porCompletar["{$tabla}.{$campo}"] ?? 0) + 1;
    }

    public function municipio(string $archivo, string $code): void
    {
        $this->municipiosNoReconocidos[$archivo][$code] = ($this->municipiosNoReconocidos[$archivo][$code] ?? 0) + 1;
    }

    public function aviso(string $texto): void
    {
        $this->avisos[] = $texto;
    }

    public function toArray(): array
    {
        foreach ($this->municipiosNoReconocidos as &$codes) {
            arsort($codes);
        }
        ksort($this->contradicciones);
        ksort($this->flags);
        ksort($this->porCompletar);

        return [
            'run_id' => $this->runId,
            'modo' => $this->dryRun ? 'dry-run (todo revertido)' : 'carga real',
            'partes' => $this->partes,
            'conteos' => $this->counts,
            'contradicciones_por_campo' => $this->contradicciones,
            'marcas_de_revision' => $this->flags,
            'por_completar' => $this->porCompletar,
            'municipios_no_reconocidos' => $this->municipiosNoReconocidos,
            'tiempos_segundos' => $this->tiempos,
            'avisos' => $this->avisos,
        ];
    }

    public function write(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
