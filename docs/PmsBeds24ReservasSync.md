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
11. [Camino D — Sincronización Financiera (invoiceItems)](#11-camino-d--sincronización-financiera-invoiceitems)
12. [Coherencia Financiera — Rollup Multi-moneda y Candado](#12-coherencia-financiera--rollup-multi-moneda-y-candado)
    · [12.0.1 Cargos automáticos de una estancia directa](#1201-cargos-automáticos-de-una-estancia-directa)
    · [12.4.3 Vista dual soles / dólares](#1243-vista-dual-soles--dólares)
    · [12.6 Gotcha: SearchFilter y UUID binario](#126--gotcha-searchfilter-no-funciona-sobre-relaciones-con-uuid-binario)
13. [Dónde tocar para cambiar X](#13-dónde-tocar-para-cambiar-x)

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

7. upsertEvento($dto, $map, $reserva, $existingLink, $bookIdStr, $isLinkPrincipal)
   → Si existingLink: actualiza evento existente
   → Si nuevo:  PmsEventoCalendarioFactory::createFromBeds24Import()
                  └─ internalHydrate() → crea PmsEventoBeds24Link por cada mapa activo
   → SOLO si $isLinkPrincipal (un espejo no escribe la estancia compartida, §6.4):
       Asigna: inicio/fin con horas del establecimiento, estado, channel,
               cantidadAdultos, monto, comision, tituloCache...
       resolveEstado(): lógica OTA (Airbnb/pago total → fuerza CONFIRMADA)
       resolveEstadoPago(): pago total si canal en PmsChannel::CANAL_PAGO_TOTAL
   → Siempre: refresca lastSeenAt del link entrante
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

### 5.3 Gotcha: teléfonos de OTA sin el "+"

Las OTAs entregan el teléfono **con código de país pero sin el `+`**: Booking.com mandó
`5561998210624` (Brasil). El formateador del frontend (`util/src/utils/telefono.ts`) asume Perú
cuando el número no empieza por `+`, así que lo leía como peruano, lo daba por inválido, lo
mostraba crudo y —peor— `telefonoParaWhatsapp()` devolvía `null`, con lo que **desaparecía el
botón de WhatsApp** para ese huésped.

`interpretar()` prueba dos lecturas en orden: primero **nacional** del país por defecto (un
`984123456` peruano sigue resolviéndose ahí, sin cambio de comportamiento) y, si falla, como
**internacional al que le falta el `+`**. El segundo intento exige 8-15 dígitos para no convertir
notas de texto en teléfonos; un `012345` o un "no tiene" siguen sin botón, que es lo correcto.

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

### 6.4 Sólo el link principal escribe los datos de la estancia

Los links espejo cuelgan del **mismo** `PmsEventoCalendario` que el principal (§6.2), pero en
Beds24 son reservas distintas. Y son huecas por diseño del Push (§7.2): llegan con `price: 0`,
`commission: 0`, `firstName: "(M) …"` y `channel: "direct"`, porque esos campos nunca se les
envían. Un espejo **no tiene ninguna verdad que aportar sobre la estancia**.

Por eso `BookingPullPersister::upsertEvento()` recibe `$isLinkPrincipal` y encierra todo el
bloque de campos autoritativos —`monto`, `comision`, `tituloCache`, `channel`,
`referenciaCanal`, `rateDescription`, `comentariosHuesped`, `estadoBeds24`, estado y fechas—
tras esa condición. Del Pull de un espejo sólo sobrevive el `lastSeenAt` de su propio link, que
es exactamente lo que aporta: confirmar que sigue vivo en Beds24.

```
Pull del espejo (price 0, channel direct, "(M) Susan"):
  ANTES:    montoTotal = 241.14 | Casita 4  monto=91.59  canal=booking  titulo=Susan Acuña
  DESPUÉS:  montoTotal = 241.14 | Casita 4  monto=91.59  canal=booking  titulo=Susan Acuña  ✅ sin cambios

Re-pull del PRINCIPAL con precio nuevo (91.59 → 120.00):
  DESPUÉS:  montoTotal = 269.55 | Casita 4  monto=120.00 comision=18.00                     ✅ sí aplica
```

**Sin esta condición** (comportamiento anterior) el espejo dejaba `monto=0.00`, `comision=0.00`,
`titulo="(M) Susan Acuña"` y convertía el canal de `booking` a `directo`; `montoTotal` caía de
241.14 a 149.55 en cada pasada del cron.

Dos matices del arreglo:

- **Excepción `$action === 'created'`:** si el evento se acaba de crear no hay datos previos que
  proteger, y saltar el bloque dejaría una entidad incompleta (estado y fechas son obligatorios).
  Un espejo no debería estrenar evento nunca, pero ante la anomalía se prefiere una fila completa
  a una corrupta.
- **`channel`/`referenciaCanal` del propio link** también quedan tras la condición: en un link
  espejo se dejan en `NULL`, mismo criterio con el que la migración `Version20260731042857` sólo
  pobló los principales.

**Por qué el descuadre no se veía antes:** `montoTotal` (suma de eventos) era la única fuente, así
que no había contra qué contrastarlo. Con la cabecera financiera (§12) hay un testigo
independiente —`totalCargos`, que sale de los `invoiceItems` y el espejo no toca—, y cualquier
reaparición del problema salta como un `saldo` que no cuadra.

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

## 11. Camino D — Sincronización Financiera (invoiceItems)

Beds24 reporta en el mismo payload un nodo `invoiceItems` con los conceptos que se le cobran al
huésped: **alojamiento** (`subType` 8), **limpieza** y **cargo por servicio** (`subType` 11),
comisiones, pagos, etc. Este camino los persiste replicando **exactamente** la arquitectura de
Mensajes (§3.2 / cron de mensajes): un registro **padre** por reserva + N **hijos** por concepto,
alimentado por los **dos** caminos (webhook en tiempo real y pull en-demanda por cron), porque el
webhook de Beds24 **no reintenta**.

### 11.1 Mapeo espejo Mensajes ↔ Finanzas

| Mensajes (`src/Message/`)                         | Finanzas (`src/Pms/`)                                  |
|---|---|
| `MessageConversation` (padre, por reserva)        | `PmsInformacionFinanciera` (padre, por reserva, 1:1)   |
| `Message` (hijo = 1 mensaje)                       | `PmsCargoFinanciero` (hijo = 1 invoiceItem)            |
| `Beds24MessageDto`                                 | `App\Pms\Dto\Beds24InvoiceItemDto`                     |
| `Beds24ReceiveQueue` (cola pull)                   | `App\Pms\Entity\Beds24InvoiceReceiveQueue`             |
| `Beds24ReceivePersister::upsertMessages()`         | `Beds24InvoiceReceivePersister::upsertCargos()`        |
| Task `beds24_message_receive`                      | Task `beds24_invoice_receive`                          |
| Cron `Beds24MessageReceiveJob` (endpoint `GET_MESSAGES`) | Cron `Beds24InvoiceReceiveJob` (endpoint `GET_INVOICES`) |
| — (no aplica)                                      | `PmsPagoFinanciero` (pagos propios, no vienen de Beds24) |

El **task-set** de pull vive en `src/Pms/Service/Exchange/Tasks/InvoiceReceive/`
(`Task`, `QueueProvider`, `MappingStrategy`, `Handler`, `Persister`) y el enqueuer en
`src/Pms/Service/Queue/Beds24InvoiceReceiveEnqueuer.php`. El cron **reutiliza**
`PmsBeds24MessageTargetFinder::findTargetsForPeriod()` (mismo generador de reservas activas por
periodo, no se duplica esa lógica).

### 11.2 Flujo

```
WEBHOOK (tiempo real)                          PULL / CRON (relleno, sin reintentos del webhook)
─────────────────────                          ──────────────────────────────────────────────────
ProcessBeds24WebhookDispatchHandler            Beds24InvoiceReceiveJob (cron 'beds24_invoice_receive')
  handleInvoiceItems($payload['invoiceItems'])   └─ PmsBeds24MessageTargetFinder → enqueue por reserva
    agrupa por bookingId                        ExchangeOrchestrator::run('beds24_invoice_receive')
        │                                         └─ MappingStrategy → GET bookings/invoices?bookingId=..
        ▼                                            └─ parseResponse: APLANA data[] (facturas)→items
   Beds24InvoiceReceivePersister::upsertCargos(bookingId, dtos[])  ◄──────────────────────┘
     1. reserva = PmsReservaRepository::findByAnyBeds24Id(bookId)   (no encontrada → RuntimeException)
     2. info = PmsInformacionFinancieraFactory::upsertForReserva()  (find-or-create padre)
     3. upsert por 'beds24ItemId' (dedupe): nuevo → persist | existente → sólo campos volátiles
     4. info.recalcularTotales() + lastSyncedAt; flush
```

### 11.3 Gotchas

- **`data` del endpoint on-demand NO es plano.** A diferencia de mensajes (cuyo `data` ya es la
  lista de mensajes), `GET bookings/invoices` devuelve `data[]` = **facturas anidadas**, cada una
  con `invoiceId`, `invoiceDate` y su propio `invoiceItems[]`.
  `Beds24InvoiceReceiveMappingStrategy::parseResponse()` **aplana** a lista de invoiceItems
  propagando `invoiceId`/`invoiceDate` de la factura padre a cada línea.
- **El webhook no trae `invoiceId`/`invoiceDate`.** Sus `invoiceItems` vienen top-level (cada uno
  con su `bookingId`). Por eso ambos campos son nullable en `PmsCargoFinanciero`, y el persister
  los **enriquece** después: si primero llegó por webhook (sin factura) y luego el pull trae la
  factura, `aplicarCambiosVolatiles()` rellena los huecos sin duplicar la fila.
- **Verdad histórica:** el cargo se guarda tal cual llega (subTipo/descripción/monto). La
  clasificación (`tipoCargo`) es una **capa encima** que estandariza, no reemplaza esos datos crudos.
- **Dedupe:** índice único `uq_cargo_info_item (informacion_id, beds24_item_id)`. Reejecutar el
  pull es idempotente (stats `skipped`).
- **FKs unidireccionales:** `Beds24InvoiceReceiveQueue` apunta a `Beds24Config`/`ExchangeEndpoint`
  sin `inversedBy`, a propósito, para no tocar esas entidades del módulo Exchange.
- **Endpoint requerido:** fila `ExchangeEndpoint` provider `beds24`, `accion='GET_INVOICES'`,
  `endpoint='bookings/invoices'`, `metodo='GET'` (creada por la migración
  `Version20260802133405`).

### 11.2.1 Barrido del cursor: horizonte real y paso adaptativo

`TimelineEnqueuerService` avanza un cursor por el calendario y le pide a cada job que encole lo
que encuentre en la ventana. Con dos constantes fijas —el paso del job y un tope de **+18 meses**
codificado en el servicio— eso funciona sólo si los datos están repartidos de forma uniforme. No
lo están.

Medido en producción el 2026-08-03:

| | Datos | Horizonte real | Cursor real ese día |
|---|---|---|---|
| Tarifas | 23 rangos en 0-60 d · 21 en 60-180 d · **0 más allá** | 2027-01-01 (151 d) | **2028-01-06** |
| Reservas vivas | 24 en 0-60 d · 2 en 60-180 d · 1 más allá | 2027-02-04 (185 d) | **2027-11-18** |

Los cursores llevaban meses recorriendo calendario donde no había ni una tarifa ni una reserva.
Dos causas distintas, con arreglos distintos:

**1. El techo de +18 meses era arbitrario** — `CronHorizonteInterface`. Un job que sepa hasta
dónde llegan sus datos devuelve ese `MAX(fecha)` y el cursor se reinicia ahí. No es una
heurística: es un límite exacto que se autoajusta solo (al cargar tarifas de 2028, el horizonte
se extiende sin tocar código). `resolverLimite()` nunca lo deja por debajo de hoy, para que un
horizonte en el pasado no deje el cursor reiniciándose en bucle sin barrer nada.

**2. Dentro del rango con datos, la densidad puede ser muy desigual** —
`CronPasoAdaptativoInterface` + `PasoAdaptativo::calcular()`. El paso se **duplica** cada
`TRAMO_DIAS` de lejanía, con techo en `MULT_MAX`: lo cercano se revisa fino y lo lejano se recorre
a zancadas.

⚠️ **Los dos son opt-in, y no todas las tareas quieren los dos.** El reparto no es estético, cada
exclusión tiene un motivo:

| Job | Horizonte | Paso adaptativo | Por qué |
|---|---|---|---|
| `beds24_rates_push` | Sí | **No** | Dentro del rango cubierto la densidad es uniforme (23 contra 21): alargar el paso lejano sólo revisaría peor esa parte. El desperdicio estaba en el techo |
| `beds24_invoice_receive` | Sí | Sí | Reservas vivas 24 / 2 / 1: el desierto está *dentro* del rango con datos |
| `beds24_message_receive` | Sí | Sí | Ídem |
| `beds24_bookings_push` | Sí | No | Lee eventos locales, así que el horizonte vale. Con paso base de 1 mes ya son pocas vueltas y doblarlo dejaría lo lejano con granularidad de medio año |
| `beds24_bookings_pull_arrival` | **No** | Sí | ⚠️ Pregunta a **Beds24** por ventana de llegada, no consulta reservas locales. Un horizonte sacado de `MAX(e.fin)` nos dejaría ciegos ante las reservas que aún no tenemos — que son justo las que este pull existe para descubrir. El paso adaptativo sí es seguro: se sigue cubriendo el rango entero, con menos grano en lo lejano |

**Dónde se configura:** en constantes de cada job (`PASO_BASE_DIAS`, `TRAMO_DIAS`, `MULT_MAX`) y
en la consulta de `getHorizonteMaximo()`. Nada de esto vive en el servicio ni en configuración
externa: ajustar el comportamiento es cambiar un número en la clase que lo sufre.

**Resultado medido** (vueltas por ciclo completo):

| Job | Antes | Después |
|---|---|---|
| `beds24_invoice_receive` | 79 | **16** |
| `beds24_message_receive` | 79 | **16** |
| `beds24_rates_push` | 40 | **11** |
| `beds24_bookings_pull_arrival` | 40 | **15** |
| `beds24_bookings_push` | 19 | **7** |

Verificado además con una ejecución real: con el cursor de mensajes en 2027-11-18 (pasado el
horizonte), el enqueuer lo reinició a 2026-08-02 y encontró **17 reservas** en la primera
ventana; con el código anterior esa misma ejecución habría barrido 2027-11-18 → 2027-11-25 y
encontrado **cero**. Casos límite comprobados: cursor en el pasado y cursor a +50 años devuelven
un paso válido (nunca 0, nunca `INF` — el exponente se acota antes de elevar), y un horizonte en
el pasado no provoca reinicios en bucle.

**No hace falta reiniciar cursores a mano al desplegar:** los que estén pasados de su horizonte
se reinician solos en la primera ejecución.

Aun así, el panel *Monitoreo de Crons* tiene un botón **Reiniciar**
(`CronCursorCrudController::reiniciarCursorAction()`, requiere `RESERVAS_WRITE`) que devuelve el
cursor a ayer. Su caso de uso es **el contrario** al anterior: cuando se cargan tarifas o reservas
de fechas que el cursor **ya dejó atrás**, ese tramo no se vuelve a barrer hasta que el ciclo dé
la vuelta entera. El botón adelanta ese barrido. Para un cursor disparado al futuro no hace falta:
eso se corrige solo.

### 11.3.1 Lote múltiple: varias reservas en una sola petición

Los dos pull en demanda (facturas y mensajes) piden **N reservas por petición** repitiendo el
parámetro: `?bookingId=A&bookingId=B&bookingId=C`. Beds24 devuelve todo mezclado y cada línea
trae su `bookingId`, que es lo que permite repartirlo.

**Por qué durante un tiempo el lote fue 1.** El comentario decía "para evitar cortes por
paginación", pero eso ya lo resuelve `Beds24ExchangeClient`: recorre `pages.nextPageLink` en
bucle y **fusiona los `data`** antes de entregárselos a la estrategia. El motivo real de que no
se pudiera subir era otro: las estrategias estaban escritas para un solo ítem
(`$batch->getItems()[0]`) y devolvían **un único `ItemResult`**. Con un lote mayor, los demás
ítems no encontraban resultado en el orquestador (`$results[$itemId] ?? null`) y acababan en
`handleFailure()`. No se perdían datos: se marcaban fallidos 9 de cada 10.

Cuatro cosas que hay que respetar al tocar estas estrategias:

1. **La query se construye a mano en `fullUrl`, no en `payload`.** El cliente hace
   `'query' => $payload` y Symfony serializa un array como `bookingId[0]=…`, que Beds24 no
   interpreta como parámetro repetido. Además encaja con la paginación: al saltar de página el
   cliente sustituye la URL y vacía el payload.
2. **`correlationMap` es `bookingId => [idsDeCola]`**, una lista y no un valor único, por si dos
   ítems en cola apuntaran al mismo booking.
3. ⚠️ **Se recorre la CORRELACIÓN, no el `data`.** Una reserva sin facturas (o sin mensajes) **no
   deja ningún rastro en la respuesta**. Si sólo se devolviera resultado para los `bookingId` que
   aparecen, esas reservas irían a `handleFailure()` y **se reintentarían para siempre**. Vacío es
   un éxito legítimo.
4. ⚠️ **`(string) $bookingId` al agrupar.** PHP convierte a `int` las claves numéricas de un
   array y `getTargetBookId()` devuelve string; sin el cast, el `??` no encuentra nada y *todas*
   las reservas parecen vacías. Es el mismo tropiezo que ya hubo en
   `ProcessBeds24WebhookDispatchHandler::handleInvoiceItems()`.

**Qué limita el tamaño del lote — y no es la paginación.** El lote entero comparte una
transacción y **un solo lock**, cuyo TTL fija el provider (60 s en facturas y mensajes). Una
respuesta con muchas páginas puede pasarse de ese TTL, y entonces el watchdog de
`AbstractExchangeRepository::claimRunnable()` marca **todo el lote** como `failed`. Por eso está
en 10 y no en 50.

**Verificado** (2026-08-03) contra respuestas reales de Beds24: 3 reservas pedidas + 1 inexistente
→ facturas repartidas 1 / 3 / 1 y la cuarta con éxito y 0 ítems; mensajes 3 / 1 / 2 y la cuarta
igual; con `success: false` fallan las cuatro con el mensaje de la API.

**No hace falta tocar los handlers ni los persisters:** siguen recibiendo los datos de UNA reserva
y usando `$item->getTargetBookId()`. Tampoco hay riesgo de mezclar cuentas de Beds24 en una misma
petición: `claimRunnable()` sondea un candidato y trae sólo los que comparten `config_id` **y**
`endpoint_id`, así que el lote es homogéneo por construcción.

**Efecto colateral en la auditoría.** `ExchangeBatchProcessor` guardaba en `lastRequestRaw` sólo
`$mapping->payload`; al pasar los `bookingId` a la URL, el campo quedaba en `[]`. Ahora guarda
`{method, url, payload}`, lo que además tapa un hueco que ya existía: **en cualquier GET la
auditoría nunca registró a qué URL se llamó**. Afecta a todas las colas, es sólo de lectura (nadie
parsea ese campo, se muestra en el panel) y la autenticación de Beds24 va en cabeceras, así que no
se filtra ningún token.

### 11.4 Moneda, tipo de cambio y clasificación (enriquecimiento local)

Beds24 **no** envía moneda ni tipo de cambio en los invoiceItems; el `subType` es grueso (el 11
mezcla limpieza y servicio) y las descripciones son plantillas idénticas en Booking y Airbnb. Por
eso el persister enriquece cada cargo con datos **locales** al crearlo (y hace *backfill* en filas
viejas vía `aplicarCambiosVolatiles()`, sin pisar snapshots existentes):

- **Moneda** → `MonedaResolver::resolve(?code)` (`src/Pms/Service/Finance/`). Regla: si no llega
  código → **USD** (`MaestroMoneda::DB_ID_USD`). Aplica a `PmsInformacionFinanciera`,
  `PmsCargoFinanciero` y `PmsPagoFinanciero` (todos referencian `App\Entity\Maestro\MaestroMoneda`).
- **Tipo de cambio** → `TipoCambioDelDia::venta()` (`src/Pms/Service/Finance/`), que **reutiliza**
  `App\Service\TipocambioManager` (cachea en BD, cae a SUNAT / último disponible). Se snapshotea la
  **venta** USD→PEN del día en cada cargo/pago (decimal 10,3). Se resuelve **una sola vez por
  llamada** a `upsertCargos()` (evita N+1 y golpes repetidos a SUNAT). Nunca rompe la persistencia:
  si falla, queda `null`. Se usa sobre todo para valorizar pagos en soles.
- **Clasificación** → enum `PmsTipoCargo` (`ALOJAMIENTO`/`LIMPIEZA`/`SERVICIO`/`OTRO`), fuente única
  `PmsTipoCargo::desdeBeds24($descripcion, $subTipo)`: `subType 8 → ALOJAMIENTO`; luego por
  descripción; resto `OTRO`. Expone `labelKey()` para i18n.

### 11.5 Pagos propios — `PmsPagoFinanciero`

A diferencia de los cargos (que vienen de Beds24), los **pagos** son registros nuestros de dinero
efectivamente recibido. Cuelgan de la misma cabecera (`PmsInformacionFinanciera::pagos`). Campos:
`medioPago` (enum `PmsMedioPago`: efectivo, plin/yape, tarjeta de crédito, western union,
transferencia bancaria, paypal), `moneda`, `tipoCambio` (venta del día), `comision`, `fechaPago`,
`referencia`, `notas`.

- **Comisión de tarjeta: se guarda el PORCENTAJE, no el importe.** `comisionPorcentaje` (5.50 =
  5.5%) es el dato que define la operación; si luego se corrige el monto, el recargo sigue siendo
  correcto sin recalcularlo. El valor por defecto lo propone `PmsMedioPago::comisionPorcentaje()`
  (5.5 en tarjeta, 0 en el resto) y el operador puede pisarlo.

  Los importes se **derivan**, nunca se persisten:

  | Campo | Qué es | Cómo se obtiene |
  |---|---|---|
  | `monto` | **Neto**: lo que abona la estancia | Persistido. **Es el único que entra al rollup** |
  | `montoComision()` | Importe del recargo | `monto × pct / 100` |
  | `montoTotalCobrado()` | Lo que se pasa por la tarjeta | `monto + montoComision()` |

  Por qué el rollup suma el neto y no el total: el recargo cubre el coste de la pasarela, no la
  estancia del huésped. Si la reserva vale 100 y paga con tarjeta, se le cobran 105.50 pero su
  deuda baja 100.

  En el panel, "Total cobrado" es un campo **no persistido y bidireccional**: al escribir el neto
  se recalcula el total, y al escribir el total se recalcula el neto
  (`totalConComision()` / `netoDesdeTotal()` en `pmsFinanzasModel.ts`, **espejo** de los métodos
  PHP — si cambia la fórmula hay que tocar los dos).

- **Tipo de cambio automático:** al elegir una moneda distinta a la de la reserva (o cambiar la
  fecha del pago), el panel consulta `POST /platform/maestro/tipo-cambio/consultar` —el mismo
  servicio que ya usaba el editor de cotizaciones— y rellena la **venta** del día. Nunca pisa un
  valor escrito a mano, y si el servicio falla queda vacío: el aviso de "sin TC no suma al saldo"
  ya explica la consecuencia.
- **Tipo de cambio a guardar:** se eligió **venta** SUNAT (ver `TipoCambioDelDia`). Para cambiar a
  promedio/compra se toca ese único servicio.
- **Multi-moneda:** el rollup a la cabecera (`totalCargos`/`totalPagos`/`getSaldo()`) y el candado
  de moneda se explican en la §12 (`PmsInformacionFinancieraCoherenciaListener`) — no son
  responsabilidad de esta entidad ni del persister.

### 11.6 Reservas agrupadas (booking group) — una cabecera, varios bookings

Un grupo de Booking.com (`bookingGroup.master` + `ids`) produce **una** `PmsReserva` con **N**
`PmsEventoCalendario` (uno por habitación). En lo financiero eso significa **una sola cabecera**
con los cargos de todos los bookings mezclados: `Beds24InvoiceReceivePersister::upsertCargos()`
resuelve la reserva con `findByAnyBeds24Id()`, que acepta tanto el `masterId` como cualquier
`beds24BookId` de sus links — así que todos los webhooks del grupo caen en el mismo padre.

Verificado con un grupo real de 2 bookings (90853942 master + 90853943):

```
RESERVA  master=90853942  montoTotal=241.14  eventos=2  cabeceras=1
  CABECERA totalCargos=241.14  nCargos=6
    166408917 book=90853942 alojamiento  66.60   |  166408920 book=90853943 alojamiento 117.00
    166408918 book=90853942 servicio      9.99   |  166408921 book=90853943 servicio     17.55
    166408919 book=90853942 limpieza     15.00   |  166408922 book=90853943 limpieza     15.00
```

El `montoTotal` de la reserva (suma de `evento.monto`) y el `totalCargos` de la cabecera
coinciden: son dos rollups independientes que deben cuadrar.

**🔥 GOTCHA — el pull del master devuelve el grupo entero.** `GET /bookings/invoices` no se
comporta igual según a quién se le pregunte:

```
?bookingId=90853942  (master) → 6 invoiceItems  (los de AMBOS bookings del grupo)
?bookingId=90853943  (hija)   → 3 invoiceItems  (sólo los suyos)
```

No duplica nada porque el índice único `uq_cargo_info_item (informacion_id, beds24_item_id)` los
absorbe (`{"imported":0,"skipped":6}` al reejecutar).

**Cómo lo aprovecha el cron.** Preguntar por cada habitación devolvía los mismos items N veces:
inocuo, pero N-1 llamadas desperdiciadas contra una API con límite de peticiones. Por eso el cron
de facturas usa su propio finder, `PmsBeds24InvoiceTargetFinder`, que agrupa los links **por
reserva** y encola **un job por reserva** eligiendo siempre el `beds24MasterId`:

```
Antes (finder de mensajes): targets = [90853942, 90853943]   → 2 llamadas
Ahora (finder de facturas): targets = [90853942]             → 1 llamada
```

⚠️ **Siempre el master, nunca una hija:** una hija devuelve un subconjunto, así que elegirla
perdería los cargos de las demás habitaciones. Si el master aún no se conoce (sync a medias),
se cae al primer link disponible. El cron de **mensajes** conserva su finder original: los
mensajes sí son de cada booking y ahí hay que ir uno a uno.

**Para distinguir a qué estancia pertenece cada cargo** está `beds24BookingId`, y
`PmsInformacionFinanciera::getEstancias()` da el mapa `bookingId → casita/fechas` que necesita
la UI. Sin eso, dos habitaciones del mismo grupo se ven con descripciones idénticas
(`[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`, que Beds24 no resuelve) y no hay forma de saber cuál
es cuál. En el panel, `ReservaFinanzasPanel.vue` agrupa los cargos por estancia con subtotal:

```
▸ Casita 4   2027-03-08 → 2027-03-10        91.59
    alojamiento 66.60 · servicio 9.99 · limpieza 15.00
▸ Casita 2   2027-03-08 → 2027-03-10       149.55
    alojamiento 117.00 · servicio 17.55 · limpieza 15.00
```

Con **una sola estancia** (el caso normal) no se pinta cabecera de grupo: sería ruido. Los cargos
manuales, que no tienen `beds24BookingId`, caen en un bloque propio ("Cargos manuales").

## 12. Coherencia Financiera — Rollup Multi-moneda y Candado

**Archivos relevantes:**
- `src/Pms/EventListener/PmsInformacionFinancieraCoherenciaListener.php`
- `src/Pms/Service/Finance/PmsInformacionFinancieraRecalculoService.php`

### 12.0 Auto-provisión de la cabecera (reservas directas)

La cabecera financiera sólo nacía por la sincronización de `invoiceItems` (§11), así que una
**reserva directa nunca la tenía**: sin registro padre no hay dónde colgar costos ni pagos y el
panel quedaba inutilizable.

Ahora `PmsInformacionFinancieraCoherenciaListener::crearCabeceraPara()` la crea en el mismo
flush en que se inserta la `PmsReserva` (`onFlush` + `computeChangeSet()`, igual que
`Beds24BookingsPushQueueCreator`), con la moneda base (`MonedaResolver::resolve(null)` → USD)
y totales en cero.

- **Se hace para TODA reserva, no sólo las directas.** Es idempotente (la relación es 1:1 y
  `PmsInformacionFinancieraFactory::upsertForReserva()` hace find-or-create), y además desbloquea
  registrar un pago en una reserva OTA cuyas facturas todavía no han llegado del canal.
- **Reservas antiguas:** la migración `Version20260802235500` hizo el *backfill* de las que ya
  existían sin cabecera (280 filas), respetando las que ya tenían datos.

### 12.0.1 Cargos automáticos de una estancia directa

Tener la cabecera (§12.0) resuelve *dónde* colgar los importes, pero en una directa seguían
entrando a mano. `PmsCargosAutomaticosService::generarParaEvento()` los arma solo al insertarse
la estancia:

| Concepto | Origen del importe | ¿Se genera? |
|---|---|---|
| `ALOJAMIENTO` | Suma del precio de **cada noche** del tarifario | Sí, si hay tarifas |
| `LIMPIEZA` | Fijo, `PmsCargosAutomaticosService::TARIFA_LIMPIEZA` (hoy `15.00`) | Siempre |
| `SERVICIO` | — | **Nunca: en las directas se exonera** |

La ausencia del cargo por servicio es una **regla de negocio deliberada**, no un olvido: las OTA
lo cobran y lo mandan en sus `invoiceItems` (§11), pero a los huéspedes directos se les exonera.

**Alojamiento = precio por día, no tarifa plana.** Se reutiliza
`TarifaPricingEngine::buildDailyPricesForIntervalWithFallback()` — el mismo motor que pinta el
calendario de tarifas —, así que el importe respeta temporadas, prioridades y solapamientos
*exactamente igual que lo que el operador ve en pantalla*. El intervalo es `[llegada, salida)`:
la noche de salida no se cobra, que es justo el contrato del flattener (`to` exclusivo).

⚠️ **Gotcha — el flattener OMITE los días que no sabe precisar.** No devuelve `0`, devuelve un
mapa más corto: quien sume precios a ciegas cobra menos noches de las reales sin enterarse. De
ahí dos defensas:

1. **Fallback a la tarifa base de la unidad** (`PmsUnidad::isTarifaBaseActiva()` /
   `getTarifaBasePrecio()`), el mismo que usa el push de tarifas a Beds24
   (`Beds24RatesPushQueueCreator::createFallbackProvider()`). Sin él, una estancia en fechas sin
   ningún `PmsTarifaRango` — habitual en fechas lejanas — generaría un cargo de cero.
   Por eso se usa la variante `…WithFallback()`: la versión sin fallback no acepta el proveedor.
2. **Guarda de cobertura:** si aun con el fallback faltan noches (`count($preciosDiarios) <
   $nochesEsperadas`), **no se crea el cargo** y se registra en el log.

`$nochesEsperadas` se cuenta **a día truncado, no a instante**: la estancia va de las 14:00 a las
10:00, así que un `diff()` crudo de dos noches daría "1 día y 20 horas" → 1 (§12.5.5). El
flattener trunca a medianoche y aquí se cuenta igual (`aDia()`).

**Cuándo aplica** (`PmsCargosAutomaticosService::aplica()`):

- Sólo estancias de **canal directo**. Una OTA recibe sus cargos del canal; duplicarlos falsearía
  el saldo.
- Nunca sobre un **bloqueo**: no es una venta.
- Requiere unidad + fechas.

**Grados de fallo — nunca se inventa un importe.** Si la cobertura queda incompleta o el motor de
precios revienta, se registra en el log y **no se crea el cargo de alojamiento** (la limpieza sí).
El operador lo añade a mano. Romper el guardado de la reserva por un problema del tarifario sería
peor que un cargo faltante.

**Verificado** (2026-08-02) sobre una directa de 2 noches en Casita 1: cabecera USD, alojamiento
`140.00` (2 × 70.00 del tarifario), limpieza `15.00`, servicio `0.00`, saldo `155.00`; re-guardar
la reserva no duplicó cargos.

**Ambos nacen como cargos MANUALES** (sin `beds24ItemId`, §12.4.1): el operador puede corregirlos
o borrarlos sin pelearse con la sincronización. Y es **idempotente por estancia** —
`yaTieneCargos()` mira si ya hay cargos imputados a ese evento—, así que un segundo guardado del
drawer no duplica el alojamiento.

**Orden dentro del flush (gotcha):**

```
onFlush   → paso 5: recolecta los PmsEventoCalendario INSERTADOS que pasan `aplica()`
postFlush → generarCargosAutomaticos()  ← PRIMERO: crea los cargos y hace su propio flush
            recalcular($ids)            ← DESPUÉS, con los IDs de cabecera que devolvió
```

El orden importa: si el rollup corriera antes, el saldo recién creado saldría en cero hasta el
siguiente guardado. Ese `flush()` interno vuelve a disparar `onFlush`, pero el guardia
`isFlushing` lo corta — por eso `generarCargosAutomaticos()` **devuelve** las cabeceras tocadas
en vez de confiar en que el listener las recolecte sola.

### 12.0.2 Desglose por tipo en la cabecera

`PmsInformacionFinanciera::getTotalPorTipo(PmsTipoCargo)` (con los atajos serializados
`getTotalAlojamiento()`, `getTotalLimpieza()`, `getTotalServicio()`) suma los cargos de un solo
concepto, en la moneda de la cabecera.

⚠️ **Es un espejo en PHP del SQL del rollup** (`PmsInformacionFinancieraRecalculoService`) y
replica su regla de anulación: **con `activa = false` sólo cuenta la `PENALIZACION`** (§12.7).
Si se cambia esa condición hay que tocar **los dos sitios**, o el desglose dejará de cuadrar con
`totalCargos`.

### 12.0.3 Los importes de mensajería salen de la cabecera, no de `PmsReserva`

`PmsReserva::montoTotal` es un agregado de `PmsEventoCalendario.monto`, un campo que **sólo
rellenan las OTA**. En una reserva directa vale `0.00`, así que una plantilla de WhatsApp que
dijera *"el total de su reserva es {{total_amount}}"* mandaba **"0.00"** al huésped. Además nunca
supo desglosar alojamiento / limpieza / servicio, ni cuánto queda por pagar.

Las variables financieras de `PmsMessageDataResolver::getMessageVariables()` ahora leen la
cabecera:

| Variable | Origen |
|---|---|
| `total_amount` | `PmsInformacionFinanciera::getTotalCargos()` |
| `accommodation_amount` | `getTotalAlojamiento()` |
| `cleaning_fee` | `getTotalLimpieza()` |
| `service_fee` | `getTotalServicio()` |
| `paid_amount` | `getTotalPagos()` |
| `balance` | `getSaldo()` |
| `currency` | `getMoneda()?->getId()` |

Sin cabecera devuelven `'0.00'` en vez de reventar. `getPreviewMessageVariables()` trae los
mismos nombres con valores dummy — **hay que añadirlos en los dos sitios**, porque el set de
preview es el que se manda a Meta como `example` al crear la plantilla; una variable que falte
ahí hace que Meta rechace la plantilla.

**Verificado** (2026-08-02): en una OTA, `total_amount` coincide con el `montoTotal` de siempre
(246.15) y ahora se desglosa 201.00 + 15.00 + 30.15; en una directa que antes daba `0.00`,
devuelve 250.00 con 240.00 pagados y 10.00 de saldo.

**`PmsReservaMessageContext` también.** `getFinancialTotal()` e `isFinancialCleared()` alimentan
`MessageConversation::setContextFinancials()`, que es lo que consulta el motor de reglas. La
cabecera **se inyecta por constructor** (segundo argumento, opcional) en vez de resolverse dentro:

- El adaptador no tiene `EntityManager` — envuelve una entidad, nada más.
- Añadir la relación inversa en `PmsReserva` **no** era la salida fácil: el lado inverso de un
  `OneToOne` no se puede proxiar, así que Doctrine cargaría la cabecera en **cada** hidratación de
  reserva y penalizaría el calendario entero por un dato que sólo usa el chat.
- Si llega `null` se cae a `montoTotal` (comportamiento anterior). Los dos sitios que construyen
  el contexto (`Beds24ReceivePersister::upsertMessages()` y
  `PmsReservaRecalculoService::recalcularDesdeEventos()`) ya la pasan.

`isFinancialCleared()` exige `totalCargos > 0` antes de mirar el saldo: sin cargos el saldo
también es cero, y dar por saldada una reserva recién creada haría que el chat se saltara los
mensajes de cobro.

⚠️ **`contextData.financials` es una FOTO, las variables no.** Las variables se resuelven en el
momento del envío, así que siempre van frescas. El snapshot de la conversación sólo se refresca
cuando algo vuelve a llamar a `upsertFromContext()` — registrar un pago actualiza el rollup pero
**no** re-sincroniza la conversación. Si una regla del chat llega a depender del saldo al céntimo,
hay que disparar ese upsert desde el listener financiero.

### 12.1 Problema

Cargos (de Beds24) y pagos (propios) pueden llegar en monedas distintas entre sí y distintas de la
moneda de la cabecera (`PmsInformacionFinanciera.moneda`, ej. un hotel que cotiza en soles pero
recibe un pago en dólares). Sumarlos a lo bruto sin convertir daría un total sin sentido.

### 12.2 Regla de coherencia

Los totales de la cabecera (`totalCargos`, `totalPagos`) se expresan **siempre en la moneda de la
cabecera**. Cada hijo se convierte a esa moneda usando **su propio** `tipoCambio` (venta USD→PEN,
snapshoteado al crearlo — ver §11.4) antes de sumar:

```
Cabecera en PEN + cargo de 100 USD (TC snapshoteado 3.5)  → aporta 350.00 PEN al total
Cabecera en USD + pago de 100 PEN (TC snapshoteado 3.5)   → aporta  28.57 USD al total (100 / 3.5)
Cabecera en USD + cargo de 100 USD                        → aporta 100.00 USD (misma moneda, sin convertir)
```

Si un hijo está en moneda distinta a la cabecera y **no tiene TC** (no se pudo obtener el día que
se registró), aporta **0** al total en vez de inventar una cifra — mismo criterio conservador que
`TipoCambioDelDia::venta()` devolviendo `null`.

### 12.3 Cuándo se recalcula (Doctrine onFlush/postFlush)

```
PmsInformacionFinancieraCoherenciaListener   (mismo patrón que PmsReservaRecalculoListener)
  onFlush   → detecta inserciones/updates/deletes de PmsCargoFinanciero / PmsPagoFinanciero,
              y cambios de 'moneda' en la propia cabecera. Recolecta los IDs de cabecera afectados.
  postFlush → PmsInformacionFinancieraRecalculoService::recalcular($ids, $em)
              UPDATE ... SQL crudo (mismo patrón que PmsReservaRecalculoService): agrega
              cargos (tipo=charge) y pagos por informacion_id, convirtiendo cada fila a la
              moneda de su cabecera antes de sumar. Sin flush de entidades: es SQL directo.
```

Cambiar la moneda de la **cabecera** es seguro: el siguiente flush reconvierte todos sus hijos a
la nueva moneda automáticamente (no hace falta bloquearla).

### 12.4 Candado de moneda en cargos y pagos

Cambiar la moneda de un **cargo o pago que ya existe en BD** (es un UPDATE, no un INSERT) está
**bloqueado**: `PmsInformacionFinancieraCoherenciaListener::onFlush()` revisa el changeset de
Doctrine y lanza `DomainException` si el campo `moneda` cambia de un valor no nulo a otro.

```
old=null,  new=X   → PERMITIDO   (backfill: primer seteo de moneda en una fila antigua sin moneda)
old=USD,   new=PEN → BLOQUEADO   (ya se registró en USD con su propio TC; cambiarla sin
                                   remontar monto/TC dejaría el registro inconsistente)
```

- **Por qué el candado es sólo sobre hijos, no sobre la cabecera:** la moneda de un cargo/pago está
  atada a un `monto` + `tipoCambio` capturados en ese instante — mutarla invalidaría esa foto. La
  moneda de la cabecera es sólo la *unidad de reporte* del rollup; cambiarla no toca ningún dato
  capturado, sólo hace que el rollup se re-exprese en otra moneda.
- **UX:** `PmsPagoFinancieroCrudController` deshabilita el campo `moneda` en la página de edición
  (`Crud::PAGE_EDIT`) para que el operador nunca choque con la excepción — el candado del listener
  es la defensa real, el campo deshabilitado es sólo higiene de formulario.
- **Los cargos de Beds24** ya son de sólo lectura en el panel (§11), así que en la práctica el
  candado los protege del *backfill* automático del persister, no de ediciones manuales.

### 12.4.1 Cargos manuales vs sincronizados

Para que una reserva directa pueda tener costos, `PmsCargoFinanciero.beds24ItemId` es
**NULL-able**: `NULL` identifica un cargo **manual** creado por el operador. MySQL admite varios
`NULL` en un índice único, así que el dedupe de los cargos de Beds24
(`uq_cargo_info_item`) sigue intacto.

| | Cargo de Beds24 | Cargo manual (`beds24ItemId = NULL`) |
|---|---|---|
| Origen | Sincronización (§11) | `POST /pms_cargo_financieros` |
| `isManual()` | `false` | `true` (se serializa como `manual`) |
| Editar | Sí, pero **tras abrir el candado** en la UI | Sí, sin candado |
| Borrar | **Nunca** (`assertCargoBorrable()` lanza `DomainException`) | Sí |

Por qué un cargo de Beds24 no se puede borrar: el siguiente pull lo recrearía igual, y mientras
tanto el saldo quedaría desfasado. Corregirlo (Patch) sí tiene sentido; borrarlo no.

**Invariantes de un cargo manual** (`PmsCargoFinanciero::aplicarDefectosDeCargoManual()`,
`prePersist`): se le fuerza `tipo = 'charge'` — sin eso el rollup, que suma sólo los `charge`,
lo ignoraría y el cargo no afectaría al saldo — y `fechaCreacionBeds24 = ahora`, que es el campo
por el que se ordena la colección.

**Imputación a una estancia.** Una reserva puede tener varias casitas también siendo directa. Los
cargos de Beds24 se atribuyen por `beds24BookingId`, pero un cargo manual no tiene booking del
canal: para eso está `PmsCargoFinanciero.evento` (FK nullable a `PmsEventoCalendario`), que el
operador elige en el panel. `NULL` = cargo de la reserva en conjunto.

La UI **no agrupa por dos claves distintas**: `getEstancias()` devuelve el `eventoId` de cada
estancia junto a su `beds24BookingId`, y el panel traduce ambas familias a esa única clave
(`ReservaFinanzasPanel.vue::claveEstancia()`). El `<select>` de estancia sólo aparece si la
reserva tiene más de una casita; con una sola se preselecciona sin preguntar.

`ON DELETE SET NULL` en la FK: si se borra la estancia, el cargo sobrevive como cargo general en
lugar de irse con ella — es dinero registrado, no un dato derivado.

### 12.4.2 El tipo de cambio SÍ se puede rellenar (pero no cambiar)

Excepción deliberada al candado anterior. Un registro en moneda distinta a la cabecera y **sin
tipo de cambio aporta 0 al total** (§12.2). Si el candado sobre `tipoCambio` fuera total, la
única forma de arreglar un cargo así sería borrarlo y rehacerlo — y en un cargo sincronizado
desde Beds24 eso ni siquiera es posible (§12.4.1).

```
old=null, new=X   → PERMITIDO   (reparación: el cargo empieza a sumar)
old=X,    new=X   → PERMITIDO   (no es un cambio)
old=X,    new=Y   → BLOQUEADO   (es la foto del día; corregirla falsearía el histórico)
```

Lo aplica `PmsInformacionFinancieraCoherenciaListener::assertTipoCambioNoBloqueado()`, y por eso
`tipoCambio` está en el grupo `pms_cargo:patch` mientras `moneda` sigue fuera. La SPA sólo envía
el campo cuando el cargo no lo tenía.

**Este era un bug real, no una hipótesis.** El formulario de alta de cargos tenía el campo de
tipo de cambio con su aviso, pero **sin autocompletado** (el de pagos sí lo tenía). El operador
registraba un cargo de S/. 1425.00 y el panel seguía enseñando `US$ 0.00` en Cargos. Verificado
en local: cargo en PEN sin TC → `totalCargos` no se movía de 250.00; al rellenar TC=3.411 pasó a
667.77 (250 + 1425/3.411); al intentar cambiarlo a 3.900, `DomainException`.

Arreglado en tres frentes, porque el fallo era de diseño de formulario, no de cálculo:

1. `autocompletarTipoCambioCargo()` rellena la venta del día al abrir el formulario y al cambiar
   de moneda — igual que en pagos, pero con la fecha de hoy (el cargo se crea hoy).
2. `errorCargo` **bloquea el guardado** si la moneda difiere y no hay TC. Un aviso que se puede
   ignorar no sirve: el operador ya lo había ignorado.
3. La fila del cargo y el subtotal del grupo marcan en ámbar los que no suman, con el enlace
   mental a la acción ("edítalo para completarlo").

### 12.4.3 Vista dual soles / dólares

La moneda **contable** es una sola: la de la cabecera. El conmutador del panel es de
**presentación**, no cambia ningún dato ni llama al servidor.

La regla que lo hace coherente: **cada registro se convierte con su propio tipo de cambio, nunca
con una cotización global**. Reconvertir el total ya sumado daría una cifra que nadie pactó — si
al huésped se le cobró S/. 1425 con TC 3.750, la vista en soles debe enseñar 1425 exactos.

⚠️ **Consecuencia esperada, no un fallo:** con registros a tipos de cambio distintos, el total en
soles **no** es el total en dólares multiplicado por ningún número. Son dos sumas de cifras
reales, cada una en su moneda. El panel lo dice explícitamente en la franja bajo el conmutador,
en vez de dejar que el operador lo descubra cuadrando a mano.

Implementación: `sumarEnMoneda()` / `importeEnMoneda()` en `util/src/types/pmsFinanzasModel.ts`,
**espejo en TS** de `PmsInformacionFinancieraRecalculoService::expresionConvertida()` (SQL) —
incluida la regla de anulación de §12.7. Si cambia la conversión, hay que tocar **los dos**.
En la moneda contable NO se recalcula nada: se usan los totales del backend tal cual, para que la
vista por defecto no discrepe del servidor por un redondeo distinto.

El conmutador **sólo aparece si hay registros en otra moneda**: en una reserva enteramente en
dólares sería ruido.

### 12.4.4 Moneda base configurable (sólo directas puras)

El cliente directo peruano cierra el trato **en soles**. Hasta aquí la contabilidad era siempre
dólares y los soles se veían por conversión, lo que dejaba la moneda del trato como algo
implícito. Ahora la moneda base (`PmsInformacionFinanciera.moneda`) se puede fijar por reserva,
y con eso queda **determinista**: los formularios de cargo y de pago proponen esa moneda por
defecto, con la otra siempre disponible al tipo de cambio.

**Sólo en reservas DIRECTAS PURAS.** El criterio de `PmsInformacionFinanciera::isMonedaBaseEditable()`
no es el canal de la reserva sino **el origen de los cargos**: basta un cargo sincronizado desde
Beds24 para bloquearlo. En una OTA la moneda la manda el canal, reescribirla falsearía la verdad
histórica (§11), y además un cargo sincronizado no se puede borrar para deshacer el estropicio.

Qué hace `PmsMonedaBaseService::cambiar()`:

| | Qué pasa | Por qué |
|---|---|---|
| **Cargos** | Se **reescribe** el importe en la moneda nueva y la descripción conserva `(orig. USD 250.00 · TC 3.500)` | Es nuestra tarificación, y en una directa pura todos son manuales. La anotación es la traza mínima para auditar sin montar una tabla de histórico |
| **Pagos** | **No se tocan.** Sólo se les rellena el `tipoCambio` si les faltaba | Un pago es un hecho: el dinero entró en una moneda concreta. Reescribirlo haría que el registro y el recibo del huésped dejaran de coincidir |
| **Cabecera** | Cambia `moneda` y el listener recalcula los totales | — |

**Es una operación, no un campo.** `POST /pms/pms_informacion_financieras/{id}/moneda-base`
(`PmsCambiarMonedaBaseProcessor`), no un PATCH sobre `moneda`: tiene efectos, necesita un tipo de
cambio que no es atributo de la cabecera, y un PATCH daría la falsa impresión de que se revierte
poniendo el campo de vuelta. Un `DomainException` se traduce a **422** con el mensaje visible
para el operador.

⚠️ **Levanta los candados de §12.4 y §12.4.2** — reescribe la moneda de un cargo, que es
justo lo que esos candados prohíben. Para que la excepción sea explícita y no se cuele desde
cualquier sitio, se exige declararla entrando en `MonedaBaseRebaseContext::durante()`; el listener
sólo levanta el candado si `estaRebasando()`. Mismo patrón que `SyncContext` (§9.2).

**Verificado** (2026-08-03): directa con cargo de 250.00 USD y pagos de 230 USD / 10 USD / 300 PEN
→ al pasar a base PEN con TC 3.500, el cargo quedó en `875.00 PEN` con la anotación del original,
los pagos intactos y los que estaban en USD con TC 3.500 rellenado; la ida y vuelta a USD devolvió
el cargo a 250.00. Sobre una reserva OTA, `isMonedaBaseEditable()` da `false` y el servicio lanza
`DomainException`.

#### Gotcha: `MonedaResolver` cacheaba entidades desligadas

Salió al probar esto. El resolver cachea las `MaestroMoneda` por instancia para evitar N+1, pero
la caché sobrevivía a un `em->clear()`: la instancia seguía viva en PHP mientras el EntityManager
ya no la conocía, y asignarla a un cargo reventaba con *"A new entity was found through the
relationship … that was not configured to cascade persist"*. Ahora la caché se descarta si
`em->contains()` dice que la entidad está desligada.

**No era sólo un problema del banco de pruebas:** cualquier proceso largo que llame a
`em->clear()` —los workers de messenger, el cron, los persisters por lotes— podía toparse con
ello.

#### Barras de acción pegadas (sticky) de los formularios

Los formularios de pago y de cargo manual son largos (medio, fecha, comisión, TC, referencia,
notas), y en móvil su botón de guardar quedaba fuera de pantalla: el operador acababa usando el
"Guardar Cambios" del drawer, que es mucho más visible. Ahora su barra de acción queda pegada
**justo encima** de esa barra del drawer mientras el formulario está abierto.

Que eso funcione costó esquivar **dos trampas de `position: sticky`**, y las dos son fáciles de
reintroducir sin darse cuenta porque no dan ningún error:

1. **Ningún ancestro puede tener `overflow` distinto de `visible`.** Un `overflow-hidden` se
   convierte en el scrollport de referencia del sticky y, como no scrollea, el elemento no se
   pega a nada. El panel tenía tres (`<section>` raíz y los dos acordeones), todos puestos para
   recortar esquinas redondeadas. Se sustituyeron por redondeo elemento a elemento
   (`rounded-t-xl` en la cabecera — completo si la sección está cerrada — y `rounded-b-xl` en la
   propia barra sticky, que es el último elemento).
2. **La barra no puede ser un ítem del grid.** El bloque contenedor de un sticky dentro de un
   CSS grid es su **celda**, que mide exactamente lo que el elemento: sin recorrido, no hay
   desplazamiento posible. Por eso cada formulario es ahora un envoltorio con el
   `grid grid-cols-2` **dentro** y la barra como hermana **fuera** de él.

El scrollport real es el `flex-1 overflow-y-auto` del drawer (`ReservaEditDrawer.vue`), y su
`<footer>` es hermano suyo, no descendiente: por eso `bottom-0` deja la barra justo por encima
del "Guardar Cambios" sin necesidad de compensar su altura a mano.

⚠️ **Gotcha al añadir estado al panel:** `cargar()` resetea `monedaVista`, y el
`watch(() => props.reservaId, cargar, { immediate: true })` ejecuta `cargar()` **durante el
setup**. Cualquier `ref` que `cargar()` toque debe declararse **antes** de ese watch, o revienta
con `Cannot access 'X' before initialization` al abrir el panel. `vue-tsc` da verde igualmente:
TypeScript no modela el *temporal dead zone*, así que esto sólo se caza abriendo la pantalla.

Esto también arregló un fallo latente: el subtotal por estancia sumaba importes crudos de monedas
distintas y los etiquetaba con la moneda de la cabecera, así que un cargo de S/. 1425 aparecía
como `US$ 1425.00` en la cabecera de su grupo.

### 12.4.5 Depósito automático de las OTA de pago total

En los canales de `PmsChannel::CANAL_PAGO_TOTAL` (hoy **Airbnb y VRBO**) el dinero no lo cobramos
nosotros: la OTA cobra al huésped y **deposita en la cuenta bancaria**. No hay ningún pago que
teclear, así que esas reservas figuraban impagadas aunque estuvieran cobradas al 100 %.

`PmsPagoOtaAutomaticoService` crea y mantiene ese pago, dejando el saldo en **0**:

| | Valor | Por qué |
|---|---|---|
| Importe | `totalCargos` | Es lo que deja el saldo en cero, que es la regla pedida |
| Fecha | Llegada **+ 1 día** | Es cuando la OTA libera el depósito |
| Medio | Transferencia bancaria | Es como entra |
| Moneda | La de la cabecera | Así no necesita tipo de cambio y no depende de §12.2 |

⚠️ **No se genera al crear la cabecera**, aunque sea lo intuitivo: en ese momento la reserva aún
no tiene cargos (llegan después por webhook o por el pull de invoiceItems, §11) y el pago habría
nacido en `0.00`. Se sincroniza **después de cada recálculo**, que es cuando el importe ya se
conoce — y como al crearlo cambia `total_pagos`, el listener **vuelve a recalcular**.

**El depósito es de SÓLO LECTURA.** Editarlo o borrarlo lanza `DomainException` con un mensaje
que dice dónde está la verdad: *corrige los cargos, que el depósito los sigue*.

Esa decisión salió de un fallo detectado al probarlo. El primer diseño desmarcaba el pago cuando
el operador lo editaba, para respetar su criterio. Resultado: al perder la marca, el sincronizador
no encontraba depósito automático y **creaba otro** — dos pagos y el saldo en −100. La raíz era
que un solo booleano intentaba significar dos cosas a la vez ("es el depósito del canal" y "lo
gestiona el sistema"). Se resolvió quitándole al pago la condición de dato editable: **es un
reflejo de los cargos**, no un dato propio.

`referencia` y `notas` sí se pueden anotar: no afectan al saldo.

**Si los cargos desaparecen, el depósito se borra solo** (o si la reserva se anula y no queda ni
penalización). Sin cargos no se inventa un depósito.

**Backfill:** `Version20260803190000` añade `es_automatico` y crea los depósitos que faltaban.
Inserta la **diferencia** `total_cargos − total_pagos`, no el total: una reserva con un pago
parcial ya registrado a mano habría quedado con saldo negativo. Y cuadra la caché de totales a
mano, porque el rollup es SQL crudo y no se dispara desde una migración.

⚠️ **Tensión conocida, decidida a favor del saldo cero:** las OTA depositan **neto de su
comisión**, así que el importe del depósito no es literalmente lo que aparece en el extracto
bancario. Se prioriza que el saldo quede en cero, que es lo que se pidió. Para reflejar el neto
real habría que cambiar `PmsPagoOtaAutomaticoService::sincronizar()` **y aceptar que el saldo deje
de ser cero**.

## 12.5 Panel financiero en la SPA (`util/`) y patrón de Enums por AJAX

**Archivos relevantes:**
- `src/Api/Controller/Tipo/PmsEnumAjaxController.php`
- `util/src/components/reservas/ReservaFinanzasPanel.vue`
- `util/src/stores/reservas/finanzasStore.ts` · `util/src/types/pmsFinanzasModel.ts`

### 12.5.1 Enums expuestos por AJAX (patrón a replicar)

Las etiquetas, colores, iconos y reglas de negocio de un enum PHP **no se duplican en
TypeScript**: se sirven desde un controlador AJAX y el frontend los consume. Es el mismo patrón
de `TravelEnumAjaxController` (`/tipo/user/enum/travel/componente-tipos`), ahora también para PMS:

```
GET /tipo/user/enum/pms/tipos-cargo   → [{ id, label, color }]            (PmsTipoCargo)
GET /tipo/user/enum/pms/medios-pago   → [{ id, label, icono,
                                           comisionPorcentaje }]           (PmsMedioPago)
```

- El prefijo `/tipo/user/...` hereda las reglas del firewall (no lleva security propio).
- Respuestas con `setSharedMaxAge(3600)`: la forma de un enum casi nunca cambia.
- Para **añadir un caso** basta tocar el enum PHP (`case` + `label()` + `color()`/`icono()`):
  la SPA lo recoge sola, sin tocar TS.

**Qué SÍ conviene migrar a este patrón:** metadatos de presentación (label, color, icono) y
reglas atadas a un `case` (`comisionPorcentaje()`, `sinHorario()`, `prioridad()`).

**Qué NO** (y por qué): `PMS_ESTADO`, `PMS_ESTADO_PAGO` y `PMS_CHANNEL` de
`util/src/types/pmsReservaModel.ts` son **identificadores**, no metadatos. Se usan en funciones
puras y síncronas (`filtrarEstadosDisponibles()`, `requiereAutoConfirmacionPorPago()`) y como
literales `as const` para el estrechado de tipos. Cargarlos por red los volvería asíncronos y
perderían el tipado literal, a cambio de nada: sus **etiquetas y colores ya vienen de la API**
(son filas de `PmsEventoEstado` / `PmsChannel`, servidas con `nombre` y `color`), así que hoy
no hay duplicación que eliminar.

### 12.5.2 Panel financiero del drawer

`ReservaFinanzasPanel.vue` se monta dentro de `ReservaEditDrawer.vue` **solo si hay
`reservaId`**: durante la creación la reserva todavía no existe, así que no hay cabecera a la
que colgar nada.

Va **arriba del todo**, por delante de la estancia y del titular, y **arranca colapsado**: lo
primero que se consulta al abrir una reserva es cuánto debe, pero desplegado ocupa demasiado
para dejarlo fijo. Por eso su cabecera —en degradado teal, o **ámbar si el cobro está
anulado** (§12.7)— ya muestra el **saldo** sin necesidad de abrirlo, en verde si está saldado y
en rojo si queda pendiente. Al cambiar de reserva vuelve a colapsarse.

Dentro: un resumen (Cargos · Pagado · Saldo, en la moneda de la cabecera) y dos acordeones,
**Cargos** y **Pagos**.

**Gotcha del alta de una reserva directa.** Como el panel exige `reservaId`, al crear no aparece;
y si el drawer se cerrara al guardar, habría que buscar la reserva otra vez en el calendario para
cargarle los costos — justo el flujo normal de una directa, que no recibe cargos del canal. Por
eso `guardar()` devuelve el id creado en el evento `saved` y `ReservasView::onGuardado()`
**deja el drawer abierto** en modo edición sobre esa reserva. Para el resto de casos (bloqueo,
edición) el drawer se cierra como siempre.

**Varias casitas al crear.** `PmsReservaCrearProcessor` sólo admite UNA estancia por payload, así
que el drawer crea la reserva con la primera y las demás del acordeón las cuelga después con
`createEvento(... reserva: <IRI>)` — el mismo camino que la edición usa para una estancia añadida
a mano. `permiteVariasEstancias` habilita el botón "Agregar estancia" (en creación de reserva y en
edición, nunca en un bloqueo); el acordeón sólo se pinta al llegar a la segunda estancia, porque
con una sola las cabeceras plegables sobran.

### 12.5.3 Color de las estancias y drag & drop de las OTA

- **Cada estancia se tiñe con el color de su estado** (`resolveEventoColor()`, el mismo que usa
  el calendario): cabecera al ~18% de opacidad, cuerpo al ~4% y borde al ~35%
  (`CABECERA_ALPHA` / `CUERPO_ALPHA` / `BORDE_ALPHA` en `ReservaEditDrawer.vue`). El salto de
  tono entre cabecera y cuerpo es lo que marca dónde acaba una estancia y empieza la siguiente
  cuando hay varias apiladas; el cuerpo queda muy tenue para no restar contraste a los campos.
- **Las estancias OTA no se arrastran ni se redimensionan en el calendario.** `eventDataTransform`
  les pone `editable: false` cuando `extendedProps.isOta`, con `eventAllow` como red de
  seguridad. Antes se podían arrastrar y el backend las rechazaba con 403 (§3): el evento volvía
  a su sitio tras un error. Ahora ni siquiera aparece el cursor de "mover".
- El aviso de "las fechas las gestiona el canal" es **una nota gris discreta**, no un banner de
  alerta: es contexto permanente de toda reserva OTA, no una incidencia.

### 12.5.4 Fechas del drawer: arrastre del check-out y validación

- **Rango por defecto: 2 noches** (`RANGO_POR_DEFECTO_DIAS`). Está duplicado a propósito en
  `ReservasView.vue` (clic en el calendario) y `ReservaEditDrawer.vue` (botón "Agregar estancia"):
  son dos puntos de entrada distintos, **hay que tocar los dos**.
- **Al mover el check-in, el check-out se arrastra los mismos días conservando su hora**
  (`onCambiarInicio()`). Correr una reserva de semana no obliga a recalcular la salida: la
  duración se mantiene y la hora de check-out del establecimiento no se toca. Cambiar sólo la
  *hora* del check-in no mueve el check-out (el desplazamiento se mide en días naturales).
- **Los campos usan `FechaHoraPicker`** (24 h, con máscara), no el `<input type="datetime-local">`
  nativo — ver `docs/UI_Componentes_Compartidos.md` §1, incluido el `minmax(11rem, 1fr)` que hace
  que check-in y check-out salten de línea solos en móvil.
- **Validaciones que cortan antes de la red** (`errorFechas()` y `errorUnidad()`): marcan el campo
  en rojo, explican el motivo y **desactivan el botón de guardar** (`hayErroresEstancia`).
  - `fin > inicio` — el backend lo rechaza con 422.
  - **unidad obligatoria** — sin ella el POST daba un `400 Bad Request` seco, sin pista de qué
    faltaba. El `<select>` lleva además una opción vacía explícita («— Elegir casita —»): sin ella
    mostraba la primera casita de la lista aunque el modelo estuviese vacío, y el operador creía
    haberla elegido.
- **Clonar fechas de la estancia anterior** (`clonarFechasDeAnterior()`): al añadir una estancia
  las fechas **encadenan** (empieza donde acabó la anterior), que es lo correcto para una mudanza
  de casita a mitad de viaje. El botón de copiar en la barra cubre el otro caso frecuente: dos
  casitas ocupadas **en paralelo** por el mismo grupo.
- **`monto` y `comision` ya NO se editan ni se muestran en el drawer.** El dinero de la reserva
  vive en el panel de Finanzas (cargos + pagos); tenerlo en los dos sitios daba dos cifras que
  podían contradecirse. Los campos siguen en el modelo y se envían **sin tocar**, para no pisar
  lo que sincroniza el canal en una estancia OTA.

  ⚠️ **Consecuencia a tener presente:** en una reserva **directa** nadie escribe ya `evento.monto`,
  así que `PmsReserva::montoTotal` se queda en `0.00` mientras el importe real está en
  `total_cargos`. Eso afecta a lo que lee `montoTotal` fuera del panel:
  `PmsReservaMessageContext::getFinancialTotal()` (contexto del chat) y la variable
  `total_amount` de `PmsMessageDataResolver` (plantillas de mensajería). En reservas OTA no
  cambia nada, porque ahí el monto lo sigue escribiendo el pull. Si esas plantillas deben
  reflejar el importe real de una directa, el arreglo es que esos dos puntos lean la cabecera
  financiera en vez de `montoTotal`.
- **Gotcha de zona horaria:** los `datetime-local` se parsean con `parseLocal()`, nunca con
  `new Date(str)` — ese constructor lee `"YYYY-MM-DD"` como UTC y en Perú (UTC-5) devuelve el día
  anterior.

### 12.5.5 ⚠️ `inicio`/`fin` son HORA DE PARED, no instantes

`pms_evento_calendario.inicio` y `.fin` son columnas `datetime` **sin zona**, y el backend las
escribe con la hora de recepción del establecimiento (`resolveFechaConHora()`: check-in 14:00,
check-out 10:00). **"Las 14:00" significa las 14:00 en recepción**, no un instante universal.

El frontend las trataba como instantes: `new Date(iso)` al leer y `.toISOString()` al escribir.
En Lima (UTC-5) eso producía:

```
BD 14:00  →  API "14:00+00:00"  →  el formulario mostraba 09:00
y al corregirlo a 14:00 a mano, la BD acababa guardando 19:00
```

El desfase no se acumulaba entre guardados, pero **la hora mostrada era falsa** y las estancias
editadas a mano quedaban descuadradas respecto a las que crea la sincronización de Beds24.

**Regla:** en `util/`, estas fechas se manejan siempre como **cadena de hora de pared**
(`"YYYY-MM-DDTHH:mm"`) y **nunca pasan por `new Date()`**:

| Helper (`pmsReservaModel.ts`) | Qué hace |
|---|---|
| `toDatetimeLocal(iso)` | Recorta la cadena del backend; ignora segundos y offset |
| `fromDatetimeLocal(local)` | Devuelve `"…T14:00:00"` **sin `Z` ni offset** |
| `fechaAInputLocal(date)` | `Date` → hora de pared, sin convertir a UTC |

Los **productores** también: `ReservasView::abrirCreacion()` y `agregarEvento()` construyen la
cadena con `fechaAInputLocal()`, no con `toISOString()` — si uno solo de los dos lados convierte,
el desfase vuelve. Verificado estable en `America/Lima`, `UTC`, `Europe/Madrid` y `Asia/Tokyo`,
y con la API sirviendo `+00:00`, `-05:00` o sin offset.

- **Candado de habilitación en Cargos:** el bloque nace **bloqueado**; hay que marcar "Habilitar
  edición" para corregir un cargo **sincronizado** (verdad histórica del canal, §11: editarlo es
  excepcional). Los **cargos manuales no pasan por el candado** — son nuestros, se editan y borran
  directamente (§12.4.1). El botón "Agregar cargo manual" tampoco lo requiere: dar de alta un costo
  es el flujo normal de una reserva directa.
- **Pagos: siempre globales a la reserva, nunca por estancia.** `PmsPagoFinanciero` cuelga de la
  cabecera y no tiene FK a `PmsEventoCalendario`, a diferencia de los cargos. Es deliberado: el
  huésped paga **una vez por la reserva**, no por apartamento — un depósito de 100 USD sobre un
  grupo de dos casitas no se reparte de forma no ambigua entre ellas, y forzar la elección
  inventaría un dato que nadie tiene. El desglose por casita vive en los **cargos** (qué se
  cobra); los pagos responden a "cuánto entró", y el `saldo` de la cabecera cruza ambos.
- **Pagos:** alta, edición y borrado libres (`PmsPagoFinanciero` sí expone `Post`/`Patch`/`Delete`).
  Al elegir el medio de pago se **sugiere** la comisión con el `comisionPorcentaje()` del enum
  (5.5% en tarjeta); el operador puede pisarla.
- **Candado de moneda en la UI:** al **editar** un pago el `<select>` de moneda va `disabled`, y
  el PATCH ni siquiera la envía — el grupo `pms_pago:patch` la excluye (§12.4). Así el operador
  no choca nunca con el `DomainException` del listener, que sigue siendo la defensa real.
- **Aviso de moneda sin TC:** si el pago va en una moneda distinta a la de la cabecera y se deja
  el tipo de cambio vacío, el panel avisa de que ese pago **no sumará al saldo** (es la regla
  conservadora del rollup, §12.2).

Los totales del resumen **no se calculan en el front**: se leen de la cabecera, que mantiene el
listener de coherencia. Tras cualquier alta/edición el store recarga la cabecera
(`finanzasStore.recargar()`) para reflejar el nuevo saldo.

**"Guardar Cambios" arrastra los formularios del panel.** El botón principal del drawer es mucho
más visible que los botoncitos de cada formulario financiero, así que es el que se acaba
pulsando: antes, un cargo o un pago tecleado pero sin confirmar se perdía en silencio al cerrar.
Ahora el panel expone `guardarPendientes()` (vía `defineExpose`) y `ReservaEditDrawer::guardar()`
lo invoca **al final**, después de la estancia y el titular:

- Se ejecuta el último para que un fallo aquí no deje a medias lo principal; lo ya guardado
  arriba es idempotente al reintentar.
- Si falla, se muestra el error y el drawer **no se cierra** — así no se pierde lo tecleado.
- Los formularios **vacíos se ignoran**: uno abierto por descuido no debe bloquear el guardado
  con un error de validación.
- Los botones propios de cada formulario siguen ahí, para guardar sin cerrar el drawer.

## 12.6 ⚠️ Gotcha: `SearchFilter` no funciona sobre relaciones con UUID binario

**Síntoma:** `GET /pms_informacion_financieras?reserva=<uuid>` devuelve `totalItems: 0` aunque la
fila exista y un `SELECT` a mano la encuentre. **Sin ningún error**: ni 400, ni excepción, ni log.

**Causa:** las FK de UUID son `BINARY(16)`. El `SearchFilter` de API Platform vincula el valor sin
declarar el tipo Doctrine, así que MySQL acaba comparando texto contra binario y nunca casa.
Comprobado en DQL sobre la misma fila:

| Vinculación | Filas |
|---|---|
| `i.reserva = 'uuid-string'` | **0** |
| `i.reserva = $uuid` (objeto, sin tipo) | **0** |
| `i.reserva = $uuid` con `UuidType::NAME` | **1** ✅ |
| `findOneBy(['reserva' => $entidad])` | **1** ✅ |

Que el objeto `Uuid` *sin tipo* también falle es lo importante: significa que **pasar la IRI
tampoco arregla nada** (la IRI se resuelve justo a ese objeto). El filtro es inservible aquí.

**Solución adoptada:** operación dedicada en vez de filtro.

```
GET /platform/pms/pms_informacion_financieras/por-reserva/{reservaId}
  → PmsInformacionFinancieraPorReservaProvider
      → PmsInformacionFinancieraRepository::findOneByReservaId()
          → setParameter('reserva', $uuid, UuidType::NAME)   ← el tipo es obligatorio
```

⚠️ **Segundo gotcha, en la propia operación:** una variable de URI que **no** es el identificador
del recurso hay que declararla en `uriVariables` con un `Link`. Sin eso API Platform intenta
casar `{reservaId}` con el `id` de `PmsInformacionFinanciera` y responde
`404 "Invalid uri variables"` **antes de llegar al provider** — el `security` ni se evalúa, así
que el síntoma es idéntico estando o no autenticado. El patrón correcto ya estaba en la
operación `{localizador}` de `PmsReserva`:

```php
uriVariables: ['reservaId' => new Link(fromClass: PmsReserva::class, identifiers: ['id'])],
```

⚠️ **Tercer gotcha, encadenado al anterior:** al declarar ese `Link`, API Platform **castea la
variable al tipo del identificador**, así que al provider llega un objeto
`Symfony\Component\Uid\Uuid`, **no una cadena**. Una guarda `if (!is_string($x)) return null;`
la descarta y produce otro `404` — indistinguible del "no existe", aunque la fila esté en la
tabla. `PmsInformacionFinancieraPorReservaProvider` normaliza `Uuid|string` a texto antes de
consultar.

Los tres fallos daban **el mismo síntoma** (colección vacía o 404 sin error) por causas
distintas: tipo Doctrine sin declarar, `uriVariables` sin declarar y tipo PHP inesperado. Ante
un 404 en este endpoint, comprobar los tres.

Devuelve el recurso en singular (la relación es 1:1 con la reserva) y 404 si aún no tiene
cabecera, que el store trata como "panel vacío", no como error.

**Se retiraron los dos `SearchFilter` afectados** (`PmsInformacionFinanciera.reserva` y
`PmsPagoFinanciero.informacionFinanciera`): dejar un filtro que siempre responde vacío es una
trampa peor que no tenerlo.

> **Antes de filtrar por una relación UUID en cualquier recurso**, comprueba que devuelve algo.
> `PmsTarifaRango.unidad` tiene un `SearchFilter` con el mismo patrón y, si alguien lo usa desde
> el frontend, se encontrará con este mismo silencio.

## 12.7 Cancelaciones: el flag `activa` y la PENALIZACIÓN

### 12.7.1 El problema

Al cancelar, Beds24 manda `status: cancelled` con `price: 0` **y un invoiceItem nuevo**
("Cancel Fee") con un id propio — no reemplaza a los anteriores. Como el persister sólo hace
upsert y nunca borra, los cargos originales se conservan (bien: **no se pierde historia**) pero
el total pasaría a sumar la estancia completa **más** la penalización:

```
Antes:   746.40  (636 + 95.40 + 15)
Cancela: 746.40 + 62.20 = 808.60   ← se cobraría la estancia Y la penalización
```

Además, el "Cancel Fee" llega con `subType: 8`, el mismo del alojamiento, así que sin más se
clasificaba como una noche más.

### 12.7.2 La regla

`PmsInformacionFinanciera.activa` (bool, default `true`) decide qué computa:

| `activa` | Qué suma `total_cargos` |
|---|---|
| `true` | **Todos** los cargos (caso normal) |
| `false` | **Sólo** los de tipo `PENALIZACION` |

Los cargos **nunca se borran**: siguen en la tabla y visibles en el panel, sólo dejan de contar.

`PmsTipoCargo::PENALIZACION` se detecta **antes** que la regla del `subType` (por descripción:
`cancel`, `penaliz`, `no show`), justo porque comparte el `subType 8` con el alojamiento.

### 12.7.3 Por qué el flag y no el estado de Beds24

Porque **no siempre coinciden**. Un huésped que negocia pasarse a **reserva directa** cancela en
la OTA para ahorrarse la comisión: Beds24 dice "cancelada" pero la estancia ocurre y hay que
cobrarla. Atar el cobro al estado del canal daría un saldo de 0 en una estancia que sí se sirve.

Por eso el flag es persistido e independiente:

- `PmsInformacionFinancieraCoherenciaListener::aplicarCancelacion()` lo baja **sólo en la
  TRANSICIÓN** a cancelada (mira el changeSet de `estado`), y sólo si **todas** las estancias de
  la reserva quedan canceladas — en un grupo, que caiga una casita no anula el cobro de las otras.
- Como actúa en la transición y no en el estado, un webhook que repite `cancelled` **no vuelve a
  tocarlo**: la decisión del operador gana. Mismo criterio asimétrico que `datosLocked` (§9.3).
- En el panel: aviso ámbar + botón "Reactivar cobro" cuando está anulada, y un "Anular cobro"
  discreto para el caso inverso (un no-show que el canal no marcó).

Verificado con la reserva 86182399 (Van der Meer, 746.40 + fee de 62.20):

```
1) Activa                        activa=SÍ  totalCargos=746.40
2) Cancelada (fee 62.20)         activa=NO  totalCargos= 62.20   ← sólo la penalización
3) El operador la reactiva       activa=SÍ  totalCargos=808.60   ← vuelve a cobrarse todo
4) Webhook repite "cancelled"    activa=SÍ  totalCargos=808.60   ← NO pisa la decisión
```

En los cuatro pasos los 4 cargos siguen en la BD; lo único que cambia es qué suma.

## 13. Dónde tocar para cambiar X

| Necesidad | Archivo | Método/Campo |
|---|---|---|
| Cambiar ventana anti-dup | `Beds24WebhookController` | `600` (seg) y `15000` (ms) |
| Añadir canal de pago total | `PmsChannel` | `CANAL_PAGO_TOTAL` — también decide qué canales generan depósito automático (§12.4.5) |
| Cambiar el importe/fecha del depósito de OTA (§12.4.5) | `PmsPagoOtaAutomaticoService` | `sincronizar()` / `fechaDeposito()` |
| Permitir editar el depósito automático (§12.4.5) | `PmsInformacionFinancieraCoherenciaListener` | `assertPagoAutomaticoNoEditable()` |
| Cambiar lógica de estado OTA | `BookingPullPersister` | `resolveEstado()` |
| Cambiar estado de pago inicial | `BookingPullPersister` | `resolveEstadoPagoInicial()` |
| Cambiar la auto-confirmación por pago (§9.5) | `PmsEventoCalendario` + `util/src/types/pmsReservaModel.ts` | `requiereAutoConfirmacionPorPago()` (hay que tocar **los dos**: son espejo) |
| Añadir campo nuevo al Pull | `Beds24BookingDto` + `BookingPullPersister` | `fromArray()` + `upsertReservaFull()` |
| Cambiar qué campos puede escribir un espejo (§6.4) | `BookingPullPersister` | `upsertEvento()` → bloque `if ($isLinkPrincipal ...)` |
| Cambiar qué se envía al espejo | `BookingsPushMappingStrategy` | `buildUpsertPayload()` bloque `$isMirror` |
| Cambiar ventana de Pull Cron | `Beds24BookingsPullCronJob` | `arrivalFrom/To` |
| Cambiar reintento Push | `BookingsPushHandler` | `handleFailure()` → `$delayMinutes` |
| Cambiar reintento Pull | `BookingsPullHandler` | `handleFailure()` → `'+15 minutes'` |
| Añadir listing espejo | Admin: `PmsEstablecimientoVirtual` + `PmsUnidadBeds24Map` | `esPrincipal=false` |
| Proteger campo Push en OTA | `Beds24BookingsPushQueueListener` | `IGNORED_FIELDS_ON_LOCKED_OTA` |
| Cambiar idioma por defecto | `MaestroIdioma` | `DEFAULT_IDIOMA` |
| Cambiar país por defecto | `MaestroPais` | `DEFAULT_PAIS` |
| Añadir campo nuevo a un cargo financiero | `PmsCargoFinanciero` + `Beds24InvoiceItemDto` + `Beds24InvoiceReceivePersister` | `hidratar()` / `fromArray()` |
| Cambiar qué campos se actualizan al re-sincronizar un cargo | `Beds24InvoiceReceivePersister` | `aplicarCambiosVolatiles()` |
| Cambiar el aplanado de facturas del pull | `Beds24InvoiceReceiveMappingStrategy` | `parseResponse()` |
| Cambiar cuántas reservas van por petición (§11.3.1) | `Beds24InvoiceReceiveTask` / `Beds24ReceiveTask` | `getMaxBatchSize()` — el techo lo pone el TTL del lock, no la paginación |
| Tocar el reparto por bookingId (§11.3.1) | `Beds24InvoiceReceiveMappingStrategy` / `Beds24ReceiveMappingStrategy` | `map()` + `parseResponse()` — recorre la correlación, **nunca** el `data` |
| Cambiar cómo se agrupan los invoiceItems del webhook | `ProcessBeds24WebhookDispatchHandler` | `handleInvoiceItems()` |
| Cambiar qué booking se consulta por reserva/grupo (§11.6) | `PmsBeds24InvoiceTargetFinder` | `findTargetsForPeriod()` (hoy: el `beds24MasterId`) |
| Saber a qué estancia pertenece un cargo de un grupo (§11.6) | `PmsCargoFinanciero` + `PmsInformacionFinanciera` | `beds24BookingId` / `getEstancias()` |
| Cambiar la agrupación de cargos por casita en la UI (§11.6) | `ReservaFinanzasPanel.vue` | `gruposCargos` / `claveEstancia()` |
| Imputar un cargo manual a una estancia (§11.5) | `PmsCargoFinanciero` | FK `evento` (`NULL` = toda la reserva) |
| Cambiar qué pasa tras crear una reserva directa (§12.5.2) | `ReservasView.vue` | `onGuardado()` → `payload.reservaIdCreada` |
| Cambiar el país asumido / el parseo de teléfonos (§5.3) | `util/src/utils/telefono.ts` | `PAIS_POR_DEFECTO` / `interpretar()` |
| Permitir varias casitas al crear (§12.5.2) | `ReservaEditDrawer.vue` | `permiteVariasEstancias` |
| Que el panel financiero arranque abierto (§12.5.2) | `ReservaFinanzasPanel.vue` | `panelAbierto` |
| Cambiar qué arrastra "Guardar Cambios" del panel (§12.5.2) | `ReservaFinanzasPanel.vue` + `ReservaEditDrawer.vue` | `guardarPendientes()` / llamada en `guardar()` |
| Cambiar el tinte de las estancias (§12.5.3) | `ReservaEditDrawer.vue` | `CABECERA_ALPHA` / `CUERPO_ALPHA` / `BORDE_ALPHA` |
| Permitir arrastrar estancias OTA en el calendario (§12.5.3) | `ReservasView.vue` | `eventDataTransform` / `eventAllow` |
| Cambiar el rango por defecto de una estancia (§12.5.4) | `ReservasView.vue` + `ReservaEditDrawer.vue` | `RANGO_POR_DEFECTO_DIAS` (hay que tocar **los dos**) |
| Cambiar el arrastre del check-out o la validación de fechas (§12.5.4) | `ReservaEditDrawer.vue` | `onCambiarInicio()` / `errorFechas()` |
| Tocar la conversión de fechas del drawer (§12.5.5) | `util/src/types/pmsReservaModel.ts` | `toDatetimeLocal()` / `fromDatetimeLocal()` / `fechaAInputLocal()` — **nunca** usar `new Date()` con ellas |
| Buscar la cabecera financiera de una reserva (§12.6) | `PmsInformacionFinancieraRepository` | `findOneByReservaId()` — el tipo `UuidType::NAME` es obligatorio |
| Filtrar por cualquier relación UUID (§12.6) | — | **No uses `SearchFilter`**: devuelve vacío en silencio. Operación dedicada + provider. |
| Cambiar el ritmo del barrido de un cron (§11.2.1) | El job en cuestión | `PASO_BASE_DIAS` / `TRAMO_DIAS` / `MULT_MAX` |
| Cambiar hasta dónde barre un cron (§11.2.1) | El job en cuestión | `getHorizonteMaximo()` — **no** se lo pongas a `bookings_pull`: lo cegaría |
| Que un cron nuevo se adapte a la densidad (§11.2.1) | `CronPasoAdaptativoInterface` / `CronHorizonteInterface` | implementarlas es opt-in; sin ellas, paso fijo y tope de +18 meses |
| Cambiar la clasificación de un cargo (incluida la penalización, §12.7) | `PmsTipoCargo` | `desdeBeds24()` — el caso `PENALIZACION` va **antes** que la regla del subType |
| Cambiar qué computa una reserva cancelada (§12.7) | `PmsInformacionFinancieraRecalculoService` | `AND (i2.activa = 1 OR c.tipo_cargo = 'penalizacion')` |
| Cambiar cuándo se anula el cobro automáticamente (§12.7) | `PmsInformacionFinancieraCoherenciaListener` | `aplicarCancelacion()` |
| Cambiar la moneda por defecto (hoy USD) | `MonedaResolver` | `resolve()` |
| Cambiar quién puede fijar la moneda base (§12.4.4) | `PmsInformacionFinanciera` | `isMonedaBaseEditable()` (hoy: ningún cargo sincronizado ni estancia OTA) |
| Cambiar qué se reescribe al fijar la moneda base (§12.4.4) | `PmsMonedaBaseService` | `cambiar()` — cargos sí, pagos nunca |
| Cambiar la anotación del importe original (§12.4.4) | `PmsMonedaBaseService` | `anotarOriginal()` |
| Añadir un par de monedas al rebase (§12.4.4) | `PmsMonedaBaseService` | `convertir()` — hoy sólo USD↔PEN |
| Levantar los candados de moneda desde otro sitio (§12.4.4) | `MonedaBaseRebaseContext` | `durante()` — **no** debilites los `assert*` del listener |
| Cambiar qué tipo de cambio se snapshotea (venta/promedio/compra) | `TipoCambioDelDia` | `venta()` |
| Cambiar el % de comisión de tarjeta (hoy 5.5%) | `PmsMedioPago` | `comisionPorcentaje()` |
| Cambiar la fórmula del recargo / total cobrado (§11.5) | `PmsPagoFinanciero` + `util/src/types/pmsFinanzasModel.ts` | `getMontoComision()` / `totalConComision()` (son **espejo**: tocar los dos) |
| Cambiar de dónde sale el tipo de cambio del panel (§11.5) | `ReservaFinanzasPanel.vue` | `autocompletarTipoCambio()` (hoy: `venta` de `/maestro/tipo-cambio/consultar`) |
| Añadir un medio de pago | `PmsMedioPago` | nuevo `case` + `labelKey()` |
| Cambiar el rollup de totales / la conversión entre monedas (§12) | `PmsInformacionFinancieraRecalculoService` | `recalcular()` / `expresionConvertida()` |
| Cambiar cuándo se dispara el recálculo (§12) | `PmsInformacionFinancieraCoherenciaListener` | `onFlush()` / `collectInformacionId()` |
| Cambiar o quitar el candado de moneda en cargos/pagos (§12.4) | `PmsInformacionFinancieraCoherenciaListener` | `assertMonedaNoBloqueada()` |
| Cambiar el candado del tipo de cambio (§12.4.2) | `PmsInformacionFinancieraCoherenciaListener` | `assertTipoCambioNoBloqueado()` — hoy permite null→X |
| Cambiar de dónde sale el TC automático de un cargo (§12.4.2) | `ReservaFinanzasPanel.vue` | `autocompletarTipoCambioCargo()` (hoy: venta de hoy) |
| Relajar el bloqueo de guardado sin TC (§12.4.2) | `ReservaFinanzasPanel.vue` | `errorCargo` |
| Cambiar la conversión de la vista dual (§12.4.3) | `util/src/types/pmsFinanzasModel.ts` + `PmsInformacionFinancieraRecalculoService` | `importeEnMoneda()` / `expresionConvertida()` (son **espejo**: tocar los dos) |
| Cambiar cuándo aparece el conmutador de moneda (§12.4.3) | `ReservaFinanzasPanel.vue` | `monedaAlterna` |
| Tocar el redondeo o el layout del panel (§12.4.3) | `ReservaFinanzasPanel.vue` | **No reintroduzcas `overflow-hidden`** ni metas la barra de acción en el grid: rompe el sticky sin dar error |
| Añadir un par de monedas a la vista dual (§12.4.3) | `util/src/types/pmsFinanzasModel.ts` | `importeEnMoneda()` — hoy sólo USD↔PEN, el resto devuelve null |
| Añadir un tipo de cargo o medio de pago (label/color/icono) | `PmsTipoCargo` / `PmsMedioPago` | nuevo `case` + `label()` + `color()`/`icono()` (la SPA lo recoge sola) |
| Exponer un enum PMS nuevo al frontend (§12.5.1) | `PmsEnumAjaxController` | nueva ruta `#[Route]` + `cacheable()` |
| Cambiar qué campos del cargo puede corregir el operador | `PmsCargoFinanciero` | grupo `pms_cargo:write` |
| Cambiar qué campos del pago se pueden editar tras crearlo | `PmsPagoFinanciero` | grupo `pms_pago:patch` |
| Cambiar el candado de edición de cargos en la UI (§12.5.2) | `ReservaFinanzasPanel.vue` | `cargosDesbloqueados` / `puedeEditarCargo()` |
| Cambiar cuándo se auto-crea la cabecera financiera (§12.0) | `PmsInformacionFinancieraCoherenciaListener` | `crearCabeceraPara()` |
| Permitir/impedir borrar un cargo (§12.4.1) | `PmsInformacionFinancieraCoherenciaListener` | `assertCargoBorrable()` |
| Cambiar los valores por defecto de un cargo manual (§12.4.1) | `PmsCargoFinanciero` | `aplicarDefectosDeCargoManual()` |
| Cambiar la tarifa de limpieza de las directas (hoy 15.00, §12.0.1) | `PmsCargosAutomaticosService` | `TARIFA_LIMPIEZA` |
| Empezar a cobrar el servicio en las directas (§12.0.1) | `PmsCargosAutomaticosService` | `generarParaEvento()` — hoy omite `SERVICIO` a propósito |
| Cambiar qué estancias estrenan cargos automáticos (§12.0.1) | `PmsCargosAutomaticosService` | `aplica()` (hoy: directas, sin bloqueos) |
| Cambiar cómo se calcula el alojamiento por noche (§12.0.1) | `PmsCargosAutomaticosService` | `calcularAlojamiento()` → `TarifaPricingEngine::buildDailyPricesForIntervalWithFallback()` |
| Cambiar el relleno de días sin tarifa (§12.0.1) | `PmsCargosAutomaticosService` | `fallbackProvider` (hoy: tarifa base de la unidad) |
| Cambiar el orden cargos-automáticos ↔ rollup (§12.0.1) | `PmsInformacionFinancieraCoherenciaListener` | `postFlush()` / `generarCargosAutomaticos()` |
| Cambiar el desglose de importes por tipo (§12.0.2) | `PmsInformacionFinanciera` + `PmsInformacionFinancieraRecalculoService` | `getTotalPorTipo()` (es **espejo** del SQL: tocar los dos) |
| Añadir una variable financiera a las plantillas (§12.0.3) | `PmsMessageDataResolver` | `getMessageVariables()` **y** `getPreviewMessageVariables()` (Meta exige el `example`) |
| Cambiar el total que ve el motor de reglas del chat (§12.0.3) | `PmsReservaMessageContext` | `getFinancialTotal()` / `isFinancialCleared()` |
| Pasar la cabecera al contexto de mensajería (§12.0.3) | `Beds24ReceivePersister` + `PmsReservaRecalculoService` | 2º argumento de `new PmsReservaMessageContext()` |
