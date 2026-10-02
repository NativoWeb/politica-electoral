import { router } from '@inertiajs/react';

const NIVEL_BADGE = {
    sin_llamar: { label: 'Sin llamar', cls: 'bg-gray-100 text-gray-600' },
    contactado: { label: 'Contactado', cls: 'bg-yellow-100 text-yellow-700' },
    confirmado: { label: 'Confirmado', cls: 'bg-blue-100 text-blue-700' },
    comprometido: { label: 'Comprometido', cls: 'bg-emerald-100 text-emerald-700' },
    no_responde: { label: 'No responde', cls: 'bg-red-100 text-red-700' },
};

function WhatsAppIcon() {
    return (
        <svg className="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="currentColor">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
        </svg>
    );
}

function TagDot({ active, label, color }) {
    if (!active) return null;
    return (
        <span
            title={label}
            className={`inline-block w-2 h-2 rounded-full flex-shrink-0 ${color}`}
        />
    );
}

export default function VotanteTable({ votantes, selectedId, onSelect }) {
    const { data, current_page, last_page, links, from, to, total } = votantes;

    function goToPage(url) {
        if (!url) return;
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    }

    if (!data || data.length === 0) {
        return (
            <div className="bg-white rounded-xl border border-[var(--color-line)] p-8 text-center">
                <svg className="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                </svg>
                <p className="text-[14px] font-semibold text-[var(--color-ink-faint)]">No se encontraron votantes</p>
                <p className="text-[12px] text-[var(--color-ink-faint)] mt-1">Intenta ajustar los filtros</p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-xl border border-[var(--color-line)] overflow-hidden">
            {/* Table */}
            <div className="overflow-x-auto">
                <table className="w-full text-left">
                    <thead>
                        <tr className="bg-gray-50 border-b border-[var(--color-line)]">
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider">Nombre</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider hidden sm:table-cell">Cedula</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider hidden md:table-cell">Municipio</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider hidden lg:table-cell">Partido</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider hidden sm:table-cell">Tel</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider">Confianza</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider hidden sm:table-cell">Referidos</th>
                            <th className="px-3 py-2.5 text-[11px] font-bold text-[var(--color-ink-faint)] uppercase tracking-wider w-[60px]">Tags</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--color-line)]">
                        {data.map(v => {
                            const isSelected = selectedId === v.id;
                            const badge = NIVEL_BADGE[v.nivel_confianza] || NIVEL_BADGE.sin_llamar;
                            const phone = v.telefono?.replace(/\D/g, '') || '';

                            return (
                                <tr
                                    key={v.id}
                                    onClick={() => onSelect(v.id)}
                                    className={`cursor-pointer transition-colors ${
                                        isSelected
                                            ? 'bg-blue-50 border-l-2 border-l-[var(--color-primary)]'
                                            : 'hover:bg-gray-50'
                                    }`}
                                >
                                    <td className="px-3 py-2.5">
                                        <span className="text-[13px] font-semibold text-[var(--color-ink)] line-clamp-1">{v.nombre}</span>
                                        <span className="block sm:hidden text-[11px] text-[var(--color-ink-faint)]">{v.cedula}</span>
                                    </td>
                                    <td className="px-3 py-2.5 text-[13px] text-[var(--color-ink-soft)] hidden sm:table-cell font-mono">{v.cedula || '-'}</td>
                                    <td className="px-3 py-2.5 text-[13px] text-[var(--color-ink-soft)] hidden md:table-cell">{v.municipio || '-'}</td>
                                    <td className="px-3 py-2.5 text-[13px] text-[var(--color-ink-soft)] hidden lg:table-cell truncate max-w-[120px]">{v.partido || '-'}</td>
                                    <td className="px-3 py-2.5 hidden sm:table-cell">
                                        {v.telefono ? (
                                            <a
                                                href={`https://wa.me/57${phone}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                onClick={e => e.stopPropagation()}
                                                className="inline-flex items-center gap-1 text-[13px] text-[var(--color-ink-soft)] hover:text-green-600 transition-colors"
                                                title="Abrir WhatsApp"
                                            >
                                                <WhatsAppIcon />
                                                <span className="hidden xl:inline">{v.telefono}</span>
                                            </a>
                                        ) : (
                                            <span className="text-[13px] text-gray-300">-</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <span className={`inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold ${badge.cls}`}>
                                            {badge.label}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2.5 hidden sm:table-cell">
                                        {(v.referidos_count || 0) > 0 ? (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 text-[12px] font-bold">
                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                                {v.referidos_count}
                                            </span>
                                        ) : (
                                            <span className="text-[13px] text-gray-300">-</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div className="flex items-center gap-1">
                                            <TagDot active={v.gran_elector} label="Gran Elector" color="bg-amber-400" />
                                            <TagDot active={v.verificado} label="Verificado" color="bg-emerald-400" />
                                            <TagDot active={v.militante} label="Militante" color="bg-blue-400" />
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-2 px-4 py-3 border-t border-[var(--color-line)] bg-gray-50/50">
                <p className="text-[12px] text-[var(--color-ink-faint)]">
                    {from && to ? `${from}-${to} de ${total}` : `${total} registros`}
                </p>
                <div className="flex items-center gap-1">
                    {links.map((link, i) => {
                        if (i === 0) {
                            return (
                                <button
                                    key="prev"
                                    disabled={!link.url}
                                    onClick={() => goToPage(link.url)}
                                    className="px-2 py-1 rounded text-[12px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                    title="Anterior"
                                >
                                    &laquo;
                                </button>
                            );
                        }
                        if (i === links.length - 1) {
                            return (
                                <button
                                    key="next"
                                    disabled={!link.url}
                                    onClick={() => goToPage(link.url)}
                                    className="px-2 py-1 rounded text-[12px] font-semibold text-[var(--color-ink-soft)] hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                    title="Siguiente"
                                >
                                    &raquo;
                                </button>
                            );
                        }
                        return (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => goToPage(link.url)}
                                className={`min-w-[28px] h-7 rounded text-[12px] font-semibold transition-colors ${
                                    link.active
                                        ? 'bg-[var(--color-primary)] text-white'
                                        : 'text-[var(--color-ink-soft)] hover:bg-gray-200'
                                } ${!link.url ? 'opacity-30 cursor-not-allowed' : ''}`}
                            >
                                {link.label}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
