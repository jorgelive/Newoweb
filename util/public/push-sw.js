// util/public/push-sw.js

console.log('[push-sw.js] ✅ Script cargado');

self.addEventListener('push', (event) => {
    let notificationData = {
        title: 'Nueva Notificación',
        body: 'Tienes un mensaje nuevo.',
        actionUrl: '/chat'
    };

    if (event.data) {
        try {
            const parsedData = event.data.json();
            notificationData = parsedData.payload || parsedData;
        } catch (e) {
            console.error('[push-sw.js] Error al parsear el JSON del Push:', e);
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const isAppFocused = clientList.some((client) => client.focused);

            if (isAppFocused) {
                // Si la app está abierta, se lo pasamos a Pinia (Vue) para que muestre el Toast
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: notificationData
                    });
                });
            } else {
                // Si la app está cerrada, mostramos la notificación nativa del celular/mac
                // NOTA: Ya no usamos setAppBadge aquí, Pinia se encarga de eso.
                return self.registration.showNotification(notificationData.title, {
                    body: notificationData.body,
                    icon: '/app_util/pwa-192x192.png',
                    badge: '/app_util/favicon.svg',
                    data: { url: notificationData.actionUrl || '/chat' },
                    tag: 'chat-message',
                    renotify: true
                });
            }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    // Al tocar la notificación, la cerramos de la barra superior
    event.notification.close();

    const urlToOpen = event.notification.data.url || '/chat';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // Buscamos si la app ya está abierta en segundo plano
            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                if (client.url && 'focus' in client) {
                    client.navigate(urlToOpen); // Navegamos al chat específico
                    return client.focus();      // Traemos la app al primer plano
                }
            }

            // Si estaba cerrada por completo, la abrimos
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});

// Cuando Vue (App.vue) se abre y nos dice que limpiemos la basura:
self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_NOTIFICATIONS') {
        // Cerramos todas las notificaciones de la barra superior de Android
        self.registration.getNotifications().then((notifications) => {
            notifications.forEach((notification) => {
                notification.close();
            });
        });
        // ¡OJO! Ya no ejecutamos clearAppBadge(). Dejamos que el chatStore.ts controle los números del icono.
    }
});