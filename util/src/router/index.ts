import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
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
            redirect: '/chat'
        }
    ]
})

export default router