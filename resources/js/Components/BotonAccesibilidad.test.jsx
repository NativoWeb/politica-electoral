import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import BotonAccesibilidad, { altoBarrasInferiores } from './BotonAccesibilidad';
import { CLAVE } from '@/lib/accesibilidad';

const html = document.documentElement;

function limpiar() {
    localStorage.clear();
    [...html.attributes].filter(a => a.name.startsWith('data-a11y')).forEach(a => html.removeAttribute(a.name));
}

beforeEach(limpiar);
afterEach(() => {
    vi.restoreAllMocks();
    limpiar();
    document.body.innerHTML = '';
});

const abrir = () => fireEvent.click(screen.getByRole('button', { name: 'Accesibilidad' }));

describe('BotonAccesibilidad', () => {
    it('abre el panel con el foco dentro y el tamaño en 100 %', () => {
        render(<BotonAccesibilidad />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        abrir();

        const panel = screen.getByRole('dialog', { name: 'Accesibilidad' });
        expect(panel).toContainElement(document.activeElement);
        expect(screen.getByText('100%')).toHaveAttribute('aria-live', 'polite');
        expect(screen.getByRole('button', { name: 'Accesibilidad' })).toHaveAttribute('aria-expanded', 'true');
    });

    it('A+ y A- cambian el tamaño, lo aplican a <html> y lo guardan; en el tope no pasa', () => {
        render(<BotonAccesibilidad />);
        abrir();
        const mas = screen.getByRole('button', { name: 'Aumentar el tamaño del texto' });

        fireEvent.click(mas);
        fireEvent.click(mas);
        expect(screen.getByText('125%')).toBeInTheDocument();
        expect(html).toHaveAttribute('data-a11y-texto', '125');
        expect(JSON.parse(localStorage.getItem(CLAVE)).texto).toBe(125);

        fireEvent.click(mas);
        fireEvent.click(mas);
        fireEvent.click(mas);
        expect(screen.getByText('150%')).toBeInTheDocument();
        expect(mas).toHaveAttribute('aria-disabled', 'true');

        const menos = screen.getByRole('button', { name: 'Reducir el tamaño del texto' });
        for (let i = 0; i < 8; i++) fireEvent.click(menos);
        expect(screen.getByText('90%')).toBeInTheDocument();
        expect(menos).toHaveAttribute('aria-disabled', 'true');
        expect(html).toHaveAttribute('data-a11y-texto', '90');
    });

    it('los interruptores ponen y quitan los atributos y dicen su estado', () => {
        render(<BotonAccesibilidad />);
        abrir();
        const contraste = screen.getByRole('switch', { name: 'Alto contraste' });
        const animaciones = screen.getByRole('switch', { name: 'Reducir animaciones' });
        const enlaces = screen.getByRole('switch', { name: 'Subrayar enlaces' });

        fireEvent.click(contraste);
        fireEvent.click(animaciones);
        fireEvent.click(enlaces);
        expect(contraste).toHaveAttribute('aria-checked', 'true');
        expect(animaciones).toHaveAttribute('aria-checked', 'true');
        expect(enlaces).toHaveAttribute('aria-checked', 'true');
        expect(html).toHaveAttribute('data-a11y-contraste');
        expect(html).toHaveAttribute('data-a11y-animaciones', 'reducidas');
        expect(html).toHaveAttribute('data-a11y-enlaces');

        fireEvent.click(contraste);
        fireEvent.click(animaciones);
        expect(contraste).toHaveAttribute('aria-checked', 'false');
        expect(html).not.toHaveAttribute('data-a11y-contraste');
        expect(html).toHaveAttribute('data-a11y-animaciones', 'normales');
        expect(JSON.parse(localStorage.getItem(CLAVE))).toMatchObject({ contraste: false, reducirAnimaciones: false, enlaces: true });
    });

    it('arranca con lo guardado en el dispositivo', () => {
        localStorage.setItem(CLAVE, JSON.stringify({ texto: 140, contraste: true, enlaces: false }));
        render(<BotonAccesibilidad />);
        abrir();
        expect(screen.getByText('140%')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Alto contraste' })).toHaveAttribute('aria-checked', 'true');
    });

    it('Esc cierra el panel y devuelve el foco al botón', () => {
        render(<BotonAccesibilidad />);
        const boton = screen.getByRole('button', { name: 'Accesibilidad' });
        boton.focus();
        fireEvent.click(boton);
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        fireEvent.keyDown(document.activeElement, { key: 'Escape' });

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(boton).toHaveFocus();
    });

    it('la X y un clic fuera cierran el panel', () => {
        render(<><p>afuera</p><BotonAccesibilidad /></>);
        abrir();
        fireEvent.click(screen.getByRole('button', { name: 'Cerrar accesibilidad' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        abrir();
        fireEvent.pointerDown(screen.getByRole('switch', { name: 'Alto contraste' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        fireEvent.pointerDown(screen.getByText('afuera'));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('Tab no se sale del panel', () => {
        render(<BotonAccesibilidad />);
        abrir();
        const panel = screen.getByRole('dialog');
        const ultimo = screen.getByRole('switch', { name: 'Subrayar enlaces' });
        ultimo.focus();
        fireEvent.keyDown(panel, { key: 'Tab' });
        expect(screen.getByRole('button', { name: 'Cerrar accesibilidad' })).toHaveFocus();
    });

    it('si el dispositivo no deja guardar, igual aplica el cambio y lo dice', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('QuotaExceededError'); });
        render(<BotonAccesibilidad />);
        abrir();
        fireEvent.click(screen.getByRole('switch', { name: 'Subrayar enlaces' }));
        expect(html).toHaveAttribute('data-a11y-enlaces');
        expect(screen.getByRole('status')).toHaveTextContent('no deja guardar');
    });
});

describe('altoBarrasInferiores', () => {
    const barra = (top, bottom) => {
        const el = document.createElement('div');
        el.setAttribute('data-barra-inferior', '');
        el.getBoundingClientRect = () => ({ top, bottom, height: bottom - top, left: 0, right: 100, width: 100 });
        document.body.appendChild(el);
        return el;
    };

    it('mide la barra pegada abajo más alta (pestañas + Guardar cambios)', () => {
        barra(668, 740);
        barra(600, 668);
        expect(altoBarrasInferiores(740)).toBe(140);
    });

    it('ignora barras ocultas o lejos del borde inferior', () => {
        barra(0, 0);
        barra(100, 200);
        expect(altoBarrasInferiores(740)).toBe(0);
    });

    it('el botón se sube encima de la barra', async () => {
        barra(668, 740);
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 740 });
        render(<BotonAccesibilidad />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Accesibilidad' }).style.bottom).toBe('84px'));
    });
});
