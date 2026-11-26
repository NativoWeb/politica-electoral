import { useCallback, useEffect, useRef, useState } from 'react';
import useDialogFocus from '@/lib/useDialogFocus';
import useEscapeKey from '@/lib/useEscapeKey';
import {
    CLAVE,
    PASOS_TEXTO,
    animacionesReducidas,
    aplicarPreferencias,
    cambiarTexto,
    esTextoMaximo,
    esTextoMinimo,
    guardarPreferencias,
    leerPreferencias,
} from '@/lib/accesibilidad';

const MARGEN = 12;
// A bar fixed (or stuck) to the bottom of the screen, e.g. the mobile tab bar or «Guardar cambios», carries
// data-barra-inferior: the button sits above it instead of covering it.
const SELECTOR_BARRA = '[data-barra-inferior]';

export function altoBarrasInferiores(alto = window.innerHeight) {
    let max = 0;
    document.querySelectorAll(SELECTOR_BARRA).forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.height === 0 || r.top >= alto || r.bottom < alto - 160) return;
        max = Math.max(max, alto - r.top);
    });
    return Math.round(max);
}

function useAltoBarrasInferiores() {
    const [alto, setAlto] = useState(0);

    useEffect(() => {
        let frame = 0;
        // A bar can change its height without adding nodes (the Reportes count loading): watch its size too.
        const tamanos = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(() => medir());
        // At most one measure per frame; never postponed, so a page that changes every frame cannot starve it.
        function medir() {
            if (frame) return;
            frame = requestAnimationFrame(() => {
                frame = 0;
                document.querySelectorAll(SELECTOR_BARRA).forEach(el => tamanos?.observe(el));
                setAlto(altoBarrasInferiores());
            });
        }
        medir();
        const observador = new MutationObserver(medir);
        observador.observe(document.body, { childList: true, subtree: true });
        window.addEventListener('resize', medir);
        window.addEventListener('scroll', medir, { passive: true, capture: true });
        return () => {
            cancelAnimationFrame(frame);
            observador.disconnect();
            tamanos?.disconnect();
            window.removeEventListener('resize', medir);
            window.removeEventListener('scroll', medir, { capture: true });
        };
    }, []);

    return alto;
}

function IconoAccesibilidad({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <circle cx="12" cy="4.5" r="1.8" fill="currentColor" stroke="none" />
            <path d="M5 8.5c2.3.7 4.6 1 7 1s4.7-.3 7-1" />
            <path d="M12 9.5v5" />
            <path d="M12 14.5 9 21" />
            <path d="M12 14.5 15 21" />
        </svg>
    );
}

function Interruptor({ activo, onCambiar, children }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={activo}
            onClick={onCambiar}
            className="w-full flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg text-left text-[14px] font-semibold text-ink hover:bg-bg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
            <span>{children}</span>
            <span aria-hidden="true" className={`relative shrink-0 w-10 h-6 rounded-full transition-colors ${activo ? 'bg-primary' : 'bg-line-strong'}`}>
                <span className={`absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform ${activo ? 'translate-x-4' : ''}`} />
            </span>
        </button>
    );
}

function PanelAccesibilidad({ prefs, sinGuardar, onCambiar, onCerrar, estilo }) {
    const panelRef = useRef(null);
    useDialogFocus(panelRef);
    useEscapeKey(onCerrar, panelRef);

    const paso = PASOS_TEXTO.indexOf(prefs.texto);
    const minimo = esTextoMinimo(prefs.texto);
    const maximo = esTextoMaximo(prefs.texto);
    const botonTexto = 'w-11 h-11 rounded-lg border border-line-strong bg-panel text-primary font-bold hover:bg-bg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary aria-disabled:opacity-40 aria-disabled:cursor-not-allowed';

    return (
        <div
            ref={panelRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="a11y-titulo"
            data-panel-accesibilidad
            style={estilo}
            className="fixed right-4 z-[45] w-[min(300px,calc(100vw-2rem))] overflow-y-auto rounded-xl bg-panel border border-line-strong shadow-2xl print:hidden"
        >
            <div className="flex items-center justify-between gap-2 px-4 py-3 bg-primary text-white rounded-t-xl">
                <h2 id="a11y-titulo" className="text-[15px] font-bold font-heading">Accesibilidad</h2>
                <button
                    type="button"
                    onClick={onCerrar}
                    aria-label="Cerrar accesibilidad"
                    className="w-11 h-11 -mr-2.5 flex items-center justify-center rounded-lg hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                >
                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                </button>
            </div>

            <div className="p-3 space-y-3">
                <div className="px-1">
                    <p className="text-[14px] font-semibold text-ink">
                        Tamaño del texto: <span aria-live="polite" className="tabular-nums">{prefs.texto}%</span>
                    </p>
                    <div className="mt-2 flex items-center gap-2">
                        <button
                            type="button"
                            aria-label="Reducir el tamaño del texto"
                            aria-disabled={minimo}
                            onClick={() => !minimo && onCambiar({ texto: cambiarTexto(prefs.texto, -1) })}
                            className={`${botonTexto} text-[14px]`}
                        >
                            A-
                        </button>
                        <div aria-hidden="true" className="flex-1 h-2 rounded-full bg-line overflow-hidden">
                            <div className="h-full bg-primary rounded-full transition-[width]" style={{ width: `${((paso + 1) / PASOS_TEXTO.length) * 100}%` }} />
                        </div>
                        <button
                            type="button"
                            aria-label="Aumentar el tamaño del texto"
                            aria-disabled={maximo}
                            onClick={() => !maximo && onCambiar({ texto: cambiarTexto(prefs.texto, 1) })}
                            className={`${botonTexto} text-[17px]`}
                        >
                            A+
                        </button>
                    </div>
                </div>

                <div className="border-t border-line pt-2 space-y-0.5">
                    <Interruptor activo={prefs.contraste} onCambiar={() => onCambiar({ contraste: !prefs.contraste })}>Alto contraste</Interruptor>
                    <Interruptor activo={animacionesReducidas(prefs)} onCambiar={() => onCambiar({ reducirAnimaciones: !animacionesReducidas(prefs) })}>Reducir animaciones</Interruptor>
                    <Interruptor activo={prefs.enlaces} onCambiar={() => onCambiar({ enlaces: !prefs.enlaces })}>Subrayar enlaces</Interruptor>
                </div>

                {sinGuardar && (
                    <p role="status" className="px-1 text-[13px] text-ink-soft">
                        Este dispositivo no deja guardar la preferencia: se aplica mientras tengas la página abierta.
                    </p>
                )}
            </div>
        </div>
    );
}

export default function BotonAccesibilidad() {
    const [prefs, setPrefs] = useState(leerPreferencias);
    const [abierto, setAbierto] = useState(false);
    const [sinGuardar, setSinGuardar] = useState(false);
    const botonRef = useRef(null);
    const altoBarras = useAltoBarrasInferiores();

    useEffect(() => {
        function onStorage(event) {
            if (event.key !== CLAVE) return;
            const nuevas = leerPreferencias();
            aplicarPreferencias(nuevas);
            setPrefs(nuevas);
        }
        window.addEventListener('storage', onStorage);
        return () => window.removeEventListener('storage', onStorage);
    }, []);

    useEffect(() => {
        if (!abierto) return undefined;
        function onPointerDown(event) {
            const panel = document.querySelector('[data-panel-accesibilidad]');
            if (panel?.contains(event.target) || botonRef.current?.contains(event.target)) return;
            setAbierto(false);
        }
        document.addEventListener('pointerdown', onPointerDown);
        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [abierto]);

    function cambiar(cambio) {
        const nuevas = { ...prefs, ...cambio };
        aplicarPreferencias(nuevas);
        setSinGuardar(!guardarPreferencias(nuevas));
        setPrefs(nuevas);
    }

    const cerrar = useCallback(() => setAbierto(false), []);

    const inferior = altoBarras > 0 ? `${altoBarras + MARGEN}px` : `calc(16px + env(safe-area-inset-bottom, 0px))`;

    return (
        <>
            <button
                ref={botonRef}
                type="button"
                onClick={() => setAbierto(a => !a)}
                aria-label="Accesibilidad"
                aria-expanded={abierto}
                aria-haspopup="dialog"
                title="Accesibilidad"
                data-boton-accesibilidad
                style={{ bottom: inferior }}
                className="fixed right-4 z-[45] w-11 h-11 rounded-full bg-primary text-white shadow-lg ring-2 ring-white flex items-center justify-center hover:bg-primary-light focus:outline-none focus-visible:ring-4 focus-visible:ring-accent print:hidden"
            >
                <IconoAccesibilidad className="w-6 h-6" />
            </button>
            {abierto && (
                <PanelAccesibilidad
                    prefs={prefs}
                    sinGuardar={sinGuardar}
                    onCambiar={cambiar}
                    onCerrar={cerrar}
                    estilo={{ bottom: `calc(${inferior} + 52px)`, maxHeight: `calc(100dvh - ${inferior} - 68px)` }}
                />
            )}
        </>
    );
}
