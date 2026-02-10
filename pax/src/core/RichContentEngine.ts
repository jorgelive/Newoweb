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
    // Multimedia (Basado en URL)
    'video': defineAsyncComponent(() => import('@/components/RichText/VideoBlock.vue')),
    'img':   defineAsyncComponent(() => import('@/components/RichText/ImageBlock.vue')),
    'map':   defineAsyncComponent(() => import('@/components/RichText/MapBlock.vue')),

    // Widgets Lógicos (Basado en Contexto)
    'widget': defineAsyncComponent(() => import('@/components/GuiaUnidad/WifiCardWidget.vue')),
};

export class RichContentEngine {
    private context: any;
    private translator: (c: any) => string;

    constructor(context: any, translatorFn: (c: any) => string) {
        this.context = context || {};
        this.translator = translatorFn;
    }

    // REEMPLAZO DE VARIABLES {{ key }}
    private interpolateString(text: string): string {
        const replacements = this.context?.data?.replacements || {};

        return text.replace(/{{\s*([a-z0-9_]+)\s*}}/gi, (_, key) => {
            const val = replacements[key.toLowerCase()];

            // A. Array -> Traducción
            if (Array.isArray(val)) return `<span class="font-bold text-gray-900">${this.translator(val)}</span>`;

            // B. String -> Texto
            if (val !== undefined) return `<span class="font-bold text-indigo-600">${val}</span>`;

            return `{{${key}}}`;
        });
    }

    // PARSER {{ tipo : valor }}
    public parse(rawText: string): RenderBlock[] {
        if (!rawText) return [];

        const regex = /{{\s*([a-z]+)\s*:\s*(.+?)\s*}}/gi;
        const blocks: RenderBlock[] = [];
        let lastIndex = 0;
        let match;

        while ((match = regex.exec(rawText)) !== null) {
            // Texto Previo
            const textBefore = rawText.slice(lastIndex, match.index);
            if (textBefore.trim()) {
                blocks.push({
                    id: `txt-${match.index}`,
                    type: 'text',
                    content: this.interpolateString(textBefore)
                });
            }

            // Componente
            const type = match[1].toLowerCase();
            const value = match[2].trim();
            const blockId = `cmp-${match.index}`;

            if (COMPONENT_REGISTRY[type]) {
                blocks.push({
                    id: blockId,
                    type: 'component',
                    component: COMPONENT_REGISTRY[type],
                    props: {
                        src: value,       // URL cruda
                        value: value,     // Alias
                        context: this.context // Data completa
                    }
                });
            } else {
                console.warn(`[Engine] Componente desconocido: ${type}`);
            }

            lastIndex = regex.lastIndex;
        }

        // Texto Final
        if (lastIndex < rawText.length) {
            blocks.push({
                id: `txt-end`,
                type: 'text',
                content: this.interpolateString(rawText.slice(lastIndex))
            });
        }

        return blocks;
    }
}