<script setup lang="ts">
/**
 * Selector de módulos para la cabecera de cada vista.
 *
 * Sustituye al botón de «volver al inicio» que tenía cada módulo: para pasar de
 * Reservas a Cotizaciones había que ir al portal y volver a entrar. El inicio
 * sigue estando, como una opción más del panel.
 *
 * El catálogo de módulos NO se declara aquí: viene de `@/types/modulosApp`, que
 * es también lo que pinta el mosaico del portal. Un módulo nuevo aparece en los
 * dos sitios sin tocar este archivo.
 *
 * `variante` existe porque las cabeceras del portal no son iguales: las de los
 * calendarios y el chat son oscuras (slate-900) y las de cotizaciones, claras.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { MODULOS_APP, moduloDeRuta, type ModuloApp } from '@/types/modulosApp';

const props = withDefaults(defineProps<{ variante?: 'oscura' | 'clara' }>(), {
    variante: 'oscura',
});

const route = useRoute();
const router = useRouter();

const abierto = ref(false);
const raiz = ref<HTMLElement | null>(null);
const boton = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);

/** Módulo en el que estamos, para marcarlo y no ofrecerlo como salto. */
const actual = computed<ModuloApp | null>(() => moduloDeRuta(route.path));

const ANCHO_PANEL = 288; // w-72
const BORDE = 8;

/**
 * El panel se ancla al BOTÓN, midiéndolo al abrir.
 *
 * Con un `top` fijo no se podía: cada cabecera del portal mide distinto —la de
 * Reservas lleva además la barra de búsqueda debajo— y el panel acababa naciendo
 * detrás de ella, comiéndose sus primeras filas (la de «Inicio»). Medir el botón
 * lo deja siempre justo debajo, en cualquier vista.
 */
const estiloPanel = ref<{ top: string; left: string }>({ top: '0px', left: '0px' });

function colocarPanel(): void {
    const r = boton.value?.getBoundingClientRect();
    if (!r) return;

    estiloPanel.value = {
        top: `${r.bottom + BORDE}px`,
        left: `${Math.max(BORDE, Math.min(r.left, window.innerWidth - ANCHO_PANEL - BORDE))}px`,
    };
}

function alternar(): void {
    if (!abierto.value) colocarPanel();
    abierto.value = !abierto.value;
}

function irA(destino: string): void {
    abierto.value = false;
    if (route.path !== destino) router.push(destino);
}

/**
 * Cierre por clic fuera y por Escape. Va en `document` con captura: dentro de
 * las vistas de calendario hay overlays que se tragan el clic antes de que
 * burbujee, y sin captura el panel se quedaba abierto detrás de ellos.
 *
 * Se comprueban DOS elementos porque el panel se teletransporta a `<body>` y ya
 * no cuelga de `raiz`: sin mirarlo aparte, un clic en el propio panel contaría
 * como «fuera» y lo cerraría antes de navegar.
 */
function alClicFuera(ev: MouseEvent): void {
    if (!abierto.value) return;

    const destino = ev.target as Node;
    const dentro = raiz.value?.contains(destino) || panel.value?.contains(destino);
    if (!dentro) abierto.value = false;
}

function alTeclado(ev: KeyboardEvent): void {
    if (ev.key === 'Escape') abierto.value = false;
}

/** Al girar el móvil o redimensionar, el ancla se mueve: hay que re-medir. */
function alRedimensionar(): void {
    if (abierto.value) colocarPanel();
}

onMounted(() => {
    document.addEventListener('click', alClicFuera, true);
    document.addEventListener('keydown', alTeclado);
    window.addEventListener('resize', alRedimensionar);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', alClicFuera, true);
    document.removeEventListener('keydown', alTeclado);
    window.removeEventListener('resize', alRedimensionar);
});
</script>

<template>
    <div ref="raiz" class="relative shrink-0">
        <button
            ref="boton"
            type="button"
            :title="abierto ? 'Cerrar' : 'Ir a otro módulo'"
            :aria-expanded="abierto"
            aria-haspopup="menu"
            @click="alternar"
            :class="[
                'w-8 md:w-10 h-10 flex items-center justify-center rounded-full transition-colors',
                variante === 'oscura'
                    ? 'bg-slate-800 hover:bg-slate-700 text-white'
                    : 'bg-slate-50 hover:bg-slate-100 text-slate-500 border border-slate-200',
            ]"
        >
            <i class="fas text-sm" :class="abierto ? 'fa-xmark' : 'fa-grip'" aria-hidden="true"></i>
        </button>

        <!--
          El panel se teletransporta a <body>. NO es un adorno: la cabecera que
          monta este selector suele ser un *flex item* con `z-20`, y un flex item
          con z-index —aunque sea `static`— CREA CONTEXTO DE APILAMIENTO. Dentro
          de él, el panel queda encerrado por debajo de todo lo que tenga un
          z-index mayor en la vista: en Reservas, la barra de búsqueda (`z-30`)
          le tapaba su primera fila («Inicio») por mucho z-index que llevara el
          panel. Fuera del árbol el problema desaparece; es el mismo motivo por
          el que tippy monta sus tooltips en `document.body`.

          Sigue siendo `fixed` con coordenadas medidas (ver colocarPanel), así que
          no depende de dónde acabe en el DOM.
        -->
        <Teleport to="body">
            <Transition name="switcher">
                <div v-if="abierto" class="fixed inset-0 z-[60]" @click.self="abierto = false">
                    <div
                        ref="panel"
                        class="fixed w-72 max-w-[calc(100vw-1rem)] max-h-[80vh] overflow-y-auto bg-white rounded-2xl shadow-2xl border border-slate-200 py-2"
                        :style="estiloPanel"
                        role="menu"
                    >
                    <button
                        type="button"
                        role="menuitem"
                        class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-slate-50 transition-colors"
                        @click="irA('/')"
                    >
                        <span class="w-8 h-8 rounded-lg bg-slate-900 text-white flex items-center justify-center shrink-0">
                            <i class="fas fa-house text-xs" aria-hidden="true"></i>
                        </span>
                        <span class="text-sm font-black text-slate-800">Inicio</span>
                    </button>

                    <div v-for="grupo in MODULOS_APP" :key="grupo.titulo" class="mt-1">
                        <p class="px-4 pt-2 pb-1 text-[10px] font-black uppercase tracking-[0.16em]" :class="grupo.color">
                            {{ grupo.titulo }}
                        </p>

                        <button
                            v-for="mod in grupo.modulos"
                            :key="mod.to"
                            type="button"
                            role="menuitem"
                            :disabled="actual?.to === mod.to"
                            class="w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors"
                            :class="actual?.to === mod.to ? 'bg-slate-50 cursor-default' : 'hover:bg-slate-50'"
                            @click="irA(mod.to)"
                        >
                            <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" :class="mod.iconBg">
                                <i class="fas text-xs" :class="[mod.icon, mod.iconColor]" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-bold text-slate-800 truncate">{{ mod.title }}</span>
                            </span>
                            <!-- Punto en vez de texto: en 288 px una etiqueta
                                 «Estás aquí» empujaba el nombre del módulo. -->
                            <span v-if="actual?.to === mod.to" class="w-1.5 h-1.5 rounded-full shrink-0" :class="mod.bar"
                                title="Estás aquí"></span>
                        </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
.switcher-enter-active,
.switcher-leave-active {
    transition: opacity 0.15s ease;
}
.switcher-enter-from,
.switcher-leave-to {
    opacity: 0;
}
</style>
