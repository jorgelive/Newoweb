<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useOperacionStore } from '@/stores/operacion/operacionStore';
import {
    getEstadoOsConfig,
    getEstadoReservaConfig,
    getEstadoOperacionConfig,
} from '@/types/operacionModel';

const router = useRouter();
const operacionStore = useOperacionStore();

const activeTab = ref<'biblia' | 'ordenes'>('biblia');
const filtroFecha = ref<string>(new Date().toISOString().split('T')[0]);

const serviciosFiltrados = computed(() => operacionStore.servicios);

const cargarBiblia = async () => {
    await operacionStore.fetchServicios({ fechaServicio: filtroFecha.value });
};

const cargarOrdenes = async () => {
    await operacionStore.fetchOrdenesServicio();
};

const cambiarTab = async (tab: 'biblia' | 'ordenes') => {
    activeTab.value = tab;
    if (tab === 'biblia') await cargarBiblia();
    else await cargarOrdenes();
};

onMounted(cargarBiblia);
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">

        <!-- ================================================================
             HEADER
             ================================================================ -->
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3">
                <button
                    @click="router.push('/')"
                    class="w-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors"
                >
                    <i class="fas fa-arrow-left text-sm"></i>
                </button>
                <div class="overflow-hidden">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none">
                        Centro de Operaciones
                    </h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                        Tráfico · Órdenes de Servicio
                    </p>
                </div>
            </div>

            <!-- Tabs como segmented control -->
            <div class="flex items-center bg-slate-800 rounded-lg p-1 gap-1">
                <button
                    @click="cambiarTab('biblia')"
                    :class="activeTab === 'biblia' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                    class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap"
                >
                    <i class="fas fa-car-side mr-1"></i>
                    <span class="hidden sm:inline">La </span>Biblia
                </button>
                <button
                    @click="cambiarTab('ordenes')"
                    :class="activeTab === 'ordenes' ? 'bg-[#E07845] text-white shadow' : 'text-slate-400 hover:text-white'"
                    class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap"
                >
                    <i class="fas fa-file-invoice mr-1"></i>
                    Órdenes
                </button>
            </div>
        </header>

        <!-- ================================================================
             CONTENIDO
             ================================================================ -->
        <main class="flex-1 overflow-y-auto">

            <!-- PESTAÑA: LA BIBLIA ---------------------------------------->
            <section v-if="activeTab === 'biblia'" class="flex flex-col min-h-full">

                <!-- Barra de filtros pegajosa -->
                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-4 md:px-6 py-3 flex flex-wrap items-center gap-3 shrink-0">
                    <div class="flex items-center bg-white border border-slate-200 rounded-xl px-3 py-2 gap-2 shadow-sm">
                        <i class="fas fa-calendar-day text-[#376875] text-xs"></i>
                        <input
                            type="date"
                            v-model="filtroFecha"
                            class="bg-transparent text-sm font-bold text-slate-700 outline-none"
                        />
                    </div>
                    <button
                        @click="cargarBiblia"
                        :disabled="operacionStore.isLoading"
                        class="flex items-center gap-2 px-4 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                    >
                        <i class="fas fa-rotate" :class="{ 'fa-spin': operacionStore.isLoading }"></i>
                        <span class="hidden sm:inline">Actualizar</span>
                    </button>
                    <span class="ml-auto text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        {{ serviciosFiltrados.length }} servicio{{ serviciosFiltrados.length !== 1 ? 's' : '' }}
                    </span>
                </div>

                <!-- Spinner -->
                <div v-if="operacionStore.isLoading" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-[#376875] mb-3"></i>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Sincronizando logística...</p>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else-if="serviciosFiltrados.length === 0" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-car-side text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Sin logística</p>
                        <p class="text-sm text-slate-400">No hay servicios programados para esta fecha.</p>
                    </div>
                </div>

                <!-- Tabla -->
                <div v-else class="px-4 md:px-6 py-4">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

                        <!-- Vista tabla (md+) -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200">
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Hora</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Servicio</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Pax</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden md:table-cell">Proveedor</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Reserva</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Operación</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="servicio in serviciosFiltrados"
                                        :key="servicio.id"
                                        class="hover:bg-slate-50/80 transition-colors group"
                                    >
                                        <!-- Hora -->
                                        <td class="px-4 py-4 whitespace-nowrap align-top">
                                            <span class="text-sm font-black text-slate-900 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200 tabular-nums">
                                                {{ servicio.horaRecojoReal || '--:--' }}
                                            </span>
                                        </td>

                                        <!-- Servicio + proveedor inline en móvil -->
                                        <td class="px-4 py-4 align-top">
                                            <p class="text-sm font-black text-slate-800 leading-tight">
                                                {{ servicio.descripcionServicio }}
                                            </p>
                                            <p class="text-[10px] font-bold text-slate-400 mt-1 md:hidden">
                                                <i class="fas fa-user mr-1"></i>
                                                {{ servicio.proveedorNombreManual || 'Por asignar' }}
                                            </p>
                                            <!-- Estado operación en móvil (< sm) -->
                                            <span
                                                class="mt-1.5 sm:hidden inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-black rounded-lg border"
                                                :class="[getEstadoOperacionConfig(servicio.estadoOperacion).bg, getEstadoOperacionConfig(servicio.estadoOperacion).text, getEstadoOperacionConfig(servicio.estadoOperacion).border]"
                                            >
                                                <i :class="['text-[9px]', getEstadoOperacionConfig(servicio.estadoOperacion).icon]"></i>
                                                {{ getEstadoOperacionConfig(servicio.estadoOperacion).label }}
                                            </span>
                                        </td>

                                        <!-- Pax -->
                                        <td class="px-4 py-4 hidden sm:table-cell whitespace-nowrap align-top">
                                            <span class="text-xs font-black text-slate-600 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200">
                                                <i class="fas fa-users text-slate-400 mr-1"></i>{{ servicio.cantidadPax }}
                                            </span>
                                        </td>

                                        <!-- Proveedor -->
                                        <td class="px-4 py-4 hidden md:table-cell align-top">
                                            <span class="text-sm font-bold text-slate-700">
                                                {{ servicio.proveedorNombreManual || 'Por asignar' }}
                                            </span>
                                        </td>

                                        <!-- Estado reserva -->
                                        <td class="px-4 py-4 whitespace-nowrap align-top">
                                            <span
                                                :class="['px-2 py-1 inline-flex items-center gap-1 text-[10px] font-black rounded-lg border', getEstadoReservaConfig(servicio.estadoReserva).bg, getEstadoReservaConfig(servicio.estadoReserva).text, getEstadoReservaConfig(servicio.estadoReserva).border]"
                                            >
                                                <i :class="['text-[9px]', getEstadoReservaConfig(servicio.estadoReserva).icon]"></i>
                                                <span class="hidden sm:inline">{{ getEstadoReservaConfig(servicio.estadoReserva).label }}</span>
                                            </span>
                                        </td>

                                        <!-- Estado operación -->
                                        <td class="px-4 py-4 hidden sm:table-cell whitespace-nowrap align-top">
                                            <span
                                                :class="['px-2 py-1 inline-flex items-center gap-1 text-[10px] font-black rounded-lg border', getEstadoOperacionConfig(servicio.estadoOperacion).bg, getEstadoOperacionConfig(servicio.estadoOperacion).text, getEstadoOperacionConfig(servicio.estadoOperacion).border]"
                                            >
                                                <i :class="['text-[9px]', getEstadoOperacionConfig(servicio.estadoOperacion).icon]"></i>
                                                {{ getEstadoOperacionConfig(servicio.estadoOperacion).label }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA: ÓRDENES DE SERVICIO -------------------------------->
            <section v-else-if="activeTab === 'ordenes'" class="flex flex-col min-h-full">

                <!-- Barra superior -->
                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-4 md:px-6 py-3 flex items-center gap-3 shrink-0">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-list-check text-[#E07845]"></i>
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:inline">Órdenes Vigentes</span>
                    </div>
                    <button
                        @click="cargarOrdenes"
                        :disabled="operacionStore.isLoading"
                        class="ml-auto flex items-center gap-2 px-4 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                    >
                        <i class="fas fa-rotate" :class="{ 'fa-spin': operacionStore.isLoading }"></i>
                        <span class="hidden sm:inline">Actualizar</span>
                    </button>
                </div>

                <!-- Spinner -->
                <div v-if="operacionStore.isLoading" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-[#E07845] mb-3"></i>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Cargando órdenes...</p>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else-if="operacionStore.ordenesServicio.length === 0" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-file-invoice text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Sin órdenes</p>
                        <p class="text-sm text-slate-400">No hay órdenes de servicio recientes.</p>
                    </div>
                </div>

                <!-- Tabla OS -->
                <div v-else class="px-4 md:px-6 py-4">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200">
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">OS #</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Proveedor</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Total</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Estado</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="orden in operacionStore.ordenesServicio"
                                        :key="orden.id"
                                        class="hover:bg-slate-50/80 transition-colors"
                                    >
                                        <!-- OS # -->
                                        <td class="px-4 py-4 whitespace-nowrap align-top">
                                            <span class="text-sm font-black text-[#376875]">{{ orden.numeroOs }}</span>
                                        </td>

                                        <!-- Proveedor + total inline en móvil -->
                                        <td class="px-4 py-4 align-top">
                                            <p class="text-sm font-bold text-slate-800">
                                                {{ orden.proveedorNombreManual || 'No definido' }}
                                            </p>
                                            <p class="text-[10px] font-black text-slate-400 mt-0.5 sm:hidden">
                                                <span class="text-slate-300">{{ orden.monedaOs?.id || 'USD' }}</span>
                                                {{ orden.totalOs }}
                                            </p>
                                        </td>

                                        <!-- Total -->
                                        <td class="px-4 py-4 hidden sm:table-cell whitespace-nowrap align-top">
                                            <span class="text-sm font-black text-slate-800">
                                                <span class="text-[10px] font-bold text-slate-400 mr-1">{{ orden.monedaOs?.id || 'USD' }}</span>{{ orden.totalOs }}
                                            </span>
                                        </td>

                                        <!-- Estado OS -->
                                        <td class="px-4 py-4 whitespace-nowrap align-top">
                                            <span
                                                :class="['px-2 py-1 inline-flex items-center gap-1 text-[10px] font-black rounded-lg border', getEstadoOsConfig(orden.estadoOs).bg, getEstadoOsConfig(orden.estadoOs).text, getEstadoOsConfig(orden.estadoOs).border]"
                                            >
                                                <i :class="['text-[9px]', getEstadoOsConfig(orden.estadoOs).icon]"></i>
                                                {{ getEstadoOsConfig(orden.estadoOs).label }}
                                            </span>
                                        </td>

                                        <!-- Acciones -->
                                        <td class="px-4 py-4 text-right align-top">
                                            <button class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm">
                                                <i class="fas fa-message text-[9px]"></i>
                                                <span class="hidden sm:inline">Mensajes</span>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>
</template>
