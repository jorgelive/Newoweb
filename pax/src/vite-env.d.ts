/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue'
    const component: DefineComponent<{}, {}, any>
    export default component
}

// 1. Tipado para window.OPENPERU_CONFIG
interface Window {
    OPENPERU_CONFIG: {
        apiUrl: string;
    };
}

// 2. Tipado para import.meta.env
interface ImportMetaEnv {
    readonly VITE_API_URL: string;
    // más variables de entorno si las tienes...
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}