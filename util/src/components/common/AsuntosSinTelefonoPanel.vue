<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import { useRefrescoDelAsistente } from '@/composables/useRefrescoDelAsistente';
import { ETIQUETA_MOTIVO, type AsuntoSinTelefono } from '@/types/asuntoSinTelefonoModel';

/**
 * «Sin teléfono»: lo vigente a lo que hoy no le sale un WhatsApp, con un clic para arreglarlo.
 *
 * Booking dejó de pasar el teléfono del huésped (octubre de 2026). Sin número sólo se le puede
 * escribir por la mensajería de Beds24 —~6 minutos de vuelta, sin botones—, así que hay que
 * pedírselo y apuntarlo. El backend junta lo de cada dominio
 * (`AsuntosSinTelefonoController`); el criterio es el del envío, no el de la ficha: una reserva
 * con el número en la ficha pero no en su conversación también sale aquí («Número sin
 * conectar»), porque a ésa tampoco le llega nada.
 *
 * El clic abre donde se arregla: la reserva en Reservas (su «Editar» del teléfono, o el banner de
 * unir hilos si el número ya era de otra conversación) y el expediente en su ficha.
 */
const router = useRouter();

const asuntos = ref<AsuntoSinTelefono[]>([]);
const cargando = ref(true);
const error = ref(false);

const cargar = async (): Promise<void> => {
  try {
    const { data } = await apiClient.get('/platform/message/asuntos-sin-telefono');
    asuntos.value = (data?.asuntos ?? []) as AsuntoSinTelefono[];
    error.value = false;
  } catch {
    error.value = true;
  } finally {
    cargando.value = false;
  }
};

onMounted(cargar);
useRefrescoDelAsistente(() => { void cargar(); });

const reservas = computed(() => asuntos.value.filter(a => a.negocio === 'pms_reserva'));
const cotizaciones = computed(() => asuntos.value.filter(a => a.negocio === 'cotizacion_file'));

const grupos = computed(() => [
  { titulo: 'Reservas', icono: 'fa-bed', filas: reservas.value },
  { titulo: 'Cotizaciones', icono: 'fa-suitcase', filas: cotizaciones.value },
].filter(g => g.filas.length > 0));

/** 05/10 — la fecha corta basta: todo lo de aquí es de estas semanas. */
const fechaCorta = (ymd: string | null): string => (ymd ? `${ymd.slice(8, 10)}/${ymd.slice(5, 7)}` : '');

const abrir = (a: AsuntoSinTelefono): void => {
  if (a.negocio === 'pms_reserva' && a.destino.evento) {
    // `evento` es obligatorio: Reservas abre la ficha por él (ver HomeView `verEnReservas()`).
    void router.push({ path: '/reservas', query: { evento: a.destino.evento, reserva: a.destino.reserva } });
  } else if (a.negocio === 'cotizacion_file' && a.destino.file) {
    void router.push({ name: 'file_detalle', params: { id: a.destino.file } });
  }
};
</script>

<template>
  <section class="mb-8 md:mb-10">
    <div class="flex items-baseline gap-3 mb-4 md:mb-5">
      <h2 class="text-sm font-black uppercase tracking-[0.18em] text-[#E07845]">Sin teléfono</h2>
      <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">No les llega WhatsApp</span>
      <span class="flex-1 h-px bg-slate-200"></span>
    </div>

    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
      <div v-if="cargando" class="px-5 py-6 text-sm font-bold text-slate-400">
        <i class="fas fa-circle-notch fa-spin mr-2" aria-hidden="true"></i> Cargando…
      </div>
      <p v-else-if="error" class="px-5 py-6 text-sm font-bold text-slate-400">No se pudo cargar el reporte.</p>
      <p v-else-if="!asuntos.length" class="px-5 py-6 text-sm font-bold text-slate-400">
        <i class="fas fa-check text-emerald-500 mr-1.5" aria-hidden="true"></i>Todas las reservas y cotizaciones vigentes tienen teléfono.
      </p>

      <template v-else>
        <div v-for="grupo in grupos" :key="grupo.titulo">
          <div class="flex items-center gap-2 px-5 py-2.5 bg-slate-50 border-b border-slate-100">
            <i class="fas text-[11px] text-slate-400" :class="grupo.icono" aria-hidden="true"></i>
            <span class="text-[11px] font-black uppercase tracking-widest text-slate-500">{{ grupo.titulo }}</span>
            <span class="text-[11px] font-black text-slate-400 tabular-nums">{{ grupo.filas.length }}</span>
          </div>

          <ul class="divide-y divide-slate-50">
            <li v-for="fila in grupo.filas" :key="`${fila.negocio}:${fila.id}`">
              <button type="button" @click="abrir(fila)"
                      class="w-full text-left flex items-center gap-3 px-5 py-3 hover:bg-slate-50 transition-colors">
                <span class="text-xs font-black text-slate-500 tabular-nums w-11 shrink-0">{{ fechaCorta(fila.fecha) }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-bold text-slate-800 truncate">{{ fila.nombre }}</span>
                  <span class="block text-[11px] font-bold text-slate-400 truncate">{{ fila.detalle }}</span>
                </span>
                <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider"
                      :class="fila.fusionSugerida ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500'">
                  {{ fila.fusionSugerida ? '¿Misma persona?' : ETIQUETA_MOTIVO[fila.motivo] }}
                </span>
                <i class="fas fa-chevron-right text-[10px] text-slate-300 shrink-0" aria-hidden="true"></i>
              </button>
            </li>
          </ul>
        </div>
      </template>
    </div>
  </section>
</template>
