<?php

namespace App\Services\Configuracion;

use App\Support\Configuracion\AjusteRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The changed ajustes (clave => valor), cached until an edit forgets them. Without the table, every ajuste is its default.
class AjusteStore
{
    public const CACHE_KEY = 'config:ajustes:v1';

    // Bumped on every edit: a request that read the table before the edit caches its old copy under the old
    // version, which nobody reads any more.
    public const VERSION_KEY = 'config:ajustes:version';

    private const TTL = 86400;

    private ?array $guardados = null;

    public function guardados(): array
    {
        if ($this->guardados !== null) {
            return $this->guardados;
        }

        $rows = Cache::remember(self::CACHE_KEY.':'.(int) Cache::get(self::VERSION_KEY, 0), self::TTL, function () {
            if (! Schema::hasTable('ajustes')) {
                return null;
            }

            return DB::table('ajustes')->get(['clave', 'valor'])
                ->mapWithKeys(fn ($r) => [$r->clave => json_decode($r->valor, true)])
                ->all();
        });

        $guardados = [];
        foreach ($rows ?? [] as $clave => $valor) {
            if (AjusteRegistry::existe($clave) && ($v = AjusteRegistry::normalizar($clave, $valor)) !== null) {
                $guardados[$clave] = $v;
            }
        }

        return $this->guardados = $guardados;
    }

    public function olvidar(): void
    {
        $this->guardados = null;
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }
}
