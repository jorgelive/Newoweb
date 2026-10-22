/**
 * src/types/paxGuiaEnlaceModel.ts
 *
 * Espejo TS de `App\Pms\Controller\Publico\PmsGuiaEnlacePublicoController::ver()`. No es un
 * `ApiResource`, así que no entra en `api.d.ts`: si cambias un campo allí, cámbialo aquí.
 */
import type { PmsContenidoTraducible } from '@/types/paxHuespedModel.ts';

/** Respuesta de `GET /pms/guia-enlace/{token}`: una ficha `PorEnlace`, ya interpolada. */
export interface PaxGuiaEnlace {
    titulo: PmsContenidoTraducible[];
    /** HTML con los bloques de `RichContentEngine` (`{{ img: … }}`, `{{ video: … }}`). */
    descripcion: PmsContenidoTraducible[];
    casita: string | null;
    /**
     * Las fotos propias de la ficha (cartuchera, llaveros…), en orden. `imageUrl` es una ruta del
     * sitio (`/carga/…`), igual que en la galería de la guía. La página pinta las que el texto no
     * haya insertado ya.
     */
    galeria: { imageUrl: string; descripcion: PmsContenidoTraducible[] }[];
    /** Idioma de la reserva: con él se abre la página. */
    idioma: string | null;
    /** ISO 8601. */
    expiraEn: string;
}
