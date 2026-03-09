/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue'
    const component: DefineComponent<{}, {}, any>
    export default component
}

// Tipado para la variable inyectada desde Symfony Twig
interface Window {
    OPENPERU_CONFIG: {
        apiUrl: string;
    };
}

// Tipado para el .env local de Vite
interface ImportMetaEnv {
    readonly VITE_API_URL: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}