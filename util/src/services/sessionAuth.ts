import { apiClient, type CustomAxiosRequestConfig, type ErrorSesionMuerta } from './apiClient';
import { esErrorApi } from './apiError';
import { isSessionExpired } from './sessionState';
export { isSessionExpired };

export const checkSession = async (): Promise<boolean> => {
    try {
        const response = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as Partial<CustomAxiosRequestConfig>);
        return !response.headers['content-type']?.includes('text/html');
    } catch (e: unknown) {
        if ((e as ErrorSesionMuerta)?.isSessionDead) return false;
        // Cualquier otro fallo que no sea un 401 se toma como sesión viva
        // (un corte de red no debería desloguear al usuario).
        return !esErrorApi(e) || e.response?.status !== 401;
    }
};

/** Credenciales del formulario de login de Symfony (`/ajax_login`). */
export interface CredencialesLogin {
    _username: string;
    _password: string;
    _remember_me?: boolean;
}

export const renewSession = async (credentials: CredencialesLogin) => {
    await apiClient.post('/ajax_login', credentials);
    isSessionExpired.value = false;
};