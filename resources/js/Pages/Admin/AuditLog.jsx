import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const METHOD_COLORS = {
    POST: 'bg-emerald-100 text-emerald-700',
    PUT: 'bg-amber-100 text-amber-700',
    PATCH: 'bg-amber-100 text-amber-700',
    DELETE: 'bg-red-100 text-red-700',
    LOGIN: 'bg-blue-100 text-blue-700',
    LOGOUT: 'bg-blue-100 text-blue-700',
};

function getMethodColor(action) {
    if (!action) return 'bg-gray-100 text-gray-600';
    const upper = action.toUpperCase();
    for (const [key, cls] of Object.entries(METHOD_COLORS)) {
        if (upper.startsWith(key) || upper.includes(key)) return cls;
    }
    return 'bg-gray-100 text-gray-600';
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleString('es-CO', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function shortId(uuid) {
    if (!uuid) return '-';
    return uuid.substring(0, 8);
}

export default function AuditLog({ logs, filters, users, entityTypes }) {
    const [form, setForm] = useState({
        user_id: filters?.user_id || '',
        search: filters?.search || '',
        entity_type: filters?.entity_type || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
    });
    const [expanded, setExpanded] = useState({});

    function handleFilter(e) {
        e.preventDefault();
        const params = {};
        Object.entries(form).forEach(([k, v]) => { if (v) params[k] = v; });
        router.get('/admin/auditoria', params, { preserveState: true });
    }

    function handleClear() {
        setForm({ user_id: '', search: '', entity_type: '', date_from: '', date_to: '' });
        router.get('/admin/auditoria', {}, { preserveState: true });
    }

    function toggleRow(id) {
        setExpanded(prev => ({ ...prev, [id]: !prev[id] }));
    }

    const inputCls = "px-3 py-2 border border-[var(--color-line)] rounded-lg text-[13px] focus:outline-none focus:border-[var(--color-primary)] bg-white";

    return (
        <AppLayout title="Auditoria" breadcrumb={[{ label: 'ADMIN', href: '/admin/usuarios' }, { label: 'AUDITORIA' }]}>
            <Head title="Auditoria -- Administracion" />

            {/* Header */}
            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4 flex items-center justify-between">
                <div>
                    <h1 className="text-[18px] lg:text-[22px] font-extrabold">AUDITORIA</h1>
                    <p className="text-[11px] text-white/40 mt-0.5">Historial de acciones en el sistema</p>
                </div>
                <span className="text-[11px] text-white/50">{logs.total} registro{logs.total !== 1 ? 's' : ''}</span>
            </div>

            {/* Filters */}
            <form onSubmit={handleFilter} className="bg-white border-b border-[var(--color-line)] px-4 lg:px-6 py-3">
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <select
                        value={form.user_id}
                        onChange={e => setForm(p => ({ ...p, user_id: e.target.value }))}
                        className={inputCls}
                    >
                        <option value="">Todos los usuarios</option>
                        {users.map(u => (
                            <option key={u.id} value={u.id}>{u.name}</option>
                        ))}
                    </select>

                    <input
                        type="text"
                        placeholder="Buscar..."
                        value={form.search}
                        onChange={e => setForm(p => ({ ...p, search: e.target.value }))}
                        className={inputCls}
                    />

                    <select
                        value={form.entity_type}
                        onChange={e => setForm(p => ({ ...p, entity_type: e.target.value }))}
                        className={inputCls}
                    >
                        <option value="">Tipo de entidad</option>
                        {entityTypes.map(t => (
                            <option key={t} value={t}>{t}</option>
                        ))}
                    </select>

                    <input
                        type="date"
                        value={form.date_from}
                        onChange={e => setForm(p => ({ ...p, date_from: e.target.value }))}
                        className={inputCls}
                        title="Desde"
                    />

                    <input
                        type="date"
                        value={form.date_to}
                        onChange={e => setForm(p => ({ ...p, date_to: e.target.value }))}
                        className={inputCls}
                        title="Hasta"
                    />

                    <div className="flex gap-2">
                        <button type="submit" className="flex-1 px-4 py-2 bg-[var(--color-primary)] text-white rounded-lg text-[13px] font-bold hover:opacity-90">
                            Filtrar
                        </button>
                        <button type="button" onClick={handleClear} className="px-4 py-2 border border-[var(--color-line)] rounded-lg text-[13px] text-[var(--color-ink-faint)] hover:bg-gray-50">
                            Limpiar
                        </button>
                    </div>
                </div>
            </form>

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="w-full text-left text-[13px]">
                    <thead className="bg-gray-50 border-b border-[var(--color-line)]">
                        <tr>
                            <th className="px-4 py-3 font-bold text-[11px] text-[var(--color-ink-faint)] uppercase">Fecha/Hora</th>
                            <th className="px-4 py-3 font-bold text-[11px] text-[var(--color-ink-faint)] uppercase">Usuario</th>
                            <th className="px-4 py-3 font-bold text-[11px] text-[var(--color-ink-faint)] uppercase">Accion</th>
                            <th className="px-4 py-3 font-bold text-[11px] text-[var(--color-ink-faint)] uppercase">Entidad</th>
                            <th className="px-4 py-3 font-bold text-[11px] text-[var(--color-ink-faint)] uppercase">IP</th>
                            <th className="px-4 py-3 font-bold text-[11px] text-[var(--color-ink-faint)] uppercase">Detalles</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--color-line)]">
                        {logs.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center text-[var(--color-ink-faint)]">
                                    No se encontraron registros de auditoria
                                </td>
                            </tr>
                        )}
                        {logs.data.map(log => (
                            <LogRow key={log.id} log={log} expanded={!!expanded[log.id]} onToggle={() => toggleRow(log.id)} />
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {logs.last_page > 1 && (
                <div className="flex items-center justify-between px-4 lg:px-6 py-3 border-t border-[var(--color-line)] bg-white">
                    <span className="text-[12px] text-[var(--color-ink-faint)]">
                        {logs.from}-{logs.to} de {logs.total}
                    </span>
                    <div className="flex gap-1">
                        {logs.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                className={`px-3 py-1.5 rounded text-[12px] font-bold transition-colors
                                    ${link.active ? 'bg-[var(--color-primary)] text-white' : ''}
                                    ${!link.url ? 'text-gray-300 cursor-not-allowed' : 'text-[var(--color-ink)] hover:bg-gray-100'}
                                `}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function LogRow({ log, expanded, onToggle }) {
    const hasMetadata = log.metadata && Object.keys(log.metadata).length > 0;

    return (
        <>
            <tr className="hover:bg-gray-50/50">
                <td className="px-4 py-2.5 whitespace-nowrap text-[12px]">
                    {formatDate(log.created_at)}
                </td>
                <td className="px-4 py-2.5">
                    <span className="font-medium">{log.user?.name || 'Sistema'}</span>
                </td>
                <td className="px-4 py-2.5">
                    <span className={`inline-block px-2 py-0.5 rounded text-[11px] font-bold ${getMethodColor(log.action)}`}>
                        {log.action}
                    </span>
                </td>
                <td className="px-4 py-2.5 whitespace-nowrap">
                    <span className="font-medium">{log.entity_type}</span>
                    {log.entity_id && (
                        <span className="ml-1 text-[11px] text-[var(--color-ink-faint)] font-mono">
                            {shortId(log.entity_id)}
                        </span>
                    )}
                </td>
                <td className="px-4 py-2.5 text-[12px] text-[var(--color-ink-faint)] font-mono">
                    {log.ip_address || '-'}
                </td>
                <td className="px-4 py-2.5">
                    {hasMetadata ? (
                        <button
                            onClick={onToggle}
                            className="text-[12px] font-bold text-[var(--color-primary)] hover:underline"
                        >
                            {expanded ? 'Ocultar' : 'Ver'}
                        </button>
                    ) : (
                        <span className="text-[11px] text-[var(--color-ink-faint)]">--</span>
                    )}
                </td>
            </tr>
            {expanded && hasMetadata && (
                <tr>
                    <td colSpan={6} className="px-4 py-3 bg-gray-50">
                        <pre className="text-[11px] font-mono text-[var(--color-ink)] whitespace-pre-wrap break-all max-h-[300px] overflow-y-auto">
                            {JSON.stringify(log.metadata, null, 2)}
                        </pre>
                    </td>
                </tr>
            )}
        </>
    );
}
