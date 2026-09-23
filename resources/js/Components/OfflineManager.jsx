/**
 * OfflineManager — UI components for managing offline data
 * Designed for elderly users with large touch targets and clear language
 */
import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { downloadMunicipio, deleteDownload, syncPendingChanges } from '@/lib/offlineDb';
import { useOnlineStatus, usePendingCount, useDownloads, useAutoSync } from '@/lib/useOffline';

export function OfflineIndicator() {
    const isOnline = useOnlineStatus();
    const [pendingCount] = usePendingCount();
    useAutoSync();

    return (
        <div className="flex items-center gap-2">
            <div className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${isOnline ? 'bg-emerald-400' : 'bg-red-400 animate-pulse'}`} />
            <span className="text-[10px] text-white/50 hidden sm:inline">
                {isOnline ? 'En línea' : 'Sin conexión'}
            </span>
            {pendingCount > 0 && (
                <span className="px-1.5 py-0.5 bg-amber-500 text-white text-[9px] font-bold rounded-full">
                    {pendingCount}
                </span>
            )}
        </div>
    );
}

export function DownloadRegionButton({ municipioId, municipioName, size = 'sm' }) {
    const [downloading, setDownloading] = useState(false);
    const [done, setDone] = useState(false);
    const isOnline = useOnlineStatus();

    if (!isOnline) return null;

    async function handleDownload() {
        setDownloading(true);
        try {
            await downloadMunicipio(municipioId);
            setDone(true);
            setTimeout(() => setDone(false), 3000);
        } catch (err) {
            alert('Error al descargar: ' + err.message);
        } finally {
            setDownloading(false);
        }
    }

    const cls = size === 'sm'
        ? 'px-2 py-1 text-[10px]'
        : 'px-3 py-1.5 text-[12px]';

    return (
        <button
            onClick={handleDownload}
            disabled={downloading || done}
            className={`${cls} font-bold rounded-lg transition-colors flex items-center gap-1 ${
                done
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-blue-50 text-blue-700 hover:bg-blue-100'
            } disabled:opacity-50`}
            title={`Descargar ${municipioName} para uso offline`}
        >
            {downloading ? (
                <>
                    <svg className="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    Descargando...
                </>
            ) : done ? (
                <>
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                    Listo
                </>
            ) : (
                <>
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    Offline
                </>
            )}
        </button>
    );
}

/* ── Municipio download row ── */
function MunicipioRow({ muni, downloaded, onDownload, onDelete }) {
    const [downloading, setDownloading] = useState(false);

    async function handleDownload() {
        setDownloading(true);
        try {
            await onDownload(muni.id);
        } finally {
            setDownloading(false);
        }
    }

    return (
        <div className={`flex items-center justify-between px-4 py-4 border-b border-gray-100 last:border-0 ${downloaded ? 'bg-emerald-50' : ''}`}>
            <div className="flex items-center gap-3 min-w-0 flex-1">
                {downloaded ? (
                    <div className="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center flex-shrink-0">
                        <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                    </div>
                ) : (
                    <div className="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                        <svg className="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" /></svg>
                    </div>
                )}
                <div className="min-w-0">
                    <p className="text-[15px] font-bold text-[var(--color-ink)] truncate">{muni.name}</p>
                    {downloaded && downloaded.counts && (
                        <p className="text-[12px] text-emerald-600">
                            {downloaded.counts.personas + downloaded.counts.lideres} personas guardadas
                        </p>
                    )}
                </div>
            </div>
            <div className="flex-shrink-0 ml-2">
                {downloading ? (
                    <div className="w-12 h-12 flex items-center justify-center">
                        <svg className="w-6 h-6 animate-spin text-[var(--color-primary)]" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    </div>
                ) : downloaded ? (
                    <button
                        onClick={() => onDelete(muni.id)}
                        className="w-12 h-12 rounded-xl bg-red-50 flex items-center justify-center active:bg-red-100"
                        title="Eliminar datos guardados"
                    >
                        <svg className="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button>
                ) : (
                    <button
                        onClick={handleDownload}
                        className="w-12 h-12 rounded-xl bg-[var(--color-primary)] flex items-center justify-center active:bg-[var(--color-primary-dark)]"
                        title="Guardar para usar sin internet"
                    >
                        <svg className="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    </button>
                )}
            </div>
        </div>
    );
}

export function OfflinePanel({ open, onClose }) {
    const [downloads, refreshDownloads] = useDownloads();
    const [pendingCount, refreshPending] = usePendingCount();
    const isOnline = useOnlineStatus();
    const [syncing, setSyncing] = useState(false);
    const [syncResult, setSyncResult] = useState(null);
    const [searchMuni, setSearchMuni] = useState('');

    // Get municipios from Inertia shared props
    const { props } = usePage();
    const municipios = props.municipiosForOffline ?? props.municipios ?? [];

    if (!open) return null;

    async function handleSync() {
        setSyncing(true);
        try {
            const result = await syncPendingChanges();
            setSyncResult(result);
            refreshPending();
            setTimeout(() => setSyncResult(null), 3000);
        } finally {
            setSyncing(false);
        }
    }

    async function handleDownload(municipioId) {
        try {
            await downloadMunicipio(municipioId);
            refreshDownloads();
        } catch (err) {
            alert('No se pudo guardar: ' + err.message);
        }
    }

    async function handleDelete(municipioId) {
        if (!confirm('¿Borrar los datos guardados de este municipio?')) return;
        await deleteDownload(municipioId);
        refreshDownloads();
    }

    const downloadedIds = new Set(downloads.map(d => d.municipioId));

    // Group municipios by provincia
    const grouped = {};
    const filteredMunis = municipios.filter(m =>
        !searchMuni || m.name.toLowerCase().includes(searchMuni.toLowerCase())
    );
    filteredMunis.forEach(m => {
        const prov = m.provincia || 'Otros';
        if (!grouped[prov]) grouped[prov] = [];
        grouped[prov].push(m);
    });

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />

            {/* Mobile: full-screen bottom sheet / Desktop: side panel */}
            <div className="fixed inset-0 lg:inset-auto lg:right-0 lg:top-0 lg:bottom-0 lg:w-[420px] bg-white z-50 flex flex-col safe-bottom animate-slide-up lg:animate-none lg:shadow-2xl">
                {/* Header */}
                <div className="bg-[var(--color-primary)] text-white px-5 py-5 flex items-center justify-between flex-shrink-0">
                    <div>
                        <h2 className="text-[20px] font-extrabold">GUARDAR DATOS</h2>
                        <p className="text-[13px] text-white/60 mt-1">Para usar la app sin internet</p>
                    </div>
                    <button onClick={onClose} className="w-12 h-12 rounded-full bg-white/15 active:bg-white/30 flex items-center justify-center">
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                {/* Status bar */}
                <div className="px-5 py-3 border-b border-gray-200 flex items-center gap-3 flex-shrink-0">
                    <div className={`w-3.5 h-3.5 rounded-full ${isOnline ? 'bg-emerald-400' : 'bg-red-400 animate-pulse'}`} />
                    <span className="text-[15px] font-semibold">{isOnline ? 'Conectado a internet' : 'Sin conexión'}</span>

                    {downloads.length > 0 && (
                        <span className="ml-auto text-[13px] font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg">
                            {downloads.length} guardado{downloads.length > 1 ? 's' : ''}
                        </span>
                    )}
                </div>

                {/* Pending sync */}
                {pendingCount > 0 && (
                    <div className="px-5 py-3 bg-amber-50 border-b border-amber-200 flex items-center justify-between flex-shrink-0">
                        <div>
                            <p className="text-[14px] font-bold text-amber-800">{pendingCount} cambio{pendingCount > 1 ? 's' : ''} sin enviar</p>
                            <p className="text-[12px] text-amber-600">Se enviaran cuando haya internet</p>
                        </div>
                        {isOnline && (
                            <button
                                onClick={handleSync}
                                disabled={syncing}
                                className="px-4 py-2.5 bg-amber-600 text-white text-[14px] font-bold rounded-xl active:bg-amber-700 disabled:opacity-50"
                            >
                                {syncing ? 'Enviando...' : 'Enviar ahora'}
                            </button>
                        )}
                    </div>
                )}

                {syncResult && (
                    <div className="px-5 py-2.5 bg-emerald-50 border-b border-emerald-200 text-[14px] font-semibold text-emerald-700 flex-shrink-0">
                        {syncResult.synced} enviado{syncResult.synced > 1 ? 's' : ''} correctamente
                        {syncResult.failed > 0 && ` · ${syncResult.failed} con error`}
                    </div>
                )}

                {/* Instructions */}
                <div className="px-5 py-4 bg-blue-50 border-b border-blue-100 flex-shrink-0">
                    <div className="flex items-start gap-3">
                        <div className="w-10 h-10 rounded-xl bg-[var(--color-primary)] flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div>
                            <p className="text-[14px] font-bold text-[var(--color-ink)]">¿Como funciona?</p>
                            <p className="text-[13px] text-[var(--color-ink-soft)] mt-1 leading-relaxed">
                                Toque el boton azul <span className="inline-block w-5 h-5 bg-[var(--color-primary)] rounded align-middle mx-0.5">
                                    <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                </span> junto al municipio que necesite. Asi podra consultar los datos aunque no tenga internet.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Search */}
                <div className="px-5 py-3 border-b border-gray-200 flex-shrink-0">
                    <div className="relative">
                        <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <input
                            type="text"
                            value={searchMuni}
                            onChange={e => setSearchMuni(e.target.value)}
                            placeholder="Buscar municipio..."
                            className="w-full pl-11 pr-4 py-3 border border-gray-200 rounded-xl text-[15px] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-primary)]"
                        />
                    </div>
                </div>

                {/* Municipios list */}
                <div className="flex-1 overflow-y-auto">
                    {municipios.length === 0 ? (
                        <div className="text-center py-12 px-6">
                            <svg className="w-16 h-16 text-gray-200 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" /></svg>
                            <p className="text-[16px] font-bold text-[var(--color-ink-faint)]">No hay municipios disponibles</p>
                            <p className="text-[14px] text-[var(--color-ink-faint)] mt-2">Primero vaya al Mapa Politico y seleccione un municipio</p>
                        </div>
                    ) : (
                        Object.entries(grouped).sort(([a], [b]) => a.localeCompare(b)).map(([provincia, munis]) => (
                            <div key={provincia}>
                                <div className="sticky top-0 bg-gray-100 px-5 py-2.5 text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] border-b border-gray-200">
                                    {provincia}
                                    <span className="ml-2 text-[var(--color-ink-faint)]/50">({munis.length})</span>
                                </div>
                                {munis.map(m => (
                                    <MunicipioRow
                                        key={m.id}
                                        muni={m}
                                        downloaded={downloads.find(d => d.municipioId === m.id)}
                                        onDownload={handleDownload}
                                        onDelete={handleDelete}
                                    />
                                ))}
                            </div>
                        ))
                    )}
                </div>
            </div>
        </>
    );
}
