<script setup lang="ts">
// ============================================================================
// TIRAR PARA RECARGAR
//
// ── Por qué hace falta escribirlo ───────────────────────────────────────────
// El gesto nativo sólo funcionaba en el Home, y no por casualidad: es la única vista cuya raíz
// scrollea (`h-screen overflow-y-auto`), así que el navegador la promociona a *root scroller* y
// le da el tirón-para-recargar. Las demás son `h-screen … overflow-hidden` con scrollers
// internos —la forma normal de una app con cabecera fija— y ahí no hay root scroller que
// promocionar: el gesto sencillamente no existe.
//
// ── Por qué vive en el shell y no en cada vista ─────────────────────────────
// Porque hay NUEVE vistas sin él y el editor de cotizaciones tiene doce scrollers. Cablear
// «el principal» de cada una es elegir a mano doce veces y olvidarse en la trece. Esto se
// engancha en `document` y busca el scroller bajo el dedo, sea cual sea.
//
// ── Qué hace: RECARGAR, no refrescar datos ─────────────────────────────────
// Igual que el nativo del Home, para que el gesto signifique lo mismo en toda la app. Un
// refetch por vista sería más elegante y más peligroso: si a alguna se le olvida una fuente,
// el gesto MIENTE —dice que refrescó y algo se quedó viejo—, y eso no se descubre hasta que
// alguien decide sobre un dato caducado. Además la recarga trae versión nueva si la hay.
//
// El día que se quiera refetch, `alRecargar` es el único punto que cambia.
// ============================================================================

import { ref, onMounted, onUnmounted } from 'vue';

/** Cuánto hay que tirar para que cuente. Generoso a propósito: ver el aviso de abajo. */
const UMBRAL = 88;

/** Resistencia: el dedo recorre más de lo que baja el indicador, como en el gesto nativo. */
const ROCE = 2.4;

const distancia = ref(0);
const recargando = ref(false);

let inicioY = 0;
let inicioX = 0;
/** `false` mientras el gesto no pueda contar: no se empezó arriba del todo, o ya se descartó. */
let armado = false;
/** El scroller bajo el dedo. `null` es legítimo: una pantalla que no scrollea también cuenta. */
let scroller: Element | null = null;
/** null = todavía no se sabe si el dedo va en vertical u horizontal. */
let esVertical: boolean | null = null;

/**
 * El scroller vertical más cercano al dedo, o `null` si no hay ninguno.
 *
 * Se sube por los padres porque el dedo casi nunca aterriza sobre el contenedor que scrollea:
 * cae sobre una fila, un texto o una imagen dentro de él.
 */
const VETADO = Symbol('sin-recarga');

function scrollerDe(nodo: EventTarget | null): Element | null | typeof VETADO {
    let el = nodo instanceof Element ? nodo : null;

    while (el) {
        // `data-sin-recarga` es la salida de emergencia para lo que maneje su propio táctil.
        //
        // ⚠️ Devuelve VETADO y no `null`: `null` significa «no hay scroller», que es una razón
        // para ARMAR el gesto —una pantalla corta también se tira—, así que confundir los dos
        // haría que el veto activara justo lo que venía a impedir.
        if (el instanceof HTMLElement && el.dataset.sinRecarga !== undefined) return VETADO;

        const estilo = getComputedStyle(el);

        if (/(auto|scroll|overlay)/.test(estilo.overflowY) && el.scrollHeight > el.clientHeight) {
            return el;
        }

        el = el.parentElement;
    }

    return null;
}

function alEmpezar(e: TouchEvent): void {
    if (recargando.value || e.touches.length !== 1) return;

    const dedo = e.touches[0];

    const encontrado = scrollerDe(e.target);

    if (encontrado === VETADO) {
        armado = false;

        return;
    }

    scroller = encontrado;
    esVertical = null;
    inicioY = dedo.clientY;
    inicioX = dedo.clientX;

    // Sólo desde arriba del todo. Sin scroller es una pantalla que no scrollea: también vale.
    armado = scroller === null || scroller.scrollTop <= 0;
}

function alMover(e: TouchEvent): void {
    if (recargando.value || !armado) return;

    const dedo = e.touches[0];
    const dy = dedo.clientY - inicioY;
    const dx = dedo.clientX - inicioX;

    // ⚠️ La dirección se decide UNA vez y no se revisa. Sin esto, un arrastre horizontal en el
    // calendario o en una tabla ancha empezaba a armar el gesto en cuanto temblaba el pulgar.
    if (esVertical === null) {
        if (Math.abs(dy) < 6 && Math.abs(dx) < 6) return;

        esVertical = Math.abs(dy) > Math.abs(dx);
    }

    if (!esVertical || dy <= 0) {
        distancia.value = 0;

        return;
    }

    // Y el scroller pudo bajar entre el touchstart y ahora (inercia): se comprueba otra vez.
    if (scroller !== null && scroller.scrollTop > 0) {
        distancia.value = 0;

        return;
    }

    distancia.value = Math.min(dy / ROCE, UMBRAL * 1.5);

    // Sólo se bloquea el desplazamiento cuando ya se está tirando de verdad, para no robarle
    // el primer píxel de scroll a quien sólo quería subir la lista.
    if (distancia.value > 4 && e.cancelable) e.preventDefault();
}

function alSoltar(): void {
    if (recargando.value) return;

    if (distancia.value >= UMBRAL) {
        recargando.value = true;
        // El indicador se queda puesto: la recarga tarda lo suyo y sin él parece que no pasó nada.
        window.location.reload();

        return;
    }

    distancia.value = 0;
    armado = false;
    scroller = null;
    esVertical = null;
}

onMounted(() => {
    // Captura, para verlo antes que los `@touchstart` de las vistas —el pulsado largo del chat,
    // por ejemplo—, que siguen funcionando: no se detiene la propagación.
    //
    // `touchmove` NO puede ser pasivo: necesita `preventDefault()` para que el scroller no se
    // mueva mientras se tira.
    document.addEventListener('touchstart', alEmpezar, { passive: true, capture: true });
    document.addEventListener('touchmove', alMover, { passive: false, capture: true });
    document.addEventListener('touchend', alSoltar, { passive: true, capture: true });
    document.addEventListener('touchcancel', alSoltar, { passive: true, capture: true });
});

onUnmounted(() => {
    document.removeEventListener('touchstart', alEmpezar, true);
    document.removeEventListener('touchmove', alMover, true);
    document.removeEventListener('touchend', alSoltar, true);
    document.removeEventListener('touchcancel', alSoltar, true);
});
</script>

<template>
    <!-- `pointer-events-none`: es un aviso, no algo que se pueda tocar. -->
    <div v-if="distancia > 0 || recargando"
         class="fixed top-0 left-1/2 z-[9998] pointer-events-none"
         :style="{
             transform: `translate(-50%, ${Math.max(distancia, recargando ? UMBRAL : 0)}px)`,
             transition: distancia === 0 && !recargando ? 'transform .2s ease-out' : 'none',
         }">
        <div class="mt-2 w-10 h-10 rounded-full bg-white shadow-lg border border-slate-200 flex items-center justify-center">
            <i class="fas fa-arrow-down text-[#376875] text-sm transition-transform duration-200"
               :class="{
                   'fa-spin fa-circle-notch': recargando,
                   'rotate-180': !recargando && distancia >= UMBRAL,
               }"></i>
        </div>
    </div>
</template>
