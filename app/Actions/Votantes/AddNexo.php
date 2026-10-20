<?php

namespace App\Actions\Votantes;

use App\Http\Controllers\Concerns\ScopesTerritory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddNexo
{
    use ScopesTerritory;

    // The owner can be a lider or an electoral person.
    public function authorizeFor(string $personId): void
    {
        $exists = DB::table('persons')->where('id', $personId)->exists()
            || DB::table('lideres')->where('id', $personId)->exists();
        abort_if(! $exists, 404);
        $this->authorizePersonOrLider($personId);
    }

    // An offline create carries the id generated on the device ($data['client_id']): sending it twice stores it once.
    public function handle(string $personId, array $data): string
    {
        $id = $data['client_id'] ?? (string) Str::uuid();
        unset($data['client_id']);

        $exists = fn () => DB::table('nexos_familiares')->where('id', $id)->exists();
        if ($exists()) {
            return $id;
        }

        try {
            DB::table('nexos_familiares')->insert([
                'id' => $id,
                'person_id' => $personId,
                ...$data,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $exists()) {
                throw $e;
            }
        }

        return $id;
    }
}
