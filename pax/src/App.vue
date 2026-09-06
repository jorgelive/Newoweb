<script setup lang="ts">

import { ref, onMounted } from 'vue';
import { RouterView } from 'vue-router';
import { useMaestroStore } from '@/stores/maestroStore';

const maestroStore = useMaestroStore();

// ============================================================================
// AVISO DE NUEVA VERSIÓN
// ----------------------------------------------------------------------------
// Espejo del de `util/src/App.vue`, donde está el porqué largo. Pax también se
// instala como PWA y una pestaña de larga vida puede quedarse con el bundle viejo
// indefinidamente.
//
// El SW se genera con `skipWaiting: false` (ver vite.config.ts): el nuevo espera
// en `waiting` hasta que el cartel se lo pide. Pulsar NO recarga —eso serviría la
// versión vieja otra vez—, manda `SKIP_WAITING`; la recarga llega sola con el
// `controllerchange` que sigue.
//
// Quien pregunta si hay versión nueva es el `reg.update()` periódico de
// `templates/pax/app.html.twig`. Sin él no hay `updatefound` y el cartel no sale.
// ============================================================================
const updateAvailable = ref(false);

let registroSw: ServiceWorkerRegistration | null = null;
let recargando = false;

const refreshApp = () => {
  if (registroSw?.waiting) {
    registroSw.waiting.postMessage({ type: 'SKIP_WAITING' });
    return;
  }

  window.location.reload();
};

/**
 * `navigator.serviceWorker.controller` distingue una ACTUALIZACIÓN del estreno de
 * la PWA: sin controlador previo no hay versión nueva, hay una instalación.
 */
const vigilarActualizaciones = async (): Promise<void> => {
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (recargando) return;
    recargando = true;
    window.location.reload();
  });

  registroSw = await navigator.serviceWorker.getRegistration() ?? null;
  if (!registroSw) return;

  if (registroSw.waiting && navigator.serviceWorker.controller) {
    updateAvailable.value = true;
  }

  registroSw.addEventListener('updatefound', () => {
    const entrante = registroSw?.installing;
    if (!entrante) return;

    entrante.addEventListener('statechange', () => {
      if (entrante.state === 'installed' && navigator.serviceWorker.controller) {
        updateAvailable.value = true;
      }
    });
  });
};

onMounted(() => {
  // 🔥 Disparamos la carga de idiomas al iniciar la aplicación.
  // No usamos 'await' para no bloquear el renderizado inicial;
  // que cargue en paralelo mientras el router busca la página.
  maestroStore.cargarConfiguracion();

  if ('serviceWorker' in navigator) {
    void vigilarActualizaciones();
  }
});
</script>

<template>
  <Transition name="fade-slide">
    <div
        v-if="updateAvailable"
        class="fixed top-4 left-1/2 -translate-x-1/2 z-[9999] bg-slate-900 text-white px-6 py-3 rounded-full shadow-2xl flex items-center gap-4 font-bold cursor-pointer hover:bg-slate-700 transition-colors"
        @click="refreshApp"
    >
      <i class="fas fa-sync-alt fa-spin"></i>
      <span class="text-sm">Nueva versión disponible. Toca para actualizar.</span>
    </div>
  </Transition>

  <RouterView />
</template>

<style scoped>
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translateY(-20px) scale(0.95); }
</style>
