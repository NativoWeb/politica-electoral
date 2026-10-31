import { describe, it, expect } from 'vitest';
import {
    blockedParentIds, buildCampaignTree, campaignInitials, campaignSummary, flattenCampaignTree, nextSlug, preselectedCampaign,
    slugify, usersUrlFor,
} from './campaignAdmin';

describe('slugify', () => {
    it('turns a campaign name into the slug Laravel would generate', () => {
        expect(slugify('Alejandro Cárdenas')).toBe('alejandro-cardenas');
        expect(slugify('  Campaña  Año 2027! ')).toBe('campana-ano-2027');
        expect(slugify('Centro_Democrático -- Santander')).toBe('centro-democratico-santander');
    });

    it('returns an empty slug for empty or symbol-only names', () => {
        expect(slugify('')).toBe('');
        expect(slugify(null)).toBe('');
        expect(slugify('¡¿?!')).toBe('');
    });
});

describe('nextSlug', () => {
    it('follows the name until the user edits the slug', () => {
        expect(nextSlug({ name: 'Prueba Campaña', slug: '', slugTouched: false })).toBe('prueba-campana');
        expect(nextSlug({ name: 'Prueba Campaña', slug: 'mi-slug', slugTouched: true })).toBe('mi-slug');
    });
});

describe('blockedParentIds', () => {
    const campaigns = [
        { id: 'cd', parentId: null },
        { id: 'a', parentId: 'cd' },
        { id: 'a1', parentId: 'a' },
        { id: 'a11', parentId: 'a1' },
        { id: 'b', parentId: 'cd' },
        { id: 'y', parentId: null },
    ];

    it('blocks the campaign itself and every campaign below it', () => {
        expect([...blockedParentIds(campaigns, 'a')].sort()).toEqual(['a', 'a1', 'a11']);
        expect([...blockedParentIds(campaigns, 'cd')].sort()).toEqual(['a', 'a1', 'a11', 'b', 'cd']);
    });

    it('blocks nothing for a new campaign', () => {
        expect(blockedParentIds(campaigns, null).size).toBe(0);
    });
});

describe('Users page preselection', () => {
    const campaigns = [{ id: 'cd' }, { id: 'x' }];

    it('builds the link and reads it back', () => {
        expect(usersUrlFor('x')).toBe('/admin/usuarios?campana=x');
        expect(preselectedCampaign(usersUrlFor('x'), campaigns)).toBe('x');
    });

    it('ignores unknown or missing campaigns', () => {
        expect(preselectedCampaign('/admin/usuarios?campana=otra', campaigns)).toBeNull();
        expect(preselectedCampaign('/admin/usuarios', campaigns)).toBeNull();
        expect(preselectedCampaign(undefined, campaigns)).toBeNull();
    });
});

describe('buildCampaignTree', () => {
    const campaigns = [
        { id: 'z', name: 'Zeta independiente', parentId: null },
        { id: 'b', name: 'Beta', parentId: 'cd' },
        { id: 'cd', name: 'Centro Democrático', parentId: null },
        { id: 'a', name: 'Álvaro', parentId: 'cd' },
        { id: 'a2', name: 'Ñame', parentId: 'a' },
        { id: 'a1', name: 'Barrios', parentId: 'a' },
        { id: 'm', name: 'Alfa independiente', parentId: null },
    ];
    const names = nodes => nodes.map(n => n.name);

    it('puts the default campaign first and the other roots by name', () => {
        expect(names(buildCampaignTree(campaigns, 'cd'))).toEqual(['Centro Democrático', 'Alfa independiente', 'Zeta independiente']);
    });

    it('nests children under their parent, sorted by name at every level', () => {
        const [cd] = buildCampaignTree(campaigns, 'cd');
        expect(names(cd.children)).toEqual(['Álvaro', 'Beta']);
        expect(names(cd.children[0].children)).toEqual(['Barrios', 'Ñame']);
        expect(cd.children[1].children).toEqual([]);
    });

    it('keeps a campaign whose parent is missing as a root and survives a cycle', () => {
        const tree = buildCampaignTree([
            { id: 'cd', name: 'CD', parentId: null },
            { id: 'o', name: 'Huérfana', parentId: 'no-existe' },
            { id: 'x', name: 'X', parentId: 'y' },
            { id: 'y', name: 'Y', parentId: 'x' },
        ], 'cd');
        expect(names(tree)).toEqual(['CD', 'Huérfana']);
    });

    it('flattens in display order with the depth of each campaign', () => {
        const flat = flattenCampaignTree(buildCampaignTree(campaigns, 'cd'));
        expect(flat.map(({ campaign, depth }) => `${depth}:${campaign.id}`)).toEqual(['0:cd', '1:a', '2:a1', '2:a2', '1:b', '0:m', '0:z']);
    });
});

describe('campaign row texts', () => {
    it('takes two initials from the name, skipping symbols', () => {
        expect(campaignInitials('Centro Democrático')).toBe('CD');
        expect(campaignInitials('Alejandro Cárdenas — Asamblea')).toBe('AC');
        expect(campaignInitials('ñandú')).toBe('Ñ');
        expect(campaignInitials('')).toBe('');
    });

    it('summarises users, líderes and WhatsApp with es-CO thousands', () => {
        expect(campaignSummary({ usersCount: 1, lideresCount: 12345, whatsappEnabled: true })).toBe('1 usuario · 12.345 líderes · WhatsApp sí');
        expect(campaignSummary({ usersCount: 0, lideresCount: 1, whatsappEnabled: false })).toBe('0 usuarios · 1 líder · WhatsApp no');
    });
});
