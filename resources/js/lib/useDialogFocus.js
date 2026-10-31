import { useEffect, useState } from 'react';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Keeps Tab inside an open dialog and gives the focus back to whatever opened it when it closes.
export default function useDialogFocus(ref) {
    // Read while rendering: autoFocus inside the dialog moves the focus before any effect runs.
    const [opener] = useState(() => (typeof document === 'undefined' ? null : document.activeElement));

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return undefined;

        const focusables = () => [...dialog.querySelectorAll(FOCUSABLE)].filter(el => !el.closest('[hidden]'));
        if (!dialog.contains(document.activeElement)) focusables()[0]?.focus();

        function onKeyDown(event) {
            if (event.key !== 'Tab') return;
            const items = focusables();
            if (items.length === 0) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;
            if (!dialog.contains(active) || (event.shiftKey ? active === first : active === last)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            }
        }

        dialog.addEventListener('keydown', onKeyDown);
        return () => {
            dialog.removeEventListener('keydown', onKeyDown);
            if (opener?.isConnected && typeof opener.focus === 'function') opener.focus();
        };
    }, [ref, opener]);
}
