import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import AdminCard from '@/Components/Territorio/AdminCard';
import ConfigHeader from '@/Components/Configuracion/ConfigHeader';
import { plural } from '@/Components/Territorio/TerritorioControls';

const ICONS = {
    lista: <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm0 5.25h.007v.008H3.75V12zm0 5.25h.007v.008H3.75v-.008z" />,
    ajustes: <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />,
    historial: <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />,
    enlace: <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />,
};

function SectionTitle({ children }) {
    return <h2 className="text-[12px] font-bold uppercase tracking-wider text-[var(--color-ink-faint)] mt-2 mb-2 px-1">{children}</h2>;
}

export default function ConfiguracionIndex({ listas = [], accesos = [], resumenAjustes: ajustes = { total: 0, cambiados: 0 }, totalCambios = 0 }) {
    const ajustesSubtitle = [plural(ajustes.total, 'ajuste', 'ajustes'), ajustes.cambiados > 0 ? `${ajustes.cambiados} cambiado${ajustes.cambiados === 1 ? '' : 's'}` : 'todos por defecto'].join(' · ');

    return (
        <AppLayout title="Configuración" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'CONFIGURACIÓN' }]}>
            <Head title="Configuración" />
            <ConfigHeader
                title="CONFIGURACIÓN"
                subtitle="Listas de los formularios, ajustes del sistema e historial de cambios. Aplica a todos los grupos políticos."
                back={{ href: '/admin/territorio', label: 'Volver a Admin' }}
            />

            <div className="p-4 lg:p-6 space-y-3">
                <SectionTitle>Ajustes e historial</SectionTitle>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <AdminCard href="/admin/configuracion/ajustes" title="Ajustes del sistema" subtitle={ajustesSubtitle} iconClass="bg-slate-100 text-slate-700" icon={ICONS.ajustes} />
                    <AdminCard href="/admin/configuracion/historial" title="Historial de cambios" subtitle={`${plural(totalCambios, 'cambio registrado', 'cambios registrados')} · revertir un cambio`} iconClass="bg-sky-100 text-sky-700" icon={ICONS.historial} />
                </div>

                <SectionTitle>Listas de los formularios</SectionTitle>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    {listas.map(l => (
                        <AdminCard
                            key={l.clave}
                            href={`/admin/configuracion/listas/${l.clave}`}
                            title={l.nombre}
                            subtitle={[`${l.activas} de ${l.total} activas`, l.fijo ? 'valores fijos del sistema' : null, l.descripcion].filter(Boolean).join(' · ')}
                            iconClass="bg-emerald-100 text-emerald-700"
                            icon={ICONS.lista}
                        />
                    ))}
                </div>

                <SectionTitle>Listas con pantalla propia</SectionTitle>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    {accesos.map(a => (
                        <AdminCard key={a.href} href={a.href} title={a.titulo} subtitle={a.descripcion} iconClass="bg-amber-100 text-amber-700" icon={ICONS.enlace} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
