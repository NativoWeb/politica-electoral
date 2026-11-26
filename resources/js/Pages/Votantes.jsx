import AppLayout from '@/Layouts/AppLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import VotanteFilters, { EventFilterChips } from '@/Components/VotanteFilters';
import VotanteTable from '@/Components/VotanteTable';
import VotanteDetail from '@/Components/VotanteDetail';
import { isOn, withCardFilters } from '@/lib/votantesFiltros';
import { useCreateBlocked } from '@/lib/campaign';

function getCsrf() {
    const cookie = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

async function apiFetch(url, options = {}) {
    const csrf = getCsrf();
    const res = await fetch(url, {
        ...options,
        headers: {
            'X-XSRF-TOKEN': csrf,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...options.headers,
        },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw { status: res.status, ...data };
    }
    return data;
}

// A KPI card is also a filter button: pressed, the table shows only its rows. Its number does not change when it is
// pressed: the server counts the cards on the filters without the card filters.
// The text block is min-content wide and the row wraps: when the number or a word of the label does not fit next to the
// icon (a phone, big text), it drops under the icon instead of spilling out of the card. Its em min-width grows with the
// text size, so the cards of a row switch together instead of one by one.
function KpiCard({ icon, label, value, accent, pressed, onClick, hint }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={pressed}
            title={hint}
            className={`w-full min-w-0 text-left flex flex-wrap items-center gap-x-3 gap-y-2 p-3 sm:px-4 min-h-[72px] rounded-xl border-2 shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 ${
                pressed
                    ? 'bg-blue-50 border-[var(--color-primary)]'
                    : `bg-white border-[var(--color-line)] hover:border-[var(--color-primary)]/50 ${accent ? 'ring-1 ring-amber-200' : ''}`
            }`}
        >
            <div className={`relative w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${accent ? 'bg-amber-100 text-amber-600' : 'bg-blue-100 text-[var(--color-primary)]'}`}>
                {icon}
                {pressed && (
                    <span className="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-[var(--color-primary)] text-white flex items-center justify-center ring-2 ring-white">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                    </span>
                )}
            </div>
            <div className="w-min min-w-[5.75em] max-w-full flex-1 text-[13px]">
                <p className="text-[20px] sm:text-[22px] xl:text-[26px] font-extrabold text-[var(--color-ink)] leading-none whitespace-nowrap tabular-nums">{(value ?? 0).toLocaleString('es-CO')}</p>
                <p className={`text-[12px] sm:text-[13px] font-semibold uppercase tracking-wide sm:tracking-wider leading-tight break-words mt-1 ${pressed ? 'text-[var(--color-primary)]' : 'text-[var(--color-ink-faint)]'}`}>{label}</p>
            </div>
        </button>
    );
}

export default function Votantes({ votantes, counters, municipios, comunasPorMunicipio = {}, filters, eventos = [], canViewEventos = false, campanasActivas = false }) {
    // Creating a votante needs write.data on the server (H1).
    // Not while the superadmin's header shows another group: the new ficha would land out of sight.
    const canWrite = !!usePage().props.auth?.user?.canWrite && !useCreateBlocked();
    const [selectedId, setSelectedId] = useState(null);
    const [detailData, setDetailData] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const [toast, setToast] = useState(null);
    const [mobileDetailOpen, setMobileDetailOpen] = useState(false);

    const showToast = useCallback((message, type = 'success') => {
        setToast({ message, type });
        setTimeout(() => setToast(null), 3000);
    }, []);

    const selectVotante = useCallback(async (id) => {
        setCreating(false);
        setSelectedId(id);
        setMobileDetailOpen(true);
        setDetailLoading(true);
        try {
            const res = await apiFetch(`/votantes/${id}`);
            setDetailData(res.data);
        } catch {
            showToast('Error al cargar detalle', 'error');
            setDetailData(null);
        } finally {
            setDetailLoading(false);
        }
    }, [showToast]);

    const handleCreate = useCallback(() => {
        setSelectedId(null);
        setDetailData(null);
        setCreating(true);
        setMobileDetailOpen(true);
    }, []);

    const handleCloseDetail = useCallback(() => {
        setSelectedId(null);
        setDetailData(null);
        setCreating(false);
        setMobileDetailOpen(false);
    }, []);

    const handleSaved = useCallback((newId) => {
        showToast(creating ? 'Votante creado' : 'Votante actualizado');
        setCreating(false);
        router.reload({ only: ['votantes', 'counters'] });
        const idToLoad = newId || selectedId;
        if (idToLoad) {
            setSelectedId(idToLoad);
            setDetailLoading(true);
            apiFetch(`/votantes/${idToLoad}`).then(res => {
                setDetailData(res.data);
            }).catch(() => {}).finally(() => setDetailLoading(false));
        }
    }, [creating, selectedId, showToast]);

    const handleDeleted = useCallback(() => {
        showToast('Estado actualizado');
        handleCloseDetail();
        router.reload({ only: ['votantes', 'counters'] });
    }, [handleCloseDetail, showToast]);

    const handleApplyFilters = useCallback((filterValues) => {
        const params = {};
        Object.entries(filterValues).forEach(([key, val]) => {
            if (val !== '' && val !== null && val !== undefined) {
                params[key] = val;
            }
        });
        router.get('/votantes', params, { preserveState: true, preserveScroll: true });
    }, []);

    const handleClearFilters = useCallback(() => {
        router.get('/votantes', {}, { preserveState: false });
    }, []);

    const granElectorOn = isOn(filters.gran_elector);
    const conReferidosOn = isOn(filters.con_referidos);
    const esLiderOn = campanasActivas && isOn(filters.es_lider);
    const applyCard = useCallback((changes) => {
        router.get('/votantes', withCardFilters(filters, changes), { preserveState: true, preserveScroll: true });
    }, [filters]);

    const detailOpen = selectedId || creating;

    return (
        <AppLayout title="Votantes">
            <Head title="Votantes" />

            {/* Toast */}
            {toast && (
                <div className={`fixed top-16 left-1/2 -translate-x-1/2 z-50 px-5 py-3 rounded-xl shadow-2xl text-white text-[14px] font-semibold animate-slide-up ${
                    toast.type === 'error' ? 'bg-red-500' : 'bg-emerald-500'
                }`}>
                    {toast.message}
                </div>
            )}

            <div className="px-4 lg:px-6 pt-4 lg:pt-6 pb-4">
                {/* Header */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h1 className="text-[22px] lg:text-[28px] font-extrabold text-[var(--color-ink)]" style={{ fontFamily: 'var(--font-heading)' }}>
                            Directorio de Votantes
                        </h1>
                        <p className="text-[13px] text-[var(--color-ink-faint)] mt-0.5">Gestion y consulta de votantes registrados</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {canWrite && <button
                            onClick={handleCreate}
                            className="flex items-center gap-1.5 px-4 py-2 bg-[var(--color-primary)] text-white rounded-lg text-[13px] font-bold hover:bg-[var(--color-primary-light)] active:bg-[var(--color-primary-dark)] transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" /></svg>
                            Nuevo Votante
                        </button>}
                    </div>
                </div>

                {/* KPI Cards */}
                <div className={`grid grid-cols-2 ${campanasActivas ? 'lg:grid-cols-4' : 'lg:grid-cols-3'} gap-3 lg:gap-4 mb-4`} role="group" aria-label="Filtrar por tarjeta">
                    <KpiCard
                        pressed={!granElectorOn && !conReferidosOn && !esLiderOn}
                        onClick={() => applyCard({ gran_elector: null, con_referidos: null, ...(campanasActivas ? { es_lider: null } : {}) })}
                        hint="Ver todos los votantes de los filtros"
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                        label="Total Votantes"
                        value={counters.total}
                    />
                    <KpiCard
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>}
                        label="Gran Elector"
                        value={counters.gran_elector}
                        accent
                        pressed={granElectorOn}
                        onClick={() => applyCard({ gran_elector: granElectorOn ? null : '1' })}
                        hint={granElectorOn ? 'Quitar el filtro Gran elector' : 'Ver solo los gran elector'}
                    />
                    <KpiCard
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                        label="Con Referidos"
                        value={counters.con_referidos}
                        pressed={conReferidosOn}
                        onClick={() => applyCard({ con_referidos: conReferidosOn ? null : '1' })}
                        hint={conReferidosOn ? 'Quitar el filtro Con referidos' : 'Ver solo quienes tienen referidos'}
                    />
                    {campanasActivas && (
                        <KpiCard
                            icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7l7-4z" /></svg>}
                            label="Líderes"
                            value={counters.es_lider ?? 0}
                            pressed={esLiderOn}
                            onClick={() => applyCard({ es_lider: esLiderOn ? null : '1' })}
                            hint={esLiderOn ? 'Quitar el filtro Líderes' : 'Ver solo los líderes (cargo Líder o casilla Es líder)'}
                        />
                    )}
                </div>

                {/* Filter bar on top (as in Mapa Político and Eventos), then table | detail */}
                <div className="mb-4">
                    <VotanteFilters
                        filters={filters}
                        municipios={municipios}
                        comunasPorMunicipio={comunasPorMunicipio}
                        onApply={handleApplyFilters}
                        onClear={handleClearFilters}
                        eventos={eventos}
                        canViewEventos={canViewEventos}
                        campanasActivas={campanasActivas}
                    />
                </div>

                <div className="flex gap-4 items-start">

                    {/* Table */}
                    <div className="flex-1 min-w-0">
                        <EventFilterChips
                            filters={filters}
                            eventos={eventos}
                            onRemove={key => {
                                const next = { ...filters };
                                delete next[key];
                                router.get('/votantes', next, { preserveState: false, preserveScroll: true });
                            }}
                        />
                        <VotanteTable
                            votantes={votantes}
                            selectedId={selectedId}
                            onSelect={selectVotante}
                            showEventos={canViewEventos}
                        />
                    </div>

                    {/* Desktop Detail Panel */}
                    {detailOpen && (
                        <div className="hidden lg:block w-[400px] flex-shrink-0 sticky top-4">
                            <VotanteDetail
                                data={detailData}
                                loading={detailLoading}
                                creating={creating}
                                onClose={handleCloseDetail}
                                onSaved={handleSaved}
                                onDeleted={handleDeleted}
                                apiFetch={apiFetch}
                                municipios={municipios}
                                showToast={showToast}
                                campanasActivas={campanasActivas}
                            />
                        </div>
                    )}
                </div>
            </div>

            {/* Mobile Detail Overlay */}
            {mobileDetailOpen && detailOpen && (
                <div className="fixed inset-0 z-50 lg:hidden bg-white overflow-y-auto">
                    <VotanteDetail
                        data={detailData}
                        loading={detailLoading}
                        creating={creating}
                        onClose={handleCloseDetail}
                        onSaved={handleSaved}
                        onDeleted={handleDeleted}
                        apiFetch={apiFetch}
                        municipios={municipios}
                        showToast={showToast}
                        campanasActivas={campanasActivas}
                    />
                </div>
            )}
        </AppLayout>
    );
}
