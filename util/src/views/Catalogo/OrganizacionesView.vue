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
import { useRouter, onBeforeRouteLeave } from 'vue-router';
import { useOrganizacionStore } from '@/stores/travel/organizacionStore';
import { useChatStore } from '@/stores/chat/chatStore.ts';
import { uuidDe } from '@/services/hydra';
import {
    portadaDe,
    proveedorVacio,
    puedeMostrarseAlCliente,
    AYUDA_TITULO_SERVICIO,
    type Organizacion,
    type ProveedorWrite,
} from '@/types/organizacionModel';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import { usePermisosStore } from '@/stores/permisosStore';
import OrganizacionFormulario from '@/components/common/OrganizacionFormulario.vue';
import ContactoDeIdentidad from '@/components/common/ContactoDeIdentidad.vue';

const store = useOrganizacionStore();
const chatStore = useChatStore();
const router = useRouter();
// Sólo para pintar el botón: quien decide es el #[IsGranted] del endpoint. Ver el store.
const permisos = usePermisosStore();

const termino = ref('');
const formulario = ref<ProveedorWrite>(proveedorVacio());
const editandoId = ref<string | null>(null);
const panelAbierto = ref(false);
const nuevoServicio = ref('');
/** IRIs de los lugares marcados. Es la cobertura del proveedor, no su ubicación. */
const lugaresSel = ref<string[]>([]);

const subiendo = ref(false);
const confirmandoBorrado = ref<string | null>(null);
const inputArchivo = ref<HTMLInputElement | null>(null);

const esNuevo = computed(() => editandoId.value === null);
const activo = computed(() => store.proveedorActivo);

// ══ VER / EDITAR ═══════════════════════════════════════════════════════════
//
// Mismo interruptor que `ReservaEditDrawer`, y por el mismo motivo: **abrir una ficha es casi
// siempre para mirarla**, no para cambiarla. Con el formulario delante, consultar un teléfono
// obliga a leer dentro de un `<input>` —donde el texto se corta y no se puede pulsar— y deja el
// panel a un despiste de guardar algo sin querer.
//
// Y hay un motivo concreto de este panel: las acciones que no son «guardar» —escribir por chat,
// llamar, abrir la web— no tienen sitio natural en un formulario. En la ficha sí.
const soloLectura = ref(false);

/**
 * La ficha de lectura, con lo que hay AHORA en el formulario.
 *
 * Es una vista previa, no una recarga: si se cambió el teléfono y no se guardó, la ficha enseña
 * el nuevo. Igual que `ReservaEditDrawer::verSoloLectura()`.
 */
const verFicha = () => { soloLectura.value = true; };
const editar = () => { soloLectura.value = false; };

/** Los nombres de los lugares que cubre, resueltos contra el vocabulario ya cargado. */
const lugaresDelProveedor = computed<string[]>(() =>
    lugaresSel.value
        .map(iri => store.lugares.find(l => l['@id'] === iri || l.id === iri)?.nombre)
        .filter((n): n is string => !!n)
);

// ══ ESCRIBIRLE AL PROVEEDOR ════════════════════════════════════════════════
//
// El hilo de una organización **no nace al guardarla**, y es deliberado: se guarda muchas veces
// —se le corrige la dirección, se le sube una foto— y ninguna significa «quiero hablar con
// ella». Nace aquí, cuando alguien lo decide.
//
// Es idempotente: si ya tenía hilo, devuelve ése. Ver `docs/Travel.md` §11 bis.
const abriendoChat = ref(false);
const errorChat = ref<string | null>(null);

/**
 * Sin teléfono ni correo el hilo no resolvería a nadie, y el backend se negaría igual.
 *
 * 🪞 Se comprueba aquí sólo para **desactivar el botón y decir por qué**: quien de verdad lo
 * impide es `AperturaDeHilo::abrir()`. Se mira el formulario y no `activo`, para que el aviso
 * desaparezca en cuanto se teclea el teléfono, sin tener que guardar primero.
 */
const puedeEscribirse = computed(() =>
    !esNuevo.value && (!!formulario.value.telefono?.trim() || !!formulario.value.email?.trim())
);

async function escribirle(): Promise<void> {
    if (!editandoId.value) return;

    abriendoChat.value = true;
    errorChat.value = null;

    try {
        const resultado = await chatStore.abrirConversacion('travel_organizacion', editandoId.value);

        if ('error' in resultado) {
            // El motivo lo redacta el backend —«ese asunto ya no existe», «no hay ni teléfono ni
            // correo»— y se pinta tal cual: sabe mejor que nosotros qué falta.
            errorChat.value = resultado.error;

            return;
        }

        const id = uuidDe(resultado.conversacion);

        if (!id) return;

        // ⚠️ Cerrar ANTES de navegar: `onBeforeRouteLeave` cancela la salida mientras el panel
        // esté abierto, así que el push se lo tragaría y el botón parecería no hacer nada.
        cerrarPanel();
        await router.push({ path: '/chat', query: { id } });
    } catch {
        errorChat.value = 'No se pudo abrir la conversación.';
    } finally {
        abriendoChat.value = false;
    }
}

// Debounce manual, sin dependencias — mismo criterio que el buscador de expedientes.
let temporizador: ReturnType<typeof setTimeout> | null = null;
watch(termino, (t) => {
    if (temporizador) clearTimeout(temporizador);
    temporizador = setTimeout(() => store.fetchProveedores(t), 300);
});

const abrirNuevo = () => {
    errorChat.value = null;
    // Un alta empieza en el formulario: no hay ficha que mirar todavía.
    soloLectura.value = false;
    editandoId.value = null;
    formulario.value = proveedorVacio();
    lugaresSel.value = [];
    store.proveedorActivo = null;
    panelAbierto.value = true;
};

const abrirEdicion = async (p: Organizacion) => {
    // El aviso de chat es de UN proveedor: arrastrarlo a la ficha siguiente diría algo falso
    // sobre alguien que quizá sí tiene teléfono.
    errorChat.value = null;
    // Pulsar un proveedor de la lista es para VERLO. Se edita desde el botón de la cabecera.
    soloLectura.value = true;
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
        titulo: f?.titulo ?? [],
    };
    lugaresSel.value = [...(f?.lugares ?? [])];
};

const cerrarPanel = () => {
    errorChat.value = null;
    panelAbierto.value = false;
    editandoId.value = null;
    store.proveedorActivo = null;
    confirmandoBorrado.value = null;
};

const guardar = async () => {
    if (!formulario.value.nombreComercial.trim()) return;

    formulario.value.lugares = [...lugaresSel.value];

    if (esNuevo.value) {
        const creado = await store.crearProveedor(formulario.value);

        // Se queda abierto sobre el recién creado: lo normal tras darlo de alta es seguir con
        // sus servicios o su galería, no volver al listado.
        //
        // ⚠️ Y en modo EDICIÓN, no en la ficha: servicios y galería sólo existen en el
        // formulario —necesitan el IRI del proveedor ya creado—, así que dejarlo en la ficha
        // mandaría a dar un rodeo justo cuando se acaba de crear.
        if (creado) {
            await abrirEdicion(creado);
            soloLectura.value = false;
        }

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

/**
 * El «atrás» cierra el panel; sólo sale de la vista si ya estaba cerrado.
 *
 * ── El fallo ────────────────────────────────────────────────────────────────
 * El panel es una capa, no una ruta, así que el navegador no sabía que estaba abierto: darle
 * atrás para «volver al listado» **se salía hasta el home**, porque la entrada anterior del
 * historial era la de antes de entrar a Proveedores. En móvil, donde el atrás del sistema es el
 * gesto natural, se perdía el trabajo del formulario sin un aviso.
 *
 * Mismo patrón que `ReservasView`: cada capa abierta consume un «atrás».
 *
 * ⚠️ **Cancela CUALQUIER salida de ruta, no sólo el back.** Por eso `escribirle()` cierra el
 * panel antes de su `router.push`, igual que `ReservaEditDrawer::abrirChatInterno()`: un push
 * con el panel abierto se lo tragaría este guard y el botón parecería no hacer nada.
 */
onBeforeRouteLeave(() => {
    if (panelAbierto.value) {
        cerrarPanel();

        return false;
    }
});

onMounted(async () => {
    void permisos.cargar();
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
                    :disabled="!permisos.puede('ROLE_MAESTROS_WRITE')"
                    :title="permisos.motivo('ROLE_MAESTROS_WRITE', 'dar de alta empresas en el catálogo')"
                    :class="permisos.puede('ROLE_MAESTROS_WRITE') ? '' : 'opacity-40 cursor-not-allowed'"
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
                    <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest truncate">
                        {{ esNuevo ? 'Nuevo proveedor' : soloLectura ? 'Proveedor' : 'Editar proveedor' }}
                    </h2>

                    <!-- El interruptor, igual que en el cajón de reservas. No aparece al crear:
                         todavía no hay nada que ver. -->
                    <button v-if="!esNuevo && soloLectura" @click="editar"
                            class="ml-auto px-3 py-1.5 flex items-center gap-1.5 bg-[#376875] hover:bg-[#2d5660] text-white rounded-full text-[10px] font-black uppercase tracking-widest transition-colors">
                        <i class="fas fa-pen text-[10px]"></i> Editar
                    </button>
                    <button v-else-if="!esNuevo" @click="verFicha"
                            class="ml-auto px-3 py-1.5 flex items-center gap-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded-full text-[10px] font-black uppercase tracking-widest transition-colors">
                        <i class="fas fa-eye text-[10px]"></i> Ver
                    </button>

                    <button @click="cerrarPanel"
                            :class="esNuevo ? 'ml-auto' : ''"
                            class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-4">
                    <!-- ═══ FICHA (modo «Ver») ═══════════════════════════════════════════
                         No es el formulario deshabilitado: es una ficha, con los datos
                         legibles y pulsables. Un teléfono dentro de un `<input>` se corta y no
                         se puede marcar; aquí es un enlace `tel:`. -->
                    <template v-if="soloLectura && !esNuevo">
                        <div class="flex items-start gap-3">
                            <img v-if="activo && portadaDe(activo)" :src="portadaDe(activo) as string" alt=""
                                 class="w-16 h-16 rounded-xl object-cover shrink-0 bg-slate-100" />
                            <div v-else class="w-16 h-16 rounded-xl bg-slate-100 flex items-center justify-center shrink-0">
                                <i class="fas fa-truck-field text-slate-300 text-xl"></i>
                            </div>
                            <div class="min-w-0 pt-0.5">
                                <p class="text-base font-black text-slate-800 leading-tight">{{ formulario.nombreComercial }}</p>
                                <p v-if="formulario.razonSocial" class="text-[11px] font-bold text-slate-400 mt-0.5">
                                    {{ formulario.razonSocial }}
                                </p>
                            </div>
                        </div>

                        <!-- ⚠️ La visibilidad NO es la casilla a secas: hace falta bandera Y
                             título público. Se pinta con `puedeMostrarseAlCliente()`, el espejo
                             de la regla de PHP, para no contar aquí una versión más simple. -->
                        <div class="flex items-center gap-2 rounded-xl border px-3 py-2"
                             :class="puedeMostrarseAlCliente(formulario)
                                 ? 'border-emerald-200 bg-emerald-50'
                                 : 'border-slate-200 bg-slate-50'">
                            <i class="fas text-[11px]"
                               :class="puedeMostrarseAlCliente(formulario) ? 'fa-eye text-emerald-600' : 'fa-eye-slash text-slate-400'"></i>
                            <p class="text-[11px] font-bold"
                               :class="puedeMostrarseAlCliente(formulario) ? 'text-emerald-700' : 'text-slate-500'">
                                {{ puedeMostrarseAlCliente(formulario)
                                    ? 'Se puede nombrar ante el cliente'
                                    : formulario.visibleParaCliente
                                        ? 'Marcado como nombrable, pero sin título público: no se muestra'
                                        : 'No se nombra ante el cliente' }}
                            </p>
                        </div>

                        <!-- ⚠️ El contacto sale de la IDENTIDAD, no del catálogo.
                             Esto pintaba `formulario.telefono` y `formulario.email` —lo que
                             guarda la organización—, que es la SEMILLA: si alguien corrigió el
                             número en los identificadores, la ficha seguía enseñando el viejo y
                             los mensajes salían al nuevo. Dos sitios contándolo distinto. -->
                        <ContactoDeIdentidad v-if="editandoId" context-type="travel_organizacion" :context-id="editandoId" />

                        <div class="rounded-xl border border-slate-200 divide-y divide-slate-100 overflow-hidden">
                            <div v-if="formulario.direccion" class="px-3 py-2.5">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Dirección</p>
                                <p class="text-sm font-bold text-slate-700">{{ formulario.direccion }}</p>
                            </div>
                            <div v-if="formulario.url" class="px-3 py-2.5">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Web</p>
                                <!-- `noopener`: la pestaña que se abre no debe poder tocar ésta. -->
                                <a :href="formulario.url" target="_blank" rel="noopener noreferrer"
                                   class="text-sm font-bold text-[#376875] hover:underline break-all">{{ formulario.url }}</a>
                            </div>
                        </div>

                        <!-- Cobertura: hasta dónde opera, no dónde está. -->
                        <div v-if="lugaresDelProveedor.length">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Cobertura</h3>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="nombre in lugaresDelProveedor" :key="nombre"
                                      class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold">{{ nombre }}</span>
                            </div>
                        </div>

                        <div v-if="(activo?.proveedorServicios ?? []).length">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Servicios que presta</h3>
                            <ul class="space-y-1">
                                <li v-for="s in activo?.proveedorServicios" :key="s.id"
                                    class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5">
                                    <i class="fas fa-cube text-slate-300 text-[10px]"></i>
                                    <span class="text-xs font-bold text-slate-700 truncate">{{ s.nombre }}</span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="(activo?.proveedorImagenes ?? []).length">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Galería</h3>
                            <div class="grid grid-cols-3 gap-1.5">
                                <img v-for="img in activo?.proveedorImagenes" :key="img.id"
                                     :src="img.imageUrl as string" alt=""
                                     class="w-full aspect-square rounded-lg object-cover bg-slate-100" />
                            </div>
                        </div>
                    </template>

                    <!-- ═══ FORMULARIO (modo «Editar» y alta) ═══════════════════════════ -->
                    <template v-else>
                    <!-- Mismo formulario que el alta inline del editor de cotizaciones: uno
                         solo, para que no diverjan. Servicios y galería se quedan fuera
                         porque necesitan el IRI del proveedor ya creado. -->
                    <!-- `organizacionId` sólo en edición: al crear, teléfono y correo son la
                         semilla que se teclea; después se editan en la identidad. -->
                    <OrganizacionFormulario
                        v-model="formulario"
                        :lugares="store.lugares"
                        v-model:lugaresSeleccionados="lugaresSel"
                        :organizacion-id="editandoId"
                    />

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
                    </template>
                </div>

                <!-- El aviso va ENCIMA del pie y no dentro: el pie es una fila y un texto
                     largo la rompía; además así queda pegado al botón que lo produjo. -->
                <p v-if="errorChat" class="px-4 pt-3 text-[11px] font-bold text-red-600 shrink-0">
                    <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorChat }}
                </p>

                <div class="px-4 py-3 border-t border-slate-200 flex items-center gap-2 shrink-0">
                    <!-- Sólo en edición: en una ficha que se abre para consultar, un botón de
                         borrar es un resbalón esperando. -->
                    <button v-if="!esNuevo && !soloLectura" @click="borrar(editandoId as string)"
                            class="px-3 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors"
                            :class="confirmandoBorrado === editandoId
                                ? 'bg-red-600 text-white'
                                : 'text-red-500 hover:bg-red-50'">
                        <i class="fas fa-trash mr-1"></i>
                        {{ confirmandoBorrado === editandoId ? '¿Seguro?' : 'Eliminar' }}
                    </button>

                    <!-- ⚠️ «Escribir» es la acción de la FICHA, no del formulario, y ése era
                         el problema: enterrada entre Eliminar y Guardar, en un pie pensado para
                         cerrar una edición, no se encontraba. Aquí es lo que se ve al abrir un
                         proveedor.

                         Desactivada sin datos de contacto, con el motivo en el `title`, en vez
                         de dejar que el backend responda 409. -->
                    <button v-if="soloLectura && !esNuevo" @click="escribirle"
                            :disabled="abriendoChat || !puedeEscribirse"
                            :title="puedeEscribirse
                                ? 'Abre el chat con este proveedor'
                                : 'Necesita un teléfono o un correo para poder escribirle'"
                            class="ml-auto px-5 py-2 flex items-center gap-1.5 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-40 disabled:hover:bg-[#376875] text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                        <i :class="abriendoChat ? 'fas fa-circle-notch fa-spin' : 'fas fa-comment-dots'"></i>
                        Escribir
                    </button>

                    <button v-if="!soloLectura" @click="guardar"
                            :disabled="store.isGuardando || !formulario.nombreComercial.trim()"
                            class="ml-auto px-5 py-2 bg-[#E07845] hover:bg-[#c96837] disabled:opacity-40 text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                        <i :class="store.isGuardando ? 'fas fa-circle-notch fa-spin' : 'fas fa-floppy-disk'" class="mr-1"></i>
                        Guardar
                    </button>
                </div>
            </aside>
        </div>
    </div>
</template>
