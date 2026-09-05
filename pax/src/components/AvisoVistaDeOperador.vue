<script setup lang="ts">
/**
 * El cartel de «esto lo estás viendo como operador».
 *
 * 🔥 **Nace de un falso fallo.** Con la operativa publicada, el operador abría `pax` y no le pedía
 * el documento: veía el expediente entero y concluía que la puerta estaba abierta para las 133
 * familias. No lo estaba —desde fuera la API contesta `403 IDENTIFICACION_REQUERIDA`—, pero nada
 * en pantalla lo decía.
 *
 * `util` y `pax` comparten dominio de cookie a propósito, así que la sesión del operador llega a
 * la API y se salta puertas sin avisar. Es justo la familia de fallo que este proyecto persigue:
 * el que no da error.
 *
 * ── Qué se dice, y qué NO ───────────────────────────────────────────────────
 * 🔥 **Sólo lo que se saltó en ESTA petición.** La primera versión enumeraba las tres puertas
 * siempre, y el operador pasa la mayor parte del día mirando **confirmadas, enviadas y
 * pendientes**, donde la identificación y el filtrado por persona **no existen**: son de la
 * operativa. Dos tercios del cartel eran ruido, y el ruido enseña a saltarse el tercio que sí
 * importaba.
 *
 * ⚠️ **El veredicto lo manda el servidor** en `saltosDeOperador`, que es quien evaluó las
 * condiciones. Deducirlas aquí sería un segundo juez capaz de discrepar del primero — y
 * discreparía en silencio. Por eso `pax` no necesita recibir `estado` ni `publicado`.
 *
 * ⚠️ **Sin saltos no hay cartel.** Si lo que ves es exactamente lo que ve el cliente, no hay nada
 * de qué avisar: un aviso permanente que casi siempre dice «todo bien» deja de leerse, y con él se
 * pierde el día que sí tenía algo que decir.
 *
 * ── Cómo se dice ────────────────────────────────────────────────────────────
 * ⚠️ **Todas con la misma forma y el mismo signo.** Estuvo escrito como una frase corrida con tres
 * sujetos distintos —«tu sesión se salta», «ve propuestas», «no te pide»— y dos polaridades
 * mezcladas. Se leía dos veces y seguía sin entenderse.
 *
 * ⚠️ **Y se nombra contra quién se comparan**: «que no ve el cliente». «Tres cosas de más» deja sin
 * decir de más *que quién*, y esa comparación es lo único que este cartel existe para contar.
 *
 * ⚠️ **Lo de «sin publicar» va aparte y arriba.** Es lo único que habla de lo que tienes delante y
 * no de un permiso tuyo: decide si puedes enseñar la pantalla o mandar el enlace. Enterrado como
 * un punto más de la lista se leía como una capacidad y no como el estado de la propuesta.
 */
import { computed } from 'vue';

import { usePaxCotizacionStore } from '@/stores/cotizacion/paxCotizacionStore';

const store = usePaxCotizacionStore();

const saltos = computed<string[]>(() => store.file?.saltosDeOperador ?? []);

const sinPublicar = computed(() => saltos.value.includes('sin_publicar'));

/** Lo que se ve de más y es un permiso, no un estado. Vacío en todo lo que no sea una operativa. */
const deMas = computed(() => [
  {
    clave: 'sin_documento',
    texto: 'la operativa sin que te pida el documento',
  },
  {
    clave: 'sin_filtrar',
    texto: 'el viaje entero, no sólo lo de una persona',
  },
].filter(x => saltos.value.includes(x.clave)));
</script>

<template>
  <div v-if="saltos.length"
       class="mb-6 rounded-2xl border-2 border-dashed border-amber-300 bg-amber-50 px-4 py-3">
    <p class="flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-amber-700">
      <i class="fas fa-user-shield"></i>
      Lo estás viendo como operador
    </p>

    <!-- El estado de lo que tienes delante, primero y aparte. -->
    <p v-if="sinPublicar"
       class="mt-1.5 text-[11px] leading-snug text-amber-900 bg-amber-100/70 border border-amber-300 rounded-lg px-2 py-1.5">
      <i class="fas fa-eye-slash mr-1"></i>
      <strong>Sin publicar:</strong> el cliente todavía no lo ve, ni con el enlace.
    </p>

    <template v-if="deMas.length">
      <!-- La «Y» sólo si hay algo delante que continuar: sin la línea de «sin publicar», una
           frase que empieza por conjunción se lee como si faltara un trozo. -->
      <p class="mt-1.5 text-[11px] font-bold text-amber-900">
        <template v-if="sinPublicar">Y ves</template><template v-else>Ves</template>
        {{ deMas.length === 1 ? 'una cosa' : 'dos cosas' }} que no ve el cliente:
      </p>
      <ul class="mt-1 space-y-0.5 text-[11px] leading-snug text-amber-900">
        <li v-for="x in deMas" :key="x.clave" class="flex gap-1.5">
          <span class="text-amber-500">·</span>
          <span>{{ x.texto }}</span>
        </li>
      </ul>
    </template>

    <p class="mt-1.5 text-[11px] leading-snug text-amber-900/80">
      Para verlo como lo ve un pasajero, abre el enlace en una ventana de incógnito.
    </p>
  </div>
</template>
