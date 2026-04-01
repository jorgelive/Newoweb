// util/src/stores/notificationStore.ts
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

/**
 * Función utilitaria para convertir la clave VAPID de Base64 segura para URL a un Uint8Array.
 * Esto es requerido por la API del navegador (PushManager).
 * @param {string} base64String Clave VAPID pública.
 * @returns {Uint8Array} Arreglo de bytes.
 */
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

/**
 * Store global para gestionar el estado de las notificaciones visuales (Toasts) en toda la aplicación,
 * y para gestionar la suscripción del navegador a las notificaciones Push (VAPID).
 */
export const useNotificationStore = defineStore('notificationStore', () => {

    /**
     * Obtiene las URLs base de la API desde la configuración global o variables de entorno.
     * Mantiene la consistencia dinámica con el resto de la aplicación (ej. chatStore).
     * @returns {{api: string}} Objeto con la URL de la API.
     */
    const getUrls = () => {
        // @ts-ignore
        const config = window.OPENPERU_CONFIG || {};
        return {
            api: config.apiUrl || import.meta.env.VITE_API_URL || 'https://api.openperu.pe'
        };
    };

    /**
     * Instancia configurada de Axios exclusiva para este store.
     * Garantiza que las peticiones apunten al dominio correcto y envíen las cookies de sesión.
     */
    const apiClient = axios.create({
        baseURL: getUrls().api,
        withCredentials: true,
        headers: { 'Accept': 'application/json' }
    });

    /**
     * @type {import('vue').Ref<AppNotification[]>} Arreglo interno reactivo que mantiene la cola de notificaciones.
     */
    const notifications = ref<AppNotification[]>([]);

    /**
     * Obtiene explícitamente la lista de notificaciones activas.
     * @returns {AppNotification[]} Arreglo de notificaciones encoladas.
     */
    const getNotifications = computed((): AppNotification[] => notifications.value);

    /**
     * Establece explícitamente el estado completo de notificaciones.
     * @param {AppNotification[]} newNotifications Nuevo arreglo de notificaciones a establecer.
     */
    const setNotifications = (newNotifications: AppNotification[]): void => {
        notifications.value = newNotifications;
    };

    /**
     * Añade una nueva notificación a la cola visual y programa su eliminación automática.
     * @param {Omit<AppNotification, 'id'>} payload Los datos de la notificación sin requerir un ID manual.
     */
    const addNotification = (payload: Omit<AppNotification, 'id'>): void => {
        const id = Date.now();
        notifications.value.push({ ...payload, id });
        setTimeout(() => removeNotification(id), 5000);
    };

    /**
     * Elimina explícitamente una notificación específica de la cola mediante su identificador.
     * @param {number} id El identificador único (timestamp) de la notificación a remover.
     */
    const removeNotification = (id: number): void => {
        notifications.value = notifications.value.filter(n => n.id !== id);
    };

    /**
     * Solicita permisos al navegador y registra la suscripción Push en el backend de Symfony.
     * ¿Por qué existe?: Es el puente necesario para que el Service Worker reciba notificaciones en segundo plano.
     */
    const subscribeToPushNotifications = async (): Promise<void> => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Push messaging no es soportado por este navegador.');
            return;
        }

        try {
            // 1. Pedir permiso al usuario explícitamente
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                console.warn('Permiso de notificaciones denegado por el usuario.');
                return;
            }

            // 2. Obtener el Service Worker activo
            const registration = await navigator.serviceWorker.ready;

            const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;

            if (!vapidPublicKey) {
                console.error('La clave VAPID pública no está configurada en el .env');
                return;
            }

            const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);

            // 3. Suscribir el navegador usando la clave pública
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: convertedVapidKey as BufferSource
            });

            // 4. Extraer los datos brutos de la suscripción
            const subscriptionData = subscription.toJSON();

            // 5. Enviar a tu backend Symfony usando el apiClient dinámico
            await apiClient.post('/user/push-subscription', {
                endpoint: subscriptionData.endpoint,
                p256dh: subscriptionData.keys?.p256dh,
                auth: subscriptionData.keys?.auth
            });

            console.log('Suscripción Push registrada exitosamente en el backend.');

        } catch (error) {
            console.error('Error al intentar suscribirse a las notificaciones Push:', error);
        }
    };

    /**
     * Cancela la suscripción Push local del navegador y elimina el registro en el backend.
     * ¿Por qué existe?: Para garantizar la privacidad del usuario al cerrar sesión, evitando
     * que sus notificaciones sigan llegando a una computadora compartida.
     */
    const unsubscribeFromPushNotifications = async (): Promise<void> => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                const endpoint = subscription.endpoint;

                // 1. Desuscribir a nivel de navegador (Chrome/Safari)
                await subscription.unsubscribe();

                // 2. Avisarle a Symfony que elimine este endpoint de la BD
                await apiClient.post('/user/push-unsubscribe', { endpoint });

                console.log('Suscripción Push eliminada exitosamente.');
            }
        } catch (error) {
            console.error('Error al intentar desuscribirse de las notificaciones Push:', error);
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