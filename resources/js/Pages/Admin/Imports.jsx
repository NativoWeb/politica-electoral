import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

function formatBytes(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function TypeBadge({ type }) {
    const colors = {
        xlsx: 'bg-emerald-100 text-emerald-700',
        xls: 'bg-teal-100 text-teal-700',
        csv: 'bg-blue-100 text-blue-700',
        json: 'bg-amber-100 text-amber-700',
    };
    const key = (type ?? '').toLowerCase();
    const cls = colors[key] ?? 'bg-gray-100 text-gray-600';
    return (
        <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${cls}`}>
            {type ?? '—'}
        </span>
    );
}

export default function Imports({ imports }) {
    return (
        <AppLayout
            title="Importaciones"
            breadcrumb={[
                { label: 'SANTANDER', href: '/' },
                { label: 'ADMIN', href: '/admin' },
                { label: 'IMPORTACIONES' },
            ]}
        >
            <Head title="Importaciones — Administración" />

            {/* Page header */}
            <div className="bg-white border-b border-[var(--color-line)] px-6 py-5">
                <h1 className="text-[18px] font-extrabold text-[var(--color-ink)] font-[var(--font-heading)]">Importaciones</h1>
                <p className="text-[12px] text-[var(--color-ink-faint)] mt-0.5">Historial de archivos fuente cargados al sistema</p>
            </div>

            <div className="p-4 lg:p-6 space-y-5">

                {/* Info notice */}
                <div className="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg px-5 py-4">
                    <svg className="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                    </svg>
                    <div>
                        <p className="text-[13px] font-semibold text-blue-800">Las importaciones se ejecutan desde la línea de comandos</p>
                        <code className="text-[12px] font-[var(--font-mono)] text-blue-700 bg-blue-100 px-2 py-0.5 rounded mt-1 inline-block">
                            php artisan import:electoral
                        </code>
                    </div>
                </div>

                {/* Imports table */}
                <div className="bg-white border border-[var(--color-line)] rounded-lg overflow-hidden">
                    <div className="px-5 py-3 border-b border-[var(--color-line)] bg-gray-50 flex items-center justify-between">
                        <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">
                            {imports.length} archivo{imports.length !== 1 ? 's' : ''} registrado{imports.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-[var(--color-line)]">
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Archivo</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Tipo</th>
                                <th className="text-right px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Tamaño</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Hash SHA-256</th>
                                <th className="text-left px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)]">Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            {imports.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-5 py-12 text-center text-[12px] text-[var(--color-ink-faint)]">
                                        No hay archivos fuente registrados aún.
                                        <br />
                                        <span className="text-[11px]">Ejecuta <code className="font-[var(--font-mono)] bg-gray-100 px-1 rounded">php artisan import:electoral</code> para cargar datos.</span>
                                    </td>
                                </tr>
                            )}
                            {imports.map((file) => (
                                <tr key={file.id} className="border-b border-[var(--color-line)] last:border-b-0 hover:bg-gray-50 transition-colors">
                                    <td className="px-5 py-3.5">
                                        <p className="font-semibold text-[var(--color-ink)] truncate max-w-[280px]">{file.name}</p>
                                    </td>
                                    <td className="px-5 py-3.5">
                                        <TypeBadge type={file.type} />
                                    </td>
                                    <td className="px-5 py-3.5 text-right font-[var(--font-mono)] text-[12px] text-[var(--color-ink-soft)]">
                                        {formatBytes(file.size)}
                                    </td>
                                    <td className="px-5 py-3.5 font-[var(--font-mono)] text-[11px] text-[var(--color-ink-faint)]">
                                        {file.hash ? `${file.hash}…` : '—'}
                                    </td>
                                    <td className="px-5 py-3.5 font-[var(--font-mono)] text-[11px] text-[var(--color-ink-faint)]">
                                        {file.date ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
