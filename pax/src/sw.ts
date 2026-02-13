/// <reference lib="webworker" />

import { precacheAndRoute, createHandlerBoundToURL } from 'workbox-precaching'
import { registerRoute, setCatchHandler } from 'workbox-routing'
import { NetworkFirst, CacheFirst } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'
import type { RouteHandlerCallbackOptions } from 'workbox-core'

declare let self: ServiceWorkerGlobalScope

precacheAndRoute(self.__WB_MANIFEST)

const shellHandler = createHandlerBoundToURL('/app_pax/shell.html')

registerRoute(
    ({ request }) => request.mode === 'navigate',
    new NetworkFirst({
        cacheName: 'pax-html',
        networkTimeoutSeconds: 3,
    })
)

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

setCatchHandler(async ({ event }: RouteHandlerCallbackOptions) => {
    const fetchEvent = event as FetchEvent

    if (fetchEvent.request.mode === 'navigate') {
        // ✅ así se llama, sin “options”
        return (shellHandler as any)({ event: fetchEvent })
    }

    return Response.error()
})