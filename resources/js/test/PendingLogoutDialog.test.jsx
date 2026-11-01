import { describe, it, expect, afterEach } from 'vitest';
import { act, fireEvent, screen } from '@testing-library/react';
import { askLogoutWithPending, pendingLogoutMessage } from '@/Components/PendingLogoutDialog';

// Wrapped in an object: an async function returning the promise would wait for the dialog to close.
async function open(count) {
    let answer;
    await act(async () => { answer = askLogoutWithPending(count); });
    return { answer };
}

describe('Diálogo de cierre de sesión con registros sin enviar', () => {
    afterEach(() => { document.body.innerHTML = ''; });

    it('explica cuántos se pierden y por defecto se queda (foco en "Quedarme")', async () => {
        const { answer } = await open(3);
        const dialog = await screen.findByRole('alertdialog', { name: 'Hay registros sin enviar' });
        expect(dialog).toHaveTextContent('Tiene 3 registros sin enviar. Si cierra sesión ahora se perderán.');
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Quedarme y enviar después' }));

        await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Quedarme y enviar después' })); });
        expect(await answer).toBe(false);
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('Escape también se queda; "Cerrar sesión de todos modos" confirma', async () => {
        const { answer: first } = await open(1);
        await act(async () => { fireEvent.keyDown(screen.getByRole('alertdialog'), { key: 'Escape' }); });
        expect(await first).toBe(false);

        const { answer: second } = await open(1);
        await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión de todos modos' })); });
        expect(await second).toBe(true);
        expect(document.body.children).toHaveLength(0);
    });

    it('usa singular y avisa cuando no se pudo contar', () => {
        expect(pendingLogoutMessage(1)).toBe('Tiene 1 registro sin enviar. Si cierra sesión ahora se perderán.');
        expect(pendingLogoutMessage(null)).toMatch(/No se pudo comprobar/);
    });
});
