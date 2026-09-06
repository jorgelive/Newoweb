<script setup lang="ts">

import { ref, computed, onMounted } from 'vue';
import { RouterView, useRoute } from 'vue-router';
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

const route = useRoute();

/**
 * ⚠️ **El cartel NO sale mientras se está pagando.**
 *
 * Se pinta sobre `<RouterView />`, así que salía en todas las rutas — incluida la de cobro, y
 * justo encima del importe, con un icono girando: en una pantalla de pago eso se lee como si
 * fuera parte del pago. Y tocarlo recarga.
 *
 * Lo que cuesta según cuándo se toque:
 *
 * | Momento | Qué pasa |
 * |---|---|
 * | antes de pulsar Pagar | inofensivo |
 * | con el cobro en vuelo | el cargo puede existir en Culqi y perderse la respuesta |
 * | durante el reto 3DS | el reto muere; hasta once minutos de espera tirados |
 *
 * 🔥 **Y el riesgo se estrenó al arreglar el propio cartel.** Antes casi no salía en `pax`,
 * porque nadie preguntaba si había versión nueva; con el `reg.update()` periódico de
 * `templates/pax/app.html.twig` cualquier pestaña se entera de un despliegue en menos de un
 * minuto. Pasó de inalcanzable a rutinario sin que cambiara una línea de esta lógica.
 *
 * No se pierde nada aplazándolo: una pantalla de cobro dura minutos, el SW nuevo sigue esperando
 * en `waiting` y el cartel aparece en cuanto la persona navegue a otra cosa.
 */
const enPantallaDeCobro = computed(() => route.name === 'pago_enlace');

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
        v-if="updateAvailable && !enPantallaDeCobro"
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
