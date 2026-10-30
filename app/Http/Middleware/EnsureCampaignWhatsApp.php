<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Bulk WhatsApp (templates, recipients, sends, history) only for users whose own campaign has WhatsApp enabled.
class EnsureCampaignWhatsApp
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->campaignHasWhatsapp(), 403, 'Tu campaña no tiene habilitado WhatsApp.');

        return $next($request);
    }
}
