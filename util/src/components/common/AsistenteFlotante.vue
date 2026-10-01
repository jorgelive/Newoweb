<script setup lang="ts">
/**
 * El asistente, disponible en TODA la app.
 *
 * ── Por qué deja de vivir sólo en el Home ───────────────────────────────────
 * Vivía embebido en el panel de inicio, así que preguntarle algo mientras editabas una
 * cotización o revisabas el padrón exigía salir de donde estabas —y volver—. Lo que se le
 * pregunta («¿quién está en el grupo 6?», «¿qué casitas tengo libres?») es justo lo que hace falta
 * SIN soltar la pantalla en la que estás.
 *
 * ── La pestaña ─────────────────────────────────────────────────────────────
 * Colapsado es una lengüeta anclada al borde superior. Ocupa 40 px y no tapa nada; desplegado
 * baja un panel con la barra de siempre.
 *
 * ⚠️ **Anclada arriba y no abajo**, a propósito: abajo compiten el teclado del móvil, la barra de
 * gestos del sistema y el aviso de notificaciones que ya vive ahí. Un panel que se abre hacia
 * abajo desde arriba deja el teclado donde está.
 *
 * ⚠️ Se engancha a `useCapasEnHistorial`, así que el gesto «atrás» lo colapsa en vez de sacarte de
 * la pantalla — que es lo que hacía cualquier capa antes de ese composable.
 *
 * ── Se arrastra de lado ─────────────────────────────────────────────────────
 * «No tapa nada» era cierto en el escritorio, no en el móvil: centrada, caía encima del nombre
 * del huésped en la cabecera del chat y de lo que hubiera debajo (Jorge, 01/10/2026). En vez de
 * buscarle a mano un hueco libre en cada pantalla, se arrastra a un lado con el dedo o el ratón y
 * se queda donde se dejó (en este navegador). Un toque sin arrastre la sigue abriendo.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AsistenteBar from '@/components/common/AsistenteBar.vue';
import { useCapasEnHistorial } from '@/composables/useCapasEnHistorial';
import { usePermisosStore } from '@/stores/permisosStore';
import { avisarDeCambio, hayQuienEscuche } from '@/composables/useRefrescoDelAsistente';

const permisos = usePermisosStore();
const capas = useCapasEnHistorial();

const abierto = ref(false);
const huboEscritura = ref(false);

/**
 * ⚠️ Sólo para quien puede usarlo. El asistente consulta el PMS con las skills del usuario, así
 * que a un actor sin permiso de reservas le contestaría «no puedo» a todo: una lengüeta que sólo
 * sabe negarse es peor que ninguna.
 */
// La cadena literal es la convención del resto de la app: no hay diccionario de roles en TS.
const visible = computed(() => permisos.puede('ROLE_RESERVAS_SHOW'));

const abrir = (): void => {
    abierto.value = true;
    capas.abrir('asistente', () => { abierto.value = false; });
};

const cerrar = (): void => capas.cerrar('asistente');

/**
 * El asistente escribió en la base, así que lo que hay en pantalla ya no vale.
 *
 * Se avisa a las vistas montadas que sepan refrescarse ({@see useRefrescoDelAsistente}) y **sólo
 * si no hay ninguna** se ofrece recargar la página.
 *
 * ⚠️ Nunca se recarga entero por cuenta propia: desde el armazón no se sabe si la pantalla de
 * turno tiene un formulario a medias, y perderlo por un refresco que nadie pidió es peor que el
 * dato viejo. Por eso el recargón es un botón, no un efecto.
 */
const alCambiarDatos = (): void => {
    avisarDeCambio();
    huboEscritura.value = !hayQuienEscuche();
};

const recargar = (): void => window.location.reload();

// ── Arrastre lateral ─────────────────────────────────────────────────────────

const CLAVE_POSICION = 'asistente.lengueta.x';
/** Cuánto hay que mover el dedo para que sea un arrastre y no un toque. */
const UMBRAL_ARRASTRE = 6;

/** Dónde está su centro, como fracción del ancho (0 = borde izquierdo, 1 = derecho). */
const leerPosicion = (): number => {
    try {
        const guardada = Number(localStorage.getItem(CLAVE_POSICION));
        return Number.isFinite(guardada) && guardada > 0 && guardada < 1 ? guardada : 0.5;
    } catch {
        return 0.5;
    }
};

const posicion = ref(leerPosicion());
const anchoVentana = ref(window.innerWidth);
const lengueta = ref<HTMLElement | null>(null);

/** Su centro en píxeles, sin que se salga por ningún lado. */
const centroPx = computed(() => {
    const mitad = (lengueta.value?.offsetWidth ?? 120) / 2;
    return Math.min(Math.max(posicion.value * anchoVentana.value, mitad), anchoVentana.value - mitad);
});

let arrastre: { inicioX: number; inicioPos: number; movido: boolean } | null = null;
/** El `click` que sigue a un arrastre no tiene que abrir el asistente. */
let ignorarClick = false;

const alApretar = (e: PointerEvent): void => {
    arrastre = { inicioX: e.clientX, inicioPos: centroPx.value, movido: false };
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
};

const alMover = (e: PointerEvent): void => {
    if (!arrastre) return;
    const dx = e.clientX - arrastre.inicioX;
    if (!arrastre.movido && Math.abs(dx) < UMBRAL_ARRASTRE) return;
    arrastre.movido = true;
    posicion.value = (arrastre.inicioPos + dx) / anchoVentana.value;
};

const alSoltar = (): void => {
    if (!arrastre) return;
    if (arrastre.movido) {
        ignorarClick = true;
        // Se guarda donde se ve, ya recortado al borde.
        posicion.value = centroPx.value / anchoVentana.value;
        try { localStorage.setItem(CLAVE_POSICION, String(posicion.value)); } catch { /* sin almacenamiento: dura hasta recargar */ }
    }
    arrastre = null;
};

const alPulsar = (): void => {
    if (ignorarClick) {
        ignorarClick = false;
        return;
    }
    abrir();
};

const alRedimensionar = (): void => { anchoVentana.value = window.innerWidth; };

onMounted(() => {
    void permisos.cargar();
    window.addEventListener('resize', alRedimensionar);
});

onUnmounted(() => window.removeEventListener('resize', alRedimensionar));
</script>

<template>
  <div v-if="visible" class="fixed top-0 left-0 right-0 z-[9990] pointer-events-none">
    <!-- La lengüeta: pegada al borde y con el asa hacia abajo, para que se lea como algo que
         cuelga y se puede tirar. -->
    <!-- `touch-action: none`: el dedo arrastra la lengüeta, no la página (ni arma el gesto de
         recargar). `left` + `-translate-x-1/2` para que la posición guardada sea su centro. -->
    <div class="relative h-0">
      <button
          v-if="!abierto"
          ref="lengueta"
          type="button"
          title="Preguntar al asistente (arrástrala a un lado si tapa algo)"
          class="pointer-events-auto absolute top-0 -translate-x-1/2 touch-none select-none flex items-center gap-1.5 bg-[#376875] text-white pl-3 pr-3.5 py-1 rounded-b-xl shadow-lg hover:bg-[#2c535d] transition-colors whitespace-nowrap"
          :style="{ left: `${centroPx}px` }"
          @pointerdown="alApretar"
          @pointermove="alMover"
          @pointerup="alSoltar"
          @pointercancel="alSoltar"
          @click="alPulsar"
      >
        <i class="fas fa-wand-magic-sparkles text-[11px]" aria-hidden="true"></i>
        <span class="text-[11px] font-black uppercase tracking-widest">Asistente</span>
        <i class="fas fa-chevron-down text-[9px] opacity-70" aria-hidden="true"></i>
      </button>
    </div>

    <Transition name="baja">
      <div v-if="abierto" class="pointer-events-auto px-2 pt-2 pb-3">
        <div class="mx-auto w-full max-w-2xl">
          <AsistenteBar @datos-cambiados="alCambiarDatos" />

          <!-- Se avisa, no se recarga: la pantalla de turno puede tener cambios sin guardar. -->
          <div v-if="huboEscritura"
               class="mt-2 bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl px-4 py-2.5 flex items-center justify-between gap-3 shadow-sm">
            <p class="text-[11px] font-bold leading-snug">
              El asistente cambió datos. Lo que ves en pantalla puede estar desactualizado.
            </p>
            <button type="button" @click="recargar"
                    class="shrink-0 bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest">
              Recargar
            </button>
          </div>

          <div class="flex justify-center">
            <button type="button" title="Cerrar el asistente"
                    class="mt-1 flex items-center gap-1.5 bg-white/95 backdrop-blur text-slate-500 px-3 py-1 rounded-b-xl shadow-lg border border-slate-200 border-t-0 hover:text-slate-800 transition-colors"
                    @click="cerrar">
              <i class="fas fa-chevron-up text-[10px]" aria-hidden="true"></i>
              <span class="text-[10px] font-black uppercase tracking-widest">Cerrar</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
/* Baja desde el borde, que es de donde cuelga la lengüeta. */
.baja-enter-active, .baja-leave-active { transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1); }
.baja-enter-from, .baja-leave-to { opacity: 0; transform: translateY(-12px); }

@media (prefers-reduced-motion: reduce) {
  .baja-enter-active, .baja-leave-active { transition: none; }
  .baja-enter-from, .baja-leave-to { transform: none; }
}
</style>
