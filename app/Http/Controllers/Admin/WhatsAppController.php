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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    /**
     * Lista los contactos y aplica los filtros del Mapa Político.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $source = $request->input('source', 'todos');

        if (!in_array($source, ['todos', 'votantes', 'lideres'], true)) {
            $source = 'todos';
        }

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'birth_month' => $request->input('birth_month', ''),
            'birth_day' => $request->input('birth_day', ''),
            'source' => $source,
            'provincia' => trim((string) $request->input('provincia', '')),
            'municipio' => trim((string) $request->input('municipio', '')),
            'tipo' => trim((string) $request->input('tipo', '')),
            'cargo' => trim((string) $request->input('cargo', '')),
            'partido' => trim((string) $request->input('partido', '')),
            'profesion' => trim((string) $request->input('profesion', '')),
            'barrio' => trim((string) $request->input('barrio', '')),
            'estado' => trim((string) $request->input('estado', 'todos')),
        ];

        if (!in_array($filters['estado'], ['activos', 'inactivos', 'todos'], true)) {
            $filters['estado'] = 'todos';
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

            if ($filters['search'] !== '') {
                $search = $filters['search'];

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

            $birthMonth = (int) $filters['birth_month'];
            $birthDay = (int) $filters['birth_day'];

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
                    'candidacies.contest.geographicUnit.parent',
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
                    $geographicUnit = $contest?->geographicUnit;
                    $organization = $candidacy?->electoralList?->organization;

                    return [
                        'id' => $person->id,
                        'name' => $person->full_name,
                        'phone' => $contact?->value_normalized
                            ?: $contact?->value_raw,
                        'birth_date' => $this->dateValue($person->birth_date),
                        'provincia' => $geographicUnit?->parent?->canonical_name,
                        'municipio' => $geographicUnit?->canonical_name,
                        'partido' => $organization?->canonical_name,
                        'cargo' => $contest?->office?->name
                            ?? $contest?->corporation?->name,
                        'tipo' => $candidacy ? 'Candidato' : 'Persona',
                        'tipo_registro' => $candidacy ? 'Candidato' : 'Persona',
                        'profesion' => null,
                        'barrio' => null,
                        'estado' => 'activo',
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

            if ($filters['search'] !== '') {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('nombre', 'ilike', "%{$search}%")
                        ->orWhere('telefono', 'ilike', "%{$search}%")
                        ->orWhereHas('contactos', function ($cq) use ($search) {
                            $cq->where('valor', 'ilike', "%{$search}%");
                        });
                });
            }

            $birthMonth = (int) $filters['birth_month'];
            $birthDay = (int) $filters['birth_day'];

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
                    'geographicUnit.parent',
                ])
                ->select([
                    'id',
                    'nombre',
                    'telefono',
                    'fecha_nacimiento',
                    'municipio',
                    'provincia',
                    'partido',
                    'cargo',
                    'tipo',
                    'profesion',
                    'barrio',
                    'is_active',
                    'geographic_unit_id',
                ])
                ->get()
                ->map(function (Lider $lider) {
                    $phone = $lider->telefono
                        ?: $lider->contactos->first()?->valor;

                    return [
                        'id' => $lider->id,
                        'name' => $lider->nombre,
                        'phone' => $phone,
                        'birth_date' => $this->dateValue(
                            $lider->fecha_nacimiento
                        ),
                        'provincia' => $lider->provincia
                            ?: $lider->geographicUnit?->parent?->canonical_name,
                        'municipio' => $lider->geographicUnit?->canonical_name
                            ?? $lider->municipio,
                        'partido' => $lider->partido,
                        'cargo' => $lider->cargo,
                        'tipo' => $lider->tipo ?: 'Líder',
                        'tipo_registro' => 'Líder',
                        'profesion' => $lider->profesion,
                        'barrio' => $lider->barrio,
                        'estado' => $lider->is_active ? 'activo' : 'inactivo',
                        'source' => 'lider',
                    ];
                });
        }

        /*
         * Opciones para los selectores.
         * Se calculan antes de aplicar los filtros para que el usuario
         * pueda cambiar un filtro sin perder las opciones disponibles.
         */
        $allContacts = $persons
            ->concat($lideres)
            ->sortBy(fn ($contact) => mb_strtolower($contact['name'] ?? ''))
            ->values();

        $filterOptions = [
            'provincias' => $this->distinctValues($allContacts, 'provincia'),
            'municipios' => $this->distinctValues($allContacts, 'municipio'),
            'tipos' => $this->distinctValues($allContacts, 'tipo'),
            'cargos' => $this->distinctValues($allContacts, 'cargo'),
            'partidos' => $this->distinctValues($allContacts, 'partido'),
            'profesiones' => $this->distinctValues($allContacts, 'profesion'),
            'barrios' => $this->distinctValues($allContacts, 'barrio'),
            'estados' => [
                ['value' => 'todos', 'label' => 'Todos los estados'],
                ['value' => 'activos', 'label' => 'Activos'],
                ['value' => 'inactivos', 'label' => 'Inactivos'],
            ],
        ];

        $contacts = $this->applyContactFilters($allContacts, $filters)
            ->values();

        /*
         * Identificadores de TODOS los contactos filtrados con teléfono.
         * El frontend podrá usarlos para seleccionar resultados de todas
         * las páginas, no solamente los de la página visible.
         */
        $matchingContactIds = $contacts
            ->filter(fn ($contact) => !empty($contact['phone']))
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

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
            'matchingContactIds' => $matchingContactIds,
            'filterOptions' => $filterOptions,
            'filters' => $filters,
        ]);
    }

    /**
     * Aplica los filtros sobre los contactos ya limitados por permisos
     * territoriales, fuente y búsqueda.
     */
    private function applyContactFilters(
        Collection $contacts,
        array $filters
    ): Collection {
        $filterFields = [
            'provincia' => 'provincia',
            'municipio' => 'municipio',
            'tipo' => 'tipo',
            'cargo' => 'cargo',
            'partido' => 'partido',
            'profesion' => 'profesion',
            'barrio' => 'barrio',
        ];

        foreach ($filterFields as $filter => $field) {
            $value = trim((string) ($filters[$filter] ?? ''));

            if ($value === '') {
                continue;
            }

            $contacts = $contacts->filter(function ($contact) use ($field, $value) {
                return mb_strtolower(trim((string) ($contact[$field] ?? '')))
                    === mb_strtolower($value);
            });
        }

        $estado = $filters['estado'] ?? 'todos';

        if ($estado === 'activos') {
            $contacts = $contacts->filter(
                fn ($contact) => ($contact['source'] ?? '') !== 'lider'
                    || ($contact['estado'] ?? 'activo') === 'activo'
            );
        } elseif ($estado === 'inactivos') {
            // Las personas no tienen un campo is_active equivalente.
            // Este filtro aplica únicamente a los líderes.
            $contacts = $contacts->filter(
                fn ($contact) => ($contact['source'] ?? '') === 'lider'
                    && ($contact['estado'] ?? '') === 'inactivo'
            );
        }

        return $contacts;
    }

    /**
     * Genera opciones únicas para los filtros.
     */
    private function distinctValues(
        Collection $contacts,
        string $field
    ): array {
        return $contacts
            ->pluck($field)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim($value))
            ->unique(fn ($value) => mb_strtolower($value))
            ->sort(fn ($a, $b) => strcasecmp($a, $b))
            ->values()
            ->map(fn ($value) => [
                'value' => $value,
                'label' => $value,
            ])
            ->all();
    }

    /**
     * Prepara un envío manual.
     *
     * Conserva la creación del envío y el webhook actual de n8n.
     * El frontend enviará todos los IDs coincidentes cuando se seleccione
     * la totalidad de los resultados filtrados.
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
                        $cq->where(function ($active) {
                            $active->where('estado', true)
                                ->orWhereNull('estado');
                        })
                        ->whereNotNull('valor')
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
            ->unique(fn ($recipient) => $recipient['id'])
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
     * Actualización de estado usada por los envíos existentes.
     */
    public function updateRecipientStatus(
        Request $request,
        WhatsAppSendRecipient $recipient
    ) {
        if (!$this->hasValidBirthdayToken($request)) {
            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:pendiente,enviando,enviado,leido,error'],
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
     * Actualiza el estado de un destinatario usando el teléfono recibido
     * desde Meta.
     */
    public function updateRecipientStatusByPhone(Request $request)
    {
        if (!$this->hasValidBirthdayToken($request)) {
            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'status' => ['required', 'in:delivered,read,failed'],
        ]);

        $phoneDigits = preg_replace('/\D+/', '', $validated['phone']);

        if (strlen($phoneDigits) < 10) {
            return response()->json([
                'success' => false,
                'message' => 'El número de teléfono no es válido.',
            ], 422);
        }

        $lastTenDigits = substr($phoneDigits, -10);

        $recipient = WhatsAppSendRecipient::query()
            ->whereIn('status', ['pendiente', 'enviando', 'enviado'])
            ->whereRaw(
                "right(regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g'), 10) = ?",
                [$lastTenDigits]
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (!$recipient) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un destinatario pendiente para ese teléfono.',
            ], 404);
        }

        $newStatus = match ($validated['status']) {
            'delivered' => 'enviado',
            'read' => 'leido',
            'failed' => 'error',
        };

        $recipient->update([
            'status' => $newStatus,
        ]);

        return response()->json([
            'success' => true,
            'recipient_id' => $recipient->id,
            'send_id' => $recipient->whatsapp_send_id,
            'phone' => $recipient->phone,
            'meta_status' => $validated['status'],
            'status' => $recipient->status,
        ]);
    }

    /**
     * Actualiza el estado de un destinatario de cumpleaños.
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
            'status' => ['required', 'in:pendiente,enviando,enviado,leido,error'],
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
     * Consulta el estado de un envío para la interfaz.
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

        $personIds = $sends
            ->flatMap(fn (WhatsAppSend $send) => $send->recipients->pluck('person_id'))
            ->filter()
            ->unique()
            ->values();

        $personBirthDates = Person::query()
            ->whereIn('id', $personIds)
            ->pluck('birth_date', 'id');

        $leaderBirthDates = Lider::query()
            ->whereIn('id', $personIds)
            ->pluck('fecha_nacimiento', 'id');

        $recipients = $sends
            ->flatMap(function (WhatsAppSend $send) use (
                $personBirthDates,
                $leaderBirthDates
            ) {
                return $send->recipients->map(function (
                    WhatsAppSendRecipient $recipient
                ) use ($send, $personBirthDates, $leaderBirthDates) {
                    $birthDate = $personBirthDates->get($recipient->person_id)
                        ?? $leaderBirthDates->get($recipient->person_id);

                    return [
                        'id' => $recipient->id,
                        'send_id' => $send->id,
                        'name' => $recipient->name,
                        'phone' => $recipient->phone,
                        'birth_date' => $this->dateValue($birthDate),
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
                'leido' => $recipients->where('status', 'leido')->count(),
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
