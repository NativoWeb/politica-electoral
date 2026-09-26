import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import SectionCard from '@/Components/SectionCard';
import { useState, useEffect } from 'react';
import { fmt } from '@/lib/electoral';

const inputCls = "w-full border border-[var(--color-line)] rounded-lg px-3 py-2 text-[13px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]";
const btnPrimary = "px-4 py-2 bg-[var(--color-primary)] text-white text-[12px] font-bold rounded-lg hover:bg-[var(--color-primary-light)] transition-colors";
const btnSecondary = "px-4 py-2 border border-[var(--color-line)] text-[12px] font-semibold text-[var(--color-ink-soft)] rounded-lg hover:bg-gray-50 transition-colors";

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

function Field({ label, children }) {
    return (
        <div>
            <label className="block text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-1">{label}</label>
            {children}
        </div>
    );
}

function StatusBadge({ status }) {
    const map = {
        completed: 'bg-emerald-100 text-emerald-700',
        scheduled: 'bg-blue-100 text-blue-700',
        in_progress: 'bg-amber-100 text-amber-700',
        annulled: 'bg-red-100 text-red-600',
    };
    return <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${map[status] ?? 'bg-gray-100 text-gray-500'}`}>{(status ?? '—').toUpperCase()}</span>;
}

/* ── Event Form ── */
function EventForm({ item, onClose }) {
    const isEdit = !!item;
    const form = useForm({
        name: item?.name ?? '',
        event_type: item?.type ?? 'territorial',
        election_date: item?.date ?? '',
        political_period: item?.period ?? '',
        status: item?.status ?? 'completed',
    });
    function submit(e) {
        e.preventDefault();
        if (isEdit) form.put(`/admin/elecciones/events/${item.id}`, { onSuccess: onClose, preserveScroll: true });
        else form.post('/admin/elecciones/events', { onSuccess: () => { form.reset(); onClose(); }, preserveScroll: true });
    }
    return (
        <form onSubmit={submit} className="space-y-4">
            <Field label="Nombre"><input className={inputCls} value={form.data.name} onChange={e => form.setData('name', e.target.value)} required placeholder="Elecciones Territoriales 2023" /></Field>
            <div className="grid grid-cols-2 gap-4">
                <Field label="Tipo">
                    <select className={inputCls} value={form.data.event_type} onChange={e => form.setData('event_type', e.target.value)}>
                        <option value="territorial">Territorial</option>
                        <option value="legislative">Legislativa</option>
                        <option value="presidential">Presidencial</option>
                        <option value="consultation">Consulta</option>
                        <option value="other">Otra</option>
                    </select>
                </Field>
                <Field label="Fecha"><input type="date" className={inputCls} value={form.data.election_date} onChange={e => form.setData('election_date', e.target.value)} required /></Field>
            </div>
            <div className="grid grid-cols-2 gap-4">
                <Field label="Periodo politico"><input className={inputCls} value={form.data.political_period} onChange={e => form.setData('political_period', e.target.value)} placeholder="2024-2027" /></Field>
                <Field label="Estado">
                    <select className={inputCls} value={form.data.status} onChange={e => form.setData('status', e.target.value)}>
                        <option value="completed">Completada</option>
                        <option value="scheduled">Programada</option>
                        <option value="in_progress">En curso</option>
                        <option value="annulled">Anulada</option>
                    </select>
                </Field>
            </div>
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className={btnSecondary}>Cancelar</button>
                <button type="submit" disabled={form.processing} className={btnPrimary}>{isEdit ? 'Actualizar' : 'Crear evento'}</button>
            </div>
        </form>
    );
}

/* ── Event Detail (contests + candidacies) ── */
function EventDetail({ event, oficios, corporaciones, municipios, partidos }) {
    const [data, setData] = useState(null);
    const [contestDetail, setContestDetail] = useState(null);
    const [showContestForm, setShowContestForm] = useState(false);
    const [showCandidacyForm, setShowCandidacyForm] = useState(false);

    useEffect(() => {
        fetch(`/admin/elecciones/${event.id}/json`).then(r => r.json()).then(setData);
    }, [event.id]);

    function loadContest(contestId) {
        fetch(`/admin/elecciones/contest/${contestId}/json`).then(r => r.json()).then(setContestDetail);
    }

    const contestForm = useForm({ electoral_event_id: event.id, office_id: '', corporation_id: '', geographic_unit_id: '', seats: '' });
    const candForm = useForm({ contest_id: '', person_id: '', organization_id: '', outcome: 'elected', list_position: '', votos: '' });
    const resultForm = useForm({ candidacy_id: '', contest_id: '', metric_type: 'votes', value: '' });
    const [showResultForm, setShowResultForm] = useState(false);

    if (!data) return <div className="p-4 text-center text-[var(--color-ink-faint)]">Cargando...</div>;

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="text-[14px] font-bold text-[var(--color-ink)]">{data.event.name}</h3>
                    <p className="text-[11px] text-[var(--color-ink-faint)]">{data.event.date} · {data.event.type}</p>
                </div>
                <button onClick={() => setShowContestForm(true)} className={btnPrimary}>+ Contienda</button>
            </div>

            {/* Contests list */}
            <div className="space-y-1.5">
                {data.contests.map(c => (
                    <div key={c.id}
                        className={`flex items-center justify-between px-3 py-2.5 rounded-lg border cursor-pointer transition-all ${contestDetail?.contest?.id === c.id ? 'border-[var(--color-primary)] bg-blue-50' : 'border-[var(--color-line)] hover:bg-gray-50'}`}
                        onClick={() => loadContest(c.id)}
                    >
                        <div>
                            <p className="text-[12px] font-semibold text-[var(--color-ink)]">{c.office ?? '—'} {c.corporation ? `· ${c.corporation}` : ''}</p>
                            <p className="text-[10px] text-[var(--color-ink-faint)]">{c.municipio ?? 'General'} · {c.candidacies} candidaturas{c.seats ? ` · ${c.seats} curules` : ''}</p>
                        </div>
                    </div>
                ))}
                {data.contests.length === 0 && <p className="text-[12px] text-[var(--color-ink-faint)] py-4 text-center">Sin contiendas. Crea una para agregar candidaturas.</p>}
            </div>

            {/* Contest Detail - candidacies */}
            {contestDetail && (
                <div className="bg-gray-50 rounded-lg p-4 space-y-3">
                    <div className="flex items-center justify-between">
                        <h4 className="text-[12px] font-bold text-[var(--color-ink)]">
                            {contestDetail.contest.office} — {contestDetail.contest.municipio ?? 'General'}
                        </h4>
                        <div className="flex gap-2">
                            <button onClick={() => { candForm.setData('contest_id', contestDetail.contest.id); setShowCandidacyForm(true); }} className="text-[10px] font-bold text-[var(--color-primary)] hover:underline">+ Candidatura</button>
                            <button onClick={() => { resultForm.setData('contest_id', contestDetail.contest.id); setShowResultForm(true); }} className="text-[10px] font-bold text-emerald-600 hover:underline">+ Resultado</button>
                        </div>
                    </div>
                    <table className="w-full text-[12px]">
                        <thead>
                            <tr className="border-b border-[var(--color-line)]">
                                <th className="text-left py-1.5 text-[10px] font-bold uppercase text-[var(--color-ink-faint)]">Candidato</th>
                                <th className="text-left py-1.5 text-[10px] font-bold uppercase text-[var(--color-ink-faint)]">Partido</th>
                                <th className="text-right py-1.5 text-[10px] font-bold uppercase text-[var(--color-ink-faint)]">Votos</th>
                                <th className="text-left py-1.5 text-[10px] font-bold uppercase text-[var(--color-ink-faint)]">Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contestDetail.candidacies.map(c => (
                                <tr key={c.id} className="border-b border-[var(--color-line)] last:border-0">
                                    <td className="py-2 font-semibold text-[var(--color-ink)]">{c.personName}</td>
                                    <td className="py-2 text-[var(--color-ink-soft)]">{c.party ?? '—'}</td>
                                    <td className="py-2 text-right font-bold font-[var(--font-mono)]">{c.votos > 0 ? fmt(c.votos) : '—'}</td>
                                    <td className="py-2">
                                        <select
                                            value={c.outcome ?? ''}
                                            onChange={e => router.put(`/admin/elecciones/candidacies/${c.id}`, { outcome: e.target.value }, { preserveScroll: true, onSuccess: () => loadContest(contestDetail.contest.id) })}
                                            className="text-[10px] border border-[var(--color-line)] rounded px-1 py-0.5"
                                        >
                                            <option value="">—</option>
                                            <option value="elected">Electo</option>
                                            <option value="lost">No electo</option>
                                            <option value="withdrawn">Retirado</option>
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Contest Form Modal */}
            <Modal open={showContestForm} onClose={() => setShowContestForm(false)} title="Nueva contienda">
                <form onSubmit={e => { e.preventDefault(); contestForm.post('/admin/elecciones/contests', { onSuccess: () => { setShowContestForm(false); fetch(`/admin/elecciones/${event.id}/json`).then(r => r.json()).then(setData); }, preserveScroll: true }); }} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Cargo">
                            <select className={inputCls} value={contestForm.data.office_id} onChange={e => contestForm.setData('office_id', e.target.value)}>
                                <option value="">— Seleccionar —</option>
                                {oficios.map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Corporacion">
                            <select className={inputCls} value={contestForm.data.corporation_id} onChange={e => contestForm.setData('corporation_id', e.target.value)}>
                                <option value="">— Ninguna —</option>
                                {corporaciones.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Municipio">
                            <select className={inputCls} value={contestForm.data.geographic_unit_id} onChange={e => contestForm.setData('geographic_unit_id', e.target.value)}>
                                <option value="">— General —</option>
                                {municipios.map(m => <option key={m.id} value={m.id}>{m.canonical_name}</option>)}
                            </select>
                        </Field>
                        <Field label="Curules"><input type="number" className={inputCls} value={contestForm.data.seats} onChange={e => contestForm.setData('seats', e.target.value)} min="1" /></Field>
                    </div>
                    <div className="flex justify-end gap-2"><button type="button" onClick={() => setShowContestForm(false)} className={btnSecondary}>Cancelar</button><button type="submit" className={btnPrimary}>Crear</button></div>
                </form>
            </Modal>

            {/* Candidacy Form Modal */}
            <Modal open={showCandidacyForm} onClose={() => setShowCandidacyForm(false)} title="Nueva candidatura">
                <form onSubmit={e => { e.preventDefault(); candForm.post('/admin/elecciones/candidacies', { onSuccess: () => { setShowCandidacyForm(false); loadContest(candForm.data.contest_id); }, preserveScroll: true }); }} className="space-y-4">
                    <Field label="Persona (buscar por nombre)">
                        <PersonSearchInput value={candForm.data.person_id} onChange={val => candForm.setData('person_id', val)} />
                    </Field>
                    <Field label="Partido">
                        <select className={inputCls} value={candForm.data.organization_id} onChange={e => candForm.setData('organization_id', e.target.value)}>
                            <option value="">— Sin partido —</option>
                            {partidos.map(p => <option key={p.id} value={p.id}>{p.acronym ? `${p.acronym} — ` : ''}{p.canonical_name}</option>)}
                        </select>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Resultado">
                            <select className={inputCls} value={candForm.data.outcome} onChange={e => candForm.setData('outcome', e.target.value)}>
                                <option value="elected">Electo</option>
                                <option value="lost">No electo</option>
                                <option value="withdrawn">Retirado</option>
                            </select>
                        </Field>
                        <Field label="Posicion en lista"><input type="number" className={inputCls} value={candForm.data.list_position} onChange={e => candForm.setData('list_position', e.target.value)} /></Field>
                    </div>
                    <Field label="Votos (opcional)"><input type="number" className={inputCls} value={candForm.data.votos} onChange={e => candForm.setData('votos', e.target.value)} min="0" placeholder="0" /></Field>
                    <div className="flex justify-end gap-2"><button type="button" onClick={() => setShowCandidacyForm(false)} className={btnSecondary}>Cancelar</button><button type="submit" className={btnPrimary}>Registrar</button></div>
                </form>
            </Modal>

            {/* Result Form Modal */}
            <Modal open={showResultForm} onClose={() => setShowResultForm(false)} title="Registrar votos">
                <form onSubmit={e => { e.preventDefault(); resultForm.post('/admin/elecciones/results', { onSuccess: () => { setShowResultForm(false); loadContest(resultForm.data.contest_id); }, preserveScroll: true }); }} className="space-y-4">
                    {contestDetail && (
                        <Field label="Candidatura">
                            <select className={inputCls} value={resultForm.data.candidacy_id} onChange={e => resultForm.setData('candidacy_id', e.target.value)} required>
                                <option value="">— Seleccionar —</option>
                                {contestDetail.candidacies.map(c => <option key={c.id} value={c.id}>{c.personName} ({c.party ?? 'Sin partido'})</option>)}
                            </select>
                        </Field>
                    )}
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Tipo">
                            <select className={inputCls} value={resultForm.data.metric_type} onChange={e => resultForm.setData('metric_type', e.target.value)}>
                                <option value="votes">Votos</option>
                                <option value="nominal_votes">Votos nominales</option>
                                <option value="list_votes">Votos de lista</option>
                            </select>
                        </Field>
                        <Field label="Cantidad"><input type="number" className={inputCls} value={resultForm.data.value} onChange={e => resultForm.setData('value', e.target.value)} min="0" required /></Field>
                    </div>
                    <div className="flex justify-end gap-2"><button type="button" onClick={() => setShowResultForm(false)} className={btnSecondary}>Cancelar</button><button type="submit" className={btnPrimary}>Guardar</button></div>
                </form>
            </Modal>
        </div>
    );
}

/* ── Person search with autocomplete ── */
function PersonSearchInput({ value, onChange }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [selected, setSelected] = useState(null);

    useEffect(() => {
        if (query.length < 2) { setResults([]); return; }
        const t = setTimeout(() => {
            fetch(`/api/search?q=${encodeURIComponent(query)}`).then(r => r.json()).then(d => {
                setResults((d.results ?? []).filter(r => r.type === 'persona'));
            });
        }, 300);
        return () => clearTimeout(t);
    }, [query]);

    return (
        <div className="relative">
            <input
                className={inputCls}
                value={selected ?? query}
                onChange={e => { setQuery(e.target.value); setSelected(null); onChange(''); }}
                placeholder="Escribir nombre..."
            />
            {results.length > 0 && !selected && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-[var(--color-line)] rounded-lg shadow-lg z-50 max-h-40 overflow-y-auto">
                    {results.map(r => (
                        <button key={r.id} type="button" onClick={() => { onChange(r.id); setSelected(r.name); setResults([]); }}
                            className="w-full text-left px-3 py-2 text-[12px] hover:bg-blue-50 border-b border-[var(--color-line)] last:border-0">
                            {r.name}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ── MAIN PAGE ── */
export default function Elecciones({ events, oficios, corporaciones, municipios, partidos }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editEvent, setEditEvent] = useState(null);
    const [selectedEvent, setSelectedEvent] = useState(null);

    return (
        <AppLayout
            title="Elecciones"
            breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'ADMIN', href: '/admin' }, { label: 'ELECCIONES' }]}
        >
            <Head title="Elecciones — Administracion" />

            <div className="bg-[var(--color-primary)] text-white px-6 py-5">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <p className="text-[10px] font-semibold tracking-widest text-white/40 uppercase">Administracion</p>
                        <h1 className="text-[24px] font-extrabold">ELECCIONES</h1>
                        <p className="text-[12px] text-white/50">Eventos, contiendas, candidaturas y resultados</p>
                    </div>
                    <button onClick={() => setShowCreate(true)} className="px-4 py-2 bg-white/15 hover:bg-white/25 text-white text-[12px] font-bold rounded-lg transition-colors border border-white/20">
                        + Nuevo evento
                    </button>
                </div>
            </div>

            <div className="p-4 lg:p-6">
                <div className="grid grid-cols-1 xl:grid-cols-12 gap-6">

                    {/* Events list */}
                    <div className="xl:col-span-4">
                        <SectionCard title={`Eventos electorales (${events.length})`}>
                            <div className="space-y-1.5">
                                {events.map(e => (
                                    <div key={e.id}
                                        className={`px-3 py-3 rounded-lg border cursor-pointer transition-all ${selectedEvent?.id === e.id ? 'border-[var(--color-primary)] bg-blue-50' : 'border-[var(--color-line)] hover:bg-gray-50'}`}
                                        onClick={() => setSelectedEvent(e)}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="text-[13px] font-bold text-[var(--color-ink)]">{e.name}</p>
                                                <p className="text-[10px] text-[var(--color-ink-faint)] font-[var(--font-mono)]">{e.date} · {e.type}</p>
                                            </div>
                                            <StatusBadge status={e.status} />
                                        </div>
                                        <div className="flex items-center justify-between mt-2">
                                            <span className="text-[10px] text-[var(--color-ink-faint)]">{e.contests} contiendas</span>
                                            <div className="flex gap-1" onClick={ev => ev.stopPropagation()}>
                                                <button onClick={() => setEditEvent(e)} className="text-[10px] text-amber-600 hover:underline font-semibold px-1">Editar</button>
                                                <button onClick={() => { if (confirm('Eliminar evento?')) router.delete(`/admin/elecciones/events/${e.id}`, { preserveScroll: true }); }} className="text-[10px] text-red-500 hover:underline font-semibold px-1">Eliminar</button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </SectionCard>
                    </div>

                    {/* Event detail */}
                    <div className="xl:col-span-8">
                        {selectedEvent ? (
                            <SectionCard title="Detalle del evento">
                                <EventDetail event={selectedEvent} oficios={oficios} corporaciones={corporaciones} municipios={municipios} partidos={partidos} />
                            </SectionCard>
                        ) : (
                            <div className="flex items-center justify-center h-[300px] text-[var(--color-ink-faint)] text-[13px]">
                                Selecciona un evento electoral para ver sus contiendas y candidaturas
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Nuevo evento electoral">
                <EventForm onClose={() => setShowCreate(false)} />
            </Modal>
            <Modal open={!!editEvent} onClose={() => setEditEvent(null)} title="Editar evento">
                <EventForm item={editEvent} onClose={() => setEditEvent(null)} />
            </Modal>
        </AppLayout>
    );
}
