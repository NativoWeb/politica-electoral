<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Person::query()
            ->whereNull('persons.merged_into_id');

        if ($user?->hasTerritoryScopeRestriction()) {
            $allowedGeoIds = $user->allowedGeoIds();

            if (!empty($allowedGeoIds)) {
                $query->whereHas('candidacies.contest', function ($q) use ($allowedGeoIds) {
                    $q->whereIn('geographic_unit_id', $allowedGeoIds);
                });
            }
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('persons.full_name', 'ilike', "%{$search}%")
                    ->orWhereHas('contactPoints', function ($contactQuery) use ($search) {
                        $contactQuery
                            ->where('type', 'mobile')
                            ->where('is_current', true)
                            ->where(function ($phoneQuery) use ($search) {
                                $phoneQuery
                                    ->where('value_normalized', 'ilike', "%{$search}%")
                                    ->orWhere('value_raw', 'ilike', "%{$search}%");
                            });
                    });
            });
        }

        if ($birthMonth = $request->integer('birth_month')) {
            if ($birthMonth >= 1 && $birthMonth <= 12) {
                $query->whereMonth('birth_date', $birthMonth);
            }
        }

        if ($birthDay = $request->integer('birth_day')) {
            if ($birthDay >= 1 && $birthDay <= 31) {
                $query->whereDay('birth_date', $birthDay);
            }
        }

        $persons = $query
            ->with([
                'contactPoints' => function ($q) {
                    $q->select([
                        'id',
                        'person_id',
                        'type',
                        'value_raw',
                        'value_normalized',
                        'is_current',
                        'allow_export',
                    ])
                    ->where('type', 'mobile')
                    ->where('is_current', true)
                    ->where(function ($phoneQuery) {
                        $phoneQuery
                            ->whereNotNull('value_normalized')
                            ->where('value_normalized', '!=', '')
                            ->orWhere(function ($rawQuery) {
                                $rawQuery
                                    ->whereNotNull('value_raw')
                                    ->where('value_raw', '!=', '');
                            });
                    })
                    ->orderByDesc('type');
                },
                'candidacies.electoralList.organization',
                'candidacies.contest.office',
                'candidacies.contest.corporation',
                'candidacies.contest.geographicUnit',
            ])
            ->select([
                'persons.id',
                'persons.full_name',
                'persons.birth_date',
            ])
            ->orderBy('persons.full_name')
            ->paginate(100)
            ->withQueryString()
            ->through(function (Person $person) {
                $contact = $person->contactPoints->first();

                $candidacy = $person->candidacies
                    ->sortByDesc(fn ($c) => $c->outcome_date?->timestamp ?? 0)
                    ->first();

                $contest = $candidacy?->contest;
                $organization = $candidacy?->electoralList?->organization;

                $cargo = $contest?->office?->name
                    ?? $contest?->corporation?->name;

                return [
                    'id' => $person->id,
                    'name' => $person->full_name,
                    'phone' => $contact?->value_normalized ?: $contact?->value_raw,
                    'birth_date' => $person->birth_date?->format('Y-m-d'),
                    'municipio' => $contest?->geographicUnit?->canonical_name,
                    'partido' => $organization?->canonical_name,
                    'cargo' => $cargo,
                    'tipo_registro' => $candidacy ? 'Candidato' : 'Persona',
                ];
            });

        $templates = WhatsAppTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'meta_name',
                'body_text',
                'variables',
                'header_type',
                'header_text',
                'language',
                'category',
                'description',
            ]);

        return Inertia::render('Admin/WhatsApp', [
            'contacts' => $persons,
            'templates' => $templates,
            'filters' => [
                'search' => $request->input('search'),
                'birth_month' => $request->input('birth_month'),
                'birth_day' => $request->input('birth_day'),
            ],
        ]);
    }

    public function prepare(Request $request)
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['uuid'],
            'variables' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url'],
            'image' => ['nullable', 'image', 'max:10240'],
        ]);

        $user = $request->user();

        $query = Person::query()
            ->whereNull('persons.merged_into_id')
            ->whereIn('persons.id', $validated['contact_ids'])
            ->whereHas('contactPoints', function ($q) {
                $q->where('type', 'mobile')
                    ->where('is_current', true)
                    ->where(function ($phoneQuery) {
                        $phoneQuery
                            ->whereNotNull('value_normalized')
                            ->where('value_normalized', '!=', '')
                            ->orWhere(function ($rawQuery) {
                                $rawQuery
                                    ->whereNotNull('value_raw')
                                    ->where('value_raw', '!=', '');
                            });
                    });
            });

        if ($user?->hasTerritoryScopeRestriction()) {
            $allowedGeoIds = $user->allowedGeoIds();

            if (!empty($allowedGeoIds)) {
                $query->whereHas('candidacies.contest', function ($q) use ($allowedGeoIds) {
                    $q->whereIn('geographic_unit_id', $allowedGeoIds);
                });
            }
        }

        $persons = $query
            ->with([
                'contactPoints' => function ($q) {
                    $q->where('type', 'mobile')
                        ->where('is_current', true)
                        ->where(function ($phoneQuery) {
                            $phoneQuery
                                ->whereNotNull('value_normalized')
                                ->where('value_normalized', '!=', '')
                                ->orWhere(function ($rawQuery) {
                                    $rawQuery
                                        ->whereNotNull('value_raw')
                                        ->where('value_raw', '!=', '');
                                });
                        });
                },
            ])
            ->get();

        $template = WhatsAppTemplate::query()
            ->where('is_active', true)
            ->findOrFail($validated['template_id']);

        $recipients = $persons->map(function (Person $person) {
            $contact = $person->contactPoints->first();

            return [
                'id' => $person->id,
                'name' => $person->full_name,
                'phone' => $contact?->value_normalized ?: $contact?->value_raw,
            ];
        })->values()->all();

        if (empty($recipients)) {
            return response()->json([
                'message' => 'No se encontraron destinatarios válidos para el envío.',
            ], 422);
        }

        /*
         * Creamos el envío antes de llamar a n8n porque n8n
         * necesitará conocer el ID del envío para actualizar
         * posteriormente los estados de cada destinatario.
         */
        $send = WhatsAppSend::create([
            'template_id' => $template->id,
        ]);

        /*
         * Registramos cada destinatario con estado inicial
         * "pendiente".
         */
        $sendRecipients = [];

        foreach ($recipients as $recipient) {
            $sendRecipient = WhatsAppSendRecipient::create([
                'whatsapp_send_id' => $send->id,
                'person_id' => $recipient['id'],
                'name' => $recipient['name'],
                'phone' => $recipient['phone'],
                'status' => 'pendiente',
            ]);

            $sendRecipients[] = $sendRecipient;

            $recipient['send_recipient_id'] = $sendRecipient->id;
            $recipient['send_id'] = $send->id;
        }

        /*
         * Volvemos a construir los destinatarios con sus IDs
         * internos para enviarlos a n8n.
         */
        $recipients = collect($sendRecipients)
            ->map(function (WhatsAppSendRecipient $recipient) {
                return [
                    'id' => $recipient->person_id,
                    'send_recipient_id' => $recipient->id,
                    'send_id' => $recipient->whatsapp_send_id,
                    'name' => $recipient->name,
                    'phone' => $recipient->phone,
                ];
            })
            ->values()
            ->all();

        $payload = [
            'send_id' => $send->id,

            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'meta_name' => $template->meta_name,
                'language' => $template->language,
                'category' => $template->category,
                'body_text' => $template->body_text,
            ],

            'recipients' => $recipients,

            'variables' => $validated['variables'] ?? [],

            'image_url' => $validated['image_url'] ?? null,
        ];

        // Webhook de n8n configurado mediante .env.
        $webhook = config('services.n8n.whatsapp_webhook');

        if ($request->hasFile('image')) {
            $image = $request->file('image');

            $response = Http::attach(
                'image',
                fopen($image->getRealPath(), 'r'),
                $image->getClientOriginalName()
            )->post($webhook, [
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } else {
            $response = Http::post($webhook, $payload);
        }

        if ($response->failed()) {
            /*
             * Si n8n falla, eliminamos el envío y sus
             * destinatarios para no dejar un registro incompleto.
             */
            $send->delete();

            return response()->json([
                'message' => 'No fue posible enviar los datos a n8n.',
                'n8n_response' => $response->body(),
            ], 502);
        }

        return response()->json([
            'message' => 'Datos enviados correctamente a n8n.',
            'send_id' => $send->id,
            'recipients_count' => count($recipients),
        ]);
    }

    public function updateRecipientStatus(
        Request $request,
        WhatsAppSendRecipient $recipient
    ) {
        $validated = $request->validate([
            'status' => ['required', 'in:pendiente,enviando,enviado,error'],
        ]);

        $recipient->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'recipient_id' => $recipient->id,
            'status' => $recipient->status,
        ]);
    }

    public function status(WhatsAppSend $send)
    {
        $send->load([
            'recipients:id,whatsapp_send_id,person_id,name,phone,status',
        ]);

        return response()->json([
            'send_id' => $send->id,
            'recipients' => $send->recipients,
        ]);
    }
}
