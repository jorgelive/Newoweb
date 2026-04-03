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
        // @ts-ignore
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
        // Validación de URLs corruptas de Mercure (cuando manda "unknown" o "undefined")
        if (payload.actionUrl && (payload.actionUrl.includes('undefined') || payload.actionUrl.includes('unknown'))) {
            payload.actionUrl = undefined;
        }

        // Filtro anti-spam: Si una notificación con el mismo título, cuerpo y URL ya está en pantalla, la ignoramos.
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

    const subscribeToPushNotifications = async (): Promise<boolean> => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Push messaging no es soportado.');
            return false;
        }

        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return false;
            }

            const registration = await navigator.serviceWorker.ready;
            const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;

            if (!vapidPublicKey) {
                console.error('La clave VAPID pública no está configurada en el .env');
                return false;
            }

            const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: convertedVapidKey as BufferSource
            });

            const subscriptionData = subscription.toJSON();

            await apiClient.post('/user/push-subscription', {
                endpoint: subscriptionData.endpoint,
                p256dh: subscriptionData.keys?.p256dh,
                auth: subscriptionData.keys?.auth
            });

            return true;

        } catch (error) {
            console.error('Error al intentar suscribirse:', error);
            return false;
        }
    };

    const unsubscribeFromPushNotifications = async (): Promise<void> => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                const endpoint = subscription.endpoint;
                await subscription.unsubscribe();
                await apiClient.post('/user/push-unsubscribe', { endpoint });
            }
        } catch (error) {
            console.error('Error al desuscribirse:', error);
        }
    };

    return {
        getNotifications,
        setNotifications,
        addNotification,
        removeNotification,
        subscribeToPushNotifications,
        unsubscribeFromPushNotifications
    };
});