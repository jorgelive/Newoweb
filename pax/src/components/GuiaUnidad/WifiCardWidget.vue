<script setup lang="ts">
import { ref, computed } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PmsContenidoTraducible } from '@/types/paxHuespedModel';

/**
 * Widget de redes WiFi. Lo inserta el editor con `{{ widget: wifi }}`.
 *
 * Ya no existe estado "bloqueado" en este componente. Antes recibía el contexto
 * completo y deducía el candado de tres sitios distintos: `config.is_locked`,
 * el `is_locked` de la primera red, y —para decidir si permitía copiar— un
 * `texto.includes('*')` sobre la contraseña enmascarada. Un contrato basado en
 * asteriscos.
 *
 * Ahora el backend manda las redes o no las manda: fuera de la ventana de
 * llegada, `guia.redesWifi` llega vacío y no hay contraseña que ocultar porque
 * no se ha enviado. Lista vacía = todavía no toca.
 */
interface RedWifi {
    ssid: string;
    password: string;
    ubicacion?: PmsContenidoTraducible[] | string;
}

const props = defineProps<{
  wifiData?: RedWifi[];
}>();

const maestroStore = useMaestroStore();

const redes = computed<RedWifi[]>(() => props.wifiData ?? []);

// Feedback visual al copiar la contraseña
const indiceCopiado = ref<number | null>(null);

const copiarAlPortapapeles = (texto: string, index: number) => {
  navigator.clipboard.writeText(texto);
  indiceCopiado.value = index;
  setTimeout(() => {
    if (indiceCopiado.value === index) indiceCopiado.value = null;
  }, 2000);
};

/** El backend manda el array i18n; las guías antiguas traen un string suelto. */
const traducirUbicacion = (ubicacion: RedWifi['ubicacion']): string => {
  if (typeof ubicacion === 'string') return ubicacion || 'WiFi';
  return maestroStore.traducir(ubicacion) || 'WiFi';
};
</script>

<template>
  <div v-if="redes.length > 0" class="space-y-4 my-6 not-prose">
    <div
        v-for="(wifi, index) in redes"
        :key="index"
        class="relative overflow-hidden rounded-2xl border bg-white border-[#376875]/10 shadow-sm hover:shadow-md hover:border-[#376875]/30 transition-all duration-300"
    >
      <div class="p-5 pb-3 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-sm shrink-0 bg-[#E07845] text-white shadow-orange-200">
          <i class="fas fa-wifi text-lg"></i>
        </div>

        <div>
          <p class="text-[10px] font-black uppercase tracking-widest mb-0.5 text-[#376875]">
            {{ traducirUbicacion(wifi.ubicacion) }}
          </p>
          <h4 class="font-black text-gray-900 leading-tight text-lg">
            {{ wifi.ssid }}
          </h4>
        </div>
      </div>

      <div class="px-5 pb-5 pt-2">
        <div class="flex items-center justify-between p-4 rounded-xl border bg-[#376875]/5 border-[#376875]/10">
          <div class="flex flex-col min-w-0">
            <span class="text-[9px] font-bold uppercase mb-1 text-[#376875]/60">Password</span>
            <span class="font-mono text-base font-bold tracking-wider truncate mr-2 text-gray-800 select-all">
              {{ wifi.password }}
            </span>
          </div>

          <button
              @click="copiarAlPortapapeles(wifi.password, index)"
              class="w-10 h-10 flex items-center justify-center rounded-lg bg-white text-[#E07845] shadow-sm border border-orange-100 hover:bg-[#E07845] hover:text-white hover:shadow-md transition-all active:scale-95 shrink-0"
              :title="maestroStore.t('gui_copiar') || 'Copiar contraseña'"
          >
            <i class="fas" :class="indiceCopiado === index ? 'fa-check text-green-500 hover:text-white' : 'fa-copy'"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Sin redes: o la unidad no tiene WiFi configurado, o la ventana de
       llegada aún no se abrió. El aviso con la fecha lo pinta el ítem, que es
       quien lleva `bloqueadoHasta`. -->
  <div v-else class="p-8 text-center bg-[#F8FAFC] rounded-2xl border border-dashed border-[#376875]/20 my-4 not-prose">
    <div class="w-12 h-12 bg-[#376875]/5 text-[#376875]/40 rounded-full flex items-center justify-center mx-auto mb-3">
      <i class="fas fa-wifi text-xl"></i>
    </div>
    <p class="text-xs font-bold text-[#376875]/60 uppercase tracking-widest">
      {{ maestroStore.t('gui_wifi_no_disponible') || 'WiFi no disponible' }}
    </p>
  </div>
</template>
