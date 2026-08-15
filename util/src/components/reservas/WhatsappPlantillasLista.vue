<script setup lang="ts">
/**
 * Lista de plantillas de WhatsApp "link" para una reserva, con el enlace ya resuelto.
 *
 * Vive aparte porque tiene DOS consumidores —el submenú del menú contextual del
 * calendario (`ReservasView`) y la subbarra de `ReservaEditDrawer`— y lo que comparten
 * no es el aspecto sino las reglas: qué plantillas salen y cómo se abre cada una. Si
 * cada vista las reimplementara, la del drawer nacería ya divergida de la del
 * calendario, que es la que lleva tiempo en producción.
 *
 * Sólo pinta las FILAS: el contenedor (dropdown del menú, popover de la barra) lo pone
 * cada consumidor, porque ahí sí son cosas distintas.
 */
import { ref, computed, onMounted } from 'vue';
import { useChatStore, type ApiTemplate } from '@/stores/chat/chatStore';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { whatsappUrl } from '@/types/pmsReservaModel';

// El backend expone `hasWhatsappLinkContent()` pero el serializer de Symfony recorta el
// prefijo `has`, así que en la API el campo llega como `whatsappLinkContent` (verificado
// en api:openapi:export). Aún no está en el schema generado de api.d.ts, de ahí la
// extensión manual del tipo.
type ApiTemplateWA = ApiTemplate & { whatsappLinkContent?: boolean };

const props = defineProps<{
    reservaId: string;
}>();

const emit = defineEmits<{
    /** Se eligió una plantilla con enlace válido: el consumidor cierra su contenedor. */
    elegido: [];
    /** Falló la carga o TODAS las plantillas fallaron: el consumidor decide cómo avisar. */
    error: [mensaje: string];
}>();

const chatStore = useChatStore();
const reservasStore = useReservasStore();

const cargando = ref(false);

/**
 * La restricción de canal: sólo salen las plantillas con cuerpo de enlace. Una plantilla
 * de Beds24 o de la API de Meta no tiene `whatsappLinkTmpl` y aquí no significa nada —
 * este menú abre wa.me, no encola un envío.
 */
const plantillas = computed(() => (chatStore.templates as ApiTemplateWA[]).filter(t => t.whatsappLinkContent));

// ============================================================================
// POR QUÉ EL ENLACE SE RESUELVE ANTES DE PINTARLO
//
// El flujo natural sería: pulsar plantilla → `await` al backend para resolver el
// texto → `window.open()`. En iOS, y en particular en la PWA instalada (standalone),
// ese `window.open()` ya no cuenta como parte del gesto del usuario y el sistema lo
// descarta EN SILENCIO: sin error, sin ventana, el usuario cree que la app se colgó.
//
// Solución: al abrir la lista se resuelven los enlaces de todas las plantillas y cada
// una se pinta como un `<a href>` de verdad. El toque del usuario navega directamente —
// no hay ventana emergente que bloquear. Son N peticiones pequeñas en paralelo, y sólo
// de las plantillas con contenido de enlace (suelen ser un puñado).
// ============================================================================

/** templateId -> URL de WhatsApp ya resuelta. Vacío mientras carga o si falló. */
const links = ref<Record<string, string>>({});

async function cargar(): Promise<void> {
    cargando.value = true;
    try {
        if (!chatStore.templates.length) await chatStore.fetchTemplates();

        // allSettled: que una plantilla rota (variable sin resolver, teléfono ausente)
        // no deje sin enlace a las demás.
        const resueltos = await Promise.allSettled(
            plantillas.value.map(t => reservasStore.fetchWhatsappLink(props.reservaId, t.id ?? '')),
        );

        const mapa: Record<string, string> = {};
        resueltos.forEach((r, i) => {
            const id = plantillas.value[i]?.id;
            if (id && r.status === 'fulfilled') mapa[id] = whatsappUrl(r.value.telefono, r.value.texto);
        });
        links.value = mapa;

        if (plantillas.value.length && !Object.keys(mapa).length) {
            const primero = resueltos.find(r => r.status === 'rejected') as PromiseRejectedResult | undefined;
            emit('error', extractApiErrorMessage(primero?.reason, 'No se pudo generar el mensaje de WhatsApp.'));
        }
    } catch {
        emit('error', 'No se pudieron cargar las plantillas de WhatsApp.');
    } finally {
        cargando.value = false;
    }
}

// Se carga al montar: los dos consumidores montan la lista con `v-if` en el momento de
// abrirla, así que montar y abrir son el mismo instante.
onMounted(cargar);
</script>

<template>
    <div v-if="cargando" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-400">
        <i class="fas fa-spinner fa-spin w-4"></i> Cargando plantillas…
    </div>

    <p v-else-if="!plantillas.length" class="px-4 py-2.5 text-xs font-bold text-slate-400">
        No hay plantillas de WhatsApp configuradas.
    </p>

    <!-- <a> real con el enlace ya resuelto: el toque del usuario navega sin window.open(),
         que la PWA de iOS bloquea en silencio (ver el bloque de comentarios del script).
         Sin enlace, la fila queda inerte en vez de desaparecer: que una plantilla conocida
         falte sin explicación se lee como que el sistema la perdió. -->
    <a v-for="t in plantillas" :key="t.id ?? ''"
        :href="links[t.id ?? ''] || undefined"
        :target="links[t.id ?? ''] ? '_blank' : undefined" rel="noopener"
        @click="links[t.id ?? ''] && emit('elegido')"
        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-sm font-bold text-slate-700"
        :class="links[t.id ?? ''] ? 'hover:bg-slate-50 cursor-pointer' : 'opacity-40 cursor-not-allowed'">
        <i class="fab fa-whatsapp w-4 text-emerald-500 shrink-0"></i> <span class="truncate">{{ t.name }}</span>
    </a>
</template>
