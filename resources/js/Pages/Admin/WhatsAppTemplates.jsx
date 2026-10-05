import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const emptyVariable = {
    position: 1,
    label: 'Nombre',
};

export default function WhatsAppTemplates({ templates = [] }) {
    const [editingTemplate, setEditingTemplate] = useState(null);
    const [showForm, setShowForm] = useState(false);

    const form = useForm({
        name: '',
        meta_name: '',
        body_text: '',
        variables: [emptyVariable],
        header_type: '',
        header_text: '',
        language: 'es',
        category: 'marketing',
        description: '',
        is_active: true,
    });

    useEffect(() => {
        if (!form.data.body_text) return;

        const matches = [
            ...form.data.body_text.matchAll(/\{\{(\d+)\}\}/g),
        ];

        const positions = [
            ...new Set(
                matches.map((match) => Number(match[1]))
            ),
        ].sort((a, b) => a - b);

        if (!positions.length) {
            form.setData('variables', [emptyVariable]);
            return;
        }

        const currentVariables = form.data.variables ?? [];

        const nextVariables = positions.map((position) => {
            const existing = currentVariables.find(
                (variable) =>
                    Number(variable.position) === position
            );

            if (existing) {
                return existing;
            }

            return {
                position,
                label:
                    position === 1
                        ? 'Nombre'
                        : `Variable ${position}`,
            };
        });

        if (
            JSON.stringify(nextVariables) !==
            JSON.stringify(currentVariables)
        ) {
            form.setData('variables', nextVariables);
        }
    }, [form.data.body_text]);

    const resetForm = () => {
        form.setData({
            name: '',
            meta_name: '',
            body_text: '',
            variables: [emptyVariable],
            header_type: '',
            header_text: '',
            language: 'es',
            category: 'marketing',
            description: '',
            is_active: true,
        });

        form.clearErrors();
        setEditingTemplate(null);
    };

    const openCreate = () => {
        resetForm();
        setShowForm(true);
    };

    const openEdit = (template) => {
        form.setData({
            name: template.name ?? '',
            meta_name: template.meta_name ?? '',
            body_text: template.body_text ?? '',
            variables:
                template.variables?.length
                    ? template.variables
                    : [emptyVariable],
            header_type: template.header_type ?? '',
            header_text: template.header_text ?? '',
            language: template.language ?? 'es',
            category: template.category ?? 'marketing',
            description: template.description ?? '',
            is_active: Boolean(template.is_active),
        });

        form.clearErrors();
        setEditingTemplate(template);
        setShowForm(true);
    };

    const closeForm = () => {
        if (form.processing) return;

        setShowForm(false);
        resetForm();
    };

    const submit = (event) => {
        event.preventDefault();

        if (editingTemplate) {
            form.put(
                `/admin/whatsapp/templates/${editingTemplate.id}`,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setShowForm(false);
                        resetForm();
                    },
                }
            );

            return;
        }

        form.post('/admin/whatsapp/templates', {
            preserveScroll: true,
            onSuccess: () => {
                setShowForm(false);
                resetForm();
            },
        });
    };

    const deleteTemplate = (template) => {
        const confirmed = window.confirm(
            `¿Eliminar la plantilla "${template.name}"?`
        );

        if (!confirmed) return;

        router.delete(
            `/admin/whatsapp/templates/${template.id}`,
            {
                preserveScroll: true,
            }
        );
    };

    const updateVariableLabel = (position, value) => {
        form.setData(
            'variables',
            (form.data.variables ?? []).map((variable) =>
                Number(variable.position) === Number(position)
                    ? {
                          ...variable,
                          label: value,
                      }
                    : variable
            )
        );
    };

    const addVariable = () => {
        const variables = form.data.variables ?? [];

        const nextPosition =
            Math.max(
                0,
                ...variables.map((variable) =>
                    Number(variable.position)
                )
            ) + 1;

        form.setData('variables', [
            ...variables,
            {
                position: nextPosition,
                label: `Variable ${nextPosition}`,
            },
        ]);
    };

    const removeVariable = (position) => {
        if (Number(position) === 1) return;

        form.setData(
            'variables',
            (form.data.variables ?? []).filter(
                (variable) =>
                    Number(variable.position) !==
                    Number(position)
            )
        );
    };

    return (
        <AppLayout
            title="Plantillas de WhatsApp"
            breadcrumb={[
                { label: 'SANTANDER', href: '/' },
                { label: 'ADMINISTRACIÓN', href: '/admin' },
                {
                    label: 'WHATSAPP',
                    href: '/admin/whatsapp',
                },
                { label: 'PLANTILLAS' },
            ]}
        >
            <Head title="Plantillas de WhatsApp" />

            <div className="bg-[var(--color-primary)] px-6 py-8 text-white">
                <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.25em] text-white/50">
                    Comunicaciones
                </p>

                <h1 className="font-[var(--font-heading)] text-[28px] font-extrabold tracking-tight">
                    PLANTILLAS DE WHATSAPP
                </h1>

                <p className="mt-1 text-[13px] text-white/60">
                    Administra las plantillas utilizadas para los
                    envíos.
                </p>
            </div>

            <div className="p-4 lg:p-6">
                <div className="max-w-6xl">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h2 className="text-[14px] font-bold text-[var(--color-ink)]">
                                Plantillas
                            </h2>

                            <p className="mt-1 text-[11px] text-[var(--color-ink-faint)]">
                                {templates.length} plantilla
                                {templates.length === 1 ? '' : 's'}
                                registrada
                                {templates.length === 1
                                    ? ''
                                    : 's'}
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={openCreate}
                            className="rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-[11px] font-semibold text-white hover:opacity-90"
                        >
                            + Nueva plantilla
                        </button>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-[var(--color-line)] bg-white">
                        {templates.length > 0 ? (
                            <div className="divide-y divide-[var(--color-line)]">
                                {templates.map((template) => (
                                    <div
                                        key={template.id}
                                        className="p-5"
                                    >
                                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="text-[14px] font-bold text-[var(--color-ink)]">
                                                        {template.name}
                                                    </h3>

                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ${
                                                            template.is_active
                                                                ? 'bg-green-100 text-green-700'
                                                                : 'bg-gray-100 text-gray-500'
                                                        }`}
                                                    >
                                                        {template.is_active
                                                            ? 'Activa'
                                                            : 'Inactiva'}
                                                    </span>
                                                </div>

                                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-[var(--color-ink-faint)]">
                                                    <span>
                                                        Meta:{' '}
                                                        <strong className="text-[var(--color-ink)]">
                                                            {
                                                                template.meta_name
                                                            }
                                                        </strong>
                                                    </span>

                                                    <span>
                                                        Idioma:{' '}
                                                        {
                                                            template.language
                                                        }
                                                    </span>

                                                    {template.category && (
                                                        <span>
                                                            Categoría:{' '}
                                                            {
                                                                template.category
                                                            }
                                                        </span>
                                                    )}
                                                </div>

                                                <div className="mt-4 rounded-lg bg-gray-50 p-3">
                                                    <p className="whitespace-pre-wrap text-[12px] leading-5 text-[var(--color-ink)]">
                                                        {
                                                            template.body_text
                                                        }
                                                    </p>
                                                </div>

                                                {template.variables?.length >
                                                    0 && (
                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        {template.variables.map(
                                                            (
                                                                variable
                                                            ) => (
                                                                <span
                                                                    key={
                                                                        variable.position
                                                                    }
                                                                    className="rounded-md border border-[var(--color-line)] bg-white px-2 py-1 text-[9px] text-[var(--color-ink-faint)]"
                                                                >
                                                                    {`{{${variable.position}}}`}{' '}
                                                                    {
                                                                        variable.label
                                                                    }
                                                                </span>
                                                            )
                                                        )}
                                                    </div>
                                                )}
                                            </div>

                                            <div className="flex shrink-0 gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        openEdit(
                                                            template
                                                        )
                                                    }
                                                    className="rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-[10px] font-semibold text-[var(--color-primary)] hover:bg-gray-50"
                                                >
                                                    Editar
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        deleteTemplate(
                                                            template
                                                        )
                                                    }
                                                    className="rounded-lg border border-red-200 bg-white px-3 py-2 text-[10px] font-semibold text-red-600 hover:bg-red-50"
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-[13px] font-semibold text-[var(--color-ink)]">
                                    No hay plantillas registradas
                                </p>

                                <p className="mx-auto mt-1 max-w-md text-[11px] text-[var(--color-ink-faint)]">
                                    Crea una plantilla para poder
                                    utilizarla posteriormente en
                                    los envíos de WhatsApp.
                                </p>

                                <button
                                    type="button"
                                    onClick={openCreate}
                                    className="mt-4 rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-[11px] font-semibold text-white hover:opacity-90"
                                >
                                    Crear primera plantilla
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {showForm && (
                <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
                    <div className="my-6 w-full max-w-3xl rounded-xl bg-white shadow-2xl">
                        <div className="flex items-center justify-between border-b border-[var(--color-line)] px-6 py-4">
                            <div>
                                <h2 className="text-[15px] font-bold text-[var(--color-ink)]">
                                    {editingTemplate
                                        ? 'Editar plantilla'
                                        : 'Nueva plantilla'}
                                </h2>

                                <p className="mt-1 text-[10px] text-[var(--color-ink-faint)]">
                                    Configura la plantilla que
                                    utilizará el módulo de envíos.
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={closeForm}
                                className="text-xl leading-none text-gray-400 hover:text-gray-700"
                            >
                                ×
                            </button>
                        </div>

                        <form
                            onSubmit={submit}
                            className="space-y-5 p-6"
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                        Nombre
                                    </label>

                                    <input
                                        type="text"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value
                                            )
                                        }
                                        placeholder="Ej. Cumpleaños"
                                        className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none focus:border-[var(--color-primary)]"
                                    />

                                    {form.errors.name && (
                                        <p className="mt-1 text-[10px] text-red-600">
                                            {form.errors.name}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                        Meta name
                                    </label>

                                    <input
                                        type="text"
                                        value={form.data.meta_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'meta_name',
                                                event.target.value
                                            )
                                        }
                                        placeholder="Ej. oscarfc"
                                        className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none focus:border-[var(--color-primary)]"
                                    />

                                    <p className="mt-1 text-[9px] text-[var(--color-ink-faint)]">
                                        Debe coincidir con el
                                        identificador de la plantilla
                                        aprobada.
                                    </p>

                                    {form.errors.meta_name && (
                                        <p className="mt-1 text-[10px] text-red-600">
                                            {form.errors.meta_name}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                    Texto de la plantilla
                                </label>

                                <textarea
                                    rows={6}
                                    value={form.data.body_text}
                                    onChange={(event) =>
                                        form.setData(
                                            'body_text',
                                            event.target.value
                                        )
                                    }
                                    placeholder="Hola {{1}}, queremos desearte un feliz cumpleaños..."
                                    className="w-full rounded-lg border border-[var(--color-line)] px-3 py-3 text-sm leading-6 outline-none focus:border-[var(--color-primary)]"
                                />

                                <p className="mt-1 text-[9px] text-[var(--color-ink-faint)]">
                                    Usa {'{{1}}'}, {'{{2}}'}, etc.
                                    para las variables.
                                </p>

                                {form.errors.body_text && (
                                    <p className="mt-1 text-[10px] text-red-600">
                                        {form.errors.body_text}
                                    </p>
                                )}
                            </div>

                            <div>
                                <div className="mb-3 flex items-center justify-between">
                                    <div>
                                        <label className="block text-[11px] font-semibold text-[var(--color-ink)]">
                                            Variables
                                        </label>

                                        <p className="mt-1 text-[9px] text-[var(--color-ink-faint)]">
                                            {'{{1}}'} se reserva
                                            automáticamente para el
                                            nombre del contacto.
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={addVariable}
                                        className="rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-[10px] font-semibold text-[var(--color-primary)] hover:bg-gray-50"
                                    >
                                        + Variable
                                    </button>
                                </div>

                                <div className="space-y-2">
                                    {(form.data.variables ?? []).map(
                                        (variable) => (
                                            <div
                                                key={
                                                    variable.position
                                                }
                                                className="flex items-center gap-2"
                                            >
                                                <span className="w-12 shrink-0 text-center text-[10px] font-bold text-[var(--color-ink-faint)]">
                                                    {`{{${variable.position}}}`}
                                                </span>

                                                <input
                                                    type="text"
                                                    value={
                                                        variable.label ??
                                                        ''
                                                    }
                                                    disabled={
                                                        Number(
                                                            variable.position
                                                        ) === 1
                                                    }
                                                    onChange={(
                                                        event
                                                    ) =>
                                                        updateVariableLabel(
                                                            variable.position,
                                                            event.target
                                                                .value
                                                        )
                                                    }
                                                    className="flex-1 rounded-lg border border-[var(--color-line)] px-3 py-2 text-[11px] outline-none disabled:bg-gray-100"
                                                />

                                                {Number(
                                                    variable.position
                                                ) !== 1 && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            removeVariable(
                                                                variable.position
                                                            )
                                                        }
                                                        className="rounded-lg px-2 py-2 text-red-500 hover:bg-red-50"
                                                    >
                                                        ×
                                                    </button>
                                                )}
                                            </div>
                                        )
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                        Idioma
                                    </label>

                                    <select
                                        value={form.data.language}
                                        onChange={(event) =>
                                            form.setData(
                                                'language',
                                                event.target.value
                                            )
                                        }
                                        className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none"
                                    >
                                        <option value="es">
                                            Español
                                        </option>
                                        <option value="en">
                                            Inglés
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                        Categoría
                                    </label>

                                    <select
                                        value={form.data.category}
                                        onChange={(event) =>
                                            form.setData(
                                                'category',
                                                event.target.value
                                            )
                                        }
                                        className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none"
                                    >
                                        <option value="marketing">
                                            Marketing
                                        </option>
                                        <option value="utility">
                                            Utility
                                        </option>
                                        <option value="authentication">
                                            Authentication
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                        Encabezado
                                    </label>

                                    <select
                                        value={
                                            form.data.header_type
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'header_type',
                                                event.target.value
                                            )
                                        }
                                        className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none"
                                    >
                                        <option value="">
                                            Sin encabezado
                                        </option>
                                        <option value="text">
                                            Texto
                                        </option>
                                        <option value="image">
                                            Imagen
                                        </option>
                                    </select>
                                </div>
                            </div>

                            {form.data.header_type === 'text' && (
                                <div>
                                    <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                        Texto del encabezado
                                    </label>

                                    <input
                                        type="text"
                                        value={form.data.header_text}
                                        onChange={(event) =>
                                            form.setData(
                                                'header_text',
                                                event.target.value
                                            )
                                        }
                                        className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none focus:border-[var(--color-primary)]"
                                    />
                                </div>
                            )}

                            {form.data.header_type === 'image' && (
                                <div className="rounded-lg border border-dashed border-[var(--color-line)] bg-gray-50 p-4">
                                    <p className="text-[10px] text-[var(--color-ink-faint)]">
                                        El soporte de imagen para el
                                        envío se conectará posteriormente
                                        con n8n. Por ahora la plantilla
                                        queda marcada con encabezado de
                                        imagen.
                                    </p>
                                </div>
                            )}

                            <div>
                                <label className="mb-1 block text-[11px] font-semibold text-[var(--color-ink)]">
                                    Descripción interna
                                </label>

                                <textarea
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value
                                        )
                                    }
                                    placeholder="Descripción para identificar el uso de esta plantilla..."
                                    className="w-full rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-sm outline-none"
                                />
                            </div>

                            <label className="flex cursor-pointer items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_active}
                                    onChange={(event) =>
                                        form.setData(
                                            'is_active',
                                            event.target.checked
                                        )
                                    }
                                    className="h-4 w-4 rounded border-gray-300"
                                />

                                <span className="text-[11px] font-semibold text-[var(--color-ink)]">
                                    Plantilla activa
                                </span>
                            </label>

                            {Object.keys(form.errors).length > 0 && (
                                <div className="rounded-lg bg-red-50 p-3 text-[10px] text-red-600">
                                    Revisa los campos marcados
                                    anteriormente.
                                </div>
                            )}

                            <div className="flex justify-end gap-2 border-t border-[var(--color-line)] pt-5">
                                <button
                                    type="button"
                                    onClick={closeForm}
                                    disabled={form.processing}
                                    className="rounded-lg border border-[var(--color-line)] bg-white px-4 py-2.5 text-[11px] font-semibold text-[var(--color-ink)] hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Cancelar
                                </button>

                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-lg bg-[var(--color-primary)] px-5 py-2.5 text-[11px] font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {form.processing
                                        ? 'Guardando...'
                                        : editingTemplate
                                          ? 'Guardar cambios'
                                          : 'Crear plantilla'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}