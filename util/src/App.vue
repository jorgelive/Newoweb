<script setup lang="ts">
import { onMounted } from 'vue';
import { RouterView } from 'vue-router';
import NotificationToast from '@/components/NotificationToast.vue';
import { useNotificationStore } from '@/stores/notificationStore';

const notificationStore = useNotificationStore();

/**
 * Al montar la aplicación base, inicializamos el oyente global del Service Worker.
 * ¿Por qué aquí?: Porque App.vue nunca se desmonta mientras la PWA esté abierta,
 * garantizando que siempre atraparemos los eventos Push provenientes de push-sw.js
 * sin importar en qué ruta se encuentre el usuario.
 */
onMounted(() => {
  // --- 1. OYENTE DE MENSAJES DEL SERVICE WORKER ---
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

  // --- 2. REGISTRO AUTOMÁTICO DE SUSCRIPCIÓN PUSH ---
  // Como Symfony ya validó al usuario mediante el firewall antes de cargar Vue,
  // intentamos registrar el navegador silenciosamente a los 3 segundos.
  setTimeout(async () => {
    // Si no hay VAPID keys en producción, la función fallará silenciosamente
    // según el fallback que programamos en el store.
    await notificationStore.subscribeToPushNotifications();
  }, 3000);
});
</script>

<template>
  <NotificationToast />

  <RouterView />
</template>