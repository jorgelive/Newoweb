// util/public/push-sw.js

/**
 * Escucha eventos 'push' enviados desde el servidor Symfony vía WebPush.
 * Este script se ejecuta en segundo plano, independiente de la instancia principal de Vue.
 * Su función principal es decidir si lanza una notificación nativa o si deriva la carga
 * a la aplicación activa.
 */
self.addEventListener('push', (event) => {
    let data = { title: 'Nueva Notificación', body: 'Tienes un mensaje nuevo.', actionUrl: '/app_util/' };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            console.error('Error al parsear el JSON del Push:', e);
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const isAppFocused = clientList.some((client) => client.focused);

            if (isAppFocused) {
                // La App está abierta y en foco. Pasamos el mensaje a Vue para que el notificationStore lo maneje visualmente.
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: data
                    });
                });
            } else {
                // La App está cerrada o minimizada en segundo plano. Lanzamos notificación del Sistema Operativo.
                return self.registration.showNotification(data.title, {
                    body: data.body,
                    icon: '/app_util/pwa-192x192.png',
                    badge: '/app_util/pwa-192x192.png', // Opcional: icono monocromático
                    data: {
                        url: data.actionUrl || '/app_util/'
                    }
                });
            }
        })
    );
});

/**
 * Escucha y maneja el clic sobre la notificación nativa del Sistema Operativo.
 * Redirige al usuario a la URL proporcionada en el payload de la notificación.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        self.clients.openWindow(event.notification.data.url)
    );
});