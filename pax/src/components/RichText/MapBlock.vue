<script setup lang="ts">
const props = defineProps<{ src: string }>();
</script>
<template>
  <a :href="src" target="_blank" class="block w-full my-6 group">
    <div class="flex items-center gap-4 p-4 bg-white border border-gray-200 rounded-2xl shadow-sm group-hover:shadow-md group-hover:border-indigo-300 transition-all">
      <div class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xl shrink-0">
        <i class="fas fa-map-marker-alt"></i>
      </div>
      <div class="flex-1 min-w-0">
        <h4 class="font-bold text-gray-900 truncate">Ver Ubicación</h4>
        <p class="text-xs text-gray-500 truncate">{{ src }}</p>
      </div>
      <i class="fas fa-external-link-alt text-gray-300 group-hover:text-indigo-500"></i>
    </div>
  </a>
</template>