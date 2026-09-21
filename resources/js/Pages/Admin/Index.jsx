import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';

const STAT_CARDS = [
    {
        key: 'users',
        label: 'USUARIOS',
        description: 'Cuentas registradas',
        href: '/admin/usuarios',
        color: 'var(--color-primary)',
    },
    {
        key: 'roles',
        label: 'ROLES',
        description: 'Perfiles de acceso',
        href: null,
        color: '#4F46E5',
    },
    {
        key: 'partidos',
        label: 'PARTIDOS',
        description: 'Organizaciones políticas',
        href: '/admin/catalogos',
        color: '#0891B2',
    },
    {
        key: 'imports',
        label: 'IMPORTACIONES',
        description: 'Jobs ejecutados',
        href: '/admin/importaciones',
        color: '#059669',
    },
    {
        key: 'sourceFiles',
        label: 'ARCHIVOS FUENTE',
        description: 'Ficheros cargados',
        href: '/admin/importaciones',
        color: '#D97706',
    },
];

const NAV_LINKS = [
    {
        href: '/admin/personas',
        label: 'PERSONAS',
        description: 'Crear, editar, aliases y contactos',
        icon: (
            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 12c2.5 0 4.5-2 4.5-4.5S14.5 3 12 3 7.5 5 7.5 7.5 9.5 12 12 12zM20 21c0-3.3-3.6-6-8-6s-8 2.7-8 6" />
            </svg>
        ),
    },
    {
        href: '/admin/elecciones',
        label: 'ELECCIONES',
        description: 'Eventos, contiendas, candidaturas y votos',
        icon: (
            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
    },
    {
        href: '/admin/usuarios',
        label: 'USUARIOS',
        description: 'Gestionar cuentas y roles de acceso',
        icon: (
            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
        ),
    },
    {
        href: '/admin/catalogos',
        label: 'CATALOGOS',
        description: 'Partidos, cargos y corporaciones',
        icon: (
            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            </svg>
        ),
    },
    {
        href: '/admin/importaciones',
        label: 'IMPORTACIONES',
        description: 'Historial de archivos fuente y ETL',
        icon: (
            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
        ),
    },
];

export default function AdminIndex({ stats }) {
    return (
        <AppLayout
            title="Administración"
            breadcrumb={[
                { label: 'SANTANDER', href: '/' },
                { label: 'ADMINISTRACIÓN' },
            ]}
        >
            <Head title="Administración — Inteligencia Electoral" />

            {/* Hero header */}
            <div className="bg-[var(--color-primary)] px-6 py-8 text-white">
                <p className="text-[10px] font-semibold tracking-[0.25em] text-white/50 uppercase mb-1">Panel de control</p>
                <h1 className="text-[28px] font-extrabold tracking-tight font-[var(--font-heading)]">ADMINISTRACIÓN</h1>
                <p className="text-[13px] text-white/60 mt-1">Gestión de usuarios, importaciones y catálogos del sistema.</p>
            </div>

            <div className="p-4 lg:p-6 space-y-8">

                {/* Stat cards */}
                <section>
                    <h2 className="text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-4">
                        Resumen del sistema
                    </h2>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        {STAT_CARDS.map(({ key, label, description, href, color }) => {
                            const inner = (
                                <div
                                    className="bg-white border border-[var(--color-line)] rounded-lg p-4 flex flex-col gap-2 hover:shadow-md hover:border-[var(--color-primary)] transition-all group"
                                >
                                    <div
                                        className="w-10 h-10 rounded-lg flex items-center justify-center text-white text-[18px] font-bold"
                                        style={{ background: color }}
                                    >
                                        <span className="font-[var(--font-mono)] text-[20px]">{stats[key] ?? 0}</span>
                                    </div>
                                    <div>
                                        <p className="text-[11px] font-bold tracking-wider text-[var(--color-ink)] group-hover:text-[var(--color-primary)]">{label}</p>
                                        <p className="text-[10px] text-[var(--color-ink-faint)] mt-0.5">{description}</p>
                                    </div>
                                </div>
                            );

                            return href ? (
                                <Link key={key} href={href}>{inner}</Link>
                            ) : (
                                <div key={key}>{inner}</div>
                            );
                        })}
                    </div>
                </section>

                {/* Navigation links */}
                <section>
                    <h2 className="text-[11px] font-bold uppercase tracking-widest text-[var(--color-ink-faint)] mb-4">
                        Acceso rápido
                    </h2>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        {NAV_LINKS.map(({ href, label, description, icon }) => (
                            <Link
                                key={href}
                                href={href}
                                className="bg-white border border-[var(--color-line)] rounded-lg p-5 flex items-start gap-4 hover:border-[var(--color-primary)] hover:shadow-md transition-all group"
                            >
                                <div className="w-11 h-11 rounded-lg bg-blue-50 text-[var(--color-primary)] flex items-center justify-center flex-shrink-0 group-hover:bg-[var(--color-primary)] group-hover:text-white transition-colors">
                                    {icon}
                                </div>
                                <div>
                                    <p className="text-[13px] font-bold text-[var(--color-ink)] group-hover:text-[var(--color-primary)] transition-colors">{label}</p>
                                    <p className="text-[12px] text-[var(--color-ink-faint)] mt-0.5">{description}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </section>

            </div>
        </AppLayout>
    );
}
