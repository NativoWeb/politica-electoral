import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import ImportFila from '@/Components/Empresarios/ImportFila';
import { failure, request } from '@/lib/actas';

// Review of an Excel load of organizations: every row with what will happen (nueva, ya existe → completar vacíos o
// saltar, con errores) and its corrections. Nothing is saved until "Guardar carga".
export default function EmpresariosImportar({ carga: initialCarga, filas: initialFilas, columnas }) {
    const [carga, setCarga] = useState(initialCarga);
    const [filas, setFilas] = useState(initialFilas);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [ask, setAsk] = useState(null);
    const enRevision = carga.estado === 'revision';
    const base = `/empresarios/carga/${carga.id}`;

    async function patchRow(id, body) {
        setError('');
        const res = await request(`${base}/filas/${id}`, { method: 'PATCH', json: body });
        if (!res.ok) { setError(failure(res, 'No se pudo guardar la corrección.')); return false; }
        setCarga(res.body.data.carga);
        setFilas(res.body.data.filas);
        return true;
    }

    async function confirm() {
        setAsk(null);
        setBusy(true);
        const res = await request(`${base}/confirmar`, { method: 'POST', json: {} });
        setBusy(false);
        if (!res.ok) { setError(res.body?.errors?.carga?.[0] ?? failure(res, 'No se pudo guardar la carga.')); return; }
        setNotice(res.body.message);
        setCarga(c => ({ ...c, estado: 'confirmada', resumen: res.body.data }));
    }

    async function discard() {
        setAsk(null);
        setBusy(true);
        const res = await request(base, { method: 'DELETE' });
        setBusy(false);
        if (!res.ok) { setError(failure(res, 'No se pudo descartar.')); return; }
        router.visit('/empresarios');
    }

    const c = carga.conteo;

    return (
        <AppLayout title="Cargar empresarios" breadcrumb={[{ label: 'EMPRESARIOS', href: '/empresarios' }, { label: 'Cargar Excel' }]}>
            <Head title="Cargar Excel — Empresarios" />
            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href="/empresarios" className="inline-flex items-center min-h-[36px] text-[14px] font-semibold text-white/75 hover:text-white">← Empresarios y gremios</Link>
                <h1 className="text-[20px] lg:text-[24px] font-extrabold leading-tight">Revisar la carga</h1>
                <p className="text-[14px] text-white/85">{carga.archivo}</p>
            </div>
            <div className="p-4 lg:px-6 space-y-4">
                {notice && <p role="status" className="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-[15px] font-semibold text-emerald-800">{notice}</p>}
                {error && <p role="alert" className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-[15px] font-semibold text-red-700">{error}</p>}
                {!enRevision && !notice && <p role="status" className="px-4 py-3 rounded-xl bg-gray-100 text-[15px] font-semibold">Esta carga ya {carga.estado === 'confirmada' ? 'se guardó' : 'se descartó'}.</p>}

                <section className="bg-white border border-[var(--color-line)] rounded-2xl p-4 space-y-2" aria-label="Resumen">
                    <p className="text-[16px] font-bold">{filas.length} filas: {c.crear} nuevas · {c.completar} ya existen (se completan solo los datos vacíos) · {c.saltar} se saltan · {c.error} con errores</p>
                    <p className="text-[14px] text-[var(--color-ink-soft)]">Una organización que ya existe (mismo NIT, o mismo nombre en el mismo municipio) nunca se sobrescribe: solo se llenan sus datos vacíos y se agrega el gremio. Las filas con errores no se guardan hasta corregirlas.</p>
                    {enRevision && (
                        <div className="flex flex-wrap gap-2 pt-1">
                            <button type="button" disabled={busy || c.crear + c.completar === 0} onClick={() => setAsk('confirm')} className="min-h-[48px] px-6 rounded-xl bg-emerald-600 text-white text-[16px] font-bold disabled:opacity-50">{busy ? 'Guardando…' : 'Guardar carga'}</button>
                            <button type="button" disabled={busy} onClick={() => setAsk('discard')} className="min-h-[48px] px-5 rounded-xl border-2 border-gray-200 text-[15px] font-bold text-[var(--color-ink-soft)]">Descartar</button>
                        </div>
                    )}
                </section>

                <ul className="space-y-2" aria-label="Filas del archivo">
                    {filas.map(f => <ImportFila key={f.id} fila={f} columnas={columnas} editable={enRevision} onPatch={body => patchRow(f.id, body)} />)}
                </ul>
            </div>
            {ask === 'confirm' && (
                <ConfirmDialog title="¿Guardar la carga?" message={`Se crean ${c.crear} organizaciones y se completan ${c.completar}. Las filas con errores no se guardan.`} confirmLabel="Sí, guardar" onConfirm={confirm} onCancel={() => setAsk(null)} />
            )}
            {ask === 'discard' && (
                <ConfirmDialog title="¿Descartar la carga?" message="No se guarda nada de este archivo." confirmLabel="Sí, descartar" danger onConfirm={discard} onCancel={() => setAsk(null)} />
            )}
        </AppLayout>
    );
}
