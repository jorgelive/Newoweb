<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const editorRef = ref<HTMLElement | null>(null);

const exec = (command: string, value: string | undefined = undefined) => {
  document.execCommand(command, false, value);
  editorRef.value?.focus();
  emitUpdate();
};

const emitUpdate = () => {
  if (editorRef.value) emit('update:modelValue', editorRef.value.innerHTML);
};

watch(() => props.modelValue, (newVal) => {
  if (editorRef.value && document.activeElement !== editorRef.value && newVal !== editorRef.value.innerHTML) {
    editorRef.value.innerHTML = newVal || '';
  }
});

onMounted(() => {
  if (editorRef.value) editorRef.value.innerHTML = props.modelValue || '';
});
</script>

<template>
  <div class="border border-slate-300 rounded-xl overflow-hidden flex flex-col bg-white shadow-sm focus-within:border-indigo-500 focus-within:ring-1 transition-all">
    <div class="bg-slate-50 border-b border-slate-200 p-1.5 flex gap-1 items-center flex-wrap">
      <button @click.prevent="exec('bold')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-700 font-black" title="Negrita"><i class="fas fa-bold text-xs"></i></button>
      <button @click.prevent="exec('italic')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-700 italic" title="Cursiva"><i class="fas fa-italic text-xs"></i></button>
      <button @click.prevent="exec('underline')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-700 underline" title="Subrayado"><i class="fas fa-underline text-xs"></i></button>
      <div class="w-px h-5 bg-slate-300 mx-1"></div>
      <button @click.prevent="exec('insertUnorderedList')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-700" title="Lista con viñetas"><i class="fas fa-list-ul text-xs"></i></button>
      <button @click.prevent="exec('removeFormat')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-500 ml-auto" title="Limpiar formato"><i class="fas fa-eraser text-xs"></i></button>
    </div>
    <div ref="editorRef" contenteditable="true" class="p-4 min-h-[120px] text-sm text-slate-700 outline-none editor-content" @input="emitUpdate" @blur="emitUpdate"></div>
  </div>
</template>

<style>
.editor-content p { margin-bottom: 0.75em; }
.editor-content p:last-child { margin-bottom: 0; }
.editor-content ul { list-style-type: disc; padding-left: 1.5em; margin-bottom: 0.75em; }
.editor-content ol { list-style-type: decimal; padding-left: 1.5em; margin-bottom: 0.75em; }
.editor-content strong { font-weight: 900; color: #1e293b; }
</style>