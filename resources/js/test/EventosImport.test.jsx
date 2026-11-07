// Eventos especiales phase 3: attendees from Excel (helpers, steps, preview rows, page and the Show entry point).
import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';

const mockRouter = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), patch: vi.fn(), get: vi.fn(), delete: vi.fn() }));
const mockPage = { props: {}, url: '/eventos' };

vi.mock('@/Components/OfflineManager', () => ({ OfflineIndicator: () => null }));
vi.mock('@/lib/logout', () => ({ logout: vi.fn() }));
vi.mock('@/lib/swSession', () => ({ precacheExtraPages: vi.fn(), precacheExtraPagesOnce: vi.fn() }));
vi.mock('qrcode', () => ({ default: { toDataURL: () => Promise.resolve('data:image/png;base64,AAAA') } }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, prefetch: _p, preserveScroll: _s, preserveState: _st, only: _o, ...rest }) => <a href={href} {...rest}>{children}</a>,
    usePage: () => mockPage,
    useForm: () => ({ data: {}, setData: vi.fn(), errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn(), transform: vi.fn() }),
    router: { ...mockRouter, on: () => () => {}, reload: vi.fn() },
}));

import { sourceLabel } from '@/lib/eventos';
import {
    changedValues, columnOptions, confirmBlocker, leftOutSummary, confirmDialogMessage, confirmDialogTitle, confirmSummary, currentStep, importErrorMessage, mappingProblem, rowStatusKey,
    setMapping, uploadProblem, ROW_FILTERS,
} from '@/lib/eventosImport';
import ImportRowCard from '@/Components/Eventos/ImportRowCard';
import { ImportConfirm, ImportMapping, ImportStepper, ImportUpload } from '@/Components/Eventos/ImportSteps';
import Importar from '@/Pages/Eventos/Importar';
import Show from '@/Pages/Eventos/Show';

const CD = { id: 'cd', name: 'Centro Democrático' };
const MANAGER = { id: 'u1', name: 'Gestor', permissions: ['manage.eventos'], canWrite: false, canManageEventos: true, campaign: CD };
const EVENTO = { id: 'ev1', nombre: 'Gira Girón', status: 'cerrado', starts_at: '2026-11-05T18:00:00-05:00', municipio: 'Girón', campaign: { id: 'cd', nombre: 'CD' }, acepta_cargas: true };
const CAMPOS = [
    { key: 'nombres', label: 'Nombres' }, { key: 'apellidos', label: 'Apellidos' }, { key: 'nombre_completo', label: 'Nombre completo' },
    { key: 'cedula', label: 'Cédula' }, { key: 'celular', label: 'Celular' },
];
const COUNTS = { total: 50, validas: 42, en_plataforma: 10, nuevas: 32, errores: 5, duplicadas: 3, en_evento: 0, a_importar: 42, importadas: 0, omitidas: 1 };

function row(overrides = {}) {
    return {
        id: 'r1', fila: 7, status: 'valida', decision: 'crear', en_plataforma: false, importada: false, corregida: false,
        valores: { nombres: 'Ana', apellidos: 'Pérez', cedula: '1098765432', celular: '3001234567', autoriza: 'SÍ' },
        errores: {},
        ...overrides,
    };
}

beforeEach(() => {
    Object.values(mockRouter).forEach(fn => fn.mockReset());
    mockPage.props = { auth: { user: MANAGER }, errors: {}, flash: {}, visibleCampaigns: [] };
});
afterEach(() => cleanup());

describe('eventosImport helpers', () => {
    it('finds the step from the import status', () => {
        expect(currentStep(null)).toBe('archivo');
        expect(currentStep({ status: 'subido' })).toBe('columnas');
        expect(currentStep({ status: 'revisado' })).toBe('revisar');
        expect(currentStep({ status: 'confirmado' })).toBe('confirmar');
    });

    it('explains an incomplete or repeated mapping like the server', () => {
        expect(mappingProblem({})).toMatch(/nombres y la de apellidos/);
        expect(mappingProblem({ nombre_completo: 1 })).toMatch('Elija la columna de la cédula o la del teléfono');
        expect(mappingProblem({ cedula: 0, nombres: 1 })).toMatch(/nombres y la de apellidos/);
        expect(mappingProblem({ cedula: 0, nombre_completo: 1, celular: 1 })).toMatch(/Una misma columna/);
        expect(mappingProblem({ cedula: 0, nombre_completo: 1 })).toBeNull();
        expect(mappingProblem({ celular: 3, nombre_completo: 1 })).toBeNull();
        expect(mappingProblem({ cedula: 0, nombres: 1, apellidos: 2, celular: 3, barrio: null })).toBeNull();
        expect(setMapping({ cedula: 0 }, 'cedula', '')).toEqual({ cedula: null });
        expect(setMapping({}, 'celular', '3')).toEqual({ celular: 3 });
    });

    it('shows examples next to each column', () => {
        const options = columnOptions(['CC', 'Nombre completo'], [{ 0: '1098765432', 1: 'Ana Pérez' }, { 0: '63525141' }]);
        expect(options[0]).toEqual({ value: 0, label: 'CC — ej.: 1098765432, 63525141' });
        expect(options[1].label).toBe('Nombre completo — ej.: Ana Pérez');
    });

    it('sends only the corrected cells, empty as null', () => {
        expect(changedValues({ cedula: '12AB', nombres: 'Ana' }, { cedula: '1098', nombres: 'Ana' })).toEqual({ cedula: '1098' });
        expect(changedValues({ barrio: 'Centro' }, { barrio: '' })).toEqual({ barrio: null });
        expect(changedValues({ autoriza: 'NO' }, { autoriza: 'SÍ' })).toEqual({});
    });

    it('imports every valid person, without any authorization, and says why the rest are left out', () => {
        expect(confirmSummary({ a_importar: 42 })).toBe('Se registrarán 42 personas en el evento. Las nuevas quedan pendientes de aprobar antes de pasar a Votantes.');
        expect(confirmSummary({ a_importar: 1 })).toBe('Se registrará 1 persona en el evento. Si es nueva, queda pendiente de aprobar antes de pasar a Votantes.');
        expect(confirmSummary({ a_importar: 3, sin_autorizacion: 3, sin_ficha: 1 })).toMatch('2 quedan en el historial de su ficha (sin cambiarla) y el resto pendiente de aprobar');
        expect(confirmSummary({ a_importar: 3, sin_autorizacion: 3 })).not.toMatch(/autorizaci/);
        expect(confirmDialogMessage({ a_importar: 1 })).toBe('Se importará 1 persona. Esta acción no se puede deshacer.');
        expect(confirmDialogTitle({ a_importar: 1 })).toBe('¿Importar a esta persona?');
        expect(confirmDialogTitle({ a_importar: 42 })).toBe('¿Importar estas personas?');
        expect(leftOutSummary({ errores: 2, duplicadas: 1, omitidas: 0, no_autorizaron: 3, en_evento: 1 }))
            .toBe('Quedan fuera: 2 con error, 1 repetida en el archivo, 1 que ya está en el evento.');
        expect(leftOutSummary({ omitidas: 1 })).toBe('Quedan fuera: 1 marcada «No importar».');
        expect(leftOutSummary({ errores: 0 })).toBeNull();
        expect(confirmSummary({ a_importar: 0 })).toMatch('No hay filas para importar');
        expect(confirmDialogMessage({ a_importar: 42 })).toBe('Se importarán 42 personas. Esta acción no se puede deshacer.');
        expect(confirmBlocker({ counts: { a_importar: 0 } })).toBe('No hay filas para importar.');
        expect(confirmBlocker({ counts: { a_importar: 3 } })).toBeNull();
        expect(ROW_FILTERS.map(f => f.label)).toEqual(expect.arrayContaining(['No importar', 'Nuevas por aprobar']));
        expect(ROW_FILTERS.map(f => f.label)).not.toContain('No autorizaron');
    });

    it('checks the file before uploading', () => {
        expect(uploadProblem(null)).toMatch('Elija el archivo');
        expect(uploadProblem({ name: 'foto.pdf', size: 10 })).toMatch('.xlsx o .xls');
        expect(uploadProblem({ name: 'grande.XLSX', size: 6 * 1024 * 1024 }, { mb: 5 })).toMatch('más de 5 MB');
        expect(uploadProblem({ name: 'lista.csv', size: 100 })).toBeNull();
    });

    it('labels rows already in the platform and joins server errors', () => {
        expect(rowStatusKey({ status: 'valida', en_plataforma: true })).toBe('plataforma');
        expect(rowStatusKey({ status: 'raro' })).toBe('pendiente');
        expect(importErrorMessage({ archivo: 'No se pudo leer el archivo.' })).toBe('No se pudo leer el archivo.');
        expect(importErrorMessage(undefined)).toBe('');
    });
});

describe('Import steps', () => {
    it('marks the current step', () => {
        render(<ImportStepper step="revisar" />);
        expect(screen.getByText('Revisar').closest('li').getAttribute('aria-current')).toBe('step');
    });

    it('offers the template and does not upload a wrong file', () => {
        const { container } = render(<ImportUpload eventId="ev1" limits={{ filas: 2000, mb: 5 }} />);
        expect(screen.getByRole('link', { name: 'Descargar plantilla de Excel' }).getAttribute('href')).toBe('/eventos/ev1/plantilla.xlsx');
        fireEvent.click(screen.getByRole('button', { name: 'Subir archivo' }));
        expect(screen.getByRole('alert').textContent).toMatch('Elija el archivo');

        const input = container.querySelector('input[type=file]');
        fireEvent.change(input, { target: { files: [new File(['x'], 'foto.pdf')] } });
        fireEvent.click(screen.getByRole('button', { name: 'Subir archivo' }));
        expect(mockRouter.post).not.toHaveBeenCalled();

        const xlsx = new File(['x'], 'asistentes.xlsx');
        fireEvent.change(input, { target: { files: [xlsx] } });
        expect(screen.getByText('asistentes.xlsx')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Subir archivo' }));
        expect(mockRouter.post).toHaveBeenCalledWith('/eventos/ev1/importar', { archivo: xlsx }, expect.objectContaining({ forceFormData: true }));
    });

    it('lets the user fix the columns before reviewing', () => {
        const importacion = { id: 'imp1', status: 'subido', encabezados: ['CC', 'Persona', 'Tel', 'Firma'], columnas: { cedula: 0, celular: 2, nombres: null, autoriza: 3 } };
        render(<ImportMapping eventId="ev1" importacion={importacion} muestra={[{ 0: '1098765432', 1: 'Ana Pérez', 2: '3001234567', 3: 'SI' }]} campos={CAMPOS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Revisar las filas' }));
        expect(screen.getByRole('alert').textContent).toMatch('nombres y la de apellidos');
        expect(mockRouter.put).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Nombre completo'), { target: { value: '1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Revisar las filas' }));
        expect(mockRouter.put).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/columnas', { columnas: { cedula: 0, celular: 2, nombres: null, autoriza: 3, nombre_completo: 1 } }, expect.any(Object));
    });

    it('asks only for a confirmation dialog before importing: no declaration and no approved text', () => {
        render(<ImportConfirm eventId="ev1" importacion={{ id: 'imp1', counts: COUNTS }} />);
        expect(screen.getByText('Quedan fuera: 5 con error, 3 repetidas en el archivo, 1 marcada «No importar».')).toBeTruthy();
        expect(screen.queryByRole('checkbox')).toBeNull();
        expect(screen.queryByText(/planillas firmadas|autorizaci/)).toBeNull();
        const button = screen.getByRole('button', { name: 'Importar 42 personas' });
        expect(button.disabled).toBe(false);
        fireEvent.click(button);
        expect(mockRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText('Se importarán 42 personas. Esta acción no se puede deshacer.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'No, revisar otra vez' }));
        expect(mockRouter.post).not.toHaveBeenCalled();

        fireEvent.click(button);
        fireEvent.click(screen.getByRole('button', { name: 'Sí, importar' }));
        expect(mockRouter.post).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/confirmar', {}, expect.any(Object));
    });

    it('cannot confirm when nothing is importable', () => {
        render(<ImportConfirm eventId="ev1" importacion={{ id: 'imp1', counts: { a_importar: 0 } }} />);
        expect(screen.getByRole('button', { name: 'Importar 0 personas' }).disabled).toBe(true);
    });

    it('rings the file picker when it has the keyboard focus', () => {
        const { container } = render(<ImportUpload eventId="ev1" limits={{ filas: 2000, mb: 5 }} />);
        const input = container.querySelector('input[type=file]');
        expect(input.closest('label').className).toMatch('focus-within:ring-4');
    });
});

describe('ImportRowCard', () => {
    const renderCard = (r, editable = true) => render(<ul><ImportRowCard row={r} eventId="ev1" importId="imp1" editable={editable} /></ul>);

    it('highlights the wrong cell with its message and cannot be imported', () => {
        const { container } = renderCard(row({ status: 'error', decision: 'omitir', valores: { nombres: 'Ana', apellidos: 'Pérez', cedula: '12AB', celular: '3001234567' }, errores: { cedula: 'La cédula debe tener solo números (entre 5 y 10 dígitos).' } }));
        expect(screen.getByText('Con error')).toBeTruthy();
        const cell = container.querySelector('[data-error="cedula"]');
        expect(cell.textContent).toMatch('12AB');
        expect(cell.textContent).toMatch('entre 5 y 10 dígitos');
        expect(screen.queryByRole('checkbox', { name: 'Importar' })).toBeNull();
    });

    it('corrects a cell and sends only the change', () => {
        renderCard(row({ status: 'error', errores: { cedula: 'Mal' }, valores: { nombres: 'Ana', apellidos: 'Pérez', cedula: '12AB', celular: '3001234567' } }));
        fireEvent.click(screen.getByRole('button', { name: 'Corregir esta fila' }));
        const input = screen.getByLabelText('Cédula');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        fireEvent.change(input, { target: { value: '1098765432' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar y revisar' }));
        expect(mockRouter.patch).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/filas/r1', { cedula: '1098765432' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('toggles whether a valid row is imported', () => {
        renderCard(row());
        fireEvent.click(screen.getByRole('checkbox', { name: 'Importar' }));
        expect(mockRouter.patch).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/filas/r1', { decision: 'omitir' }, expect.any(Object));
        cleanup();
        renderCard(row({ decision: 'omitir' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Importar' }));
        expect(mockRouter.patch).toHaveBeenLastCalledWith('/eventos/ev1/importar/imp1/filas/r1', { decision: 'importar' }, expect.any(Object));
    });

    it('a new person of a list is importable and stays pending; "Autorizó" is never shown', () => {
        renderCard(row({ status: 'sin_ficha', decision: 'crear', sin_autorizacion: true, valores: { nombres: 'Ana', apellidos: 'Pérez', cedula: '1', celular: '3', autoriza: 'NO' } }));
        expect(screen.getByText('Nueva: pendiente de aprobar')).toBeTruthy();
        expect(screen.getByText(/pendiente de aprobar para pasar a Votantes/)).toBeTruthy();
        expect(screen.getByRole('checkbox', { name: 'Importar' }).checked).toBe(true);
        expect(screen.queryByText('Autorizó')).toBeNull();
        cleanup();

        renderCard(row({ status: 'error', errores: { cedula: 'Mal' } }));
        fireEvent.click(screen.getByRole('button', { name: 'Corregir esta fila' }));
        expect(screen.queryByLabelText('Autorizó')).toBeNull();
        expect(screen.queryByText(/autoriz/i)).toBeNull();
    });

    it('explains duplicates and rows already in the platform; read-only after confirming', () => {
        renderCard(row({ status: 'duplicada', decision: 'omitir' }));
        expect(screen.getByText(/ya aparece en una fila anterior/)).toBeTruthy();
        cleanup();
        renderCard(row({ en_plataforma: true, decision: 'vincular', importada: true }), false);
        expect(screen.getByText('Ya estaba en la plataforma')).toBeTruthy();
        expect(screen.getByText('Importada')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Corregir esta fila' })).toBeNull();
        expect(screen.queryByRole('checkbox')).toBeNull();
    });
});

describe('Importar page', () => {
    const PAGE = { evento: EVENTO, muestra: [], filters: { estado: null }, campos: CAMPOS, limites: { filas: 2000, mb: 5 }, recientes: [] };

    it('starts with the template and the upload, and lists previous loads', () => {
        render(<Importar {...PAGE} importacion={null} filas={null} recientes={[{ id: 'i0', nombre: 'viejo.xlsx', status: 'confirmado', filas: 20, importadas: 18, created_at: '2026-11-01T10:00:00-05:00', created_by: 'Gestor' }]} />);
        expect(screen.getByRole('heading', { name: 'Cargar asistentes desde Excel' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Subir archivo' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'viejo.xlsx' }).getAttribute('href')).toBe('/eventos/ev1/importar/i0');
        expect(screen.getByText(/18 importadas/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'viejo.xlsx' }).className).toMatch('min-h-[44px]');
    });

    it('allows the upload with no mention of an authorization text', () => {
        render(<Importar {...PAGE} importacion={null} filas={null} />);
        expect(screen.getByRole('button', { name: 'Subir archivo' }).disabled).toBe(false);
        expect(screen.queryByText(/autorizaci/i)).toBeNull();
    });

    it('a list confirms without any declaration: new people stay pending of approval', () => {
        const counts = { total: 3, validas: 1, en_plataforma: 1, sugeridas: 1, sin_ficha: 1, sin_autorizacion: 3, a_importar: 2, a_importar_con_autorizacion: 0, errores: 0, duplicadas: 0, en_evento: 0, importadas: 0, omitidas: 0 };
        const filas = { data: [row({ status: 'sugerida', decision: 'omitir', sin_autorizacion: true, sugerencia: { id: 'l1', nombre: 'Ana María Pérez', municipio: 'Girón', cargo: 'Edil', estado: null } })], current_page: 1, last_page: 1, total: 1, links: [] };
        render(<Importar {...PAGE} importacion={{ id: 'imp1', nombre: 'a.xlsx', status: 'revisado', filas: 3, fila_encabezados: 1, counts, sin_autorizacion: true, hojas: [] }} filas={filas} />);
        expect(screen.getAllByText(/queda en el evento pendiente de aprobar para pasar a Votantes/).length).toBeGreaterThan(0);
        expect(screen.queryByText(/autorizaci|planillas firmadas/i)).toBeNull();
        expect(screen.getByText('Nuevas (por aprobar)').nextSibling.textContent).toBe('1');
        const button = screen.getByRole('button', { name: 'Importar 2 personas' });
        expect(button.disabled).toBe(false);
        expect(screen.getByText('¿Es esta persona?', { selector: 'p' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Sí, es esta persona' }));
        expect(mockRouter.patch).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/filas/r1', { sugerencia: 'confirmar' }, expect.anything());
        expect(screen.getByRole('button', { name: 'No, es otra persona' }).disabled).toBe(true);
        fireEvent.click(button);
        fireEvent.click(screen.getByRole('button', { name: 'Sí, importar' }));
        expect(mockRouter.post).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/confirmar', {}, expect.anything());
    });

    it('a workbook with several sheets lets the operator pick the sheet of the event', () => {
        const hojas = [{ nombre: 'GIRÓN', titulo: 'JORNADA DEL CONGRESO AL BARRIO 23.10.2026' }, { nombre: 'LEBRIJA', titulo: null }];
        render(<Importar {...PAGE} importacion={{ id: 'imp1', nombre: 'a.xlsx', status: 'subido', filas: 3, fila_encabezados: 5, encabezados: ['A'], columnas: {}, hoja: 'GIRÓN', hojas }} muestra={[]} filas={null} />);
        expect(screen.getByText('JORNADA DEL CONGRESO AL BARRIO 23.10.2026')).toBeTruthy();
        fireEvent.change(screen.getByRole('combobox', { name: 'Hoja del archivo' }), { target: { value: 'LEBRIJA' } });
        expect(mockRouter.put).toHaveBeenCalledWith('/eventos/ev1/importar/imp1/hoja', { hoja: 'LEBRIJA' }, expect.anything());
    });

    it('shows the counts, the filters and the confirm box while reviewing', () => {
        mockPage.props.flash = { error: 'Este archivo ya estaba subido: puede seguir donde lo dejó.' };
        const filas = { data: [row()], current_page: 1, last_page: 1, total: 1, links: [] };
        render(<Importar {...PAGE} importacion={{ id: 'imp1', nombre: 'a.xlsx', status: 'revisado', filas: 50, fila_encabezados: 1, counts: COUNTS }} filas={filas} />);
        expect(screen.getByText('Este archivo ya estaba subido: puede seguir donde lo dejó.')).toBeTruthy();
        const group = screen.getByRole('group', { name: 'Filtrar filas' });
        expect(within(group).getByRole('button', { name: 'Con error (5)' })).toBeTruthy();
        expect(within(group).queryByRole('button', { name: /No autorizaron/ })).toBeNull();
        expect(within(group).getByRole('button', { name: 'No importar (1)' })).toBeTruthy();
        expect(screen.queryByText(/Autorizó/)).toBeNull();
        fireEvent.click(within(group).getByRole('button', { name: 'Con error (5)' }));
        expect(mockRouter.get).toHaveBeenCalledWith('/eventos/ev1/importar/imp1', { estado: 'error' }, expect.objectContaining({ only: ['filas', 'filters'] }));
        expect(screen.getByRole('button', { name: 'Importar 42 personas' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Descartar y subir otro archivo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, descartar' }));
        expect(mockRouter.delete).toHaveBeenCalledWith('/eventos/ev1/importar/imp1');
    });

    it('shows the result after confirming, without the confirm box', () => {
        render(<Importar {...PAGE} importacion={{ id: 'imp1', nombre: 'a.xlsx', status: 'confirmado', filas: 50, counts: { ...COUNTS, importadas: 42 }, declaro_planillas: false, confirmed_at: '2026-11-01T10:00:00-05:00' }} filas={{ data: [], last_page: 1, links: [] }} />);
        expect(screen.getByText(/42 personas quedaron registradas/)).toBeTruthy();
        expect(screen.getByText(/Las nuevas están pendientes de aprobar/)).toBeTruthy();
        expect(screen.queryByText(/autorizaci/i)).toBeNull();
        expect(screen.queryByRole('button', { name: /Importar \d+/ })).toBeNull();
        expect(screen.getByRole('link', { name: 'Cargar otro archivo' }).getAttribute('href')).toBe('/eventos/ev1/importar');
    });
});

describe('Show: entry point and Excel attendees', () => {
    const SHOW_EVENTO = {
        id: 'ev1', nombre: 'Gira Girón', status: 'cerrado', tipo: { id: 't', nombre: 'Gira' }, starts_at: '2026-11-05T18:00:00-05:00', ends_at: null,
        sitio_encuentro: 'Parque', provincia: { id: 'p', nombre: 'Soto' }, municipio: { id: 'm', nombre: 'Girón' }, barrios: [],
        campaign: { id: 'cd', nombre: 'CD' }, editable: true, public_url: 'http://x/e/t', public_open: false, organizadores: [], figuras: [],
    };
    const ASISTENTE = { id: 'a1', nombres: 'Ana', apellidos: 'Pérez', cedula: '1', source: 'excel', link_status: 'pendiente', autoriza_datos: true, consentimiento_provisional: false, registered_by: { name: 'Gestor' }, created_at: '2026-11-01T10:00:00-05:00' };

    function renderShow(can) {
        return render(<Show evento={SHOW_EVENTO} asistentes={{ data: [ASISTENTE], current_page: 1, last_page: 1, total: 1, links: [] }} kpis={{}} filters={{}} consent={null} tipos={[]} provincias={[]} can={can} />);
    }

    it('has a "Cargar Excel" button for managers of the own campaign only', () => {
        renderShow({ manage: true, register: true, edit: true });
        expect(screen.getByRole('link', { name: 'Cargar Excel' }).getAttribute('href')).toBe('/eventos/ev1/importar');
        expect(screen.getByText(/Por Excel: Gestor/)).toBeTruthy();
        cleanup();
        renderShow({ manage: false, register: true, edit: false });
        expect(screen.queryByRole('link', { name: 'Cargar Excel' })).toBeNull();
    });

    it('labels where each attendee came from', () => {
        expect(sourceLabel({ source: 'qr' })).toBe('Por QR');
        expect(sourceLabel({ source: 'excel' })).toBe('Por Excel');
        expect(sourceLabel({ source: 'operador', registered_by: { name: 'Luis' } })).toBe('Operador: Luis');
    });
});
