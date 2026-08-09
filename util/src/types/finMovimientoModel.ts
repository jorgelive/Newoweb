// src/types/finMovimientoModel.ts
// ============================================================================
// Vista de CAJA: el dinero que entró, sea cual sea el medio y venga del módulo que venga.
//
// Espejo de `App\Finanzas\Dto\FinMovimientoDto::aArray()` y de la respuesta de
// `FinCajaApiController::movimientos()`. Ninguno se genera solo: si cambias un campo en
// PHP, cámbialo aquí.
//
// Ojo con la diferencia entre las dos pestañas del módulo, que no es cosmética:
//   - COBROS  = enlaces de pago emitidos (pueden estar sin pagar). Importe = montoTotal,
//               con el recargo de la pasarela incluido.
//   - CAJA    = dinero efectivamente recibido, por cualquier medio. Importe = NETO,
//               porque el recargo se lo queda la pasarela y no llega a nuestra cuenta.
// ============================================================================
import type { FinOrigenCobro } from '@/types/finEnlacePagoModel';

export interface FinMovimiento {
    /** Id en el módulo de origen (un PmsPagoFinanciero, hoy). */
    id: string;
    /** `YYYY-MM-DD`. Fecha del cobro, no la de registro. */
    fecha: string;
    medioCodigo: string;
    /** Etiqueta ya traducida por el módulo dueño: aquí no se declara ningún diccionario. */
    medioEtiqueta: string;
    /** Importe NETO imputado, en `moneda`. */
    monto: string;
    moneda: string;
    /** TC del día del pago si el módulo lo guardó. */
    tipoCambio: string | null;
    referencia: string | null;
    origenTipo: FinOrigenCobro;
    origenId: string;
    /** Localizador del documento. */
    origenReferencia: string | null;
    origenDescripcion: string;
    /** Quién recibió el dinero en mano; null en transferencias y cobros automáticos. */
    cobradorNombre: string | null;
    /** Lo generó el sistema: depósito de OTA o cobro por pasarela. */
    esAutomatico: boolean;
}

/** Total por moneda. Sin conversión a una moneda común: ver el comentario de arriba. */
export interface FinTotalMoneda {
    moneda: string;
    total: string;
    registros: number;
}

/** Respuesta de `GET /finanzas/caja/movimientos`. */
export interface FinMovimientosRespuesta {
    movimientos: FinMovimiento[];
    totales: FinTotalMoneda[];
    /** Catálogo del desplegable, servido por los módulos. */
    medios: { value: string; label: string }[];
    truncado: boolean;
}

/** Filtros compartidos por las dos pestañas. */
export interface FinCajaFiltros {
    desde: string;
    hasta: string;
    /** Vacío = todos. */
    estado: string;
    medio: string;
    q: string;
}
