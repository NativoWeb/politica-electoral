<?php

namespace App\Http\Controllers;

use App\Actions\Eventos\RegisterAttendee;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\CampaignEvent;
use App\Support\Provincias;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// Public registration by QR (/e/{token}, no login). The success answer is always the same, whether the cédula
// was new, already a lider of the campaign or already registered at the event, so the form never reveals who
// exists. Bots (honeypot filled, missing or expired form token, or a submit faster than MIN_SECONDS) get that same
// answer and nothing is stored. The per-event hourly quota only counts registrations that passed those checks and
// validation, so bot traffic cannot close the form for everybody.
class EventoPublicoController extends Controller
{
    public const THANKS = '¡Gracias, quedó registrado!';

    private const MIN_SECONDS = 4;

    private const MAX_TOKEN_AGE = 6 * 3600;

    public const EVENT_HOURLY_LIMIT = 3000;

    private const EVENT_HOURLY_WARNING = 2400;

    public function show(Request $request, string $token): Response
    {
        $event = $this->findByToken($token);

        return $this->page($request, $event, false, null);
    }

    public function store(Request $request, string $token, RegisterAttendee $register): Response|JsonResponse
    {
        $event = $this->findByToken($token);
        if (! EventoController::publicOpen($event)) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Este evento ya no recibe registros.'], 410)
                : $this->page($request, $event, false, null);
        }

        if ($this->looksLikeBot($request, $event)) {
            return $this->thanks($request, $event);
        }

        $data = Validator::make(
            AttendeeInput::normalize($request->only(AttendeeInput::FIELDS)),
            AttendeeInput::rules(),
            AttendeeInput::messages(),
            AttendeeInput::attributes(),
        )->validate();

        $this->countAgainstEventQuota($event);

        $result = $register->handle($event, $data, 'qr', null, $request->ip(), $request->userAgent());
        if (! $result['created']) {
            // A repeated cédula answers faster than a new one: blur the timing so it does not reveal that either.
            usleep(random_int(50_000, 150_000));
        }

        return $this->thanks($request, $event);
    }

    private function findByToken(string $token): CampaignEvent
    {
        abort_unless(strlen($token) >= 20 && strlen($token) <= 64, 404);

        return CampaignEvent::with(['type', 'municipio', 'provincia', 'barrios'])->where('public_token', $token)->firstOrFail();
    }

    public static function quotaKey(CampaignEvent $event): string
    {
        return 'eventos-evento|'.$event->id;
    }

    private function countAgainstEventQuota(CampaignEvent $event): void
    {
        $key = self::quotaKey($event);
        if (RateLimiter::tooManyAttempts($key, self::EVENT_HOURLY_LIMIT)) {
            abort(429, 'Hay demasiados registros en este momento. Intente de nuevo en unos minutos.', ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $attempts = RateLimiter::hit($key, 3600);
        if ($attempts === self::EVENT_HOURLY_WARNING) {
            Log::warning('Eventos: registro público cerca del tope por hora', [
                'event_id' => $event->id, 'attempts' => $attempts, 'limit' => self::EVENT_HOURLY_LIMIT,
            ]);
        }
    }

    private function looksLikeBot(Request $request, CampaignEvent $event): bool
    {
        if (filled($request->input('website'))) {
            return true;
        }

        try {
            $issued = json_decode(Crypt::decryptString((string) $request->input('form_token')), true);
        } catch (DecryptException) {
            return true;
        }

        return ! is_array($issued)
            || ($issued['e'] ?? null) !== $event->id
            || ! is_int($issued['t'] ?? null)
            || now()->timestamp - $issued['t'] < self::MIN_SECONDS
            || now()->timestamp - $issued['t'] > self::MAX_TOKEN_AGE;
    }

    private function thanks(Request $request, CampaignEvent $event): Response|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => self::THANKS]);
        }

        return $this->page($request, $event, true, self::THANKS);
    }

    // 200 with the form while the QR is open; 410 with estado "cerrado" (and no form data) otherwise.
    private function page(Request $request, CampaignEvent $event, bool $submitted, ?string $message): Response
    {
        $open = EventoController::publicOpen($event);
        $tz = EventoController::TZ;

        $props = [
            'estado' => $open ? 'abierto' : 'cerrado',
            'evento' => [
                'nombre' => $event->nombre,
                'descripcion' => $event->descripcion,
                'tipo' => $event->type?->nombre,
                'starts_at' => $event->starts_at?->copy()->setTimezone($tz)->toIso8601String(),
                'ends_at' => $event->ends_at?->copy()->setTimezone($tz)->toIso8601String(),
                'sitio_encuentro' => $event->sitio_encuentro,
                'municipio' => $event->municipio?->canonical_name,
                'provincia' => Provincias::display($event->provincia?->canonical_name),
            ],
            'barrios' => $open ? $event->barrios->pluck('nombre')->values()->all() : [],
            'form_token' => $open ? Crypt::encryptString(json_encode(['e' => $event->id, 't' => now()->timestamp])) : null,
            'client_uuid' => $open ? (string) Str::uuid() : null,
            'submitted' => $submitted,
            'message' => $message,
        ];

        return Inertia::render('Eventos/Publico', $props)->toResponse($request)->setStatusCode($open ? 200 : 410);
    }
}
