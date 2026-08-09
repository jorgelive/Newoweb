# PWA y notificaciones push (util)

Cómo llega un mensaje nuevo del chat al teléfono de un operador, qué piezas tienen que
estar alineadas para que eso ocurra, y las trampas que ya rompieron el flujo en
producción sin dejar un solo error en los logs.

Alcance: la PWA de `util/` (la de `pax/` no tiene push, pero **comparte docroot** y por eso
aparece aquí). El disparo desde el dominio de mensajería está en
[`Mensajeria.md`](Mensajeria.md).

## Índice

1. [El recorrido completo](#1-el-recorrido-completo)
2. [Las piezas y dónde viven](#2-las-piezas-y-dónde-viven)
3. [Gotcha: un docroot, dos PWAs](#3-gotcha-un-docroot-dos-pwas)
4. [Gotcha: el fallo es silencioso en las dos puntas](#4-gotcha-el-fallo-es-silencioso-en-las-dos-puntas)
5. [Gotcha: el badge del icono](#5-gotcha-el-badge-del-icono)
6. [Un push fallido no debe callar al resto](#6-un-push-fallido-no-debe-callar-al-resto)
7. [Cómo diagnosticar cuando "no llegan las notificaciones"](#7-cómo-diagnosticar-cuando-no-llegan-las-notificaciones)
8. [Dónde tocar para cambiar X](#8-dónde-tocar-para-cambiar-x)

---

## 1. El recorrido completo

```
Huésped escribe (Beds24 / WhatsApp)
        │
        ▼
MessageConversation.unreadCount sube  ──► postUpdate de Doctrine
        │
        ▼
MessageConversationMercureListener::safeDispatchPushNotifications()
        │  filtra usuarios con ROLE_MENSAJES_SHOW (vía RoleHierarchy)
        ▼
WebPushNotificationService::sendToUser()
        │  busca las filas de push_subscription del usuario
        │  cifra con VAPID y hace flush() a FCM / APNs
        ▼
   [ servidor push del fabricante ]  ← responde 201 si lo ACEPTA
        │                               (201 NO significa "el usuario lo vio")
        ▼
Service worker del dispositivo: evento `push`
        │
        ├── app enfocada  → postMessage al cliente → toast dentro de la app
        └── app cerrada   → showNotification()     → aviso del sistema operativo
```

El eslabón que rompe sin avisar es el último: **si el service worker instalado no tiene
listener de `push`, todo lo anterior sale perfecto y con 201, y el teléfono no muestra
nada.**

## 2. Las piezas y dónde viven

| Pieza | Archivo | Qué hace |
|---|---|---|
| Disparo | `MessageConversationMercureListener::safeDispatchPushNotifications()` | Elige destinatarios por rol y arma el payload |
| Envío | `WebPushNotificationService::sendToUser()` | Cifra VAPID, encola y hace `flush()` |
| Persistencia | `PushSubscription` + `PushSubscriptionController::subscribe()` | Una fila por dispositivo y usuario |
| Alta desde el cliente | `util/src/stores/notificationStore.ts` → `subscribeToPushNotifications()` | Pide permiso, se suscribe y hace POST al backend |
| Recepción | `util/public/push-sw.js` | **Único sitio con el listener de `push`** |
| SW contenedor | generado por VitePWA → `public/util-service-worker.js` | Precache + `importScripts('/app_util/push-sw.js')` |
| Registro | `templates/util/app.html.twig` y el shell de `util/scripts/pwa-postbuild.mjs` | `register('/util-service-worker.js', { scope: '/' })` |

`push-sw.js` **no** es un service worker independiente: entra al SW generado a través de
`workbox.importScripts` en `util/vite.config.ts`. Si esa línea desaparece, no hay error de
build ni de runtime — solo silencio.

## 3. Gotcha: un docroot, dos PWAs

`util.openperu.pe` y `pax.openperu.pe` **son el mismo vhost de nginx**, con un solo
`server_name` compartido y un solo `root /var/www/openperu.pe/public`. Son orígenes
distintos para el navegador (cada uno con su registro de service worker), pero **leen el
mismo archivo del disco**.

Cuando los dos `vite.config.ts` declaraban `filename: '../service-worker.js'`, ambos builds
escribían en `public/service-worker.js` y **el último en correr borraba el del otro**.

Eso fue exactamente lo que pasó el 2026-08-09 en producción:

```
public/app_util/push-sw.js    01:40   ← deploy de util
public/service-worker.js      02:40   ← lo pisó un build de pax
```

El resultado: la PWA de util registraba `/service-worker.js` y recibía **el service worker
de pax**, cuyo precache apuntaba a `/app_pax/...` y que no importa `push-sw.js` ni tiene
listener de `push`. El backend enviaba, FCM devolvía 201 y ningún teléfono mostraba nada.
No hubo un solo error, ni en el build ni en los logs del servidor.

**La regla ahora:** cada app emite su SW con nombre propio.

| App | `filename` en su `vite.config.ts` | Archivo | Lo registra |
|---|---|---|---|
| util | `../util-service-worker.js` | `public/util-service-worker.js` | `templates/util/app.html.twig`, shell de `pwa-postbuild.mjs` |
| pax | `../pax-service-worker.js` | `public/pax-service-worker.js` | `templates/pax/app.html.twig` |

`public/service-worker.js` quedó **obsoleto**: hay que borrarlo del servidor. Mientras
exista, un cliente viejo puede seguir sirviéndose de él. El postbuild de pax avisa si lo
encuentra.

> **Si añades una tercera PWA**, dale su propio `filename` y su propia entrada en
> `.gitignore`. Nunca el nombre genérico.

Guardas que impiden la recaída (las tres fallan el build o el deploy, no avisan y siguen):

- `util/scripts/pwa-postbuild.mjs` — comprueba que `util-service-worker.js` existe,
  referencia `/app_util/shell.html` **y contiene `/app_util/push-sw.js`**.
- `pax/scripts/pwa-postbuild.mjs` — comprueba que emitió su propio SW y que **no** dejó
  assets de `/app_pax/` dentro del SW de util.
- `util/scripts/pwa-verify-deploy.mjs` (`npm run verify:deploy`) — contra el dominio real:
  que los cuatro artefactos respondan 200 **y** que el SW servido importe `push-sw.js` y no
  mezcle assets de pax. Un 200 no basta: el bug de agosto daba 200 en todo.

## 4. Gotcha: el fallo es silencioso en las dos puntas

Las dos capas se tragaban el error, y por eso el diagnóstico costó lo que costó:

- **Servidor.** Cuando un usuario no tiene dispositivos, `sendToUser()` deja solo un
  `WARNING`. Es lo único que se ve, y apunta al usuario equivocado: dice "jorge.gomez no
  tiene dispositivos" cuando el operador en realidad entra como `admin`, que **sí** los
  tiene. El warning es ruido de fondo, no la causa.
- **Cliente.** `subscribeToPushNotifications()` tenía `catch (error) { return false; }` sin
  una sola traza. Fallara el permiso, la clave VAPID o el POST, el resultado era idéntico e
  invisible. Ahora cada salida deja un `console.warn/error` con su motivo, y avisa si el SW
  activo no es el de util.

**201 del servidor push no significa entregado.** Significa que FCM/APNs aceptó el mensaje.
Todo lo que pase después ocurre en el dispositivo y no vuelve por ningún log.

## 5. Gotcha: el badge del icono

Dos cosas distintas pintan el globito del icono, y se confundían entre sí:

1. **Con la app abierta** — `chatStore.ts` observa las conversaciones que llegan por Mercure
   y llama a `navigator.setAppBadge()`.
2. **Con la app cerrada** — lo pone el sistema operativo al mostrarse la notificación. Sin
   `showNotification()` no hay globito.

Dos bugs que hacían que el número se quedara clavado en 1:

- `chatStore.ts` contaba **conversaciones con no leídos** (`.filter(...).length`) en vez de
  **sumar los mensajes sin leer**. Con un solo huésped escribiendo, siempre 1. Ahora usa
  `reduce((total, c) => total + c.unreadCount, 0)`.
- `push-sw.js` usaba `tag: 'chat-message'` **fijo**: cada aviso reemplazaba al anterior, así
  escribieran cinco huéspedes distintos. Ahora el tag es por conversación
  (`chat-<uuid>`), de modo que un mensaje nuevo del mismo huésped sigue reemplazando al
  suyo — no queremos veinte avisos del mismo chat — pero cada conversación suma el suyo.

## 6. Un push fallido no debe callar al resto

`sendToUser()` **ya no lanza excepciones** por fallos de entrega. Antes, un endpoint que
devolviera un HTTP distinto de 2xx lanzaba `RuntimeException`, que subía hasta el `foreach`
de destinatarios del listener y **dejaba sin notificación a todos los usuarios que vinieran
detrás en la lista**. Un dispositivo muerto es un caso normal, no un error del envío
completo. Los fallos quedan en el log como `critical` y el reparto continúa.

Los 404/410 siguen borrando la fila de `push_subscription`: el endpoint ya no existe y el
navegador creará otro en la próxima suscripción.

## 7. Cómo diagnosticar cuando "no llegan las notificaciones"

En orden, porque cada paso descarta el anterior:

1. **¿Con qué usuario entra el operador?** El warning del log nombra al usuario que el
   backend intentó notificar, que puede no ser el que usa el operador.
   ```sql
   SELECT u.username, COUNT(p.id) FROM user u
   LEFT JOIN push_subscription p ON p.user_id = u.id GROUP BY u.username;
   ```
   Sin filas para ese usuario, el problema es el alta en el cliente (paso 4).
2. **¿El servidor push acepta?** Si hay filas y no hay `critical` en el log, acepta. Para
   confirmarlo mandando uno de verdad, `bin/console app:test-push`.
3. **¿El SW desplegado es el correcto?** Es el paso que casi nadie mira y el que falló:
   ```
   cd util && npm run verify:deploy
   ```
   Comprueba contenido, no solo códigos HTTP.
4. **¿Se dio de alta el dispositivo?** Consola del navegador con la PWA abierta: las trazas
   `[push]` dicen en qué paso se rompió. En `chrome://serviceworker-internals` se ve qué
   script controla el origen.

## 8. Dónde tocar para cambiar X

| Necesidad | Archivo | Método / clave |
|---|---|---|
| **Añadir una PWA nueva** | su `vite.config.ts` | `filename` con nombre propio + entrada en `.gitignore`. Nunca `service-worker.js`, §3 |
| Cambiar el texto o el enlace del aviso | `MessageConversationMercureListener` | `safeDispatchPushNotifications()` — array `$payload` |
| Cambiar quién recibe los avisos | `MessageConversationMercureListener` | el filtro `ROLE_MENSAJES_SHOW`; la herencia sale de `security.yaml` |
| Cambiar cómo se pinta la notificación del SO | `util/public/push-sw.js` | `showNotification()` — sube la versión del encabezado para forzar reinstalación |
| Cambiar el agrupado de notificaciones | `util/public/push-sw.js` | `conversationTag` — el tag fijo era el bug del "siempre 1", §5 |
| Cambiar el número del icono con la app abierta | `util/src/stores/chat/chatStore.ts` | el `watch` del badge — suma `unreadCount`, no cuenta conversaciones, §5 |
| Qué pasa si la app está enfocada al llegar un push | `util/public/push-sw.js` | rama `isAppFocused` → `postMessage`, lo recoge `App.vue` |
| Rotar las llaves VAPID | `.env.local` **y** `util/.env.production` | `VAPID_PUBLIC_KEY` ↔ `VITE_VAPID_PUBLIC_KEY` son **espejo**: si difieren, el servidor push rechaza con 403. Cambiarlas invalida todas las suscripciones existentes |
| Que un dispositivo caído no calle a los demás | `WebPushNotificationService::sendToUser()` | No lanza excepciones a propósito, §6 |
| Verificar un deploy de la PWA | — | `cd util && npm run verify:deploy [url]`, §3 |
| Mandar un push de prueba | — | `php bin/console app:test-push` — usa la primera suscripción que encuentre |
