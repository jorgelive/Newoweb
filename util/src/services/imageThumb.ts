/**
 * Transforma una URL de imagen original en su versión filtrada por
 * LiipImagineBundle (/media/cache/resolve/{filtro}/{ruta}).
 *
 * El endpoint "resolve" genera el thumbnail al vuelo en el primer hit y
 * después redirige al caché estático, así que siempre es seguro usarlo.
 * Filtros disponibles: ver config/packages/liip_imagine.yaml
 * (ej: 'pms_thumb_admin' 150x150, 'pms_compress_initial' 1600px webp).
 */
export const thumbUrl = (url: string | null | undefined, filter = 'pms_thumb_admin'): string => {
    if (!url) return '';
    if (url.includes('/media/cache/')) return url; // ya pasó por un filtro
    const match = url.match(/^(https?:\/\/[^/]+)?\/(.+)$/);
    if (!match) return url;
    const host = match[1] || '';
    return `${host}/media/cache/resolve/${filter}/${match[2]}`;
};
