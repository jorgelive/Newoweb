<script setup lang="ts">
import { computed } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';
import { RichContentEngine, type RenderBlock } from '@/core/RichContentEngine';

const props = defineProps<{
  content: string;
  context: any;
}>();

const maestroStore = useMaestroStore();

const engine = computed(() => new RichContentEngine(
    props.context,
    maestroStore.traducir
));

const blocks = computed<RenderBlock[]>(() => {
  // 🔥 Dependencia reactiva: obliga a recalcular si cambia el idioma
  const _idioma = maestroStore.idiomaActual;
  return engine.value.parse(props.content);
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