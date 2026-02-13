/// <reference lib="webworker" />

import { precacheAndRoute, createHandlerBoundToURL } from 'workbox-precaching'
import { registerRoute, setCatchHandler } from 'workbox-routing'
import { NetworkFirst, CacheFirst } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'

declare let self: ServiceWorkerGlobalScope

precacheAndRoute(self.__WB_MANIFEST)

// ✅ App Shell físico
const shellHandler = createHandlerBoundToURL('/app_pax/shell.html')

// ✅ Navegación: red primero; si falla, Vue Router se monta con el shell
registerRoute(
    ({ request }) => request.mode === 'navigate',
    new NetworkFirst({
        cacheName: 'pax-html',
        networkTimeoutSeconds: 3,
    })
)

// ✅ Imágenes
registerRoute(
    ({ request }) => request.destination === 'image',
    new CacheFirst({
        cacheName: 'pax-images',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 80,
                maxAgeSeconds: 60 * 60 * 24 * 30,
            }),
        ],
    })
)

// ✅ Catch handler con tipos correctos
setCatchHandler(async ({ event }) => {
    // En Workbox, aquí "event" es FetchEvent
    if (event.request.mode === 'navigate') {
        return shellHandler({ event })
    }

    // Puedes devolver algo mejor para assets si quieres, pero esto está ok:
    return Response.error()
})