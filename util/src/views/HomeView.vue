<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { useChatStore } from '@/stores/chat/chatStore.ts';
import { useNotificationStore } from '@/stores/notificationStore';
import { isSessionExpired } from '@/services/sessionAuth';

const store = useChatStore();
const notificationStore = useNotificationStore();

// ============================================================================
// MÓDULOS DEL PORTAL, AGRUPADOS POR NEGOCIO
//
// Son dos negocios distintos con equipos y rutinas distintas —el alojamiento
// (PMS) y la agencia de viajes—, así que la rejilla plana obligaba a leer las
// seis tarjetas para encontrar la propia. Ahora cada bloque lleva su encabezado
// y su color: teal el PMS, naranja Viajes.
//
// `destacado` marca la puerta de entrada de cada bloque (Reservas y
// Cotizaciones, las de uso diario): en el mosaico es la pieza 2x2 pintada en
// color pleno —`bar` pasa a ser el FONDO de la pieza, no el filo—, con las dos
// planas a su derecha. Es una jerarquía visual, no un permiso: todas las piezas
// llevan al mismo sitio de siempre. `iconBg`/`iconColor` solo los usan las
// planas; en la grande el icono va sobre un velo blanco.
// ============================================================================
interface Modulo {
  to: string;
  title: string;
  icon: string;
  desc: string;
  iconBg: string;
  iconColor: string;
  bar: string;
  titleHover: string;
  /** Tarjeta ancha y resaltada: el módulo que se abre a diario. */
  destacado?: boolean;
}

interface SeccionModulos {
  titulo: string;
  desc: string;
  /** Color del encabezado del bloque (identidad del negocio). */
  color: string;
  modulos: Modulo[];
}

const secciones: SeccionModulos[] = [
  {
    titulo: 'Alojamiento',
    desc: 'PMS · Casitas',
    color: 'text-[#376875]',
    modulos: [
      {
        to: '/reservas', title: 'Reservas', icon: 'fa-calendar-days',
        desc: 'Calendario PMS: estancias, bloqueos y disponibilidad por unidad.',
        iconBg: 'bg-[#376875]', iconColor: 'text-white',
        bar: 'bg-[#376875]', titleHover: 'group-hover:text-[#376875]',
        destacado: true,
      },
      {
        to: '/tarifas', title: 'Tarifas', icon: 'fa-tags',
        desc: 'Rangos de precio y estancia mínima por unidad, con push automático a Beds24.',
        iconBg: 'bg-[#376875]/10', iconColor: 'text-[#376875]',
        bar: 'bg-[#376875]', titleHover: 'group-hover:text-[#376875]',
      },
      {
        to: '/chat', title: 'Chat Inbox', icon: 'fa-comments',
        desc: 'Mensajería omnicanal con huéspedes: Beds24, Airbnb y WhatsApp en una sola bandeja.',
        iconBg: 'bg-slate-900', iconColor: 'text-white',
        bar: 'bg-slate-900', titleHover: 'group-hover:text-slate-900',
      },
    ],
  },
  {
    titulo: 'Viajes',
    desc: 'Agencia · Tours',
    color: 'text-[#E07845]',
    modulos: [
      {
        to: '/cotizacion', title: 'Cotizaciones', icon: 'fa-file-invoice-dollar',
        desc: 'Motor de armado de propuestas, tarifas y cálculo financiero por expediente.',
        iconBg: 'bg-[#E07845]', iconColor: 'text-white',
        bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
        destacado: true,
      },
      {
        to: '/operacion', title: 'Operaciones', icon: 'fa-car-side',
        desc: 'Centro de operaciones: logística, proveedores y ejecución día a día.',
        iconBg: 'bg-[#E07845]/10', iconColor: 'text-[#E07845]',
        bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
      },
      {
        to: '/catalogo', title: 'Catálogo de Tours', icon: 'fa-book-open',
        desc: 'Producto pre-armado por segmento, listo para cotizar en minutos.',
        iconBg: 'bg-[#E07845]/10', iconColor: 'text-[#E07845]',
        bar: 'bg-[#E07845]', titleHover: 'group-hover:text-[#E07845]',
      },
    ],
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
            Todo tu ecosistema operativo en un solo lugar: el PMS de las casitas y la operación
            de viajes, cada uno con sus herramientas a un clic.
          </p>
        </div>

        <!-- Mosaico de piezas desiguales, estilo Windows: el módulo de uso diario
             es una pieza 2x2 en color pleno y los otros dos, piezas planas a su
             derecha. En la rejilla de 4 columnas encaja exacto: 2+2 de ancho, 2
             filas de alto. En móvil (2 columnas) la grande ocupa la fila entera y
             las pequeñas quedan de a dos, así que el puzle se mantiene. -->
        <section v-for="(seccion, s) in secciones" :key="seccion.titulo" :class="s > 0 ? 'mt-10 md:mt-12' : ''">

          <div class="flex items-baseline gap-3 mb-4 md:mb-5">
            <h2 class="text-sm font-black uppercase tracking-[0.18em]" :class="seccion.color">
              {{ seccion.titulo }}
            </h2>
            <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ seccion.desc }}</span>
            <span class="flex-1 h-px bg-slate-200"></span>
          </div>

          <div class="grid grid-cols-2 lg:grid-cols-4 auto-rows-[8.5rem] md:auto-rows-[9rem] gap-3 md:gap-4">

            <RouterLink
              v-for="mod in seccion.modulos" :key="mod.to" :to="mod.to"
              class="module-card group relative rounded-3xl overflow-hidden flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl"
              :class="mod.destacado
                ? ['col-span-2 row-span-2 p-6 text-white shadow-lg', mod.bar]
                : 'col-span-1 lg:col-span-2 p-4 md:p-5 bg-white border border-slate-100 shadow-sm'"
            >
              <!-- Solo en las planas: el filo de color que aparece al pasar por
                   encima. La grande no lo necesita, ya ES el color. -->
              <div v-if="!mod.destacado" class="absolute inset-x-0 top-0 h-1 opacity-0 group-hover:opacity-100 transition-opacity duration-300" :class="mod.bar"></div>

              <div class="flex items-start justify-between gap-3">
                <div class="rounded-2xl flex items-center justify-center transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-3"
                  :class="mod.destacado ? 'w-14 h-14 bg-white/15' : ['w-11 h-11', mod.iconBg]">
                  <i class="fas" :class="[mod.icon, mod.destacado ? 'text-2xl text-white' : ['text-xl', mod.iconColor]]" aria-hidden="true"></i>
                </div>
                <span v-if="mod.destacado" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/15 text-[9px] font-black uppercase tracking-widest text-white/90">
                  <i class="fas fa-star text-[8px]" aria-hidden="true"></i> Uso diario
                </span>
              </div>

              <div>
                <h3 class="font-black tracking-tight transition-colors"
                  :class="mod.destacado ? 'text-2xl md:text-3xl text-white' : ['text-base text-slate-900', mod.titleHover]">
                  {{ mod.title }}
                </h3>
                <p class="font-medium leading-snug mt-1"
                  :class="mod.destacado ? 'text-sm text-white/75 max-w-md' : 'text-[11px] md:text-xs text-slate-500 line-clamp-2'">
                  {{ mod.desc }}
                </p>

                <span v-if="mod.destacado" class="mt-4 inline-flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-white/80 group-hover:gap-3 transition-all">
                  Entrar <i class="fas fa-arrow-right text-[10px]" aria-hidden="true"></i>
                </span>
              </div>
            </RouterLink>

          </div>
        </section>
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
/* Tres por bloque: el nth-child cuenta dentro de cada rejilla, así que los dos
   bloques entran en cascada a la vez y no uno detrás del otro. */
.module-card:nth-child(1) { animation-delay: 0.04s; }
.module-card:nth-child(2) { animation-delay: 0.10s; }
.module-card:nth-child(3) { animation-delay: 0.16s; }

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
