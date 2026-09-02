// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router'

/**
 * Primeros segmentos que NUNCA pueden ser el slug de un establecimiento.
 *
 * `huesped` encabeza la lista porque de ahí cuelga /huesped/reserva/:localizador,
 * la URL que se reparte a los clientes y que va en correos ya enviados: ningún
 * slug nuevo puede llegar a secuestrarla.
 */
const PREFIJOS_RESERVADOS: readonly string[] = ['huesped', 'file', 'catalogo', 'pago'];

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        // -----------------------------------------------------------------
        // COTIZACIÓN — Portada del expediente (por localizador)
        // El "?" lo hace opcional. Si no está, muestra el buscador
        // -----------------------------------------------------------------
        {
            path: '/file/:localizador?',
            name: 'file_publica',
            component: () => import('@/views/cotizacion/PaxFilePortadaView.vue'),
            props: true
        },

        // -----------------------------------------------------------------
        // COTIZACIÓN — Guía día a día de una PROPUESTA
        //
        // ⚠️ «Propuesta», no «versión»: no se sustituyen entre sí. Un expediente puede tener
        // varias vivas a la vez, y el cliente puede aprobar más de una porque a veces son
        // COMPLEMENTARIAS —una la parte de Lima y otra la de Bolivia—. `versionesParaCliente`
        // lleva por cada una su propio `titulo` y su tramo de fechas: eso es una propuesta, no
        // una revisión. Renombrado el 02/09/2026.
        //
        // El campo de la API y la columna siguen llamándose `version`: es el nombre heredado y
        // cambiarlo es otro trabajo. Aquí se traduce en la frontera.
        // -----------------------------------------------------------------
        {
            path: '/file/:localizador/p/:propuesta',
            name: 'cotizacion_guia',
            component: () => import('@/views/cotizacion/PaxCotizacionGuiaView.vue'),
            props: true
        },

        // -----------------------------------------------------------------
        // CATÁLOGO DE TOURS — Escaparate público (por localizador)
        // -----------------------------------------------------------------
        {
            path: '/catalogo/:localizador',
            name: 'catalogo_publico',
            component: () => import('@/views/cotizacion/PaxCatalogoPortadaView.vue'),
            props: true
        },

        // -----------------------------------------------------------------
        // CATÁLOGO DE TOURS — Guía día a día de un tour (reusa la vista guía)
        // -----------------------------------------------------------------
        {
            path: '/catalogo/:localizador/p/:propuesta',
            name: 'catalogo_guia',
            component: () => import('@/views/cotizacion/PaxCotizacionGuiaView.vue'),
            props: true,
            meta: { esCatalogo: true }
        },

        // -----------------------------------------------------------------
        // COBRO — Página de pago por pasarela: /pago/{token}
        //
        // El parámetro es el TOKEN del enlace (32 bytes aleatorios), no el UUID
        // de la fila: el v7 lleva marca de tiempo y sería enumerable. Ver
        // `App\Finanzas\Entity\FinEnlacePago::$token`.
        //
        // `pago` está en PREFIJOS_RESERVADOS para que ningún slug de
        // establecimiento pueda secuestrar una URL de cobro ya enviada.
        // -----------------------------------------------------------------
        {
            path: '/pago/:token',
            name: 'pago_enlace',
            component: () => import('@/views/pago/PaxPagoView.vue'),
            props: true
        },

        // -----------------------------------------------------------------
        // 1. RUTA DE RESERVA (Entrada Clásica)
        // -----------------------------------------------------------------
        {
            path: '/huesped/reserva/:localizador',
            name: 'pms_reserva',
            component: () => import('@/views/huesped/PmsReservaView.vue'),
            props: true
        },

        // -----------------------------------------------------------------
        // 1.b GUÍA DE UNA ESTANCIA — /huesped/reserva/RPR8U8/casita-7
        //
        // Hija de la ruta canónica, no una rama hermana: el enlace que ya
        // circula por correo es el ancla de todo lo demás (ver
        // docs/PmsGuiaHuesped.md §5). El segundo segmento es el SLUG de la
        // unidad, no el UUID del evento: el identificador que ve el cliente es
        // el localizador que ya tiene, y el UUID interno no sale de la casa.
        //
        // Va después de `/huesped/reserva/:localizador` por claridad; no
        // compiten, uno tiene un segmento más que el otro.
        // -----------------------------------------------------------------
        // Ruta corta: sin unidad, el provider sirve la primera estancia
        // cronológica. Es la salida para una unidad sin slug (creada antes de
        // PmsSlugListener) y para un enlace escrito a mano. Va delante de la
        // versión con `:unidad`, aunque no compitan: vue-router puntúa el
        // segmento estático por encima del dinámico, y ningún slug de unidad
        // puede ser "guia" a secas porque siempre lleva el prefijo del
        // establecimiento.
        {
            path: '/huesped/reserva/:localizador/guia',
            name: 'guia_huesped_corta',
            component: () => import('@/views/huesped/HuespedGuiaView.vue'),
            props: true
        },
        {
            path: '/huesped/reserva/:localizador/:unidad',
            name: 'guia_huesped',
            component: () => import('@/views/huesped/HuespedGuiaView.vue'),
            props: true
        },

        // -----------------------------------------------------------------
        // 2. CATÁLOGO PÚBLICO DE UNA UNIDAD — .pe/casita/casita-1
        //
        // Va la ÚLTIMA antes del fallback por ser la más genérica: dos
        // segmentos libres. vue-router puntúa los segmentos estáticos por
        // encima de los dinámicos, así que /file/XJ922M y /catalogo/... ganan
        // igualmente; declararla aquí lo deja explícito y evita sustos si
        // mañana se añade otra ruta de dos niveles.
        //
        // Las rutas con primer segmento ESTÁTICO (/file/…, /catalogo/…) ganan
        // solas: vue-router las puntúa por encima de las dinámicas. El único
        // hueco es una URL de dos segmentos bajo un prefijo reservado —
        // /huesped/reserva sin localizador, típico de un enlace cortado al
        // copiar—, que si no caería aquí y pintaría "alojamiento no
        // disponible" en vez del inicio. De eso se ocupa `beforeEnter`.
        //
        // Se filtra con un guard y no con un regex en el `path`: vue-router
        // escapa los paréntesis anidados dentro del patrón de un parámetro,
        // así que un lookahead como `(?!(?:huesped|file)(?:/|$))` no llega a
        // compilar. Comprobado, no supuesto.
        //
        // Un slug inexistente NO cae al 404 del router: la vista pide el
        // endpoint, recibe 404 y pinta su propio "alojamiento no disponible".
        // -----------------------------------------------------------------
        {
            path: '/:establecimiento/:unidad',
            name: 'catalogo_unidad',
            component: () => import('@/views/huesped/CatalogoUnidadView.vue'),
            props: true,
            beforeEnter: (to) => {
                // Comparación por segmento completo, no por prefijo: slugs
                // legítimos como "huespedes" o "filemaker" tienen que pasar.
                const primero = String(to.params.establecimiento || '');
                return PREFIJOS_RESERVADOS.includes(primero) ? { name: 'home' } : true;
            }
        },

        // -----------------------------------------------------------------
        // 3. HOME & FALLBACK
        // -----------------------------------------------------------------
        {
            path: '/:pathMatch(.*)*',
            name: 'home',
            component: () => import('@/views/HomeView.vue')
        }
    ]
})

export default router