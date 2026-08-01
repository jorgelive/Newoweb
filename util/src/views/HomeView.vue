<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { useChatStore } from '@/stores/chat/chatStore.ts';
import { useNotificationStore } from '@/stores/notificationStore';
import { isSessionExpired } from '@/services/sessionAuth';

const store = useChatStore();
const notificationStore = useNotificationStore();

// Módulos del portal. Colores alternando la identidad de marca (#376875 / #E07845).
const modulos = [
  {
    to: '/chat', title: 'Chat Inbox', icon: 'fa-comments',
    desc: 'Mensajería omnicanal con huéspedes: Beds24, Airbnb y WhatsApp en una sola bandeja.',
    iconBg: 'bg-slate-900', iconColor: 'text-white',
    bar: 'bg-slate-900', titleHover: 'group-hover:text-slate-900',
  },
  {
    to: '/cotizacion', title: 'Cotizaciones', icon: 'fa-file-invoice-dollar',
    desc: 'Motor de armado de propuestas, tarifas y cálculo financiero por expediente.',
    iconBg: 'bg-[#E07845]/10', iconColor: 'text-[#E07845]',
    bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
  },
  {
    to: '/operacion', title: 'Operaciones', icon: 'fa-car-side',
    desc: 'Centro de operaciones: logística, proveedores y ejecución día a día.',
    iconBg: 'bg-[#376875]/10', iconColor: 'text-[#376875]',
    bar: 'bg-[#376875]', titleHover: 'group-hover:text-[#376875]',
  },
  {
    to: '/reservas', title: 'Reservas', icon: 'fa-calendar-days',
    desc: 'Calendario PMS: estancias, bloqueos y disponibilidad por unidad.',
    iconBg: 'bg-[#376875]/10', iconColor: 'text-[#376875]',
    bar: 'bg-[#376875]', titleHover: 'group-hover:text-[#376875]',
  },
  {
    to: '/catalogo', title: 'Catálogo de Tours', icon: 'fa-book-open',
    desc: 'Producto pre-armado por segmento, listo para cotizar en minutos.',
    iconBg: 'bg-[#E07845]/10', iconColor: 'text-[#E07845]',
    bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
  },
];

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
  <div class="h-screen overflow-y-auto bg-slate-50 relative font-sans">

    <Transition name="toast-slide">
      <div v-if="showSuccessTooltip" class="fixed top-8 left-1/2 -translate-x-1/2 z-50 bg-emerald-500 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 font-bold">
        <i class="fas fa-check-circle text-xl" aria-hidden="true"></i>
        <span>¡Sesión iniciada correctamente!</span>
      </div>
    </Transition>

    <!-- Fondo decorativo -->
    <div class="pointer-events-none fixed inset-0 overflow-hidden">
      <div class="absolute top-[-15%] left-[-10%] w-[36rem] h-[36rem] bg-[#376875] rounded-full mix-blend-multiply filter blur-[120px] opacity-[0.15] animate-pulse"></div>
      <div class="absolute bottom-[-20%] right-[-10%] w-[36rem] h-[36rem] bg-[#E07845] rounded-full mix-blend-multiply filter blur-[120px] opacity-[0.15] animate-pulse" style="animation-delay: 2s;"></div>
      <div class="absolute inset-0 bg-[radial-gradient(#0f172a_1px,transparent_1px)] [background-size:32px_32px] opacity-[0.03]"></div>
    </div>

    <div class="relative z-10 min-h-full flex flex-col">

      <!-- Barra superior: marca + control de sesión -->
      <header class="w-full max-w-6xl mx-auto px-6 pt-6 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="w-11 h-11 bg-slate-900 rounded-2xl flex items-center justify-center shadow-lg shadow-slate-900/20">
            <i class="fas fa-satellite-dish text-lg text-white" aria-hidden="true"></i>
          </div>
          <div class="leading-none">
            <p class="font-black text-slate-900 tracking-tight">OpenPeru</p>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-1">Portal Interno</p>
          </div>
        </div>

        <div>
          <div v-if="isCheckingSession" class="flex items-center px-2 py-2">
            <i class="fas fa-circle-notch fa-spin text-xl text-slate-300" aria-hidden="true"></i>
          </div>

          <button v-else-if="!isSessionActive" @click="openLogin" class="px-4 py-2 bg-white text-slate-600 border border-slate-200 font-bold text-sm rounded-xl shadow-sm hover:bg-slate-50 hover:border-slate-300 transition-all flex items-center gap-2">
            <i class="fas fa-sign-in-alt" aria-hidden="true"></i> Iniciar Sesión
          </button>

          <div v-else class="flex items-center gap-2">
            <div class="px-3 py-2 bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold text-xs rounded-xl shadow-sm flex items-center gap-2">
              <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
              </span>
              <span class="hidden sm:inline">Sesión Activa</span>
            </div>
            <button @click="handleLogout" :disabled="isLoggingOut" class="px-3 py-2 bg-white text-red-600 border border-red-200 font-bold text-xs rounded-xl shadow-sm hover:bg-red-50 transition-all flex items-center gap-2 disabled:opacity-50">
              <i class="fas" :class="isLoggingOut ? 'fa-circle-notch fa-spin' : 'fa-power-off'" aria-hidden="true"></i> <span class="hidden sm:inline">Salir</span>
            </button>
          </div>
        </div>
      </header>

      <!-- Contenido principal -->
      <main class="flex-1 w-full max-w-6xl mx-auto px-6 py-10 md:py-16 flex flex-col justify-center">

        <div class="mb-10 md:mb-14 max-w-2xl">
          <h1 class="text-4xl md:text-6xl font-black text-slate-900 tracking-tighter mb-4">
            Centro de <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#376875] to-[#E07845]">control</span>
          </h1>
          <p class="text-base md:text-lg text-slate-500 font-medium leading-relaxed">
            Todo tu ecosistema operativo en un solo lugar: mensajería omnicanal, cotizaciones, operaciones y catálogo de tours.
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5">

          <RouterLink
            v-for="mod in modulos" :key="mod.to" :to="mod.to"
            class="module-card group relative bg-white rounded-3xl p-6 border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-1 transition-all duration-300 overflow-hidden flex flex-col"
          >
            <div class="absolute inset-x-0 top-0 h-1 opacity-0 group-hover:opacity-100 transition-opacity duration-300" :class="mod.bar"></div>

            <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-5 transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-3" :class="mod.iconBg">
              <i class="fas text-2xl" :class="[mod.icon, mod.iconColor]" aria-hidden="true"></i>
            </div>

            <h2 class="text-lg font-black text-slate-900 tracking-tight mb-1.5 transition-colors" :class="mod.titleHover">
              {{ mod.title }}
            </h2>
            <p class="text-[13px] text-slate-500 font-medium leading-snug flex-1">
              {{ mod.desc }}
            </p>

            <span class="mt-5 inline-flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-slate-400 group-hover:gap-3 transition-all" :class="mod.titleHover">
              Entrar <i class="fas fa-arrow-right text-[10px]" aria-hidden="true"></i>
            </span>
          </RouterLink>

        </div>
      </main>

      <footer class="w-full max-w-6xl mx-auto px-6 py-6 text-slate-400 text-[11px] font-bold tracking-widest uppercase">
        &copy; {{ new Date().getFullYear() }} OpenPeru · Sistema Privado
      </footer>
    </div>
  </div>
</template>

<style scoped>
.module-card {
  animation: cardIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.module-card:nth-child(1) { animation-delay: 0.04s; }
.module-card:nth-child(2) { animation-delay: 0.10s; }
.module-card:nth-child(3) { animation-delay: 0.16s; }
.module-card:nth-child(4) { animation-delay: 0.22s; }
.module-card:nth-child(5) { animation-delay: 0.28s; }

.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.toast-slide-enter-from,
.toast-slide-leave-to {
  opacity: 0;
  transform: translate(-50%, -20px);
}

@keyframes cardIn {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
