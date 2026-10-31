import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: vi.fn() },
}));

vi.mock('@/Layouts/AppLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import AuditLog from '@/Pages/Admin/AuditLog';

function logsWithPages(lastPage) {
    const links = [{ url: null, label: '&laquo; Anterior', active: false }];
    for (let i = 1; i <= lastPage; i++) links.push({ url: `/admin/auditoria?page=${i}`, label: String(i), active: i === 1 });
    links.push({ url: '/admin/auditoria?page=2', label: 'Siguiente &raquo;', active: false });

    return {
        data: [{ id: 'a1', created_at: '2026-10-30T10:00:00Z', user: { name: 'Ana' }, action: 'POST votantes.store', entity_type: 'votante', entity_id: null, ip_address: '1.1.1.1', metadata: {} }],
        total: lastPage * 50, from: 1, to: 50, last_page: lastPage, links,
    };
}

describe('AuditLog on a phone', () => {
    it('wraps the page links instead of widening the whole page', () => {
        render(<AuditLog logs={logsWithPages(12)} filters={{}} users={[]} entityTypes={[]} />);

        const pageLinks = screen.getByRole('button', { name: '1' }).parentElement;
        expect(pageLinks.className).toMatch(/\bflex-wrap\b/);
        expect(pageLinks.className).toMatch(/\bmin-w-0\b/);
        expect(pageLinks.parentElement.className).toMatch(/\bflex-wrap\b/);
    });

    it('keeps the wide table scrolling inside its own container', () => {
        render(<AuditLog logs={logsWithPages(1)} filters={{}} users={[]} entityTypes={[]} />);

        expect(screen.getByRole('table').parentElement.className).toMatch(/\boverflow-x-auto\b/);
        expect(screen.queryByRole('button', { name: '1' })).not.toBeInTheDocument();
        expect(screen.getByText('POST votantes.store')).toBeInTheDocument();
    });
});
