# PMS Beds24 — Sincronización de Reservas

Documento de arquitectura del sistema bidireccional de sincronización de reservas entre Beds24 y el PMS interno. Cubre los dos caminos de entrada (Webhook en tiempo real y Pull por Cron), el Persister compartido, el mecanismo de espejo virtual y el ciclo Push de vuelta a Beds24.

---

## Índice

1. [Visión General](#1-visión-general)
2. [Entidades Clave](#2-entidades-clave)
3. [Camino A — Webhook (Tiempo Real)](#3-camino-a--webhook-tiempo-real)
4. [Camino B — Pull por Cron (Sincronización Programada)](#4-camino-b--pull-por-cron-sincronización-programada)
    · [4.1.b La query se monta a mano (y por qué no llegaban las canceladas)](#41b--la-query-se-monta-a-mano-y-no-es-un-capricho)
    · [4.1.c Qué se pide y qué NO: aquí se traen reservas, no cargos](#41c-qué-se-pide-y-qué-no-aquí-se-traen-reservas-no-cargos)
5. [Persister Compartido — BookingPullPersister](#5-persister-compartido--bookingpullpersister)
    · [5.4 Una reserva que llega como `new` se QUEDA en `new` — es intencional](#54--una-reserva-que-llega-como-new-se-queda-en-new--es-intencional)
6. [El Mecanismo de Espejo Virtual](#6-el-mecanismo-de-espejo-virtual)
    · [6.3.b Cambiar de casita: qué link se mueve y cuál se recrea](#63b-cambiar-de-casita-qué-link-se-mueve-y-cuál-se-recrea)
    · [6.3.c Los estados del link: cuáles se usan de verdad](#63c-los-estados-del-link-cuáles-se-usan-de-verdad)
7. [Camino C — Push de vuelta a Beds24](#7-camino-c--push-de-vuelta-a-beds24)
8. [Motor de Exchange — ExchangeOrchestrator](#8-motor-de-exchange--exchangeorchestrator)
    · [8.1 Quién llena la cola — reactivo vs. Timeline Enqueuer](#81-quién-llena-la-cola--el-listener-reactivo-vs-el-timeline-enqueuer)
9. [Anti-duplicación y Seguridad](#9-anti-duplicación-y-seguridad)
10. [Políticas de Reintento y Error](#10-políticas-de-reintento-y-error)
11. [Camino D — Sincronización Financiera (invoiceItems)](#11-camino-d--sincronización-financiera-invoiceitems)
12. [Coherencia Financiera — Rollup Multi-moneda y Candado](#12-coherencia-financiera--rollup-multi-moneda-y-candado)
    · [12.0.1 Cargos automáticos de una estancia directa](#1201-cargos-automáticos-de-una-estancia-directa)
    · [12.4.3 Vista dual soles / dólares](#1243-vista-dual-soles--dólares)
    · [12.5.6 Descripción del cargo para el huésped: autotraducción y flag de sobrescritura](#1256-la-descripción-del-cargo-para-el-huésped-traducción-automática-y-cómo-rehacerla)
    · [12.5.7 Asignar el cobrador desde la SPA](#1257-asignar-el-cobrador-desde-la-spa-cobradorid-no-una-iri)
    · [12.6 Gotcha: SearchFilter y UUID binario](#126--gotcha-searchfilter-no-funciona-sobre-relaciones-con-uuid-binario)
    · [12.11.b El link ya borrado (2ª causa del «new entity»)](#1211b-la-segunda-causa-del-mismo-error-el-link-ya-borrado)
    · [12.12 Borrado de una reserva o de una estancia](#1212-borrado-de-una-reserva-o-de-una-estancia)
13. [Dónde tocar para cambiar X](#13-dónde-tocar-para-cambiar-x)

> Horario extra (early check-in / late check-out → evento `extension` invisible): §7.1.b.

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
| `PmsRatesPushQueue` | `pms_rates_push_queue` | Jobs de subida de **tarifas** a Beds24 (ver §8.1) |

> ⚠️ **Las colas guardan lo procesado; no se auto-purgan.** Una fila `success` se queda para
> siempre. Con el refresco defensivo de tarifas (§8.1) eso son ~1.000 filas/día que se acumulan:
> `pms_rates_push_queue` llegó a 152.000 filas / 505 MB antes de que se purgara a mano el 17/08/2026.
> El borrado periódico de `success` viejos es responsabilidad de `app:exchange:vigilar-colas` (cron,
> min 25 de cada hora) — si crece una cola, ahí es donde se poda.

---

## 3. Camino A — Webhook (Tiempo Real)

**Archivos relevantes:**
- `src/Pms/Controller/Webhook/Beds24WebhookController.php`
- `src/Pms/Dispatch/ProcessBeds24WebhookDispatch.php`
- `src/Pms/DispatchHandler/ProcessBeds24WebhookDispatchHandler.php`
- `src/Pms/Service/Beds24/Webhook/Beds24WebhookBookingFastTrackService.php`

### 3.0 ⏳ El colchón de 15 segundos (y por qué no se toca sin medir)

Antes de encolar nada, el controlador decide si el trabajo espera:

```
evento RECIENTE (≤10 min)   →  DelayStamp(15 s)
evento VIEJO o reenviado    →  entra directo
```

La frontera son 10 minutos sobre `modifiedTime` —o `bookingTime` si falta— del propio payload,
comparados en valor **absoluto**: también entra directo un evento con fecha futura, que es lo que
ocurre cuando el reloj de Beds24 va adelantado.

**Consecuencia medida:** los mensajes que entran por Beds24 tardan ~15 s más que los de WhatsApp
en empezar a procesarse. En una traza del 12/08 se ven tres `Sending → Received` separados por 15
segundos clavados; no era contención de workers, era esto.

⚠️ **Por qué 15 y no otro número: no está registrado.** Llegó con el refactor a webhook asíncrono
(`d780814`, 19/03/2026) sin explicación ni prueba. Al revisarlo en agosto de 2026 se descartaron
dos motivos plausibles y quedó uno en pie:

| Hipótesis | Veredicto |
|---|---|
| Esperar a que la API de Beds24 tenga el dato | **No.** El payload trae la reserva y el handler la procesa él mismo; quien consulta la API es el cron `Beds24MessageReceiveJob`, por otra cola |
| Evitar la carrera que perdió dos mensajes el 23/03 | **No.** Aquélla ocurría en la cola del pull, no aquí, y se arregló reintentando en vez de marcar éxito (§10) |
| Evitar el ECO de nuestro propio push | **Es la que queda.** Mandamos un cambio, Beds24 nos lo devuelve como webhook, y procesarlo al instante se cruza con nuestra propia escritura. Encaja con que sólo se aplique a eventos recientes y con que el handler entre en `SyncContext::MODE_PULL` — pero es una hipótesis, no una certeza |

**Antes de tocarlo, mide.** Son 15 s en la latencia de cada mensaje del canal de las OTA, así que
quitarlo es tentador. Pero si la hipótesis del eco es cierta, quitarlo devuelve un bucle de
sincronización que no se ve hasta que descuadra una reserva — y ése es justo el tipo de fallo que
este proyecto considera el peor: silencioso y tardío.

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

> ### 🚫 `country2`, nunca `country`
>
> Beds24 manda **las dos** y no son lo mismo — payload real de la auditoría, 15/08/2026:
>
> ```json
> "country": "pe",    ← minúsculas, ISO2 en crudo
> "country2": "PE"    ← MAYÚSCULAS, la canónica
> ```
>
> `Beds24BookingDto` expone **sólo `country2`**, y es deliberado: los identificadores de
> `MaestroPais` son mayúsculas (`PE`, `ES`, `DE`), así que casa directo con `find()`.
>
> **Esto ya se «corrigió» mal una vez.** `BookingPullPersister` leía `$dto->country` —una
> propiedad que el DTO no declara—, el `??` devolvía cadena vacía, y la inferencia de idioma a
> partir del país del huésped llevaba muerta desde que se escribió **sin dar un solo error**.
> Arreglado el 15/08/2026.
>
> Si ves `->country` en cualquier sitio del módulo, es el fallo — no una mejora. El aviso está
> repetido en el propio DTO, junto a la propiedad.

> ### 🔥 El `country2` de Airbnb NO es el país: para hispanohablantes manda el IDIOMA
>
> Auditado sobre `pms_beds24_webhook_audit` (1385 payloads reales, 19/08/2026). Comparando el
> campo con el prefijo del teléfono del propio payload:
>
> | OTA | `country2` | Prefijo del teléfono | Reservas |
> |---|---|---|---:|
> | **Airbnb** | `ES` | **+51 (Perú)** | **16** |
> | Airbnb | `ES` | +52 (México) | 1 |
> | Airbnb | `ES` | +57 (Colombia) | 1 |
> | Airbnb | `ES` | +34 (España) | **0** |
> | Booking.com | `ES` | +34 (España) | 9 |
> | Booking.com | `FR` | +33 (Francia) | 6 |
> | Booking.com | `IT` | +39 (Italia) | 4 |
>
> **Booking.com manda el país de verdad; Airbnb no.** Cuando el huésped tiene el Airbnb en
> español, `country2` llega como `ES` — que es el código de idioma `es` en mayúsculas, y que
> por desgracia también es un país válido, así que `find(MaestroPais::class, 'ES')` casa y no
> falla nada. Los nombres no dejan lugar a duda: Bravo Manco, Cubas Puicon, Vallejos Guevara,
> Onsueta Vasquez… con móviles peruanos distintos y reales.
>
> Pasa igual con `fr`→`FR` y `pt`→`PT`. Con `en` no colapsa —«EN» no es un país— y entonces sí
> llega el país bueno (`US`, `CA`, `PE`).
>
> **Hoy hay 27 reservas de Airbnb guardadas como España.**
>
> #### Y aparte, en un 8 % Airbnb no manda nada
>
> En 4 de 47 reservas distintas de Airbnb el campo llega `null` (JSON null, **no** cadena
> vacía). En Booking.com, en ninguna.
>
> ⚠️ Ojo al comprobarlo: `JSON_EXTRACT(...) IS NULL` **no lo detecta** —un JSON null no es un
> NULL de SQL—. Hay que preguntar por `JSON_TYPE(...) = 'NULL'`. Y `country` (minúsculas) no
> sirve de respaldo: en uno de esos payloads valía `"19"`.
>
> Ahí entra `BookingPullPersister::resolvePais()`, que hace
> `if ($iso2 === '') $iso2 = MaestroPais::DEFAULT_PAIS;` — y `DEFAULT_PAIS` **es `'PE'`**. En
> esas cuatro acertó (apellidos Duque, Tamashiro, Ugaz; teléfonos +51), pero por suerte, no por
> diseño: la reserva queda indistinguible de una con país confirmado.
>
> #### Por qué importa
>
> `PmsProcedenciaHuesped::pagaDesdePeru()` decide qué medios de cobro se le ofrecen a alguien.
> Con `pais = 'ES'` devuelve `false` —«paga desde fuera»— y a un peruano con móvil +51 se le
> ofrece tarjeta con recargo y Western Union, y **no** Yape, Plin ni transferencia. Es
> exactamente al revés de la regla, y a escala.
>
> #### ✅ Cómo se resuelve ahora (19/08/2026)
>
> **En Airbnb manda el teléfono.** `BookingPullPersister::resolvePais()` pide el país al prefijo
> del número con `PhoneSanitizer::paisDelNumero()` antes de mirar `country2`:
>
> ```
> teléfono (prefijo internacional)  →  country2  →  MaestroPais::DEFAULT_PAIS
> ```
>
> El orden importa en los dos extremos. El teléfono va primero porque es el dato duro; y
> `country2` se conserva como respaldo —en vez de saltar al defecto— para no tirar el país bueno
> de los Airbnb en inglés, donde `en` no colapsa contra ningún código de país y el campo llega
> correcto.
>
> `paisDelNumero()` **sólo concluye con números que traen prefijo internacional**, y no se le
> pasa región por defecto a propósito: con una, cualquier número local «resolvería» a esa región
> y devolvería la suposición de entrada disfrazada de conclusión. Cuando calla, se sigue como
> antes.
>
> Simulado sobre los payloads guardados antes de aplicarlo: de 56 filas de Airbnb, **22 cambian
> y 34 se quedan igual; ninguna empeora**. De las 22, diecinueve corrigen el país (17 `ES`→`PE`,
> una `ES`→`CO`, una `ES`→`MX`) y tres pasan de «`PE` porque es el defecto» a «`PE` porque lo
> dice el +51». Las 23 sin teléfono deducible no se tocan.
>
> ⚠️ **Esto arregla lo que entre a partir de ahora.** Para las reservas ya guardadas está
> `app:pms:corregir-pais-ota` (idempotente, con `--dry-run`), que aplica el mismo criterio
> sobre lo que hay en base. Ver `docs/Pendientes.md`.

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
    "country": "es",
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
  └─ Construye A MANO la query de GET /bookings (payload vacío):
       ├─ arrivalFrom, arrivalTo
       ├─ status=confirmed&status=new&status=request&status=cancelled
       │        &status=black&status=inquiry     ← REPETIDO, ver §4.1.b
       ├─ includeInfoItems=true, includeGuests=true   ← ver §4.1.c
       └─ roomId=501&roomId=502  ← solo habitaciones de esta config

ExchangeBatchProcessor → HTTP GET → Beds24 API
  └─ Respuesta: array de booking objects

BookingsPullHandler::handleSuccess(data, item)
  ├─ persister.reset()  ← limpia caché de instancia
  └─ forEach booking in data:
       └─ BookingPullPersister::upsert($config, $dto)
```

### 4.1.b ⚠️ La query se monta a mano, y no es un capricho

**Un array en el `payload` NO llega a Beds24 como parámetro repetido.** El cliente entrega el
payload a Symfony como `'query' => $payload`, y `http_build_query()` serializa un array indexado
así:

```
status[0]=confirmed&status[1]=new&status[2]=cancelled…
```

Beds24 **no** interpreta esa forma. Al no ver ningún `status` válido aplica su filtro por
defecto, **que excluye las canceladas**. La forma que sí entiende es el parámetro repetido, tal
cual lo devuelve su propia consola:

```
?status=confirmed&status=request&status=new&status=cancelled&status=black&status=inquiry
```

**Qué costó:** el `status` del pull incluía `cancelled` desde el principio y era correcto leyéndolo
—el fallo estaba en la serialización, no en el código—, así que durante todo ese tiempo el cron
**no recuperaba ninguna cancelación**. Si el webhook de una cancelación se perdía, la reserva se
quedaba viva en el PMS para siempre: el pull volvía a pasar por esas fechas y Beds24 no la
mencionaba, porque para él era una cancelada y nadie se las había pedido.

Por eso `BookingsPullMappingStrategy::map()` construye la URL a mano y devuelve `payload: []`.
Encaja con el bucle de paginación de `Beds24ExchangeClient`, que al saltar de página sustituye la
URL por `nextPageLink` —que ya trae los parámetros— y vacía el payload.

El mismo problema estaba ya documentado y resuelto en
`Beds24InvoiceReceiveMappingStrategy::map()` para `bookingId`. **Es una trampa del cliente, no de
una tarea concreta: cualquier GET nuevo a Beds24 con un parámetro multivaluado la tiene delante.**

Fijado por `tests/Pms/Service/Exchange/Tasks/BookingsPull/BookingsPullMappingStrategyTest.php`,
que comprueba la URL final —no el payload—, porque es justo donde el fallo era invisible.

### 4.1.c Qué se pide y qué NO: aquí se traen reservas, no cargos

| Parámetro | Estado | Por qué |
|---|---|---|
| `includeInfoItems=true` | se pide | El DTO aún no los mapea; se piden para poder ver su forma real |
| `includeGuests=true` | se pide | Ídem: se captura ahora, se implementa cuando toque |
| `includeInvoiceItems` | **NO se pide** | Los cargos llegan por el Camino D (§11), no por aquí |

**Los cargos tienen su propia vía y no se solapan.** `GET /bookings/invoices?bookingId=…`
(`Beds24InvoiceReceiveMappingStrategy`) es quien trae los `invoiceItems`, con su cola y su
handler. Pedirlos también en el pull de reservas engordaría cada respuesta con datos que
`BookingsPullHandler` no mira, y encima duplicaría la fuente de una cifra — que es justo lo que
§12 existe para evitar.

> ⚠️ Durante mucho tiempo este pull mandó `includeInvoice`, que **no es un parámetro de Beds24**
> —el suyo se llama `includeInvoiceItems`—, así que no tuvo efecto nunca. Si buscas por qué el
> pull «traía cargos» y no los encuentras, es por esto: no los traía.

**`infoItems` y `guests` llegan pero no se guardan todavía.** `Beds24BookingDto` no declara esos
campos y el denormalizador los descarta en silencio (`ALLOW_EXTRA_ATTRIBUTES` por defecto, así
que no rompe). **No se pierden**: la respuesta completa queda en `lastResponseRaw` de la fila de
`pms_bookings_pull_queue`, y ahí es donde hay que mirar para conocer su forma real antes de
mapearlos. Se piden desde ya precisamente para que, el día que se implementen, haya datos
reales que leer en lugar de adivinar el contrato.

#### Lo que esto NO arregla: la ventana es de llegada, no de modificación

El cron barre por **fecha de llegada** con un cursor que empieza en «ayer» y avanza a saltos de 14
días (doblando con la lejanía, techo ×8) hasta el horizonte, y al terminar se reinicia
(`TimelineEnqueuerService`). Consecuencia: una cancelación sólo se ve **cuando el cursor pasa por
encima de la fecha de llegada de esa reserva**, no cuando ocurre.

Con el arreglo de arriba, una cancelación perdida se recupera —pero puede tardar lo que tarde el
ciclo en dar la vuelta, y si la llegada ya quedó por detrás del cursor, hasta la siguiente vuelta
entera. Para que el cron sea de verdad la red de los webhooks perdidos hace falta pedir por
`modifiedFrom`, que devuelve lo tocado desde una marca de tiempo sin depender de la llegada.
**Pendiente**: no está implementado.

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

### 5.4 ⚠️ Una reserva que llega como `new` se QUEDA en `new` — es intencional

**Regla de negocio: el canal no confirma solo. Lo que llega como `new` o `request` se guarda tal
cual, y es el equipo local quien verifica la reserva antes de darla por buena.**

Ese estado intermedio **es el aviso**: mientras la estancia no esté confirmada, alguien tiene que
mirarla. Si se auto-confirmara al entrar, la verificación dejaría de ocurrir — no porque se
decidiera quitarla, sino porque nadie vería la diferencia entre una reserva revisada y una que
acaba de aterrizar.

Aplica también a los canales de pago total (Airbnb, VRBO): que el dinero esté cobrado en el canal
no equivale a que la estancia esté verificada de nuestro lado.

> No confundir con **§9.5**, que es otra regla y sí confirma sola: allí el disparador es un
> **pago registrado en el PMS**, y además está explícitamente desactivada durante el Pull
> (`SyncContext::MODE_PULL`). Son dos caminos distintos hacia `confirmada`; el del canal no
> existe.

#### La trampa: el código dice lo contrario, y no hay que «arreglarlo»

`BookingPullPersister::resolveEstado()` abre así:

```php
if ((int)$statusApi === 0 || $estadoBase->getId() === CODIGO_CANCELADA) {
    return $estadoBase;                 // ← devuelve el estado del canal, tal cual
}
```

Ese `(int)$statusApi === 0` viene de la **API v1**, donde el estado era numérico y `0` significaba
cancelada. En la **v2 los estados son texto** (`cancelled`, `confirmed`, `new`, `request`,
`black`, `inquiry`) y `(int)"new"` es `0`, así que **la condición se cumple siempre**:

```
(int)"cancelled" = 0     (int)"confirmed" = 0     (int)"new"     = 0
(int)"request"   = 0     (int)"black"     = 0     (int)"inquiry" = 0
```

Consecuencia: la función **siempre sale por esa primera rama**, y las dos siguientes —la
protección del *inquiry* y la que forzaría `Confirmada` en canales de pago total— **no se ejecutan
nunca**.

**Eso es exactamente el comportamiento que se quiere**, aunque se consiga por un camino que parece
un descuido. Quien lea la rama 3 verá escrito «si es Airbnb, forzamos Confirmada» y pensará que
hay un bug en el cast.

> 🚨 **Quitar ese cast reactiva la rama 3 y pone a confirmarse solas las reservas de Airbnb y
> VRBO, saltándose la verificación del equipo local.** El fallo no daría ningún error: las
> reservas simplemente empezarían a entrar ya confirmadas y la revisión se volvería invisible.

Las ramas 2 y 3 se conservan en vez de borrarse porque documentan la intención original, y porque
el día que Beds24 cambie el contrato del campo hay que decidir a conciencia qué se quiere — no
descubrirlo porque de pronto empiecen a ejecutarse.

Este comportamiento **no está cubierto por tests**: `resolveEstado()` es privado y consulta el
maestro `PmsEventoEstado` en base de datos, así que hoy no hay forma de fijarlo con un test
unitario puro. Se verifica mirando datos reales.

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

**Ojo: «nace» es literal.** Vale para un link nuevo, no para uno que se recicla. Ver §6.3.b.

### 6.3.b Cambiar de casita: qué link se mueve y cuál se recrea

Mover una estancia de unidad (el *Smart Move*) es una reasignación de mapas, no un borrado.
`PmsEventoCalendarioFactory::internalHydrate()` recorre los mapas activos de la unidad **nueva**
y decide, para cada uno, qué link reutilizar.

**Quién puede moverla depende de por dónde entre el cambio**, y es fácil leerlo al revés:

| Origen | ¿Estancia directa? | ¿Estancia OTA? |
|---|---|---|
| Manual (panel / API) | ✅ permitido | ❌ `preUpdate()` regla 3b: *"No puedes reasignar la unidad física de una reserva externa"* |
| Pull o webhook de Beds24 | ✅ | ✅ **también permitido** |

El veto de OTA es **sólo contra el cambio manual**: la habitación la manda el canal, no nosotros.
Cuando el movimiento lo ordena Beds24, `PmsEventoCalendarioSecurityListener::preUpdate()` sale
por la puerta de `if ($this->syncContext->isPull()) return;` —que está *antes* de la regla 3b— y
`BookingPullPersister::upsertEvento()` aplica `setPmsUnidad()` + `rebuildLinks($bookId, $roomId)`.
El modo lo abre `ExchangeOrchestrator` desde `BookingsPullTask::getSyncMode()` (cron) o
`Beds24WebhookBookingFastTrackService` (tiempo real).

La regla es **conservar el `beds24BookId` siempre que la reserva siga siendo la misma en
Beds24**, y eso ocurre cuando sólo cambia el `roomId` dentro del mismo establecimiento virtual:

| Caso | Link | `beds24BookId` | Qué ve Beds24 |
|---|---|---|---|
| Principal (rescate) | se reutiliza | **se conserva** | UPDATE: la reserva cambia de habitación |
| Espejo, mismo virtual (`INTI`→`INTI`) | se reutiliza | **se conserva** | UPDATE: la reserva espejo cambia de habitación |
| Espejo, sin pareja en la unidad nueva | va a sobrantes → se borra | — | DELETE (§12.12) y un POST nuevo para el mapa entrante |
| Mapa nuevo sin superviviente | se crea | `null` | POST: reserva nueva, el ID lo sella el Push (§6.3) |

**El bug que esto arregla.** La rama de espejos hacía `setBeds24BookId(null)` *siempre*, también
al reciclar un link que ya tenía ID. Resultado: mover una casita dejaba la reserva espejo viva
en Beds24, en el room viejo, bloqueando esas noches, **sin ningún link que la reclamara y sin
DELETE encolado**; y encima el espejo reciclado creaba una reserva nueva en el room nuevo. Dos
fugas por cada cambio de casita. Verificado sobre datos reales: `book=83120655` desaparecía de
todos los links sin dejar rastro.

Por el mismo motivo el **fallback FIFO** sólo recicla links *sin* ID: emparejar por orden un
link que ya tiene reserva con un mapa de otro virtual perdería esa reserva igual. Los que traen
ID se dejan en los sobrantes, donde el borrado sí dispara su DELETE.

> **Sobre el supuesto de que Beds24 acepta el cambio de room conservando el `id`.** El diseño
> lo da por hecho: `BookingsPushMappingStrategy::buildUpsertPayload()` manda siempre `roomId`
> («ancla de ubicación») y añade `id` cuando existe («ancla de identidad, evita duplicados»), y
> el Pull reacciona a que Beds24 mueva una reserva pasándole a `rebuildLinks()` el mismo
> `bookId` con otro `roomId`. Pero **no hay ni un caso registrado**: de 730 colas en producción,
> las respuestas con `modified` sólo traen `status` y `departure`, **ninguna `roomId`**. Nunca
> se ha movido de casita una reserva ya sincronizada, y por eso la fuga descrita arriba jamás
> llegó a verse. Si Beds24 rechazara el movimiento, la cola quedaría en `failed` con su razón —
> visible, a diferencia de la reserva huérfana que dejaba el comportamiento anterior.

> ⚠️ **Una estancia recién creada no tiene ningún `beds24BookId` todavía.** El principal se
> identifica entonces por el flag `esPrincipal`, no por el ID. Sin ese segundo criterio ningún
> link se reconocía como superviviente, el principal se borraba y se insertaba otro en el mismo
> flush, y Doctrine abortaba con **«A managed+dirty entity Link … can not be scheduled for
> insertion»** — el error que `PmsExtensionEstanciaService` ya había encontrado por su cuenta.
> Efecto práctico: no se podía cambiar de casita ninguna reserva aún sin sincronizar.

### 6.3.c Los estados del link

`PmsEventoBeds24Link::$status` tiene **tres** valores, y cada uno tiene quien lo escriba y quien
lo lea:

| Estado | Quién lo pone | Quién lo lee |
|---|---|---|
| `active` | `markActive()`, en cada hidratación. En la práctica, todos. | — (es el caso normal) |
| `pending_delete` | **Sólo a mano, desde el panel.** Es la vía de borrado remoto que no borra la fila. | `Beds24BookingsPushQueueListener::isLinkBeingDeleted()` |
| `synced_deleted` | `BookingsPushHandler::handleSuccess()` al completar el DELETE (§12.12.5). Terminal. | `PmsBeds24MessageTargetFinder`, `PmsBeds24InvoiceTargetFinder`, `Beds24BookingsPushJob` |

**Antes había cinco.** `detached` y `pending_move` —con sus `markDetached()` y
`markPendingMove()`— se eliminaron: en todo el código no había un solo sitio que los escribiera
ni que los leyera, y en base de datos no existía una sola fila con esos valores (678 links en
local, 740 en producción, **todos `active`**). Lo único que hacían era aparentar un flujo de
desvinculación y otro de movimiento en dos fases que nunca se cablearon; el movimiento real se
resuelve reutilizando el link y cambiándole el mapa (§6.3.b), y los links que sobran se borran
en lugar de desvincularse. El desplegable del CRUD también los ofrecía, así que elegirlos no
hacía nada salvo desinformar a quien mirara la ficha después.

> **Regla al añadir un estado nuevo:** que tenga las dos mitades, quien lo produzca y quien lo
> consuma. Un estado a medio cablear es peor que no tenerlo, porque se lee como una promesa.

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

### 7.1.b Horario extra — la noche invisible que bloquea la unidad

Un *late check-out* (se va a las 17:00 en vez de a las 10:00) o un *early check-in* (llega a las
09:00 en vez de a las 14:00) **no son noches más**, pero dejan la casita invendible una noche: no
da tiempo a limpiar y entregar. Antes se resolvía creando una SEGUNDA estancia de una noche, que
inflaba noches y ADR, partía la reserva en dos y obligaba a inventar un check-in que nunca ocurría.

Ahora hay dos casillas en la estancia —**`$entradaTemprana`** y **`$salidaTardia`**—, y cada una
levanta un **evento hermano en estado `extension`** que cubre esa noche:

```
Estancia    31/07 14:00 ──────────────────────► 05/08 10:00     (5 noches, lo que se factura)
                     │                                   │
  extension  30/07 ──┘ (víspera)          (día salida) └── 06/08
             └─ evento real: ocupa la unidad en el PMS y va a Beds24 como `black`
```

| Efecto | Dónde |
|---|---|
| Nace / se recoloca / se borra la extensión | `PmsExtensionEstanciaService::sincronizar()` |
| Se abre su cargo, en **0.00** | `PmsCargosAutomaticosService::sincronizarExtras()` |
| Se encola el push de la extensión | `Beds24BookingsPushQueueListener`, sobre los links que le hidrata `PmsEventoCalendarioFactory` |

#### Por qué un evento y no una fecha desplazada

El primer intento fue estirar `arrival`/`departure` solo en el payload del push. Tenía **dos
fallos que costaron una vuelta entera**:

1. **No servía para las OTA** — y son las que más piden estos servicios. Las fechas de una
   reserva OTA no se envían nunca (guard `!$isOta || $isMirror`, §9.4): esa protección impide
   que el PMS pise lo que dice el canal, y levantarla habría abierto un agujero peor.
2. **No protegía del propio PMS.** Beds24 no vendía la noche, pero el operador sí podía crear
   una reserva encima desde el calendario, porque internamente la estancia acababa a su hora real.

Un evento real resuelve las dos cosas: ocupa la unidad **dentro** del PMS y viaja al canal como
`black`, sea la estancia directa o de una OTA. La extensión se crea **siempre con canal directo e
`isOta = false`**, aunque su estancia venga de Airbnb: si heredara el canal, el push se negaría a
mandar sus fechas y no bloquearía nada.

#### El reto: que sea invisible

La extensión cuelga de la misma reserva (para que el borrado en cascada y las finanzas la sigan),
pero **no es una estancia**. Se filtra en todos estos sitios, y esta lista es la que hay que
repasar al añadir cualquier vista nueva de eventos:

| Dónde | Cómo |
|---|---|
| Los 4 calendarios (2 legacy + 2 SPA) | `filters.estado.not_in: [… extension]` en `services_parameters_pms.yaml` |
| Fondo de ocupación de Tarifas | ya excluida: usa la lista blanca `PmsEventoEstado::OCUPAN_UNIDAD` |
| Listado de eventos (EasyAdmin) | `PmsEventoCalendarioCrudController::createIndexQueryBuilder()` |
| Detalle de estancias de la reserva | `detail_eventos.html.twig`, filtro `estancias` |
| Estancias del drawer (SPA) | `ReservaEditDrawer`, filtro al mapear `detalles` |
| Buscador de reservas | `PmsReservaBuscarController` |
| **Rollup de la reserva** | `PmsReservaRecalculoService`: `AND e.estado_id != 'extension'` en el `WHERE` |
| Coste teórico del panel | `PmsCargosAutomaticosService::aplica()` la descarta, como a los bloqueos |

> ⚠️ **El rollup es el que más duele si se olvida.** Sin ese filtro, `fecha_salida` de la reserva
> se va al día siguiente y la cabecera dice que el huésped se marcha el 06 cuando se va el 05.

#### El cargo no existe hasta que se guarda

Marcar la casilla no crea nada por sí solo: la extensión y su cargo los produce el backend al
**guardar** (`postFlush`), así que en el drawer no hay cargo que valorar hasta después del
guardado. Y como guardar cerraba el drawer, había que buscar la reserva otra vez solo para
escribir el importe.

Por eso `ReservaEditDrawer::guardar()` **no cierra el drawer** cuando en ese guardado se tocó una
casilla de horario extra: recarga los datos y muestra un aviso apuntando al panel de Finanzas,
donde ya está el cargo en 0.00. El resto de guardados siguen cerrando como siempre.

Se detecta comparando la casilla con `entry.horarioExtraGuardado` (el valor con el que la
estancia llegó del servidor), que es el mismo campo que congela el día — no hace falta más
estado.

#### Reservas de varias casitas

Todo va **por estancia, no por reserva**, y eso importa porque una reserva puede tener cuatro
casitas y el horario extra pactarse solo en una:

- La extensión hereda la **unidad de su estancia** y guarda `eventoOrigen` apuntando a ella;
  `buscar()` filtra por ese campo, así que marcar la Casita 7 no toca la Casita 6.
- El cargo apunta a su `evento`, y su descripción lleva la casita detrás
  («Salida tardía (noche bloqueada) · Casita 7»): sin eso, dos estancias con salida tardía dan
  dos líneas idénticas en el panel financiero y no hay forma de saber cuál se está valorando.
  La búsqueda del cargo compara por **prefijo**, de modo que los cargos anteriores a este añadido
  se siguen reconociendo.

#### Lo que se congela es el DÍA, nunca la hora

**La hora de check-in/check-out siempre se puede tocar. El día, según el caso.** La distinción es
la clave de todo este apartado y vale para los dos guardianes de fechas:

| Situación | Día | Hora |
|---|---|---|
| Estancia OTA | ❌ lo manda el canal (`PmsEventoCalendarioSecurityListener`) | ✔ |
| Estancia con horario extra marcado | ❌ (`PmsEventoCalendarioIntegrityListener::assertFechasMoviblesConHorarioExtra()`) | ✔ |
| Estancia directa sin horario extra | ✔ | ✔ |

**Por qué la hora es libre incluso en una OTA**: lo que vende el canal son NOCHES, y a Beds24
sólo le viajan `arrival`/`departure` en formato `Y-m-d` (§7.2). Ajustar el check-out a las 17:00
—un late check-out pactado por WhatsApp— no contradice nada de lo que dice Booking. Y sobre todo:
**pactar un horario extra ES cambiar esa hora**, así que bloquear el campo entero impedía justo
lo que la casilla acompaña. Ambos listeners comparan con `format('Y-m-d')` mediante su
`cambiaDeDia()`.

Para mover el DÍA de una estancia con horario extra hay que quitar la casilla, **guardar**, y
entonces moverlo. Desmarcar y mover en el mismo guardado también se rechaza — no es purismo: la
extensión se retira y se reconstruye dentro del mismo flush y Doctrine revienta. Por eso se mira
el valor **anterior** de las casillas, no el final.

En el front, `ReservaEditDrawer::diaBloqueadoPara()` pasa `dia-bloqueado` al `FechaHoraPicker`
—y devuelve `true` para **toda** estancia OTA, tenga o no horario extra—. El picker lo resuelve
en tres sitios, y hacen falta los tres:

1. `min-date` = `max-date` = ese día, en lugar de `disabled`: el calendario no deja elegir otro
   día, el reloj sigue vivo y el control no se ve apagado.
2. **La máscara del input deja la FECHA como texto literal**, así que el día no se puede ni
   teclear: sólo quedan editables las posiciones de la hora. Cada carácter del día va escapado
   con `\\` porque en IMask el `0` es un placeholder de «dígito requerido» y un día como
   `10/07/2026` se leería como huecos que rellenar. El patrón es reactivo: al soltar el candado
   o moverse la estancia hay que rehacer la máscara (`updateOptions`) o el input se queda con el
   día anterior clavado.
3. **`conDiaCongelado()` al emitir**, como red por si el valor llega por otra vía (el picker, un
   `paste`): restaura el día original y respeta la hora nueva.
4. **Repintado en `blur`.** Cuando se teclea otro día, `conDiaCongelado()` devuelve el MISMO
   `modelValue` que ya había, así que el prop no cambia, el `watch` no salta y el input se queda
   mostrando lo tecleado, que es mentira. El `blur` lo devuelve a la verdad. Compara con el valor
**guardado** (`entry.horarioExtraGuardado`), no con la casilla: si mirase la casilla, desmarcarla
desbloquearía el día en el acto y el backend rechazaría el guardado. Y el PATCH manda `inicio`/
`fin` **siempre**, también en OTA — antes se omitían y por eso la hora no se podía guardar.
**Es un espejo del listener: si cambia el criterio, se tocan los dos lados.**

#### La HORA acordada: registrarla no es lo mismo que bloquear

`aplicar_cambio_horario` acepta `hora` (`HH:MM`), y ahí está la distinción que faltaba: **no todo
cambio de hora es un horario extra**.

```
Salida a las 09:30  (check-out 10:00) → sólo se REGISTRA la hora. Sin marca, sin bloqueo, sin cargo.
Salida a las 14:00  (check-out 10:00) → excede: se registra Y se marca Y se bloquea la noche.
Sin hora                              → comportamiento de siempre: marca y bloquea.
```

El hito sale del **establecimiento** (`getHoraCheckIn()` / `getHoraCheckOut()`, hoy 14:00 y 10:00),
no de una constante: es una decisión de negocio que puede cambiar sin tocar código. La comparación
es asimétrica a propósito —salir DESPUÉS del check-out ocupa la casita más tiempo; entrar ANTES
del check-in la ocupa antes—, así que salir a las 09:00 o entrar a las 18:00 no bloquean nada. Son
datos útiles para limpieza, no horarios extra.

⚠️ **Se cambia la hora, NUNCA el día.** `registrarHora()` conserva la fecha y sólo mueve la hora de
pared (§12.5.5). Es seguro incluso en OTA porque el push manda `Y-m-d`
(`BookingsPushMappingStrategy`): el portal no ve las horas. Mover el DÍA sigue siendo otra
operación, prohibida en OTA y reservada al calendario del panel.

⚠️ El mensaje final refleja **lo que pasó**, no lo que suele pasar. Con una hora dentro del
horario decía «Marcada la salida tardía. Se ha creado la extensión que bloquea esa noche» sin
haber hecho ninguna de las dos: el operador se quedaba creyendo que había bloqueado una noche que
seguía vendible.

#### ⛔ Nadie miraba si esa noche estaba vendida

`evaluar_cambio_horario` devolvía `permitido: true` y `aplicar_cambio_horario` aplicaba, **sin
mirar la ocupación**. Con un caso real: la noche de salida de una estancia ya estaba vendida a otra
reserva, y aun así las dos skills daban vía libre — se habría creado una extensión solapada con
otra reserva y un bloqueo saliendo al canal sobre algo ya vendido.

Las **dos** skills consultan ahora `PmsDisponibilidadService::margenesDe()` (§8.b de
`PmsDisponibilidad.md`) y devuelven `noche_adyacente`. Que las dos digan lo mismo no es
redundancia: quien evalúa y quien aplica tienen que leer la misma advertencia, o la evaluación
deja de servir para decidir.

**⚠️ Es una ALERTA, no un veto.** `permitido` no cambia. Puede haber motivo para hacerlo igual
—la otra reserva entra por la tarde, se habló con los dos huéspedes— y esa decisión es del
operador. Lo que no puede es tomarla sin saberlo.

Se devuelve **siempre**, y el tono cambia según lo que vaya a pasar:

| Noche adyacente | Va a bloquear | Aviso |
|---|---|---|
| Ocupada | Sí | `⛔ CONFLICTO: … el bloqueo se solapará con esa reserva` |
| Ocupada | No | `⚠️ … no hay conflicto, pero el margen de limpieza es más corto` |
| Libre | — | `✅ … está LIBRE` |

El tercer caso —ocupada **sin** bloqueo— es el que se habría perdido calculándolo sólo cuando hay
extensión: no hay solape posible, pero que esa noche entre otra reserva sigue cambiando la
decisión sobre el horario acordado.

Y mira la noche que toca en cada caso: la **del día de salida** para una salida tardía, la
**anterior a la entrada** para una entrada temprana.

#### 🕒 Registrar la hora: no todo horario acordado es un horario extra

Ninguna skill registraba la hora —sólo `crear_reserva` al nacer la estancia—, así que «sale a las
14:00» se marcaba como salida tardía sin guardar el 14:00 en ninguna parte, y «sale a las 9:30» se
marcaba igual, bloqueando una noche vendible por nada.

`aplicar_cambio_horario` acepta ahora `hora` (`HH:MM`) y la compara con el **horario del
establecimiento** (`PmsEstablecimiento::getHoraCheckIn()` / `getHoraCheckOut()`, hoy 14:00 y
10:00), que es el hito contra el que se decide:

```
salida 09:30  ≤ 10:00  →  sólo se registra la hora. Ni extensión, ni bloqueo, ni cargo.
salida 14:00  > 10:00  →  se registra Y se marca la salida tardía (las 4 consecuencias)
sin hora               →  comportamiento de siempre: se marca y se bloquea
```

Asimétrico a propósito: salir **después** del check-out ocupa la casita más tiempo y entrar
**antes** del check-in la ocupa antes; salir a las 09:00 o entrar a las 18:00 no molestan a nadie
—son datos útiles para limpieza, no horarios extra—.

⚠️ **La hora se escribe conservando el DÍA** (`registrarHora()`). Mover el día es otra operación,
prohibida en OTA, y se hace en el calendario. Cambiar sólo la hora es seguro incluso en OTA porque
`BookingsPushMappingStrategy` manda `arrival`/`departure` como `Y-m-d`: **el portal no ve las
horas**. Sí dispara un push redundante con las mismas fechas, que es inocuo.

#### Una estancia cancelada no tiene extensión

Cancelar una estancia **retira su extensión** (y reactivarla la devuelve, si la casilla sigue
marcada). Una estancia cancelada no ocupa nada: bloquear una noche por ella en Beds24 es una
noche que se deja de vender por nada.

El disparo va en `PmsInformacionFinancieraCoherenciaListener`, que además de las casillas vigila
el cambio de **`estado`** en el `changeSet`. Mirando sólo las casillas, la noche bloqueada
sobrevivía a la cancelación.

> El caso que lo destapó: una reserva con la estancia **duplicada** —una cancelada y otra viva,
> misma casita y mismas fechas— generaba desde la cancelada una extensión IDÉNTICA a la de la
> viva. En el calendario y en el cargo son indistinguibles, así que parecía que el late check-out
> «se había ido a la estancia activa».

#### Auditar una reserva

```bash
php bin/console pms:reserva:auditar XTHRMQ
```

Solo lectura. Saca estancias con sus extensiones anidadas, cargos, pagos y saldo, y **señala los
descuadres** que ya nos han mordido: estancias duplicadas con la misma casita y fechas, cargos
colgando de una estancia cancelada, extensiones activas cuya estancia está cancelada, extensiones
con estado de pago distinto de «no-pagado», registros sin tipo de cambio que aportan 0, y saldo
negativo. Es lo que había que reconstruir a mano con scripts cada vez que algo no cuadraba.

#### Gotchas

- **Al desmarcar sólo se retira el cargo si sigue en CERO.** Un cargo con importe es dinero
  facturado —y probablemente cobrado—: borrarlo dejaba el pago huérfano y la reserva con saldo
  negativo sin rastro de por qué (pasó en producción con XTHRMQ: saldo −50.00). Si el horario
  extra se anula de verdad, el cargo lo borra el operador: es una decisión sobre dinero.
- **`findBy()` y no DQL para localizar la extensión.** `PmsExtensionEstanciaService::buscar()`
  usa `findBy(['eventoOrigen' => $estancia])`: con `createQueryBuilder()->setParameter('origen',
  $estancia)` el UUID `BINARY(16)` se serializa mal, la consulta **no falla** y devuelve cero
  filas. El síntoma fue que al desmarcar una casilla no encontraba la extensión para borrarla y
  encima duplicaba la otra. Misma trampa que §12.6 y que `fetchFinanzas()`.
- **Sin link no hay push.** La cola se arma sobre `PmsEventoBeds24Link`, así que la extensión
  hay que hidratarla (`PmsEventoCalendarioFactory::hydrateLinksForUi()`) o se queda en el PMS
  sin bloquear nada en el canal — justo lo contrario de para lo que existe. Si cambia de casita,
  `rebuildLinks()`.
- **`estado_pago_id` es NOT NULL**: la extensión va con «no pagado» y ahí se queda. No cobra nada
  por sí misma; lo que se cobre vive en el cargo de la estancia.
- **Retirar una extensión es CANCELARLA, nunca borrarla.** Lo pide el dominio
  (`getMotivoNoBorrable()`: lo que ya existe en Beds24 se cancela y se espera la sincronización)
  y lo impone Doctrine: al borrar el evento se van en cascada sus links, el listener de push los
  recoge para encolar el DELETE y al armar la cola con un link ya eliminado intenta revivirlo —
  «A new entity was found through the relationship `PmsEventoBeds24Link#evento`». Cancelar es un
  UPDATE simple que el push traduce a `cancelled`. Consecuencia: la extensión retirada queda como
  evento cancelado de una noche, y al volver a marcar la casilla esa misma **se revive** — nunca
  hay más de una por tipo y estancia.
- ⚠️ **Se filtran por `eventoOrigen`, NUNCA por el estado.** Es el error que se cometió al
  empezar y no se ve venir: el estado `extension` sólo lo tienen mientras están ACTIVAS, y al
  retirarlas pasan a `cancelada` sin dejar de ser extensiones. Con el filtro por estado
  reaparecían como estancias fantasma en el drawer, en el detalle de EasyAdmin y en el calendario
  «Todas» — **una por cada vez que se marcó y se desmarcó**. `eventoOrigen` no cambia nunca. Los
  sitios que filtran: los dos providers de calendario, el índice del CRUD, el rollup de la
  reserva, el buscador de reservas, el drawer y `detail_eventos.html.twig`.
- **Los links de la extensión sólo se rehacen si cambió la casita.** Hacerlo en cada revivida
  revienta con «A managed+dirty entity Link … can not be scheduled for insertion»: la fábrica
  intenta insertar links que el UnitOfWork ya está gestionando.
- **Se busca por `eventoOrigen` + descripción, nunca por fechas**: si la estancia se mueve, las
  fechas de la extensión dejan de cuadrar, y ese es justo el caso en que hay que **recolocarla**,
  no crear otra.
- **Las casillas están en los dos frontales**: `ReservaEditDrawer` (SPA) y
  `PmsEventoCalendarioCrudController` (EasyAdmin, que `PmsReservaCrudController` reutiliza vía
  `useEntryCrudForm`). Guardar desde el panel legacy sin ellas perdería el bloqueo.
- **Cómo se comprueba que el push salió**: la cola **no crea filas nuevas** para la estancia,
  reutiliza la del link por `dedupe_key` y la devuelve a `pending`. Contar filas despista; hay
  que mirar el `status`. Las extensiones, al ser eventos nuevos, sí estrenan sus propias filas.

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
  │  + firstName, lastName, notes, comments, lang                     │
  │  - arrival, departure, status, email, phone, price, masterId      │
  │  - country2  ← desde el 19/08/2026, ver abajo                     │
  └───────────────────────────────────────────────────────────────────┘

  ┌─ DIRECTA PROPIA (!isOta, !isMirror) ─────────────────────────────┐
  │  Objetivo: sincronización completa, somos dueños absolutos        │
  │  + Todo: fechas, estado, huésped, email, phone, price, masterId   │
  └───────────────────────────────────────────────────────────────────┘
```

#### 🚫 `country2` ya no se empuja a las OTA (19/08/2026)

Estaba **fuera** de la guarda `if (!$isOta)` que ya protegía email y teléfonos, así que viajaba
en todos los push, OTA incluida. Ahora va dentro, con ellos.

Lo que se enviaba era el **código ISO2**, no el nombre: comprobado en `last_request_raw` de
`pms_bookings_push_queue` — 787 de 823 push lo llevaban, siempre como `"country2":"PE"`. Si en
la ficha de Beds24 se ve el país escrito entero, lo pinta **su** resolver a partir del código;
de este lado nunca sale una cadena larga.

El motivo del cambio no es el formato, es de quién es el dato:

- En una reserva de OTA el país **lo pone el canal**. Devolvérselo es escribirle encima de su
  propio registro, igual que ya se evitaba con el correo proxy y el teléfono.
- En Airbnb, además, el nuestro **es una deducción**: su `country2` trae el idioma y el país lo
  sacamos del prefijo del teléfono (§3.3). Empujarlo convertiría una inferencia nuestra en el
  dato oficial de la reserva, y borraría la evidencia de que no lo sabíamos.

En reservas directas se sigue enviando: ahí el dueño del dato somos nosotros.

⚠️ `lang` **sí se sigue empujando** en OTA. No se ha tocado porque no estaba en la pregunta,
pero merece la misma revisión: en Airbnb es dato del canal exactamente igual que el país.

#### 📣 El nombre se normaliza al entrar, no después (19/08/2026)

`BookingPullPersister::upsert()` pasa `firstName` y `lastName` por
{@see \App\Service\Nombre\NombreSanitizer} antes de guardarlos, para que la bienvenida no salga
como «Hola QUISPE CONTRERAS, bienvenido a Centro Cusco Inti».

**Va aquí y no en un trabajo asíncrono posterior por dos motivos, y los dos son de carrera:**

1. **La bienvenida no espera.** Las reglas «Bienvenida a Booking» y «Bienvenida a Airbnb» son
   `milestone = created_at` con `offset_minutes = 0`: `MessageRuleEngine` las programa con
   `runAt = now` en el **`postFlush` de este mismo guardado**. Un trabajo asíncrono lanzado a la
   vez compite con el worker de envío, y a veces pierde.
2. **El pull reescribe el nombre.** Mientras `datosLocked` siga abierto, *cada* pasada vuelve a
   escribir los dos campos desde el payload. Un nombre corregido por fuera se desharía solo en
   la siguiente, sin que nadie lo notara.

Normalizar en la fuente hace las dos preguntas irrelevantes, y de paso el nombre bueno lo ven
todos los consumidores —guía, panel, agente—, no sólo la bienvenida.

**El sanitizador sólo toca lo que está CLARAMENTE gritado**: si el texto tiene una sola
minúscula, se devuelve intacto. `Viana Da Silva`, `McDonald` y `O'Brien` los escribió alguien así
a propósito, y «corregirlos» los rompe; `H` —apellido de una letra, como lo trunca Airbnb— no
tiene nada que bajar. Es idempotente, que importa porque cada pull vuelve a pasar por aquí.

⚠️ **No restituye tildes.** `JOSÉ` sale `José`, pero `JOSE` sale `Jose`: la tilde no está en el
dato. Eso sí sabría hacerlo un modelo, pero es un refinamiento — no la diferencia entre un
mensaje presentable y uno que no lo es.

#### Cuánto pasa esto, medido

De 123 reservas en la auditoría de webhooks, **4 traían el apellido en mayúsculas**, y al mirarlas
una por una sólo 2 eran realmente eso:

| Reserva | Qué llegó | Qué es |
|---|---|---|
| `88115197` (Booking) | `ILAY` / `NAHARY` | gritado de verdad |
| `88689680` (Booking) | `PAULO` / `LEANDRO` | gritado de verdad |
| `88298460` (Airbnb) | `César` / `H` | apellido de una letra, **no** está gritado |
| `88233049` (Booking) | `RODRIGUEZ BARRERA` / `ALISSON ANGELICA` | ⚠️ **los campos vienen al revés** |

Ese último no lo arregla la capitalización: el nombre está en `lastName` y los apellidos en
`firstName`, así que la bienvenida saludaba por el apellido. Tiene su propio mecanismo, abajo.

#### 🔀 El orden cruzado: lo decide el modelo, lo aplica el código

No hay regla determinista que lo resuelva —qué token es nombre y cuál apellido depende de la
cultura, y en muchos países el orden invertido es el correcto—, así que aquí sí entra el modelo:

```
 flush con nombreCliente/apellidoCliente tocados
   └─ PmsNombreOrdenListener::onFlush()    recolecta (el UUID v7 ya existe)
        └─ postFlush → bus->dispatch(RevisarOrdenDelNombreDispatch)   → transporte `async`
             └─ RevisarOrdenDelNombreDispatchHandler
                  ├─ turnoDirecto() tramo BAJO, con esquema
                  │     → {invertido: bool, confianza: alta|media|baja, motivo: string}
                  └─ OrdenDelNombre::resultado()  ← intercambia las cadenas YA guardadas
```

⚠️ **El modelo nunca devuelve el nombre, sólo un booleano.** Si devolviera texto podría escribir
uno que nadie tecleó —cambiar una letra, «arreglar» un apellido raro, traducirlo— y eso acabaría
en el saludo de un huésped sin que nadie lo revisara. Con un booleano, el peor fallo posible es
un intercambio equivocado de dos cadenas que ya existían. Se aplica sólo con `confianza: alta`,
y sólo si al volver la respuesta los dos campos siguen siendo los que se juzgaron.

**Tres guardas que no son opcionales:**

| Guarda | Qué evita |
|---|---|
| `OrdenDelNombre::mereceRevision()` | gastar una llamada en `Pendiente Sync (Grupo)`, en un apellido de una letra o sin apellido |
| `OrdenDelNombre::esNuestroIntercambio()` | el **bucle**: nuestro guardado despierta al listener y volvería a encolar. Compara los dos pares como conjunto |
| la comparación con el valor actual en `resultado()` | aplicar el veredicto sobre un dato que cambió entre la pregunta y la respuesta |

El corta-bucles mira que sean *las mismas dos cadenas cambiadas de sitio*, no que «el nombre
cambió»: un operador corrigiendo una tilde también lo cambia, y ése sí hay que revisarlo.

#### ⏱️ Por qué asíncrono llega a tiempo (y hasta cuándo)

La bienvenida es una regla `created_at` con `offset_minutes = 0`, y `MessageRuleEngine` la crea
en el `postFlush` de este mismo guardado. Aun así hay margen, y el motivo es concreto: **la fila
de `msg_beds24_send_queue` guarda `message_id`, no el cuerpo**. El texto lo compone
`exchange:run beds24_message_send` —un comando de **cron**— al enviar. Hasta que ese cron pase,
corregir el nombre todavía cambia lo que va a leer el huésped.

⚠️ **Ese margen no lo garantiza nada de este repositorio**: es la cadencia del crontab del
servidor (~3 min según §7 de `docs/Mensajeria.md`). Si algún día se aprieta, la bienvenida puede
adelantarse a la corrección. La forma de volverlo determinista **sin tocar código** es dar a las
reglas «Bienvenida a Booking» y «Bienvenida a Airbnb» un `offset_minutes` de unos pocos minutos,
que hoy es 0.

⚠️ Y no se hace síncrono a propósito: una llamada al modelo dentro del webhook lo alargaría
segundos, y Beds24 y Meta reintentan los webhooks lentos — el mismo motivo por el que el router
del Agent está en `async` (ver `config/packages/messenger.yaml`).

En `pms_reserva` sólo hay 2 nombres gritados sobre 300, y **los dos son de canal `directo`**, es
decir, tecleados a mano en el panel. Esa vía **no** pasa por el sanitizador: entra por
`PmsReservaCrearProcessor`, y quedó fuera de este cambio a propósito.

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

La sección 8 describe el **consumidor** (`exchange:run <tarea>`): saca filas de la cola y las
envía. Pero la cola no se llena sola —alguien encola—, y ahí hay dos patrones que conviene no
confundir.

### 8.1 Quién llena la cola — el listener reactivo vs. el Timeline Enqueuer

Toda cola de Exchange (rates, bookings…) se alimenta por **dos caminos independientes**, y el de
tarifas (`pms_rates_push_queue`) es el ejemplo claro:

```
                      ┌──────────────────────────────────────────────┐
   cambió una tarifa  │  CAMINO REACTIVO                              │
   (PmsTarifaRango)   │  Beds24RatesPushQueueListener (onFlush)       │
        ───────────►  │  encola SOLO lo que cambió                    │
                      └──────────────────────────────────────────────┘
                      ┌──────────────────────────────────────────────┐
   cron del SO        │  CAMINO PERIÓDICO (defensivo)                 │
   min 8 y 38 c/hora  │  exchange:timeline:enqueue beds24_rates_push  │
        ───────────►  │  → TimelineEnqueuerService → Beds24RatesPushJob│
                      │  re-encola el horizonte ENTERO, sin mirar     │
                      │  si cambió algo                               │
                      └──────────────────────────────────────────────┘
                                        │
                                        ▼
                              pms_rates_push_queue
                                        │
                      exchange:run rates_push (cada 15 min) → Beds24
```

**El camino reactivo** (`Beds24RatesPushQueueListener`, `#[AsDoctrineListener(onFlush/postFlush)]`)
solo dispara cuando de verdad se inserta/edita/borra un `PmsTarifaRango`. Encola vía
`Beds24RatesPushQueueCreator::enqueueForInterval()` únicamente el intervalo tocado. Es exacto y
barato; no es el que hace crecer la cola.

**El camino periódico** es el productor programado. El comando `exchange:timeline:enqueue
<tarea>` (`TimelineEnqueuerCommand` → `TimelineEnqueuerService::enqueue()`) avanza un **cursor**
(`ExchangeCronCursor`) por el calendario en pasos fijos —`P2W`, dos semanas, en
`Beds24RatesPushJob::getStepInterval()`— y en cada corrida llama a `enqueueForInterval()` para
**todas las unidades activas de ese tramo, sin comparar contra lo ya enviado**. Al pasar el
`horizonteMáximo` (= `MAX(fechaFin)` de las tarifas activas) el cursor **se reinicia a "ayer"** y
vuelve a barrer desde el principio.

**Por qué una tarifa se re-encola ~10 veces al día — y por qué NO es un bug.** No hay
des-duplicación por valor en ningún punto: `enqueueForInterval()` solo cancela las filas
`PENDING` que solapan («Smart Flattening», dedupe *intra*-pasada), pero **nunca consulta las
`SUCCESS`** ni compara el precio nuevo contra el último empujado. Así que cada barrido completo
del horizonte re-encola cada tarifa una vez; el número de veces/día = cuántos barridos completos
caben en el día (con el horizonte actual, ~10). **Es un refresco defensivo deliberado:** reenvía
las tarifas a Beds24 periódicamente aunque no hayan cambiado, para que un fallo de sync no deje
un precio viejo colgado allá. El costo es cola + llamadas API redundantes; el beneficio es que
Beds24 nunca queda desincronizado. Para el volumen real (7 unidades) el trade-off es sano.

⚠️ **Consecuencia operativa:** ese refresco genera ~1.000 filas `success`/día que **nadie borra**
(ver el aviso en §2). Si algún día ese caudal molesta —más unidades, presión sobre el binlog de
MySQL— la palanca correcta **no** es tocar la cadencia del cron a ciegas, sino **añadir dedupe por
valor** en `Beds24RatesPushQueueCreator`: comparar el precio/minStay nuevo contra el último
`success` de esa unidad+fecha y encolar solo si difiere. Eso mataría el ~88% redundante sin
perder la garantía reactiva. Se deja anotado, no hecho: hoy el costo es asumible.

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
- En **Pull** no se aplica: ahí manda el canal y `BookingPullPersister::resolveEstado()` guarda
  el estado que llegó, tal cual. Ojo, que es fácil leerlo al revés: **el Pull no confirma nada**
  —ni siquiera en canales de pago total—, porque lo que llega como `new`/`request` se deja así
  a propósito para que el equipo local lo verifique. Ver **§5.4**, que explica además por qué el
  código parece decir lo contrario.
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

### 11.2.b Un INQUIRY no estrena línea financiera

Beds24 manda `invoiceItems` también para las pre-reservas de Airbnb: siempre en **0.00** y con
la descripción sin resolver (`[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`). El panel de la reserva
enseñaba «Cargos (1)» y una línea de US$ 0.00 que no informa de nada y que hace parecer que una
pre-reserva ya tiene contabilidad.

`upsertCargos()` salta la **creación** —no la actualización— cuando la estancia del ítem está en
`abierto`, que es exactamente `inquiry` y nada más (ver el mapeo de
`pms_evento_estado.codigo_beds24`).

Encaja con lo que el sistema ya creía: `abierto` **no** está en `IMPIDEN_VENTA` —un inquiry no
bloquea la venta— y `resolveEstadoPagoInicial()` ya lo forzaba a nacer sin pago. Esta era la
única pieza que seguía tratándolo como una venta.

⚠️ **`cancelada` NO se excluye, aunque la intuición diga lo contrario.** Las **penalizaciones**
llegan justo sobre reservas ya canceladas y son lo único que sigue contando cuando la cabecera
se marca inactiva (§12.7). En producción, al hacer el cambio: 20 penalizaciones por 129.38 sobre
estancias `cancelada`. Excluirla habría roto el cobro de penalizaciones. `bloqueo` no necesita
regla: no es una reserva y no tiene facturas.

#### No hace falta un disparador para «cuando pase a estado real»

Es la pregunta obvia, y la contestan dos hechos:

1. **El webhook trae SIEMPRE los importes.** 1.739 de 1.739 avisos auditados desde el 04/06/2026
   incluyen el nodo `invoiceItems`: Beds24 no manda «sólo el cambio de estado», manda la reserva
   entera.
2. **`ProcessBeds24WebhookDispatchHandler` procesa `booking` ANTES que `invoiceItems`.** Cuando
   el persister mira la estancia para decidir si la salta, ya la ve en su estado nuevo. En el
   mismo aviso de la conversión entran los cargos con su importe real.

Y `Beds24InvoiceReceiveJob` es la red: barre las estancias del periodo **sin filtrar por
estado**, que es justo para lo que existe (el webhook de Beds24 no reintenta).

Si la estancia no se resuelve (sync a medias), el cargo **se crea igual**: no saber en qué estado
está no es motivo para tirar un importe que puede ser real.

Los tres cargos de 0.00 que ya existían los retiró `Version20260826180000`, acotada a
`abierto` + `beds24_item_id IS NOT NULL` + `total_linea = 0` — las tres condiciones juntas son
lo que la hace inerte: no mueve ningún total.

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

#### 11.5.1 El cobrador: quién RECIBIÓ el dinero

`PmsPagoFinanciero.cobrador` es una FK nullable a `User`. Existe para poder cuadrar la caja: sin
ella no hay forma de saber a quién reclamarle el efectivo que un huésped entregó en la casita.

⚠️ **No es quien registró el pago en el sistema.** El efectivo lo cobra quien está en la casita
—la limpiadora, el de mantenimiento— y lo apunta después otra persona, o el propio agente por
chat. Confundirlos haría que todo el efectivo figurase a nombre del operador de recepción, que es
exactamente lo que impide cuadrar. Por eso es un campo propio y no se deduce del usuario
autenticado.

**Quién puede serlo: `ROLE_COBRADOR`** (`Roles::COBRADOR`), y **no se filtra por `enabled`**. Los
dos criterios que se descartaron, con su porqué:

| Criterio | Por qué no |
|---|---|
| `enabled = 1` | Dice si la persona **entra al sistema**, no si maneja caja. Maria Apaza y Milka están en `enabled = 0` —cobran el efectivo y no necesitan login— y quedaban fuera justo las que más cobran. Habilitarlas sólo para nombrarlas en un desplegable les daría acceso al panel |
| Deducirlo del puesto (`ROLE_LIMPIEZA`…) | Puede haber personal de limpieza que no maneje caja. Cobrar es una **responsabilidad**, no un cargo |

Tampoco cuelga de `ROLE_ADMIN` en `security.yaml`: ser administrador no implica manejar el
efectivo, y heredarlo metería a todos los admin en la lista.

**🪞 El filtro vive en dos sitios y hay que tocar los dos:**

| Camino | Símbolo |
|---|---|
| Panel (desplegable) | `PmsEnumAjaxController::getCobradores()` — `UserRepository::findByRole()` |
| Agente | `RegistrarPagoSkill::cobradoresPosibles()` — `LIKE` sobre `u.roles` |

Si el agente admitiera a alguien que el panel no ofrece, registraría pagos a nombre de quien el
operador no puede elegir a mano.

⚠️ **Se filtra por la columna `user.roles` LITERAL, no por la jerarquía de `security.yaml`.** Un
rol que sólo se tenga por herencia NO aparece en la lista de cobradores. Es lo que se quiere
—`ROLE_COBRADOR` se asigna a dedo—, pero sorprende si se busca el fallo por el lado de la
jerarquía.

**Cuándo se pide:** sólo si `PmsMedioPago::seCobraEnMano()`. Una transferencia y un PayPal caen en
la cuenta de la empresa sin que nadie los coja, así que preguntar ahí quién recibió el dinero es
una pregunta sin respuesta; en efectivo, Yape, tarjeta y Western Union sí hay una persona detrás.

`NULL` es legítimo en tres casos: pagos anteriores a este campo (la migración no hace backfill —no
se puede adivinar quién cobró hace un año), depósitos automáticos de las OTA (`esAutomatico`, §12.8)
y los medios que no se cobran en mano.

**Cómo se decide el cobrador, por orden:**

| El operador dice… | Cobrador | Dónde sale |
|---|---|---|
| «se lo pagó a María» | Maria Apaza | Búsqueda parcial entre los `ROLE_COBRADOR` |
| «me pagó a mí», «lo cobré yo» | El propio operador | `ActorInterface::usuario()` |
| *(nada)* | Quien tenga `User::$esCobradorPrincipal` (recepción) | Defecto |

`esCobradorPrincipal` existe porque en la práctica casi todo el efectivo lo cobra recepción, y
obligar a repetirlo en cada pago era fricción sin información. **Es un defecto, no una regla:** el
cobrador atribuido se enseña SIEMPRE antes de confirmar (`cobrado_por`), porque uno equivocado y
silencioso descuadra dos cajas. Se espera una sola persona marcada; con varias se toma la primera
por nombre —determinista pero arbitrario: es un dato mal puesto, no un caso a soportar.

⚠️ **El «yo» tampoco se salta el filtro.** Manejar el chat y estar autorizado a recibir dinero son
cosas distintas: si quien opera no tiene `ROLE_COBRADOR`, la skill lo rechaza y pide un nombre. Se
mira la columna literal y **no `tieneRol()`**, que a un `SUPER_ADMIN` le abriría esta puerta como
le abre las demás.

**El panel (EasyAdmin):** `PmsPagoFinancieroCrudController` filtra el desplegable por
`ROLE_COBRADOR` con la misma consulta. En el CRUD de usuarios el flag se marca con «Cobra por
defecto (recepción)», deliberadamente separado de «Cuenta Activa».

> 🐛 De paso se corrigió una etiqueta que costaba dinero: `monto` figuraba como «Monto (cobrado al
> cliente)» cuando es el **neto**. Quien pagaba 105.50 por el POS al 5.5% tecleaba 105.50, y la
> reserva quedaba con 5.50 de más abonados. Ahora es «Monto NETO (abona a la deuda)» con el
> ejemplo en el `help`. También se añadió `esAutomatico` como sólo lectura: sin verlo, un depósito
> de OTA parece un pago manual y alguien lo «corrige» sin saber que el sistema lo mantiene solo.

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

**Para distinguir a qué estancia pertenece cada cargo**, el persister la imputa en la FK
`evento` al sincronizar (resuelta desde `beds24BookingId` por el link principal, ver §12.4.1);
`beds24BookingId` queda como verdad histórica cruda y `getEstancias()` (mapa
`bookingId → casita/fechas`) es el respaldo del panel para filas aún sin imputar. Sin nada de
esto, dos habitaciones del mismo grupo se ven con descripciones idénticas
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

Tener la cabecera (§12.0) resuelve *dónde* colgar los importes. Lo que se cuelga cambió dos veces,
y las dos por el mismo motivo.

#### Qué se crea hoy: NADA

**Desde el 25/08/2026 una estancia directa nueva nace sin ningún cargo.** No hay
`generarParaEvento()`: se retiró junto con el paso 5 del `onFlush` que recolectaba las estancias
directas insertadas.

La historia en dos saltos:

| Hasta | Qué estrenaba una estancia directa |
|---|---|
| 15/08/2026 | Tres cargos **con importe**: alojamiento del tarifario noche a noche, suplemento por persona y limpieza de la unidad |
| 25/08/2026 | **Una** línea de `LIMPIEZA` en `0.00`, descrita `Estancia directa · <casita>` |
| hoy | Ninguna |

**Por qué se fueron los importes.** El precio de una venta directa **no lo pone el tarifario, lo
pone quien vende**: se cierra por teléfono o por WhatsApp, con descuento por estancia larga, con
el desayuno dentro, o al precio de siempre para un repetidor. Había que borrar tres líneas y
teclear la real —y, peor, si alguien no las borraba la reserva quedaba con un precio que nadie
había acordado.

**Por qué se fue también la línea en cero.** No resolvía eso: era un hueco que igualmente había
que rellenar o borrar, y encima llegaba etiquetada como `LIMPIEZA` a una estancia cuyo primer
cargo casi nunca es la limpieza.

⚠️ **Esto NO alcanza a los cargos de horario extra** (§12.5.4), que siguen naciendo a `0.00`.
Ahí el cargo no es un precio inventado: es la consecuencia de una noche que se bloqueó, y el
sistema sí sabe que existe aunque no sepa cuánto vale.

⚠️ **No es «se dejó de calcular el tarifario».** Se dejó de *cobrarlo solo*. El cálculo sigue
entero y se **enseña**, que es lo siguiente.

#### El coste teórico: se enseña, no se cobra

`PmsCargosAutomaticosService::costoTeorico()` devuelve el desglose de lo que esa estancia
costaría según el tarifario y la ficha de la casita:

| Icono en el panel | Línea | De dónde sale |
|---|---|---|
| 🛏 | `porNoche × noches = importe` | Suma del precio de **cada noche** del tarifario |
| 👤 | `precio × personas × noches` | `PmsUnidad::suplementoPorPax()`, sobre el pax que excede `paxIncluidos` |
| 🧹 | `importe` | `PmsUnidad::costoLimpieza()` — fijo o porcentaje, según el flag de la casita |
| ═ | Total | La suma, con la moneda del tarifario |

El panel financiero lo pinta en un **tooltip tras un icono `i`**, en la cabecera de la estancia
(`ReservaFinanzasPanel.vue`). Va en la cabecera y no pegado a un cargo concreto **a propósito**:
es de la estancia entera, y colgado de una línea desaparecería en cuanto alguien borrara esa
línea.

⚠️ **Y la cabecera no depende de que haya cargos** (25/08/2026). `gruposCargos` se siembra desde
`info.estancias` y los cargos se reparten encima; antes se armaba recorriendo **sólo los cargos**,
así que una estancia sin ninguno no generaba cabecera y el tooltip desaparecía. Mientras existió
la línea en cero eso no se notaba —siempre había un cargo—, y al retirarla la referencia se
habría perdido justo cuando sirve: **antes** de que nadie teclee el primer importe. Una estancia
sin cargos pinta su cabecera con «Sin cargos en esta estancia» debajo.

Dos honestidades que no son adorno:

- **`porNoche` sólo se rellena si TODAS las noches valen lo mismo.** Con temporada alta de por
  medio no hay un «precio por noche» que enseñar, y escribir la media invitaría a multiplicarla
  por las noches y a no cuadrar con el total. En ese caso el tooltip dice «tarifa variable».
- **`alojamiento` puede venir a `null` con el resto relleno**, y eso **no es cero**: significa que
  al tarifario le faltaba alguna noche. El tooltip lo dice en ámbar («sin tarifa para todas las
  noches») en vez de enseñar un alojamiento corto que se leería como el precio entero. Y si la
  limpieza de esa casita es a porcentaje, se omite también: su base sería incompleta.

El tooltip cierra con «Referencia, no el precio acordado». Sin esa línea el número de arriba se
lee como el precio de la reserva y alguien lo teclea tal cual.

**Cómo llega al front.** `costoTeorico()` necesita el tarifario, que es un servicio, y una entidad
no puede llamarlo. Lo inyecta `PmsInformacionFinancieraPorReservaProvider` en el campo transitorio
`PmsInformacionFinanciera::$costosTeoricos`, indexado por `eventoId`, y `getEstancias()` lo emite
dentro de cada estancia. Mismo mecanismo y mismo motivo que `$prepagoPendiente`.

⚠️ Sólo lo rellena la operación `por-reserva`, que es la que usa el panel. En un `GET` por id o en
la colección llega `null` — y ahí `null` significa «nadie lo ha calculado», no «no hay tarifario».

#### La excepción: cuando SÍ hay un acuerdo

Las skills del agente (`crear_reserva`, `crear_estancia`) tienen algo que el panel no tiene: un
**paso de aprobación explícito**. El operador ve el desglose y dice que sí. Ahí sí se escriben
importes, por una sola puerta:

`PmsCargosAutomaticosService::escribirAprobados()` retira las líneas en cero de esa estancia —**y
sólo si siguen en cero**: con importe es dinero que alguien valoró, y eso no se pisa— y escribe
las líneas aprobadas.

⚠️ Esa limpieza previa **ya no la genera nadie**, y se queda como defensa: las reservas anteriores
al 25/08/2026 arrastran su línea en cero, y escribir encima sin retirarla dejaría el hueco viejo
debajo del importe nuevo.

⚠️ **Vive en el servicio, no en cada skill.** Con una copia por skill, la primera corrección se
quedaría en una de las dos. Y las líneas salen del **mismo** `cargosPrevistos()` que se le enseñó
al operador: cuando eran dos cálculos separados se separaron de verdad —a la previsualización le
faltaba el suplemento por persona, y el operador confirmaba 36.00 menos de lo que luego veía—.

Una línea aprobada en `0.00` (la limpieza perdonada por el operador) **no se escribe**: es
informativa, y escribirla llenaría la cuenta de ceros que no significan nada. Sale en
`ajustes_aplicados` / `cargos_escritos` para que el agente se lo pueda contar.

#### El cálculo del tarifario, para lo que se enseña y para lo que se aprueba

`preciosDeNoches()` es la única lectura del tarifario del servicio: la usan
`estimarAlojamiento()` (la previsualización de las skills) y `costoTeorico()` (el tooltip). Está
separada justo para que no haya dos — una fórmula que suma y otra que descompone acabarían dando
cifras distintas el día que una de las dos cambie.

Reutiliza `TarifaPricingEngine::buildDailyPricesForIntervalWithFallback()` — el mismo motor que
pinta el calendario de tarifas —, así que respeta temporadas, prioridades y solapamientos
*exactamente igual que lo que el operador ve en pantalla*. El intervalo es `[llegada, salida)`: la
noche de salida no se cobra, que es el contrato del flattener (`to` exclusivo).

⚠️ **Gotcha — el flattener OMITE los días que no sabe precisar.** No devuelve `0`, devuelve un
mapa más corto: quien sume precios a ciegas cuenta menos noches de las reales sin enterarse. De
ahí dos defensas:

1. **Fallback a la tarifa base de la unidad** (`PmsUnidad::isTarifaBaseActiva()` /
   `getTarifaBasePrecio()`), el mismo que usa el push de tarifas a Beds24
   (`Beds24RatesPushQueueCreator::createFallbackProvider()`). Sin él, una estancia en fechas sin
   ningún `PmsTarifaRango` — habitual en fechas lejanas — daría una estimación de cero.
   Por eso se usa la variante `…WithFallback()`: la versión sin fallback no acepta el proveedor.
2. **Guarda de cobertura:** si aun con el fallback faltan noches (`count($preciosDiarios) <
   $nochesEsperadas`), devuelve `null` y lo registra en el log.

`$nochesEsperadas` se cuenta **a día truncado, no a instante**: la estancia va de las 14:00 a las
10:00, así que un `diff()` crudo de dos noches daría "1 día y 20 horas" → 1 (§12.5.5). El
flattener trunca a medianoche y aquí se cuenta igual (`aDia()`).

**Cuándo aplica** (`PmsCargosAutomaticosService::aplica()`). Ya no decide qué cargos nacen —no
nace ninguno—, sino **a qué estancias se les enseña el coste teórico**:

- Sólo estancias de **canal directo**. Una OTA recibe sus importes del canal, y ofrecerle una
  referencia del tarifario no significaría nada.
- Nunca sobre un **bloqueo** ni sobre una **extensión** (`esExtension()`): no son ventas.
- Requiere unidad + fechas.

⚠️ De ahí que `PmsEstanciaCreator` marque **canal directo** una estancia añadida a mano dentro de
una reserva de OTA: con el canal de la OTA se quedaría sin esa referencia, en silencio.

**Nunca se rompe el guardado por el tarifario.** Si el motor de precios revienta, se registra en
el log y se sigue sin estimación. Un tooltip vacío es mucho más barato que una reserva que no se
puede guardar.

**Verificado en su día** (2026-08-15) sobre las 24 estancias directas reales de la base: las 24
devolvían su desglose de coste teórico, contrastado a mano con `var/probar-costo-teorico.php` —
p. ej. Casita 4, 6 noches, 14 pax: `33.00 × 6 N = 198.00`, `6.00 × 11 P × 6 N = 396.00`, limpieza
`15.00`, total `609.00 USD`. Esa parte sigue igual; la que comprobaba el cargo en cero
(`var/probar-cargo-directo-cero.php`) ya no describe lo que pasa.

**Lo que escriba `escribirAprobados()` nace MANUAL** (sin `beds24ItemId`, §12.4.1): el operador lo
corrige o lo borra sin pelearse con la sincronización.

**Orden dentro del flush (gotcha).** El paso que recolectaba las estancias directas insertadas
para generarles cargos **ya no existe**; lo que queda en `postFlush` es el horario extra, y el
orden sigue importando por lo mismo:

```
postFlush → sincronizarHorariosExtra()  ← PRIMERO: crea o retira el cargo y hace su propio flush
            recalcular($ids)            ← DESPUÉS, con los IDs de cabecera que devolvió
```

Si el rollup corriera antes, el saldo saldría sin ese cargo hasta el siguiente guardado. Ese
`flush()` interno vuelve a disparar `onFlush`, pero el guardia `isFlushing` lo corta — por eso
`sincronizarHorariosExtra()` **devuelve** las cabeceras tocadas en vez de confiar en que el
listener las recolecte sola.

### 12.0.1.1 La barra de estancia del panel financiero

En «Cargos», cada estancia lleva su propia barra de cabecera con **icono de canal, casita, fechas
y subtotal**. Tres detalles que parecen cosméticos y no lo son:

- **Se muestra SIEMPRE, aunque la reserva tenga una sola estancia.** Antes se ocultaba con un
  único grupo, por no meter ruido, y eso dejaba los cargos sin decir de qué estancia y de qué
  canal son — que es justo lo que hay que saber para cuadrar una reserva.
- **El icono de canal va con su color de marca**: Booking azul, Airbnb rojo, directo verde. Sale
  de `canalInfo()` en `util/src/types/pmsReservaModel.ts`, que es la **tabla única** del front
  para canal → texto + icono + color. Hasta el 15/08/2026 había tres copias de esa
  correspondencia (`canalInfo`, `otaMenuInfo`, `extranetInfo`); ahora es una, y por eso VRBO
  aparece sin haber tocado nada.

  ⚠️ Las clases de color van **completas y sin interpolar** (`text-rose-500`, nunca
  `text-${x}-500`): Tailwind compila leyendo el código fuente y una clase construida en tiempo de
  ejecución no llega nunca al CSS. Es el mismo motivo por el que un color que venga de la API no
  puede ser un nombre de clase.
- **El backend manda el `id` crudo del canal**, no una etiqueta. `getEstancias()` emite
  `'canal' => $evento->getChannel()?->getId()`; el texto y el icono los decide el front. Si el
  backend mandara el nombre resuelto habría dos sitios donde decir cómo se llama Booking.

El mismo icono aparece en la cabecera de cada estancia del acordeón del drawer
(`ReservaEditDrawer.vue`): en una reserva agrupada pueden convivir una casita de Booking y otra
vendida directa, y hasta ahora el canal sólo se veía abriendo el acordeón.

### 12.0.2 Desglose por tipo en la cabecera

`PmsInformacionFinanciera::getTotalPorTipo(PmsTipoCargo)` (con los atajos serializados
`getTotalAlojamiento()`, `getTotalLimpieza()`, `getTotalServicio()`) suma los cargos de un solo
concepto, en la moneda de la cabecera.

⚠️ **Es un espejo en PHP del SQL del rollup** (`PmsInformacionFinancieraRecalculoService`) y
replica su regla de anulación: **con `activa = false` sólo cuenta la `PENALIZACION`** (§12.7).
Si se cambia esa condición hay que tocar **los dos sitios**, o el desglose dejará de cuadrar con
`totalCargos`.

### 12.0.2b El estado de cuenta que ve el huésped (pax)

`PmsReservaView.vue` (pax) muestra una tarjeta "Estado de cuenta" con total, adelanto, saldo y
el saldo pagando con tarjeta. Reglas que no se ven en el código:

- **Viaja el agregado y un desglose, nunca el árbol.** `PmsReservaPaxProvider` decora el Get
  público por localizador y cuelga `resumenFinanciero` como propiedad virtual de `PmsReserva`:
  moneda, símbolo, total, pagado, saldo, más `cargos` (agrupados por tipo) y `pagos` (fecha +
  medio + importe). El árbol real (`pms_finanzas:read`, con descripciones, referencias, notas,
  IDs de Beds24 y comisiones) es del panel interno y **nunca** se serializa al cliente. Espejo
  TS: `PmsResumenFinanciero` en `pax/src/types/paxHuespedModel.ts`.
- **El desglose viaja por TIPO, no por descripción.** Las descripciones las escribe Beds24 en un
  solo idioma (y son plantillas del estilo `[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`): no se
  pueden traducir. Lo que viaja es el **valor del enum** — `PmsTipoCargo` en los cargos,
  `PmsMedioPago` en los pagos— y el front resuelve la etiqueta por i18n (`res_cargo_*`,
  `res_medio_*`, sembradas en `Version20260804170000`). Añadir un caso al enum obliga a sembrar
  su etiqueta **y** a añadirla al mapa de respaldo de `PmsReservaView`.
- **Las líneas van convertidas a la moneda de la cabecera, igual que el agregado.** Es lo que
  hace que el desglose SUME el total que se muestra al lado en una reserva con cargos en soles y
  en dólares. `PmsInformacionFinanciera::aMonedaBase()` es el **espejo en PHP** de
  `PmsInformacionFinancieraRecalculoService::expresionConvertida()`, que produce
  `total_cargos` / `total_pagos` en SQL: mismo par de monedas, mismo TC congelado en la línea,
  mismo `ELSE` sin convertir para un par no contemplado. **Si cambia una, cambia la otra.**
- **El desglose lo calcula la cabecera, no el provider.** `PmsInformacionFinanciera::getDesglosePorTipo()`
  concentra las cuatro reglas (anulación §12.7, `esCargo()` porque la colección también trae filas
  `payment`, `totalLinea ?? monto` porque el webhook no siempre manda la primera, y la conversión
  de arriba). `getTotalPorTipo()` —que usa la mensajería— es hoy un envoltorio suyo, así que las
  dos vistas no pueden separarse.
- **Sin cabecera o con `total_cargos = 0` el campo va `null`** y la tarjeta no se pinta: una
  reserva sin cargos no debe enseñar "total 0.00". Con `activa = false` los caches ya solo suman
  la penalización (§12.7), así que el saldo mostrado sigue siendo el correcto sin lógica extra.
- **En Airbnb y VRBO no se enseñan los importes del canal.** Lo que guardamos es lo que la OTA
  nos **remite**, no lo que el huésped **pagó** (que lleva encima la comisión de servicio de la
  OTA). Enseñárselo abre una conversación sobre por qué las cifras no cuadran. Ver el apartado
  siguiente.
- **El recargo por tarjeta (+5.5%) es presentación, no un cargo.** Vive únicamente en
  `PmsReservaView::RECARGO_TARJETA_PCT`; el backend no lo conoce y el cobro real lo hace
  recepción. La nota i18n (`res_recargo_nota`) interpola `{{ pct }}` con esa constante para que
  el texto no se desactualice si cambia la comisión.
- El lookup de la cabecera usa `findOneBy(['reserva' => $reserva])` con el objeto — un
  SearchFilter sobre la relación devolvería vacío en silencio por el UUID binario (§12.6).

#### El resumen delante, el detalle desplegable, y el ancla (28/08/2026)

La tarjeta tiene **tres peldaños**, y el orden es el de la atención del huésped:

```
cerrada           sólo la barra de progreso  →  «¿voy bien?»
+ Ver mi cuenta   el RESUMEN                 →  cuánto y a qué precio
  + Ver detalle   el DETALLE                 →  de qué se compone
```

⚠️ **El resumen va DENTRO del primer plegable, no fuera.** Estuvo fuera y rompía lo que la
tarjeta ya hacía bien: cerrada enseña sólo la barra, y en móvil eso es lo que impide que la
cuenta empuje las unidades fuera de pantalla.

El botón de detalle vive **dentro** del resumen, pegado a las cifras que abre — un botón que
despliega algo escondido dentro de otra cosa cerrada no se entiende. Cerrar el resumen cierra
también el detalle, para que no quede colgando.

Lo que cambia entre resumen y detalle son los **subtotales**: el resumen da una cifra por
precio; el detalle la abre en cargos, pagos, tipo de cambio y comisión.

El estado viaja en la URL:

```
/huesped/reserva/{localizador}#resumen    → sólo el resumen
/huesped/reserva/{localizador}#detalle    → con el desglose desplegado
```

⚠️ **Se probaron pestañas y estaba peor.** Elegir «Detallado» **escondía el resumen**, y el
resumen es justo lo que hay que tener delante mientras se mira el desglose: es el total al
que se refieren esas líneas. Así que se volvió al plegable y se le añadió lo único que le
faltaba —el ancla—, que es lo que resuelve el problema de verdad: **un plegable no se puede
enlazar**, y cuando el equipo contesta a quien pregunta de qué se compone su cuenta quiere
mandarle el desglose, no una página donde buscar el botón.

**De ahí sale que la plantilla de WhatsApp no necesite variantes.** Fuera de la ventana de
24 h sólo se puede mandar plantilla aprobada; con el ancla, el mensaje aprobado es **uno
solo** —un empujón con botón de URL— y lo único que cambia entre «te recuerdo que debes» y
«aquí tienes el desglose» es el `#`. Sin esto haría falta una plantilla por variante, y las
variantes son cientos (política × momento × procedencia × monedas × idiomas). Ver
`docs/Mensajeria.md`.

**Las dos anclas son explícitas**, incluida la del resumen que es el estado por defecto: el
enlace tiene que decir a qué lleva. Quien lo recibe por WhatsApp no ve la página, ve la URL.

#### Qué enseña el resumen, y de dónde sale

Lo decide `PmsSituacionDeCobro` y llega en `resumenFinanciero.situacion`. La vista **no
calcula nada**: ni qué se pide, ni qué medios valen, ni cuánto sale con tarjeta. Si volviera
a multiplicar por 1.055 habría dos verdades otra vez.

- **Una línea por PRECIO, no por medio.** Agrupar por tipo bajó de doce filas del catálogo a
  cinco medios y **seguía sobrando**: «Yape 164.10 · Plin 164.10 · Efectivo 164.10 ·
  Transferencia 164.10 · Tarjeta 173.13» son cinco líneas para decir **dos cifras**, que es el
  mismo ruido en otro formato. Lo que el huésped decide no es «¿por dónde pago?» sino «¿me
  cuesta lo mismo?», y a eso sólo hay dos respuestas. Lo agrupa
  `PmsSituacionDeCobro::mediosPorImporte()`, en la fuente única, para que el mensaje, el pax y
  el panel agrupen igual.
- **El recargo va DENTRO del importe**: efectivo 259.72, tarjeta 274.00. El porcentaje se dice
  como matiz —«incluye 5.5% de comisión»— no como una operación que el huésped tenga que hacer.
- **Las cuentas viven detrás de una «i»**, una por medio que las tenga. Ver más abajo.
- **Un bloque por moneda** cuando hay dos. No se suman ni se convierte: el cuadre con `≈` es
  para el panel interno, no para pedirle dinero a alguien.
- **Los soles entre paréntesis** sólo si consta que paga desde Perú (`pagaDesdePeru()` es
  ternaria; su `null` es «no se sabe»).

#### La «i» de cada medio: las cuentas, detrás de un clic

Las fichas —titular, banco, número, CCI— **estuvieron excluidas a propósito** mientras la idea
era pintarlas: volcar ocho cuentas en la primera pantalla de todo el mundo es el abrumamiento
que el agrupado por precio venía a deshacer. Viajan desde el 28/08/2026 porque cambia **dónde
se enseñan**: tras una «i» que hay que pulsar son lo que el huésped que ya eligió Yape necesita
para ejecutar el pago, y le ahorran el «¿a qué número te lo mando?» por WhatsApp.

Las compone `PmsReservaPaxProvider::conFichas()`, que cruza por código los grupos de
`mediosPorImporte()` con `PmsSituacionDeCobro::$medios`.

- **Campo a campo, no la entidad.** `FinMedioCobro` lleva además audiencia, `dias_minimos` y
  `dias_maximos`, que son reglas nuestras: no le dicen nada al huésped y describen a quién le
  ofrecemos qué.
- **La ficha sin ningún dato NO viaja.** «Efectivo» tiene la suya en el catálogo —la necesita
  para llevar su ventana de días— pero no tiene nada que enseñar: se paga en recepción. Si
  viajara, la app le pintaría su «i» y abriría un cuadro en blanco, que es peor que no tener
  icono: enseña que los iconos de esta pantalla no llevan a nada. `pms:situacion-cobro` marca
  esas fichas como *vacía — sin «i» en la app*.
- **Un medio puede tener varias**: «transferencia bancaria» son ocho, cuatro bancos por dos
  monedas. Por eso es una lista, y por eso el cuadro se pinta en filas de una línea con el
  titular dicho **una sola vez** al pie (`titularComun`): repetido ocho veces convierte el
  desplegable en una pared.
- **La nota se pinta a lo ancho al pie del cuadro** —no en la columna estrecha de los
  números—, después del nombre: es el orden en que se rellena el formulario de un giro. En
  Western Union es la línea que evita mandar el dinero por una vía que no podemos cobrar, así
  que no va en gris de pie de página. Ver `docs/FinanzasEnlacesPago.md` §14.2c.
- ⚠️ **La nota viaja como ARRAY i18n y la traduce el cliente** (`maestroStore.traducir()`),
  igual que `PmsGuiaHuespedProvider::mediosPago()` hace con esta misma nota. Resolverla en PHP
  con `getNotaEn($reserva->getIdioma())` parece más limpio y **da el idioma equivocado**: ese
  campo es el que se dedujo al crear la reserva, y la tarjeta se pinta en el que el huésped
  eligió en el selector. Hay reservas con `idioma = en` que se leen en castellano; resuelta en
  servidor, esa tarjeta saldría en español con un párrafo en inglés dentro.
- **El aviso de «ya pagué» lo elige el CANAL, y va aparte de la nota.** El chat de Booking no
  transporta imágenes, así que a su huésped hay que decirle que la captura la mande por
  WhatsApp; al de una reserva directa, no. Estuvo metido dentro de las notas de Yape y Plin del
  catálogo de cobro y el resultado era que **a un huésped de una reserva directa se le hablaba
  del chat de una plataforma por la que no vino**. Ahora `comoResumen()` manda la clave i18n
  (`res_aviso_pago` / `res_aviso_pago_sin_imagenes`) según `PmsChannel::chatAdmiteImagenes()`, y
  la resuelve el cliente. Sin canal —una reserva cargada a mano— se asume el chat normal, que es
  el caso bueno. Se pinta una vez, al pie del cuadro abierto: repetida en cada ficha es ruido.
- ⚠️ **Y nunca `getNotaEsVisual()`.** Es un getter señuelo para que EasyAdmin encuentre la
  propiedad en el listado y **siempre devuelve cadena vacía** —quien pinta la celda es el
  `formatValue()` del CRUD—. Usarlo filtraba la nota entera sin dar un solo error: el huésped de
  Western Union veía un nombre y una ciudad, y ninguna instrucción. Es la trampa de «un atributo
  mal puesto no falla, deja de hacer su trabajo», con un getter.
- **La fila se rotula con PALABRAS: «En soles», no «PEN» ni «S/.»** En la columna del importe
  el símbolo es lo correcto; como rótulo de una fila —«S/.  +51 958191965»— se lee como el
  prefijo de un precio que no está, no como «esta cuenta es en soles». Viajan el código ISO y el
  símbolo: el código elige la cadena (`ETIQUETA_MONEDA` en la vista → `res_en_soles`), y el
  símbolo queda de respaldo para una moneda sin cadena propia. El símbolo lo resuelve
  `simboloMoneda()` contra `MaestroMoneda`, porque `FinMedioCobro::$moneda` es una columna de
  texto suelta y no una relación.
- **El titular lleva su rótulo, «A nombre de».** Suelto era un nombre bajo unos números que no
  decía su papel — y es el que el huésped teclea en su banca y coteja antes de confirmar.
- **El título del cuadro nombra a TODOS los medios que comparten esa cuenta.** Yape y Plin son
  dos entradas del catálogo con el mismo número —dos apps sobre el mismo teléfono— y el cuadro
  se titulaba con el que se hubiera pulsado: quien abría por Yape leía «YAPE» y no tenía cómo
  saber que ese número también le vale por Plin, que es lo que quiere saber justamente quien
  sólo tiene una de las dos apps. Se agrupa comparando la ficha, no una lista de pares
  conocidos: el día que dos cuentas dejen de coincidir, el título se separa solo.
- **`tabular-nums` sólo si hay dígitos.** Western Union no tiene cuenta: su «número» es el
  destino del giro —«Cusco, Perú»—, y en cifras monoespaciadas se lee como un código que copiar.
- **El titular alterno sólo sale si aporta.** Casi siempre es el mismo nombre sin tildes
  —hay bancos que no las aceptan en el campo de destinatario—, y enseñar «Susan Acuña Romero ·
  Susan Acuna Romero» le hace dudar de cuál copiar cuando su banco acepta cualquiera de los
  dos. `alternoSiAporta()` compara sin tildes ni mayúsculas; si de verdad son dos personas
  distintas, sale.
- **Las «i» laten cada 10 s hasta que se abre una.** Un icono de información dentro de una
  corrida de texto no se lee como pulsable —parece puntuación—, y quien no lo pulsa acaba
  preguntando el número de cuenta por WhatsApp, que es el paso que las fichas vienen a quitar.
  El latido es doble y corto: un parpadeo lento se lee como algo roto. Se apaga con el primer
  clic (`yaDescubrio`), porque descubierto deja de ser información y pasa a ser insistencia en
  la pantalla donde le pedimos dinero. Va en CSS y no en un `setInterval` —el navegador lo
  pausa solo en segundo plano— y **`prefers-reduced-motion` lo desactiva**: un bucle infinito
  de movimiento es un síntoma para quien tiene migraña con aura o trastorno vestibular.
- **El cierre es una X.** En táctil no hay «quitar el ratón de encima», así que sin cierre
  explícito un cuadro abierto se queda abierto. Y empuja lo de abajo en vez de flotar: un
  tooltip absoluto en un móvil tapa justo el importe que lo motivó.

⚠️ **Viajan aunque nadie pulse, y es una decisión.** Esta vista se abre con el localizador, así
que están al alcance de quien tenga el enlace. Son cuentas para **recibir** dinero, no
credenciales — el mismo criterio por el que la guía del huésped ya las publica.

#### ⚠️ El cuadro del importe NO es un enlace

Lo fue: el cuadro entero llevaba al enlace de pago. Se cruzaban dos cosas.

La de bulto es que dentro viven ahora las «i», y un icono dentro de un enlace es un icono que
navega. La de fondo ya estaba mal antes: el cuadro dice **259.72** —lo que cuesta por Yape— y
el enlace cobra **274.00**, porque es de tarjeta y lleva el 5.5 %. Pulsar el importe de un
medio para acabar pagando el de otro es exactamente la trampa que el agrupado por precio venía
a deshacer, y estaba dentro del propio arreglo.

Pagar con tarjeta se hace desde **«Pagar ahora»**, que está debajo junto a *su* cifra.
`enlaceDelImporte` sigue eligiendo cuál —por importe, no el primero— porque pueden convivir el
del adelanto y el del total. Un solo camino, y lleva a lo que dice.

⚠️ **`resumenFinancieroCliente` es un `?array` sin `openapiContext`**, así que la
introspección no ve su forma y `api.d.ts` no cambia al añadirle claves. Por eso
`PmsResumenFinanciero` se declara a mano en `pax/src/types/paxHuespedModel.ts` — es la
excepción del tipo inyectado por el provider, no un descuido. Al tocar `comoResumen()` hay
que tocar ese espejo.

#### El ancla trae la tarjeta a la vista

El router de `pax` **no declara `scrollBehavior`**, así que un hash abría el estado correcto y
dejaba al huésped mirando el principio de la página —la cuenta es una sección entre varias—.
Se resuelve en el componente y no en el router a propósito: un `scrollBehavior` global
cambiaría el comportamiento de todas las rutas de `pax` para arreglar una.

⚠️ Y el desplazamiento va **también al montar**, no sólo en el `watch` del hash: llegando por
enlace el hash ya está puesto y nunca *cambia*, así que el `watch` no dispara. Es el camino
más frecuente —el huésped abre lo que le mandamos— y era justo el que se quedaba sin
desplazar. Va después de `cargar()`, porque la sección se pinta con `v-if="finanzas"`.

**El «atrás» del móvil:** desplegar usa `replace`, no `push`. Con `push`, atrás cerraría el
detalle en vez de salir de la reserva.

Con `soloProgreso` no hay resumen ni detalle: esa reserva no enseña una sola cifra.

### 12.0.2c Canales que cobran por nosotros: qué ve el huésped

En `PmsChannel::CANAL_PAGO_TOTAL` (Airbnb, VRBO) el resumen se recalcula excluyendo **todo lo que
sea espejo contable del canal**. `PmsReservaPaxProvider::cifras()`:

| Situación | Qué ve el huésped |
|---|---|
| Sin cargos añadidos a mano | `soloProgreso: true` → barra al 100 %, **ninguna cifra** |
| Con cargos añadidos a mano | Solo esos: total, pagado y saldo calculados sobre ellos |
| Canal normal (Booking, directo) | Los agregados cacheados de la cabecera, sin tocar |

**La exclusión va por marca explícita, nunca por heurística.** Dos campos, uno a cada lado:

- `PmsCargoFinanciero::$esAutomatico` — lo escribe `Beds24InvoiceReceivePersister` cuando la
  reserva está en un canal de pago total. Se **reevalúa en cada sync**, no solo cuando cambian
  importes: un cambio de canal tiene que corregirlo.
- `PmsPagoFinanciero::$esAutomatico` — ya existía; lo escribe `PmsPagoOtaAutomaticoService` para
  el depósito de la OTA, y **se apaga solo** si el operador edita el pago (a partir de ahí es un
  cobro real y vuelve a verse).

> **Por qué no vale emparejar «el cargo de subtipo 8 con el pago del mismo importe»**, que es lo
> primero que se piensa. Dos motivos, los dos reales:
>
> 1. **El Cancel Fee de Beds24 llega con `subType: 8`**, el mismo del alojamiento — por eso
>    `PmsTipoCargo::desdeBeds24()` comprueba la penalización *antes* que el subtipo. Filtrar por
>    subtipo 8 escondería las penalizaciones, que son justo lo que sí hay que cobrar y enseñar.
> 2. **Emparejar por importe** falla con dos cargos iguales, o si el depósito se recalculó y ya
>    no cuadra al céntimo.

⚠️ `esAutomatico` en el cargo **NO significa «lo generó el sistema»**. `PmsCargosAutomaticosService`
también genera cargos solo —los de reservas DIRECTAS, desde el tarifario— y esos van en `false` a
propósito: el huésped nos los paga a nosotros y tiene que verlos. Lo que marca el campo es *«esto
es contabilidad interna del canal»*.

La suma usa el mismo criterio que `PmsInformacionFinanciera::totalPorTipo()`: `esCargo()` —la
colección de cargos también trae filas `payment` de Beds24— y `totalLinea ?? monto`, porque el
webhook no siempre manda la primera. Sumar de otra forma daría cifras que no cuadran con el panel.

Backfill: `Version20260804160000`.

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

> 🚧 **En transición (desde el 16/08/2026).** Lo que sigue describe el modelo **viejo**, que
> todavía se escribe y todavía manda. El modelo nuevo —**totales por moneda, sin convertir**— ya
> tiene su esquema en la base (§12.2b) y se irá activando por fases. Mientras dure, las dos cosas
> se escriben a la vez a propósito: así revertir el código no obliga a revertir la base.

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

### 12.2b Contabilidad por moneda: soles con soles, dólares con dólares

**El problema del modelo de §12.2 no es el redondeo: es que convierte.** Un registro sin tipo de
cambio aportaba 0 y **desaparecía del total sin avisar**; y el saldo en la otra moneda era una
cifra que nadie había pactado. De ahí salieron, en tres días, «en dólares cuadra y en soles no»
(HMN4BP8J25) y el saldo que el panel tuvo que aprender a pintar como `—`.

La regla nueva: **los importes se suman por moneda y no se convierten**. El cliente debe lo que
debe — si se quedó en dólares, en dólares; si se quedó en soles, en soles; si se quedó en ambos,
en ambos.

#### Las tres piezas del esquema

| Pieza | Qué guarda |
|---|---|
| `pms_finanzas_total_moneda` (ficha, moneda) | `total_cargos` y `total_pagos` de **esa** moneda, sin convertir |
| `pms_pago_financiero.moneda_saldada_id` | A qué deuda se imputa un cobro cuando no es a la de su propia moneda |
| `pms_informacion_financiera.tipo_cambio` | El cambio con el que se **cuadra** la reserva entera |

**Tabla y no columna JSON:** `PmsEstadoPagoEventosService` decide en SQL crudo si una estancia
pasa a `pago-total` —y eso encadena hasta los códigos de acceso del huésped—, así que necesita
preguntar «¿queda alguna moneda debiendo?» con un `NOT EXISTS`. Sobre un JSON eso no se consulta.

⚠️ **`ON DELETE CASCADE` no es opcional.** Sin él, borrar una reserva revienta con un 1451 de
MySQL: es el mismo fallo que documenta el `OneToOne` de `PmsInformacionFinanciera` («ninguna
reserva se podía borrar desde el panel»), y esta tabla no entra en ninguna cascada del ORM.

⚠️ **`PmsFinanzasTotalMoneda` no tiene lado inverso**, y es deliberado: (a) el rollup se escribe
con SQL crudo y una colección ya inicializada quedaría rancia en la misma petición —`$em->refresh()`
**no refresca colecciones**—; (b) `PmsEventosSpaCalendarProvider::fetchFinanzas()` carga cabeceras
en lote y una colección lazy ahí son 50-80 consultas por mes pintado. Se lee con
`PmsFinanzasTotalMonedaRepository::totalesDe()`; para el saldo de una ficha recién tocada, con el
value object que suma desde sus colecciones.

#### La única conversión que sobrevive

`moneda_saldada_id` existe por un caso real y medido: **GASUNN**, cargos de Booking por US$ 65.97
y un único cobro de **S/ 223.70 por Yape al 3.391** (65.97 × 3.391 = 223.70 exacto). Sumando cada
moneda por su lado, esa ficha diría «debe US$ 65.97» y «tiene S/ 223.70 a favor» — y el huésped
pagó y se fue. Ese cobro **sí cruzó de moneda**, y esto es lo que le deja decirlo.

Medido sobre producción (16/08/2026): de 317 fichas, **4** tienen movimiento en más de una moneda,
y de ésas **3 son cruces** (`GASUNN` +0.00, `XTHRMQ` +0.10, `ZHX76S` −2.94) y **1 es deuda real en
dos monedas** (`XK6FV4`: US$ saldado y S/ 50 pendientes).

🔴 **Esto NO es la caja.** `PmsPagoMovimientoProvider` —lo que alimenta el arqueo de
`src/Finanzas/`— sigue diciendo S/ 223.70, que es el dinero que entró por la puerta. La imputación
es contable. Son dos verdades y ninguna sustituye a la otra: **no se «cuadra» la caja contra la
ficha.**

#### El cuadre, y por qué no decide nada

`pms_informacion_financiera.tipo_cambio` responde la pregunta del mostrador: *«te pago en soles lo
que falta, ¿cuánto es?»*. Con un solo cambio para la reserva entera sale un balance soles↔dólares
que en una reserva cerrada da ≈ 0.

```
cuadre = Σ ( (total_cargos − total_pagos)ₘ  convertido con el TC de la ficha )
```

No se guarda: se calcula al vuelo. Y **no sustituye a nada** — la contabilidad siguen siendo los
totales por moneda.

El umbral es `1.00` en la moneda de la ficha, y sale de los datos, no de una intuición: de los
tres cruces reales, dos quedan dentro (0.00 y 0.10 — redondeo de cambio) y `ZHX76S` queda fuera
con −2.94, que no es redondeo sino S/ 10 de más que alguien tiene que mirar. `XK6FV4` queda fuera
por diseño: son S/ 50 de deuda, no un residuo.

🔴 **El cuadre NO decide `pago-total`.** Esa decisión sigue siendo estricta por moneda, y no es
cuestión de gusto: `pago-total` → `confirmarPorPago()` → `ESTADOS_PAGO_CONFIABLES` → **se le abren
al huésped los códigos de acceso de la casa**. Un umbral no puede estar en esa cadena. Lo que hace
el cuadre es **proponerle al operador** que impute el cobro; el ledger se va a cero de verdad y
sólo entonces cambia el estado.

#### Cómo se calcula: `DELETE` + `INSERT`, no `UPSERT`

`PmsInformacionFinancieraRecalculoService` escribe **los dos modelos a la vez** mientras dure la
transición (`recalcularPorMoneda()` y `recalcularConvertido()`), para que revertir el código no
obligue a revertir la base.

El nuevo es un `DELETE` del chunk + `INSERT … SELECT` con tres ramas en `UNION ALL`:

1. **Cargos**, cada uno en su moneda. Sin conversión.
2. **Cobros que saldan lo suyo** — el 97 % — *más* los que declaran otra moneda pero no se puede
   honrar (sin TC o par no soportado). **Nunca se pierde un cobro.**
3. **Cobros imputados**, con `ROUND(…, 2)` **por fila**. Sin ese redondeo, 223.70 / 3.391 deja
   0.0013 de residuo y el saldo en dólares no llega nunca a cero exacto.

Tres decisiones que parecen detalle y no lo son:

- **`DELETE`+`INSERT` y no `INSERT … ON DUPLICATE KEY UPDATE`.** Es lo único que garantiza *por
  construcción* que no quede una fila fantasma. Con un upsert, borrar el último cargo en soles
  dejaría viva una fila `PEN 50.00 / 0.00` diciendo que se debe algo que ya no existe — y eso
  encadena hasta `PmsEstadoPagoEventosService`. La tabla es de una o dos filas por ficha:
  rehacerla es gratis.
- **Dentro de `$conn->transactional()`.** `postFlush` corre **fuera** de la transacción del flush
  (Doctrine hace commit y luego dispara el evento). Sin envolverlo hay una ventana de
  milisegundos en la que otra petición ve la ficha **sin ninguna fila** y el panel pinta ceros.
- **Las filas en `0.00` se conservan.** Nada de `HAVING`: una estancia directa nace con una línea
  a cero y es donde el operador teclea el precio (§12.0.1).

#### Dos lecturas, y cuál usar

| Para | Se usa | Nunca |
|---|---|---|
| El saldo de UNA ficha que se tiene en la mano | `PmsTotalesPorMoneda::de($info)` | leer decenas de fichas |
| Decenas de fichas de golpe (calendario) | `PmsFinanzasTotalMonedaRepository::totalesDe()` | leer una ficha recién tocada |
| Decidir en SQL (`pago-total`) | la tabla, directamente | — |

**El value object no es un duplicado por comodidad.** La tabla la escribe SQL crudo en `postFlush`,
así que dentro de la misma petición que acaba de añadir un cobro **puede ir un paso por detrás**.
Ese desfase ya tiene un bug latente: `RegistrarPagoSkill` y `RegistrarCargoSkill` releen
`$info->getSaldo()` tras el flush con el comentario «lo recalcula el listener», y no es cierto.
`PmsTotalesPorMoneda` suma desde `$info->getCargos()`/`getPagos()`, que el ORM sí tiene al día.

⚠️ **Es un ESPEJO del SQL de `recalcularPorMoneda()`.** Si dejan de decir lo mismo, el panel
enseñará un saldo y la decisión de `pago-total` tomará otro — y esa decisión encadena hasta los
códigos de acceso del huésped. Al tocar uno, tocar el otro.

Es a la vez un espejo **mejor** que el que sustituye: `expresionConvertida()` ↔ `aMonedaBase()`
convertían, y una divergencia ahí cambiaba cifras en silencio. Sin conversión, las dos fórmulas son
«agrupa y suma».

#### Gotcha: un cargo sin `tipo` cuenta como cargo

`PmsCargoFinanciero::$tipo` recibe su valor `'charge'` en **`prePersist`**
(`aplicarDefectosDeCargoManual()`). Una entidad recién añadida y todavía sin flush lo tiene a
`null`, y `esCargo()` devuelve `false` — o sea, **un cargo recién creado desaparecía de la suma**,
justo en el momento para el que existe el value object.

Los dos lados llevan ahora `COALESCE(tipo, 'charge')`. En la base no hay ni una fila con `tipo`
nulo (121 de 121 son `charge`) pero la columna lo admite, y dos criterios distintos sobre la misma
fila es exactamente lo que no puede pasar aquí.

> Lo cazó el **test unitario**, no PHPStan ni la sonda con datos reales: en la base el campo
> siempre está puesto, así que sólo se ve construyendo la entidad a mano. Es el primer argumento
> concreto para tener tests de esta pieza.

#### Estado de la transición

- ✅ Esquema en la base (`Version20260816010000`), pura adición. Las 317 fichas ya tienen su TC de
  cuadre (backfill con la venta del día en que se abrieron).
- ✅ Todos los registros nacen con tipo de cambio (§12.4.1b).
- ✅ Rollup por moneda, escribiendo los dos modelos.
- ✅ Lectura: `PmsTotalesPorMoneda` (una ficha) y `PmsFinanzasTotalMonedaRepository` (en lote),
  con **17 tests unitarios** — la primera cobertura del módulo financiero.
- ✅ Decisión de `pago-total` / `pago-parcial` (§12.9b).
- ✅ Panel del operador (§12.5.2), incluida la imputación de un cobro a otra moneda.
- ✅ Agente (`docs/Mensajeria.md`), calendario, enlaces de pago, depósito OTA y placeholders.
- ✅ Panel del huésped (`pax/`).
- ✅ **Ningún consumidor lee ya `total_cargos`/`total_pagos`/`getSaldo()`.** Sólo quedan dentro de
  la propia entidad y del recalculador, que los escribe mientras dure la doble escritura.
- ⏳ Retirada del modelo viejo: `DROP COLUMN total_cargos, total_pagos` y el rebase de moneda base.

> 🧪 Los últimos cuatro se encontraron con un `grep` de los tres métodos, no repasando la lista de
> tareas. Dos eran de fondo: `RegistrarPagoSkill` devolvía al operador el `saldo_real` **de antes
> del pago** —el comentario decía «lo recalcula el listener» y no es cierto: el rollup es SQL
> crudo y la entidad conserva sus escalares—, y `PmsPrepagoCalculador` preguntaba «¿hay pagos?» a
> un total convertido, así que **un cobro en soles sin tipo de cambio aportaba 0 y volvía a
> pedirle el adelanto a quien ya había pagado**.
>
> Y ejecutar `pms:reserva:auditar` destapó que **reventaba con cualquier reserva con pagos**: un
> `(string)` sobre el enum `PmsMedioPago`. PHPStan lo tenía cazado (`cast.string`) y **el baseline
> lo escondía** — el recordatorio de que no es una lista de perdonados.

#### El panel del huésped

Es la superficie más delicada porque el huésped **compara con sus recibos**. Quien pagó S/ 223.70
por Yape tiene que ver S/ 223.70; «US$ 65.97» es una cifra que no reconoce de ninguna parte.

| Qué | De dónde sale |
|---|---|
| Barra de progreso y titular «saldo pendiente» | El **cuadre** — son preguntas que sólo admiten una respuesta. Marcado con `≈` |
| Bloque «esta reserva tiene movimientos en dos monedas» | `porMoneda`, **sin convertir**: lo exacto de cada una |
| Líneas y pagos del detalle | Su propia moneda |

⚠️ **El conmutador de soles NO se ofrece con dos monedas.** El backend deja de mandar
`tipoCambioReferencial` en ese caso, así que el front ni lo pinta. Convertir una de las dos —o las
dos con un tipo que no es el suyo— produce una tarjeta que no cuadra consigo misma; y el huésped
ya tiene delante lo que pagó en cada una, que es justo lo que el conmutador venía a resolver
cuando sólo había una moneda.

Con **una sola moneda la tarjeta no cambia en nada**, conmutador incluido.

**Verificado** sobre los tres casos reales: `GASUNN` (PEN −223.70 / USD 65.97, cuadre 0.00),
`XTHRMQ` (PEN 176.90 / USD −52.00, cuadre +0.10) y `ZHX76S` (PEN −10.00 / USD 0.00, cuadre −2.94).

#### El calendario: una sola cifra, marcada cuando es aproximada

Decisión del dueño del producto tras ver la alternativa: enseñar «la moneda de mayor valor con un
`+`» deja **información parcial** —en `GASUNN` la pastilla diría `$66` y se callaría S/ 223.70—, y
una pastilla de calendario existe para responder «cuánto es esto» de un vistazo.

| Monedas | Qué se pinta |
|---|---|
| Una (313 de 317) | Su importe, igual que siempre. `convertido: false` |
| Dos | Todo llevado a la moneda de la ficha con su TC de cuadre, con `≈` delante |

El **detalle exacto va en el tooltip** (`totales`, sin convertir): lo aproximado se marca y la
verdad está siempre a un hover.

🟢 Y el color lo decide ahora el backend con `cuadra`, no un `saldo <= 0.005` en el front. Es lo
que impide que diez céntimos de redondeo del cambio pinten de rojo una reserva pagada.

Las cifras salen del value object sobre las cabeceras **ya cargadas en lote** por
`fetchFinanzas()`: leer `pms_finanzas_total_moneda` por reserva sería el N+1 que ese lote evita.

#### Un depósito de OTA POR MONEDA

`buscarAutomatico()` devolvía el primero y ya. Con cargos del canal en dos monedas, el segundo
depósito **no nacía nunca** y el primero recibía el importe de la otra — diciendo que la OTA
remitió algo que jamás remitió. Ahora es `buscarAutomaticos()` indexado por moneda, con creación,
ajuste y **borrado de los de monedas que ya no tienen cargos del canal** (mismo motivo que las
filas huérfanas del rollup). El candado de `intervenido` se respeta por depósito.

Con los datos de hoy es un no-op —cada canal factura en una sola moneda, verificado: 8 reservas,
un depósito cada una, todos USD—, lo que lo hace seguro de desplegar.

#### Enlaces de pago: uno por moneda

`FinOrigenCobroResolverInterface::resolver()` gana un `?string $moneda = null` **opcional**: no
rompe a los llamantes existentes ni al gemelo que implementará el módulo de tours. Con `null`
devuelve la moneda de mayor saldo — lo que ya hacía cuando sólo había una.

La pasarela es multidivisa y `FinEnlacePago` ya guardaba su propia moneda, así que la fontanería
estaba: el único que forzaba una sola era el resolver.

#### Placeholders de mensajería: autocontenidos

`{total_amount}`, `{paid_amount}` y `{balance}` pasan a ser cadenas completas con la moneda dentro
(`US$ 65.97 + S/ 50.00`). Y `{currency}` viaja **vacío cuando hay más de una**: una plantilla
escrita como «Debe {balance} {currency}» habría renderizado «Debe US$ 65.97 + S/ 50.00 USD».

> Auditado antes de tocarlo: de las **11 plantillas** en base, ninguna usa hoy ninguno de estos
> marcadores en ninguno de sus cuatro canales. El cambio de forma es seguro.

#### ⚠️ Tras desplegar la migración: `pms:finanzas:recalcular-totales`

La tabla se llena en el `postFlush` de cada ficha que se toca. Para la decisión de estado de pago
eso basta —el rollup corre inmediatamente antes, así que nunca decide con datos rancios— pero deja
**vacías las fichas que nadie ha vuelto a guardar**. Medido tras la migración: **58 de 317** con
movimiento y sin filas.

Al **calendario** eso sí le importa: lee la tabla en lote para pintar un mes, y una ficha sin filas
se pintaría sin cifras — no porque no deba nada, sino porque nadie la ha tocado.

El comando es idempotente por construcción (`DELETE` + `INSERT … SELECT` de lo que hay en las
tablas hijas) y tarda ~30 ms para las 317. Lleva `--dry-run`.

> 🧪 Su contador de «fichas sin totales» tiene que usar **el mismo criterio que el rollup**, no
> «¿tiene algún cargo?». Cuatro fichas anuladas con un cargo que no es penalización salían siempre
> como pendientes por más veces que se recalculara: cero filas **es** su resultado correcto (§12.7).

#### La imputación en el panel

El formulario de cobro ofrece marcar **«este cobro salda la deuda en X»** cuando el dinero entra en
una moneda donde no hay ningún cargo — la señal de que no puede saldar nada suyo. Enseña además
cuánto abonaría al cambio del propio cobro, antes de guardar.

Dos decisiones:

- **No se marca solo.** A veces esos soles son de verdad otra cosa: una propina, un extra que
  todavía no se ha cargado. Sin marcar, quedan como saldo a favor en su moneda, que también es
  cierto.
- **Sólo se propone con UNA candidata.** Con deuda en dos monedas distintas de la del cobro,
  elegir por el operador sería adivinar a cuál se aplica.

⚠️ El campo entra en la lista de `intervieneAlGuardar()`: reimputar el depósito automático de un
canal es cambiar a qué deuda se aplica, y eso lo saca del automático igual que cambiarle el
importe. Es **espejo** de `$camposBloqueados` en el listener — al tocar uno, tocar el otro.

#### Lo que desapareció del panel

| Se retiró | Por qué |
|---|---|
| El **conmutador de moneda** (`monedaVista`, `monedaAlterna`, `enMonedaContable`) | Convertía todo a soles o a dólares para mirarlo. Ahora cada moneda tiene su fila: no hay nada que conmutar |
| `totalesVista`, `importeVista`, `saldoVista`, `claseSaldoVista` | Recalculaban los totales en la moneda de la vista |
| El contador `sinConvertir` y el saldo pintado como `—` | Existían **sólo** porque un registro sin tipo de cambio desaparecía de la suma |
| `subtotalIncompleto` de cada grupo | Lo mismo, a nivel de estancia |
| `importeEnMoneda()` y `sumarEnMoneda()` de `pmsFinanzasModel.ts` | Su único consumidor era este panel |
| El equivalente `· ≈ …` de cada cargo | Un cargo se enseña en la moneda en que se pactó y en ninguna otra |

Lo que queda en su lugar: **una fila por moneda** en el resumen y en cada subtotal —de estancia y
de bloque de cobros—, y una **fila de cuadre** que sólo aparece con más de una moneda.

La única conversión que sobrevive en el panel es el **equivalente en vivo del formulario de
cargo** («son unos S/ 340 — el cargo se registra en USD»). Es ayuda de lectura mientras se teclea
y no toca ninguna cifra guardada; por eso se llama «equivalente» y no «total».

⚠️ **Deuda declarada:** `ReservaEnlacesPagoSection` sigue recibiendo el escalar viejo
`info.saldo`. Una pasarela cobra en UNA divisa, así que con saldo en dos monedas habrá que dejar
elegir en cuál se emite el enlace — es trabajo de la fase siguiente. Hasta entonces se emite en la
moneda de cotización, que es lo que ya hacía.

#### 12.9b La decisión de estado de pago, por moneda y con tolerancia

`PmsEstadoPagoEventosService` decidía con `(total_cargos - total_pagos) <= 0`, una resta de dos
escalares. Con los totales repartidos por moneda eso ya no existe: la condición pasa a una
subconsulta agregada (`subconsultaDeCuadre()`) que da, por ficha, si hay cargos, si hay cobros, el
**cuadre** y la **tolerancia**.

**El cuadre se convierte aquí, y sólo aquí.** La contabilidad no convierte, pero decidir «¿está
pagada?» exige *un* número. Se usa exactamente el mismo cuadre que ve el operador —los saldos de
cada moneda llevados a la de la ficha con su TC de cuadre—, para que el panel y esta decisión no
puedan discrepar.

#### La tolerancia: un suelo y una parte proporcional

```
tolerancia = 0                                      si NO hubo conversión
tolerancia = MAX(1.00, convertido × 0.1 %)          si la hubo
```

**El residuo no lo genera nuestro redondeo.** Convertir con `round(…, 2)` produce como mucho medio
céntimo. Lo genera que **el cambio del mostrador nunca es exactamente el de la ficha**: se paga a
la tasa del banco, de Yape o de la calle, y el cuadre usa la de SUNAT.

Ese error es **proporcional al importe que cruzó**. El tipo de cambio se guarda con tres decimales,
así que dos tasas plausibles del mismo día difieren en unos milésimos: sobre 750 dólares, 0.002 de
diferencia son 1.50 soles ≈ 0.44 USD. Con una constante de 1.00, una reserva de 750 pasa y una de
3.000 se queda colgada por dos dólares que no son error de nadie. De ahí el
`GREATEST(mínimo, convertido × proporción)`.

- `convertido` se suma en **valor absoluto**: importa cuánto dinero pasó por una tasa, no en qué
  dirección. Dos saldos de signo opuesto no cancelan su incertidumbre, la suman.
- **Sin conversión, tolerancia 0.** La holgura la concede el hecho de haber pasado por una tasa,
  no el tamaño de la reserva: quien deba 0.50 en una sola moneda debe 0.50, tenga la reserva 100
  o 5.000.
- ⚠️ El **0.1 % está calibrado con poca evidencia** —hoy sólo hay dos cruces con residuo, de 0.00
  y 0.10—, así que es un número a revisar cuando haya más casos. La **forma** (suelo +
  proporción) sí está fundamentada; el porcentaje es la parte que puede moverse.

Y es `cuadre <= tolerancia`, **no `ABS(cuadre)`**: un sobrepago deja el cuadre negativo y tiene que
contar como pagada, igual que hacía el `<= 0` de la versión vieja. Con valor absoluto, un huésped
que paga de más se quedaría en «parcial» para siempre.

⚠️ **«Pagada» y «no hay nada que mirar» no son lo mismo.** Un sobrepago está pagado —no se le puede
exigir nada al huésped— pero son S/ 10 suyos que están en nuestra caja. Eso lo señala
`PmsTotalesPorMoneda::haySaldoAFavor()`, que se mide contra la misma tolerancia: por debajo de ella
es redondeo del cambio y no hay nada que devolver.

Lo demás se conserva: `pago-parcial` **sólo asciende desde `no-pagado`** (nunca degrada un
`pago-total` ni pisa un `pago-alojamiento` puesto a mano), las estancias canceladas y las
extensiones quedan fuera, y `confirmarPorPago()` no se toca porque opera sobre `estado_pago_id`.

**Verificado** (16/08/2026, `var/probar-estado-pago-por-moneda.php`, en transacción con rollback):
sobre las 317 fichas, **0 estancias cambian de estado de pago** — la lógica nueva reproduce
exactamente la actual. Y `XTHRMQ`, que deja +0.10 de diferencia cambiaria, queda en `pago-total` en
vez de arrastrar un «parcial» eterno por diez céntimos.

> 🧪 Dos aclaraciones sobre el riesgo, porque la primera versión de este análisis lo exageró:
> - **`pago-parcial` ya está en `ESTADOS_PAGO_CONFIABLES`.** Los códigos de acceso de la guía se
>   abren con **cualquier** cobro, no con `pago-total`. La precisión hace falta para que el estado
>   sea cierto, no porque `pago-total` sea una puerta.
> - El caso «ficha anulada con un cobro» **no puede llegar a una estancia viva**: los dos `UPDATE`
>   excluyen `e.estado_id = 'cancelada'`, y hoy hay **0 fichas anuladas con algún evento sin
>   cancelar**. Los dos `EXISTS` se conservan igual —cuestan nada y mantienen «sin cargos no está
>   pagada»— pero no son lo que impide un desastre.

**Verificado** (16/08/2026, `var/probar-rollup-por-moneda.php`, en transacción con rollback):
recálculo de las **317 fichas en 50 ms**; las **50 de una sola moneda coinciden al céntimo** con
el modelo viejo —si no, el nuevo estaría tocando algo que no debía—; las 4 mixtas dan el desglose
esperado; al imputar el cobro de `GASUNN` a la deuda en dólares, **la fila en soles desaparece
sola** y queda sólo `USD 65.97 / 65.97` con saldo `+0.00`; y borrar una ficha con filas de totales
no revienta ni deja huérfanas.

> 🧪 Dos cosas que la sonda enseñó y conviene no repetir:
> - `Uuid::fromString()` **lanza `Invalid UUID` con 32 caracteres hex seguidos** — exige la forma
>   con guiones. Sobre una columna `BINARY(16)` va `Uuid::fromBinary()`, no `fromString(HEX(...))`.
>   Estaba mal en `PmsFinanzasTotalMonedaRepository` y lo cazó la sonda, no el análisis estático.
> - **No se puede probar la cascada borrando la ficha con SQL crudo**: `pms_cargo_financiero` y
>   `pms_pago_financiero` la referencian con `NO ACTION` —los cascadea el ORM— y el `DELETE` da
>   1451 con o sin la tabla nueva. Y tampoco por el ORM: hoy ninguna ficha con filas pertenece a
>   una reserva directa, y el listener de integridad veta borrar una de OTA. Hay que reproducir a
>   mano lo que hace el ORM (hijos primero) y comprobar lo que sí depende de la base.

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
importe anterior = 0.00
           → PERMITIDO   (no se registró ningún importe: no hay foto que romper)
```

**La excepción del importe en cero** (15/08/2026) es lo que hace usable la línea que estrena una
estancia directa (§12.0.1). Esa línea nace en `0.00` y en la moneda de la CABECERA —normalmente
USD— para que el operador escriba el precio acordado; y ese precio se cierra a menudo en soles.
Con el candado total, la única salida era borrar la línea y crear otra: cuatro clics para
corregir un campo que nunca había significado nada.

El candado dice «la moneda queda fija **al momento de registrar el importe**». Sin importe no hay
momento, y no hay nada fijado.

⚠️ **Se mira el importe ANTERIOR, no el que trae el PATCH.** El panel manda importe y moneda en
el mismo viaje —«350 soles»—, así que leer el importe ya mutado diría «tiene 350» y el candado
saltaría justo en el caso para el que se abrió la excepción. Lo resuelve
`importeAnteriorEnCero()`, que toma el valor viejo del changeSet cuando el importe también viene
en él.

Se exige que **todos** los importes que existan estén en cero: un cargo lleva `totalLinea` y
`monto`, un pago sólo `monto`. Con cualquiera con importe, ya hay dinero registrado.

**Verificado** (2026-08-15, `var/probar-moneda-cargo-en-cero.php`, en transacción con rollback):
cargo en 0.00 USD → PEN **pasa**; importe y moneda en el mismo guardado (que es como lo manda el
panel) **pasa**; un cargo que ya tiene importe **sigue bloqueado**.

> 🧪 Nota de método: en esa sonda el caso que BLOQUEA va el último a propósito. Una excepción
> dentro de `flush()` deja la unidad de trabajo sucia, y cualquier `flush()` posterior vuelve a
> intentar el cambio rechazado — así que lo que fuera detrás no mediría lo que dice medir. Se
> descubrió al ver el mismo `id` en dos resultados que debían ser de cargos distintos.

- **Por qué el candado es sólo sobre hijos, no sobre la cabecera:** la moneda de un cargo/pago está
  atada a un `monto` + `tipoCambio` capturados en ese instante — mutarla invalidaría esa foto. La
  moneda de la cabecera es sólo la *unidad de reporte* del rollup; cambiarla no toca ningún dato
  capturado, sólo hace que el rollup se re-exprese en otra moneda.
- **UX:** `PmsPagoFinancieroCrudController` deshabilita el campo `moneda` en la página de edición
  (`Crud::PAGE_EDIT`) para que el operador nunca choque con la excepción — el candado del listener
  es la defensa real, el campo deshabilitado es sólo higiene de formulario.
- **UX en el panel:** el formulario de edición de un cargo muestra el selector de moneda
  **habilitado sólo mientras el cargo valga 0.00**, y con la explicación en su «i» cuando no.
  La condición vive en `puedeCambiarMoneda()` de `ReservaFinanzasPanel.vue`, **espejo en TS** de
  `importeAnteriorEnCero()`. Si dejan de decir lo mismo, el panel ofrecerá un select que el
  guardado va a rechazar: al tocar uno, tocar el otro.
- **Grupo de serialización:** `moneda` está en `pms_cargo:patch` desde entonces. El grupo no
  puede ser el candado porque la regla depende del ESTADO de la fila, no del campo.
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

**Imputación a una estancia: la FK `evento` es LA clave, para todos los cargos.** Una reserva
puede tener varias casitas también siendo directa. Desde 2026-08-14 la imputación se **persiste**
en `PmsCargoFinanciero.evento` (FK nullable a `PmsEventoCalendario`) sea cual sea el origen del
cargo: en los manuales la elige el operador en el panel; en los sincronizados la resuelve
`Beds24InvoiceReceivePersister::eventosPorBookingId()` desde el `beds24BookingId` vía el link
**principal** (los espejos nacen sin `beds24BookId`, §6.3) — y la **reevalúa en cada sync**, así
el cargo sigue a su estancia en una mudanza de casita. Un bookId aún sin link (sync a medias) no
borra una imputación existente; el siguiente pull la corrige. `NULL` = cargo de la reserva en
conjunto. `Version20260814100000` hizo el backfill (99/99 en la copia local).

Antes los sincronizados sólo llevaban `beds24BookingId` y únicamente el panel sabía traducirlo
(`claveEstancia()` con el mapa de `getEstancias()`): cualquier otro consumidor —caja, agente, un
estado de pago por estancia— tenía que repetir la traducción o quedarse sin imputación. Esa
traducción del panel **se conserva sólo como respaldo** para filas que aún no pasaron por un
sync. El `<select>` de estancia sólo aparece si la reserva tiene más de una casita; con una sola
se preselecciona sin preguntar.

`ON DELETE SET NULL` en la FK: si se borra la estancia, el cargo sobrevive como cargo general en
lugar de irse con ella — es dinero registrado, no un dato derivado.

### 12.4.1b El tipo de cambio se sella SIEMPRE, en todos los registros

**Desde el 16/08/2026, ningún cargo, cobro ni ficha nace sin tipo de cambio** — coincida o no su
moneda con la de la ficha. Lo pone `PmsTipoCambioSnapshotListener` en `prePersist`.

> 🔴 **La cabecera se añadió en la revisión del mismo día, y es la mitad que se había escapado.**
> `Version20260816010000` rellenó las 317 fichas existentes, así que en local no se veía nada —
> pero **la ficha nueva nacía con `tipo_cambio` vacío**. Sin él, el cuadre descarta en silencio la
> moneda que no es la base (el `COALESCE(…, 0)` de `subconsultaDeCuadre()`): una ficha en soles
> con los soles saldados y dólares pendientes daba cuadre `0.00` y con él `pago-total`, que por
> `confirmarPorPago()` → `ESTADOS_PAGO_CONFIABLES` **abre los códigos de acceso de la casa a quien
> todavía debe**. No es un descuadre contable, es una regresión que ve el huésped.
>
> Hay dos líneas de defensa, y las dos hacen falta: el sello de aquí (que no haya fichas sin
> cambio de cuadre) y el guardia `monedas_sin_convertir` de `subconsultaDeCuadre()` (que aunque la
> haya, no se concluya «pagada»). Su espejo en PHP es
> `PmsTotalesPorMoneda::hayMonedaSinConvertir()`, que hace que `cuadra()` diga NO.

**Qué significa el campo, que no es lo que parece.** `tipo_cambio` **no** es «el número que hace
falta para convertir». Es **cuánto valía el dólar el día en que ese dinero se movió**: un hecho
histórico del registro, que sigue siendo cierto y sigue siendo útil aunque nadie convierta nada.
Con él se reconstruye cualquier cuenta a posteriori, se le puede decir a un huésped a cuánto se
le cambió, y se cuadra una reserva cobrada en dos monedas. Sin él esa información **no existe en
ninguna parte**: SUNAT publica la cotización de cada día, pero nadie sabe cuál se aplicó.

**Por qué un listener y no una llamada en cada creador.** Mismo argumento que
`PmsLimpiezaAsignacionListener`, y aquí está medido: hay **seis** sitios que crean registros
financieros —`PmsCargosAutomaticosService`, `PmsPagoOtaAutomaticoService`,
`Beds24InvoiceReceivePersister`, `RegistrarCargoSkill`, `RegistrarPagoSkill`,
`PmsReservaOrigenCobroResolver`— más el POST de la API, y **dos de ellos no lo sellaban**. Un
hueco así no se ve: el registro no da error, simplemente aporta 0 al total en la otra moneda y
desaparece (§12.2). Es lo que se destapó con HMN4BP8J25 —«en dólares cuadra y en soles no»— y lo
que dejó 6 registros históricos sin sellar.

- **Es un defecto, no una regla:** sólo escribe si el campo viene vacío. Quien registre el cambio
  que de verdad se aplicó —el del mostrador, que no siempre es el de SUNAT— manda. Es además lo
  que impide que choque con el candado de §12.4.2.
- **El día que se toma es el día del dinero:** en un cobro, `fechaPago` (un pago de hace tres días
  se registra hoy pero ocurrió entonces); en un cargo, hoy, que es su fecha de creación. Mismo
  criterio que `PmsCompletarTipoCambioCommand` — si cambia aquí, cambia allí.
- **Nunca rompe un guardado:** `TipoCambioDelDia::venta()` cachea en base, cae a SUNAT y de ahí al
  último disponible, y no lanza. Si aun así devuelve `null`, el registro nace sin TC y se persiste
  igual: un problema con la cotización no puede impedir anotar un cobro que ya se recibió.

**Verificado** (16/08/2026, `var/probar-sello-tipo-cambio.php`, en transacción con rollback): un
cargo persistido sale con la venta de hoy (3.371); un cobro con `fechaPago` de hace cuatro días
sale con la de **ese** día (3.372), no con la de hoy; y un registro que ya traía el suyo no se
pisa. Los 6 históricos se completaron con
`php bin/console pms:finanzas:completar-tipo-cambio --aplicar` → **0 registros sin TC** en las dos
tablas.

> Una sola prueba cubre los seis creadores a propósito: todos pasan por el mismo `persist`, y ése
> es justamente el motivo de poner el defecto en `prePersist` en vez de en cada uno.

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

#### ⚠️ Con registros sin convertir, el saldo NO se enseña

Un registro sin tipo de cambio no se puede convertir, así que en esa moneda queda **fuera** de la
suma. Los totales siguen siendo ciertos —son la suma de lo que sí se pudo convertir— pero el
**saldo no**: restar unos cargos incompletos de unos pagos completos (o al revés) da un número
que no significa nada, y se leía como deuda del huésped.

Caso real que lo destapó (reserva HMN4BP8J25, 15/08/2026): en dólares cuadraba y en soles no. El
depósito automático de la OTA se creaba **sin tipo de cambio**, así que desaparecía de la vista en
soles y el saldo salía por el importe entero del depósito.

Dos arreglos, y hacen falta los dos:

1. **Que no vuelva a nacer sin tipo de cambio.** `PmsPagoOtaAutomaticoService` ahora fotografía la
   cotización del día al crear el depósito (`TipoCambioDelDia::venta()`). Era el único creador de
   registros financieros que no lo hacía.
2. **Que no se enseñe un saldo que no se puede calcular.** `saldoVista` / `claseSaldoVista` en
   `ReservaFinanzasPanel.vue` pintan `—` en gris —con su `title` explicando por qué— siempre que
   `sinConvertir > 0`, en los dos sitios donde se pinta el saldo (la insignia de cabecera y la
   rejilla del drawer). El aviso de la franja lo dice también con todas las letras.

Un guion es peor que un número **sólo si el número es correcto**. Aquí no lo era.

Para los registros que ya existen sin tipo de cambio está
`php bin/console pms:finanzas:completar-tipo-cambio --aplicar` (§12.4.2).

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

`PmsPagoOtaAutomaticoService` crea y mantiene ese pago, dejando en **0** el saldo de lo que
cobró el canal:

| | Valor | Por qué |
|---|---|---|
| Importe | `getTotalCargosDelCanal()` — los cargos **del canal** (`esAutomatico`, §12.0.2c), convertidos a la moneda de la cabecera | El canal deposita **lo suyo**; los cargos manuales los paga el huésped a nosotros y tienen que mover el saldo |
| Fecha | Llegada **+ 1 día** | Es cuando la OTA libera el depósito |
| Medio | Transferencia bancaria | Es como entra |
| Moneda | La de la cabecera | Así no necesita tipo de cambio y no depende de §12.2 |

⚠️ **El importe NO es `totalCargos`, y el cambio está pagado.** El primer diseño cuadraba el
total entero de la cabecera, asumiendo "en una reserva de Airbnb todo lo cobra Airbnb". El caso
real que lo rompió (reserva V6WDDQ, 2026-08-14): estancia de Airbnb en dólares + **ampliación
directa en soles dentro de la misma reserva**. Cada cargo manual que el operador registraba lo
absorbía el depósito en el mismo flush —el saldo volvía a 0 hiciera lo que hiciera—, y registrar
el pago real del huésped dejaba la reserva en negativo. Con el alcance acotado a los cargos del
canal, una reserva OTA **pura** se comporta exactamente igual que antes (todos sus cargos son del
canal), y en una **mixta** los cargos manuales quedan pendientes hasta que se registra el pago
manual — verificado con `var/probar-deposito-canal.php` (transacción con rollback).

Los depósitos sobredimensionados que dejó la regla anterior **se corrigen solos** en el
siguiente recálculo de su cabecera (cualquier alta/edición de un cargo o pago de esa reserva).

⚠️ **No se genera al crear la cabecera**, aunque sea lo intuitivo: en ese momento la reserva aún
no tiene cargos (llegan después por webhook o por el pull de invoiceItems, §11) y el pago habría
nacido en `0.00`. Se sincroniza **después de cada recálculo**, que es cuando el importe ya se
conoce — y como al crearlo cambia `total_pagos`, el listener **vuelve a recalcular**.

**El depósito nace de SÓLO LECTURA.** Editarlo o borrarlo lanza `DomainException` con un mensaje
que dice dónde está la verdad: *corrige los cargos, que el depósito los sigue*.

Esa decisión salió de un fallo detectado al probarlo. El primer diseño desmarcaba el pago cuando
el operador lo editaba, para respetar su criterio. Resultado: al perder la marca, el sincronizador
no encontraba depósito automático y **creaba otro** — dos pagos y el saldo en −100. La raíz era
que un solo booleano intentaba significar dos cosas a la vez ("es el depósito del canal" y "lo
gestiona el sistema").

`referencia` y `notas` sí se pueden anotar: no afectan al saldo.

#### El candado se puede abrir: `intervenido`

El bloqueo total resolvía el 99% de los casos y dejaba sin salida el 1%: **la OTA que deposita un
importe distinto del que facturó** —un ajuste, una resolución de disputa, una penalización cobrada
aparte—. Ahí la única cifra real era justo la imposible de registrar.

La salida son **dos campos donde antes había uno**, que es la lección del fallo de arriba:

| Campo | Significa | Quién lo cambia |
|---|---|---|
| `esAutomatico` | **Identidad**: éste es el depósito del canal | Nadie. Es como lo encuentra el sincronizador para no crear un segundo |
| `intervenido` | **Gobierno**: el importe lo manda el operador | El operador, abriendo el candado del panel |

Con `intervenido = 1`, `PmsPagoOtaAutomaticoService::sincronizar()` **no toca nada** de ese pago:
ni el importe, ni la fecha, ni el borrado por quedarse sin cargos. Cualquiera de las tres cosas le
desharía la corrección en el siguiente recálculo, que es exactamente lo que hace inútil un campo
editable.

Cómo se abre y cómo se cierra:

- **Abrir**: el panel manda `intervenido: true` en el MISMO PATCH que el importe nuevo.
  `assertPagoAutomaticoNoEditable()` lee el estado **ya mutado** del pago
  (`isGestionadoPorElSistema()`), no el changeSet, así que no hacen falta dos viajes.
  ⚠️ Sólo lo manda si cambió algo que el sincronizador gobierna: `intervieneAlGuardar()` en el
  panel es **espejo de `$camposBloqueados`** en el listener, y hay que tocar los dos a la vez.
  Anotar la `referencia` o una nota nunca estuvo vetado y no debe sacar el depósito del
  automático — es una decisión demasiado grande para un comentario.
- **Cerrar**: `intervenido: false` **a secas**. Mandarlo junto a un importe se rechaza, y con
  razón: es pedir «devuélveme el control, pero con mi cifra». Al volver bajo el sincronizador, el
  recálculo de ese mismo flush lo cuadra contra los cargos del canal — o lo **retira**, si ya no
  queda ninguno. Ésa es la salida limpia de un depósito intervenido que sobra.
- **Borrar sigue vetado**, intervenido o no. El veto es por su IDENTIDAD: sin el pago, el
  sincronizador no lo encuentra y crea otro. Pero el MOTIVO que se enseña cambia, y no es
  cosmético: a uno intervenido el sincronizador ni se asoma, así que **quitarle los cargos no lo
  retira** —hay que devolverlo antes al automático—. `getMotivoNoBorrable()` da un texto para
  cada caso; con uno solo, el tooltip del basurero daba una instrucción que no funcionaba.

#### `TipoCambioDelDia` memoriza por día (28/08/2026)

No cacheaba nada: **cada llamada consultaba**, y `TipocambioManager` va a SUNAT cuando no
tiene la tasa del día. Con un consumidor por petición no se notaba; componiendo una lista
—20 reservas, cada una con su importe y su equivalencia con tarjeta— salían decenas de
consultas para pedir **el mismo número**. Se veía en el log: «Consulta mensual SUNAT vacía.
Intentando diaria», repetido. Medido con `pms:situacion-cobro --limite=20`: **6 consultas
antes, 1 después**.

La memoria es **por instancia, o sea por petición**, y guarda también el fallo: si SUNAT no
responde, reintentarlo cincuenta veces en la misma petición no lo arregla y multiplica la
latencia.

⚠️ **No es una caché con caducidad**, y no debe convertirse en una: la tasa ya vive en
`MaestroTipocambio`, y persistirla más allá de la petición sería inventarse una política de
invalidación para un dato que ya tiene su sitio.

#### El segundo pago que no se borra: el que vino de una pasarela (28/08/2026)

`getMotivoNoBorrable()` tiene desde el 28/08/2026 **dos** motivos, y conviene no confundirlos
porque se parecen en la pantalla y no en el fondo:

| Pago | Por qué no se borra |
|---|---|
| Depósito automático del canal (`esAutomatico`) | Porque **reaparecería solo**: el sincronizador no lo encuentra y crea otro |
| Cobro por enlace de pasarela (`enlacePagoId`) | Porque **la pasarela no se entera**: el dinero se movió fuera y aquí sólo hay su reflejo |

El segundo lo marca `pms_pago_financiero.enlace_pago_id`, que escribe
`PmsReservaOrigenCobroResolver::registrarCobro()` al crear el pago. Es una referencia **soft**
a `fin_enlace_pago`, sin FK, porque Finanzas es transversal y el PMS no le pone llaves a sus
tablas. El porqué de persistirla —la relación se resolvía en el frontend y bastaba mientras
sólo sirviera para pintar una etiqueta— está en `docs/FinanzasEnlacesPago.md`.

Borrar ese cobro dejaba el enlace en `PAGADO`, con su código de autorización, mientras la
reserva volvía a deber un dinero que el huésped sí había pagado. **Una devolución se anota
como un cargo aparte**, no borrando el cobro; el hueco que eso deja abierto (no hay estado
`REEMBOLSADO`) está en `docs/Pendientes.md`.

#### El 500 sin texto al borrar la reserva, y las dos puertas que lo arreglan

Los pagos van en `cascade: ['remove']`, así que un pago no borrable tumba el borrado de la
reserva entera. Eso ya pasaba con el depósito automático de las OTA, y **se veía como un 500
pelado**: el veto lo lanzaba el listener de coherencia desde `onFlush`, con la transacción
abierta, y a la pantalla no llegaba ningún motivo que enseñar.

Arreglado el 28/08/2026 por los dos lados que el operador toca:

| Puerta | Dónde | Qué consigue |
|---|---|---|
| **Antes de pulsar** | `PmsReserva::getMotivoNoBorrable()` ahora recorre también `informacionFinanciera->getPagos()` | `safeToDelete` va en `false` y la SPA no ofrece el botón; el campo ya viajaba serializado, así que el front no cambia |
| **Si se intenta igual** | `PmsReservaDeleteListener::preRemove()` suma los motivos de los pagos a los que ya reunía | Sale un `AccessDeniedHttpException` **con el texto dentro**, no un `DomainException` en mitad del flush |

⚠️ **`preRemove` y no el listener de coherencia**, aunque los dos vetan lo mismo. `preRemove`
corre en el `remove()`, antes de tocar la base, y lanza una excepción HTTP que la SPA sabe
leer. El de coherencia salta en `onFlush`, cuando el EntityManager está a punto de cerrarse —
y ése era el 500. **El veto de coherencia se queda igualmente**: protege el borrado de un pago
*suelto*, que no pasa por `preRemove`. Dos puertas para dos caminos, con la misma regla detrás
(`PmsPagoFinanciero::getMotivoNoBorrable()`).

Verificado con `var/probar-borrado-pago-enlace.php` (transacción con rollback): un pago manual
sigue borrable, el del enlace no, la reserva se declara no borrable **con su motivo**, el
intento devuelve `AccessDeniedHttpException` con el texto, y el pago suelto lo sigue parando
la coherencia.

⚠️ El script tiene un orden que no se puede cambiar a la ligera: **un flush rechazado cierra
el EntityManager**, así que todo lo que necesite seguir usándolo va antes de provocar el
rechazo (el último caso pide un manager nuevo con `resetManager()`; la transacción sobrevive
porque es de la conexión, no del manager).

La regla vive en **la entidad** (`PmsPagoFinanciero::isGestionadoPorElSistema()`), no repetida en
el listener, el servicio y la SPA: los tres la consultan, y el campo se serializa para que el
panel sepa cuándo pedir el candado. Verificado con `var/probar-deposito-intervenido.php`
(transacción con rollback): el veto sigue en pie sin intervenir, la edición pasa al intervenir, el
recálculo la respeta, no nace un segundo depósito y la marcha atrás vuelve a cuadrar.

⚠️ **Un depósito intervenido deja de avisar de los descuadres.** Es el precio de la válvula: si
después llega un cargo nuevo del canal, el saldo ya no se cuadra solo y nadie lo señala. Por eso
la fila lo marca en ámbar («Ajustado a mano») en vez de dejarlo indistinguible de uno automático.

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

### 12.4.6 Regularización de las reservas cobradas antes del módulo financiero

El módulo financiero es posterior a buena parte de las reservas, así que en ellas el hecho de
estar cobradas vivía **sólo** en `PmsEventoCalendario.estadoPago`. Al estrenar cargos y pagos
aparecían con saldo pendiente pese a estar cobradas, y ese saldo falso contamina el panel y las
variables de mensajería (§12.0.3).

`Version20260803210000` les registra un pago en **efectivo** por lo que falta, con fecha el
**último día del mes de llegada**, dejando el saldo en cero.

Dos criterios que conviene tener presentes si hay que repetir la operación:

- **"Pagada" = ninguna estancia fuera de `pago-total`.** El estado de pago vive en el evento, no
  en la reserva. Con una sola estancia —el caso normal— equivale a que esa estancia lo esté; en
  una reserva agrupada exige que lo estén **todas**, que es lo prudente.
- **Se inserta la diferencia `total_cargos − total_pagos`, no el total.** Una reserva con un
  abono parcial ya registrado habría quedado con saldo negativo. Por lo mismo, las OTA de pago
  total que ya cuadró `Version20260803190000` (§12.4.5) tienen diferencia 0 y no entran.

Estos pagos nacen con `es_automatico = 0`: son un dato histórico normal y el operador puede
editarlos. Se identifican por su `notas` para poder revertirlos en el `down()`.

## 12.5 Panel financiero en la SPA (`util/`) y patrón de Enums por AJAX

**El panel se refresca al guardar el drawer, no sólo al abrirlo.** El store ya recargaba tras
cada movimiento hecho *dentro* del panel (`finanzasStore.recargar()` en cada create/patch/delete),
pero el panel entero sólo cargaba al cambiar `props.reservaId` — y guardar las **estancias**
también mueve las finanzas en el backend: el cargo de un horario extra (§7.1.b) y el reajuste del
depósito de la OTA (§12.4.5).
Resultado: los totales parecían congelados («no es información viva») hasta cerrar y reabrir la
reserva. Ahora `ReservaFinanzasPanel` expone `refrescar()` —recarga datos SIN resetear acordeones,
candado ni moneda de vista, al contrario que `cargar()`— y `ReservaEditDrawer.guardar()` lo llama
en los dos caminos que dejan el drawer abierto.

**Dos candados, no uno.** `cargosDesbloqueados` protege los cargos sincronizados de Beds24;
`pagosDesbloqueados` protege **sólo** el depósito automático del canal, y su banner no se pinta
si la reserva no tiene ninguno (sería una advertencia sobre algo que no está en pantalla). Los
dos se cierran al cambiar de reserva y ambos, al cerrarse, abandonan la edición que hubiera en
marcha: si no, quedaba abierto un formulario que ya no se podía guardar y el operador se llevaba
el rechazo al pulsar. La diferencia entre ellos es que el de los cargos sólo desbloquea la
interfaz, mientras que el de los pagos **también cambia el dato** — guardar manda `intervenido`,
o el importe volvería a su sitio en el mismo flush (§12.4.5).

**El tipo de cambio se pide SIEMPRE, en cualquier moneda.** El campo aparecía sólo cuando la
moneda del registro no era la de la cabecera, y eso dejaba los registros **cojos**: el día que se
cambia la moneda base de la reserva (USD → PEN), todos los que se guardaron «en la moneda de
casa» se quedan sin TC, dejan de sumar y hay que ir uno por uno a repararlos.

Guardarlo siempre no tiene coste ni riesgo, y la razón está en el SQL: el TC de este módulo **no
es «moneda del registro → cabecera»**, es la venta **USD→PEN** del día.
`PmsInformacionFinancieraRecalculoService::expresionConvertida()` multiplica o divide según el
par y **lo ignora cuando las dos monedas coinciden** — o sea que un TC de más nunca deforma un
total, mientras que uno de menos sí lo rompe.

En el formulario: el campo va en alta y en edición de cargos y de pagos, rotulado «Tipo de cambio
(USD→PEN)», y se autocompleta con la cotización del día al abrir el formulario —también al editar
un cargo que se guardó sin él, que es justo el que hay que reparar—. Si el registro ya lo tenía,
se muestra **bloqueado**: es la foto del día y el backend rechaza cambiarlo (§12.4), aunque sí
permite rellenar el vacío.

**Reparar los que ya están cojos:**

```bash
php bin/console pms:finanzas:completar-tipo-cambio            # simula y lista lo que tocaría
php bin/console pms:finanzas:completar-tipo-cambio --aplicar  # escribe
```

Rellena cargos y pagos sin TC con la cotización **de su propia fecha** (`createdAt` en los
cargos, que no tienen fecha propia; `fechaPago` en los pagos), nunca con la de hoy: el TC es la
foto del día en que se movió el dinero. Arranca en simulación a propósito, es idempotente —sólo
toca los vacíos— y al hacer flush dispara el listener de coherencia, que recalcula las cabeceras.
Si para alguna fecha no hay cotización, salta ese registro y lo reporta en vez de inventarse un
valor.

Es un **comando y no una migración** por dos motivos: consulta un servicio externo (SUNAT), que
no pinta nada dentro de una migración, y hace falta cada vez que se cambia la moneda base de una
reserva, no una sola vez.

**Formularios abiertos y candado.** Tres reglas que salieron de usarlo:

- **«Guardar Cambios» del drawer arrastra los formularios internos.** Ese botón es mucho más
  visible que los pequeños de cada formulario, así que es el que se acaba pulsando:
  `ReservaFinanzasPanel::guardarPendientes()` (expuesto con `defineExpose`) guarda el cargo o el
  pago a medio escribir antes de cerrar. Los vacíos se ignoran, para que uno abierto por descuido
  no bloquee el guardado con un error de validación.
- **Los pies de los formularios son `sticky`** — alta de cargo, EDICIÓN de cargo y pago. El
  drawer scrollea y son formularios largos: con los botones al final se perdían de vista y el
  operador pulsaba «Guardar Cambios» de la reserva creyendo que guardaba el cargo.
- **Cerrar el candado cancela la edición en curso** de un cargo del canal. Si no, quedaba abierto
  un formulario que ya no se puede guardar: el botón seguía ahí y el operador se llevaba el
  rechazo. Los cargos MANUALES no se ven afectados, que nunca necesitaron candado.

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

Dentro: un resumen (Cargos · Pagado · Saldo, **una fila por moneda** desde §12.2b — nada se
convierte) y tres acordeones, **Cargos**, **Pagos** y **Enlaces de pago**.

El tercero se separó de «Pagos» el 28/08/2026; el porqué y la retirada de su `readOnly` están
en `docs/FinanzasEnlacesPago.md` §11 bis. En una línea: **Pagos es lo que ya entró; un enlace
es lo que se ha pedido y puede no entrar nunca**, y colgado al final de Pagos había que
recorrer el acordeón entero para llegar a él.

#### «Registrar pago», no «Cobrar» (28/08/2026)

Los dos botones del resumen —el del saldo de cada moneda y el de la fila de prepago— decían
**Cobrar**. Con la sección de enlaces al lado, esa palabra pasó a estar ocupada: ahí sí se
cobra de verdad, contra una pasarela. Estos dos **no mueven un céntimo**: abren la
confirmación del cobro rápido y **anotan** que el huésped ya pagó.

Dos detalles de maquetación que no son cosméticos, porque la etiqueta pasó de una palabra a
dos en un panel que se usa en móvil:

- En la celda de saldo (un tercio del ancho) el botón va **sin `tracking-wide` y con
  `leading-tight`**: envuelve en dos renglones antes que recortarse. Lo que no puede romperse
  nunca es la **cifra**, que por eso sigue en su propia línea encima.
- La fila de prepago pasó a `flex-wrap`: con la etiqueta larga de la política al lado, el
  botón se salía por el borde derecho.

⚠️ Y la **tira de confirmación** perdió el `shrink-0` que llevaba su grupo de controles. El
`<select>` de medio de pago mide lo que mida su opción más larga («Transferencia bancaria»), y
con la fila blindada contra el encogimiento los botones se salían de la pantalla — «Cancelar»
quedaba cortado por la mitad. **El panel no lleva `overflow-hidden`** (rompería los `sticky`
de los formularios), así que un desbordamiento aquí no se delata solo: no se ve una barra de
scroll, se ve un botón a medias.

#### El nombre de la casita no se recorta

La barra de estancia de **Cargos** pintaba `Casita 6 · 05/10/2026 → 11/10/2026` con `truncate`
en el título y `whitespace-nowrap` en las fechas. En un móvil el que cedía era **siempre** el
título: la casita quedaba en una «C». Y la casita es el dato que se busca ahí — las fechas ya
están arriba, en la estancia.

Ahora fluye: título y fechas se apilan cuando no caben y vuelven a la misma línea cuando sí
(`flex-wrap`, sin punto de corte por breakpoint — **el ancho aquí lo manda el drawer, no la
pantalla**). Se fue el `·` de separación: al envolver abría el renglón de las fechas y se leía
como una viñeta; el salto de tamaño y de color ya separa las dos cosas. Mismo criterio en las
cabeceras de bloque de **Pagos**, donde recortar «Depósito del canal» a «Depósito» diría otra
cosa.

**La regla que sale de aquí:** en este panel, `truncate` sobre un rótulo que identifica algo
—una casita, un bloque— es un bug esperando a un móvil. Se envuelve. `truncate` se queda para
lo que de verdad no cabe y no se lee, como la URL de un enlace de pago.

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

#### Jerarquía visual: cabecera, bloque, fila

Dentro de cada acordeón hay **tres niveles**, y se distinguen por sangría, no por color:

```
Cargos  (2)                                   US$ 129.18     ← sección  (px-4, fondo teñido)
  🔒 Habilitar edición  (i)                                  ← barra de estado
  🅐 Casita 3 · 12/08 → 14/08          US$ 129.18            ← bloque   (px-4, bg-slate-50)
      ALOJAMIENTO  Beds24                US$ 129.18          ← fila     (pl-6)
  🤝 Casita 6 · 14/08 → 16/08            US$ 0.00 (i)
      LIMPIEZA  Estancia directa · Casita 6    US$ 0.00
```

Con todo al mismo margen la barra de la estancia se leía como un **separador** y no como una
cabecera de la que cuelgan las filas de abajo. Los `pl-6` de las filas son lo que hace visible
que esos cargos son **de esa casita**, no de la reserva entera.

#### La sección de Pagos va en dos bloques, y el del canal arranca plegado

Los cobros no son todos la misma cosa:

| Bloque | Qué es | Estado inicial |
|---|---|---|
| **Cobros registrados** | Dinero que alguien recibió y anotó | Abierto |
| **Depósito del canal** | Lo que el sistema cuadra contra los cargos, no una decisión de nadie | **Plegado** |

Quien abre esta sección viene a ver o a registrar un cobro suyo. El depósito del canal ya está
cuadrado y no se toca —corregirlo es tarea de los CARGOS (§12.4.5)—, así que ocupar con él la
primera pantalla es gastar el sitio en lo único que no hay que mirar. Y como suele ser el importe
más grande, mezclado enterraba los cobros reales.

Los manuales van **primero** aunque el depósito sea mayor: el orden de un panel lo marca lo que se
va a tocar, no el tamaño de las cifras.

🔒 **El candado de pagos vive DENTRO del bloque del canal**, no arriba de la sección. Sólo protege
ese depósito; puesto fuera parecía que hacía falta para tocar cualquier cobro, y los manuales
nunca lo han necesitado. Al bloque hay que abrirlo para editar de todas formas, así que no
esconde nada. (El de **Cargos** sí se queda arriba: ahí protege cargos que pueden estar en
cualquier estancia.)

⚠️ El reparto usa `esAutomatico`, **no** `gestionadoPorElSistema`: un depósito ya intervenido
sigue naciendo del canal aunque su importe lo mande ahora el operador. Con la otra condición, el
cobro saltaría de bloque justo mientras se está trabajando con él.

El marcado de la fila **no está duplicado**: `bloquesPagos` construye la lista de bloques y el
`v-for` de la fila es uno solo. Una fila de cobro tiene ocho estados —enlace de pago, depósito,
intervenido, no borrable…— y dos copias serían dos copias que se separan.

#### La ayuda vive en una «i», no en una franja

Cinco franjas de texto explicativo llegaron a comerse la primera pantalla del panel. El criterio
que las ordenó, y el gotcha del hover en táctil que obligó a escribir un componente en vez de
usar `group-hover`, están en `docs/UI_Componentes_Compartidos.md` §3.e (`InfoTooltip`).

Lo que **sigue a la vista** es lo que exige actuar: «2 registros no suman: les falta el tipo de
cambio». Eso no es ayuda, es un problema de esta reserva.

#### Las plantillas sin resolver de Beds24 no se enseñan

Beds24 manda la descripción del cargo con sus propios marcadores y a veces **no los sustituye**:
lo que llega es `[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`, idéntico en todos los cargos de una
reserva agrupada. No es una descripción incompleta, es la plantilla en crudo — ocupa la línea
donde se busca de qué es el cargo y no dice nada.

`descripcionVisible()` (`pmsFinanzasModel.ts`) la oculta. Se detecta **por marcador**
(`/\[[A-Z][A-Z0-9]*\]/`) y no por el texto exacto: los marcadores de Beds24 son muchos
(`[GUESTNAME]`, `[BOOKINGID]`…) y una lista literal se queda corta con el primero que no
habíamos visto. Un corchete con MAYÚSCULAS dentro no es texto humano.

⚠️ **El dato NO se toca en la base.** Es lo que el canal mandó y el cargo sigue siendo suyo; esto
es presentación. Si algún día Beds24 empieza a resolverlos, la descripción aparecerá sola sin
migrar nada. Y no se pierde información: la casita y las fechas de ese cargo ya salen en la barra
de su estancia.

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

### 12.5.6 La descripción del cargo para el huésped: traducción automática y cómo rehacerla

`PmsCargoFinanciero::$descripcionCliente` es el único texto del módulo financiero que ve el
huésped redactado por nosotros (`$descripcion` viene de Beds24 y no es presentable). Es un
`I18nContent[]` con `#[AutoTranslate(sourceLanguage: 'es')]`: se escribe en español y el
traductor rellena los demás idiomas.

**⚠️ El atributo `#[AutoTranslate]` NO basta.** El campo estuvo sin traducirse nunca, en
silencio, por esto:

```
AutoTranslationEventListener::preUpdate()
    └── AutoTranslationService::processEntity($entidad)
            └── $execute = method_exists($entidad, 'getEjecutarTraduccion')
                           && $entidad->getEjecutarTraduccion();
                if (!$execute) return;   ← salía AQUÍ, sin mirar una sola propiedad
```

`getEjecutarTraduccion()` lo aporta `AutoTranslateControlTrait`, y `PmsCargoFinanciero` era la
**única** entidad del proyecto con `#[AutoTranslate]` que no lo usaba. Sin el trait el atributo
es decorativo y no hay error, ni log, ni excepción: se guarda el español y no aparece ningún
otro idioma. **Si añades `#[AutoTranslate]` a una entidad, añade también el trait.** Para
verificar que no vuelve a pasar:

```bash
for f in $(grep -rln "AutoTranslate(" src/ | grep Entity); do
  grep -q "use AutoTranslateControlTrait;" $f || echo "SIN TRAIT: $f"; done
```

**El flag de sobrescritura, y por qué hace falta un botón en la SPA.** El trait aporta dos
flags; el relevante aquí es `$sobreescribirTraduccion` (columna real, `Version20260810300000`):

| Valor | Comportamiento de `AutoTranslationService::translateAndCloneRows()` |
|---|---|
| `false` (defecto) | **Modo seguro**: traduce sólo los idiomas VACÍOS, respeta los que ya tienen texto |
| `true` | **Modo forzado**: retraduce desde el español, pisando lo que hubiera |

La consecuencia asimétrica: la primera vez que se redacta la descripción funciona sola, pero al
**corregir** el español de un cargo ya traducido el modo seguro hace `continue` sobre el inglés
—que no está vacío— y el huésped se queda viendo la versión vieja. `setDescripcionClienteEs()`
conserva a propósito las traducciones de los demás idiomas, así que no se auto-reparan.

Por eso el panel financiero tiene el botón <i>fa-language</i> junto al campo "Descripción para
el huésped", **espejo del que ya tenía el editor de cotizaciones** (`CotizacionEditorView.vue`,
sobre los mismos campos `#[AutoTranslate]`): pulsarlo manda `sobreescribirTraduccion: true` en
el PATCH y rehace los idiomas. El servicio lo **apaga solo** tras traducir
(`processEntity()`, bloque de auto-apagado), así que no se queda pegado ni obliga a traducir en
cada edición posterior.

Detalles de la implementación en la SPA (`ReservaFinanzasPanel.vue`):

- El botón sólo aparece en la **edición** de un cargo, no en el alta: en un cargo nuevo no hay
  traducciones que pisar y el modo seguro ya las crea todas. Un control que no hace nada es ruido.
- `cargoForm.sobreescribirTraduccion` arranca en `false` en `empezarEdicionCargo()` aunque el
  cargo venga con otro valor: forzar es una decisión de **este** guardado, no un ajuste del cargo.
- El campo viaja **sólo en el PATCH**, no en el POST.

Los grupos de serialización se declaran redeclarando el getter/setter en la entidad
(`#[Groups(['pms_cargo:read', 'pms_cargo:write', 'pms_cargo:patch'])]`), no en el trait: el trait
lo comparten media docena de entidades y cada una tiene sus propios grupos. Mismo patrón que
`CotizacionCottarifa::getSobreescribirTraduccion()`.

### 12.5.7 Asignar el cobrador desde la SPA: `cobradorId`, no una IRI

`PmsPagoFinanciero::$cobrador` es **quién recibió el dinero de manos del huésped** — no quién
registró el pago (ver la nota del campo: el efectivo lo cobra quien está en la casita y lo apunta
después otra persona). El panel lo expone como el desplegable "Lo cobró".

**⚠️ La relación `$cobrador` NO se serializa.** `User` no es un `ApiResource`, así que API
Platform no sabe convertirlo ni a IRI al leer ni desde IRI al escribir. Con los grupos puestos
sobre la relación —como estaba— el resultado era:

- **Al leer:** `cobrador: {}`, un objeto vacío (`User-pms_pago.write: Record<string, never>` en
  `api.d.ts`), porque `User` no tiene ninguna propiedad en esos grupos.
- **Al escribir:** imposible. No hay forma de nombrar a un usuario en el payload.

La solución es un par de accesores que hablan en **UUID plano**, el mismo que ya servía
`/tipo/user/enum/pms/cobradores` para el desplegable:

```
GET  /tipo/user/enum/pms/cobradores   →  [{ id: "<uuid>", label: "María Pérez" }, …]
                                              │
SPA: pagoForm.cobrador ────────────────────────┘
                                              ▼
POST/PATCH /platform/pms/pms_pago_financieros   { cobradorId: "<uuid>" }
                                              ▼
PmsPagoFinanciero::setCobradorId()   (buzón virtual, no mapeado)
                                              ▼
PmsPagoFinancieroProcessor::process()  →  UserRepository::find() → setCobrador(User)
                                              ▼
                        persist processor de Doctrine (decorado, no reemplazado)
```

Por qué cada pieza es como es:

- **Un processor y no un listener de Doctrine:** la entidad no tiene acceso al EntityManager, y
  el processor es el punto natural de API Platform. **Decora** el
  `api_platform.doctrine.orm.state.persist_processor` en vez de hacer el `flush()` a mano, para
  que los listeners de coherencia y el recálculo del saldo corran igual que en cualquier otra
  escritura (mismo patrón que `MessageMultipartProcessor`).
- **Se valida el ROL, no sólo que el usuario exista.** El desplegable ya viene filtrado, pero el
  endpoint lo puede llamar cualquiera con `RESERVAS_WRITE`, y un pago atribuido a quien no maneja
  caja rompe el cuadre sin dejar rastro. Responde `422` con el nombre de la persona.
- **`cobradorIdRecibido`** distingue "el PATCH no menciona el campo" (no se toca) de "lo manda
  vacío" (desasignar). Sin esa marca, cualquier PATCH parcial borraría el cobrador.
- **El `<select>` arranca vacío**, sin preseleccionar al operador con la sesión abierta: es
  justamente la confusión que el campo viene a evitar.
- **La lista NO se cachea** en el store, al contrario que los enums: son personas, y un alta
  reciente tiene que aparecer en el acto. Por eso `fetchCobradores()` vive fuera de `fetchEnums()`
  y sin el guardia de `enumsLoaded` — el backend tampoco le pone `setSharedMaxAge`.
- **Tampoco se filtra por `enabled`**, ni aquí ni en el backend: la limpiadora que cobra el
  efectivo en la casita no necesita login, y habilitarla sólo para poder nombrarla en un
  desplegable le daría acceso al panel. Ver `Roles::COBRADOR`.

🪞 **Tres sitios con el mismo criterio de "quién puede cobrar"**, y si cambia uno cambian los
tres: `PmsEnumAjaxController::getCobradores()` (el desplegable),
`PmsPagoFinancieroProcessor::resolverCobrador()` (la validación al escribir) y
`RegistrarPagoSkill::cobradoresPosibles()` (por donde lo registra el agente). Los tres miran el
rol **literal** de la columna `user.roles`, sin la jerarquía de `security.yaml`.

En la lista de pagos el nombre se pinta desde `cobradorNombre` (propiedad derivada, ya existente),
que expone sólo el nombre en vez de arrastrar el `User` entero con su email y sus roles.

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

> 🔥 Esto no es una rareza de API Platform: es la misma trampa en cualquier `setParameter()` del
> QueryBuilder, y no estar barrida costó caro. La barrera de idempotencia de las colas de
> mensajería la tenía dentro, contaba **0** colas existentes siempre, y fabricaba una cola nueva
> en cada `preUpdate`: 23 huéspedes recibieron el mismo mensaje entre 2 y 6 veces durante cinco
> meses. El barrido completo —qué estaba roto y con qué síntoma— está en **docs/Mensajeria.md
> §7, «En DQL, pasar la ENTIDAD como parámetro…»**. Si escribes una consulta nueva por
> asociación, el tipo `UuidType::NAME` no es opcional.

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

## 12.9 El estado de pago de las estancias se deriva de la cabecera

El operador registra el dinero en **un** sitio (cargos y pagos de la cabecera), pero quien
decide qué ve el huésped es el `estadoPago` de cada `PmsEventoCalendario`. Mantener los dos a
mano era el olvido más frecuente, y no es un olvido inocuo: `pago-parcial` y `pago-total` están
en `ESTADOS_PAGO_CONFIABLES`, que es **lo que abre los códigos de acceso de la guía**
(`docs/PmsGuiaHuesped.md` §3). Un adelanto cobrado y no reflejado dejaba al huésped sin poder
entrar.

`PmsEstadoPagoEventosService::sincronizar()` lo deriva:

| Situación de la cabecera | Estancias pasan a |
|---|---|
| Hay pagos y queda saldo | `pago-parcial` |
| Hay pagos y el saldo es 0 (o negativo) | `pago-total` |
| Sin pagos, o sin cargos todavía | *no se toca* |

**Solo hacia adelante, nunca degrada.** Es una asimetría deliberada, la misma que ya tenía el
sistema («registrar un pago confirma la reserva, quitarlo no la des-confirma»):

- `pago-parcial` solo se escribe **sobre `no-pagado`**. Así un `pago-alojamiento` puesto a mano
  por el operador sobrevive a cualquier recálculo.
- `pago-total` sí pisa cualquier estado: si no queda saldo, la estancia está pagada entera.
- Quitar un pago **no** devuelve la estancia a `no-pagado`. Deshacer el acceso a la guía de un
  huésped que ya está dentro por un ajuste contable sería peor que el problema que resuelve.

Dos decisiones de implementación:

- **Va en SQL, no por el ORM.** Se ejecuta desde el `postFlush` del listener de coherencia,
  donde tocar entidades gestionadas obligaría a un flush anidado. Mismo patrón que
  `PmsInformacionFinancieraRecalculoService`.
- **Es el ÚLTIMO paso del `postFlush`**, después del recálculo y del depósito automático de las
  OTA (§12.8): lee `total_cargos`/`total_pagos`, así que todo lo que los mueva tiene que haber
  terminado antes. En una reserva de Airbnb, el depósito automático deja el saldo en cero y por
  tanto sus estancias quedan en `pago-total` solas.

### 12.9.a ⚠️ La invariante: no puede haber una estancia `pendiente` con el dinero cobrado

**Nunca debe existir un `PmsEventoCalendario` en `pendiente` con `pago-parcial` o
`pago-total`.** No es cosmética: un estado de pago confiable es lo que abre los códigos de
acceso de la guía, así que la contradicción significa que el huésped tiene sus códigos mientras
el calendario dice que su estancia está sin confirmar.

En producción llegó a haber **18 estancias así**, 16 de ellas de OTA.

#### La causa: el canal decía `new` y nosotros no podíamos contradecirle

Beds24 deja las reservas de **Booking en `new` indefinidamente** —no las asciende a `confirmed`
como sí hace con Airbnb—, y `BookingPullPersister::resolveEstado()` mapea `new` → `pendiente` en
cada ciclo del pull. El operador cobraba, la estancia pasaba a `confirmada`, y la siguiente
tanda la devolvía a `pendiente`.

Se vio en los datos: un pago registrado a las 15:18 y tres filas con el **`updated_at`
idéntico** de las 19:15:04 — la marca de una tanda del pull.

Y no se recuperaba sola, porque el canal nunca se enteraba de la confirmación: el push la
descartaba (§12.13). El bucle se cerraba sobre sí mismo.

#### El arreglo: dejar que el `confirmed` viaje al canal

La raíz no estaba en el pull sino en el **push**: el bloqueo de §12.13 estaba escrito como
«nunca confirmar» cuando su motivo era sólo «nunca confirmar **encima de una cancelación**».

Desde entonces `BookingsPushMappingStrategy` manda `status` también cuando la reserva pasa a
`confirmada` **y el canal todavía la tiene en `new`**. Beds24 se entera, el siguiente pull lee
`confirmed` y la estancia se queda confirmada sin que nadie vuelva a tocarla.

⚠️ **La guarda mira lo que dice el CANAL, no lo que decimos nosotros**: se compara contra
`PmsEventoCalendario::$estadoBeds24`, el último `status` que reportó Beds24. Si el canal ya la
canceló, el push no lleva `status` y la cancelación sobrevive.

#### Consecuencia práctica al sanear a mano

Un `UPDATE` directo en SQL **no sirve para arreglar estas filas**: no pasa por el UnitOfWork,
así que `Beds24BookingsPushQueueListener` no encola nada, el canal sigue en `new` y el siguiente
pull deshace el arreglo. Hay que confirmarlas **por el panel**, que es lo que dispara el push.

> Las 18 filas se pasaron a `confirmada` con SQL directo el 2026-08-10 y por eso hubo que
> repasarlas después por el panel. Si vuelve a aparecer alguna, la consulta está en la tabla
> de §13.

### 12.9.c ⚠️ El drawer NO manda `estado`/`estadoPago` que no se tocaron

El segundo camino por el que una estancia "volvía sola" a `no-pagado` no era el pull sino
**nuestro propio drawer** (detectado 2026-08-14, reserva V6WDDQ). El PATCH de la estancia
(`ReservaEditDrawer.guardar()`) mandaba el formulario completo, y `estado`/`estadoPago` son
justo los dos campos que el backend mueve POR SU CUENTA mientras el drawer está abierto:

```
1. Se abre el drawer            → el formulario carga estadoPago = no-pagado
2. Se registra el pago en el    → el saldo cuadra; PmsEstadoPagoEventosService pone la
   panel de finanzas              estancia en pago-total (+ confirmada)
3. «Guardar Cambios» (cerrar)   → el PATCH mandaba el no-pagado con que se ABRIÓ el
                                  formulario y pisaba el pago-total recién puesto
```

El síntoma es intermitente porque depende del orden: si el pago se guarda con el propio
«Guardar Cambios» (paso 2 y 3 juntos), el PATCH viejo viaja ANTES que el pago y el recálculo
posterior lo corrige. Sólo falla cuando el pago se registró con el botón del panel y el
guardado del drawer llegó DESPUÉS.

Ahora `estado` y `estadoPago` viajan **sólo si difieren del valor con que llegaron del
servidor** (`EventoEntry.estadoActualId` / `estadoPagoActualId`). La auto-confirmación local
por pago sigue viajando: muta `form.estado`, así que difiere del original. El resto del
formulario se sigue mandando entero: ningún otro campo del PATCH lo escribe el backend solo.

La contraparte del teléfono del huésped. Cuando entra un WhatsApp lo único que se tiene es el
número del remitente, y sin este campo no había forma de distinguir a un operador de un huésped:
**todos entraban por la puerta del huésped** (`AgentActor::huesped()`, acotado a una reserva).
`AgentActor::delEquipoPorChat()` existía desde el principio sin nadie que lo usara, precisamente
porque faltaba con qué reconocer a la persona.

**Mismo formato que `PmsReserva::$telefono`: dígitos con código de país, sin `+`** (`51987654321`).
No es un capricho de estilo — los dos se comparan contra el mismo remitente, y si uno se guardara
como `+51 987 654 321` y el otro como `51987654321`, la búsqueda fallaría **en silencio** y el
operador quedaría como desconocido sin que nada lo delate.

| Pieza | Quién | Nota |
|---|---|---|
| Normaliza al guardar | `UserIntegrityListener` | Gemelo de `PmsReservaIntegrityListener`; delega en `PhoneSanitizer` |
| Busca al que escribe | `UserRepository::findByTelefono()` | Sanea el entrante con el MISMO servicio antes de comparar |
| Muestra en el panel | `UserCrudController` | `PhoneExtension::formatPhone()`, igual que el CRUD de reservas |

Se sanea en el listener y no en el CRUD porque hay más puertas que el formulario (fixtures,
comandos, importaciones): el único sitio por el que pasan todas es Doctrine. Comprobado de punta
a punta: `+51 987 654 321`, `987 654 322` y `(51) 987-654-323` se guardan como `51987654321`,
`51987654322` y `51987654323`; y entrando por cualquiera de esas formas se resuelve la persona.

⚠️ **País por defecto `PE`**: `User` no tiene país y el equipo es de Cusco. Un número local de 9
dígitos se resuelve como peruano; uno en internacional se respeta, porque `PhoneSanitizer` sólo
aplica el defecto cuando falta el prefijo.

⚠️ **`unique`**: dos personas con el mismo número serían indistinguibles justo cuando hay que
decidir quién manda. Nullable, así que quien no lo tenga no estorba (MySQL admite varios NULL).

⚠️ **`findByTelefono()` NO filtra por `enabled`**: identificar no es autorizar. Quien decide qué
se puede hacer es el actor con sus roles; filtrar aquí dejaría a una limpiadora sin login —que sí
puede cobrar (§11.5.1)— como desconocida.

> 🔒 El número de WhatsApp **no se puede suplantar** —lo entrega Meta firmado con HMAC, con el
> remitente dentro de lo firmado—, así que como identidad es un factor de posesión verificado en
> cada mensaje. Lo que no cubre es la **transferencia**: SIM swap, móvil robado, WhatsApp Web
> abierto. Por eso el control del agente se escala con el daño en `NivelRiesgo` en vez de confiar
> en el canal. Ver `AgentActor::delEquipoPorChat()` y docs/Mensajeria.md §7.

**Falta cerrar el círculo:** nadie llama todavía a `findByTelefono()`. `AiConversationProcessor`
—la entrada real del chat— sigue construyendo siempre un actor de huésped. Ver §12 de
`Mensajeria.md`.

## 12.10 A qué número se llama: `getTelefonoContacto()`

Una reserva guarda **dos** números. Hoy los dos son móviles, así que la pareja
`telefono`/`telefono2` ya no dice cuál es el bueno — solo en qué orden llegaron. El operador
marca el suyo con `telefono2EsPrincipal`, y **todo** lo que contacta al huésped pasa por
`PmsReserva::getTelefonoContacto()`.

```
telefono2EsPrincipal = false  →  telefono  ?? telefono2   (comportamiento histórico)
telefono2EsPrincipal = true   →  telefono2 ?? telefono
```

El flag solo decide el **orden de preferencia**: si el preferido está vacío se cae al otro, para
que marcar como principal un número que luego se borra no deje la reserva incomunicada.

> **Por qué un método y no leer el flag en cada sitio.** El `telefono ?? telefono2` estaba
> copiado en **siete** lugares (vCard ×2, enlace de WhatsApp, `{{ guest_phone }}` de las
> plantillas, contexto de mensajería, menú del calendario y drawer). Añadir el flag sin
> centralizar habría sido añadir siete oportunidades de olvidarlo, y el que se olvidara seguiría
> llamando al número equivocado en silencio.

Consumidores (todos migrados):

| Dónde | Qué usa |
|---|---|
| `PmsReservaVcardController`, `PmsReservaCrudController::generarVcard()` | teléfono de la vCard |
| `PmsReservaWhatsappLinkController` | destinatario del enlace de WhatsApp |
| `PmsMessageDataResolver`, `PmsReservaMessageContext` | variable de plantilla |
| `ReservasView.vue` | menú contextual — lee `telefonoContacto` **ya resuelto** de la API |
| `ReservaEditDrawer.vue` | botón de WhatsApp — usa el espejo TS (ver abajo) |
| `PmsReservaCrudController` (EasyAdmin) | el campo «Teléfono / Acciones» resuelve el contacto en su `formatValue`, así que los botones del panel apuntan al mismo número |

**Ojo con el panel legacy**: sus botones los pinta
`templates/panel/pms/pms_reserva/fields/telefono_wa_vcard.html.twig`, que arma el `wa.me/` desde
el valor **crudo** del campo. Por eso el `formatValue` le pasa `getTelefonoContacto()` en vez del
`telefono` a secas — si no, el panel llamaría a un número distinto del que usan las plantillas y
el chat. La plantilla marca con una insignia `T2` cuando el número mostrado es el segundo.

**El envío real de WhatsApp usa un snapshot.** `WhatsappMetaSendEnqueuer` toma
`MessageConversation::$guestPhone` como fuente principal y solo cae al resolver
(`PmsMessageDataResolver::getPhoneNumber()`) si está vacío. Ese snapshot lo refresca
`MessageConversationFactory::upsertFromContext()`, al que se llega desde el `postFlush` de
`PmsReservaRecalculoListener` — que se dispara con **cualquier** update de `PmsReserva`, así que
cambiar el flag actualiza la conversación. Cuidado con las escrituras que esquiven Doctrine
(SQL directo, migraciones de datos): dejarían la conversación apuntando al número viejo.

**Dos sitios que NO siguen el flag, a propósito:**

- **`BookingsPushMappingStrategy`** manda `telefono`→`phone` y `telefono2`→`mobile`. Es un espejo
  fiel de los dos números guardados, no una preferencia de contacto. Si el push enviara el
  principal como `phone`, el siguiente pull lo escribiría de vuelta en `telefono` y acabaría
  **intercambiando los números** en cada ida y vuelta.
- **`PmsReservaIntegrityListener`** sanea los dos por igual; no elige ninguno.

**El pull resetea el flag.** `BookingPullPersister` reemplaza ambos números cuando `datosLocked`
está abierto; llegado ese punto «el segundo es el bueno» apunta a un número que el operador nunca
vio, así que vuelve a `false`. Con el candado cerrado no se llega ahí y su elección aguanta.

**Espejo PHP ↔ TS.** `telefonoContactoDe()` en `util/src/types/pmsReservaModel.ts` replica la
regla, y hace falta **solo** en el drawer: allí el botón de WhatsApp tiene que reflejar el
formulario *sin guardar* mientras el operador teclea y marca la casilla. Todo lo demás consume
`telefonoContacto` del backend. Si cambia la regla, se tocan los dos.

Migración: `Version20260804180000` (arranca en `false`, o sea el comportamiento anterior).

## 12.11 ⚠️ Gotcha: el `cascade: persist` de la cola de push y el evento a medio nacer

Síntoma, esporádico y con el flush entero abortado:

```
A new entity was found through the relationship 'App\Pms\Entity\PmsEventoBeds24Link#evento'
that was not configured to cascade persist operations for entity: Casita 2 | 19/09 - Reserva
```

La cadena que lo produce:

```
Beds24BookingsPushQueueCreator::enqueueForLink()
   └─ em->persist(PmsBookingsPushQueue)
        └─ cascade: ['persist']  ──►  PmsEventoBeds24Link      (PmsBookingsPushQueue#link)
                                        └─ ManyToOne SIN cascade ──► PmsEventoCalendario
                                             ¿entidad nueva todavía no gestionada? → 💥
```

**Por qué es intermitente.** El evento suele llegar gestionado porque la cascada de
`PmsReserva` ya lo alcanzó. Pero si el `onFlush` llega antes al listener de push que a esa
cascada, el link apunta a un evento que Doctrine considera desconocido y aborta. Depende del
orden de recorrido del UnitOfWork, no de los datos: por eso aparece una vez cada muchas.

**Cómo NO se arregla: añadiendo `cascade: ['persist']` a `PmsEventoBeds24Link#evento`.** Es lo
que sugiere el propio mensaje de Doctrine y es una trampa — de hecho el `cascade: ['persist']`
de `PmsBookingsPushQueue#link` (con el comentario *"para soportar Links nuevos en batch"*) fue
exactamente ese parche, y es la primera mitad de este bug. Encadenar cascadas empuja el
problema un nivel más arriba cada vez (evento → reserva → unidad…) y hace que cualquier grafo a
medio construir se inserte por sorpresa.

**Cómo se arregla:** garantizando el orden padre→hijo antes de encolar. En
`Beds24BookingsPushQueueCreator::enqueueForLink()`, justo antes del `persist()` de la cola, se
persiste el evento si no está gestionado. La guarda sólo actúa en el caso que hoy revienta: si
el evento ya está gestionado —lo normal— no hace nada.

Si vuelves a ver el error con OTRA relación en el mensaje, busca el `cascade: persist` que la
alcanza y aplica la misma receta: asegurar el padre, no ampliar la cascada.

### 12.11.b La segunda causa del mismo error: el link ya borrado

El mensaje de arriba tiene **dos** orígenes distintos, y conviene no confundirlos. El de §12.11
es un evento que aún no ha nacido. Éste es justo el contrario: un evento que ya murió.

Al borrar una estancia, sus `PmsEventoBeds24Link` se van por cascada. Pero las colas
**históricas** —el `POST_BOOKINGS` en `success` que creó la reserva en su día— siguen
gestionadas y apuntando a ese link, y `PmsBookingsPushQueue#link` declara `cascade: ['persist']`.
En el siguiente flush Doctrine recorre esa asociación, alcanza el link borrado y desde él su
evento, ambos en estado `NEW`, y aborta.

«El siguiente flush» no es hipotético: `PmsReservaRecalculoService::recalcularDesdeEventos()`
hace uno dentro de su propio `postFlush`. Por eso el borrado de una estancia reventaba siempre,
no de forma intermitente.

Se arregla cortando la referencia antes de que el link desaparezca:
`Beds24BookingsPushQueueCreator::detachQueuesFromLink()`, que el listener llama tras encolar el
DELETE. La FK `link_id` es `ON DELETE SET NULL`, así que la base se arreglaba sola; el problema
era sólo el grafo en memoria. Ver §12.12.

## 12.12 Borrado de una reserva o de una estancia

Borrar es la operación con más piezas del módulo: intervienen las reglas de negocio, dos
cascadas de Doctrine, una FK que no estaba mapeada y el encolado del DELETE hacia Beds24. El
orden importa.

### 12.12.1 Las tres puertas

Una estancia sólo se borra si pasa `PmsEventoCalendario::getMotivoNoBorrable()`, que devuelve
el motivo legible o `null`:

1. **¿Es OTA?** → se cancela en el canal, no se borra aquí.
2. **¿Existe en Beds24 y no está cancelada?** → hay que cancelarla primero. Beds24 **sólo
   permite borrar reservas canceladas**, de ahí la asimetría.
3. **¿Hay cola en curso** (`processing` o con `locked_at`)? → se espera.

`PmsReserva::getMotivoNoBorrable()` delega en sus estancias: basta que UNA bloquee para vetar
la reserva entera. Los guardianes son `PmsEventoCalendarioSecurityListener::preRemove()` y
`PmsReservaDeleteListener::preRemove()`, que lanzan `AccessDeniedHttpException`.

> **Cómo distinguir un veto de negocio de un fallo técnico.** Si el panel muestra *"Este
> elemento no se puede eliminar porque otros elementos dependen de él"*, eso **no** es una
> regla tuya: es la etiqueta `entity_remove` de EasyAdmin, que sólo se emite al capturar un
> `ForeignKeyConstraintViolationException` (`AbstractCrudController::deleteEntity()`). Un veto
> de negocio se ve siempre con el texto `INTEGRIDAD BEDS24: …` o `NO SE PUEDE ELIMINAR LA
> RESERVA #…`. Ante la duda, el log de prod trae la constraint exacta.

### 12.12.2 El flujo completo

```
em->remove(PmsReserva)
  │
  ├─ cascade remove ──► PmsInformacionFinanciera   (OneToOne, §12.12.3)
  │                       ├─ cascade ──► PmsCargoFinanciero
  │                       └─ cascade ──► PmsPagoFinanciero
  ├─ cascade remove ──► PmsReservaHuesped
  └─ cascade remove ──► PmsEventoCalendario
                          └─ cascade remove ──► PmsEventoBeds24Link
                                                  │
   onFlush (prio 200) ── Beds24BookingsPushQueueListener ──────────┘
     ├─ collectDeleted()      los links caen en $linksDeleted
     ├─ resolveTasks()        action = 'DELETE' (prioridad absoluta)
     ├─ ensureBookIdForDelete()   recupera el bookId de colas viejas si hace falta
     ├─ cancelPendingPostForLink()  mata el POST pendiente del link
     ├─ enqueueForLink(DELETE_BOOKINGS)
     │     └─ setLink(null) + snapshot beds24BookIdOriginal / linkIdOriginal
     └─ detachQueuesFromLink()   corta las colas HISTÓRICAS (§12.11.b)

   onFlush (prio -1000) ── PmsReservaRecalculoListener
     └─ descarta las reservas que se están borrando (§12.12.4)

   postFlush ──► dispatch RunExchangeTaskDispatch('bookings_push')
```

La cola de DELETE se desengancha del link **a propósito**: el link desaparece en este mismo
flush, y guardar el `beds24BookId` y el `linkId` como snapshot es lo que permite al worker
completar la llamada cuando ya no queda nada a lo que apuntar.

### 12.12.3 Por qué `PmsInformacionFinanciera` es `OneToOne`

Fue durante mucho tiempo un `ManyToOne` **unidireccional** con `unique: true`, y `PmsReserva`
no tenía el lado inverso. Consecuencia: Doctrine no sabía que esa fila existía, emitía el
`DELETE FROM pms_reserva` con la FK todavía apuntando y MySQL respondía

```
1451 Cannot delete or update a parent row: a foreign key constraint fails
(`pms_informacion_financiera`, CONSTRAINT `FK_2AFB3104D67139E8` FOREIGN KEY (`reserva_id`))
```

Como **toda** reserva tiene su cabecera financiera, ninguna reserva se podía borrar nunca desde
el panel, sin importar canal ni estado. El síntoma engañaba: las reglas de negocio daban verde
y el botón aparecía.

Hoy `PmsReserva::$informacionFinanciera` es el lado inverso con `cascade: ['remove']` y
`orphanRemoval`. La columna ya tenía índice único (`UNIQ_2AFB3104D67139E8`), así que el cambio
de mapeo **no arrastró migración**. El campo no lleva `#[Groups]`: existe sólo para cascadear,
no para serializarse.

### 12.12.4 El recálculo no debe correr sobre lo que ya no existe

`PmsReservaRecalculoListener` recoge la reserva de `getScheduledEntityDeletions()` sin
distinguir «cambió» de «desapareció», y en su `postFlush` lanza un segundo `flush()`. Sobre una
reserva recién borrada eso revienta con el error de §12.11.b. Por eso el listener descarta al
final de su `onFlush` las `PmsReserva` que están en la lista de borrado.

Se descarta **sólo** la reserva que desaparece: borrar una estancia de una reserva que
sobrevive sigue recalculando sus totales, que es justo lo que debe pasar.

### 12.12.5 El ciclo del borrado manual y el estado `synced_deleted`

`PmsEventoBeds24Link::$status` es editable desde el panel, así que un operador puede poner un
link en `pending_delete` a mano. `Beds24BookingsPushQueueListener::isLinkBeingDeleted()` lo
trata como DELETE aunque el link no se esté borrando físicamente.

En ese camino el link **sobrevive** a la operación, y hay que cerrarle el ciclo:
`BookingsPushHandler::handleSuccess()` lo pasa a `synced_deleted` cuando la tarea era un
borrado (`PmsBookingsPushQueue::esBorrado()`). Sin esa transición el link se quedaba en
`pending_delete` para siempre y **cada flush posterior que lo tocara volvía a encolar el mismo
DELETE** contra Beds24.

`synced_deleted` es el estado terminal, y es el que ya excluían
`PmsBeds24MessageTargetFinder`, `PmsBeds24InvoiceTargetFinder` y `Beds24BookingsPushJob`: esos
filtros existían desde antes esperando este cableado.

| Camino | ¿Sobrevive el link? | Quién dispara | Quién cierra |
|---|---|---|---|
| Cascada (se borra el evento) | No | `collectDeleted()` desde el UnitOfWork | el propio borrado físico |
| Manual (`pending_delete` en el panel) | Sí | `isLinkBeingDeleted()` por estado | `handleSuccess()` → `synced_deleted` |

### 12.12.6b La carrera de la cancelación (y por qué el frontend espera)

`isSafeToDelete()` responde «¿está permitido borrar?», **no** «¿conviene borrar ahora?». En
cuanto la estancia pasa a «cancelada» entra en `ESTADOS_BORRABLES_CON_ID` y el motivo
desaparece, aunque el push de esa cancelación siga en cola: `getMotivoNoBorrable()` sólo mira
colas en `processing` o con `lockedAt`, y una `pending` no bloquea.

Borrar en esa ventana pierde la carrera:

```
1. El operador cancela la estancia      → se encola POST {status: cancelled}   [pending]
2. safeToDelete pasa a true             → el botón de borrar aparece
3. El operador borra                    → cancelPendingPostForLink() MATA el POST del paso 1
                                        → se encola DELETE
4. Beds24 recibe el DELETE sobre una reserva TODAVÍA CONFIRMADA  → lo rechaza
   ⇒ la reserva se queda viva en el canal bloqueando esas noches
```

Por eso `getSyncStatus()` y `getSyncStatusAggregate()` están serializados (`pms_evento:read` /
`pms_reserva:read`): el frontend exige además `synced` o `local` antes de ofrecer el borrado, y
mientras tanto muestra «Esperar y eliminar» con un sondeo. La regla espejo vive en
`util/src/types/pmsReservaModel.ts` → `puedeBorrarseYa()`, y el flujo de UI está en
«Borrado desde el drawer» de `docs/Calendar_architecture.md`. **Si cambias una, cambia la otra.**

Comprobado en datos reales: hay estancias con `safeToDelete=true` y `syncStatus=pending` a la
vez; ése es exactamente el estado peligroso.

### 12.12.6 ⚠️ Un DELETE sin `beds24BookId` se descarta

Si al encolar el borrado el link no tiene `beds24BookId` y `ensureBookIdForDelete()` tampoco lo
recupera de sus colas, **no hay nada que borrar en Beds24** y la tarea se descarta. El PMS borra
su copia y la reserva sigue viva en el canal bloqueando esas noches.

Es un fallo silencioso con consecuencia externa, así que el listener lo registra como `error`
con el `link_id` y el `evento_id`. **Si ese mensaje aparece en el log, hay que retirar la
reserva a mano en Beds24**; no hay reintento posible, porque el identificador se perdió.

## 12.13 Push de estado a una OTA: sólo por intención explícita

`BookingsPushMappingStrategy` **manda `status` a una OTA no-espejo SÓLO cuando el operador
cambió el estado a propósito**, marcado por `PmsEventoCalendario::$estadoPushSolicitado`. En
cualquier re-push del cron —fechas, huésped, lo que sea— el `status` **no viaja**, así que un
estado viejo del PMS nunca pisa lo que diga el canal.

### 12.13.0 Por qué un flag, y no comparar contra `estadoBeds24` (2026-08-18)

**El huevo y la gallina.** Antes la decisión se tomaba comparando el estado a empujar contra
`estadoBeds24` —*el último `status` que trajo el PULL*— con `transicionOtaPermitida()`. Eso
ataba el PUSH a que el PULL estuviera fresco: si el pull se atrasaba o **se rompía** (como pasó
con el filtro `status` que no llegaba a Beds24, §4.x), el «desde» era falso, la transición salía
permitida, y **cada corrida del cron re-imponía el estado del PMS, resucitando cancelaciones que
el canal ya había hecho**. Una reserva cancelada en Booking volvía a `new` en Beds24 sola, una y
otra vez.

El flag rompe el ciclo: el push ya **no depende del pull**. Se manda el `status` cuando hubo
**intención local**, punto. Ciclo de vida:

```
edición LOCAL del estado (util/API)
  → SecurityListener (preUpdate) VALIDA que la transición sea legal (o lanza 403)
  → Beds24BookingsPushQueueListener (onFlush) pone estadoPushSolicitado = true  (si !isPull)
  → el push manda `status` (map)  ── y sólo entonces ──
  → BookingsPushHandler::handleSuccess() limpia el flag (en el link PRINCIPAL)
     · si el push falla, el flag se queda y reintenta
```

Un cambio venido del PULL no pone el flag (`!isPull`): eso viene del canal, no hay que
devolvérselo. El flag es **interno**: no está en ningún grupo de serialización, no toca contratos.

La **legalidad** de la transición la sigue decidiendo `PmsEventoEstado::transicionOtaPermitida()`
en el `SecurityListener` (abajo). El flag decide el *cuándo empujar*; la tabla decide el *qué se
permite*. Son cosas distintas y viven separadas a propósito.

---

Lo que sigue es la regla de **legalidad** (qué transiciones acepta el SecurityListener), no la de
push. **Excepción deliberadamente asimétrica: la cancelación siempre es legal.**

```
                        ┌──────────── lo que dice el CANAL (estadoBeds24) ────────────┐
PMS → canal:            new/request/confirmed      inquiry        cancelled    otro
  a pendiente                   ✔                     ✘               ✘          ✘
  a confirmada                  ✔                     ✘               ✘          ✘
  a requerimiento               ✔                     ✘               ✘          ✘
  a cancelada                   ✘                     ✔               —          ✘
  a abierto / bloqueo           ✘                     ✘               ✘          ✘
```

Los tres estados de una reserva **viva** —`pendiente`, `confirmada`, `requerimiento`— son
intercambiables entre sí: moverse entre ellos no le quita ni le da la habitación a nadie. Del
par `abierto`/`cancelada` no entra ni sale nada, salvo la flecha de limpiar consultas.

La regla la declara `PmsEventoEstado::transicionOtaPermitida()` y la aplican los tres sitios que
antes decidían por su cuenta (§12.13.b).

El fallo que motiva el bloqueo no puede darse en ese sentido: cancelar algo ya cancelado es
un no-op. Y sin la excepción, PMS y canal divergen **en silencio**: una consulta de Airbnb en
`abierto` que se cancela aquí vuelve a aparecer abierta en el siguiente pull programado,
porque Beds24 nunca se enteró. El operador la cancela una y otra vez sin efecto y acaba
creyendo que el PMS está roto.

Regla mental: **el PMS puede CERRAR en el canal, nunca abrir ni confirmar.**

> ⚠️ Alcance real: aplica a **cualquier** cancelación local de una reserva de OTA, no sólo a
> las consultas — el estado anterior no se conserva en la cola, así que no hay forma de
> distinguirlas al empujar. Si alguien cancela aquí una reserva confirmada de Airbnb, ahora
> sí se cancela en el canal. Es lo coherente (antes divergían sin avisar), pero es una acción
> con consecuencias para el huésped.

El literal vive en `BookingsPushMappingStrategy::BEDS24_CANCELLED`, espejo de
`pms_evento_estado.codigo_beds24` para `cancelada` (`abierto` → `inquiry`).

### 12.13.b Una sola regla para los tres sitios que la aplicaban por su cuenta

El bloqueo estaba escrito como «nunca confirmar», y era **más ancho que su motivo**. El daño
concreto es confirmar *encima de una cancelación*; entre los tres estados vivos no hay nada que
revivir.

Mientras estuvo cerrado, una estancia cobrada no podía decírselo al canal: Beds24 seguía en
`new`, el pull la devolvía a `pendiente` en el ciclo siguiente y quedaba `pendiente` con
`pago-total` — el huésped con los códigos de la guía abiertos y el calendario diciendo que su
estancia no estaba confirmada. Llegó a haber 18 así (§12.9.a).

La regla vive ahora en **`PmsEventoEstado::transicionOtaPermitida()`**, y la consultan:

| Quién | Para qué |
|---|---|
| `PmsEventoCalendarioSecurityListener` (REGLA 4) | bloquear la mutación local |
| `BookingsPushMappingStrategy` | decidir si el `status` viaja al canal |
| `pmsReservaModel.ts::filtrarEstadosDisponibles()` | qué ofrece el desplegable de `util` |

#### 🔥 Lo que destapó cruzarlos

Los tres decidían por separado y **no coincidían**. La comprobación cruzada
(`var/verificar_transiciones_ota.php`, una matriz 6×6) encontró tres agujeros:

| Transición | Regla | Listener | Desplegable |
|---|---|---|---|
| `abierto` → confirmada/pendiente/requerimiento | ✘ | **permitía** | ✘ |
| `bloqueo` → los tres vivos | ✘ | **permitía** | **ofrecía** |
| `cancelada` → los tres vivos | ✘ | ✘ | ✘ *(ya estaba bien)* |

El del listener venía de que **sus tres reglas miran el estado DESTINO**, y en `abierto →
confirmada` el destino es inocente: el culpable es el origen. No se veía por el panel —el
desplegable sí lo bloqueaba— pero la API y la consola llegaban.

Y un cuarto, en el push: `desdeCodigoBeds24('black')` devuelve `null`, y con el defecto
permisivo un `black → confirmed` viajaba al canal. **En una regla de seguridad el defecto tiene
que negar**, así que `transicionOtaPermitida(null, …)` es `false` — lo que además cubre
cualquier literal que Beds24 añada mañana.

#### El «desde» es el canal, no nosotros

🔑 El push compara contra `PmsEventoCalendario::$estadoBeds24`, el último `status` que reportó
Beds24. Si el canal ya la canceló, ninguna transición sale permitida, el `status` no viaja y la
cancelación sobrevive — que es exactamente lo que el bloqueo original protegía.

Queda la ventana entre el último pull y este push (~20 min): si el canal cancela justo ahí,
nuestro «desde» está caduco. Se acepta a sabiendas, con el mismo criterio con el que se aceptó
que la cancelación viajara.

⚠️ **Consecuencia: cancelar una reserva de OTA en firme ya NO viaja al canal.** El listener
tampoco deja hacerlo (`confirmada → cancelada` está bloqueado desde siempre en OTA). Las
cancelaciones de reservas en firme se hacen en el canal; lo único que se cancela desde aquí son
las consultas (`abierto → cancelada`), y un `DELETE`, que manda `cancelled` por definición y no
pasa por la regla.

## 12.14 Una estancia que se borra se lleva sus cargos

`pms_cargo_financiero.evento_id` es `ON DELETE SET NULL`. Hasta 2026-08-12, retirar una
ampliación de estadía borraba su estancia y **los cargos del tarifario que
`PmsCargosAutomaticosService` le había generado sobrevivían sin dueño**, sumando al total para
siempre y sin forma de saber de dónde salían.

En producción quedaron dos así: «Alojamiento» 130.00 y «Suplemento de limpieza» 15.00, del
2026-08-03, en una reserva de **Airbnb** que llega el 2026-09-11. Esa estancia ya tenía su
cargo real de Beds24 (163.42, ítem `154407400`), así que el alojamiento estaba cobrado **dos
veces** y la cabecera decía 308.42.

### Por qué nadie lo vio en cuatro meses

Porque el saldo salía a cero. `PmsPagoOtaAutomaticoService::sincronizar()` dejaba entonces el
depósito espejo igual a `getTotalCargos()` —desde 2026-08-14 sigue sólo los cargos **del canal**
(§12.4.5), así que hoy unos huérfanos locales como estos SÍ dejarían saldo visible—, de modo
que cuando los huérfanos inflaron el total
el depósito los siguió hasta 308.42. Dos errores que se compensan y un saldo aparentemente
correcto — la peor combinación para detectar nada.

### Cómo se descubrió

Tirando del hilo equivocado. La pregunta era si el agente debía callar `prepago_pendiente` en
un canal restringido; resultó que eso ya estaba bien (`PmsPrepagoCalculador` corta en
`CANAL_PAGO_TOTAL`), pero al comprobar la regla nueva de `ConsultarCuentaSkill` contra datos
reales aparecieron dos cargos de Airbnb sin marcar como espejo del canal.

El RAW de `pms_beds24_invoice_receive_queue` fue decisivo: Beds24 mandó **un solo ítem** y el
pull registró `imported: 1` y luego `skipped: 1` en cada corrida. El pull financiero nunca los
creó. Las descripciones literales («Alojamiento», «Suplemento de limpieza») sólo las produce
`PmsCargosAutomaticosService`, y el `ON DELETE SET NULL` explicó el `evento_id` vacío.

### El arreglo

- **`PmsInformacionFinancieraCoherenciaListener`** anota en `onFlush` los cargos MANUALES de
  cada `PmsEventoCalendario` que se está borrando, y los retira en `postFlush`. Se anota antes
  del borrado a propósito: después, el FK ya puso `evento_id` a NULL y la pista se perdió.
  Sólo los manuales — los de Beds24 los gobierna el sync, y `assertCargoBorrable()` tampoco
  deja borrarlos a mano.
- **`Version20260812260000`** limpia los dos que quedaron y cuadra cabecera y depósito espejo.
  Va en SQL crudo porque una migración no dispara listeners: borrar sólo los cargos habría
  dejado `total_cargos` en 308.42 y el saldo mintiendo en el otro sentido.

⚠️ Lo que NO se toca: los cargos locales **con estancia viva**. Son los ajustes que teclea el
operador —un «Descuento tipo de cambio» de −0.20 en una reserva de Booking, por ejemplo— y sí
deben verse.

## Borrar una reserva: qué lo impide y por qué se dice (21/08/2026)

`PmsReservaDeleteListener::preRemove()`.

### El fallo mudo

El 20/08/2026 a las 23:49 un `DELETE /platform/pms/pms_reservas/{id}` murió en MySQL con:

```
Cannot delete or update a parent row: a foreign key constraint fails
(`pms_conversacion_enlace`, CONSTRAINT `FK_D58944CED67139E8` FOREIGN KEY (`reserva_id`) …)
```

Una reserva cascadea casi todo lo suyo —eventos, huéspedes, cabecera financiera— **salvo el
enlace de conversación**: `PmsConversacionEnlace.reserva` no lleva `onDelete`, y la reserva ni
siquiera conoce sus enlaces (la relación es unidireccional). Como la excepción sale del
`commit()` de Doctrine, llegaba al panel como un 500 sin nada legible.

**El coste real:** el operador lo vio como «no borra y no dice nada», desmontó la reserva a mano
—primero el evento, luego la conversación— y se quedó una **reserva vacía sin eventos** que nadie
volvió a mirar. La encontró una auditoría al día siguiente.

### Avisa, no cascadea

La tentación es `onDelete: CASCADE` y que el enlace se vaya solo. Pero un enlace es la
pertenencia de un ASUNTO a la conversación de una persona, y llevárselo por delante sin decirlo
borra parte de lo que le pasó a ese cliente — lo contrario de «no se borra: se marca». Así que se
niega con el motivo y con el nombre de quién tiene el hilo:

> No se puede borrar la reserva UDKAY9: su conversación de chat con Natalia Rosso todavía la
> tiene como asunto — retírala primero desde el chat.

El nombre no es adorno: con 333 hilos de alojamiento, «tiene una conversación» obliga a ir a
buscar cuál.

### ⚠️ Doctrine llama a los HIJOS antes que al padre

`UnitOfWork::doRemove()` invoca `cascadeRemove()` **antes** que los listeners de `preRemove` del
padre —para no tener que inicializar proxies después—. Consecuencia práctica: si alguna estancia
está bloqueada, quien contesta es `PmsEventoCalendarioSecurityListener` y esta guarda **ni se
ejecuta**.

Medido contra producción: borrar una reserva de OTA devuelve el mensaje de aquél; borrar un
bloqueo con hilo devuelve el de éste. O sea que el motivo que esta guarda aporta de verdad es el
de la conversación — que es el único que no vigila nadie más.

Verificación: `var/probar-borrado-reserva.php`, en transacción con `rollback`. ⚠️ Busca una
reserva con estancias borrables **y** conversación; con otra mide el listener equivocado.

### ⚠️ Un respaldo con `json_encode()` de filas del PMS sale VACÍO

Al borrar la reserva fantasma `6PN5SH` (21/08/2026) el script guardó primero una «copia de
seguridad» de las filas. **Pesaba 0 bytes.**

`json_encode()` devuelve `false` sobre las columnas `BINARY(16)` de los UUID —no son UTF-8
válido— y `file_put_contents($ruta, false)` escribe la cadena vacía **sin quejarse**. El fichero
existe, tiene nombre de respaldo, y no contiene nada. Se descubrió después de borrar.

Si vas a respaldar filas de este esquema antes de tocarlas:

- **`mysqldump --where` de las tablas implicadas**, que es lo único que conserva los binarios; o
- si de verdad hace falta JSON, envolver los binarios (`LOWER(HEX(id))` en el `SELECT`) y
  **comprobar que `json_encode()` no devolvió `false`** antes de escribir.

Y en cualquier caso, **verificar el tamaño del fichero** antes de dar el borrado por seguro. Un
respaldo que no se comprueba no es un respaldo: es un nombre de fichero.

Aquí no costó nada —la fila estaba vacía, verificado antes de borrarla— pero el mismo script
sobre una reserva con contenido habría dado la misma falsa tranquilidad.

## 13. Dónde tocar para cambiar X

| Necesidad | Archivo | Método/Campo |
|---|---|---|
| Cambiar cómo se normaliza un nombre que llega en mayúsculas | `NombreSanitizer` | `formatear()` — sólo actúa si NO hay ninguna minúscula; se llama desde `BookingPullPersister::upsert()` |
| Cambiar cuándo se revisa si nombre y apellido vienen cruzados | `OrdenDelNombre` + `PmsNombreOrdenListener` | `mereceRevision()` / `esNuestroIntercambio()` — el corta-bucles |
| Cambiar el criterio con que el modelo juzga el orden | `RevisarOrdenDelNombreDispatchHandler` | `reglas()` y `esquema()` — el modelo NUNCA devuelve el nombre |
| Cambiar cómo se deduce el país de una reserva de OTA | `BookingPullPersister` | `resolvePais()` — en Airbnb manda el teléfono, §3.3 |
| Corregir el país de las reservas YA guardadas | `PmsCorregirPaisOtaCommand` | `app:pms:corregir-pais-ota --dry-run` — idempotente, sólo con evidencia de teléfono |
| Deducir el país desde un número de teléfono | `PhoneSanitizer` | `paisDelNumero()` — sólo con prefijo internacional; `null` si no se sabe |
| Cambiar qué datos de huésped se empujan a una OTA | `BookingsPushMappingStrategy` | `mapGuestData()`, bloque `if (!$isOta)` — §7.2 |
| Cómo se coloca la noche que bloquea un horario extra | `PmsExtensionEstanciaService` | `sincronizar()` — §7.1.b |
| Qué estados puede imponerle el PMS a una OTA | `BookingsPushMappingStrategy` | bloque «4. ESTADO» — §12.13 y §12.13.b |
| Buscar estancias `pendiente` con pago cobrado | — | `WHERE estado_id='pendiente' AND estado_pago_id IN ('pago-parcial','pago-total')` |
| Que las extensiones dejen de ser invisibles en una vista nueva | — | la tabla de filtros de §7.1.b |
| Cambiar cómo nacen los cargos de horario extra (hoy en 0.00) | `PmsCargosAutomaticosService` | `sincronizarExtras()` |
| Cambiar ventana anti-dup | `Beds24WebhookController` | `600` (seg) y `15000` (ms) |
| Añadir o quitar un estado del pull de reservas | `BookingsPullMappingStrategy` | `ESTADOS_CONSULTADOS` — **repetido en la URL, nunca en el `payload`**: §4.1.b |
| Cambiar si el canal puede confirmar una estancia solo | `BookingPullPersister` | `resolveEstado()` — **lee §5.4 antes**: hoy NO confirma a propósito, y el `(int)$status === 0` que lo consigue parece un bug y no lo es |
| Añadir un parámetro multivaluado a cualquier GET de Beds24 | `BookingsPullMappingStrategy`, `Beds24InvoiceReceiveMappingStrategy` | montarlo en `fullUrl`; un array en el `payload` sale como `x[0]=` y Beds24 lo ignora |
| Tocar cascadas del grafo evento/link/cola de push | `Beds24BookingsPushQueueCreator` | `enqueueForLink()` — **lee §12.11 antes** |
| Que el refresco de tarifas deje de re-encolar lo idéntico (§8.1) | `Beds24RatesPushQueueCreator` | `enqueueForInterval()` — añadir dedupe por valor contra el último `success` de la unidad+fecha |
| Cambiar el paso/horizonte del barrido de tarifas (§8.1) | `Beds24RatesPushJob` | `getStepInterval()` (`P2W`) / `getHorizonteMaximo()` |
| Podar filas `success` viejas de una cola que creció (§2, §8.1) | `app:exchange:vigilar-colas` (cron min 25) | ahí va el `DELETE ... status='success' AND created_at < ...` |
| Cambiar cuándo se puede borrar una estancia (§12.12.1) | `PmsEventoCalendario` | `getMotivoNoBorrable()` — fuente única; `util` sólo tipa el campo serializado en `PmsBorrableInfo`, no reimplementa la regla |
| Cambiar qué se le pide a una reserva y por qué | `src/Pms/Finanzas/PmsSituacionDeCobroResolver.php` | el pipeline de `componer()` — ver `docs/Mensajeria.md` |
| Auditar esa decisión en producción | — | `php bin/console pms:situacion-cobro` (lista las fichas y marca las vacías) |
| Cambiar qué datos de cuenta ve el huésped tras la «i» | `src/Api/Provider/Pms/PmsReservaPaxProvider.php` | `conFichas()` — campo a campo, nunca la entidad |
| Cambiar cómo se pinta esa ficha | `pax/src/views/huesped/PmsReservaView.vue` | `fichaAbierta` / `fichasDelAbierto` / `titularComun` |
| Editar las cuentas en sí (número, banco, CCI) | — | el catálogo `FinMedioCobro`, desde el panel |
| Cambiar cuándo se puede borrar un PAGO | `PmsPagoFinanciero` | `getMotivoNoBorrable()` — dos motivos: depósito del canal y cobro por enlace (`enlacePagoId`) |
| Que el borrado de la reserva arrastre una tabla nueva (§12.12.3) | `PmsReserva` | declarar el lado inverso con `cascade: ['remove']`; una FK sin mapear = 1451 |
| Tocar el desenganche de colas al borrar un link (§12.11.b) | `Beds24BookingsPushQueueCreator` | `detachQueuesFromLink()` |
| Cambiar qué link conserva su `beds24BookId` al mover de casita (§6.3.b) | `PmsEventoCalendarioFactory` | `internalHydrate()` — rama de espejos y `$mueveDentroDelMismoVirtual` |
| Cómo se identifica el link principal sin ID remoto (§6.3.b) | `PmsEventoCalendarioFactory` | `internalHydrate()` — bloque B, `$esElPrincipal` |
| Cambiar qué se recalcula tras un borrado (§12.12.4) | `PmsReservaRecalculoListener` | `onFlush()` — bloque 5, descarte de reservas borradas |
| Cerrar el ciclo de un DELETE manual (§12.12.5) | `BookingsPushHandler` | `handleSuccess()` → `markSyncedDeleted()` |
| Cambiar cuándo la SPA ofrece borrar (§12.12.6b) | `util/src/types/pmsReservaModel.ts` **y** `PmsEventoCalendario` | `puedeBorrarseYa()` / `getSyncStatus()` — son espejo, hay que tocar **los dos** |
| Añadir canal de pago total | `PmsChannel` | `CANAL_PAGO_TOTAL` — también decide qué canales generan depósito automático (§12.4.5) |
| Cambiar el importe/fecha del depósito de OTA (§12.4.5) | `PmsPagoOtaAutomaticoService` + `PmsInformacionFinanciera` | `sincronizar()` / `fechaDeposito()` — el alcance del importe vive en `getTotalCargosDelCanal()` |
| Cambiar cuándo se puede editar el depósito automático (§12.4.5) | `PmsPagoFinanciero` | `isGestionadoPorElSistema()` — fuente única; la consultan el listener, el sincronizador y la SPA |
| Cambiar el mensaje del veto o los campos vetados (§12.4.5) | `PmsInformacionFinancieraCoherenciaListener` | `assertPagoAutomaticoNoEditable()` → `$camposBloqueados` |
| Cambiar cómo se abre el candado del depósito en el panel (§12.4.5) | `util/src/components/reservas/ReservaFinanzasPanel.vue` | `pagosDesbloqueados` / `puedeEditarPago()` / `devolverPagoAlAutomatico()` |
| Cambiar lógica de estado OTA | `BookingPullPersister` | `resolveEstado()` |
| Cambiar en qué estados NO se crean cargos del canal (§11.2.b) | `Beds24InvoiceReceivePersister` | `upsertCargos()` → la guarda de `CODIGO_ABIERTO`. ⚠️ No añadas `cancelada`: ahí llegan las penalizaciones |
| Cambiar estado de pago inicial | `BookingPullPersister` | `resolveEstadoPagoInicial()` |
| Cambiar cómo el saldo deriva el estado de pago de las estancias (§12.9) | `PmsEstadoPagoEventosService` | `sincronizar()` — las dos sentencias |
| Cambiar a qué número se llama (§12.10) | `PmsReserva` **y** `util/src/types/pmsReservaModel.ts` | `getTelefonoContacto()` / `telefonoContactoDe()` — son espejo, hay que tocar **los dos** |
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
| Saber a qué estancia pertenece un cargo (§12.4.1) | `PmsCargoFinanciero` | FK `evento` — la persiste el persister para los sincronizados y el operador para los manuales; `beds24BookingId`/`getEstancias()` son sólo respaldo |
| Cambiar cómo se resuelve la imputación de un cargo del canal (§12.4.1) | `Beds24InvoiceReceivePersister` | `eventosPorBookingId()` |
| Cambiar la agrupación de cargos por casita en la UI (§11.6) | `ReservaFinanzasPanel.vue` | `gruposCargos` / `claveEstancia()` |
| Imputar un cargo manual a una estancia (§11.5) | `PmsCargoFinanciero` | FK `evento` (`NULL` = toda la reserva) |
| Que un campo `#[AutoTranslate]` se traduzca de verdad (§12.5.6) | la entidad | `use AutoTranslateControlTrait;` — sin él el atributo es decorativo y falla en silencio |
| Cambiar cuándo se rehacen las traducciones de un cargo (§12.5.6) | `ReservaFinanzasPanel.vue` + `AutoTranslationService` | botón `toggleTraduccion()` → `sobreescribirTraduccion` → `translateAndCloneRows()` |
| Cambiar quién puede figurar como cobrador (§12.5.7) | `PmsEnumAjaxController` **y** `PmsPagoFinancieroProcessor` **y** `RegistrarPagoSkill` | `getCobradores()` / `resolverCobrador()` / `cobradoresPosibles()` — son espejo, hay que tocar **los tres** |
| Escribir una relación a `User` desde la API (§12.5.7) | la entidad + un processor | accesor `*Id` en UUID plano; `User` no es `ApiResource`, la IRI no funciona |
| Cambiar el hito que decide si una hora es «horario extra» | `PmsEstablecimiento` | `horaCheckIn` / `horaCheckOut` — se editan en el panel, no en código |
| Cambiar la alerta de noche ocupada al marcar horario extra | `AplicarCambioHorarioSkill` **y** `EvaluarCambioHorarioSkill` | `alertaDeOcupacion()` en las dos — si cambia una, cambia la otra |
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
| Volver a crear cargos al insertar una directa (hoy NINGUNO, §12.0.1) | `PmsInformacionFinancieraCoherenciaListener` | `onFlush()` — habría que recolectar las inserciones otra vez |
| Cambiar de qué día sale el TC que se sella al crear un registro (§12.4.1b) | `PmsTipoCambioSnapshotListener` | `sellarCargo()` / `sellarPago()` / `sellarFicha()` — los dos primeros son espejo del criterio de `PmsCompletarTipoCambioCommand` |
| Cambiar quién limpia por defecto en las estancias nuevas | Panel → **Usuarios** | Casilla «Limpia por defecto» (`User::$esLimpiezaPorDefecto`) — es un DATO, no hay ningún nombre en el código |
| Cambiar hasta cuándo se puede corregir la moneda de un cargo (§12.4) | `PmsInformacionFinancieraCoherenciaListener` | `importeAnteriorEnCero()` + espejo `puedeCambiarMoneda()` en `ReservaFinanzasPanel.vue` |
| Cambiar qué cobros arrancan plegados (§12.5.2) | `ReservaFinanzasPanel.vue` | `bloquesPagos` — el flag `plegable` |
| Añadir o mover un acordeón del panel (§12.5.2) | `ReservaFinanzasPanel.vue` | `SeccionFinanzas` + `toggleSeccion()` — cuerpo con `v-show`, nunca `v-if` |
| Cambiar la pastilla «N por cobrar» de Enlaces (§12.5.2) | `ReservaFinanzasPanel.vue` | `enlacesVigentes` — lee `enlace.vigente`, **no** el estado |
| Renombrar los botones del cobro rápido (§12.5.2) | `ReservaFinanzasPanel.vue` | `abrirCobroRapido()` — «Registrar pago» anota, no cobra |
| Dejar de ocultar una plantilla de Beds24 (§12.5.2) | `util/src/types/pmsFinanzasModel.ts` | `MARCADOR_BEDS24` en `descripcionVisible()` |
| Mover una ayuda de la pantalla a un tooltip | Vista | `<InfoTooltip>` — criterio en `UI_Componentes_Compartidos.md` §3.e |
| Cambiar cómo se suman los totales por moneda (§12.2b) | `PmsInformacionFinancieraRecalculoService` | `recalcularPorMoneda()` — **y su espejo** `PmsTotalesPorMoneda::de()` |
| Cambiar el umbral del cuadre (§12.2b) | `PmsTotalesPorMoneda` | `UMBRAL_CUADRE` — hoy 1.00, sacado de los tres cruces reales |
| Leer saldos de muchas fichas a la vez | `PmsFinanzasTotalMonedaRepository` | `totalesDe()` — nunca el value object en bucle |
| Cambiar cuándo una estancia pasa a `pago-total` (§12.9b) | `PmsEstadoPagoEventosService` | `subconsultaDeCuadre()` + los dos `UPDATE` |
| Repoblar los totales por moneda tras un despliegue | — | `php bin/console pms:finanzas:recalcular-totales` |
| Cambiar cuándo se ofrece imputar un cobro a otra moneda | `ReservaFinanzasPanel.vue` | `monedaAImputar` |
| Cambiar el desglose del tooltip de coste teórico (§12.0.1) | `PmsCargosAutomaticosService` | `costoTeorico()` + `ReservaFinanzasPanel.vue` |
| Cambiar cómo se escriben los cargos que el operador aprueba (§12.0.1) | `PmsCargosAutomaticosService` | `escribirAprobados()` |
| Cambiar la tarifa de limpieza de las directas | `PmsUnidad` | `precioLimpieza` / `limpiezaEsPorcentaje` (ficha de la casita) |
| Empezar a cobrar el servicio en las directas (§12.0.1) | `PmsCargosAutomaticosService` | `escribirAprobados()` — es la única puerta que escribe importes, y exige aprobación |
| Cambiar qué estancias estrenan cargos automáticos (§12.0.1) | `PmsCargosAutomaticosService` | `aplica()` (hoy: directas, sin bloqueos) |
| Cambiar cómo se calcula el alojamiento por noche (§12.0.1) | `PmsCargosAutomaticosService` | `preciosDeNoches()` → `TarifaPricingEngine::buildDailyPricesForIntervalWithFallback()` |
| Cambiar el relleno de días sin tarifa (§12.0.1) | `PmsCargosAutomaticosService` | `fallbackProvider` (hoy: tarifa base de la unidad) |
| Cambiar el orden horario-extra ↔ rollup (§12.0.1) | `PmsInformacionFinancieraCoherenciaListener` | `postFlush()` / `sincronizarHorariosExtra()` |
| Cambiar el desglose de importes por tipo (§12.0.2) | `PmsInformacionFinanciera` + `PmsInformacionFinancieraRecalculoService` | `getTotalPorTipo()` (es **espejo** del SQL: tocar los dos) |
| Añadir una variable financiera a las plantillas (§12.0.3) | `PmsMessageDataResolver` | `getMessageVariables()` **y** `getPreviewMessageVariables()` (Meta exige el `example`) |
| Cambiar el total que ve el motor de reglas del chat (§12.0.3) | `PmsReservaMessageContext` | `getFinancialTotal()` / `isFinancialCleared()` |
| Pasar la cabecera al contexto de mensajería (§12.0.3) | `Beds24ReceivePersister` + `PmsReservaRecalculoService` | 2º argumento de `new PmsReservaMessageContext()` |
