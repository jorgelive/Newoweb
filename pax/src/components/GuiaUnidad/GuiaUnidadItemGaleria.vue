<script setup lang="ts">
/* src/components/GuiaUnidad/GuiaUnidadItemGaleria.vue */
import { computed, ref } from 'vue';
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';
import VueEasyLightbox from 'vue-easy-lightbox';

const props = defineProps<{
  item: any;
  context: any;
  store: any;
}>();

// --- ESTADO DEL LIGHTBOX ---
const visibleRef = ref(false);
const indexRef = ref(0);

// Extraemos solo las URLs para pasarlas al lightbox
const imagesList = computed(() => {
  if (!props.item.galeria) return [];
  return props.item.galeria.map((foto: any) => foto.imageUrl);
});

// Función para abrir el modal en la foto específica
const showImg = (index: number) => {
  indexRef.value = index;
  visibleRef.value = true;
};

const onHide = () => {
  visibleRef.value = false;
};

// --- CONTENIDO ---
const descripcionRaw = computed(() => {
  return props.store.traducir(props.item.descripcion);
});
</script>

<template>
  <div class="space-y-5">

    <RichTextRenderer
        :content="descripcionRaw"
        :context="context"
    />

    <div v-if="item.galeria && item.galeria.length > 0" class="mt-6">

      <div class="flex items-center gap-3 mb-4">
        <span class="h-px bg-[#376875]/10 flex-1"></span>
        <span class="text-[10px] font-bold text-[#376875]/60 uppercase tracking-[0.2em]">
            {{ props.store.traducir([{ language: 'es', content: 'Galería' }, { language: 'en', content: 'Gallery' }]) }}
        </span>
        <span class="h-px bg-[#376875]/10 flex-1"></span>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div
            v-for="(foto, idx) in item.galeria"
            :key="idx"
            class="group relative rounded-2xl overflow-hidden aspect-[4/3] bg-[#376875]/5 shadow-sm hover:shadow-md transition-all duration-300 cursor-zoom-in border border-[#376875]/10"
            @click="showImg(idx)"
        >
          <img
              :src="foto.imageUrl"
              class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
              loading="lazy"
              :alt="store.traducir(foto.descripcion) || 'Gallery image'"
          />

          <div v-if="foto.descripcion" class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end p-4">
            <span class="text-white text-xs font-bold line-clamp-2 drop-shadow-md">
              {{ store.traducir(foto.descripcion) }}
            </span>
          </div>

          <div class="absolute top-2 right-2 w-8 h-8 bg-black/30 backdrop-blur-sm rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300">
            <i class="fas fa-expand text-white text-xs"></i>
          </div>
        </div>
      </div>
    </div>

    <VueEasyLightbox
        :visible="visibleRef"
        :imgs="imagesList"
        :index="indexRef"
        @hide="onHide"
        :moveDisabled="true"
    />
  </div>
</template>