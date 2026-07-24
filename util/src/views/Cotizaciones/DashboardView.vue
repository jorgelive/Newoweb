<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import { useMaestroStore } from '@/stores/maestroStore';
import type { ApiCotizacionFile } from '@/types/fileDetalleModel';

/**
 * Normaliza y formatea la fecha provista a estándar regional PE.
 * @param {string | undefined} dateStr Timestamp en formato ISO 8601.
 * @returns {string} Fecha procesada visualmente (ej: '02 may. 2026').
 */
const formatDate = (dateStr?: string): string => {
  if (!dateStr) return 'N/A';
  return new Date(dateStr).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' });
};

/**
 * Formatea una fecha "date-only" (YYYY-MM-DD, sin hora) sin sufrir el
 * corrimiento de un día que produce `new Date('YYYY-MM-DD')` en zonas
 * horarias negativas (parsea como UTC medianoche).
 */
const formatFechaInicio = (dateStr: string | null): string => {
  if (!dateStr) return 'S/F';
  const [y, m, d] = dateStr.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' });
};

const router = useRouter();
const fileStore = useCotizacionFileStore();
const maestroStore = useMaestroStore();

// ============================================================================
// BUSCADOR POR NOMBRE (grupo o pasajero principal)
// ============================================================================
const searchInput = ref<string>('');
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

const onSearchInput = (): void => {
  if (searchDebounce) clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    fileStore.setSearchTerm(searchInput.value);
  }, 400);
};

onBeforeUnmount(() => {
  if (searchDebounce) clearTimeout(searchDebounce);
});

// ============================================================================
// ORDENAMIENTO: fecha de cotización vs. fecha de primer servicio (más próxima)
// ============================================================================
type SortKey = 'createdAt' | 'fechaInicio';
const sortKey = ref<SortKey>('createdAt');
const sortDir = ref<'asc' | 'desc'>('desc');

/** Fecha de primer servicio más próxima entre todas las versiones del file (o null si ninguna tiene). */
const primeraFechaServicio = (file: ApiCotizacionFile): string | null => {
  const fechas = (file.versionesFechas || [])
    .map(v => v.fechaInicio)
    .filter((f): f is string => !!f)
    .sort();
  return fechas[0] ?? null;
};

const sortedFiles = computed(() => {
  const lista = [...fileStore.files];
  const dir = sortDir.value === 'asc' ? 1 : -1;

  return lista.sort((a, b) => {
    const va = sortKey.value === 'createdAt' ? (a.createdAt || '') : (primeraFechaServicio(a) || '');
    const vb = sortKey.value === 'createdAt' ? (b.createdAt || '') : (primeraFechaServicio(b) || '');
    // Los que no tienen fecha de servicio siempre van al final, sin importar la dirección.
    if (sortKey.value === 'fechaInicio' && (!va || !vb)) {
      if (!va && !vb) return 0;
      return !va ? 1 : -1;
    }
    return va < vb ? -1 * dir : va > vb ? 1 * dir : 0;
  });
});

// ============================================================================
// ESTADO LOCAL DE LA INTERFAZ
// ============================================================================
const showCreateModal = ref<boolean>(false);

// Payload reactivo del formulario, utilizando IDs crudos para la interacción UI
const newFile = ref({
  nombreGrupo: '',
  pasajeroPrincipal: '',
  email: '',
  telefono: '',
  paisId: 'PE', // Set default to Perú
  idiomaId: 'es' // Set default to Español
});

// ============================================================================
// CICLO DE VIDA
// ============================================================================
onMounted(() => {
  fileStore.fetchFiles(1);
  maestroStore.fetchMaestros();
});

// ============================================================================
// MANEJADORES DE EVENTOS
// ============================================================================

/**
 * Valida, formatea y procesa la solicitud de creación de un nuevo Expediente.
 * Se encarga de ensamblar los IRIs necesarios para relaciones ManyToOne.
 * Al resolver satisfactoriamente, redirige automáticamente al Motor de Edición.
 *
 * @returns {Promise<void>}
 */
const handleCreate = async (): Promise<void> => {
  if (!newFile.value.nombreGrupo) return;

  // 🔥 FIX: Búsqueda dinámica del IRI real.
  // Extraemos el '@id' directamente del catálogo descargado para no tener que adivinar
  // las rutas pluralizadas de API Platform (ej. /paises en vez de /maestro_pais)
  const paisObj = maestroStore.paises.find((p: any) => p.id === newFile.value.paisId || p['@id'] === newFile.value.paisId);
  const idiomaObj = maestroStore.idiomas.find((i: any) => i.id === newFile.value.idiomaId || i['@id'] === newFile.value.idiomaId);

  // Ignoramos tipado estricto si OpenAPI no ha sido indexado completamente en el IDE local
  // @ts-ignore
  const result = await fileStore.createFile({
    nombreGrupo: newFile.value.nombreGrupo,
    pasajeroPrincipal: newFile.value.pasajeroPrincipal || null,
    email: newFile.value.email || null,
    telefono: newFile.value.telefono || null,
    estado: 'abierto',
    // Composición de IRI para API Platform basadas en la estructura de endpoints
    // Inyectamos el IRI exacto que demanda API Platform
    pais: paisObj ? paisObj['@id'] : null,
    idioma: idiomaObj ? idiomaObj['@id'] : null
  });

  if (result && (result.id || result['@id'])) {
    // Cierre de interfaz modal y purga de estado temporal
    showCreateModal.value = false;
    newFile.value = { nombreGrupo: '', pasajeroPrincipal: '', email: '', telefono: '', paisId: 'PE', idiomaId: 'es' };

    // Extracción segura del ID en presencia del estándar Hydra
    const safeId = result.id || (result['@id'] as string).split('/').pop();
    router.push(`/cotizacion/${safeId}`);
  }
};

/**
 * Gestiona el Infinite Scroll / Paginación invocando la página siguiente de Expedientes.
 *
 * @returns {void}
 */
const loadMore = (): void => {
  if (fileStore.hasNextPage && !fileStore.loadingMore) {
    fileStore.fetchFiles(fileStore.currentPage + 1, true);
  }
};
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex flex-col font-sans">

    <!-- CABECERA -->
    <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between sticky top-0 z-30">
      <div class="flex items-center gap-4">
        <RouterLink to="/" class="w-10 h-10 flex items-center justify-center bg-slate-50 hover:bg-slate-100 rounded-xl text-slate-500 transition-colors">
          <i class="fas fa-arrow-left"></i>
        </RouterLink>
        <div>
          <h1 class="font-black text-2xl text-slate-800 tracking-tight leading-none mb-1">Cotizaciones</h1>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Dashboard de Expedientes</p>
        </div>
      </div>
      <button @click="showCreateModal = true" class="px-5 py-2.5 bg-[#E07845] hover:bg-[#c96636] text-white font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
        <i class="fas fa-plus"></i> <span class="hidden sm:inline">Nuevo File</span>
      </button>
    </header>

    <!-- ÁREA PRINCIPAL -->
    <main class="flex-1 p-6 md:p-8 max-w-7xl mx-auto w-full">

      <!-- ALERTAS GLOBALES -->
      <div v-if="fileStore.error" class="mb-6 bg-red-50 text-red-600 border border-red-200 p-4 rounded-2xl flex items-center gap-3 font-bold text-sm shadow-sm">
        <i class="fas fa-exclamation-triangle text-xl"></i> {{ fileStore.error }}
      </div>

      <!-- BUSCADOR Y ORDENAMIENTO -->
      <div class="mb-6 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <div class="relative flex-1 max-w-sm">
          <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
          <input v-model="searchInput" @input="onSearchInput" type="text"
                 placeholder="Buscar por grupo o pasajero..."
                 class="w-full bg-white border border-slate-200 rounded-xl pl-10 pr-4 py-2.5 text-sm font-medium outline-none focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] shadow-sm">
        </div>
        <div class="flex items-center gap-2">
          <select v-model="sortKey" class="bg-white border border-slate-200 rounded-xl px-3 py-2.5 text-xs font-bold text-slate-600 outline-none focus:ring-2 focus:ring-[#376875]/30 shadow-sm">
            <option value="createdAt">Ordenar por fecha de cotización</option>
            <option value="fechaInicio">Ordenar por fecha de primer servicio</option>
          </select>
          <button @click="sortDir = sortDir === 'asc' ? 'desc' : 'asc'"
                  class="w-9 h-9 flex items-center justify-center bg-white hover:bg-slate-50 border border-slate-200 rounded-xl text-slate-500 transition-colors shadow-sm"
                  :title="sortDir === 'asc' ? 'Ascendente' : 'Descendente'">
            <i class="fas" :class="sortDir === 'asc' ? 'fa-arrow-up-wide-short' : 'fa-arrow-down-wide-short'"></i>
          </button>
        </div>
      </div>

      <!-- ESTADO DE CARGA INICIAL -->
      <div v-if="fileStore.loadingFiles && fileStore.files.length === 0" class="flex flex-col items-center justify-center py-20 text-slate-300">
        <i class="fas fa-circle-notch fa-spin text-4xl mb-4"></i>
        <span class="font-bold uppercase tracking-widest text-sm">Cargando expedientes...</span>
      </div>

      <!-- SIN RESULTADOS DE BÚSQUEDA -->
      <div v-else-if="!sortedFiles.length" class="text-center py-20">
        <i class="fas fa-folder-open text-5xl text-slate-300 mb-4"></i>
        <h2 class="text-xl font-black text-slate-600">Sin resultados</h2>
        <p class="text-sm text-slate-400 font-medium mt-1">No hay expedientes que coincidan con "{{ searchInput }}".</p>
      </div>

      <!-- GRILLA DE RESULTADOS / EXPEDIENTES -->
      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="(file, index) in sortedFiles" :key="file.id ?? index"
             class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm hover:shadow-xl transition-all cursor-pointer group flex flex-col relative overflow-hidden"
             @click="router.push(`/cotizacion/${file.id || (file['@id']?.split('/').pop())}`)">

          <div class="flex justify-between items-start mb-4">
                    <span class="px-3 py-1 bg-slate-100 text-[#E07845] font-black text-xs rounded-lg tracking-widest border border-slate-200 uppercase">
                        {{ file.localizador || 'S/C' }}
                    </span>
            <span class="w-8 h-8 rounded-full flex items-center justify-center bg-green-50 text-green-600 border border-green-100" title="Estado Abierto">
                        <i class="fas fa-folder-open text-xs"></i>
                    </span>
          </div>

          <h3 class="font-black text-xl text-slate-800 mb-1 group-hover:text-[#376875] transition-colors leading-tight">
            {{ file.nombreGrupo }}
          </h3>

          <!-- Detalles incrustados de país y pasajero -->
          <p class="text-sm font-medium text-slate-500 flex items-center gap-2 mt-1">
                    <span v-if="file.pais" class="flex items-center gap-1.5" :title="file.pais.nombre">
                        {{ file.pais.bandera ?? '🏳️' }}
                    </span>
            <i class="fas fa-user-tie opacity-50 ml-1"></i> {{ file.pasajeroPrincipal || 'Pasajero principal sin asignar' }}
          </p>

          <!-- Fechas de primer servicio por versión -->
          <div v-if="file.versionesFechas?.length" class="flex flex-wrap gap-1.5 mt-3">
            <span v-for="v in file.versionesFechas" :key="v.version"
                  class="px-2 py-1 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-bold text-slate-500">
              V{{ v.version }}: {{ formatFechaInicio(v.fechaInicio) }}
            </span>
          </div>

          <!-- Área inferior de la tarjeta -->
          <div class="mt-8 pt-4 border-t border-slate-100 flex justify-between items-center text-xs font-bold text-slate-400">
            <span class="flex items-center gap-1"><i class="far fa-calendar-alt"></i> {{ formatDate(file.createdAt) }}</span>
            <span class="flex items-center gap-1 text-[#376875] opacity-0 group-hover:opacity-100 transition-opacity">
                      Abrir Motor <i class="fas fa-chevron-right ml-1"></i>
                  </span>
          </div>
        </div>
      </div>

      <!-- CONTROL DE PAGINACIÓN -->
      <div v-if="fileStore.hasNextPage && !fileStore.loadingFiles" class="mt-8 flex justify-center">
        <button @click="loadMore" :disabled="fileStore.loadingMore" class="px-6 py-2.5 bg-white border-2 border-slate-200 hover:border-slate-300 text-slate-600 font-bold rounded-xl transition-colors flex items-center gap-2 shadow-sm">
          <i v-if="fileStore.loadingMore" class="fas fa-circle-notch fa-spin"></i>
          <span>Cargar expedientes antiguos</span>
        </button>
      </div>

    </main>

    <!-- INTERFAZ MODAL: NUEVO FILE -->
    <div v-if="showCreateModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
      <div class="bg-white rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-fade-in border border-slate-200">

        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <h3 class="font-black text-slate-800 text-lg flex items-center gap-2">
            <i class="fas fa-file-invoice text-[#E07845]"></i> Aperturar Expediente Comercial
          </h3>
          <button @click="showCreateModal = false" class="text-slate-400 hover:text-red-500 w-8 h-8 flex items-center justify-center rounded-full hover:bg-red-50 transition-colors">
            <i class="fas fa-times text-lg"></i>
          </button>
        </div>

        <form @submit.prevent="handleCreate" class="p-6 space-y-5">

          <!-- Nomenclatura del File -->
          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Nombre del Grupo / Agencia</label>
            <input v-model="newFile.nombreGrupo" type="text" required placeholder="Ej: Familia Pérez - Vacaciones 2026" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] focus:bg-white transition-colors font-medium">
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Pasajero Principal (Titular / Contacto)</label>
            <div class="relative">
              <i class="fas fa-user-circle absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
              <input v-model="newFile.pasajeroPrincipal" type="text" placeholder="Ej: Juan Pérez" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] focus:bg-white transition-colors font-medium">
            </div>
          </div>

          <!-- Contacto -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Email corporativo o personal</label>
              <div class="relative">
                <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input v-model="newFile.email" type="email" placeholder="correo@ejemplo.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] focus:bg-white transition-colors font-medium">
              </div>
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">WhatsApp / Teléfono</label>
              <div class="relative">
                <i class="fas fa-phone-alt absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input v-model="newFile.telefono" type="tel" placeholder="+51 987 654 321" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] focus:bg-white transition-colors font-medium">
              </div>
            </div>
          </div>

          <!-- Ubicación y Regionalización -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5 border-t border-slate-100 pt-5">
            <div>
              <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
                <i class="fas fa-globe-americas opacity-70"></i> País de Origen
              </label>
              <div class="relative">
                <select v-model="newFile.paisId" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] focus:bg-white transition-colors font-medium appearance-none">
                  <option value="" disabled>Seleccione origen...</option>
                  <option v-for="pais in maestroStore.paises" :key="pais.id || pais['@id']" :value="pais.id">
                    {{ pais.bandera || '🏳️' }} {{ pais.nombre }}
                  </option>
                </select>
                <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
              </div>
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
                <i class="fas fa-language opacity-70"></i> Idioma Base
              </label>
              <div class="relative">
                <select v-model="newFile.idiomaId" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#376875]/30 focus:border-[#376875] focus:bg-white transition-colors font-medium appearance-none">
                  <option value="" disabled>Idioma del prospecto...</option>
                  <option v-for="idioma in maestroStore.idiomas" :key="idioma.id || idioma['@id']" :value="idioma.id">
                    {{ idioma.bandera || '🏳️' }} {{ idioma.nombre }}
                  </option>
                </select>
                <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
              </div>
            </div>
          </div>

          <!-- Acciones del Modal -->
          <div class="pt-6 flex gap-4">
            <button type="button" @click="showCreateModal = false" class="w-1/3 py-3.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 font-bold rounded-xl transition-colors shadow-sm">
              Cancelar
            </button>
            <button type="submit" :disabled="fileStore.loadingFiles" class="flex-1 py-3.5 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded-xl shadow-xl hover:shadow-slate-900/20 transition-all flex items-center justify-center gap-2 group">
              <i v-if="fileStore.loadingFiles" class="fas fa-circle-notch fa-spin"></i>
              <template v-else>
                Crear y Abrir Motor <i class="fas fa-arrow-right opacity-50 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
              </template>
            </button>
          </div>
        </form>
      </div>
    </div>

  </div>
</template>

<style scoped>
.animate-fade-in {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(15px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Ocultar scrollbar en elementos internos si es necesario */
.scrollbar-hide::-webkit-scrollbar {
  display: none;
}
.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>