<script setup lang="ts">
/**
 * Los campos de identidad de un `Organizacion`, en un solo sitio.
 *
 * Lo usan dos pantallas que antes habrían acabado con dos copias del mismo formulario:
 *
 *   · `Catalogo/OrganizacionesView.vue` — el alta y edición de siempre.
 *   · el alta inline del editor de cotizaciones, para no tener que salir a buscar el
 *     catálogo cuando la empresa todavía no existe.
 *
 * Lo segundo es lo que hace que esto tenga que ser un componente y no un bloque copiado:
 * el prestador **debe quedar SIEMPRE identificado contra el maestro** —lo dice el propio
 * `Organizacion::POST`— y la única forma de que eso se cumpla es que dar de alta desde el
 * editor cueste lo mismo que escribir texto libre.
 *
 * ── Qué NO incluye, a propósito ─────────────────────────────────────────────
 * Servicios y galería. Los dos necesitan el IRI del proveedor para colgarse de él, así que
 * sólo tienen sentido cuando ya existe. Meterlos aquí obligaría a que el alta inline
 * pintara secciones muertas.
 *
 * ── El título público se edita en plano ─────────────────────────────────────
 * El campo es `I18nContent[]`, pero se escribe sólo en español: `AutoTranslate` rellena el
 * resto al guardar. La conversión vive aquí dentro para que quien use el componente no
 * tenga que acordarse — era un paso suelto en `guardar()` y es justo lo que se olvida al
 * copiar un formulario.
 */
import { computed } from 'vue';
import ContactoDeIdentidad from '@/components/common/ContactoDeIdentidad.vue';
import {
    tituloEs,
    desdeTituloEs,
    AYUDA_TITULO_PUBLICO,
    AYUDA_VISIBLE_PARA_CLIENTE,
    AYUDA_LUGARES_PROVEEDOR,
    type ProveedorWrite,
    type LugarOpcion,
} from '@/types/organizacionModel';

const props = defineProps<{
    modelValue: ProveedorWrite;
    /** Vocabulario de lugares. Vacío = no se pinta la sección. */
    lugares?: LugarOpcion[];
    /** IRIs marcados. Es la cobertura del proveedor, no su ubicación. */
    lugaresSeleccionados?: string[];
    /** Oculta razón social, dirección y web: el alta inline sólo necesita lo imprescindible. */
    compacto?: boolean;
    /**
     * UUID de la organización **si ya existe**.
     *
     * Es lo que parte el bloque de contacto en dos comportamientos, y la distinción importa:
     *
     *  - **Sin id (alta):** teléfono y correo se teclean. Son la SEMILLA con la que nacerá la
     *    identidad de ese proveedor; sin ellos no hay a quién escribir y el hilo no se abriría.
     *  - **Con id (edición):** dejan de editarse aquí. El dato bueno ya vive en las
     *    identidades, y un `<input>` que se guarda pero no cambia a dónde sale el mensaje es
     *    peor que no tenerlo.
     */
    organizacionId?: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', v: ProveedorWrite): void;
    (e: 'update:lugaresSeleccionados', v: string[]): void;
}>();

/** Escribe un campo sin mutar el objeto del padre. */
const set = <K extends keyof ProveedorWrite>(campo: K, valor: ProveedorWrite[K]): void => {
    emit('update:modelValue', { ...props.modelValue, [campo]: valor });
};

/**
 * El título en español, ida y vuelta. Vacío no oculta —para eso está la casilla— pero
 * deja al proveedor sin nada que mostrar, y el aviso de abajo lo dice.
 */
const tituloPublico = computed<string>({
    get: () => tituloEs(props.modelValue.titulo),
    set: (v) => set('titulo', desdeTituloEs(v)),
});

const alternarLugar = (iri: string): void => {
    const actuales = props.lugaresSeleccionados ?? [];
    const i = actuales.indexOf(iri);

    emit('update:lugaresSeleccionados', i === -1
        ? [...actuales, iri]
        : actuales.filter((x) => x !== iri));
};
</script>

<template>
  <div class="space-y-4">
    <div>
      <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Nombre comercial *</label>
      <input :value="modelValue.nombreComercial" @input="set('nombreComercial', ($event.target as HTMLInputElement).value)"
             type="text"
             class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
      <p class="text-[10px] text-slate-400 mt-1">
        Uso interno: identidad que queda fijada en el histórico financiero. El cliente no lo ve.
      </p>
    </div>

    <!-- La bandera decide SI se nombra; el título, CÓMO. Hacen falta las dos. -->
    <div>
      <label class="flex items-start gap-2 cursor-pointer">
        <input :checked="modelValue.visibleParaCliente"
               @change="set('visibleParaCliente', ($event.target as HTMLInputElement).checked)"
               type="checkbox" class="mt-0.5 w-4 h-4 accent-[#E07845] cursor-pointer" />
        <span>
          <span class="block text-[10px] font-black text-slate-500 uppercase">Nombrable ante el cliente</span>
          <span class="block text-[10px] text-slate-400 mt-0.5">{{ AYUDA_VISIBLE_PARA_CLIENTE }}</span>
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
      <p v-if="modelValue.visibleParaCliente && !tituloPublico.trim()"
         class="text-[10px] mt-1 text-amber-600 font-semibold">
        <i class="fas fa-triangle-exclamation"></i>
        Marcado como nombrable pero sin título: el cliente no verá nada.
      </p>
    </div>

    <!-- ═══ CONTACTO ═════════════════════════════════════════════════════════
         Ya creada: se enseña lo que dice la IDENTIDAD y se edita allí. Ver el prop
         `organizacionId` y `ContactoDeIdentidad`. -->
    <div v-if="organizacionId">
      <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Contacto</label>
      <ContactoDeIdentidad context-type="travel_organizacion" :context-id="organizacionId" />
    </div>

    <!-- Alta: aquí sí se teclean, porque son la semilla de la identidad que va a nacer. -->
    <div v-else class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Teléfono</label>
        <input :value="modelValue.telefono ?? ''" @input="set('telefono', ($event.target as HTMLInputElement).value)"
               type="text"
               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
      </div>
      <div>
        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">
          Email
          <span class="text-slate-300 font-bold normal-case">(le llega la orden)</span>
        </label>
        <input :value="modelValue.email ?? ''" @input="set('email', ($event.target as HTMLInputElement).value)"
               type="email"
               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
      </div>
    </div>

    <template v-if="!compacto">
      <div>
        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Razón social</label>
        <input :value="modelValue.razonSocial ?? ''" @input="set('razonSocial', ($event.target as HTMLInputElement).value)"
               type="text"
               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
      </div>

      <div>
        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Dirección</label>
        <input :value="modelValue.direccion ?? ''" @input="set('direccion', ($event.target as HTMLInputElement).value)"
               type="text"
               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
      </div>

      <div>
        <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Web</label>
        <input :value="modelValue.url ?? ''" @input="set('url', ($event.target as HTMLInputElement).value)"
               type="url" placeholder="https://…"
               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#E07845]" />
      </div>
    </template>

    <!-- Chips y no un multiselect: son pocos y se marcan de un vistazo. -->
    <div v-if="(lugares ?? []).length">
      <label class="block text-[10px] font-black text-slate-500 uppercase mb-1">Lugares donde opera</label>
      <div class="flex flex-wrap gap-1.5">
        <button v-for="l in lugares" :key="l.id" type="button"
                @click="alternarLugar(l['@id'] ?? '')"
                :class="(lugaresSeleccionados ?? []).includes(l['@id'] ?? '')
                    ? 'bg-[#E07845] text-white border-[#E07845]'
                    : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors">
          <i class="fas fa-map-marker-alt mr-1 text-[9px]"></i>{{ l.nombre }}
        </button>
      </div>
      <p class="text-[10px] text-slate-400 mt-1">{{ AYUDA_LUGARES_PROVEEDOR }}</p>
    </div>
  </div>
</template>
