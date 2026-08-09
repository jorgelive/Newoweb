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
import { clasesEstadoEnlace, type FinEnlacePago } from '@/types/finEnlacePagoModel';
import type { FinCajaFiltros, FinMovimiento } from '@/types/finMovimientoModel';

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

const cargar = async (): Promise<void> => {
    if (activeTab.value === 'cobros') {
        await store.fetchCobros(filtros.value);
    } else {
        await store.fetchMovimientos(filtros.value);
    }
};

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
const irAlOrigen = (origenTipo: string, origenId: string): void => {
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
                                <th class="text-left px-3 py-2">Concepto</th>
                                <th class="text-left px-3 py-2">Documento</th>
                                <th class="text-right px-3 py-2">Importe</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="c in store.cobros" :key="c.id" class="hover:bg-slate-50">
                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ fechaCorta(c.createdAt) }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded border text-[10px] font-black uppercase"
                                        :class="clasesEstadoEnlace(c.estado)">{{ c.estadoEtiqueta }}</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ c.pasarelaEtiqueta }}</td>
                                <td class="px-3 py-2 max-w-[18rem] truncate" :title="c.concepto">{{ c.concepto }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <button type="button" @click="irAlOrigen(c.origenTipo, c.origenId)"
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
                                    <button v-if="c.vigente" type="button" @click="copiar(c)"
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
    </div>
</template>
