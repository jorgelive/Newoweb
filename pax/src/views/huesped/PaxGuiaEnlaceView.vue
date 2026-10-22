<script setup lang="ts">
/**
 * src/views/huesped/PaxGuiaEnlaceView.vue
 *
 * La ficha que abre un ENLACE DEL EQUIPO: `.pe/acceso/{token}`. Hoy, cómo sacar la llave de
 * emergencia y cómo dejar un pago en la caja de arriba.
 *
 * No es la guía: no hay menú, ni secciones, ni «Siguiente». Esas fichas no salen nunca en la guía
 * (`PmsGuiaVisibilidad::PorEnlace`); sólo llegan aquí, por un enlace que manda una persona.
 * Qué vale y qué no lo decide el servidor: un enlace caducado o manipulado es un 404 y nada más.
 *
 * Espejo del backend: `App\Pms\Controller\Publico\PmsGuiaEnlacePublicoController`.
 * Ver `docs/PmsGuiaHuesped.md` §7 sexies.
 */
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import { useMaestroStore } from '@/stores/maestroStore';
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';
import VueEasyLightbox from 'vue-easy-lightbox';
import { thumbUrl } from '@/services/imageThumb';
import type { PaxGuiaEnlace } from '@/types/paxGuiaEnlaceModel';

const route = useRoute();
const maestroStore = useMaestroStore();
const token = computed(() => String(route.params.token || '').trim());

const ficha = ref<PaxGuiaEnlace | null>(null);
const cargando = ref(true);
const error = ref<'no_disponible' | 'throttle' | 'red' | null>(null);

/**
 * Las fotos de la ficha que el texto no trae ya insertadas: así basta con subirlas en el panel y
 * no salen dos veces si alguien las metió también con `{{ img: … }}`.
 */
const fotosAlPie = computed(() => {
    if (!ficha.value) return [];
    const texto = maestroStore.traducir(ficha.value.descripcion);
    return ficha.value.galeria.filter(f => !texto.includes(f.imageUrl));
});

const visorVisible = ref(false);
const visorIndice = ref(0);
const abrirFoto = (indice: number): void => {
    visorIndice.value = indice;
    visorVisible.value = true;
};

const caduca = computed(() => {
    if (!ficha.value) return '';
    return new Date(ficha.value.expiraEn).toLocaleString(maestroStore.idiomaActual, {
        day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });
});

onMounted(async () => {
    try {
        await maestroStore.cargarConfiguracion();
        if (!token.value) {
            error.value = 'no_disponible';
            return;
        }
        const { data } = await apiClient.get<PaxGuiaEnlace>(`/pms/guia-enlace/${token.value}`);
        ficha.value = data;
        if (data.idioma) maestroStore.setIdioma(data.idioma);
    } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        error.value = status === 404 ? 'no_disponible' : status === 429 ? 'throttle' : 'red';
    } finally {
        cargando.value = false;
    }
});

const mensajeError = computed(() => {
    switch (error.value) {
        case 'throttle':
            return maestroStore.t('gui_error_throttle') || 'Demasiados intentos. Vuelve a abrir el enlace en unos minutos.';
        case 'red':
            return maestroStore.t('enl_error_red') || 'No pudimos cargar las instrucciones. Inténtalo en un momento.';
        default:
            return maestroStore.t('enl_no_disponible') || 'Este enlace ya no está disponible. Escríbenos y te enviamos uno nuevo.';
    }
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 flex items-start justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div v-if="cargando" class="text-center py-20 text-slate-400">
                <div class="w-8 h-8 mx-auto rounded-full border-2 border-slate-200 border-t-slate-500 animate-spin" />
            </div>

            <div v-else-if="error || !ficha" class="bg-white rounded-2xl shadow-sm p-8 text-center">
                <p class="text-sm text-slate-600">{{ mensajeError }}</p>
            </div>

            <article v-else class="bg-white rounded-3xl shadow-sm border border-slate-200/60 p-6 md:p-7">
                <p v-if="ficha.casita" class="text-[11px] font-black uppercase tracking-wide text-slate-400">{{ ficha.casita }}</p>
                <h1 class="mt-1 text-xl font-black text-slate-900 tracking-tight">{{ maestroStore.traducir(ficha.titulo) }}</h1>
                <div class="mt-4">
                    <RichTextRenderer :content="maestroStore.traducir(ficha.descripcion)" />
                </div>
                <div v-if="fotosAlPie.length" class="mt-5 grid grid-cols-2 gap-3">
                    <button v-for="(foto, i) in fotosAlPie" :key="foto.imageUrl" type="button" @click="abrirFoto(i)"
                            class="aspect-4/3 rounded-2xl overflow-hidden ring-1 ring-black/5 cursor-zoom-in">
                        <img :src="thumbUrl(foto.imageUrl, 'travel_thumb_admin')" :alt="maestroStore.traducir(foto.descripcion)"
                             loading="lazy" class="w-full h-full object-cover">
                    </button>
                </div>
                <VueEasyLightbox :visible="visorVisible" :imgs="fotosAlPie.map(f => thumbUrl(f.imageUrl, 'travel_cliente'))"
                                 :index="visorIndice" @hide="visorVisible = false" />
                <p class="mt-6 pt-4 border-t border-slate-100 text-[11px] text-slate-400">
                    {{ maestroStore.t('enl_privado', { fecha: caduca }) || `Este enlace es sólo para ti y vale hasta el ${caduca}. No lo compartas.` }}
                </p>
            </article>
        </div>
    </div>
</template>
