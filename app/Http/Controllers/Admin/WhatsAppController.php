<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lider;
use App\Models\Person;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        /*
         * Origen de los destinatarios:
         *
         * todos     = Personas + Líderes
         * votantes  = Personas
         * lideres   = Líderes
         */
        $source = $request->input('source', 'todos');

        if (!in_array($source, ['todos', 'votantes', 'lideres'], true)) {
            $source = 'todos';
        }

        /*
         * ---------------------------------------------------------
         * PERSONAS / VOTANTES
         * ---------------------------------------------------------
         */
        $persons = collect();

        if (in_array($source, ['todos', 'votantes'], true)) {
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
                ->get()
                ->map(function (Person $person) {
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
                        'source' => 'votante',
                    ];
                });
        }

        /*
         * ---------------------------------------------------------
         * LÍDERES
         * ---------------------------------------------------------
         */
        $lideres = collect();

        if (in_array($source, ['todos', 'lideres'], true)) {
            $query = Lider::query();

            if ($user?->hasTerritoryScopeRestriction()) {
                $allowedGeoIds = $user->allowedGeoIds();

                if (!empty($allowedGeoIds)) {
                    $query->whereIn('geographic_unit_id', $allowedGeoIds);
                }
            }

            if ($search = trim((string) $request->input('search'))) {
                $query->where(function ($q) use ($search) {
                    $q->where('nombre', 'ilike', "%{$search}%")
                        ->orWhere('telefono', 'ilike', "%{$search}%")
                        ->orWhereHas('contactos', function ($contactQuery) use ($search) {
                            $contactQuery
                                ->where('valor', 'ilike', "%{$search}%");
                        });
                });
            }

            if ($birthMonth = $request->integer('birth_month')) {
                if ($birthMonth >= 1 && $birthMonth <= 12) {
                    $query->whereMonth('fecha_nacimiento', $birthMonth);
                }
            }

            if ($birthDay = $request->integer('birth_day')) {
                if ($birthDay >= 1 && $birthDay <= 31) {
                    $query->whereDay('fecha_nacimiento', $birthDay);
                }
            }

            $lideres = $query
                ->with([
                    'contactos' => function ($q) {
                        $q->where(function ($contactQuery) {
                            $contactQuery
                                ->where('estado', true)
                                ->orWhereNull('estado');
                        })
                        ->orderByDesc('principal');
                    },
                    'geographicUnit',
                ])
                ->select([
                    'id',
                    'nombre',
                    'telefono',
                    'fecha_nacimiento',
                    'municipio',
                    'partido',
                    'cargo',
                    'geographic_unit_id',
                ])
                ->get()
                ->map(function (Lider $lider) {
                    /*
                     * Primero usamos el teléfono principal de la
                     * tabla lideres. Si no existe, buscamos uno
                     * dentro de lider_contactos.
                     */
                    $phone = $lider->telefono;

                    if (!$phone) {
                        $contacto = $lider->contactos->first();
                        $phone = $contacto?->valor;
                    }

                    return [
                        'id' => $lider->id,
                        'name' => $lider->nombre,
                        'phone' => $phone,
                        'birth_date' => $lider->fecha_nacimiento?->format('Y-m-d'),
                        'municipio' => $lider->geographicUnit?->canonical_name
                            ?? $lider->municipio,
                        'partido' => $lider->partido,
                        'cargo' => $lider->cargo,
                        'tipo_registro' => 'Líder',
                        'source' => 'lider',
                    ];
                });
        }

        /*
         * ---------------------------------------------------------
         * UNIFICAR RESULTADOS
         * ---------------------------------------------------------
         *
         * No modificamos el funcionamiento del checkmark.
         * React seguirá recibiendo un "id" por contacto.
         */
        $contacts = $persons
            ->concat($lideres)
            ->sortBy(fn ($contact) => mb_strtolower($contact['name'] ?? ''))
            ->values();

        /*
         * ---------------------------------------------------------
         * PAGINACIÓN
         * ---------------------------------------------------------
         *
         * Antes Laravel paginaba directamente la consulta de Person.
         * Ahora tenemos dos fuentes, por lo que paginamos la colección
         * combinada manteniendo el mismo formato de paginación.
         */
        $perPage = 100;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        $currentItems = $contacts
            ->slice(($currentPage - 1) * $perPage, $perPage)
            ->values();

        $paginatedContacts = new LengthAwarePaginator(
            $currentItems,
            $contacts->count(),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        /*
         * ---------------------------------------------------------
         * PLANTILLAS
         * ---------------------------------------------------------
         */
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
            'contacts' => $paginatedContacts,
            'templates' => $templates,

            'filters' => [
                'search' => $request->input('search'),
                'birth_month' => $request->input('birth_month'),
                'birth_day' => $request->input('birth_day'),
                'source' => $source,
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
            'source' => ['nullable', 'in:todos,votantes,lideres'],
        ]);

        $source = $validated['source'] ?? 'todos';

        $user = $request->user();

        /*
         * ---------------------------------------------------------
         * DESTINATARIOS PERSONAS / VOTANTES
         * ---------------------------------------------------------
         */
        $persons = collect();

        if (in_array($source, ['todos', 'votantes'], true)) {
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
                ->get()
                ->map(function (Person $person) {
                    $contact = $person->contactPoints->first();

                    return [
                        'id' => $person->id,
                        'name' => $person->full_name,
                        'phone' => $contact?->value_normalized ?: $contact?->value_raw,
                    ];
                });
        }

        /*
         * ---------------------------------------------------------
         * DESTINATARIOS LÍDERES
         * ---------------------------------------------------------
         */
        $lideres = collect();

        if (in_array($source, ['todos', 'lideres'], true)) {
            $query = Lider::query()
                ->whereIn('lideres.id', $validated['contact_ids'])
                ->where(function ($q) {
                    $q->whereNotNull('telefono')
                        ->where('telefono', '!=', '')
                        ->orWhereHas('contactos', function ($contactQuery) {
                            $contactQuery
                                ->whereNotNull('valor')
                                ->where('valor', '!= '');
                        });
                });

            if ($user?->hasTerritoryScopeRestriction()) {
                $allowedGeoIds = $user->allowedGeoIds();

                if (!empty($allowedGeoIds)) {
                    $query->whereIn('geographic_unit_id', $allowedGeoIds);
                }
            }

            $lideres = $query
                ->with([
                    'contactos' => function ($q) {
                        $q->where(function ($contactQuery) {
                            $contactQuery
                                ->where('estado', true)
                                ->orWhereNull('estado');
                        })
                        ->orderByDesc('principal');
                    },
                ])
                ->get()
                ->map(function (Lider $lider) {
                    $phone = $lider->telefono;

                    if (!$phone) {
                        $contacto = $lider->contactos->first();
                        $phone = $contacto?->valor;
                    }

                    return [
                        'id' => $lider->id,
                        'name' => $lider->nombre,
                        'phone' => $phone,
                    ];
                });
        }

        /*
         * Unificamos los dos tipos de destinatarios.
         */
        $recipients = $persons
            ->concat($lideres)
            ->values()
            ->all();

        if (empty($recipients)) {
            return response()->json([
                'message' => 'No se encontraron destinatarios válidos para el envío.',
            ], 422);
        }

        $template = WhatsAppTemplate::query()
            ->where('is_active', true)
            ->findOrFail($validated['template_id']);

        /*
         * ---------------------------------------------------------
         * CREAR ENVÍO
         * ---------------------------------------------------------
         */
        $send = WhatsAppSend::create([
            'template_id' => $template->id,
        ]);

        $sendRecipients = [];

        foreach ($recipients as $recipient) {
            $sendRecipient = WhatsAppSendRecipient::create([
                'whatsapp_send_id' => $send->id,

                /*
                 * Esta columna actualmente es UUID y no tiene
                 * foreign key hacia persons, por lo que puede
                 * almacenar tanto el UUID de una Persona como
                 * el UUID de un Líder.
                 */
                'person_id' => $recipient['id'],

                'name' => $recipient['name'],
                'phone' => $recipient['phone'],
                'status' => 'pendiente',
            ]);

            $sendRecipients[] = $sendRecipient;
        }

        /*
         * ---------------------------------------------------------
         * PAYLOAD PARA N8N
         * ---------------------------------------------------------
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

        /*
         * Webhook de n8n configurado mediante .env.
         */
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
