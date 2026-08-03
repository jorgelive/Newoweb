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

/**
 * Bloques de MAQUETACIÓN que el editor puede insertar en el cuerpo de un ítem:
 * `{{ video: url }}`, `{{ img: url }}`, `{{ map: coords }}`, `{{ widget: wifi }}`.
 * Se resuelven aquí porque los componentes Vue viven aquí.
 */
const COMPONENT_REGISTRY: Record<string, Component> = {
    'video': defineAsyncComponent(() => import('@/components/RichText/VideoBlock.vue')),
    'img':   defineAsyncComponent(() => import('@/components/RichText/ImageBlock.vue')),
    'map':   defineAsyncComponent(() => import('@/components/RichText/MapBlock.vue')),
    'widget': defineAsyncComponent(() => import('@/components/GuiaUnidad/WifiCardWidget.vue')),
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
        const regex = /{{\s*([a-z]+)\s*:\s*(.+?)\s*}}/gi;

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
                    props: { src: value, value, ...this.datosWidget },
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
