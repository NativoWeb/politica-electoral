import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';
import { blockedParentIds, nextSlug, usersUrlFor } from '@/lib/campaignAdmin';

const inputCls = 'w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[16px] focus:outline-none focus:border-[var(--color-primary)] disabled:bg-[var(--color-bg)] disabled:text-[var(--color-ink-faint)]';
const labelCls = 'block text-[14px] font-bold text-[var(--color-ink-soft)] mb-1';

function Chip({ tone = 'neutral', children }) {
    const tones = {
        neutral: 'bg-[var(--color-bg)] border-[var(--color-line)] text-[var(--color-ink-soft)]',
        ok: 'bg-emerald-50 border-emerald-200 text-emerald-700',
        off: 'bg-red-50 border-red-200 text-red-700',
    };
    return (
        <span className={`inline-flex items-center px-2.5 py-1 rounded-lg border text-[13px] font-semibold ${tones[tone]}`}>
            {children}
        </span>
    );
}

function CandidatePicker({ value, onChange, error }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [status, setStatus] = useState('idle');
    const timer = useRef(null);

    useEffect(() => () => clearTimeout(timer.current), []);

    function search(text) {
        setQuery(text);
        clearTimeout(timer.current);
        if (text.trim().length < 3) {
            setResults([]);
            setStatus('idle');
            return;
        }
        timer.current = setTimeout(async () => {
            setStatus('loading');
            try {
                const res = await apiFetch(`/admin/campanas/candidatos?${new URLSearchParams({ q: text.trim() })}`);
                if (!res.ok) throw new Error(String(res.status));
                const json = await res.json();
                setResults(json.data || []);
                setStatus('done');
            } catch {
                setResults([]);
                setStatus('error');
            }
        }, 350);
    }

    if (value) {
        return (
            <div>
                <span className={labelCls}>Candidato (opcional)</span>
                <div className="flex items-center gap-3 px-3 py-3 border border-[var(--color-line)] rounded-lg bg-[var(--color-bg)]">
                    <span className="flex-1 min-w-0 text-[16px] font-semibold text-[var(--color-ink)] truncate">{value.name}</span>
                    <button type="button" onClick={() => onChange(null)} className="px-3 py-2 text-[14px] font-bold text-red-600 rounded-lg border border-red-200 bg-white active:bg-red-50">
                        Quitar
                    </button>
                </div>
                {error && <p className="text-[13px] text-red-600 mt-1">{error}</p>}
            </div>
        );
    }

    return (
        <div>
            <label htmlFor="campaign-candidate" className={labelCls}>Candidato (opcional)</label>
            <input
                id="campaign-candidate"
                className={inputCls}
                value={query}
                onChange={e => search(e.target.value)}
                placeholder="Escribe al menos 3 letras del nombre"
                autoComplete="off"
            />
            {status === 'loading' && <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">Buscando...</p>}
            {status === 'error' && <p className="text-[13px] text-red-600 mt-1">No se pudo buscar. Intenta de nuevo.</p>}
            {status === 'done' && results.length === 0 && (
                <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">No hay personas con ese nombre.</p>
            )}
            {results.length > 0 && (
                <ul className="mt-1 border border-[var(--color-line)] rounded-lg overflow-hidden divide-y divide-[var(--color-line)] max-h-60 overflow-y-auto">
                    {results.map(p => (
                        <li key={p.id}>
                            <button
                                type="button"
                                onClick={() => { onChange(p); setQuery(''); setResults([]); setStatus('idle'); }}
                                className="w-full text-left px-3 py-3 bg-white hover:bg-gray-50 active:bg-gray-100"
                            >
                                <span className="block text-[15px] font-semibold text-[var(--color-ink)]">{p.name}</span>
                                <span className="block text-[12px] text-[var(--color-ink-faint)]">
                                    {p.candidacies === 1 ? '1 candidatura' : `${p.candidacies} candidaturas`}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <p className="text-[13px] text-red-600 mt-1">{error}</p>}
        </div>
    );
}

function CampaignForm({ campaigns, defaultId, campaign, onDone }) {
    const isEdit = !!campaign;
    const isDefault = !!campaign?.isDefault;
    const form = useForm({
        name: campaign?.name ?? '',
        slug: campaign?.slug ?? '',
        parent_id: isEdit ? (campaign.parentId ?? '') : defaultId,
        whatsapp_enabled: campaign?.whatsappEnabled ?? false,
        candidate_person_id: campaign?.candidateId ?? '',
    });
    const [slugTouched, setSlugTouched] = useState(isEdit);
    const [candidate, setCandidate] = useState(
        campaign?.candidateId ? { id: campaign.candidateId, name: campaign.candidateName } : null,
    );
    const blocked = useMemo(() => blockedParentIds(campaigns, campaign?.id), [campaigns, campaign?.id]);
    const parentOptions = campaigns.filter(c => !blocked.has(c.id));
    const idPrefix = campaign?.id ?? 'new';

    function setName(name) {
        form.setData(data => ({ ...data, name, slug: nextSlug({ name, slug: data.slug, slugTouched }) }));
    }

    function pickCandidate(person) {
        setCandidate(person);
        form.setData('candidate_person_id', person?.id ?? '');
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (isEdit) form.put(`/admin/campanas/${campaign.id}`, options);
        else form.post('/admin/campanas', options);
    }

    return (
        <form onSubmit={submit} className="bg-white border-2 border-[var(--color-primary)] rounded-xl p-4 lg:p-5 space-y-4">
            <h3 className="text-[17px] font-bold text-[var(--color-primary)]">{isEdit ? `Editar: ${campaign.name}` : 'Nueva campaña'}</h3>

            <div>
                <label htmlFor={`${idPrefix}-name`} className={labelCls}>Nombre *</label>
                <input id={`${idPrefix}-name`} className={inputCls} value={form.data.name} onChange={e => setName(e.target.value)} required maxLength={255} placeholder="Ej.: Alejandro Cárdenas — Asamblea" />
                {form.errors.name && <p className="text-[13px] text-red-600 mt-1">{form.errors.name}</p>}
            </div>

            <div>
                <label htmlFor={`${idPrefix}-slug`} className={labelCls}>Identificador (slug)</label>
                <input
                    id={`${idPrefix}-slug`}
                    className={`${inputCls} font-mono`}
                    value={form.data.slug}
                    onChange={e => { setSlugTouched(true); form.setData('slug', e.target.value); }}
                    maxLength={255}
                    placeholder="se-crea-desde-el-nombre"
                />
                <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">Se llena solo a partir del nombre. Solo letras, números y guiones; debe ser único.</p>
                {form.errors.slug && <p className="text-[13px] text-red-600 mt-1">{form.errors.slug}</p>}
            </div>

            <div>
                <label htmlFor={`${idPrefix}-parent`} className={labelCls}>Campaña madre</label>
                <select
                    id={`${idPrefix}-parent`}
                    className={inputCls}
                    value={form.data.parent_id}
                    onChange={e => form.setData('parent_id', e.target.value)}
                    disabled={isDefault}
                >
                    <option value="">Ninguna (independiente)</option>
                    {parentOptions.map(c => (
                        <option key={c.id} value={c.id}>{c.name}{c.isActive ? '' : ' (inactiva)'}</option>
                    ))}
                </select>
                <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">
                    {isDefault
                        ? 'La campaña principal no tiene campaña madre.'
                        : 'El equipo de la campaña madre ve los líderes de esta campaña. Independiente: nadie más los ve.'}
                </p>
                {form.errors.parent_id && <p className="text-[13px] text-red-600 mt-1">{form.errors.parent_id}</p>}
            </div>

            <div className="border border-[var(--color-line)] rounded-lg p-3">
                <label className="flex items-start gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        checked={!!form.data.whatsapp_enabled}
                        onChange={e => form.setData('whatsapp_enabled', e.target.checked)}
                        className="mt-1 w-5 h-5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                    />
                    <span>
                        <span className="block text-[15px] font-bold text-[var(--color-ink)]">WhatsApp habilitado</span>
                        <span className="block text-[13px] text-[var(--color-ink-faint)] mt-0.5">
                            Requiere un número propio; si se activa, sus líderes podrán recibir mensajes masivos y de cumpleaños.
                        </span>
                    </span>
                </label>
            </div>

            <CandidatePicker value={candidate} onChange={pickCandidate} error={form.errors.candidate_person_id} />

            <div className="flex flex-col sm:flex-row gap-3">
                <button type="submit" disabled={form.processing} className="flex-1 px-4 py-3.5 bg-[var(--color-primary)] text-white text-[16px] font-bold rounded-xl disabled:opacity-50">
                    {form.processing ? 'Guardando...' : (isEdit ? 'Guardar cambios' : 'Crear campaña')}
                </button>
                <button type="button" onClick={onDone} className="px-4 py-3.5 border-2 border-gray-200 text-[16px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                    Cancelar
                </button>
            </div>
        </form>
    );
}

function CampaignCard({ campaign, onEdit }) {
    function toggle() {
        if (campaign.isActive && !confirm(`¿Desactivar la campaña "${campaign.name}"?\n\nSus ${campaign.usersCount} usuario(s) no podrán entrar al sistema hasta que la actives de nuevo. No se borra ningún dato.`)) return;
        router.post(`/admin/campanas/${campaign.id}/toggle`, {}, { preserveScroll: true });
    }

    return (
        <div className={`bg-white border border-[var(--color-line)] rounded-xl p-4 ${campaign.isActive ? '' : 'bg-gray-50'}`}>
            <div className="flex flex-col lg:flex-row lg:items-center gap-3">
                <div className="flex-1 min-w-0">
                    <p className="text-[17px] font-bold text-[var(--color-ink)] break-words">{campaign.name}</p>
                    <p className="text-[12px] text-[var(--color-ink-faint)] font-mono break-all">{campaign.slug}</p>
                    <div className="flex flex-wrap gap-1.5 mt-2">
                        <Chip>{campaign.parentName ? `Campaña madre: ${campaign.parentName}` : 'Principal'}</Chip>
                        <Chip tone={campaign.whatsappEnabled ? 'ok' : 'neutral'}>WhatsApp: {campaign.whatsappEnabled ? 'Sí' : 'No'}</Chip>
                        <Chip tone={campaign.isActive ? 'ok' : 'off'}>{campaign.isActive ? 'Activa' : 'Inactiva'}</Chip>
                    </div>
                    <p className="text-[14px] text-[var(--color-ink-soft)] mt-2">
                        <span className="font-bold text-[var(--color-ink)]">{campaign.usersCount}</span> usuario{campaign.usersCount === 1 ? '' : 's'}
                        {' · '}
                        <span className="font-bold text-[var(--color-ink)]">{campaign.lideresCount}</span> líder{campaign.lideresCount === 1 ? '' : 'es'}
                    </p>
                    {campaign.candidateName && (
                        <p className="text-[14px] text-[var(--color-ink-soft)] mt-1">Candidato: <span className="font-semibold text-[var(--color-ink)]">{campaign.candidateName}</span></p>
                    )}
                </div>
                <div className="grid grid-cols-2 lg:flex gap-2 lg:flex-shrink-0">
                    <button type="button" onClick={onEdit} className="min-h-[44px] px-4 py-2.5 rounded-xl bg-blue-50 text-blue-700 text-[14px] font-bold active:bg-blue-100">
                        Editar
                    </button>
                    {campaign.isDefault ? (
                        <span className="min-h-[44px] px-4 py-2.5 rounded-xl border border-[var(--color-line)] text-[13px] font-semibold text-[var(--color-ink-faint)] flex items-center justify-center text-center">
                            Siempre activa
                        </span>
                    ) : (
                        <button
                            type="button"
                            onClick={toggle}
                            className={`min-h-[44px] px-4 py-2.5 rounded-xl text-[14px] font-bold ${campaign.isActive ? 'bg-amber-50 text-amber-700 active:bg-amber-100' : 'bg-emerald-50 text-emerald-700 active:bg-emerald-100'}`}
                        >
                            {campaign.isActive ? 'Desactivar' : 'Activar'}
                        </button>
                    )}
                    <Link
                        href={usersUrlFor(campaign.id)}
                        className="col-span-2 min-h-[44px] px-4 py-2.5 rounded-xl bg-[var(--color-primary)] text-white text-[14px] font-bold flex items-center justify-center text-center"
                    >
                        Crear usuario para esta campaña
                    </Link>
                </div>
            </div>
        </div>
    );
}

export default function Campaigns({ campaigns, defaultId }) {
    const { props } = usePage();
    const success = props.flash?.success;
    const toggleError = props.errors?.toggle;
    const [mode, setMode] = useState(null);
    const editing = mode && mode !== 'new' ? campaigns.find(c => c.id === mode) : null;

    return (
        <AppLayout title="Campañas" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'CAMPAÑAS' }]}>
            <Head title="Campañas — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">CAMPAÑAS</h1>
                    <p className="text-[12px] text-white/60 mt-0.5">{campaigns.length} campaña{campaigns.length === 1 ? '' : 's'}</p>
                </div>
                <button
                    type="button"
                    onClick={() => setMode(mode === 'new' ? null : 'new')}
                    className="px-4 py-2.5 bg-white/15 text-white text-[15px] font-bold rounded-xl active:bg-white/25 border border-white/20 flex-shrink-0"
                >
                    {mode === 'new' ? 'Cancelar' : '+ Crear'}
                </button>
            </div>

            <div className="p-4 space-y-3 max-w-5xl">
                {success && (
                    <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{success}</div>
                )}
                {toggleError && (
                    <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{toggleError}</div>
                )}

                {mode === 'new' && (
                    <CampaignForm key="new" campaigns={campaigns} defaultId={defaultId} onDone={() => setMode(null)} />
                )}

                {campaigns.map(c => (
                    editing?.id === c.id ? (
                        <CampaignForm key={`edit-${c.id}`} campaigns={campaigns} defaultId={defaultId} campaign={c} onDone={() => setMode(null)} />
                    ) : (
                        <CampaignCard key={c.id} campaign={c} onEdit={() => setMode(c.id)} />
                    )
                ))}

                <p className="text-[13px] text-[var(--color-ink-faint)] px-1 pt-2">
                    Las campañas no se borran: se desactivan. Mientras una campaña esté inactiva, sus usuarios no pueden entrar
                    y quienes estén dentro salen en su siguiente acción. Los superadministradores siempre pueden entrar.
                </p>
            </div>
        </AppLayout>
    );
}
