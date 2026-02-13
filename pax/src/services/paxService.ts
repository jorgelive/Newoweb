// src/services/paxService.ts
import type { PmsGuia, GuiaHelperContext } from '@/types/pms';
import type { MaestroIdioma } from '@/types/maestros';

const API_BASE = window.OPENPERU_CONFIG?.apiUrl || import.meta.env.VITE_API_URL;

if (!API_BASE) console.error('CRITICAL: API_BASE no definida.');

export const paxService = {

    // --- MAESTROS ---
    async getIdiomasPrioritarios(): Promise<MaestroIdioma[]> {
        try {
            const url = new URL(`${API_BASE}/platform/public/maestro_idioma`);
            url.searchParams.append('prioridad[gt]', '0');
            url.searchParams.append('order[prioridad]', 'desc');
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/ld+json' } });
            if (!res.ok) return [];
            const data = await res.json();
            return data['hydra:member'] || data['member'] || [];
        } catch { return []; }
    },

    async getPaxUiTextos(): Promise<Record<string, any>> {
        try {
            const res = await fetch(`${API_BASE}/platform/public/pax/ui_i18n`, { headers: { 'Accept': 'application/ld+json' } });
            if (!res.ok) return {};
            const data = await res.json();
            const list = data['member'] || data['hydra:member'] || [];
            const dic: Record<string, any> = {};
            list.forEach((i: any) => { if (i.id && i.contenido) dic[i.id] = i.contenido; });
            return dic;
        } catch { return {}; }
    },

    async getPmsReserva(loc: string) {
        const res = await fetch(`${API_BASE}/platform/client/pax/pms/pms_reserva/${loc}`, { headers: { 'Accept': 'application/ld+json' } });
        if (!res.ok) throw new Error('Reserva no encontrada');
        return res.json();
    },

    // --- GUÍA VISUAL (CMS) ---
    // Carga fotos y textos usando el ID DE LA UNIDAD
    async getPmsGuia(unidadUuid: string): Promise<PmsGuia> {
        const res = await fetch(`${API_BASE}/platform/public/pax/pms/pms_guia/pms_unidad/${unidadUuid}`, {
            headers: { 'Accept': 'application/ld+json' }
        });
        if (!res.ok) {
            if (res.status === 404) throw new Error('ERR_404_GUIA');
            throw new Error('ERR_CONNECTION');
        }
        return res.json();
    },

    // --- CONTEXTO PRIVADO (GUEST) ---
    // Usa UUID EVENTO -> Devuelve códigos reales si fecha ok
    async getGuiaGuestContext(eventoUuid: string): Promise<GuiaHelperContext> {
        const res = await fetch(`${API_BASE}/pax/client/guiahelper/${eventoUuid}`, {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) throw new Error('No se pudo cargar la información de la estancia.');
        return res.json();
    },

    // --- CONTEXTO PÚBLICO (DEMO/QR) ---
    // Usa UUID UNIDAD -> Devuelve máscaras y modo demo
    async getGuiaPublicContext(unidadUuid: string): Promise<GuiaHelperContext> {
        const res = await fetch(`${API_BASE}/pax/public/guiahelper/${unidadUuid}`, {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) throw new Error('No se pudo cargar la información de la unidad.');
        return res.json();
    }
};