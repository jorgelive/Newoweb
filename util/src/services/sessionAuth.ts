import { apiClient } from './apiClient';
import { isSessionExpired } from './sessionState';
export { isSessionExpired };

export const checkSession = async (): Promise<boolean> => {
    try {
        const response = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as any);
        return !response.headers['content-type']?.includes('text/html');
    } catch (e: any) {
        if (e.isSessionDead) return false;
        return e.response?.status !== 401;
    }
};

export const renewSession = async (credentials: any) => {
    await apiClient.post('/ajax_login', credentials);
    isSessionExpired.value = false;
};