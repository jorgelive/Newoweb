<script setup lang="ts">
import { ref, computed } from 'vue';
import { useTarifasStore } from '@/stores/tarifas/tarifasStore';
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { diasDeRango, type PmsTarifaMasivaPayload } from '@/types/pmsTarifaModel';

/**
 * Equivalente Vue de la acción global "Generar Masivo" del CRUD de EasyAdmin
 * (PmsTarifaRangoCrudController::generarMasivo + GeneradorTarifaMasivaType).
 *
 * Crea un rango por CADA unidad activa que tenga tarifa base configurada,
 * aplicando `porcentaje` sobre el precio base de cada unidad — no sobre un
 * precio único, por eso el formulario no pide importe.
 */
const emit = defineEmits<{ close: []; generated: [creadas: number] }>();

const tarifasStore = useTarifasStore();

const localError = ref<string | null>(null);

const form = ref<PmsTarifaMasivaPayload>({
    fechaInicio: '',
    fechaFin: '',
    porcentaje: 0,
    minStay: 2,
    prioridad: 0,
    importante: false,
});

const rangoInvertido = computed(
    () => !!form.value.fechaInicio && !!form.value.fechaFin && form.value.fechaFin <= form.value.fechaInicio,
);

const dias = computed(() => diasDeRango(form.value.fechaInicio, form.value.fechaFin));

/** Etiqueta legible del ajuste, para que nadie confunda el signo del porcentaje. */
const resumenPorcentaje = computed(() => {
    const p = form.value.porcentaje;
    if (!p) return 'exactamente la tarifa base de cada unidad';
    return p > 0 ? `la tarifa base +${p}%` : `la tarifa base ${p}%`;
});

async function generar(): Promise<void> {
    localError.value = null;

    if (!form.value.fechaInicio || !form.value.fechaFin) {
        localError.value = 'Las fechas de inicio y fin son obligatorias.';
        return;
    }
    if (rangoInvertido.value) {
        localError.value = 'La fecha de fin debe ser posterior a la fecha de inicio.';
        return;
    }

    try {
        const res = await tarifasStore.generarMasivo(form.value);
        emit('generated', res.creadas ?? 0);
    } catch (err) {
        localError.value = extractApiErrorMessage(err, 'No se pudo generar las tarifas.');
    }
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/40" @click="emit('close')"></div>

        <div class="relative w-full max-w-lg h-full bg-white shadow-2xl flex flex-col animate-slide-in">
            <header class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
                <div>
                    <h2 class="font-black text-base tracking-tight">Generar Masivo</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                        Tarifas · Todas las unidades
                    </p>
                </div>
                <button @click="emit('close')" class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </header>

            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">

                <div v-if="localError" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold rounded-xl px-4 py-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>{{ localError }}
                </div>

                <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs font-bold rounded-xl px-4 py-3">
                    <i class="fas fa-info-circle mr-2"></i>
                    Se creará un rango nuevo por cada unidad activa que tenga tarifa base configurada.
                    El precio sale del precio base de cada unidad, no de un importe único.
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label>
                        <span class="text-xs font-bold text-slate-500">Inicio</span>
                        <input type="date" v-model="form.fechaInicio"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Fin</span>
                        <input type="date" v-model="form.fechaFin"
                            class="mt-1 w-full border rounded-lg px-3 py-2 text-sm"
                            :class="rangoInvertido ? 'border-rose-300 bg-rose-50' : 'border-slate-200'" />
                    </label>

                    <p class="col-span-2 -mt-1 text-[11px] font-bold"
                        :class="rangoInvertido ? 'text-rose-600' : 'text-slate-400'">
                        <template v-if="rangoInvertido">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            La fecha de fin debe ser posterior a la de inicio.
                        </template>
                        <template v-else-if="dias">
                            <i class="fas fa-calendar-day mr-1"></i> Rango de {{ dias }} día(s).
                        </template>
                    </p>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Ajuste (%)</span>
                        <input type="number" step="0.01" v-model.number="form.porcentaje"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        <span class="text-[10px] font-bold text-slate-400">20 = +20% · -10 = -10% · 0 = precio base</span>
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Estancia mín. (noches)</span>
                        <input type="number" min="0" v-model.number="form.minStay"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Prioridad</span>
                        <input type="number" v-model.number="form.prioridad"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                    </label>

                    <label class="col-span-2 flex items-center gap-2">
                        <input type="checkbox" v-model="form.importante" class="rounded" />
                        <span class="text-xs font-bold text-slate-500">Marcar como tarifa prioritaria</span>
                    </label>
                </div>

                <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-xs font-bold text-slate-600">
                    <i class="fas fa-magic mr-1.5 text-[#376875]"></i>
                    Cada unidad recibirá {{ resumenPorcentaje }}, con estancia mínima de
                    {{ form.minStay }} noche(s).
                </div>
            </div>

            <footer class="border-t border-slate-200 px-5 py-4 flex items-center justify-end gap-3 shrink-0">
                <button @click="emit('close')" class="px-4 py-2 text-sm font-bold text-slate-500 hover:text-slate-700">
                    Cancelar
                </button>
                <button @click="generar" :disabled="tarifasStore.isSaving"
                    class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-xl text-sm font-black shadow-sm transition-colors">
                    <i class="fas" :class="tarifasStore.isSaving ? 'fa-circle-notch fa-spin' : 'fa-magic'"></i>
                    Generar
                </button>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.animate-slide-in {
    animation: slideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes slideIn {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
}
</style>
