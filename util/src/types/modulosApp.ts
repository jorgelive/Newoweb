// ============================================================================
// CATÁLOGO DE MÓDULOS DEL PORTAL
//
// Fuente única de "qué apps hay y cómo se ven". La consumen:
//   - HomeView.vue        → el mosaico del portal
//   - AppSwitcher.vue     → el salto entre módulos desde la cabecera de cada vista
//
// Antes vivía dentro de HomeView, y por eso el único camino entre dos módulos
// era pasar por el inicio. Al añadir un módulo, se agrega aquí y aparece en los
// dos sitios a la vez.
//
// Son dos negocios con equipos y rutinas distintas —el alojamiento (PMS) y la
// agencia de viajes—, de ahí la agrupación: teal el PMS, naranja Viajes.
// ============================================================================

export interface ModuloApp {
    /** Ruta del router (también identifica el módulo activo, por prefijo). */
    to: string;
    title: string;
    /** Clase de Font Awesome, sin el prefijo `fas`. */
    icon: string;
    desc: string;
    iconBg: string;
    iconColor: string;
    /** Color de marca del módulo: filo en las piezas planas, fondo en la grande. */
    bar: string;
    titleHover: string;
    /**
     * Puerta de entrada del bloque (la de uso diario). En el mosaico del portal
     * es la pieza 2x2 en color pleno; en el selector, la primera de su grupo.
     */
    destacado?: boolean;
}

export interface GrupoModulos {
    titulo: string;
    desc: string;
    /** Color del encabezado del grupo (identidad del negocio). */
    color: string;
    modulos: ModuloApp[];
}

export const MODULOS_APP: readonly GrupoModulos[] = [
    {
        titulo: 'Alojamiento',
        desc: 'PMS · Casitas',
        color: 'text-[#376875]',
        modulos: [
            {
                to: '/reservas', title: 'Reservas', icon: 'fa-calendar-days',
                desc: 'Calendario PMS: estancias, bloqueos y disponibilidad por unidad.',
                iconBg: 'bg-[#376875]', iconColor: 'text-white',
                bar: 'bg-[#376875]', titleHover: 'group-hover:text-[#376875]',
                destacado: true,
            },
            {
                to: '/tarifas', title: 'Tarifas', icon: 'fa-tags',
                desc: 'Rangos de precio y estancia mínima por unidad, con push automático a Beds24.',
                iconBg: 'bg-[#376875]/10', iconColor: 'text-[#376875]',
                bar: 'bg-[#376875]', titleHover: 'group-hover:text-[#376875]',
            },
            {
                to: '/chat', title: 'Chat Inbox', icon: 'fa-comments',
                desc: 'Mensajería omnicanal con huéspedes: Beds24, Airbnb y WhatsApp en una sola bandeja.',
                iconBg: 'bg-slate-900', iconColor: 'text-white',
                bar: 'bg-slate-900', titleHover: 'group-hover:text-slate-900',
            },
        ],
    },
    {
        titulo: 'Viajes',
        desc: 'Agencia · Tours',
        color: 'text-[#E07845]',
        modulos: [
            {
                to: '/cotizacion', title: 'Cotizaciones', icon: 'fa-file-invoice-dollar',
                desc: 'Motor de armado de propuestas, tarifas y cálculo financiero por expediente.',
                iconBg: 'bg-[#E07845]', iconColor: 'text-white',
                bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
                destacado: true,
            },
            {
                to: '/operacion', title: 'Operaciones', icon: 'fa-car-side',
                desc: 'Centro de operaciones: logística, proveedores y ejecución día a día.',
                iconBg: 'bg-[#E07845]/10', iconColor: 'text-[#E07845]',
                bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
            },
            {
                to: '/catalogo', title: 'Catálogo de Tours', icon: 'fa-book-open',
                desc: 'Producto pre-armado por segmento, listo para cotizar en minutos.',
                iconBg: 'bg-[#E07845]/10', iconColor: 'text-[#E07845]',
                bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
            },
        ],
    },
];

/**
 * Módulo al que pertenece una ruta, por PREFIJO: `/cotizacion/<id>/version/<x>`
 * sigue siendo Cotizaciones. Devuelve `null` en el portal (`/`), que no es un
 * módulo sino su índice.
 */
export function moduloDeRuta(path: string): ModuloApp | null {
    for (const grupo of MODULOS_APP) {
        for (const modulo of grupo.modulos) {
            if (path === modulo.to || path.startsWith(`${modulo.to}/`)) return modulo;
        }
    }
    return null;
}
