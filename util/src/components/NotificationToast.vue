<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useNotificationStore, AppNotification } from '@/stores/notificationStore';

const notificationStore = useNotificationStore();
const router = useRouter();

/**
 * Maneja el clic sobre una notificación interactiva.
 * Redirige al usuario a la ruta de acción usando Vue Router y cierra el Toast.
 * * Se implementa una sanitización defensiva para evitar errores críticos de DNS (NXDOMAIN)
 * causados cuando el Service Worker o el Store envían propiedades faltantes que JS
 * convierte en la cadena literal 'undefined'.
 *
 * @param {AppNotification} notif La notificación que ha sido clickeada.
 * @returns {void}
 */
const handleActionClick = (notif: AppNotification): void => {
  if (notif.actionUrl) {
    let targetRoute = String(notif.actionUrl);

    // 1. Escudo anti-undefined: Si la ruta se corrompió, aplicamos un fallback seguro al Inbox.
    if (targetRoute.includes('undefined') || targetRoute.includes('unknown')) {
      console.warn('[Toast] Ruta corrupta interceptada antes del Vue Router. Aplicando fallback a /chat. Ruta original:', targetRoute);
      targetRoute = '/chat';
    }
    // 2. Prevención de fuga de SPA: Si llega una URL absoluta, extraemos solo el path relativo.
    else if (targetRoute.startsWith('http')) {
      try {
        const urlObj = new URL(targetRoute);
        targetRoute = urlObj.pathname + urlObj.search;
      } catch (e) {
        targetRoute = '/chat';
      }
    }

    // Ejecutamos la navegación con la ruta purificada
    router.push(targetRoute);
    notificationStore.removeNotification(notif.id);
  }
};
</script>

<template>
  <div class="fixed top-5 right-4 md:right-8 z-[9999] flex flex-col gap-3 pointer-events-none w-[calc(100vw-2rem)] sm:w-96 max-w-sm">
    <TransitionGroup name="toast" tag="div" class="flex flex-col gap-3">
      <div
          v-for="notif in notificationStore.getNotifications"
          :key="notif.id"
          class="pointer-events-auto flex items-start gap-3 p-4 bg-white rounded-2xl shadow-2xl border-l-4 overflow-hidden relative transition-all"
          :class="{
          'border-[#376875] cursor-pointer hover:bg-slate-50 hover:scale-[1.02]': notif.type === 'info' && notif.actionUrl,
          'border-[#376875] cursor-default': notif.type === 'info' && !notif.actionUrl,
          'border-green-500 cursor-default': notif.type === 'success',
          'border-red-500 cursor-default': notif.type === 'error',
          'border-[#E07845] cursor-default': notif.type === 'warning',
        }"
          @click="handleActionClick(notif)"
      >
        <div class="shrink-0 mt-0.5">
          <i v-if="notif.type === 'success'" class="fas fa-check-circle text-green-500 text-xl"></i>
          <i v-else-if="notif.type === 'error'" class="fas fa-exclamation-circle text-red-500 text-xl"></i>
          <i v-else-if="notif.type === 'warning'" class="fas fa-exclamation-triangle text-[#E07845] text-xl"></i>
          <i v-else class="fas fa-info-circle text-[#376875] text-xl"></i>
        </div>

        <div class="flex-1 min-w-0">
          <h4 class="text-slate-800 font-bold text-sm leading-tight">{{ notif.title }}</h4>
          <p v-if="notif.body" class="text-slate-500 text-xs mt-1 leading-relaxed">{{ notif.body }}</p>

          <span v-if="notif.actionUrl" class="inline-block mt-2 text-[10px] font-black uppercase tracking-widest text-[#376875]">
            Ver mensaje <i class="fas fa-arrow-right ml-1"></i>
          </span>
        </div>

        <button
            @click.stop="notificationStore.removeNotification(notif.id)"
            class="text-slate-400 hover:text-red-500 transition-colors focus:outline-none w-7 h-7 flex items-center justify-center rounded-full bg-slate-50 hover:bg-red-50 shrink-0"
            title="Cerrar"
        >
          <i class="fas fa-times text-xs"></i>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
/* Animaciones de entrada y salida fluidas para la lista de notificaciones */
.toast-enter-active,
.toast-leave-active {
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.toast-enter-from {
  opacity: 0;
  transform: translateX(100px) scale(0.9);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(100px) scale(0.9);
}
/* Asegura que los demás elementos se muevan suavemente cuando uno desaparece */
.toast-move {
  transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
</style>