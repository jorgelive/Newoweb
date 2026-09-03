//src/main.ts
import { createApp } from 'vue'
import { tooltipTactil } from '@/directives/tooltipTactil'
import { createPinia } from 'pinia'
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'
import '@fortawesome/fontawesome-free/css/all.min.css'
import App from '@/App.vue'
import router from '@/router'
import '@/assets/main.css'

const app = createApp(App)

const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

// El `title` de los botones-icono, visible también en táctil (pulsación larga).
// Global porque el patrón «fila de iconos» se repite en varias vistas.
app.directive('tooltip-tactil', tooltipTactil)

app.use(pinia)
app.use(router)
app.mount('#app')