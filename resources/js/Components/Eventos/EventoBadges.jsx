import { EVENT_STATUS, LINK_STATUS } from '@/lib/eventos';

const badgeCls = 'inline-flex items-center px-2.5 py-1 rounded-full border text-[12px] font-bold leading-tight whitespace-nowrap';

export function StatusBadge({ status }) {
    const meta = EVENT_STATUS[status] ?? { label: status, cls: 'bg-gray-100 border-gray-300 text-gray-700' };
    return <span className={`${badgeCls} ${meta.cls}`}>{meta.label}</span>;
}

export function LinkStatusBadge({ status }) {
    const meta = LINK_STATUS[status] ?? { label: status, cls: 'bg-gray-100 border-gray-300 text-gray-700' };
    return <span className={`${badgeCls} ${meta.cls}`}>{meta.label}</span>;
}
