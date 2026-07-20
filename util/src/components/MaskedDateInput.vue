<template>
  <input
      ref="inputRef"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      :value="display"
      :placeholder="placeholder"
      :required="required"
      maxlength="10"
      @input="onInput"
      @paste="onPaste"
      @blur="onBlur"
      class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500 tabular-nums"
  />
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';

const props = withDefaults(defineProps<{
  modelValue: string | null;   // valor canónico: ISO 'YYYY-MM-DD'
  placeholder?: string;
  required?: boolean;
}>(), {
  placeholder: 'DD/MM/AAAA',
  required: false,
});

const emit = defineEmits<{ (e: 'update:modelValue', v: string | null): void }>();

const inputRef = ref<HTMLInputElement | null>(null);
const display = ref('');

// ISO -> 'DD/MM/YYYY'
const isoToDisplay = (iso?: string | null): string => {
  if (!iso) return '';
  const [y, m, d] = iso.split('T')[0].split('-');
  return y && m && d ? `${d}/${m}/${y}` : '';
};

// Solo dígitos, tope 8, inserta los "/"
const maskDigits = (raw: string): string => {
  const d = raw.replace(/\D/g, '').slice(0, 8);
  return [d.slice(0, 2), d.slice(2, 4), d.slice(4, 8)].filter(Boolean).join('/');
};

// 'DD/MM/YYYY' (o dígitos) -> ISO validado, o null si es inválida
const displayToIso = (val: string): string | null => {
  const d = val.replace(/\D/g, '');
  if (d.length !== 8) return null;
  const dd = d.slice(0, 2), mm = d.slice(2, 4), yyyy = d.slice(4, 8);
  const day = +dd, month = +mm, year = +yyyy;
  if (month < 1 || month > 12 || day < 1 || day > 31 || year < 1900) return null;
  const iso = `${yyyy}-${mm}-${dd}`;
  const dt = new Date(`${iso}T00:00:00`);
  // rechaza fechas imposibles (31/02, etc.)
  if (isNaN(dt.getTime()) || dt.getMonth() + 1 !== month || dt.getDate() !== day) return null;
  return iso;
};

// Sanitiza cualquier texto pegado: DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY, YYYY-MM-DD...
const sanitizePaste = (text: string): { iso: string | null; masked: string } => {
  const t = text.trim();
  // Caso ISO / año primero
  const ymd = t.match(/^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})/);
  if (ymd) {
    const [, y, mo, d] = ymd;
    const iso = displayToIso(`${d.padStart(2, '0')}${mo.padStart(2, '0')}${y}`);
    if (iso) return { iso, masked: isoToDisplay(iso) };
  }
  // Caso día primero con separadores variados
  const dmy = t.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})/);
  if (dmy) {
    let [, d, mo, y] = dmy;
    if (y.length === 2) y = (+y > 30 ? '19' : '20') + y; // heurística de siglo
    const iso = displayToIso(`${d.padStart(2, '0')}${mo.padStart(2, '0')}${y}`);
    if (iso) return { iso, masked: isoToDisplay(iso) };
  }
  // Fallback: quedarse con los dígitos enmascarados
  const masked = maskDigits(t);
  return { iso: displayToIso(masked), masked };
};

watch(() => props.modelValue, (v) => {
  // No pisar lo que el usuario escribe si ya representa la misma fecha
  if (displayToIso(display.value) !== (v ?? null)) display.value = isoToDisplay(v);
}, { immediate: true });

const emitFromDisplay = () => emit('update:modelValue', displayToIso(display.value));

const onInput = (e: Event) => {
  const el = e.target as HTMLInputElement;
  display.value = maskDigits(el.value);
  el.value = display.value; // fuerza la máscara en pantalla
  emitFromDisplay();
};

const onPaste = (e: ClipboardEvent) => {
  e.preventDefault();
  const { masked } = sanitizePaste(e.clipboardData?.getData('text') ?? '');
  display.value = masked;
  emitFromDisplay();
};

const onBlur = () => emitFromDisplay();
</script>