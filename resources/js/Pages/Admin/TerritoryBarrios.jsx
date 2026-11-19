import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import BarriosComunasPanel from '@/Components/Territorio/BarriosComunasPanel';
import { BackToAdmin } from '@/Components/Territorio/AdminCard';

export default function TerritoryBarrios() {
    return (
        <AppLayout title="Barrios y comunas" breadcrumb={[{ label: 'ADMIN', href: '/admin/territorio' }, { label: 'BARRIOS Y COMUNAS' }]}>
            <Head title="Barrios y comunas — Administración" />

            <div className="bg-[var(--color-primary)] text-white px-4 lg:px-6 pt-1 pb-3 lg:pb-4">
                <BackToAdmin />
                <h1 className="text-[18px] lg:text-[22px] font-extrabold">BARRIOS Y COMUNAS</h1>
                <p className="text-[12px] text-white/60 mt-0.5">Asignar a su comuna los barrios que el sistema no pudo ubicar</p>
            </div>

            <div className="p-4">
                <BarriosComunasPanel />
            </div>
        </AppLayout>
    );
}
