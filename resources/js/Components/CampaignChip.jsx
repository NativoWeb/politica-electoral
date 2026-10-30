import { readOnlyMessage, useCampaign } from '@/lib/campaign';

// Marks a record that belongs to another visible campaign (a subcampaign). Nothing for the user's own records.
export default function CampaignChip({ campaignId, className = '' }) {
    const { isOther, nameOf } = useCampaign();
    if (!isOther(campaignId)) return null;

    const name = nameOf(campaignId) || 'otra';

    return (
        <span
            className={`inline-flex items-center max-w-[180px] px-2 py-0.5 rounded-full border border-[var(--color-line-strong)] bg-[var(--color-bg)] text-[var(--color-ink-soft)] text-[11px] font-semibold leading-tight flex-shrink-0 ${className}`}
            title={`Registro de la campaña ${name}`}
        >
            <span className="truncate">Campaña {name}</span>
        </span>
    );
}

export function ReadOnlyCampaignNotice({ campaignName, className = '' }) {
    return (
        <div role="note" className={`flex items-start gap-3 px-4 py-3 rounded-xl bg-blue-50 border border-blue-200 text-[14px] font-semibold text-[var(--color-primary)] ${className}`}>
            <svg className="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
            <span>{readOnlyMessage(campaignName)}</span>
        </div>
    );
}
