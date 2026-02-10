//src/main.ts

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'

import '@fortawesome/fontawesome-free/css/all.min.css'

// TypeScript se quejará de este import si no tienes el archivo de declaración (ver abajo)
import App from './App.vue'
import router from './router' // Automáticamente busca index.ts

import '@/assets/main.css'; // Si tienes estilos globales

const app = createApp(App)

// 1. Configurar Pinia + Persistencia (Offline)
const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

// 2. Instalar plugins
app.use(pinia)
app.use(router)

// 3. Montar la App
app.mount('#app')