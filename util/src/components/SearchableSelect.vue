<template>
  <div class="relative w-full" v-click-outside="close">
    <div
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

    <div
        v-if="isOpen"
        class="absolute z-[110] w-full mt-2 rounded-2xl shadow-2xl border overflow-hidden animate-fade-in"
        :class="darkMode ? 'bg-slate-800 border-slate-700' : 'bg-white border-slate-200'"
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
            :key="opt.value"
            @click="select(opt)"
            class="px-4 py-2.5 text-sm cursor-pointer transition-colors flex items-center justify-between"
            :class="[
            darkMode ? 'hover:bg-slate-700 text-slate-300' : 'hover:bg-slate-50 text-slate-700',
            modelValue === opt.value ? (darkMode ? 'bg-orange-500/10 text-orange-400 font-black' : 'bg-orange-50 text-orange-600 font-black') : ''
          ]"
        >
          <span class="truncate">{{ opt.label }}</span>
          <i v-if="modelValue === opt.value" class="fas fa-check text-[10px]"></i>
        </li>
        <li v-if="filteredOptions.length === 0" class="px-4 py-8 text-center text-slate-400 text-xs font-bold uppercase tracking-widest">
          No se encontraron resultados
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch } from 'vue';

const props = withDefaults(defineProps<{
  modelValue: any;
  options: { value: any, label: string }[];
  placeholder?: string;
  darkMode?: boolean;
  // 🆕 opcionales — no afectan a las instancias existentes
  required?: boolean;      // marca error si queda vacío tras interactuar
  invalid?: boolean;       // fuerza el estado de error desde el padre (ej. al enviar)
  errorMessage?: string;   // texto de error bajo el campo
}>(), {
  placeholder: '',
  darkMode: false,
  required: false,
  invalid: false,
  errorMessage: '',
});

const emit = defineEmits(['update:modelValue', 'change', 'search', 'blur']);

const isOpen = ref(false);
const searchQuery = ref('');
const searchInputRef = ref<HTMLInputElement | null>(null);
const touched = ref(false); // se activa tras abrir/cerrar (comportamiento tipo blur)

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

const toggle = async () => {
  isOpen.value = !isOpen.value;
  if (isOpen.value) {
    searchQuery.value = '';
    await nextTick();
    searchInputRef.value?.focus(); // 🔥 FOCO AUTOMÁTICO AL ABRIR
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

const select = (opt: any) => {
  emit('update:modelValue', opt.value);
  emit('change', opt.value);
  touched.value = true;
  close();
};

const selectedLabel = computed(() => {
  return props.options.find(o => o.value === props.modelValue)?.label || '';
});

const filteredOptions = computed(() => {
  if (!searchQuery.value) return props.options;
  const q = searchQuery.value.toLowerCase();
  return props.options.filter(o => o.label.toLowerCase().includes(q));
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

// Directiva simple para cerrar al hacer clic fuera
const vClickOutside = {
  mounted(el: any, binding: any) {
    el.clickOutsideEvent = (event: Event) => {
      if (!(el === event.target || el.contains(event.target))) {
        binding.value();
      }
    };
    document.addEventListener('click', el.clickOutsideEvent);
  },
  unmounted(el: any) {
    document.removeEventListener('click', el.clickOutsideEvent);
  },
};
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.animate-fade-in { animation: fadeIn 0.15s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>