# Módulo de Mensajería — Conversaciones, Reglas y Colas

Cómo una reserva del PMS acaba generando un WhatsApp automático: quién dispara al motor de
reglas, qué garantiza y qué **no** garantiza cada disparador, y por qué el módulo está partido
en tres capas que no se conocen entre sí.

Alcance: `src/Message/` completo, más los dos puntos donde el PMS lo alimenta
(`src/Pms/Service/Message/PmsReservaMessageContext.php` y
`src/Pms/Service/Reserva/PmsReservaRecalculoService.php`).

---

## Índice

1. [Vocabulario](#1-vocabulario)
2. [Las tres capas](#2-las-tres-capas)
3. [Disparadores: qué evento aplica las reglas](#3-disparadores-qué-evento-aplica-las-reglas)
4. [El motor de reglas, decisión a decisión](#4-el-motor-de-reglas-decisión-a-decisión)
5. [Canales y colas](#5-canales-y-colas)
6. [Lo que NO se re-evalúa solo](#6-lo-que-no-se-re-evalúa-solo)
7. [Gotchas](#7-gotchas)
8. [Menús en canales sin botones](#8-menús-en-canales-sin-botones)
9. [Dónde tocar para cambiar X](#9-dónde-tocar-para-cambiar-x)

---

## 1. Vocabulario

| Término | Significado |
|---|---|
| **Conversación** (`MessageConversation`) | El chat con un huésped. Se identifica por la pareja lógica `contextType` + `contextId` (ej. `pms_reserva` + UUID de reserva), no por una FK. |
| **Contexto** (`MessageContextInterface`) | Adaptador que traduce una entidad de negocio (una reserva) al vocabulario agnóstico del chat. Hoy sólo lo implementa `PmsReservaMessageContext`. |
| **Hito** (`ConversationMilestoneInterface`) | Fecha clave del contexto: `created_at`, `start`, `end`, `expected_arrival`, `cancelled_at`. Es el ancla temporal de toda regla. |
| **Regla** (`MessageRule`) | "Esta plantilla, por estos canales, N minutos antes/después de este hito, sólo para estas fuentes/agencias". |
| **Mensaje** (`Message`) | La intención de comunicar. No envía nada por sí mismo. |
| **Cola** (`Beds24SendQueue`, `WhatsappMetaSendQueue`) | El intento físico de envío por un canal concreto. Un mensaje puede tener varias. |

La distinción **mensaje ≠ cola** es la que sostiene todo el módulo: el mensaje es la decisión de
negocio (qué y cuándo), la cola es la ejecución técnica (por dónde y con qué reintentos). El
estado del mensaje se **deduce** de sus colas, nunca al revés — ver `MessageRuleEngine::resolveMessageStatus()`.

---

## 2. Las tres capas

```
  ┌─────────────────────────────────────────────────────────────────┐
  │ CAPA 1 — CONTEXTO        ¿Qué sabe el negocio de este huésped?  │
  │                                                                 │
  │  PmsReserva ──► PmsReservaMessageContext ──► MessageConversation│
  │                   (adaptador)                 (JSON contextData)│
  └─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │ CAPA 2 — REGLAS          ¿Qué habría que decirle, y cuándo?     │
  │                                                                 │
  │  MessageRule × N  ──► MessageRuleEngine ──► Message (scheduledAt)│
  └─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │ CAPA 3 — TRANSPORTE      ¿Por dónde sale y qué pasó?            │
  │                                                                 │
  │  MessageEnqueuerEntityListener ──► MessageDispatcher            │
  │            └► ChannelEnqueuerInterface ──► *SendQueue ──► Worker│
  └─────────────────────────────────────────────────────────────────┘
```

Ninguna capa importa a la de abajo por su nombre concreto: el motor no conoce
`Beds24SendQueue`, habla con `Message::getAllQueues()` y `MessageQueueItemInterface`.

### Por qué el motor no fabrica colas

`MessageRuleEngine` decide **qué mensaje debe existir y para cuándo**. La fabricación de colas
vive en `MessageEnqueuerEntityListener::prePersist()/preUpdate()`, que reacciona a la entidad
`Message`. Así, un mensaje creado a mano por un operador desde la API recorre exactamente el
mismo camino de encolado que uno automático, sin duplicar lógica.

**La excepción, y es importante:** la *cancelación* de colas por canal inválido sí la ejecuta el
motor (`MessageRuleEngine::cancelQueuesOutsideChannels()`). El motivo está en §7.

---

## 3. Disparadores: qué evento aplica las reglas

`MessageRuleEngine::syncConversationRules()` tiene exactamente cuatro entradas:

| Disparador | Trigger | Cuándo ocurre |
|---|---|---|
| `MessageRuleEngineListener::postFlush()` | `INSERT` / `UPDATE` | Cambió una `MessageConversation` en un flush de Doctrine |
| `PmsReservaRecalculoService::recalcularDesdeEventos()` | `UPDATE` + `force` | Cualquier cambio estructural de una reserva del PMS |
| `Beds24ReceivePersister::upsertMessages()` | indirecto | Llega un mensaje del huésped; sólo dispara si el upsert cambió el contexto |
| `MessageSyncRulesCommand` | `COMMAND` | Barrido por cron cada 15 min (`--all`) o reparación manual (§6) |

### El camino real de una reserva

Todo cambio del PMS pasa por aquí, y **no** por el listener de conversaciones:

```
 flush() sobre PmsReserva / PmsEventoCalendario / PmsReservaHuesped
        │
        ▼
 PmsReservaRecalculoListener::onFlush()      recolecta reservaIds
        │
        ▼  postFlush
 PmsReservaRecalculoService::recalcularDesdeEventos()
        │
        ├─ UPDATE … SET fecha_llegada, monto_total, …     (SQL crudo, sin listeners)
        ├─ EntityManager::refresh($reserva)
        ├─ MessageConversationFactory::upsertFromContext()   ← rellena contextData
        └─ MessageRuleEngine::syncConversationRules(UPDATE, force: true)
                  │
                  └─ flush() interno ──► despierta MessageRuleEngineListener
                                          └─ si la conversación era NUEVA: 2ª pasada con INSERT
```

**El SQL crudo es la razón de todo esto.** El rollup de la reserva se hace con un `UPDATE`
directo por velocidad, y eso salta los listeners de Doctrine por completo. De ahí el
`refresh()` y la llamada explícita al motor.

### La doble pasada (INSERT después de UPDATE)

Una conversación **nueva** se evalúa dos veces: primero como `UPDATE force` desde el recálculo,
y después como `INSERT`, porque el `flush()` con el que termina el motor es el que realmente
inserta la conversación y eso despierta a `MessageRuleEngineListener`.

No es un bug, pero **sí es frágil**: el rescate de reservas de última hora (§4.4) depende de esa
segunda pasada. Si algún día `upsertFromContext()` pasa a flushear por su cuenta, hay que
comprobar que la pasada `INSERT` sigue ocurriendo.

---

## 4. El motor de reglas, decisión a decisión

Por cada regla del `contextType` de la conversación, el motor calcula dos cosas independientes:

- **`calculateRunAt()`** — la fecha: hito + `offsetMinutes`. `null` si el hito no existe.
- **`ruleAppliesToConversation()`** — si la regla tiene derecho a existir aquí y ahora.

Si cualquiera de las dos falla y ya había un mensaje, se cancela. Ese es el mecanismo por el que
mover una reserva a otra fecha reprograma sus avisos y cancelarla los apaga.

### 4.1 Se cargan TODAS las reglas, activas o no

```php
$rules = $this->em->getRepository(MessageRule::class)->findBy(['contextType' => …]);
```

Filtrar por `isActive` en la consulta parecía lo natural y era un bug: una regla apagada
desaparecía del bucle, su rama de cancelación no se ejecutaba y **los mensajes ya programados se
enviaban igual**. Hoy la regla inactiva entra al bucle y `ruleAppliesToConversation()` la
rechaza, que es lo que dispara la cancelación. Si vuelves a filtrar en el `findBy`, reintroduces
el bug.

### 4.2 La barrera de estado y el hito `CANCELLED`

| Estado de la conversación | Reglas normales | Reglas con hito `cancelled_at` |
|---|---|---|
| `open` | ✅ | ❌ |
| `closed` | ❌ | ✅ |
| `archived` | ❌ | ❌ |

La asimetría es obligatoria: `MessageConversationFactory::upsertFromContext()` cierra la
conversación **en el mismo movimiento** en que detecta que la reserva está cancelada. Exigir
"conversación abierta" para todas las reglas dejaba el hito `CANCELLED` matemáticamente
inalcanzable — nunca se enviaba un solo mensaje de cancelación.

Un cierre manual del operador no cuenta como cancelación: lo que distingue los dos casos es la
presencia del hito `cancelled_at`, que sólo `PmsReservaMessageContext::getMilestones()` añade
cuando `isCancelled()` es cierto. Sin hito no hay `runAt`, y sin `runAt` no hay mensaje.

### 4.3 Identidad del mensaje: la REGLA, no la plantilla

`Message::getRule()` (columna `rule_id`, migración `Version20260805120000`) es lo que empareja un
mensaje con la regla que lo creó.

Antes la identidad era la plantilla, y dos reglas que la compartieran —un recordatorio a −7 días
y otro a −1 día con el mismo texto— se reconocían como **el mismo mensaje**: la segunda regla
encontraba el mensaje de la primera, le reescribía `scheduledAt` y sólo salía un envío.

`MessageRuleEngine::messageBelongsToRule()` cae en la plantilla sólo para mensajes anteriores a
la columna (`rule_id IS NULL`). El backfill de la migración es conservador: sólo adopta la regla
cuando una plantilla identifica a una sola.

### 4.4 Los cuatro umbrales temporales

Todos son constantes de `MessageRuleEngine` y todos se miden en `America/Lima`:

| Constante | Valor | Qué decide |
|---|---|---|
| `PAST_THRESHOLD` | −2 h | Más atrás que esto, el mensaje ya no se programa en su fecha original |
| `RESCUE_WINDOW` | −24 h | Antigüedad máxima que admite el rescate de última hora |
| `EXPIRY_WINDOW` | −12 h | Un pendiente más atrasado que esto se cancela por caducidad |
| `TZ` | `America/Lima` | Zona en la que se interpretan hitos y "hoy" |

**El rescate** (`canRescue`) crea el mensaje con `runAt = now` cuando su fecha teórica ya pasó.
Se activa en `INSERT` o en `COMMAND + force`, y sólo dentro de `RESCUE_WINDOW`. Ese suelo es
imprescindible: sin él, importar el histórico de un canal —donde toda conversación entra como
`INSERT`— disparaba de golpe los mensajes de bienvenida de reservas hechas hace meses.

> ⚠️ **`force` por sí solo NO habilita el rescate**, y esto no es un detalle menor.
> `PmsReservaRecalculoService` pasa `force: true` en **cada** cambio de reserva, y
> `app:message:rebuild-context` lo hace de madrugada sobre **todas** las conversaciones
> `pms_reserva` (sin filtro de estado ni de fecha). Si el rescate colgara de `force`, ese
> barrido de las 3 AM crearía mensajes con `runAt = now` y el worker de WhatsApp —que corre
> cada 3 minutos— los sacaría en el acto. `force` significa exactamente una cosa: saltarse el
> blindaje de las reglas `CREATED`. La reparación es `TRIGGER_COMMAND` + `force`, es decir,
> alguien tecleando `app:message:sync-rules --force`.

**La caducidad** (`EXPIRY_WINDOW`, en `healZombieMessages()`) cubre el hueco del worker: las
colas se reclaman con `run_at <= now` y **sin fecha de caducidad**, así que un worker caído dos
días acababa enviando el "llegas mañana" después del check-out. Sólo caduca mensajes del sistema
con `scheduledAt`; lo que un operador dejó en cola a mano se respeta.

**Sobre la zona horaria:** los hitos se serializan como texto sin sufijo de zona
(`MessageConversation::addContextMilestone()` usa `'Y-m-d\TH:i:s'`). Interpretarlos con la zona
de `php.ini` significaba que el mismo dato valía dos instantes distintos según el servidor.
`MessageRuleEngine::parseMilestone()` fija `TZ` explícitamente. **Si algún día los hitos pasan a
guardarse en UTC, hay que cambiar las dos puntas a la vez.**

### 4.5 Estados terminales y la salida del callejón `FAILED`

`syncPendingMessage()` no toca un mensaje `SENT`/`DELIVERED`/`READ`: lo enviado es historia.

`FAILED` es distinto y tiene trampa. Un mensaje fallido puede estar en pleno ciclo de reintentos
del worker, así que regenerarlo arriesga un envío doble al huésped. Pero si sus colas ya agotaron
`maxAttempts`, no lo va a reintentar nadie y su mera existencia bloqueaba la creación de uno
nuevo: quedaba muerto para siempre.

La salida es explícita y humana: sólo en reparación manual (`TRIGGER_COMMAND` + `force`, o sea
`app:message:sync-rules --force`) el motor regenera un `FAILED` cuyas colas están agotadas —
`MessageRuleEngine::queuesAreExhausted()`. Cualquier indicio de entrega previa lo descarta.
Igual que el rescate, **no** basta con `force`: ver el aviso de §4.4.

---

## 5. Canales y colas

`MessageDispatcher::resolveChannels()` aplica tres reglas en cascada:

1. **La plantilla manda.** Los canales con `is_active` en su JSON son el máximo permitido; la
   selección del operador (`transientChannels`) sólo puede recortar ese conjunto, nunca ampliarlo.
2. Sin plantilla, vale la selección explícita del operador.
3. Sin nada, el canal propio del mensaje.

`ChannelEnqueuerInterface` tiene dos métodos que se confunden con facilidad:

- **`createQueueEntity()`** — fabrica. Lanza excepción si el envío es imposible (reserva directa
  por Beds24, ventana de 24 h de WhatsApp cerrada sin plantilla oficial).
- **`isValid()`** — pregunta sin fabricar. Es lo que usa el motor para podar canales antes de
  intentar nada.

Fallo parcial: si al menos una cola se crea, el mensaje queda `QUEUED` y los errores del resto se
guardan en `metadata.dispatch_partial_errors`. Sólo si **ninguna** se crea el mensaje pasa a
`FAILED` con `metadata.dispatch_errors`.

### Recibos de lectura: proactivos, no reactivos

`MessageEnqueuerEntityListener` ignora deliberadamente los mensajes `INCOMING`. Marcar como leído
desde un listener de entidad crearía una cascada de eventos y bucles. Se hace a mano en
`MarkConversationReadController`. El comentario en el propio listener lo advierte; no lo quites.

---

## 6. El barrido periódico

**El cron vive en el crontab de `www-data` en el servidor, no en el repo.** Quien lea sólo el
código concluye que el motor es puramente reactivo, y no lo es. Estado a 2026-08-05:

| Frecuencia | Comando | Qué hace por el módulo |
|---|---|---|
| `*/15 * * * *` | `app:message:sync-rules --all --env=prod` | Re-evalúa reglas de las conversaciones **OPEN**: reprograma, cancela lo caducado, sana zombies |
| `0 3 * * *` | `app:message:rebuild-context --env=prod` | Recalcula el contexto de **todas** las `pms_reserva` (con `force`), repara `lastMessageAt` y archiva chats con `end` de hace +7 días |
| `*/3 * * * *` | `exchange:run whatsapp_meta_message_send` | Vacía la cola de WhatsApp |
| `*/5 * * * *` | `exchange:run beds24_message_send` / `beds24_message_receive` | Ídem Beds24, ambos sentidos |

Que el worker corra cada 3-5 minutos es lo que hace holgada la `EXPIRY_WINDOW` de 12 h: nada
que el worker fuera a enviar en condiciones normales cae por caducidad.

### El hueco que queda: `--closed`

`--all` filtra por `status = OPEN` (`MessageSyncRulesCommand::execute()`), y las reglas con hito
`CANCELLED` exigen `CLOSED` (§4.2). **No hay ninguna entrada de cron con `--closed`**, así que
esas reglas dependen por completo del disparo reactivo del PMS en el momento de cancelar. Si ese
momento falla, nada las repesca. Entrada recomendada:

```cron
# Barredora de cerradas: mensajes de cancelación + cancelar colas de reservas muertas.
15 4 * * * /usr/bin/php /var/www/openperu.pe/bin/console app:message:sync-rules --closed --env=prod >> /var/www/openperu.pe/var/log/message_sync_rules.log 2>&1
```

`--force` **nunca** va en el cron: es reparación con supervisión humana (§4.4).

### Lo que sigue sin re-evaluarse solo

| Situación | Qué pasa |
|---|---|
| Se crea o edita una `MessageRule` | Las reservas existentes no se reprograman al guardar; las recoge el barrido de los 15 min. El CRUD lo avisa con un flash (`MessageRuleCrudController::avisarResincronizacion()`) |
| Se activa/desactiva un canal en una `MessageTemplate` | Igual: entra por el barrido, no al guardar |
| Conversaciones `ARCHIVED` | Fuera de `--all` y de `--closed` (que sí las incluye). Una vez archivada, ninguna regla vuelve a aplicar |

---

## 7. Gotchas

### 🔥 `transientChannels` no es una columna

`Message::$transientChannels` no tiene `#[ORM\Column]`. Cambiarlo **no ensucia la entidad**:
Doctrine no calcula changeset y `MessageEnqueuerEntityListener::preUpdate()` no se dispara.

Esto costó un envío indebido. El motor "podaba" canales llamando a `setTransientChannels()` y
confiando en que el listener cancelara la cola sobrante; cuando no cambiaba nada más del mensaje,
la cola de Beds24 de una reserva que había pasado a directa seguía viva y acababa enviando.

Por eso `MessageRuleEngine::cancelQueuesOutsideChannels()` cancela las colas **directamente**, de
forma agnóstica al canal vía `MessageQueueItemInterface::getChannelId()`. La fabricación de
canales *añadidos* sigue dependiendo del listener, y por tanto de que cambie `scheduledAt` o
`status`; si no, la recoge el siguiente barrido del comando.

### 🔥 `STATUS_DELIVERED === STATUS_SENT`

En `Message` ambas constantes valen `'sent'`. "Entregado" no es distinguible de "enviado" a nivel
de mensaje; el dato fino vive en la cola. Si algún día se separan, revisa los `in_array()` de
estados terminales en `MessageRuleEngine::syncPendingMessage()`.

### 🔥 Doble candado anti-bucle

Hay dos banderas independientes y ambas son necesarias:

- `MessageRuleEngineListener::$isSyncing` — el `flush()` interno del motor volvería a despertar
  al recolector, duplicando colas por "amnesia" de la UnitOfWork.
- `PmsReservaRecalculoListener::$isFlushing` — el recálculo escribe con SQL crudo y luego
  flushea; sin la bandera se recolecta a sí mismo.

Quitar cualquiera de las dos produce recursión infinita, no un error visible.

### 🔥 El agregado de hora de llegada es multivalor

`PmsReserva::getHoraLlegadaCanalAggregate()` viene de un `GROUP_CONCAT(... SEPARATOR ' | ')`. Con
dos unidades que informaron ETA distinta llega `"14:00 | 16:00"`. El parseo reventaba y el hito
`expected_arrival` desaparecía **sin log**. Hoy `PmsReservaMessageContext::getMilestones()` parte
por `|` y se queda con la hora más temprana. Los canales también mandan texto libre ("late
night"): esos valores se descartan uno a uno, no tumban el hito entero.

### 🔥 Concurrencia sin candado

`findExistingSystemMessage()` recorre la colección en memoria de la conversación. No hay lock ni
índice único sobre `(conversation_id, rule_id, sender_type)`. Dos procesos simultáneos —un
webhook y el cron— pueden crear el mismo mensaje del sistema por duplicado. La idempotencia de
los enqueuers (`isAlreadyEnqueued()`) protege la cola **por mensaje**, no el mensaje en sí.
Pendiente si alguna vez se ven duplicados en producción.

### 🔥 Los bloqueos puros no generan chat, los inquiries sí

`PmsReservaMessageContext::isSoloBloqueo()` corta la creación de conversación para bloqueos de
calendario (mantenimiento, agenda cerrada). `isAbiertoOrBloqueo()` es más ancha e incluye
inquiries; se usa para vaciar los hitos, no para saltarse la conversación. **No son
intercambiables**: confundirlas deja a los inquiries de Airbnb sin chat.

---

## 8. Menús en canales sin botones

Airbnb y Booking, que viajan por Beds24, son **texto plano**: no existen los botones pulsables.
El sistema los emula, y son dos mitades de un mismo contrato que hay que mantener simétricas.

```
   PLANTILLA (whatsappMetaTmpl.buttons_map)   ← fuente ÚNICA del menú, para todos los canales
        │
   ┌────┴─────────────────────────────┐
   ▼ ENVÍO                            ▼ VUELTA
 Beds24SendMappingStrategy::map()    InboundMenuResolver::resolveActionCode()
   «1️⃣ *Guía*»                        "1"  → payload del 1º quick_reply
   «2️⃣ *Tours*»                       "2"  → payload del 2º
   «🔗 *Ver mapa*: https://…»         (los `url` NO consumen número)
   «👉 Responde con el número…»
```

**El `buttons_map` vive en el bloque de WhatsApp Meta aunque el mensaje salga por Airbnb.** No
es un descuido: es la definición única del menú, y `Beds24SendMappingStrategy` la reutiliza para
pintar el texto. Si una plantilla necesita salir sin menú por Beds24, está
`MessageTemplate::isBeds24MetaButtonsDisabled()`.

**La numeración cuenta sólo los `quick_reply`.** Los botones de tipo `url` se renderizan como
enlace y no gastan número. Los dos lados filtran igual; si tocas uno, tocas el otro.

### 🔥 Por qué esto no funcionaba: `createdAt` no es el orden del chat

El resolutor busca «el último mensaje que vio el huésped» para saber a qué menú se refiere el
número. Antes ordenaba por `createdAt DESC`, y eso es **falso** para los mensajes automáticos:
el motor de reglas los CREA cuando programa la reserva y se ENVÍAN mucho después. Medido en
producción sobre 1008 mensajes de sistema con plantilla:

| Desfase entre `created_at` y `scheduled_at` | Mensajes |
|---|---|
| más de 1 hora | 653 |
| más de 24 horas | 615 |
| máximo observado | 7771 h (≈ 324 días) |

Con `createdAt DESC`, «el último mensaje» era a menudo un recordatorio programado para dentro de
medio año que el huésped aún no había recibido. Resultado: el menú no casaba —y cuando casaba
por casualidad, podía apuntar a **otra** plantilla y responder algo que nadie pidió.

El criterio correcto es la **fecha efectiva**, `COALESCE(scheduledAt, createdAt)`, filtrando
además los estados que no llegaron al huésped y descartando el futuro. Es el mismo criterio que
ya usaba `RebuildConversationContextCommand`. Al cambiarlo, las conversaciones en las que el
menú puede resolverse pasaron de **82 a 151** de 284.

**Por qué WhatsApp no sufría esto:** allí los botones son pulsables y llegan como
`button`/`interactive` **con su payload**, sin pasar por el resolutor. El interceptor numérico
es, en la práctica, la única puerta del motor determinista para las OTA — por eso el bug lo
dejaba prácticamente muerto en Beds24 (2 disparos frente a 835 mensajes) y no se notaba en Meta.

### El menú caduca

`InboundMenuResolver::VENTANA_VALIDEZ` (24 h). Sin ella, un huésped que contesta «2» a un
«¿cuántos sois?» revive un menú de hace semanas y recibe la lista de tours.

### ⚠️ Decisión de producto: los menús están APAGADOS en Beds24

A 2026-08-05, `welcome_airbnb`, `welcome_booking` y `enviar_guia` llevan
`beds24Tmpl.disable_meta_buttons = true`. **Es deliberado, no un olvido**: la latencia de Beds24
entregando mensajes desde y hacia Airbnb/Booking hace que un menú interactivo sea mala
experiencia — el huésped responde «2» y la respuesta tarda de más.

El cuello de botella es del proveedor, no nuestro. Medido en producción:

| Tramo | Latencia |
|---|---|
| Envío nuestro (cola Beds24 `run_at` → ejecutada) | **media 5 s**, máx 35 s (155 envíos/14 días) |
| Recepción | por **webhook**, sin esperar al pull |

Los cron de `*/5` son sólo red de seguridad: `MessagerDispatcherSendQueueEventListener` despacha
al worker de Messenger en el acto, y `Beds24WebhookController` no aplica el delay de 15 s a los
mensajes de huésped (*«queremos lo más rápido posible los guests»*). Optimizar nuestro lado no
mueve la aguja; **antes de reactivar los menús en Beds24 hay que comprobar que el proveedor ha
mejorado**, no el código.

En WhatsApp Meta no aplica: allí los botones son nativos y la entrega es inmediata.

### Requisito de configuración, no de código

Para que una opción funcione por Beds24, la plantilla de **respuesta** debe tener su canal
`beds24Tmpl.is_active = true`. Si no, `MessageDispatcher::resolveChannels()` intersecta a vacío
y el mensaje queda `QUEUED` sin ninguna cola — nunca sale, sin error visible.

`menu_tours` estaba en `false` por ese motivo y se activó el 2026-08-05. No contradice el
apagado de menús de arriba: son dos interruptores distintos. `disable_meta_buttons` decide si el
menú se **pinta** al salir; `is_active` decide si la plantilla de respuesta **puede salir** por
ese canal. Con los menús apagados nadie llega a pedir tours desde una OTA, así que el segundo
interruptor sólo evita que la respuesta muera en silencio el día que se reactiven.

## 9. Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Añadir un hito nuevo (ej. "pago recibido") | `src/Message/Contract/ConversationMilestoneInterface.php` | constante + `MessageConversation::addContextMilestone()` + `MessageRule::setMilestone()` + choices del CRUD |
| Cambiar de dónde sale una fecha del PMS | `src/Pms/Service/Message/PmsReservaMessageContext.php` | `getMilestones()` |
| Ajustar cuándo caduca o se rescata un mensaje | `src/Message/Service/Queue/MessageRuleEngine.php` | constantes `PAST_THRESHOLD`, `RESCUE_WINDOW`, `EXPIRY_WINDOW` |
| Cambiar qué estados de conversación admiten reglas | `src/Message/Service/Queue/MessageRuleEngine.php` | `ruleAppliesToConversation()` — §4.2 |
| Añadir un filtro de segmentación (ej. por tipo de unidad) | `src/Message/Entity/MessageRule.php` | `matchesSegmentation()` — **fuente única**, la usa también `isSatisfiedBy()` |
| Que un cambio de la conversación re-dispare reglas | `src/Message/EventListener/Queue/MessageRuleEngineListener.php` | `CAMPOS_CRITICOS` |
| Añadir un canal de salida nuevo | nuevo `ChannelEnqueuerInterface` + entidad de cola | `supports()`, `createQueueEntity()`, `isValid()`, `getChannelId()` |
| Cambiar la prioridad plantilla vs. operador | `src/Message/Service/Queue/MessageDispatcher.php` | `resolveChannels()` |
| Cambiar cómo se deduce el estado de un mensaje | `src/Message/Service/Queue/MessageRuleEngine.php` | `resolveMessageStatus()` + `healZombieMessages()` |
| Reparar mensajes fallidos de una reserva | — | `php bin/console app:message:sync-rules <uuid> --force` |
| Aplicar una regla recién creada al parque existente | — | `php bin/console app:message:sync-rules --all` |
| Cambiar cómo se numera el menú al SALIR por una OTA | `Beds24SendMappingStrategy` | `map()` — §8, simétrico con el resolutor |
| Cambiar cómo se interpreta el número al VOLVER | `InboundMenuResolver` | `resolveActionCode()` — §8, simétrico con el render |
| Añadir un idioma al footer «responde con el número» | `Beds24SendMappingStrategy` | `getMenuTranslations()` |
| Que una opción del menú pueda responderse por Airbnb/Booking | EasyAdmin | `beds24Tmpl.is_active` de la plantilla de respuesta — §8 |
| Entender por qué un mensaje no salió | `var/log/` | busca "Omisión preventiva", "Rescate descartado", "Poda de canales", "Caducidad", "Sanidad" |
