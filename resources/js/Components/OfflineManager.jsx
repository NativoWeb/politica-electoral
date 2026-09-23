/**
 * OfflineManager — UI component for managing offline data
 * - Download button per municipio
 * - List of downloaded regions
 * - Pending changes badge
 * - Sync button
 */
import { useState } from 'react';
import { downloadMunicipio, deleteDownload, syncPendingChanges } from '@/lib/offlineDb';
import { useOnlineStatus, usePendingCount, useDownloads, useAutoSync } from '@/lib/useOffline';

export function OfflineIndicator() {
    const isOnline = useOnlineStatus();
    const [pendingCount] = usePendingCount();
    useAutoSync();

    return (
        <div className="flex items-center gap-2">
            {/* Online/Offline dot */}
            <div className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${isOnline ? 'bg-emerald-400' : 'bg-red-400 animate-pulse'}`} />
            <span className="text-[10px] text-white/50 hidden sm:inline">
                {isOnline ? 'En línea' : 'Sin conexión'}
            </span>

            {/* Pending changes badge */}
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
                    Descargado
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

export function OfflinePanel({ open, onClose }) {
    const [downloads, refreshDownloads] = useDownloads();
    const [pendingCount, refreshPending] = usePendingCount();
    const isOnline = useOnlineStatus();
    const [syncing, setSyncing] = useState(false);
    const [syncResult, setSyncResult] = useState(null);

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

    async function handleDelete(municipioId) {
        if (!confirm('Eliminar datos offline de este municipio?')) return;
        await deleteDownload(municipioId);
        refreshDownloads();
    }

    return (
        <>
            <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
            <div className="fixed right-0 top-0 bottom-0 w-[380px] bg-white z-50 shadow-2xl overflow-y-auto">
                {/* Header */}
                <div className="bg-[var(--color-primary)] text-white px-6 py-5 flex items-center justify-between">
                    <div>
                        <h2 className="text-[18px] font-extrabold">MODO OFFLINE</h2>
                        <p className="text-[11px] text-white/50 mt-0.5">Datos descargados para uso sin internet</p>
                    </div>
                    <button onClick={onClose} className="w-10 h-10 rounded-full bg-white/15 hover:bg-white/30 flex items-center justify-center">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                {/* Status */}
                <div className="px-6 py-4 border-b border-[var(--color-line)]">
                    <div className="flex items-center gap-3">
                        <div className={`w-3 h-3 rounded-full ${isOnline ? 'bg-emerald-400' : 'bg-red-400 animate-pulse'}`} />
                        <span className="text-[14px] font-semibold">{isOnline ? 'Conectado' : 'Sin conexión'}</span>
                    </div>

                    {pendingCount > 0 && (
                        <div className="mt-3 flex items-center justify-between bg-amber-50 rounded-lg px-4 py-3">
                            <div>
                                <p className="text-[13px] font-bold text-amber-700">{pendingCount} cambio{pendingCount > 1 ? 's' : ''} pendiente{pendingCount > 1 ? 's' : ''}</p>
                                <p className="text-[11px] text-amber-600">Se sincronizarán al conectarse</p>
                            </div>
                            {isOnline && (
                                <button
                                    onClick={handleSync}
                                    disabled={syncing}
                                    className="px-3 py-1.5 bg-amber-600 text-white text-[12px] font-bold rounded-lg hover:bg-amber-700 disabled:opacity-50"
                                >
                                    {syncing ? 'Sincronizando...' : 'Sincronizar'}
                                </button>
                            )}
                        </div>
                    )}

                    {syncResult && (
                        <div className="mt-2 px-4 py-2 bg-emerald-50 rounded-lg text-[12px] text-emerald-700">
                            {syncResult.synced} sincronizado{syncResult.synced > 1 ? 's' : ''}
                            {syncResult.failed > 0 && `, ${syncResult.failed} fallido${syncResult.failed > 1 ? 's' : ''}`}
                        </div>
                    )}
                </div>

                {/* Downloaded regions */}
                <div className="px-6 py-4">
                    <h3 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mb-3">
                        Municipios descargados ({downloads.length})
                    </h3>

                    {downloads.length === 0 ? (
                        <div className="text-center py-8">
                            <svg className="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            <p className="text-[14px] text-[var(--color-ink-faint)]">No hay datos descargados</p>
                            <p className="text-[12px] text-[var(--color-ink-faint)]/60 mt-1">Selecciona un municipio y usa el botón "Offline"</p>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {downloads.map(dl => (
                                <div key={dl.municipioId} className="bg-gray-50 rounded-xl p-4 flex items-center justify-between">
                                    <div>
                                        <p className="text-[14px] font-bold text-[var(--color-ink)]">{dl.municipioName}</p>
                                        <p className="text-[11px] text-[var(--color-ink-faint)]">
                                            {dl.counts?.personas ?? 0} candidatos · {dl.counts?.lideres ?? 0} líderes · {dl.counts?.nexos ?? 0} nexos
                                        </p>
                                        <p className="text-[10px] text-[var(--color-ink-faint)]">
                                            Descargado: {new Date(dl.downloadedAt).toLocaleDateString('es-CO')}
                                        </p>
                                    </div>
                                    <button
                                        onClick={() => handleDelete(dl.municipioId)}
                                        className="text-red-500 hover:text-red-700 p-2"
                                        title="Eliminar datos offline"
                                    >
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
