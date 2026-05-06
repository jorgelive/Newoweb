<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router'; // 🔥 NUEVO: Para el botón retroceder
import { useCotizacionEditorStore } from '@/stores/cotizaciones/cotizacionEditorStore';

const store = useCotizacionEditorStore();
const router = useRouter(); // 🔥 NUEVO

// ============================================================================
// INICIALIZACIÓN (HOOKS)
// ============================================================================
onMounted(() => {
  store.inicializarEditor();
});

// ============================================================================
// HELPERS DE VISTA
// ============================================================================
const formatFecha = (fecha?: string) => {
  if (!fecha) return '--';
  return new Date(fecha).toLocaleDateString('es-PE', { weekday: 'long', day: '2-digit', month: 'short', timeZone: 'UTC' });
};

const formatTimeFromISO = (isoString?: string) => {
  if (!isoString) return '--:--';
  const date = new Date(isoString);
  return isNaN(date.getTime()) ? '--:--' : date.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
};

const formatMoneda = (monto?: number | string, moneda?: string) => {
  const num = typeof monto === 'string' ? parseFloat(monto) : (monto ?? 0);
  return `${moneda === 'USD' ? '$' : 'S/'} ${num.toFixed(2)}`;
};

const dragStart = (e: DragEvent, segmentoMaestro: any) => {
  if (e.dataTransfer) {
    e.dataTransfer.setData('application/json', JSON.stringify(segmentoMaestro));
    e.dataTransfer.effectAllowed = 'copy';
  }
};

const dropSegmento = (e: DragEvent) => {
  if (e.dataTransfer) {
    const data = e.dataTransfer.getData('application/json');
    if (data) store.agregarSegmentoIndividual(JSON.parse(data));
  }
};

const plantillaSeleccionada = ref<string | null>(null);
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden">

    <!-- ================================================================== -->
    <!-- HEADER GLOBAL -->
    <!-- ================================================================== -->
    <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md flex-shrink-0">
      <div class="flex items-center gap-3">
        <!-- 🔥 NUEVO: Router Back implementado -->
        <button @click="router.back()" class="w-8 h-8 md:w-10 md:h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
          <i class="fas fa-arrow-left text-sm"></i>
        </button>
        <div class="overflow-hidden">
          <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">Familia Pérez <span class="hidden sm:inline">- Construcción</span></h1>
          <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">
            Motor Operativo <span v-if="store.cotizacion">• V{{ store.cotizacion.version ?? 1 }}</span>
          </p>
        </div>
      </div>

      <div class="flex gap-2 md:gap-3" v-if="store.cotizacion">
        <!-- Switch Idioma Dinámico (Operador vs Cliente) -->
        <div class="hidden md:flex items-center bg-slate-800 rounded-lg p-1 gap-1">
          <button @click="store.cotizacion.idiomaEdicion = 'es'"
                  :class="store.cotizacion.idiomaEdicion === 'es' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                  class="px-3 py-1 rounded text-[10px] font-black tracking-widest transition-all">
            ES (INTERNO)
          </button>
          <button v-if="store.cotizacion.idiomaCliente !== 'es'"
                  @click="store.cotizacion.idiomaEdicion = store.cotizacion.idiomaCliente"
                  :class="store.cotizacion.idiomaEdicion === store.cotizacion.idiomaCliente ? 'bg-[#E07845] text-white shadow' : 'text-slate-400 hover:text-white'"
                  class="px-3 py-1 rounded text-[10px] font-black tracking-widest uppercase transition-all">
            {{ store.cotizacion.idiomaCliente }} (CLIENTE)
          </button>
        </div>

        <button @click="store.abrirNivel('resumen')" class="md:hidden px-4 py-2 bg-slate-800 text-slate-300 rounded-lg text-xs font-bold">Totales</button>
        <button @click="store.guardarCotizacion()" class="px-4 md:px-5 py-2 bg-[#E07845] hover:bg-[#c96636] rounded-lg text-xs font-bold transition-colors flex items-center gap-2">
          <i class="fas fa-save"></i> <span class="hidden sm:inline">Guardar</span>
        </button>
      </div>
    </header>

    <!-- ================================================================== -->
    <!-- ESTADO DE CARGA (SPINNER) -->
    <!-- ================================================================== -->
    <div v-if="store.isLoading" class="flex-1 flex items-center justify-center bg-[#F8FAFC]">
      <div class="text-center text-slate-400">
        <i class="fas fa-spinner fa-spin text-4xl mb-4 text-[#376875]"></i>
        <p class="font-black tracking-widest uppercase text-xs">Sincronizando con Servidor...</p>
      </div>
    </div>

    <!-- ================================================================== -->
    <!-- ÁREA DE TRABAJO (Solo visible si hay datos) -->
    <!-- ================================================================== -->
    <div v-else-if="store.cotizacion" class="flex flex-1 overflow-hidden relative">

      <!-- ================================================================== -->
      <!-- LIENZO DEL ITINERARIO CENTRAL -->
      <!-- ================================================================== -->
      <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-[#F8FAFC]">
        <div class="max-w-4xl mx-auto pb-32">

          <div v-for="dia in store.cotizacion.itinerario" :key="dia.diaNumero" class="mb-8">
            <div class="flex items-center gap-3 sticky top-0 bg-[#F8FAFC]/90 backdrop-blur-sm py-2 z-10 mb-4">
              <div class="bg-slate-800 text-white px-3 py-1.5 rounded-lg font-black text-xs uppercase tracking-widest shadow-sm">
                Día {{ dia.diaNumero ?? 0 }}
              </div>
              <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">{{ formatFecha(dia.fechaAbsoluta) }}</div>
              <hr class="flex-1 border-slate-200">
            </div>

            <div class="space-y-3">
              <div v-for="servicio in dia.cotservicios" :key="servicio.id"
                   @click="store.abrirNivel('servicio', servicio)"
                   class="bg-white border-2 rounded-2xl p-4 shadow-sm transition-all cursor-pointer group relative overflow-hidden"
                   :class="store.inspectorActivo === 'servicio' && store.dataActiva?.id === servicio.id ? 'border-[#376875] shadow-md' : 'border-slate-100 hover:border-[#376875]/50'">

                <button @click.stop="store.eliminarServicio(dia.diaNumero, servicio.id)" class="absolute right-4 top-4 text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity">
                  <i class="fas fa-trash-alt"></i>
                </button>

                <div class="flex items-start justify-between gap-4">
                  <div class="pr-6">
                    <p class="text-[10px] font-bold text-slate-400 uppercase flex items-center gap-1.5 mb-1">
                      <i class="far fa-calendar-check"></i> {{ formatFecha(servicio.fechaInicioAbsoluta) }}
                    </p>
                    <h3 class="font-black text-sm md:text-base text-slate-800">{{ store.renderI18n(servicio.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h3>
                    <p class="text-[10px] font-bold text-slate-500 mt-1"><i class="fas fa-map-signs mr-1"></i> {{ store.renderI18n(servicio.itinerarioNombreSnapshot, store.cotizacion.idiomaEdicion) }}</p>

                    <div class="flex gap-2 mt-3">
                      <p class="text-[10px] font-bold text-[#E07845] bg-orange-50 inline-block px-2 py-1 rounded border border-orange-100">
                        <i class="fas fa-layer-group mr-1"></i> {{ servicio.cotcomponentes?.length ?? 0 }} Componentes
                      </p>
                      <p v-if="servicio.cotsegmentos?.length" class="text-[10px] font-bold text-indigo-600 bg-indigo-50 inline-block px-2 py-1 rounded border border-indigo-100">
                        <i class="fas fa-align-left mr-1"></i> {{ servicio.cotsegmentos.length }} Segmentos
                      </p>
                    </div>
                  </div>
                </div>
              </div>

              <button @click="store.agregarServicio(dia.diaNumero)" class="w-full py-4 border-2 border-dashed border-slate-200 rounded-2xl text-slate-400 font-bold text-[11px] uppercase tracking-widest hover:border-[#376875] hover:text-[#376875] transition-colors">
                + Añadir Servicio
              </button>
            </div>
          </div>
        </div>
      </main>

      <!-- ================================================================== -->
      <!-- EL INSPECTOR MULTINIVEL -->
      <!-- ================================================================== -->
      <aside :class="[
            'bg-white flex flex-col transition-transform duration-300 ease-in-out border-slate-200 flex-shrink-0',
            'fixed inset-0 z-50 md:z-10 w-full',
            store.isMobileOpen ? 'translate-y-0' : 'translate-y-full',
            'md:relative md:w-[420px] md:border-l md:translate-y-0 md:transform-none',
            store.inspectorActivo === 'tarifa' ? 'bg-slate-900 text-white' : 'bg-white text-slate-800'
        ]">

        <!-- ================================================================== -->
        <!-- CONTENIDO 0: CABECERA Y RESUMEN ECONÓMICO -->
        <!-- ================================================================== -->
        <div v-if="store.inspectorActivo === 'resumen'" class="flex-1 flex flex-col">
          <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest">Cabecera de Cotización</h2>
            <button @click="store.cerrarInspectorMobile" class="md:hidden text-slate-400 hover:text-red-500"><i class="fas fa-times text-lg"></i></button>
          </div>

          <div class="p-6 flex-1 overflow-y-auto space-y-6">

            <div class="bg-[#376875] text-white rounded-3xl p-6 shadow-xl relative overflow-hidden">
              <i class="fas fa-chart-pie absolute -right-6 -bottom-6 text-8xl opacity-10"></i>
              <div class="relative z-10 flex justify-between items-end">
                <div>
                  <p class="text-[10px] font-bold bg-white/20 px-2 py-1 rounded w-max uppercase tracking-widest mb-1">Total Costo Neto</p>
                  <p class="text-4xl font-black tracking-tight">${{ store.totalCostoNeto.toFixed(2) }}</p>
                </div>
                <div class="text-right">
                  <p class="text-[9px] font-bold text-slate-300 uppercase tracking-widest">Venta Sugerida</p>
                  <p class="text-xl font-bold text-[#E07845]">${{ store.ventaSugerida.toFixed(2) }}</p>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2 grid grid-cols-2 gap-4 bg-slate-50 border border-slate-200 rounded-2xl p-4">
                <div>
                  <span class="block text-xs font-bold text-slate-500 uppercase mb-1">Estado Versión</span>
                  <select v-model="store.cotizacion.estado" class="w-full font-black text-slate-800 bg-white px-3 py-2 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] text-sm">
                    <option value="Pendiente">Pendiente</option>
                    <option value="Archivado">Archivado</option>
                    <option value="Confirmado">Confirmado</option>
                    <option value="Operado">Operado</option>
                    <option value="Cancelado">Cancelado</option>
                  </select>
                </div>
                <div>
                  <span class="block text-xs font-bold text-slate-500 uppercase mb-1">Idioma (PDF) <i class="fas fa-language ml-1 text-slate-400"></i></span>
                  <!-- Pinta las opciones directo de tu API Platform (MaestroIdioma) -->
                  <select v-model="store.cotizacion.idiomaCliente" @change="store.cotizacion.idiomaEdicion = 'es'" class="w-full font-black text-slate-800 bg-white px-3 py-2 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] text-sm">
                    <option v-for="lang in store.idiomasDisponibles" :key="lang.id" :value="lang.id">
                      {{ lang.nombre }} {{ lang.bandera ? lang.bandera : '' }} ({{ lang.id.toUpperCase() }})
                    </option>
                  </select>
                </div>
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Num Pax (Base) *</label>
                <input v-model="store.cotizacion.numPax" type="number" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold text-center outline-none focus:ring-2 focus:ring-[#376875]">
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Comisión / Markup (%)</label>
                <div class="relative">
                  <input v-model="store.cotizacion.comision" type="number" step="0.1" class="w-full bg-white border border-slate-300 rounded-xl pl-4 pr-8 py-3 text-sm font-bold text-right text-emerald-600 outline-none focus:ring-2 focus:ring-[#376875]">
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold">%</span>
                </div>
              </div>

              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Monto Adelanto</label>
                <input v-model="store.cotizacion.adelanto" type="number" step="0.01" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875]">
              </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
              <label class="flex items-center gap-3 cursor-pointer">
                <input v-model="store.cotizacion.hotelOculto" type="checkbox" class="w-5 h-5 rounded text-[#376875] focus:ring-[#376875]">
                <span class="text-xs font-bold text-slate-700">Hotel oculto en PDF</span>
              </label>
              <label class="flex items-center gap-3 cursor-pointer">
                <input v-model="store.cotizacion.precioOculto" type="checkbox" class="w-5 h-5 rounded text-[#376875] focus:ring-[#376875]">
                <span class="text-xs font-bold text-slate-700">Precio oculto en PDF</span>
              </label>
            </div>

            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1"><i class="fas fa-align-left mr-1"></i> Párrafo Resumen ({{ store.cotizacion.idiomaEdicion.toUpperCase() }})</label>
              <textarea v-model="store.getI18n(store.cotizacion.resumenI18n, store.cotizacion.idiomaEdicion).content" rows="4" class="w-full bg-white border border-slate-300 rounded-xl p-3 text-xs text-slate-600 leading-relaxed outline-none focus:ring-2 focus:ring-[#376875] resize-none" placeholder="Escriba aquí el resumen introductorio para el PDF..."></textarea>
            </div>

          </div>
        </div>

        <!-- ================================================================== -->
        <!-- CONTENIDO 1: SERVICIO -->
        <!-- ================================================================== -->
        <div v-else-if="store.inspectorActivo === 'servicio'" class="flex-1 flex flex-col h-full">
          <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3 bg-slate-50 flex-shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors"><i class="fas fa-arrow-left"></i></button>
            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-[#E07845] uppercase tracking-widest truncate">Edición de Servicio</p>
              <h2 class="text-sm font-black truncate">{{ store.renderI18n(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h2>
            </div>
          </div>

          <div class="p-5 flex-1 overflow-y-auto space-y-6">
            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-4">
              <div>
                <label class="block text-[10px] font-black text-[#E07845] uppercase tracking-widest mb-2"><i class="fas fa-book mr-1"></i> Catálogo Maestro</label>
                <select v-model="store.dataActiva.servicioMaestroId" @change="e => store.onServicioMaestroChange((e.target as HTMLSelectElement).value)" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold outline-none">
                  <option :value="null">Seleccione del catálogo...</option>
                  <option v-for="cat in store.catalogos.servicios" :key="cat.id" :value="cat.id">{{ cat.nombreInterno || store.renderI18n(cat.titulo, 'es') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Público ({{ store.cotizacion.idiomaEdicion.toUpperCase() }}) *</label>
                <input v-model="store.getI18n(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion).content" type="text" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
            </div>

            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1"><i class="far fa-calendar-alt mr-1"></i> Fecha Ejecución (Milestone)</label>
              <input v-model="store.dataActiva.fechaInicioAbsoluta" type="date" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none">
            </div>

            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4">
              <div class="flex items-start justify-between gap-2 mb-2">
                <div>
                  <h3 class="text-[10px] font-black text-indigo-700 uppercase tracking-widest"><i class="fas fa-align-left mr-1"></i> Storytelling</h3>
                  <p class="text-[10px] text-indigo-500 mt-1 font-medium">{{ store.renderI18n(store.dataActiva.itinerarioNombreSnapshot, store.cotizacion.idiomaEdicion) }}</p>
                </div>
                <button @click="store.abrirEditorSegmentos" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-[10px] font-bold shadow-sm whitespace-nowrap">
                  <i class="fas fa-pencil-alt mr-1"></i> Configurar
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-5">
              <h3 class="text-[10px] font-black text-sky-600 uppercase tracking-widest mb-3 flex items-center justify-between">
                <span><i class="fas fa-puzzle-piece mr-1"></i> Componentes (Costos)</span>
                <button @click="store.agregarComponente(store.dataActiva.id)" class="bg-sky-100 text-sky-700 px-2 py-1 rounded text-[9px] font-bold hover:bg-sky-200"><i class="fas fa-plus"></i> Añadir</button>
              </h3>
              <div class="space-y-3">
                <div v-for="comp in store.dataActiva.cotcomponentes" :key="comp.id"
                     @click="store.abrirNivel('componente', comp)"
                     class="bg-white border-2 border-sky-100 rounded-xl p-4 shadow-sm cursor-pointer hover:border-sky-300 relative group overflow-hidden">
                  <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-sky-400"></div>

                  <button @click.stop="store.eliminarComponente(store.dataActiva.id, comp.id)" class="absolute right-2 top-2 text-sky-200 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-trash-alt"></i>
                  </button>

                  <div class="pl-2">
                    <p class="text-[9px] font-bold text-sky-600 uppercase mb-1 flex items-center justify-between pr-6">
                      <span><i class="far fa-clock"></i> {{ formatTimeFromISO(comp.fechaHoraInicio) }} a {{ formatTimeFromISO(comp.fechaHoraFin) }}</span>
                      <span v-if="comp.modo !== 'incluido'" class="bg-sky-100 px-1.5 py-0.5 rounded text-[8px]">{{ comp.modo }}</span>
                    </p>
                    <div class="flex justify-between items-start gap-2 pr-6">
                      <h4 class="font-bold text-sm text-slate-800 leading-tight">{{ store.renderI18n(comp.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h4>
                      <i class="fas fa-chevron-right text-sky-300 group-hover:translate-x-1 transition-transform"></i>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ================================================================== -->
        <!-- CONTENIDO 2: COMPONENTE -->
        <!-- ================================================================== -->
        <div v-else-if="store.inspectorActivo === 'componente'" class="flex-1 flex flex-col h-full bg-sky-50/50">
          <div class="px-5 py-4 border-b border-sky-200 flex items-center gap-3 bg-sky-600 text-white flex-shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-sky-500 flex items-center justify-center transition-colors"><i class="fas fa-arrow-left"></i></button>
            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-sky-200 uppercase tracking-widest truncate">Componente Logístico</p>
              <h2 class="text-sm font-black truncate">{{ store.renderI18n(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h2>
            </div>
          </div>

          <div class="p-5 flex-1 overflow-y-auto space-y-6">
            <div class="bg-white border border-sky-200 p-4 rounded-xl shadow-sm">
              <label class="block text-[10px] font-black text-sky-600 uppercase tracking-widest mb-2"><i class="fas fa-box-open mr-1"></i> Insumo Maestro</label>
              <select v-model="store.dataActiva.componenteMaestroId" @change="e => store.onComponenteMaestroChange((e.target as HTMLSelectElement).value)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold outline-none">
                <option :value="null">Seleccione componente...</option>
                <option v-for="cat in store.catalogos.componentes" :key="cat.id" :value="cat.id">{{ store.renderI18n(cat.titulo, 'es') || cat.nombre }}</option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre ({{ store.cotizacion.idiomaEdicion.toUpperCase() }}) *</label>
                <input v-model="store.getI18n(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion).content" type="text" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-sky-500 outline-none">
              </div>

              <div class="col-span-2 grid grid-cols-2 gap-3 p-3 bg-white border border-slate-200 rounded-xl">
                <div>
                  <label class="block text-[9px] font-black text-slate-500 uppercase mb-1">Inicio Exacto *</label>
                  <input v-model="store.dataActiva.fechaHoraInicio" type="datetime-local" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold outline-none">
                </div>
                <div>
                  <label class="block text-[9px] font-black text-slate-500 uppercase mb-1">Fin Exacto *</label>
                  <input v-model="store.dataActiva.fechaHoraFin" type="datetime-local" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold outline-none">
                </div>
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Cantidad *</label>
                <input v-model="store.dataActiva.cantidad" type="number" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold text-center outline-none">
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Estado Operativo *</label>
                <select v-model="store.dataActiva.estado" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none">
                  <option value="Pendiente">Pendiente</option>
                  <option value="Confirmado">Confirmado</option>
                  <option value="Reconfirmado">Reconfirmado</option>
                  <option value="Cancelado">Cancelado</option>
                </select>
              </div>

              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Modo Comercial (ItemModoEnum)</label>
                <select v-model="store.dataActiva.modo" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-sky-500">
                  <option value="incluido">Incluido</option>
                  <option value="opcional">Opcional (Upsell)</option>
                  <option value="no_incluido">No Incluido</option>
                  <option value="no_necesario">No Necesario</option>
                  <option value="cortesia">Cortesía</option>
                </select>
              </div>
            </div>

            <div class="border-t border-sky-100 pt-5">
              <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest mb-3 flex items-center justify-between">
                <span><i class="fas fa-money-bill-wave mr-1"></i> Tarifas (Costos)</span>
                <button @click="store.agregarTarifa(store.dataActiva.id)" class="bg-orange-500 text-white px-2 py-1 rounded shadow text-[9px]"><i class="fas fa-plus"></i> Tarifa</button>
              </h3>
              <div class="space-y-3">
                <div v-for="tarifa in store.dataActiva.cottarifas" :key="tarifa.id"
                     @click="store.abrirNivel('tarifa', tarifa)"
                     class="bg-white border-2 border-orange-200 rounded-xl p-4 shadow-sm cursor-pointer hover:border-orange-400 relative group overflow-hidden">
                  <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-orange-400"></div>

                  <button @click.stop="store.eliminarTarifa(store.dataActiva.id, tarifa.id)" class="absolute right-2 top-2 text-orange-200 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-trash-alt"></i>
                  </button>

                  <div class="pl-2 pr-6">
                    <p class="text-[9px] font-black text-slate-400 uppercase mb-1">{{ tarifa.tipoModalidadSnapshot ?? 'N/A' }} • {{ tarifa.proveedorNombreSnapshot ?? 'S/P' }}</p>
                    <div class="flex justify-between items-center">
                      <h4 class="font-bold text-sm text-slate-800">{{ store.renderI18n(tarifa.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h4>
                      <h4 class="font-black text-base text-orange-600">{{ formatMoneda(tarifa.montoCosto * tarifa.cantidad, tarifa.moneda) }}</h4>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ================================================================== -->
        <!-- CONTENIDO 3: TARIFA (Dark Mode) -->
        <!-- ================================================================== -->
        <div v-else-if="store.inspectorActivo === 'tarifa'" class="flex-1 flex flex-col h-full bg-slate-900">
          <div class="px-5 py-4 border-b border-slate-700 flex items-center gap-3 bg-slate-800 flex-shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-slate-700 text-slate-400 flex items-center justify-center transition-colors"><i class="fas fa-arrow-left"></i></button>
            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-emerald-400 uppercase tracking-widest truncate">Costo y Operativa</p>
              <h2 class="text-sm font-black text-white truncate">{{ store.renderI18n(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h2>
            </div>
          </div>

          <div class="p-5 flex-1 overflow-y-auto space-y-6">
            <div class="bg-slate-800 border border-slate-700 p-4 rounded-xl">
              <label class="block text-[10px] font-black text-orange-400 uppercase tracking-widest mb-2"><i class="fas fa-tags mr-1"></i> Tarifa Maestra</label>
              <select v-model="store.dataActiva.tarifaMaestraId" @change="e => store.onTarifaMaestraChange((e.target as HTMLSelectElement).value)" class="w-full bg-slate-900 border border-slate-600 text-white rounded-lg px-3 py-2 text-sm font-bold focus:ring-1 focus:ring-orange-500 outline-none">
                <option :value="null">Precio manual (Sin maestro)...</option>
                <option v-for="cat in store.catalogos.tarifas" :key="cat.id" :value="cat.id">{{ cat.nombreInterno || store.renderI18n(cat.titulo, 'es') }} ({{ cat.moneda }} {{ cat.monto ?? 0 }})</option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Nombre Recibo ({{ store.cotizacion.idiomaEdicion.toUpperCase() }}) *</label>
                <input v-model="store.getI18n(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion).content" type="text" class="w-full bg-slate-800 border border-slate-600 text-white rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-orange-500 outline-none">
              </div>

              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Proveedor (Snapshot)</label>
                <input v-model="store.dataActiva.proveedorNombreSnapshot" type="text" class="w-full bg-slate-800 border border-slate-600 text-white rounded-xl px-4 py-3 text-sm font-bold outline-none" placeholder="Nombre Proveedor">
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Modalidad</label>
                <input v-model="store.dataActiva.tipoModalidadSnapshot" type="text" class="w-full bg-slate-800 border border-slate-600 text-white rounded-xl px-4 py-3 text-sm font-bold outline-none" placeholder="Normal, Privado...">
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Cant (Pax/Items) *</label>
                <input v-model="store.dataActiva.cantidad" type="number" class="w-full bg-slate-800 border border-slate-600 text-white rounded-xl px-4 py-3 text-sm font-bold text-center outline-none">
              </div>

              <div class="col-span-2 bg-slate-800 border border-slate-700 rounded-2xl p-4 flex justify-between items-center mt-2">
                <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Moneda Base</label>
                  <select v-model="store.dataActiva.moneda" class="bg-transparent text-white font-bold text-xs outline-none border-b border-slate-600 pb-1">
                    <option value="USD" class="text-slate-800">USD</option>
                    <option value="PEN" class="text-slate-800">PEN</option>
                  </select>
                </div>
                <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1 text-right">Costo Unitario</label>
                  <input v-model="store.dataActiva.montoCosto" type="number" step="0.01" class="w-32 bg-slate-900 border border-slate-600 text-orange-400 rounded-xl px-3 py-2 text-xl font-black text-right focus:border-orange-500 outline-none">
                </div>
              </div>
              <!-- Suma Total In-place -->
              <div class="col-span-2 text-right mt-[-10px]">
                <p class="text-[10px] text-slate-400 font-bold uppercase">Subtotal Neto: <span class="text-orange-400 text-sm">${{ (store.dataActiva.montoCosto * store.dataActiva.cantidad).toFixed(2) }}</span></p>
              </div>
            </div>

            <div class="pt-2 border-t border-slate-700">
              <h3 class="text-[10px] font-black text-emerald-400 uppercase tracking-widest mb-4 flex items-center justify-between">
                <span><i class="fas fa-clipboard-list mr-1"></i> Detalles Operativos (JSON)</span>
                <button @click="store.agregarDetalleOperativo(store.dataActiva.id)" class="bg-slate-700 text-white px-2 py-1 rounded shadow text-[9px]"><i class="fas fa-plus"></i> Agregar</button>
              </h3>
              <div class="space-y-3">
                <div v-for="detalle in store.dataActiva.detallesOperativos" :key="detalle.id" class="bg-slate-800 border border-slate-600 p-3 rounded-xl flex gap-3 items-start group">
                  <i class="fas fa-info-circle text-emerald-500 mt-2"></i>
                  <div class="flex-1 space-y-1">
                    <div class="flex justify-between items-center">
                      <input v-model="detalle.tipo" class="bg-transparent text-[9px] font-black text-slate-400 uppercase outline-none focus:text-emerald-400 w-24">
                      <button @click="store.eliminarDetalleOperativo(store.dataActiva.id, detalle.id)" class="text-slate-500 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"><i class="fas fa-trash-alt text-[10px]"></i></button>
                    </div>
                    <textarea v-model="detalle.contenido" rows="2" class="w-full bg-slate-900 text-slate-200 rounded-lg p-2 text-xs font-medium border border-slate-700 focus:border-emerald-500 outline-none resize-none"></textarea>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </aside>
    </div>

    <!-- ================================================================== -->
    <!-- MODAL: EDITOR NARRATIVO (STORYTELLING) -->
    <!-- ================================================================== -->
    <Transition name="fade-scale">
      <div v-if="store.isSegmentEditorOpen && store.cotizacion" class="fixed inset-0 z-[100] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 md:p-8">
        <div class="bg-[#F8FAFC] w-full max-w-6xl h-full max-h-[90vh] rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-slate-200">
          <header class="bg-indigo-600 text-white px-6 py-4 flex justify-between items-center flex-shrink-0">
            <div>
              <h2 class="font-black text-lg flex items-center gap-2"><i class="fas fa-book-open"></i> Constructor de Storytelling</h2>
              <p class="text-[11px] font-bold text-indigo-200 uppercase tracking-widest mt-1">Servicio: {{ store.renderI18n(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</p>
            </div>
            <button @click="store.cerrarEditorSegmentos" class="w-8 h-8 rounded-full bg-indigo-500 hover:bg-indigo-400 flex items-center justify-center transition-colors">
              <i class="fas fa-times text-sm"></i>
            </button>
          </header>

          <div class="flex flex-1 overflow-hidden flex-col md:flex-row">
            <aside class="w-full md:w-1/3 bg-white border-r border-slate-200 flex flex-col flex-shrink-0 shadow-[4px_0_24px_rgba(0,0,0,0.02)] z-10 h-1/2 md:h-full">
              <div class="p-5 border-b border-slate-100 bg-slate-50">
                <label class="block text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-2">1. Cargar Plantilla Completa</label>
                <div class="flex gap-2">
                  <select v-model="plantillaSeleccionada" class="flex-1 bg-white text-slate-700 border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold outline-none focus:border-indigo-500 appearance-none shadow-sm">
                    <option :value="null" class="text-slate-400 bg-white font-medium">Seleccionar itinerario...</option>
                    <option v-for="plt in store.catalogos.plantillasItinerario"
                            :key="plt.id || plt['@id']"
                            :value="plt.id || plt['@id']"
                            class="text-slate-800 bg-white font-bold">
                      {{ store.renderI18n(plt.titulo, store.cotizacion.idiomaEdicion) || plt.nombreInterno || 'Plantilla sin nombre' }}
                    </option>
                  </select>
                  <button @click="plantillaSeleccionada && store.aplicarPlantilla(plantillaSeleccionada)" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700 transition-colors">Aplicar</button>
                </div>
              </div>

              <div class="p-5 flex-1 overflow-y-auto bg-white">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">2. O arrastra piezas individuales (Pool)</label>
                <div class="space-y-3">
                  <div v-for="seg in store.catalogos.poolSegmentos" :key="seg.id"
                       draggable="true"
                       @dragstart="dragStart($event, seg)"
                       class="bg-white border-2 border-dashed border-slate-200 p-3 rounded-xl cursor-grab hover:border-indigo-300 hover:bg-indigo-50 transition-all flex gap-3">
                    <i class="fas fa-grip-vertical text-slate-300 mt-1"></i>
                    <div class="flex-1">
                      <h4 class="text-xs font-bold text-slate-700 leading-tight mb-1">{{ store.renderI18n(seg.titulo, store.cotizacion.idiomaEdicion) }}</h4>
                      <p class="text-[10px] text-slate-500 line-clamp-2 leading-snug">{{ store.renderI18n(seg.contenido, store.cotizacion.idiomaEdicion) }}</p>
                    </div>
                    <button @click="store.agregarSegmentoIndividual(seg)" class="text-indigo-600 hover:bg-indigo-100 p-2 rounded-lg transition-colors flex-shrink-0"><i class="fas fa-plus"></i></button>
                  </div>
                </div>
              </div>
            </aside>

            <main class="flex-1 overflow-y-auto p-6 md:p-8 bg-[#F8FAFC] h-1/2 md:h-full" @dragover.prevent @dragenter.prevent @drop="dropSegmento">
              <div class="max-w-3xl mx-auto space-y-6 pb-20">
                <div class="flex items-center justify-between mb-2">
                  <h3 class="text-sm font-black text-slate-700 uppercase tracking-widest"><i class="fas fa-stream mr-2"></i> Párrafos en la Cotización ({{ store.cotizacion.idiomaEdicion.toUpperCase() }})</h3>
                </div>

                <div v-if="!store.dataActiva?.cotsegmentos?.length" class="border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400 flex flex-col items-center">
                  <i class="fas fa-align-center text-4xl mb-4 opacity-50"></i>
                  <p class="text-sm font-bold uppercase tracking-widest">El servicio no tiene textos</p>
                  <p class="text-xs mt-2 font-medium">Aplica una plantilla o arrastra segmentos aquí desde el panel izquierdo.</p>
                </div>

                <div v-else class="space-y-4 relative">
                  <div class="absolute left-[15px] top-4 bottom-4 w-0.5 bg-slate-200 z-0"></div>
                  <div v-for="(cotSeg, idx) in store.dataActiva.cotsegmentos" :key="cotSeg.id" class="relative z-10 flex gap-4 items-start group">
                    <div class="w-8 h-8 rounded-full bg-white border-4 border-indigo-100 text-indigo-600 flex items-center justify-center font-black text-xs shadow-sm flex-shrink-0 mt-1">{{ idx + 1 }}</div>
                    <div class="flex-1 bg-white border border-slate-200 shadow-sm rounded-2xl overflow-hidden">
                      <div class="bg-slate-50 px-4 py-2 border-b border-slate-100 flex justify-between items-center">
                        <div class="flex items-center gap-2 text-slate-400 w-full">
                          <i class="fas fa-grip-horizontal cursor-move hover:text-slate-600"></i>
                          <input v-model="store.getI18n(cotSeg.nombreSnapshot, store.cotizacion.idiomaEdicion).content" class="bg-transparent text-xs font-black text-slate-700 uppercase tracking-wide outline-none w-full focus:border-b focus:border-indigo-300" placeholder="Título del Párrafo" />
                        </div>
                        <button @click="store.removerCotSegmento(cotSeg.id)" class="text-slate-400 hover:text-red-500 transition-colors ml-4"><i class="fas fa-trash-alt"></i></button>
                      </div>
                      <div class="p-4">
                        <textarea v-model="store.getI18n(cotSeg.contenidoSnapshot, store.cotizacion.idiomaEdicion).content" rows="3" class="w-full bg-transparent text-sm text-slate-600 leading-relaxed outline-none resize-none focus:ring-1 focus:ring-indigo-100 rounded p-1" placeholder="Contenido narrativo..."></textarea>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </main>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.3s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.95); }
</style>