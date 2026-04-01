<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { RouterView } from 'vue-router';
import NotificationToast from '@/components/NotificationToast.vue';
import { useNotificationStore } from '@/stores/notificationStore';

const notificationStore = useNotificationStore();
const showManualSubscriptionButton = ref(false);

const triggerSubscription = async () => {
  // Ocultamos de inmediato para mejorar UX
  showManualSubscriptionButton.value = false;
  try {
    const success = await notificationStore.subscribeToPushNotifications();
    // Si la plataforma exige otro clic nativo, lo volvemos a mostrar
    if (!success && Notification.permission !== 'denied') {
      showManualSubscriptionButton.value = true;
    }
  } catch (error) {
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
  }

  setTimeout(async () => {
    if (Notification.permission === 'granted') {
      await notificationStore.subscribeToPushNotifications();
    } else if (Notification.permission === 'default') {
      await triggerSubscription();
    }
  }, 3000);
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
        <button @click="showManualSubscriptionButton = false" class="px-3 py-1.5 text-xs font-semibold text-slate-400 hover:text-white transition-colors">
          Ahora no
        </button>
        <button @click="triggerSubscription" class="px-4 py-1.5 text-xs font-bold bg-[#376875] hover:bg-[#2c535d] text-white rounded-lg transition-colors">
          Permitir
        </button>
      </div>
    </div>
  </Transition>

  <RouterView />
</template>

<style scoped>
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translateY(20px) scale(0.95); }
</style>