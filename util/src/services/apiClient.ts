// src/services/apiClient.ts
import axios, { type InternalAxiosRequestConfig } from 'axios';
import { isSessionExpired } from './sessionState';

export interface CustomAxiosRequestConfig extends InternalAxiosRequestConfig {
    _retry?: boolean;
    _silentAuthCheck?: boolean;
}

export const getUrls = () => {
    // @ts-ignore
    const config = window.OPENPERU_CONFIG || {};
    return {
        api: config.apiUrl || import.meta.env.VITE_API_URL || 'https://api.openperu.pe',
        panel: config.panelUrl || import.meta.env.VITE_PANEL_URL || 'https://panel.openperu.pe',
        pax: config.paxUrl || import.meta.env.VITE_PAX_URL || 'https://pax.openperu.pe',
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
    const isFormData = config.data instanceof FormData;

    if (needsBody && !isFormData && !config.headers['Content-Type']) {
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

// ============================================================================
// INTERCEPTOR BLINDADO ANTI-HTML (FIREWALL DE SYMFONY)
// ============================================================================
apiClient.interceptors.response.use(
    (response) => {
        const contentType = response.headers['content-type'] || '';

        // Si esperamos JSON pero Symfony nos manda el formulario de Login HTML
        if (contentType.includes('text/html')) {
            const originalRequest = response.config as CustomAxiosRequestConfig;

            if (!originalRequest._retry && !originalRequest._silentAuthCheck) {
                originalRequest._retry = true;

                isSessionExpired.value = true;

                return new Promise((resolve, reject) => {
                    failedQueue.push({ resolve, reject, config: originalRequest });
                });
            }
            const sessionDeadError = new Error('Sesión expirada (HTML detectado)');
            (sessionDeadError as any).isSessionDead = true;
            return Promise.reject(sessionDeadError);
        }
        return response;
    },
    async (error) => {
        const originalRequest = error.config as CustomAxiosRequestConfig;

        if (error.response?.status === 401 && !originalRequest._retry && !originalRequest._silentAuthCheck) {
            originalRequest._retry = true;

            isSessionExpired.value = true;

            return new Promise((resolve, reject) => {
                failedQueue.push({ resolve, reject, config: originalRequest });
            });
        }
        return Promise.reject(error);
    }
);