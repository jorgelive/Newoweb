/**
 * Transforma una URL de imagen original en su versión filtrada por
 * LiipImagineBundle (/media/cache/resolve/{filtro}/{ruta}).
 *
 * El endpoint "resolve" genera el thumbnail al vuelo en el primer hit y
 * después redirige al caché estático, así que siempre es seguro usarlo.
 * Filtros: 'travel_cliente' (max 1600x900 webp, visor/cards) y
 * 'travel_thumb_admin' (220x124 recorte, grillas chicas).
 */
export const thumbUrl = (url: string | null | undefined, filter = 'travel_cliente'): string => {
    if (!url) return '';
    if (url.includes('/media/cache/')) return url; // ya pasó por un filtro
    const match = url.match(/^(https?:\/\/[^/]+)?\/(.+)$/);
    if (!match) return url;
    const host = match[1] || '';
    return `${host}/media/cache/resolve/${filter}/${match[2]}`;
};
