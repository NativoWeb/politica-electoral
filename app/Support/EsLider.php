<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

// Who counts as a leader in Campañas electorales (decision of 2026-11-26, docs/campanas-electorales.md): a ficha not
// deceased whose cargo has the word «líder» (with or without accent, any case, as a whole word: «LIDER COLONIAL» and
// «Líder, Candidato 2027» yes; «Liderazgo», «Lideresa», «Líderes», «Directorio Municipal» no), or of tipo lider /
// nuevo_lider, or with the box «Es líder (cuenta para las metas)» (lideres.es_lider) ticked, which is left for someone
// without that cargo. sql() and de() are the same rule, for a query and for one ficha.
//
// The box is only shown, filtered and saved while the module is on for the user. It changes only when the key comes
// with true/false: a form, the offline queue or the agent that leaves it out (or sends null) never unmarks anyone.
class EsLider
{
    public const CAMPO = 'es_lider';

    // The ficha already counts without the box (cargo or tipo): the box shows ticked and locked.
    public const CAMPO_POR_CARGO = 'es_lider_por_cargo';

    public const ETIQUETA = 'Líderes';

    // The same mark on one person (a report's Marcas column).
    public const ETIQUETA_FICHA = 'Es líder';

    public const TIPOS = ['lider', 'nuevo_lider'];

    // Turned into spaces before looking for the word, so «Líder, Candidato» and «LIDER/COORDINADOR» count.
    private const SEPARADORES = [',', '.', ';', ':', '-', '/', '(', ')', '"', '«', '»', "'", "\t", "\n", "\r", "\u{00A0}"];

    private const ACENTOS = ['Í', 'í'];

    public static function activo(?User $user = null): bool
    {
        $user ??= request()->user();

        return $user instanceof User && Features::enabledFor('campanas', $user);
    }

    // The validated data without es_lider when the module is off or it came empty.
    public static function filtrar(array $data, ?User $user = null): array
    {
        if (! array_key_exists(self::CAMPO, $data)) {
            return $data;
        }
        if ($data[self::CAMPO] === null || ! self::activo($user)) {
            unset($data[self::CAMPO]);
        } else {
            $data[self::CAMPO] = (bool) $data[self::CAMPO];
        }

        return $data;
    }

    /**
     * The rule as a boolean SQL expression over the lideres table (or its alias), PostgreSQL and SQLite alike.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sql(string $tabla = 'lideres'): array
    {
        [$porCargo, $bindings] = self::sqlPorCargo($tabla);

        return ["(NOT COALESCE({$tabla}.fallecido, FALSE) AND ({$tabla}.es_lider OR {$porCargo}))", $bindings];
    }

    // Filters $query to the fichas that count as leaders.
    public static function where(Builder $query, string $tabla = 'lideres'): Builder
    {
        [$sql, $bindings] = self::sql($tabla);

        return $query->whereRaw($sql, $bindings);
    }

    /**
     * Cargo or tipo, without the box. Replace and lower of ASCII letters behave the same in both engines (SQLite's
     * lower() leaves «Í» as is, so the accents go first).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sqlPorCargo(string $tabla = 'lideres'): array
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tabla)) {
            throw new InvalidArgumentException("Tabla inválida: {$tabla}");
        }
        $cargo = "COALESCE({$tabla}.cargo, '')";
        foreach (self::ACENTOS as $_) {
            $cargo = "REPLACE({$cargo}, ?, 'i')";
        }
        $cargo = "LOWER({$cargo})";
        foreach (self::SEPARADORES as $_) {
            $cargo = "REPLACE({$cargo}, ?, ' ')";
        }
        $tipos = implode(', ', array_fill(0, count(self::TIPOS), '?'));

        return [
            "({$tabla}.tipo IN ({$tipos}) OR (' ' || {$cargo} || ' ') LIKE ?)",
            [...self::TIPOS, ...self::ACENTOS, ...self::SEPARADORES, '% lider %'],
        ];
    }

    // The same rule for one ficha (a model, a row or an array with es_lider, tipo, cargo and fallecido).
    public static function de(object|array $ficha): bool
    {
        $f = (object) $ficha;
        if (self::si($f->fallecido ?? null)) {
            return false;
        }

        return self::si($f->es_lider ?? null) || self::porCargo($f->tipo ?? null, $f->cargo ?? null);
    }

    public static function porCargo(?string $tipo, ?string $cargo): bool
    {
        if ($tipo !== null && in_array($tipo, self::TIPOS, true)) {
            return true;
        }
        $texto = str_replace(self::SEPARADORES, ' ', strtolower(str_replace(self::ACENTOS, 'i', $cargo ?? '')));

        return str_contains(" {$texto} ", ' lider ');
    }

    private static function si(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
