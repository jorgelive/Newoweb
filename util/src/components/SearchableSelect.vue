<!-- src/components/SearchableSelect.vue -->
<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number, null], required: true },
  options: { type: Array as () => Array<{ value: string | null, label: string }>, required: true },
  placeholder: { type: String, default: 'Seleccionar...' },
  darkMode: { type: Boolean, default: false }
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const search = ref('');
const containerRef = ref<HTMLElement | null>(null);

const filteredOptions = computed(() => {
  if (!search.value) return props.options;
  const query = search.value.toLowerCase();
  return props.options.filter(opt => opt.label.toLowerCase().includes(query));
});

const selectedLabel = computed(() => {
  const opt = props.options.find(o => o.value === props.modelValue);
  return opt ? opt.label : null;
});

const toggleDropdown = () => {
  isOpen.value = !isOpen.value;
  if (isOpen.value) search.value = '';
};

const selectOption = (value: string | null) => {
  emit('update:modelValue', value);
  emit('change', value);
  isOpen.value = false;
};

const closeDropdown = (e: MouseEvent) => {
  if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
    isOpen.value = false;
  }
};

onMounted(() => document.addEventListener('click', closeDropdown));
onBeforeUnmount(() => document.removeEventListener('click', closeDropdown));
</script>

<template>
  <div class="relative w-full text-left" ref="containerRef">
    <div @click="toggleDropdown"
         :class="[
           'w-full rounded-lg px-3 py-2 text-sm font-bold flex justify-between items-center cursor-pointer shadow-sm transition-colors border outline-none',
           darkMode
             ? 'bg-slate-900 border-slate-600 text-white hover:border-orange-500 focus:ring-1 focus:ring-orange-500'
             : 'bg-white border-slate-300 text-slate-800 hover:border-[#376875] focus:ring-2 focus:ring-[#376875]'
         ]">
      <span :class="!selectedLabel ? 'text-slate-400' : ''" class="truncate pr-2">
        {{ selectedLabel || placeholder }}
      </span>
      <i class="fas" :class="isOpen ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
    </div>

    <transition name="fade-down">
      <div v-if="isOpen"
           :class="[
             'absolute z-50 w-full mt-1 border rounded-xl shadow-2xl flex flex-col overflow-hidden max-h-64',
             darkMode ? 'bg-slate-800 border-slate-600' : 'bg-white border-slate-200'
           ]">
        <div :class="['p-2 border-b', darkMode ? 'bg-slate-900 border-slate-700' : 'bg-slate-50 border-slate-100']">
          <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
            <input v-model="search" type="text"
                   class="w-full pl-8 pr-3 py-1.5 rounded-lg text-xs font-bold outline-none"
                   :class="darkMode ? 'bg-slate-800 text-white border-slate-600' : 'bg-white text-slate-800 border-slate-300'"
                   placeholder="Escribe para filtrar..." @click.stop>
          </div>
        </div>
        <ul class="overflow-y-auto flex-1 p-1">
          <li @click.stop="selectOption(null)"
              :class="['px-3 py-2 text-xs font-bold cursor-pointer rounded-lg transition-colors mb-0.5', darkMode ? 'text-slate-400 hover:bg-slate-700' : 'text-slate-500 hover:bg-slate-100']">
            -- Limpiar selección --
          </li>
          <li v-for="opt in filteredOptions" :key="opt.value!"
              @click.stop="selectOption(opt.value)"
              :class="[
                'px-3 py-2 text-xs md:text-sm font-bold cursor-pointer rounded-lg transition-colors mb-0.5 truncate',
                darkMode ? 'text-slate-200 hover:bg-slate-700' : 'text-slate-700 hover:bg-indigo-50 hover:text-indigo-700',
                opt.value === modelValue ? (darkMode ? 'bg-slate-700 text-orange-400' : 'bg-indigo-100 text-indigo-800') : ''
              ]">
            {{ opt.label }}
          </li>
        </ul>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.fade-down-enter-active, .fade-down-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.fade-down-enter-from, .fade-down-leave-to { opacity: 0; transform: translateY(-5px); }
</style>