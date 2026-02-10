<script setup lang="ts">
import { computed } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{
  wifiData?: any[];
  context?: any;
}>();

const maestroStore = useMaestroStore();

// 🔥 BUSCA EN context.data.widgets.wifi_data
const listaFinal = computed(() => {
  if (props.wifiData) return props.wifiData;
  return props.context?.data?.widgets?.wifi_data || [];
});

const isLocked = computed(() => {
  if (listaFinal.value.length > 0 && listaFinal.value[0].is_locked) {
    return true;
  }
  return false;
});

const copiarAlPortapapeles = (texto: string) => {
  if (texto.includes('*')) return;
  navigator.clipboard.writeText(texto);
};

const traducirUbicacion = (ubicacion: any) => {
  return maestroStore.traducir ? maestroStore.traducir(ubicacion) : (ubicacion || 'WiFi');
};
</script>

<template>
  <div v-if="listaFinal.length > 0" class="space-y-4 my-6 not-prose">
    <div v-for="(wifi, index) in listaFinal" :key="index"
         class="relative overflow-hidden rounded-2xl border transition-all duration-300"
         :class="isLocked ? 'bg-slate-100 border-slate-200' : 'bg-white border-indigo-100 shadow-sm'"
    >
      <div class="p-5 pb-3 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-sm shrink-0"
             :class="isLocked ? 'bg-slate-200 text-slate-400' : 'bg-indigo-600 text-white'"
        >
          <i class="fas" :class="isLocked ? 'fa-lock' : 'fa-wifi'"></i>
        </div>
        <div>
          <p class="text-[10px] font-black uppercase tracking-widest"
             :class="isLocked ? 'text-slate-400' : 'text-indigo-500'">
            {{ traducirUbicacion(wifi.ubicacion) }}
          </p>
          <h4 class="font-black text-slate-900 leading-tight text-lg">
            {{ wifi.ssid }}
          </h4>
        </div>
      </div>

      <div class="px-5 pb-5 pt-1">
        <div class="relative group">
          <div class="flex items-center justify-between p-4 rounded-xl border transition-colors"
               :class="isLocked ? 'bg-slate-200/50 border-slate-200' : 'bg-slate-50 border-slate-100 group-hover:border-indigo-200'"
          >
            <div class="flex flex-col">
               <span class="text-[9px] font-bold uppercase mb-1 text-slate-400">
                 Password
               </span>
              <span class="font-mono text-base font-bold tracking-wider"
                    :class="isLocked ? 'text-slate-400' : 'text-slate-800 select-all'">
                  {{ wifi.password }}
              </span>
            </div>

            <button v-if="!isLocked"
                    @click="copiarAlPortapapeles(wifi.password)"
                    class="w-10 h-10 flex items-center justify-center rounded-lg bg-white text-indigo-600 shadow-sm border border-slate-100 hover:bg-indigo-600 hover:text-white hover:shadow-md transition-all active:scale-95"
            >
              <i class="fas fa-copy"></i>
            </button>
            <div v-else class="text-slate-400 px-2">
              <i class="fas fa-lock-alt"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div v-else class="p-8 text-center bg-slate-50 rounded-2xl border border-dashed border-slate-200 my-4">
    <i class="fas fa-wifi text-slate-300 text-2xl mb-3 block"></i>
    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">
      No WiFi Configured
    </p>
  </div>
</template>