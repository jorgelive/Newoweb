<script setup lang="ts">
/**
 * Experiencias: los álbumes de fotos y vídeos de viajes ya operados que enseña la web
 * (openperu.pe/experiencias). Van aparte del cotizador y de los expedientes: ver
 * docs/WebPublica.md, «Experiencias», y docs/PlanWebPublica.md Fase 3.
 *
 * 🔒 «Publicado» exige «autorizado» (permiso firmado de las familias: son viajes con menores). La
 * casilla de publicar se deshabilita sin él, pero quien manda es el servidor
 * (`ExperienciaAlbum::validarPublicacion()`): si rechaza, aquí se enseña su mensaje tal cual.
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import { useExperienciaStore } from '@/stores/front/experienciaStore';
import { thumbUrl } from '@/services/imageThumb';
import { acotarSiEsFotoGrande } from '@/utils/imagenParaSubir';
import { getUrls } from '@/services/apiClient';
import type { ExperienciaAlbum, ExperienciaMedio } from '@/types/experienciaModel';
import type { I18nContent } from '@/types/cotizacionEditorModel';

const store = useExperienciaStore();

const seleccionadoId = ref<string | null>(null);
const seleccionado = computed<ExperienciaAlbum | null>(() => store.albumes.find((a) => a.id === seleccionadoId.value) ?? null);
const medios = computed<ExperienciaMedio[]>(() => [...(seleccionado.value?.medios ?? [])].sort((a, b) => a.orden - b.orden));

const nuevoTitulo = ref('');
const aviso = ref<string | null>(null);

// ── Formulario del álbum (copia editable del seleccionado) ───────────────────
const form = ref({ titulo: '', texto: '', destino: '', fecha: '', slug: '' });

/** El texto en español de un i18n (el origen que traduce `#[AutoTranslate]`). */
const textoEs = (arr?: I18nContent[] | null): string => (arr || []).find((i) => i.language === 'es')?.content || '';

/** Cambia sólo el español y conserva los demás si no cambió (mismo criterio que el catálogo). */
const conEspanol = (arr: I18nContent[] | undefined, texto: string): I18nContent[] => {
  if (!texto.trim()) return [];
  const resto = (arr || []).filter((i) => i.language !== 'es');
  return [{ language: 'es', content: texto.trim() }, ...(textoEs(arr) === texto.trim() ? resto : [])];
};

const sugerirSlug = (texto: string): string =>
  texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);

watch(seleccionado, (a) => {
  aviso.value = null;
  form.value = {
    titulo: textoEs(a?.titulo),
    texto: textoEs(a?.texto),
    destino: a?.destino ?? '',
    fecha: (a?.fecha ?? '').slice(0, 10),
    slug: a?.slug ?? '',
  };
}, { immediate: true });

const guardarDatos = async () => {
  const a = seleccionado.value;
  if (!a) return;
  aviso.value = await store.guardar(a.id, {
    titulo: conEspanol(a.titulo, form.value.titulo),
    texto: conEspanol(a.texto, form.value.texto),
    destino: form.value.destino.trim() || null,
    fecha: form.value.fecha || null,
    slug: form.value.slug.trim() || null,
  });
};

const alternarAutorizado = async () => {
  const a = seleccionado.value;
  if (!a) return;
  // Quitar la autorización despublica a la vez: un álbum publicado sin permiso no puede quedar ni un instante.
  aviso.value = await store.guardar(a.id, a.autorizado ? { autorizado: false, publicado: false } : { autorizado: true });
};

const alternarPublicado = async () => {
  const a = seleccionado.value;
  if (!a) return;
  if (!a.publicado && !form.value.slug.trim()) {
    form.value.slug = sugerirSlug(form.value.titulo);
    await guardarDatos();
    if (aviso.value) return;
  }
  aviso.value = await store.guardar(a.id, { publicado: !a.publicado });
};

const urlWeb = computed(() => {
  const a = seleccionado.value;
  return a?.publicado && a.autorizado && a.slug ? `${getUrls().web}/experiencias/${a.slug}` : null;
});

// ── Álbumes ──────────────────────────────────────────────────────────────────
const crearAlbum = async () => {
  if (!nuevoTitulo.value.trim()) return;
  const creado = await store.crear(nuevoTitulo.value);
  if (creado) {
    nuevoTitulo.value = '';
    seleccionadoId.value = creado.id;
  }
};

const borrarAlbum = async () => {
  const a = seleccionado.value;
  if (!a || !confirm(`¿Borrar el álbum «${textoEs(a.titulo) || 'sin título'}» con sus ${a.medios?.length ?? 0} fotos y vídeos? No se puede deshacer.`)) return;
  if (await store.borrar(a.id)) seleccionadoId.value = store.albumes[0]?.id ?? null;
};

const estado = (a: ExperienciaAlbum): { texto: string; clase: string } => {
  if (a.publicado && a.autorizado) return { texto: 'Publicado', clase: 'bg-emerald-100 text-emerald-700' };
  if (a.autorizado) return { texto: 'Autorizado', clase: 'bg-sky-100 text-sky-700' };
  return { texto: 'Sin autorizar', clase: 'bg-slate-100 text-slate-500' };
};

const portadaDe = (a: ExperienciaAlbum): string | null => {
  const fotos = (a.medios ?? []).filter((m) => m.tipo === 'foto' && m.imageUrl);
  const p = fotos.find((m) => m.esPortada) ?? fotos[0];
  return p?.imageUrl ? thumbUrl(p.imageUrl, 'travel_thumb_admin') : null;
};

// ── Fotos y vídeos ───────────────────────────────────────────────────────────
const subiendo = ref<{ hechas: number; total: number } | null>(null);
const enlaceVideo = ref('');

const alSubir = async (ev: Event) => {
  const a = seleccionado.value;
  const input = ev.target as HTMLInputElement;
  const archivos = Array.from(input.files ?? []);
  input.value = '';
  if (!a || !archivos.length) return;
  subiendo.value = { hechas: 0, total: archivos.length };
  const fallos: string[] = [];
  let orden = medios.value.length;
  // De una en una: enseña el progreso y no tumba el servidor con 40 subidas a la vez.
  for (const original of archivos) {
    const fallo = await store.subirFoto(a.id, await acotarSiEsFotoGrande(original), orden++);
    if (fallo) fallos.push(fallo);
    subiendo.value = { hechas: subiendo.value.hechas + 1, total: archivos.length };
  }
  subiendo.value = null;
  aviso.value = fallos.length ? fallos.join(' · ') : null;
  await store.recargarAlbum(a.id);
};

const agregarVideo = async () => {
  const a = seleccionado.value;
  if (!a || !enlaceVideo.value.trim()) return;
  aviso.value = await store.agregarVideo(a.id, enlaceVideo.value, medios.value.length);
  if (!aviso.value) enlaceVideo.value = '';
  await store.recargarAlbum(a.id);
};

/** Intercambia el orden con el vecino y renumera lo que cambió. */
const mover = async (idx: number, delta: number) => {
  const a = seleccionado.value;
  const lista = [...medios.value];
  const destino = idx + delta;
  if (!a || destino < 0 || destino >= lista.length) return;
  [lista[idx], lista[destino]] = [lista[destino], lista[idx]];
  for (const [i, m] of lista.entries()) {
    if (m.orden !== i) await store.editarMedio(m.id, { orden: i });
  }
  await store.recargarAlbum(a.id);
};

const ponerPortada = async (m: ExperienciaMedio) => {
  const a = seleccionado.value;
  if (!a) return;
  aviso.value = await store.marcarPortada(a, m.id);
  await store.recargarAlbum(a.id);
};

const guardarPie = async (m: ExperienciaMedio, texto: string) => {
  if (texto.trim() === textoEs(m.pie)) return;
  aviso.value = await store.editarMedio(m.id, { pie: conEspanol(m.pie, texto) });
  if (seleccionado.value) await store.recargarAlbum(seleccionado.value.id);
};

const quitar = async (m: ExperienciaMedio) => {
  const a = seleccionado.value;
  if (!a || !confirm(m.tipo === 'foto' ? '¿Quitar esta foto del álbum?' : '¿Quitar este vídeo del álbum?')) return;
  aviso.value = await store.borrarMedio(m.id);
  await store.recargarAlbum(a.id);
};

/** La miniatura de un vídeo de YouTube (Vimeo no da una sin llamar a su API). Espejo de `VideoExterno::miniatura()`. */
const miniaturaYoutube = (url?: string | null): string | null => {
  const id = (url ?? '').match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/)?.[1];
  return id ? `https://i.ytimg.com/vi/${id}/hqdefault.jpg` : null;
};

onMounted(async () => {
  await store.cargar();
  seleccionadoId.value = store.albumes[0]?.id ?? null;
});
</script>

<template>
  <div class="h-dvh bg-[#F8FAFC] font-sans flex flex-col overflow-hidden">
    <header class="shrink-0 bg-slate-900 text-white px-4 md:px-8 py-4 flex items-center justify-between shadow-md">
      <div class="flex items-center gap-3">
        <AppSwitcher />
        <div>
          <h1 class="font-black text-lg md:text-xl tracking-tight leading-none">Experiencias</h1>
          <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">Fotos y vídeos de viajes para la web</p>
        </div>
      </div>
      <a :href="`${getUrls().web}/experiencias`" target="_blank" rel="noopener"
         class="text-xs font-bold text-slate-300 hover:text-white"><i class="fas fa-up-right-from-square mr-1"></i> Ver en la web</a>
    </header>

    <main class="flex-1 min-h-0 overflow-y-auto">
      <div class="max-w-6xl mx-auto p-4 md:p-6 grid gap-5 md:grid-cols-[280px_1fr]">
        <!-- Álbumes -->
        <aside class="space-y-3">
          <form class="flex gap-2" @submit.prevent="crearAlbum">
            <input v-model="nuevoTitulo" placeholder="Nuevo álbum: «Promo Punta Cana 2026»"
                   class="flex-1 min-w-0 rounded-xl border border-slate-300 px-3 py-2 text-sm" />
            <button type="submit" class="shrink-0 rounded-xl bg-[#E07845] px-3 text-white" title="Crear álbum"><i class="fas fa-plus"></i></button>
          </form>
          <p v-if="store.cargando" class="text-xs text-slate-400">Cargando…</p>
          <p v-if="store.error" class="text-xs text-red-600">{{ store.error }}</p>
          <button v-for="a in store.albumes" :key="a.id" type="button" @click="seleccionadoId = a.id"
                  :class="a.id === seleccionadoId ? 'border-[#376875] ring-2 ring-[#376875]/20' : 'border-slate-200'"
                  class="w-full flex items-center gap-3 rounded-2xl border bg-white p-2 text-left">
            <img v-if="portadaDe(a)" :src="portadaDe(a) ?? ''" alt="" class="h-12 w-16 shrink-0 rounded-lg object-cover" />
            <span v-else class="h-12 w-16 shrink-0 rounded-lg bg-linear-to-br from-[#376875] to-[#1f4550]"></span>
            <span class="min-w-0">
              <span class="block truncate text-sm font-bold text-slate-800">{{ textoEs(a.titulo) || 'Sin título' }}</span>
              <span class="mt-0.5 inline-block rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="estado(a).clase">{{ estado(a).texto }}</span>
              <span class="ml-1 text-[10px] text-slate-400">{{ a.medios?.length ?? 0 }} medios</span>
            </span>
          </button>
          <p v-if="!store.cargando && !store.albumes.length" class="text-xs text-slate-400">Aún no hay álbumes. Crea el primero arriba.</p>
        </aside>

        <!-- Editor -->
        <section v-if="seleccionado" class="space-y-5">
          <div class="rounded-3xl border border-slate-200 bg-white p-5 space-y-4">
            <div class="grid gap-3 md:grid-cols-2">
              <label class="block md:col-span-2">
                <span class="text-[10px] font-black uppercase text-slate-500">Título</span>
                <input v-model="form.titulo" @blur="guardarDatos" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-bold" />
              </label>
              <label class="block md:col-span-2">
                <span class="text-[10px] font-black uppercase text-slate-500">Texto (opcional)</span>
                <textarea v-model="form.texto" @blur="guardarDatos" rows="3" placeholder="Cómo fue el viaje, en dos o tres frases."
                          class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
              </label>
              <label class="block">
                <span class="text-[10px] font-black uppercase text-slate-500">Destino</span>
                <input v-model="form.destino" @blur="guardarDatos" placeholder="Punta Cana" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="block">
                <span class="text-[10px] font-black uppercase text-slate-500">Fecha del viaje</span>
                <input v-model="form.fecha" @change="guardarDatos" type="date" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="block md:col-span-2">
                <span class="text-[10px] font-black uppercase text-slate-500">Dirección web</span>
                <div class="mt-1 flex items-center overflow-hidden rounded-xl border border-slate-300">
                  <span class="pl-3 text-xs text-slate-400 whitespace-nowrap">/experiencias/</span>
                  <input v-model="form.slug" @blur="form.slug = sugerirSlug(form.slug || form.titulo); guardarDatos()" placeholder="promo-punta-cana-2026"
                         class="w-full px-1 py-2 text-sm font-bold outline-none" />
                </div>
              </label>
            </div>

            <!-- Autorizado y publicado -->
            <div class="grid gap-3 md:grid-cols-2 border-t border-slate-100 pt-4">
              <button type="button" @click="alternarAutorizado"
                      :class="seleccionado.autorizado ? 'border-sky-300 bg-sky-50' : 'border-slate-200'"
                      class="flex items-start gap-3 rounded-2xl border p-3 text-left">
                <i class="fas mt-0.5" :class="seleccionado.autorizado ? 'fa-square-check text-sky-600' : 'fa-square text-slate-300'"></i>
                <span>
                  <span class="block text-xs font-black text-slate-700">Autorizado</span>
                  <span class="block text-[11px] text-slate-500">Tenemos el permiso firmado de las familias para publicar estas imágenes.</span>
                </span>
              </button>
              <button type="button" @click="alternarPublicado" :disabled="!seleccionado.autorizado && !seleccionado.publicado"
                      :class="seleccionado.publicado ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200'"
                      class="flex items-start gap-3 rounded-2xl border p-3 text-left disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas mt-0.5" :class="seleccionado.publicado ? 'fa-toggle-on text-emerald-600' : 'fa-toggle-off text-slate-300'"></i>
                <span>
                  <span class="block text-xs font-black text-slate-700">Publicado en la web</span>
                  <span class="block text-[11px] text-slate-500">
                    <template v-if="!seleccionado.autorizado">Primero, «autorizado».</template>
                    <template v-else-if="urlWeb"><a :href="urlWeb" target="_blank" rel="noopener" class="text-[#376875] underline" @click.stop>Ver el álbum en la web</a></template>
                    <template v-else>Sale en openperu.pe/experiencias.</template>
                  </span>
                </span>
              </button>
            </div>
            <p v-if="aviso" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700">{{ aviso }}</p>
          </div>

          <!-- Medios -->
          <div class="rounded-3xl border border-slate-200 bg-white p-5 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
              <label class="cursor-pointer rounded-xl bg-[#376875] px-3 py-2 text-xs font-black text-white">
                <i class="fas fa-images mr-1"></i> Subir fotos
                <input type="file" accept="image/*" multiple class="hidden" @change="alSubir" :disabled="!!subiendo" />
              </label>
              <span v-if="subiendo" class="text-xs font-bold text-slate-500">Subiendo {{ subiendo.hechas }} de {{ subiendo.total }}…</span>
              <form class="ml-auto flex min-w-0 flex-1 gap-2 md:max-w-md" @submit.prevent="agregarVideo">
                <input v-model="enlaceVideo" placeholder="Enlace de YouTube o Vimeo" class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-xs" />
                <button type="submit" class="shrink-0 rounded-xl border border-slate-300 px-3 text-xs font-black text-slate-600"><i class="fab fa-youtube mr-1"></i> Añadir</button>
              </form>
            </div>

            <p v-if="!medios.length" class="text-xs text-slate-400">Este álbum aún no tiene fotos ni vídeos.</p>
            <div class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
              <div v-for="(m, idx) in medios" :key="m.id" class="overflow-hidden rounded-2xl border border-slate-200">
                <div class="relative aspect-video bg-slate-100">
                  <img v-if="m.tipo === 'foto' && m.imageUrl" :src="thumbUrl(m.imageUrl, 'travel_thumb_admin')" alt="" class="h-full w-full object-cover" />
                  <img v-else-if="miniaturaYoutube(m.videoUrl)" :src="miniaturaYoutube(m.videoUrl) ?? ''" alt="" class="h-full w-full object-cover" />
                  <span v-if="m.tipo === 'video'" class="absolute inset-0 flex items-center justify-center text-white"><i class="fas fa-circle-play text-3xl drop-shadow"></i></span>
                  <span v-if="m.esPortada" class="absolute left-1.5 top-1.5 rounded bg-amber-400 px-1.5 py-0.5 text-[9px] font-black uppercase text-amber-950">Portada</span>
                </div>
                <div class="space-y-1.5 p-2">
                  <input :value="m.pie ? textoEs(m.pie) : ''" @blur="guardarPie(m, ($event.target as HTMLInputElement).value)" placeholder="Pie (opcional)"
                         class="w-full rounded-lg border border-slate-200 px-2 py-1 text-[11px]" />
                  <div class="flex items-center gap-1">
                    <button type="button" @click="mover(idx, -1)" :disabled="idx === 0" title="Antes" class="h-7 w-7 rounded-lg border border-slate-200 text-slate-500 disabled:opacity-30"><i class="fas fa-chevron-left text-[10px]"></i></button>
                    <button type="button" @click="mover(idx, 1)" :disabled="idx === medios.length - 1" title="Después" class="h-7 w-7 rounded-lg border border-slate-200 text-slate-500 disabled:opacity-30"><i class="fas fa-chevron-right text-[10px]"></i></button>
                    <button v-if="m.tipo === 'foto'" type="button" @click="ponerPortada(m)" :disabled="m.esPortada" title="Usar de portada" class="h-7 w-7 rounded-lg border border-slate-200 text-amber-500 disabled:opacity-30"><i class="fas fa-star text-[10px]"></i></button>
                    <a v-if="m.tipo === 'video' && m.videoUrl" :href="m.videoUrl" target="_blank" rel="noopener" title="Abrir el vídeo" class="flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-slate-500"><i class="fas fa-up-right-from-square text-[10px]"></i></a>
                    <button type="button" @click="quitar(m)" title="Quitar" class="ml-auto h-7 w-7 rounded-lg border border-red-200 text-red-500"><i class="fas fa-trash text-[10px]"></i></button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <button type="button" @click="borrarAlbum" class="text-xs font-bold text-red-600 hover:underline"><i class="fas fa-trash mr-1"></i> Borrar este álbum</button>
        </section>
      </div>
    </main>
  </div>
</template>
