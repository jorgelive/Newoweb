<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const editorRef = ref<HTMLElement | null>(null);

const exec = (command: string, value: string | undefined = undefined) => {
  document.execCommand(command, false, value);
  editorRef.value?.focus();
  emitUpdate();
  void nextTick(() => sincronizarBurbuja());
};

const emitUpdate = () => {
  if (editorRef.value) emit('update:modelValue', editorRef.value.innerHTML);
};

// ── La barra flotante sobre la selección ─────────────────────────────────────
//
// ⚠️ **Este editor es `contenteditable` + `execCommand`, no TinyMCE.** El panel resolvió lo
// mismo activando el plugin `quickbars`; aquí no hay plugins, así que la burbuja se monta a
// mano. El repertorio es el mismo a propósito —negrita, cursiva, viñetas, numeración, limpiar—
// porque quien redacta salta entre las dos pantallas y el gesto debe ser idéntico.
//
// ⚠️ **Va en un `Teleport` al body.** El editor vive dentro de tarjetas con `overflow-hidden`
// y de un panel con scroll propio: en flujo normal la burbuja se recortaba contra el borde de
// la tarjeta justo cuando se selecciona cerca del margen.
interface PosicionBurbuja { top: number; left: number }

const burbuja = ref<PosicionBurbuja | null>(null);
const activos = ref<Record<string, boolean>>({});

/** ¿La selección actual está dentro de ESTE editor? Puede haber varios en pantalla. */
const seleccionPropia = (sel: Selection | null): boolean => {
  if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return false;

  const nodo = sel.getRangeAt(0).commonAncestorContainer;

  return !!editorRef.value?.contains(nodo.nodeType === Node.TEXT_NODE ? nodo.parentNode : nodo);
};

const sincronizarBurbuja = () => {
  const sel = document.getSelection();

  if (!seleccionPropia(sel)) {
    burbuja.value = null;
    return;
  }

  const rect = sel!.getRangeAt(0).getBoundingClientRect();

  // Una selección de ancho cero (un clic que arrastra a nada) no merece burbuja.
  if (rect.width === 0 && rect.height === 0) {
    burbuja.value = null;
    return;
  }

  burbuja.value = { top: rect.top, left: rect.left + rect.width / 2 };

  // `queryCommandState` está deprecado igual que `execCommand`, pero son la pareja: mientras la
  // base sea una, el estado tiene que leerse con la otra.
  activos.value = {
    bold: document.queryCommandState('bold'),
    italic: document.queryCommandState('italic'),
    underline: document.queryCommandState('underline'),
    insertUnorderedList: document.queryCommandState('insertUnorderedList'),
    insertOrderedList: document.queryCommandState('insertOrderedList'),
  };
};

// Al hacer scroll la selección sigue viva pero su rect se mueve: sin esto la burbuja se queda
// flotando sobre otro párrafo. Se recalcula en vez de esconderla, que es menos brusco.
const alDesplazar = () => { if (burbuja.value) sincronizarBurbuja(); };

onMounted(() => {
  if (editorRef.value) editorRef.value.innerHTML = props.modelValue || '';
  document.addEventListener('selectionchange', sincronizarBurbuja);
  window.addEventListener('scroll', alDesplazar, true);
  window.addEventListener('resize', alDesplazar);
});

onUnmounted(() => {
  document.removeEventListener('selectionchange', sincronizarBurbuja);
  window.removeEventListener('scroll', alDesplazar, true);
  window.removeEventListener('resize', alDesplazar);
});

watch(() => props.modelValue, (newVal) => {
  if (editorRef.value && document.activeElement !== editorRef.value && newVal !== editorRef.value.innerHTML) {
    editorRef.value.innerHTML = newVal || '';
  }
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
      <button @click.prevent="exec('insertOrderedList')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-700" title="Lista numerada"><i class="fas fa-list-ol text-xs"></i></button>
      <button @click.prevent="exec('removeFormat')" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 text-slate-500 ml-auto" title="Limpiar formato"><i class="fas fa-eraser text-xs"></i></button>
    </div>
    <div ref="editorRef" contenteditable="true" class="p-4 min-h-[120px] text-sm text-slate-700 outline-none editor-content" @input="emitUpdate" @blur="emitUpdate"></div>
  </div>

  <!-- ⚠️ `@mousedown.prevent` en CADA botón, no `@click`: sin él el navegador mueve el foco al
       pulsar y la selección se pierde ANTES de que `execCommand` llegue a aplicarse. El comando
       corre en el `@click`, que ya no tiene que recuperar nada. -->
  <Teleport to="body">
    <transition name="burbuja">
      <div v-if="burbuja"
           class="burbuja-flotante fixed z-[60] flex items-center gap-0.5 bg-slate-800 text-white rounded-xl shadow-2xl px-1.5 py-1"
           :style="{ top: burbuja.top - 8 + 'px', left: burbuja.left + 'px' }">
        <button v-for="b in [
                  { cmd: 'bold', icono: 'fa-bold', titulo: 'Negrita' },
                  { cmd: 'italic', icono: 'fa-italic', titulo: 'Cursiva' },
                  { cmd: 'insertUnorderedList', icono: 'fa-list-ul', titulo: 'Viñetas' },
                  { cmd: 'insertOrderedList', icono: 'fa-list-ol', titulo: 'Numerada' },
                ]"
                :key="b.cmd"
                type="button"
                :title="b.titulo"
                @mousedown.prevent
                @click="exec(b.cmd)"
                class="w-8 h-8 flex items-center justify-center rounded-lg transition-colors"
                :class="activos[b.cmd] ? 'bg-white text-slate-800' : 'hover:bg-slate-700 text-slate-200'">
          <i class="fas text-xs" :class="b.icono"></i>
        </button>

        <div class="w-px h-5 bg-slate-600 mx-1"></div>

        <button type="button" title="Limpiar formato"
                @mousedown.prevent
                @click="exec('removeFormat')"
                class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-700 text-slate-200 transition-colors">
          <i class="fas fa-eraser text-xs"></i>
        </button>
      </div>
    </transition>
  </Teleport>
</template>

<style>
.editor-content p { margin-bottom: 0.75em; }
.editor-content p:last-child { margin-bottom: 0; }
.editor-content ul { list-style-type: disc; padding-left: 1.5em; margin-bottom: 0.75em; }
.editor-content ol { list-style-type: decimal; padding-left: 1.5em; margin-bottom: 0.75em; }
.editor-content strong { font-weight: 900; color: #1e293b; }

/* ⚠️ El centrado va aquí y NO con `-translate-x-1/2 -translate-y-full` de Tailwind: la
   transición anima `transform`, así que si el estado base lo pusiera Tailwind y los estados de
   entrada/salida esta hoja, se pisarían y la burbuja saltaría de sitio al aparecer. Una sola
   propiedad, un solo dueño. */
.burbuja-flotante { transform: translate(-50%, -100%); }
.burbuja-enter-active, .burbuja-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.burbuja-enter-from, .burbuja-leave-to { opacity: 0; transform: translate(-50%, -100%) scale(0.94); }
</style>
