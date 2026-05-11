<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { apiClient } from '@/services/apiClient';

const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const file = ref<any>(null);
const isSavingFile = ref(false);

const cargarFile = async () => {
  isLoading.value = true;
  try {
    const response = await apiClient.get(`/platform/sales/cotizacion_files/${route.params.id}`);
    file.value = response.data;
  } catch (error) {
    console.error("Error al cargar el File", error);
    router.push('/cotizaciones');
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => {
  cargarFile();
});

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
    await apiClient.put(`/platform/sales/cotizacion_files/${file.value.id || file.value['@id'].split('/').pop()}`, payload);
    alert('Expediente actualizado correctamente.');
  } catch (error) {
    alert('Error al guardar el expediente.');
  } finally {
    isSavingFile.value = false;
  }
};

const nuevaVersion = () => {
  const fileId = file.value.id || file.value['@id'].split('/').pop();
  router.push(`/cotizaciones/${fileId}/version/nueva`);
};

const abrirMotor = (cotizacion: any) => {
  const fileId = file.value.id || file.value['@id'].split('/').pop();
  const cotId = cotizacion.id || cotizacion['@id'].split('/').pop();
  router.push(`/cotizaciones/${fileId}/version/${cotId}`);
};

const extractIdStr = (val: any) => val ? String(val).split('/').pop() : '';
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden">

    <header class="flex-shrink-0 bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between z-30 shadow-sm">
      <div class="flex items-center gap-4">
        <button @click="router.push('/cotizaciones')" class="w-10 h-10 flex items-center justify-center bg-slate-50 hover:bg-slate-100 rounded-xl text-slate-500 transition-colors">
          <i class="fas fa-arrow-left"></i>
        </button>
        <div>
          <h1 class="font-black text-2xl text-slate-800 tracking-tight leading-none mb-1">Detalle del Expediente</h1>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">{{ file?.localizador || 'Cargando...' }}</p>
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
        </aside>

        <section class="md:col-span-2 space-y-4">
          <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-5"><i class="fas fa-code-branch mr-2 text-indigo-500"></i> Historial de Versiones</h2>

          <div v-if="!file.cotizaciones || file.cotizaciones.length === 0" class="bg-white border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400">
            <i class="fas fa-clipboard-list text-4xl mb-4 opacity-50"></i>
            <p class="text-sm font-bold uppercase tracking-widest">No hay cotizaciones</p>
            <p class="text-xs mt-2 font-medium">Haz clic en "Crear Nueva Versión" para arrancar el motor operativo.</p>
          </div>

          <div v-else v-for="cot in file.cotizaciones" :key="cot.id" class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between hover:border-[#376875] transition-colors group">
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
        </section>

      </div>
    </main>
  </div>
</template>