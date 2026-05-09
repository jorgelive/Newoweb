// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        {
            path: '/',
            name: 'home',
            component: () => import('../views/HomeView.vue')
        },
        {
            path: '/chat',
            name: 'chat_home',
            component: () => import('../views/ChatView.vue')
        },
        {
            path: '/chat/:conversationId',
            name: 'chat_conversation',
            component: () => import('../views/ChatView.vue'),
            props: true
        },
        // ============================================================================
        // MÓDULO DE COTIZACIONES
        // ============================================================================

        // 1. Dashboard: Lista de todos los Files (Expedientes)
        {
            path: '/cotizaciones',
            name: 'cotizaciones_dashboard',
            component: () => import('../views/Cotizaciones/DashboardView.vue')
        },

        // 2. Sala del File (NUEVO): Datos del cliente y lista de versiones (V1, V2...)
        {
            path: '/cotizaciones/:id',
            name: 'file_detalle',
            // Asegúrate de que este archivo exista con el nombre que le dimos en el paso anterior
            component: () => import('../views/Cotizaciones/FileDetalle.vue'),
            props: true
        },

        // 3. Motor Operativo: Edición de una versión específica de la cotización
        {
            path: '/cotizaciones/:fileId/version/:cotizacionId',
            name: 'cotizaciones_editor',
            // Tu vista del Editor / Motor Operativo
            component: () => import('../views/Cotizaciones/CotizacionEditorView.vue'),
            props: true
        },

        // ============================================================================
        // FALLBACK (Rutas no encontradas)
        // ============================================================================
        {
            path: '/:pathMatch(.*)*',
            name: 'fallback',
            redirect: '/' // Redirigir a la raíz en vez de chat directamente
        }
    ]
})

export default router