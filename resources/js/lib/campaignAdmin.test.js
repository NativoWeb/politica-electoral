import { describe, it, expect } from 'vitest';
import { blockedParentIds, nextSlug, preselectedCampaign, slugify, usersUrlFor } from './campaignAdmin';

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
