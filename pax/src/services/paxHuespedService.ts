// src/services/paxHuespedService.ts

const API_BASE = window.OPENPERU_CONFIG?.apiUrl || import.meta.env.VITE_API_URL;

if (!API_BASE) console.error('CRITICAL: API_BASE no definida.');

export const paxHuespedService = {

    /**
     * Reserva por localizador — la entrada canónica del huésped
     * (`/huesped/reserva/:localizador`), la única URL que se reparte a clientes.
     *
     * Aquí vivían tres métodos más (getPmsGuia, getGuiaGuestContext,
     * getGuiaPublicContext) que servían al contrato viejo: dos peticiones
     * encadenadas y un diccionario de valores sensibles que el navegador tenía
     * que recibir entero para decidir cuál pintar. Ese circuito ya no existe.
     * La guía se pide de una vez, ya podada e interpolada, a
     * GET /platform/client/pax/pms/pms_guia/{localizador}.
     */
    async getPmsReserva(loc: string) {
        const res = await fetch(`${API_BASE}/platform/client/pax/pms/pms_reserva/${loc}`, {
            headers: { 'Accept': 'application/ld+json' }
        });
        if (!res.ok) throw new Error('Reserva no encontrada');
        return res.json();
    },

    /**
     * El huésped deja su WhatsApp (`PmsReservaTelefonoHuespedController`). Devuelve el
     * `resultado` del backend: `guardado`, `invalido`, `ya_tenemos`, `sin_estancia`,
     * `no_encontrada`; o `error` si ni siquiera respondió.
     */
    async guardarTelefono(loc: string, telefono: string): Promise<string> {
        try {
            const res = await fetch(`${API_BASE}/platform/client/pax/pms/pms_reserva/${loc}/telefono`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ telefono }),
            });
            const data = await res.json().catch(() => null) as { resultado?: string } | null;

            return data?.resultado ?? 'error';
        } catch {
            return 'error';
        }
    },
};
