<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Campaign;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddNexo
{
    use ScopesTerritory;

    // The owner can be a lider (of the user's own campaign) or a shared electoral person.
    public function authorizeFor(string $personId): void
    {
        $exists = DB::table('persons')->where('id', $personId)->exists()
            || DB::table('lideres')->where('id', $personId)->exists();
        abort_if(! $exists, 404);
        $this->authorizePersonOrLider($personId, true);
    }

    // An offline create carries the id generated on the device ($data['client_id']): sending it twice stores it once.
    public function handle(string $personId, array $data): string
    {
        $id = $data['client_id'] ?? (string) Str::uuid();
        unset($data['client_id']);

        $owner = fn () => DB::table('nexos_familiares')->where('id', $id)->value('person_id');
        $alreadyStored = function () use ($owner, $personId) {
            $current = $owner();
            if ($current !== null && $current !== $personId) {
                throw new BusinessRuleException('Ese identificador ya pertenece a otro nexo.', 409);
            }

            return $current !== null;
        };
        if ($alreadyStored()) {
            return $id;
        }

        // A lider's nexos live in the lider's campaign; an electoral person's, in the writer's campaign.
        $campaignId = DB::table('lideres')->where('id', $personId)->value('campaign_id')
            ?? $this->campaign()->ownId()
            ?? Campaign::DEFAULT_ID;

        try {
            DB::table('nexos_familiares')->insert([
                'id' => $id,
                'person_id' => $personId,
                ...$data,
                'campaign_id' => $campaignId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $alreadyStored()) {
                throw $e;
            }
        }

        return $id;
    }
}
