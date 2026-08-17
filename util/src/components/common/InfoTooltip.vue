<script setup lang="ts">
// ============================================================================
// AYUDA EN UNA «i», NO EN UN PÁRRAFO — solución estándar del proyecto.
//
// Los paneles se habían ido llenando de franjas de texto explicativo: el aviso de
// «habilitar edición», el de la vista dual de monedas, el de por qué un depósito no
// se toca. Cada una tenía su razón de estar, y juntas ocupaban media pantalla de
// cosas que ya sabes y estorban para ver lo que sí cambia: los importes.
//
// La ayuda se lee UNA VEZ. Después es ruido permanente. Por eso vive detrás de una
// «i», y por eso este componente existe: para que la explicación no desaparezca
// —sigue estando, a un toque— sin pagar su espacio en cada apertura del panel.
//
// 👆 EL PUNTO DELICADO: EN TÁCTIL NO HAY «HOVER»
// Un tooltip hecho con `:hover` (o `group-hover` de Tailwind) parece funcionar en el
// móvil, y es un espejismo: el navegador EMULA el hover en el primer toque. Los
// síntomas son de los que se investigan dos veces:
//
//   · El primer toque «hoverea» y el segundo dispara el clic: cualquier botón debajo
//     de un elemento con hover necesita DOS toques.
//   · El hover emulado se queda pegado hasta que tocas en otro sitio, así que el
//     tooltip persiste tapando lo que hay debajo.
//   · Un toque largo abre el menú contextual del sistema encima del tooltip.
//
// Por eso aquí el estado es EXPLÍCITO y no una regla de CSS:
//
//   · Con ratón (`pointer: fine`) abre al pasar por encima y cierra al salir.
//   · En táctil abre y cierra al TOCAR — un toque, no dos —, y se cierra también al
//     tocar fuera o con Escape.
//
// El `touch-action: manipulation` del disparador quita el retardo de 300 ms del
// doble toque, y `-webkit-touch-callout: none` evita el menú contextual del toque
// largo sobre el icono.
//
// Documentado en docs/UI_Componentes_Compartidos.md.
// ============================================================================
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = withDefaults(defineProps<{
    /** Texto de la ayuda. Para algo con formato, usa el slot por defecto. */
    texto?: string;
    /**
     * Lado por el que crece la burbuja.
     *
     * `derecha` la ancla por su borde derecho, que es lo que hay que usar cuando la
     * «i» vive cerca del borde del panel: centrada se saldría de la pantalla.
     */
    lado?: 'izquierda' | 'derecha' | 'centro';
    /** Clases extra para el icono, normalmente el color. */
    claseIcono?: string;
    /** Ancho máximo de la burbuja. */
    ancho?: string;
}>(), {
    texto: '',
    lado: 'derecha',
    claseIcono: 'text-slate-400 hover:text-[#376875]',
    ancho: 'max-w-[17rem]',
});

const abierto = ref(false);
const raiz = ref<HTMLElement | null>(null);

/**
 * ¿Hay un puntero fino (ratón/trackpad)?
 *
 * No se pregunta por «es móvil»: un portátil con pantalla táctil tiene las dos cosas,
 * y lo que decide el comportamiento correcto es si existe un puntero que pueda pasar
 * por encima sin tocar. Se consulta en `onMounted` porque en SSR no hay `matchMedia`.
 */
const conPuntero = ref(true);

const clasesLado = computed(() => ({
    izquierda: 'left-0',
    derecha: 'right-0',
    centro: 'left-1/2 -translate-x-1/2',
}[props.lado]));

function alEntrar(): void {
    if (conPuntero.value) abierto.value = true;
}

function alSalir(): void {
    if (conPuntero.value) abierto.value = false;
}

/**
 * El toque (o el clic) alterna.
 *
 * `preventDefault()` corta el hover y el clic emulados que el navegador táctil
 * dispara después del toque: sin él, el mismo gesto abriría y cerraría la burbuja.
 */
function alTocar(e: Event): void {
    e.preventDefault();
    e.stopPropagation();
    abierto.value = !abierto.value;
}

function alClicFuera(e: MouseEvent | TouchEvent): void {
    if (abierto.value && raiz.value && !raiz.value.contains(e.target as Node)) {
        abierto.value = false;
    }
}

function alEscape(e: KeyboardEvent): void {
    if (e.key === 'Escape') abierto.value = false;
}

onMounted(() => {
    conPuntero.value = window.matchMedia?.('(pointer: fine)').matches ?? true;
    document.addEventListener('click', alClicFuera, true);
    document.addEventListener('touchstart', alClicFuera, true);
    document.addEventListener('keydown', alEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', alClicFuera, true);
    document.removeEventListener('touchstart', alClicFuera, true);
    document.removeEventListener('keydown', alEscape);
});
</script>

<template>
    <span ref="raiz" class="relative inline-flex items-center align-middle">
        <!-- `button` y no un `<i>` suelto: se llega con el tabulador y lo anuncia el
             lector de pantalla. `type="button"` porque esto vive dentro de formularios
             y un botón sin tipo los envía. -->
        <button type="button"
            class="inline-flex items-center justify-center transition-colors info-tooltip-trigger"
            :class="claseIcono"
            :aria-expanded="abierto"
            aria-label="Más información"
            @mouseenter="alEntrar"
            @mouseleave="alSalir"
            @touchstart.passive.stop="() => {}"
            @click="alTocar">
            <i class="fas fa-circle-info text-xs"></i>
        </button>

        <span v-if="abierto" role="tooltip"
            class="absolute top-full mt-1.5 z-40 w-max rounded-lg bg-slate-800 p-3 text-left shadow-xl
                   text-[11px] font-medium text-slate-200 leading-snug normal-case tracking-normal"
            :class="[clasesLado, ancho]">
            <slot>{{ texto }}</slot>
        </span>
    </span>
</template>

<style scoped>
/* Quita el retardo de 300 ms del doble toque y el menú contextual del toque largo:
   los dos convierten un tooltip en algo que «a veces no sale». */
.info-tooltip-trigger {
    touch-action: manipulation;
    -webkit-touch-callout: none;
}
</style>
