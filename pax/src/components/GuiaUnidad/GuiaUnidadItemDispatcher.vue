<script setup lang="ts">
/* src/components/GuiaUnidad/GuiaUnidadItemDispatcher.vue
   EL JEFE: Solo decide a quién llamar.
*/
import { computed } from 'vue';
import GuiaUnidadItemCard from './GuiaUnidadItemCard.vue';
import GuiaUnidadItemGaleria from './GuiaUnidadItemGaleria.vue';

const props = defineProps<{
  item: any;
  context: any;
  store: any;
  maestro: any;
}>();

const componenteActual = computed(() => {
  const tipo = props.item.tipo?.toLowerCase() || 'card';

  switch (tipo) {
    case 'album':
    case 'galeria':
      return GuiaUnidadItemGaleria;

    case 'wifi':
    case 'card':
    default:
      // Ahora usamos 'Card' para todo lo que sea texto o wifi,
      // porque el Card es inteligente.
      return GuiaUnidadItemCard;
  }
});
</script>

<template>
  <component
      :is="componenteActual"
      :item="item"
      :context="context"
      :store="store"
      :maestro="maestro"
  />
</template>