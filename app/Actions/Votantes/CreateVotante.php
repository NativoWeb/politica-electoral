<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Campaign;
use App\Models\Lider;
use App\Support\Cedula;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Shared by the web (VotanteController) and the WhatsApp agent; territory is checked for the current user.
// The votante always goes to the user's own campaign, and its cédula only has to be unique there.
class CreateVotante
{
    use ScopesTerritory;

    public function handle(array $data): Lider
    {
        $this->authorizeGeo($data['municipio_id']);
        $data = Cedula::normalizeFields($data);

        $campaignId = $this->campaign()->ownId() ?? Campaign::DEFAULT_ID;

        $cedula = $data['cedula'] ?? null;
        if ($cedula !== null && $cedula !== '') {
            $existente = DB::table('lideres')->where('campaign_id', $campaignId)->where('cedula', $cedula)->first();
            if ($existente) {
                throw new BusinessRuleException($this->duplicateCedulaMessage($cedula, $existente));
            }
        }

        $mun = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.id', $data['municipio_id'])
            ->select('g.canonical_name', 'prov.canonical_name as provincia')
            ->first();

        try {
            return DB::transaction(fn () => tap(new Lider([
                'nombre' => $data['nombre'],
                'municipio' => $mun->canonical_name ?? '',
                'provincia' => $mun->provincia ?? '',
                'geographic_unit_id' => $data['municipio_id'],
                'cargo' => $data['cargo'] ?? '',
                'tipo' => $data['tipo'] ?? 'directorio',
                'telefono' => $data['telefono'] ?? null,
                'cedula' => $data['cedula'] ?? null,
                'email' => $data['email'] ?? null,
                'observacion' => $data['observacion'] ?? null,
                'partido' => $data['partido'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'barrio' => $data['barrio'] ?? null,
                'zona' => $data['zona'] ?? null,
                'profesion' => $data['profesion'] ?? null,
                'destacado' => false,
                'votos' => 0,
                'tipo_documento' => $data['tipo_documento'] ?? null,
                'fecha_expedicion_doc' => $data['fecha_expedicion_doc'] ?? null,
                'genero' => $data['genero'] ?? null,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'estado_civil' => $data['estado_civil'] ?? null,
                'referente_documento' => $data['referente_documento'] ?? null,
                'referente_nombre' => $data['referente_nombre'] ?? null,
                'referente_apellido' => $data['referente_apellido'] ?? null,
                'departamento_votacion' => $data['departamento_votacion'] ?? null,
                'municipio_votacion' => $data['municipio_votacion'] ?? null,
                'puesto_votacion' => $data['puesto_votacion'] ?? null,
                'direccion_puesto' => $data['direccion_puesto'] ?? null,
                'mesa_votacion' => $data['mesa_votacion'] ?? null,
                'militante' => $data['militante'] ?? false,
                'autoriza_datos' => $data['autoriza_datos'] ?? false,
                'verificado' => $data['verificado'] ?? false,
                'fallecido' => $data['fallecido'] ?? false,
                'empresario' => $data['empresario'] ?? false,
                'reservista' => $data['reservista'] ?? false,
                'funcionario' => $data['funcionario'] ?? false,
                'exfuncionario' => $data['exfuncionario'] ?? false,
                'gran_elector' => $data['gran_elector'] ?? false,
                'nivel_confianza' => $data['nivel_confianza'] ?? 'sin_llamar',
                'convenio' => $data['convenio'] ?? null,
                'escolaridad' => $data['escolaridad'] ?? null,
                'tipo_hoja_vida' => $data['tipo_hoja_vida'] ?? null,
                'fecha_registro_hv' => $data['fecha_registro_hv'] ?? null,
                'facebook' => $data['facebook'] ?? null,
                'twitter' => $data['twitter'] ?? null,
                'instagram' => $data['instagram'] ?? null,
            ]), fn (Lider $lider) => $lider->forceFill(['campaign_id' => $campaignId])->save()));
        } catch (UniqueConstraintViolationException) {
            // Same cedula inserted concurrently between the check above and the insert.
            throw new BusinessRuleException("Ya existe un líder con cédula {$cedula}.");
        }
    }
}
