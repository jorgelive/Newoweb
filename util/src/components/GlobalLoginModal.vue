<script setup lang="ts">
import { ref } from 'vue';
import { isSessionExpired, renewSession } from '@/services/sessionAuth';
import { processQueue } from '@/services/apiClient';

import { useNotificationStore } from '@/stores/notificationStore';

const notificationStore = useNotificationStore();

const loginUsername = ref('');
const loginPassword = ref('');
const loginRemember = ref(true);
const isLoggingIn = ref(false);
const loginError = ref('');

const handleSessionRenewal = async () => {
  if (!loginUsername.value || !loginPassword.value) return;

  isLoggingIn.value = true;
  loginError.value = '';

  try {
    await renewSession({
      _username: loginUsername.value,
      _password: loginPassword.value,
      _remember_me: loginRemember.value
    });

    // Si tiene éxito, oculta el modal y procesa la cola retenida
    loginPassword.value = '';
    processQueue(null);
    await notificationStore.subscribeToPushNotifications();
  } catch (err: any) {
    loginError.value = err.response?.data?.message || 'Credenciales inválidas. Inténtalo de nuevo.';
    processQueue(err);
  } finally {
    isLoggingIn.value = false;
  }
};

const handleCancel = () => {
  isSessionExpired.value = false;
  processQueue(new Error('Cancelado por el usuario.'));
};
</script>

<template>
  <Teleport to="body">
    <Transition name="fade-slide">
      <div v-if="isSessionExpired" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-md p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden border border-slate-200">
          <div class="bg-slate-50 px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-800 text-lg flex items-center gap-2">
              <i class="fas fa-lock text-[#E07845]"></i> Sesión Expirada
            </h3>
            <button @click="handleCancel" class="text-slate-400 hover:text-slate-600 transition-colors">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <form @submit.prevent="handleSessionRenewal" class="p-6">
            <p class="text-sm text-slate-500 mb-6 leading-relaxed">
              Por seguridad, tu sesión ha caducado. Ingresa tus credenciales para reanudar el trabajo exactamente donde te quedaste.
            </p>

            <div class="space-y-4">
              <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Usuario</label>
                <div class="relative">
                  <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                  <input v-model="loginUsername" type="text" required class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-xl pl-10 pr-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#376875]/50 focus:border-[#376875] transition-all text-sm font-medium" placeholder="tu_usuario">
                </div>
              </div>

              <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Contraseña</label>
                <div class="relative">
                  <i class="fas fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                  <input v-model="loginPassword" type="password" required class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-xl pl-10 pr-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#376875]/50 focus:border-[#376875] transition-all text-sm font-medium" placeholder="••••••••">
                </div>
              </div>
            </div>
            <div class="flex items-center mt-4">
              <input
                  type="checkbox"
                  id="globalRememberMe"
                  v-model="loginRemember"
                  class="w-4 h-4 text-[#376875] bg-slate-50 border-slate-300 rounded focus:ring-[#376875] focus:ring-2 cursor-pointer transition-colors"
              >
              <label for="globalRememberMe" class="ml-2 text-xs font-bold text-slate-600 cursor-pointer select-none">
                Mantener sesión iniciada
              </label>
            </div>

            <div v-if="loginError" class="mt-4 text-xs font-bold text-red-500 bg-red-50 p-3 rounded-lg flex items-center gap-2">
              <i class="fas fa-exclamation-circle shrink-0"></i>
              <span>{{ loginError }}</span>
            </div>

            <div class="mt-8 flex gap-3">
              <button type="button" @click="handleCancel" class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 hover:bg-slate-200 font-bold rounded-xl text-sm transition-colors">
                Cancelar
              </button>
              <button type="submit" :disabled="isLoggingIn || !loginUsername || !loginPassword" class="flex-1 px-4 py-2.5 bg-[#376875] text-white hover:bg-[#2c535d] font-bold rounded-xl text-sm transition-colors disabled:opacity-50 flex items-center justify-center gap-2 shadow-md">
                <i v-if="isLoggingIn" class="fas fa-circle-notch fa-spin"></i>
                <span v-else>Entrar</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translateY(10px) scale(0.98); }
</style>