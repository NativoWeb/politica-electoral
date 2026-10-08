import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState, useEffect } from 'react';

const emptyPagination = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 100,
    total: 0,
};

export default function WhatsApp({
    contacts = emptyPagination,
    templates = [],
    filters = {},
}) {
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const [selectedContactIds, setSelectedContactIds] = useState([]);
    const [search, setSearch] = useState(filters.search ?? '');
    const [variableValues, setVariableValues] = useState({});

    const [imageFile, setImageFile] = useState(null);
    const [imageUrl, setImageUrl] = useState('');
    const [imagePreview, setImagePreview] = useState('');

    const [isSending, setIsSending] = useState(false);
    const [sendMessage, setSendMessage] = useState('');
    const [sendError, setSendError] = useState('');

    const [sendTracking, setSendTracking] = useState([]);
    const [isTrackingActive, setIsTrackingActive] = useState(false);
    const [currentSendId, setCurrentSendId] = useState(null);

    const [birthdayDate, setBirthdayDate] = useState(
        filters.birth_month && filters.birth_day
            ? `${new Date().getFullYear()}-${String(
                  filters.birth_month
              ).padStart(2, '0')}-${String(filters.birth_day).padStart(
                  2,
                  '0'
              )}`
            : ''
    );

    const initialBirthdayDate =
        filters.birth_month && filters.birth_day
            ? `${new Date().getFullYear()}-${String(
                  filters.birth_month
              ).padStart(2, '0')}-${String(filters.birth_day).padStart(
                  2,
                  '0'
              )}`
            : '';

    const contactData = contacts?.data ?? [];
    const totalContacts = contacts?.total ?? contactData.length;

    const selectedTemplate = useMemo(
        () =>
            templates.find(
                (template) =>
                    String(template.id) === String(selectedTemplateId)
            ) ?? null,
        [templates, selectedTemplateId]
    );

    const isBirthdayTemplate =
        selectedTemplate?.meta_name === 'oscarfc';

    const hasImageHeader =
        selectedTemplate?.header_type === 'image';

    const sortedVariables = useMemo(() => {
        if (!selectedTemplate?.variables?.length) return [];

        return [...selectedTemplate.variables].sort(
            (a, b) => Number(a.position) - Number(b.position)
        );
    }, [selectedTemplate]);

    const previewContact = useMemo(() => {
        if (selectedContactIds.length !== 1) return null;

        return (
            contactData.find(
                (contact) =>
                    String(contact.id) === String(selectedContactIds[0])
            ) ?? null
        );
    }, [contactData, selectedContactIds]);

    const previewName =
        selectedContactIds.length === 1
            ? previewContact?.name ?? '[NOMBRE DEL CONTACTO]'
            : selectedContactIds.length > 1
              ? '[NOMBRE DEL CONTACTO]'
              : '';

    const previewText = useMemo(() => {
        if (!selectedTemplate) return '';

        let text = selectedTemplate.body_text ?? '';

        for (const variable of sortedVariables) {
            const position = Number(variable.position);

            const value =
                position === 1
                    ? previewName
                    : variableValues?.[position] ?? '';

            text = text.replace(
                new RegExp(`\\{\\{${position}\\}\\}`, 'g'),
                value || `{{${position}}}`
            );
        }

        return text;
    }, [
        selectedTemplate,
        sortedVariables,
        previewName,
        variableValues,
    ]);

    /*
     * Consulta a Laravel el estado real del envío.
     *
     * Laravel devuelve los registros de whatsapp_send_recipients
     * que n8n está actualizando.
     */
    useEffect(() => {
        if (!currentSendId || !isTrackingActive) {
            return;
        }

        let cancelled = false;

        const fetchTrackingStatus = async () => {
            try {
                const response = await fetch(
                    `/api/whatsapp/sends/${currentSendId}/status`,
                    {
                        method: 'GET',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    }
                );

                if (!response.ok) {
                    throw new Error(
                        'No fue posible consultar el estado del envío.'
                    );
                }

                const data = await response.json();

                if (cancelled) return;

                const recipients = data?.recipients ?? [];

                setSendTracking(recipients);

                const hasPendingRecipients = recipients.some((recipient) =>
                    ['pendiente', 'enviando'].includes(
                        String(recipient.status).toLowerCase()
                    )
                );

                if (
                    recipients.length > 0 &&
                    !hasPendingRecipients
                ) {
                    setIsTrackingActive(false);
                }
            } catch (error) {
                if (cancelled) return;

                console.error(
                    'Error consultando estado del envío:',
                    error
                );
            }
        };

        fetchTrackingStatus();

        const interval = setInterval(
            fetchTrackingStatus,
            3000
        );

        return () => {
            cancelled = true;
            clearInterval(interval);
        };
    }, [currentSendId, isTrackingActive]);

    const handleTemplateChange = (event) => {
        setSelectedTemplateId(event.target.value);
        setVariableValues({});
        setSelectedContactIds([]);
        setBirthdayDate('');
        setSendMessage('');
        setSendError('');

        setSendTracking([]);
        setIsTrackingActive(false);
        setCurrentSendId(null);

        setImageFile(null);
        setImageUrl('');
        setImagePreview('');
    };

    const handleVariableChange = (position, value) => {
        if (Number(position) === 1) return;

        setVariableValues((current) => ({
            ...current,
            [position]: value,
        }));
    };

    const handleImageFileChange = (event) => {
        const file = event.target.files?.[0];

        if (!file) return;

        if (!file.type.startsWith('image/')) {
            event.target.value = '';
            return;
        }

        setImageFile(file);
        setImageUrl('');

        const previewUrl = URL.createObjectURL(file);
        setImagePreview(previewUrl);
    };

    const handleImageUrlChange = (event) => {
        const value = event.target.value;

        setImageUrl(value);
        setImageFile(null);

        setImagePreview(value.trim());
    };

    const clearImage = () => {
        setImageFile(null);
        setImageUrl('');
        setImagePreview('');
    };

    const toggleContact = (contact) => {
        if (!contact.phone) return;

        const contactId = String(contact.id);

        setSelectedContactIds((current) => {
            const alreadySelected = current.some(
                (id) => String(id) === contactId
            );

            if (alreadySelected) {
                return current.filter(
                    (id) => String(id) !== contactId
                );
            }

            return [...current, contact.id];
        });
    };

    const toggleAllContacts = () => {
        const visibleIds = contactData
            .filter((contact) => contact.phone)
            .map((contact) => contact.id)
            .filter(Boolean);

        if (!visibleIds.length) return;

        const allSelected = visibleIds.every((id) =>
            selectedContactIds.some(
                (selectedId) =>
                    String(selectedId) === String(id)
            )
        );

        if (allSelected) {
            setSelectedContactIds((current) =>
                current.filter(
                    (id) =>
                        !visibleIds.some(
                            (visibleId) =>
                                String(visibleId) === String(id)
                        )
                )
            );

            return;
        }

        setSelectedContactIds((current) => {
            const result = [...current];

            for (const id of visibleIds) {
                if (
                    !result.some(
                        (existingId) =>
                            String(existingId) === String(id)
                    )
                ) {
                    result.push(id);
                }
            }

            return result;
        });
    };

    /*
     * Envía la preparación del envío a Laravel.
     *
     * Laravel crea el registro del envío y sus destinatarios,
     * y posteriormente envía el payload a n8n.
     */
    const prepareSending = async () => {
        if (!selectedTemplate || selectedContactIds.length === 0) {
            return;
        }

        setIsSending(true);
        setSendMessage('');
        setSendError('');
        setSendTracking([]);
        setIsTrackingActive(false);
        setCurrentSendId(null);

        try {
            const formData = new FormData();

            formData.append(
                'template_id',
                String(selectedTemplate.id)
            );

            selectedContactIds.forEach((contactId) => {
                formData.append(
                    'contact_ids[]',
                    String(contactId)
                );
            });

            Object.entries(variableValues).forEach(
                ([position, value]) => {
                    formData.append(
                        `variables[${position}]`,
                        value ?? ''
                    );
                }
            );

            if (imageUrl.trim()) {
                formData.append(
                    'image_url',
                    imageUrl.trim()
                );
            }

            if (imageFile) {
                formData.append('image', imageFile);
            }

            const response = await fetch(
                '/admin/whatsapp/prepare',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector(
                                    'meta[name="csrf-token"]'
                                )
                                ?.getAttribute('content') ?? '',
                    },
                    body: formData,
                }
            );

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data?.message ??
                        'No fue posible preparar el envío.'
                );
            }

            const sendId = data?.send_id;

            if (!sendId) {
                throw new Error(
                    'Laravel no devolvió el identificador del envío.'
                );
            }

            setCurrentSendId(sendId);

            setSendMessage(
                data?.message ??
                    'Datos enviados correctamente a n8n.'
            );

            /*
             * A partir de aquí React deja de simular.
             * El seguimiento se obtiene desde Laravel.
             */
            setIsTrackingActive(true);
        } catch (error) {
            setSendError(
                error?.message ??
                    'Ocurrió un error al preparar el envío.'
            );
        } finally {
            setIsSending(false);
        }
    };

    /*
     * Aplica búsqueda y filtros usando Inertia.
     */
    const applyFilters = ({
        searchValue = search,
        birthdayValue = birthdayDate,
    } = {}) => {
        const params = {};

        if (searchValue.trim()) {
            params.search = searchValue.trim();
        }

        if (isBirthdayTemplate && birthdayValue) {
            const [, month, day] = birthdayValue.split('-');

            if (month && day) {
                params.birth_month = month;
                params.birth_day = day;
            }
        }

        router.get('/admin/whatsapp', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitSearch = (event) => {
        event.preventDefault();

        applyFilters();
    };

    const clearSearch = () => {
        setSearch('');

        applyFilters({
            searchValue: '',
        });
    };

    const handleBirthdayDateChange = (event) => {
        const value = event.target.value;

        setBirthdayDate(value);

        applyFilters({
            birthdayValue: value,
        });
    };

    const selectTodayBirthdays = () => {
        const today = new Date();

        const value = `${today.getFullYear()}-${String(
            today.getMonth() + 1
        ).padStart(2, '0')}-${String(today.getDate()).padStart(
            2,
            '0'
        )}`;

        setBirthdayDate(value);

        applyFilters({
            birthdayValue: value,
        });
    };

    const clearBirthdayFilter = () => {
        setBirthdayDate('');

        applyFilters({
            birthdayValue: '',
        });
    };

    const selectableContactData = contactData.filter(
        (contact) => Boolean(contact.phone)
    );

    const allVisibleSelected =
        selectableContactData.length > 0 &&
        selectableContactData.every((contact) =>
            selectedContactIds.some(
                (id) => String(id) === String(contact.id)
            )
        );

    const formatBirthDate = (birthDate) => {
        if (!birthDate) return 'Sin fecha';

        const date = new Date(`${birthDate}T00:00:00`);

        if (Number.isNaN(date.getTime())) {
            return birthDate;
        }

        return new Intl.DateTimeFormat('es-CO', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        }).format(date);
    };

    const normalizeStatus = (status) => {
        return String(status ?? '').toLowerCase();
    };

    const getStatusLabel = (status) => {
        switch (normalizeStatus(status)) {
            case 'pendiente':
                return 'Pendiente';

            case 'enviado':
                return 'Enviado';

            case 'error':
                return 'Error';

            default:
                return status;
        }
    };

    const getStatusClass = (status) => {
        switch (normalizeStatus(status)) {
            case 'pendiente':
                return 'bg-gray-100 text-gray-600';

            case 'enviado':
                return 'bg-green-100 text-green-700';

            case 'error':
                return 'bg-red-100 text-red-700';

            default:
                return 'bg-gray-100 text-gray-600';
        }
    };

    const sentCount = sendTracking.filter(
        (item) =>
            normalizeStatus(item.status) === 'enviado'
    ).length;

    return (
        <AppLayout
            title="Envíos de WhatsApp"
            breadcrumb={[
                { label: 'SANTANDER', href: '/' },
                { label: 'ADMINISTRACIÓN', href: '/admin' },
                { label: 'ENVÍOS DE WHATSAPP' },
            ]}
        >
            <Head title="Envíos de WhatsApp — Inteligencia Electoral" />

            <div className="bg-[var(--color-primary)] px-6 py-8 text-white">
                <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.25em] text-white/50">
                    Comunicaciones
                </p>

                <h1 className="font-[var(--font-heading)] text-[28px] font-extrabold tracking-tight">
                    ENVÍOS DE WHATSAPP
                </h1>

                <p className="mt-1 text-[13px] text-white/60">
                    Preparar envíos mediante plantillas aprobadas.
                </p>
            </div>

            <div className="p-4 lg:p-6">
                <div className="max-w-5xl">
                    <div className="rounded-lg border border-[var(--color-line)] bg-white p-6">
                        <h2 className="text-[14px] font-bold text-[var(--color-ink)]">
                            Nuevo envío
                        </h2>

                        <p className="mb-6 mt-1 text-[12px] text-[var(--color-ink-faint)]">
                            Selecciona una plantilla y posteriormente los
                            destinatarios.
                        </p>

                        <div className="space-y-6">
                            <div>
                                <label
                                    htmlFor="plantilla"
                                    className="block text-sm font-medium text-gray-700"
                                >
                                    Plantilla
                                </label>

                                <select
                                    id="plantilla"
                                    value={selectedTemplateId}
                                    onChange={handleTemplateChange}
                                    className="mt-1 block w-full rounded-lg border-2 border-gray-400 bg-gray-100 px-3 py-2 text-gray-900 shadow-sm focus:border-indigo-600 focus:ring-indigo-500"
                                >
                                    <option value="">
                                        Selecciona una plantilla
                                    </option>

                                    {templates.map((template) => (
                                        <option
                                            key={template.id}
                                            value={template.id}
                                        >
                                            {template.name}
                                        </option>
                                    ))}
                                </select>

                                {!templates.length && (
                                    <p className="mt-2 text-[11px] text-red-600">
                                        No hay plantillas disponibles.
                                    </p>
                                )}
                            </div>

                            {selectedTemplate && (
                                <>
                                    <div>

                                        <div className="rounded-lg border border-[var(--color-line)] bg-gray-50 p-4">
                                            {isBirthdayTemplate && (
                                                <div className="mb-4 rounded-lg border border-[var(--color-line)] bg-white p-4">
                                                    <div className="mb-2">
                                                        <label
                                                            htmlFor="birthday-filter"
                                                            className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink)]"
                                                        >
                                                            Filtrar por cumpleaños
                                                        </label>

                                                        <p className="mt-1 text-[10px] text-[var(--color-ink-faint)]">
                                                            Selecciona un día para
                                                            encontrar todas las
                                                            personas que cumplen
                                                            años en esa fecha.
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                        <input
                                                            id="birthday-filter"
                                                            type="date"
                                                            value={birthdayDate}
                                                            onChange={
                                                                handleBirthdayDateChange
                                                            }
                                                            className="flex-1 rounded-lg border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)]"
                                                        />

                                                        <button
                                                            type="button"
                                                            onClick={
                                                                selectTodayBirthdays
                                                            }
                                                            className="rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-[11px] font-semibold text-white hover:opacity-90"
                                                        >
                                                            Cumplen hoy
                                                        </button>

                                                        {birthdayDate && (
                                                            <button
                                                                type="button"
                                                                onClick={
                                                                    clearBirthdayFilter
                                                                }
                                                                className="rounded-lg border border-[var(--color-line)] bg-white px-4 py-2.5 text-[11px] font-semibold text-[var(--color-primary)] hover:bg-gray-50"
                                                            >
                                                                Quitar filtro
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>
                                            )}

                                            <form
                                                onSubmit={submitSearch}
                                                className="flex flex-col gap-2 sm:flex-row"
                                            >
                                                <input
                                                    type="text"
                                                    value={search}
                                                    onChange={(event) =>
                                                        setSearch(
                                                            event.target.value
                                                        )
                                                    }
                                                    placeholder="Buscar por nombre o teléfono..."
                                                    className="flex-1 rounded-lg border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)]"
                                                />

                                                <button
                                                    type="submit"
                                                    className="rounded-lg bg-[var(--color-primary)] px-5 py-2.5 text-[11px] font-semibold text-white hover:opacity-90"
                                                >
                                                    Buscar
                                                </button>

                                                {search && (
                                                    <button
                                                        type="button"
                                                        onClick={clearSearch}
                                                        className="rounded-lg border border-[var(--color-line)] bg-white px-4 py-2.5 text-[11px] font-semibold text-[var(--color-primary)] hover:bg-gray-50"
                                                    >
                                                        Limpiar
                                                    </button>
                                                )}
                                            </form>

                                            <div className="mt-4 flex items-center justify-between">
                                                <span className="text-[11px] text-[var(--color-ink-faint)]">
                                                    Página {contacts?.current_page ?? 1} de{' '}
                                                    {contacts?.last_page ?? 1}
                                                </span>

                                                {selectableContactData.length > 0 && (
                                                    <button
                                                        type="button"
                                                        onClick={toggleAllContacts}
                                                        className="text-[11px] font-semibold text-[var(--color-primary)] hover:underline"
                                                    >
                                                        {allVisibleSelected
                                                            ? 'Deseleccionar todos'
                                                            : 'Seleccionar todos'}
                                                    </button>
                                                )}
                                            </div>

                                            {contactData.length > 0 ? (
                                                <div className="mt-3 max-h-96 space-y-2 overflow-y-auto">
                                                    {contactData.map(
                                                        (contact) => {
                                                            const selected =
                                                                selectedContactIds.some(
                                                                    (id) =>
                                                                        String(
                                                                            id
                                                                        ) ===
                                                                        String(
                                                                            contact.id
                                                                        )
                                                                );

                                                            return (
                                                                <label
                                                                    key={
                                                                        contact.id
                                                                    }
                                                                    className={`flex ${
                                                                        contact.phone ? 'cursor-pointer' : 'cursor-default'
                                                                    } items-center gap-3 rounded-lg border bg-white px-3 py-3 transition ${
                                                                        selected
                                                                            ? 'border-[var(--color-primary)] bg-gray-50'
                                                                            : 'border-[var(--color-line)] hover:bg-gray-50'
                                                                    }`}
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={selected}
                                                                        disabled={!contact.phone}
                                                                        onChange={() =>
                                                                            toggleContact(contact)
                                                                        }
                                                                        className="h-4 w-4 rounded border-gray-300 disabled:cursor-not-allowed disabled:opacity-40"
                                                                    />

                                                                    <div className="min-w-0 flex-1">
                                                                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 sm:items-center">
                                                                            <div className="min-w-0">
                                                                                <p className="truncate text-sm font-semibold text-[var(--color-ink)]">
                                                                                    {
                                                                                        contact.name
                                                                                    }
                                                                                </p>
                                                                            </div>

                                                                            <div>
                                                                                <p className="text-[10px] font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]">
                                                                                    Celular
                                                                                </p>

                                                                                <p className="mt-0.5 text-[11px] text-[var(--color-ink)]">
                                                                                    {contact.phone || 'Sin celular'}
                                                                                </p>
                                                                            </div>

                                                                            <div>
                                                                                <p className="text-[10px] font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]">
                                                                                    Cumpleaños
                                                                                </p>

                                                                                <p className="mt-0.5 text-[11px] text-[var(--color-ink)]">
                                                                                    {formatBirthDate(
                                                                                        contact.birth_date
                                                                                    )}
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </label>
                                                            );
                                                        }
                                                    )}
                                                </div>
                                            ) : (
                                                <div className="py-8 text-center">
                                                    <p className="text-[12px] text-[var(--color-ink-faint)]">
                                                        No se encontraron
                                                        contactos disponibles.
                                                    </p>

                                                    <p className="mt-1 text-[10px] text-[var(--color-ink-faint)]">
                                                        Las personas sin celular aparecen en la lista,
                                                        pero no pueden ser seleccionadas para el envío.
                                                    </p>
                                                </div>
                                            )}

                                            <div className="mt-4 flex items-center justify-between">
                                                <span className="text-[10px] text-[var(--color-ink-faint)]">
                                                    {selectedContactIds.length}{' '}
                                                    destinatarios
                                                    seleccionados
                                                </span>

                                                <div className="flex gap-2">
                                                    {contacts?.prev_page_url && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.get(
                                                                    contacts.prev_page_url,
                                                                    {},
                                                                    {
                                                                        preserveState:
                                                                            true,
                                                                        preserveScroll:
                                                                            true,
                                                                        replace: true,
                                                                    }
                                                                )
                                                            }
                                                            className="rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-[11px] font-semibold text-[var(--color-primary)] hover:bg-gray-50"
                                                        >
                                                            Anterior
                                                        </button>
                                                    )}

                                                    {contacts?.next_page_url && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.get(
                                                                    contacts.next_page_url,
                                                                    {},
                                                                    {
                                                                        preserveState:
                                                                            true,
                                                                        preserveScroll:
                                                                            true,
                                                                        replace: true,
                                                                    }
                                                                )
                                                            }
                                                            className="rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-[11px] font-semibold text-[var(--color-primary)] hover:bg-gray-50"
                                                        >
                                                            Siguiente
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {sortedVariables.length > 0 && (
                                        <div>
                                            <label className="mb-3 block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink)]">
                                                Personalización
                                            </label>

                                            <div className="space-y-3">
                                                {sortedVariables.map(
                                                    (variable) => {
                                                        const position =
                                                            Number(
                                                                variable.position
                                                            );

                                                        if (position === 1) {
                                                            return null;
                                                        }

                                                        return (
                                                            <div
                                                                key={
                                                                    variable.position
                                                                }
                                                            >
                                                                <label
                                                                    htmlFor={`variable-${variable.position}`}
                                                                    className="mb-1.5 block text-[11px] font-semibold text-[var(--color-ink)]"
                                                                >
                                                                    {
                                                                        variable.label
                                                                    }
                                                                </label>

                                                                <input
                                                                    id={`variable-${variable.position}`}
                                                                    type="text"
                                                                    value={
                                                                        variableValues?.[
                                                                            position
                                                                        ] ?? ''
                                                                    }
                                                                    onChange={(
                                                                        event
                                                                    ) =>
                                                                        handleVariableChange(
                                                                            position,
                                                                            event
                                                                                .target
                                                                                .value
                                                                        )
                                                                    }
                                                                    placeholder={`Escribe el ${String(
                                                                        variable.label ??
                                                                            ''
                                                                    ).toLowerCase()}`}
                                                                    className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)]"
                                                                />
                                                            </div>
                                                        );
                                                    }
                                                )}
                                            </div>

                                            <p className="mt-2 text-[10px] text-[var(--color-ink-faint)]">
                                                <strong>
                                                    El nombre del contacto se
                                                    agregará automáticamente al
                                                    momento del envío.
                                                </strong>
                                            </p>
                                        </div>
                                    )}

                                    {hasImageHeader && (
                                        <div>
                                            <label className="mb-3 block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink)]">
                                                Imagen del encabezado
                                            </label>

                                            <div className="rounded-lg border border-[var(--color-line)] bg-gray-50 p-4">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                                    <input
                                                        type="file"
                                                        accept="image/*"
                                                        onChange={
                                                            handleImageFileChange
                                                        }
                                                        className="hidden"
                                                        id="whatsapp-image-file"
                                                    />

                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            document
                                                                .getElementById(
                                                                    'whatsapp-image-file'
                                                                )
                                                                ?.click()
                                                        }
                                                        className="rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-[11px] font-semibold text-white hover:opacity-90"
                                                    >
                                                        Adjuntar imagen
                                                    </button>

                                                    <span className="text-[11px] text-[var(--color-ink-faint)]">
                                                        o usa una URL pública
                                                    </span>

                                                    <input
                                                        type="url"
                                                        value={imageUrl}
                                                        onChange={
                                                            handleImageUrlChange
                                                        }
                                                        placeholder="https://ejemplo.com/imagen.jpg"
                                                        className="min-w-0 flex-1 rounded-lg border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)]"
                                                    />
                                                </div>

                                                {(imageFile || imageUrl) && (
                                                    <div className="mt-3 flex items-center justify-between gap-3">
                                                        <p className="min-w-0 truncate text-[10px] text-[var(--color-ink-faint)]">
                                                            {imageFile ? (
                                                                <>
                                                                    Archivo seleccionado:{' '}
                                                                    <strong>
                                                                        {
                                                                            imageFile.name
                                                                        }
                                                                    </strong>
                                                                </>
                                                            ) : (
                                                                <>
                                                                    Imagen desde URL pública:{' '}
                                                                    <strong>
                                                                        {
                                                                            imageUrl
                                                                        }
                                                                    </strong>
                                                                </>
                                                            )}
                                                        </p>

                                                        <button
                                                            type="button"
                                                            onClick={
                                                                clearImage
                                                            }
                                                            className="shrink-0 text-[10px] font-semibold text-[var(--color-primary)] hover:underline"
                                                        >
                                                            Quitar
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    <div>
                                        <div className="mb-2 flex items-center justify-between">
                                            <label className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink)]">
                                                Vista previa
                                            </label>

                                            {selectedContactIds.length ===
                                                1 &&
                                                previewContact && (
                                                    <span className="text-[10px] text-[var(--color-ink-faint)]">
                                                        Para:{' '}
                                                        <strong>
                                                            {
                                                                previewContact.name
                                                            }
                                                        </strong>
                                                    </span>
                                                )}

                                            {selectedContactIds.length > 1 && (
                                                <span className="text-[10px] text-[var(--color-ink-faint)]">
                                                    Vista previa general para{' '}
                                                    <strong>
                                                        {
                                                            selectedContactIds.length
                                                        }
                                                    </strong>{' '}
                                                    destinatarios
                                                </span>
                                            )}
                                        </div>

                                        <div className="rounded-lg border border-[var(--color-line)] bg-gray-50 p-4">
                                            {hasImageHeader &&
                                                imagePreview && (
                                                    <div className="mb-4 overflow-hidden rounded-lg bg-white">
                                                        <img
                                                            src={imagePreview}
                                                            alt="Vista previa del encabezado"
                                                            className="max-h-80 w-full object-contain"
                                                        />
                                                    </div>
                                                )}

                                            {selectedContactIds.length > 0 ? (
                                                <p className="whitespace-pre-wrap text-sm leading-6 text-[var(--color-ink)]">
                                                    {previewText}
                                                </p>
                                            ) : (
                                                <p className="text-[12px] text-[var(--color-ink-faint)]">
                                                    Selecciona un contacto para
                                                    visualizar el mensaje.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </>
                            )}

                            {!selectedTemplate && (
                                <div className="rounded-lg border border-dashed border-[var(--color-line)] p-6 text-center">
                                    <p className="text-[12px] text-[var(--color-ink-faint)]">
                                        Selecciona una plantilla para comenzar.
                                    </p>
                                </div>
                            )}

                            <div className="pt-2">
                                <button
                                    type="button"
                                    onClick={prepareSending}
                                    disabled={
                                        !selectedTemplate ||
                                        selectedContactIds.length === 0 ||
                                        isSending ||
                                        isTrackingActive
                                    }
                                    className={`rounded-lg bg-[var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-white ${
                                        !selectedTemplate ||
                                        selectedContactIds.length === 0 ||
                                        isSending ||
                                        isTrackingActive
                                            ? 'cursor-not-allowed opacity-50'
                                            : 'hover:opacity-90'
                                    }`}
                                >
                                    {isSending
                                        ? 'Enviando a n8n...'
                                        : isTrackingActive
                                          ? 'Envío en progreso...'
                                          : 'Enviar'}
                                </button>

                                {sendMessage && (
                                    <p className="mt-2 text-[11px] font-medium text-green-600">
                                        {sendMessage}
                                    </p>
                                )}

                                {sendError && (
                                    <p className="mt-2 text-[11px] font-medium text-red-600">
                                        {sendError}
                                    </p>
                                )}
                            </div>

                            {sendTracking.length > 0 && (
                                <div className="rounded-lg border border-[var(--color-line)] bg-white">
                                    <div className="border-b border-[var(--color-line)] px-4 py-4">
                                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h3 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink)]">
                                                    Seguimiento del envío
                                                </h3>

                                                <p className="mt-1 text-[10px] text-[var(--color-ink-faint)]">
                                                    Estado de cada destinatario.
                                                </p>
                                            </div>

                                            <span className="text-[10px] font-medium text-[var(--color-ink-faint)]">
                                                {sentCount} de{' '}
                                                {sendTracking.length}{' '}
                                                enviados
                                            </span>
                                        </div>
                                    </div>

                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left">
                                            <thead>
                                                <tr className="border-b border-[var(--color-line)] bg-gray-50">
                                                    <th className="px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                                                        Destinatario
                                                    </th>

                                                    <th className="px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                                                        Celular
                                                    </th>

                                                    <th className="px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">
                                                        Estado
                                                    </th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                {[...sendTracking]
                                                    .sort((a, b) => {
                                                        const order = {
                                                            enviado: 1,
                                                            error: 2,
                                                            pendiente: 3,
                                                        };

                                                        return (
                                                            (order[
                                                                normalizeStatus(
                                                                    a.status
                                                                )
                                                            ] ?? 99) -
                                                            (order[
                                                                normalizeStatus(
                                                                    b.status
                                                                )
                                                            ] ?? 99)
                                                        );
                                                    })
                                                    .map((recipient) => {
                                                        const status =
                                                            normalizeStatus(
                                                                recipient.status
                                                            );

                                                        return (
                                                            <tr
                                                                key={
                                                                    recipient.id
                                                                }
                                                                className="border-b border-[var(--color-line)] last:border-b-0"
                                                            >
                                                                <td className="px-4 py-3">
                                                                    <p className="text-[12px] font-semibold text-[var(--color-ink)]">
                                                                        {
                                                                            recipient.name
                                                                        }
                                                                    </p>
                                                                </td>

                                                                <td className="px-4 py-3">
                                                                    <p className="text-[11px] text-[var(--color-ink)]">
                                                                        {
                                                                            recipient.phone
                                                                        }
                                                                    </p>
                                                                </td>

                                                                <td className="px-4 py-3">
                                                                    <span
                                                                        className={`inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-semibold ${getStatusClass(
                                                                            recipient.status
                                                                        )}`}
                                                                    >
                                                                        {status ===
                                                                            'enviado' && (
                                                                            <span className="mr-1.5">
                                                                                ✓
                                                                            </span>
                                                                        )}

                                                                        {status ===
                                                                            'error' && (
                                                                            <span className="mr-1.5">
                                                                                !
                                                                            </span>
                                                                        )}

                                                                        {getStatusLabel(
                                                                            recipient.status
                                                                        )}
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
