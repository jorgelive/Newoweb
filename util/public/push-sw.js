// util/public/push-sw.js

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
            console.error('Error al parsear el JSON del Push:', e);
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const isAppFocused = clientList.some((client) => client.focused);

            if (isAppFocused) {
                // App en foco → mensaje a Vue, sin badge
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: notificationData
                    });
                });
            } else {
                // App en segundo plano → notificación nativa + badge del SO
                if ('setAppBadge' in self.navigator) {
                    self.navigator.setAppBadge().catch(() => {});
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

    // ✅ Limpiar badge al hacer click en la notificación
    if ('clearAppBadge' in self.navigator) {
        self.navigator.clearAppBadge().catch(() => {});
    }

    const urlToOpen = event.notification.data.url || '/app_util/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                if (client.url && 'focus' in client) {
                    client.navigate(urlToOpen);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});

// ✅ Nuevo: recibe la señal desde App.vue para limpiar el badge
self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_BADGE') {
        if ('clearAppBadge' in self.navigator) {
            self.navigator.clearAppBadge().catch(() => {});
        }
    }
});