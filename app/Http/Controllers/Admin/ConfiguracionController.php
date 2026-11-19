<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditActivity;
use App\Http\Requests\Configuracion\AjusteRequest;
use App\Http\Requests\Configuracion\OpcionRequest;
use App\Models\CatalogoOpcion;
use App\Models\ConfigCambio;
use App\Services\Configuracion\Ajuste;
use App\Services\Configuracion\Catalogo;
use App\Services\Configuracion\ConfiguracionException;
use App\Services\Configuracion\ConfiguracionService;
use App\Support\Configuracion\AjusteRegistry;
use App\Support\Configuracion\CatalogoRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

// Centro de configuración (/admin/configuracion, superadmin only, global): lists of the forms, ajustes del sistema and
// the history of every change with "revertir".
class ConfiguracionController extends Controller
{
    private const HISTORIAL_POR_PAGINA = 30;

    public function __construct(private readonly ConfiguracionService $service) {}

    public function index(): Response
    {
        $listas = [];
        foreach (array_keys(CatalogoRegistry::DEFINICIONES) as $catalogo) {
            $def = CatalogoRegistry::definicion($catalogo);
            $opciones = Catalogo::opciones($catalogo, true);
            $listas[] = [
                'clave' => $catalogo, 'nombre' => $def['nombre'], 'descripcion' => $def['descripcion'], 'fijo' => $def['fijo'],
                'activas' => count(array_filter($opciones, fn ($o) => $o['activo'])), 'total' => count($opciones),
            ];
        }
        $cambiados = array_values(array_filter(array_keys(AjusteRegistry::definiciones()), fn ($c) => Ajuste::guardado($c)));

        return Inertia::render('Admin/Configuracion/Index', [
            'listas' => $listas,
            'accesos' => CatalogoRegistry::ACCESOS_DIRECTOS,
            'resumenAjustes' => ['total' => count(AjusteRegistry::definiciones()), 'cambiados' => count($cambiados)],
            'totalCambios' => ConfigCambio::count(),
        ]);
    }

    public function lista(string $catalogo): Response
    {
        abort_unless(CatalogoRegistry::existe($catalogo), 404);
        $usos = $this->service->usosPorValor($catalogo);

        return Inertia::render('Admin/Configuracion/Lista', [
            'lista' => array_diff_key(CatalogoRegistry::definicion($catalogo), ['uso' => true]),
            'opciones' => array_map(fn ($o) => $o + ['usos' => $usos[$o['valor']] ?? 0], $this->opcionesDeTabla($catalogo)),
        ]);
    }

    public function storeOpcion(OpcionRequest $request, string $catalogo): JsonResponse
    {
        abort_unless(CatalogoRegistry::existe($catalogo), 404);

        return $this->attempt($request, function () use ($request, $catalogo) {
            $opcion = $this->service->crearOpcion($catalogo, $request->validated(), $request->user());
            $this->audit($request, null, ['catalogo' => $catalogo, 'valor' => $opcion->valor] + $opcion->estado());

            return response()->json(['success' => true, 'message' => 'Opción agregada.', 'data' => $this->fila($opcion)], 201);
        });
    }

    public function updateOpcion(OpcionRequest $request, string $id): JsonResponse
    {
        $opcion = $this->opcion($id);
        $antes = $opcion->estado();

        return $this->attempt($request, function () use ($request, $opcion, $antes) {
            $opcion = $this->service->editarOpcion($opcion, $request->validated(), $request->user());
            $this->audit($request, ['catalogo' => $opcion->catalogo, 'valor' => $opcion->valor] + $antes, $opcion->estado());

            return response()->json(['success' => true, 'message' => 'Opción guardada.', 'data' => $this->fila($opcion)]);
        });
    }

    public function destroyOpcion(Request $request, string $id): JsonResponse
    {
        $opcion = $this->opcion($id);

        return $this->attempt($request, function () use ($request, $opcion) {
            $antes = ['catalogo' => $opcion->catalogo, 'valor' => $opcion->valor] + $opcion->estado();
            $this->service->eliminarOpcion($opcion, $request->user());
            $this->audit($request, $antes, null);

            return response()->json(['success' => true, 'message' => 'Opción borrada.']);
        });
    }

    public function reorder(Request $request, string $catalogo): JsonResponse
    {
        abort_unless(CatalogoRegistry::existe($catalogo), 404);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', 'uuid', 'distinct']]);

        return $this->attempt($request, function () use ($request, $catalogo, $data) {
            $this->service->reordenar($catalogo, $data['ids'], $request->user());

            return response()->json(['success' => true, 'message' => 'Orden guardado.', 'data' => $this->opcionesDeTabla($catalogo)]);
        });
    }

    public function ajustes(): Response
    {
        $ajustes = [];
        foreach (AjusteRegistry::definiciones() as $clave => $def) {
            $ajustes[] = [
                'clave' => $clave, 'grupo' => $def['grupo'], 'tipo' => $def['tipo'], 'etiqueta' => $def['etiqueta'],
                'descripcion' => $def['descripcion'], 'min' => $def['min'] ?? null, 'max' => $def['max'] ?? null,
                'unidad' => $def['unidad'] ?? null, 'opciones' => $def['opciones'] ?? null,
                'default' => $def['default'], 'valor' => Ajuste::get($clave), 'cambiado' => Ajuste::guardado($clave),
            ];
        }

        return Inertia::render('Admin/Configuracion/Ajustes', ['grupos' => AjusteRegistry::GRUPOS, 'ajustes' => $ajustes]);
    }

    public function updateAjuste(AjusteRequest $request, string $clave): JsonResponse
    {
        $antes = Ajuste::get($clave);

        return $this->attempt($request, function () use ($request, $clave, $antes) {
            $this->service->guardarAjuste($clave, $request->validated('valor'), $request->user());
            $this->audit($request, ['clave' => $clave, 'valor' => $antes], ['clave' => $clave, 'valor' => Ajuste::get($clave)]);

            return response()->json(['success' => true, 'message' => 'Ajuste guardado.', 'data' => $this->ajusteEstado($clave)]);
        });
    }

    public function resetAjuste(Request $request, string $clave): JsonResponse
    {
        abort_unless(AjusteRegistry::existe($clave), 404);
        $antes = Ajuste::get($clave);

        return $this->attempt($request, function () use ($request, $clave, $antes) {
            $this->service->restablecerAjuste($clave, $request->user());
            $this->audit($request, ['clave' => $clave, 'valor' => $antes], ['clave' => $clave, 'valor' => Ajuste::get($clave)]);

            return response()->json(['success' => true, 'message' => 'Volvió al valor por defecto.', 'data' => $this->ajusteEstado($clave)]);
        });
    }

    public function historial(Request $request): Response
    {
        $tipo = in_array($request->query('tipo'), [ConfigCambio::TIPO_CATALOGO, ConfigCambio::TIPO_AJUSTE], true) ? $request->query('tipo') : null;
        $page = ConfigCambio::with('user:id,name')
            ->when($tipo, fn ($q) => $q->where('tipo', $tipo))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::HISTORIAL_POR_PAGINA)->withQueryString();

        $page->getCollection()->transform(fn (ConfigCambio $c) => [
            'id' => $c->id, 'tipo' => $c->tipo, 'accion' => $c->accion, 'referencia' => $c->referencia,
            'donde' => $this->donde($c), 'antes' => $c->antes, 'despues' => $c->despues,
            'usuario' => $c->user?->name, 'fecha' => $c->created_at?->toIso8601String(),
            'revertido' => $c->revertido_por_id !== null, 'revierte' => $c->revierte_id !== null,
        ]);

        return Inertia::render('Admin/Configuracion/Historial', ['cambios' => $page, 'filtro' => $tipo]);
    }

    public function revertir(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $cambio = ConfigCambio::findOrFail($id);

        return $this->attempt($request, function () use ($request, $cambio) {
            $nuevo = $this->service->revertir($cambio, $request->user());
            $this->audit($request, ['cambio' => $cambio->id] + ($nuevo->antes ?? []), ['cambio' => $nuevo->id] + ($nuevo->despues ?? []));

            return response()->json(['success' => true, 'message' => 'Cambio revertido.']);
        });
    }

    private function attempt(Request $request, callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ConfiguracionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function audit(Request $request, ?array $antes, ?array $despues): void
    {
        $request->attributes->set(AuditActivity::BEFORE_STATE, $antes);
        $request->attributes->set(AuditActivity::AFTER_STATE, $despues);
    }

    private function opcion(string $id): CatalogoOpcion
    {
        abort_unless(Str::isUuid($id), 404);
        $opcion = CatalogoOpcion::findOrFail($id);
        abort_unless(CatalogoRegistry::existe($opcion->catalogo), 404);

        return $opcion;
    }

    // From the table (not the cache): the screen edits by id.
    private function opcionesDeTabla(string $catalogo): array
    {
        return CatalogoOpcion::where('catalogo', $catalogo)->orderBy('orden')->orderBy('etiqueta')->get()
            ->map(fn (CatalogoOpcion $o) => $this->fila($o))->all();
    }

    private function fila(CatalogoOpcion $o): array
    {
        $def = CatalogoRegistry::definicion($o->catalogo);

        return ['id' => $o->id, 'valor' => $o->valor] + $o->estado()
            + ['protegido' => in_array($o->valor, $def['protegidos'], true)];
    }

    private function ajusteEstado(string $clave): array
    {
        return ['clave' => $clave, 'valor' => Ajuste::get($clave), 'cambiado' => Ajuste::guardado($clave)];
    }

    private function donde(ConfigCambio $c): string
    {
        if ($c->tipo === ConfigCambio::TIPO_AJUSTE) {
            return AjusteRegistry::existe($c->referencia) ? AjusteRegistry::definicion($c->referencia)['etiqueta'] : $c->referencia;
        }
        $lista = CatalogoRegistry::existe((string) $c->catalogo) ? CatalogoRegistry::definicion($c->catalogo)['nombre'] : (string) $c->catalogo;

        return $c->referencia === '*' ? $lista : "{$lista}: {$c->referencia}";
    }
}
