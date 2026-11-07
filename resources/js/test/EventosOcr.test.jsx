// Eventos especiales phase 5: printed sheets read with AI (helpers, upload, reading loop, review rows, page, Show entry).
import 'fake-indexeddb/auto';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor, act } from '@testing-library/react';

const mockRouter = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), patch: vi.fn(), get: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
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
    router: { ...mockRouter, on: () => () => {} },
}));

import { sourceLabel } from '@/lib/eventos';
import {
    HEIC_MESSAGE, OCR_ROW_FIELDS, fileProblem, filesProblem, isLowConfidence, ocrConfirmSummary, ocrStep, progressPercent, progressText, responseMessage,
} from '@/lib/eventosOcr';
import ImportRowCard from '@/Components/Eventos/ImportRowCard';
import { OcrReader, OcrUpload, PageViewer } from '@/Components/Eventos/OcrSteps';
import Planillas from '@/Pages/Eventos/Planillas';
import Show from '@/Pages/Eventos/Show';

const CD = { id: 'cd', name: 'Centro Democrático' };
const MANAGER = { id: 'u1', name: 'Gestor', permissions: ['manage.eventos'], canWrite: false, canManageEventos: true, campaign: CD };
const EVENTO = { id: 'ev1', nombre: 'Gira Girón', status: 'cerrado', starts_at: '2026-11-05T18:00:00-05:00', municipio: 'Girón', campaign: { id: 'cd', nombre: 'CD' }, acepta_cargas: true };
const LIMITS = { mb: 10, paginas: 20 };
const OCR = { listo: true, aviso: null, umbral: 0.85, limites: LIMITS };

function file(name, size = 1000, type = 'image/jpeg') {
    const f = new File(['x'], name, { type });
    Object.defineProperty(f, 'size', { value: size });
    return f;
}

function json(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

function ocrRow(overrides = {}) {
    return {
        id: 'r1', fila: 7, fila_pagina: 7, status: 'valida', decision: 'crear', en_plataforma: false, importada: false, corregida: false,
        valores: { nombres: 'ANA', apellidos: 'PÉREZ', cedula: '1098765432', celular: '3001234567', autoriza: 'SÍ', autorizo: 'SÍ', firma: 'SÍ' },
        confianza: { nombres: 0.99, apellidos: 0.5, cedula: 0.97, celular: 0.99, autorizo: 0.99, firma_presente: 0.99 },
        errores: {},
        motivo: null,
        ...overrides,
    };
}

beforeEach(() => {
    Object.values(mockRouter).forEach(fn => fn.mockReset());
    mockPage.props = { auth: { user: MANAGER }, errors: {}, flash: {}, visibleCampaigns: [] };
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('eventosOcr helpers', () => {
    it('finds the step from the load status', () => {
        expect(ocrStep(null)).toBe('fotos');
        expect(ocrStep({ status: 'subido' })).toBe('leer');
        expect(ocrStep({ status: 'revisado' })).toBe('revisar');
        expect(ocrStep({ status: 'confirmado' })).toBe('confirmar');
    });

    it('checks the photos before uploading', () => {
        expect(fileProblem(file('foto.HEIC', 10, 'image/heic'), LIMITS)).toBe(HEIC_MESSAGE);
        expect(fileProblem(file('foto.jpg', 11 * 1024 * 1024), LIMITS)).toBe('«foto.jpg» pesa más de 10 MB. Tómela de nuevo con menos resolución.');
        expect(fileProblem(file('hoja.docx'), LIMITS)).toBe('Solo se aceptan fotos JPG, PNG o WEBP, o un PDF.');
        expect(fileProblem(file('planilla.pdf', 100, 'application/pdf'), LIMITS)).toBeNull();
        expect(filesProblem([], LIMITS)).toBe('Elija las fotos de las planillas.');
        expect(filesProblem(Array.from({ length: 21 }, (_, i) => file(`f${i}.jpg`)), LIMITS)).toBe('Máximo 20 fotos por carga. Haga otra carga con el resto.');
    });

    it('marks cells below the threshold and describes the progress', () => {
        expect(isLowConfidence({ cedula: 0.84 }, 'cedula', 0.85)).toBe(true);
        expect(isLowConfidence({ cedula: 0.85 }, 'cedula', 0.85)).toBe(false);
        expect(isLowConfidence(undefined, 'cedula', 0.85)).toBe(false);
        expect(progressText({ total: 5, pendientes: 2, rechazadas: 1, errores: 0 })).toBe('3 de 5 páginas · 1 no se leyó');
        expect(progressPercent({ total: 4, pendientes: 1 })).toBe(75);
        expect(responseMessage({ status: 422, body: { errors: { foto: ['Esta foto ya se subió'] } } })).toBe('Esta foto ya se subió');
        expect(responseMessage({ status: 413, body: null })).toMatch(/demasiado grande/);
        expect(ocrConfirmSummary({ a_importar: 1 }, 2)).toBe('Se registrará 1 persona en el evento. Aún hay 2 filas con datos dudosos (en amarillo): revíselas contra la foto.');
        expect(sourceLabel({ source: 'ocr', registered_by: { name: 'Gestor' } })).toBe('Planilla leída con IA: Gestor');
    });
});

describe('OCR review row', () => {
    it('shows doubtful cells in amber and lets the person correct them', () => {
        const { container } = render(<ImportRowCard row={ocrRow()} eventId="ev1" importId="i1" editable fields={OCR_ROW_FIELDS} umbral={0.85} />);
        expect(screen.getByText('Fila 7')).toBeTruthy();
        expect(container.querySelector('[data-doubtful="apellidos"]')).toBeTruthy();
        expect(container.querySelector('[data-doubtful="cedula"]')).toBeNull();
        expect(screen.getByText('Dudoso: compárelo con la foto')).toBeTruthy();
        expect(screen.queryByText(/Autorizo|Firma/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Corregir esta fila' }));
        fireEvent.change(screen.getByLabelText('Apellidos'), { target: { value: 'PÉREZ GÓMEZ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar y revisar' }));
        expect(mockRouter.patch).toHaveBeenCalledWith('/eventos/ev1/importar/i1/filas/r1', { apellidos: 'PÉREZ GÓMEZ' }, expect.any(Object));
    });

    it('explains why a row without authorization is left out and does not offer to correct it', () => {
        render(<ImportRowCard row={ocrRow({ status: 'no_autorizo', motivo: 'La IA no vio la firma de esta fila: la persona no se importa.' })} eventId="ev1" importId="i1" editable fields={OCR_ROW_FIELDS} umbral={0.85} />);
        expect(screen.getByText('La IA no vio la firma de esta fila: la persona no se importa.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Corregir esta fila' })).toBeNull();
        expect(screen.queryByRole('checkbox')).toBeNull();
    });

    it('keeps the Excel rows as they were (no confidence, no amber)', () => {
        const { container } = render(<ImportRowCard row={{ ...ocrRow(), confianza: undefined, fila_pagina: undefined, fila: 44 }} eventId="ev1" importId="i1" editable />);
        expect(screen.getByText('Fila 44')).toBeTruthy();
        expect(container.querySelector('[data-doubtful]')).toBeNull();
    });
});

describe('OCR upload and reading', () => {
    it('uploads the photos one by one into the same load and opens it', async () => {
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => json(201, { success: true, data: { id: 'imp1', paginas: 1, total_paginas: 1 } }))
            .mockImplementationOnce(() => json(201, { success: true, data: { id: 'imp1', paginas: 1, total_paginas: 2 } }));
        vi.stubGlobal('fetch', fetchMock);
        render(<OcrUpload eventId="ev1" limits={LIMITS} />);
        fireEvent.change(screen.getByLabelText(/Elegir fotos/), { target: { files: [file('a.jpg'), file('b.jpg')] } });
        fireEvent.click(screen.getByRole('button', { name: 'Subir fotos' }));

        await waitFor(() => expect(mockRouter.visit).toHaveBeenCalledWith('/eventos/ev1/importar/ocr/imp1'));
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[0][0]).toBe('/eventos/ev1/importar/ocr');
        expect(fetchMock.mock.calls[0][1].body.get('importacion')).toBeNull();
        expect(fetchMock.mock.calls[1][1].body.get('importacion')).toBe('imp1');
    });

    it('stops on a refused photo and says which one', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json(422, { message: 'x', errors: { foto: ['Solo se aceptan fotos JPG, PNG o WEBP, o un PDF.'] } })));
        render(<OcrUpload eventId="ev1" limits={LIMITS} />);
        fireEvent.change(screen.getByLabelText(/Elegir fotos/), { target: { files: [file('a.jpg')] } });
        fireEvent.click(screen.getByRole('button', { name: 'Subir fotos' }));
        expect(await screen.findByText('«a.jpg»: Solo se aceptan fotos JPG, PNG o WEBP, o un PDF.')).toBeTruthy();
        expect(mockRouter.visit).not.toHaveBeenCalled();
    });

    it('does not upload a HEIC photo', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        render(<OcrUpload eventId="ev1" limits={LIMITS} />);
        fireEvent.change(screen.getByLabelText(/Elegir fotos/), { target: { files: [file('IMG_1.HEIC', 10, 'image/heic')] } });
        fireEvent.click(screen.getByRole('button', { name: 'Subir fotos' }));
        expect(screen.getByText(HEIC_MESSAGE)).toBeTruthy();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('reads page after page only when asked and reloads when done', async () => {
        const progress = (pendientes, listo = false) => ({ status: listo ? 'revisado' : 'subido', total: 2, pendientes, procesadas: 2 - pendientes, rechazadas: 0, errores: 0, filas: 3, listo });
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => json(200, { success: true, data: progress(1) }))
            .mockImplementationOnce(() => json(200, { success: true, data: progress(0, true) }));
        vi.stubGlobal('fetch', fetchMock);
        render(<OcrReader eventId="ev1" importacion={{ id: 'imp1', progreso: progress(2) }} ready />);
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Leer 2 páginas con IA' }));
        await waitFor(() => expect(mockRouter.reload).toHaveBeenCalled());
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[0][0]).toBe('/eventos/ev1/importar/ocr/imp1/procesar');
    });

    it('stops on a failed page and offers to retry or to continue without it', async () => {
        const failed = { status: 'subido', total: 2, pendientes: 1, procesadas: 0, rechazadas: 0, errores: 1, filas: 0, listo: false };
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => json(200, { success: false, message: 'El proveedor de IA está ocupado.', data: failed }))
            .mockImplementationOnce(() => json(200, { success: true, data: { ...failed, pendientes: 0, errores: 0, listo: true } }));
        vi.stubGlobal('fetch', fetchMock);
        render(<OcrReader eventId="ev1" importacion={{ id: 'imp1', progreso: { ...failed, pendientes: 2, errores: 0 } }} ready />);
        fireEvent.click(screen.getByRole('button', { name: 'Leer 2 páginas con IA' }));
        expect(await screen.findByText('El proveedor de IA está ocupado.')).toBeTruthy();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Intentar de nuevo' })); });
        await waitFor(() => expect(mockRouter.reload).toHaveBeenCalled());
        expect(fetchMock.mock.calls[1][1].body.get('reintentar')).toBe('1');
    });

    it('shows a photo with an <img> and a PDF page in a frame, both from the private route', () => {
        const { container } = render(<PageViewer pagina={{ numero: 1, nombre: 'a.jpg', pdf: false, archivo: '/eventos/ev1/importar/ocr/i/paginas/p/archivo' }} />);
        expect(container.querySelector('img').getAttribute('src')).toBe('/eventos/ev1/importar/ocr/i/paginas/p/archivo');
        cleanup();
        const pdf = render(<PageViewer pagina={{ numero: 2, nombre: 'b.pdf', pdf: true, pagina_pdf: 3, archivo: '/x/archivo' }} />);
        expect(pdf.container.querySelector('iframe').getAttribute('src')).toBe('/x/archivo#page=3');
    });
});

describe('Planillas page and the Show entry point', () => {
    const PAGINAS = [
        { id: 'p1', numero: 1, nombre: 'a.jpg', pdf: false, pagina_pdf: 1, pagina_impresa: 1, status: 'procesada', mensaje: null, filas: 1, dudosas: 1, archivo: '/eventos/ev1/importar/ocr/imp1/paginas/p1/archivo' },
        { id: 'p2', numero: 2, nombre: 'b.jpg', pdf: false, pagina_pdf: 1, pagina_impresa: null, status: 'rechazada', mensaje: 'Esta página es de otro evento (código ABCD1234): no se leyó.', filas: 0, dudosas: 0, archivo: '/x' },
    ];
    const IMPORT = { id: 'imp1', tipo: 'ocr', nombre: 'a.jpg', status: 'revisado', counts: { total: 1, validas: 1, a_importar: 1, en_plataforma: 0, no_autorizaron: 0, errores: 0, duplicadas: 0, en_evento: 0, importadas: 0 }, progreso: {} };

    it('shows the photo next to its rows, the page tabs and the OCR declaration', () => {
        render(<Planillas evento={EVENTO} ocr={OCR} importacion={IMPORT} paginas={PAGINAS} pagina_actual={1}
            filas={{ data: [ocrRow()], total: 1, last_page: 1 }} filters={{ estado: null }} recientes={[]} />);
        expect(screen.getByAltText('Foto de la página 1')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Página 2/ })).toBeTruthy();
        expect(screen.getByText(/1 dudosa/)).toBeTruthy();
        expect(screen.queryByText(/planillas firmadas/)).toBeNull();
        expect(screen.queryByRole('checkbox', { name: /planillas/ })).toBeNull();
        expect(screen.getByRole('button', { name: 'Importar 1 persona' }).disabled).toBe(false);

        fireEvent.click(screen.getByRole('button', { name: /Página 2/ }));
        expect(mockRouter.get).toHaveBeenCalledWith('/eventos/ev1/importar/ocr/imp1', { pagina: 2 }, expect.any(Object));
    });

    it('says when the key is missing and does not let upload', () => {
        render(<Planillas evento={EVENTO} ocr={{ ...OCR, listo: false, aviso: 'Falta la clave.' }} importacion={null} paginas={[]} pagina_actual={null} filas={null} filters={{}} recientes={[]} />);
        expect(screen.getByText('Falta la clave.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Subir fotos' }).disabled).toBe(true);
        expect(screen.getByText(/se envían a un proveedor de inteligencia artificial en el exterior/)).toBeTruthy();
    });

    it('offers "Leer planillas con IA" on the event only when it is switched on', () => {
        const SHOW_EVENTO = {
            id: 'ev1', nombre: 'Gira Girón', status: 'cerrado', tipo: { id: 't', nombre: 'Gira' }, starts_at: '2026-11-05T18:00:00-05:00', ends_at: null,
            sitio_encuentro: 'Parque', provincia: { id: 'p', nombre: 'Soto' }, municipio: { id: 'm', nombre: 'Girón' }, barrios: [],
            campaign: { id: 'cd', nombre: 'CD' }, editable: true, public_url: 'http://x/e/t', public_open: false, organizadores: [], figuras: [],
        };
        const renderShow = can => render(<Show evento={SHOW_EVENTO} asistentes={{ data: [], current_page: 1, last_page: 1, total: 0, links: [] }} kpis={{}} filters={{}} tipos={[]} provincias={[]} can={can} />);
        renderShow({ manage: true, register: true, edit: true, ocr: true });
        expect(screen.getByRole('link', { name: 'Leer planillas con IA' }).getAttribute('href')).toBe('/eventos/ev1/importar/ocr');
        cleanup();
        renderShow({ manage: true, register: true, edit: true, ocr: false });
        expect(screen.queryByRole('link', { name: 'Leer planillas con IA' })).toBeNull();
    });
});

describe('OCR left-out summary', () => {
    it('leaves nobody out for lack of authorization', async () => {
        const { ocrLeftOutSummary } = await import('@/lib/eventosOcr');
        expect(ocrLeftOutSummary({ errores: 2, no_autorizaron: 1 })).toBe('Quedan fuera: 2 con error.');
        expect(ocrLeftOutSummary({})).toBeNull();
    });
});
