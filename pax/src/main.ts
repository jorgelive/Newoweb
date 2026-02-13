import { createApp } from 'vue'
import { createPinia } from 'pinia'
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'
import '@fortawesome/fontawesome-free/css/all.min.css'
import App from './App.vue'
import router from './router'
import '@/assets/main.css'

const app = createApp(App)

const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

app.use(pinia)
app.use(router)
app.mount('#app')

// ✅ SOLO PRODUCCIÓN
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    import('virtual:pwa-register').then(({ registerSW }) => {
        registerSW({
            immediate: true,
            onRegisteredSW() {
                console.log('✅ PWA: Service Worker registrado')
            },
            onRegisterError(error) {
                console.error('❌ PWA: Error de registro:', error)
            },
        })
    })
}