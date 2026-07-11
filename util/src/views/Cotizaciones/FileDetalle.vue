<script setup lang="ts">
import {ref, onMounted, onUnmounted, watch, computed} from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import { getUrls } from '@/services/apiClient';

import type { ApiPais } from '@/types/maestroModel';

import {
  getArchivoLabel, ARCHIVO_TIPO_LABELS,
  getSexoLabel, SEXO_LABELS,
  getDocIdLabel, DOCUMENTO_IDENTIDAD_LABELS,
  type ApiCotizacionFile,
  type ApiCotizacionFilepasajero,
  type ApiCotizacionVersion
} from '@/types/fileDetalleModel';

const linkCopiado = ref(false);

defineProps<{
  id?: string;
}>();

const route = useRoute();
const router = useRouter();
const fileStore = useCotizacionFileStore();

const isLoading = ref(true);
const file = ref<ApiCotizacionFile>({} as ApiCotizacionFile);
const isSavingFile = ref(false);

// ============================================================================
// 🔥 GUARDIÁN DE CAMBIOS SIN GUARDAR (DIRTY CHECK)
// ============================================================================
const isDirty = ref(false);
let watchActivo = false;

const linkPublico = computed(() => {
  if (!file.value?.localizador) return '';
  return `${getUrls().pax}/file/${file.value.localizador}`;
});

const copiarLink = async () => {
  if (!linkPublico.value) return;
  try {
    await navigator.clipboard.writeText(linkPublico.value);
    linkCopiado.value = true;
    setTimeout(() => { linkCopiado.value = false; }, 2000);
  } catch (e) {
    alert('No se pudo copiar. Copia manualmente: ' + linkPublico.value);
  }
};

const editandoVersion = ref<string | null>(null);
const versionTemp = ref<number>(1);
const eliminandoItem = ref<string | null>(null);

const iniciarEdicionVersion = (cot: ApiCotizacionVersion) => {
  editandoVersion.value = cot['@id'] || cot.id || '';
  versionTemp.value = cot.version;
};

const guardarVersion = async (cot: ApiCotizacionVersion) => {
  const iri = cot['@id'] || `/platform/sales/cotizacions/${extractIdStr(cot.id)}`;
  const success = await fileStore.updateCotizacionVersion(iri, versionTemp.value);
  if (success) {
    cot.version = versionTemp.value;
    editandoVersion.value = null;
  } else {
    alert(fileStore.error || 'Error al actualizar versión.');
  }
};

const eliminarVersion = async (cot: ApiCotizacionVersion) => {
  if (!confirm(`¿Eliminar la Versión ${cot.version}? Esta acción no se puede deshacer.`)) return;
  const iri = cot['@id'] || `/platform/sales/cotizacions/${extractIdStr(cot.id)}`;
  eliminandoItem.value = iri;
  const success = await fileStore.deleteCotizacion(iri);
  if (success) await cargarFile();
  else alert(fileStore.error || 'Error al eliminar la versión.');
  eliminandoItem.value = null;
};

const eliminarFile = async () => {
  if (!confirm(`¿Eliminar TODO el expediente "${file.value.nombreGrupo}"? Se borrarán también todas sus versiones, pasajeros y documentos. Esta acción no se puede deshacer.`)) return;
  const iri = file.value['@id'] || `/platform/sales/cotizacion_files/${extractIdStr(file.value.id)}`;
  const success = await fileStore.deleteFile(iri);
  if (success) {
    router.push('/cotizacion');
  } else {
    alert(fileStore.error || 'Error al eliminar el expediente.');
  }
};

const onBeforeUnload = (e: BeforeUnloadEvent) => {
  if (isDirty.value) {
    e.preventDefault();
    e.returnValue = '';
  }
};

onMounted(() => {
  window.addEventListener('beforeunload', onBeforeUnload);
  fetchCatalogos();
  cargarFile();
});

onUnmounted(() => {
  window.removeEventListener('beforeunload', onBeforeUnload);
});

// Vigila el formulario base. Si cambia, marcamos como sucio.
watch(() => file.value, () => {
  if (watchActivo) {
    isDirty.value = true;
  }
}, { deep: true });

onBeforeRouteLeave((to, from, next) => {
  if (isDirty.value) {
    const confirmacion = window.confirm('Tienes cambios sin guardar en los Datos del Expediente. ¿Estás seguro de que deseas salir y perder los cambios?');
    if (confirmacion) {
      next();
    } else {
      next(false);
    }
  } else {
    next();
  }
});

// ============================================================================
// CATÁLOGOS Y ENUMS
// ============================================================================
const catalogos = ref({
  paises: [] as ApiPais[],
});

const showPaxModal = ref(false);
const showDocModal = ref(false);
const isSubmittingPax = ref(false);
const isSubmittingDoc = ref(false);

const paxForm = ref({
  nombre: '', apellido: '', pais: '', sexo: '', tipodocumento: '', numerodocumento: '', fechanacimiento: ''
});

const docForm = ref({
  tipodocumento: '', vencimiento: '', fileObject: null as File | null
});

const extractIdStr = (val: any) => val ? String(val).split('/').pop() : '';

const fetchCatalogos = async () => {
  try {
    const paisesRes = await apiClient.get('/platform/maestro/paises?pagination=false');
    catalogos.value.paises = paisesRes.data['hydra:member'] || paisesRes.data['member'] || [];
  } catch (e) {
    console.error("Error cargando catálogos", e);
  }
};

const cargarFile = async () => {
  isLoading.value = true;
  watchActivo = false; // Apagamos el guardián mientras hidratamos para no disparar falsas alarmas
  try {
    const response = await apiClient.get(`/platform/sales/cotizacion_files/${route.params.id}`);
    file.value = response.data;
  } catch (error) {
    console.error("Error al cargar el File", error);
    router.push('/cotizacion');
  } finally {
    isLoading.value = false;
    // Encendemos el guardián con un ligero delay tras pintar la UI
    setTimeout(() => {
      watchActivo = true;
      isDirty.value = false;
    }, 100);
  }
};

const handleVolver = () => {
  router.push('/cotizacion');
};

const guardarFile = async () => {
  isSavingFile.value = true;

  // 1. Preparamos el payload con los campos que quieres actualizar
  const payload = {
    nombreGrupo: file.value.nombreGrupo,
    pasajeroPrincipal: file.value.pasajeroPrincipal,
    email: file.value.email,
    telefono: file.value.telefono,
    estado: file.value.estado
  };

  try {
    // 2. Usamos la acción del store que SÍ usa PATCH y el header correcto
    const iri = extractIdStr(file.value.id || file.value['@id']);
    const success = await fileStore.updateFile(`/platform/sales/cotizacion_files/${iri}`, payload);

    if (success) {
      isDirty.value = false;
      alert('Expediente actualizado correctamente.');
    } else {
      alert(fileStore.error || 'Error al guardar el expediente.');
    }
  } catch (error) {
    alert('Error de red al actualizar.');
  } finally {
    isSavingFile.value = false;
  }
};

const nuevaVersion = () => {
  router.push(`/cotizacion/${extractIdStr(file.value.id || file.value['@id'])}/version/nueva`);
};

const abrirMotor = (cotizacion: ApiCotizacionVersion) => {
  const fileId = extractIdStr(file.value.id || file.value['@id']);
  const cotId = extractIdStr(cotizacion.id || cotizacion['@id']);
  router.push(`/cotizacion/${fileId}/version/${cotId}`);
};

// ==========================================
// LÓGICA DE PASAJEROS
// ==========================================

const paxEditandoIri = ref<string | null>(null);
const abrirPaxModal = () => {
  paxEditandoIri.value = null; // modo creación
  paxForm.value = { nombre: '', apellido: '', pais: '', sexo: '', tipodocumento: '', numerodocumento: '', fechanacimiento: '' };
  showPaxModal.value = true;
};

const abrirEdicionPax = (pax: ApiCotizacionFilepasajero) => {
  paxEditandoIri.value = pax['@id'] || `/platform/sales/cotizacion_filepasajeros/${extractIdStr(pax.id)}`;
  paxForm.value = {
    nombre: pax.nombre || '',
    apellido: pax.apellido || '',
    pais: typeof pax.pais === 'object' && pax.pais ? (pax.pais['@id'] || pax.pais.id || '') : (pax.pais || ''),
    sexo: pax.sexo || '',
    tipodocumento: pax.tipodocumento || '',
    numerodocumento: pax.numerodocumento || '',
    fechanacimiento: pax.fechanacimiento ? pax.fechanacimiento.split('T')[0] : ''
  };
  showPaxModal.value = true;
};

const guardarPasajero = async () => {
  isSubmittingPax.value = true;

  let success: boolean;

  if (paxEditandoIri.value) {
    // Modo edición
    success = await fileStore.updatePassenger(paxEditandoIri.value, paxForm.value);
  } else {
    // Modo creación (igual que antes)
    const payload = {
      ...paxForm.value,
      file: `/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`
    };
    success = await fileStore.addPassenger(payload);
  }

  if (success) {
    showPaxModal.value = false;
    paxEditandoIri.value = null;
    await cargarFile();
  } else {
    alert(fileStore.error || (paxEditandoIri.value ? 'Error al actualizar pasajero' : 'Error al registrar pasajero'));
  }
  isSubmittingPax.value = false;
};

const eliminarPasajero = async (iri?: string): Promise<void> => {
  if (!iri) return;
  if (!confirm('¿Eliminar pasajero?')) return;
  const success = await fileStore.deletePassenger(iri);
  if (success) await cargarFile();
  else alert("Error al eliminar pasajero");
};

// ==========================================
// LÓGICA DE DOCUMENTOS
// ==========================================

const docEditandoIri = ref<string | null>(null);

const abrirDocModal = () => {
  docEditandoIri.value = null; // modo creación
  docForm.value = { tipodocumento: '', vencimiento: '', fileObject: null };
  showDocModal.value = true;
};

const abrirEdicionDoc = (doc: any) => {
  docEditandoIri.value = doc['@id'] || `/platform/sales/cotizacion_filedocumentos/${extractIdStr(doc.id)}`;
  docForm.value = {
    tipodocumento: doc.tipodocumento || '',
    vencimiento: doc.vencimiento ? doc.vencimiento.split('T')[0] : '',
    fileObject: null
  };
  showDocModal.value = true;
};

const handleFileUpload = (e: Event) => {
  const target = e.target as HTMLInputElement;
  if (target.files && target.files[0]) docForm.value.fileObject = target.files[0];
};

const guardarDocumento = async () => {
  let success: boolean;

  if (docEditandoIri.value) {
    // Modo edición: solo metadata, sin archivo
    isSubmittingDoc.value = true;
    success = await fileStore.updateDocument(docEditandoIri.value, {
      tipodocumento: docForm.value.tipodocumento,
      vencimiento: docForm.value.vencimiento || null
    });
  } else {
    // Modo creación: igual que antes, exige archivo
    if (!docForm.value.fileObject || !docForm.value.tipodocumento) {
      alert("Faltan datos o el archivo");
      return;
    }
    isSubmittingDoc.value = true;
    const formData = new FormData();
    formData.append('documento', docForm.value.fileObject);
    formData.append('tipodocumento', docForm.value.tipodocumento);
    formData.append('file', `/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`);
    if (docForm.value.vencimiento) formData.append('vencimiento', docForm.value.vencimiento);
    success = await fileStore.uploadDocument(formData);
  }

  if (success) {
    showDocModal.value = false;
    docEditandoIri.value = null;
    await cargarFile();
  } else {
    alert(fileStore.error || (docEditandoIri.value ? 'Error al actualizar documento' : 'Error al subir el documento'));
  }
  isSubmittingDoc.value = false;
};

const eliminarDocumento = async (iri?: string) => {
  if (!iri) return;
  if(!confirm('¿Eliminar este documento de la bóveda?')) return;
  const success = await fileStore.deleteDocument(iri);
  if (success) await cargarFile();
  else alert("Error al eliminar documento");
};
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden">

    <header class="flex-shrink-0 bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between z-30 shadow-sm">
      <div class="flex items-center gap-4">
        <button @click="handleVolver" class="w-10 h-10 flex items-center justify-center bg-slate-50 hover:bg-slate-100 rounded-xl text-slate-500 transition-colors">
          <i class="fas fa-arrow-left"></i>
        </button>
        <div>
          <h1 class="font-black text-2xl text-slate-800 tracking-tight leading-none mb-1">Detalle del Expediente</h1>
          <div class="flex items-center gap-2">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">{{ file?.localizador || 'Sin Localizador' }}</p>
            <button
                v-if="file?.localizador"
                @click="copiarLink"
                class="text-[10px] font-bold px-2 py-0.5 rounded-md border transition-colors flex items-center gap-1"
                :class="linkCopiado ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-slate-100'"
            >
              <i :class="linkCopiado ? 'fas fa-check' : 'far fa-copy'"></i>
              {{ linkCopiado ? 'Copiado' : 'Copiar link' }}
            </button>
          </div>
        </div>
      </div>
      <button @click="eliminarFile" class="text-[10px] font-bold px-2 py-0.5 rounded-md border border-red-200 text-red-500 hover:bg-red-50 transition-colors flex items-center gap-1">
        <i class="fas fa-trash-alt"></i> Eliminar Expediente
      </button>
      <button @click="nuevaVersion" class="px-5 py-2.5 bg-[#376875] hover:bg-[#2d5662] text-white font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
        <i class="fas fa-rocket"></i> <span class="hidden md:inline">Crear Nueva Versión</span>
      </button>
    </header>

    <main v-if="isLoading" class="flex-1 flex justify-center items-center">
      <i class="fas fa-spinner fa-spin text-4xl text-slate-300"></i>
    </main>

    <main v-else class="flex-1 overflow-y-auto p-6 md:p-8">
      <div class="max-w-6xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-8 items-start pb-20">

        <aside class="md:col-span-1 space-y-6 md:sticky md:top-0">

          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-5 border-b pb-3"><i class="fas fa-folder-open mr-2 text-[#E07845]"></i> Datos del Expediente</h2>
            <form @submit.prevent="guardarFile" class="space-y-4">
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nombre Grupo</label>
                <input v-model="file.nombreGrupo" type="text" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Titular</label>
                <input v-model="file.pasajeroPrincipal" type="text" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Email</label>
                <input v-model="file.email" type="email" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Estado</label>
                <select v-model="file.estado" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
                  <option value="abierto">Abierto</option>
                  <option value="cerrado">Cerrado (Ganado)</option>
                  <option value="perdido">Perdido</option>
                </select>
              </div>
              <button type="submit" :disabled="isSavingFile" class="w-full py-3 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl shadow mt-2">
                <i v-if="isSavingFile" class="fas fa-spinner fa-spin mr-1"></i> Guardar Cambios
              </button>
            </form>
          </div>

          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-4 border-b pb-3">
              <h2 class="text-xs font-black text-slate-800 uppercase tracking-widest"><i class="fas fa-folder-open mr-1 text-sky-500"></i> Bóveda Digital</h2>
              <button @click="abrirDocModal" class="bg-sky-100 text-sky-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-sky-200">+ Subir Doc</button>
            </div>

            <div v-for="doc in file.filedocumentos" :key="doc.id" class="flex items-center gap-3 p-2 bg-slate-50 rounded-xl border border-slate-200 group relative">
              <a :href="doc.imageUrl || undefined" target="_blank" class="flex-1 flex items-center gap-3 min-w-0">
                <div class="w-8 h-8 rounded bg-sky-100 text-sky-600 flex items-center justify-center text-sm flex-shrink-0"><i class="far fa-file-pdf"></i></div>
                <div class="min-w-0">
                  <p class="text-[10px] font-black text-slate-800 truncate">{{ getArchivoLabel(doc.tipodocumento) }}</p>
                  <p class="text-[8px] font-bold text-slate-500 uppercase mt-0.5" :class="doc.vencimiento && new Date(doc.vencimiento) < new Date() ? 'text-red-500' : ''">
                    <span v-if="doc.vencimiento">Vence: {{ new Date(doc.vencimiento).toLocaleDateString() }}</span>
                    <span v-else>Permanente</span>
                  </p>
                </div>
              </a>
              <button @click="abrirEdicionDoc(doc)" class="w-6 h-6 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200 flex items-center justify-center transition-colors">
                <i class="fas fa-pencil-alt text-xs"></i>
              </button>
              <button @click="eliminarDocumento(doc['@id'])" class="w-6 h-6 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-red-500 hover:border-red-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times text-xs"></i>
              </button>
            </div>
          </div>
        </aside>

        <section class="md:col-span-2 space-y-8">

          <div>
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest"><i class="fas fa-users mr-2 text-indigo-500"></i> Manifiesto de Pasajeros</h2>
              <button @click="abrirPaxModal" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700 shadow-sm">+ Añadir Pax</button>
            </div>

            <div v-if="!file.filepasajeros?.length" class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-3xl p-8 text-center text-indigo-400">
              <i class="fas fa-user-plus text-3xl mb-3 opacity-50"></i>
              <p class="text-xs font-bold uppercase tracking-widest">Sin pasajeros registrados</p>
            </div>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div v-for="(pax, idx) in file.filepasajeros" :key="pax.id" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm relative group">
                <div class="absolute top-3 right-3 flex items-center gap-1">
                  <button @click="abrirEdicionPax(pax)" class="text-slate-300 hover:text-indigo-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                    <i class="fas fa-pencil-alt text-xs"></i>
                  </button>
                  <button @click="eliminarPasajero(pax['@id'])" class="text-slate-300 hover:text-red-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                    <i class="fas fa-trash-alt text-xs"></i>
                  </button>
                </div>
                <div class="flex items-start gap-3 pr-16">
                  <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 font-black text-xs flex items-center justify-center border border-indigo-200">{{ idx + 1 }}</div>
                  <div>
                    <h3 class="text-sm font-black text-slate-800 leading-tight">{{ pax.nombre }} {{ pax.apellido }}</h3>
                    <div class="flex flex-wrap gap-1 mt-2">
                      <span class="text-[9px] font-bold bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border border-slate-200 uppercase">{{ pax.tipopaxperurail === 1 ? 'Adulto' : 'Niño' }} PR</span>
                      <span v-if="pax.edad" class="text-[9px] font-bold bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded border border-indigo-100 uppercase">{{ pax.edad }} Años</span>
                    </div>
                    <p class="text-[9px] text-slate-400 font-bold uppercase mt-2">
                      <i class="fas fa-globe-americas"></i> {{ pax.pais?.nombre }} ({{ getSexoLabel(pax.sexo) }})<br>
                      <i class="far fa-id-card mt-1"></i> {{ getDocIdLabel(pax.tipodocumento) }}: {{ pax.numerodocumento }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div>
            <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4"><i class="fas fa-code-branch mr-2 text-[#E07845]"></i> Historial de Versiones</h2>

            <div v-if="!file.cotizaciones || file.cotizaciones.length === 0" class="bg-white border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400">
              <i class="fas fa-clipboard-list text-4xl mb-4 opacity-50"></i>
              <p class="text-sm font-bold uppercase tracking-widest">No hay cotizaciones</p>
              <p class="text-xs mt-2 font-medium">Haz clic en "Crear Nueva Versión" para arrancar el motor operativo.</p>
            </div>

            <div v-else v-for="cot in file.cotizaciones" :key="cot.id" class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:border-[#376875] transition-colors group mb-3">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-4 flex-1 min-w-0">
                  <!-- Badge de versión, editable -->
                  <div v-if="editandoVersion !== (cot['@id'] || cot.id)"
                       @click="iniciarEdicionVersion(cot)"
                       class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-black text-slate-700 text-lg border-2 border-white shadow-sm group-hover:bg-[#376875] group-hover:text-white transition-colors cursor-pointer flex-shrink-0"
                       title="Click para editar versión">
                    V{{ cot.version }}
                  </div>
                  <div v-else class="flex items-center gap-1 flex-shrink-0">
                    <input v-model.number="versionTemp" type="number" min="1"
                           class="w-14 h-12 text-center font-black rounded-full border-2 border-[#376875] outline-none"
                           @keyup.enter="guardarVersion(cot)" @keyup.esc="editandoVersion = null">
                    <button @click="guardarVersion(cot)" class="text-emerald-600 w-8 h-8 flex items-center justify-center"><i class="fas fa-check"></i></button>
                    <button @click="editandoVersion = null" class="text-slate-400 w-8 h-8 flex items-center justify-center"><i class="fas fa-times"></i></button>
                  </div>

                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-black text-slate-800">{{ cot.estado || 'Pendiente' }}</p>
                    <div class="flex flex-wrap items-center gap-3 text-[10px] font-bold text-slate-400 uppercase mt-1">
                      <span><i class="fas fa-users"></i> {{ cot.numPax ?? '—' }} Pax</span>
                      <span><i class="fas fa-money-bill"></i> Venta: {{ cot.monedaGlobal }} {{ cot.totalVenta ?? '0.00' }}</span>
                      <span class="text-emerald-600"><i class="fas fa-chart-line"></i> Ganancia: {{ cot.monedaGlobal }} {{ cot.ganancia ?? '0.00' }}</span>
                    </div>
                    <p v-if="cot.resumen" class="text-[10px] text-slate-400 font-medium mt-1 truncate">
                      {{ fileStore.extraerResumenPreview(cot.resumen) }}
                    </p>
                  </div>
                </div>

                <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                  <button @click="abrirMotor(cot)" class="px-4 py-2 bg-[#E07845] text-white text-xs font-bold rounded-lg shadow hover:bg-[#c96636] transition-colors">
                    Abrir Motor <i class="fas fa-arrow-right ml-1"></i>
                  </button>
                  <button @click="eliminarVersion(cot)" :disabled="eliminandoItem === (cot['@id'] || cot.id)"
                          class="w-9 h-9 flex items-center justify-center rounded-lg border border-slate-200 text-slate-300 hover:text-red-500 hover:border-red-200 transition-colors disabled:opacity-50">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="eliminandoItem === (cot['@id'] || cot.id)"></i>
                    <i class="fas fa-trash-alt text-xs" v-else></i>
                  </button>
                </div>
              </div>
            </div>

          </div>

        </section>
      </div>
    </main>
  </div>

  <Teleport to="body">
    <div v-if="showPaxModal" class="fixed inset-0 z-[1000] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden">
        <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white">
          <h3 class="font-black text-sm uppercase tracking-widest">Nuevo Pasajero</h3>
          <button @click="showPaxModal = false" class="text-indigo-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarPasajero" class="p-6 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nombres *</label>
              <input v-model="paxForm.nombre" required type="text" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Apellidos *</label>
              <input v-model="paxForm.apellido" required type="text" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
            </div>
            <div class="col-span-2">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nacionalidad *</label>
              <select v-model="paxForm.pais" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <option v-for="p in catalogos.paises" :key="p['@id']" :value="p['@id']">{{ p.nombre }}</option>
              </select>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Tipo Doc *</label>
              <select v-model="paxForm.tipodocumento" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <option v-for="(label, valor) in DOCUMENTO_IDENTIDAD_LABELS" :key="valor" :value="valor">{{ label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">N° Documento</label>
              <input v-model="paxForm.numerodocumento" type="text" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nacimiento</label>
              <input v-model="paxForm.fechanacimiento" type="date" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Sexo *</label>
              <select v-model="paxForm.sexo" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <option v-for="(label, valor) in SEXO_LABELS" :key="valor" :value="valor">{{ label }}</option>
              </select>
            </div>
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="showPaxModal = false" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="isSubmittingPax" class="px-5 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-indigo-700 flex items-center gap-2">
              <i v-if="isSubmittingPax" class="fas fa-spinner fa-spin"></i> Guardar Pasajero
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>

  <Teleport to="body">
    <div v-if="showDocModal" class="fixed inset-0 z-[1000] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">

        <div class="bg-sky-600 px-6 py-4 flex justify-between items-center text-white">
          <h3 class="font-black text-sm uppercase tracking-widest">
            <i class="fas fa-upload mr-2" v-if="!docEditandoIri"></i>
            <i class="fas fa-pencil-alt mr-2" v-else></i>
            {{ docEditandoIri ? 'Editar Documento' : 'Subir a Bóveda' }}
          </h3>
          <button @click="showDocModal = false; docEditandoIri = null" class="text-sky-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarDocumento" class="p-6 space-y-4">
          <div v-if="!docEditandoIri">
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Archivo (PDF / Img) *</label>
            <input type="file" @change="handleFileUpload" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100">
          </div>
          <div v-else class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-[10px] font-bold text-slate-500 flex items-center gap-2">
            <i class="fas fa-info-circle"></i> El archivo no se puede reemplazar aquí. Elimina y sube uno nuevo si necesitas cambiarlo.
          </div>

          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Categoría del Doc *</label>
            <select v-model="docForm.tipodocumento" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
              <option v-for="(label, valor) in ARCHIVO_TIPO_LABELS" :key="valor" :value="valor">{{ label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Vencimiento (Opcional)</label>
            <input v-model="docForm.vencimiento" type="date" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
            <p class="text-[9px] text-slate-400 mt-1">Útil para alertar sobre Pasaportes o Visas vencidas.</p>
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="showDocModal = false; docEditandoIri = null" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="isSubmittingDoc" class="px-5 py-2 bg-sky-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-sky-700 flex items-center gap-2">
              <i v-if="isSubmittingDoc" class="fas fa-spinner fa-spin"></i>
              {{ docEditandoIri ? 'Guardar Cambios' : 'Subir Documento' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>

</template>