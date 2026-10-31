import { useRef, useState } from 'react';
import { apiFetch } from '@/lib/api';
import { EMPTY_ATTENDEE, attendeeClientErrors, attendeePayload, firstErrors, newClientUuid } from '@/lib/eventos';

const STATUS_MESSAGES = {
    419: 'La página estuvo abierta mucho tiempo. Recárguela e intente de nuevo.',
    429: 'Se enviaron muchos registros seguidos. Espere un minuto e intente de nuevo.',
};

// Attendee registration shared by the operator mode and the public QR page. The client_uuid stays the same
// while the same person is retried (network failure, validation) and changes once a registration is saved,
// so a double tap never creates two rows and the next person never reuses the previous id.
export default function useAttendeeForm({ url, consentId, initialUuid, extraPayload, onSaved, onClosed }) {
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

    async function submit() {
        if (inFlight.current) return false;
        const clientErrors = attendeeClientErrors(data);
        setNotice('');
        if (Object.keys(clientErrors).length) {
            setErrors(clientErrors);
            return false;
        }

        const payload = { ...attendeePayload(data, { clientUuid: clientUuid.current, consentId }), ...(extraPayload?.() ?? {}) };
        inFlight.current = true;
        setSaving(true);
        try {
            const res = await apiFetch(url, { method: 'POST', body: JSON.stringify(payload) });
            const json = await res.json().catch(() => ({}));

            if (res.ok) {
                const saved = { ...json, payload };
                setData(EMPTY_ATTENDEE);
                setErrors({});
                clientUuid.current = newClientUuid();
                onSaved?.(saved);
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
        } catch {
            setNotice('No hay conexión con el servidor. Revise el internet y vuelva a pulsar guardar: no se duplicará.');
            return false;
        } finally {
            inFlight.current = false;
            setSaving(false);
        }
    }

    return { data, set, setBirth, errors, notice, saving, submit, reset };
}
