// src/types/paxHuespedGuiaModel.ts
// ============================================================================
// Tipos de la GUÍA DEL HUÉSPED (`/huesped/reserva/:localizador/:unidad`).
//
// Espejo del grupo de serialización `pax_guia:read`. Ver en el backend:
//  - src/Api/Provider/Pms/PmsGuiaHuespedProvider.php  (quién lo llena)
//  - src/Pms/Entity/PmsGuia.php                       (los getters y sus @SerializedName)
//  - src/Pms/Guia/PmsGuiaAccesoEstado.php             (los estados de acceso)
//  - docs/PmsGuiaHuesped.md                           (la matriz de acceso completa)
//
// Lo esencial del contrato, y la razón de que aquí no haya ni una bandera de
// bloqueo: **lo que el huésped no puede ver, no viaja**. El árbol llega podado
// por PmsGuiaArbolFiltro y con los `{{ placeholders }}` ya resueltos por
// PmsGuiaInterpolador. El contrato anterior mandaba los códigos enmascarados
// con asteriscos y el navegador decidía; ese diseño ya no existe.
// ============================================================================

import type { CatalogoItem, CatalogoSeccion } from './paxCatalogoUnidadModel';
import type { PmsContenidoTraducible } from './paxHuespedModel';

// El árbol es la MISMA entidad serializada con otro grupo: la forma de secciones
// e ítems es idéntica a la del escaparate. Lo que cambia es *cuánto* llega (aquí
// entran también los ítems `privado` y `llegada`), no la estructura. Se aliasan
// en vez de duplicarse para que un campo nuevo en el serializer no haya que
// añadirlo en dos sitios.
/**
 * Ítem de la guía. Añade sobre el del escaparate el par de campos del candado,
 * que solo existen en `pax_guia:read`: un ítem `llegada` cuya ventana aún no
 * abrió llega **con título y con el cuerpo anunciando la fecha**, no vacío
 * (`PmsGuiaAcceso::debeAnunciarBloqueo()`). El valor real nunca sale del
 * servidor; `bloqueado` es solo para pintar el candado sin comparar fechas aquí.
 */
export type GuiaItem = CatalogoItem & {
    bloqueado?: boolean;
    bloqueadoHasta?: string | null;
};

export type GuiaSeccion = Omit<CatalogoSeccion, 'items'> & { items: GuiaItem[] };

/**
 * Estado de la ventana de acceso. Solo decide el AVISO de cabecera: el recorte
 * del contenido ya viene hecho del backend.
 *
 * - `publico`   — sin estancia asociada (no debería darse en esta vista). Es
 *   también donde cae una estancia cancelada, aunque a esas el backend ni les
 *   sirve la guía: `getEventosActivosGuia()` las descarta antes.
 * - `sin_pago`  — con localizador pero sin pago confiable. Ve el nivel
 *   `cliente`; lo demás llega anunciado con candado.
 * - `pendiente` — pagada, pero aún falta para la ventana (`liberaEn`).
 * - `activa`    — ventana abierta: códigos y WiFi viajan de verdad.
 * - `expirada`  — la estancia terminó. Lo pagado sigue visible; la ventana no.
 */
export type GuiaAccesoEstado = 'publico' | 'sin_pago' | 'pendiente' | 'activa' | 'expirada';

export interface GuiaAcceso {
    estado: GuiaAccesoEstado;
    /** ISO-8601 del momento en que se abre la ventana; null si no aplica. */
    liberaEn?: string | null;
}

/** Red WiFi con su contraseña REAL. La lista llega vacía si la ventana está cerrada. */
export interface GuiaRedWifi {
    ssid: string;
    password: string;
    ubicacion?: PmsContenidoTraducible[] | string;
}

/**
 * Cabecera de la estancia. Claves tal cual las emite PmsGuiaContexto::construir()
 * (`valores`), nunca las sensibles: los códigos ya están resueltos dentro del
 * cuerpo de cada ítem, o directamente no están.
 */
export interface GuiaContexto {
    unit_name?: string;
    hotel_name?: string;
    host_name?: string;
    host_whatsapp?: string;
    guest_name?: string;
    booking_ref?: string;
    check_in?: string;
    check_out?: string;
    start_date?: string;
    end_date?: string;
}

export interface GuiaUnidadResumen {
    nombre?: string | null;
    slug?: string | null;
    imageUrl?: string | null;
    capacidad?: number | null;
}

/**
 * Un medio de cobro que se le puede ofrecer a ESTE huésped.
 *
 * 🪞 Espejo de `PmsGuiaHuespedProvider::mediosPago()`, que a su vez aplana
 * `App\Finanzas\Entity\FinMedioCobro`. Si allí se añade una clave, se añade aquí.
 *
 * La lista llega **ya filtrada por procedencia** (`PmsProcedenciaHuesped`): a quien paga desde
 * fuera de Perú no le viaja ninguna cuenta bancaria, así que aquí no hay nada que decidir —se
 * pinta lo que venga—. Ese filtro es el mismo que aplica el asistente en el chat, a propósito.
 *
 * `nota` viaja sin traducir, como el resto de textos de la guía: la resuelve
 * `maestroStore.traducir()` con el idioma que el huésped tenga elegido en ese momento.
 */
export interface GuiaMedioPago {
    /** Valor de `FinMedioCobroTipo`: `yape`, `plin`, `transferencia_bancaria`… */
    tipo: string;
    /** Etiqueta ya legible («Transferencia bancaria»). Viene del backend para no duplicar el diccionario. */
    medio: string;
    icono?: string;
    titular?: string;
    /** Cómo lo escribe la banca sin tildes ni «ñ». */
    titularAlterno?: string;
    numero?: string;
    banco?: string;
    cci?: string;
    moneda?: string;
    nota?: PmsContenidoTraducible[];
}

/** Raíz de la respuesta: una PmsGuia serializada con `pax_guia:read`. */
export interface GuiaHuespedResponse {
    '@id'?: string;
    titulo: PmsContenidoTraducible[];
    unidad?: GuiaUnidadResumen;
    secciones: GuiaSeccion[];
    contexto: GuiaContexto;
    redesWifi: GuiaRedWifi[];
    /** Medios de cobro para `{{ medios_pago }}`. Sin ventana: se ven antes de llegar. */
    mediosPago: GuiaMedioPago[];
    acceso: GuiaAcceso;
}
