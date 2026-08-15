// ============================================================================
// ESTADO DE UN MENSAJE — el estrechamiento que la API no puede declarar
//
// El backend expone `status` como `string` porque sale de un getter, y OpenAPI no
// ve un enum ahí (es el caso descrito en CLAUDE.md, §"los tipos de la API se
// GENERAN"). Del lado del front sí sabemos que es una lista cerrada: son los ocho
// estados que MessageStatusIcon sabe pintar.
//
// Espejo de los estados que emiten `Message` y las colas de envío en
// `src/Message/`. Si allí nace un estado nuevo, esta unión y el icono se tocan
// juntos — si no, el mensaje llega y no se pinta nada.
// ============================================================================

export type EstadoMensaje =
    | 'pending'
    | 'queued'
    | 'sent'
    | 'delivered'
    | 'read'
    | 'failed'
    | 'received'
    | 'cancelled';

const ESTADOS: readonly EstadoMensaje[] = [
    'pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'received', 'cancelled',
];

/**
 * Traduce lo que venga de la API a un estado pintable.
 *
 * Hace falta un normalizador y no un simple cast porque el valor llega como `string`
 * abierto: un estado nuevo en el backend, o un `null` de una fila a medio migrar,
 * pasarían el compilador y reventarían al buscar el icono. Lo desconocido cae en
 * `pending`, que es el icono más neutro — se ve «en camino», no un error inventado.
 *
 * Sustituye al `validator` que el componente tenía cuando estaba en JavaScript: al
 * pasarlo a TypeScript esa comprobación en tiempo de ejecución se habría perdido, y
 * el compilador no cubre lo que entra por la red.
 */
export function aEstadoMensaje(valor: string | null | undefined): EstadoMensaje {
    return ESTADOS.includes(valor as EstadoMensaje) ? (valor as EstadoMensaje) : 'pending';
}
