<template>
  <div class="relative w-full">
    <div
        ref="triggerRef"
        @click="toggle"
        :class="[
        'w-full cursor-pointer flex justify-between items-center px-4 py-3 border rounded-xl transition-all shadow-sm',
        darkMode ? 'bg-slate-800 border-slate-600 text-white' : 'bg-white border-slate-300 text-slate-700',
        isOpen
          ? 'ring-2 ring-orange-500 border-orange-500'
          : (showError ? 'ring-1 ring-red-400 border-red-300' : '')
      ]"
    >
      <span class="truncate font-bold text-sm" :class="!selectedLabel ? 'text-slate-400 font-medium' : ''">
        {{ selectedLabel || placeholder }}
      </span>
      <i class="fas fa-chevron-down text-[10px] transition-transform" :class="{ 'rotate-180': isOpen }"></i>
    </div>

    <!-- Mensaje de error opcional (solo si se activa `required`/`invalid`) -->
    <p v-if="showError && errorMessage" class="text-[9px] font-bold text-red-400 mt-1">
      {{ errorMessage }}
    </p>

    <!-- La lista va teletransportada al body con posición fija: dentro del panel la recortaba
         el contenedor con `overflow-y-auto` y la tapaba la barra de totales. `posicionar()` la
         ancla al disparador y la abre hacia arriba si no cabe abajo. -->
    <Teleport to="body">
    <div
        v-if="isOpen"
        ref="dropdownRef"
        class="fixed z-[200] rounded-2xl shadow-2xl border overflow-hidden animate-fade-in"
        :class="darkMode ? 'bg-slate-800 border-slate-700' : 'bg-white border-slate-200'"
        :style="dropdownStyle"
    >
      <div class="p-2 border-b" :class="darkMode ? 'border-slate-700' : 'border-slate-100'">
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
          <input
              ref="searchInputRef"
              v-model="searchQuery"
              type="text"
              :placeholder="placeholder"
              class="w-full pl-9 pr-4 py-2 text-sm rounded-lg outline-none transition-all"
              :class="darkMode ? 'bg-slate-900 border-slate-700 text-white focus:bg-slate-950' : 'bg-slate-50 border-slate-100 text-slate-800 focus:bg-white'"
              @keyup.esc="close"
          />
        </div>
      </div>

      <ul class="max-h-64 overflow-y-auto py-1 custom-scrollbar">
        <li
            v-for="opt in filteredOptions"
            :key="opt.value ?? opt.label"
            @click="select(opt)"
            class="px-4 py-2.5 text-sm cursor-pointer transition-colors flex items-start justify-between gap-2"
            :class="[
            darkMode ? 'hover:bg-slate-700 text-slate-300' : 'hover:bg-slate-50 text-slate-700',
            modelValue === opt.value ? (darkMode ? 'bg-orange-500/10 text-orange-400 font-black' : 'bg-orange-50 text-orange-600 font-black') : ''
          ]"
        >
          <span class="min-w-0 flex-1">
            <!-- Con segunda línea el nombre se ENVUELVE en vez de cortarse: el punto de
                 tener dos líneas es que quepa, no repartir el mismo recorte. -->
            <span class="block" :class="opt.sublabel ? 'leading-snug' : 'truncate'">{{ opt.label }}</span>
            <!--
              En PASTILLAS y no como una cadena con separadores: con proveedor, modalidad,
              categoría, procedencia y edad, el texto seguido se envolvía en tres o cuatro
              renglones donde no se distinguía dónde acababa un dato y empezaba el siguiente.
              Troceado por ` · ` cada uno cae en su caja y la fila fluye sola: en un móvil se
              apilan, en pantalla ancha caben en una. Que ocupe varias líneas es lo correcto
              aquí — recortar es lo que escondía el proveedor.
            -->
            <span v-if="opt.sublabel" class="flex flex-wrap gap-1 mt-1">
              <span v-for="(dato, i) in opt.sublabel.split(' · ')" :key="i"
                    class="text-[10px] font-bold px-1.5 py-0.5 rounded border leading-tight"
                    :class="darkMode
                        ? 'bg-slate-900/60 text-slate-300 border-slate-700'
                        : 'bg-slate-100 text-slate-600 border-slate-200'">{{ dato }}</span>
            </span>
          </span>
          <i v-if="modelValue === opt.value" class="fas fa-check text-[10px] mt-1 shrink-0"></i>
        </li>
        <!--
          Distinguir «aún no he buscado» de «no hay nada» importa: con el mensaje único,
          escribir una letra menos del mínimo mostraba «no se encontraron resultados»
          sobre un catálogo que sí tenía la respuesta, y el operador daba por hecho que
          el proveedor no existía.
        -->
        <li v-if="filteredOptions.length === 0 && faltanCaracteres" class="px-4 py-8 text-center text-slate-400 text-xs font-bold uppercase tracking-widest">
          Escribe al menos {{ minCharsBusqueda }} letras
        </li>
        <li v-else-if="filteredOptions.length === 0" class="px-4 py-8 text-center text-slate-400 text-xs font-bold uppercase tracking-widest">
          No se encontraron resultados
        </li>
      </ul>
    </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch, onBeforeUnmount } from 'vue';

/**
 * Valor de una opción: siempre un identificador (id o IRI), o null cuando no hay
 * selección. El componente no necesita saber nada más del dominio.
 */
type OpcionValor = string | number | null | undefined;

const props = withDefaults(defineProps<{
  modelValue: OpcionValor;
  /**
   * `sublabel` es opcional y sólo pinta una segunda línea bajo el nombre. Existe porque
   * las tarifas necesitaban meter proveedor, procedencia y edad en una sola cadena y se
   * cortaba a la mitad en el móvil: la información estaba, pero no se leía.
   *
   * La búsqueda mira las DOS líneas, así que se puede teclear el proveedor.
   */
  options: { value: OpcionValor, label: string, sublabel?: string }[];
  placeholder?: string;
  darkMode?: boolean;
  // 🆕 opcionales — no afectan a las instancias existentes
  required?: boolean;      // marca error si queda vacío tras interactuar
  invalid?: boolean;       // fuerza el estado de error desde el padre (ej. al enviar)
  errorMessage?: string;   // texto de error bajo el campo
  /**
   * Letras que el padre exige para lanzar su búsqueda remota. Sólo cambia el mensaje del
   * vacío; el filtrado local sigue igual. Por defecto 0 = comportamiento de siempre.
   */
  minCharsBusqueda?: number;
}>(), {
  placeholder: '',
  darkMode: false,
  required: false,
  invalid: false,
  errorMessage: '',
  minCharsBusqueda: 0,
});

const emit = defineEmits(['update:modelValue', 'change', 'search', 'blur']);

const isOpen = ref(false);
const searchQuery = ref('');
const searchInputRef = ref<HTMLInputElement | null>(null);
const triggerRef = ref<HTMLElement | null>(null);
const dropdownRef = ref<HTMLElement | null>(null);
const dropdownStyle = ref<Record<string, string>>({});
const touched = ref(false); // se activa tras abrir/cerrar (comportamiento tipo blur)

// Ancla la lista teletransportada al disparador. Abre hacia abajo salvo que no quepa y arriba
// haya más sitio (típico en inputs pegados al footer en móvil). Se recalcula al abrir y en
// cada scroll/resize mientras está abierta.
const posicionar = (): void => {
  const el = triggerRef.value;
  if (!el) return;
  const r = el.getBoundingClientRect();
  const alto = 320; // input de búsqueda + lista (max-h-64) + márgenes, aproximado

  // ⚠️ `visualViewport` y NO `innerHeight`, y es toda la diferencia en un teléfono.
  //
  // `innerHeight` **no encoge cuando sale el teclado** (en iOS nunca; en Android depende del
  // modo). Con él, la cuenta dice que hay sitio de sobra abajo justo cuando el teclado acaba de
  // taparlo: la lista se abre hacia abajo, queda detrás del teclado, y como el campo ya está en
  // el límite tampoco hay scroll con el que alcanzarla. No es que se vea mal — es que no se
  // puede elegir, que es el fallo que reportó el operador.
  const vv = window.visualViewport;
  const alturaVisible = vv?.height ?? window.innerHeight;
  const desplazamiento = vv?.offsetTop ?? 0;

  const espacioAbajo = (desplazamiento + alturaVisible) - r.bottom;
  const espacioArriba = r.top - desplazamiento;
  const abrirArriba = espacioAbajo < alto && espacioArriba > espacioAbajo;
  dropdownStyle.value = abrirArriba
    ? { left: `${r.left}px`, width: `${r.width}px`, bottom: `${window.innerHeight - r.top + 8}px` }
    : { left: `${r.left}px`, width: `${r.width}px`, top: `${r.bottom + 8}px` };
};

// Cierre al hacer clic fuera: la lista está teletransportada, así que hay que contemplar
// tanto el disparador como la propia lista (si no, teclear en el buscador la cerraría).
const onDocDown = (event: Event): void => {
  const t = event.target as Node;
  if (triggerRef.value?.contains(t) || dropdownRef.value?.contains(t)) return;
  close();
};

watch(isOpen, async (abierta) => {
  if (abierta) {
    await nextTick();
    posicionar();
    searchInputRef.value?.focus();
    window.addEventListener('scroll', posicionar, true);
    window.addEventListener('resize', posicionar);
    // El teclado no dispara `resize` de forma fiable; lo que sí cambia es el visualViewport. Sin
    // esto, la lista se coloca bien al abrirse y se queda mal en cuanto sube el teclado.
    window.visualViewport?.addEventListener('resize', posicionar);
    window.visualViewport?.addEventListener('scroll', posicionar);
    document.addEventListener('pointerdown', onDocDown, true);
  } else {
    quitarEscuchas();
  }
});

const quitarEscuchas = (): void => {
  window.removeEventListener('scroll', posicionar, true);
  window.removeEventListener('resize', posicionar);
  window.visualViewport?.removeEventListener('resize', posicionar);
  window.visualViewport?.removeEventListener('scroll', posicionar);
  document.removeEventListener('pointerdown', onDocDown, true);
};

onBeforeUnmount(quitarEscuchas);

// Temporizador para el debounce de la búsqueda asíncrona
let debounceTimer: ReturnType<typeof setTimeout>;

// Observamos lo que el usuario escribe para emitir la búsqueda tras 300ms de inactividad
watch(searchQuery, (newVal) => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    emit('search', newVal);
  }, 300);
});

const isEmpty = computed(() => props.modelValue === '' || props.modelValue === null || props.modelValue === undefined);

// Muestra error si: el padre lo fuerza (invalid) o es required, está vacío y ya fue tocado
const showError = computed(() => props.invalid || (props.required && isEmpty.value && touched.value));

const toggle = () => {
  isOpen.value = !isOpen.value;
  if (isOpen.value) {
    searchQuery.value = '';
    // El foco, el posicionamiento y los listeners los pone el watch de `isOpen`.
  } else {
    touched.value = true;
    emit('blur');
  }
};

const close = () => {
  if (isOpen.value) {
    touched.value = true;
    emit('blur');
  }
  isOpen.value = false;
};

const select = (opt: { value: OpcionValor; label: string; sublabel?: string }) => {
  emit('update:modelValue', opt.value);
  emit('change', opt.value);
  touched.value = true;
  close();
};

const selectedLabel = computed(() => {
  return props.options.find(o => o.value === props.modelValue)?.label || '';
});

/** Se escribió algo, pero aún no lo suficiente para que el padre busque de verdad. */
const faltanCaracteres = computed(() =>
    props.minCharsBusqueda > 0
    && searchQuery.value.trim().length > 0
    && searchQuery.value.trim().length < props.minCharsBusqueda
);

const filteredOptions = computed(() => {
  if (!searchQuery.value) return props.options;
  const q = searchQuery.value.toLowerCase();
  return props.options.filter(o => `${o.label} ${o.sublabel ?? ''}`.toLowerCase().includes(q));
});

/**
 * Valida a demanda (útil al enviar el formulario desde el padre).
 * Marca el campo como tocado y devuelve si es válido.
 */
const validate = (): boolean => {
  touched.value = true;
  return !(props.required && isEmpty.value);
};

defineExpose({ validate, isValid: computed(() => !(props.required && isEmpty.value)) });
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.animate-fade-in { animation: fadeIn 0.15s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>