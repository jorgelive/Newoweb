/**
 * Una fila del reporte «Sin teléfono» del portal.
 *
 * Espejo de `src/Message/Dto/AsuntoSinTelefono.php` (`aArray()`), que sirve
 * `GET /platform/message/asuntos-sin-telefono`. No es un ApiResource: el esquema generado no lo
 * ve, así que la forma se fija aquí y la garantiza ese DTO.
 */
export type MotivoSinTelefono = 'sin_conversacion' | 'sin_telefono' | 'solo_en_la_ficha' | 'whatsapp_vetado';

export interface AsuntoSinTelefono {
    /** El `contextType`: `pms_reserva`, `cotizacion_file`… */
    negocio: string;
    id: string;
    nombre: string;
    /** Lo que el dominio quiere decir del asunto: canal, casitas, localizador. */
    detalle: string;
    /** Y-m-d de cuando empieza (reserva) o se creó (expediente). */
    fecha: string | null;
    motivo: MotivoSinTelefono;
    conversacionId: string | null;
    /** El número ya está en otra conversación: el arreglo es unir los hilos. */
    fusionSugerida: boolean;
    /** Ids para abrirlo: `{reserva, evento}` o `{file}`. */
    destino: Record<string, string>;
}

/** Qué le pasa, dicho para quien lo va a arreglar. */
export const ETIQUETA_MOTIVO: Record<MotivoSinTelefono, string> = {
    sin_conversacion: 'Sin datos de contacto',
    sin_telefono: 'Sin teléfono',
    solo_en_la_ficha: 'Número sin conectar',
    whatsapp_vetado: 'WhatsApp vetado',
};
