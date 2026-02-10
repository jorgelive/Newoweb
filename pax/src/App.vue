<script setup>
/*
// src/App.vue
*/

import { onMounted } from 'vue';
import { RouterView } from 'vue-router';
import { useMaestroStore } from '@/stores/maestroStore';

const maestroStore = useMaestroStore();

onMounted(() => {
  // 🔥 Disparamos la carga de idiomas al iniciar la aplicación.
  // No usamos 'await' para no bloquear el renderizado inicial;
  // que cargue en paralelo mientras el router busca la página.
  maestroStore.cargarConfiguracion();
});
</script>

<template>
  <RouterView />
</template>