//src/stores/notificationStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export interface AppNotification {
    id: number;
    title: string;
    body?: string;
    type: 'info' | 'success' | 'warning' | 'error';
    actionUrl?: string;
}

// ====================================================================
// FIX: WHITELIST POR UUID (mismo criterio que push-sw.js)
// ====================================================================
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function buildChatUrl(raw: unknown): string {
    if (!raw) return '/chat';
    const str = String(raw).trim();

    if (UUID_RE.test(str)) return `/chat?id=${str}`;

    try {
        const url = new URL(str, window.location.origin);
        const idParam = url.searchParams.get('id');
        if (idParam && UUID_RE.test(idParam)) return `/chat?id=${idParam}`;
        const lastSegment = url.pathname.split('/').pop() || '';
        if (UUID_RE.test(lastSegment)) return `/chat?id=${lastSegment}`;
    } catch (e) { /* cae al fallback */ }

    return '/chat';
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

export const useNotificationStore = defineStore('notificationStore', () => {

    const getUrls = () => {
        const config = window.OPENPERU_CONFIG || {};
        return {
            api: config.apiUrl || import.meta.env.VITE_API_URL || 'https://api.openperu.pe'
        };
    };

    const apiClient = axios.create({
        baseURL: getUrls().api,
        withCredentials: true,
        headers: { 'Accept': 'application/json' }
    });

    const notifications = ref<AppNotification[]>([]);

    const getNotifications = computed((): AppNotification[] => notifications.value);

    const setNotifications = (newNotifications: AppNotification[]): void => {
        notifications.value = newNotifications;
    };

    const addNotification = (payload: Omit<AppNotification, 'id'>): void => {

        // FIX: los tres "escudos anti-basura" blacklist se reemplazan por
        // una sola línea whitelist: o hay UUID válido, o va al inbox.
        payload.actionUrl = buildChatUrl(payload.actionUrl);

        // Filtro anti-spam: Evita toasts idénticos al mismo tiempo
        const isDuplicate = notifications.value.some(
            n => n.title === payload.title && n.body === payload.body && n.actionUrl === payload.actionUrl
        );

        if (isDuplicate) return;

        const id = Date.now();
        notifications.value.push({ ...payload, id });
        setTimeout(() => removeNotification(id), 5000);
    };

    const removeNotification = (id: number): void => {
        notifications.value = notifications.value.filter(n => n.id !== id);
    };

    // Cada `return false` de aquí deja al usuario sin notificaciones de forma
    // permanente y SILENCIOSA: el backend solo ve "este usuario no tiene
    // dispositivos suscritos" y no hay manera de saber en qué paso se rompió.
    // Por eso cada salida deja rastro en consola con su motivo concreto.
    const subscribeToPushNotifications = async (): Promise<boolean> => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('[push] El navegador no soporta serviceWorker/PushManager.');
            return false;
        }
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                console.warn(`[push] Permiso de notificaciones no concedido (estado: ${permission}).`);
                return false;
            }

            const registration = await navigator.serviceWorker.ready;

            // Sin listener de `push` en el SW activo, la suscripción se registra
            // igual y el servidor recibe 201 del servicio push, pero el
            // dispositivo no muestra nada. Es exactamente el fallo que dejó las
            // notificaciones mudas (ver docs/PwaNotificaciones.md), así que
            // avisamos en vez de dejarlo pasar en silencio.
            if (!registration.active?.scriptURL.includes('util-service-worker')) {
                console.error(
                    '[push] El SW activo NO es el de util:', registration.active?.scriptURL,
                    '— las notificaciones no se mostrarán. Revisa el deploy del service worker.'
                );
            }

            const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;
            if (!vapidPublicKey) {
                console.error('[push] Falta VITE_VAPID_PUBLIC_KEY en el build. Sin ella no se puede suscribir.');
                return false;
            }

            const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);
            const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: convertedVapidKey as BufferSource });
            const subscriptionData = subscription.toJSON();

            await apiClient.post('/user/push-subscription', {
                endpoint: subscriptionData.endpoint,
                p256dh: subscriptionData.keys?.p256dh,
                auth: subscriptionData.keys?.auth,
            });

            console.info('[push] Suscripción registrada en el backend.');
            return true;
        } catch (error) {
            console.error('[push] Falló la suscripción a notificaciones:', error);
            return false;
        }
    };

    const unsubscribeFromPushNotifications = async (): Promise<void> => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                const endpoint = subscription.endpoint;
                await subscription.unsubscribe();
                await apiClient.post('/user/push-unsubscribe', { endpoint });
            }
        } catch (error) {
            console.error('[push] Falló la desuscripción:', error);
        }
    };

    return { getNotifications, setNotifications, addNotification, removeNotification, subscribeToPushNotifications, unsubscribeFromPushNotifications };
});
