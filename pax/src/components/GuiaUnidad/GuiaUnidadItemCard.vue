<script setup lang="ts">
import { computed } from 'vue';
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';

const props = defineProps<{
  item: any;
  context: any;
  store: any;
}>();

// Texto traducido
const descripcionRaw = computed(() => {
  return props.store.traducir(props.item.descripcion);
});

// Texto del botón traducido
const textoBoton = computed(() => {
  if (!props.item.labelBoton) return '';
  const txt = props.store.traducir(props.item.labelBoton);
  return txt ? txt.trim() : '';
});

// URL del botón (Directa desde la propiedad virtual de la API)
const urlBoton = computed(() => {
  return props.item.urlBoton ? props.item.urlBoton.trim() : '';
});

// Mostrar solo si hay Texto Y URL
const mostrarBoton = computed(() => {
  return textoBoton.value !== '' && urlBoton.value !== '' && urlBoton.value !== '#';
});
</script>

<template>
  <div class="bg-slate-50 rounded-[1.5rem] p-6 text-slate-600">

    <RichTextRenderer
        :content="descripcionRaw"
        :context="context"
    />

    <div v-if="mostrarBoton" class="mt-6">
      <a :href="urlBoton"
         target="_blank"
         rel="noopener noreferrer"
         class="group relative flex items-center justify-center w-full py-4 bg-white text-indigo-600 font-bold text-center rounded-xl border border-indigo-100 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-100 transition-all duration-300">

        <span class="mr-2">{{ textoBoton }}</span>
        <i class="fas fa-arrow-right text-xs transition-transform group-hover:translate-x-1"></i>
      </a>
    </div>

  </div>
</template>