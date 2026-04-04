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

    // 2. Si viene el dominio pegado (util.openperu.peundefined)
    if (urlStr.includes('openperu.pe')) {
        const parts = urlStr.split('openperu.pe');
        let dirtyId = parts[1] ? parts[1] : '';

        // Removemos slash inicial si lo hay
        dirtyId = dirtyId.replace(/^\//, '');

        if (dirtyId.includes('undefined') || dirtyId.includes('unknown')) {
            return '/chat';
        }

        // Si es un ID real (uuid > 10 chars)
        if (dirtyId && dirtyId.length > 10 && !dirtyId.startsWith('chat')) {
            return `/chat?id=${dirtyId}`;
        } else if (dirtyId && dirtyId.startsWith('chat')) {
            return `/${dirtyId}`; // Ej: /chat?id=...
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

// ====================================================================
// INTERCEPTOR SHARE TARGET (Guardado en IndexedDB)
// ====================================================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Interceptamos la petición POST que manda el Share Target
    if (event.request.method === 'POST' && url.pathname.endsWith('/chat')) {
        event.respondWith((async () => {
            try {
                const formData = await event.request.formData();
                const file = formData.get('shared_file');

                if (file) {
                    await new Promise((resolve, reject) => {
                        const request = indexedDB.open('OpenPeruSharedDB', 1);

                        request.onupgradeneeded = (e) => {
                            const db = e.target.result;
                            if (!db.objectStoreNames.contains('sharedFiles')) {
                                db.createObjectStore('sharedFiles');
                            }
                        };

                        request.onsuccess = (e) => {
                            const db = e.target.result;
                            const tx = db.transaction('sharedFiles', 'readwrite');
                            const store = tx.objectStore('sharedFiles');
                            // Guardamos con llave fija para tener un solo slot limpio
                            store.put(file, 'latest_shared_file');
                            tx.oncomplete = () => resolve();
                            tx.onerror = () => reject(tx.error);
                        };
                        request.onerror = () => reject(request.error);
                    });
                }
            } catch (e) {
                console.error('[push-sw.js] Error interceptando archivo compartido:', e);
            }

            // Redirigimos al usuario a la vista de chat sin recargar la app por POST
            return Response.redirect(url.pathname, 303);
        })());
    }
});


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
        } catch (e) {}
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
                    data: { url: safeUrl }, // URL curada
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

    // Curamos por última vez por si algo se filtró
    let urlToOpen = event.notification.data?.url || '/chat';
    if(String(urlToOpen).includes('undefined') || String(urlToOpen).includes('unknown')) {
        urlToOpen = '/chat';
    }

    // Construimos la ruta segura
    const targetUrl = new URL(urlToOpen, self.registration.scope).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // Buscamos si la app ya está abierta en segundo plano
            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                if (client.url && 'focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
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
            notifications.forEach((notification) => notification.close());
        });
    }
});