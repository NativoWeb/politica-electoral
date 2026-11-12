import { useEffect, useRef, useState } from 'react';
import TabBar from '@/Components/TabBar';
import CampaignChip from '@/Components/CampaignChip';
import ConfirmDialog from '@/Components/ConfirmDialog';
import OrganizacionForm from '@/Components/Empresarios/OrganizacionForm';
import MiembrosTab from '@/Components/Empresarios/MiembrosTab';
import ActasOrganizacion from '@/Components/Empresarios/ActasOrganizacion';
import useEscapeKey from '@/lib/useEscapeKey';
import { failure, request } from '@/lib/actas';
import { toForm, toPayload } from '@/lib/empresarios';

function DataRow({ label, value }) {
    return (
        <div className="flex items-start justify-between gap-3 py-2 border-b border-[var(--color-line)] last:border-0">
            <span className="text-[14px] text-[var(--color-ink-faint)] uppercase tracking-wider flex-shrink-0 w-[150px]">{label}</span>
            <span className="text-[16px] text-[var(--color-ink)] text-right flex-1 break-words">{value || '—'}</span>
        </div>
    );
}

// Ficha of an organization (orgId) or the form of a new one (orgId null), in a centered modal with tabs:
// Datos, Miembros, Gremios/Empresas and Actas. onSaved(ficha) / onDeleted() let the list reload.
export default function OrganizacionPanel({ orgId, onClose, onSaved, onDeleted, onOpen, canManage, catalogos, municipios, gremios, rangos, roles }) {
    const creating = !orgId;
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(!creating);
    const [tab, setTab] = useState('datos');
    const [editing, setEditing] = useState(creating);
    const [form, setForm] = useState(toForm(null));
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [askDelete, setAskDelete] = useState(false);
    const rootRef = useRef(null);
    useEscapeKey(() => (askDelete ? setAskDelete(false) : onClose()), rootRef);

    useEffect(() => {
        if (creating) return undefined;
        let alive = true;
        setLoading(true);
        request(`/empresarios/${orgId}`).then(res => {
            if (!alive) return;
            setLoading(false);
            if (!res.ok) { setError(failure(res, 'No se pudo abrir la organización.')); return; }
            setData(res.body.data);
            setForm(toForm(res.body.data));
        });
        return () => { alive = false; };
    }, [orgId, creating]);

    const editable = creating ? canManage : !!data?.can?.edit;
    // An empresa only joins gremios of its own campaign.
    const ownGremios = (gremios ?? []).filter(g => g.id !== data?.id && (creating || g.campaign_id === data?.campaign_id));

    async function save(e) {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError('');
        const res = await request(creating ? '/empresarios' : `/empresarios/${orgId}`, { method: creating ? 'POST' : 'PUT', json: toPayload(form) });
        setBusy(false);
        if (!res.ok) {
            const errs = res.body?.errors ?? {};
            setErrors(Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
            setError(res.status === 422 ? 'Revise los datos marcados.' : failure(res, 'No se pudo guardar.'));
            return;
        }
        setData(res.body.data);
        setForm(toForm(res.body.data));
        setEditing(false);
        setMessage(res.body.message);
        onSaved?.(res.body.data);
    }

    async function remove() {
        setAskDelete(false);
        setBusy(true);
        const res = await request(`/empresarios/${orgId}`, { method: 'DELETE' });
        setBusy(false);
        if (!res.ok) { setError(failure(res, 'No se pudo eliminar.')); return; }
        onDeleted?.();
    }

    const related = data?.tipo === 'gremio' ? data?.empresas : data?.gremios;
    const tabs = creating ? [{ key: 'datos', label: 'Datos' }] : [
        { key: 'datos', label: 'Datos' },
        { key: 'miembros', label: `Miembros (${data?.miembros?.length ?? 0})` },
        { key: 'relacion', label: data?.tipo === 'gremio' ? `Empresas (${related?.length ?? 0})` : `Gremios (${related?.length ?? 0})` },
        { key: 'actas', label: 'Actas' },
    ];

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-50" onClick={onClose} />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-0 sm:p-4 pointer-events-none">
                <div ref={rootRef} role="dialog" aria-modal="true" aria-labelledby="org-panel-title" className="pointer-events-auto bg-[var(--color-surface,#f8fafc)] w-full h-full sm:h-auto sm:max-h-[92vh] sm:max-w-[860px] sm:rounded-2xl shadow-2xl overflow-y-auto">
                    <div className="sticky top-0 z-10 bg-[var(--color-primary)] text-white px-4 py-3 flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-[13px] font-semibold text-white/75 uppercase">{creating ? 'Nueva organización' : data?.tipo_label}</p>
                            <h2 id="org-panel-title" className="text-[20px] font-extrabold leading-tight break-words">{creating ? 'Empresa o gremio' : (data?.nombre ?? 'Cargando…')}</h2>
                            {data && <CampaignChip campaignId={data.campaign_id} />}
                        </div>
                        <button type="button" onClick={onClose} className="min-w-[44px] min-h-[44px] rounded-lg bg-white/15 text-[20px] font-bold" aria-label="Cerrar">×</button>
                    </div>
                    <TabBar tabs={tabs} active={tab} onChange={setTab} />

                    <div className="p-4 space-y-3">
                        {message && <p role="status" className="px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{message}</p>}
                        {error && <p role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{error}</p>}
                        {loading && <p role="status" className="text-[15px] text-[var(--color-ink-faint)]">Cargando…</p>}

                        {tab === 'datos' && (creating || data) && (editing ? (
                            <form onSubmit={save} className="space-y-4">
                                <OrganizacionForm form={form} setForm={setForm} errors={errors} catalogos={catalogos} municipios={municipios} gremios={ownGremios} rangos={rangos} />
                                <div className="flex flex-wrap gap-2">
                                    <button type="submit" disabled={busy} className="min-h-[48px] px-6 rounded-xl bg-[var(--color-primary)] text-white text-[16px] font-bold disabled:opacity-50">{busy ? 'Guardando…' : 'Guardar'}</button>
                                    <button type="button" onClick={() => (creating ? onClose() : (setEditing(false), setForm(toForm(data)), setErrors({})))} className="min-h-[48px] px-5 rounded-xl border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)]">Cancelar</button>
                                </div>
                            </form>
                        ) : (
                            <div className="space-y-3">
                                <div className="bg-white rounded-xl border border-[var(--color-line)] px-4">
                                    <DataRow label="NIT" value={data.nit_formateado} />
                                    <DataRow label="Representante" value={[data.representante_nombre, data.representante_cedula && `C.C. ${data.representante_cedula}`].filter(Boolean).join(' · ')} />
                                    <DataRow label="Celular repr." value={data.representante_celular} />
                                    <DataRow label="Teléfono" value={data.telefono} />
                                    <DataRow label="Correo" value={data.correo} />
                                    <DataRow label="Sitio web" value={data.sitio_web} />
                                    <DataRow label="Sector económico" value={data.sector_economico} />
                                    <DataRow label="Sector" value={data.sector} />
                                    <DataRow label="Ubicación" value={[data.municipio, data.provincia, data.departamento].filter(Boolean).join(', ')} />
                                    <DataRow label="Dirección" value={[data.direccion, data.barrio].filter(Boolean).join(' · ')} />
                                    <DataRow label="Empleados" value={data.empleados ?? data.empleados_rango} />
                                    <DataRow label="Estado" value={data.is_active ? 'Activa' : 'Inactiva'} />
                                    <DataRow label="Observaciones" value={data.observaciones} />
                                </div>
                                {editable && (
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" onClick={() => setEditing(true)} className="min-h-[48px] px-6 rounded-xl bg-[var(--color-primary)] text-white text-[16px] font-bold">Editar</button>
                                        <button type="button" disabled={busy} onClick={() => setAskDelete(true)} className="min-h-[48px] px-5 rounded-xl border-2 border-red-300 text-red-700 text-[15px] font-bold">Eliminar</button>
                                    </div>
                                )}
                            </div>
                        ))}

                        {tab === 'miembros' && data && <MiembrosTab org={data} editable={editable} roles={roles} onChanged={d => d && setData(d)} />}

                        {tab === 'relacion' && data && (
                            <div className="space-y-2">
                                {(related ?? []).length === 0 && (
                                    <p className="text-[15px] text-[var(--color-ink-faint)]">
                                        {data.tipo === 'gremio' ? 'Ninguna empresa está afiliada a este gremio.' : 'No pertenece a ningún gremio. Edite los datos para elegirlos.'}
                                    </p>
                                )}
                                <ul className="space-y-2" aria-label={data.tipo === 'gremio' ? 'Empresas afiliadas' : 'Gremios'}>
                                    {(related ?? []).map(r => (
                                        <li key={r.id}>
                                            <button type="button" onClick={() => onOpen?.(r.id)} className="w-full min-h-[48px] px-3 py-2 rounded-xl border border-[var(--color-line)] bg-white text-left hover:bg-blue-50">
                                                <span className="text-[15px] font-bold text-[var(--color-primary)] uppercase">{r.nombre}</span>
                                                {r.municipio && <span className="text-[14px] text-[var(--color-ink-soft)]"> · {r.municipio}</span>}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {tab === 'actas' && data && <ActasOrganizacion orgId={data.id} editable={editable && data.is_active} />}
                    </div>
                </div>
            </div>
            {askDelete && (
                <ConfirmDialog
                    title="¿Eliminar la organización?"
                    message="Deja de aparecer en la lista. Las fichas de sus miembros en Votantes no cambian."
                    confirmLabel="Sí, eliminar"
                    danger
                    onConfirm={remove}
                    onCancel={() => setAskDelete(false)}
                />
            )}
        </>
    );
}
