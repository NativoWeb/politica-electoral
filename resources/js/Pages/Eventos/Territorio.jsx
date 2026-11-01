import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import TerritorioFiltros from '@/Components/Eventos/TerritorioFiltros';
import TerritorioResultado from '@/Components/Eventos/TerritorioResultado';
import { territoryParams } from '@/lib/eventosIntegracion';

// Eventos especiales: lideres, events and attendees of one municipio (and barrio). Contract: docs/eventos-api.md §12.
export default function Territorio({ filters = {}, municipio = null, provincias = [], barrios = [], resultado = null }) {
    function search(next) {
        router.get('/eventos/territorio', territoryParams(next), { preserveState: true, preserveScroll: true, replace: true });
    }

    const lugar = municipio
        ? [filters.barrio && `Barrio ${filters.barrio}`, municipio.nombre, municipio.provincia].filter(Boolean).join(', ')
        : null;

    return (
        <AppLayout title="Eventos por territorio" breadcrumb={[{ label: 'EVENTOS', href: '/eventos' }, { label: 'POR TERRITORIO' }]}>
            <Head title="Eventos por territorio" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 py-3 lg:py-4">
                <Link href="/eventos" className="inline-flex items-center gap-1 min-h-[36px] text-[14px] font-semibold text-white/75 hover:text-white">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    Eventos
                </Link>
                <h1 className="text-[20px] lg:text-[24px] font-extrabold mt-1">Eventos por territorio</h1>
                <p className="text-[15px] text-white/80 mt-0.5">Elija un municipio (y si quiere un barrio) para ver sus líderes, los eventos que se hicieron ahí y quiénes asistieron.</p>
            </div>

            <div className="p-4 max-w-6xl space-y-4">
                <TerritorioFiltros provincias={provincias} barrios={barrios} filters={filters} onSearch={search} />
                {resultado ? (
                    <TerritorioResultado resultado={resultado} filters={filters} lugar={lugar} />
                ) : (
                    <p className="text-center py-10 px-4 bg-white border border-dashed border-[var(--color-line-strong)] rounded-xl text-[16px] font-semibold text-[var(--color-ink-soft)]">
                        Elija un municipio para empezar.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
