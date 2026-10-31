import { useEffect, useRef } from 'react';

// One document listener for every open panel. Escape goes to the visible panel that holds the focus,
// otherwise to the last visible one opened, so two mounted copies of a panel (desktop + mobile, one of
// them display:none) never both react.
const stack = [];

function isVisible(entry) {
    const el = entry.ref?.current;
    return !el || typeof el.checkVisibility !== 'function' || el.checkVisibility();
}

function onKeyDown(event) {
    if (event.key !== 'Escape' || event.defaultPrevented || event.isComposing) return;
    const active = document.activeElement;
    const visible = stack.filter(isVisible).reverse();
    const entry = visible.find(e => e.ref?.current?.contains(active)) ?? visible[0];
    if (!entry) return;
    event.preventDefault();
    entry.handler.current(event);
}

export default function useEscapeKey(handler, ref = null) {
    const handlerRef = useRef(handler);

    useEffect(() => {
        handlerRef.current = handler;
    });

    useEffect(() => {
        const entry = { handler: handlerRef, ref };
        if (!stack.length) document.addEventListener('keydown', onKeyDown);
        stack.push(entry);
        return () => {
            stack.splice(stack.indexOf(entry), 1);
            if (!stack.length) document.removeEventListener('keydown', onKeyDown);
        };
    }, [ref]);
}
