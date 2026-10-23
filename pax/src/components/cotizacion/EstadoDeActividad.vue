<script setup lang="ts">
/**
 * La marca de una actividad: «Incluye: …» por cada cosa incluida y la excepción con nombre
 * —«No incluye: Boleto de ingreso a Machu Picchu»— si algo no va.
 *
 * Se pinta en dos sitios de la guía: en la etapa a la que pertenece y, si es lo del PROGRAMA
 * entero (lo que cuelga del segmento del ancla), en la cabecera del servicio. Por eso es un
 * componente: escrita dos veces, las dos copias acabarían diciendo cosas distintas.
 *
 * Qué entra aquí lo decide `estadoPorActividad` en `PaxCotizacionGuiaView.vue`.
 */
import { computed } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';

export type ExcepcionDeActividad = { tipo: 'noIncluidos' | 'opcionales' | 'cortesias'; nombre: string };

const props = withDefaults(defineProps<{
  incluidos: string[];
  excepciones: ExcepcionDeActividad[];
  /**
   * Cuántos «Incluye» se enseñan; el resto queda tras «Ver N más». Sin límite, todos.
   *
   * ⚠️ **Sólo se recortan los «Incluye». Las excepciones —«No incluye», opcional, cortesía— se
   * ven siempre**: están para que el cliente no se entere en la puerta, y recortarlas las
   * escondería justo en las excursiones largas, que es donde más hay.
   */
  limite?: number | null;
  /**
   * ¿Hay detalle que abrir? Entonces la lista termina en una pastilla que lo abre: «Ver N más» si
   * se recortó, «Ver detalle» si cabe entera (Jorge, 23/10/2026). Sustituye al botón «¿Qué incluye
   * el tour?»: la puerta al detalle es la lista que el cliente ya está mirando.
   */
  conDetalle?: boolean;
}>(), { limite: null, conDetalle: false });

const emit = defineEmits<{ detalle: [] }>();

const maestroStore = useMaestroStore();

/** Cuántos «Incluye» quedan tras «Ver N más». */
const ocultos = computed(() =>
    props.limite !== null && props.incluidos.length > props.limite ? props.incluidos.length - props.limite : 0);

/** ¿Este «Incluye» queda tras el recorte? Se pinta igual: en papel no hay «Ver más» que pulsar. */
const recortado = (i: number): boolean => props.limite !== null && i >= props.limite;

const ESTILO_EXCEPCION: Record<ExcepcionDeActividad['tipo'], { clave: string; texto: string; icono: string; cls: string }> = {
  // ⚠️ **Gris y con icono de información, no rojo con una ✗.** La guía es para vender: la
  // exclusión tiene que estar —que el cliente no se entere en la puerta de Machu Picchu—, pero
  // como un dato práctico, no como la alarma más llamativa de la página. En rojo era lo primero
  // que se veía en la actividad estrella del viaje.
  noIncluidos: { clave: 'cot_no_incluye', texto: 'No incluye', icono: 'fa-circle-info', cls: 'bg-slate-50 border-slate-200 text-slate-500' },
  opcionales: { clave: 'cot_opcional', texto: 'Opcional', icono: 'fa-circle-question', cls: 'bg-amber-50 border-amber-200 text-amber-800' },
  cortesias: { clave: 'cot_cortesia', texto: 'Cortesía', icono: 'fa-gift', cls: 'bg-sky-50 border-sky-200 text-sky-700' },
};
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <span
        v-for="(n, i) in incluidos"
        :key="'incluidos' + n"
        :class="{ 'solo-papel': recortado(i) }"
        class="inline-flex items-start gap-1.5 text-[11px] font-semibold border rounded-lg px-2 py-1 leading-snug bg-emerald-50 border-emerald-200 text-emerald-800"
    >
      <i class="fas fa-circle-check mt-0.5 shrink-0 text-emerald-500"></i>
      <span><span class="font-bold">{{ maestroStore.t('cot_incluye') || 'Incluye' }}:</span> {{ n }}</span>
    </span>
    <span
        v-for="x in excepciones"
        :key="x.tipo + x.nombre"
        class="inline-flex items-start gap-1.5 text-[11px] font-semibold border rounded-lg px-2 py-1 leading-snug"
        :class="ESTILO_EXCEPCION[x.tipo].cls"
    >
      <i class="fas mt-0.5 shrink-0" :class="ESTILO_EXCEPCION[x.tipo].icono"></i>
      <span><span class="font-bold">{{ maestroStore.t(ESTILO_EXCEPCION[x.tipo].clave) || ESTILO_EXCEPCION[x.tipo].texto }}:</span> {{ x.nombre }}</span>
    </span>
    <!-- La puerta al detalle, en el naranja de los enlaces y con flecha para que se lea como algo
         que se pulsa. Fuera de la impresión: en papel no abre nada. -->
    <button
        v-if="conDetalle || ocultos > 0"
        type="button"
        @click="emit('detalle')"
        class="no-imprimir inline-flex items-center gap-1.5 text-[11px] font-black border rounded-lg px-2.5 py-1 leading-snug bg-white border-[#E07845]/30 text-[#E07845] hover:bg-[#E07845]/5 hover:border-[#E07845]/60 transition-colors"
    >
      <template v-if="ocultos > 0">{{ maestroStore.t('cot_inc_ver_mas', { n: String(ocultos) }) || `Ver ${ocultos} más` }}</template>
      <template v-else>{{ maestroStore.t('cot_inc_ver_detalle') || 'Ver detalle' }}</template>
      <i class="fas fa-circle-arrow-right text-[10px]"></i>
    </button>
  </div>
</template>

<style scoped>
/* ⚠️ **Lo que se recorta en pantalla se imprime entero**, como las descripciones de la guía: en
   papel no hay «Ver N más» que pulsar, y el recorte pasaría a ser «no existe». */
.solo-papel { display: none; }
@media print {
  .solo-papel { display: inline-flex !important; }
  .no-imprimir { display: none !important; }
}
</style>
