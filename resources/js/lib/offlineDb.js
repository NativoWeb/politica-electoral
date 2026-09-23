/**
 * IndexedDB Store — Dexie.js wrapper for offline data
 *
 * Tables:
 *  - municipios: cached municipio list
 *  - downloads: metadata of downloaded regions { municipioId, municipioName, downloadedAt, dataVersion }
 *  - personas: persons + candidacy data for downloaded municipios
 *  - lideres: lider records for downloaded municipios
 *  - nexos: nexos familiares
 *  - gobernador: gobernador candidates (shared across all municipios)
 *  - pendingChanges: offline edits queue { id, type, action, data, createdAt, synced }
 */
import Dexie from 'dexie';

export const db = new Dexie('ElectoralOffline');

db.version(1).stores({
    municipios: 'id, name, provincia',
    downloads: 'municipioId, downloadedAt',
    personas: 'id, municipioId, nombre, tipo_registro, partido',
    lideres: 'id, municipioId, nombre, cargo, partido, barrio',
    nexos: 'id, personId',
    gobernador: 'id, nombre',
    pendingChanges: '++id, type, action, synced, createdAt',
});

// ─── Download a municipio's data from the server ───

export async function downloadMunicipio(municipioId) {
    const response = await fetch(`/api/offline/download/${municipioId}`, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
    });

    if (!response.ok) throw new Error(`Download failed: ${response.status}`);

    const data = await response.json();

    await db.transaction('rw', [db.downloads, db.personas, db.lideres, db.nexos, db.gobernador], async () => {
        // Clear old data for this municipio
        await db.personas.where('municipioId').equals(municipioId).delete();
        await db.lideres.where('municipioId').equals(municipioId).delete();

        // Store new data
        if (data.personas?.length) {
            await db.personas.bulkPut(data.personas.map(p => ({ ...p, municipioId })));
        }
        if (data.lideres?.length) {
            await db.lideres.bulkPut(data.lideres.map(l => ({ ...l, municipioId })));
        }
        if (data.nexos?.length) {
            await db.nexos.bulkPut(data.nexos);
        }
        if (data.gobernador?.length) {
            await db.gobernador.clear();
            await db.gobernador.bulkPut(data.gobernador);
        }

        // Mark download
        await db.downloads.put({
            municipioId,
            municipioName: data.municipioName,
            downloadedAt: new Date().toISOString(),
            counts: {
                personas: data.personas?.length ?? 0,
                lideres: data.lideres?.length ?? 0,
                nexos: data.nexos?.length ?? 0,
            },
        });
    });

    return data;
}

// ─── Get offline data for a municipio ───

export async function getOfflineData(municipioId) {
    const [personas, lideres, gobernador] = await Promise.all([
        db.personas.where('municipioId').equals(municipioId).toArray(),
        db.lideres.where('municipioId').equals(municipioId).toArray(),
        db.gobernador.toArray(),
    ]);

    return { personas, lideres, gobernador };
}

// ─── Get downloaded municipios ───

export async function getDownloads() {
    return db.downloads.toArray();
}

// ─── Check if a municipio is downloaded ───

export async function isDownloaded(municipioId) {
    return (await db.downloads.get(municipioId)) !== undefined;
}

// ─── Delete downloaded municipio ───

export async function deleteDownload(municipioId) {
    await db.transaction('rw', [db.downloads, db.personas, db.lideres], async () => {
        await db.personas.where('municipioId').equals(municipioId).delete();
        await db.lideres.where('municipioId').equals(municipioId).delete();
        await db.downloads.delete(municipioId);
    });
}

// ─── Pending Changes (offline edits queue) ───

export async function addPendingChange(type, action, data) {
    return db.pendingChanges.add({
        type,       // 'lider', 'persona', 'nexo'
        action,     // 'create', 'update', 'delete'
        data,       // the payload
        synced: 0,
        createdAt: new Date().toISOString(),
    });
}

export async function getPendingChanges() {
    return db.pendingChanges.where('synced').equals(0).toArray();
}

export async function getPendingCount() {
    return db.pendingChanges.where('synced').equals(0).count();
}

export async function markSynced(id) {
    return db.pendingChanges.update(id, { synced: 1 });
}

// ─── Sync Engine ───

export async function syncPendingChanges() {
    const pending = await getPendingChanges();
    if (pending.length === 0) return { synced: 0, failed: 0 };

    let synced = 0;
    let failed = 0;

    // Get CSRF token
    const cookie = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='));
    const csrfToken = cookie ? decodeURIComponent(cookie.split('=')[1]) : '';

    for (const change of pending) {
        try {
            let url, method, body;

            switch (change.type) {
                case 'lider':
                    if (change.action === 'create') {
                        url = '/mapa-politico/crear-lider';
                        method = 'POST';
                    } else if (change.action === 'update') {
                        url = `/mapa-politico/persona/${change.data.id}`;
                        method = 'PUT';
                    }
                    break;
                case 'nexo':
                    if (change.action === 'create') {
                        url = `/mapa-politico/persona/${change.data.personId}/nexos`;
                        method = 'POST';
                    } else if (change.action === 'delete') {
                        url = `/mapa-politico/nexos/${change.data.id}`;
                        method = 'DELETE';
                    }
                    break;
                case 'persona':
                    url = `/mapa-politico/persona/${change.data.id}`;
                    method = 'PUT';
                    break;
            }

            if (!url) { failed++; continue; }

            body = change.action !== 'delete' ? JSON.stringify(change.data) : undefined;

            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body,
            });

            if (res.ok) {
                await markSynced(change.id);
                synced++;
            } else {
                failed++;
            }
        } catch {
            failed++;
        }
    }

    // Clean synced changes older than 24h
    const oneDayAgo = new Date(Date.now() - 86400000).toISOString();
    await db.pendingChanges.where('synced').equals(1).and(c => c.createdAt < oneDayAgo).delete();

    return { synced, failed };
}

// ─── Offline CRUD (saves locally + queues for sync) ───

export async function offlineCreateLider(liderData) {
    const id = crypto.randomUUID();
    const record = { ...liderData, id, _offline: true };

    await db.lideres.put(record);
    await addPendingChange('lider', 'create', liderData);

    return record;
}

export async function offlineUpdatePersona(id, updates) {
    // Update in local DB if exists
    const lider = await db.lideres.get(id);
    if (lider) {
        await db.lideres.update(id, updates);
    }
    const persona = await db.personas.get(id);
    if (persona) {
        await db.personas.update(id, updates);
    }

    await addPendingChange('persona', 'update', { id, ...updates });
}

export async function offlineCreateNexo(personId, nexoData) {
    const id = crypto.randomUUID();
    const record = { ...nexoData, id, personId, _offline: true };

    await db.nexos.put(record);
    await addPendingChange('nexo', 'create', { ...nexoData, personId });

    return record;
}

export async function offlineDeleteNexo(nexoId) {
    await db.nexos.delete(nexoId);
    await addPendingChange('nexo', 'delete', { id: nexoId });
}
