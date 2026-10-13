<script setup lang="ts">
import { formatearTelefono } from '@/utils/telefono';
import type { PreviaDeFusion } from '@/composables/useDuenioDeIdentificador';

/**
 * «¿A qué número le escribimos después de unir?» — dentro de la previa de cualquier fusión.
 *
 * 🔥 Camille Boisfer (12/10/2026): al unir su hilo de Booking —con un teléfono puesto a mano— con
 * el de WhatsApp desde el que escribía, quedaron dos números vivos y ninguno principal, y siguió
 * de salida el que nunca nos escribió. Meta rechazó la respuesta del agente (131047) y nadie se
 * enteró hasta la mañana siguiente. El backend propone ahora el del hilo con la ventana abierta
 * (`FusionadorDeHilos::telefonoPropuesto()`); esto lo enseña y deja cambiarlo.
 *
 * Con un solo teléfono no se pinta: no hay nada que elegir.
 */
const props = defineProps<{ previa: PreviaDeFusion }>();
const elegido = defineModel<string | null>({ required: true });

const etiqueta = (valor: string): string => formatearTelefono('+' + valor) || valor;
</script>

<template>
  <fieldset v-if="props.previa.telefonos.length > 1" class="mt-1.5">
    <legend class="text-slate-600 leading-snug">Después de unir, escribirle a:</legend>
    <label v-for="tel in props.previa.telefonos" :key="tel"
           class="flex items-center gap-1.5 mt-1 text-slate-700 cursor-pointer">
      <input type="radio" :value="tel" v-model="elegido" class="accent-amber-600" />
      <span class="font-mono">{{ etiqueta(tel) }}</span>
      <span v-if="tel === props.previa.telefonoPropuesto"
            class="text-[9px] font-black uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1">
        propuesto
      </span>
    </label>
  </fieldset>
</template>
