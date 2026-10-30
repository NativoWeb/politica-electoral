<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Lider;
use App\Models\Person;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use App\Support\CurrentCampaign;
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

        $templateId = $request->input('template_id');
        $selectedTemplate = $templateId
            ? $this->ownTemplates()
                ->where('is_active', true)
                ->find($templateId)
            : null;

        $persons = collect();

        if (in_array($source, ['todos', 'votantes'], true) && $this->includesPersons()) {
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

            if ($selectedTemplate?->meta_name === 'oscarfc') {
                $birthMonth = $request->integer('birth_month');
                $birthDay = $request->integer('birth_day');

                if ($birthMonth >= 1 && $birthMonth <= 12) {
                    $query->whereMonth('birth_date', $birthMonth);
                }

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
                    'persons.identity_status',
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
                        'tipo' => $candidacy ? 'Candidato' : 'Persona',
                        'estado' => $person->identity_status,
                        'provincia' => null,
                        'profesion' => null,
                        'barrio' => null,
                        'source' => 'votante',
                    ];
                });
        }

        $lideres = collect();

        if (in_array($source, ['todos', 'lideres'], true)) {
            $query = Lider::query()->where('lideres.campaign_id', $this->ownCampaignId());

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

            if ($selectedTemplate?->meta_name === 'oscarfc') {
                $birthMonth = $request->integer('birth_month');
                $birthDay = $request->integer('birth_day');

                if ($birthMonth >= 1 && $birthMonth <= 12) {
                    $query->whereMonth('fecha_nacimiento', $birthMonth);
                }

                if ($birthDay >= 1 && $birthDay <= 31) {
                    $query->whereDay('fecha_nacimiento', $birthDay);
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
                    'provincia',
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
                        'municipio' => $lider->geographicUnit?->canonical_name
                            ?? $lider->municipio,
                        'partido' => $lider->partido,
                        'cargo' => $lider->cargo,
                        'tipo_registro' => 'Líder',
                        'tipo' => $lider->tipo,
                        'provincia' => $lider->provincia,
                        'profesion' => $lider->profesion,
                        'barrio' => $lider->barrio,
                        'estado' => $lider->is_active ? 'Activo' : 'Inactivo',
                        'source' => 'lider',
                    ];
                });
        }

        $contacts = $persons
            ->concat($lideres)
            ->sortBy(fn ($contact) => mb_strtolower($contact['name'] ?? ''))
            ->values();

            $filterOptions = [];
            foreach ([
                'provincia',
                'municipio',
                'tipo',
                'cargo',
                'partido',
                'profesion',
                'barrio',
                'estado',
            ] as $field) {
                $filterOptions[$field] = $contacts
                    ->pluck($field)
                    ->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
                    ->map(fn ($value) => trim((string) $value))
                    ->unique()
                    ->sort(fn ($a, $b) => strcasecmp($a, $b))
                    ->values()
                    ->all();
            }
        /*
        * Solo aplicar filtros configurados en la plantilla seleccionada.
        * Los filtros se reciben como filter_provincia, filter_municipio, etc.
        */
        $configuredFilters = $selectedTemplate?->filters ?? [];

        $filterFields = [
            'provincia',
            'municipio',
            'tipo',
            'cargo',
            'partido',
            'profesion',
            'barrio',
            'estado',
        ];

        foreach ($filterFields as $field) {
            $value = trim((string) $request->input("filter_{$field}", ''));

            if (
                $value !== ''
                && in_array($field, $configuredFilters, true)
            ) {
                $contacts = $contacts->filter(function ($contact) use ($field, $value) {
                    $contactValue = (string) ($contact[$field] ?? '');

                    return $contactValue !== ''
                        && str_contains(
                            mb_strtolower($contactValue),
                            mb_strtolower($value)
                        );
                });
            }
        }

        if (
            in_array('cumpleanos', $configuredFilters, true)
            && $selectedTemplate?->meta_name !== 'oscarfc'
        ) {
            $birthdayValue = trim(
                (string) $request->input('filter_cumpleanos', '')
            );

            if ($birthdayValue !== '') {
                try {
                    $birthday = Carbon::createFromFormat(
                        '!Y-m-d',
                        $birthdayValue
                    );

                    if ($birthday->format('Y-m-d') === $birthdayValue) {
                        $month = $birthday->month;
                        $day = $birthday->day;

                        $contacts = $contacts->filter(
                            function ($contact) use ($month, $day) {
                                $birthDate = $contact['birth_date'] ?? null;

                                if (!$birthDate) {
                                    return false;
                                }

                                try {
                                    $date = Carbon::parse($birthDate);

                                    return $date->month === $month
                                        && $date->day === $day;
                                } catch (\Throwable $exception) {
                                    return false;
                                }
                            }
                        );
                    }
                } catch (\Throwable $exception) {
                    // Si la fecha no es válida, no aplicar el filtro.
                }
            }
        }

        $contacts = $contacts->values();

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

        $templates = $this->ownTemplates()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'meta_name',
                'body_text',
                'variables',
                'filters',
                'header_type',
                'header_text',
                'language',
                'category',
                'description',
            ]);

        return Inertia::render('Admin/WhatsApp', [
            'contacts' => $paginatedContacts,
            'templates' => $templates,
            'filter_options' => $filterOptions,
            'filters' => [
                'search' => $request->input('search'),
                'birth_month' => $request->input('birth_month'),
                'birth_day' => $request->input('birth_day'),
                'source' => $source,
                'template_id' => $templateId,

                'filter_provincia' => $request->input('filter_provincia', ''),
                'filter_municipio' => $request->input('filter_municipio', ''),
                'filter_tipo' => $request->input('filter_tipo', ''),
                'filter_cargo' => $request->input('filter_cargo', ''),
                'filter_partido' => $request->input('filter_partido', ''),
                'filter_profesion' => $request->input('filter_profesion', ''),
                'filter_barrio' => $request->input('filter_barrio', ''),
                'filter_estado' => $request->input('filter_estado', ''),
                'filter_cumpleanos' => $request->input('filter_cumpleanos', ''),
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
        $selectAllMatching = $request->boolean('select_all_matching');

        $validated = $request->validate([
            'template_id' => [
                'required',
                'integer',
                'exists:whatsapp_templates,id',
            ],
            'contact_ids' => [
                $selectAllMatching ? 'nullable' : 'required',
                'array',
                $selectAllMatching ? 'min:0' : 'min:1',
            ],
            'contact_ids.*' => ['uuid'],
            'select_all_matching' => ['nullable', 'boolean'],
            'variables' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url'],
            'image' => ['nullable', 'image', 'max:10240'],
            'source' => ['nullable', 'in:todos,votantes,lideres'],
            'search' => ['nullable', 'string', 'max:255'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'filter_provincia' => ['nullable', 'string', 'max:255'],
            'filter_municipio' => ['nullable', 'string', 'max:255'],
            'filter_tipo' => ['nullable', 'string', 'max:255'],
            'filter_cargo' => ['nullable', 'string', 'max:255'],
            'filter_partido' => ['nullable', 'string', 'max:255'],
            'filter_profesion' => ['nullable', 'string', 'max:255'],
            'filter_barrio' => ['nullable', 'string', 'max:255'],
            'filter_estado' => ['nullable', 'string', 'max:255'],
            'filter_cumpleanos' => ['nullable', 'date_format:Y-m-d'],

            'excluded_contact_ids' => [
                'nullable',
                'array',
            ],
            'excluded_contact_ids.*' => ['uuid'],
        ]);
        $template = $this->ownTemplates()
            ->where('is_active', true)
            ->findOrFail($validated['template_id']);

        $source = $validated['source'] ?? 'todos';
        $user = $request->user();
        $configuredFilters = $template->filters ?? [];

        $persons = collect();

        if (in_array($source, ['todos', 'votantes'], true) && $this->includesPersons()) {
            $query = Person::query()
                ->whereNull('persons.merged_into_id')
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

            if (!$selectAllMatching) {
                $query->whereIn('persons.id', $validated['contact_ids'] ?? []);
            }

            if ($search = trim((string) ($validated['search'] ?? ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('persons.full_name', 'ilike', "%{$search}%")
                        ->orWhereHas('contactPoints', function ($cq) use ($search) {
                            $cq->where('type', 'mobile')
                                ->where('is_current', true)
                                ->where(function ($pq) use ($search) {
                                    $pq->where('value_normalized', 'ilike', "%{$search}%")
                                        ->orWhere('value_raw', 'ilike', "%{$search}%");
                                });
                        });
                });
            }

            if ($template->meta_name === 'oscarfc') {
                if (!empty($validated['birth_month'])) {
                    $query->whereMonth('birth_date', $validated['birth_month']);
                }

                if (!empty($validated['birth_day'])) {
                    $query->whereDay('birth_date', $validated['birth_day']);
                }
            }

            if (
                $template->meta_name !== 'oscarfc'
                && in_array('cumpleanos', $configuredFilters, true)
                && !empty($validated['filter_cumpleanos'])
            ) {
                $birthday = Carbon::createFromFormat(
                    '!Y-m-d',
                    $validated['filter_cumpleanos']
                );

                $query->whereMonth('birth_date', $birthday->month)
                    ->whereDay('birth_date', $birthday->day);
            }

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
                    'candidacies.electoralList.organization',
                    'candidacies.contest.office',
                    'candidacies.contest.corporation',
                    'candidacies.contest.geographicUnit',
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

                    return [
                        'id' => $person->id,
                        'name' => $person->full_name,
                        'phone' => $contact?->value_normalized
                            ?: $contact?->value_raw,
                        'municipio' => $contest?->geographicUnit?->canonical_name,
                        'partido' => $candidacy?->electoralList?->organization?->canonical_name,
                        'cargo' => $contest?->office?->name
                            ?? $contest?->corporation?->name,
                        'tipo' => $candidacy ? 'Candidato' : 'Persona',
                        'estado' => $person->identity_status,
                        'provincia' => null,
                        'profesion' => null,
                        'barrio' => null,
                        'source' => 'votante',
                    ];
                });
        }

        $lideres = collect();

        if (in_array($source, ['todos', 'lideres'], true)) {
            $query = Lider::query()
                ->where('lideres.campaign_id', $this->ownCampaignId())
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

            if (!$selectAllMatching) {
                $query->whereIn('lideres.id', $validated['contact_ids'] ?? []);
            }

            if ($search = trim((string) ($validated['search'] ?? ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('nombre', 'ilike', "%{$search}%")
                        ->orWhere('telefono', 'ilike', "%{$search}%")
                        ->orWhereHas('contactos', function ($cq) use ($search) {
                            $cq->where('valor', 'ilike', "%{$search}%");
                        });
                });
            }

            if ($template->meta_name === 'oscarfc') {
                if (!empty($validated['birth_month'])) {
                    $query->whereMonth('fecha_nacimiento', $validated['birth_month']);
                }

                if (!empty($validated['birth_day'])) {
                    $query->whereDay('fecha_nacimiento', $validated['birth_day']);
                }
            }

            if (
                $template->meta_name !== 'oscarfc'
                && in_array('cumpleanos', $configuredFilters, true)
                && !empty($validated['filter_cumpleanos'])
            ) {
                $birthday = Carbon::createFromFormat(
                    '!Y-m-d',
                    $validated['filter_cumpleanos']
                );

                $query->whereMonth('fecha_nacimiento', $birthday->month)
                    ->whereDay('fecha_nacimiento', $birthday->day);
            }

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
                    'geographicUnit',
                ])
                ->get()
                ->map(function (Lider $lider) {
                    return [
                        'id' => $lider->id,
                        'name' => $lider->nombre,
                        'phone' => $lider->telefono
                            ?: $lider->contactos->first()?->valor,
                        'municipio' => $lider->geographicUnit?->canonical_name
                            ?? $lider->municipio,
                        'partido' => $lider->partido,
                        'cargo' => $lider->cargo,
                        'tipo' => $lider->tipo,
                        'provincia' => $lider->provincia,
                        'profesion' => $lider->profesion,
                        'barrio' => $lider->barrio,
                        'estado' => $lider->is_active ? 'Activo' : 'Inactivo',
                        'source' => 'lider',
                    ];
                });
        }

        $recipients = $persons
            ->concat($lideres)
            ->filter(fn ($recipient) => !empty($recipient['phone']))
            ->values();

        /*
        * Aplicar exclusivamente los filtros habilitados para esta plantilla.
        */
        foreach ([
            'provincia',
            'municipio',
            'tipo',
            'cargo',
            'partido',
            'profesion',
            'barrio',
            'estado',
        ] as $field) {
            $value = trim((string) ($validated["filter_{$field}"] ?? ''));

            if (
                $value !== ''
                && in_array($field, $configuredFilters, true)
            ) {
                $recipients = $recipients->filter(function ($recipient) use ($field, $value) {
                    $contactValue = (string) ($recipient[$field] ?? '');

                    return $contactValue !== ''
                        && str_contains(
                            mb_strtolower($contactValue),
                            mb_strtolower($value)
                        );
                });
            }
        }


        if (!$selectAllMatching) {
            $selectedIds = collect($validated['contact_ids'] ?? [])
                ->map(fn ($id) => (string) $id);

            $recipients = $recipients
                ->filter(fn ($recipient) => $selectedIds->contains((string) $recipient['id']))
                ->values();
        } else {
            $excludedIds = collect($validated['excluded_contact_ids'] ?? [])
                ->map(fn ($id) => (string) $id)
                ->flip();

            if ($excludedIds->isNotEmpty()) {
                $recipients = $recipients
                    ->reject(
                        fn ($recipient) => $excludedIds->has(
                            (string) $recipient['id']
                        )
                    )
                    ->values();
            }
        }


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
            $send = $this->createSend($template, [
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
        * Se mantiene el payload y el webhook actuales de n8n.
        */
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
            'message' => 'Envío en progreso, revise el historial',
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
            $recipients = $this->getTodayBirthdayRecipients($today, $template);

            $send = DB::transaction(function () use (
                $template,
                $date,
                $recipients
            ) {
                $send = $this->createSend($template, [
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
     *
     * Solo líderes de la campaña dueña de la plantilla (si tiene WhatsApp); las personas electorales
     * compartidas solo con la plantilla de la campaña de plataforma.
     */
    private function getTodayBirthdayRecipients(Carbon $today, WhatsAppTemplate $template): array
    {
        if (! DB::table('campaigns')->where('id', $template->campaign_id)->value('whatsapp_enabled')) {
            return [];
        }

        $month = (int) $today->month;
        $day = (int) $today->day;

        $persons = $template->campaign_id !== Campaign::DEFAULT_ID ? collect() : Person::query()
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

        // Called by n8n without a user, so the campaign scope of Lider does not apply: the template's campaign only.
        $lideres = Lider::query()
            ->where('lideres.campaign_id', $template->campaign_id)
            ->whereIn('lideres.campaign_id', $this->whatsappCampaignIds())
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
     * Actualización de estado protegida para n8n.
     */

    
    /**
     * Actualiza el estado de un destinatario usando el teléfono recibido desde Meta.
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
            'send_id' => ['nullable', 'integer'],
        ]);
    
        // Normalizar el teléfono recibido desde Meta.
        $phoneDigits = preg_replace('/\D+/', '', $validated['phone']);
    
        if (strlen($phoneDigits) < 10) {
            return response()->json([
                'success' => false,
                'message' => 'El número de teléfono no es válido.',
            ], 422);
        }
    
        // Comparar los últimos 10 dígitos para ignorar el indicativo 57.
        $lastTenDigits = substr($phoneDigits, -10);
    
        // Buscar el destinatario pendiente más reciente que coincida, solo en envíos de campañas con WhatsApp
        // (y del envío indicado, si n8n lo manda). Meta no devuelve un id de mensaje que esté guardado aquí.
        $recipient = WhatsAppSendRecipient::query()
            ->whereHas('send', fn ($q) => $q->whereIn('campaign_id', $this->whatsappCampaignIds()))
            ->when($validated['send_id'] ?? null, fn ($q, $sendId) => $q->where('whatsapp_send_id', $sendId))
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
    
        // Mantener los estados actuales de la aplicación.

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
     * Consulta el estado de un envío para el seguimiento de la interfaz.
     */
    public function status(WhatsAppSend $send)
    {
        abort_unless($send->campaign_id === $this->ownCampaignId(), 404);

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
            ->where('campaign_id', $this->ownCampaignId())
            ->where('campaign_type', 'birthday')
            ->whereDate('campaign_date', $date)
            ->orderBy('id')
            ->get();

        // Obtener los identificadores de todos los destinatarios del historial.
        $personIds = $sends
            ->flatMap(fn (WhatsAppSend $send) => $send->recipients->pluck('person_id'))
            ->filter()
            ->unique()
            ->values();

        // Consultar fechas de nacimiento en las dos fuentes posibles.
        $personBirthDates = Person::query()
            ->whereIn('id', $personIds)
            ->pluck('birth_date', 'id');

        $leaderBirthDates = Lider::query()
            ->where('lideres.campaign_id', $this->ownCampaignId())
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

    // Bulk WhatsApp works on the user's OWN campaign only: its templates, its sends and its lideres, never a
    // subcampaign's (the root campaign does not message the lideres of its subcampaigns).
    private function ownCampaignId(): string
    {
        return app(CurrentCampaign::class)->ownId();
    }

    private function ownTemplates()
    {
        return WhatsAppTemplate::query()->where('campaign_id', $this->ownCampaignId());
    }

    // Electoral persons are shared platform data: only the platform (superadmin, root campaign) messages them.
    private function includesPersons(): bool
    {
        return app(CurrentCampaign::class)->canEditElectoral();
    }

    // A send belongs to the campaign of its template, also when n8n creates it without a user.
    private function createSend(WhatsAppTemplate $template, array $attributes): WhatsAppSend
    {
        $send = new WhatsAppSend(['template_id' => $template->id, ...$attributes]);
        $send->forceFill(['campaign_id' => $template->campaign_id])->save();

        return $send;
    }

    // Campaigns allowed to send WhatsApp messages to their lideres.
    private function whatsappCampaignIds()
    {
        return DB::table('campaigns')->where('whatsapp_enabled', true)->select('id');
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
