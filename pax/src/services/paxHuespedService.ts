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
};
