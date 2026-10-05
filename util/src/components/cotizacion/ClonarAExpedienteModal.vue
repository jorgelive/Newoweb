<script setup lang="ts">
/**
 * Llevar una cotización ya armada a OTRO expediente, con otras fechas y otros pasajeros.
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
import SearchableSelect from '@/components/SearchableSelect.vue';

const props = defineProps<{
  /** UUID de la cotización a copiar. `null` mantiene el panel cerrado. */
  cotizacionId: string | null;
  /** Rótulo de cabecera: de qué propuesta se está copiando. */
  titulo: string;
}>();

const emit = defineEmits<{ (e: 'cerrar'): void; (e: 'clonada'): void }>();

const fileStore = useCotizacionFileStore();

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

/** Sin destino no hay nada que hacer: para copiar dentro del mismo expediente ya está el otro botón. */
const puedeEnviar = computed(() => destino.value !== '' && !enviando.value);

const confirmar = async (): Promise<void> => {
  if (!props.cotizacionId || !puedeEnviar.value) return;

  const pax = parseInt(numPax.value, 10);
  aviso.value = null;
  enviando.value = true;

  // Lo que no se rellena NO se manda: `undefined` significa «no lo toques», que no es lo mismo
  // que mandar vacío. Es la misma distinción que hace el DTO del backend.
  const ok = await fileStore.cloneCotizacion(props.cotizacionId, {
    file: destino.value,
    fechaInicio: fechaInicio.value || undefined,
    numPax: Number.isFinite(pax) && pax > 0 ? pax : undefined,
  });

  enviando.value = false;

  if (ok) {
    emit('clonada');
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
        <h3 class="text-sm font-black text-slate-800">Copiar a otro expediente</h3>
        <p class="text-[11px] text-slate-400 mt-0.5">Desde {{ titulo }}</p>
      </div>

      <div class="px-5 py-4 space-y-4">
        <div>
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

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-[10px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Primer día</label>
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

        <p class="text-[10px] text-slate-500 bg-slate-50 border border-slate-100 rounded-xl px-3 py-2 leading-snug">
          La copia nace <b>pendiente</b> y sin operaciones. La Biblia y las órdenes siguen colgando del original.
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
