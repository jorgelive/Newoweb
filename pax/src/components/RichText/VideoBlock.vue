<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ src: string }>();

const videoState = computed(() => {
  const url = props.src.trim();
  // YouTube
  const ytMatch = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/|youtube\.com\/shorts\/)([^"&?\/\s]{11})/i);
  if (ytMatch && ytMatch[1]) return { type: 'iframe', url: `https://www.youtube.com/embed/${ytMatch[1]}?rel=0` };
  // Vimeo
  const vimeoMatch = url.match(/(?:vimeo\.com\/)(\d+)/i);
  if (vimeoMatch && vimeoMatch[1]) return { type: 'iframe', url: `https://player.vimeo.com/video/${vimeoMatch[1]}` };
  // MP4
  if (/\.(mp4|webm|ogg|mov)$/i.test(url)) return { type: 'native', url: url };

  return { type: 'link', url };
});
</script>

<template>
  <div class="w-full my-6">
    <div v-if="videoState.type === 'iframe'" class="relative w-full rounded-2xl overflow-hidden shadow-lg bg-black aspect-video border border-gray-100">
      <iframe :src="videoState.url" class="absolute inset-0 w-full h-full" frameborder="0" allowfullscreen></iframe>
    </div>
    <video v-else-if="videoState.type === 'native'" controls class="w-full rounded-2xl shadow-lg bg-black border border-gray-100">
      <source :src="videoState.url">
    </video>
    <a v-else :href="videoState.url" target="_blank" class="block p-4 bg-gray-50 text-indigo-600 text-center rounded-xl border border-indigo-100 font-bold hover:bg-indigo-50">
      <i class="fas fa-video mr-2"></i> Ver Video
    </a>
  </div>
</template>