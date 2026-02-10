<script setup lang="ts">
import { computed } from 'vue';
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';

const props = defineProps<{
  item: any;
  context: any;
  store: any;
}>();

// Texto raw (traducido del CMS)
const descripcionRaw = computed(() => {
  return props.store.traducir(props.item.descripcion);
});

const textoBoton = computed(() => {
  if (!props.item.labelBoton) return '';
  const txt = props.store.traducir(props.item.labelBoton);
  return txt ? txt.trim() : '';
});

const urlBoton = computed(() => {
  return props.item.urlBoton ? props.item.urlBoton.trim() : '';
});

const mostrarBoton = computed(() => {
  return textoBoton.value !== '' && urlBoton.value !== '' && urlBoton.value !== '#';
});
</script>

<template>
  <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden p-6">

    <h3 v-if="item.titulo" class="text-xl font-black text-gray-900 mb-4">
      {{ store.traducir(item.titulo) }}
    </h3>

    <RichTextRenderer
        :content="descripcionRaw"
        :context="context"
    />

    <div v-if="mostrarBoton" class="mt-8">
      <a :href="urlBoton"
         target="_blank"
         rel="noopener noreferrer"
         class="block w-full py-4 bg-indigo-600 text-white font-bold text-center rounded-xl hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-200">
        {{ textoBoton }} <i class="fas fa-arrow-right ml-2 text-xs"></i>
      </a>
    </div>

  </div>
</template>