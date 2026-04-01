<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { RouterView } from 'vue-router';
import NotificationToast from '@/components/NotificationToast.vue';
import { useNotificationStore } from '@/stores/notificationStore';

const notificationStore = useNotificationStore();

// Estado para mostrar el botón manual de notificaciones (Especial para iOS)
const showManualSubscriptionButton = ref(false);

/**
 * Función que limpia el globo rojo ("Badge") del icono de la PWA en el SO.
 */
const clearPwaBadge = () => {
  if ('clearAppBadge' in navigator) {
    navigator.clearAppBadge().catch((error) => {
      console.warn('No se pudo limpiar el badge del SO:', error);
    });
  }
};

/**
 * Event Listener: Detecta cuando el usuario regresa a la pestaña o abre la PWA.
 */
const handleVisibilityChange = () => {
  if (document.visibilityState === 'visible') {
    clearPwaBadge();
  }
};

/**
 * Intenta suscribir al usuario. Si el navegador lo bloquea (ej. iOS exigiendo un tap manual),
 * muestra el botón para que el usuario pueda hacerlo voluntariamente.
 */
const triggerSubscription = async () => {
  try {
    const success = await notificationStore.subscribeToPushNotifications();
    // Si la suscripción falla (probablemente porque iOS exige interacción del usuario),
    // o si el permiso está en "default", mostramos el botón.
    if (!success && Notification.permission !== 'denied') {
      showManualSubscriptionButton.value = true;
    } else {
      showManualSubscriptionButton.value = false;
    }
  } catch (error) {
    console.error("Fallo al suscribir, mostrando botón manual fallback.", error);
    showManualSubscriptionButton.value = true;
  }
};

/**
 * Al montar la aplicación base, inicializamos los oyentes globales.
 */
onMounted(() => {
  // --- 1. LIMPIEZA DEL BADGE EN PRIMER PLANO ---
  clearPwaBadge();
  document.addEventListener('visibilitychange', handleVisibilityChange);

  // --- 2. OYENTE DE MENSAJES DEL SERVICE WORKER ---
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
      if (event.data && event.data.type === 'PUSH_TO_STORE') {
        const payload = event.data.payload;
        notificationStore.addNotification({
          title: payload.title,
          body: payload.body,
          type: 'info',
          actionUrl: payload.actionUrl
        });
      }
    });
  }

  // --- 3. REGISTRO AUTOMÁTICO DE SUSCRIPCIÓN PUSH (Con fallback para iOS) ---
  setTimeout(() => {
    // Si el permiso ya está concedido, Notification.permission será 'granted'
    if (Notification.permission === 'granted') {
      // Ya tiene permiso, solo renovamos/aseguramos la suscripción silenciosamente
      notificationStore.subscribeToPushNotifications();
    } else if (Notification.permission === 'default') {
      // El navegador aún no sabe qué hacer. Intentamos de forma automática (Funciona en Android/Mac).
      triggerSubscription();
    }
    // Si está en 'denied', no hacemos nada, el usuario lo bloqueó intencionalmente.
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
        <button
            @click="showManualSubscriptionButton = false"
            class="px-3 py-1.5 text-xs font-semibold text-slate-400 hover:text-white transition-colors"
        >
          Ahora no
        </button>
        <button
            @click="triggerSubscription"
            class="px-4 py-1.5 text-xs font-bold bg-[#376875] hover:bg-[#2c535d] text-white rounded-lg transition-colors"
        >
          Permitir
        </button>
      </div>
    </div>
  </Transition>

  <RouterView />
</template>

<style scoped>
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.95);
}
</style>