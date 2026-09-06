<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { RouterView } from 'vue-router';
import NotificationToast from '@/components/NotificationToast.vue';
import { useNotificationStore } from '@/stores/notificationStore';
import { useNoLeidosStore } from '@/stores/chat/noLeidosStore';
import GlobalLoginModal from "@/components/GlobalLoginModal.vue";
import GestoDeRecarga from '@/components/common/GestoDeRecarga.vue';
import AsistenteFlotante from '@/components/common/AsistenteFlotante.vue';

const notificationStore = useNotificationStore();
const noLeidosStore = useNoLeidosStore();
const showManualSubscriptionButton = ref(false);

// ============================================================================
// AVISO DE NUEVA VERSIÓN
// ----------------------------------------------------------------------------
// Vive aquí y no en ChatView: si estás en Reservas con la pestaña abierta desde
// ayer, el código viejo te afecta igual y el aviso también te sirve.
//
// El cartel es la ÚNICA puerta de la actualización, y ahora de verdad: el SW se
// genera con `skipWaiting: false` (ver vite.config.ts), así que el nuevo se queda
// en `waiting` hasta que esta pantalla se lo pide. Antes estaba en `true` y el SW
// nuevo tomaba el control solo —caché nueva bajo una página vieja—; el cartel sólo
// pedía permiso para la recarga, que llegaba tarde.
//
// No se recarga por nuestra cuenta, y no es cosa de iOS: la recarga automática
// dispara también en la primera visita, puede entrar en bucle y tira por delante
// lo que estuvieras haciendo. Ver docs/PwaNotificaciones.md §5.1.
//
// Espejo de pax/src/App.vue. Si cambia el mecanismo, cambian los dos.
// ============================================================================
const updateAvailable = ref(false);

/** El registro vivo del SW: hace falta para hablar con el que espera. */
let registroSw: ServiceWorkerRegistration | null = null;

/** Una sola recarga. `controllerchange` puede llegar más de una vez. */
let recargando = false;

/**
 * Pulsar el cartel NO recarga: le pide al SW en espera que tome el mando.
 *
 * La recarga viene después, sola, cuando el nuevo toma el control y dispara
 * `controllerchange`. Recargar aquí serviría la versión vieja otra vez, porque el
 * SW que responde sigue siendo el de antes hasta ese momento.
 *
 * Si no hay nadie esperando —el cartel salió por otra vía, o el SW ya activó—
 * queda la recarga a secas: peor no deja las cosas.
 */
const refreshApp = (): void => {
    if (registroSw?.waiting) {
        registroSw.waiting.postMessage({ type: 'SKIP_WAITING' });
        return;
    }

    window.location.reload();
};

/**
 * Enciende el cartel cuando hay una versión nueva instalada y esperando.
 *
 * `navigator.serviceWorker.controller` es lo que distingue una ACTUALIZACIÓN del
 * estreno de la PWA: sin controlador previo no hay nada que actualizar, hay una
 * instalación. Es la misma guarda que antes se llamaba `teniaControlador`, ahora
 * puesta donde de verdad decide.
 *
 * Quién pregunta si hay versión nueva no es esto: es el `reg.update()` periódico
 * del shell (`scripts/pwa-postbuild.mjs`). Sin él no habría `updatefound` y el
 * cartel no saldría nunca — que fue exactamente el fallo de agosto de 2026.
 */
const vigilarActualizaciones = async (): Promise<void> => {
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (recargando) return;
        recargando = true;
        window.location.reload();
    });

    registroSw = await navigator.serviceWorker.getRegistration() ?? null;
    if (!registroSw) return;

    // Puede haber uno esperando desde antes de que montara la app.
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

// ============================================================================
// RESUMEN DE NO LEÍDOS
// ----------------------------------------------------------------------------
// Se refresca al arrancar y cada vez que la app vuelve a primer plano: el badge
// del icono y los contadores tienen que estar bien en cualquier vista, no solo
// en las que abren un túnel de Mercure. Es una sola petición agregada.
// ============================================================================
const handleVisibilityChange = (): void => {
    if (document.visibilityState === 'visible') void noLeidosStore.refrescar();
};

const triggerSubscription = async () => {
  // Ocultamos de inmediato para mejorar UX
  showManualSubscriptionButton.value = false;
  try {
    const success = await notificationStore.subscribeToPushNotifications();
    // Si la plataforma exige otro clic nativo, lo volvemos a mostrar
    if (!success && Notification.permission !== 'denied') {
      showManualSubscriptionButton.value = true;
    }
  } catch {
    showManualSubscriptionButton.value = true;
  }
};

onMounted(() => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
      if (event.data && event.data.type === 'PUSH_TO_STORE') {
        notificationStore.addNotification(event.data.payload);
      }
    });

    void vigilarActualizaciones();
  }

  void noLeidosStore.refrescar();
  document.addEventListener('visibilitychange', handleVisibilityChange);

  setTimeout(async () => {
    if (Notification.permission === 'granted') {
      await notificationStore.subscribeToPushNotifications();
    } else if (Notification.permission === 'default') {
      await triggerSubscription();
    }
  }, 3000);
});

onUnmounted(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange);
});
</script>

<template>
  <!-- Tirar para recargar. Va en el shell porque el gesto nativo sólo existía en el Home —la
       única vista cuya raíz scrollea— y cablearlo vista por vista, con doce scrollers en el
       editor de cotizaciones, sería olvidarse en alguna. Ver el componente. -->
  <GestoDeRecarga />

  <!-- El asistente, en el armazón y no en una vista: lo que se le pregunta —«¿quién está en el
       grupo 6?»— es justo lo que hace falta SIN soltar la pantalla en la que estás. Ver el
       componente. -->
  <AsistenteFlotante />

  <NotificationToast />

  <Transition name="fade-slide">
    <div
        v-if="updateAvailable"
        class="fixed top-4 left-1/2 -translate-x-1/2 z-[9999] bg-[#376875] text-white px-6 py-3 rounded-full shadow-2xl flex items-center gap-4 font-bold cursor-pointer hover:bg-[#2c535d] transition-colors"
        @click="refreshApp"
    >
      <i class="fas fa-sync-alt fa-spin"></i>
      <span class="text-sm">Nueva versión disponible. Clic para actualizar.</span>
    </div>
  </Transition>

  <Transition name="fade-slide">
    <div
        v-if="showManualSubscriptionButton"
        class="fixed bottom-4 right-4 z-[9999] bg-slate-900 text-white p-4 rounded-2xl shadow-2xl border border-slate-700 max-w-sm flex flex-col gap-3"
    >
      <div class="flex items-start gap-3">
        <i class="fas fa-bell text-[#E07845] text-xl mt-1"></i>
        <div>
          <h4 class="font-bold text-sm">Activar Notificaciones</h4>
          <p class="text-xs text-slate-400 mt-1">Para recibir avisos de nuevos mensajes cuando la app esté cerrada, necesitamos tu permiso.</p>
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-1">
        <button @click="showManualSubscriptionButton = false" class="px-3 py-1.5 text-xs font-semibold text-slate-400 hover:text-white transition-colors">
          Ahora no
        </button>
        <button @click="triggerSubscription" class="px-4 py-1.5 text-xs font-bold bg-[#376875] hover:bg-[#2c535d] text-white rounded-lg transition-colors">
          Permitir
        </button>
      </div>
    </div>
  </Transition>
  <GlobalLoginModal />
  <RouterView />
</template>

<style scoped>
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translateY(20px) scale(0.95); }
</style>