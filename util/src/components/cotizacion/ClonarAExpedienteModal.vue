<script setup lang="ts">
/**
 * Llevar una cotización ya armada a OTRO expediente, con otras fechas y otros pasajeros, o a un
 * CATÁLOGO de tours como propuesta genérica (07/10/2026).
 *
 * Dos orígenes: desde un expediente (`origen = 'expediente'`) se puede elegir cualquiera de los dos
 * destinos; desde un tour de catálogo (`origen = 'catalogo'`) sólo un expediente, y la fecha es
 * obligatoria porque el tour vive en fechas nominales (2030).
 *
 * El viaje de promoción de un colegio es el del colegio siguiente con otras fechas: los mismos
 * servicios, el mismo orden, los mismos proveedores. Rearmarlo a mano son horas, y el error que
 * importa no es olvidar un servicio —eso se ve— sino **barajar los días**.
 *
 * ⚠️ **Las reglas NO viven aquí.** El desplazamiento por delta y el arrastre de tarifas los decide
 * el backend (`Cotizacion::desplazarA()` y `ajustarPax()`); esto sólo recoge tres datos y los
 * manda. Reimplementar aquí «mover fechas» habría creado una segunda versión de la regla, que es
 * lo que este proyecto paga cada vez que lo hace. Ver `docs/Cotizaciones.md`.
 */
import { ref, computed, watch } from 'vue';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import { apiClient } from '@/services/apiClient';
import SearchableSelect from '@/components/SearchableSelect.vue';

const props = withDefaults(defineProps<{
  /** UUID de la cotización a copiar. `null` mantiene el panel cerrado. */
  cotizacionId: string | null;
  /** Rótulo de cabecera: de qué propuesta se está copiando. */
  titulo: string;
  /** De dónde viene: decide qué destinos se ofrecen y si la fecha es obligatoria. */
  origen?: 'expediente' | 'catalogo';
}>(), { origen: 'expediente' });

/** `catalogo` sólo llega cuando la copia fue a un catálogo: quien abre el modal la abre allí. */
const emit = defineEmits<{ (e: 'cerrar'): void; (e: 'clonada', copia: { id: string; catalogo?: string; file?: string }): void }>();

const fileStore = useCotizacionFileStore();

const tipoDestino = ref<'expediente' | 'catalogo'>('expediente');
const catalogo = ref<string>('');
const catalogos = ref<{ value: string; label: string }[]>([]);
const destino = ref<string>('');
const fechaInicio = ref<string>('');
const numPax = ref<string>('');
const opciones = ref<{ value: string; label: string }[]>([]);
const buscando = ref(false);
const enviando = ref(false);
const aviso = ref<string | null>(null);

// Al abrirlo con otra cotización se limpia todo: dejar el destino anterior puesto es la forma
// más fácil de mandar una copia al expediente equivocado sin notarlo.
watch(() => props.cotizacionId, () => {
  tipoDestino.value = 'expediente';
  catalogo.value = '';
  destino.value = '';
  fechaInicio.value = '';
  numPax.value = '';
  opciones.value = [];
  aviso.value = null;
});

/** Lo lanza el `search` del selector; el filtrado local lo sigue haciendo él sobre lo que llega. */
const buscar = async (texto: string): Promise<void> => {
  buscando.value = true;
  opciones.value = (await fileStore.buscarExpedientes(texto)).map((f) => ({ value: f.id, label: f.nombre }));
  buscando.value = false;
};

/** Los catálogos son pocos: se cargan enteros al elegir ese destino, en su orden de prioridad. */
const cargarCatalogos = async (): Promise<void> => {
  if (catalogos.value.length) return;
  try {
    type FilaCatalogo = { id?: string; '@id'?: string; nombre?: string };
    const res = await apiClient.get<{ member?: FilaCatalogo[]; 'hydra:member'?: FilaCatalogo[] }>(
      '/platform/sales/cotizacion_catalogos?order[orden]=asc',
    );
    // ⚠️ El listado (grupo `catalogo:read`) NO trae `id`, sólo el IRI `@id`: filtrar por `id`
    // dejaba la lista siempre vacía y el botón deshabilitado. Mismo criterio que
    // `CatalogoDashboard.extractId()`.
    catalogos.value = (res.data.member ?? res.data['hydra:member'] ?? [])
      .map((c) => ({ value: String(c.id ?? c['@id'] ?? '').split('/').pop() ?? '', label: c.nombre || 'Catálogo sin nombre' }))
      .filter((c) => c.value !== '');
  } catch {
    aviso.value = 'No se pudieron cargar los catálogos.';
  }
};

watch(tipoDestino, (t) => { if (t === 'catalogo') void cargarCatalogos(); });

/**
 * Sin destino no hay nada que hacer: para copiar dentro del mismo padre ya está el otro botón.
 * Desde un catálogo, además, la fecha: el backend la exige igual (422), pero decirlo aquí
 * ahorra el viaje y el mensaje de error.
 */
const puedeEnviar = computed(() => {
  if (enviando.value) return false;
  if (tipoDestino.value === 'catalogo') return catalogo.value !== '';
  return destino.value !== '' && (props.origen !== 'catalogo' || fechaInicio.value !== '');
});

const confirmar = async (): Promise<void> => {
  if (!props.cotizacionId || !puedeEnviar.value) return;

  const pax = parseInt(numPax.value, 10);
  aviso.value = null;
  enviando.value = true;

  // Lo que no se rellena NO se manda: `undefined` significa «no lo toques», que no es lo mismo
  // que mandar vacío. Es la misma distinción que hace el DTO del backend.
  const aCatalogo = tipoDestino.value === 'catalogo';
  // Al catálogo NO se manda fecha: el backend ancla el día 1 en la base nominal
  // (`CotizacionCatalogo::FECHA_BASE_NOMINAL`), que es lo único que tiene sentido ahí.
  const nuevaId = await fileStore.cloneCotizacion(props.cotizacionId, {
    ...(aCatalogo ? { catalogo: catalogo.value } : { file: destino.value, fechaInicio: fechaInicio.value || undefined }),
    numPax: Number.isFinite(pax) && pax > 0 ? pax : undefined,
  });

  enviando.value = false;

  if (nuevaId) {
    emit('clonada', aCatalogo ? { id: nuevaId, catalogo: catalogo.value } : { id: nuevaId, file: destino.value });
    emit('cerrar');
  } else {
    aviso.value = fileStore.error || 'No se pudo clonar la cotización.';
  }
};
</script>

<template>
  <div v-if="cotizacionId" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4"
       @click.self="emit('cerrar')">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="text-sm font-black text-slate-800">{{ origen === 'catalogo' ? 'Crear expediente desde este tour' : 'Copiar a otro expediente o a un catálogo' }}</h3>
        <p class="text-[11px] text-slate-400 mt-0.5">Desde {{ titulo }}</p>
      </div>

      <div class="px-5 py-4 space-y-4">
        <div v-if="origen === 'expediente'" class="grid grid-cols-2 gap-1 p-1 bg-slate-100 rounded-xl">
          <button type="button" @click="tipoDestino = 'expediente'"
                  :class="tipoDestino === 'expediente' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'"
                  class="py-1.5 text-[11px] font-black rounded-lg transition-colors">
            <i class="fas fa-folder-open mr-1"></i>Otro expediente
          </button>
          <button type="button" @click="tipoDestino = 'catalogo'"
                  :class="tipoDestino === 'catalogo' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'"
                  class="py-1.5 text-[11px] font-black rounded-lg transition-colors">
            <i class="fas fa-store mr-1"></i>Catálogo de tours
          </button>
        </div>

        <template v-if="tipoDestino === 'catalogo'">
          <div>
            <label class="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Catálogo destino</label>
            <SearchableSelect v-model="catalogo" :options="catalogos" placeholder="Elige el catálogo…" />
          </div>
          <div>
            <label class="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Pax base</label>
            <input v-model="numPax" type="number" min="1" placeholder="los mismos"
                   class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:border-sky-400 focus:outline-none">
            <p class="text-[9px] text-slate-400 mt-1 leading-snug">Sobre cuántos se calcula el precio de referencia. Las tarifas grupales no cambian.</p>
          </div>
          <p class="text-[10px] text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 leading-snug">
            Pasa a ser una <b>propuesta genérica</b>: fechas nominales (Día 1, Día 2…), total oculto, sin subgrupos ni
            pasajeros, y <b>sin publicar</b>. Antes de publicarla pon el <b>precio «desde»</b> por persona y revisa que
            ningún texto nombre al cliente.
          </p>
        </template>

        <div v-else>
          <label class="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Expediente destino</label>
          <SearchableSelect
            v-model="destino"
            :options="opciones"
            :min-chars-busqueda="2"
            :limpiable="true"
            placeholder="Escribe dos letras del grupo…"
            @search="buscar"
          />
          <p v-if="buscando" class="text-[9px] text-slate-400 mt-1">Buscando…</p>
        </div>

        <div v-if="tipoDestino === 'expediente'" class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Primer día{{ origen === 'catalogo' ? ' *' : '' }}</label>
            <input v-model="fechaInicio" type="date"
                   class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:border-sky-400 focus:outline-none">
            <!-- Se dice lo que hace, porque «fecha de inicio» se lee como «la fecha del primer
                 servicio» y lo que de verdad ocurre es que se mueve el viaje entero. -->
            <p class="text-[9px] text-slate-400 mt-1 leading-snug">Mueve el viaje entero y conserva la separación entre días.</p>
          </div>

          <div>
            <label class="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Pasajeros</label>
            <input v-model="numPax" type="number" min="1" placeholder="los mismos"
                   class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:border-sky-400 focus:outline-none">
            <p class="text-[9px] text-slate-400 mt-1 leading-snug">Las tarifas grupales no cambian: eso se renegocia.</p>
          </div>
        </div>

        <p v-if="tipoDestino === 'expediente'" class="text-[10px] text-slate-500 bg-slate-50 border border-slate-100 rounded-xl px-3 py-2 leading-snug">
          La copia nace <b>pendiente</b>, <b>sin publicar</b> y sin operaciones. La Biblia y las órdenes siguen
          colgando del original.<template v-if="origen === 'catalogo'"> El total vuelve a ser visible y se quitan los
          precios «desde»: un expediente es un grupo concreto.</template>
        </p>

        <p v-if="aviso" class="text-[11px] font-bold text-red-600">{{ aviso }}</p>
      </div>

      <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-end gap-2">
        <button @click="emit('cerrar')" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
        <button @click="confirmar" :disabled="!puedeEnviar"
                class="px-4 py-1.5 text-xs font-black text-white bg-sky-600 hover:bg-sky-700 rounded-xl disabled:opacity-40 disabled:cursor-not-allowed">
          <i v-if="enviando" class="fas fa-spinner fa-spin mr-1"></i>Copiar
        </button>
      </div>
    </div>
  </div>
</template>
