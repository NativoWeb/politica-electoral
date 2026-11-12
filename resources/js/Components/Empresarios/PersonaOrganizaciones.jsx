import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { failure, request } from '@/lib/actas';

// Who can see Empresarios y gremios: view.empresarios or manage.empresarios.
export function canSeeEmpresarios(user) {
    const perms = user?.permissions || [];

    return !!user?.isSuperadmin || perms.includes('*') || perms.includes('view.empresarios') || perms.includes('manage.empresarios');
}

// "Empresas y gremios" of a ficha: the organizations it belongs to and its role in each.
export default function PersonaOrganizaciones({ liderId, compact = false }) {
    const [state, setState] = useState({ loading: true, error: '', items: [] });

    useEffect(() => {
        if (!liderId) return undefined;
        let alive = true;
        request(`/empresarios/persona/${liderId}`).then(res => {
            if (!alive) return;
            setState(res.ok
                ? { loading: false, error: '', items: res.body?.data ?? [] }
                : { loading: false, error: failure(res, 'No se pudieron cargar las empresas y gremios.'), items: [] });
        });
        return () => { alive = false; };
    }, [liderId]);

    const text = compact ? 'text-[14px]' : 'text-[16px]';

    return (
        <section aria-label="Empresas y gremios de la persona" className="space-y-2">
            {!compact && <h3 className={`${text} font-extrabold text-[var(--color-ink)]`}>Empresas y gremios</h3>}
            {state.loading && <p className={`${text} text-[var(--color-ink-faint)]`} role="status">Cargando…</p>}
            {state.error && <p className={`${text} font-semibold text-red-700`} role="alert">{state.error}</p>}
            {!state.loading && !state.error && state.items.length === 0 && (
                <p className={`${text} text-[var(--color-ink-faint)]`}>No pertenece a ninguna empresa ni gremio.</p>
            )}
            {state.items.length > 0 && (
                <ul className="space-y-2">
                    {state.items.map(o => (
                        <li key={`${o.id}-${o.rol}`}>
                            <Link href={`/empresarios?abrir=${o.id}`} className="block p-3 rounded-xl border border-[var(--color-line)] bg-white hover:bg-blue-50">
                                <span className={`block ${text} font-bold text-[var(--color-primary)] uppercase`}>{o.nombre}</span>
                                <span className="block text-[14px] text-[var(--color-ink-soft)]">{[o.tipo_label, o.rol_label, o.municipio].filter(Boolean).join(' · ')}</span>
                                {o.nota && <span className="block text-[13px] text-[var(--color-ink-soft)]">{o.nota}</span>}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
