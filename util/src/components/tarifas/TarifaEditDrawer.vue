<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useTarifasStore } from '@/stores/tarifas/tarifasStore';
// extractApiErrorMessage es un helper puro (no toca el store de reservas):
// se reutiliza para que ambos módulos PMS muestren los errores 403/422 igual.
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { apiClient } from '@/services/apiClient';
import { coleccionFeed, type CalendarEventoFeed } from '@/types/calendarFeedModel';
import {
    pmsUnidadIri,
    maestroMonedaIri,
    sumarDias,
    toDateInput,
    fechaLegible,
    nochesDeRango,
    netoDe,
    NETO_BOOKING,
    NETO_AIRBNB,
    SYNC_STATUS_META,
    type PmsTarifaExtendedProps,
    type PmsTarifaRango,
    type PmsTarifaRangoCreate,
    type PmsTarifaRangoPatch,
    type PmsTarifaSyncStatus,
} from '@/types/pmsTarifaModel';

const props = defineProps<{
    /** Id del rango a ver/editar. Si es null, el drawer está en modo creación. */
    tarifaId?: string | null;
    /**
     * Valores iniciales al crear. `unidadId` es opcional: al crear desde el
     * botón de la cabecera no hay casita elegida todavía y la escoge el usuario.
     */
    createDefaults?: { unidadId?: string; fechaInicio: string; fechaFin: string } | null;
    /** Abre en modo solo-lectura ("Ver"); se pasa a edición con el botón del header. */
    startReadOnly?: boolean;
}>();

const emit = defineEmits<{ close: []; saved: []; deleted: [] }>();

const tarifasStore = useTarifasStore();

const isCreate = computed(() => !props.tarifaId);
const isLoadingDrawer = ref(false);
const localError = ref<string | null>(null);

const readOnly = ref(!!props.startReadOnly);
function habilitarEdicion(): void {
    readOnly.value = false;
}

// ============================================================================
// FORM STATE
// ============================================================================
interface TarifaFormData {
    unidad: string;
    moneda: string;
    fechaInicio: string;
    fechaFin: string;
    precio: string;
    minStay: number;
    importante: boolean;
    prioridad: number;
    activo: boolean;
}

/** USD por defecto: mismo criterio que PmsTarifaRangoFactory::create() en el backend. */
const MONEDA_DEFAULT = 'USD';

function formVacio(overrides: Partial<TarifaFormData> = {}): TarifaFormData {
    return {
        unidad: '',
        moneda: MONEDA_DEFAULT,
        fechaInicio: '',
        fechaFin: '',
        precio: '0.00',
        minStay: 2,
        importante: false,
        prioridad: 0,
        activo: true,
        ...overrides,
    };
}

const form = ref<TarifaFormData>(formVacio());
const syncStatus = ref<PmsTarifaSyncStatus | null>(null);

function formDesdeTarifa(tarifa: PmsTarifaRango): TarifaFormData {
    return {
        unidad: tarifa.unidad?.id ?? '',
        moneda: tarifa.moneda?.id ?? MONEDA_DEFAULT,
        fechaInicio: toDateInput(tarifa.fechaInicio),
        fechaFin: toDateInput(tarifa.fechaFin),
        precio: tarifa.precio ?? '0.00',
        minStay: tarifa.minStay ?? 2,
        importante: !!tarifa.importante,
        prioridad: tarifa.prioridad ?? 0,
        activo: tarifa.activo ?? true,
    };
}

// ============================================================================
// DERIVADOS PARA LA UI
// ============================================================================
const nombreUnidad = computed(
    () => tarifasStore.unidades.find(u => u.id === form.value.unidad)?.nombre || '—',
);

const simboloMoneda = computed(
    () => tarifasStore.monedas.find(m => m.id === form.value.moneda)?.simbolo || '',
);

const noches = computed(() => nochesDeRango(form.value.fechaInicio, form.value.fechaFin));

/** Netos que el calendario ya muestra en el título del evento (espejo del provider PHP). */
const netoBooking = computed(() => netoDe(form.value.precio, NETO_BOOKING));
const netoAirbnb = computed(() => netoDe(form.value.precio, NETO_AIRBNB));

/**
 * Rango que no cubre ninguna noche. Incluye `fin === inicio`: no es sólo un
 * detalle estético, el flattener descarta ese rango entero y la tarifa se guarda
 * sin efecto y sin error (ver `nochesDeRango`).
 */
const rangoInvertido = computed(
    () => !!form.value.fechaInicio && !!form.value.fechaFin && form.value.fechaFin <= form.value.fechaInicio,
);

const syncMeta = computed(() => (syncStatus.value ? SYNC_STATUS_META[syncStatus.value] : null));

// ============================================================================
// PRECARGA DESDE LA TARIFA VIGENTE
//
// Una tarifa nueva casi siempre nace para RETOCAR la que ya rige ese día: subirla
// un fin de semana, bajarla en temporada baja. Arrancar el formulario en blanco
// obligaba a ir al calendario, mirar el precio vigente y teclearlo — y a acertar
// con una prioridad que lo dejara por encima, o la tarifa nueva no se aplicaba.
//
// Se lee del MISMO calendario compactado que pinta la vista (el motor ya resolvió
// ahí quién gana ese día), y la prioridad sale del rango ganador +1: se crea una
// tarifa para que sea efectiva, no para que quede tapada.
// ============================================================================

/** El operador ya tecleó un precio: la precarga no lo pisa. */
const precioTocado = ref(false);

/**
 * Última pareja casita|día ya consultada. `cargarDatos()` pide la precarga al
 * abrir y el watch la repite cuando el operador cambia casita o día; sin esta
 * marca, abrir el cajón disparaba las dos y se pedía el feed por duplicado.
 */
const ultimaPrecarga = ref('');

async function precargarTarifaVigente(unidadId: string, fecha: string): Promise<void> {
    if (!unidadId || !fecha || precioTocado.value) return;

    const clave = `${unidadId}|${fecha}`;
    if (ultimaPrecarga.value === clave) return;
    ultimaPrecarga.value = clave;

    try {
        // `end` EXCLUSIVO: [D, D+1) es «sólo el día D» (misma técnica que
        // `cargarTarifaDelDia()` en ReservasView).
        const r = await apiClient.get('/fullcalendar/load/event/tarifa_rangos_compactados_spa', {
            params: { start: fecha, end: sumarDias(fecha, 1), _t: Date.now() },
        });

        const tramo = coleccionFeed<CalendarEventoFeed<PmsTarifaExtendedProps>>(r.data)
            .find((e) => String(e.resourceId) === String(unidadId));
        const vigente = tramo?.extendedProps;
        if (!vigente?.precio || precioTocado.value) return;

        form.value.precio = vigente.precio;
        if (vigente.minStay) form.value.minStay = vigente.minStay;

        // La prioridad NO viaja en el feed —el compactado sólo lleva el precio
        // ganador—, así que se pide el rango de origen, que el propio tramo
        // identifica con `tarifaRangoId`.
        //
        // Se pide con `apiClient` y no con `tarifasStore.fetchTarifa()` a
        // propósito: el store guardaría el rango ganador en `tarifaActiva` y
        // encendería su `isLoading`, y aquí no estamos editando ese rango —
        // sólo espiándolo.
        if (!vigente.tarifaRangoId) return;

        const ganador = (await apiClient.get(
            `/platform/pms/pms_tarifa_rangos/${vigente.tarifaRangoId}`
        )).data as PmsTarifaRango;

        // +1 sobre el ganador: una tarifa se crea para que sea efectiva. Con la
        // misma prioridad el desempate lo decide el motor y el operador no sabría
        // por qué su precio nuevo no se aplica.
        form.value.prioridad = (ganador.prioridad ?? 0) + 1;
        if (ganador.moneda?.id) form.value.moneda = ganador.moneda.id;
    } catch {
        // Sin tarifa vigente ese día (o con el feed caído) el formulario se queda
        // como estaba: esto es una comodidad, no un requisito para crear.
    }
}

// ============================================================================
// CARGA
// ============================================================================
async function cargarDatos(): Promise<void> {
    isLoadingDrawer.value = true;
    localError.value = null;
    syncStatus.value = null;

    try {
        await tarifasStore.fetchMasters();

        if (props.tarifaId) {
            const tarifa = await tarifasStore.fetchTarifa(props.tarifaId);
            form.value = formDesdeTarifa(tarifa);
            syncStatus.value = tarifa.syncStatus ?? null;
            return;
        }

        // Creación: la selección en el calendario fija casita y rango; desde el
        // botón de la cabecera llegan solo las fechas y la casita queda vacía.
        form.value = formVacio({
            unidad: props.createDefaults?.unidadId ?? '',
            fechaInicio: props.createDefaults?.fechaInicio ?? '',
            fechaFin: props.createDefaults?.fechaFin ?? '',
        });
        precioTocado.value = false;
        ultimaPrecarga.value = '';

        await precargarTarifaVigente(form.value.unidad, form.value.fechaInicio);
    } catch (err) {
        localError.value = extractApiErrorMessage(err, 'No se pudo cargar la tarifa.');
    } finally {
        isLoadingDrawer.value = false;
    }
}

watch(
    () => [props.tarifaId, props.createDefaults],
    () => cargarDatos(),
    { immediate: true },
);

// Creando desde el botón de la cabecera no hay casita todavía: en cuanto se
// elige (o se mueve el día), se mira qué tarifa rige y se propone.
watch(
    () => [form.value.unidad, form.value.fechaInicio],
    ([unidad, fecha]) => {
        if (props.tarifaId || readOnly.value) return;
        void precargarTarifaVigente(String(unidad ?? ''), String(fecha ?? ''));
    },
);

// (b) el fin NUNCA queda antes que el inicio: si el inicio lo adelanta, el fin
// se arrastra y conserva la duración que tenía.
watch(() => form.value.fechaInicio, (nuevo, anterior) => {
    if (!nuevo || !form.value.fechaFin) return;

    if (form.value.fechaFin <= nuevo) {
        // Conserva las noches que tenía; mínimo una, porque cero no cubre nada.
        const span = anterior ? Math.max(1, nochesDeRango(anterior, form.value.fechaFin)) : 1;
        form.value.fechaFin = sumarDias(nuevo, span);
    }
});

// ============================================================================
// GUARDAR
// ============================================================================
function payload(): PmsTarifaRangoCreate {
    return {
        unidad: pmsUnidadIri(form.value.unidad),
        moneda: maestroMonedaIri(form.value.moneda),
        fechaInicio: form.value.fechaInicio,
        fechaFin: form.value.fechaFin,
        precio: form.value.precio,
        minStay: form.value.minStay,
        importante: form.value.importante,
        prioridad: form.value.prioridad,
        activo: form.value.activo,
    };
}

async function guardar(): Promise<void> {
    localError.value = null;

    if (!form.value.unidad) {
        localError.value = 'Selecciona una unidad.';
        return;
    }
    if (!form.value.fechaInicio || !form.value.fechaFin) {
        localError.value = 'Las fechas de inicio y fin son obligatorias.';
        return;
    }
    if (rangoInvertido.value) {
        localError.value = 'La fecha de fin debe ser posterior a la de inicio: marca la salida, así que un rango con fin = inicio no cubre ninguna noche.';
        return;
    }

    try {
        if (props.tarifaId) {
            await tarifasStore.patchTarifa(props.tarifaId, payload() as PmsTarifaRangoPatch);
        } else {
            await tarifasStore.createTarifa(payload());
        }
        emit('saved');
    } catch (err) {
        localError.value = extractApiErrorMessage(err, 'No se pudo guardar la tarifa.');
    }
}

// ============================================================================
// ELIMINAR (confirmación en dos pasos, sin modal anidado)
// ============================================================================
const confirmandoBorrado = ref(false);

async function eliminar(): Promise<void> {
    if (!props.tarifaId) return;
    localError.value = null;
    try {
        await tarifasStore.deleteTarifa(props.tarifaId);
        emit('deleted');
    } catch (err) {
        confirmandoBorrado.value = false;
        localError.value = extractApiErrorMessage(err, 'No se pudo eliminar la tarifa.');
    }
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/40" @click="emit('close')"></div>

        <!-- Panel -->
        <div class="relative w-full max-w-lg h-full bg-white shadow-2xl flex flex-col animate-slide-in">
            <header class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
                <div>
                    <h2 class="font-black text-base tracking-tight">
                        <template v-if="isCreate">Nueva Tarifa</template>
                        <template v-else-if="readOnly">Ver Tarifa</template>
                        <template v-else>Editar Tarifa</template>
                    </h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                        PMS · Rango de precios
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="readOnly" @click="habilitarEdicion"
                        class="px-3 h-8 flex items-center gap-1.5 bg-[#376875] hover:bg-[#2d5660] rounded-full transition-colors text-xs font-black">
                        <i class="fas fa-pen text-[11px]"></i> Editar
                    </button>
                    <button @click="emit('close')" class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
            </header>

            <div v-if="isLoadingDrawer" class="flex-1 flex items-center justify-center">
                <i class="fas fa-spinner fa-spin text-3xl text-[#376875]"></i>
            </div>

            <div v-else class="flex-1 overflow-y-auto px-5 py-4 space-y-5">

                <div v-if="localError" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold rounded-xl px-4 py-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>{{ localError }}
                </div>

                <!-- Estado de sincronización con Beds24 -->
                <div v-if="syncMeta" class="flex items-center gap-2">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Beds24</span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-black" :class="syncMeta.clase">
                        <i class="fas" :class="syncMeta.icono"></i> {{ syncMeta.texto }}
                    </span>
                </div>

                <!-- ===== VISTA (modo "Ver") ===== -->
                <div v-if="readOnly" class="rounded-xl border border-slate-200 divide-y divide-slate-100 overflow-hidden">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Unidad</p>
                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ nombreUnidad }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Estado</p>
                            <span class="inline-block mt-1 px-2.5 py-1 rounded-full text-[11px] font-black"
                                :class="form.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                                {{ form.activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Inicio</p>
                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ fechaLegible(form.fechaInicio) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Fin (salida)</p>
                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ fechaLegible(form.fechaFin) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Precio</p>
                            <p class="text-sm font-black text-slate-800 mt-0.5">{{ simboloMoneda }} {{ form.precio }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Estancia mínima</p>
                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ form.minStay }} noches</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Prioridad</p>
                            <p class="text-sm font-bold text-slate-800 mt-0.5">
                                {{ form.prioridad }}
                                <span v-if="form.importante" class="ml-1 text-amber-600"><i class="fas fa-star text-[10px]"></i> Prioritaria</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Duración</p>
                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ noches }} noche(s)</p>
                        </div>
                    </div>
                    <div class="px-4 py-3 bg-slate-50">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Netos de canal</p>
                        <p class="text-sm font-bold text-slate-700 mt-0.5">
                            {{ netoBooking }} <span class="text-slate-400 font-medium">al 20%</span>
                            · {{ netoAirbnb }} <span class="text-slate-400 font-medium">al 30%</span>
                        </p>
                    </div>
                </div>

                <!-- ===== FORM (crear / editar) ===== -->
                <div v-else class="grid grid-cols-2 gap-3">
                    <label class="col-span-2">
                        <span class="text-xs font-bold text-slate-500">Unidad</span>
                        <select v-model="form.unidad"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="u in tarifasStore.unidades" :key="u.id ?? ''" :value="u.id ?? ''">
                                {{ u.nombre }}{{ !u.activo ? ' (inactiva)' : '' }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Inicio</span>
                        <input type="date" v-model="form.fechaInicio"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Fin (salida)</span>
                        <!-- `min` = inicio + 1: `fechaFin` es EXCLUSIVA, así que el
                             mismo día no cubre ninguna noche y el motor descartaría el
                             rango entero. El aviso en rojo se queda para lo tecleado a
                             mano. -->
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
                            {{ noches }} noche(s): la última es la del {{ fechaLegible(sumarDias(form.fechaFin, -1)) }}.
                            El {{ fechaLegible(form.fechaFin) }} es salida y no lleva esta tarifa.
                        </template>
                    </p>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Precio</span>
                        <!-- En cuanto el operador teclea, la precarga deja de
                             pisar el precio (ver precargarTarifaVigente). -->
                        <input type="text" inputmode="decimal" v-model="form.precio"
                            @input="precioTocado = true"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Moneda</span>
                        <select v-model="form.moneda"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                            <option v-for="m in tarifasStore.monedas" :key="m.id ?? ''" :value="m.id ?? ''">
                                {{ m.id }} — {{ m.simbolo }}
                            </option>
                        </select>
                    </label>

                    <!-- Netos informativos: el calendario los muestra en el título del evento -->
                    <div class="col-span-2 -mt-1 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-[11px] font-bold text-slate-500">
                        <i class="fas fa-percent mr-1 text-slate-400"></i>
                        Neto al 20%: <span class="text-slate-700">{{ netoBooking }}</span>
                        · Neto al 30%: <span class="text-slate-700">{{ netoAirbnb }}</span>
                    </div>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Estancia mín. (noches)</span>
                        <input type="number" min="0" v-model.number="form.minStay"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                    </label>

                    <label>
                        <span class="text-xs font-bold text-slate-500">Prioridad</span>
                        <input type="number" v-model.number="form.prioridad"
                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        <span class="text-[10px] font-bold text-slate-400">Mayor prioridad gana sobre rangos solapados.</span>
                    </label>

                    <label class="col-span-2 flex items-center gap-2">
                        <input type="checkbox" v-model="form.importante" class="rounded" />
                        <span class="text-xs font-bold text-slate-500">Tarifa prioritaria (se dibuja sobre las demás)</span>
                    </label>

                    <label class="col-span-2 flex items-center gap-2">
                        <input type="checkbox" v-model="form.activo" class="rounded" />
                        <span class="text-xs font-bold text-slate-500">Activa</span>
                    </label>
                </div>
            </div>

            <footer class="border-t border-slate-200 px-5 py-4 flex items-center gap-3 shrink-0"
                :class="readOnly ? 'justify-end' : 'justify-between'">
                <!-- Eliminar: confirmación en dos pasos, nunca visible en modo "Ver" -->
                <div v-if="!readOnly && !isCreate">
                    <button v-if="!confirmandoBorrado" @click="confirmandoBorrado = true"
                        class="px-3 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 rounded-xl transition-colors">
                        <i class="fas fa-trash mr-1"></i> Eliminar
                    </button>
                    <div v-else class="flex items-center gap-2">
                        <span class="text-xs font-bold text-slate-500">¿Eliminar?</span>
                        <button @click="eliminar" :disabled="tarifasStore.isSaving"
                            class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white rounded-lg text-xs font-black transition-colors">
                            Sí, eliminar
                        </button>
                        <button @click="confirmandoBorrado = false"
                            class="px-2 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-700">
                            No
                        </button>
                    </div>
                </div>

                <button v-if="readOnly" @click="emit('close')"
                    class="px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-black transition-colors">
                    Cerrar
                </button>
                <div v-else class="flex items-center gap-3">
                    <button @click="emit('close')" class="px-4 py-2 text-sm font-bold text-slate-500 hover:text-slate-700">
                        Cancelar
                    </button>
                    <button @click="guardar" :disabled="tarifasStore.isSaving || isLoadingDrawer"
                        class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-xl text-sm font-black shadow-sm transition-colors">
                        <i class="fas" :class="tarifasStore.isSaving ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>
                        {{ isCreate ? 'Crear Tarifa' : 'Guardar Cambios' }}
                    </button>
                </div>
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
