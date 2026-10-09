
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lider;
use App\Models\Person;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    /**
     * Lista de contactos para la interfaz de WhatsApp.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $source = $request->input('source', 'todos');

        if (!in_array($source, ['todos', 'votantes', 'lideres'], true)) {
            $source = 'todos';
        }

        $persons = collect();

        if (in_array($source, ['todos', 'votantes'], true)) {
            $query = Person::query()
                ->whereNull('persons.merged_into_id');

            if ($user?->hasTerritoryScopeRestriction()) {
                $allowedGeoIds = $user->allowedGeoIds();

                if (!empty($allowedGeoIds)) {
                    $query->whereHas(
                        'candidacies.contest',
                        fn ($q) => $q->whereIn(
                            'geographic_unit_id',
                            $allowedGeoIds
                        )
                    );
                }
            }

            if ($search = trim((string) $request->input('search'))) {
                $query->where(function ($q) use ($search) {
                    $q->where('persons.full_name', 'ilike', "%{$search}%")
                        ->orWhereHas('contactPoints', function ($cq) use ($search) {
                            $cq->where('type', 'mobile')
                                ->where('is_current', true)
                                ->where(function ($pq) use ($search) {
                                    $pq->where(
                                        'value_normalized',
                                        'ilike',
                                        "%{$search}%"
                                    )->orWhere(
                                        'value_raw',
                                        'ilike',
                                        "%{$search}%"
                                    );
                                });
                        });
                });
            }

            $birthMonth = $request->integer('birth_month');
            $birthDay = $request->integer('birth_day');

            if ($birthMonth >= 1 && $birthMonth <= 12) {
                $query->whereMonth('birth_date', $birthMonth);
            }

            if ($birthDay >= 1 && $birthDay <= 31) {
                $query->whereDay('birth_date', $birthDay);
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
                        ->where(function ($pq) {
                            $pq->where(function ($a) {
                                $a->whereNotNull('value_normalized')
                                    ->where('value_normalized', '!=', '');
                            })->orWhere(function ($a) {
                                $a->whereNotNull('value_raw')
                                    ->where('value_raw', '!=', '');
                            });
                        });
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
                        ->sortByDesc(
                            fn ($c) => $c->outcome_date?->timestamp ?? 0
                        )
                        ->first();

                    $contest = $candidacy?->contest;
                    $organization = $candidacy?->electoralList?->organization;

                    return [
                        'id' => $person->id,
                        'name' => $person->full_name,
                        'phone' => $contact?->value_normalized
                            ?: $contact?->value_raw,
                        'birth_date' => $this->dateValue($person->birth_date),
                        'municipio' => $contest?->geographicUnit?->canonical_name,
                        'partido' => $organization?->canonical_name,
                        'cargo' => $contest?->office?->name
                            ?? $contest?->corporation?->name,
                        'tipo_registro' => $candidacy ? 'Candidato' : 'Persona',
                        'source' => 'votante',
                    ];
                });
        }

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
                        ->orWhereHas('contactos', function ($cq) use ($search) {
                            $cq->where('valor', 'ilike', "%{$search}%");
                        });
                });
            }

            $birthMonth = $request->integer('birth_month');
            $birthDay = $request->integer('birth_day');

            if ($birthMonth >= 1 && $birthMonth <= 12) {
                $query->whereMonth('fecha_nacimiento', $birthMonth);
            }

            if ($birthDay >= 1 && $birthDay <= 31) {
                $query->whereDay('fecha_nacimiento', $birthDay);
            }

            $lideres = $query
                ->with([
                    'contactos' => function ($q) {
                        $q->where(function ($cq) {
                            $cq->where('estado', true)
                                ->orWhereNull('estado');
                        })->orderByDesc('principal');
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
                    $phone = $lider->telefono;

                    if (!$phone) {
                        $phone = $lider->contactos->first()?->valor;
                    }

                    return [
                        'id' => $lider->id,
                        'name' => $lider->nombre,
                        'phone' => $phone,
                        'birth_date' => $this->dateValue(
                            $lider->fecha_nacimiento
                        ),
                        'municipio' => $lider->geographicUnit?->canonical_name
                            ?? $lider->municipio,
                        'partido' => $lider->partido,
                        'cargo' => $lider->cargo,
                        'tipo_registro' => 'Líder',
                        'source' => 'lider',
                    ];
                });
        }

        $contacts = $persons
            ->concat($lideres)
            ->sortBy(fn ($contact) => mb_strtolower($contact['name'] ?? ''))
            ->values();

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

    /**
     * Prepara un envío manual.
     *
     * Para oscarfc, registra los destinatarios seleccionados como
     * campaña de cumpleaños. No envía texto ni imagen desde Laravel.
     * Las demás plantillas conservan el envío al webhook actual.
     */
    public function prepare(Request $request)
    {
        $validated = $request->validate([
            'template_id' => [
                'required',
                'integer',
                'exists:whatsapp_templates,id',
            ],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['uuid'],
            'variables' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url'],
            'image' => ['nullable', 'image', 'max:10240'],
            'source' => ['nullable', 'in:todos,votantes,lideres'],
        ]);

        $template = WhatsAppTemplate::query()
            ->where('is_active', true)
            ->findOrFail($validated['template_id']);

        $source = $validated['source'] ?? 'todos';
        $user = $request->user();

        $persons = collect();

        if (in_array($source, ['todos', 'votantes'], true)) {
            $query = Person::query()
                ->whereNull('persons.merged_into_id')
                ->whereIn('persons.id', $validated['contact_ids'])
                ->whereHas('contactPoints', function ($q) {
                    $q->where('type', 'mobile')
                        ->where('is_current', true)
                        ->where(function ($pq) {
                            $pq->where(function ($a) {
                                $a->whereNotNull('value_normalized')
                                    ->where('value_normalized', '!=', '');
                            })->orWhere(function ($a) {
                                $a->whereNotNull('value_raw')
                                    ->where('value_raw', '!=', '');
                            });
                        });
                });

            if ($user?->hasTerritoryScopeRestriction()) {
                $allowedGeoIds = $user->allowedGeoIds();

                if (!empty($allowedGeoIds)) {
                    $query->whereHas(
                        'candidacies.contest',
                        fn ($q) => $q->whereIn(
                            'geographic_unit_id',
                            $allowedGeoIds
                        )
                    );
                }
            }

            $persons = $query
                ->with([
                    'contactPoints' => function ($q) {
                        $q->where('type', 'mobile')
                            ->where('is_current', true)
                            ->where(function ($pq) {
                                $pq->where(function ($a) {
                                    $a->whereNotNull('value_normalized')
                                        ->where('value_normalized', '!=', '');
                                })->orWhere(function ($a) {
                                    $a->whereNotNull('value_raw')
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
                        'phone' => $contact?->value_normalized
                            ?: $contact?->value_raw,
                    ];
                });
        }

        $lideres = collect();

        if (in_array($source, ['todos', 'lideres'], true)) {
            $query = Lider::query()
                ->whereIn('lideres.id', $validated['contact_ids'])
                ->where(function ($q) {
                    $q->where(function ($a) {
                        $a->whereNotNull('telefono')
                            ->where('telefono', '!=', '');
                    })->orWhereHas('contactos', function ($cq) {
                        $cq->whereNotNull('valor')
                            ->where('valor', '!=', '');
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
                        $q->where(function ($cq) {
                            $cq->where('estado', true)
                                ->orWhereNull('estado');
                        })->orderByDesc('principal');
                    },
                ])
                ->get()
                ->map(function (Lider $lider) {
                    $phone = $lider->telefono
                        ?: $lider->contactos->first()?->valor;

                    return [
                        'id' => $lider->id,
                        'name' => $lider->nombre,
                        'phone' => $phone,
                    ];
                });
        }

        $recipients = $persons
            ->concat($lideres)
            ->filter(fn ($recipient) => !empty($recipient['phone']))
            ->values();

        if ($recipients->isEmpty()) {
            return response()->json([
                'message' => 'No se encontraron destinatarios válidos para el envío.',
            ], 422);
        }

        $isBirthdayTemplate = $template->meta_name === 'oscarfc';

        $send = DB::transaction(function () use (
            $template,
            $recipients,
            $isBirthdayTemplate
        ) {
            $send = WhatsAppSend::create([
                'template_id' => $template->id,
                'campaign_type' => $isBirthdayTemplate ? 'birthday' : null,
                'campaign_date' => $isBirthdayTemplate
                    ? Carbon::now('America/Bogota')->toDateString()
                    : null,
            ]);

            foreach ($recipients as $recipient) {
                WhatsAppSendRecipient::create([
                    'whatsapp_send_id' => $send->id,
                    'person_id' => $recipient['id'],
                    'name' => $recipient['name'],
                    'phone' => $recipient['phone'],
                    'status' => 'pendiente',
                ]);
            }

            return $send;
        });

        $savedRecipients = $send->recipients()
            ->get(['id', 'whatsapp_send_id', 'person_id', 'name', 'phone', 'status'])
            ->map(fn (WhatsAppSendRecipient $recipient) => [
                'id' => $recipient->person_id,
                'send_recipient_id' => $recipient->id,
                'send_id' => $recipient->whatsapp_send_id,
                'name' => $recipient->name,
                'phone' => $recipient->phone,
                'status' => $recipient->status,
            ])
            ->values();

        /*
         * Cumpleaños: solo se registra la selección.
         * No se manda texto ni imagen desde Laravel.
         * El procesamiento y envío se conectarán con n8n.
         */
        if ($isBirthdayTemplate) {
            return response()->json([
                'message' => 'Destinatarios de cumpleaños registrados.',
                'send_id' => $send->id,
                'recipients_count' => $savedRecipients->count(),
                'recipients' => $savedRecipients,
                'campaign_date' => $send->campaign_date?->toDateString(),
                'queued' => true,
            ]);
        }

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
            'recipients' => $savedRecipients->all(),
            'variables' => $validated['variables'] ?? [],
            'image_url' => $validated['image_url'] ?? null,
        ];

        $webhook = config('services.n8n.whatsapp_webhook');

        if (!$webhook) {
            $send->delete();

            return response()->json([
                'message' => 'No está configurada la URL del webhook de n8n.',
            ], 500);
        }

        try {
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                $response = Http::attach(
                    'image',
                    file_get_contents($image->getRealPath()),
                    $image->getClientOriginalName()
                )->post($webhook, [
                    'payload' => json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE
                    ),
                ]);
            } else {
                $response = Http::post($webhook, $payload);
            }
        } catch (\Throwable $exception) {
            $send->delete();

            report($exception);

            return response()->json([
                'message' => 'No fue posible conectar con n8n.',
            ], 502);
        }

        if ($response->failed()) {
            $send->delete();

            return response()->json([
                'message' => 'No fue posible enviar los datos a n8n.',
                'n8n_response' => $response->body(),
            ], 502);
        }

        return response()->json([
            'message' => 'Datos enviados correctamente a n8n.',
            'send_id' => $send->id,
            'recipients_count' => $savedRecipients->count(),
        ]);
    }

    /**
     * Endpoint para n8n: devuelve la campaña de cumpleaños del día.
     *
     * Si la interfaz ya registró una selección de cumpleaños para hoy,
     * devuelve esos destinatarios. Si no existe una campaña para hoy,
     * crea una con los contactos que cumplen años hoy y tienen teléfono.
     *
     * Autenticación: X-Birthday-Token o Authorization: Bearer <token>.
     */
    public function birthdayRecipients(Request $request)
    {
        if (!$this->hasValidBirthdayToken($request)) {
            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        $today = Carbon::now('America/Bogota')->startOfDay();
        $date = $today->toDateString();

        $template = WhatsAppTemplate::query()
            ->where('meta_name', 'oscarfc')
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return response()->json([
                'message' => 'No se encontró la plantilla activa oscarfc.',
            ], 404);
        }

        $send = WhatsAppSend::query()
            ->where('campaign_type', 'birthday')
            ->whereDate('campaign_date', $date)
            ->where('template_id', $template->id)
            ->latest('id')
            ->first();

        if (!$send) {
            $recipients = $this->getTodayBirthdayRecipients($today);

            $send = DB::transaction(function () use (
                $template,
                $date,
                $recipients
            ) {
                $send = WhatsAppSend::create([
                    'template_id' => $template->id,
                    'campaign_type' => 'birthday',
                    'campaign_date' => $date,
                ]);

                foreach ($recipients as $recipient) {
                    WhatsAppSendRecipient::create([
                        'whatsapp_send_id' => $send->id,
                        'person_id' => $recipient['id'],
                        'name' => $recipient['name'],
                        'phone' => $recipient['phone'],
                        'status' => 'pendiente',
                    ]);
                }

                return $send;
            });
        }

        $savedRecipients = $send->recipients()
            ->orderBy('id')
            ->get([
                'id',
                'whatsapp_send_id',
                'person_id',
                'name',
                'phone',
                'status',
            ])
            ->map(fn (WhatsAppSendRecipient $recipient) => [
                'id' => $recipient->person_id,
                'send_recipient_id' => $recipient->id,
                'send_id' => $recipient->whatsapp_send_id,
                'name' => $recipient->name,
                'phone' => $recipient->phone,
                'status' => $recipient->status,
            ])
            ->values();

        return response()->json([
            'send_id' => $send->id,
            'campaign_date' => $date,
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'meta_name' => $template->meta_name,
                'language' => $template->language,
                'body_text' => $template->body_text,
            ],
            'recipients_count' => $savedRecipients->count(),
            'recipients' => $savedRecipients,
        ]);
    }

    /**
     * Construye la lista de cumpleaños de hoy para personas y líderes.
     */
    private function getTodayBirthdayRecipients(Carbon $today): array
    {
        $month = (int) $today->month;
        $day = (int) $today->day;

        $persons = Person::query()
            ->whereNull('persons.merged_into_id')
            ->whereNotNull('persons.birth_date')
            ->whereMonth('persons.birth_date', $month)
            ->whereDay('persons.birth_date', $day)
            ->whereHas('contactPoints', function ($q) {
                $q->where('type', 'mobile')
                    ->where('is_current', true)
                    ->where(function ($pq) {
                        $pq->where(function ($a) {
                            $a->whereNotNull('value_normalized')
                                ->where('value_normalized', '!=', '');
                        })->orWhere(function ($a) {
                            $a->whereNotNull('value_raw')
                                ->where('value_raw', '!=', '');
                        });
                    });
            })
            ->with([
                'contactPoints' => function ($q) {
                    $q->where('type', 'mobile')
                        ->where('is_current', true)
                        ->where(function ($pq) {
                            $pq->where(function ($a) {
                                $a->whereNotNull('value_normalized')
                                    ->where('value_normalized', '!=', '');
                            })->orWhere(function ($a) {
                                $a->whereNotNull('value_raw')
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
                    'phone' => $contact?->value_normalized
                        ?: $contact?->value_raw,
                ];
            });

        $lideres = Lider::query()
            ->whereNotNull('fecha_nacimiento')
            ->whereMonth('fecha_nacimiento', $month)
            ->whereDay('fecha_nacimiento', $day)
            ->where(function ($q) {
                $q->where(function ($a) {
                    $a->whereNotNull('telefono')
                        ->where('telefono', '!=', '');
                })->orWhereHas('contactos', function ($cq) {
                    $cq->where(function ($active) {
                        $active->where('estado', true)
                            ->orWhereNull('estado');
                    })
                    ->whereNotNull('valor')
                    ->where('valor', '!=', '');
                });
            })
            ->with([
                'contactos' => function ($q) {
                    $q->where(function ($cq) {
                        $cq->where('estado', true)
                            ->orWhereNull('estado');
                    })->orderByDesc('principal');
                },
            ])
            ->get()
            ->map(function (Lider $lider) {
                $phone = $lider->telefono
                    ?: $lider->contactos->first()?->valor;

                return [
                    'id' => $lider->id,
                    'name' => $lider->nombre,
                    'phone' => $phone,
                ];
            });

        return $persons
            ->concat($lideres)
            ->filter(fn ($recipient) => !empty($recipient['phone']))
            ->values()
            ->all();
    }

    /**
     * Actualización de estado usada por la interfaz y los envíos existentes.
     */
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

    /**
     * Actualización de estado protegida para n8n.
     */
    public function updateBirthdayRecipientStatus(
        Request $request,
        WhatsAppSendRecipient $recipient
    ) {
        if (!$this->hasValidBirthdayToken($request)) {
            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        if ($recipient->send?->campaign_type !== 'birthday') {
            return response()->json([
                'message' => 'El destinatario no pertenece a una campaña de cumpleaños.',
            ], 422);
        }

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

    /**
     * Consulta el estado de un envío para el seguimiento de la interfaz.
     */
    public function status(WhatsAppSend $send)
    {
        $send->load([
            'recipients:id,whatsapp_send_id,person_id,name,phone,status',
        ]);

        return response()->json([
            'send_id' => $send->id,
            'campaign_type' => $send->campaign_type,
            'campaign_date' => $this->dateValue($send->campaign_date),
            'recipients' => $send->recipients,
        ]);
    }

    /**
     * Historial de cumpleaños para una fecha concreta.
     * Ruta web: /admin/whatsapp/birthdays/history?date=YYYY-MM-DD
     */
    public function birthdayHistory(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = $validated['date']
            ?? Carbon::now('America/Bogota')->toDateString();

        $sends = WhatsAppSend::query()
            ->with([
                'template:id,name,meta_name',
                'recipients:id,whatsapp_send_id,person_id,name,phone,status,created_at,updated_at',
            ])
            ->where('campaign_type', 'birthday')
            ->whereDate('campaign_date', $date)
            ->orderBy('id')
            ->get();

        $recipients = $sends
            ->flatMap(function (WhatsAppSend $send) {
                return $send->recipients->map(function (
                    WhatsAppSendRecipient $recipient
                ) use ($send) {
                    return [
                        'id' => $recipient->id,
                        'send_id' => $send->id,
                        'name' => $recipient->name,
                        'phone' => $recipient->phone,
                        'status' => $recipient->status,
                        'campaign_date' => $this->dateValue(
                            $send->campaign_date
                        ),
                        'created_at' => $recipient->created_at,
                        'updated_at' => $recipient->updated_at,
                    ];
                });
            })
            ->values();

        return response()->json([
            'date' => $date,
            'campaigns_count' => $sends->count(),
            'recipients_count' => $recipients->count(),
            'summary' => [
                'pendiente' => $recipients->where('status', 'pendiente')->count(),
                'enviando' => $recipients->where('status', 'enviando')->count(),
                'enviado' => $recipients->where('status', 'enviado')->count(),
                'error' => $recipients->where('status', 'error')->count(),
            ],
            'recipients' => $recipients,
        ]);
    }

    /**
     * Verifica el token sin exponerlo en respuestas ni registros.
     */
    private function hasValidBirthdayToken(Request $request): bool
    {
        $configuredToken = (string) config('services.n8n.birthday_token');

        if ($configuredToken === '') {
            return false;
        }

        $providedToken = (string) $request->header('X-Birthday-Token');

        if ($providedToken === '') {
            $authorization = (string) $request->header('Authorization');

            if (str_starts_with($authorization, 'Bearer ')) {
                $providedToken = substr($authorization, 7);
            }
        }

        return $providedToken !== ''
            && hash_equals($configuredToken, $providedToken);
    }

    /**
     * Convierte fechas Carbon o cadenas a YYYY-MM-DD.
     */
    private function dateValue($value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }
}