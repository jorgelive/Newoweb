// util/public/push-sw.js

self.addEventListener('push', (event) => {
    // Valores por defecto por si falla algo
    let notificationData = {
        title: 'Nueva Notificación',
        body: 'Tienes un mensaje nuevo.',
        actionUrl: '/app_util/'
    };

    if (event.data) {
        try {
            const parsedData = event.data.json();

            // Desenvolvemos el payload que envía Symfony: { type: 'PUSH_TO_STORE', payload: {...} }
            if (parsedData.payload) {
                notificationData = parsedData.payload;
            } else {
                // Por si en algún momento pruebas enviando un JSON directo sin la envoltura
                notificationData = parsedData;
            }
        } catch (e) {
            console.error('Error al parsear el JSON del Push:', e);
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const isAppFocused = clientList.some((client) => client.focused);

            if (isAppFocused) {
                // La App está abierta y en foco. Pasamos el mensaje a Vue.
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: notificationData // Ahora sí pasamos los datos limpios
                    });
                });
            } else {
                // La App está en segundo plano o cerrada. Lanzamos notificación nativa.
                return self.registration.showNotification(notificationData.title, {
                    body: notificationData.body,
                    icon: '/app_util/pwa-192x192.png',
                    badge: '/app_util/favicon.svg', // Recomendado que sea un SVG/PNG monocromático
                    data: {
                        url: notificationData.actionUrl || '/app_util/'
                    },
                    tag: 'chat-message', // Agrupa notificaciones para no saturar la pantalla
                    renotify: true
                });
            }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const urlToOpen = event.notification.data.url || '/app_util/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(windowClients) {
            // Buscamos si ya hay una pestaña de la PWA abierta
            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                // Si está abierta, la enfocamos y le cambiamos la URL si es necesario
                if (client.url && 'focus' in client) {
                    client.navigate(urlToOpen);
                    return client.focus();
                }
            }
            // Si la app estaba totalmente cerrada, abrimos una ventana nueva
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});