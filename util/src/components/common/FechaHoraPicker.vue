<script setup lang="ts">
// ============================================================================
// SELECTOR DE FECHA (Y HORA) EN 24 h — solución estándar del proyecto.
//
// Envuelve `@vuepic/vue-datepicker` con una máscara estricta de `imask`, tal como
// se implementó primero en CotizacionEditorView.vue. Se extrajo a componente para
// no volver a copiarlo: cualquier campo de fecha/hora de `util/` debería usar esto
// en vez de un `<input type="datetime-local">` nativo.
//
// Por qué no el input nativo:
//   · El formato depende del idioma del SO: en un equipo en inglés sale AM/PM,
//     y aquí se trabaja siempre en 24 h.
//   · El widget nativo cambia de aspecto y de comportamiento en cada navegador.
//   · No admite máscara al teclear; con `imask` se escribe de corrido "13072026 1400".
//
// 🕒 HORA DE PARED, NUNCA INSTANTES
// El `model-value` es una cadena SIN zona horaria ("2026-07-13T14:00"), y todas las
// conversiones se hacen recortando texto, jamás con `new Date()`. Las 14:00 de un
// check-in son las 14:00 en recepción; pasarlas por `Date` les aplicaría el offset
// del navegador y desplazaría la hora (ver §12.5.5 de docs/PmsBeds24ReservasSync.md).
// ============================================================================
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import IMask from 'imask';

const props = withDefaults(defineProps<{
    /** Hora de pared "YYYY-MM-DDTHH:mm" (se admiten segundos y se ignoran). */
    modelValue?: string | null;
    /** Oculta el reloj y trabaja sólo con la fecha. */
    soloFecha?: boolean;
    disabled?: boolean;
    /** Límite inferior, en el mismo formato que `modelValue`. */
    minDate?: string | null;
    /** Marca el campo en rojo (validación del formulario que lo contiene). */
    invalido?: boolean;
}>(), {
    modelValue: '',
    soloFecha: false,
    disabled: false,
    minDate: null,
    invalido: false,
});

const emit = defineEmits<{ 'update:modelValue': [string] }>();

// ============================================================================
// CONVERSIONES — sólo manipulación de texto
// ============================================================================
const PATRON_ISO = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/;

/** "2026-07-13T14:00" -> "13/07/2026 14:00" (lo que ve y teclea el operador). */
function aTextoVisible(iso?: string | null): string {
    const m = String(iso ?? '').match(PATRON_ISO);
    if (!m) return '';
    const [, ano, mes, dia, hh, mm] = m;
    const fecha = `${dia}/${mes}/${ano}`;
    return props.soloFecha ? fecha : `${fecha} ${hh ?? '00'}:${mm ?? '00'}`;
}

/** "13/07/2026 14:00" -> "2026-07-13T14:00". Devuelve null si aún está incompleto. */
function aModelo(texto: string): string | null {
    const esperado = props.soloFecha ? 10 : 16;
    if (texto.length !== esperado) return null;

    const [fecha, hora] = texto.split(' ');
    const [dia, mes, ano] = fecha.split('/');
    if (!dia || !mes || !ano) return null;

    return `${ano}-${mes}-${dia}T${props.soloFecha ? '00:00' : (hora || '00:00')}`;
}

/** El picker trabaja con segundos; el resto de la app, con minutos. */
const valorPicker = computed(() => {
    const m = String(props.modelValue ?? '').match(PATRON_ISO);
    if (!m) return null;
    const [, ano, mes, dia, hh, mm] = m;
    return `${ano}-${mes}-${dia}T${hh ?? '00'}:${mm ?? '00'}:00`;
});

const minPicker = computed(() => {
    const m = String(props.minDate ?? '').match(PATRON_ISO);
    if (!m) return undefined;
    const [, ano, mes, dia, hh, mm] = m;
    return `${ano}-${mes}-${dia}T${hh ?? '00'}:${mm ?? '00'}:00`;
});

function onPickerChange(valor: string | null): void {
    if (!valor) {
        emit('update:modelValue', '');
        return;
    }
    // El picker devuelve con segundos; se recortan para dejar el formato de la app.
    emit('update:modelValue', valor.slice(0, 16));
}

// ============================================================================
// MÁSCARA
//
// Se controla con un `ref` y un `watch` en vez de con una directiva: cuando el valor
// cambia por código —p. ej. el check-out que se arrastra al mover el check-in— hay que
// re-sincronizar `mask.value` a mano, o el input sigue mostrando lo anterior.
// ============================================================================
const inputRef = ref<HTMLInputElement | null>(null);
let mask: ReturnType<typeof IMask> | null = null;

const patron = computed(() => (props.soloFecha ? 'd/m/Y' : 'd/m/Y H:M'));
const marcador = computed(() => (props.soloFecha ? 'DD/MM/AAAA' : 'DD/MM/AAAA HH:MM'));

onMounted(() => {
    if (!inputRef.value) return;

    mask = IMask(inputRef.value, {
        mask: patron.value,
        lazy: false,
        blocks: {
            d: { mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2 },
            m: { mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2 },
            // Rango amplio a propósito: una reserva puede ser de dentro de varios años.
            Y: { mask: IMask.MaskedRange, from: 2024, to: 2099, maxLength: 4 },
            H: { mask: IMask.MaskedRange, from: 0, to: 23, maxLength: 2 },
            M: { mask: IMask.MaskedRange, from: 0, to: 59, maxLength: 2 },
        },
    } as never);

    mask.value = aTextoVisible(props.modelValue);

    // `complete` sólo salta con la máscara entera rellena: no se emiten fechas a medias.
    mask.on('complete', () => {
        const nuevo = aModelo(mask?.value ?? '');
        if (nuevo && nuevo !== props.modelValue) emit('update:modelValue', nuevo);
    });
});

watch(() => props.modelValue, (v) => {
    const texto = aTextoVisible(v);
    if (mask && mask.value !== texto) mask.value = texto;
});

onBeforeUnmount(() => {
    mask?.destroy();
    mask = null;
});
</script>

<template>
    <VueDatePicker
        :model-value="valorPicker"
        @update:model-value="onPickerChange"
        :is-24="true"
        :enable-time-picker="!soloFecha"
        :format="soloFecha ? 'dd/MM/yyyy' : 'dd/MM/yyyy HH:mm'"
        model-type="yyyy-MM-dd'T'HH:mm:ss"
        :min-date="minPicker"
        :disabled="disabled"
        auto-apply
    >
        <template #dp-input="{ onEnter, onTab }">
            <input
                ref="inputRef"
                type="text"
                inputmode="numeric"
                :disabled="disabled"
                :placeholder="marcador"
                @keydown.enter="onEnter"
                @keydown.tab="onTab"
                class="w-full border rounded-lg px-3 py-2 text-sm font-bold text-slate-700 tabular-nums outline-none focus:ring-2 focus:ring-[#376875]/30 disabled:bg-slate-100 disabled:text-slate-400 cursor-text"
                :class="invalido ? 'border-rose-300 bg-rose-50' : 'border-slate-200'"
            />
        </template>
    </VueDatePicker>
</template>
