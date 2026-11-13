<?php

namespace App\Providers;

use App\Services\Eventos\Ocr\AnthropicOcrClient;
use App\Services\Eventos\Ocr\OcrClient;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\ComunaCatalog;
use App\Support\CurrentCampaign;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCampaign::class);
        // Per request: they memoize the comunas, barrios and matches they read.
        $this->app->scoped(ComunaCatalog::class);
        $this->app->scoped(BarrioMatcher::class);
        $this->app->scoped(ComunaAssigner::class);
        // Tests bind a fake here: they never call the real API.
        $this->app->bind(OcrClient::class, AnthropicOcrClient::class);
    }

    public function boot(): void
    {
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute(300)->by('agent|'.$request->ip()));
        RateLimiter::for('agent-link', fn (Request $request) => [
            Limit::perMinute(60)->by('agent-link|'.$request->ip()),
            Limit::perMinute(10)->by('agent-link-phone|'.(is_string($request->input('phone')) ? $request->input('phone') : '')),
        ]);
        RateLimiter::for('agent-user', fn (Request $request) => Limit::perMinute(60)
            ->by('agent-user|'.($request->user()?->id ?? $request->ip())));
        // PDF rendering is expensive: a tighter quota on top of agent-user.
        RateLimiter::for('agent-reports', fn (Request $request) => Limit::perMinute(10)
            ->by('agent-reports|'.($request->user()?->id ?? $request->ip())));
        // Listing and export of votantes with contact data: a tighter quota on top of agent-user.
        RateLimiter::for('agent-votantes-read', fn (Request $request) => Limit::perMinute(max(1, (int) config('agent.votantes_read_per_minute', 20)))
            ->by('agent-votantes-read|'.($request->user()?->id ?? $request->ip())));

        // Public event registration (QR), per IP. Generous because attendees often share the venue Wi-Fi or a carrier
        // NAT. The per-event quota is counted in EventoPublicoController on real registrations only, so bots cannot spend it.
        RateLimiter::for('eventos-publico', fn (Request $request) => Limit::perMinute(60)->by('eventos-ip|'.$request->ip()));

        // The sign-in sheet PDF takes up to ~120 MB to render (20 pages): 10 per minute per user on the 2 GB VPS.
        // HEAD (the app's probe before navigating) renders nothing: its own, wider quota, apart from the downloads.
        RateLimiter::for('eventos-planilla', fn (Request $request) => $request->isMethod('HEAD')
            ? Limit::perMinute(60)->by('eventos-planilla-head|'.($request->user()?->id ?? $request->ip()))
            : Limit::perMinute(10)->by('eventos-planilla|'.($request->user()?->id ?? $request->ip())));

        // Excel imports of attendees parse and validate up to 2000 rows in the request: upload, mapping and confirm
        // share a per-user quota on the 2 GB VPS.
        RateLimiter::for('eventos-importar', fn (Request $request) => Limit::perMinute(20)
            ->by('eventos-importar|'.($request->user()?->id ?? $request->ip())));
        // Correcting or skipping preview rows (one request per click) and discarding: cheap, but still bounded.
        RateLimiter::for('eventos-importar-fila', fn (Request $request) => Limit::perMinute(120)
            ->by('eventos-importar-fila|'.($request->user()?->id ?? $request->ip())));
        // AI reading of printed sheets: one request per photo uploaded and one per page read (each waits for the API).
        RateLimiter::for('eventos-ocr', fn (Request $request) => Limit::perMinute(60)
            ->by('eventos-ocr|'.($request->user()?->id ?? $request->ip())));

        // Force HTTPS and correct host when behind ngrok/proxy
        if ($this->app->environment('local') && str_contains(config('app.url'), 'ngrok')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }
    }
}
