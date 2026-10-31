// Same result as Laravel's Str::slug for the names we use (accents, ñ, spaces and symbols); the server
// slugs the value again, so this only previews it.
export function slugify(text) {
    return String(text ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9\s_-]/g, '')
        .trim()
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

// The slug follows the name until the user writes their own one.
export function nextSlug({ name, slug, slugTouched }) {
    return slugTouched ? slug : slugify(name);
}

// A campaign cannot hang from itself nor from any campaign below it.
export function blockedParentIds(campaigns, campaignId) {
    if (!campaignId) return new Set();

    const blocked = new Set([campaignId]);
    let added = true;
    while (added) {
        added = false;
        for (const c of campaigns) {
            if (c.parentId && blocked.has(c.parentId) && !blocked.has(c.id)) {
                blocked.add(c.id);
                added = true;
            }
        }
    }

    return blocked;
}

export function usersUrlFor(campaignId) {
    return `/admin/usuarios?${new URLSearchParams({ campana: campaignId }).toString()}`;
}

// ?campana= opens the Users page with the new-user form for that campaign, if the superadmin can pick it.
export function preselectedCampaign(url, campaigns) {
    const query = String(url ?? '').split('?')[1] ?? '';
    const id = new URLSearchParams(query).get('campana');

    return id && campaigns.some(c => c.id === id) ? id : null;
}
