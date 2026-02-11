//src/router/index.ts

import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        {
            path: '/huesped/reserva/:localizador',
            // Patrón existente: snake_case
            name: 'pms_reserva',
            component: () => import('../views/pax/PmsReservaView.vue'),
            props: true
        },
        {
            // URL semántica: /guia/unidad/{uuid}
            path: '/huesped/guia/unidad/:uuid',
            // Nombre corregido para mantener consistencia con 'pms_reserva'
            name: 'guia_unidad',
            component: () => import('../views/pax/GuiaUnidadView.vue'),
            props: true
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'home',
            component: () => import('../views/HomeView.vue')
        }
    ]
})

export default router