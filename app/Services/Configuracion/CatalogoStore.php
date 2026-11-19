<?php

namespace App\Services\Configuracion;

use App\Support\Configuracion\CatalogoRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Reads catalogo_opciones once per request (cached in the default store, Redis in production, until an edit forgets
// it). Without the table (before the migration, or tests that do not create it) the registry's original options rule.
class CatalogoStore
{
    public const CACHE_KEY = 'config:catalogos:v1';

    // Bumped on every edit: a request that read the table before the edit caches its old copy under the old
    // version, which nobody reads any more.
    public const VERSION_KEY = 'config:catalogos:version';

    private const TTL = 86400;

    private ?array $todas = null;

    public function todas(): array
    {
        if ($this->todas !== null) {
            return $this->todas;
        }

        $rows = Cache::remember(self::CACHE_KEY.':'.(int) Cache::get(self::VERSION_KEY, 0), self::TTL, function () {
            if (! Schema::hasTable('catalogo_opciones')) {
                return null;
            }

            return DB::table('catalogo_opciones')
                ->orderBy('catalogo')->orderBy('orden')->orderBy('etiqueta')
                ->get(['id', 'catalogo', 'valor', 'etiqueta', 'orden', 'activo', 'color'])
                ->groupBy('catalogo')
                ->map(fn ($group) => $group->map(fn ($r) => [
                    'id' => $r->id, 'valor' => $r->valor, 'etiqueta' => $r->etiqueta, 'orden' => (int) $r->orden,
                    'activo' => (bool) $r->activo, 'color' => $r->color,
                ])->values()->all())
                ->all();
        });

        $todas = [];
        foreach (array_keys(CatalogoRegistry::DEFINICIONES) as $catalogo) {
            $todas[$catalogo] = $rows === null ? CatalogoRegistry::opcionesIniciales($catalogo) : ($rows[$catalogo] ?? []);
        }

        return $this->todas = $todas;
    }

    public function olvidar(): void
    {
        $this->todas = null;
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }
}
