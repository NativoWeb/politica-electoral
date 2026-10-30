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
import { showGlobalToast } from './api';
import { forbiddenMessage } from './campaign';

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

// ─── Download all municipios in a province sequentially ───

export async function downloadProvincia(municipios, onProgress) {
    const total = municipios.length;
    let completed = 0;
    let failed = 0;
    const errors = [];

    for (const mun of municipios) {
        try {
            await downloadMunicipio(mun.id);
            completed++;
        } catch (err) {
            failed++;
            errors.push({ id: mun.id, name: mun.name, error: err.message });
        }
        onProgress?.({ completed, failed, total, current: mun.name });
    }

    return { completed, failed, total, errors };
}

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

// ─── Get ALL downloaded data merged (for offline page reload) ───

export async function getAllOfflineData() {
    const downloads = await db.downloads.toArray();
    if (downloads.length === 0) return null;

    const [personas, lideres] = await Promise.all([
        db.personas.toArray(),
        db.lideres.toArray(),
    ]);

    const transformedLideres = lideres.map(l => ({
        ...l,
        tipo_registro: 'Líderes',
        votos: l.votos ?? 0,
    }));

    const seen = new Set();
    const merged = [];
    for (const row of [...personas, ...transformedLideres]) {
        const key = row.id;
        if (seen.has(key)) {
            const existing = merged.find(r => r.id === key);
            if (existing && row.tipo_registro && !existing.tipo_registro.includes(row.tipo_registro)) {
                existing.tipo_registro += `, ${row.tipo_registro}`;
            }
            continue;
        }
        seen.add(key);
        merged.push({ ...row });
    }

    return {
        data: merged,
        downloads,
        municipioNames: downloads.map(d => d.municipioName),
    };
}

// ─── Get a single person from offline DB (for persona panel) ───

export async function getOfflinePersona(personId) {
    let person = await db.personas.get(personId);
    let source = 'persona';
    if (!person) {
        person = await db.lideres.get(personId);
        source = 'lider';
    }
    if (!person) return null;

    const nexos = await db.nexos.where('personId').equals(personId).toArray();

    return {
        ...person,
        municipio: person.municipio || person.municipioId,
        cargos: person.cargos || (person.cargo ? [person.cargo] : []),
        observacion: person.observacion || person.observaciones || '',
        nexos: nexos.map(n => ({
            id: n.id, nombre: n.nombre, parentesco: n.parentesco,
            cargo: n.cargo, edad: n.edad, gustos: n.gustos, observaciones: n.observaciones,
        })),
        _offline: true,
        _source: source,
    };
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

// ─── Wipe every downloaded region (logout / account switch) ───
// The pendingChanges queue is kept: it holds unsynced edits, not downloaded data.

export async function clearDownloadedData() {
    const tables = [db.municipios, db.downloads, db.personas, db.lideres, db.nexos, db.gobernador];
    await db.transaction('rw', tables, async () => {
        await Promise.all(tables.map(table => table.clear()));
    });
}

// ─── Pending Changes (offline edits queue) ───
// Every change records its owner (userKey of the session that made it). Only the owner's
// changes are listed, counted or synced, so another account never sends or sees them.

let ownerKey = Promise.resolve(null);

// Accepts the key or a promise of it (hashing is async).
export function setOfflineOwner(key) {
    ownerKey = Promise.resolve(key).then(value => value || null);
}

export function getOfflineOwnerKey() {
    return ownerKey;
}

export async function addPendingChange(type, action, data) {
    const owner = await ownerKey;
    if (!owner) throw new Error('No hay una sesión activa para guardar cambios sin conexión.');

    return db.pendingChanges.add({
        type,       // 'lider', 'persona', 'nexo'
        action,     // 'create', 'update', 'delete'
        data,       // the payload
        owner,
        synced: 0,
        createdAt: new Date().toISOString(),
    });
}

export async function getPendingChanges() {
    const owner = await ownerKey;
    if (!owner) return [];
    return db.pendingChanges.where('synced').equals(0).and(c => c.owner === owner).toArray();
}

export async function getPendingCount() {
    const owner = await ownerKey;
    if (!owner) return 0;
    return db.pendingChanges.where('synced').equals(0).and(c => c.owner === owner).count();
}

// Changes of other accounts (or saved before owners existed and not claimed) are kept hidden for
// FOREIGN_RETENTION_MS from the moment they were hidden, then deleted. They are never listed, counted
// or sent while hidden; when their owner logs in again on this device they become visible and sync.
export const FOREIGN_RETENTION_MS = 7 * 24 * 60 * 60 * 1000;

export async function countLegacyPendingChanges() {
    return db.pendingChanges.filter(c => !c.owner && !c.hiddenSince && c.synced === 0).count();
}

export async function reconcilePendingChanges(owner, { adoptLegacy = false, now = Date.now() } = {}) {
    const result = { adopted: 0, restored: 0, hidden: 0, expired: 0 };
    const all = await db.pendingChanges.toArray();

    await db.transaction('rw', db.pendingChanges, async () => {
        for (const change of all) {
            if (change.owner && change.owner === owner) {
                if (change.hiddenSince) {
                    await db.pendingChanges.update(change.id, { hiddenSince: undefined });
                    result.restored++;
                }
            } else if (!change.owner && !change.hiddenSince && adoptLegacy) {
                await db.pendingChanges.update(change.id, { owner });
                result.adopted++;
            } else if (!change.hiddenSince) {
                await db.pendingChanges.update(change.id, { hiddenSince: new Date(now).toISOString() });
                result.hidden++;
            } else if (now - Date.parse(change.hiddenSince) > FOREIGN_RETENTION_MS) {
                await db.pendingChanges.delete(change.id);
                result.expired++;
            }
        }
    });

    return result;
}

// Removes the given owner's queue (logout after the user accepted to discard it).
export async function clearPendingChanges(owner) {
    if (!owner) return;
    await db.pendingChanges.filter(c => c.owner === owner).delete();
}

export async function markSynced(id) {
    return db.pendingChanges.update(id, { synced: 1 });
}

// A 4xx that will answer the same on every retry (no permission, gone, invalid). Session (401/419),
// timeout (408) and rate limit (429) are temporary: those changes stay queued.
function isDefinitiveRejection(status) {
    return status >= 400 && status < 500 && ![401, 408, 419, 429].includes(status);
}

// The record a change creates (its client_id becomes the server id) and the records it needs to exist.
function createdId(change) {
    return change.action === 'create' ? change.data?.client_id ?? null : null;
}

function referencedIds(change) {
    if (change.type === 'nexo' && change.action === 'create') return [change.data?.personId];
    if (change.action === 'create') return [];
    return [change.data?.id];
}

// A 404 is only final when the record is not still waiting to be created by another queued change.
function waitsForQueuedCreate(change, pending, resolved) {
    const needed = referencedIds(change).filter(Boolean);
    return pending.some(other => other.id !== change.id && !resolved.has(other.id)
        && createdId(other) !== null && needed.includes(createdId(other)));
}

// The server refused the change for good: it leaves the queue (resolved, not retried) with the reason.
export async function markRejected(id, status, reason) {
    return db.pendingChanges.update(id, {
        synced: 1, rejected: true, rejectedStatus: status, rejectedReason: reason, rejectedAt: new Date().toISOString(),
    });
}

// A refused create must not keep showing as saved in the offline view.
async function dropRejectedLocalRecord(change) {
    const id = createdId(change);
    if (!id) return;
    if (change.type === 'lider') await db.lideres.delete(id);
    if (change.type === 'nexo') await db.nexos.delete(id);
}

async function rejectionReason(res) {
    const body = await res.json().catch(() => null);
    if (res.status === 403) return forbiddenMessage(body?.message);
    const firstError = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    return firstError || body?.message || 'El servidor no aceptó el cambio.';
}

const SYNCED_RETENTION_MS = 86400000;
const REJECTED_RETENTION_MS = 7 * 86400000;

// Synced changes go after a day; refused ones are kept a week from the refusal (they hold what the user typed).
export function isExpiredResolvedChange(change, now = Date.now()) {
    if (change.rejected) {
        return Boolean(change.rejectedAt) && change.rejectedAt < new Date(now - REJECTED_RETENTION_MS).toISOString();
    }
    return change.createdAt < new Date(now - SYNCED_RETENTION_MS).toISOString();
}

// ─── Sync Engine ───

// Callers (auto-sync, manual button, login of the owner) share one run, so a change is never sent twice.
let syncInFlight = null;

export function syncPendingChanges() {
    if (!syncInFlight) {
        syncInFlight = runSync().finally(() => { syncInFlight = null; });
    }
    return syncInFlight;
}

async function runSync() {
    const owner = await ownerKey;
    const pending = await getPendingChanges();
    if (pending.length === 0) return { synced: 0, failed: 0 };

    let synced = 0;
    let failed = 0;
    let rejected = 0;
    const messages = [];
    // Changes settled in this run (synced or refused for good).
    const resolved = new Set();

    // Get CSRF token
    const cookie = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='));
    const csrfToken = cookie ? decodeURIComponent(cookie.split('=')[1]) : '';

    for (const change of pending) {
        // The session changed mid-sync (logout / other account): stop, never send with someone else's cookie.
        if ((await ownerKey) !== owner) break;
        let url, method;

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

        let res;
        try {
            res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: change.action !== 'delete' ? JSON.stringify(change.data) : undefined,
            });
        } catch {
            // No network: later changes may depend on this one, keep the queue order for the next run.
            failed++;
            break;
        }

        if (res.ok) {
            await markSynced(change.id);
            resolved.add(change.id);
            synced++;
            continue;
        }

        const definitive = isDefinitiveRejection(res.status)
            && !(res.status === 404 && waitsForQueuedCreate(change, pending, resolved));
        if (!definitive) {
            // Temporary (5xx, session, rate limit) or still waiting for its create: stop here, in order.
            failed++;
            break;
        }

        const reason = await rejectionReason(res);
        await markRejected(change.id, res.status, reason);
        await dropRejectedLocalRecord(change);
        resolved.add(change.id);
        messages.push(reason);
        rejected++;
    }

    await db.pendingChanges.where('synced').equals(1).and(c => isExpiredResolvedChange(c)).delete();

    if (rejected > 0) {
        const more = rejected > 1 ? ` (y ${rejected - 1} cambio${rejected > 2 ? 's' : ''} más)` : '';
        showGlobalToast(`Un cambio hecho sin internet no se guardó${more}: ${messages[0]}`, 'error');
    }

    return { synced, failed, rejected, messages };
}

// ─── Offline CRUD (saves locally + queues for sync) ───

export async function offlineCreateLider(liderData) {
    const id = crypto.randomUUID();
    const record = { ...liderData, id, _offline: true };

    await db.lideres.put(record);
    // client_id becomes the server id: a create sent twice (two tabs, a retry) is only stored once.
    await addPendingChange('lider', 'create', { ...liderData, client_id: id });

    return record;
}

export async function offlineUpdatePersona(id, updates) {
    const original = (await db.personas.get(id)) || (await db.lideres.get(id));

    // Only sync fields that actually changed (prevents overwriting server data with blanks)
    const changedFields = { id };
    if (original) {
        for (const [key, val] of Object.entries(updates)) {
            if (key === 'cargos' || key === 'tipos') continue;
            const origVal = original[key] ?? '';
            const newVal = val ?? '';
            if (String(origVal) !== String(newVal)) {
                changedFields[key] = val;
            }
        }
    } else {
        Object.assign(changedFields, updates);
    }

    // Update local DB with all provided fields
    const lider = await db.lideres.get(id);
    if (lider) await db.lideres.update(id, updates);
    const persona = await db.personas.get(id);
    if (persona) await db.personas.update(id, updates);

    // Only queue changed fields for sync
    if (Object.keys(changedFields).length > 1) {
        await addPendingChange('persona', 'update', changedFields);
    }
}

export async function offlineCreateNexo(personId, nexoData) {
    const id = crypto.randomUUID();
    const record = { ...nexoData, id, personId, _offline: true };

    await db.nexos.put(record);
    await addPendingChange('nexo', 'create', { ...nexoData, personId, client_id: id });

    return record;
}

export async function offlineDeleteNexo(nexoId) {
    await db.nexos.delete(nexoId);
    await addPendingChange('nexo', 'delete', { id: nexoId });
}
