<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Support\Cedula;
use Illuminate\Support\Facades\DB;

// Two lider records of the same person: $dropId is folded into $keepId and deleted.
// The kept record's values win; it only takes what it lacks from the duplicate.
class MergeLideres
{
    use ScopesTerritory;

    // Rows pointing at a lider id, moved to the kept record.
    private const REFERENCES = [
        ['lider_contactos', 'lider_id'], ['lider_info_politica', 'lider_id'], ['lider_seguimientos', 'lider_id'],
        ['lider_municipios_influencia', 'lider_id'], ['nexos_familiares', 'person_id'],
        ['whatsapp_send_recipients', 'person_id'], ['favorites', 'favoritable_id'], ['recent_views', 'viewable_id'],
        ['taggables', 'taggable_id'],
    ];

    // Merged by hand below, or never copied.
    private const SPECIAL = ['id', 'nombre', 'cargo', 'observacion', 'telefono', 'cedula', 'votos', 'nivel_confianza',
        'municipio', 'provincia', 'geographic_unit_id', 'created_at', 'updated_at', 'is_active'];

    // Text attributes whose differing value in the duplicate is noted in the observation.
    private const NOTED = ['email' => 'correo', 'direccion' => 'dirección', 'barrio' => 'barrio', 'profesion' => 'profesión', 'partido' => 'partido'];

    // Yes/no attributes: true on either record stays true.
    private const FLAGS = ['destacado', 'militante', 'autoriza_datos', 'verificado', 'fallecido', 'empresario',
        'reservista', 'funcionario', 'exfuncionario', 'gran_elector'];

    // $cedula: the one typed in the form. When it is the duplicate's, it replaces a (mistyped) cédula of the kept record.
    public function handle(string $keepId, string $dropId, ?string $cedula = null): object
    {
        if ($keepId === $dropId) {
            throw new BusinessRuleException('No se puede unificar un registro consigo mismo.');
        }

        return DB::transaction(function () use ($keepId, $dropId, $cedula) {
            $keep = DB::table('lideres')->where('id', $keepId)->lockForUpdate()->first();
            $drop = DB::table('lideres')->where('id', $dropId)->lockForUpdate()->first();
            if (! $keep || ! $drop) {
                throw new BusinessRuleException('Uno de los registros ya no existe.', 404);
            }
            $this->authorizeGeo($keep->geographic_unit_id);
            $this->authorizeGeo($drop->geographic_unit_id);

            $keepCedula = Cedula::normalize($keep->cedula) ?: null;
            $dropCedula = Cedula::normalize($drop->cedula) ?: null;
            $replacesCedula = $dropCedula && $cedula === $dropCedula && $keepCedula !== $dropCedula;
            if ($keepCedula && $dropCedula && $keepCedula !== $dropCedula && ! $replacesCedula) {
                throw new BusinessRuleException('No se pueden unificar: los dos registros tienen cédulas distintas.');
            }

            $upd = $this->mergedValues($keep, $drop);
            if ($replacesCedula) {
                $upd['cedula'] = $dropCedula;
                if ($keepCedula) {
                    $upd['observacion'] = trim(($upd['observacion'] ?? (string) $keep->observacion)."\nCédula anterior: {$keep->cedula}.");
                }
            }

            foreach (self::REFERENCES as [$table, $column]) {
                $this->moveReferences($table, $column, $dropId, $keepId);
            }
            // The cédula is unique: free it before the kept record takes it.
            DB::table('lideres')->where('id', $dropId)->delete();
            if ($upd) {
                DB::table('lideres')->where('id', $keepId)->update($upd + ['updated_at' => now()]);
            }

            return DB::table('lideres')->where('id', $keepId)->first();
        });
    }

    private function mergedValues(object $keep, object $drop): array
    {
        $empty = fn ($v) => $v === null || $v === '';
        $upd = [];

        $tokens = [];
        foreach ([$keep->cargo, $drop->cargo] as $cargo) {
            foreach (explode(',', (string) $cargo) as $t) {
                $t = trim($t);
                if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $tokens), true)) {
                    $tokens[] = $t;
                }
            }
        }
        if (implode(', ', $tokens) !== (string) $keep->cargo) {
            $upd['cargo'] = implode(', ', $tokens);
        }

        $notes = array_values(array_unique(array_filter([trim((string) $keep->observacion), trim((string) $drop->observacion)])));
        $keepPhone = preg_replace('/\D/', '', (string) $keep->telefono);
        $dropPhone = preg_replace('/\D/', '', (string) $drop->telefono);
        if ($keepPhone === '' && $dropPhone !== '') {
            $upd['telefono'] = $drop->telefono;
        } elseif ($dropPhone !== '' && $dropPhone !== $keepPhone) {
            $notes[] = "Otro teléfono registrado: {$drop->telefono}.";
        }
        // The kept record wins; what the duplicate had different is written down instead of lost.
        foreach (self::NOTED as $col => $label) {
            $mine = trim((string) ($keep->$col ?? ''));
            $theirs = trim((string) ($drop->$col ?? ''));
            if ($mine !== '' && $theirs !== '' && mb_strtolower($mine) !== mb_strtolower($theirs)) {
                $notes[] = "Otro {$label} registrado: {$theirs}.";
            }
        }
        if (implode("\n", $notes) !== trim((string) $keep->observacion)) {
            $upd['observacion'] = implode("\n", $notes);
        }

        if ($empty($keep->cedula) && ! $empty($drop->cedula)) {
            $upd['cedula'] = Cedula::normalize($drop->cedula);
        }
        if ((int) $drop->votos > (int) $keep->votos) {
            $upd['votos'] = (int) $drop->votos;
        }
        if (($keep->nivel_confianza ?? 'sin_llamar') === 'sin_llamar' && ! $empty($drop->nivel_confianza ?? null) && $drop->nivel_confianza !== 'sin_llamar') {
            $upd['nivel_confianza'] = $drop->nivel_confianza;
        }

        foreach ((array) $keep as $col => $val) {
            if (in_array($col, self::SPECIAL, true) || ! property_exists($drop, $col)) {
                continue;
            }
            $other = $drop->$col;
            if (in_array($col, self::FLAGS, true) || is_bool($val) || is_bool($other)) {
                if ($other && ! $val) {
                    $upd[$col] = true;
                }
            } elseif ($empty($val) && ! $empty($other)) {
                $upd[$col] = $other;
            }
        }

        return $upd;
    }

    private function moveReferences(string $table, string $column, string $from, string $to): void
    {
        // Rows that would duplicate one the kept record already has are dropped instead of moved.
        $uniqueWith = ['lider_municipios_influencia' => 'geographic_unit_id', 'favorites' => 'user_id', 'taggables' => 'tag_id'][$table] ?? null;
        if ($uniqueWith) {
            DB::table($table)->where($column, $from)
                ->whereIn($uniqueWith, DB::table($table)->where($column, $to)->select($uniqueWith))
                ->delete();
        }
        DB::table($table)->where($column, $from)->update([$column => $to]);
    }
}
