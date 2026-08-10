<script setup lang="ts">
// ============================================================================
// Sección "Cobrar con tarjeta" del panel financiero: emite enlaces de pago.
//
// Componente APARTE y no un bloque más dentro de ReservaFinanzasPanel.vue por dos
// razones: el panel ya pasa de 1400 líneas, y sobre todo porque esto no es del PMS.
// Habla con el módulo Finanzas por `origenTipo` + `origenId`, así que el día que
// existan las reservas de tours se monta igual cambiando el prop — sin tocar nada
// de aquí. Ver docs/FinanzasEnlacesPago.md.
//
// El importe que se teclea es el NETO (lo que abona la reserva). El recargo de la
// pasarela lo calcula y lo suma el backend, que es quien tiene el porcentaje.
// ============================================================================
import { computed, ref, watch } from 'vue';
import { useEnlacesPagoStore } from '@/stores/finanzas/enlacesPagoStore';
import {
    clasesEstadoEnlace,
    type FinEnlacePago,
    type FinOrigenCobro,
    type FinPasarela,
} from '@/types/finEnlacePagoModel';

const props = defineProps<{
    origenTipo: FinOrigenCobro;
    origenId: string;
    /** Saldo pendiente de la cabecera, para prellenar el importe. */
    saldo: number;
    /**
     * Prepago pendiente de la cabecera, o `null` si ya no procede pedirlo.
     *
     * Lo manda `PmsInformacionFinancieraPorReservaProvider`. Es `null` en cuanto hay un pago
     * registrado (ese pago ERA el adelanto), y de eso depende qué atajos se ofrecen.
     */
    prepago?: {
        monto: string;
        politica?: string;
        politicaEtiqueta?: string;
        politicaCorta?: string;
        concepto?: string;
    } | null;
    monedaSimbolo?: string | null;
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    /** Un enlace cambió de estado: el panel recarga por si generó un pago. */
    (e: 'actualizado'): void;
}>();

const store = useEnlacesPagoStore();

const formAbierto = ref(false);
const error = ref<string | null>(null);
/** Id del enlace recién copiado, para el "¡copiado!" efímero. */
const copiadoId = ref<string | null>(null);

/** Enlace cuya respuesta cruda está abierta, y su contenido ya formateado. */
const auditandoId = ref<string | null>(null);
const auditoria = ref<string | null>(null);
const cargandoAuditoria = ref(false);

/**
 * Abre (o cierra) la respuesta cruda de la pasarela.
 *
 * Se pide al abrir y no con el listado: son varios KB por enlace y se consultan una vez al
 * año. Se pinta con `JSON.stringify(..., 2)` **sin interpretar ningún campo**: cada pasarela
 * responde lo que quiere, y quien audita quiere ver lo que dijo la pasarela, no nuestra
 * lectura de lo que dijo.
 */
async function alternarAuditoria(enlace: FinEnlacePago): Promise<void> {
    if (auditandoId.value === enlace.id) {
        auditandoId.value = null;
        auditoria.value = null;
        return;
    }

    auditandoId.value = enlace.id;
    auditoria.value = null;
    cargandoAuditoria.value = true;

    try {
        const data = await store.fetchRespuesta(enlace.id);
        auditoria.value = data.respuesta === null || data.respuesta === undefined
            ? 'La pasarela no dejó respuesta para este enlace.'
            : JSON.stringify(data.respuesta, null, 2);
    } catch {
        auditoria.value = 'No se pudo cargar la respuesta de la pasarela.';
    } finally {
        cargandoAuditoria.value = false;
    }
}

const form = ref({
    monto: '',
    conRecargo: true,
    vigenciaDias: 7,
    /** Vacío = la que decida el backend. Solo se elige si hay más de una configurada. */
    pasarela: '' as FinPasarela | '',
    /**
     * Vacío = el backend describe la reserva («Casita 1, 09/08 al 18/08»).
     *
     * Se puede pisar porque no todo lo que se cobra es la estancia: un tour, un traslado,
     * una lavandería, una garantía. Es el texto que ve el cliente en la página de pago y en
     * el correo de su banco, así que si dice "estancia" cuando pagó un tour, el cobro parece
     * un error y acaba en una reclamación.
     */
    concepto: '',
});

/** Con una sola pasarela no hay nada que elegir: el selector estorbaría. */
const eligePasarela = computed(() => store.pasarelas.length > 1);

const hayPendiente = computed(() => store.enlaces.some((e) => e.vigente));

/**
 * El botón está SIEMPRE disponible (salvo en solo-lectura), aunque el saldo sea cero.
 *
 * Antes se ocultaba sin saldo, y era un error: el saldo de ahora no es el de dentro de un
 * rato. Se añade un cargo por consumos, una noche extra o una penalización, y en ese momento
 * hay que poder cobrar. Ocultar el botón obligaba a registrar primero el cargo sólo para que
 * reapareciera — el sistema decidiendo por el operador.
 *
 * Si no hay saldo, el importe simplemente nace vacío y lo teclea él.
 */
const puedeCobrar = computed(() => !props.readOnly);

/** Sin saldo pendiente el importe no se prellena: no hay nada que proponer. */
const haySaldo = computed(() => props.saldo > 0.005);

/**
 * Recarga al cambiar de documento. `immediate` porque la sección se monta con el
 * drawer ya abierto sobre una reserva concreta.
 */
watch(
    () => props.origenId,
    (id) => {
        store.clear();
        if (id) void store.fetchPorOrigen(props.origenTipo, id);
    },
    { immediate: true },
);

// ============================================================================
// ATAJOS DE IMPORTE
//
// Los dos cobros que se piden de verdad, sin teclear ni calcular de cabeza:
//
//   · Con adelanto pendiente → «Primera noche» y «Total».
//     Los dos son legítimos y conviven: la política pide el adelanto, pero hay
//     huéspedes que prefieren dejarlo pagado entero de una vez.
//   · Ya cobrado el adelanto → sólo «Saldo». Ofrecer «primera noche» ahí sería
//     pedir por segunda vez algo que el huésped ya hizo; el backend además lo
//     impide (`prepago` llega null en cuanto hay un pago).
//
// El atajo NO emite: prellena el formulario y lo abre. La vigencia, el recargo y
// el concepto siguen a la vista y el operador confirma con «Generar enlace» — es
// un cobro, y ahorrar el tecleo no es razón para quitar la última mirada.
//
// ⚠️ La etiqueta de la política viene del backend (`PmsPoliticaPrepago`), no de un
// Record de aquí: el establecimiento puede pasar a `mitad_total` y el botón tiene
// que dejar de decir «Primera noche» solo.
// ============================================================================

interface PresetImporte {
    clave: string;
    etiqueta: string;
    /** El matiz largo de la política, al `title`: en el botón estorba. */
    detalle?: string;
    monto: string;
    /** Lo que verá el huésped en la página de pago. Vacío = lo describe el backend. */
    concepto: string;
    destacado: boolean;
}

const presets = computed<PresetImporte[]>(() => {
    const lista: PresetImporte[] = [];
    const prepago = props.prepago;

    if (prepago && Number(prepago.monto) > 0.005) {
        lista.push({
            clave: 'prepago',
            etiqueta: prepago.politicaCorta || 'Adelanto',
            detalle: prepago.politicaEtiqueta,
            monto: Number(prepago.monto).toFixed(2),
            concepto: prepago.concepto ?? '',
            destacado: true,
        });
    }

    if (haySaldo.value) {
        lista.push({
            // Sin adelanto pendiente lo que queda ES el saldo, y llamarlo «total» mentiría:
            // el total de la reserva incluye lo ya cobrado.
            clave: 'saldo',
            etiqueta: prepago ? 'Total' : 'Saldo',
            monto: props.saldo.toFixed(2),
            concepto: '',
            destacado: !prepago,
        });
    }

    return lista;
});

function abrirForm(preset?: PresetImporte): void {
    error.value = null;
    // Sin atajo se prellena con el saldo COMPLETO: cobrar todo lo pendiente es el caso normal.
    form.value = {
        monto: preset ? preset.monto : (haySaldo.value ? props.saldo.toFixed(2) : ''),
        conRecargo: true,
        vigenciaDias: 7,
        pasarela: '',
        concepto: preset?.concepto ?? '',
    };
    formAbierto.value = true;
    // Al abrir y no al montar: la mayoría de veces el panel se despliega para mirar, no
    // para cobrar, y esto es una petición que casi siempre sobraría.
    void store.fetchPasarelas();
}

async function crear(): Promise<void> {
    error.value = null;

    try {
        await store.crear({
            origenTipo: props.origenTipo,
            origenId: props.origenId,
            monto: form.value.monto || undefined,
            conRecargo: form.value.conRecargo,
            vigenciaDias: form.value.vigenciaDias,
            pasarela: form.value.pasarela || undefined,
            concepto: form.value.concepto.trim() || undefined,
        });
        formAbierto.value = false;
        emit('actualizado');
    } catch (err) {
        error.value = mensajeDeError(err);
    }
}

async function anular(enlace: FinEnlacePago): Promise<void> {
    if (!window.confirm('¿Anular este enlace? El cliente ya no podrá pagar con él.')) return;

    error.value = null;
    try {
        await store.anular(enlace.id);
        emit('actualizado');
    } catch (err) {
        error.value = mensajeDeError(err);
    }
}

async function copiar(enlace: FinEnlacePago): Promise<void> {
    try {
        await navigator.clipboard.writeText(enlace.url);
        copiadoId.value = enlace.id;
        window.setTimeout(() => { copiadoId.value = null; }, 1800);
    } catch {
        // Sin permiso de portapapeles (http, o el navegador lo bloquea): que al menos
        // se pueda seleccionar a mano. El input de solo lectura de abajo sirve para eso.
        error.value = 'No se pudo copiar automáticamente. Selecciona la URL y cópiala.';
    }
}

/** El backend responde `{error: "..."}` en texto plano, no en hydra. */
function mensajeDeError(err: unknown): string {
    const data = (err as { response?: { data?: { error?: string } } })?.response?.data;
    return data?.error || 'No se pudo completar la operación.';
}

function fechaCorta(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: '2-digit' });
}
</script>

<template>
    <div class="border-t border-slate-100">
        <div class="px-4 py-3">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">
                    <i class="fas fa-link mr-1"></i> Enlaces de pago
                </p>
                <button v-if="puedeCobrar && !formAbierto" type="button" @click="abrirForm()"
                    class="px-3 py-1.5 bg-[#376875] hover:bg-[#2d5660] text-white rounded-lg text-[11px] font-black">
                    <i class="fas fa-credit-card mr-1"></i> Cobrar con tarjeta
                </button>
            </div>

            <!-- ===== ATAJOS DE IMPORTE =====
                 Prellenan el formulario, no emiten: el operador sigue viendo vigencia,
                 recargo y concepto antes de confirmar. Ver el bloque del script. -->
            <div v-if="puedeCobrar && !formAbierto && presets.length" class="mt-2 flex flex-wrap gap-2">
                <button v-for="preset in presets" :key="preset.clave" type="button"
                    :title="preset.detalle"
                    @click="abrirForm(preset)"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[11px] font-black transition-colors"
                    :class="preset.destacado
                        ? 'border-[#376875]/30 bg-[#376875]/8 text-[#376875] hover:bg-[#376875]/15'
                        : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700'">
                    <i class="fas fa-bolt text-[9px]"></i>
                    {{ preset.etiqueta }}
                    <span class="font-black tabular-nums">{{ props.monedaSimbolo }} {{ preset.monto }}</span>
                </button>
            </div>

            <p v-if="!props.readOnly && !haySaldo && !store.enlaces.length"
                class="mt-2 text-[11px] text-slate-400">
                Sin saldo pendiente. Puedes emitir un enlace igualmente y teclear el importe.
            </p>

            <p v-if="error" class="mt-2 text-[11px] font-bold text-rose-600">{{ error }}</p>

            <!-- ===== FORMULARIO DE EMISIÓN ===== -->
            <div v-if="formAbierto" class="mt-3 p-3 rounded-lg bg-[#376875]/5 border border-[#376875]/20 space-y-3">
                <p v-if="hayPendiente" class="text-[11px] font-bold text-amber-700">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    Ya hay un enlace vigente. Si emites otro, los dos podrán cobrarse.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-[10px] font-black text-slate-500 uppercase">Importe ({{ props.monedaSimbolo }})</span>
                        <input v-model="form.monto" type="number" step="0.01" min="0.01"
                            class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                    </label>

                    <label class="block">
                        <span class="text-[10px] font-black text-slate-500 uppercase">Vigencia (días)</span>
                        <input v-model.number="form.vigenciaDias" type="number" min="0" step="1"
                            class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                        <span class="text-[10px] text-slate-400">0 = sin caducidad</span>
                    </label>
                </div>

                <!-- Concepto libre: no todo lo que se cobra es la estancia. Es lo que ve el
                     cliente en la página de pago y en el cargo de su banco. -->
                <label class="block">
                    <span class="text-[10px] font-black text-slate-500 uppercase">Concepto</span>
                    <input v-model="form.concepto" type="text" maxlength="200"
                        placeholder="Por defecto: la estancia (casita y fechas)"
                        class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                    <span class="text-[10px] text-slate-400">
                        Cámbialo si cobras otra cosa: un tour, un traslado, una garantía.
                    </span>
                </label>

                <!-- Solo con más de una pasarela configurada. Ver §11 del doc: conviven, no
                     se sustituyen, así que el operador puede necesitar forzar una. -->
                <label v-if="eligePasarela" class="block">
                    <span class="text-[10px] font-black text-slate-500 uppercase">Pasarela</span>
                    <select v-model="form.pasarela"
                        class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white">
                        <option value="">Automática</option>
                        <option v-for="p in store.pasarelas" :key="p.value" :value="p.value">
                            {{ p.label }}{{ p.porDefecto ? ' (por defecto)' : '' }}
                        </option>
                    </select>
                </label>

                <label class="flex items-start gap-2 cursor-pointer">
                    <input v-model="form.conRecargo" type="checkbox" class="mt-0.5" />
                    <span class="text-[11px] text-slate-600 leading-snug">
                        <b>Trasladar la comisión al cliente.</b>
                        El enlace cobra el importe más el recargo de tarjeta; a la reserva se le
                        abona solo el importe.
                    </span>
                </label>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="formAbierto = false"
                        class="px-3 py-1.5 text-[11px] font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                    <button type="button" @click="crear" :disabled="store.isSaving || !form.monto"
                        class="px-3 py-1.5 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-[11px] font-black">
                        <i class="fas" :class="store.isSaving ? 'fa-circle-notch fa-spin' : 'fa-link'"></i>
                        Generar enlace
                    </button>
                </div>
            </div>

            <!-- ===== LISTA ===== -->
            <ul v-if="store.enlaces.length" class="mt-3 space-y-2">
                <li v-for="enlace in store.enlaces" :key="enlace.id"
                    class="p-2.5 rounded-lg border" :class="clasesEstadoEnlace(enlace.estado)">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] font-black uppercase tracking-wide">
                            {{ enlace.estadoEtiqueta }}
                            <!-- Por dónde entró: con dos conectores en paralelo es la primera
                                 pregunta al conciliar contra el extracto de la pasarela. -->
                            <span class="ml-1 font-bold opacity-60">· {{ enlace.pasarelaEtiqueta }}</span>
                        </span>
                        <span class="text-sm font-black">
                            {{ enlace.monedaSimbolo }} {{ enlace.montoTotal }}
                        </span>
                    </div>

                    <p class="mt-0.5 text-[10px] opacity-80">
                        Abona {{ enlace.monedaSimbolo }} {{ enlace.montoNeto }}
                        <template v-if="Number(enlace.montoRecargo) > 0.005">
                            · comisión {{ enlace.monedaSimbolo }} {{ enlace.montoRecargo }}
                            ({{ enlace.recargoPorcentaje }}%)
                        </template>
                    </p>

                    <!-- COBRADO: los datos que se piden cuando el cliente reclama —fecha,
                         tarjeta, código de autorización y referencia en la pasarela— a la
                         vista, sin tener que abrir la respuesta cruda. -->
                    <div v-if="enlace.estado === 'pagado'" class="mt-1">
                        <p class="text-[10px] font-black">
                            <i class="fas fa-circle-check mr-1"></i>
                            Cobrado el {{ fechaCorta(enlace.pagadoEn) }}
                        </p>
                        <dl class="mt-1 grid grid-cols-[auto,1fr] gap-x-2 text-[10px] opacity-80">
                            <template v-if="enlace.medioDetalle">
                                <dt class="font-bold">Tarjeta</dt><dd>{{ enlace.medioDetalle }}</dd>
                            </template>
                            <template v-if="enlace.autorizacionCodigo">
                                <dt class="font-bold">Autorización</dt><dd>{{ enlace.autorizacionCodigo }}</dd>
                            </template>
                            <template v-if="enlace.ordenId">
                                <dt class="font-bold">Orden</dt><dd class="font-mono">{{ enlace.ordenId }}</dd>
                            </template>
                        </dl>
                    </div>
                    <p v-else-if="enlace.expiraEn" class="mt-0.5 text-[10px] opacity-80">
                        Caduca el {{ fechaCorta(enlace.expiraEn) }}
                    </p>

                    <!-- La URL en un input de solo lectura, no en un <p>: si el portapapeles
                         está bloqueado, esto es lo que permite seleccionarla a mano. -->
                    <div v-if="enlace.vigente" class="mt-2 flex items-center gap-1.5">
                        <input :value="enlace.url" readonly
                            class="flex-1 min-w-0 px-2 py-1 bg-white/70 border border-current/20 rounded text-[10px] font-mono truncate" />
                        <button type="button" @click="copiar(enlace)"
                            class="shrink-0 px-2 py-1 bg-white/80 hover:bg-white border border-current/20 rounded text-[10px] font-black">
                            <i class="fas" :class="copiadoId === enlace.id ? 'fa-check' : 'fa-copy'"></i>
                            {{ copiadoId === enlace.id ? 'Copiado' : 'Copiar' }}
                        </button>
                        <button v-if="!props.readOnly" type="button" @click="anular(enlace)"
                            :disabled="store.isSaving"
                            class="shrink-0 px-2 py-1 hover:bg-white/60 rounded text-[10px] font-black opacity-70 hover:opacity-100">
                            <i class="fas fa-ban"></i>
                        </button>
                    </div>

                    <!-- ===== AUDITORÍA: la respuesta tal cual la mandó la pasarela =====
                         Sin interpretar ni mapear. Cuando una transacción se discute meses
                         después, los campos que mapeamos nunca son los que hacen falta. -->
                    <button v-if="enlace.estado === 'pagado' || enlace.estado === 'fallido'"
                        type="button" @click="alternarAuditoria(enlace)"
                        class="mt-2 text-[10px] font-black underline decoration-dotted opacity-70 hover:opacity-100">
                        <i class="fas" :class="auditandoId === enlace.id ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        Respuesta de {{ enlace.pasarelaEtiqueta }}
                    </button>

                    <div v-if="auditandoId === enlace.id" class="mt-1.5">
                        <p v-if="cargandoAuditoria" class="text-[10px] opacity-70">Cargando…</p>
                        <pre v-else
                            class="max-h-64 overflow-auto p-2 rounded bg-white/70 border border-current/20
                                   text-[9px] leading-relaxed font-mono whitespace-pre-wrap break-all"
                        >{{ auditoria }}</pre>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
