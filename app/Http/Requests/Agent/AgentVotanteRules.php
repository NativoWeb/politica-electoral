<?php

namespace App\Http\Requests\Agent;

use Illuminate\Validation\Rule;

// Fields the agent may write on a votante; cedula, municipio and nombre are create-only.
final class AgentVotanteRules
{
    public const NIVELES_CONFIANZA = ['sin_llamar', 'contactado', 'confirmado', 'comprometido', 'no_responde'];

    public const FLAGS = ['militante', 'autoriza_datos', 'verificado', 'gran_elector',
        'empresario', 'reservista', 'funcionario', 'exfuncionario'];

    public static function editable(): array
    {
        $rules = [
            'nivel_confianza' => ['sometimes', 'string', Rule::in(self::NIVELES_CONFIANZA)],
            'observacion' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'puesto_votacion' => ['sometimes', 'nullable', 'string', 'max:200'],
            'mesa_votacion' => ['sometimes', 'nullable', 'string', 'max:20'],
            'municipio_votacion' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
        foreach (self::FLAGS as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
