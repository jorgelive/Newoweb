<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { usePaxHuespedGuiaStore } from '@/stores/huesped/paxHuespedGuiaStore';
import { useMaestroStore } from '@/stores/maestroStore';
import type { GuiaItem, GuiaSeccion } from '@/types/paxHuespedGuiaModel';

/**
 * El enlace de una ficha a otra: `{{ ficha: equipaje-horarios-flexibles }}`.
 *
 * ── Por qué se resuelve AQUÍ y no en el servidor ────────────────────────────
 * El navegador ya tiene el árbol **podado**: lo que este huésped no puede ver no está. Así, un
 * enlace a una ficha que no le corresponde —podada por canal, o de otra casita— sencillamente no
 * encuentra destino y **no se pinta**. Resolverlo en el servidor obligaría a repetir esa poda en
 * otro sitio, y dos sitios que deciden lo mismo se desincronizan.
 *
 * ── Qué enseña ──────────────────────────────────────────────────────────────
 * El TÍTULO de la ficha destino en el idioma del huésped, no el código: el código es la clave del
 * editor y no significa nada para quien lee. Navega con la misma query que el resto de la guía
 * (`?section=…&item=…`), así que el «atrás» del móvil retrocede un nivel como siempre.
 */
const props = defineProps<{ value: string }>();

const store = usePaxHuespedGuiaStore();
const maestroStore = useMaestroStore();
const router = useRouter();

/** La sección y el ítem con ese código, o `null` si este huésped no lo tiene delante. */
const destino = computed<{ seccion: GuiaSeccion; item: GuiaItem } | null>(() => {
    const codigo = props.value.trim().toLowerCase();

    for (const seccion of store.guia?.secciones ?? []) {
        for (const item of seccion.items ?? []) {
            if ((item.codigo ?? '').toLowerCase() === codigo) {
                return { seccion, item };
            }
        }
    }

    return null;
});

const titulo = computed(() => destino.value ? maestroStore.traducir(destino.value.item.titulo) : '');

const abrir = () => {
    if (!destino.value) return;

    router.push({ query: { section: destino.value.seccion.id, item: destino.value.item['@id'] } });
};
</script>

<template>
  <!-- Sin destino no se pinta nada: ver el bloque de comentarios del script. -->
  <button v-if="destino" type="button" @click="abrir"
          class="inline-flex items-center gap-2 text-left font-bold text-[#0F766E] underline decoration-2 underline-offset-4 hover:opacity-80">
    <i class="fas fa-arrow-right-long text-xs" aria-hidden="true"></i>
    <span>{{ titulo }}</span>
  </button>
</template>
