import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import {
    CLAVE,
    PASOS_TEXTO,
    aplicarPreferencias,
    cambiarTexto,
    esTextoMaximo,
    esTextoMinimo,
    guardarPreferencias,
    leerPreferencias,
} from './accesibilidad';

const html = document.documentElement;
const atributos = () => [...html.attributes].filter(a => a.name.startsWith('data-a11y')).map(a => `${a.name}=${a.value}`).sort();

function limpiar() {
    localStorage.clear();
    [...html.attributes].filter(a => a.name.startsWith('data-a11y')).forEach(a => html.removeAttribute(a.name));
}

beforeEach(limpiar);
afterEach(() => {
    vi.restoreAllMocks();
    limpiar();
});

describe('tamaño del texto', () => {
    it('sube y baja por los pasos 90, 100, 110, 125, 140, 150', () => {
        expect(PASOS_TEXTO).toEqual([90, 100, 110, 125, 140, 150]);
        expect(cambiarTexto(100, 1)).toBe(110);
        expect(cambiarTexto(110, 1)).toBe(125);
        expect(cambiarTexto(125, -1)).toBe(110);
        expect(cambiarTexto(100, -1)).toBe(90);
    });

    it('no pasa de los límites', () => {
        expect(cambiarTexto(150, 1)).toBe(150);
        expect(cambiarTexto(90, -1)).toBe(90);
        expect(esTextoMaximo(150)).toBe(true);
        expect(esTextoMinimo(90)).toBe(true);
        expect(esTextoMaximo(140)).toBe(false);
    });

    it('un valor fuera de los pasos arranca desde 100 %', () => {
        expect(cambiarTexto(133, 1)).toBe(110);
    });
});

describe('persistencia', () => {
    it('guarda y vuelve a leer las preferencias', () => {
        expect(guardarPreferencias({ texto: 125, contraste: true, reducirAnimaciones: false, enlaces: true })).toBe(true);
        expect(leerPreferencias()).toEqual({ texto: 125, contraste: true, reducirAnimaciones: false, enlaces: true });
    });

    it('sin nada guardado devuelve lo normal y deja las animaciones al sistema', () => {
        expect(leerPreferencias()).toEqual({ texto: 100, contraste: false, reducirAnimaciones: null, enlaces: false });
    });

    it('descarta lo corrupto o inventado', () => {
        localStorage.setItem(CLAVE, '{no es json');
        expect(leerPreferencias().texto).toBe(100);
        localStorage.setItem(CLAVE, JSON.stringify({ texto: 999, contraste: 'si', enlaces: 1 }));
        expect(leerPreferencias()).toEqual({ texto: 100, contraste: false, reducirAnimaciones: null, enlaces: false });
    });

    it('con el almacenamiento bloqueado no revienta: lee lo normal y avisa que no guardó', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('SecurityError'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('QuotaExceededError'); });
        expect(leerPreferencias().texto).toBe(100);
        expect(guardarPreferencias({ texto: 150 })).toBe(false);
    });
});

describe('aplicarPreferencias', () => {
    it('pone y quita los atributos en <html>', () => {
        aplicarPreferencias({ texto: 125, contraste: true, reducirAnimaciones: true, enlaces: true });
        expect(atributos()).toEqual([
            'data-a11y-animaciones=reducidas',
            'data-a11y-contraste=',
            'data-a11y-enlaces=',
            'data-a11y-texto=125',
        ]);

        aplicarPreferencias({ texto: 100, contraste: false, reducirAnimaciones: false, enlaces: false });
        expect(atributos()).toEqual(['data-a11y-animaciones=normales']);

        aplicarPreferencias({ texto: 100, reducirAnimaciones: null });
        expect(atributos()).toEqual([]);
    });
});

// The inline script of app.blade.php paints the saved preferences before React loads: it must agree with the lib.
describe('script previo al pintado (app.blade.php)', () => {
    const blade = readFileSync(resolve(__dirname, '../../views/app.blade.php'), 'utf8');
    const codigo = blade.match(/<script id="a11y-inicial">([\s\S]*?)<\/script>/)[1];
    const correr = () => new Function(codigo)();

    const casos = [
        { texto: 150, contraste: true, reducirAnimaciones: true, enlaces: true },
        { texto: 90, contraste: false, reducirAnimaciones: false, enlaces: false },
        { texto: 100, contraste: true, reducirAnimaciones: null, enlaces: false },
        { texto: 999, contraste: 'si', enlaces: 1 },
    ];

    it.each(casos)('deja los mismos atributos que aplicarPreferencias (%o)', (prefs) => {
        localStorage.setItem(CLAVE, JSON.stringify(prefs));
        aplicarPreferencias(leerPreferencias());
        const esperados = atributos();

        limpiar();
        localStorage.setItem(CLAVE, JSON.stringify(prefs));
        correr();
        expect(atributos()).toEqual(esperados);
    });

    it('con datos corruptos o almacenamiento bloqueado no pone nada ni lanza', () => {
        localStorage.setItem(CLAVE, '{roto');
        expect(correr).not.toThrow();
        expect(atributos()).toEqual([]);

        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('SecurityError'); });
        expect(correr).not.toThrow();
        expect(atributos()).toEqual([]);
    });
});
