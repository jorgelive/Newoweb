<script setup lang="ts">
/* src/components/GuiaUnidad/GuiaUnidadItemGaleria.vue */
import { computed } from 'vue';
// 🔥 CORRECCIÓN: Usamos el renderizador nuevo, NO useContentProcessor
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';

const props = defineProps<{
  item: any;
  context: any;
  store: any;
}>();

const descripcionRaw = computed(() => {
  return props.store.traducir(props.item.descripcion);
});

const verFoto = (url: string) => window.open(url, '_blank');
</script>

<template>
  <div class="space-y-5">

    <RichTextRenderer
        :content="descripcionRaw"
        :context="context"
    />

    <div v-if="item.galeria && item.galeria.length > 0" class="mt-4">
      <div class="flex items-center gap-2 mb-3">
        <span class="h-px bg-gray-100 flex-1"></span>
        <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">
            {{ props.store.traducir([{ language: 'es', content: 'Galería' }, { language: 'en', content: 'Gallery' }]) }}
        </span>
        <span class="h-px bg-gray-100 flex-1"></span>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div
            v-for="(foto, idx) in item.galeria"
            :key="idx"
            class="group relative rounded-2xl overflow-hidden aspect-[4/3] bg-gray-100 shadow-sm cursor-zoom-in"
            @click="verFoto(foto.imageUrl)"
        >
          <img
              :src="foto.imageUrl"
              class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
              loading="lazy"
           alt="{{ store.traducir(foto.descripcion) }}"/>
          <div v-if="foto.descripcion" class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end p-3">
            <span class="text-white text-xs font-medium line-clamp-2">
              {{ store.traducir(foto.descripcion) }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>