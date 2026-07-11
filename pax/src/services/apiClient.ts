// src/services/apiClient.ts
import axios, { type InternalAxiosRequestConfig } from 'axios';

export interface CustomAxiosRequestConfig extends InternalAxiosRequestConfig {
    _retry?: boolean;
    _silentAuthCheck?: boolean;
}

export const getUrls = () => {
    // @ts-ignore
    const config = window.OPENPERU_CONFIG || {};
    return {
        api: config.apiUrl || import.meta.env.VITE_API_URL || 'https://api.openperu.pe',
        panel: config.panelUrl || import.meta.env.VITE_PANEL_URL || 'https://panel.openperu.pe'
    };
};

export const apiClient = axios.create({
    baseURL: getUrls().api,
    withCredentials: true,
    headers: {
        'Accept': 'application/ld+json'
    }
});

apiClient.interceptors.request.use((config) => {
    const method = config.method?.toLowerCase();
    const needsBody = method === 'post' || method === 'put' || method === 'patch';

    if (needsBody && !config.headers['Content-Type']) {
        config.headers['Content-Type'] = method === 'patch'
            ? 'application/merge-patch+json'
            : 'application/ld+json';
    }

    return config;
});

// ============================================================================
// SISTEMA CENTRALIZADO DE COLA (PAUSA Y REANUDACIÓN DE PETICIONES)
// ============================================================================
let failedQueue: { resolve: Function, reject: Function, config: CustomAxiosRequestConfig }[] = [];

/**
 * Procesa la cola de peticiones pausadas.
 * Si recibe un error, rechaza todas (ej: usuario canceló login).
 * Si no, las reintenta automáticamente usando las credenciales renovadas.
 */
export const processQueue = (error: any = null) => {
    failedQueue.forEach(prom => {
        if (error) prom.reject(error);
        else prom.resolve(apiClient(prom.config));
    });
    failedQueue = [];
};

