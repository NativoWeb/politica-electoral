import { useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';
import { isApiAnswer } from '@/lib/offlineDb';
import { EMPTY_ATTENDEE, attendeeClientErrors, attendeePayload, firstErrors, newClientUuid } from '@/lib/eventos';

const STATUS_MESSAGES = {
    419: 'La página estuvo abierta mucho tiempo. Recárguela e intente de nuevo.',
    429: 'Se enviaron muchos registros seguidos. Espere un minuto e intente de nuevo.',
};

// Operator mode: answers that do not mean the registration is wrong. It is kept on the phone and sent later.
const QUEUED_SESSION_MESSAGE = 'Su sesión se cerró. Inicie sesión de nuevo; lo guardado en el teléfono no se pierde.';
const QUEUED_SERVER_MESSAGE = 'El servidor no respondió; quedó guardado en el teléfono y se enviará después.';
const QUEUED_PASSWORD_MESSAGE = 'Debe cambiar su contraseña antes de seguir; lo guardado en el teléfono no se pierde.';

// Why a registration made with signal stays on the phone: 'session' (401/419) or 'server' (5xx).
function queuedReason(status) {
    if (status === 401 || status === 419) return 'session';
    if (status >= 500) return 'server';
    return null;
}

const QUEUED_MESSAGES = { session: QUEUED_SESSION_MESSAGE, server: QUEUED_SERVER_MESSAGE, password: QUEUED_PASSWORD_MESSAGE };

const NETWORK_MESSAGE = 'No hay conexión con el servidor. Revise el internet y vuelva a pulsar guardar: no se duplicará.';

// Attendee registration shared by the operator mode and the public QR page. The client_uuid stays the same
// while the same person is retried (network failure, validation) and changes once a registration is saved,
// so a double tap never creates two rows and the next person never reuses the previous id.
// With `queueOffline` (operator mode) a registration made without signal, or whose POST fails or times out
// on the network, is handed to the offline queue with its client_uuid and the form moves on to the next person.
// onQueued gets the reason: 'offline', 'session', 'password' or 'server'.
// `prepareUuid` may swap the current client_uuid for a fresh one before sending (e.g. one already used).
export default function useAttendeeForm({
    url, consentId, initialUuid, extraPayload, onSaved, onClosed, queueOffline, onQueued, prepareUuid, timeoutMs,
}) {
    const [data, setData] = useState(EMPTY_ATTENDEE);
    const [errors, setErrors] = useState({});
    const [notice, setNotice] = useState('');
    const [saving, setSaving] = useState(false);
    const clientUuid = useRef(initialUuid || newClientUuid());
    const inFlight = useRef(false);

    function set(field, value) {
        setData(prev => ({ ...prev, [field]: value }));
        setErrors(prev => (prev[field] ? { ...prev, [field]: undefined } : prev));
    }

    function setBirth(birth) {
        setData(prev => ({ ...prev, birth }));
        setErrors(prev => (prev.fecha_nacimiento ? { ...prev, fecha_nacimiento: undefined } : prev));
    }

    function reset() {
        setData(EMPTY_ATTENDEE);
        setErrors({});
        setNotice('');
        clientUuid.current = newClientUuid();
    }

    // Puts a registration back in the form to correct it; it is sent with a new client_uuid.
    function load(values) {
        setData({ ...EMPTY_ATTENDEE, ...values });
        setErrors({});
        setNotice('');
        clientUuid.current = newClientUuid();
    }

    function moveOn() {
        setData(EMPTY_ATTENDEE);
        setErrors({});
        clientUuid.current = newClientUuid();
    }

    async function queue(payload, reason = 'offline') {
        try {
            await queueOffline(payload);
        } catch (err) {
            setNotice(`${NETWORK_MESSAGE} (No se pudo guardar en el teléfono: ${err?.message || 'error del almacenamiento'}.)`);
            return false;
        }
        moveOn();
        setNotice(QUEUED_MESSAGES[reason] ?? '');
        onQueued?.({ payload, reason });
        return true;
    }

    async function submit() {
        if (inFlight.current) return false;
        const clientErrors = attendeeClientErrors(data);
        setNotice('');
        if (Object.keys(clientErrors).length) {
            setErrors(clientErrors);
            return false;
        }

        inFlight.current = true;
        setSaving(true);
        let timer = null;
        try {
            if (prepareUuid) clientUuid.current = await prepareUuid(clientUuid.current);
            const payload = { ...attendeePayload(data, { clientUuid: clientUuid.current, consentId }), ...(extraPayload?.() ?? {}) };
            if (queueOffline && typeof navigator !== 'undefined' && navigator.onLine === false) {
                return await queue(payload);
            }

            const controller = timeoutMs ? new AbortController() : null;
            if (controller) timer = setTimeout(() => controller.abort(), timeoutMs);
            let res;
            try {
                res = await apiFetch(url, { method: 'POST', body: JSON.stringify(payload), signal: controller?.signal });
            } catch {
                // Network failure or no answer in time: the server may or may not have it, the client_uuid makes the resend safe.
                if (queueOffline) return await queue(payload);
                setNotice(NETWORK_MESSAGE);
                return false;
            }
            // A followed redirect (the forced password change) ends in an HTML page: the registration was not saved.
            if (res.redirected || !isApiAnswer(res)) {
                if (queueOffline) return await queue(payload, res.redirected ? 'password' : 'server');
                setNotice('No se pudo guardar. Recargue la página e intente de nuevo.');
                return false;
            }
            const keepReason = queueOffline ? queuedReason(res.status) : null;
            if (keepReason) return await queue(payload, keepReason);
            const json = await res.json().catch(() => ({}));

            if (res.ok) {
                const saved = { ...json, payload };
                moveOn();
                await onSaved?.(saved);
                return true;
            }
            if (res.status === 422) {
                const fieldErrors = firstErrors(json.errors);
                setErrors(fieldErrors);
                setNotice(fieldErrors.consent_text_id || 'Revise los campos marcados en rojo.');
                return false;
            }
            if (res.status === 410) {
                setNotice(json.message || 'El evento ya no recibe registros.');
                onClosed?.(json);
                return false;
            }
            if (res.status === 409) {
                clientUuid.current = newClientUuid();
                setNotice(`${json.message || 'Ese registro pertenece a otro evento.'} Pulse guardar otra vez.`);
                return false;
            }
            setNotice(STATUS_MESSAGES[res.status] || json.message || 'No se pudo guardar. Intente de nuevo en un momento.');
            return false;
        } finally {
            clearTimeout(timer);
            inFlight.current = false;
            setSaving(false);
        }
    }

    return { data, set, setBirth, errors, notice, saving, submit, reset, load };
}
