<script setup lang="ts">
import {ref, onMounted, onUnmounted, watch, computed} from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import MaskedDateInput from '@/components/MaskedDateInput.vue';   // ajusta ruta
import SearchableSelect from '@/components/SearchableSelect.vue';
import { apiClient } from '@/services/apiClient';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import { getUrls } from '@/services/apiClient';
import { ESTADO_FILE_LABELS } from '@/types/cotizacionEditorModel';

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

// ============================================================================
// CATÁLOGOS Y ENUMS
// ============================================================================
const catalogos = ref({
  paises: [] as ApiPais[],
});

// País como opciones {value,label} para el buscador
const paisOptions = computed(() =>
    catalogos.value.paises
        .filter(p => (p['@id'] || p.id) && p.nombre)
        .map(p => ({ value: (p['@id'] ?? p.id) as string, label: p.nombre as string }))
);

// ============================================================================
// IDIOMAS (revisión de traducciones AutoTranslate)
// ============================================================================
const idiomaActivo = ref('es');
const idiomaDocDropdown = ref(false);
const idiomaFileDropdown = ref(false);
const idiomasDisponibles = computed(() => fileStore.idiomasDisponibles);

// ============================================================================
// TELÉFONO — máscara de display
// ============================================================================
const telefonoFocused = ref(false);

const formatearTelefonoDisplay = (val?: string | null): string => {
  if (!val) return '';
  const v = val.trim();
  // Si ya tiene espacios o + viene formateado del backend, mostrar tal cual
  if (v.includes(' ') || v.startsWith('+')) return v;
  const d = v.replace(/\D/g, '');
  // Perú: 51XXXXXXXXX (11 dígitos) → +51 XXX XXX XXX
  if (d.startsWith('51') && d.length === 11) return `+51 ${d.slice(2, 5)} ${d.slice(5, 8)} ${d.slice(8)}`;
  // Celular peruano 9 dígitos → +51 XXX XXX XXX
  if (d.length === 9 && d.startsWith('9')) return `+51 ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}`;
  return v;
};

// ============================================================================
// PAÍS DEL EXPEDIENTE
// ============================================================================
const paisFileIri = ref('');

// ============================================================================
// LINKS VISTA CLIENTE  (pax + /file/ + localizador [+ /v/N])
// ============================================================================
const linkPublico = computed(() => {
  if (!file.value?.localizador) return '';
  return `${getUrls().pax}/file/${file.value.localizador}`;
});

const linkPublicoVersion = (version?: number) => {
  if (!file.value?.localizador) return '';
  const base = `${getUrls().pax}/file/${file.value.localizador}`;
  return version ? `${base}/v/${version}` : base;
};

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

// ============================================================================
// HELPER NOMBRE DOCUMENTO  (formato AutoTranslate: [{content, language}])
// ============================================================================
const getDocNombre = (doc: any, lang = idiomaActivo.value): string => {
  if (!doc?.nombre) return '';
  if (Array.isArray(doc.nombre)) {
    return doc.nombre.find((n: any) => n.language === lang)?.content
        || doc.nombre.find((n: any) => n.language === 'es')?.content
        || doc.nombre[0]?.content
        || '';
  }
  return typeof doc.nombre === 'object' ? (doc.nombre[lang] || doc.nombre.es || '') : String(doc.nombre);
};

const editandoVersion = ref<string | null>(null);
const versionTemp = ref<number>(1);
const eliminandoItem = ref<string | null>(null);
const clonandoItem = ref<string | null>(null);

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

/**
 * Clona una versión existente delegando la llamada al store.
 * Al completarse, refresca la vista del expediente para mostrar la nueva tarjeta.
 */
const clonarVersion = async (cot: ApiCotizacionVersion) => {
  const idStr = extractIdStr(cot.id || cot['@id']);

  if (!idStr) {
    console.error('No se encontró el ID de la cotización');
    return;
  }

  if (!confirm(`¿Estás seguro de duplicar la Versión ${cot.version}?\nSe creará una copia idéntica y segura con una nueva versión.`)) return;

  clonandoItem.value = idStr;

  const success = await fileStore.cloneCotizacion(idStr);

  if (success) {
    await cargarFile();
  } else {
    alert(fileStore.error || 'Ocurrió un error al intentar clonar la cotización.');
  }

  clonandoItem.value = null;
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
  fileStore.fetchIdiomas();
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

const showPaxModal = ref(false);
const showDocModal = ref(false);
const isSubmittingPax = ref(false);
const isSubmittingDoc = ref(false);

const paxForm = ref({
  nombre: '', apellido: '', pais: '', sexo: '', tipodocumento: '', numerodocumento: '', fechanacimiento: ''
});

const docForm = ref({
  nombre: '', tipodocumento: '', vencimiento: '', sobreescribirTraduccion: false, fileObject: null as File | null
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
    const pais = file.value.pais;
    paisFileIri.value = pais ? (typeof pais === 'object' ? (pais['@id'] ?? '') : String(pais)) : '';
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
    telefono: file.value.telefono || null,
    pais: paisFileIri.value || null,
    estado: file.value.estado,
    idiomaCliente: file.value.idiomaCliente || 'es'
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

const paisSelectRef = ref<{ validate: () => boolean } | null>(null);

const guardarPasajero = async () => {
  // SearchableSelect no dispara la validación nativa del form: validamos a mano.
  // validate() pinta el error dentro del componente y devuelve si es válido.
  if (paisSelectRef.value && !paisSelectRef.value.validate()) {
    return;
  }

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
  docForm.value = { nombre: '', tipodocumento: '', vencimiento: '', sobreescribirTraduccion: false, fileObject: null };
  showDocModal.value = true;
};

const abrirEdicionDoc = (doc: any) => {
  docEditandoIri.value = doc['@id'] || `/platform/sales/cotizacion_filedocumentos/${extractIdStr(doc.id)}`;
  docForm.value = {
    nombre: getDocNombre(doc, 'es'),   // siempre editamos la fuente en español
    tipodocumento: doc.tipodocumento || '',
    vencimiento: doc.vencimiento ? doc.vencimiento.split('T')[0] : '',
    sobreescribirTraduccion: false,
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
    // Modo edición: solo metadata, sin archivo (PATCH JSON → array i18n)
    isSubmittingDoc.value = true;
    success = await fileStore.updateDocument(docEditandoIri.value, {
      nombre: docForm.value.nombre
          ? [{ content: docForm.value.nombre.trim(), language: 'es' }]
          : null,
      tipodocumento: docForm.value.tipodocumento,
      vencimiento: docForm.value.vencimiento || null,
      sobreescribirTraduccion: docForm.value.sobreescribirTraduccion
    });
  } else {
    // Modo creación: exige archivo (POST multipart)
    if (!docForm.value.fileObject || !docForm.value.tipodocumento) {
      alert("Faltan datos o el archivo");
      return;
    }
    isSubmittingDoc.value = true;
    const formData = new FormData();
    formData.append('documento', docForm.value.fileObject);
    // ⚠️ nombre es json/array (I18nContent[]): se envía con notación de índice,
    //     nunca como string plano (rompe AbstractItemNormalizer).
    if (docForm.value.nombre) {
      formData.append('nombre[0][content]', docForm.value.nombre.trim());
      formData.append('nombre[0][language]', 'es');
    }
    formData.append('tipodocumento', docForm.value.tipodocumento);
    formData.append('sobreescribirTraduccion', docForm.value.sobreescribirTraduccion ? 'true' : 'false');
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

    <header class="shrink-0 bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between gap-4 z-30 shadow-sm">
      <div class="flex items-center gap-4 min-w-0">
        <button @click="handleVolver" class="w-10 h-10 shrink-0 flex items-center justify-center bg-slate-50 hover:bg-slate-100 rounded-xl text-slate-500 transition-colors">
          <i class="fas fa-arrow-left"></i>
        </button>
        <div class="min-w-0">
          <h1 class="font-black text-2xl text-slate-800 tracking-tight leading-none mb-1 truncate">Detalle del Expediente</h1>
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

      <div class="flex items-center gap-2 shrink-0">
        <button @click="eliminarFile" class="text-[10px] font-bold px-2 py-0.5 rounded-md border border-red-200 text-red-500 hover:bg-red-50 transition-colors flex items-center gap-1">
          <i class="fas fa-trash-alt"></i> <span class="hidden md:inline">Eliminar Expediente</span>
        </button>

        <a v-if="file?.localizador" :href="linkPublicoVersion()" target="_blank" rel="noopener"
           class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl shadow-sm hover:bg-slate-50 transition-all flex items-center gap-2">
          <i class="fas fa-external-link-alt"></i> <span class="hidden md:inline">Vista Cliente</span>
        </a>

        <button @click="nuevaVersion" class="px-5 py-2.5 bg-[#376875] hover:bg-[#2d5662] text-white font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
          <i class="fas fa-rocket"></i> <span class="hidden md:inline">Crear Nueva Versión</span>
        </button>
      </div>
    </header>

    <main v-if="isLoading" class="flex-1 flex justify-center items-center">
      <i class="fas fa-spinner fa-spin text-4xl text-slate-300"></i>
    </main>

    <main v-else class="flex-1 overflow-y-auto p-6 md:p-8">
      <div class="max-w-6xl mx-auto w-full grid grid-cols-1 lg:grid-cols-[minmax(340px,380px)_1fr] gap-8 items-start pb-20">

        <aside class="space-y-6 lg:sticky lg:top-0 min-w-0">

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
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Teléfono</label>
                <div class="relative">
                  <input
                      v-model="file.telefono"
                      type="tel"
                      placeholder="+51 987 654 321"
                      @focus="telefonoFocused = true"
                      @blur="telefonoFocused = false"
                      class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none transition-colors"
                      :class="!telefonoFocused && file.telefono ? 'text-transparent caret-transparent' : ''"
                  >
                  <div v-if="!telefonoFocused && file.telefono"
                       class="absolute inset-0 flex items-center px-3 text-sm font-bold text-slate-800 pointer-events-none select-none rounded-lg">
                    <i class="fas fa-phone text-[#376875]/40 text-xs mr-2"></i>
                    {{ formatearTelefonoDisplay(file.telefono) }}
                  </div>
                </div>
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">País de Origen</label>
                <SearchableSelect
                    v-model="paisFileIri"
                    :options="paisOptions"
                    placeholder="Buscar país..."
                />
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Estado</label>
                <select v-model="file.estado" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
                  <option v-for="(label, valor) in ESTADO_FILE_LABELS" :key="valor" :value="valor">
                    {{ label }}
                  </option>
                </select>
              </div>
              <div v-if="idiomasDisponibles.length">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">Idioma Predeterminado</label>
                <div class="relative">
                  <div v-if="idiomaFileDropdown" class="fixed inset-0 z-40" @click="idiomaFileDropdown = false"></div>
                  <button type="button" @click="idiomaFileDropdown = !idiomaFileDropdown"
                      class="relative z-50 w-full flex items-center gap-2 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 transition-colors">
                    <span class="text-base leading-none">{{ idiomasDisponibles.find(i => i.id === (file.idiomaCliente || 'es'))?.bandera ?? '🌐' }}</span>
                    <span class="flex-1 text-left">{{ idiomasDisponibles.find(i => i.id === (file.idiomaCliente || 'es'))?.nombre ?? (file.idiomaCliente || 'es') }}</span>
                    <i class="fas fa-chevron-down text-[9px] text-slate-400 transition-transform duration-200" :class="idiomaFileDropdown ? 'rotate-180' : ''"></i>
                  </button>
                  <div v-if="idiomaFileDropdown" class="absolute left-0 right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-100 overflow-hidden z-50">
                    <button v-for="idi in idiomasDisponibles" :key="idi.id" type="button"
                        @click="file.idiomaCliente = idi.id; idiomaFileDropdown = false"
                        class="flex items-center gap-3 w-full px-3 py-2.5 text-left text-sm font-bold transition-colors hover:bg-slate-50"
                        :class="(file.idiomaCliente || 'es') === idi.id ? 'bg-[#376875]/5 text-[#376875]' : 'text-slate-700'">
                      <span class="text-base leading-none">{{ idi.bandera }}</span>
                      <span class="flex-1">{{ idi.nombre }}</span>
                      <i v-if="(file.idiomaCliente || 'es') === idi.id" class="fas fa-check text-[#376875] text-[10px]"></i>
                    </button>
                  </div>
                </div>
              </div>
              <button type="submit" :disabled="isSavingFile" class="w-full py-3 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl shadow mt-2">
                <i v-if="isSavingFile" class="fas fa-spinner fa-spin mr-1"></i> Guardar Cambios
              </button>
            </form>
          </div>

          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-4 border-b pb-3">
              <h2 class="text-xs font-black text-slate-800 uppercase tracking-widest"><i class="fas fa-folder-open mr-1 text-sky-500"></i> Bóveda Digital</h2>
              <div class="flex items-center gap-2">
                <div v-if="idiomasDisponibles.length > 1" class="relative">
                  <div v-if="idiomaDocDropdown" class="fixed inset-0 z-40" @click="idiomaDocDropdown = false"></div>
                  <button type="button" @click="idiomaDocDropdown = !idiomaDocDropdown"
                      class="relative z-50 flex items-center gap-1.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 transition-colors">
                    <span>{{ idiomasDisponibles.find(i => i.id === idiomaActivo)?.bandera ?? '🌐' }}</span>
                    <span class="uppercase tracking-wider">{{ idiomaActivo }}</span>
                    <i class="fas fa-chevron-down text-[8px] transition-transform duration-200" :class="idiomaDocDropdown ? 'rotate-180' : ''"></i>
                  </button>
                  <div v-if="idiomaDocDropdown" class="absolute right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-100 overflow-hidden min-w-[150px] z-50">
                    <button v-for="idi in idiomasDisponibles" :key="idi.id" type="button"
                        @click="idiomaActivo = idi.id; idiomaDocDropdown = false"
                        class="flex items-center gap-2.5 w-full px-3 py-2.5 text-left text-xs font-bold transition-colors hover:bg-slate-50"
                        :class="idiomaActivo === idi.id ? 'bg-sky-50 text-sky-700' : 'text-slate-700'">
                      <span class="text-sm">{{ idi.bandera }}</span>
                      <span class="flex-1">{{ idi.nombre }}</span>
                      <i v-if="idiomaActivo === idi.id" class="fas fa-check text-sky-500 text-[10px]"></i>
                    </button>
                  </div>
                </div>
                <button @click="abrirDocModal" class="bg-sky-100 text-sky-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-sky-200 shrink-0">+ Subir Doc</button>
              </div>
            </div>

            <div v-if="!file.filedocumentos?.length" class="bg-sky-50 border-2 border-dashed border-sky-200 rounded-2xl p-6 text-center text-sky-400">
              <i class="fas fa-cloud-upload-alt text-2xl mb-2 opacity-60"></i>
              <p class="text-[10px] font-bold uppercase tracking-widest">Bóveda vacía</p>
            </div>

            <div v-else class="space-y-2">
              <div v-for="doc in file.filedocumentos" :key="doc.id" class="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border border-slate-200 group relative">
                <a :href="doc.imageUrl || undefined" target="_blank" class="flex-1 flex items-center gap-3 min-w-0">
                  <div class="w-8 h-8 rounded bg-sky-100 text-sky-600 flex items-center justify-center text-sm shrink-0"><i class="far fa-file-pdf"></i></div>
                  <div class="min-w-0">
                    <p class="text-[11px] font-black text-slate-800 truncate">{{ getDocNombre(doc) || getArchivoLabel(doc.tipodocumento) }}</p>
                    <p class="text-[9px] font-bold text-slate-400 uppercase truncate">{{ getArchivoLabel(doc.tipodocumento) }}</p>
                    <p class="text-[8px] font-bold text-slate-500 uppercase mt-0.5" :class="doc.vencimiento && new Date(doc.vencimiento) < new Date() ? 'text-red-500' : ''">
                      <span v-if="doc.vencimiento">Vence: {{ new Date(doc.vencimiento).toLocaleDateString() }}</span>
                      <span v-else>Permanente</span>
                    </p>
                  </div>
                </a>
                <button @click="abrirEdicionDoc(doc)" class="w-6 h-6 shrink-0 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200 flex items-center justify-center transition-colors">
                  <i class="fas fa-pencil-alt text-xs"></i>
                </button>
                <button @click="eliminarDocumento(doc['@id'])" class="w-6 h-6 shrink-0 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-red-500 hover:border-red-200 flex items-center justify-center transition-colors">
                  <i class="fas fa-times text-xs"></i>
                </button>
              </div>
            </div>
          </div>
        </aside>

        <section class="space-y-8 min-w-0">

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

            <div v-else v-for="cot in file.cotizaciones" :key="cot.id" class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm hover:border-[#376875] transition-colors group mb-4">

              <!-- 1. CABECERA: Versión, Estado y Botones -->
              <div class="flex flex-wrap sm:flex-nowrap items-start justify-between gap-3 mb-4">

                <!-- Izquierda: Versión y Estado -->
                <div class="flex items-center gap-3">
                  <!-- Badge de versión, editable -->
                  <div v-if="editandoVersion !== (cot['@id'] || cot.id)"
                       @click="iniciarEdicionVersion(cot)"
                       class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-black text-slate-700 text-lg border-2 border-white shadow-sm group-hover:bg-[#376875] group-hover:text-white transition-colors cursor-pointer shrink-0"
                       title="Click para editar versión">
                    V{{ cot.version }}
                  </div>
                  <div v-else class="flex items-center gap-1 shrink-0">
                    <input v-model.number="versionTemp" type="number" min="1"
                           class="w-14 h-12 text-center font-black rounded-full border-2 border-[#376875] outline-none"
                           @keyup.enter="guardarVersion(cot)" @keyup.esc="editandoVersion = null">
                    <button @click="guardarVersion(cot)" class="text-emerald-600 w-8 h-8 flex items-center justify-center bg-emerald-50 rounded-full"><i class="fas fa-check"></i></button>
                    <button @click="editandoVersion = null" class="text-slate-400 w-8 h-8 flex items-center justify-center bg-slate-100 rounded-full"><i class="fas fa-times"></i></button>
                  </div>

                  <div class="min-w-0">
                    <div class="flex items-center gap-2 leading-none">
                      <p class="text-sm sm:text-base font-black text-slate-800 capitalize">{{ cot.estado || 'Pendiente' }}</p>
                      <span class="text-[9px] font-black bg-orange-50 text-orange-600 px-1.5 py-0.5 rounded border border-orange-100 uppercase shrink-0">{{ cot.monedaGlobal || 'USD' }}</span>
                    </div>
                    <p v-if="cot.resumen" class="text-[10px] text-slate-400 font-medium mt-1 truncate max-w-35 sm:max-w-xs">
                      {{ fileStore.extraerResumenPreview(cot.resumen) }}
                    </p>
                  </div>
                </div>

                <!-- Derecha: Botones de Acción -->
                <div class="flex items-center gap-2 w-full sm:w-auto justify-end mt-2 sm:mt-0">
                  <button @click="abrirMotor(cot)" class="px-4 py-2 bg-[#E07845] text-white text-xs font-bold rounded-xl shadow-sm hover:bg-[#c96636] transition-colors flex items-center gap-2">
                    Editar <i class="fas fa-arrow-right"></i>
                  </button>

                  <a :href="linkPublicoVersion(cot.version)" target="_blank" rel="noopener"
                     class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-emerald-500 hover:border-emerald-200 hover:bg-emerald-50 transition-colors"
                     :title="`Abrir vista cliente (V${cot.version})`">
                    <i class="fas fa-external-link-alt text-xs"></i>
                  </a>

                  <button @click="clonarVersion(cot)" :disabled="clonandoItem === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-sky-500 hover:border-sky-200 hover:bg-sky-50 transition-colors disabled:opacity-50"
                          title="Clonar esta versión">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="clonandoItem === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-copy text-xs" v-else></i>
                  </button>

                  <button @click="eliminarVersion(cot)" :disabled="eliminandoItem === (cot['@id'] || cot.id)"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-200 hover:bg-red-50 transition-colors disabled:opacity-50">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="eliminandoItem === (cot['@id'] || cot.id)"></i>
                    <i class="fas fa-trash-alt text-xs" v-else></i>
                  </button>
                </div>
              </div>

              <!-- 2. PANEL DE MÉTRICAS (Grid separada) -->
              <div class="grid grid-cols-4 gap-2 sm:gap-4 bg-slate-50 border border-slate-100 rounded-xl p-3 sm:p-4 mt-2">

                <!-- Pax -->
                <div class="flex flex-col">
                  <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                    <i class="fas fa-users mr-1"></i> Pax
                  </span>
                  <span class="text-xs sm:text-sm font-black text-slate-700">
                    {{ cot.numPax ?? '—' }}
                  </span>
                </div>

                <!-- Venta -->
                <div class="flex flex-col border-l border-slate-200 pl-3 sm:pl-4">
                  <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                    <i class="fas fa-money-bill mr-1"></i> Venta
                  </span>
                  <span class="text-xs sm:text-sm font-black text-slate-800">
                    <span class="text-[9px] font-bold text-slate-400 mr-0.5">{{ cot.monedaGlobal }}</span>
                    {{ cot.totalVenta ?? '0.00' }}
                  </span>
                </div>

                <!-- Ganancia -->
                <div class="flex flex-col border-l border-slate-200 pl-3 sm:pl-4">
                  <span class="text-[9px] font-bold text-emerald-600/70 uppercase tracking-widest mb-1">
                    <i class="fas fa-chart-line mr-1"></i> Ganancia
                  </span>
                  <span class="text-xs sm:text-sm font-black text-emerald-600">
                    <span class="text-[9px] font-bold text-emerald-600/60 mr-0.5">{{ cot.monedaGlobal }}</span>
                    {{ cot.ganancia ?? '0.00' }}
                  </span>
                </div>

                <!-- Idioma -->
                <div class="flex flex-col border-l border-slate-200 pl-3 sm:pl-4">
                  <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                    <i class="fas fa-language mr-1"></i> Idioma
                  </span>
                  <span class="text-xs sm:text-sm font-black text-slate-700">
                    {{ idiomasDisponibles.find(i => i.id === cot.idiomaCliente)?.bandera ?? '🌐' }}
                    <span class="text-[10px] uppercase text-slate-500">{{ cot.idiomaCliente || 'es' }}</span>
                  </span>
                </div>

              </div>

            </div>

          </div>

        </section>
      </div>
    </main>
  </div>

  <Teleport to="body">
    <div v-if="showPaxModal" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-visible">
        <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white rounded-t-3xl">
          <h3 class="font-black text-sm uppercase tracking-widest">{{ paxEditandoIri ? 'Editar Pasajero' : 'Nuevo Pasajero' }}</h3>
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
              <SearchableSelect
                  ref="paisSelectRef"
                  v-model="paxForm.pais"
                  :options="paisOptions"
                  placeholder="Buscar país..."
                  required
                  error-message="La nacionalidad es obligatoria."
              />
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
              <MaskedDateInput v-model="paxForm.fechanacimiento" />
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
    <div v-if="showDocModal" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
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
            <div class="flex items-center justify-between mb-1">
              <label class="block text-[10px] font-bold text-slate-500 uppercase">Nombre del documento *</label>
              <button type="button"
                      @click="docForm.sobreescribirTraduccion = !docForm.sobreescribirTraduccion"
                      :title="docForm.sobreescribirTraduccion ? 'Se regenerarán las traducciones al guardar' : 'Se conservan las traducciones existentes'"
                      class="w-8 h-8 flex items-center justify-center rounded-lg border transition-all"
                      :class="docForm.sobreescribirTraduccion ? 'bg-sky-100 border-sky-300 text-sky-600 shadow-inner' : 'bg-white border-slate-200 text-slate-300 hover:text-slate-500'">
                <i class="fas fa-language text-base"></i>
              </button>
            </div>
            <input v-model="docForm.nombre" required type="text" placeholder="Ej. Entrada Machupicchu"
                   class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
            <p class="text-[9px] text-slate-400 mt-1">
              {{ docForm.sobreescribirTraduccion
                ? 'Al guardar se regenerarán las traducciones automáticas.'
                : 'Se traduce automáticamente; las traducciones existentes se conservan.' }}
            </p>
          </div>

          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Categoría del Doc *</label>
            <select v-model="docForm.tipodocumento" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
              <option v-for="(label, valor) in ARCHIVO_TIPO_LABELS" :key="valor" :value="valor">{{ label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Vencimiento (Opcional)</label>
            <MaskedDateInput v-model="docForm.vencimiento" placeholder="DD/MM/AAAA" />
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