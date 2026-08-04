// src/core/RichContentEngine.ts
import { defineAsyncComponent, type Component } from 'vue';

export type BlockType = 'text' | 'component';

export interface RenderBlock {
    id: string;
    type: BlockType;
    component?: Component;
    props?: Record<string, unknown>;
    content?: string;
}

const MediaBloqueada = defineAsyncComponent(() => import('@/components/RichText/MediaBloqueadaBlock.vue'));

/**
 * Bloques de MAQUETACIÓN que el editor puede insertar en el cuerpo de un ítem:
 * `{{ video: url }}`, `{{ img: url }}`, `{{ map: coords }}`, `{{ widget: wifi }}`.
 * Se resuelven aquí porque los componentes Vue viven aquí.
 *
 * Las claves `*bloqueado` NO las escribe el editor: las emite el backend
 * (`PmsGuiaInterpolador::resolverMediaVentana()`) al sustituir un
 * `{{ video_ventana: url }}` cuya ventana está cerrada. El valor que llega es
 * el mensaje de disponibilidad, nunca la URL.
 *
 * `video_ventana` e `img_ventana` están registradas como RED DE SEGURIDAD:
 * si un texto llegara sin pasar por el interpolador, se pinta el marco
 * bloqueado en vez de volcar la URL como texto plano a la vista de cualquiera.
 * En el camino normal nunca llegan: PHP siempre las reescribe.
 */
const COMPONENT_REGISTRY: Record<string, Component> = {
    'video': defineAsyncComponent(() => import('@/components/RichText/VideoBlock.vue')),
    'img':   defineAsyncComponent(() => import('@/components/RichText/ImageBlock.vue')),
    'map':   defineAsyncComponent(() => import('@/components/RichText/MapBlock.vue')),
    'widget': defineAsyncComponent(() => import('@/components/GuiaUnidad/WifiCardWidget.vue')),

    'videobloqueado': MediaBloqueada,
    'imgbloqueado':   MediaBloqueada,
    'video_ventana':  MediaBloqueada,
    'img_ventana':    MediaBloqueada,
};

/**
 * Parte el cuerpo de un ítem en bloques de texto y bloques de componente.
 *
 * LO QUE ESTA CLASE YA NO HACE: interpolar `{{ door_code }}` y compañía. Esa
 * sustitución vivía aquí (`interpolateString`), y para poder hacerla el backend
 * tenía que enviarle al navegador el diccionario COMPLETO de valores —códigos
 * de puerta incluidos— dejando que el front eligiera cuál pintar. Bastaba abrir
 * las herramientas de desarrollo para leerlos antes de tiempo.
 *
 * Ahora los placeholders de DATOS llegan ya resueltos desde PHP
 * (src/Pms/Guia/PmsGuiaInterpolador.php): el valor real solo sale del servidor
 * si el acceso lo permite, y si no, en su lugar viene el mensaje de bloqueo.
 *
 * OJO al tocar la expresión regular: la de PHP no admite `:` dentro de la clave,
 * que es justo lo que deja pasar estos bloques. Si se cambia una, se cambia la
 * otra, o habrá placeholders que un lado sustituye y el otro enseña crudos.
 */
export class RichContentEngine {
    /**
     * @param datosWidget Datos que reciben los bloques de componente. Hoy solo
     *   lo usa el widget de WiFi, que en la guía del huésped viene de
     *   `guia.redesWifi` — y llega vacío si la ventana está cerrada, porque el
     *   backend no manda contraseñas enmascaradas: no las manda.
     */
    constructor(private readonly datosWidget: Record<string, unknown> = {}) {}

    public parse(rawText: string): RenderBlock[] {
        if (!rawText) return [];

        // Normalizar la forma antigua del widget de WiFi.
        const textToProcess = rawText.replace(/{{\s*wifi_data\s*}}/gi, '{{ widget: wifi }}');

        // Componentes: {{ tipo : valor }}
        // El `_` en la clave es lo que permite reconocer `video_ventana` e
        // `img_ventana` si alguna vez llegaran sin reescribir (ver el registro).
        const regex = /{{\s*([a-z_]+)\s*:\s*(.+?)\s*}}/gi;

        const blocks: RenderBlock[] = [];
        let lastIndex = 0;
        let match: RegExpExecArray | null;

        while ((match = regex.exec(textToProcess)) !== null) {

            const textBefore = textToProcess.slice(lastIndex, match.index);
            if (textBefore.trim()) {
                blocks.push({ id: `txt-${match.index}`, type: 'text', content: textBefore });
            }

            const type = match[1].toLowerCase();
            const value = match[2].trim();

            if (COMPONENT_REGISTRY[type]) {
                blocks.push({
                    id: `cmp-${match.index}`,
                    type: 'component',
                    component: COMPONENT_REGISTRY[type],
                    // `tipo` lo usa MediaBloqueadaBlock para elegir silueta e
                    // icono; los demás bloques lo ignoran.
                    props: { src: value, value, tipo: type, ...this.datosWidget },
                });
            } else {
                console.warn(`[Engine] Componente desconocido: ${type}`);
            }

            lastIndex = regex.lastIndex;
        }

        if (lastIndex < textToProcess.length) {
            const resto = textToProcess.slice(lastIndex);
            if (resto.trim()) {
                blocks.push({ id: 'txt-end', type: 'text', content: resto });
            }
        }

        return blocks;
    }
}
