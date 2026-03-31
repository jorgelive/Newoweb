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
        {
            path: '/:pathMatch(.*)*',
            name: 'fallback',
            redirect: '/' // Redirigir a la raíz en vez de chat directamente
        }
    ]
})

export default router