<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Support\Cedula;
use Illuminate\Support\Facades\DB;

// Two lider records of the same person become one.
// The most complete record survives (its id keeps links, contacts, favorites...). Values come first from
// $editedId, the record the user is working on (its data was just saved), then from the other one.
class MergeLideres
{
    use ScopesTerritory;

    // Rows pointing at a lider id, moved to the surviving record.
    private const REFERENCES = [
        ['lider_contactos', 'lider_id'], ['lider_info_politica', 'lider_id'], ['lider_seguimientos', 'lider_id'],
        ['lider_municipios_influencia', 'lider_id'], ['nexos_familiares', 'person_id'],
        ['whatsapp_send_recipients', 'person_id'], ['favorites', 'favoritable_id'], ['recent_views', 'viewable_id'],
        ['taggables', 'taggable_id'],
    ];

    // Merged by hand below, or never copied.
    private const SPECIAL = ['id', 'nombre', 'cargo', 'observacion', 'telefono', 'cedula', 'votos', 'nivel_confianza',
        'municipio', 'provincia', 'geographic_unit_id', 'created_at', 'updated_at', 'is_active'];

    // Text attributes whose differing value in the other record is noted in the observation.
    private const NOTED = ['email' => 'correo', 'direccion' => 'dirección', 'barrio' => 'barrio', 'profesion' => 'profesión', 'partido' => 'partido'];

    // Yes/no attributes: true on either record stays true.
    private const FLAGS = ['destacado', 'militante', 'autoriza_datos', 'verificado', 'fallecido', 'empresario',
        'reservista', 'funcionario', 'exfuncionario', 'gran_elector'];

    public function __construct(private readonly UpdateVotante $updateVotante) {}

    // $cedula: the one typed in the form. When it is the other record's, it replaces a (mistyped) cédula of the edited one.
    // Returns the surviving record.
    public function handle(string $editedId, string $otherId, ?string $cedula = null): object
    {
        if ($editedId === $otherId) {
            throw new BusinessRuleException('No se puede unificar un registro consigo mismo.');
        }

        return DB::transaction(function () use ($editedId, $otherId, $cedula) {
            $edited = DB::table('lideres')->where('id', $editedId)->lockForUpdate()->first();
            $other = DB::table('lideres')->where('id', $otherId)->lockForUpdate()->first();
            if (! $edited || ! $other) {
                throw new BusinessRuleException('Uno de los registros ya no existe.', 404);
            }
            $this->authorizeGeo($edited->geographic_unit_id);
            $this->authorizeGeo($other->geographic_unit_id);

            $editedCedula = Cedula::normalize($edited->cedula) ?: null;
            $otherCedula = Cedula::normalize($other->cedula) ?: null;
            $correctsCedula = $otherCedula && $cedula === $otherCedula && $editedCedula !== $otherCedula;
            if ($editedCedula && $otherCedula && $editedCedula !== $otherCedula && ! $correctsCedula) {
                throw new BusinessRuleException('No se pueden unificar: los dos registros tienen cédulas distintas.');
            }

            [$keep, $drop] = $this->completeness($other) > $this->completeness($edited) ? [$other, $edited] : [$edited, $other];

            // A candidate opens as their lider by exact name: never rename away from a linked name.
            $linkedName = match (true) {
                $this->updateVotante->linkedPersonIds($edited)->isNotEmpty() => $edited->nombre,
                $this->updateVotante->linkedPersonIds($other)->isNotEmpty() => $other->nombre,
                default => null,
            };
            $final = $this->mergedValues($edited, $other, $linkedName);
            if ($correctsCedula) {
                $final['cedula'] = $otherCedula;
                if ($editedCedula) {
                    $final['observacion'] = trim($final['observacion']."\nCédula anterior: {$edited->cedula}.");
                }
            }
            $upd = array_filter($final, fn ($value, $col) => $value !== $keep->$col, ARRAY_FILTER_USE_BOTH);

            foreach (self::REFERENCES as [$table, $column]) {
                $this->moveReferences($table, $column, $drop->id, $keep->id);
            }
            // The cédula is unique: free it before the surviving record takes it.
            DB::table('lideres')->where('id', $drop->id)->delete();
            if ($upd) {
                DB::table('lideres')->where('id', $keep->id)->update($upd + ['updated_at' => now()]);
            }

            return DB::table('lideres')->where('id', $keep->id)->first();
        });
    }

    // Filled attributes plus related rows (contacts, nexos...): the record with more of them survives.
    private function completeness(object $lider): int
    {
        $filled = count(array_filter((array) $lider, fn ($v) => $v !== null && $v !== '' && $v !== false && $v !== 0));
        foreach (self::REFERENCES as [$table, $column]) {
            $filled += DB::table($table)->where($column, $lider->id)->count();
        }

        return $filled;
    }

    // The resulting values of every mergeable column; $first wins conflicts, $second fills the gaps.
    private function mergedValues(object $first, object $second, ?string $linkedName): array
    {
        $empty = fn ($v) => $v === null || $v === '';
        $final = [];

        // Where the record lives and whether it shows is what the user is editing, whichever row survives.
        $final['geographic_unit_id'] = $first->geographic_unit_id;
        $final['municipio'] = $first->municipio;
        $final['provincia'] = $first->provincia ?? $second->provincia ?? null;
        $final['is_active'] = (bool) ($first->is_active ?? true) || (bool) ($second->is_active ?? true);

        $final['nombre'] = $linkedName ?? self::fullestName($first->nombre, $second->nombre);

        $tokens = [];
        foreach ([$first->cargo, $second->cargo] as $cargo) {
            foreach (explode(',', (string) $cargo) as $t) {
                $t = trim($t);
                if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $tokens), true)) {
                    $tokens[] = $t;
                }
            }
        }
        $final['cargo'] = implode(', ', $tokens);

        $notes = array_values(array_unique(array_filter([trim((string) $first->observacion), trim((string) $second->observacion)])));
        $firstPhone = preg_replace('/\D/', '', (string) $first->telefono);
        $secondPhone = preg_replace('/\D/', '', (string) $second->telefono);
        $final['telefono'] = $firstPhone !== '' ? $first->telefono : $second->telefono;
        if ($firstPhone !== '' && $secondPhone !== '' && $secondPhone !== $firstPhone) {
            $notes[] = "Otro teléfono registrado: {$second->telefono}.";
        }
        foreach (self::NOTED as $col => $label) {
            $mine = trim((string) ($first->$col ?? ''));
            $theirs = trim((string) ($second->$col ?? ''));
            if ($mine !== '' && $theirs !== '' && mb_strtolower($mine) !== mb_strtolower($theirs)) {
                $notes[] = "Otro {$label} registrado: {$theirs}.";
            }
        }
        $otherName = mb_strtolower($final['nombre']) === mb_strtolower(trim($first->nombre)) ? $second->nombre : $first->nombre;
        if (mb_strtolower(trim($otherName)) !== mb_strtolower($final['nombre'])) {
            $notes[] = "También registrado como: {$otherName}.";
        }
        $final['observacion'] = implode("\n", $notes);

        $final['cedula'] = (Cedula::normalize($first->cedula) ?: Cedula::normalize($second->cedula)) ?: null;
        $final['votos'] = max((int) $first->votos, (int) $second->votos);
        $nivel = fn ($v) => $empty($v) || $v === 'sin_llamar' ? null : $v;
        $final['nivel_confianza'] = $nivel($first->nivel_confianza ?? null) ?? $nivel($second->nivel_confianza ?? null) ?? 'sin_llamar';

        foreach ((array) $first as $col => $val) {
            if (in_array($col, self::SPECIAL, true) || ! property_exists($second, $col)) {
                continue;
            }
            $theirs = $second->$col;
            if (in_array($col, self::FLAGS, true) || is_bool($val) || is_bool($theirs)) {
                $final[$col] = (bool) $val || (bool) $theirs;
            } else {
                $final[$col] = $empty($val) ? $theirs : $val;
            }
        }

        return $final;
    }

    // "Cesar Ricardo Silva Diaz" over "Cesar Silva Diaz"; proper case over ALL CAPS when they are the same name.
    private static function fullestName(string $a, string $b): string
    {
        $a = preg_replace('/\s+/', ' ', trim($a));
        $b = preg_replace('/\s+/', ' ', trim($b));
        $words = fn ($n) => count(preg_split('/\s+/', $n));
        if ($words($b) > $words($a)) {
            return $b;
        }
        if ($words($b) === $words($a) && $a === mb_strtoupper($a) && $b !== mb_strtoupper($b)) {
            return $b;
        }

        return $a;
    }

    private function moveReferences(string $table, string $column, string $from, string $to): void
    {
        // Rows that would duplicate one the surviving record already has are dropped instead of moved.
        $uniqueWith = ['lider_municipios_influencia' => 'geographic_unit_id', 'favorites' => 'user_id', 'taggables' => 'tag_id'][$table] ?? null;
        if ($uniqueWith) {
            DB::table($table)->where($column, $from)
                ->whereIn($uniqueWith, DB::table($table)->where($column, $to)->select($uniqueWith))
                ->delete();
        }
        DB::table($table)->where($column, $from)->update([$column => $to]);
    }
}
