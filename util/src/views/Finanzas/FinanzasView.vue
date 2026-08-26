<script setup lang="ts">
/**
 * Módulo Finanzas — vista transversal del dinero.
 *
 * Dos pestañas que responden a dos preguntas distintas, y por eso no se fusionan:
 *
 *   COBROS  ¿qué enlaces de pago he emitido y en qué han quedado? Incluye los que nadie
 *           pagó — que son justamente los que hay que perseguir. Importe = lo que se
 *           cobra a la tarjeta, con recargo.
 *   CAJA    ¿cuánto dinero ha entrado y por dónde? Efectivo, Yape, transferencias y
 *           tarjeta juntos. Importe = NETO: el recargo se lo queda la pasarela y nunca
 *           llega a nuestra cuenta.
 *
 * Un cobro emitido y sin pagar sale en la primera y no en la segunda; un pago en efectivo,
 * al revés. Sumar las dos cifras no tiene sentido y por eso nunca se pintan juntas.
 *
 * Es transversal de verdad: la pestaña de caja se alimenta de
 * `FinMovimientoRegistry`, así que el día que exista el módulo de tours sus pagos
 * aparecen aquí sin tocar esta vista. Ver docs/FinanzasEnlacesPago.md.
 */
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import { useCajaStore } from '@/stores/finanzas/cajaStore';
import {
    clasesEstadoEnlace,
    type FinCobroOrigen,
    type FinEnlacePago,
    type FinOrigenCobro,
} from '@/types/finEnlacePagoModel';
import type { FinCajaFiltros, FinMovimiento } from '@/types/finMovimientoModel';
import { useRefrescoDelAsistente } from '@/composables/useRefrescoDelAsistente';

const router = useRouter();
const store = useCajaStore();

const activeTab = ref<'cobros' | 'caja'>('cobros');

/** `YYYY-MM-DD` de hoy desplazado N días. Local, no UTC: `toISOString` restaba un día. */
function fechaISO(diasAtras = 0): string {
    const d = new Date();
    d.setDate(d.getDate() - diasAtras);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Arranca en los últimos 30 días y no "todo".
 *
 * Sin rango, la primera carga barre la tabla entera y choca contra el tope de filas del
 * backend, que además avisa de truncado nada más entrar. Un mes es lo que se mira a
 * diario, y ampliarlo es cambiar una fecha.
 */
const filtros = ref<FinCajaFiltros>({
    desde: fechaISO(30),
    hasta: fechaISO(),
    estado: '',
    medio: '',
    q: '',
});

const truncado = computed(() => (activeTab.value === 'cobros' ? store.cobrosTruncado : store.cajaTruncado));

// ============================================================================
// COBRO MANUAL
//
// Un cobro que no cuelga de ningún documento: una venta suelta, una garantía, algo que
// todavía no tiene su módulo. El `modulo` es SOLO una etiqueta para poder filtrarlo
// después — no vincula con ninguna reserva ni cotización, y por eso se admite Cotizaciones
// aunque ese módulo aún no sepa cobrar.
// ============================================================================
const formAbierto = ref(false);
const guardando = ref(false);
const errorForm = ref<string | null>(null);
/** Enlace recién emitido: se muestra su URL para copiarla sin buscarla en la tabla. */
const recienCreado = ref<FinEnlacePago | null>(null);

const formManual = ref({
    monto: '',
    moneda: 'USD',
    concepto: '',
    modulo: '' as FinOrigenCobro | '',
    conRecargo: true,
    vigenciaDias: 7,
    clienteNombre: '',
    clienteEmail: '',
    referencia: '',
});

function abrirFormManual(): void {
    errorForm.value = null;
    recienCreado.value = null;
    formManual.value = {
        monto: '', moneda: 'USD', concepto: '', modulo: '',
        conRecargo: true, vigenciaDias: 7, clienteNombre: '', clienteEmail: '', referencia: '',
    };
    formAbierto.value = true;
}

async function guardarManual(): Promise<void> {
    errorForm.value = null;
    guardando.value = true;
    try {
        recienCreado.value = await store.crearManual({
            monto: formManual.value.monto,
            moneda: formManual.value.moneda,
            concepto: formManual.value.concepto,
            modulo: formManual.value.modulo || undefined,
            conRecargo: formManual.value.conRecargo,
            vigenciaDias: formManual.value.vigenciaDias,
            clienteNombre: formManual.value.clienteNombre || undefined,
            clienteEmail: formManual.value.clienteEmail || undefined,
            referencia: formManual.value.referencia || undefined,
        });
    } catch (err) {
        const data = (err as { response?: { data?: { error?: string } } })?.response?.data;
        errorForm.value = data?.error || 'No se pudo emitir el cobro.';
    } finally {
        guardando.value = false;
    }
}

const cargar = async (): Promise<void> => {
    if (activeTab.value === 'cobros') {
        await store.fetchCobros(filtros.value);
    } else {
        await store.fetchMovimientos(filtros.value);
    }
};

// `registrar_pago` y `registrar_cargo` escriben justo lo que esta pantalla enseña: sin
// refresco, el saldo de arriba contradice al movimiento que el asistente acaba de apuntar.
useRefrescoDelAsistente(() => { void cargar(); });

const cambiarTab = async (tab: 'cobros' | 'caja'): Promise<void> => {
    activeTab.value = tab;
    await cargar();
};

const limpiarFiltros = async (): Promise<void> => {
    filtros.value = { desde: fechaISO(30), hasta: fechaISO(), estado: '', medio: '', q: '' };
    await cargar();
};

/**
 * Salta a la reserva del cobro.
 *
 * Sólo para `pms_reserva`: cuando existan los tours, cada origen tendrá su ruta y esto
 * será un `match`. Se deja explícito en vez de construir la URL a ciegas para que un
 * origen nuevo no mande al operador a una pantalla en blanco.
 */
const irAlOrigen = (origenTipo: string | null, origenId: string | null): void => {
    // Un cobro manual no tiene documento al que ir, aunque lleve etiqueta de módulo.
    if (origenTipo !== 'pms_reserva' || !origenId) return;
    void router.push({ path: '/reservas', query: { reserva: origenId } });
};

const fechaCorta = (iso: string | null): string => {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: '2-digit' });
};

const copiar = async (enlace: FinEnlacePago): Promise<void> => {
    try {
        await navigator.clipboard.writeText(enlace.url);
    } catch {
        // Portapapeles bloqueado (http o permiso denegado): no es un error que merezca
        // interrumpir; el operador puede abrir la ficha y copiar desde ahí.
    }
};

/** Marca visual del medio. Los códigos los define PmsMedioPago; el resto cae en gris. */
const iconoMedio = (movimiento: FinMovimiento): string => {
    switch (movimiento.medioCodigo) {
        case 'efectivo': return 'fa-money-bill-wave';
        case 'plin_yape': return 'fa-mobile-screen-button';
        case 'tarjeta_credito': return 'fa-credit-card';
        case 'western_union': return 'fa-building-columns';
        case 'transferencia_bancaria': return 'fa-right-left';
        case 'paypal': return 'fa-paypal';
        default: return 'fa-circle-dollar-to-slot';
    }
};

onMounted(cargar);

// ============================================================================
// FICHA DE UN COBRO
//
// La tabla contesta «qué pasó con mis enlaces»; para «qué es EXACTAMENTE este cobro» no
// había nada. En un cobro MANUAL, además, lo que se tecleó al crearlo —email, teléfono,
// referencia, notas— se guardaba y no se volvía a ver nunca.
//
// Va en panel lateral y no en fila desplegable porque la tabla ya no cabe en un móvil: en
// pantalla estrecha se cortan Concepto, Documento e Importe, que es justo lo que se busca.
// ============================================================================
const fichaAbierta = ref(false);
const fichaCargando = ref(false);
const fichaCobro = ref<FinEnlacePago | null>(null);
const fichaOrigen = ref<FinCobroOrigen | null>(null);
const fichaError = ref<string | null>(null);

/**
 * Descarta la respuesta de una ficha que ya no está en pantalla: pulsando dos filas
 * seguidas, la lenta de la primera pintaría sus datos bajo el título de la segunda.
 */
let peticionFicha = 0;

async function abrirFicha(cobro: FinEnlacePago): Promise<void> {
    const mia = ++peticionFicha;

    // Se pinta YA lo que la fila ya tenía; el viaje sólo añade el origen y los datos del
    // cliente. Así la ficha nunca aparece vacía.
    fichaCobro.value = cobro;
    fichaOrigen.value = null;
    fichaError.value = null;
    fichaAbierta.value = true;
    fichaCargando.value = true;

    try {
        const detalle = await store.fetchCobroDetalle(cobro.id);
        if (mia !== peticionFicha) return;

        fichaCobro.value = detalle.cobro;
        fichaOrigen.value = detalle.origen;
    } catch {
        if (mia !== peticionFicha) return;
        // El cobro que ya se pintó sigue siendo válido: lo que falta es el origen.
        fichaError.value = 'No se pudo cargar el documento de origen.';
    } finally {
        if (mia === peticionFicha) fichaCargando.value = false;
    }
}

function cerrarFicha(): void {
    fichaAbierta.value = false;
    peticionFicha++;
    fichaCobro.value = null;
    fichaOrigen.value = null;
    fichaError.value = null;
}

/** Fecha con hora: en una ficha sí importa a qué hora se emitió o se pagó. */
function fechaLarga(iso?: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleString('es-PE', {
        day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit',
    });
}

</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">

        <!-- ================= HEADER ================= -->
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 md:gap-3 z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <AppSwitcher />
                <div class="min-w-0">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">Finanzas</h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1 truncate">
                        Cobros · Caja
                    </p>
                </div>
            </div>

            <div class="flex items-center bg-slate-800 rounded-lg p-1 gap-1 shrink-0 self-start md:self-auto">
                <button @click="cambiarTab('cobros')"
                    :class="activeTab === 'cobros' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                    class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap">
                    <i class="fas fa-link mr-1"></i> Cobros
                </button>
                <button @click="cambiarTab('caja')"
                    :class="activeTab === 'caja' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-white'"
                    class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap">
                    <i class="fas fa-cash-register mr-1"></i> Caja
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto">

            <!-- ================= FILTROS ================= -->
            <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-4 md:px-6 py-3">
                <div class="flex flex-wrap items-end gap-2">
                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Desde</span>
                        <input v-model="filtros.desde" type="date" @change="cargar"
                            class="px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white" />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Hasta</span>
                        <input v-model="filtros.hasta" type="date" @change="cargar"
                            class="px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white" />
                    </label>

                    <label v-if="activeTab === 'cobros'" class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Estado</span>
                        <select v-model="filtros.estado" @change="cargar"
                            class="px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white">
                            <option value="">Todos</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="pagado">Pagado</option>
                            <option value="fallido">Fallido</option>
                            <option value="expirado">Expirado</option>
                            <option value="anulado">Anulado</option>
                        </select>
                    </label>

                    <!-- El catálogo lo sirven los módulos: aquí no hay diccionario de medios. -->
                    <label v-else class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Medio</span>
                        <select v-model="filtros.medio" @change="cargar"
                            class="px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white">
                            <option value="">Todos</option>
                            <option v-for="m in store.medios" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 flex-1 min-w-[12rem]">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Buscar</span>
                        <input v-model="filtros.q" type="search" placeholder="Localizador, referencia o cliente…"
                            @keyup.enter="cargar"
                            class="px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white" />
                    </label>

                    <button type="button" @click="cargar" :disabled="store.isLoading"
                        class="px-3 py-1.5 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-xs font-black">
                        <i class="fas" :class="store.isLoading ? 'fa-circle-notch fa-spin' : 'fa-magnifying-glass'"></i>
                    </button>
                    <button type="button" @click="limpiarFiltros"
                        class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-700">Limpiar</button>

                    <!-- Sólo en Cobros: en Caja no se emite nada, se mira lo que entró. -->
                    <button v-if="activeTab === 'cobros'" type="button" @click="abrirFormManual"
                        class="ml-auto px-3 py-1.5 bg-[#2E7D5B] hover:bg-[#26654a] text-white rounded-lg text-xs font-black">
                        <i class="fas fa-plus mr-1"></i> Cobro manual
                    </button>
                </div>

                <!-- ===== TOTALES ===== -->
                <!-- Agrupados por moneda y NUNCA sumados entre sí: el tipo de cambio bueno
                     es el del día de cada registro, y una cifra única con la cotización de
                     hoy no cuadraría con ningún extracto. -->
                <div v-if="activeTab === 'cobros' && store.totalesCobros.length" class="mt-3 flex flex-wrap gap-2">
                    <div v-for="t in store.totalesCobros" :key="t.estado + t.moneda"
                        class="px-3 py-1.5 rounded-lg border text-[11px]" :class="clasesEstadoEnlace(t.estado)">
                        <span class="font-black uppercase tracking-wide">{{ t.etiqueta }}</span>
                        <span class="ml-2 font-black">{{ t.moneda }} {{ t.total }}</span>
                        <span class="ml-1 opacity-70">({{ t.registros }})</span>
                    </div>
                </div>

                <div v-if="activeTab === 'caja' && store.totalesCaja.length" class="mt-3 flex flex-wrap gap-2">
                    <div v-for="t in store.totalesCaja" :key="t.moneda"
                        class="px-3 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 text-[11px]">
                        <span class="font-black uppercase tracking-wide">Recibido</span>
                        <span class="ml-2 font-black">{{ t.moneda }} {{ t.total }}</span>
                        <span class="ml-1 opacity-70">({{ t.registros }})</span>
                    </div>
                </div>

                <p v-if="truncado" class="mt-2 text-[11px] font-bold text-amber-700">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    Hay más registros de los que caben. Estrecha el rango de fechas para verlos todos.
                </p>
                <p v-if="store.error" class="mt-2 text-[11px] font-bold text-rose-600">{{ store.error }}</p>
            </div>

            <!-- ================= FORMULARIO DE COBRO MANUAL ================= -->
            <div v-if="formAbierto" class="px-4 md:px-6 pt-4">
                <div class="bg-white rounded-xl border border-[#2E7D5B]/30 p-4 max-w-3xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-black text-slate-800">Cobro manual</h2>
                        <button type="button" @click="formAbierto = false"
                            class="text-slate-400 hover:text-slate-700"><i class="fas fa-xmark"></i></button>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">
                        Para cobrar algo que no cuelga de una reserva: una venta suelta, una garantía,
                        un servicio aparte.
                    </p>

                    <!-- Emitido: lo primero que quiere el operador es la URL, no volver a la tabla. -->
                    <div v-if="recienCreado" class="mt-3 p-3 rounded-lg bg-emerald-50 border border-emerald-200">
                        <p class="text-[11px] font-black text-emerald-800">
                            <i class="fas fa-circle-check mr-1"></i>
                            Enlace emitido · {{ recienCreado.monedaSimbolo }} {{ recienCreado.montoTotal }}
                        </p>
                        <div class="mt-2 flex items-center gap-1.5">
                            <input :value="recienCreado.url" readonly
                                class="flex-1 min-w-0 px-2 py-1 bg-white border border-emerald-200 rounded text-[10px] font-mono truncate" />
                            <button type="button" @click="copiar(recienCreado)"
                                class="shrink-0 px-2 py-1 bg-white border border-emerald-200 rounded text-[10px] font-black">
                                <i class="fas fa-copy"></i> Copiar
                            </button>
                        </div>
                        <button type="button" @click="abrirFormManual"
                            class="mt-2 text-[11px] font-black text-emerald-700 underline decoration-dotted">
                            Emitir otro
                        </button>
                    </div>

                    <div v-else class="mt-3 space-y-3">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Importe</span>
                                <input v-model="formManual.monto" type="number" step="0.01" min="0.01"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                            </label>
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Moneda</span>
                                <select v-model="formManual.moneda"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white">
                                    <option value="USD">USD</option>
                                    <option value="PEN">PEN</option>
                                </select>
                            </label>
                            <!-- Sólo etiqueta: no vincula con ningún documento. -->
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Módulo</span>
                                <select v-model="formManual.modulo"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold bg-white">
                                    <option value="">Ninguno</option>
                                    <option value="pms_reserva">PMS</option>
                                    <option value="cotizacion">Cotizaciones</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Vigencia (días)</span>
                                <input v-model.number="formManual.vigenciaDias" type="number" min="0" step="1"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                            </label>
                        </div>

                        <label class="block">
                            <span class="text-[10px] font-black text-slate-500 uppercase">Concepto</span>
                            <input v-model="formManual.concepto" type="text" maxlength="200"
                                placeholder="Lo que verá el cliente en su tarjeta"
                                class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                        </label>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Cliente</span>
                                <input v-model="formManual.clienteNombre" type="text"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                            </label>
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Email</span>
                                <input v-model="formManual.clienteEmail" type="email"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                            </label>
                            <label class="block">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Referencia</span>
                                <input v-model="formManual.referencia" type="text" maxlength="60"
                                    placeholder="Nº de factura, pedido…"
                                    class="mt-1 w-full px-2 py-1.5 border border-slate-200 rounded-lg text-xs font-bold" />
                            </label>
                        </div>

                        <label class="flex items-start gap-2 cursor-pointer">
                            <input v-model="formManual.conRecargo" type="checkbox" class="mt-0.5" />
                            <span class="text-[11px] text-slate-600">
                                <b>Trasladar la comisión al cliente.</b>
                                Se cobra el importe más el recargo de tarjeta.
                            </span>
                        </label>

                        <p v-if="errorForm" class="text-[11px] font-bold text-rose-600">{{ errorForm }}</p>

                        <div class="flex items-center justify-end gap-2">
                            <button type="button" @click="formAbierto = false"
                                class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                            <button type="button" @click="guardarManual"
                                :disabled="guardando || !formManual.monto || !formManual.concepto"
                                class="px-4 py-1.5 bg-[#2E7D5B] hover:bg-[#26654a] disabled:opacity-50 text-white rounded-lg text-xs font-black">
                                <i class="fas" :class="guardando ? 'fa-circle-notch fa-spin' : 'fa-link'"></i>
                                Emitir enlace
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= PESTAÑA COBROS ================= -->
            <section v-if="activeTab === 'cobros'" class="px-4 md:px-6 py-4">
                <p v-if="!store.isLoading && !store.cobros.length" class="text-center py-12 text-sm text-slate-400">
                    No hay cobros emitidos en este rango.
                </p>

                <div v-else class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <tr>
                                <th class="text-left px-3 py-2">Emitido</th>
                                <th class="text-left px-3 py-2">Estado</th>
                                <!-- Con dos pasarelas en paralelo (§11 del doc), saber por
                                     cuál entró cada cobro es lo primero que se necesita
                                     para cuadrarlo contra el extracto correcto. -->
                                <th class="text-left px-3 py-2">Pasarela</th>
                                <!-- A qué negocio pertenece el cobro. «Manual» = suelto. -->
                                <th class="text-left px-3 py-2">Módulo</th>
                                <th class="text-left px-3 py-2">Concepto</th>
                                <th class="text-left px-3 py-2">Documento</th>
                                <th class="text-right px-3 py-2">Importe</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="c in store.cobros" :key="c.id"
                                @click="abrirFicha(c)"
                                class="hover:bg-slate-50 cursor-pointer">
                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ fechaCorta(c.createdAt) }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded border text-[10px] font-black uppercase"
                                        :class="clasesEstadoEnlace(c.estado)">{{ c.estadoEtiqueta }}</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ c.pasarelaEtiqueta }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase"
                                        :class="c.esManual ? 'bg-slate-100 text-slate-500' : 'bg-[#376875]/10 text-[#376875]'">
                                        {{ c.moduloEtiqueta }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 max-w-[18rem] truncate" :title="c.concepto">{{ c.concepto }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <button type="button" @click.stop="irAlOrigen(c.origenTipo, c.origenId)"
                                        class="font-black text-[#376875] hover:underline">
                                        {{ c.origenReferencia || '—' }}
                                    </button>
                                    <span v-if="c.clienteNombre" class="block text-[10px] text-slate-400 truncate max-w-[12rem]">
                                        {{ c.clienteNombre }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <span class="font-black">{{ c.monedaSimbolo }} {{ c.montoTotal }}</span>
                                    <span v-if="Number(c.montoRecargo) > 0.005" class="block text-[10px] text-slate-400">
                                        neto {{ c.montoNeto }} · com. {{ c.montoRecargo }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button v-if="c.vigente" type="button" @click.stop="copiar(c)"
                                        class="px-2 py-1 border border-slate-200 rounded text-[10px] font-black text-slate-500 hover:text-[#376875]">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================= PESTAÑA CAJA ================= -->
            <section v-else class="px-4 md:px-6 py-4">
                <p v-if="!store.isLoading && !store.movimientos.length" class="text-center py-12 text-sm text-slate-400">
                    No entró dinero en este rango.
                </p>

                <div v-else class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <tr>
                                <th class="text-left px-3 py-2">Fecha</th>
                                <th class="text-left px-3 py-2">Medio</th>
                                <th class="text-left px-3 py-2">Documento</th>
                                <th class="text-left px-3 py-2">Cobró</th>
                                <th class="text-right px-3 py-2">Importe</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="m in store.movimientos" :key="m.id" class="hover:bg-slate-50">
                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ fechaCorta(m.fecha) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <i class="fas mr-1 text-slate-400" :class="iconoMedio(m)"></i>
                                    {{ m.medioEtiqueta }}
                                    <!-- Distinguir lo automático importa al cuadrar: nadie
                                         tuvo ese dinero en la mano. -->
                                    <span v-if="m.esAutomatico"
                                        class="ml-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 text-[9px] font-black uppercase">auto</span>
                                </td>
                                <td class="px-3 py-2">
                                    <button type="button" @click="irAlOrigen(m.origenTipo, m.origenId)"
                                        class="font-black text-[#376875] hover:underline">
                                        {{ m.origenReferencia || '—' }}
                                    </button>
                                    <span class="block text-[10px] text-slate-400 truncate max-w-[16rem]">
                                        {{ m.origenDescripcion }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-slate-500 whitespace-nowrap">{{ m.cobradorNombre || '—' }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <span class="font-black text-emerald-700">{{ m.moneda }} {{ m.monto }}</span>
                                    <span v-if="m.tipoCambio" class="block text-[10px] text-slate-400">TC {{ m.tipoCambio }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
        <!-- ================= FICHA DE UN COBRO =================
             Panel lateral, no fila desplegable: en un móvil la tabla ya se corta y lo que
             queda fuera —concepto, documento e importe— es justo lo que se viene a ver. -->
        <div v-if="fichaAbierta" class="fixed inset-0 z-40 bg-slate-900/40" @click="cerrarFicha"></div>

        <aside v-if="fichaAbierta && fichaCobro"
            class="fixed inset-y-0 right-0 z-50 w-full sm:w-[26rem] bg-white shadow-2xl flex flex-col">

            <header class="px-4 py-3 bg-[#376875] text-white flex items-start justify-between gap-3 shrink-0">
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-white/60">Cobro</p>
                    <p class="text-sm font-black truncate">{{ fichaCobro.concepto }}</p>
                </div>
                <button type="button" @click="cerrarFicha"
                    class="shrink-0 w-8 h-8 rounded-lg hover:bg-white/10 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </header>

            <div class="flex-1 min-h-0 overflow-y-auto px-4 py-4 flex flex-col gap-5 text-xs">

                <!-- Estado e importe: lo que se mira primero. -->
                <div class="flex items-center justify-between gap-3">
                    <span class="px-2 py-0.5 rounded border text-[10px] font-black uppercase"
                        :class="clasesEstadoEnlace(fichaCobro.estado)">{{ fichaCobro.estadoEtiqueta }}</span>
                    <div class="text-right">
                        <p class="text-lg font-black text-slate-800">
                            {{ fichaCobro.monedaSimbolo }} {{ fichaCobro.montoTotal }}
                        </p>
                        <!-- El desglose sólo cuando hay recargo: si no, repetir el mismo número
                             tres veces sólo confunde. -->
                        <p v-if="Number(fichaCobro.montoRecargo) > 0.005" class="text-[10px] text-slate-400">
                            neto {{ fichaCobro.montoNeto }} · recargo {{ fichaCobro.montoRecargo }}
                            ({{ fichaCobro.recargoPorcentaje }}%)
                        </p>
                    </div>
                </div>

                <!-- ── EL DOCUMENTO DE ORIGEN ──────────────────────────────
                     Lo que el módulo dueño sabe de su reserva o expediente. En un cobro
                     manual no hay ninguno, y se dice en vez de dejar el hueco. -->
                <section>
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">
                        {{ fichaOrigen ? fichaOrigen.tipoEtiqueta : 'Origen' }}
                    </h3>

                    <p v-if="fichaCargando && !fichaOrigen" class="text-slate-400 italic">
                        <i class="fas fa-circle-notch fa-spin mr-1"></i> Consultando el documento…
                    </p>

                    <div v-else-if="fichaOrigen" class="rounded-xl border border-slate-200 p-3 flex flex-col gap-2">
                        <button type="button" @click="irAlOrigen(fichaCobro.origenTipo, fichaCobro.origenId)"
                            class="text-left font-black text-[#376875] hover:underline">
                            {{ fichaOrigen.referencia }} <i class="fas fa-arrow-up-right-from-square text-[9px]"></i>
                        </button>
                        <p class="text-slate-600 leading-snug">{{ fichaOrigen.descripcion }}</p>
                        <!-- Lo que debe HOY, no lo que debía al emitir el enlace. Es el dato
                             por el que se abre esta ficha después de cobrar. -->
                        <p class="pt-2 border-t border-slate-100 flex items-center justify-between">
                            <span class="text-slate-400 font-bold">Saldo pendiente hoy</span>
                            <span class="font-black"
                                :class="Number(fichaOrigen.saldoPendiente) > 0.005 ? 'text-amber-600' : 'text-emerald-600'">
                                {{ fichaOrigen.moneda }} {{ fichaOrigen.saldoPendiente }}
                            </span>
                        </p>
                    </div>

                    <p v-else-if="fichaCobro.esManual" class="text-slate-400 leading-snug">
                        Cobro manual: no cuelga de ninguna reserva ni expediente. Todo lo que se
                        sabe de él es lo que se tecleó al crearlo.
                    </p>

                    <p v-else class="text-amber-600 leading-snug">
                        <i class="fas fa-triangle-exclamation mr-1"></i>
                        {{ fichaError ?? 'El documento de origen ya no existe.' }}
                    </p>
                </section>

                <!-- ── CLIENTE ─────────────────────────────────────────────
                     En un manual esto ES el cliente: no hay ficha detrás de la que sacarlo. -->
                <section v-if="fichaCobro.clienteNombre || fichaCobro.clienteEmail || fichaCobro.clienteTelefono">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Cliente</h3>
                    <dl class="flex flex-col gap-1.5">
                        <div v-if="fichaCobro.clienteNombre" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold shrink-0">Nombre</dt>
                            <dd class="font-bold text-slate-700 text-right break-words">{{ fichaCobro.clienteNombre }}</dd>
                        </div>
                        <div v-if="fichaCobro.clienteEmail" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold shrink-0">Correo</dt>
                            <dd class="font-bold text-slate-700 text-right break-all">{{ fichaCobro.clienteEmail }}</dd>
                        </div>
                        <div v-if="fichaCobro.clienteTelefono" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold shrink-0">Teléfono</dt>
                            <dd class="font-bold text-slate-700 text-right">{{ fichaCobro.clienteTelefono }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- ── EL COBRO ────────────────────────────────────────── -->
                <section>
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">El cobro</h3>
                    <dl class="flex flex-col gap-1.5">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Módulo</dt>
                            <dd class="font-bold text-slate-700">{{ fichaCobro.moduloEtiqueta }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Pasarela</dt>
                            <dd class="font-bold text-slate-700">{{ fichaCobro.pasarelaEtiqueta }}</dd>
                        </div>
                        <div v-if="fichaCobro.origenReferencia" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold shrink-0">Referencia</dt>
                            <dd class="font-bold text-slate-700 text-right break-all">{{ fichaCobro.origenReferencia }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Emitido</dt>
                            <dd class="font-bold text-slate-700">{{ fechaLarga(fichaCobro.createdAt) }}</dd>
                        </div>
                        <div v-if="fichaCobro.creadoPorNombre" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Lo emitió</dt>
                            <dd class="font-bold text-slate-700 text-right">{{ fichaCobro.creadoPorNombre }}</dd>
                        </div>
                        <div v-if="fichaCobro.expiraEn" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Caduca</dt>
                            <dd class="font-bold text-slate-700">{{ fechaLarga(fichaCobro.expiraEn) }}</dd>
                        </div>
                    </dl>
                    <p v-if="fichaCobro.notas" class="mt-2 p-2 rounded-lg bg-slate-50 text-slate-600 leading-snug">
                        {{ fichaCobro.notas }}
                    </p>
                </section>

                <!-- ── EL PAGO, sólo si lo hubo ────────────────────────────
                     Estos cuatro son los que se cotejan contra el extracto de la pasarela. -->
                <section v-if="fichaCobro.pagadoEn">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">El pago</h3>
                    <dl class="flex flex-col gap-1.5">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Pagado</dt>
                            <dd class="font-bold text-emerald-600">{{ fechaLarga(fichaCobro.pagadoEn) }}</dd>
                        </div>
                        <div v-if="fichaCobro.medioDetalle" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Medio</dt>
                            <dd class="font-bold text-slate-700 text-right">{{ fichaCobro.medioDetalle }}</dd>
                        </div>
                        <div v-if="fichaCobro.autorizacionCodigo" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold">Autorización</dt>
                            <dd class="font-bold text-slate-700 text-right break-all">{{ fichaCobro.autorizacionCodigo }}</dd>
                        </div>
                        <div v-if="fichaCobro.ordenId" class="flex justify-between gap-3">
                            <dt class="text-slate-400 font-bold shrink-0">Orden</dt>
                            <dd class="font-bold text-slate-700 text-right break-all">{{ fichaCobro.ordenId }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <!-- Copiar el enlace sigue siendo la acción más usada; aquí también. -->
            <footer v-if="fichaCobro.vigente" class="shrink-0 px-4 py-3 border-t border-slate-100 bg-slate-50">
                <button type="button" @click="copiar(fichaCobro)"
                    class="w-full py-2 rounded-xl bg-[#376875] hover:bg-[#2d5660] text-white text-xs font-black">
                    <i class="fas fa-copy mr-1"></i> Copiar enlace de pago
                </button>
            </footer>
        </aside>
    </div>
</template>
