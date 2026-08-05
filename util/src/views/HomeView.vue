<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useChatStore } from '@/stores/chat/chatStore.ts';
import { useNotificationStore } from '@/stores/notificationStore';
import { isSessionExpired } from '@/services/sessionAuth';
import { MODULOS_APP } from '@/types/modulosApp';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import AsistenteBar from '@/components/common/AsistenteBar.vue';
import { apiClient } from '@/services/apiClient';
import { coleccionFeed, type CalendarEventoFeed } from '@/types/calendarFeedModel';
import type { PmsEventoExtendedProps } from '@/types/pmsReservaModel';
// La clave del calendario de ocupación vive en el modelo de tarifas porque nació
// allí (el fondo beige del calendario de precios). Aquí sirve para lo mismo que
// allá: son las estancias que OCUPAN la casita —pendiente, confirmada o
// requerimiento—, que son justo las que llegan y salen de verdad.
import { PMS_OCUPACION_CALENDARIO_KEY, fromDateLocal, sumarDias } from '@/types/pmsTarifaModel';

const router = useRouter();
const store = useChatStore();
const notificationStore = useNotificationStore();

// Los módulos y su agrupación viven en `@/types/modulosApp`: los comparte el
// selector de la cabecera de cada vista (AppSwitcher), para que saltar de un
// módulo a otro no obligue a volver aquí. Añadir uno se hace allí, no aquí.
//
// `destacado` marca la puerta de entrada de cada bloque (Reservas y
// Cotizaciones, las de uso diario): en el mosaico es la pieza 2x2 pintada en
// color pleno —`bar` pasa a ser el FONDO de la pieza, no el filo—, con las dos
// planas a su derecha. Es una jerarquía visual, no un permiso: todas las piezas
// llevan al mismo sitio de siempre. `iconBg`/`iconColor` solo los usan las
// planas; en la grande el icono va sobre un velo blanco.
const secciones = MODULOS_APP;

// ============================================================================
// PANEL DEL DÍA — LLEGADAS Y SALIDAS (hoy / mañana)
//
// Sale del MISMO feed que pinta el calendario de Reservas
// (`/fullcalendar/load/event/...`), no de un endpoint nuevo: el backend ya
// resuelve ahí el solape con el día y manda cliente, casita y pax en
// `extendedProps`. Se piden DOS días —[hoy, pasado mañana)— y se clasifican por
// el día de `start` (llega) y de `end` (sale): una estancia larga aparece solo el
// día que entra y el día que se va, que es lo que se quiere ver. El conmutador
// Hoy/Mañana elige cuál de los dos se pinta, sin volver a pedir nada.
//
// El día se compara por STRING (`slice(0, 10)`): el feed manda hora local sin
// zona y pasarlo por `new Date()` para leer el día es justo lo que produce
// desfases de un día en UTC-5.
// ============================================================================
type EventoHoy = CalendarEventoFeed<PmsEventoExtendedProps>;

interface FilaHoy {
    id: string;
    /** Ids con los que Reservas abre la ficha; ver `verEnReservas()`. */
    eventoId: string;
    reservaId: string | null;
    hora: string;
    cliente: string;
    unidad: string;
    pax: number;
}

const hoyIso = fromDateLocal(new Date());
const cargandoHoy = ref(true);

/**
 * Se piden DOS días de una vez —[hoy, pasado mañana)— y el conmutador
 * Hoy/Mañana filtra en cliente: cambiar de día es instantáneo y no dispara otra
 * petición. Son pocas filas; no compensa ir al servidor por cada pulsación.
 */
const eventosPanel = ref<EventoHoy[]>([]);
const diaPanel = ref<'hoy' | 'manana'>('hoy');

const diaIso = computed(() => diaPanel.value === 'hoy' ? hoyIso : sumarDias(hoyIso, 1));

const fechaPanelLarga = computed(() => {
    const d = new Date();
    if (diaPanel.value === 'manana') d.setDate(d.getDate() + 1);
    return d.toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
});

const llegadasHoy = computed<FilaHoy[]>(() =>
    eventosPanel.value
        .filter(e => e.start.slice(0, 10) === diaIso.value)
        .map(e => aFila(e, e.start))
        .sort((a, b) => a.hora.localeCompare(b.hora)),
);

// Ojo: en las salidas la hora que importa es la de FIN de la estancia.
const salidasHoy = computed<FilaHoy[]>(() =>
    eventosPanel.value
        .filter(e => e.end.slice(0, 10) === diaIso.value)
        .map(e => aFila(e, e.end))
        .sort((a, b) => a.hora.localeCompare(b.hora)),
);

function aFila(ev: EventoHoy, instante: string): FilaHoy {
    const p = ev.extendedProps;
    return {
        id: String(ev.id),
        eventoId: p?.eventoId ?? String(ev.id),
        reservaId: p?.reservaId ?? null,
        // 'YYYY-MM-DDTHH:mm:ss' -> 'HH:mm', sin construir un Date.
        hora: instante.slice(11, 16),
        cliente: p?.cliente || 'Sin nombre',
        unidad: p?.unidad || '—',
        pax: p?.pax ?? 0,
    };
}

/**
 * ¿Ya pasó la última llegada y la última salida de hoy?
 *
 * A media tarde el panel de «hoy» es historia: lo que importa es quién llega
 * mañana. Se compara con la hora local en el mismo formato de texto en que viene
 * el feed —`HH:mm`—, sin construir Dates. Si hoy no había nada, no se salta:
 * un día vacío no es un día terminado, y saltar sin motivo despista.
 */
function todoLoDeHoyYaPaso(): boolean {
    const deHoy = eventosPanel.value.filter(
        e => e.start.slice(0, 10) === hoyIso || e.end.slice(0, 10) === hoyIso,
    );
    if (!deHoy.length) return false;

    const ahora = new Date().toTimeString().slice(0, 5);

    return deHoy.every((e) => {
        const hora = e.start.slice(0, 10) === hoyIso ? e.start.slice(11, 16) : e.end.slice(11, 16);
        return hora <= ahora;
    });
}

async function cargarPanelHoy(): Promise<void> {
    cargandoHoy.value = true;
    try {
        const r = await apiClient.get(`/fullcalendar/load/event/${PMS_OCUPACION_CALENDARIO_KEY}`, {
            params: { start: hoyIso, end: sumarDias(hoyIso, 2), _t: Date.now() },
        });
        eventosPanel.value = coleccionFeed<EventoHoy>(r.data);
        if (todoLoDeHoyYaPaso()) diaPanel.value = 'manana';
    } catch {
        // Sin sesión o con la API caída, el portal tiene que seguir siendo
        // navegable: el panel se queda vacío y no se avisa de nada.
        eventosPanel.value = [];
    } finally {
        cargandoHoy.value = false;
    }
}

/**
 * Abre la estancia en el calendario de Reservas, con su ficha ya desplegada en
 * modo lectura. Los ids viajan por la query porque el drawer de Reservas solo
 * necesita eso (`abrirEdicion()` usa `eventoId`/`reservaId`), y así el enlace es
 * compartible y sobrevive a un refresco.
 */
function verEnReservas(fila: FilaHoy): void {
    router.push({
        path: '/reservas',
        query: { evento: fila.eventoId, ...(fila.reservaId ? { reserva: fila.reservaId } : {}) },
    });
}

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

  // Solo con sesión: sin ella el feed responde 401 y el interceptor abriría el
  // modal de login nada más entrar al portal.
  if (isSessionActive.value) await cargarPanelHoy();
  else cargandoHoy.value = false;
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
          <!-- El logo ES el selector de módulos: desde el portal se salta a
               cualquiera sin bajar al mosaico. Sin «Inicio», que es donde estamos. -->
          <AppSwitcher variante="marca" sin-inicio />
          <div class="leading-tight min-w-0">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">OpenPeru</p>
            <p class="text-lg md:text-2xl font-black text-slate-900 tracking-tighter">
              Centro de <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#376875] to-[#E07845]">control</span>
            </p>
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
      <main class="flex-1 w-full max-w-6xl mx-auto px-6 py-6 md:py-10 flex flex-col justify-center">

        <!-- ASISTENTE: va antes del panel del día porque resuelve la pregunta que trae al
             operador aquí ("¿tengo sitio del 12 al 15?"), y el panel es para mirar, no para
             preguntar. Sólo con sesión: sin ella el endpoint responde 401. -->
        <section v-if="isSessionActive" class="mb-6">
          <AsistenteBar />
        </section>

        <!-- PANEL DE HOY: lo primero que se mira al entrar. Llegadas y salidas
             del día salen del feed del calendario de Reservas (ver el script). -->
        <section class="mb-8 md:mb-10">
          <div class="flex items-center gap-3 mb-4">
            <!-- Conmutador de día: los dos días ya están cargados, así que solo
                 cambia el filtro (ver eventosPanel). -->
            <div class="flex items-center bg-white border border-slate-200 rounded-xl p-0.5 shadow-sm shrink-0">
              <button type="button" @click="diaPanel = 'hoy'"
                :class="['px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-widest transition-colors',
                         diaPanel === 'hoy' ? 'bg-slate-900 text-white' : 'text-slate-400 hover:text-slate-700']">
                Hoy
              </button>
              <button type="button" @click="diaPanel = 'manana'"
                :class="['px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-widest transition-colors',
                         diaPanel === 'manana' ? 'bg-slate-900 text-white' : 'text-slate-400 hover:text-slate-700']">
                Mañana
              </button>
            </div>
            <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400 first-letter:uppercase truncate">{{ fechaPanelLarga }}</span>
            <span class="flex-1 h-px bg-slate-200"></span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
            <!-- LLEGADAS -->
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden flex flex-col">
              <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100">
                <span class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                  <i class="fas fa-right-to-bracket text-sm" aria-hidden="true"></i>
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-black text-slate-900 leading-none">Check-in</p>
                  <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">{{ diaPanel === 'hoy' ? 'Llegan hoy' : 'Llegan mañana' }}</p>
                </div>
                <span class="text-2xl font-black text-slate-900 tabular-nums">{{ llegadasHoy.length }}</span>
              </div>

              <div v-if="cargandoHoy" class="px-5 py-6 text-sm font-bold text-slate-400">
                <i class="fas fa-circle-notch fa-spin mr-2" aria-hidden="true"></i> Cargando…
              </div>
              <p v-else-if="!llegadasHoy.length" class="px-5 py-6 text-sm font-bold text-slate-400">
                {{ diaPanel === 'hoy' ? 'Sin llegadas hoy.' : 'Sin llegadas mañana.' }}
              </p>
              <ul v-else class="divide-y divide-slate-50">
                <li v-for="fila in llegadasHoy" :key="fila.id">
                  <button type="button" @click="verEnReservas(fila)"
                    class="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-slate-50 transition-colors">
                  <span class="text-xs font-black text-emerald-600 tabular-nums w-11 shrink-0">{{ fila.hora }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-bold text-slate-800 truncate">{{ fila.cliente }}</span>
                    <span class="block text-[11px] font-bold text-slate-400 truncate">{{ fila.unidad }}</span>
                  </span>
                  <span v-if="fila.pax" class="text-[11px] font-black text-slate-500 shrink-0">
                    <i class="fas fa-user text-[9px] mr-1 text-slate-300" aria-hidden="true"></i>{{ fila.pax }}
                  </span>
                  <i class="fas fa-chevron-right text-[10px] text-slate-300 shrink-0" aria-hidden="true"></i>
                  </button>
                </li>
              </ul>
            </div>

            <!-- SALIDAS -->
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden flex flex-col">
              <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100">
                <span class="w-9 h-9 rounded-xl bg-[#E07845]/10 text-[#E07845] flex items-center justify-center shrink-0">
                  <i class="fas fa-right-from-bracket text-sm" aria-hidden="true"></i>
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-black text-slate-900 leading-none">Check-out</p>
                  <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">{{ diaPanel === 'hoy' ? 'Salen hoy' : 'Salen mañana' }}</p>
                </div>
                <span class="text-2xl font-black text-slate-900 tabular-nums">{{ salidasHoy.length }}</span>
              </div>

              <div v-if="cargandoHoy" class="px-5 py-6 text-sm font-bold text-slate-400">
                <i class="fas fa-circle-notch fa-spin mr-2" aria-hidden="true"></i> Cargando…
              </div>
              <p v-else-if="!salidasHoy.length" class="px-5 py-6 text-sm font-bold text-slate-400">
                {{ diaPanel === 'hoy' ? 'Sin salidas hoy.' : 'Sin salidas mañana.' }}
              </p>
              <ul v-else class="divide-y divide-slate-50">
                <li v-for="fila in salidasHoy" :key="fila.id">
                  <button type="button" @click="verEnReservas(fila)"
                    class="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-slate-50 transition-colors">
                  <span class="text-xs font-black text-[#E07845] tabular-nums w-11 shrink-0">{{ fila.hora }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-bold text-slate-800 truncate">{{ fila.cliente }}</span>
                    <span class="block text-[11px] font-bold text-slate-400 truncate">{{ fila.unidad }}</span>
                  </span>
                  <span v-if="fila.pax" class="text-[11px] font-black text-slate-500 shrink-0">
                    <i class="fas fa-user text-[9px] mr-1 text-slate-300" aria-hidden="true"></i>{{ fila.pax }}
                  </span>
                  <i class="fas fa-chevron-right text-[10px] text-slate-300 shrink-0" aria-hidden="true"></i>
                  </button>
                </li>
              </ul>
            </div>
          </div>
        </section>

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

          <!-- Alturas: ver el comentario de arriba sobre las piezas cuadradas. -->
          <div class="grid grid-cols-2 lg:grid-cols-4 auto-rows-[9.5rem] lg:auto-rows-[7.5rem] gap-3">

            <RouterLink
              v-for="mod in seccion.modulos" :key="mod.to" :to="mod.to"
              class="module-card group relative rounded-3xl overflow-hidden flex flex-col transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl"
              :class="mod.destacado
                ? ['col-span-2 row-span-2 p-5 justify-center gap-3 text-white shadow-lg', mod.bar]
                : 'col-span-1 lg:col-span-2 p-4 justify-between bg-white border border-slate-100 shadow-sm'"
            >
              <!-- Solo en las planas: el filo de color que aparece al pasar por
                   encima. La grande no lo necesita, ya ES el color. -->
              <div v-if="!mod.destacado" class="absolute inset-x-0 top-0 h-1 opacity-0 group-hover:opacity-100 transition-opacity duration-300" :class="mod.bar"></div>

              <div class="flex items-start justify-between gap-3">
                <div class="rounded-2xl flex items-center justify-center transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-3"
                  :class="mod.destacado ? 'w-12 h-12 bg-white/15' : ['w-10 h-10', mod.iconBg]">
                  <i class="fas" :class="[mod.icon, mod.destacado ? 'text-xl text-white' : ['text-lg', mod.iconColor]]" aria-hidden="true"></i>
                </div>
                <span v-if="mod.destacado" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/15 text-[9px] font-black uppercase tracking-widest text-white/90">
                  <i class="fas fa-star text-[8px]" aria-hidden="true"></i> Uso diario
                </span>
              </div>

              <div>
                <h3 class="font-black tracking-tight transition-colors"
                  :class="mod.destacado ? 'text-xl md:text-2xl text-white' : ['text-base text-slate-900', mod.titleHover]">
                  {{ mod.title }}
                </h3>
                <p class="font-medium leading-snug mt-1"
                  :class="mod.destacado ? 'text-sm text-white/75 max-w-md' : 'text-[11px] md:text-xs text-slate-500 line-clamp-2'">
                  {{ mod.desc }}
                </p>

                <span v-if="mod.destacado" class="mt-2 inline-flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-white/80 group-hover:gap-3 transition-all">
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
