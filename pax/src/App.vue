<script setup lang="ts">

import { ref, onMounted } from 'vue';
import { RouterView } from 'vue-router';
import { useMaestroStore } from '@/stores/maestroStore';

const maestroStore = useMaestroStore();

// ============================================================================
// AVISO DE NUEVA VERSIÓN
// ----------------------------------------------------------------------------
// Espejo del de `util/src/App.vue`. Pax también se instala como PWA y una
// pestaña de larga vida puede quedarse con el bundle viejo indefinidamente.
//
// La guarda de `teniaControlador` es imprescindible: el service worker se genera
// con `skipWaiting` + `clientsClaim`, y clientsClaim dispara `controllerchange`
// TAMBIÉN la primera vez que toma control de una pestaña que cargó sin
// controlador. Sin ella, el aviso sale al estrenar la app, que es justo cuando
// no hay ninguna versión nueva.
// ============================================================================
const updateAvailable = ref(false);

const refreshApp = () => {
  window.location.reload();
};

onMounted(() => {
  // 🔥 Disparamos la carga de idiomas al iniciar la aplicación.
  // No usamos 'await' para no bloquear el renderizado inicial;
  // que cargue en paralelo mientras el router busca la página.
  maestroStore.cargarConfiguracion();

  if ('serviceWorker' in navigator) {
    const teniaControlador = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (teniaControlador) updateAvailable.value = true;
    });
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
