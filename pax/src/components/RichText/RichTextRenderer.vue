<script setup lang="ts">
import { computed } from 'vue';
import { RichContentEngine, type RenderBlock } from '@/core/RichContentEngine';

/**
 * Pinta el cuerpo de un ítem de guía o catálogo.
 *
 * `content` llega con los placeholders de DATOS ya resueltos por el backend;
 * aquí solo se expanden los bloques de maquetación. Ver RichContentEngine.
 */
const props = defineProps<{
  content: string;
  /** Redes WiFi para `{{ widget: wifi }}`. Vacío = el backend no las liberó. */
  wifiData?: unknown[];
}>();

const blocks = computed<RenderBlock[]>(() => {
  const engine = new RichContentEngine({ wifiData: props.wifiData ?? [] });
  return engine.parse(props.content);
});
</script>

<template>
  <div class="rich-content-renderer space-y-6">
    <template v-for="block in blocks" :key="block.id">

      <component
          v-if="block.component"
          :is="block.component"
          v-bind="block.props"
          class="w-full my-6"
      />

      <div
          v-else-if="block.content"
          class="prose prose-indigo max-w-none text-gray-600 leading-relaxed"
          v-html="block.content"
      ></div>

    </template>
  </div>
</template>
