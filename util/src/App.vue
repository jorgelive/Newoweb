<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { RouterView } from 'vue-router';
import NotificationToast from '@/components/NotificationToast.vue';
import { useNotificationStore } from '@/stores/notificationStore';
import { useNoLeidosStore } from '@/stores/chat/noLeidosStore';
import GlobalLoginModal from "@/components/GlobalLoginModal.vue";

const notificationStore = useNotificationStore();
const noLeidosStore = useNoLeidosStore();
const showManualSubscriptionButton = ref(false);

// ============================================================================
// AVISO DE NUEVA VERSIÓN
// ----------------------------------------------------------------------------
// Vive aquí y no en ChatView: si estás en Reservas con la pestaña abierta desde
// ayer, el código viejo te afecta igual y el aviso también te sirve.
//
// La guarda de `teniaControlador` no es opcional. El service worker se genera
// con `skipWaiting` + `clientsClaim`, y clientsClaim hace que `controllerchange`
// dispare TAMBIÉN la primera vez que el SW toma control de una pestaña que cargó
// sin controlador —o sea, recién instalada la app—. Sin la guarda salía "nueva
// versión disponible" al estrenar la PWA, que es justo cuando no la hay.
// ============================================================================
const updateAvailable = ref(false);

const refreshApp = (): void => {
    window.location.reload();
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

    // Si ya hay controlador, cualquier cambio posterior ES una versión nueva.
    // Si no lo hay, el primer `controllerchange` es solo el estreno del SW.
    const teniaControlador = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (teniaControlador) updateAvailable.value = true;
    });
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