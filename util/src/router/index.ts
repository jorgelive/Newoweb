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
        {
            path: '/cotizaciones',
            name: 'cotizaciones_dashboard',
            component: () => import('../views/Cotizaciones/DashboardView.vue')
        },
        {
            path: '/cotizaciones/:id',
            name: 'cotizaciones_editor',
            component: () => import('../views/Cotizaciones/EditorView.vue'),
            props: true // Permite que Vue inyecte el :id directamente como prop en el componente
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