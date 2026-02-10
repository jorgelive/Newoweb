import type { PmsGuia, GuiaHelperContext } from '@/types/pms';
import type { MaestroIdioma } from '@/types/maestros';

const API_BASE = import.meta.env.VITE_API_URL || 'https://newapi.openperu.test:8890';

export const paxService = {
    async getIdiomasPrioritarios(): Promise<MaestroIdioma[]> {
        try {
            const url = new URL(`${API_BASE}/api/maestro_idioma`);
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
            const res = await fetch(`${API_BASE}/api/pax/ui_i18n`, { headers: { 'Accept': 'application/ld+json' } });
            if (!res.ok) return {};
            const data = await res.json();
            const list = data['member'] || data['hydra:member'] || [];
            const dic: Record<string, any> = {};
            list.forEach((i: any) => { if (i.id && i.contenido) dic[i.id] = i.contenido; });
            return dic;
        } catch { return {}; }
    },

    async getPmsReserva(loc: string) {
        const res = await fetch(`${API_BASE}/api/pax/pms/pms_reserva/${loc}`, { headers: { 'Accept': 'application/ld+json' } });
        if (!res.ok) throw new Error('Reserva no encontrada');
        return res.json();
    },

    async getPmsGuia(uuid: string): Promise<PmsGuia> {
        const res = await fetch(`${API_BASE}/api/pax/pms/pms_guia/pms_unidad/${uuid}`, { headers: { 'Accept': 'application/ld+json' } });
        if (!res.ok) {
            if (res.status === 404) throw new Error('ERR_404_GUIA');
            throw new Error('ERR_CONNECTION');
        }
        return res.json();
    },

    async getGuiaContext(uuid: string): Promise<GuiaHelperContext> {
        const res = await fetch(`${API_BASE}/pax/guiahelper/${uuid}`, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return { data: { replacements: {}, config: {}, widgets: {} } } as any;
        return res.json();
    }
};