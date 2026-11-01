import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { discardEventAttendeeChange, getEventAttendeeChanges, getPendingCount, syncPendingChanges } from '@/lib/offlineDb';
import { useOnlineStatus } from '@/lib/useOffline';

const EMPTY = { pending: [], rejected: [] };

// What keeps the phone from sending, from how the last sync stopped: null when nothing does (or only
// the signal is missing), 'session' (401/419), 'owner' (another account holds this browser's session),
// 'signed_out' (no account is open in this browser) or 'password' (the server redirected instead of
// answering, e.g. the forced password change).
export function blockedReason(result) {
    if (!result) return null;
    if (result.stoppedStatus === 401 || result.stoppedStatus === 419) return 'session';
    if (result.stoppedReason === 'owner') return 'owner';
    if (result.stoppedReason === 'signed_out') return 'signed_out';
    if (result.stoppedReason === 'unexpected') return 'password';
    return null;
}

// Operator mode: the attendees of this event queued on the phone, sent automatically when the signal is back.
export default function useEventQueue(eventId) {
    const online = useOnlineStatus();
    const [queue, setQueue] = useState(EMPTY);
    const [syncing, setSyncing] = useState(false);
    const [blocked, setBlocked] = useState(null);
    const [storageError, setStorageError] = useState('');
    const mounted = useRef(true);

    const refresh = useCallback(async () => {
        try {
            const next = await getEventAttendeeChanges(eventId);
            if (mounted.current) {
                setQueue(next);
                setStorageError('');
            }
        } catch (err) {
            if (mounted.current) setStorageError(err?.message || 'No se pudo leer el almacenamiento del teléfono.');
        }
    }, [eventId]);

    const sync = useCallback(async () => {
        if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
        try {
            if ((await getPendingCount()) === 0) return;
            setSyncing(true);
            const result = await syncPendingChanges();
            if (!mounted.current) return;
            setBlocked(blockedReason(result));
            if (result?.synced > 0) router.reload({ only: ['registrados', 'mis_registros'] });
        } catch (err) {
            if (mounted.current) setStorageError(err?.message || 'No se pudo enviar lo guardado en el teléfono.');
        } finally {
            if (mounted.current) setSyncing(false);
            await refresh();
        }
    }, [refresh]);

    useEffect(() => {
        mounted.current = true;
        refresh();
        // The header indicator and other tabs may sync too: keep the list current.
        const interval = setInterval(refresh, 5000);
        return () => {
            mounted.current = false;
            clearInterval(interval);
        };
    }, [refresh]);

    useEffect(() => {
        if (online) sync();
    }, [online, sync]);

    const discard = useCallback(async (id) => {
        await discardEventAttendeeChange(id);
        await refresh();
    }, [refresh]);

    // The form also learns it (a 401 or a redirect while saving with signal).
    const markBlocked = useCallback((reason) => setBlocked(reason), []);

    return { online, queue, syncing, blocked, markBlocked, storageError, refresh, sync, discard };
}
