<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useTarifasStore } from '@/stores/tarifas/tarifasStore';
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { fechaLegible, nochesDeRango, sumarDias, type PmsTarifaMasivaPayload, type PmsTarifaRango } from '@/types/pmsTarifaModel';

/**
 * Equivalente Vue de la acción global "Generar Masivo" del CRUD de EasyAdmin
 * (PmsTarifaRangoCrudController::generarMasivo + GeneradorTarifaMasivaType).
 *
 * Crea un rango por CADA unidad activa que tenga tarifa base configurada,
 * aplicando `porcentaje` sobre el precio base de cada unidad — no sobre un
 * precio único, por eso el formulario no pide importe.
 */
const props = defineProps<{
    /**
     * Rango que se está viendo en el calendario, del 1 al 1 (un mes o dos, según
     * la vista). Es con lo que arranca el formulario: se genera para lo que se
     * está mirando, y teclear las dos fechas a mano cada vez era el paso más
     * pesado del proceso.
     */
    rangoDefecto?: { fechaInicio: string; fechaFin: string } | null;
}>();

const emit = defineEmits<{ close: []; generated: [creadas: number] }>();

const tarifasStore = useTarifasStore();

const localError = ref<string | null>(null);

const form = ref<PmsTarifaMasivaPayload>({
    fechaInicio: props.rangoDefecto?.fechaInicio ?? '',
    fechaFin: props.rangoDefecto?.fechaFin ?? '',
    porcentaje: 0,
    minStay: 2,
    prioridad: 0,
    importante: false,
});

const rangoInvertido = computed(
    () => !!form.value.fechaInicio && !!form.value.fechaFin && form.value.fechaFin <= form.value.fechaInicio,
);

const noches = computed(() => nochesDeRango(form.value.fechaInicio, form.value.fechaFin));

/** Etiqueta legible del ajuste, para que nadie confunda el signo del porcentaje. */
const resumenPorcentaje = computed(() => {
    const p = form.value.porcentaje;
    if (!p) return 'exactamente la tarifa base de cada unidad';
    return p > 0 ? `la tarifa base +${p}%` : `la tarifa base ${p}%`;
});

// ============================================================================
// PRIORIDAD SUGERIDA
//
// El masivo compite con lo que ya hay: si nace con una prioridad igual o menor
// que la de un rango que pisa esas fechas, se genera y NO se aplica — el
// operador ve el calendario igual que antes y no sabe por qué. Se propone la
// prioridad más alta de TODAS las casitas en la ventana + 1, porque el masivo
// escribe en todas a la vez y basta una para dejarlo tapado.
// ============================================================================
const prioridadTocada = ref(false);
const prioridadGanadora = ref<number | null>(null);

async function sugerirPrioridad(): Promise<void> {
    const { fechaInicio, fechaFin } = form.value;
    if (!fechaInicio || !fechaFin || prioridadTocada.value) return;

    try {
        // Solapamiento con extremos EXCLUSIVOS por los dos lados (`strictly_`):
        // un rango que termina justo el día en que empieza la ventana no la pisa
        // —su última noche es la víspera—, y uno que empieza el día de fin
        // tampoco. Con `before`/`after` a secas se contaban esos dos vecinos y la
        // prioridad salía inflada.
        //
        // `order[prioridad]=desc` deja el máximo en la primera fila, así que no
        // hace falta paginar (además `itemsPerPage` no está habilitado como
        // parámetro de cliente).
        const r = await apiClient.get('/platform/pms/pms_tarifa_rangos', {
            params: {
                activo: true,
                'fechaInicio[strictly_before]': fechaFin,
                'fechaFin[strictly_after]': fechaInicio,
                'order[prioridad]': 'desc',
            },
        });

        const filas = (r.data['hydra:member'] ?? r.data['member'] ?? []) as PmsTarifaRango[];
        if (prioridadTocada.value) return;

        prioridadGanadora.value = filas.length ? (filas[0].prioridad ?? 0) : null;
        form.value.prioridad = (prioridadGanadora.value ?? -1) + 1;
    } catch {
        // Sin respuesta se deja la prioridad como esté: es una sugerencia.
    }
}

// ============================================================================
// PRECIO EFECTIVO POR CASITA (leyenda en vivo)
//
// El formulario no pide importe —cada unidad parte de SU tarifa base—, así que
// con sólo el porcentaje no había forma de saber qué precio iba a quedar sin
// generar y mirar el calendario. Espejo de GeneradorTarifaMasivaService: el
// mismo `round(base * (1 + %/100))`, SIN decimales — el precio se publica redondo
// en los canales. Si cambia allí, cambia aquí.
// ============================================================================
interface FilaEfectiva {
    id: string;
    nombre: string;
    simbolo: string;
    base: number;
    efectivo: number;
}

const filasEfectivas = computed<FilaEfectiva[]>(() => {
    const factor = 1 + (Number(form.value.porcentaje) || 0) / 100;

    return tarifasStore.unidades
        // Mismo filtro que el servicio: `activo = true` y `tarifaBaseActiva = true`.
        // Una unidad sin tarifa base no recibe rango, y decirlo aquí evita la
        // sorpresa de «se generaron 6 y tengo 8 casitas».
        .filter((u) => u.activo && u.tarifaBaseActiva && Number(u.tarifaBasePrecio ?? 0) > 0)
        .map((u) => {
            const base = Number(u.tarifaBasePrecio ?? 0);

            return {
                id: String(u.id),
                nombre: u.nombre ?? '—',
                simbolo: u.tarifaBaseMonedaSimbolo ?? '',
                base,
                efectivo: Math.round(base * factor),
            };
        });
});

/**
 * La base se muestra tal cual está guardada (puede llevar céntimos de una carga
 * antigua); el efectivo, redondo, porque es lo que se va a escribir.
 */
const importeBase = (valor: number): string => (Number.isInteger(valor) ? String(valor) : valor.toFixed(2));

onMounted(() => {
    void tarifasStore.fetchMasters();
    void sugerirPrioridad();
});

// Cambiar el rango cambia quién es el ganador: se vuelve a sugerir mientras el
// operador no haya tecleado una prioridad propia.
watch(() => [form.value.fechaInicio, form.value.fechaFin], () => void sugerirPrioridad());

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
                        <span class="text-xs font-bold text-slate-500">Fin (salida)</span>
                        <input type="date" v-model="form.fechaFin" :min="form.fechaInicio ? sumarDias(form.fechaInicio, 1) : undefined"
                            class="mt-1 w-full border rounded-lg px-3 py-2 text-sm"
                            :class="rangoInvertido ? 'border-rose-300 bg-rose-50' : 'border-slate-200'" />
                    </label>

                    <p class="col-span-2 -mt-1 text-[11px] font-bold"
                        :class="rangoInvertido ? 'text-rose-600' : 'text-slate-400'">
                        <template v-if="rangoInvertido">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            La fecha de fin debe ser POSTERIOR a la de inicio: marca la salida y no lleva tarifa.
                        </template>
                        <template v-else-if="noches">
                            <i class="fas fa-moon mr-1"></i>
                            {{ noches }} noche(s). El {{ fechaLegible(form.fechaFin) }} es salida: no lleva tarifa.
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
                        <!-- En cuanto el operador la teclea, deja de sugerirse
                             (ver sugerirPrioridad). -->
                        <input type="number" v-model.number="form.prioridad"
                            @input="prioridadTocada = true"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        <span class="text-[10px] font-bold text-slate-400">
                            <template v-if="prioridadGanadora !== null">
                                La más alta en estas fechas es {{ prioridadGanadora }}: con {{ form.prioridad }} manda la nueva.
                            </template>
                            <template v-else>No hay tarifas en estas fechas todavía.</template>
                        </span>
                    </label>

                    <label class="col-span-2 flex items-center gap-2">
                        <input type="checkbox" v-model="form.importante" class="rounded" />
                        <span class="text-xs font-bold text-slate-500">Marcar como tarifa prioritaria</span>
                    </label>
                </div>

                <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 space-y-3">
                    <p class="text-xs font-bold text-slate-600">
                        <i class="fas fa-magic mr-1.5 text-[#376875]"></i>
                        Cada unidad recibirá {{ resumenPorcentaje }}, con estancia mínima de
                        {{ form.minStay }} noche(s).
                    </p>

                    <!-- Qué precio queda en cada casita, EN VIVO: el formulario no
                         pide importe (sale de la tarifa base de cada unidad), así
                         que sin esto se generaba sin saber el resultado. -->
                    <div v-if="filasEfectivas.length" class="rounded-lg border border-slate-200 bg-white overflow-hidden">
                        <div v-for="fila in filasEfectivas" :key="fila.id"
                            class="flex items-center justify-between gap-2 px-3 py-1.5 border-b border-slate-100 last:border-b-0">
                            <span class="text-xs font-bold text-slate-600 truncate">{{ fila.nombre }}</span>
                            <span class="text-xs font-bold shrink-0 tabular-nums">
                                <span class="text-slate-400">{{ fila.simbolo }} {{ importeBase(fila.base) }}</span>
                                <i class="fas fa-arrow-right mx-1.5 text-[9px] text-slate-300"></i>
                                <span :class="fila.efectivo === fila.base ? 'text-slate-700'
                                    : (fila.efectivo > fila.base ? 'text-emerald-600' : 'text-rose-600')">
                                    {{ fila.simbolo }} {{ fila.efectivo }}
                                </span>
                            </span>
                        </div>
                    </div>

                    <p v-else class="text-[11px] font-bold text-amber-700">
                        <i class="fas fa-triangle-exclamation mr-1"></i>
                        Ninguna unidad activa tiene tarifa base configurada: no se generará nada.
                    </p>
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
