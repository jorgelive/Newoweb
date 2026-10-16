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
  /**
   * Cada tarjeta se abre y se cierra (16/10/2026). Con diez cuentas desplegadas —Yape, Plin y
   * ocho transferencias— quien busca la suya tenía que recorrer varias pantallas: plegadas, se lee
   * la lista de un vistazo y se abre la que interesa. Lo usa «Cómo reservar» de la cotización; la
   * guía del huésped sigue abierta mientras nadie decida lo contrario.
   */
  plegable?: boolean;
}>();

const maestroStore = useMaestroStore();

const medios = computed<GuiaMedioPago[]>(() => props.mediosPago ?? []);

/** Las tarjetas abiertas. Plegables, arrancan cerradas salvo que haya una sola: no hay qué elegir. */
const abiertas = ref<Set<number>>(new Set());
const estaAbierta = (index: number): boolean => !props.plegable || medios.value.length === 1 || abiertas.value.has(index);
const alternar = (index: number) => {
  const nuevas = new Set(abiertas.value);
  if (nuevas.has(index)) nuevas.delete(index); else nuevas.add(index);
  abiertas.value = nuevas;
};

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
const encabezado = (medio: GuiaMedioPago): string => medio.banco ?? medio.medio;

/**
 * La moneda con su nombre: «Soles», «Dollars». El código ISO («PEN») no lo usa nadie en Perú
 * —se dice soles, o S/—, y en la cabecera se leía como una sigla más del banco (16/10/2026).
 * El nombre es del maestro de monedas (`MaestroMoneda::$titulo`), no de este componente.
 */
const nombreMoneda = (moneda?: string): string => maestroStore.nombreMoneda(moneda);

/**
 * La línea pequeña de encima, sólo cuando dice algo que el título no: «Transferencia bancaria»
 * sobre «BCP · USD». Sobre «Yape · PEN» repetía «Yape» y ocupaba una línea entera.
 */
const antetitulo = (medio: GuiaMedioPago): string => (medio.banco ? medio.medio : '');

const notaDe = (medio: GuiaMedioPago): string =>
  medio.nota ? maestroStore.traducir(medio.nota) : '';

/** Etiquetas de la interfaz: del diccionario, con el español de reserva si aún no cargó. */
const t = (clave: string, porDefecto: string): string => maestroStore.t(clave) || porDefecto;
</script>

<template>
  <div v-if="medios.length > 0" class="my-6 not-prose" :class="plegable ? 'space-y-2' : 'space-y-3'">
    <div
        v-for="(medio, index) in medios"
        :key="index"
        class="relative overflow-hidden rounded-2xl border bg-white border-[#376875]/10 shadow-sm hover:shadow-md hover:border-[#376875]/30 transition-all duration-300"
    >
      <!-- Cabecera compacta: icono pequeño y una sola línea de título. Plegable, es el botón que
           abre la tarjeta; si no, un bloque más. -->
      <component :is="plegable ? 'button' : 'div'"
                 :type="plegable ? 'button' : undefined"
                 :aria-expanded="plegable ? estaAbierta(index) : undefined"
                 @click="plegable && alternar(index)"
                 class="w-full px-4 py-3 flex items-center gap-3 text-left"
                 :class="plegable ? 'hover:bg-slate-50/70 transition-colors' : ''">
        <span class="w-9 h-9 rounded-lg flex items-center justify-center shadow-sm shrink-0 bg-[#376875] text-white">
          <i class="fas text-sm" :class="medio.icono ?? 'fa-money-bill-wave'"></i>
        </span>

        <span class="min-w-0 flex-1">
          <span v-if="antetitulo(medio)" class="block text-[9px] font-black uppercase tracking-widest text-[#E07845] leading-tight">
            {{ antetitulo(medio) }}
          </span>
          <!-- Menos peso que antes: en negro y a tamaño grande, «BBVA · PEN» gritaba más que el
               importe que se va a pagar. La moneda, más suave todavía: es el segundo criterio. -->
          <span class="block leading-tight text-[15px] truncate">
            <span class="font-bold text-gray-800">{{ encabezado(medio) }}</span>
            <span v-if="medio.moneda" class="font-medium text-slate-500"> · {{ nombreMoneda(medio.moneda) }}</span>
          </span>
        </span>

        <i v-if="plegable && medios.length > 1" class="fas text-slate-400 text-xs shrink-0 transition-transform"
           :class="estaAbierta(index) ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
      </component>

      <!-- `v-show` y no `v-if`: el «copiado» de un número no se pierde al cerrar y abrir. -->
      <div v-show="estaAbierta(index)" class="px-4 pb-4 pt-1 space-y-3">
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
