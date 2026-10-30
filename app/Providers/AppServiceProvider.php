<?php

namespace App\Providers;

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

        // Force HTTPS and correct host when behind ngrok/proxy
        if ($this->app->environment('local') && str_contains(config('app.url'), 'ngrok')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }
    }
}
