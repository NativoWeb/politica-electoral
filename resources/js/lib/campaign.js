import { usePage } from '@inertiajs/react';

// Campaign context for the shared props: auth.user.campaign is the user's own campaign and visibleCampaigns
// lists every campaign they can see, only when there is more than one (subcampaigns).
export function campaignContext(user, visibleCampaigns) {
    const own = user?.campaign ?? null;
    const list = Array.isArray(visibleCampaigns) ? visibleCampaigns : [];
    const names = Object.fromEntries(list.map(c => [c.id, c.name]));

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

    return campaignContext(props.auth?.user, props.visibleCampaigns);
}

export function readOnlyMessage(campaignName) {
    const owner = campaignName ? `de la campaña ${campaignName}` : 'de otra campaña';

    return `Este registro es ${owner}. Puedes verlo, pero solo su equipo puede modificarlo.`;
}

// A 403 for a record of another campaign reads as the read-only notice; any other 403 keeps the server's reason.
export function forbiddenMessage(serverMessage, campaignName) {
    if (!serverMessage || /otra campa/i.test(serverMessage)) {
        return readOnlyMessage(campaignName);
    }

    return serverMessage;
}
