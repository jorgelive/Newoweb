<script setup lang="ts">
defineProps<{ src: string }>();
</script>
<template>
  <div class="my-6 rounded-2xl overflow-hidden shadow-sm border border-gray-100">
    <img :src="src" class="w-full h-auto object-cover" loading="lazy" alt="Imagen" />
  </div>
</template>