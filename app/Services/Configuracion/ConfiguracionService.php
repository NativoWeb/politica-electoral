<?php

namespace App\Services\Configuracion;

use App\Models\CatalogoOpcion;
use App\Models\ConfigCambio;
use App\Models\User;
use App\Support\Configuracion\AjusteRegistry;
use App\Support\Configuracion\CatalogoRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Every write of the Centro de configuración: it applies the change, records it in config_cambios (before / after)
// and forgets the cache. A change that the rules forbid throws ConfiguracionException (422 with its message).
class ConfiguracionService
{
    // --- Lists ---

    public function crearOpcion(string $catalogo, array $data, User $user): CatalogoOpcion
    {
        $def = CatalogoRegistry::definicion($catalogo);
        if ($def['fijo']) {
            throw new ConfiguracionException('Esta lista tiene valores fijos que usa el sistema: no se le agregan opciones.');
        }
        $etiqueta = trim($data['etiqueta']);
        $valor = $def['codigo'] ? Str::slug($etiqueta, '_') : $etiqueta;
        if ($valor === '') {
            throw new ConfiguracionException('Escriba un nombre con letras o números.');
        }
        if (CatalogoOpcion::where('catalogo', $catalogo)->whereRaw('LOWER(valor) = ?', [mb_strtolower($valor)])->exists()) {
            throw new ConfiguracionException("«{$etiqueta}» ya está en la lista (si está desactivada, actívela).");
        }

        return $this->write(function () use ($catalogo, $etiqueta, $valor, $data, $user) {
            $opcion = CatalogoOpcion::create([
                'catalogo' => $catalogo, 'valor' => $valor, 'etiqueta' => $etiqueta,
                'orden' => (int) CatalogoOpcion::where('catalogo', $catalogo)->max('orden') + 1,
                'activo' => true, 'color' => $data['color'] ?? null,
            ]);
            $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, $valor, 'crear', null, $opcion->estado(), $user);

            return $opcion;
        });
    }

    public function editarOpcion(CatalogoOpcion $opcion, array $data, User $user): CatalogoOpcion
    {
        $antes = $opcion->estado();
        $despues = array_merge($antes, array_intersect_key($data, array_flip(['etiqueta', 'activo', 'color'])));
        if (isset($despues['etiqueta'])) {
            $despues['etiqueta'] = trim($despues['etiqueta']);
        }
        $despues['activo'] = (bool) $despues['activo'];
        if ($antes === $despues) {
            return $opcion;
        }
        if (! $despues['activo'] && in_array($opcion->valor, CatalogoRegistry::definicion($opcion->catalogo)['protegidos'], true)) {
            throw new ConfiguracionException("«{$opcion->etiqueta}» la usa el sistema como valor por defecto: no se puede desactivar.");
        }
        $accion = $antes['activo'] !== $despues['activo'] ? ($despues['activo'] ? 'activar' : 'desactivar') : 'editar';

        return $this->write(function () use ($opcion, $antes, $despues, $accion, $user) {
            $opcion->update($despues);
            $this->record(ConfigCambio::TIPO_CATALOGO, $opcion->catalogo, $opcion->valor, $accion, $antes, $despues, $user);

            return $opcion;
        });
    }

    public function eliminarOpcion(CatalogoOpcion $opcion, User $user): void
    {
        if (CatalogoRegistry::definicion($opcion->catalogo)['fijo']) {
            throw new ConfiguracionException('Esta lista tiene valores fijos que usa el sistema: sus opciones solo se desactivan.');
        }
        if ($this->usos($opcion->catalogo, $opcion->valor) > 0) {
            throw new ConfiguracionException("«{$opcion->etiqueta}» está en uso en fichas guardadas: desactívela en lugar de borrarla.");
        }

        $this->write(function () use ($opcion, $user) {
            $antes = $opcion->estado();
            $opcion->delete();
            $this->record(ConfigCambio::TIPO_CATALOGO, $opcion->catalogo, $opcion->valor, 'eliminar', $antes, null, $user);
        });
    }

    // $ids: every option of the list in the new order.
    public function reordenar(string $catalogo, array $ids, User $user): void
    {
        $opciones = CatalogoOpcion::where('catalogo', $catalogo)->get()->keyBy('id');
        if (count($ids) !== $opciones->count() || array_diff($ids, $opciones->keys()->all()) !== []) {
            throw new ConfiguracionException('La lista cambió mientras la ordenaba: recargue la página.');
        }
        $antes = $opciones->sortBy('orden')->mapWithKeys(fn ($o) => [$o->valor => (int) $o->orden])->all();
        $despues = [];
        foreach (array_values($ids) as $i => $id) {
            $despues[$opciones[$id]->valor] = $i + 1;
        }
        if ($antes === $despues) {
            return;
        }

        $this->write(function () use ($catalogo, $antes, $despues, $user) {
            $this->aplicarOrden($catalogo, $despues);
            $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, '*', 'reordenar', ['ordenes' => $antes], ['ordenes' => $despues], $user);
        });
    }

    // How many saved records hold the valor (0 when the table of the list does not exist).
    public function usos(string $catalogo, string $valor): int
    {
        [$table, $column] = CatalogoRegistry::definicion($catalogo)['uso'];
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->where($column, $valor)->count();
    }

    // [valor => records] of a list, in one query.
    public function usosPorValor(string $catalogo): array
    {
        [$table, $column] = CatalogoRegistry::definicion($catalogo)['uso'];
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->whereNotNull($column)->groupBy($column)
            ->selectRaw("{$column} as valor, count(*) as n")->pluck('n', 'valor')->map(fn ($n) => (int) $n)->all();
    }

    // --- Ajustes ---

    public function guardarAjuste(string $clave, mixed $valor, User $user): void
    {
        $valor = AjusteRegistry::normalizar($clave, $valor);
        $antes = Ajuste::guardado($clave) ? ['valor' => Ajuste::get($clave)] : null;
        if ($antes !== null && $antes['valor'] === $valor) {
            return;
        }

        $this->write(function () use ($clave, $valor, $antes, $user) {
            $this->upsertAjuste($clave, $valor, $user);
            $this->record(ConfigCambio::TIPO_AJUSTE, null, $clave, 'editar', $antes, ['valor' => $valor], $user);
        });
    }

    public function restablecerAjuste(string $clave, User $user): void
    {
        if (! Ajuste::guardado($clave)) {
            return;
        }
        $antes = ['valor' => Ajuste::get($clave)];

        $this->write(function () use ($clave, $antes, $user) {
            DB::table('ajustes')->where('clave', $clave)->delete();
            $this->record(ConfigCambio::TIPO_AJUSTE, null, $clave, 'restablecer', $antes, null, $user);
        });
    }

    // --- History ---

    // Applies the "before" of a change as a new change (that is recorded too). Each change is reverted at most once.
    public function revertir(ConfigCambio $cambio, User $user): ConfigCambio
    {
        if ($cambio->revertido_por_id !== null) {
            throw new ConfiguracionException('Este cambio ya se revirtió.');
        }

        return $this->write(function () use ($cambio, $user) {
            $nuevo = $cambio->tipo === ConfigCambio::TIPO_AJUSTE
                ? $this->revertirAjuste($cambio, $user)
                : $this->revertirOpcion($cambio, $user);
            $nuevo->update(['revierte_id' => $cambio->id]);
            $cambio->update(['revertido_por_id' => $nuevo->id]);

            return $nuevo;
        });
    }

    private function revertirAjuste(ConfigCambio $cambio, User $user): ConfigCambio
    {
        $clave = $cambio->referencia;
        if (! AjusteRegistry::existe($clave)) {
            throw new ConfiguracionException('Ese ajuste ya no existe en el sistema.');
        }
        $actual = DB::table('ajustes')->where('clave', $clave)->value('valor');
        $actual = $actual === null ? null : ['valor' => json_decode($actual, true)];

        if ($cambio->antes === null) {
            DB::table('ajustes')->where('clave', $clave)->delete();
        } else {
            $valor = AjusteRegistry::normalizar($clave, $cambio->antes['valor'] ?? null);
            if ($valor === null) {
                throw new ConfiguracionException('El valor anterior ya no es válido para este ajuste.');
            }
            $this->upsertAjuste($clave, $valor, $user);
        }

        return $this->record(ConfigCambio::TIPO_AJUSTE, null, $clave, 'revertir', $actual, $cambio->antes, $user);
    }

    private function revertirOpcion(ConfigCambio $cambio, User $user): ConfigCambio
    {
        $catalogo = $cambio->catalogo;
        if (! CatalogoRegistry::existe((string) $catalogo)) {
            throw new ConfiguracionException('Esa lista ya no existe en el sistema.');
        }

        if (isset($cambio->antes['ordenes'])) {
            $actual = CatalogoOpcion::where('catalogo', $catalogo)->orderBy('orden')->pluck('orden', 'valor')->map(fn ($n) => (int) $n)->all();
            $this->aplicarOrden($catalogo, $cambio->antes['ordenes']);

            return $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, '*', 'revertir', ['ordenes' => $actual], $cambio->antes, $user);
        }

        $opcion = CatalogoOpcion::where('catalogo', $catalogo)->where('valor', $cambio->referencia)->first();

        // Undo a creation: deleted when no ficha has it, otherwise only switched off.
        if ($cambio->antes === null) {
            if (! $opcion) {
                throw new ConfiguracionException('Esa opción ya no está en la lista.');
            }
            $antes = $opcion->estado();
            if ($this->usos($catalogo, $opcion->valor) > 0 || CatalogoRegistry::definicion($catalogo)['fijo']) {
                $opcion->update(['activo' => false]);

                return $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, $opcion->valor, 'revertir', $antes, $opcion->estado(), $user);
            }
            $opcion->delete();

            return $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, $cambio->referencia, 'revertir', $antes, null, $user);
        }

        // Undo a deletion: the option comes back as it was.
        if ($cambio->despues === null) {
            if ($opcion) {
                throw new ConfiguracionException('Esa opción ya volvió a la lista.');
            }
            $opcion = CatalogoOpcion::create(['catalogo' => $catalogo, 'valor' => $cambio->referencia] + $cambio->antes);

            return $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, $opcion->valor, 'revertir', null, $opcion->estado(), $user);
        }

        if (! $opcion) {
            throw new ConfiguracionException('Esa opción ya no está en la lista.');
        }
        // Only the fields this change touched go back (etiqueta, activo, color, orden).
        $restaurar = [];
        foreach ($cambio->antes as $campo => $valor) {
            if (($cambio->despues[$campo] ?? null) !== $valor) {
                $restaurar[$campo] = $valor;
            }
        }
        if (array_key_exists('activo', $restaurar) && ! $restaurar['activo']
            && in_array($opcion->valor, CatalogoRegistry::definicion($catalogo)['protegidos'], true)) {
            throw new ConfiguracionException("«{$opcion->etiqueta}» la usa el sistema como valor por defecto: no se puede desactivar.");
        }
        $antes = $opcion->estado();
        $opcion->update($restaurar);

        return $this->record(ConfigCambio::TIPO_CATALOGO, $catalogo, $opcion->valor, 'revertir', $antes, $opcion->estado(), $user);
    }

    private function aplicarOrden(string $catalogo, array $ordenes): void
    {
        foreach ($ordenes as $valor => $orden) {
            CatalogoOpcion::where('catalogo', $catalogo)->where('valor', (string) $valor)->update(['orden' => (int) $orden]);
        }
    }

    private function upsertAjuste(string $clave, mixed $valor, User $user): void
    {
        $now = now();
        $exists = DB::table('ajustes')->where('clave', $clave)->exists();
        $row = ['valor' => json_encode($valor), 'tipo' => AjusteRegistry::definicion($clave)['tipo'], 'actualizado_por' => $user->id, 'updated_at' => $now];
        $exists
            ? DB::table('ajustes')->where('clave', $clave)->update($row)
            : DB::table('ajustes')->insert($row + ['clave' => $clave, 'created_at' => $now]);
    }

    private function record(string $tipo, ?string $catalogo, string $referencia, string $accion, ?array $antes, ?array $despues, User $user): ConfigCambio
    {
        return ConfigCambio::create([
            'tipo' => $tipo, 'catalogo' => $catalogo, 'referencia' => $referencia, 'accion' => $accion,
            'antes' => $antes, 'despues' => $despues, 'user_id' => $user->id, 'created_at' => now(),
        ]);
    }

    // The change and its record commit together; the cache is forgotten after the commit.
    private function write(callable $fn): mixed
    {
        $result = DB::transaction($fn);
        Catalogo::olvidar();
        Ajuste::olvidar();

        return $result;
    }
}
