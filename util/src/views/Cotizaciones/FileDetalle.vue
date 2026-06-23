<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import { useCotizacionFileStore } from '@/stores/cotizaciones/fileStore';

defineProps<{
  id?: string;
}>();

const route = useRoute();
const router = useRouter();
const fileStore = useCotizacionFileStore();

const isLoading = ref(true);
const file = ref<any>(null);
const isSavingFile = ref(false);

// ============================================================================
// 🔥 GUARDIÁN DE CAMBIOS SIN GUARDAR (DIRTY CHECK)
// ============================================================================
const isDirty = ref(false);
let watchActivo = false;

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
  paises: [] as any[],
});

const ENUMS = {
  sexos: [
    { value: 'M', label: 'Masculino' },
    { value: 'F', label: 'Femenino' }
  ],
  documentosIdentidad: [
    { value: 'DNI', label: 'DNI' },
    { value: 'CE', label: 'C.E.' },
    { value: 'RUC', label: 'RUC' },
    { value: 'PASAPORTE', label: 'Pasaporte' },
    { value: 'CI', label: 'Carné de Identidad' }
  ],
  archivosBoveda: [
    { value: 'BOLETO', label: 'Boleto / Ticket' },
    { value: 'FACTURA', label: 'Factura / Recibo' },
    { value: 'RESERVA', label: 'Confirmación de Reserva' },
    { value: 'OTROS', label: 'Otros Documentos' }
  ]
};

const getSexoLabel = (val: string) => ENUMS.sexos.find(s => s.value === val)?.label || val;
const getDocIdLabel = (val: string) => ENUMS.documentosIdentidad.find(d => d.value === val)?.label || val;
const getArchivoLabel = (val: string) => ENUMS.archivosBoveda.find(a => a.value === val)?.label || val;

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
    router.push('/cotizaciones');
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
  router.push('/cotizaciones');
};

const guardarFile = async () => {
  isSavingFile.value = true;
  try {
    const payload = {
      nombreGrupo: file.value.nombreGrupo,
      pasajeroPrincipal: file.value.pasajeroPrincipal,
      email: file.value.email,
      telefono: file.value.telefono,
      estado: file.value.estado
    };
    await apiClient.put(`/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`, payload);

    isDirty.value = false; // 🔥 Limpiamos la alerta al guardar exitosamente
    alert('Expediente actualizado correctamente.');
  } catch (error) {
    alert('Error al guardar el expediente.');
  } finally {
    isSavingFile.value = false;
  }
};

const nuevaVersion = () => {
  router.push(`/cotizaciones/${extractIdStr(file.value.id || file.value['@id'])}/version/nueva`);
};

const abrirMotor = (cotizacion: any) => {
  router.push(`/cotizaciones/${extractIdStr(file.value.id || file.value['@id'])}/version/${extractIdStr(cotizacion.id || cotizacion['@id'])}`);
};

// ==========================================
// LÓGICA DE PASAJEROS
// ==========================================
const abrirPaxModal = () => {
  paxForm.value = { nombre: '', apellido: '', pais: '', sexo: '', tipodocumento: '', numerodocumento: '', fechanacimiento: '' };
  showPaxModal.value = true;
};

const guardarPasajero = async () => {
  isSubmittingPax.value = true;
  const payload = {
    ...paxForm.value,
    file: `/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`
  };
  const success = await fileStore.addPassenger(payload);

  if (success) {
    showPaxModal.value = false;
    await cargarFile();
  } else {
    alert(fileStore.error || "Error al registrar pasajero");
  }
  isSubmittingPax.value = false;
};

const eliminarPasajero = async (iri: string) => {
  if(!confirm('¿Eliminar pasajero?')) return;
  const success = await fileStore.deletePassenger(iri);
  if (success) await cargarFile();
  else alert("Error al eliminar pasajero");
};

// ==========================================
// LÓGICA DE DOCUMENTOS
// ==========================================
const abrirDocModal = () => {
  docForm.value = { tipodocumento: '', vencimiento: '', fileObject: null };
  showDocModal.value = true;
};

const handleFileUpload = (e: Event) => {
  const target = e.target as HTMLInputElement;
  if (target.files && target.files[0]) docForm.value.fileObject = target.files[0];
};

const guardarDocumento = async () => {
  if (!docForm.value.fileObject || !docForm.value.tipodocumento) {
    alert("Faltan datos o el archivo"); return;
  }

  isSubmittingDoc.value = true;
  const formData = new FormData();
  formData.append('documento', docForm.value.fileObject);
  formData.append('tipodocumento', docForm.value.tipodocumento);
  formData.append('file', `/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`);

  if (docForm.value.vencimiento) {
    formData.append('vencimiento', docForm.value.vencimiento);
  }

  const success = await fileStore.uploadDocument(formData);
  if (success) {
    showDocModal.value = false;
    await cargarFile();
  } else {
    alert(fileStore.error || "Error al subir el documento");
  }
  isSubmittingDoc.value = false;
};

const eliminarDocumento = async (iri: string) => {
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
          <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">{{ file?.localizador || 'Sin Localizador' }}</p>
        </div>
      </div>
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

            <div v-if="!file.filedocumentos?.length" class="text-center text-slate-400 py-4 text-xs font-bold uppercase">Vacío</div>
            <div class="grid grid-cols-1 gap-2">
              <div v-for="doc in file.filedocumentos" :key="doc.id" class="flex items-center gap-3 p-2 bg-slate-50 rounded-xl border border-slate-200 group relative">
                <a :href="doc.imageUrl" target="_blank" class="flex-1 flex items-center gap-3 min-w-0">
                  <div class="w-8 h-8 rounded bg-sky-100 text-sky-600 flex items-center justify-center text-sm flex-shrink-0"><i class="far fa-file-pdf"></i></div>
                  <div class="min-w-0">
                    <p class="text-[10px] font-black text-slate-800 truncate">{{ getArchivoLabel(doc.tipodocumento) }}</p>
                    <p class="text-[8px] font-bold text-slate-500 uppercase mt-0.5" :class="doc.vencimiento && new Date(doc.vencimiento) < new Date() ? 'text-red-500' : ''">
                      <span v-if="doc.vencimiento">Vence: {{ new Date(doc.vencimiento).toLocaleDateString() }}</span>
                      <span v-else>Permanente</span>
                    </p>
                  </div>
                </a>
                <button @click="eliminarDocumento(doc['@id'])" class="w-6 h-6 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-red-500 hover:border-red-200 flex items-center justify-center transition-colors">
                  <i class="fas fa-times text-xs"></i>
                </button>
              </div>
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
                <button @click="eliminarPasajero(pax['@id'])" class="absolute top-3 right-3 text-slate-300 hover:text-red-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                  <i class="fas fa-trash-alt text-xs"></i>
                </button>
                <div class="flex items-start gap-3 pr-8">
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

            <div v-else v-for="cot in file.cotizaciones" :key="cot.id" class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between hover:border-[#376875] transition-colors group mb-3">
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-black text-slate-700 text-lg border-2 border-white shadow-sm group-hover:bg-[#376875] group-hover:text-white transition-colors">
                  V{{ cot.version }}
                </div>
                <div>
                  <p class="text-sm font-black text-slate-800">{{ cot.estado || 'Pendiente' }}</p>
                  <div class="flex items-center gap-3 text-[10px] font-bold text-slate-400 uppercase mt-1">
                    <span><i class="fas fa-users"></i> {{ cot.numPax }} Pax</span>
                    <span><i class="fas fa-money-bill"></i> {{ cot.monedaGlobal }} {{ cot.totalVenta || '0.00' }}</span>
                  </div>
                </div>
              </div>
              <button @click="abrirMotor(cot)" class="px-4 py-2 bg-[#E07845] text-white text-xs font-bold rounded-lg shadow hover:bg-[#c96636] transition-colors">
                Abrir Motor <i class="fas fa-arrow-right ml-1"></i>
              </button>
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
                <option v-for="d in ENUMS.documentosIdentidad" :key="d.value" :value="d.value">{{ d.label }}</option>
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
                <option v-for="s in ENUMS.sexos" :key="s.value" :value="s.value">{{ s.label }}</option>
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
          <h3 class="font-black text-sm uppercase tracking-widest"><i class="fas fa-upload mr-2"></i> Subir a Bóveda</h3>
          <button @click="showDocModal = false" class="text-sky-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarDocumento" class="p-6 space-y-4">
          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Archivo (PDF / Img) *</label>
            <input type="file" @change="handleFileUpload" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100">
          </div>
          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Categoría del Doc *</label>
            <select v-model="docForm.tipodocumento" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
              <option v-for="a in ENUMS.archivosBoveda" :key="a.value" :value="a.value">{{ a.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Vencimiento (Opcional)</label>
            <input v-model="docForm.vencimiento" type="date" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
            <p class="text-[9px] text-slate-400 mt-1">Útil para alertar sobre Pasaportes o Visas vencidas.</p>
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="showDocModal = false" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="isSubmittingDoc" class="px-5 py-2 bg-sky-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-sky-700 flex items-center gap-2">
              <i v-if="isSubmittingDoc" class="fas fa-spinner fa-spin"></i> Subir Documento
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>

</template>