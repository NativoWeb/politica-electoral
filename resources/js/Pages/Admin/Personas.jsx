import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import SectionCard from '@/Components/SectionCard';
import { useState, useCallback } from 'react';

function Badge({ label, cls }) {
    return <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${cls}`}>{label}</span>;
}

function StatusBadge({ status }) {
    const map = {
        confirmed: { label: 'CONFIRMADO', cls: 'bg-emerald-100 text-emerald-700' },
        unverified: { label: 'SIN VERIFICAR', cls: 'bg-amber-100 text-amber-700' },
        duplicate: { label: 'DUPLICADO', cls: 'bg-red-100 text-red-600' },
        merged: { label: 'FUSIONADO', cls: 'bg-purple-100 text-purple-700' },
    };
    const m = map[status] ?? { label: status ?? '—', cls: 'bg-gray-100 text-gray-500' };
    return <Badge {...m} />;
}

/* ── Modal genérico ── */
function Modal({ open, onClose, title, children }) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={onClose}>
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between px-6 py-4 border-b border-[var(--color-line)]">
                    <h3 className="text-[14px] font-bold text-[var(--color-ink)]">{title}</h3>
                    <button onClick={onClose} className="text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] text-lg">&times;</button>
                </div>
                <div className="p-6">{children}</div>
            </div>
        </div>
    );
}

/* ── Form fields ── */
function Field({ label, children }) {
    return (
        <div>
            <label className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">{label}</label>
            {children}
        </div>
    );
}

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";
const btnPrimary = "px-4 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors";
const btnDanger = "px-3 py-1.5 bg-red-50 text-red-600 text-[11px] font-bold rounded-lg hover:bg-red-100 transition-colors";
const btnSecondary = "px-4 py-2 border border-[var(--color-line)] text-[12px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 transition-colors";

/* ── Create/Edit Person Modal ── */
function PersonFormModal({ open, onClose, person = null }) {
    const isEdit = !!person;
    const form = useForm({
        first_name: person?.firstName ?? '',
        last_name: person?.lastName ?? '',
        gender: person?.gender ?? '',
        birth_date: person?.birthDate ?? '',
        identity_status: person?.status ?? 'unverified',
        data_classification: person?.classification ?? 'internal',
    });

    function submit(e) {
        e.preventDefault();
        if (isEdit) {
            form.put(`/admin/personas/${person.id}`, { onSuccess: onClose, preserveScroll: true });
        } else {
            form.post('/admin/personas', { onSuccess: () => { form.reset(); onClose(); }, preserveScroll: true });
        }
    }

    return (
        <Modal open={open} onClose={onClose} title={isEdit ? 'Editar persona' : 'Nueva persona'}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Nombre">
                        <input className={inputCls} value={form.data.first_name} onChange={e => form.setData('first_name', e.target.value)} required />
                    </Field>
                    <Field label="Apellido">
                        <input className={inputCls} value={form.data.last_name} onChange={e => form.setData('last_name', e.target.value)} required />
                    </Field>
                </div>
                <div className="grid grid-cols-3 gap-4">
                    <Field label="Genero">
                        <select className={inputCls} value={form.data.gender} onChange={e => form.setData('gender', e.target.value)}>
                            <option value="">—</option>
                            <option value="male">Masculino</option>
                            <option value="female">Femenino</option>
                            <option value="other">Otro</option>
                        </select>
                    </Field>
                    <Field label="Fecha nacimiento">
                        <input type="date" className={inputCls} value={form.data.birth_date} onChange={e => form.setData('birth_date', e.target.value)} />
                    </Field>
                    <Field label="Clasificacion">
                        <select className={inputCls} value={form.data.data_classification} onChange={e => form.setData('data_classification', e.target.value)}>
                            <option value="public">Publico</option>
                            <option value="internal">Interno</option>
                            <option value="confidential">Confidencial</option>
                            <option value="restricted">Restringido</option>
                        </select>
                    </Field>
                </div>
                {isEdit && (
                    <Field label="Estado de identidad">
                        <select className={inputCls} value={form.data.identity_status} onChange={e => form.setData('identity_status', e.target.value)}>
                            <option value="unverified">Sin verificar</option>
                            <option value="confirmed">Confirmado</option>
                            <option value="duplicate">Duplicado</option>
                            <option value="merged">Fusionado</option>
                        </select>
                    </Field>
                )}
                {form.errors && Object.values(form.errors).map((err, i) => (
                    <p key={i} className="text-[12px] text-red-600">{err}</p>
                ))}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className={btnSecondary}>Cancelar</button>
                    <button type="submit" disabled={form.processing} className={btnPrimary}>
                        {form.processing ? 'Guardando...' : isEdit ? 'Actualizar' : 'Crear persona'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/* ── Detail panel (aliases, contacts) ── */
function PersonDetail({ person, onClose }) {
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(true);
    const [aliasName, setAliasName] = useState('');
    const [contactType, setContactType] = useState('phone');
    const [contactValue, setContactValue] = useState('');

    useState(() => {
        fetch(`/admin/personas/${person.id}/json`)
            .then(r => r.json())
            .then(d => { setDetail(d); setLoading(false); })
            .catch(() => setLoading(false));
    }, [person.id]);

    if (loading) return <div className="p-6 text-center text-[var(--color-ink-faint)]">Cargando...</div>;
    if (!detail) return null;

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h3 className="text-[14px] font-bold text-[var(--color-ink)]">{detail.fullName}</h3>
                <button onClick={onClose} className="text-[11px] text-[var(--color-ink-faint)] hover:text-[var(--color-ink)]">&times; Cerrar</button>
            </div>

            {/* Aliases */}
            <div>
                <h4 className="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-2">Aliases ({detail.aliases?.length ?? 0})</h4>
                <div className="flex flex-wrap gap-1.5 mb-2">
                    {detail.aliases?.map(a => (
                        <span key={a.id} className="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-[11px] text-[var(--color-primary)] font-semibold rounded">
                            {a.name}
                            <button onClick={() => router.delete(`/admin/personas/${person.id}/aliases/${a.id}`, { preserveScroll: true })} className="text-red-400 hover:text-red-600 ml-1">&times;</button>
                        </span>
                    ))}
                </div>
                <form onSubmit={e => { e.preventDefault(); router.post(`/admin/personas/${person.id}/aliases`, { alias_name: aliasName, alias_type: 'other' }, { preserveScroll: true }); setAliasName(''); }} className="flex gap-2">
                    <input className={`${inputCls} flex-1`} value={aliasName} onChange={e => setAliasName(e.target.value)} placeholder="Nuevo alias..." required />
                    <button type="submit" className={btnPrimary}>Agregar</button>
                </form>
            </div>

            {/* Contacts */}
            <div>
                <h4 className="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-2">Contactos ({detail.contacts?.length ?? 0})</h4>
                <div className="space-y-1.5 mb-2">
                    {detail.contacts?.map(c => (
                        <div key={c.id} className="flex items-center justify-between px-3 py-2 bg-gray-50 rounded-lg text-[12px]">
                            <div>
                                <span className="font-bold text-[var(--color-ink-faint)] uppercase text-[10px] mr-2">{c.type}</span>
                                <span className="text-[var(--color-ink)]">{c.value}</span>
                                {c.label && <span className="text-[var(--color-ink-faint)] ml-2">({c.label})</span>}
                            </div>
                            <button onClick={() => router.delete(`/admin/personas/${person.id}/contacts/${c.id}`, { preserveScroll: true })} className={btnDanger}>Eliminar</button>
                        </div>
                    ))}
                </div>
                <form onSubmit={e => { e.preventDefault(); router.post(`/admin/personas/${person.id}/contacts`, { type: contactType, value: contactValue }, { preserveScroll: true }); setContactValue(''); }} className="flex gap-2">
                    <select className={`${inputCls} w-24`} value={contactType} onChange={e => setContactType(e.target.value)}>
                        <option value="phone">Telefono</option>
                        <option value="email">Email</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="other">Otro</option>
                    </select>
                    <input className={`${inputCls} flex-1`} value={contactValue} onChange={e => setContactValue(e.target.value)} placeholder="Valor..." required />
                    <button type="submit" className={btnPrimary}>Agregar</button>
                </form>
            </div>

            {/* Candidaturas (read-only) */}
            {detail.candidacies?.length > 0 && (
                <div>
                    <h4 className="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-2">Candidaturas ({detail.candidacies.length})</h4>
                    <div className="space-y-1">
                        {detail.candidacies.map(c => (
                            <div key={c.id} className="flex items-center justify-between px-3 py-2 bg-gray-50 rounded text-[12px]">
                                <span className="text-[var(--color-ink)]">{c.event} — {c.party ?? 'Sin partido'}</span>
                                <Badge label={c.outcome ?? '—'} cls={c.outcome === 'elected' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'} />
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

/* ── MAIN PAGE ── */
export default function Personas({ personas, filters }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editPerson, setEditPerson] = useState(null);
    const [detailPerson, setDetailPerson] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');

    const doSearch = useCallback((val) => {
        router.get('/admin/personas', { search: val || undefined, status: filters.status || undefined }, { preserveState: true, replace: true });
    }, [filters.status]);

    let debounceTimer;
    function handleSearch(val) {
        setSearch(val);
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => doSearch(val), 400);
    }

    return (
        <AppLayout
            title="Personas"
            breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'ADMIN', href: '/admin' }, { label: 'PERSONAS' }]}
        >
            <Head title="Personas — Administracion" />

            <div className="bg-[var(--color-primary)] text-white px-6 py-5">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <p className="text-[10px] font-semibold tracking-widest text-white/40 uppercase">Administracion</p>
                        <h1 className="text-[24px] font-extrabold">PERSONAS</h1>
                        <p className="text-[12px] text-white/50">Gestion de identidades, aliases y contactos</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-[13px] font-bold font-[var(--font-mono)]">{personas.total}</span>
                        <span className="text-[11px] text-white/50">registros</span>
                        <button onClick={() => setShowCreate(true)} className="px-4 py-2 bg-white/15 hover:bg-white/25 text-white text-[12px] font-bold rounded-lg transition-colors border border-white/20">
                            + Nueva persona
                        </button>
                    </div>
                </div>
            </div>

            <div className="p-4 lg:p-6">
                <div className="grid grid-cols-1 xl:grid-cols-12 gap-6">
                    {/* Table */}
                    <div className={detailPerson ? 'xl:col-span-7' : 'xl:col-span-12'}>
                        <SectionCard
                            title="Listado de personas"
                            action={
                                <div className="flex items-center gap-2">
                                    <input
                                        type="text"
                                        value={search}
                                        onChange={e => handleSearch(e.target.value)}
                                        placeholder="Buscar por nombre..."
                                        className="text-[11px] border border-[var(--color-line)] rounded-lg px-3 py-1.5 w-[200px] focus:outline-none focus:border-[var(--color-primary)]"
                                    />
                                    <select
                                        value={filters.status ?? ''}
                                        onChange={e => router.get('/admin/personas', { search: search || undefined, status: e.target.value || undefined }, { preserveState: true, replace: true })}
                                        className="text-[11px] border border-[var(--color-line)] rounded-lg px-2 py-1.5 focus:outline-none focus:border-[var(--color-primary)]"
                                    >
                                        <option value="">Todos los estados</option>
                                        <option value="confirmed">Confirmado</option>
                                        <option value="unverified">Sin verificar</option>
                                        <option value="duplicate">Duplicado</option>
                                    </select>
                                </div>
                            }
                        >
                            <div className="overflow-x-auto max-h-[600px] overflow-y-auto">
                                <table className="w-full text-[13px]">
                                    <thead className="sticky top-0 bg-white z-10">
                                        <tr className="border-b-2 border-[var(--color-line-strong)]">
                                            <th className="text-left py-2 px-2 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Nombre</th>
                                            <th className="text-left py-2 px-2 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Estado</th>
                                            <th className="text-center py-2 px-2 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Cand.</th>
                                            <th className="text-center py-2 px-2 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Alias</th>
                                            <th className="text-center py-2 px-2 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Cont.</th>
                                            <th className="text-right py-2 px-2 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)]">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {personas.data.map(p => (
                                            <tr key={p.id} className={`border-b border-[var(--color-line)] hover:bg-blue-50/50 transition-colors cursor-pointer ${detailPerson?.id === p.id ? 'bg-blue-50' : ''}`} onClick={() => setDetailPerson(p)}>
                                                <td className="py-2.5 px-2">
                                                    <p className="font-semibold text-[var(--color-ink)]">{p.fullName}</p>
                                                    {p.gender && <p className="text-[10px] text-[var(--color-ink-faint)]">{p.gender === 'male' ? 'M' : p.gender === 'female' ? 'F' : '—'}{p.birthDate ? ` · ${p.birthDate}` : ''}</p>}
                                                </td>
                                                <td className="py-2.5 px-2"><StatusBadge status={p.status} /></td>
                                                <td className="py-2.5 px-2 text-center font-[var(--font-mono)] text-[var(--color-ink-faint)]">{p.candidacies}</td>
                                                <td className="py-2.5 px-2 text-center font-[var(--font-mono)] text-[var(--color-ink-faint)]">{p.aliases}</td>
                                                <td className="py-2.5 px-2 text-center font-[var(--font-mono)] text-[var(--color-ink-faint)]">{p.contacts}</td>
                                                <td className="py-2.5 px-2 text-right">
                                                    <div className="flex items-center justify-end gap-1" onClick={e => e.stopPropagation()}>
                                                        <Link href={`/persona/${p.id}`} className="text-[10px] text-[var(--color-primary)] hover:underline font-semibold px-2 py-1">Ver</Link>
                                                        <button onClick={() => setEditPerson(p)} className="text-[10px] text-amber-600 hover:underline font-semibold px-2 py-1">Editar</button>
                                                        <button onClick={() => { if (confirm('Eliminar persona?')) router.delete(`/admin/personas/${p.id}`, { preserveScroll: true }); }} className="text-[10px] text-red-500 hover:underline font-semibold px-2 py-1">Eliminar</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {personas.last_page > 1 && (
                                <div className="flex items-center justify-between pt-4 border-t border-[var(--color-line)] mt-4">
                                    <span className="text-[11px] text-[var(--color-ink-faint)]">Pagina {personas.current_page} de {personas.last_page}</span>
                                    <div className="flex gap-1">
                                        {personas.links.filter(l => l.url).map((l, i) => (
                                            <Link key={i} href={l.url} className={`px-3 py-1 rounded text-[11px] font-semibold ${l.active ? 'bg-[var(--color-primary)] text-white' : 'text-[var(--color-ink-soft)] hover:bg-gray-100'}`} dangerouslySetInnerHTML={{ __html: l.label }} />
                                        ))}
                                    </div>
                                </div>
                            )}
                        </SectionCard>
                    </div>

                    {/* Detail panel */}
                    {detailPerson && (
                        <div className="xl:col-span-5">
                            <SectionCard title="Detalle de persona">
                                <PersonDetail person={detailPerson} onClose={() => setDetailPerson(null)} />
                            </SectionCard>
                        </div>
                    )}
                </div>
            </div>

            <PersonFormModal open={showCreate} onClose={() => setShowCreate(false)} />
            <PersonFormModal open={!!editPerson} onClose={() => setEditPerson(null)} person={editPerson} />
        </AppLayout>
    );
}
