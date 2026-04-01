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
});
</script>

<template>
  <NotificationToast />

  <RouterView />
</template>