// util/public/push-sw.js

console.log('[push-sw.js] ✅ Script cargado');

self.addEventListener('push', (event) => {
    let notificationData = {
        title: 'Nueva Notificación',
        body: 'Tienes un mensaje nuevo.',
        actionUrl: '/app_util/'
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
            console.log('[push-sw.js] Push recibido | App en foco:', isAppFocused);

            if (isAppFocused) {
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: notificationData
                    });
                });
            } else {
                if ('setAppBadge' in self.navigator) {
                    self.navigator.setAppBadge()
                        .then(() => console.log('[push-sw.js] ✅ Badge seteado'))
                        .catch((e) => console.error('[push-sw.js] ❌ Error seteando badge:', e));
                } else {
                    console.warn('[push-sw.js] setAppBadge no disponible');
                }

                return self.registration.showNotification(notificationData.title, {
                    body: notificationData.body,
                    icon: '/app_util/pwa-192x192.png',
                    badge: '/app_util/favicon.svg',
                    data: { url: notificationData.actionUrl || '/app_util/' },
                    tag: 'chat-message',
                    renotify: true
                });
            }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if ('clearAppBadge' in self.navigator) {
        self.navigator.clearAppBadge()
            .then(() => console.log('[push-sw.js] ✅ Badge limpiado en notificationclick'))
            .catch((e) => console.error('[push-sw.js] ❌ Error limpiando badge en notificationclick:', e));
    } else {
        console.warn('[push-sw.js] ❌ clearAppBadge no disponible en notificationclick');
    }

    const urlToOpen = event.notification.data.url || '/app_util/';
    console.log('[push-sw.js] notificationclick → abriendo URL:', urlToOpen);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            console.log('[push-sw.js] Clientes encontrados:', windowClients.length);

            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                console.log(`[push-sw.js] Cliente ${i}:`, client.url, '| focused:', client.focused);

                if (client.url && 'focus' in client) {
                    client.navigate(urlToOpen);
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                console.log('[push-sw.js] No había cliente abierto → abriendo nueva ventana');
                return clients.openWindow(urlToOpen);
            }
        })
    );
});

// En el SW, cuando el mensaje CLEAR_BADGE llega:
self.addEventListener('message', (event) => {
    console.log('[push-sw.js] 📨 Mensaje recibido:', JSON.stringify(event.data));

    if (event.data?.type === 'CLEAR_BADGE') {
        // Limpiar badge Web API
        if ('clearAppBadge' in self.navigator) {
            self.navigator.clearAppBadge().catch(() => {});
        }

        // ✅ Esto es lo que realmente limpia el badge en Android:
        // Cerrar todas las notificaciones activas del SW
        self.registration.getNotifications().then((notifications) => {
            console.log('[push-sw.js] Notificaciones activas:', notifications.length);
            notifications.forEach((notification) => {
                notification.close();
                console.log('[push-sw.js] ✅ Notificación cerrada:', notification.title);
            });
        });
    }
});