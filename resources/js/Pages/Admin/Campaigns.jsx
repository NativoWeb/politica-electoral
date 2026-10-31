import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { apiFetch } from '@/lib/api';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';
import { saveErrorMessage } from '@/lib/userAdmin';
import {
    blockedParentIds, buildCampaignTree, campaignInitials, campaignSummary, flattenCampaignTree, nextSlug, usersUrlFor,
} from '@/lib/campaignAdmin';

const inputCls = 'w-full px-3 py-3 border border-[var(--color-line)] rounded-lg text-[16px] focus:outline-none focus:border-[var(--color-primary)] disabled:bg-[var(--color-bg)] disabled:text-[var(--color-ink-faint)]';
const labelCls = 'block text-[14px] font-bold text-[var(--color-ink-soft)] mb-1';
const iconBtnCls = 'w-11 h-11 lg:w-9 lg:h-9 rounded-lg flex items-center justify-center flex-shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]';

function failedMessage(response) {
    return response?.status === 404 ? 'Esta campaña ya no existe. Recarga la página.' : saveErrorMessage(response);
}

function deactivateMessage(count) {
    const who = count === 1
        ? 'Su único usuario no podrá'
        : `Sus ${count > 1 ? `${new Intl.NumberFormat('es-CO').format(count)} ` : ''}usuarios no podrán`;
    return `${who} entrar al sistema hasta que la actives de nuevo. No se borra ningún dato.`;
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

function CampaignModal({ tree, campaigns, defaultId, campaign, isEdit, onClose }) {
    const isDefault = !!campaign?.isDefault;
    const form = useForm({
        name: campaign?.name ?? '',
        slug: campaign?.slug ?? '',
        parent_id: isEdit ? (campaign?.parentId ?? '') : defaultId,
        whatsapp_enabled: campaign?.whatsappEnabled ?? false,
        candidate_person_id: campaign?.candidateId ?? '',
    });
    const [slugTouched, setSlugTouched] = useState(isEdit);
    const [advanced, setAdvanced] = useState(false);
    const [saveError, setSaveError] = useState('');
    const [candidate, setCandidate] = useState(
        campaign?.candidateId ? { id: campaign.candidateId, name: campaign.candidateName } : null,
    );
    const dialogRef = useRef(null);
    useEscapeKey(onClose, dialogRef);
    useDialogFocus(dialogRef);

    const blocked = useMemo(() => blockedParentIds(campaigns, campaign?.id), [campaigns, campaign?.id]);
    const parentOptions = useMemo(
        () => flattenCampaignTree(tree).filter(({ campaign: c }) => !blocked.has(c.id)),
        [tree, blocked],
    );
    const showAdvanced = advanced || !!form.errors.slug;

    function setName(name) {
        form.setData(data => ({ ...data, name, slug: nextSlug({ name, slug: data.slug, slugTouched }) }));
    }

    function pickCandidate(person) {
        setCandidate(person);
        form.setData('candidate_person_id', person?.id ?? '');
    }

    function submit(e) {
        e.preventDefault();
        setSaveError('');
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onHttpException: response => { setSaveError(failedMessage(response)); return false; },
        };
        if (isEdit) form.put(`/admin/campanas/${campaign?.id}`, options);
        else form.post('/admin/campanas', options);
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-50" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none">
                <div
                    ref={dialogRef}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="campaign-modal-title"
                    className="pointer-events-auto bg-white rounded-2xl shadow-2xl w-full max-w-[520px] max-h-[calc(100dvh-2rem)] flex flex-col overflow-hidden"
                >
                    <div className="bg-[var(--color-primary)] text-white px-5 py-4 flex items-center justify-between gap-3 flex-shrink-0">
                        <h2 id="campaign-modal-title" className="text-[18px] font-extrabold">{isEdit ? 'EDITAR CAMPAÑA' : 'NUEVA CAMPAÑA'}</h2>
                        <button type="button" onClick={onClose} aria-label="Cerrar" title="Cerrar" className="w-11 h-11 rounded-full bg-white/15 flex items-center justify-center flex-shrink-0 active:bg-white/30">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form onSubmit={submit} className="flex flex-col min-h-0 flex-1">
                        <div className="overflow-y-auto px-5 py-5 space-y-4">
                            <div>
                                <label htmlFor="campaign-name" className={labelCls}>Nombre *</label>
                                <input id="campaign-name" autoFocus className={inputCls} value={form.data.name} onChange={e => setName(e.target.value)} required maxLength={255} placeholder="Ej.: Alejandro Cárdenas — Asamblea" />
                                {form.errors.name && <p className="text-[13px] text-red-600 mt-1">{form.errors.name}</p>}
                            </div>

                            <div>
                                <label htmlFor="campaign-parent" className={labelCls}>Campaña madre</label>
                                <select
                                    id="campaign-parent"
                                    className={inputCls}
                                    value={form.data.parent_id}
                                    onChange={e => form.setData('parent_id', e.target.value)}
                                    disabled={isDefault}
                                >
                                    <option value="">Ninguna (independiente)</option>
                                    {parentOptions.map(({ campaign: c, depth }) => (
                                        <option key={c.id} value={c.id}>
                                            {`${'   '.repeat(depth)}${depth ? '└ ' : ''}${c.name}${c.isActive ? '' : ' (inactiva)'}`}
                                        </option>
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
                                {form.errors.whatsapp_enabled && <p className="text-[13px] text-red-600 mt-1">{form.errors.whatsapp_enabled}</p>}
                            </div>

                            <CandidatePicker value={candidate} onChange={pickCandidate} error={form.errors.candidate_person_id} />

                            <div className="border-t border-[var(--color-line)] pt-2">
                                <button
                                    type="button"
                                    onClick={() => setAdvanced(!showAdvanced)}
                                    aria-expanded={showAdvanced}
                                    aria-controls="campaign-advanced"
                                    className="min-h-[44px] flex items-center gap-2 text-[14px] font-bold text-[var(--color-ink-soft)]"
                                >
                                    <svg className={`w-4 h-4 transition-transform ${showAdvanced ? 'rotate-90' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                                    Opciones avanzadas
                                </button>
                                {showAdvanced && (
                                    <div id="campaign-advanced" className="pt-1">
                                        <label htmlFor="campaign-slug" className={labelCls}>Identificador (slug)</label>
                                        <input
                                            id="campaign-slug"
                                            className={`${inputCls} font-mono`}
                                            value={form.data.slug}
                                            onChange={e => { setSlugTouched(true); form.setData('slug', e.target.value); }}
                                            maxLength={255}
                                            placeholder="se-crea-desde-el-nombre"
                                        />
                                        <p className="text-[13px] text-[var(--color-ink-faint)] mt-1">Se llena solo a partir del nombre. Solo letras, números y guiones; debe ser único.</p>
                                        {form.errors.slug && <p className="text-[13px] text-red-600 mt-1">{form.errors.slug}</p>}
                                    </div>
                                )}
                            </div>

                            {saveError && (
                                <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{saveError}</div>
                            )}
                        </div>

                        <div className="flex-shrink-0 border-t border-[var(--color-line)] px-5 py-4 flex gap-3">
                            <button type="submit" disabled={form.processing} className="flex-1 px-4 py-3.5 bg-[var(--color-primary)] text-white text-[16px] font-bold rounded-xl disabled:opacity-50">
                                {form.processing ? 'Guardando...' : (isEdit ? 'Guardar cambios' : 'Crear campaña')}
                            </button>
                            <button type="button" onClick={onClose} className="px-4 py-3.5 border-2 border-gray-200 text-[16px] font-bold text-[var(--color-ink-soft)] rounded-xl">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

// From the third level down the indent shrinks on phones, so a deep tree never scrolls sideways.
function childIndentCls(depth) {
    return depth >= 2 ? 'ml-1.5 pl-2 lg:ml-7 lg:pl-4' : 'ml-5 pl-3 lg:ml-7 lg:pl-4';
}

function CampaignRow({ node, depth = 0, onEdit, onToggle }) {
    return (
        <li data-depth={depth}>
            <div className="bg-white border border-[var(--color-line)] rounded-xl">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5">
                    <div className={`flex items-center gap-3 flex-1 min-w-[min(11rem,100%)] ${node.isActive ? '' : 'opacity-60'}`}>
                        <div className="w-10 h-10 rounded-full bg-[var(--color-primary)] flex items-center justify-center text-white text-[12px] font-bold flex-shrink-0" aria-hidden="true">
                            {campaignInitials(node.name)}
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 min-w-0">
                                <p className="text-[15px] font-bold text-[var(--color-ink)] truncate" title={node.name}>{node.name}</p>
                                {!node.isActive && (
                                    <span className="flex-shrink-0 px-2 py-0.5 rounded-md bg-red-50 border border-red-200 text-red-700 text-[11px] font-bold">Inactiva</span>
                                )}
                            </div>
                            <p className="text-[13px] text-[var(--color-ink-faint)]">{campaignSummary(node)}</p>
                            {node.candidateName && (
                                <p className="text-[13px] text-[var(--color-ink-soft)] truncate" title={node.candidateName}>
                                    Candidato: <span className="font-semibold text-[var(--color-ink)]">{node.candidateName}</span>
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="flex gap-1.5 ml-auto">
                        <button type="button" onClick={() => onEdit(node)} className={`${iconBtnCls} bg-blue-50 text-blue-600 active:bg-blue-100`} title="Editar" aria-label={`Editar ${node.name}`}>
                            <svg className="w-5 h-5 lg:w-4 lg:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                        </button>
                        <Link href={usersUrlFor(node.id)} className={`${iconBtnCls} bg-indigo-50 text-indigo-600 active:bg-indigo-100`} title="Crear usuario para esta campaña" aria-label={`Crear usuario para ${node.name}`}>
                            <svg className="w-5 h-5 lg:w-4 lg:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
                        </Link>
                        {!node.isDefault && (
                            <button
                                type="button"
                                onClick={() => onToggle(node)}
                                className={`${iconBtnCls} ${node.isActive ? 'bg-amber-50 text-amber-600 active:bg-amber-100' : 'bg-emerald-50 text-emerald-600 active:bg-emerald-100'}`}
                                title={node.isActive ? 'Desactivar' : 'Activar'}
                                aria-label={`${node.isActive ? 'Desactivar' : 'Activar'} ${node.name}`}
                            >
                                <svg className="w-5 h-5 lg:w-4 lg:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M12 3v9m6.36-5.36a9 9 0 11-12.72 0" /></svg>
                            </button>
                        )}
                    </div>
                </div>
            </div>
            {node.children.length > 0 && (
                <ul className={`mt-2 ${childIndentCls(depth)} border-l-2 border-[var(--color-line)] space-y-2`}>
                    {node.children.map(child => (
                        <CampaignRow key={child.id} node={child} depth={depth + 1} onEdit={onEdit} onToggle={onToggle} />
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function Campaigns({ campaigns, defaultId }) {
    const { props } = usePage();
    const success = props.flash?.success;
    const toggleError = props.errors?.toggle;
    const [modal, setModal] = useState(null);
    const [toggleTarget, setToggleTarget] = useState(null);
    const [listError, setListError] = useState('');
    const tree = useMemo(() => buildCampaignTree(campaigns, defaultId), [campaigns, defaultId]);
    const editing = modal?.id ? (campaigns.find(c => c.id === modal.id) ?? null) : null;

    function confirmToggle() {
        const campaign = toggleTarget;
        setToggleTarget(null);
        setListError('');
        router.post(`/admin/campanas/${campaign.id}/toggle`, {}, {
            preserveScroll: true,
            onHttpException: response => { setListError(failedMessage(response)); return false; },
        });
    }

    return (
        <AppLayout title="Campañas" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'CAMPAÑAS' }]}>
            <Head title="Campañas — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">CAMPAÑAS</h1>
                    <p className="text-[12px] text-white/60 mt-0.5">
                        {campaigns.length} {campaigns.length === 1 ? 'campaña registrada' : 'campañas registradas'}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setModal({ id: null })}
                    className="min-h-[44px] px-4 py-2.5 bg-white/15 text-white text-[15px] font-bold rounded-xl active:bg-white/25 border border-white/20 flex-shrink-0"
                >
                    + Nueva campaña
                </button>
            </div>

            <div className="p-4 space-y-3 max-w-5xl">
                {success && (
                    <div role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{success}</div>
                )}
                {(toggleError || listError) && (
                    <div role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{toggleError || listError}</div>
                )}

                {tree.length > 0 ? (
                    <ul className="space-y-2" aria-label="Campañas y subcampañas">
                        {tree.map(node => (
                            <CampaignRow key={node.id} node={node} onEdit={c => setModal({ id: c.id })} onToggle={setToggleTarget} />
                        ))}
                    </ul>
                ) : (
                    <p className="text-center py-8 text-[15px] text-[var(--color-ink-faint)]">Todavía no hay campañas.</p>
                )}

                <p className="flex items-start gap-2 text-[12px] leading-relaxed text-[var(--color-ink-faint)] px-1 pt-3">
                    <svg className="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span>
                        Las campañas no se borran: se desactivan. Mientras una campaña esté inactiva, sus usuarios no pueden entrar
                        y quienes estén dentro salen en su siguiente acción. Los superadministradores siempre pueden entrar.
                    </span>
                </p>
            </div>

            {modal && (!modal.id || editing) && (
                <CampaignModal
                    key={modal.id ?? 'new'}
                    tree={tree}
                    campaigns={campaigns}
                    defaultId={defaultId}
                    campaign={editing}
                    isEdit={!!modal.id}
                    onClose={() => setModal(null)}
                />
            )}

            {toggleTarget && (
                <ConfirmDialog
                    title={toggleTarget.isActive ? `¿Desactivar la campaña ${toggleTarget.name}?` : `¿Activar la campaña ${toggleTarget.name}?`}
                    message={toggleTarget.isActive
                        ? deactivateMessage(toggleTarget.usersCount)
                        : 'Sus usuarios podrán volver a entrar con su correo y su contraseña.'}
                    confirmLabel={toggleTarget.isActive ? 'Sí, desactivar' : 'Sí, activar'}
                    danger={toggleTarget.isActive}
                    onConfirm={confirmToggle}
                    onCancel={() => setToggleTarget(null)}
                />
            )}
        </AppLayout>
    );
}
