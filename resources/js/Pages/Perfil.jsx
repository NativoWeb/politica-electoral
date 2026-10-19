import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import WhatsAppLink from '@/Components/WhatsAppLink';

export default function Perfil() {
    const { auth, agent } = usePage().props;
    const user = auth?.user;
    const initials = user?.name?.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase() || 'U';

    return (
        <AppLayout title="Mi perfil" breadcrumb={[{ label: 'SANTANDER', href: '/' }, { label: 'MI PERFIL' }]}>
            <Head title="Mi perfil" />

            <div className="max-w-2xl mx-auto px-4 py-5 lg:py-8 space-y-4">
                <header className="flex items-center gap-4">
                    <div className="w-14 h-14 rounded-full bg-[var(--color-primary)] text-white flex items-center justify-center text-[18px] font-bold flex-shrink-0" aria-hidden="true">
                        {initials}
                    </div>
                    <div className="min-w-0">
                        <h1 className="text-[20px] font-extrabold text-[var(--color-ink)] leading-tight truncate">{user?.name}</h1>
                        <p className="text-[14px] text-[var(--color-ink-faint)] truncate">{user?.email}</p>
                        {user?.role && <p className="text-[12px] font-semibold text-[var(--color-ink-soft)] mt-0.5">{user.role}</p>}
                    </div>
                </header>

                <section aria-labelledby="whatsapp-heading" className="bg-white rounded-2xl border border-[var(--color-line)] overflow-hidden">
                    <div className="flex items-center gap-3 px-5 py-4 border-b border-[var(--color-line)]">
                        <span className="w-9 h-9 rounded-lg bg-[#128C4A]/10 text-[#128C4A] flex items-center justify-center flex-shrink-0" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor" className="w-5 h-5">
                                <path d="M12 0a12 12 0 0 0-10.4 18L0 24l6.2-1.6A12 12 0 1 0 12 0Zm0 21.8a9.8 9.8 0 0 1-5-1.4l-.4-.2-3.7 1 1-3.7-.2-.4A9.8 9.8 0 1 1 12 21.8Z" />
                            </svg>
                        </span>
                        <div>
                            <h2 id="whatsapp-heading" className="text-[16px] font-bold text-[var(--color-ink)]">Vincular WhatsApp</h2>
                            <p className="text-[13px] text-[var(--color-ink-faint)]">Asistente del sistema por WhatsApp</p>
                        </div>
                    </div>
                    <div className="p-5">
                        <WhatsAppLink whatsapp={user?.whatsapp} agentNumber={agent?.whatsappNumber} />
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
