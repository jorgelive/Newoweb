<script setup lang="ts">
// ============================================================================
// CATÁLOGO DE PROVEEDORES
//
// Administra el maestro sin salir de la SPA. Existe porque el prestador de una
// cotización debe quedar SIEMPRE identificado contra el catálogo: mientras el alta
// vivía sólo en EasyAdmin, la salida rápida era el campo de texto libre, que deja
// `prestadorMaestroId` vacío y rompe el histórico financiero.
// ============================================================================

import { ref, onMounted, computed, watch } from 'vue';
import { useProveedorStore } from '@/stores/travel/proveedorStore';
import {
    portadaDe,
    proveedorVacio,
    puedeMostrarseAlCliente,
    tituloEs,
    desdeTituloEs,
    AYUDA_TITULO_PUBLICO,
    AYUDA_TITULO_SERVICIO,
    AYUDA_VISIBLE_PARA_CLIENTE,
    AYUDA_LUGARES_PROVEEDOR,
    type Proveedor,
    type ProveedorWrite,
} from '@/types/proveedorModel';
import AppSwitcher from '@/components/common/AppSwitcher.vue';

const store = useProveedorStore();

const termino = ref('');
const formulario = ref<ProveedorWrite>(proveedorVacio());
const editandoId = ref<string | null>(null);
const panelAbierto = ref(false);
const nuevoServicio = ref('');
/** Se edita como texto plano en español; AutoTranslate rellena el resto al guardar. */
const tituloPublico = ref('');
/** IRIs de los lugares marcados. Es la cobertura del proveedor, no su ubicación. */
const lugaresSel = ref<string[]>([]);

const alternarLugar = (iri: string) => {
    const i = lugaresSel.value.indexOf(iri);
    if (i === -1) lugaresSel.value.push(iri);
    else lugaresSel.value.splice(i, 1);
};
const subiendo = ref(false);
const confirmandoBorrado = ref<string | null>(null);
const inputArchivo = ref<HTMLInputElement | null>(null);

const esNuevo = computed(() => editandoId.value === null);
const activo = computed(() => store.proveedorActivo);

// Debounce manual, sin dependencias — mismo criterio que el buscador de expedientes.
let temporizador: ReturnType<typeof setTimeout> | null = null;
watch(termino, (t) => {
    if (temporizador) clearTimeout(temporizador);
    temporizador = setTimeout(() => store.fetchProveedores(t), 300);
});

const abrirNuevo = () => {
    editandoId.value = null;
    formulario.value = proveedorVacio();
    tituloPublico.value = '';
    lugaresSel.value = [];
    store.proveedorActivo = null;
    panelAbierto.value = true;
};

const abrirEdicion = async (p: Proveedor) => {
    editandoId.value = p.id;
    panelAbierto.value = true;
    await store.fetchProveedor(p.id);

    const f = store.proveedorActivo;
    formulario.value = {
        nombreComercial: f?.nombreComercial ?? '',
        razonSocial: f?.razonSocial ?? '',
        telefono: f?.telefono ?? '',
        email: f?.email ?? '',
        url: f?.url ?? '',
        direccion: f?.direccion ?? '',
        visibleParaCliente: f?.visibleParaCliente ?? false,
    };
    tituloPublico.value = tituloEs(f?.titulo);
    lugaresSel.value = [...(f?.lugares ?? [])];
};

const cerrarPanel = () => {
    panelAbierto.value = false;
    editandoId.value = null;
    store.proveedorActivo = null;
    confirmandoBorrado.value = null;
};

const guardar = async () => {
    if (!formulario.value.nombreComercial.trim()) return;

    // Vacío se manda como array vacío: ya no oculta —para eso está la bandera— pero deja
    // al proveedor sin texto que mostrar. Ver el bloque de visibilidad en proveedorModel.
    formulario.value.titulo = desdeTituloEs(tituloPublico.value);
    formulario.value.lugares = [...lugaresSel.value];

    if (esNuevo.value) {
        const creado = await store.crearProveedor(formulario.value);
        // Se queda abierto sobre el recién creado: lo normal tras darlo de alta es
        // seguir con sus servicios o su galería, no volver al listado.
        if (creado) await abrirEdicion(creado);
        return;
    }

    await store.actualizarProveedor(editandoId.value as string, formulario.value);
};

const borrar = async (id: string) => {
    if (confirmandoBorrado.value !== id) {
        confirmandoBorrado.value = id;
        return;
    }
    if (await store.borrarProveedor(id)) cerrarPanel();
};

const agregarServicio = async () => {
    const iri = activo.value?.['@id'];
    if (!iri || !nuevoServicio.value.trim()) return;

    if (await store.crearServicio(iri, nuevoServicio.value.trim())) {
        nuevoServicio.value = '';
        await store.fetchProveedor(editandoId.value as string);
    }
};

const quitarServicio = async (servicioId: string) => {
    const servicio = (activo.value?.proveedorServicios ?? []).find((s) => s.id === servicioId);
    if (servicio && await store.borrarServicio(servicio)) {
        await store.fetchProveedor(editandoId.value as string);
    }
};

const elegirArchivo = () => inputArchivo.value?.click();

const alSubir = async (evento: Event) => {
    const archivos = (evento.target as HTMLInputElement).files;
    const iri = activo.value?.['@id'];
    if (!archivos?.length || !iri) return;

    subiendo.value = true;
    const yaHay = (activo.value?.proveedorImagenes ?? []).length;

    // Secuencial y no en paralelo: cada subida convierte a WebP en el servidor, y
    // lanzar cinco a la vez sólo sirve para que compitan por el mismo proceso.
    for (let i = 0; i < archivos.length; i++) {
        await store.subirImagen(iri, archivos[i], yaHay + i, yaHay === 0 && i === 0);
    }

    subiendo.value = false;
    if (inputArchivo.value) inputArchivo.value.value = '';
    await store.fetchProveedor(editandoId.value as string);
};

const quitarImagen = async (id: string) => {
    if (await store.borrarImagen(id)) await store.fetchProveedor(editandoId.value as string);
};

const ponerPortada = async (id: string) => {
    if (await store.marcarPortada(id)) await store.fetchProveedor(editandoId.value as string);
};

onMounted(async () => {
    await store.fetchLugares();
    await store.fetchProveedores();
});
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">
        <!-- Cabecera -->
        <header class="bg-slate-900 text-white px-4 py-3 flex items-center gap-3 shrink-0">
            <AppSwitcher />
            <div class="min-w-0">
                <h1 class="text-lg font-black leading-none truncate">Proveedores</h1>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                    Catálogo maestro · Prestadores y servicios
                </p>
            </div>
            <button @click="abrirNuevo"
                    class="ml-auto flex items-center gap-2 px-4 py-2 bg-[#E07845] hover:bg-[#c96837] rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                <i class="fas fa-plus"></i>
                <span class="hidden sm:inline">Nuevo proveedor</span>
            </button>
        </header>

        <div class="flex-1 flex min-h-0">
            <!-- Listado -->
            <section class="flex-1 flex flex-col min-w-0 border-r border-slate-200">
                <div class="p-3 border-b border-slate-200 bg-white shrink-0">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input v-model="termino" type="search" placeholder="Buscar por nombre comercial…"
                               class="w-full pl-9 pr-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                    </div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-2">
                        {{ store.proveedores.length }} proveedores
                    </p>
                </div>

                <div class="flex-1 overflow-y-auto p-3">
                    <p v-if="store.error" class="mb-3 px-3 py-2 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs font-bold">
                        {{ store.error }}
                    </p>

                    <div v-if="store.isLoading && !store.proveedores.length" class="text-center py-12 text-slate-400">
                        <i class="fas fa-circle-notch fa-spin text-2xl"></i>
                    </div>

                    <div v-else-if="!store.proveedores.length" class="text-center py-16">
                        <i class="fas fa-truck-field text-4xl text-slate-200"></i>
                        <p class="mt-3 text-xs font-black text-slate-400 uppercase tracking-widest">Sin proveedores</p>
                    </div>

                    <ul v-else class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        <li v-for="p in store.proveedores" :key="p.id">
                            <button @click="abrirEdicion(p)"
                                    class="w-full text-left bg-white border rounded-xl p-3 flex items-center gap-3 hover:border-[#E07845] transition-colors"
                                    :class="editandoId === p.id ? 'border-[#E07845] ring-1 ring-[#E07845]' : 'border-slate-200'">
                                <img v-if="portadaDe(p)" :src="portadaDe(p) as string" alt=""
                                     class="w-12 h-12 rounded-lg object-cover shrink-0 bg-slate-100" />
                                <div v-else class="w-12 h-12 rounded-lg bg-slate-100 flex items-center justify-center shrink-0">
                                    <i class="fas fa-truck-field text-slate-300"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-slate-800 truncate flex items-center gap-1.5">
                                        <!-- Con 98 proveedores y opt-in, «cuáles son nombrables» es la
                                             pregunta que el listado tiene que responder de un vistazo. -->
                                        <i class="fas text-[10px] shrink-0"
                                           :class="puedeMostrarseAlCliente(p)
                                               ? 'fa-eye text-emerald-500'
                                               : 'fa-eye-slash text-slate-300'"
                                           :title="puedeMostrarseAlCliente(p)
                                               ? 'Se puede nombrar ante el cliente'
                                               : 'No se nombra ante el cliente'"></i>
                                        <span class="truncate">{{ p.nombreComercial }}</span>
                                    </p>
                                    <p class="text-[10px] font-bold text-slate-400 truncate">
                                        {{ p.telefono || p.email || p.direccion || 'Sin datos de contacto' }}
                                    </p>
                                </div>
                            </button>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Panel de edición -->
            <aside v-if="panelAbierto" class="w-full max-w-md bg-white flex flex-col min-h-0 shadow-xl">
                <div class="px-4 py-3 border-b border-slate-200 flex items-center gap-2 shrink-0">
                    <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest">
                        {{ esNuevo ? 'Nuevo proveedor' : 'Editar proveedor' }}
                    </h2>
                    <button @click="cerrarPanel" class="ml-auto text-slate-400 hover:text-slate-700">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Nombre comercial *</label>
                        <input v-model="formulario.nombreComercial" type="text"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                        <p class="text-[10px] text-slate-400 mt-1">
                            Uso interno: identidad que queda fijada en el histórico financiero. El cliente no lo ve.
                        </p>
                    </div>

                    <!-- La bandera decide SI se nombra; el título, CÓMO. Hacen falta las dos. -->
                    <div>
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input v-model="formulario.visibleParaCliente" type="checkbox"
                                   class="mt-0.5 w-4 h-4 accent-[#E07845] cursor-pointer" />
                            <span>
                                <span class="block text-[10px] font-black text-slate-500 uppercase">
                                    Nombrable ante el cliente
                                </span>
                                <span class="block text-[10px] text-slate-400 mt-0.5">
                                    {{ AYUDA_VISIBLE_PARA_CLIENTE }}
                                </span>
                            </span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">
                            Título público
                            <span class="text-slate-300 font-bold normal-case">(lo ve el cliente)</span>
                        </label>
                        <input v-model="tituloPublico" type="text" placeholder="Cómo se le llama en la propuesta"
                               class="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:border-[#E07845]"
                               :class="tituloPublico.trim() ? 'border-emerald-300 bg-emerald-50/40' : 'border-slate-200'" />
                        <p class="text-[10px] mt-1 text-slate-400">
                            <i class="fas fa-circle-info"></i> {{ AYUDA_TITULO_PUBLICO }}
                        </p>
                        <!-- El hueco que la bandera hace posible: marcado y sin nada que pintar. -->
                        <p v-if="formulario.visibleParaCliente && !tituloPublico.trim()"
                           class="text-[10px] mt-1 text-amber-600 font-semibold">
                            <i class="fas fa-triangle-exclamation"></i>
                            Marcado como nombrable pero sin título: el cliente no verá nada.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Teléfono</label>
                            <input v-model="formulario.telefono" type="text"
                                   class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Email</label>
                            <input v-model="formulario.email" type="email"
                                   class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Razón social</label>
                        <input v-model="formulario.razonSocial" type="text"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Dirección</label>
                        <input v-model="formulario.direccion" type="text"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Web</label>
                        <input v-model="formulario.url" type="url" placeholder="https://…"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
                    </div>

                    <!-- Chips y no un multiselect: son pocos y se marcan de un vistazo. -->
                    <div v-if="store.lugares.length">
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">
                            Lugares donde opera
                        </label>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="l in store.lugares" :key="l.id" type="button"
                                    @click="alternarLugar(l['@id'] ?? '')"
                                    :class="lugaresSel.includes(l['@id'] ?? '')
                                        ? 'bg-[#E07845] text-white border-[#E07845]'
                                        : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                                    class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors">
                                <i class="fas fa-map-marker-alt mr-1 text-[9px]"></i>{{ l.nombre }}
                            </button>
                        </div>
                        <p class="text-[10px] text-slate-400 mt-1">{{ AYUDA_LUGARES_PROVEEDOR }}</p>
                    </div>

                    <!-- Servicios y galería sólo con el proveedor ya creado: ambos necesitan
                         su IRI para colgarse de él. -->
                    <template v-if="!esNuevo && activo">
                        <div class="pt-3 border-t border-slate-100">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">
                                Servicios que presta
                            </h3>

                            <p class="text-[10px] text-amber-600 mb-2 leading-snug">
                                <i class="fas fa-circle-info"></i> {{ AYUDA_TITULO_SERVICIO }}
                            </p>

                            <ul v-if="(activo.proveedorServicios ?? []).length" class="space-y-1 mb-2">
                                <li v-for="s in activo.proveedorServicios" :key="s.id"
                                    class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5">
                                    <i class="fas fa-cube text-slate-300 text-[10px]"></i>
                                    <span class="text-xs font-bold text-slate-700 truncate">{{ s.nombre }}</span>
                                    <button @click="quitarServicio(s.id)" class="ml-auto text-slate-300 hover:text-red-500">
                                        <i class="fas fa-xmark text-[10px]"></i>
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="text-[10px] text-slate-400 mb-2">Todavía no tiene servicios.</p>

                            <div class="flex gap-2">
                                <input v-model="nuevoServicio" type="text" placeholder="Ej: Habitación doble"
                                       @keyup.enter="agregarServicio"
                                       class="flex-1 px-3 py-1.5 border border-slate-200 rounded-lg text-xs focus:outline-none focus:border-[#E07845]" />
                                <button @click="agregarServicio" :disabled="!nuevoServicio.trim()"
                                        class="px-3 py-1.5 bg-slate-900 disabled:opacity-30 text-white rounded-lg text-[10px] font-black uppercase">
                                    Añadir
                                </button>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-slate-100">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Galería</h3>

                            <div v-if="(activo.proveedorImagenes ?? []).length" class="grid grid-cols-3 gap-2 mb-2">
                                <div v-for="img in activo.proveedorImagenes" :key="img.id" class="relative group">
                                    <img :src="img.imageUrl ?? ''" alt=""
                                         class="w-full h-20 object-cover rounded-lg border"
                                         :class="img.isPortada ? 'border-[#E07845] ring-1 ring-[#E07845]' : 'border-slate-200'" />
                                    <span v-if="img.isPortada"
                                          class="absolute top-1 left-1 bg-[#E07845] text-white text-[8px] font-black px-1.5 py-0.5 rounded uppercase">
                                        Portada
                                    </span>
                                    <div class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-2">
                                        <button v-if="!img.isPortada" @click="ponerPortada(img.id)" title="Marcar como portada"
                                                class="text-white hover:text-[#E07845]">
                                            <i class="fas fa-star text-xs"></i>
                                        </button>
                                        <button @click="quitarImagen(img.id)" title="Eliminar"
                                                class="text-white hover:text-red-400">
                                            <i class="fas fa-trash text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-[10px] text-slate-400 mb-2">Sin imágenes.</p>

                            <input ref="inputArchivo" type="file" accept="image/*" multiple class="hidden" @change="alSubir" />
                            <button @click="elegirArchivo" :disabled="subiendo"
                                    class="w-full py-2 border border-dashed border-slate-300 rounded-lg text-[10px] font-black text-slate-500 uppercase tracking-widest hover:border-[#E07845] hover:text-[#E07845] transition-colors disabled:opacity-50">
                                <i :class="subiendo ? 'fas fa-circle-notch fa-spin' : 'fas fa-cloud-arrow-up'" class="mr-1"></i>
                                {{ subiendo ? 'Subiendo…' : 'Subir imágenes' }}
                            </button>
                            <p class="text-[10px] text-slate-400 mt-1">Se convierten a WebP automáticamente.</p>
                        </div>
                    </template>
                </div>

                <div class="px-4 py-3 border-t border-slate-200 flex items-center gap-2 shrink-0">
                    <button v-if="!esNuevo" @click="borrar(editandoId as string)"
                            class="px-3 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors"
                            :class="confirmandoBorrado === editandoId
                                ? 'bg-red-600 text-white'
                                : 'text-red-500 hover:bg-red-50'">
                        <i class="fas fa-trash mr-1"></i>
                        {{ confirmandoBorrado === editandoId ? '¿Seguro?' : 'Eliminar' }}
                    </button>

                    <button @click="guardar" :disabled="store.isGuardando || !formulario.nombreComercial.trim()"
                            class="ml-auto px-5 py-2 bg-[#E07845] hover:bg-[#c96837] disabled:opacity-40 text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                        <i :class="store.isGuardando ? 'fas fa-circle-notch fa-spin' : 'fas fa-floppy-disk'" class="mr-1"></i>
                        Guardar
                    </button>
                </div>
            </aside>
        </div>
    </div>
</template>
