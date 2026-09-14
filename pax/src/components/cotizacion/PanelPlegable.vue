<script setup lang="ts">
/**
 * Una sección plegable de «Lo tuyo».
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Las tres secciones de la tarjeta de identidad —documentos, tarjetas de embarque, grupos y
 * vuelos— se pintaban cada una a su manera: los grupos eran un botón de borde discontinuo que
 * abría una rejilla, los boletos un bloque siempre abierto, y los documentos traían su propio
 * plegado por dentro. Parecían paneles sin serlo, iban pegados unos a otros y sólo uno se podía
 * cerrar.
 *
 * ⚠️ **Y el plegado estaba DUPLICADO**: `MisDocumentos` tenía el suyo —una línea compacta que se
 * abría— mientras el contenedor tenía otro. Dos mecanismos para un mismo gesto acaban
 * discrepando: uno decía «abierto» y el otro seguía colapsado. Ahora el plegado vive aquí y sólo
 * aquí; el componente de dentro se limita a su contenido.
 *
 * ── Qué aporta ──────────────────────────────────────────────────────────────
 * Cabecera pulsable con icono, título, un dato de estado y el galón. Todas iguales, separadas
 * entre sí, y todas se abren y se cierran.
 *
 * ⚠️ **La cabecera es un `<button>` de verdad**, no un `div` con `@click`: así entra con el
 * tabulador y la lee un lector de pantalla. `aria-expanded` y `aria-controls` dicen qué abre y en
 * qué estado está — esta pantalla la abre gente en el aeropuerto, con prisa y a veces con el
 * móvil en modo accesible.
 */
import { computed, useId } from 'vue';

const props = defineProps<{
  /** El icono de Font Awesome, sin el `fa-`: `id-card`, `plane-departure`, `layer-group`. */
  icono: string;
  titulo: string;
  /**
   * El número que hay dentro. Va en la cabecera **a propósito**: «Ver mis grupos (4)» invita a
   * abrirlo; «Ver mis grupos» a secas podría no llevar a nada.
   */
  contador?: number | null;
  /** Una línea de estado bajo el título: «Recibidos, gracias» o «Faltan 2». */
  nota?: string | null;
  /** En verde cuando la nota es una buena noticia; en ámbar cuando pide algo. */
  tono?: 'neutro' | 'listo' | 'pendiente';
}>();

const abierto = defineModel<boolean>({ default: false });

const idCuerpo = useId();

const colorNota = computed(() => ({
  listo: 'text-emerald-700',
  pendiente: 'text-[#E07845]',
  neutro: 'text-slate-500',
}[props.tono ?? 'neutro']));

const colorIcono = computed(() => ({
  listo: 'bg-emerald-100 text-emerald-600',
  pendiente: 'bg-[#E07845]/10 text-[#E07845]',
  neutro: 'bg-[#376875]/10 text-[#376875]',
}[props.tono ?? 'neutro']));
</script>

<template>
  <section class="rounded-2xl border border-slate-200 bg-slate-50/50 overflow-hidden">
    <button type="button" @click="abierto = !abierto"
            :aria-expanded="abierto" :aria-controls="idCuerpo"
            class="w-full flex items-center gap-3 px-4 py-3.5 text-left hover:bg-slate-100/70 transition-colors">
      <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0" :class="colorIcono">
        <i class="fas" :class="`fa-${icono}`"></i>
      </span>

      <span class="min-w-0 flex-1">
        <span class="block text-sm font-black text-gray-800 truncate">
          {{ titulo }}
          <span v-if="contador != null" class="text-[#E07845]">({{ contador }})</span>
        </span>
        <span v-if="nota" class="block text-[11px] font-bold leading-snug" :class="colorNota">
          {{ nota }}
        </span>
      </span>

      <i class="fas text-slate-400 text-xs shrink-0 transition-transform"
         :class="abierto ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
    </button>

    <!-- ⚠️ `v-show` y no `v-if`: el contenido de dentro tiene estado —una foto a medio elegir, un
         desplegable de compañeros abierto— y desmontarlo al cerrar lo perdería. -->
    <div v-show="abierto" :id="idCuerpo" class="px-4 pb-4 pt-1 border-t border-slate-200/70 bg-white/60">
      <slot />
    </div>
  </section>
</template>
