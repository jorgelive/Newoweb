// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router'

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
        // COTIZACIÓN — Guía día a día de una propuesta (versión)
        // -----------------------------------------------------------------
        {
            path: '/file/:localizador/v/:version',
            name: 'cotizacion_guia',
            component: () => import('@/views/cotizacion/PaxCotizacionGuiaView.vue'),
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
        // 2. RUTA PÚBLICA (QR / Demo / Link Genérico)
        // Recibe: uuidUnidad
        // Modo: 'public' (Solo info básica, sin códigos)
        // -----------------------------------------------------------------
        {
            path: '/huesped/unidad/:uuidUnidad',
            name: 'guia_publica',
            component: () => import('@/views/huesped/GuiaUnidadView.vue'),
            props: { mode: 'public' }
        },

        // -----------------------------------------------------------------
        // 3. RUTA PRIVADA (Huésped / Link Seguro)
        // Recibe: uuidEvento
        // Modo: 'guest' (Valida fechas y entrega códigos)
        // -----------------------------------------------------------------
        {
            path: '/huesped/evento/:uuidEvento',
            name: 'guia_evento',
            component: () => import('@/views/huesped/GuiaUnidadView.vue'),
            props: { mode: 'guest' }
        },

        // -----------------------------------------------------------------
        // 4. HOME & FALLBACK
        // -----------------------------------------------------------------
        {
            path: '/:pathMatch(.*)*',
            name: 'home',
            component: () => import('@/views/HomeView.vue')
        }
    ]
})

export default router