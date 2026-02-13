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

// Nota: Esta lógica asume que si el primero está bloqueado, todo el widget cambia de estilo.
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
         :class="isLocked
            ? 'bg-slate-100 border-slate-200'
            : 'bg-white border-[#376875]/10 shadow-sm hover:shadow-md hover:border-[#376875]/30'"
    >
      <div class="p-5 pb-3 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-sm shrink-0 transition-colors"
             :class="isLocked
                ? 'bg-slate-200 text-slate-400'
                : 'bg-[#E07845] text-white shadow-orange-200'"
        >
          <i class="fas text-lg" :class="isLocked ? 'fa-lock' : 'fa-wifi'"></i>
        </div>

        <div>
          <p class="text-[10px] font-black uppercase tracking-widest mb-0.5"
             :class="isLocked ? 'text-slate-400' : 'text-[#376875]'">
            {{ traducirUbicacion(wifi.ubicacion) }}
          </p>
          <h4 class="font-black text-gray-900 leading-tight text-lg">
            {{ wifi.ssid }}
          </h4>
        </div>
      </div>

      <div class="px-5 pb-5 pt-2">
        <div class="relative group">
          <div class="flex items-center justify-between p-4 rounded-xl border transition-colors"
               :class="isLocked
                  ? 'bg-slate-200/50 border-slate-200'
                  : 'bg-[#376875]/5 border-[#376875]/10 group-hover:border-[#376875]/30'"
          >
            <div class="flex flex-col">
               <span class="text-[9px] font-bold uppercase mb-1"
                     :class="isLocked ? 'text-slate-400' : 'text-[#376875]/60'">
                 Password
               </span>
              <span class="font-mono text-base font-bold tracking-wider truncate mr-2"
                    :class="isLocked ? 'text-slate-400' : 'text-gray-800 select-all'">
                  {{ wifi.password }}
              </span>
            </div>

            <button v-if="!isLocked"
                    @click="copiarAlPortapapeles(wifi.password)"
                    class="w-10 h-10 flex items-center justify-center rounded-lg bg-white text-[#E07845] shadow-sm border border-orange-100 hover:bg-[#E07845] hover:text-white hover:shadow-md transition-all active:scale-95 shrink-0"
                    title="Copiar contraseña"
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

  <div v-else class="p-8 text-center bg-[#F8FAFC] rounded-2xl border border-dashed border-[#376875]/20 my-4">
    <div class="w-12 h-12 bg-[#376875]/5 text-[#376875]/40 rounded-full flex items-center justify-center mx-auto mb-3">
      <i class="fas fa-wifi text-xl"></i>
    </div>
    <p class="text-xs font-bold text-[#376875]/60 uppercase tracking-widest">
      No WiFi Configured
    </p>
  </div>
</template>