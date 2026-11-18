<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

// Load test only: runs the heavy screens in this process, as the given user and without middleware (no HMAC, CSRF,
// throttling), and reports time and PHP peak memory per request. Never against production.
class LoadTestMeasure extends Command
{
    protected $signature = 'loadtest:measure
        {--user= : Email of the user to act as (default: the first superadmin)}
        {--only= : Comma-separated scenario keys}
        {--json= : Also write the results to this JSON file}
        {--queries : Print the slowest SQL of each scenario}
        {--repeat=1 : Runs per scenario; the last one is kept (the first pays PHP compiling, like a cold worker)}
        {--force : Allow a database whose name does not end in _load}';

    protected $description = 'PRUEBA DE CARGA: mide tiempo y memoria pico de las rutas pesadas (nunca en producción)';

    private const TEMPLATE_META = 'loadtest_b1';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (app()->environment('production') || (! str_ends_with($database, '_load') && ! $this->option('force'))) {
            $this->error("Solo para una BD de prueba de carga (*_load). BD actual: {$database}");

            return self::FAILURE;
        }

        $user = $this->option('user')
            ? User::where('email', $this->option('user'))->firstOrFail()
            : User::whereHas('role', fn ($q) => $q->where('code', 'R01_SUPERADMIN'))->firstOrFail();
        $buc = DB::table('geographic_units')->where('type', 'municipality')->where('canonical_name', 'Bucaramanga')->value('id');
        $bucLider = DB::table('lideres')->where('geographic_unit_id', $buc)->where('tipo', 'directorio')->where('is_active', true)->value('id');
        $referente = DB::table('lideres')->whereNotNull('referente_documento')
            ->groupBy('referente_documento')->orderByRaw('count(*) desc')->value('referente_documento');
        $referenteId = DB::table('lideres')->where('cedula', $referente)->value('id');
        $templateId = $this->template($user);

        app()->instance('middleware.disable', true);
        Auth::guard('web')->setUser($user);
        config(['services.n8n.whatsapp_webhook' => 'https://n8n.loadtest.invalid/webhook']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $scenarios = [
            'mapa_buc' => ['GET', '/mapa-politico', ['municipio' => $buc]],
            'mapa_area_metro' => ['GET', '/mapa-politico', ['provincia' => 'Área Metropolitana']],
            'mapa_sin_filtro' => ['GET', '/mapa-politico', []],
            'mapa_buc_search' => ['GET', '/mapa-politico', ['municipio' => $buc, 'search' => 'GARCIA']],
            'mapa_buc_cargo' => ['GET', '/mapa-politico', ['municipio' => $buc, 'cargo' => 'Líder']],
            'mapa_buc_votantes' => ['GET', '/mapa-politico', ['municipio' => $buc, 'tipo' => 'votantes']],
            'mapa_seccion_votantes' => ['GET', '/mapa-politico/seccion', ['municipio' => $buc, 'seccion' => 'votantes', 'offset' => 400]],
            'votantes_buc' => ['GET', '/votantes', ['municipio' => 'Bucaramanga']],
            'votantes_search' => ['GET', '/votantes', ['search' => 'garcia']],
            'votantes_sin_filtro' => ['GET', '/votantes', []],
            'export_xlsx_mapa_buc' => ['GET', '/exportar/excel', ['source' => 'mapa-politico', 'municipio' => $buc]],
            'export_pdf_mapa_buc' => ['GET', '/exportar/pdf', ['source' => 'mapa-politico', 'municipio' => $buc]],
            'export_xlsx_votantes_buc' => ['GET', '/exportar/excel', ['source' => 'votantes', 'municipio' => 'Bucaramanga']],
            'export_xlsx_votantes_todo' => ['GET', '/exportar/excel', ['source' => 'votantes']],
            'export_pdf_votantes_buc' => ['GET', '/exportar/pdf', ['source' => 'votantes', 'municipio' => 'Bucaramanga']],
            'offline_buc' => ['GET', "/api/offline/download/{$buc}", []],
            'agent_mapa_buc' => ['GET', '/api/agent/v1/mapa', ['municipio_id' => $buc, 'per_page' => 20]],
            'agent_mapa_buc_p50' => ['GET', '/api/agent/v1/mapa', ['municipio_id' => $buc, 'per_page' => 20, 'page' => 50]],
            'agent_mapa_conteo' => ['GET', '/api/agent/v1/mapa', ['municipio_id' => $buc, 'solo_conteo' => 1]],
            'agent_mapa_export_buc' => ['GET', '/api/agent/v1/mapa/export', ['municipio_id' => $buc, 'formato' => 'xlsx']],
            'agent_votantes_buc' => ['GET', '/api/agent/v1/votantes', ['municipio_id' => $buc]],
            'agent_votantes_export_buc' => ['GET', '/api/agent/v1/votantes/export', ['municipio_id' => $buc]],
            'whatsapp_index' => ['GET', '/admin/whatsapp', ['source' => 'lideres', 'template_id' => $templateId]],
            'whatsapp_index_p200' => ['GET', '/admin/whatsapp', ['source' => 'lideres', 'template_id' => $templateId, 'page' => 200]],
            'whatsapp_prepare_buc' => ['POST', '/admin/whatsapp/prepare', ['template_id' => $templateId, 'select_all_matching' => 1,
                'source' => 'lideres', 'filter_municipio' => 'Bucaramanga']],
            'whatsapp_prepare_socorro' => ['POST', '/admin/whatsapp/prepare', ['template_id' => $templateId, 'select_all_matching' => 1,
                'source' => 'lideres', 'filter_municipio' => 'Socorro']],
            'referidos_2k' => ['GET', "/votantes/{$referenteId}/referidos", []],
            'votante_show_referente' => ['GET', "/votantes/{$referenteId}", []],
            'duplicados_buc' => ['GET', "/mapa-politico/persona/{$bucLider}/posibles-duplicados", []],
            'lider_municipio_buc' => ['GET', "/lideres/{$buc}", []],
            'admin_cargos' => ['GET', '/admin/cargos', []],
            'search_api' => ['GET', '/api/search', ['q' => 'garcia']],
            'eventos_territorio_buc' => ['GET', '/eventos/territorio', ['municipio' => $buc]],
            'eventos_territorio_xlsx_buc' => ['GET', '/eventos/territorio/excel', ['municipio' => $buc]],
            'agent_eventos_territorio_buc' => ['GET', '/api/agent/v1/eventos/territorio', ['municipio_id' => $buc]],
        ];
        if ($only = $this->option('only')) {
            $scenarios = array_intersect_key($scenarios, array_flip(explode(',', $only)));
        }

        $kernel = app(Kernel::class);
        $results = [];
        $queries = [];
        if ($this->option('queries')) {
            DB::listen(function ($q) use (&$queries) {
                $queries[] = [$q->time, mb_substr(preg_replace('/\s+/', ' ', $q->sql), 0, 220)];
            });
        }
        foreach ($scenarios as $key => [$method, $uri, $params]) {
            for ($run = 1; $run <= max(1, (int) $this->option('repeat')); $run++) {
                $results[$key] = $this->measure($kernel, $user, $method, $uri, $params);
            }
            $r = $results[$key];
            $this->line(sprintf('%-28s %4s %8.0f ms %8.1f MB %10s B %s', $key, $r['status'], $r['ms'], $r['mb'], number_format($r['bytes']), $r['note']));
            if ($queries) {
                $this->line(sprintf('    %d consultas, %.1f ms en SQL', count($queries), array_sum(array_column($queries, 0))));
                usort($queries, fn ($a, $b) => $b[0] <=> $a[0]);
                foreach (array_slice($queries, 0, 6) as [$ms, $sql]) {
                    $this->line(sprintf('    %7.1f ms  %s', $ms, $sql));
                }
                $queries = [];
            }
        }

        DB::table('whatsapp_send_recipients')->whereIn('whatsapp_send_id', DB::table('whatsapp_sends')->where('template_id', $templateId)->select('id'))->delete();
        DB::table('whatsapp_sends')->where('template_id', $templateId)->delete();
        DB::table('whatsapp_templates')->where('id', $templateId)->delete();

        if ($file = $this->option('json')) {
            file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function measure(Kernel $kernel, User $user, string $method, string $uri, array $params): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $start = hrtime(true);
        $bytes = 0;
        $note = '';
        try {
            $request = Request::create($uri, $method, $params);
            $request->headers->set('X-Inertia', 'true');
            $request->headers->set('Accept', 'application/json, text/html');
            $request->setUserResolver(fn () => $user);
            $response = $kernel->handle($request);
            if ($response instanceof StreamedResponse) {
                ob_start(function (string $buffer) use (&$bytes) {
                    $bytes += strlen($buffer);

                    return '';
                }, 65536);
                $response->sendContent();
                ob_end_clean();
            } elseif ($response instanceof BinaryFileResponse) {
                $bytes = $response->getFile()->getSize();
            } else {
                $bytes = strlen((string) $response->getContent());
            }
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $note = mb_substr(strip_tags((string) $response->getContent()), 0, 120);
            }
            $kernel->terminate($request, $response);
        } catch (Throwable $e) {
            $status = 'ERR';
            $note = get_class($e).': '.mb_substr($e->getMessage(), 0, 150);
        }

        return [
            'status' => $status,
            'ms' => (hrtime(true) - $start) / 1e6,
            'mb' => (memory_get_peak_usage() - $base) / 1048576,
            'bytes' => $bytes,
            'note' => $note,
        ];
    }

    private function template(User $user): int
    {
        DB::table('whatsapp_templates')->where('meta_name', self::TEMPLATE_META)->delete();

        return (int) DB::table('whatsapp_templates')->insertGetId([
            'name' => 'Prueba de carga', 'meta_name' => self::TEMPLATE_META, 'body_text' => 'Hola {{1}}',
            'language' => 'es', 'is_active' => true, 'filters' => json_encode(['municipio']),
            'campaign_id' => $user->campaign_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
