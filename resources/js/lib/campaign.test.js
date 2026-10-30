import { describe, it, expect } from 'vitest';
import { campaignContext, forbiddenMessage, readOnlyMessage } from './campaign';

const CD = { id: 'cd', name: 'Centro Democrático', whatsapp_enabled: true, is_subcampaign: false };

describe('campaignContext', () => {
    it('treats a user who sees a single campaign as having nothing to label or filter', () => {
        const ctx = campaignContext({ campaign: CD }, []);

        expect(ctx.hasMultiple).toBe(false);
        expect(ctx.isOther('cd')).toBe(false);
        expect(ctx.nameOf('cd')).toBe('Centro Democrático');
    });

    it('marks records of a visible subcampaign as another campaign', () => {
        const ctx = campaignContext({ campaign: CD }, [{ id: 'cd', name: 'Centro Democrático' }, { id: 'x', name: 'Alejandro' }]);

        expect(ctx.hasMultiple).toBe(true);
        expect(ctx.isOther('x')).toBe(true);
        expect(ctx.nameOf('x')).toBe('Alejandro');
        expect(ctx.isOther('cd')).toBe(false);
    });

    it('never flags a record without campaign (shared electoral person) or a user without campaign', () => {
        expect(campaignContext({ campaign: CD }, []).isOther(null)).toBe(false);
        expect(campaignContext(null, []).isOther('x')).toBe(false);
        expect(campaignContext({ campaign: null }, undefined).hasMultiple).toBe(false);
    });
});

describe('messages', () => {
    it('names the owning campaign in the read-only notice', () => {
        expect(readOnlyMessage('Alejandro')).toBe('Este registro es de la campaña Alejandro. Puedes verlo, pero solo su equipo puede modificarlo.');
        expect(readOnlyMessage(null)).toBe('Este registro es de otra campaña. Puedes verlo, pero solo su equipo puede modificarlo.');
    });

    it('turns the server 403 of another campaign into the read-only notice', () => {
        expect(forbiddenMessage('Este registro pertenece a otra campaña: solo se puede consultar.', 'Alejandro'))
            .toBe(readOnlyMessage('Alejandro'));
        expect(forbiddenMessage(undefined, null)).toBe(readOnlyMessage(null));
    });

    it('keeps any other 403 reason from the server', () => {
        expect(forbiddenMessage('Este registro está fuera de tu territorio.', 'Alejandro')).toBe('Este registro está fuera de tu territorio.');
    });
});
