import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminCard from '@/Components/Territorio/AdminCard';
import { displayName, plural } from '@/Components/Territorio/TerritorioControls';
import { apiFetch } from '@/lib/api';

const ICONS = {
    divisiones: <path strokeLinecap="round" strokeLinejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />,
    barrios: <path strokeLinecap="round" strokeLinejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />,
    partidos: <path strokeLinecap="round" strokeLinejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm0 0h3" />,
    grupos: <path strokeLinecap="round" strokeLinejoin="round" d="M4 21V4m0 0h11l-1.5 4L15 12H4" />,
    usuarios: <path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />,
    auditoria: <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />,
};

// "· Bucaramanga: 12 pendientes" from the same list the Barrios page shows; nothing when it cannot be read.
function useBarriosPendientes() {
    const [label, setLabel] = useState('');
    useEffect(() => {
        let alive = true;
        apiFetch('/admin/territorio/barrios-sin-comuna?solo_total=1', { method: 'GET' })
            .then(r => (r.ok ? r.json() : null))
            .then(res => {
                if (!alive || !res?.success || !res.municipio) return;
                const n = res.total_textos ?? 0;
                setLabel(`${res.municipio.nombre}: ${n === 0 ? 'sin pendientes' : plural(n, 'pendiente', 'pendientes')}`);
            })
            .catch(() => {});
        return () => { alive = false; };
    }, []);
    return label;
}

function SectionTitle({ children }) {
    return <h2 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mt-2 mb-2 px-1">{children}</h2>;
}

export default function Territory({ resumen }) {
    const auth = usePage().props.auth?.user;
    const isSuperadmin = auth?.role === 'Superadministrador';
    const canAudit = (auth?.permissions || []).some(p => p === '*' || p === 'audit.view');
    const pendientes = useBarriosPendientes();
    const principal = resumen?.principal;

    const divisionesSubtitle = ['Departamentos, provincias y municipios'];
    if (principal) {
        divisionesSubtitle.push(`${displayName(principal.name)}: ${plural(principal.provincias, 'provincia', 'provincias')}, ${plural(principal.municipios, 'municipio', 'municipios')}`);
    }

    return (
        <AppLayout title="Administración" breadcrumb={[{ label: 'COLOMBIA', href: '/' }, { label: 'ADMIN' }]}>
            <Head title="Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <h1 className="text-[18px] lg:text-[22px] font-extrabold">ADMINISTRACIÓN</h1>
                <p className="text-[12px] text-white/60 mt-0.5">Territorio, partidos, usuarios y auditoría</p>
            </div>

            <div className="p-4 lg:p-6 space-y-3">
                <SectionTitle>Territorio</SectionTitle>
                {/* Two columns on wide screens so the cards use the whole width. */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <AdminCard
                    href="/admin/territorio/divisiones"
                    title="Divisiones territoriales"
                    subtitle={divisionesSubtitle.join(' · ')}
                    iconClass="bg-violet-100 text-violet-700"
                    icon={ICONS.divisiones}
                />
                <AdminCard
                    href="/admin/territorio/barrios"
                    title="Barrios y comunas"
                    subtitle={['Asignar barrios a su comuna', pendientes].filter(Boolean).join(' · ')}
                    iconClass="bg-emerald-100 text-emerald-700"
                    icon={ICONS.barrios}
                />
                </div>

                <SectionTitle>Más opciones</SectionTitle>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <AdminCard href="/admin/partidos" title="Partidos" subtitle="Crear, editar y desactivar partidos" iconClass="bg-amber-100 text-amber-700" icon={ICONS.partidos} />
                {isSuperadmin && (
                    <AdminCard href="/admin/campanas" title="Grupos políticos" subtitle="Crear, editar y desactivar grupos políticos" iconClass="bg-indigo-100 text-indigo-700" icon={ICONS.grupos} />
                )}
                {isSuperadmin && (
                    <AdminCard href="/admin/usuarios" title="Usuarios" subtitle="Crear cuentas y asignar roles" iconClass="bg-purple-100 text-purple-700" icon={ICONS.usuarios} />
                )}
                {canAudit && (
                    <AdminCard href="/admin/auditoria" title="Auditoría" subtitle="Registro de todo lo que se hace en el sistema" iconClass="bg-sky-100 text-sky-700" icon={ICONS.auditoria} />
                )}
                </div>
            </div>
        </AppLayout>
    );
}
