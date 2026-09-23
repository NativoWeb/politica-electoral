/**
 * React hooks for offline functionality
 */
import { useState, useEffect, useCallback } from 'react';
import { getPendingCount, syncPendingChanges, getDownloads } from './offlineDb';

// ─── Online/Offline status hook ───
export function useOnlineStatus() {
    const [isOnline, setIsOnline] = useState(navigator.onLine);

    useEffect(() => {
        const goOnline = () => setIsOnline(true);
        const goOffline = () => setIsOnline(false);

        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);

        return () => {
            window.removeEventListener('online', goOnline);
            window.removeEventListener('offline', goOffline);
        };
    }, []);

    return isOnline;
}

// ─── Pending changes count hook ───
export function usePendingCount() {
    const [count, setCount] = useState(0);

    const refresh = useCallback(async () => {
        const c = await getPendingCount();
        setCount(c);
    }, []);

    useEffect(() => {
        refresh();
        const interval = setInterval(refresh, 5000);
        return () => clearInterval(interval);
    }, [refresh]);

    return [count, refresh];
}

// ─── Auto-sync when coming online ───
export function useAutoSync() {
    const isOnline = useOnlineStatus();
    const [syncing, setSyncing] = useState(false);
    const [lastSync, setLastSync] = useState(null);

    useEffect(() => {
        if (!isOnline || syncing) return;

        const doSync = async () => {
            const count = await getPendingCount();
            if (count === 0) return;

            setSyncing(true);
            try {
                const result = await syncPendingChanges();
                setLastSync({ ...result, at: new Date().toISOString() });
            } finally {
                setSyncing(false);
            }
        };

        doSync();
    }, [isOnline]);

    return { syncing, lastSync };
}

// ─── Downloaded regions hook ───
export function useDownloads() {
    const [downloads, setDownloads] = useState([]);

    const refresh = useCallback(async () => {
        const dl = await getDownloads();
        setDownloads(dl);
    }, []);

    useEffect(() => { refresh(); }, [refresh]);

    return [downloads, refresh];
}
