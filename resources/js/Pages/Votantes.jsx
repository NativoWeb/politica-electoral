import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import VotanteFilters from '@/Components/VotanteFilters';
import VotanteTable from '@/Components/VotanteTable';
import VotanteDetail from '@/Components/VotanteDetail';

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

function KpiCard({ icon, label, value, accent }) {
    return (
        <div className={`flex items-center gap-3 px-4 py-3 bg-white rounded-xl border border-[var(--color-line)] shadow-sm ${accent ? 'ring-1 ring-amber-200' : ''}`}>
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${accent ? 'bg-amber-100 text-amber-600' : 'bg-blue-100 text-[var(--color-primary)]'}`}>
                {icon}
            </div>
            <div>
                <p className="text-[22px] lg:text-[26px] font-extrabold text-[var(--color-ink)] leading-none">{(value ?? 0).toLocaleString('es-CO')}</p>
                <p className="text-[11px] font-semibold text-[var(--color-ink-faint)] uppercase tracking-wider mt-0.5">{label}</p>
            </div>
        </div>
    );
}

export default function Votantes({ votantes, counters, municipios, filters }) {
    const [selectedId, setSelectedId] = useState(null);
    const [detailData, setDetailData] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const [toast, setToast] = useState(null);
    const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
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
        showToast('Votante eliminado');
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
        setMobileFiltersOpen(false);
    }, []);

    const handleClearFilters = useCallback(() => {
        router.get('/votantes', {}, { preserveState: false });
        setMobileFiltersOpen(false);
    }, []);

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
                        <button
                            onClick={() => setMobileFiltersOpen(true)}
                            className="lg:hidden flex items-center gap-1.5 px-3 py-2 bg-white border border-[var(--color-line)] rounded-lg text-[13px] font-semibold text-[var(--color-ink-soft)] active:bg-gray-100"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>
                            Filtros
                        </button>
                        <button
                            onClick={handleCreate}
                            className="flex items-center gap-1.5 px-4 py-2 bg-[var(--color-primary)] text-white rounded-lg text-[13px] font-bold hover:bg-[var(--color-primary-light)] active:bg-[var(--color-primary-dark)] transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" /></svg>
                            Nuevo Votante
                        </button>
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-3 lg:gap-4 mb-5">
                    <KpiCard
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                        label="Total Votantes"
                        value={counters.total}
                    />
                    <KpiCard
                        icon={<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>}
                        label="Gran Elector"
                        value={counters.gran_elector}
                        accent
                    />
                </div>

                {/* Main layout: filters | table | detail */}
                <div className="flex gap-4 items-start">
                    {/* Desktop Filters */}
                    <div className="hidden lg:block w-[260px] flex-shrink-0 sticky top-4">
                        <VotanteFilters
                            filters={filters}
                            municipios={municipios}
                            onApply={handleApplyFilters}
                            onClear={handleClearFilters}
                        />
                    </div>

                    {/* Table */}
                    <div className="flex-1 min-w-0">
                        <VotanteTable
                            votantes={votantes}
                            selectedId={selectedId}
                            onSelect={selectVotante}
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
                            />
                        </div>
                    )}
                </div>
            </div>

            {/* Mobile Filters Overlay */}
            {mobileFiltersOpen && (
                <>
                    <div className="fixed inset-0 bg-black/50 z-50 lg:hidden" onClick={() => setMobileFiltersOpen(false)} />
                    <div className="fixed left-0 top-0 bottom-0 w-[300px] bg-white z-50 overflow-y-auto lg:hidden shadow-2xl">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--color-line)] bg-gray-50">
                            <h3 className="text-[16px] font-bold text-[var(--color-ink)]">Filtros</h3>
                            <button onClick={() => setMobileFiltersOpen(false)} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200">
                                <svg className="w-5 h-5 text-[var(--color-ink-faint)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                        <VotanteFilters
                            filters={filters}
                            municipios={municipios}
                            onApply={handleApplyFilters}
                            onClear={handleClearFilters}
                        />
                    </div>
                </>
            )}

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
                    />
                </div>
            )}
        </AppLayout>
    );
}
