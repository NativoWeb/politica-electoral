<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Support\Cedula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Other lideres of the same municipio that look like the same person (same phone, same cédula or
// a name contained in the other), so the panel can offer to merge them. Records with different
// cédulas are never offered: they cannot be merged. Only the lider's own campaign is searched.
class FindPossibleDuplicates
{
    use ScopesTerritory;

    public function handle(string $liderId): array
    {
        $lider = DB::table('lideres')->where('id', $liderId)->first();
        // A lider of a campaign the user cannot see answers exactly like a missing one.
        if (! $lider) {
            throw new BusinessRuleException('No encontrado.', 404);
        }
        $this->authorizeLiderRow($lider);
        // A subcampaign's lider is read-only for this user: offering a merge would only end in a 403.
        if (! $lider->geographic_unit_id || ! $this->campaign()->isOwn($lider->campaign_id)) {
            return [];
        }

        $phone = self::phone($lider->telefono);
        $cedula = Cedula::normalize($lider->cedula) ?: null;
        $tokens = self::nameTokens($lider->nombre);

        $found = [];
        $others = DB::table('lideres')
            ->where('campaign_id', $lider->campaign_id)
            ->where('geographic_unit_id', $lider->geographic_unit_id)
            ->where('id', '!=', $liderId)
            ->where('is_active', true)
            ->get(['id', 'nombre', 'cargo', 'telefono', 'cedula']);
        foreach ($others as $o) {
            $otherCedula = Cedula::normalize($o->cedula) ?: null;
            if ($cedula && $otherCedula && $cedula !== $otherCedula) {
                continue;
            }
            $otherTokens = self::nameTokens($o->nombre);
            $motivo = match (true) {
                $cedula && $cedula === $otherCedula => 'Misma cédula',
                $phone !== null && $phone === self::phone($o->telefono) && self::namesOverlap($tokens, $otherTokens, 1) => 'Mismo teléfono',
                // Without a shared phone or cédula, two common words ("Maria Rodriguez") are too weak.
                self::namesOverlap($tokens, $otherTokens, 3) => 'Nombre parecido',
                default => null,
            };
            if ($motivo) {
                $found[] = ['id' => $o->id, 'nombre' => $o->nombre, 'cargo' => $o->cargo, 'telefono' => $o->telefono, 'motivo' => $motivo];
            }
        }

        return $found;
    }

    private static function phone(?string $telefono): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $telefono);

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    private static function nameTokens(?string $nombre): array
    {
        $plain = Str::lower(Str::ascii((string) $nombre));
        $words = preg_split('/[^a-z]+/', $plain, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) > 2)));
    }

    // One name's words all appear in the other (e.g. "Cesar Silva Diaz" in "Cesar Ricardo Silva Diaz"),
    // with at least $min words in common. With a shared phone, a common first name is enough.
    private static function namesOverlap(array $a, array $b, int $min): bool
    {
        if (! $a || ! $b) {
            return false;
        }
        $common = count(array_intersect($a, $b));
        $shorter = min(count($a), count($b));

        return $common >= $min && $common === $shorter;
    }
}
