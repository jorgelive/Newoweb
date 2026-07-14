<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { useChatStore } from '@/stores/chat/chatStore.ts';
import { useNotificationStore } from '@/stores/notificationStore';
import { isSessionExpired } from '@/services/sessionAuth';

const store = useChatStore();
const notificationStore = useNotificationStore();

const isSessionActive = ref(false);
const isCheckingSession = ref(true);
const showSuccessTooltip = ref(false);

/**
 * Al montar, verificamos silenciosamente si la sesión está activa
 * para mostrar el control de sesión correcto en la esquina.
 */
onMounted(async () => {
  isCheckingSession.value = true;
  isSessionActive.value = await store.checkSession();
  isCheckingSession.value = false;
});

/**
 * El login ahora vive únicamente en GlobalLoginModal (montado en App.vue).
 * Cuando el modal se cierra (isSessionExpired pasa de true a false), puede
 * ser por login exitoso o por cancelación: refrescamos el estado de sesión
 * para que el badge de la esquina quede correcto.
 *
 * La suscripción push YA NO se dispara acá: si el usuario re-loguea estando
 * en otra ruta (ej. /chat), este watcher ni siquiera existe porque Home no
 * está montado. Ese efecto se movió a GlobalLoginModal, que es el único
 * lugar donde "login exitoso" es un hecho y no una inferencia, y que vive
 * siempre montado en App.vue.
 */
watch(isSessionExpired, async (expired, wasExpired) => {
  if (!wasExpired || expired) return;

  const wasActive = isSessionActive.value;
  isSessionActive.value = await store.checkSession();

  if (isSessionActive.value && !wasActive) {
    showSuccessTooltip.value = true;
    setTimeout(() => {
      showSuccessTooltip.value = false;
    }, 3000);
  }
});

const openLogin = () => {
  isSessionExpired.value = true;
};

const isLoggingOut = ref(false);

const handleLogout = async () => {
  isLoggingOut.value = true;

  try {
    // navigator.serviceWorker.ready puede quedar colgado para siempre si no
    // hay un SW activo (registro fallido, primera carga, etc.). Nunca debe
    // bloquear el logout — le ponemos un techo de tiempo.
    const registration = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise<never>((_, reject) => setTimeout(() => reject(new Error('SW no disponible')), 3000))
    ]);
  } catch (err) {
    // No bloqueamos el logout si falla la baja de la suscripción push
  } finally {
    // 2. Usamos el enrutamiento nativo del navegador hacia el firewall de Symfony
    window.location.href = '/logout';
  }
};
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex flex-col items-center justify-center relative overflow-hidden font-sans">

    <Transition name="toast-slide">
      <div v-if="showSuccessTooltip" class="fixed top-8 z-50 bg-green-500 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-fade-in font-bold">
        <i class="fas fa-check-circle text-xl" aria-hidden="true"></i>
        <span>¡Sesión iniciada correctamente!</span>
      </div>
    </Transition>

    <div class="absolute top-[-10%] left-[-10%] w-96 h-96 bg-[#376875] rounded-full mix-blend-multiply filter blur-[100px] opacity-20 animate-pulse"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-96 h-96 bg-[#E07845] rounded-full mix-blend-multiply filter blur-[100px] opacity-20 animate-pulse" style="animation-delay: 2s;"></div>

    <!-- Estado de sesión: separado del contenido principal, como un control de cuenta -->
    <div class="absolute top-6 right-6 z-20">
      <div v-if="isCheckingSession" class="flex items-center px-2 py-2">
        <i class="fas fa-circle-notch fa-spin text-xl text-slate-300" aria-hidden="true"></i>
      </div>

      <button v-else-if="!isSessionActive" @click="openLogin" class="px-4 py-2 bg-white text-slate-600 border border-slate-200 font-bold text-sm rounded-xl shadow-sm hover:bg-slate-50 hover:border-slate-300 transition-all flex items-center gap-2">
        <i class="fas fa-sign-in-alt" aria-hidden="true"></i> Iniciar Sesión
      </button>

      <div v-else class="flex items-center gap-2">
        <div class="px-3 py-2 bg-green-50 text-green-700 border border-green-200 font-bold text-xs rounded-xl shadow-sm flex items-center gap-2">
          <i class="fas fa-shield-alt" aria-hidden="true"></i> Sesión Activa
        </div>
        <button @click="handleLogout" :disabled="isLoggingOut" class="px-3 py-2 bg-red-50 text-red-600 border border-red-200 font-bold text-xs rounded-xl shadow-sm hover:bg-red-100 transition-all flex items-center gap-2 disabled:opacity-50">
          <i class="fas" :class="isLoggingOut ? 'fa-circle-notch fa-spin' : 'fa-power-off'" aria-hidden="true"></i> Salir
        </button>
      </div>
    </div>

    <main class="relative z-10 text-center px-6 max-w-3xl mx-auto flex flex-col items-center w-full">

      <div class="mb-8 w-24 h-24 bg-white rounded-3xl shadow-xl flex items-center justify-center border border-slate-100 transform transition hover:scale-105 duration-300">
        <i class="fas fa-satellite-dish text-5xl text-[#376875]" aria-hidden="true"></i>
      </div>

      <h1 class="text-5xl md:text-7xl font-black text-slate-900 tracking-tighter mb-4">
        Portal <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#376875] to-[#E07845]">Interno</span>
      </h1>

      <p class="text-lg md:text-xl text-slate-500 mb-12 font-medium leading-relaxed max-w-2xl">
        Centro de control unificado. Accede al sistema de mensajería para gestionar las comunicaciones con los huéspedes desde todos tus canales (Beds24, Airbnb, WhatsApp).
      </p>

      <div class="flex flex-col sm:flex-row gap-5 justify-center items-center w-full sm:w-auto animate-fade-in">

        <RouterLink to="/chat" class="group relative px-8 py-4 bg-slate-900 text-white font-bold text-lg rounded-2xl overflow-hidden shadow-2xl hover:shadow-slate-900/50 transition-all hover:-translate-y-1 w-full sm:w-auto flex justify-center items-center gap-3">
          <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-[#376875] to-[#E07845] opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
          <span class="relative z-10 flex items-center gap-3">
            <i class="fas fa-comments text-xl" aria-hidden="true"></i> Abrir Chat Inbox
          </span>
        </RouterLink>

        <RouterLink to="/cotizacion" class="group relative px-8 py-4 bg-white text-slate-800 font-bold text-lg rounded-2xl overflow-hidden shadow-lg border border-slate-200 hover:border-[#E07845] transition-all hover:-translate-y-1 w-full sm:w-auto flex justify-center items-center gap-3">
          <span class="relative z-10 flex items-center gap-3 group-hover:text-[#E07845] transition-colors">
            <i class="fas fa-file-invoice-dollar text-xl" aria-hidden="true"></i> Motor de Cotizaciones
          </span>
        </RouterLink>

      </div>

    </main>

    <footer class="absolute bottom-8 text-center text-slate-400 text-sm font-bold tracking-widest uppercase">
      &copy; {{ new Date().getFullYear() }} OpenPeru. Sistema Privado.
    </footer>
  </div>
</template>

<style scoped>
.animate-fade-in {
  animation: fadeIn 0.3s ease-out forwards;
}

.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.toast-slide-enter-from {
  opacity: 0;
  transform: translateY(-20px);
}
.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(-20px);
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
