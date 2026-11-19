<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppDailyAnalytic;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WhatsAppAnalyticsController extends Controller
{
    /**
     * WABA autorizada para almacenar estas analíticas.
     */
    private const WABA_ID = '1774484810096337';

    /**
     * Recibe y almacena las métricas diarias enviadas por n8n.
     *
     * Los registros existentes se actualizan para permitir
     * correcciones de Meta durante los días posteriores al envío.
     */
    public function storeDaily(Request $request): JsonResponse
    {
        $expectedToken = (string) config('services.n8n.whatsapp_analytics_token');

        $providedToken = (string) (
            $request->header('X-WhatsApp-Analytics-Token')
            ?: $request->bearerToken()
            ?: ''
        );

        if (
            $expectedToken === ''
            || $providedToken === ''
            || ! hash_equals($expectedToken, $providedToken)
        ) {
            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'waba_id' => [
                'required',
                'string',
                Rule::in([self::WABA_ID]),
            ],

            'data' => [
                'required',
                'array',
                'min:1',
            ],

            'data.*.date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'data.*.sent' => [
                'required',
                'integer',
                'min:0',
            ],

            'data.*.delivered' => [
                'required',
                'integer',
                'min:0',
            ],

            'data.*.volume' => [
                'required',
                'integer',
                'min:0',
            ],

            'data.*.cost' => [
                'required',
                'numeric',
                'min:0',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos enviados no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $now = now();

        $records = collect($validated['data'])
            ->map(function (array $item) use ($validated, $now) {
                return [
                    'waba_id' => $validated['waba_id'],
                    'date' => $item['date'],
                    'sent' => $item['sent'],
                    'delivered' => $item['delivered'],
                    'volume' => $item['volume'],
                    'cost' => $item['cost'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->all();

        WhatsAppDailyAnalytic::upsert(
            $records,
            ['waba_id', 'date'],
            [
                'sent',
                'delivered',
                'volume',
                'cost',
                'updated_at',
            ]
        );

        return response()->json([
            'message' => 'Analíticas diarias almacenadas correctamente.',
            'waba_id' => $validated['waba_id'],
            'records_received' => count($records),
        ]);
    }

    /**
     * Consulta las métricas agregadas de una semana ISO.
     *
     * La semana comienza el lunes y termina el domingo.
     */
    public function weekly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => [
                'nullable',
                'integer',
                'min:2000',
                'max:2100',
            ],

            'week' => [
                'nullable',
                'integer',
                'min:1',
                'max:53',
            ],
        ]);

        $currentDate = Carbon::now();

        $year = (int) ($validated['year'] ?? $currentDate->isoWeekYear);

        $week = (int) ($validated['week'] ?? $currentDate->isoWeek);

        $startDate = Carbon::now()
            ->setISODate($year, $week, 1)
            ->startOfDay();

        // Evita aceptar una semana que no existe para ese año ISO.
        if (
            $startDate->isoWeekYear !== $year
            || $startDate->isoWeek !== $week
        ) {
            return response()->json([
                'message' => 'La semana seleccionada no es válida para ese año.',
            ], 422);
        }

        $endDate = $startDate->copy()
            ->addDays(6)
            ->endOfDay();

        $analytics = WhatsAppDailyAnalytic::query()
            ->where('waba_id', self::WABA_ID)
            ->whereBetween('date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->selectRaw('
                COALESCE(SUM(sent), 0) AS sent,
                COALESCE(SUM(delivered), 0) AS delivered,
                COALESCE(SUM(volume), 0) AS volume,
                COALESCE(SUM(cost), 0) AS cost
            ')
            ->first();

        $sent = (int) $analytics->sent;
        $delivered = (int) $analytics->delivered;
        $volume = (int) $analytics->volume;
        $cost = (float) $analytics->cost;

        $costPerDelivered = $delivered > 0
            ? round($cost / $delivered, 6)
            : 0;

        return response()->json([
            'waba_id' => self::WABA_ID,
            'year' => $year,
            'week' => $week,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'currency' => 'USD',

            'spend' => round($cost, 6),
            'cost_per_delivered' => $costPerDelivered,

            'sent' => $sent,
            'delivered' => $delivered,
        ]);
    }
}