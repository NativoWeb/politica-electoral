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

const byName = (a, b) => a.name.localeCompare(b.name, 'es', { sensitivity: 'base' });

// Roots by name with the default campaign first, children by name. A campaign whose parent is not in the
// list is shown as a root so it never disappears.
export function buildCampaignTree(campaigns, defaultId) {
    const ids = new Set(campaigns.map(c => c.id));
    const children = new Map();
    const roots = [];
    for (const c of campaigns) {
        if (c.parentId && ids.has(c.parentId) && c.parentId !== c.id) {
            if (!children.has(c.parentId)) children.set(c.parentId, []);
            children.get(c.parentId).push(c);
        } else {
            roots.push(c);
        }
    }

    const seen = new Set();
    const toNode = c => {
        seen.add(c.id);
        const kids = (children.get(c.id) ?? []).filter(k => !seen.has(k.id)).sort(byName);
        return { ...c, children: kids.map(toNode) };
    };

    return roots
        .sort((a, b) => (a.id === defaultId ? -1 : b.id === defaultId ? 1 : byName(a, b)))
        .map(toNode);
}

// The tree in display order, each campaign with its depth (0 for roots).
export function flattenCampaignTree(tree, depth = 0) {
    return tree.flatMap(node => [{ campaign: node, depth }, ...flattenCampaignTree(node.children, depth + 1)]);
}

export function campaignInitials(name) {
    return String(name ?? '').split(/\s+/).filter(w => /[\p{L}\p{N}]/u.test(w)).map(w => w.match(/[\p{L}\p{N}]/u)[0])
        .join('').slice(0, 2).toUpperCase();
}

const plural = (n, one, many) => `${new Intl.NumberFormat('es-CO').format(n)} ${n === 1 ? one : many}`;

export function campaignSummary(c) {
    return [
        plural(c.usersCount ?? 0, 'usuario', 'usuarios'),
        plural(c.lideresCount ?? 0, 'líder', 'líderes'),
        `WhatsApp ${c.whatsappEnabled ? 'sí' : 'no'}`,
    ].join(' · ');
}
