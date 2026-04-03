//public/push-sw.js
console.log('[push-sw.js] ✅ Script cargado y escudo anti-undefined activado');

// ====================================================================
// FUNCIÓN SANITIZADORA: Limpia la basura que envía el backend
// ====================================================================
function sanitizeUrl(rawUrl) {
    if (!rawUrl) return '/chat';
    const urlStr = String(rawUrl);

    // 1. Si trae la palabra literal undefined o unknown -> Mandar al Inbox general
    if (urlStr.includes('undefined') || urlStr.includes('unknown')) {
        return '/chat';
    }

    // 2. Si viene concatenado sin barra (ej: util.openperu.pe019d4090...)
    if (urlStr.includes('openperu.pe') && !urlStr.includes('/chat')) {
        const parts = urlStr.split('openperu.pe');
        const dirtyId = parts[1] ? parts[1].replace(/^\//, '') : '';

        if (dirtyId && dirtyId.length > 10 && !dirtyId.startsWith('chat')) {
            return `/chat?id=${dirtyId}`;
        } else if (dirtyId && dirtyId.startsWith('chat')) {
            return `/${dirtyId}`;
        }
        return '/chat';
    }

    // 3. Si viene como URL absoluta completa, sacamos solo el path
    if (urlStr.startsWith('http')) {
        try {
            const urlObj = new URL(urlStr);
            return urlObj.pathname + urlObj.search;
        } catch (e) {
            return '/chat';
        }
    }

    return urlStr;
}

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

    // ¡AQUÍ ESTÁ LA MAGIA! Limpiamos la URL antes de hacer cualquier cosa
    const safeUrl = sanitizeUrl(notificationData.actionUrl);
    notificationData.actionUrl = safeUrl; // Se lo devolvemos limpio a Vue

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const isAppFocused = clientList.some((client) => client.focused);

            if (isAppFocused) {
                // Si la app está abierta en pantalla, se lo pasamos a Pinia para el Toast verde
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: notificationData
                    });
                });
            } else {
                // Si la app está cerrada, mostramos la notificación nativa
                return self.registration.showNotification(notificationData.title, {
                    body: notificationData.body,
                    icon: '/app_util/pwa-192x192.png',
                    badge: '/app_util/favicon.svg',
                    data: { url: safeUrl }, // Guardamos la URL ya limpia y segura
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

    // Convertimos la ruta relativa en una URL absoluta basada en el dominio de la app
    // Esto evita para siempre el error de DNS "util.openperu.peundefined"
    const targetUrl = new URL(urlToOpen, self.registration.scope).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // Buscamos si la app ya está abierta en segundo plano
            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                if (client.url && 'focus' in client) {
                    client.navigate(targetUrl); // Navegamos usando la URL segura
                    return client.focus();      // Traemos la app al primer plano
                }
            }

            // Si estaba cerrada por completo, la abrimos
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

// Cuando Vue detecta que los mensajes sin leer llegaron a CERO, nos pide limpiar la barra:
self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_NOTIFICATIONS') {
        self.registration.getNotifications().then((notifications) => {
            notifications.forEach((notification) => {
                notification.close();
            });
        });
        // IMPORTANTE: NO usamos clearAppBadge aquí. El chatStore (Pinia) maneja el número.
    }
});