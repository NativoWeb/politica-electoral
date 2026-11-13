<?php

namespace App\Services\Agent;

use App\Support\ContactDataPolicy;
use Illuminate\Support\Facades\DB;

// Lists of several persons the agent hands out (referidos, participants and responsables of actas, compromisos):
// a person's contact fields only when that person is a lider (lider_id) who authorized their data.
final class AuthorizedContacts
{
    // id => true for the ids in $liderIds whose lider authorized their data.
    public static function of(iterable $liderIds): array
    {
        $ids = [];
        foreach ($liderIds as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        $authorized = [];
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach (DB::table('lideres')->whereIn('id', $chunk)->get(['id', 'autoriza_datos']) as $lider) {
                if (ContactDataPolicy::authorized($lider)) {
                    $authorized[$lider->id] = true;
                }
            }
        }

        return $authorized;
    }

    // $person with $fields set to null unless its $idKey is in $authorized. $delivered says whether any was kept.
    public static function filter(array $person, array $authorized, array $fields, string $idKey = 'lider_id', ?bool &$delivered = null): array
    {
        $delivered = false;
        if (isset($authorized[$person[$idKey] ?? ''])) {
            foreach ($fields as $field) {
                $delivered = $delivered || filled($person[$field] ?? null);
            }

            return $person;
        }
        foreach ($fields as $field) {
            if (array_key_exists($field, $person)) {
                $person[$field] = null;
            }
        }

        return $person;
    }
}
