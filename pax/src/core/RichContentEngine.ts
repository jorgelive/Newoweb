// src/core/RichContentEngine.ts
import { defineAsyncComponent } from 'vue';

export type BlockType = 'text' | 'component';

export interface RenderBlock {
    id: string;
    type: BlockType;
    component?: any;
    props?: any;
    content?: string;
}

const COMPONENT_REGISTRY: Record<string, any> = {
    'video': defineAsyncComponent(() => import('@/components/RichText/VideoBlock.vue')),
    'img':   defineAsyncComponent(() => import('@/components/RichText/ImageBlock.vue')),
    'map':   defineAsyncComponent(() => import('@/components/RichText/MapBlock.vue')),
    'widget': defineAsyncComponent(() => import('@/components/GuiaUnidad/WifiCardWidget.vue')),
};

export class RichContentEngine {
    private context: any;
    private translator: (c: any) => string;

    constructor(context: any, translatorFn: (c: any) => string) {
        this.context = context || {};
        this.translator = translatorFn;
    }

    // 🔥 INTERPOLACIÓN ROBUSTA (Busca en Fixed -> Translatable)
    private interpolateString(text: string): string {
        const fixed = this.context?.data?.text_fixed || {};
        const translatable = this.context?.data?.text_translatable || {};

        // Regex tolerante a espacios: {{ key }}
        return text.replace(/{{\s*([a-z0-9_]+)\s*}}/gi, (_, key) => {
            const lowerKey = key.toLowerCase();

            // 1. Prioridad: Texto Fijo (Strings directos)
            if (fixed[lowerKey] !== undefined) {
                return `<span class="font-bold text-indigo-600">${fixed[lowerKey]}</span>`;
            }

            // 2. Prioridad: Texto Traducible (Arrays)
            if (translatable[lowerKey] !== undefined) {
                const val = translatable[lowerKey];
                if (Array.isArray(val)) {
                    return `<span class="font-bold text-gray-900">${this.translator(val)}</span>`;
                }
            }

            // 3. Fallback: No encontrado
            return `{{${key}}}`;
        });
    }

    public parse(rawText: string): RenderBlock[] {
        if (!rawText) return [];

        // Normalizar WiFi viejo
        const textToProcess = rawText.replace(/{{\s*wifi_data\s*}}/gi, '{{ widget: wifi }}');

        // Regex Componentes: {{ tipo : valor }}
        const regex = /{{\s*([a-z]+)\s*:\s*(.+?)\s*}}/gi;

        const blocks: RenderBlock[] = [];
        let lastIndex = 0;
        let match;

        while ((match = regex.exec(textToProcess)) !== null) {

            const textBefore = textToProcess.slice(lastIndex, match.index);
            if (textBefore.trim()) {
                blocks.push({
                    id: `txt-${match.index}`,
                    type: 'text',
                    content: this.interpolateString(textBefore)
                });
            }

            const type = match[1].toLowerCase();
            const value = match[2].trim();
            const blockId = `cmp-${match.index}`;

            if (COMPONENT_REGISTRY[type]) {
                blocks.push({
                    id: blockId,
                    type: 'component',
                    component: COMPONENT_REGISTRY[type],
                    props: {
                        src: value,
                        value: value,
                        context: this.context
                    }
                });
            } else {
                console.warn(`[Engine] Componente desconocido: ${type}`);
            }

            lastIndex = regex.lastIndex;
        }

        if (lastIndex < textToProcess.length) {
            blocks.push({
                id: `txt-end`,
                type: 'text',
                content: this.interpolateString(textToProcess.slice(lastIndex))
            });
        }

        return blocks;
    }
}