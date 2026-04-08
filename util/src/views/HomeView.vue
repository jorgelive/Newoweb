<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useChatStore } from '@/stores/chatStore';
import { useNotificationStore } from '@/stores/notificationStore';

const router = useRouter();
const store = useChatStore();
const notificationStore = useNotificationStore();

const showLoginForm = ref(false);
const loginUsername = ref('');
const loginPassword = ref('');
const loginRemember = ref(true);
const isLoggingIn = ref(false);
const loginError = ref('');

// Estados para controlar la sesión y el tooltip
const isSessionActive = ref(false);
const isCheckingSession = ref(true);
const showSuccessTooltip = ref(false);

/**
 * Al montar el componente, verificamos silenciosamente si la sesión está activa
 * para mostrar los botones principales directamente.
 */
onMounted(async () => {
  isCheckingSession.value = true;
  isSessionActive.value = await store.checkSession();
  isCheckingSession.value = false;
});

/**
 * Maneja el envío del formulario de login. Si es exitoso, mantiene al usuario
 * en la misma vista y lanza un tooltip de confirmación.
 * @returns {Promise<void>}
 */
const handleLogin = async () => {
  if (!loginUsername.value || !loginPassword.value) return;

  isLoggingIn.value = true;
  loginError.value = '';

  const success = await store.renewSession({
    _username: loginUsername.value,
    _password: loginPassword.value,
    _remember_me: loginRemember.value
  });

  isLoggingIn.value = false;

  if (success) {
    // Si el login es correcto, cerramos el formulario y mostramos el tooltip
    isSessionActive.value = true;
    showLoginForm.value = false;
    showSuccessTooltip.value = true;

    // Ocultar el tooltip animado después de 3 segundos
    setTimeout(() => {
      showSuccessTooltip.value = false;
    }, 3000);

    // Intentar suscribir al navegador a las notificaciones Push
    await notificationStore.subscribeToPushNotifications();

  } else {
    loginError.value = store.error || 'Credenciales inválidas. Inténtalo de nuevo.';
  }
};

const isLoggingOut = ref(false);

const handleLogout = async () => {
  isLoggingOut.value = true;

  // 1. Matamos la suscripción Push local y remota
  await notificationStore.unsubscribeFromPushNotifications();

  // 2. Usamos el enrutamiento nativo del navegador hacia el firewall de Symfony
  window.location.href = '/logout';
};

</script>

<template>
  <div class="min-h-screen bg-slate-50 flex flex-col items-center justify-center relative overflow-hidden font-sans">

    <Transition name="toast-slide">
      <div v-if="showSuccessTooltip" class="fixed top-8 z-50 bg-green-500 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-fade-in font-bold">
        <i class="fas fa-check-circle text-xl"></i>
        <span>¡Sesión iniciada correctamente!</span>
      </div>
    </Transition>

    <div class="absolute top-[-10%] left-[-10%] w-96 h-96 bg-[#376875] rounded-full mix-blend-multiply filter blur-[100px] opacity-20 animate-pulse"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-96 h-96 bg-[#E07845] rounded-full mix-blend-multiply filter blur-[100px] opacity-20 animate-pulse" style="animation-delay: 2s;"></div>

    <main class="relative z-10 text-center px-6 max-w-3xl mx-auto flex flex-col items-center w-full">

      <div class="mb-8 w-24 h-24 bg-white rounded-3xl shadow-xl flex items-center justify-center border border-slate-100 transform transition hover:scale-105 duration-300">
        <i class="fas fa-satellite-dish text-5xl text-[#376875]"></i>
      </div>

      <h1 class="text-5xl md:text-7xl font-black text-slate-900 tracking-tighter mb-4">
        Portal <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#376875] to-[#E07845]">Interno</span>
      </h1>

      <p class="text-lg md:text-xl text-slate-500 mb-12 font-medium leading-relaxed max-w-2xl">
        Centro de control unificado. Accede al sistema de mensajería para gestionar las comunicaciones con los huéspedes desde todos tus canales (Beds24, Airbnb, WhatsApp).
      </p>

      <div v-if="isCheckingSession" class="flex justify-center items-center h-16">
        <i class="fas fa-circle-notch fa-spin text-3xl text-slate-300"></i>
      </div>

      <div v-else-if="!showLoginForm" class="flex flex-col sm:flex-row gap-5 justify-center items-center w-full sm:w-auto animate-fade-in">

        <RouterLink to="/chat" class="group relative px-8 py-4 bg-slate-900 text-white font-bold text-lg rounded-2xl overflow-hidden shadow-2xl hover:shadow-slate-900/50 transition-all hover:-translate-y-1 w-full sm:w-auto flex justify-center items-center gap-3">
          <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-[#376875] to-[#E07845] opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
          <span class="relative z-10 flex items-center gap-3">
            <i class="fas fa-comments text-xl"></i> Abrir Chat Inbox
          </span>
        </RouterLink>

        <button v-if="!isSessionActive" @click="showLoginForm = true" class="px-8 py-4 bg-white text-slate-700 border-2 border-slate-200 font-bold text-lg rounded-2xl shadow-sm hover:bg-slate-50 hover:border-slate-300 transition-all flex justify-center items-center gap-3 w-full sm:w-auto">
          <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
        </button>

        <div v-else class="flex items-center gap-3 w-full sm:w-auto">
          <div class="px-6 py-4 bg-green-50 text-green-700 border-2 border-green-200 font-bold text-lg rounded-2xl shadow-sm flex justify-center items-center gap-3">
            <i class="fas fa-shield-alt"></i> Sesión Activa
          </div>
          <button @click="handleLogout" :disabled="isLoggingOut" class="px-6 py-4 bg-red-50 text-red-600 border-2 border-red-200 font-bold text-lg rounded-2xl shadow-sm hover:bg-red-100 transition-all flex justify-center items-center gap-3 disabled:opacity-50">
            <i class="fas" :class="isLoggingOut ? 'fa-circle-notch fa-spin' : 'fa-power-off'"></i> Salir
          </button>
        </div>

      </div>

      <div v-else class="w-full max-w-md bg-white p-8 rounded-3xl shadow-2xl border border-slate-100 animate-fade-in">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-black text-slate-800">Acceso al Sistema</h2>
          <button @click="showLoginForm = false" class="text-slate-400 hover:text-slate-600 transition-colors w-8 h-8 flex items-center justify-center rounded-full bg-slate-50 hover:bg-slate-100">
            <i class="fas fa-arrow-left"></i>
          </button>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-4 text-left">
          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Usuario</label>
            <div class="relative">
              <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
              <input v-model="loginUsername" type="text" required class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-xl pl-10 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#376875]/50 focus:border-[#376875] transition-all text-sm font-medium" placeholder="tu_usuario">
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Contraseña</label>
            <div class="relative">
              <i class="fas fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
              <input v-model="loginPassword" type="password" required class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-xl pl-10 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#376875]/50 focus:border-[#376875] transition-all text-sm font-medium" placeholder="••••••••">
            </div>
          </div>

          <div class="flex items-center pt-1">
            <input
                type="checkbox"
                id="rememberMe"
                v-model="loginRemember"
                class="w-4 h-4 text-[#376875] bg-slate-50 border-slate-300 rounded focus:ring-[#376875] focus:ring-2 cursor-pointer transition-colors"
            >
            <label for="rememberMe" class="ml-2 text-xs font-bold text-slate-600 cursor-pointer select-none">
              Mantener sesión iniciada
            </label>
          </div>

          <div v-if="loginError" class="mt-2 text-xs font-bold text-red-500 bg-red-50 p-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-exclamation-circle shrink-0"></i>
            <span>{{ loginError }}</span>
          </div>

          <button type="submit" :disabled="isLoggingIn || !loginUsername || !loginPassword" class="w-full mt-4 px-4 py-3.5 bg-[#376875] text-white hover:bg-[#2c535d] font-bold rounded-xl text-sm transition-colors disabled:opacity-50 flex items-center justify-center gap-2 shadow-md">
            <i v-if="isLoggingIn" class="fas fa-circle-notch fa-spin"></i>
            <span v-else>Entrar a OpenPeru</span>
          </button>
        </form>
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