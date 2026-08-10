<script setup lang="ts">
import { ref, computed } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';
import type { GuiaMedioPago } from '@/types/paxHuespedGuiaModel';

/**
 * Por dónde puede pagar el huésped. Lo inserta el editor con `{{ medios_pago }}`.
 *
 * Los números NO viven en el texto del ítem: vienen del catálogo `FinMedioCobro` y llegan por
 * `guia.mediosPago`, ya filtrados por procedencia en el backend. Es el mismo reparto que el
 * WiFi y por el mismo motivo — un número de cuenta escrito a mano en el editor se copia mal,
 * envejece y no hay forma de validarlo. Ver docs/Mensajeria.md §16.
 *
 * ⚠️ **Esta pantalla y el asistente del chat leen la MISMA lista.** Si aquí se pintara algo que
 * el asistente no ofrece (o al revés), el huésped tendría dos versiones de dónde mandar su
 * dinero. Por eso no hay ni un filtro más en este componente: se pinta lo que llega.
 */
const props = defineProps<{
  mediosPago?: GuiaMedioPago[];
}>();

const maestroStore = useMaestroStore();

const medios = computed<GuiaMedioPago[]>(() => props.mediosPago ?? []);

/** Feedback al copiar: se marca el número copiado y se apaga solo. */
const indiceCopiado = ref<number | null>(null);

const copiar = (texto: string, index: number) => {
  navigator.clipboard.writeText(texto);
  indiceCopiado.value = index;
  setTimeout(() => {
    if (indiceCopiado.value === index) indiceCopiado.value = null;
  }, 2000);
};

/**
 * El encabezado de cada tarjeta.
 *
 * Con banco y moneda queda «BCP · USD», que es lo que el huésped busca con la vista cuando
 * tiene ocho cuentas delante: primero elige su banco, después la moneda. Sin banco —Yape,
 * Western Union— basta la etiqueta del medio.
 */
const encabezado = (medio: GuiaMedioPago): string =>
  [medio.banco ?? medio.medio, medio.moneda].filter(Boolean).join(' · ');

const notaDe = (medio: GuiaMedioPago): string =>
  medio.nota ? maestroStore.traducir(medio.nota) : '';

/** Etiquetas de la interfaz: del diccionario, con el español de reserva si aún no cargó. */
const t = (clave: string, porDefecto: string): string => maestroStore.t(clave) || porDefecto;
</script>

<template>
  <div v-if="medios.length > 0" class="space-y-3 my-6 not-prose">
    <div
        v-for="(medio, index) in medios"
        :key="index"
        class="relative overflow-hidden rounded-2xl border bg-white border-[#376875]/10 shadow-sm hover:shadow-md hover:border-[#376875]/30 transition-all duration-300"
    >
      <div class="p-5 pb-3 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-sm shrink-0 bg-[#376875] text-white">
          <i class="fas text-lg" :class="medio.icono ?? 'fa-money-bill-wave'"></i>
        </div>

        <div class="min-w-0">
          <p class="text-[10px] font-black uppercase tracking-widest mb-0.5 text-[#E07845]">
            {{ medio.medio }}
          </p>
          <h4 class="font-black text-gray-900 leading-tight text-lg truncate">
            {{ encabezado(medio) }}
          </h4>
        </div>
      </div>

      <div class="px-5 pb-5 pt-2 space-y-3">
        <!-- El número: lo que el huésped va a copiar. `select-all` para que un toque
             largo en móvil lo seleccione entero y no a medias. -->
        <div
            v-if="medio.numero"
            class="flex items-center justify-between p-4 rounded-xl border bg-[#376875]/5 border-[#376875]/10"
        >
          <div class="flex flex-col min-w-0">
            <span class="text-[9px] font-bold uppercase mb-1 text-[#376875]/60">
              {{ t('gui_pago_numero', 'Número') }}
            </span>
            <span class="font-mono text-base font-bold tracking-wider truncate mr-2 text-gray-800 select-all">
              {{ medio.numero }}
            </span>
          </div>

          <button
              @click="copiar(medio.numero, index)"
              class="w-10 h-10 flex items-center justify-center rounded-lg bg-white text-[#E07845] shadow-sm border border-orange-100 hover:bg-[#E07845] hover:text-white hover:shadow-md transition-all active:scale-95 shrink-0"
              :title="t('gui_copiar', 'Copiar')"
          >
            <i class="fas" :class="indiceCopiado === index ? 'fa-check text-green-500 hover:text-white' : 'fa-copy'"></i>
          </button>
        </div>

        <div v-if="medio.titular" class="text-sm">
          <span class="text-[9px] font-bold uppercase text-[#376875]/60 block mb-0.5">
            {{ t('gui_pago_titular', 'A nombre de') }}
          </span>
          <span class="font-semibold text-gray-800">{{ medio.titular }}</span>

          <!-- El mismo nombre sin tildes. Va aquí y no en una nota porque quien ve en su
               banca un nombre distinto al que le dimos se detiene y pregunta. -->
          <span v-if="medio.titularAlterno" class="block text-xs text-gray-500 mt-0.5">
            {{ t('gui_pago_titular_alterno', 'En algunos bancos aparece como') }}
            «{{ medio.titularAlterno }}»
          </span>
        </div>

        <div v-if="medio.cci" class="text-sm">
          <span class="text-[9px] font-bold uppercase text-[#376875]/60 block mb-0.5">
            {{ t('gui_pago_cci', 'CCI') }}
          </span>
          <span class="font-mono font-semibold text-gray-800 select-all">{{ medio.cci }}</span>
        </div>

        <p v-if="notaDe(medio)" class="text-xs text-gray-500 leading-relaxed">
          <i class="fas fa-circle-info mr-1 text-[#376875]/40"></i>
          {{ notaDe(medio) }}
        </p>
      </div>
    </div>
  </div>

  <!-- Lista vacía: o no hay medios cargados, o ninguno aplica a este huésped. NO se
       improvisa un texto con instrucciones de pago: se le dice que pregunte, que es
       exactamente lo que hace el asistente cuando le pasa lo mismo. -->
  <div v-else class="p-8 text-center bg-[#F8FAFC] rounded-2xl border border-dashed border-[#376875]/20 my-4 not-prose">
    <div class="w-12 h-12 bg-[#376875]/5 text-[#376875]/40 rounded-full flex items-center justify-center mx-auto mb-3">
      <i class="fas fa-hand-holding-dollar text-xl"></i>
    </div>
    <p class="text-xs font-bold text-[#376875]/60 uppercase tracking-widest">
      {{ t('gui_pago_consultanos', 'Consúltanos los medios de pago por el chat') }}
    </p>
  </div>
</template>
