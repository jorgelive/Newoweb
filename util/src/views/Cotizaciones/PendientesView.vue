<script setup lang="ts">
/**
 * Pendientes de cotizar: lo que un cliente pidió y que alguien tiene que trabajar.
 *
 * Ver `docs/Cotizaciones.md`, «Pendientes de cotizar». Un pedido se cierra SOLO al vincularse un
 * expediente a su conversación —nadie lo marca desde aquí para ese caso—; esta pantalla existe
 * para lo que sí hace falta: verlos, y cerrar a mano lo que no va a pasar por un expediente (un
 * duplicado, uno que el cliente retiró).
 */
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import { usePedidosCotizacionStore } from '@/stores/cotizacion/pedidosStore';
import type { ApiCotizacionPedido } from '@/types/cotizacionPedidoModel';

const router = useRouter();
const store = usePedidosCotizacionStore();

/** «Pendientes» primero, que es para lo que se abre esta pantalla; «Resueltos» es el historial. */
const tab = ref<'pendientes' | 'resueltos'>('pendientes');

onMounted(() => {
    store.fetchPedidos();
});

/**
 * «hace 5 min», «hace 3 días»… Mismo criterio que `HomeView.vue`: `Intl.RelativeTimeFormat`
 * decide plurales e irregulares mejor que una plantilla propia.
 */
const FORMATO_RELATIVO = new Intl.RelativeTimeFormat('es-PE', { numeric: 'auto' });
const TRAMOS: readonly [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

function haceCuanto(iso?: string | null): string {
    if (!iso) return '';
    const segundos = (Date.now() - new Date(iso).getTime()) / 1000;
    if (segundos < 60) return 'hace un momento';
    for (const [unidad, tamano] of TRAMOS) {
        if (segundos >= tamano) return FORMATO_RELATIVO.format(-Math.floor(segundos / tamano), unidad);
    }
    return 'hace un momento';
}

function verConversacion(pedido: ApiCotizacionPedido): void {
    if (pedido.conversacionId) router.push(`/chat/${pedido.conversacionId}`);
}

/** El id del expediente que lo resolvió, para abrir su ficha. `file` llega como IRI. */
function idDeExpediente(pedido: ApiCotizacionPedido): string | null {
    return pedido.file?.split('/').pop() ?? null;
}

function verExpediente(pedido: ApiCotizacionPedido): void {
    const id = idDeExpediente(pedido);
    if (id) router.push(`/cotizacion/${id}`);
}

const marcandoId = ref<string | null>(null);

async function marcarHecho(pedido: ApiCotizacionPedido): Promise<void> {
    if (!pedido['@id']) return;
    marcandoId.value = pedido['@id'];
    await store.marcarHecho(pedido);
    marcandoId.value = null;
}
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden">

    <!-- CABECERA -->
    <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between shrink-0 z-30">
      <div class="flex items-center gap-4">
        <AppSwitcher variante="clara" />
        <div>
          <h1 class="font-black text-2xl text-slate-800 tracking-tight leading-none mb-1">Pendientes de cotizar</h1>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Lo que un cliente pidió y hay que trabajar</p>
        </div>
      </div>
      <button @click="router.push('/cotizacion')" class="px-5 py-2.5 bg-white border border-slate-200 hover:border-slate-300 text-slate-600 font-bold rounded-xl transition-colors flex items-center gap-2 shadow-sm">
        <i class="fas fa-arrow-left"></i> <span class="hidden sm:inline">Expedientes</span>
      </button>
    </header>

    <!-- ÁREA PRINCIPAL -->
    <main class="flex-1 overflow-y-auto p-6 md:p-8 max-w-4xl mx-auto w-full">

      <div v-if="store.error" class="mb-6 bg-red-50 text-red-600 border border-red-200 p-4 rounded-2xl flex items-center gap-3 font-bold text-sm shadow-sm">
        <i class="fas fa-exclamation-triangle text-xl"></i> {{ store.error }}
      </div>

      <!-- PESTAÑAS -->
      <div class="mb-6 flex items-center gap-1 bg-slate-100 rounded-xl p-1 w-fit">
        <button @click="tab = 'pendientes'"
                class="px-4 py-1.5 rounded-lg text-xs font-black transition-colors flex items-center gap-2"
                :class="tab === 'pendientes' ? 'bg-white text-[#E07845] shadow-sm' : 'text-slate-500 hover:text-slate-700'">
          Pendientes
          <span v-if="store.pendientes.length" class="px-1.5 py-0.5 rounded-full text-[10px] bg-[#E07845] text-white leading-none">
            {{ store.pendientes.length }}
          </span>
        </button>
        <button @click="tab = 'resueltos'"
                class="px-4 py-1.5 rounded-lg text-xs font-black transition-colors"
                :class="tab === 'resueltos' ? 'bg-white text-[#376875] shadow-sm' : 'text-slate-500 hover:text-slate-700'">
          Resueltos
        </button>
      </div>

      <!-- CARGANDO -->
      <div v-if="store.loading" class="flex flex-col items-center justify-center py-20 text-slate-300">
        <i class="fas fa-circle-notch fa-spin text-4xl mb-4"></i>
        <span class="font-bold uppercase tracking-widest text-sm">Cargando pendientes...</span>
      </div>

      <!-- PENDIENTES: sin nada -->
      <div v-else-if="tab === 'pendientes' && !store.pendientes.length" class="text-center py-20">
        <i class="fas fa-circle-check text-5xl text-emerald-300 mb-4"></i>
        <h2 class="text-xl font-black text-slate-600">Nada pendiente</h2>
        <p class="text-sm text-slate-400 font-medium mt-1">Ningún pedido esperando expediente.</p>
      </div>

      <!-- LISTA: PENDIENTES -->
      <div v-else-if="tab === 'pendientes'" class="space-y-3">
        <div v-for="pedido in store.pendientes" :key="pedido['@id']"
             class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-start gap-4">
          <div class="w-10 h-10 rounded-full bg-[#E07845]/10 text-[#E07845] flex items-center justify-center shrink-0 mt-0.5">
            <i class="fas fa-suitcase-rolling"></i>
          </div>

          <div class="min-w-0 flex-1">
            <p class="font-bold text-slate-800 leading-snug">{{ pedido.texto }}</p>
            <p class="text-xs font-medium text-slate-400 mt-1">{{ haceCuanto(pedido.efectuadaAt) || 'Pedido' }}</p>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <button v-if="pedido.conversacionId" @click="verConversacion(pedido)"
                    title="Ver conversación"
                    class="w-9 h-9 flex items-center justify-center bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl text-slate-500 transition-colors">
              <i class="fas fa-comment-dots"></i>
            </button>
            <button @click="marcarHecho(pedido)" :disabled="marcandoId === pedido['@id']"
                    title="Marcar como hecho (sin abrir expediente)"
                    class="w-9 h-9 flex items-center justify-center bg-slate-50 hover:bg-emerald-50 hover:text-emerald-600 border border-slate-200 rounded-xl text-slate-500 transition-colors disabled:opacity-50">
              <i class="fas" :class="marcandoId === pedido['@id'] ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- RESUELTOS: sin nada -->
      <div v-else-if="!store.resueltos.length" class="text-center py-20">
        <i class="fas fa-inbox text-5xl text-slate-300 mb-4"></i>
        <h2 class="text-xl font-black text-slate-600">Sin historial todavía</h2>
      </div>

      <!-- LISTA: RESUELTOS -->
      <div v-else class="space-y-3">
        <div v-for="pedido in store.resueltos" :key="pedido['@id']"
             class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-start gap-4 opacity-80">
          <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center shrink-0 mt-0.5">
            <i class="fas fa-check"></i>
          </div>

          <div class="min-w-0 flex-1">
            <p class="font-bold text-slate-700 leading-snug line-through decoration-slate-300">{{ pedido.texto }}</p>
            <p class="text-xs font-medium text-slate-400 mt-1">
              {{ haceCuanto(pedido.efectuadaAt) }}
              <template v-if="pedido.cerradoAutomaticamente"> · se abrió su expediente</template>
              <template v-else-if="pedido.efectuadaPorNombre"> · marcado por {{ pedido.efectuadaPorNombre }}</template>
            </p>
          </div>

          <button v-if="pedido.fileLocalizador" @click="verExpediente(pedido)"
                  class="shrink-0 px-3 py-1.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl text-xs font-black text-slate-500 hover:text-[#376875] transition-colors">
            {{ pedido.fileLocalizador }} <i class="fas fa-chevron-right ml-1 text-[10px]"></i>
          </button>
        </div>
      </div>

    </main>
  </div>
</template>
