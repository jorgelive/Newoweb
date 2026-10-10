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
import { useMaestroStore } from '@/stores/maestroStore';

export type ExcepcionDeActividad = { tipo: 'noIncluidos' | 'opcionales' | 'cortesias'; nombre: string };

defineProps<{
  incluidos: string[];
  excepciones: ExcepcionDeActividad[];
}>();

const maestroStore = useMaestroStore();

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
        v-for="n in incluidos"
        :key="'incluidos' + n"
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
  </div>
</template>
