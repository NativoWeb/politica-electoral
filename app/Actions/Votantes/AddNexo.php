<?php

namespace App\Actions\Votantes;

use App\Http\Controllers\Concerns\ScopesTerritory;
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

    public function handle(string $personId, array $data): string
    {
        $id = (string) Str::uuid();

        DB::table('nexos_familiares')->insert([
            'id' => $id,
            'person_id' => $personId,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
