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
  /**
   * Medios de cobro para `{{ medios_pago }}`. Llegan ya filtrados por procedencia; vacío =
   * ninguno aplica a este huésped, y el widget pinta «consúltanos» en vez de improvisar.
   */
  mediosPago?: unknown[];
}>();

const blocks = computed<RenderBlock[]>(() => {
  // Las claves son los NOMBRES DE PROP que reciben los widgets: se esparcen tal cual en
  // `props` del bloque (ver RichContentEngine). Renombrar una aquí sin renombrarla en su
  // componente lo deja recibiendo `undefined` y pintando el estado vacío, sin ningún error.
  const engine = new RichContentEngine({
    wifiData: props.wifiData ?? [],
    mediosPago: props.mediosPago ?? [],
  });
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

<style scoped>
/*
 * Estilo de los placeholders de datos que resuelve el backend.
 *
 * `PmsGuiaInterpolador::envolver()` ya envuelve cada valor sustituido en
 * `<span class="guia-dato">`, y marca además con `--bloqueado` los que aún no
 * se pueden revelar (el mensaje `[Disponible el …]` de PmsGuiaMensajes). Ese
 * contrato existía desde el principio, pero NADIE definía las clases: el span
 * llegaba al DOM y se pintaba como texto corriente, indistinguible del resto
 * de la frase.
 *
 * `:deep()` es obligatorio: el contenido entra por `v-html`, así que no lleva
 * el atributo de scope que Vue añade a los elementos que compila él, y un
 * selector scoped normal nunca lo alcanzaría.
 */

/* Dato real (código, nombre, hora…): resalta dentro del párrafo sin gritar. */
.rich-content-renderer :deep(.guia-dato) {
  font-weight: 600;
  color: #2B525D;
}

/* Aún no disponible: itálica, un punto más pequeño y seminegrita. La cursiva y
   el cuerpo menor lo sacan del hilo de la frase; la seminegrita evita que, ya
   reducido y en gris, se lea como letra pequeña descartable. */
.rich-content-renderer :deep(.guia-dato--bloqueado) {
  font-style: italic;
  font-weight: 600;
  font-size: 0.85em;
  color: #94A3B8;
  background: #F8FAFC;
  border: 1px dashed #CBD5E1;
  border-radius: 0.5rem;
  padding: 0.05em 0.45em;
  /* Sin nowrap: el mensaje con fecha es largo y en móvil se desbordaría.
     `clone` hace que, si parte en dos líneas, ambos trozos lleven su borde. */
  box-decoration-break: clone;
  -webkit-box-decoration-break: clone;
}
</style>
