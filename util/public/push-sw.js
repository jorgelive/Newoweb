// public/push-sw.js — v4 (el cambio de versión fuerza la reinstalación del SW)
console.log('[push-sw.js] ✅ v4 cargado — whitelist por UUID + tag por conversación + badge con la app cerrada');

// ====================================================================
// FIX A: WHITELIST POR UUID (reemplaza los escudos blacklist)
// O hay un UUID válido, o va al inbox. Imposible que pase 'unknown',
// 'undefined', teléfonos, dominios pegados o cualquier basura futura.
// ====================================================================
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function buildChatUrl(raw) {
    if (!raw) return '/chat';
    const str = String(raw).trim();

    // Caso 1: es un UUID pelado
    if (UUID_RE.test(str)) return `/chat?id=${str}`;

    // Caso 2: URL relativa o absoluta — extraemos ?id= o el último segmento del path
    try {
        const url = new URL(str, self.location.origin);
        const idParam = url.searchParams.get('id');
        if (idParam && UUID_RE.test(idParam)) return `/chat?id=${idParam}`;
        const lastSegment = url.pathname.split('/').pop() || '';
        if (UUID_RE.test(lastSegment)) return `/chat?id=${lastSegment}`;
    } catch (e) { /* cae al fallback */ }

    return '/chat';
}

// ====================================================================
// FIX B: TOMA DE CONTROL INMEDIATA + PURGA DE NOTIFICACIONES STALE
// Sin esto, el SW viejo (con URLs corruptas en notificaciones ya
// mostradas) sigue vivo hasta que el usuario cierre todas las pestañas.
// ====================================================================
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(Promise.all([
        self.clients.claim(),
        // Cierra cualquier notificación vieja que quedó en la barra del OS
        // con data.url corrupta de versiones anteriores.
        self.registration.getNotifications().then((notifications) => {
            notifications.forEach((n) => n.close());
        })
    ]));
});

// ====================================================================
// INTERCEPTOR SHARE TARGET (sin cambios)
// ====================================================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

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

            return Response.redirect(url.pathname, 303);
        })());
    }
});

// ====================================================================
// PUSH — usa buildChatUrl (whitelist) en vez de sanitizeUrl (blacklist)
// ====================================================================
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

    // FIX A: si el backend algún día manda conversationId crudo, tiene prioridad;
    // si no, se valida/extrae el UUID del actionUrl. Todo lo demás -> /chat.
    const safeUrl = (notificationData.conversationId && UUID_RE.test(notificationData.conversationId))
        ? `/chat?id=${notificationData.conversationId}`
        : buildChatUrl(notificationData.actionUrl);
    notificationData.actionUrl = safeUrl;

    // ====================================================================
    // BADGE DEL ICONO CON LA APP CERRADA
    // --------------------------------------------------------------------
    // Con la app abierta lo pinta noLeidosStore; con la app cerrada no corre
    // nada del front y el único que puede hacerlo es este service worker.
    //
    // El número TIENE que venir en el push: aquí no hay sesión ni acceso a la
    // API para consultarlo. Sin él, Android se limita a contar notificaciones
    // visibles y, como se agrupan por conversación, el icono se quedaba
    // clavado en 1 con dieciséis mensajes esperando.
    //
    // Ojo: que se vea el NÚMERO y no un simple punto depende del lanzador de
    // Android. Samsung One UI pinta la cifra; el lanzador de Pixel, solo el
    // punto. Eso ya no está en nuestras manos.
    // ====================================================================
    const total = notificationData.unreadTotal;
    if (typeof total === 'number' && self.navigator && 'setAppBadge' in self.navigator) {
        if (total > 0) self.navigator.setAppBadge(total).catch(() => {});
        else self.navigator.clearAppBadge?.().catch(() => {});
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const isAppFocused = clientList.some((client) => client.focused);

            if (isAppFocused) {
                clientList.forEach((client) => {
                    client.postMessage({
                        type: 'PUSH_TO_STORE',
                        payload: notificationData
                    });
                });
            } else {
                // El tag agrupa POR CONVERSACIÓN, no por "todo el chat".
                // Con el tag fijo 'chat-message' cada aviso reemplazaba al anterior:
                // aunque escribieran cinco huéspedes distintos quedaba una sola
                // notificación, y el contador del icono no pasaba nunca de 1.
                // Con un tag por conversación, un mensaje nuevo del MISMO huésped
                // sigue reemplazando al suyo (que es lo que se quiere: no acumular
                // veinte avisos del mismo chat) pero cada conversación suma la suya.
                const conversationTag = safeUrl.startsWith('/chat?id=')
                    ? `chat-${safeUrl.slice('/chat?id='.length)}`
                    : 'chat-inbox';

                return self.registration.showNotification(notificationData.title, {
                    body: notificationData.body,
                    icon: '/app_util/pwa-192x192.png',
                    badge: '/app_util/favicon.svg',
                    data: { url: safeUrl },
                    tag: conversationTag,
                    renotify: true
                });
            }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // FIX A: re-validación whitelist (cubre notificaciones creadas por SW viejos)
    const urlToOpen = buildChatUrl(event.notification.data?.url);
    const targetUrl = new URL(urlToOpen, self.registration.scope).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (let i = 0; i < windowClients.length; i++) {
                let client = windowClients[i];
                if (client.url && 'focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_NOTIFICATIONS') {
        self.registration.getNotifications().then((notifications) => {
            notifications.forEach((notification) => notification.close());
        });
    }
});
