# PMS Beds24 — Sincronización de Reservas

Documento de arquitectura del sistema bidireccional de sincronización de reservas entre Beds24 y el PMS interno. Cubre los dos caminos de entrada (Webhook en tiempo real y Pull por Cron), el Persister compartido, el mecanismo de espejo virtual y el ciclo Push de vuelta a Beds24.

---

## Índice

1. [Visión General](#1-visión-general)
2. [Entidades Clave](#2-entidades-clave)
3. [Camino A — Webhook (Tiempo Real)](#3-camino-a--webhook-tiempo-real)
4. [Camino B — Pull por Cron (Sincronización Programada)](#4-camino-b--pull-por-cron-sincronización-programada)
5. [Persister Compartido — BookingPullPersister](#5-persister-compartido--bookingpullpersister)
6. [El Mecanismo de Espejo Virtual](#6-el-mecanismo-de-espejo-virtual)
7. [Camino C — Push de vuelta a Beds24](#7-camino-c--push-de-vuelta-a-beds24)
8. [Motor de Exchange — ExchangeOrchestrator](#8-motor-de-exchange--exchangeorchestrator)
9. [Anti-duplicación y Seguridad](#9-anti-duplicación-y-seguridad)
10. [Políticas de Reintento y Error](#10-políticas-de-reintento-y-error)
11. [Dónde tocar para cambiar X](#11-dónde-tocar-para-cambiar-x)

---

## 1. Visión General

El sistema mantiene sincronizadas en tiempo real las reservas entre Beds24 (gestor de canal) y el PMS, soportando **doble publicación** en canales OTA (ej: Booking.com + Airbnb) a través del concepto de **Establecimiento Virtual** (listing espejo).

```
                    ┌─────────────────────────────────────────────────────┐
                    │                     BEDS24                          │
                    │  Listing Principal (Booking.com) + Espejo (Airbnb)  │
                    └────────────┬────────────────────────┬───────────────┘
                                 │ Webhook (15s delay)    │ Cron Pull
                                 │ POST /pms/webhooks/    │ GET /bookings
                                 ▼                        ▼
                    ┌────────────────────────────────────────────────────┐
                    │                      PMS                           │
                    │  PmsReserva → PmsEventoCalendario → PmsEventoBeds24Link(s) │
                    └────────────────────────┬───────────────────────────┘
                                             │ Push (Messenger async)
                                             ▼
                    ┌─────────────────────────────────────────────────────┐
                    │  Beds24 API v2 (POST /bookings)                     │
                    │  Principal: datos reales | Espejo: bloqueo calendario│
                    └─────────────────────────────────────────────────────┘
```

**Regla de oro del flujo:** Beds24 es la fuente de verdad para fechas y estado en reservas OTA. El PMS es la fuente de verdad para datos del huésped en reservas directas.

---

## 2. Entidades Clave

### Jerarquía de persistencia

```
PmsEstablecimiento (Hotel físico)
  └── PmsUnidad (Habitación / Unidad)
        └── PmsUnidadBeds24Map  [N por unidad]
              ├── beds24PropertyId  → qué cuenta Beds24
              ├── beds24RoomId      → qué habitación en Beds24
              └── virtualEstablecimiento → PmsEstablecimientoVirtual
                    ├── nombre: "Saphy"
                    ├── codigo: "SAPHY"
                    ├── codigoExterno: "hotel_id_en_booking_com"
                    └── esPrincipal: true/false

PmsReserva  (Reserva madre / cabecera)
  ├── beds24MasterId          → ID de la reserva grupo en Beds24
  ├── beds24BookIdPrincipal   → ID del booking principal
  ├── datosLocked             → true cuando llegaron datos fuertes (apellido/email/tel)
  └── establecimiento, nombreCliente, apellidoCliente, email, telefono...

PmsEventoCalendario  (Evento de calendario, hijo de PmsReserva)
  ├── pmsUnidad     → unidad física asignada
  ├── inicio / fin  → con horas de check-in/out del establecimiento
  ├── estado        → PmsEventoEstado (confirmed, cancelled, request, abierto...)
  ├── isOta         → si el canal es una OTA
  └── beds24Links   → colección de PmsEventoBeds24Link

PmsEventoBeds24Link  (Puente técnico Evento ↔ Beds24)
  ├── beds24BookId       → ID de la reserva en Beds24 (solo en el principal)
  ├── esPrincipal        → true = link dueño del ID | false = espejo sin ID propio
  ├── unidadBeds24Map    → mapa que apunta al listing específico
  ├── status             → active | detached | pending_delete | pending_move
  └── lastSeenAt         → última vez que Beds24 confirmó este link
```

### Tablas de cola

| Entidad | Tabla | Propósito |
|---|---|---|
| `PmsBeds24WebhookAudit` | `pms_beds24_webhook_audit` | Auditoría raw de cada webhook recibido |
| `PmsBookingsPullQueue` | `pms_bookings_pull_queue` | Jobs de descarga programada (Cron) |
| `PmsBookingsPushQueue` | `pms_bookings_push_queue` | Jobs de subida a Beds24 (Push) |

---

## 3. Camino A — Webhook (Tiempo Real)

**Archivos relevantes:**
- `src/Pms/Controller/Webhook/Beds24WebhookController.php`
- `src/Pms/Dispatch/ProcessBeds24WebhookDispatch.php`
- `src/Pms/DispatchHandler/ProcessBeds24WebhookDispatchHandler.php`
- `src/Pms/Service/Beds24/Webhook/Beds24WebhookBookingFastTrackService.php`

### 3.1 Recepción (Síncrona, < 5ms)

```
POST /pms/webhooks/endpoint
  Header: X-Beds24-Webhook-Token  (o ?token= en query string)
  Body:   payload JSON de Beds24

Beds24WebhookController::__invoke()
  ├─ INSERT PmsBeds24WebhookAudit (status=QUEUED) → flush
  ├─ Valida token presente → 403 si falta
  ├─ Calcula delay anti-duplicado (ver §9.1)
  └─ dispatch(ProcessBeds24WebhookDispatch(auditId, rawPayload, token))
       Con DelayStamp(15000ms) si el evento tiene < 10 min de antigüedad
  └─ Responde 200 { ok: true, status: "queued_for_processing" }
```

El controlador **no procesa nada**: solo audita y encola. Beds24 recibe 200 en < 5ms.

### 3.2 Worker Asíncrono

```
ProcessBeds24WebhookDispatchHandler::__invoke()
  ├─ Recarga PmsBeds24WebhookAudit por auditId
  ├─ Valida Beds24Config por token (isActivo)
  ├─ SyncContext::enter(MODE_PULL, 'beds24')  ← blinda contra bucle Push
  ├─ Parsea rawPayload → separa 'booking' y 'messages'
  │
  ├─ payload['booking'] existe?
  │    └─ handleBookings() → Beds24WebhookBookingFastTrackService::process()
  │         ├─ Abre transacción
  │         ├─ Beds24BookingDto::fromArray($bookingData)
  │         ├─ BookingPullPersister::upsert($config, $dto)
  │         ├─ em->flush() + commit
  │         └─ Devuelve ['success'=>true, 'id'=>$dto->id]
  │
  ├─ payload['messages'] existe?
  │    └─ handleMessages() → Beds24ReceivePersister::upsertMessages()
  │         (Guarda mensajes en Beds24ReceiveQueue para el módulo de mensajería)
  │
  └─ Actualiza audit: status = PROCESSED | PARTIAL_ERROR | ERROR
```

### 3.3 Estructura del payload de Beds24

```json
{
  "timeStamp": "2025-07-30T14:32:00",
  "booking": {
    "id": 83116820,
    "masterId": null,
    "bookingGroup": { "master": null, "ids": [83116820] },
    "propertyId": 12345,
    "roomId": 501,
    "status": "confirmed",
    "arrival": "2025-08-15",
    "departure": "2025-08-18",
    "firstName": "Ana",
    "lastName": "García",
    "email": "ana@email.com",
    "phone": "+34600000000",
    "country2": "ES",
    "lang": "es",
    "channel": "booking.com",
    "apiReference": "BDC-1234567",
    "price": "450.00",
    "commission": "67.50",
    "bookingTime": "2025-07-30T14:30:00",
    "modifiedTime": "2025-07-30T14:32:00"
  },
  "messages": [
    { "id": 1001, "bookingId": 83116820, "source": "guest", "time": "...", "message": "..." }
  ]
}
```

**Campos críticos para el enrutamiento interno:**
- `propertyId` + `roomId` → identifican el `PmsUnidadBeds24Map`
- `bookingGroup.master` / `masterId` → determinan si es madre o hija de grupo
- `channel` → determina si es OTA (protección de datos) o directa (somos dueños)
- `timeStamp` / `modifiedTime` → calculan el delay anti-duplicado

---

## 4. Camino B — Pull por Cron (Sincronización Programada)

**Archivos relevantes:**
- `src/Exchange/Service/Cron/Job/Beds24BookingsPullCronJob.php`
- `src/Pms/Service/Exchange/Tasks/BookingsPull/BookingsPullTask.php`
- `src/Pms/Service/Exchange/Tasks/BookingsPull/BookingsPullQueueProvider.php`
- `src/Pms/Service/Exchange/Tasks/BookingsPull/BookingsPullMappingStrategy.php`
- `src/Pms/Service/Exchange/Tasks/BookingsPull/BookingsPullHandler.php`

### 4.1 Flujo del Cron Pull

```
Cron → Beds24BookingsPullCronJob
  └─ Crea PmsBookingsPullQueue (status=pending)
       ├─ arrivalFrom / arrivalTo  → ventana de fechas a consultar
       ├─ unidades[]               → habitaciones a filtrar
       └─ config                   → qué cuenta Beds24 usar

ExchangeOrchestrator::run('bookings_pull')
  └─ BookingsPullQueueProvider::claimBatch(limit=1)
       └─ CLAIM: UPDATE pms_bookings_pull_queue SET status='running'

BookingsPullMappingStrategy::map(batch)
  └─ Construye GET /bookings con params:
       ├─ arrivalFrom, arrivalTo
       ├─ status: [confirmed, new, request, cancelled, black]
       ├─ includeInvoice: true
       └─ roomId: "501,502"  ← solo habitaciones de esta config

ExchangeBatchProcessor → HTTP GET → Beds24 API
  └─ Respuesta: array de booking objects

BookingsPullHandler::handleSuccess(data, item)
  ├─ persister.reset()  ← limpia caché de instancia
  └─ forEach booking in data:
       └─ BookingPullPersister::upsert($config, $dto)
```

### 4.2 Diferencias Pull Cron vs Webhook

| Aspecto | Webhook | Cron Pull |
|---|---|---|
| Entrada | 1 booking a la vez | N bookings en batch |
| Latencia | Tiempo real (< 1s) | Periódico (cada X min) |
| Transacción | 1 por booking | 1 para el lote completo |
| DTO origin | `fromArray()` directo | Via `Serializer::denormalize()` |
| Persister reset | No necesario | `persister->reset()` al inicio |
| Soft-fail | Handler del worker captura | `BookingsPullHandler` fila a fila |
| Delay anti-dup | Sí (hasta 15s) | No |

---

## 5. Persister Compartido — BookingPullPersister

**Archivo:** `src/Pms/Service/Exchange/Tasks/BookingsPull/BookingPullPersister.php`

Es el corazón de la persistencia. Lo usan tanto el Webhook como el Cron Pull. Implementa `ResetInterface` para que Messenger limpie su caché entre mensajes.

### 5.1 Flujo de upsert()

```
BookingPullPersister::upsert(Beds24Config $config, Beds24BookingDto $dto)

1. normalizeBeds24Id($dto->id)
   → Garantiza string positivo no vacío. Null → RuntimeException.

2. resolveMap($dto)
   → SELECT pms_unidad_beds24_map
      WHERE beds24PropertyId = $dto->propertyId
        AND beds24RoomId     = $dto->roomId
   → Cache local: $this->cacheMaps["propertyId_roomId"]
   → Null → RuntimeException (no mapeo configurado para esa habitación)

3. resolveEstablecimiento($config, $map)
   → $map->getPmsUnidad()->getEstablecimiento()
   → Valida que el establecimiento pertenece a $config->getEstablecimientos()
   → RuntimeException si hay inconsistencia

4. resolveLink($bookingIdStr)
   → SELECT pms_evento_beds24_link WHERE beds24BookId = $bookingIdStr
   → Cache local con "misses" (array_key_exists + false sentinel)

5. resolveMasterIdReal($dto)
   → Lee $dto->bookingGroup['master'] ?? $dto->masterId
   → isSubReserva = ($masterIdReal !== $bookingIdStr)

6. Upsert PmsReserva
   Si es MADRE o INDIVIDUAL:
     upsertReservaFull()
       → Busca por beds24MasterId, luego por beds24BookIdPrincipal
       → Si no existe: new PmsReserva() → em->persist()
       → Asigna: establecimiento, beds24MasterId, beds24BookIdPrincipal
       → Datos del huésped (solo si datosLocked=false):
           firstName, lastName, email, telefono, pais, idioma
       → Sella datosLocked=true cuando llegan datos fuertes
           (apellido || email || telefono)

   Si es HIJA (sub-reserva de grupo):
     resolveReservaFromMasterLink($masterIdStr)
       → Busca reserva del padre via su link
     Fallback: resolveOrCreateStubReserva()
       → new PmsReserva() con nombre "Pendiente Sync (Grupo)"

7. upsertEvento($dto, $map, $reserva, $existingLink, $bookIdStr)
   → Si existingLink: actualiza evento existente
   → Si nuevo:  PmsEventoCalendarioFactory::createFromBeds24Import()
                  └─ internalHydrate() → crea PmsEventoBeds24Link por cada mapa activo
   → Asigna: inicio/fin con horas del establecimiento, estado, channel,
             cantidadAdultos, monto, comision, tituloCache...
   → resolveEstado(): lógica OTA (Airbnb/pago total → fuerza CONFIRMADA)
   → resolveEstadoPago(): pago total si canal en PmsChannel::CANAL_PAGO_TOTAL
   → em->persist($evento)

8. Devuelve: ['status'=>'success', 'action'=>'created'|'updated', 'message'=>...]
```

### 5.2 Cachés internas (evitan N+1)

| Cache | Clave | Valor |
|---|---|---|
| `$reservaByMasterId` | masterIdStr | `PmsReserva\|false` |
| `$cacheLinks` | bookingIdStr | `PmsEventoBeds24Link\|false` |
| `$cacheMaps` | "propertyId_roomId" | `PmsUnidadBeds24Map\|false` |
| `$cachePaises` | ISO2 | `MaestroPais` |
| `$cacheIdiomas` | código | `MaestroIdioma` |
| `$cacheCanales` | nombre lowercase | `PmsChannel` |
| `$cacheEstados` | codigoBeds24 | `PmsEventoEstado` |

Todas usan el patrón `array_key_exists` + sentinel `false` para cachear negativos.

---

## 6. El Mecanismo de Espejo Virtual

**Archivos relevantes:**
- `src/Pms/Entity/PmsEstablecimientoVirtual.php`
- `src/Pms/Entity/PmsUnidadBeds24Map.php`
- `src/Pms/Factory/PmsEventoCalendarioFactory.php` → `internalHydrate()`

### 6.1 Concepto

Una unidad física puede estar publicada en múltiples canales a través de diferentes cuentas de Beds24. Cada publicación es un `PmsEstablecimientoVirtual` con su propio `beds24RoomId` y `beds24PropertyId`.

```
PmsUnidad "Habitación 101"
  ├── PmsUnidadBeds24Map A
  │    ├── beds24PropertyId = 11111  (cuenta Beds24 de Booking.com)
  │    ├── beds24RoomId     = 501
  │    └── virtualEstablecimiento → "SAPHY-BCM" (esPrincipal=true)
  │
  └── PmsUnidadBeds24Map B
       ├── beds24PropertyId = 22222  (cuenta Beds24 de Airbnb)
       ├── beds24RoomId     = 502
       └── virtualEstablecimiento → "SAPHY-ABB" (esPrincipal=false)
```

### 6.2 Hydratación de links (internalHydrate)

Al crear un evento, la factory construye **un `PmsEventoBeds24Link` por cada mapa activo**:

```
internalHydrate(evento, externalBookId, externalRoomId)

1. getActiveMaps(unidad) → [MapA, MapB]

2. Determina principalMap:
   - Webhook: match exacto por beds24RoomId == externalRoomId
   - UI: mapa cuyo virtualEstablecimiento.esPrincipal == true
   - Fallback: primer mapa de la lista

3. Para cada mapa:
   Si es principalMap:
     Link.esPrincipal = true
     Link.beds24BookId = externalBookId  ← el ID real de la reserva
     Link.lastSeenAt = NOW()

   Si es espejo (otro mapa):
     Link.esPrincipal = false
     Link.beds24BookId = null            ← sin ID hasta que Push lo obtiene
     Link.unidadBeds24Map = mapa espejo

4. Reciclaje inteligente (Smart Move):
   Reutiliza links existentes por código virtual.
   Links sobrantes se vacían y desvinculan.
```

### 6.3 Por qué el espejo nace sin beds24BookId

El link espejo nace sin ID porque Beds24 todavía no sabe de esa reserva en el listing espejo. El flujo Push (§7) crea la reserva espejo en Beds24 y sella el ID vía `BookingsPushHandler::handleSuccess()`.

---

## 7. Camino C — Push de vuelta a Beds24

**Archivos relevantes:**
- `src/Pms/EventListener/Queue/Beds24BookingsPushQueueListener.php`
- `src/Pms/Service/Exchange/Tasks/BookingsPush/BookingsPushMappingStrategy.php`
- `src/Pms/Service/Exchange/Tasks/BookingsPush/BookingsPushHandler.php`

### 7.1 Detección de cambios (onFlush, prioridad 200)

```
Beds24BookingsPushQueueListener::onFlush()

Guard: if syncContext.isPush() → return  (evita bucle)

Rastreo: PmsEventoCalendario, PmsReserva, PmsEventoBeds24Link

resolveTasks():
  Link borrado    → DELETE
  Link huérfano   → CANCEL
  Link activo     → PUSH
  (torneo pickBestLink si hay duplicados por evento+mapa)

  Protección OTA: shouldIgnoreReservaUpdate()
    Si datosLocked=true y solo cambiaron campos cosméticos → no genera Push
```

### 7.2 Tres perfiles de payload

```
  ┌─ ESPEJO (isMirror=true) ──────────────────────────────────────────┐
  │  Objetivo: bloquear el calendario del listing clonado             │
  │  + arrival, departure, numAdult, numChild, status                 │
  │  + firstName = "(M) " + reserva.nombreCliente                     │
  │  + comment = "Reserva espejo"                                     │
  │  - email, phone, price, commission, apiReference, channel         │
  └───────────────────────────────────────────────────────────────────┘

  ┌─ OTA PRINCIPAL (isOta=true, isMirror=false) ──────────────────────┐
  │  Objetivo: actualizar datos del huésped sin pisar la OTA          │
  │  + firstName, lastName, notes, comments, lang, country2           │
  │  - arrival, departure, status, email, phone, price, masterId      │
  └───────────────────────────────────────────────────────────────────┘

  ┌─ DIRECTA PROPIA (!isOta, !isMirror) ─────────────────────────────┐
  │  Objetivo: sincronización completa, somos dueños absolutos        │
  │  + Todo: fechas, estado, huésped, email, phone, price, masterId   │
  └───────────────────────────────────────────────────────────────────┘
```

### 7.3 Respuesta de Beds24

```
BookingsPushHandler::handleSuccess(data, item)
  ├─ remoteId = data['new']['id'] ?? data['id'] ?? data['new'][0]['id']
  ├─ link.setBeds24BookId(remoteId)         ← sella el ID en el espejo
  ├─ link.setLastSeenAt(NOW())
  ├─ queue.setBeds24BookIdOriginal(remoteId) ← snapshot para futuros DELETE
  └─ queue.markSuccess(NOW())
```

---

## 8. Motor de Exchange — ExchangeOrchestrator

**Archivo:** `src/Exchange/Service/Engine/ExchangeOrchestrator.php`

Motor genérico agnóstico al dominio. Ejecuta Pull, Push y Rates con el mismo contrato:

```
ExchangeOrchestrator::run(taskName, requestedLimit, specificIds)

1. TaskLocator::get(taskName) → ExchangeTaskInterface
2. claimBatch() / claimSpecificBatch() → UPDATE status='running' (Claim Check)
3. SyncContext::enter(mode, provider)
4. em->beginTransaction()
5. ExchangeBatchProcessor::processBatch()
   ├─ MappingStrategy::map()     → MappingResult (URL, método, payload)
   ├─ Audita lastRequestRaw
   ├─ client.send()              → NetworkResult
   ├─ Audita lastResponseRaw + lastHttpCode
   └─ MappingStrategy::parseResponse() → {queueId: ItemResult}
6. Handler::handleSuccess() / handleFailure() por item
7. em->flush() + em->commit()
8. Limpieza de memoria (detach entidades pesadas)

Fallo catastrófico:
   → em->rollBack()
   → UPDATE SQL nativo (no depende del EM)
   → Relanza excepción (Messenger gestiona reintentos)
```

---

## 9. Anti-duplicación y Seguridad

### 9.1 Delay de 15 segundos

Beds24 dispara webhooks en cascada al bloquear todos los listigns espejo. Sin delay, el segundo webhook crearía un duplicado antes de que el primero esté persistido.

```
eventTimestamp determinado por:
  A. Último mensaje 'host' (si hay mensajes)
  B. payload.timeStamp ?? booking.modifiedTime ?? booking.bookingTime

abs(NOW - eventTimestamp) > 600s → evento antiguo → sin delay
abs(NOW - eventTimestamp) <= 600s → reciente → DelayStamp(15s)
Sin timestamp → delay por defecto (conservador)
```

### 9.2 SyncContext (anti-bucle Pull ↔ Push)

Objeto RAII compartido en el worker. `enter()` devuelve un scope con `restore()` en `finally`, lo que permite anidamiento correcto sin perder el estado anterior.

- `MODE_PULL` activo → `PushQueueListener::onFlush()` sale inmediatamente
- `MODE_PUSH` activo → ningún Pull puede arrancar simultáneamente

### 9.3 datosLocked en PmsReserva

```
false → acepta cualquier dato entrante de Beds24
true  → ignora campos cosméticos (no reescribe lo que ya está bien)

Se sella a true cuando llegan datos "fuertes":
  apellido || email || telefono || mobile

Protege contra: Airbnb que manda solo firstName en estado Request
(inquiry sin confirmar). No se sella hasta que llega la confirmación
con datos completos.
```

### 9.4 Protección de datos OTA en Push

`shouldIgnoreReservaUpdate()`: si `datosLocked=true` y el changeSet solo contiene campos de `IGNORED_FIELDS_ON_LOCKED_OTA`, no se genera cola Push. Evita sobreescribir el correo proxy de Booking.com o el teléfono proxy de Airbnb.

### 9.5 Auto-confirmación por pago (PMS, no Beds24)

Regla de negocio del PMS: una estancia con dinero recibido queda **confirmada**.

```
Fuente de verdad: PmsEventoCalendario::requiereAutoConfirmacionPorPago()
Aplicación:       PmsEventoCalendarioIntegrityListener (prePersist + preUpdate)
Espejo en la UI:  util/src/types/pmsReservaModel.ts → requiereAutoConfirmacionPorPago()

estadoPago ∈ {pago-total, pago-parcial, pago-alojamiento}   (ESTADOS_PAGO_CONFIABLES)
  y estado ∉ {cancelada, bloqueo}                           (ESTADOS_SIN_AUTO_CONFIRMACION)
  y no estamos en SyncContext::MODE_PULL
  ⇒ estado = confirmada
```

Puntos finos:

- Se dispara ante un cambio de `estadoPago` **o** de `estado`: si solo se mirara el pago,
  bastaba con bajar a "pendiente" una reserva ya pagada para dejarla inconsistente.
- Es **asimétrica**: registrar un pago confirma; volver a "no pagado" NO degrada el estado.
- En **Pull** no se aplica: ahí manda el canal y `BookingPullPersister::resolveEstado()`
  ya resuelve la variante OTA (incluida la protección de los *inquiries* "abiertos").
- Al mutar en `preUpdate` un campo que ya venía en el changeSet, hay que corregirlo con
  `PreUpdateEventArgs::setNewValue()`: Doctrine recalcula por diferencia contra
  `originalEntityData` y **fusiona**, así que una entrada vieja sobreviviría al recálculo.

### 9.6 Validación de establecimiento en config

`resolveEstablecimiento()` valida que el establecimiento de la unidad esté en los establecimientos autorizados por la `Beds24Config` del token. Previene cross-contamination entre propiedades.

---

## 10. Políticas de Reintento y Error

| Situación | Reintento | Tiempo |
|---|---|---|
| Push fallo de red/API | Sí | +5 minutos |
| Pull fallo de red/API | Sí | +15 minutos |
| Fallo catastrófico batch | Sí (Messenger dead-letter) | +5 min (SQL nativo) |
| Token inválido en webhook | No (audit=ERROR) | — |
| Mapeo de unidad no encontrado | No (excepción en worker) | — |
| EM cerrado durante procesamiento | No (RuntimeException → relanza) | — |

**Snapshot de IDs** (`beds24BookIdOriginal` en `PmsBookingsPushQueue`): si el link se borra antes de que el worker DELETE ejecute, el snapshot permite recuperar el ID de Beds24 sin depender del link ya inexistente.

---

## 11. Dónde tocar para cambiar X

| Necesidad | Archivo | Método/Campo |
|---|---|---|
| Cambiar ventana anti-dup | `Beds24WebhookController` | `600` (seg) y `15000` (ms) |
| Añadir canal de pago total | `PmsChannel` | `CANAL_PAGO_TOTAL` |
| Cambiar lógica de estado OTA | `BookingPullPersister` | `resolveEstado()` |
| Cambiar estado de pago inicial | `BookingPullPersister` | `resolveEstadoPagoInicial()` |
| Cambiar la auto-confirmación por pago (§9.5) | `PmsEventoCalendario` + `util/src/types/pmsReservaModel.ts` | `requiereAutoConfirmacionPorPago()` (hay que tocar **los dos**: son espejo) |
| Añadir campo nuevo al Pull | `Beds24BookingDto` + `BookingPullPersister` | `fromArray()` + `upsertReservaFull()` |
| Cambiar qué se envía al espejo | `BookingsPushMappingStrategy` | `buildUpsertPayload()` bloque `$isMirror` |
| Cambiar ventana de Pull Cron | `Beds24BookingsPullCronJob` | `arrivalFrom/To` |
| Cambiar reintento Push | `BookingsPushHandler` | `handleFailure()` → `$delayMinutes` |
| Cambiar reintento Pull | `BookingsPullHandler` | `handleFailure()` → `'+15 minutes'` |
| Añadir listing espejo | Admin: `PmsEstablecimientoVirtual` + `PmsUnidadBeds24Map` | `esPrincipal=false` |
| Proteger campo Push en OTA | `Beds24BookingsPushQueueListener` | `IGNORED_FIELDS_ON_LOCKED_OTA` |
| Cambiar idioma por defecto | `MaestroIdioma` | `DEFAULT_IDIOMA` |
| Cambiar país por defecto | `MaestroPais` | `DEFAULT_PAIS` |
