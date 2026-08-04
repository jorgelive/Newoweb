// ============================================================================
// SCROLL HORIZONTAL DE LAS VISTAS TIMELINE (FullCalendar)
//
// Compartido por los dos calendarios de casitas —ReservasView y TarifasView—,
// que son la misma rejilla: `resourceTimeline` de uno o dos meses con columnas
// de 24 h. Sin esto, cada vista repetía (y desincronizaba) el mismo cálculo.
//
// Buena parte de lo de aquí es el port del controlador Stimulus legacy
// `assets/controllers/fullcalendar_controller.js`, que ya tenía resuelto el
// scroll a base de cicatrices: la navegación por DIRECCIÓN (`lastViewRange`),
// el `getMainScroller()` por desbordamiento real y —lo importante— el
// `applyScroll()` con REINTENTOS. Se reescribió mirándolo, no de cero.
//
// Lo que no se ve leyendo FullCalendar:
//
// - `gotoDate()` solo coloca el RANGO (el mes). El scroll horizontal se queda
//   donde estaba, así que el día buscado puede acabar fuera de pantalla. Quien
//   mueve el scroll es `scrollToTime()`.
// - `scrollToTime()` NO recibe una fecha, sino una DURACIÓN contada desde
//   `view.activeStart`. De ahí que todo aquí se calcule en «días desde el
//   inicio del rango visible».
// - **Tras cambiar de rango, el scroller no es desplazable de inmediato.** Es
//   la trampa que costó el bug: en el frame siguiente a `datesSet` la rejilla
//   nueva todavía mide `scrollWidth === clientWidth`, así que un
//   `scrollLeft = scrollWidth` se queda en cero y no pasa nada. Hay que
//   reintentar hasta que haya desbordamiento — igual que hacía el legacy.
// ============================================================================

import type { CalendarApi } from '@fullcalendar/core';

const MS_POR_DIA = 86_400_000;

/** Reintentos del scroll: ~1 s en total, los mismos que usaba el legacy. */
const REINTENTOS = 20;
const ESPERA_MS = 50;

/** Días completos entre dos fechas locales. */
function diasEntre(desde: Date, hasta: Date): number {
    return Math.round((hasta.getTime() - desde.getTime()) / MS_POR_DIA);
}

/** Hoy a las 00:00 en hora local (las columnas son días, no instantes). */
export function hoyLocal(): Date {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    return d;
}

/**
 * Inicio del rango que se estaba viendo antes, por calendario.
 *
 * Sirve para saber si el usuario avanzó o retrocedió, que es lo que decide a
 * qué extremo se salta cuando hoy no está a la vista. Va en un `WeakMap` y no
 * en cada vista para que el helper siga siendo la única fuente de esta lógica.
 */
const inicioAnterior = new WeakMap<CalendarApi, number>();

/**
 * Scroller horizontal del cuerpo de la timeline.
 *
 * FullCalendar monta varios `.fc-scroller` (cabecera, columna de casitas,
 * cuerpo) y solo el del cuerpo scrollea en horizontal. El ancla fiable es
 * `.fc-timeline-body`, la tabla de la rejilla: su `.fc-scroller` más cercano es
 * el que hay que mover. Si no aparece, se cae al criterio del legacy —el
 * candidato con desbordamiento real—, que además cubre la clase `.fc-scroller-h`
 * de FullCalendar 5.
 */
function scrollerDelCuerpo(raiz: HTMLElement | null | undefined): HTMLElement | null {
    if (!raiz) return null;

    const porCuerpo = raiz.querySelector('.fc-timeline-body')?.closest('.fc-scroller');
    if (porCuerpo instanceof HTMLElement) return porCuerpo;

    const candidatos = Array.from(raiz.querySelectorAll('.fc-scroller-h, .fc-scroller'));
    const conDesborde = candidatos.find((s) => s.scrollWidth > s.clientWidth + 5);
    return conDesborde instanceof HTMLElement ? conDesborde : null;
}

/**
 * Ejecuta `accion` en cuanto el scroller del cuerpo EXISTE y es desplazable.
 *
 * Sin esta espera el scroll se pierde en silencio: ver la nota de la cabecera
 * sobre el estado del DOM justo después de un cambio de rango. Si el rango
 * entero cabe en pantalla no hay nada que desplazar y los reintentos se agotan
 * sin hacer nada, que es el resultado correcto.
 */
function conScrollerListo(raiz: HTMLElement | null | undefined, accion: (el: HTMLElement) => void): void {
    const intentar = (n: number): void => {
        const el = scrollerDelCuerpo(raiz);
        if (el && el.scrollWidth > el.clientWidth) {
            accion(el);
            return;
        }
        if (n < REINTENTOS) window.setTimeout(() => intentar(n + 1), ESPERA_MS);
    };

    intentar(0);
}

/**
 * Desplaza la línea de tiempo hasta `objetivo`.
 *
 * @param margenDias Días de aire a la izquierda, para que la barra no quede
 *                   pegada al borde del área visible.
 * @returns `false` si el día NO está dentro del rango montado; en ese caso hay
 *          que navegar primero (`gotoDate`) y reintentar, porque el rango nuevo
 *          puede no estar renderizado todavía.
 */
export function scrollTimelineADia(api: CalendarApi, objetivo: Date, margenDias = 1): boolean {
    const inicio = api.view.activeStart;
    if (!inicio || objetivo < inicio || objetivo >= api.view.activeEnd) return false;

    api.scrollToTime({ days: Math.max(0, diasEntre(inicio, objetivo) - margenDias) });
    return true;
}

/** Deja a la vista el extremo derecho del rango: ver el comentario de dentro. */
function scrollAlFinal(api: CalendarApi, activeStart: Date, activeEnd: Date, raiz?: HTMLElement | null): void {
    // Se piden los dos caminos a propósito: `scrollToTime()` es el oficial y su
    // ScrollResponder reaplica la petición en los re-renders que FullCalendar
    // hace al llegar los eventos; el `scrollLeft` directo no depende de que las
    // columnas estén medidas y el navegador lo recorta al máximo real, pero
    // necesita que el scroller ya desborde — de ahí la espera.
    api.scrollToTime({ days: diasEntre(activeStart, activeEnd) });
    conScrollerListo(raiz, (el) => { el.scrollLeft = el.scrollWidth; });
}

/**
 * Encuadre del rango recién montado. Se llama desde `datesSet`.
 *
 * **Manda la DIRECCIÓN de la navegación, no dónde caiga hoy**: al pulsar
 * «anterior» se salta al final del rango —los días pegados a aquel del que
 * vienes— y al pulsar «siguiente», al principio. Criterio heredado del
 * controlador Stimulus legacy (`lastViewRange`).
 *
 * > La primera versión daba prioridad a «si hoy está en el rango, ir a hoy», y
 * > eso rompía el mes en curso y solo el mes en curso: retroceder desde
 * > septiembre a agosto —con hoy en agosto— saltaba al día de hoy en vez de al
 * > final del mes, y parecía un problema de caché. La dirección manda siempre
 * > que haya habido navegación; para ir a hoy ya está el botón «Hoy».
 *
 * Cuando NO hay navegación con la que comparar —primer montaje, o un cambio de
 * vista que conserva el inicio del rango— se encuadra por dónde caiga hoy: a
 * hoy si está dentro, y si no al extremo por el que se llega.
 *
 * @param raiz Elemento que contiene el calendario, para alcanzar el scroller.
 */
export function encuadrarTimeline(api: CalendarApi, raiz?: HTMLElement | null): void {
    const { activeStart, activeEnd } = api.view;
    if (!activeStart || !activeEnd) return;

    const inicio = activeStart.getTime();
    const previo = inicioAnterior.get(api);
    inicioAnterior.set(api, inicio);

    const hoy = hoyLocal();

    // Navegación explícita: manda hacia dónde se fue.
    if (previo !== undefined && inicio !== previo) {
        if (inicio < previo) scrollAlFinal(api, activeStart, activeEnd, raiz);
        else api.scrollToTime({ days: 0 });
        return;
    }

    // Primer montaje o cambio de vista sobre el mismo arranque de rango.
    if (hoy >= activeStart && hoy < activeEnd) scrollTimelineADia(api, hoy);
    else if (hoy >= activeEnd) scrollAlFinal(api, activeStart, activeEnd, raiz);
    else api.scrollToTime({ days: 0 });
}
