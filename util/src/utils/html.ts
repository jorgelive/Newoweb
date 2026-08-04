// src/utils/html.ts

/**
 * Escapa texto que va a inyectarse como HTML.
 *
 * Hace falta en los dos sitios donde el calendario construye marcado a mano:
 * `eventContent` de FullCalendar (que recibe una cadena, no una plantilla Vue)
 * y el contenido de los tooltips de tippy, que van con `allowHTML: true`.
 * Los valores salen de la BD, pero un nombre con `<` no debe poder romper —ni
 * inyectar— el marcado de alrededor.
 */
export function escaparHtml(valor: string): string {
    return valor.replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string
    ));
}
