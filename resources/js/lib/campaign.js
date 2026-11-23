import { usePage } from '@inertiajs/react';

// Campaign context for the shared props: auth.user.campaign is the user's own campaign and visibleCampaigns
// lists every campaign they can see, only when there is more than one (subcampaigns).
// grupoVista (superadmin only) lists every group of the header selector: it keeps the names when the selector narrows
// the visible groups to one.
export function campaignContext(user, visibleCampaigns, grupoVista = null) {
    const own = user?.campaign ?? null;
    const list = Array.isArray(visibleCampaigns) ? visibleCampaigns : [];
    const extra = Array.isArray(grupoVista?.options) ? grupoVista.options : [];
    const names = Object.fromEntries([...extra, ...list].map(c => [c.id, c.name]));

    return {
        own,
        list,
        hasMultiple: list.length > 1,
        nameOf: (id) => names[id] ?? (id && id === own?.id ? own.name : null),
        // A record of a campaign that is not the user's own: visible, but only its team may change it.
        isOther: (id) => Boolean(id && own?.id && id !== own.id),
    };
}

export function useCampaign() {
    const { props } = usePage();

    return campaignContext(props.auth?.user, props.visibleCampaigns, props.grupoVista);
}

// Superadmin viewing a group that is not its own: why creating is off (null when it is not).
export function useCreateBlocked() {
    return usePage().props.grupoVista?.createBlocked ?? null;
}

export function readOnlyMessage(campaignName) {
    const owner = campaignName ? `del grupo político ${campaignName}` : 'de otro grupo político';

    return `Este registro es ${owner}. Puedes verlo, pero solo su equipo puede modificarlo.`;
}

// A 403 for a record of another campaign reads as the read-only notice; any other 403 keeps the server's reason.
export function forbiddenMessage(serverMessage, campaignName) {
    if (!serverMessage || /otra campa|otro grupo pol/i.test(serverMessage)) {
        return readOnlyMessage(campaignName);
    }

    return serverMessage;
}
