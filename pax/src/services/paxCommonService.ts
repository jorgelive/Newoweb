import type {MaestroIdioma} from "@/types/maestroModel.ts";


const API_BASE = window.OPENPERU_CONFIG?.apiUrl || import.meta.env.VITE_API_URL;

if (!API_BASE) console.error('CRITICAL: API_BASE no definida.');
export const paxCommonService = {

    // --- MAESTROS ---
    async getIdiomasPrioritarios(): Promise<MaestroIdioma[]> {
        try {
            const url = new URL(`${API_BASE}/platform/maestro/idiomas`);
            url.searchParams.append('prioridad[gt]', '0');
            url.searchParams.append('order[prioridad]', 'desc');
            const res = await fetch(url.toString(), {headers: {'Accept': 'application/ld+json'}});
            if (!res.ok) return [];
            const data = await res.json();
            return data['hydra:member'] || data['member'] || [];
        } catch {
            return [];
        }
    },

    async getPaxUiTextos(): Promise<Record<string, any>> {
        try {
            const res = await fetch(`${API_BASE}/platform/public/pax/ui_i18n`, {headers: {'Accept': 'application/ld+json'}});
            if (!res.ok) return {};
            const data = await res.json();
            const list = data['member'] || data['hydra:member'] || [];
            const dic: Record<string, any> = {};
            list.forEach((i: any) => {
                if (i.id && i.contenido) dic[i.id] = i.contenido;
            });
            return dic;
        } catch {
            return {};
        }
    }
}