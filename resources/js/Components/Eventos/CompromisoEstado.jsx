import { ESTADOS, ESTADO_CLASSES } from '@/lib/actas';

const cls = 'inline-flex items-center px-2.5 py-1 rounded-full border text-[12px] font-bold leading-tight whitespace-nowrap';

export default function CompromisoEstado({ estado, vencido }) {
    return (
        <span className="inline-flex flex-wrap gap-1">
            <span className={`${cls} ${ESTADO_CLASSES[estado] ?? 'bg-gray-100 border-gray-300 text-gray-700'}`}>{ESTADOS[estado] ?? estado}</span>
            {vencido && <span className={`${cls} bg-red-50 border-red-300 text-red-700`}>Vencido</span>}
        </span>
    );
}
