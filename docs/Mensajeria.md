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
9. [El Agent: autorespuestas e IA](#9-el-agent-autorespuestas-e-ia)
10. [El asistente interno del panel](#10-el-asistente-interno-del-panel)
11. [El agente: skills, acceso y proveedor](#11-el-agente-skills-acceso-y-proveedor)
12. [Proveedores de IA: elegir motor y modelo](#12-proveedores-de-ia-elegir-motor-y-modelo)
13. [Triaje de entrada y tramos de potencia](#13-triaje-de-entrada-y-tramos-de-potencia)
14. [El formato del texto libre y su degradación por canal](#14-el-formato-del-texto-libre-y-su-degradación-por-canal)
15. [Dónde tocar para cambiar X](#15-dónde-tocar-para-cambiar-x)

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

### 🔑 Añadir un channel manager: qué se toca y qué no

La estrategia es **coexistencia**, no sustitución: Beds24 se queda y convive con conectores
nuevos. Por eso importa que el canal N+1 sea barato.

**Ya soporta operar en paralelo, sin tocar nada:**

- Un `Message` tiene N colas a la vez; hoy ya sale por Beds24 **y** WhatsApp.
- `MessageRule::targetCommunicationChannels` es ManyToMany.
- **El enrutado ya está resuelto por `ChannelEnqueuerInterface::isValid()`**: cada enqueuer
  decide si la reserva es suya. `Beds24SendEnqueuer` mira `beds24_book_id`/`beds24_config`, así
  que una reserva de otro channel manager sencillamente no le valida. No hay que inventar
  enrutado nuevo.
- La conversación se identifica por `contextType`+`contextId` (la reserva del PMS): dos channel
  managers sobre la misma reserva **no** duplican chat.

**Lo que hay que escribir para un canal nuevo:**

| Pieza | Qué |
|---|---|
| `ChannelEnqueuerInterface` | Clase nueva. El tag `app.message.enqueuer` la registra sola |
| `MappingStrategyInterface` | Cómo se arma el payload del proveedor |
| Entidad de cola | Implementa `MessageQueueItemInterface`, incluido `getChannelId()` |
| Fila en `msg_channel` | Con su `templateColumn` |
| `MessageTemplate` | Columna JSON del canal + su caso en `getActiveChannels()` ⚠️ migración |
| `Message` | La colección nueva en **`getAllQueues()` y `addQueue()`**, y en ningún sitio más |

**`MessageEnqueuerEntityListener` NO se toca.** Es agnóstico: reparte por
`MessageQueueItemInterface::getChannelId()` e itera `getAllQueues()`. Antes decidía con
`str_contains(get_class($queue),'Beds24')` y tenía un `if (!in_array('beds24', …))` por canal
escrito a mano; olvidar uno **no fallaba**, simplemente dejaba de cancelar y el mensaje salía
por un canal ya descartado — el mismo fallo silencioso de §7.

Lo que sigue creciendo a mano, y es la deuda pendiente: la **columna por canal** de
`MessageTemplate` (exige migración cada vez) y el hecho de que `buttons_map` viva dentro de
`whatsappMetaTmpl` aunque defina el menú de todos los canales (§8).

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

**Por qué no se puede optimizar:** Beds24 no recibe webhooks de Airbnb ni de Booking — las
**consulta cada 2 minutos**. Desde que el huésped escribe hasta que nos llega el webhook pasan
hasta 3 minutos, y otros tantos de vuelta: **~6 minutos de round-trip** para un menú numérico.
Hacer polling nuestro más agresivo (cada 30 s) no sirve de nada: el dato todavía no existe en
Beds24. El eslabón lento está aguas arriba y es inalcanzable.

### 🔑 Regla de diseño: `url` sí, `quick_reply` no

La latencia sólo castiga a lo que **espera respuesta**:

| Tipo de botón | En OTA por Beds24 | Motivo |
|---|---|---|
| `url` | ✅ úsalo | El huésped hace clic y va a la web. **Sin round-trip**, la latencia no le afecta. |
| `quick_reply` | ❌ evítalo | Exige que responda y que la respuesta vuelva: ~6 min. |

Por eso `recordatorio_llegada` tiene los botones encendidos (su único botón es `url`) y
`welcome_booking` los tiene apagados (sus dos botones son `quick_reply`). **No es incoherencia.**

Ojo con el todo-o-nada: `disable_meta_buttons` suprime los DOS tipos. Si una plantilla sólo
tiene botones `url`, apagarla pierde enlaces útiles sin ganar nada — el render ya omite el
footer «responde con el número» cuando no hay ningún `quick_reply` (`$hasQuickReplies`), así que
no hay riesgo de menú fantasma. Para plantillas **mixtas** haría falta un modo intermedio
«sólo enlaces», que hoy no existe.

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

## 9. El Agent: autorespuestas e IA

`src/Agent/` decide qué hacer con un mensaje entrante. Dos ramas, según la `category` que el
persister puso en el intent:

```
  Message entrante (con inbound_intent)
        │
   MessageAutoResponderListener   postPersist / postUpdate
        │  dispatch → async  ← «Por qué el dispatch tiene que ir en async», más abajo
        │  free_text además con DelayStamp: la espera de ráfaga
        ▼
   IntentRouter::routeIntent()
        ├─ deterministic / system_alert → AutoResponderRule → BotActionHandlerInterface
        └─ free_text                    → AiConversationProcessor
                                              │
                                              ├─ 6 guardias
                                              ├─ triaje (§13) ──┬─ charla  → la contesta el triaje mismo
                                              │                 └─ resto   → camino largo, catálogo entero
                                              └─ 2.ª mirada (¿llegó otro?) → Message OUTGOING
```

`generar()` dejó de ser un solo paso: hoy son tres —clasificar, y después charlar barato **o**
ir al camino largo con todas las herramientas—. El detalle, con lo que cuesta cada uno, está en
**§13**; aquí basta con saber que **el resultado observable no cambió**: si el triaje falla o va
apagado, todo baja por el camino largo, que es el de siempre.

**Todo camino acaba en `marcarResuelto()`**, que escribe `resolved`, **`resolution`** (el
motivo) y `resolved_at` en el intent. Antes sólo se marcaba cuando una regla casaba, así que
un mensaje sin regla se quedaba `resolved: false` **para siempre** y se re-despachaba en cada
`postUpdate` — con IA eso sería volver a pagar el modelo en cada actualización del mensaje.
En producción había 29 `ERR_131049` en ese bucle.

Motivos que puede tomar `resolution`: `ia`, `ia_desactivada`, `ia_sin_credenciales`,
`ia_sin_respuesta`, `error_ia`, `regla:<trigger>`, `error_accion`, `sin_handler`, `sin_regla`,
`humano_atendiendo`, `ya_respondido`, `fuera_de_ventana_24h`, `canal_deshabilitado`,
`conversacion_no_abierta`, `rafaga_superada`, `rafaga_superada_al_responder`. Sirven para medir
después cuántos contestó el bot y cuántos se dejaron pasar — y por qué.

### 🔥 Por qué el dispatch tiene que ir en `async`

`ProcessInboundIntentDispatch` **no estaba en el `routing` de `messenger.yaml`**, y sin ruta
Symfony lo maneja de forma **síncrona**. Como quien lo lanza es un `postPersist`/`postUpdate`
de Doctrine, el router corría **dentro de un flush**: flush anidado en `marcarResuelto()`,
`persist()` de la respuesta dentro de un `postPersist`, y un `em->refresh()` a media
transacción. Los comentarios del código decían «asíncrono» y «FIX PARA EL WORKER ASÍNCRONO»
describiendo algo que no ocurría.

Con IA habría sido peor: una llamada al modelo bloquea el webhook de Meta varios segundos
**dentro de la transacción**, y Meta reintenta los webhooks lentos → el huésped recibe la
respuesta duplicada. La ruta está ahora en `messenger.yaml`; **no la quites**.

### Los seis guardias del autoresponder

`AiConversationProcessor::motivoParaCallarse()` no genera sin preguntarse antes si toca callarse:

| # | Guardia | Por qué |
|---|---|---|
| 1 | Conversación no abierta | Cerrada o archivada: su ciclo terminó |
| 2 | **Humano al mando** (30 min) | El más importante: nada deja peor al hotel que un bot pisando al compañero que ya está atendiendo |
| 3 | **Ráfaga superada** | El huésped siguió escribiendo: este trozo ya no es el final de lo que quiere decir — sección siguiente |
| 4 | **Ya respondido** | El transporte async reintenta 3 veces; sin esto un reintento duplica el mensaje al huésped |
| 5 | Fuera de la ventana de 24 h | Meta sólo acepta plantillas fuera de sesión: el texto generado acabaría como `FAILED` |
| 6 | Canal deshabilitado | Rebote duro previo (número inválido) |

### ⏱️ Agrupación por ráfaga: cuatro trozos, una respuesta

Un huésped no escribe una pregunta: escribe cuatro trozos seguidos. Esta ráfaga es real, de la
conversación del 24-jul-2026:

```
13:59:35  Hola! Gracias
13:59:36  Acabo de llegar
13:59:50  Estamos revisando que todo funcione correctamente
14:00:06  Todo bajo control ?
```

Contestando cada uno al vuelo salen **cuatro respuestas a medias** —un saludo al «Hola», otro
al «Acabo de llegar»— y **cuatro llamadas al modelo** pagando el prompt entero cada vez. La
pregunta de verdad sólo existe cuando el huésped termina.

**La solución tiene dos mitades, y las dos hacen falta.**

**1. Esperar (el listener).** `MessageAutoResponderListener::processIntent()` encola el trabajo
con un `DelayStamp` de `AGENT_IA_ESPERA_RAFAGA` segundos. Sólo para `free_text`: los botones y
las alertas del sistema salen sin retraso, porque ahí quien pulsa espera respuesta inmediata y
no hay ráfaga que agrupar.

**2. Descartar (el procesador).** La espera no basta: los cuatro mensajes encolan **cuatro**
trabajos, cada uno con su propio reloj. `hayMensajePosterior()` decide cuál sobrevive — si
existe un mensaje del huésped más nuevo que el mío, yo ya no soy el final de la frase y me
callo con `rafaga_superada`. Sobre la ráfaga de arriba, con espera de 20 s:

| Mensaje | El trabajo corre a las | ¿Hay algo posterior? | Desenlace |
|---|---|---|---|
| 13:59:35 | 13:59:55 | sí (:36, :50) | `rafaga_superada` |
| 13:59:36 | 13:59:56 | sí (:50) | `rafaga_superada` |
| 13:59:50 | 14:00:10 | sí (14:00:06) | `rafaga_superada` |
| 14:00:06 | 14:00:26 | no | **contesta**, con los cuatro en el historial |

Una llamada al modelo en vez de cuatro. Medido sobre los 1.938 mensajes de huésped del
histórico, la espera de 20 s descarta **386 (19,9 %)**.

#### La segunda mirada, después de generar

`process()` vuelve a llamar a `hayMensajePosterior()` **justo antes de encolar la respuesta**.
Generar tarda segundos, y en ese hueco el huésped puede añadir algo que cambia la pregunta
(«¿cuánto cuesta?» → «da igual, ya lo vi»). Mandar entonces la respuesta ya escrita es
contestar a destiempo. Se tira (`rafaga_superada_al_responder`) y el trabajo del mensaje nuevo
responde a todo junto. Es lo único que la ráfaga sí cuesta en dinero —el modelo ya se pagó—, y
compensa frente a que el huésped lea una respuesta desfasada.

#### 🔥 Manda `createdAt`, y el UUID sólo desempata

Parece un detalle de implementación y no lo es; las dos mitades se descubrieron con datos.

**Por qué `createdAt` y no el UUID.** Es tentador ordenar sólo por id: son UUIDv7, que llevan
el tiempo dentro. Pero el UUID dice **cuándo lo insertamos nosotros**, no cuándo escribió el
huésped, y en los mensajes importados en bloque no guardan relación. Hay filas en la BD con
UUID mayor y fecha anterior. Lo que interesa es `createdAt`, que en el flujo real **no** es la
hora de inserción sino la que trae el canal — `WhatsappMetaReceivePersister` y
`Beds24ReceivePersister` hacen `setCreatedAt($msgDate)`, y `TimestampTrait` sólo lo rellena si
viene nulo.

**Por qué el UUID hace falta igual.** `createdAt` viene con precisión de segundo, y en una
ráfaga es normal que dos mensajes caigan en el mismo: entonces ninguno es «posterior» al otro
y **contestarían los dos**. No es hipotético — en el histórico hay **258 pares** de mensajes
del mismo huésped con el segundo exacto repetido. Ahí desempata el UUIDv7, que lleva
milisegundos y da el orden de inserción, que es justo el criterio bueno para elegir cuál de
los dos trabajos vive.

Comprobado replicando la condición en SQL sobre las 215 conversaciones del histórico:

| Criterio | Conversaciones con 1 superviviente | con 2 | con 3 |
|---|---|---|---|
| Sólo `createdAt >` | 207 | 7 | 1 |
| `createdAt` + desempate por UUID | **215** | 0 | 0 |

Sin el desempate, 8 conversaciones de 215 habrían respondido dos o tres veces al mismo turno.

⚠️ **No lo «arregles» con `COALESCE(scheduledAt, createdAt)`**, que es el criterio correcto
para los mensajes de sistema y está explicado en §8. Aquí no aplica: el filtro es
`senderType = guest`, y ningún mensaje de huésped tiene `scheduledAt` (0 de 1.938) porque a un
entrante no se le programa el envío.

⚠️ **La consulta va a la tabla, no a `$conversacion->getMessages()`.** Entre el primer guardia
y el final del turno pasan segundos —la llamada al modelo— y puede haber otro worker
persistiendo mensajes. Lo que Doctrine tenga cargado en memoria ya no describe la realidad.

#### 🔥 El parámetro UUID de un QueryBuilder necesita su tipo, o devuelve cero filas

Esto tumbó la primera versión entera, y **no falla, no avisa y no aparece en los logs**:

```php
->andWhere('m.conversation = :conversacion')
->setParameter('conversacion', $conversacion)              // ← 0 filas, siempre
->setParameter('conversacion', $conversacion->getId())     // ← 0 filas, siempre
->setParameter('conversacion', $conversacion->getId(), 'uuid')  // ← correcto
```

Los ids son `UuidV7` y en MySQL viven como **binario**. `findBy(['conversation' => $conv])`
funciona porque el persister conoce el tipo del identificador; el **QueryBuilder no lo
infiere**, manda el valor sin convertir y el `WHERE` no casa con nada. El guardia devolvía
`false` siempre: dicho de otro modo, la agrupación por ráfaga estaba apagada sin que nada lo
delatara.

Se descubrió con una prueba de extremo a extremo —tres mensajes reales por el flujo del
webhook— en la que el bot **contestó al «hola»** con el acuse de recibo genérico y marcó
`ya_respondido` la pregunta de verdad, que llegaba dos mensajes después. Exactamente el
comportamiento que la funcionalidad venía a evitar.

⚠️ La lección general: **validar la lógica en SQL puro no valida la consulta del ORM.** La
regla de `createdAt` + desempate por UUID se había comprobado sobre el histórico con SQL, y
era correcta; lo que estaba roto era el puente de Doctrine, que sólo aparece ejecutando el
flujo. Si tocas este método, pruébalo con mensajes de verdad, no con un `SELECT`.

#### ⚠️ Depende de `check_delayed_interval`

El retraso real es la espera **más** lo que tarde el worker en mirar los diferidos. Ese
intervalo estaba en 60 s en `config/packages/messenger.yaml` para no machacar la BD: con él,
una espera de 20 s tardaba **hasta 80** en dispararse y el huésped se quedaba mirando el chat.
Está en **2 s**. Si alguien lo sube, la espera de ráfaga se degrada con él.

Poner `AGENT_IA_ESPERA_RAFAGA=0` desactiva el `DelayStamp`, pero **no** los guardias: si dos
mensajes llegan juntos de todos modos, el descarte sigue evitando la respuesta duplicada.

### 🔥 El prompt del huésped NO lleva los datos de su reserva, y es a propósito

`AiConversationProcessor::systemPrompt()` los llevaba: un volcado de las 23 variables del
resolver —nombre, fechas, casita, total, pagado, **saldo**—. Parecía un atajo evidente: el
modelo contesta «sales el 10/03» sin gastar una vuelta. Era una trampa, y de las caras.

**Choca de frente con el guardia de `sin_skill`.** Teniendo el dato delante, el modelo responde
sin llamar a ninguna herramienta; el motor devuelve `ConversationResponse::sinSkill()` —«esto
respondió improvisando»—; y la política del chat del huésped tira la respuesta y manda el acuse
de recibo genérico. Es decir: **cuanto mejor era el volcado, más se callaba el bot.**

Medido en la prueba real de la ráfaga: a «cuato estoy debiendo?» el huésped recibió *«Gracias
por tu mensaje. Un compañero te responderá en breve»* teniendo el saldo escrito dos líneas más
arriba en ese mismo prompt. No era el modelo —flash-lite— ni la falta de una skill: era el
prompt anulando el guardia. Con el prompt corregido y **el mismo modelo**, la misma ráfaga
contesta la cifra exacta.

```
  ANTES                                   AHORA
  prompt: balance: 159.90                 prompt: «no tienes ningún dato de su reserva»
  «cuato estoy debiendo?»                 «cuato estoy debiendo?»
     ↓ responde de memoria                   ↓ tiene que preguntar
  sin_skill  →  🗑️  acuse genérico        consultar_mi_reserva → ok → la cifra
```

**Las dos ejecuciones, misma ráfaga y mismo modelo** (`gemini-3.5-flash-lite`, ráfaga de tres
mensajes con sus erratas: `hola` / `soy daniel albert` / `cuato estoy debiendo?`):

| | Antes | Ahora |
|---|---|---|
| Llamadas al modelo | 1 | 2 |
| Tokens de entrada | 2 209 | 2 779 + 3 135 |
| Latencia total | 1,6 s | 2,4 s (1,5 + 0,9) |
| Skills usadas | ninguna → `sin_skill` | `consultar_mi_reserva` |
| Resolución del 3.er mensaje | `ia` (acuse genérico) | `ia` |
| Lo que leyó el huésped | «Gracias por tu mensaje. Un compañero te responderá en breve.» | «Hola Daniel, tu saldo pendiente actual es de 159.90 USD.» |

Los dos primeros trozos se resolvieron `rafaga_superada` en ambas, **sin gastar una sola llamada**:
la agrupación por ráfaga es lo que hace que el turno cueste dos peticiones y no seis.

El precio es +1 petición y +3 100 tokens de entrada por turno. Con la capa gratuita de Google
(20 peticiones al día **y por modelo**) eso baja de ~8 preguntas diarias a ~5 por modelo: sirve
para probar, no para el autoresponder — lo mismo que ya decía §12.

Ahora el prompt sólo lleva el **nombre del huésped y el idioma**, y dice explícitamente que los
datos están en las herramientas, nombrando las dos que responden lo que más se pregunta:
`consultar_mi_reserva` (cuándo entra, **cuándo sale**, casita, localizador, total, pagado y
**saldo**) y `consultar_cuenta` (el desglose y cuánto sale pagar con tarjeta).

Cuesta una vuelta más de modelo por turno. A cambio, cada dato que llega al huésped tiene
detrás una llamada auditable en el log, y `sin_skill` vuelve a significar lo que dice: que ahí
no se consultó nada.

> El guardia que aparece en el diagrama —`sin_skill` → 🗑️— **ya no existe**; se quitó justo
> después, y por esto: sin volcado en el prompt dejó de ser necesario. Ver la sección
> siguiente. La medición de arriba se hizo con el guardia todavía puesto y sigue siendo válida
> para lo que compara: prompt con datos contra prompt sin datos.

⚠️ **La regla general, para no repetirlo:** un dato metido en el prompt es un dato que el
modelo responderá *sin* herramienta. Si algo se quiere poder contestar al huésped, va en una
skill, **no** en el prompt. (Antes esto equivalía además a *que no lo responda*, porque el
guardia de abajo tiraba la respuesta; ya no, pero la regla sigue en pie: sin skill no hay
llamada que auditar.)

### 🔥 El saludo se entrega: `sin_skill` ya no se tira

Durante un tiempo `AiConversationProcessor::generar()` cambiaba por el acuse de recibo **toda**
respuesta marcada `sin_skill`. El razonamiento parecía prudente —sin skill, el texto salió del
modelo y no de los datos— y el precio era absurdo:

```
huésped: «hola, soy Jorge»
   ↓ el modelo contesta «¡Hola Jorge! ¿en qué te puedo ayudar?» — sin herramienta, claro:
     un saludo no necesita ninguna
   ↓ motivo = sin_skill
   ↓ 🗑️
huésped lee: «Gracias por tu mensaje. Un compañero te responderá en breve.»
```

Y ese acuse **no avisaba a nadie**: prometía una persona y sólo dejaba un `logger->info()`.
Un bot que no sabe devolver un saludo no parece prudente, parece roto.

**Lo que hacía peligroso confiar era el volcado del prompt, no el `sin_skill`.** Con el saldo
escrito en el prompt, un `sin_skill` era el modelo recitando cifras de memoria. Fuera el
volcado (sección anterior), lo que queda sin herramienta es cortesía: no hay datos de la
reserva que inventar porque no se le han dado. Las dos piezas son una sola decisión y se
tomaron juntas — **quitar el guardia sin quitar antes el volcado sería reabrir justo el
agujero que el guardia tapaba**.

Ahora:

| Lo que devuelve el motor | Qué lee el huésped |
|---|---|
| Texto, con skill (`ok`) | El texto |
| Texto, sin skill (`sin_skill`) | **El texto**, y se registra en el log con el texto entero |
| Sin texto (`sin_respuesta`, `rechazado`, `sin_permisos`) | El acuse de recibo, con un `warning` que dice cuál de los tres |

El acuse de recibo pasa a ser **el suelo, no la política**: se manda cuando no hay respuesta,
no cuando la respuesta no nos gusta. De paso cubre casos que antes eran silencio total (el
proveedor declinó, faltaban permisos).

⚠️ **El riesgo que queda, escrito para que nadie lo redescubra:** ante «¿a qué hora es el
check-in?» el modelo puede contestar de memoria en vez de mirar la guía. El freno para eso son
las reglas del prompt (*«NUNCA inventes precios, disponibilidad, horarios ni políticas»*), no
tirar la respuesta: tirarla nunca distinguió una invención de un saludo, y se llevaba por
delante las dos.

⚠️ **Sigue abierto:** el acuse de recibo continúa sin escalar. Si el modelo promete una persona
por su cuenta y no llama a `escalar_al_equipo`, no se entera nadie. El prompt se lo exige en
ese mismo turno (§9, reglas), pero es una exigencia al modelo, no una garantía del código.

### 🌐 En qué idioma trabaja el agente, y quién traduce qué

Tres piezas que se tocan y que conviene no confundir:

```
ENTRANTE   external = lo que escribió el huésped (su idioma)
           local    = traducido al español          ← para el OPERADOR, no para el bot

EL AGENTE  lee `contentExternal`  → trabaja en el idioma ORIGINAL
           el system prompt le ordena responder en ese mismo idioma

SALIENTE   external = lo que escribe el bot (idioma del huésped)
           local    = traducido al español          ← lo pone MessageTranslator
```

**El agente NO usa la traducción de entrada.** Lee el original y responde en ese idioma porque el
prompt se lo dice, no porque nadie traduzca. La versión española del mensaje entrante existe para
que el operador lea el chat en su idioma.

#### 🔥 El operador leía en inglés lo que respondía su propio asistente

`AiConversationProcessor` y `EnviarMensajeHuespedSkill` rellenaban **los dos** campos con el mismo
texto:

```php
$salida->setContentLocal($texto);      // ← inglés
$salida->setContentExternal($texto);   // ← inglés
```

Y `MessageTranslator::process()` arranca con `if ($hasLocal && $hasExternal) { return; }`, así que
se saltaba el mensaje. Como `ChatView.vue` pinta `contentLocal || contentExternal`, el panel
mostraba la respuesta del bot en el idioma del huésped.

Ahora las dos ponen **sólo `contentExternal`** y el español lo genera el traductor en
`prePersist`. Comprobado: `"Hi, your code is 2499E"` → `local: "Hola, tu código es 2499E"`; con un
huésped en español hace bypass y no gasta una llamada.

⚠️ **El caso nuevo se distingue por la DIRECCIÓN**, no por qué campo falta —que es como se deducía
todo en ese servicio—. Un saliente sin `contentLocal` caería si no en el flujo entrante, que pasa
por `translateWithDetection` y puede **reescribir el idioma de la conversación**: dejar que la
respuesta del propio bot redefina el idioma del huésped es un bucle esperando a ocurrir. Por eso
`traducirParaElOperador()` traduce sin detección, con el idioma que ya se sabe.

⚠️ El aviso de `escalar_al_equipo` **sí** rellena los dos a propósito: va a una conversación
`staff`, ya está en español y no hay nada que traducir.

### Configuración

| Variable | Efecto |
|---|---|
| `AGENT_IA_PROVEEDOR` | Quién contesta: `anthropic` \| `google`. El chat del huésped usa SIEMPRE este; el panel puede pedir otro por consulta — §12 |
| `ANTHROPIC_API_KEY` / `GOOGLE_AI_API_KEY` | Vacía ⇒ ese motor se declara no disponible y el registro elige otro. Con todas vacías **todo degrada sin romper**: el webhook que trae el mensaje del huésped nunca debe caerse por esto |
| `ANTHROPIC_MODEL` / `GOOGLE_AI_MODEL` | Modelo por defecto de cada proveedor |
| `ANTHROPIC_MODELS` / `GOOGLE_AI_MODELS` | Lista blanca de modelos que el panel puede pedir — §12 |
| `AGENT_IA_AUTORESPONDER` | Interruptor del bot del huésped. **Arranca en `0`** |
| `AGENT_IA_ESPERA_RAFAGA` | Segundos de silencio antes de contestar a un `free_text`. `20`; `0` lo desactiva. Se suma a `check_delayed_interval` — sección anterior |
| `AGENT_IA_TRIAJE` | Interruptor del clasificador de entrada. Con `0`, todo va por el camino largo con el catálogo entero, como antes — §13 |
| `AGENT_IA_TRIAJE_POTENCIA` | Tramo del clasificador: `alta` \| `media`. Nunca `baja`: corre en TODOS los mensajes — §13.5 |
| `AGENT_IA_POTENCIA_ALTA` / `_MEDIA` / `_BAJA` | Qué `proveedor:modelo` atiende cada paso. Pueden cruzar proveedores. Vacías = el de `AGENT_IA_PROVEEDOR` — §13.5 |

El autoresponder nace apagado a propósito: es el único componente que **escribe a un cliente
real** sin que nadie lo revise. Enciéndelo cuando hayas calibrado el prompt con §11.

**Prompt caching (sólo Anthropic):** el prompt de sistema lleva `cacheControl` y es idéntico
durante toda la conversación, así que a partir del segundo mensaje la mayor parte de la
entrada se cobra a precio de lectura de caché. El mínimo cacheable en Opus 5 son 512 tokens.
El motor de Gemini **no** cachea —ahí es explícito y con mínimo de tokens, y no compensa para
este tamaño de prompt—, así que su segundo turno es proporcionalmente más caro (§12).

**`stop_reason: "refusal"`:** los clasificadores pueden declinar una petición devolviendo un
200 con `content` vacío. Leer `content[0]` sin comprobarlo revienta — está comprobado en los
dos servicios. En Gemini el equivalente llega por dos sitios distintos, `promptFeedback` y
`finishReason`; los tres acaban en `ConversationResponse::rechazada()` (§12).

## 10. El asistente interno del panel

`PanelAssistant` responde en lenguaje natural a preguntas del equipo desde `HomeView`
(«¿qué casitas tengo libres del 12 al 15 de marzo?»). **No es el bot del huésped**, y a
propósito no comparte nada con él salvo la fábrica del cliente:

| | Autoresponder | Asistente del panel |
|---|---|---|
| Quién pregunta | Un huésped, por WhatsApp o una OTA | Un empleado autenticado |
| Qué hace | Genera texto y lo **envía** fuera | Consulta y responde en pantalla |
| Mecanismo | Generación sobre el hilo | **Tool use** contra el PMS |
| Riesgo | Alto | Bajo: sólo lee |

Meterlo en `MessageConversation` habría obligado a inventar conversaciones falsas y contextos
que no existen. Va por su propio endpoint.

```
HomeView → AsistenteBar.vue → POST /agent/consulta → PanelAssistant
                                                          │ tool runner
                                                          ▼
                                            consultar_disponibilidad
                                                          │
                                            PmsDisponibilidadService  (docs/PmsDisponibilidad.md)
```

- **La fecha de hoy va en el system prompt.** Sin ella «12 de marzo» se resuelve a un año
  arbitrario. Se calcula en `America/Lima`, no en la del servidor.
- **La descripción de la herramienta dice CUÁNDO usarla**, no sólo qué hace, y le prohíbe
  explícitamente responder de memoria sobre disponibilidad. Eso es lo que sube la tasa de
  llamada; es prompt, no documentación.
- **Los errores de la herramienta se devuelven al modelo** como `{"error": …}` en vez de
  romper el turno: así puede pedir la aclaración que falta.
- **`herramientas` viaja en la respuesta** para que la interfaz pueda decir «Consultado en el
  PMS». Sin eso el equipo no sabe si el modelo miró datos reales o improvisó.
- **Seguridad:** el host de `api` **no** está en `access_control` (cae en `PUBLIC_ACCESS`), así
  que lo que protege el endpoint es el `#[IsGranted('ROLE_USER')]` del controlador. No lo quites.
- **El dictado no tiene backend**: la Web Speech API transcribe en el navegador y al servidor
  llega texto. Cero coste de audio. Donde no exista, el botón no se muestra.
- **Sin streaming por ahora**: son dos llamadas (pregunta → herramienta → respuesta) y la
  respuesta es corta; el tool runner del SDK de PHP es beta y combinarlo con streaming añadía
  riesgo sin ganancia. Se puede añadir después.

**Cómo probarlo sin navegador:**

```bash
php bin/console app:agent:preguntar "¿qué casitas tengo libres del 12 al 15 de marzo?"
php bin/console app:pms:disponibilidad 2026-03-12 2026-03-15   # lo que ve la herramienta
```

## 11. El agente: skills, acceso y proveedor

El módulo está partido en cuatro capas con una regla que lo ordena todo: **el dominio no
conoce al proveedor**.

```
  src/Agent/
  ├── Skill/          DOMINIO PURO — qué sabe hacer el agente. Cero SDK.
  │   ├── SkillInterface, SkillDefinition, SkillParameter, SkillResult
  │   ├── SkillRegistry            ← catálogo + filtro por actor
  │   └── Pms/                     ← las skills concretas
  ├── Access/         QUIÉN pregunta y qué puede
  │   ├── ActorInterface, AgentActor
  │   └── NivelRiesgo
  ├── Conversation/   LA FRONTERA
  │   ├── AgentEngineInterface     ← todo lo de arriba habla con esto
  │   ├── AgentEngineRegistry      ← quién contesta: motor + modelo (§12)
  │   └── ConversationRequest / ConversationResponse
  └── Provider/
      ├── CatalogoModelos          ← lista blanca de modelos, común a todos
      ├── Anthropic/  TODO el SDK, empaquetado
      │   ├── AnthropicEngine          (implementa AgentEngineInterface)
      │   ├── AnthropicSkillAdapter    (SkillDefinition → tool del SDK)
      │   └── AnthropicClientFactory
      └── Google/     Gemini por REST, sin SDK
          ├── GoogleAIEngine           (implementa AgentEngineInterface)
          ├── GoogleAISkillAdapter     (SkillDefinition → functionDeclaration)
          └── GoogleAIClient
```

### Por qué esta frontera y no otra

`AgentEngineInterface` corta en la **conversación completa**, no en un cliente HTTP genérico.
Es deliberado: abstraer el transporte daría portabilidad aparente y obligaría a renunciar a
lo que de verdad se usa —tool use, caché de prompts, control de rechazos—. Cortando aquí,
cada motor aprovecha lo mejor de su API y arriba nadie se entera.

Qué compra:

- **Actualizar el SDK o la API** toca una carpeta, no el módulo entero.
- **Dos motores en paralelo**: se registran los dos y se elige por caso de uso —el panel con
  uno barato, el chat del huésped con otro— sin duplicar skills ni permisos.

Y lo que **no** compra, conviene saberlo: los **prompts**. Cambiar de motor obliga a
recalibrarlos, y eso no lo salva ninguna interfaz.

Cambiar de motor es una variable de entorno, y probar otro es un desplegable en el panel: lo
resuelve `AgentEngineRegistry`. Ver **§12**.

### Una skill no sabe de proveedores

Describe sus parámetros con `SkillParameter` —no con JSON Schema— y devuelve un `SkillResult`.
Traducir eso al formato de turno es trabajo del adaptador de cada proveedor
(`AnthropicSkillAdapter`, `GoogleAISkillAdapter`): **son los únicos sitios del proyecto que
saben cómo espera una herramienta cada API**. Las 22 skills existentes no se tocaron al
añadir Google.

Añadir una capacidad es crear una clase en `Skill/`: el tag `app.agent_skill` la registra
sola y no se toca ni un motor ni un adaptador.

```php
final readonly class LoQueSea implements SkillInterface
{
    public function nombre(): string { return 'lo_que_sea'; }
    public function definicion(): SkillDefinition { /* descripción + parámetros */ }
    public function rolesRequeridos(): array { return [Roles::RESERVAS_WRITE]; }
    public function nivelRiesgo(): NivelRiesgo { return NivelRiesgo::Escritura; }
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult { /* … */ }
}
```

Dos reglas al escribirla:

- **La descripción es prompt, no documentación.** Di *cuándo* usarla y prohíbe responder de
  memoria si el dato tiene que salir de ahí. Eso es lo que sube la tasa de invocación.
- **Los errores de negocio se devuelven** (`SkillResult::error()`), no se lanzan: el modelo
  puede reformular. Una excepción es un fallo de infraestructura; «no encuentro a ese huésped»
  no lo es.

Y sé una fachada delgada: la lógica vive en el servicio del dominio, no en la skill.

### ⚠️ Lo que devuelves y no anuncias, no existe

**Un campo que la skill devuelve pero su `descripcion` no menciona es inalcanzable.** El modelo
elige a quién llamar leyendo *sólo* las descripciones; los datos le llegan después, y únicamente
si acertó. Ampliar la salida no amplía lo que se puede pedir — hay que anunciarlo.

Se paga caro cuando la salida no se escribe campo a campo. `BuscarReservaSkill` y
`ConsultarMiReservaSkill` vuelcan `getMessageVariables()` entera, 23 claves, y durante un tiempo
las dos devolvieron `guide_url` mientras **sólo la del huésped lo anunciaba**: el huésped podía
pedir el enlace de su guía y el operador no, con el dato idéntico en las dos respuestas. Nada
fallaba; el agente simplemente contestaba que no sabía hacerlo, teniéndolo delante.

El síntoma es difícil de leer, porque **se parece a un modelo torpe**: responde «no tengo esa
información» y la tentación es cambiar de modelo o retocar el system prompt. La causa está una
capa más abajo.

Por eso el arreglo va **en la descripción de la skill, no en el system prompt**:

- El system prompt es común a todos los productos y actores; la descripción viaja sólo a quien
  tiene la skill, y se filtra con los roles (`SkillRegistry::paraActor()`).
- Anunciar allí una capacidad que el modelo no puede invocar —porque su rol no la incluye— es
  prometer lo que no se puede cumplir.
- Escala: si cada capacidad nueva engorda el prompt compartido, en veinte skills es ilegible y
  se paga en cada consulta.

**Al añadir una clave a `getMessageVariables()`, decide si se anuncia.** Si no aporta, mejor
recortarla de la salida: son tokens en cada llamada que nadie va a pedir.

### ✍️ Escribe la descripción para el modelo más tonto que la va a ejecutar

Las descripciones no se leen desde un modelo grande. En producción esto lo mueve un modelo
pequeño y barato —es una consulta de panel, no vale pagar Opus por «¿quién sale mañana?»—, y un
modelo pequeño **no rellena huecos**: no deduce que una skill devuelve el enlace de la guía
porque «tiene sentido», ni infiere que una evaluación positiva no se puede aplicar.

Lo que hay que dejar explícito, porque es justo lo que un modelo grande adivina y uno pequeño no:

- **Los campos que devuelve, por su nombre.** Ver el apartado anterior.
- **Las claves de encadenado.** `listar_entradas_salidas` devolvía `reserva_id` y `evento_id`
  sin anunciarlos: se podía ir de «quién sale mañana» directo a marcarle la salida tardía, y el
  modelo volvía a buscar al huésped por su nombre.
- **Lo que la skill NO hace**, si el nombre sugiere más de lo que cubre.
- **Cómo se presenta un dato ambiguo.** `tarifa_base` es por noche y orientativa; sin decirlo,
  se multiplica por las noches y se presenta como precio final.

#### Si los parámetros no expresan la pregunta, el modelo improvisa

`listar_entradas_salidas` nació con un solo parámetro de tiempo, `dias`, contado **siempre desde
hoy**. Con eso, «¿quiénes salen mañana?» no se puede pedir: hay que pasar `dias: 2` y descartar
las filas de hoy por fuera.

Lo caro no es el filtrado extra, es **el distractor**. Probado con datos reales el 5 de agosto:
la respuesta a `dias: 2` traía exactamente una fila —Dennis Chacon, que salía **hoy** a las
17:00— porque mañana no salía nadie. La respuesta correcta era «nadie», y lo que el modelo tiene
delante es un nombre con su casita y su hora. Contestar «mañana sale Dennis de la Casita 4» es
el resultado probable, y es un error que acaba dicho a un huésped.

La regla: **si una pregunta habitual no se puede expresar con los parámetros, el hueco lo rellena
el modelo — y lo rellena mal.** No se arregla en el prompt: se añade el parámetro. Ahora hay
`desde`, y «mañana» es `desde: <mañana>, dias: 1`, que devuelve `total: 0` sin nada que
malinterpretar.

Se descubrió ejecutando la skill, no leyéndola. Merece la pena probar cada skill nueva con la
pregunta **cuya respuesta correcta es «ninguno»**: es donde se ve si la salida induce a error.

#### Capacidad va en la descripción; vocabulario, en el prompt

La regla del apartado anterior —*el arreglo va en la descripción, no en el system prompt*— tiene
una frontera que conviene no borrar:

| Qué es | Dónde va | Por qué |
|---|---|---|
| **Qué puede hacer o devolver una skill** | `definicion()->descripcion` | Es de esa skill, y se filtra por rol: anunciarlo en el prompt compartido se lo promete a quien no puede invocarla |
| **Cómo habla el equipo** | `PanelAssistant::systemPrompt()` | Aplica igual a las 12 skills. Repetirlo en cada una son 12 sitios que se desincronizan |

El caso real: **«pasajero» y «huésped» son la misma persona.** El negocio opera también como
agencia, así que los dos términos se mezclan a diario — pero las 12 skills dicen «huésped» y
ninguna dice «pasajero». No es un sinónimo del diccionario que el modelo deba adivinar: es
vocabulario de esta casa, y lo mismo vale para «la 1» como nombre de la Casita 1.

Va en el prompt del panel y **no** en el del chat del huésped
(`AiConversationProcessor::systemPrompt()`): un huésped habla de «mi reserva», no se llama a sí
mismo pasajero. Es jerga de operación interna.

#### ⚠️ El caso que costó: evaluar decía que sí y aplicar no podía

`evaluar_cambio_horario` acepta cuatro cambios; `aplicar_cambio_horario` sólo aplica dos
(`salida_tardia`, `entrada_temprana`). Para «que se quede un día más» sobre una reserva directa,
la evaluación devolvía **`permitido: true`** y remataba con «antes de aplicarlo, comprueba la
disponibilidad» — una invitación a aplicar algo inaplicable. Al llamar a `aplicar_cambio_horario`
salía un error de validación.

Un modelo grande lee el error y lo explica. Uno pequeño reintenta con otro valor, o —peor— da el
cambio por hecho porque la evaluación dijo que sí.

La causa es que **`permitido` responde a una pregunta de negocio y el modelo lo leía como una de
capacidad**. Por eso la respuesta lleva ahora un `aplicable_por_el_agente` explícito en las tres
ramas, y la descripción de `evaluar_cambio_horario` obliga a mirarlo. La regla general:

> Cuando el dominio permita algo que ninguna skill implementa, dilo **en la respuesta**, con la
> salida alternativa. El silencio se lee como permiso.

Presente en dos sitios a la vez, que hay que mantener sincronizados: `evaluarMoverFecha()` /
`evaluarHorarioExtra()` en `EvaluarCambioHorarioSkill`, y el `in_array()` de
`AplicarCambioHorarioSkill::ejecutar()`. **Si algún día se implementa mover fechas, hay que tocar
los dos** o el aviso mentirá al revés.

### 🔑 Lo que no puedes usar, ni se te menciona

`SkillRegistry::paraActor()` filtra **antes** de construir la petición. No es que el modelo lo
pida y se le deniegue: es que no existe en su contexto. No puede invocar algo que no sabe que
hay, y de paso se ahorran los tokens de su definición.

### Todos los que preguntan son actores con rol

| Constructor | Quién | Roles |
|---|---|---|
| `AgentActor::delPanel($user)` | Empleado con sesión | Los suyos |
| `AgentActor::delEquipoPorChat($user, …)` | Empleado desde su móvil | Los suyos |
| `AgentActor::huesped($origen, $tipo, $id)` | Quien escribe sin ser del equipo | `ROLE_HUESPED` |

**El huésped no es un caso sin permisos**: es un actor más. Lo que lo distingue es que sus
skills están acotadas a su reserva por el contexto.

**El patrón importa más que la comprobación.** `ConsultarMiReservaSkill` no recibe *qué*
reserva consultar — la saca del contexto. Un huésped no puede pedir la de otro porque **no hay
parámetro donde escribirlo**. Por eso `BuscarReservaSkill` es una skill **distinta** y no la
misma con otro permiso: fundirlas obligaría a comprobar en ejecución si el parámetro está
permitido, que es el `if` que se olvida al añadir la siguiente.

`BuscarReservaSkill` nunca elige entre varias coincidencias: las devuelve todas para que el
modelo pregunte. Con dos Carlos González, adivinar es peor que preguntar — y cuando la skill
sea de escritura, adivinar significará mover la reserva equivocada.

### El riesgo escala con el daño

| `NivelRiesgo` | Qué toca | Trato | ¿Llega al chat del huésped? |
|---|---|---|---|
| `Lectura` | Nada | Se ejecuta sin preguntar | Sí |
| `Interna` | Sólo hacia dentro: avisos al equipo, marcas de pendiente | Se ejecuta sin preguntar | **Sí** |
| `Escritura` | Datos de la reserva o de la cuenta | Se propone y se espera un «sí» | No |
| `Destructivo` | Destruye datos | Se propone **y se pide PIN** | No |

La confirmación no desconfía del usuario: protege de que **el modelo se equivoque de persona**.

#### 🔥 `Interna`: por qué el filtro no es «¿escribe algo?»

Por el chat del huésped se pasa `permitirEscritura: false` — una escritura ahí tendría que
confirmarse y no hay a quién preguntar. Con dos niveles, ese filtro era `nivelRiesgo() !==
Lectura`, y eso dejaba fuera **justo la herramienta que más falta le hace al huésped**:
`escalar_al_equipo`. La skill declaraba en su propio docblock que el huésped la dispara y que
no pide confirmación, y aun así nunca se le ofrecía. El resultado medido: un huésped preguntaba
algo que el bot no sabía, recibía el acuse de recibo genérico… y **no se avisaba a nadie**.

El huésped es quien tiene el problema; es el primero que tiene que poder levantar la mano.

`NivelRiesgo::Interna` separa las dos cosas que estaban fundidas: *escribir* y *poder hacer
daño*. El filtro pregunta por `NivelRiesgo::exigePermisoDeEscritura()`, no por la igualdad con
`Lectura` — un método y no una comparación suelta, para que el día que aparezca otro nivel el
criterio se cambie en un sitio y no en cada punto que filtre.

La asimetría está pensada: **el daño de un aviso de más es que un operador mire un chat que no
hacía falta; el de una escritura de más es un dato falso en la reserva de alguien.**

⚠️ Al declarar una skill como `Interna` hay que poder responder que sí a las dos: ¿escribe
*sólo* en estructuras internas (mensajes al equipo, `unreadCount`, marcas del panel)? ¿Y el
peor caso posible es que alguien pierda un minuto? Si una de las dos falla, es `Escritura`.

### 🔑 `sin_skill`: la misma respuesta, dos políticas

Cuando el motor responde **sin invocar ninguna skill**, ahí no se consultó nada: o era pura
cortesía, o falta la capacidad. `ConversationResponse::sinSkill()` lo marca, pero **el motivo
no distingue las dos cosas**, y de ahí la regla:

> `sin_skill` sirve para **medir, no para censurar**. Ningún canal debe tirar el texto por venir
> marcado así.

| Canal | Qué hace |
|---|---|
| **Panel** | Lo muestra |
| **Chat del huésped** | Lo entrega igual, y lo registra en el log con el texto entero |

El chat del huésped **se lo calló durante un tiempo** y acabó contestando «un compañero te
responderá en breve» a un «hola»: ver §9, *El saludo se entrega*. Por eso el motor **informa y
no decide** — pero decidir no es tirar.

Los `sin_skill` acumulados en el log son la lista de skills que faltan por construir, ordenada
por frecuencia real de uso.

⚠️ Ese valor diagnóstico **se pierde si el prompt del huésped lleva datos**: entonces un
`sin_skill` puede significar «respondió bien, de memoria» en vez de «faltaba una skill», y el
log deja de ser una lista de huecos. Es la otra razón por la que el volcado de la reserva salió
del prompt — ver §9, *El prompt del huésped NO lleva los datos de su reserva*.

### El bucle de decisiones

Para que el «¿cuál de los dos?» funcione hace falta memoria: el hilo lo mantiene el cliente y
viaja en cada petición (`historial`), así que el endpoint no guarda estado y el operador ve el
mismo contexto que el modelo.

No hace falta desconfiar de ese historial: los permisos salen del actor que se construye **en
el servidor** con la sesión. Lo peor que consigue un cliente manipulando su propio hilo es
confundirse a sí mismo.

### 🔗 Cadenas de skills

Las skills se **componen**. El encadenamiento no se programa: el tool runner deja que el
modelo llame una, lea el resultado y decida la siguiente. Lo que sí hay que hacer es
**diseñarlas para que encajen**.

«Carlos se va mañana a las 5» son tres eslabones, y ninguno es exclusivo de ese caso:

```
  buscar_reserva("Carlos")            → reserva_id  (¿varios? pregunta cuál)
        ↓
  buscar_estancias_de_reserva(id)     → evento_id, casita, fechas, es_ota
        ↓
  evaluar_cambio_horario(evento_id)   → permitido / prohibido + por qué
```

**Lo que hace posible la cadena es el `id`.** Cada skill que identifica algo devuelve
`reserva_id` o `evento_id`, y ese valor es lo que el modelo pasa a la siguiente. Sin él, cada
consulta muere en sí misma. Ojo: `getMessageVariables()` **no** trae el id —nació para
rellenar plantillas, donde un UUID no pinta nada—, así que las skills lo añaden a mano.

**Cardinalidad: no todas quieren un resultado.**

| Skill | Cuántos espera | Por qué |
|---|---|---|
| `buscar_reserva` | Idealmente **1** | Detrás suele venir una acción: con dos Carlos, hay que preguntar |
| `buscar_estancias_de_reserva` | 1 o varias | Una reserva puede tener dos casitas o un cambio a mitad |
| `listar_entradas_salidas` | **Muchas** | Devolver 20 filas es el resultado correcto, no algo a refinar |

La diferencia va en la descripción de cada skill, que es lo que el modelo lee para decidir si
repreguntar o listar.

**Evaluar y aplicar son skills distintas.** `evaluar_cambio_horario` no escribe: responde si
se puede y qué implicaría. Así el operador pregunta «¿puedo mover la salida de Carlos?» y
recibe un no razonado **sin que se haya tocado nada** — que es el 80% de las veces que se hace
esa pregunta. La versión que aplica será otra skill, con `NivelRiesgo::Escritura`, y podrá
apoyarse en esta evaluación en vez de repetirla.

**Las reglas del dominio viven en el eslabón que corresponde**, no en el prompt. Probado
contra datos reales sobre una reserva de Booking:

| Petición | Resultado |
|---|---|
| Mover la fecha de salida | ❌ *«el canal es la fuente de verdad y el cambio no se le enviaría»* (§9.4) |
| Salida tardía el mismo día | ✅ *«no cambia las fechas, sólo añade la noche que el horario extra inutiliza»* |

Esa asimetría —una OTA no admite mover fechas pero sí una salida tardía— es justo lo que un
prompt no debe tener que saber: la sabe la skill.

### El mismo mensaje, dos desenlaces según el rol

> «Quiero cambiar mi hora de salida»

| Quién lo dice | Qué pasa |
|---|---|
| **Huésped** | No tiene `RESERVAS_WRITE`, así que `aplicar_cambio_horario` **ni se le menciona**. El motor responde sin skill → acuse de recibo en su idioma y la petición queda para una persona |
| **Susan** (recepción con RW) | Ve la cadena entera y la recorre hasta el final |

Es **la misma conversación con dos catálogos**, no dos sistemas. Y el acuse de recibo va
escrito a mano, no generado: es precisamente la respuesta que se da cuando el modelo no tenía
con qué responder, así que pedírsela a él sería confiar en lo que acaba de fallar.

### La cadena completa, con escritura

```
  Susan: «Carlos se va mañana a las 5»

  buscar_reserva            → reserva_id
  buscar_estancias_de_reserva → evento_id, es_ota
  evaluar_cambio_horario    → permitido: salida tardía sí, mover fechas no si es OTA
  aplicar_cambio_horario    ✍️  «¿confirmas?» → sí → marca el flag
        ↓ PmsExtensionEstanciaService crea la extensión y el push la manda como bloqueo
  registrar_cargo           ✍️  «¿lo cobramos? 20 soles» → sí → cargo en la cuenta
```

**Marcar y cobrar son skills distintas** porque son decisiones distintas: hay late check-outs
de cortesía. El modelo encadena las dos sólo si el operador lo pide.

### ⚠️ La confirmación la decide el modelo: hoy NO hay freno en el código

Las skills de escritura llevan un parámetro `confirmado`. Con `false` devuelven la
previsualización y **no tocan nada**:

```
"aplicado": false,
"motivo": "falta_confirmacion",
"previsualizacion": "Se marcará la salida tardía de Susan Acuña en Casita 3, lo que
                     bloqueará esa noche también en los canales de venta."
```

Lo que sí es del código es **el contenido** de esa previsualización: sale de los datos reales
—incluida la conversión de moneda ya hecha—, no de lo que el modelo cree que va a pasar.

**Pero el paso previo no está forzado.** `confirmado` es un parámetro más que el modelo rellena:
`nivelRiesgo()` sólo se usa en `SkillRegistry::paraActor()` para incluir o excluir la skill del
catálogo, y ninguna parte del servidor recuerda si hubo previsualización. Una sola llamada con
`confirmado: true` registra el cargo o manda el WhatsApp. Lo único que lo evita es que la
descripción de la skill se lo pida al modelo.

Consecuencia directa: **la seguridad de las escrituras depende hoy de la capacidad del modelo**,
que es justo de lo que no debería depender. Ver §11.1 para qué modelo aguanta eso y para los dos
niveles de freno real que quedan por implementar.

### 11.1 Qué modelo aguanta este prompt

El catálogo son ~1.600 tokens en 12 descripciones. La dificultad no está en el tamaño, sino en
que **el coste de un error no es uniforme**:

| Zona | Si el modelo se equivoca | Suelo razonable |
|---|---|---|
| Lectura (disponibilidad, ocupación, cuenta) | Respuesta mala que el operador ve y contrasta | Modelo pequeño |
| Escritura sobre datos (`registrar_cargo`, `aplicar_cambio_horario`) | Cargo indebido, o un bloqueo que **viaja al canal** y retira la noche de todos los portales | Alto, mientras no haya freno |
| Escritura hacia fuera (`enviar_mensaje_huesped`) | Un WhatsApp a una persona real. **No se deshace** | Alto, mientras no haya freno |

Las trampas concretas de la parte de lectura ya están cubiertas en las descripciones: el rango
semiabierto, las tres skills de fechas que se parecen (§ `consultar_ocupacion`), `tarifa_base`
por noche, `aplicable_por_el_agente`. Fallan **ruidosamente**: un UUID mal copiado no valida y la
skill responde «no existe», que es un error visible y barato.

La escritura no falla ruidosamente, y ahí el modelo es hoy el único guardia.

#### Los dos frenos que faltan, por orden de valor

1. **Token de confirmación ligado al payload.** La primera llamada devuelve la previsualización y
   un token HMAC del payload normalizado; sólo ejecuta una llamada que traiga ese token y cuyos
   parámetros produzcan el mismo hash. Sin estado que guardar. El modelo deja de poder ejecutar
   sin haber previsualizado **exactamente esos** parámetros, y cambiar el importe entre los dos
   pasos invalida el token.
2. **Aprobación fuera del bucle.** El token evita el salto, pero no garantiza que un humano lo
   viera: el modelo puede previsualizar y confirmar en el mismo turno. Para eso la escritura
   tiene que suspenderse y volver al panel como acción pendiente que alguien pulsa.

Sólo el segundo es **independiente del modelo**. Con él, la elección de modelo vuelve a ser una
cuestión de calidad de respuesta y de coste — no de seguridad.

> **Regla:** no se compra un modelo más caro para cubrir un `if` que falta. Mientras el freno no
> exista, el modelo es parte del perímetro de seguridad, y eso hay que decirlo en voz alta.

### 💳 `registrar_pago`: la ambigüedad se pregunta, no se resuelve

`pms_pago_financiero.monto` guarda el **neto** —lo que abona la deuda— y la comisión va aparte
en `comisionPorcentaje`. Lo que pasó por el POS es `neto × (1 + pct/100)`.

Por eso **«pagó 20 soles con tarjeta» no es un dato suficiente**, y la diferencia es dinero:

| Lectura | Cobrado | Comisión | Abona |
|---|---|---|---|
| Los 20 son lo del POS | 20.00 | 1.04 | **18.96** |
| Los 20 son lo que abona | 21.10 | 1.10 | **20.00** |

La skill **no elige ni asume un defecto**: cuando el medio lleva comisión y no se ha dicho cuál
de las dos es, devuelve `falta_dato: 'importe_incluye_comision'` con **las dos cifras ya
calculadas** y una `pregunta` lista para trasladar al operador. Con los medios al 0% no pregunta,
porque coinciden.

Es el patrón general para datos que faltan y son caros de adivinar: **la skill conduce la
repregunta con números reales**, en vez de dejar que el modelo improvise una suposición o pida
un dato en abstracto.

Igual con el medio de pago: si no viene, se devuelve un error que enumera los válidos. Suponer
«efectivo» habría sido cómodo y falso.

#### 💱 «20» tampoco dice de qué moneda, y ése es el error caro

**304 de las 307 cuentas se llevan en USD**, pero se opera en Cusco y se cobra en soles a
diario. Así que «me pagó 20» es casi siempre 20 soles sobre una cuenta en dólares.

Caer a la moneda de la cuenta —el defecto obvio— registraría **20 USD en vez de 5.89**: un 240%
de más, a favor del huésped, y sin nada que chirríe en la respuesta. Por eso `MONEDA_LOCAL` no
es un defecto sino la referencia para detectar la ambigüedad: si la cuenta no está ya en PEN y
no dicen moneda, **se pregunta**.

Las dos dudas se piden **juntas** cuando faltan las dos: dos repreguntas seguidas por el mismo
pago son una de más.

```
"falta_datos": ["moneda", "importe_incluye_comision"],
"si_son_pen":  "20.00 PEN = 5.89 USD (tipo 3.394)",
"si_son_usd":  "20.00 USD",
"pregunta":    "Pregúntale al operador: ¿los 20.00 son soles o USD?...; y con
                Tarjeta de Crédito hay 5.5% de comisión, ¿el importe es lo que
                se pasó por el POS o lo que debe abonar a la deuda?"
```

#### 🧑 «le pagó a María»: el tercer dato que se pregunta

Quién **recibió** el dinero (`PmsPagoFinanciero.cobrador`, §11.5.1 de
`PmsBeds24ReservasSync.md`). Entra en el mismo bloque `falta_datos` que la moneda y la comisión,
por la misma razón: las repreguntas van juntas.

Sólo se pide si `PmsMedioPago::seCobraEnMano()` — en una transferencia no hay a quién apuntar.
La skill resuelve el nombre suelto («María») por coincidencia parcial contra el personal con
`ROLE_COBRADOR`, y **no adivina en ninguno de los dos extremos**: ni con 0 coincidencias (no
existe) ni con 2+ (dos Marías). Apuntar el efectivo a la persona equivocada descuadra la caja de
las dos.

**🔥 Gotcha: el motivo se pasa hecho, no se deduce de la lista de candidatos.** Son tres casos con
tres redacciones (`sin_indicar`, `no_encontrado`, `ambiguo`), y en `no_encontrado` la lista de
candidatos viene con **todos** los cobradores —para poder ofrecerlos—, así que mirarla para
decidir el mensaje producía esto:

```
❌ "«María» coincide con varias personas (Jorge Gomez, Susan Acuña, Web Admin)"
```

…tres nombres que no son María, en un caso donde María no existía. Por eso `pregunta()` recibe
`$porqueFaltaCobrador` explícito en vez de inferirlo de `$candidatos`.

⚠️ **El agente y el panel filtran por su cuenta y hay que mantenerlos a la par** — ver la tabla de
§11.5.1. Y el filtro es por la columna `roles` literal: `enabled` NO cuenta, porque la limpiadora
que cobra en la casita no tiene login.

**Tres formas de decirlo, tres caminos** (detalle y tabla en §11.5.1 del doc de sync):

```
cobrador="María"  → búsqueda parcial entre los ROLE_COBRADOR
cobrador="yo"     → ActorInterface::usuario(), el operador que maneja el chat
(omitido)         → User::$esCobradorPrincipal (recepción)
```

`ALIAS_YO` recoge las formas del «lo cobré yo». El modelo traduce a `"yo"` en vez de teclear el
nombre del operador —que no siempre sabe— o dejarlo caer en el principal, que sería atribuirle a
recepción un dinero que recogió otra persona.

**`ActorInterface::usuario()` se añadió para esto.** Estaba sólo en `AgentActor` como propiedad
pública; ahora está en el contrato porque una skill recibe la interfaz, no la clase concreta, y la
alternativa era un `instanceof` en cada una. Es quien MANEJA el chat, que no siempre es quien hizo
la acción del mundo real.

Para probarlo hay `--usuario=<username>` en `app:agent:skill`: ejecuta con la identidad y los roles
de una persona real, en vez del SUPER_ADMIN sintético de siempre. Sin eso, el camino del «yo» no
era verificable desde la consola.

#### 🔥 La jerarquía de roles y el agente: `AgentActorFactory`

`User::getRoles()` devuelve la columna literal más `ROLE_USER` — la jerarquía de `security.yaml`
no está ahí. En el panel da igual, porque Symfony la expande al evaluar `is_granted()`, pero el
agente comprueba permisos por su cuenta comparando contra la lista del actor. Sin expandir:

```
Susan tiene ROLE_RESERVAS_DELETE
  panel   → registra pagos (DELETE ⊃ WRITE ⊃ SHOW)
  agente  → NO podía: registrar_pago pide ROLE_RESERVAS_WRITE y no lo tenía literalmente
```

Susan y Jorge son los dos operadores reales y ambos llevan roles `*_DELETE`: **ninguno podía usar
`registrar_pago` ni `registrar_cargo` por el chat**. El síntoma era desconcertante porque
`app:agent:permisos` no lo veía — prueba con perfiles sintéticos que llevan el rol exacto que se
comprueba, nunca uno heredado.

Lo arregla `AgentActorFactory`, que expande con `RoleHierarchyInterface` al construir el actor.
**Constrúyelos siempre por ahí**: `AgentActor::delPanel()` a secas se queda en los literales, y
el parámetro `$rolesEfectivos` sólo es opcional para actores sintéticos de prueba.

⚠️ **Escribir el registro y estar autorizado a cobrar son permisos distintos, y la expansión no
los mezcla.** Un operador con `ROLE_RESERVAS_DELETE` apunta el pago que recibió otra persona; sólo
figura como cobrador quien tenga `ROLE_COBRADOR`, que se comprueba sobre la **columna literal** —y
por eso `tieneRol()` no vale aquí: a un `SUPER_ADMIN` le abriría esa puerta como le abre las demás.

```
Jorge (RESERVAS_DELETE, sin COBRADOR)
  cobrador="maria" → ✅ "cobrado_por": "Maria Apaza"
  cobrador="yo"    → ❌ "Jorge Gomez no tiene el rol de cobrador"
```

#### 🔎 Dos caminos hacia una reserva, y por qué NO comparten reglas

Se confunden fácil, así que conviene tenerlo claro:

| | Huésped | Operador |
|---|---|---|
| Cómo llega | Su número → `PmsReservaRepository::findVivasByTelefono()` | Escribe un nombre → `buscar_reserva` |
| Qué filtra | Estricto: estados vivos, ventana temporal, una sola | Sólo esconde las **canceladas**, y de forma reversible |
| Por qué | **Identificación automática**: nadie ha pedido esos datos, así que la puerta se abre lo justo | **Búsqueda explícita**: el operador pide lo que pide, y esconderle cosas es fricción |

Aplicarle al operador las reglas del huésped sería un error: pregunta por historial a diario
(cobros, facturas, un huésped que ya se fue) y una ventana temporal le escondería justo eso.

##### Las canceladas se esconden, pero no se pierden

Los errores de guardado dejan duplicados vacíos: un huésped real tenía **6 reservas canceladas de
un evento cada una** junto a la buena, y `buscar_reserva` devolvía las 7 con su bloque financiero
completo — ~11 KB para una respuesta de una línea, y seis bloques que el modelo debe descartar en
cada consulta.

```
buscar_reserva "Adrian Tolsba"                          → total 1, canceladas_ocultas 6
buscar_reserva "Adrian Tolsba" incluir_canceladas=true  → total 7
```

Tres salidas, y ninguna pierde información:

1. **Hay activas** → se devuelven, con `canceladas_ocultas: N` para que el agente lo mencione.
2. **Sólo hay canceladas** → `total: 0` **con un aviso** que dice cuántas hay y pide al agente
   preguntar. Sin eso el modelo diría «no existe esa reserva», que es falso y deja al operador
   sin saber que sí existe.
3. **El operador insiste** («entre todas», «en el historial», «aunque esté cancelada») →
   `incluir_canceladas=true`.

Las **pasadas sí salen siempre**: se consulta historial a menudo. Lo que cambia es el ORDEN
(`ordenarPorPertinencia()`): alojado ahora → por llegar → ya se fue. El `ORDER BY fechaLlegada
DESC` de la consulta ponía arriba la reserva más lejana en el futuro, así que quien está alojado
HOY salía por debajo de alguien que llega en tres meses.

⚠️ Ese orden mira los agregados `fechaLlegada`/`fechaSalida` de la reserva, no los eventos: aquí
sólo se ordena una lista corta que el operador va a leer entera. Para decidir **quién tiene
derecho a ver qué** está `findVivasByTelefono()`, que sí baja al detalle de los tramos.

#### 📱 Atar al huésped con su reserva cuando escribe en frío

`ConsultarMiReservaSkill` **no recibe qué reserva consultar**: la saca de
`ActorInterface::contextoId()`, que es el contexto de la conversación. Ahí está la frontera —un
huésped no puede pedir la reserva de otro porque no hay parámetro donde escribirlo—, pero también
la dependencia: **sin contexto de reserva, la skill no tiene nada que responder.**

Y eso era justo lo que pasaba. `WhatsappMetaReceivePersister::resolveConversation()` buscaba una
conversación ABIERTA por teléfono y, si no la había, creaba una `('manual', $phone)`. Un huésped
que escribía por primera vez desde su móvil caía siempre en `manual`, y a «¿cuánto debo?» recibía
*«esta conversación no está asociada a ninguna reserva»*.

Ahora, antes de crear el walk-in, se busca reserva viva por el número
(`PmsReservaRepository::findVivasByTelefono()`) y la conversación nace ya como `pms_reserva`.

##### Qué reserva cuenta

Se exige al menos un evento en `PmsEventoEstado::IDENTIFICAN_HUESPED` — **pendiente, confirmada o
requerimiento**—, lo que descarta de un golpe los casos en que no hay a quién contarle nada:

| Fuera | Por qué |
|---|---|
| Todos los eventos `cancelada` | La reserva murió; los datos siguen ahí pero ya no es huésped |
| `bloqueo` | No hay huésped: es la casita cerrada por nosotros o por el canal |
| `abierto` | Un inquiry de Airbnb es una CONSULTA, no una reserva |
| `extension` | La noche fantasma de un horario extra (§7.1.b); su reserva ya entra por el tramo que la generó |
| Terminadas hace más de una semana | `DIAS_GRACIA_TRAS_SALIDA` |

⚠️ `IDENTIFICAN_HUESPED` coincide hoy con `OCUPAN_UNIDAD` y **aun así se declara aparte**, por el
mismo motivo por el que aquella se separó de `IMPIDEN_VENTA`: responden preguntas distintas. Una
dice «¿pinto esta noche como ocupada?»; ésta, «¿le enseño datos privados a quien escribe?». Si
algún día un `bloqueo` pasara a pintar ocupación, derivar una de otra abriría la cuenta de un
huésped a quien reservó una casita cerrada por mantenimiento.

##### Cuál se elige si hay varias

```
1. La ACTUAL   — un tramo en curso hoy (inicio <= hoy <= fin).
                 Si hay varias, la de entrada más reciente: la que está viviendo.
2. La PRÓXIMA  — sin ninguna en curso, la de entrada más cercana.
```

Comprobado con un mismo número en cuatro situaciones a la vez (hoy = 06-08):

```
Joaquin      confirmada 07-24→07-29   ← fuera: acabó hace más de una semana
Rutaba       confirmada 07-29→08-07   ⇒ ELEGIDA (en curso)
Catherine    pendiente  08-09→08-18      (queda de segunda)
Ramos G.     cancelada  09-01→09-07   ← fuera: cancelada
```

Quitando la actual, cae sola en Catherine. Quitando las dos, `manual`. Y una reserva cuyo único
evento vivo es `abierto` no identifica ni estando en curso.

**El filtro va sobre los EVENTOS, no sobre `fechaLlegada`/`fechaSalida` de la reserva:** esos dos
son agregados (mín/máx de sus tramos), así que una reserva con un tramo cancelado y otro vivo
daría una ventana que no corresponde a ningún tramo real.

⚠️ **Se elige, pero queda rastro.** Cuando hay más de una candidata se escribe un `info` en el log
con la elegida y cuántas había: es el caso en el que un huésped podría acabar viendo la reserva de
otro —en producción hay un número con 7 reservas de 2 personas distintas, porque aquí también se
opera como agencia—, y sin esa línea no habría forma de saber que la decisión se tomó.

Detalles que no se ven en el código:

- Se busca en `telefono` **y** `telefono2`, sin mirar `telefono2EsPrincipal`: ese flag dice a cuál
  escribimos NOSOTROS (§12.10 del doc de sync), no desde cuál escribe él. Comprobado: escribiendo
  desde cualquiera de los dos, la reserva se identifica igual.

  ⚠️ **Hoy es teórico**: de 309 reservas en producción, 279 tienen teléfono y **una sola** tiene
  `telefono2` —idéntico al primero, porque Beds24 mandó `phone` y `mobile` iguales—. Ninguna
  tiene un segundo número distinto ni el flag activado. Conviene saberlo antes de invertir en
  este camino: la cobertura está, pero el dato no.

  Y si empezara a usarse, aparece un efecto que **no** resuelve esta búsqueda:
  `resolveConversation()` empareja por `guestPhone` (exacto o los últimos 8 dígitos), así que dos
  números distintos de la misma reserva abren **dos conversaciones** con el mismo `contextId`.

  Con un acompañante eso es hasta deseable —son dos personas y se responde a cada una por su
  hilo—. Pero en el caso del **chip local**, el titular acaba con dos conversaciones suyas: una
  con el número de origen y otra con el peruano, y el historial de lo que ya se habló se queda en
  la primera. Ahí sí molesta, y unirlas sería otra decisión (no un arreglo de esto): habría que
  decidir si se fusionan los hilos o si el segundo hereda el contexto del primero.

  **Qué es `telefono2` en la práctica** (y por qué identificarlo por él es correcto, no un
  agujero): es siempre alguien del mismo grupo, no un externo —

  - el **acompañante** que está dentro de la casita,
  - un número que **sí funciona en WhatsApp** cuando el primero no,
  - el **chip local** que sacaron al llegar a Perú, en vez del de roaming.

  En los tres casos quien escribe está alojado y tiene derecho a su guía, sus códigos y su
  cuenta. El tercer caso además explica por qué existe `telefono2EsPrincipal` (§12.10 del doc de
  sync): durante la estancia, el número peruano suele ser el bueno para escribirle, aunque la
  reserva llegara con el extranjero.
- La coincidencia es exacta **o por los últimos 8 dígitos**, el mismo criterio que ya usaba
  `resolveConversation()` para las conversaciones: los números de las OTA llegan sucios, a veces
  con el código de país duplicado.
- El ORDEN se resuelve en PHP y no en un `ORDER BY`: hace falta el tramo relevante de cada
  reserva (el que está en curso, o el próximo), y con el JOIN a eventos una reserva de varias
  casitas trae varias filas — ordenar en SQL mezclaría los tramos de unas con los de otras.
  `tramoRelevante()` repite los mismos filtros que la consulta a propósito: sin eso, una reserva
  con un tramo cancelado antiguo y otro confirmado futuro se ordenaría por el cancelado.
- Al identificarla, el nombre y el idioma salen de la RESERVA, no del perfil de WhatsApp: en el
  panel se busca por el nombre con el que está reservado, y al huésped se le escribe en su idioma.

⚠️ **Sólo aplica a conversaciones NUEVAS.** Las `manual` que ya existen no se promueven solas: si
una conversación abierta coincide por teléfono, `resolveConversation()` la reutiliza y nunca llega
a esta rama. Un huésped con una `manual` viva seguirá sin poder consultar su cuenta hasta que
alguien la ate.

#### 🏘️ Crear reserva vs añadir estancia: dos skills, un creador

`crear_reserva` monta huésped + primera estancia. Pero «al grupo de Ana súmale la casita 2» o «se
cambia a la 5 las dos últimas noches» no es eso: el huésped ya está, con su nombre, idioma y
teléfono. Para eso está `crear_estancia`.

Son dos skills y no una con un parámetro opcional porque fundirlas obligaría a decidir en
ejecución si el `reserva_id` que llega significa «añade aquí» o «ignóralo y crea otra» — y
equivocarse en esa lectura **duplica la reserva del mismo huésped**, que es el error más caro y el
más difícil de deshacer.

Cubre tres casos reales del parque, que hasta ahora sólo se hacían desde el calendario:

1. Grupo que ocupa **varias casitas** las mismas fechas (una reserva, N estancias).
2. **Cambio de casita a mitad**: dos tramos consecutivos en unidades distintas.
3. **Alargar en otra casita** porque la suya está vendida esas noches.

##### Lo difícil está en `PmsEstanciaCreator`, compartido

Horas del establecimiento, disponibilidad, estados maestros y el **canal DIRECTO** los resuelve un
servicio único. El canal es el que más silenciosamente rompe: sin él,
`PmsCargosAutomaticosService::aplica()` no genera cargos y la estancia nace gratis sin que nada lo
diga. `crear_reserva` lo hacía por dentro; ahora las dos pasan por el mismo sitio, que es lo que
pedía el propio docblock del servicio.

##### Lo que la previsualización enseña y no es evidente

- **Las estancias que la reserva YA tiene.** Evita el error de añadir una casita a quien ya la
  tenía porque nadie miró la lista.
- **Que las fechas de la RESERVA se recalculan** — son el mín/máx de sus tramos—, y con ellas los
  hitos del motor de reglas: **cuándo le llegan al huésped la guía de llegada y el aviso de
  salida**. Sólo se avisa si el tramo nuevo de verdad las mueve; si cae dentro, decirlo sería ruido.

##### Los rechazos

| Caso | Respuesta |
|---|---|
| Sin `adultos` | `falta_datos` — y avisa de **no deducirlo** de la reserva: en un grupo repartido no es el mismo número por casita |
| Casita ocupada | Error con quién la ocupa y las fechas, antes de pedir ningún dato más |
| Reserva **cancelada** | Error: conserva nombre, fechas y chat, así que nada la delata; añadirle una estancia la resucitaría a medias |

#### ✏️ Corregir una reserva: `modificar_reserva`

Se podía crear una reserva y añadirle estancias, pero no **arreglarla**. Un teléfono mal
apuntado, «al final vienen tres» o el caso más frecuente —«paga al llegar»— obligaban a entrar al
panel. Cubre teléfono, `telefono2`, correo, idioma, adultos, niños y estado de pago.

##### 🔑 `pago-alojamiento` no es un dato suelto: confirma y abre la guía

Es la cadena que motivó la skill, y **no se ve desde ninguno de sus eslabones**:

```
estadoPago = pago-alojamiento (o parcial, o total)
  → requiereAutoConfirmacionPorPago()          ⇒ estado = confirmada   (§9.5 doc de sync)
  → PmsGuiaAcceso::paraEvento() deja de dar SinPago
  → PmsGuiaAccesoEstado::pagoConfirmado()      ⇒ se abre el nivel ClienteConfirmado
```

Marcar que paga en el alojamiento **confirma la estancia y le desbloquea contenido de la guía**.
Es lo que se quiere —quien paga al llegar tiene derecho a sus instrucciones—, pero aprobar
«cambio el estado de pago» sin saber las otras dos no es aprobar lo que pasa. Por eso van las tres
en `que_va_a_pasar`, incluida la que más descuadra si se olvida:

> NO se apunta ningún importe: esto marca el estado, no el dinero. Si de verdad recibiste un pago,
> regístralo con `registrar_pago` o la cuenta quedará descuadrada.

##### Lo que deja fuera, y por qué

| Fuera | Motivo |
|---|---|
| **Cancelar** | Dispara el push de cancelación al canal y toda la cadena de avisos (§12.12). Se hace en el calendario, viendo lo que se lleva por delante |
| **Fechas y casita** | Es reprogramar, no corregir; en OTA está prohibido |
| **Nombre del huésped** | Cambiarlo suele ser señal de que se está tocando la reserva equivocada |

##### Un ámbito por campo

Teléfono, correo e idioma son del **huésped** → viven en la reserva. Adultos, niños y estado de
pago son de la **estancia**, y por eso con varias casitas se pregunta cuál: en un grupo repartido
cada casita tiene su gente y puede tener su propio estado de pago. La casita **sólo** se pregunta
si se toca alguno de esos tres — pedirla para corregir un email sería absurdo.

⚠️ **El teléfono se guarda tal cual llega.** Lo normaliza `PmsReservaIntegrityListener` antes del
INSERT (§12.9.b del doc de sync); sanearlo aquí sería una segunda verdad que se desincroniza.

⚠️ **La previsualización llama a los setters** para poder describir el «antes → después», y luego
hace `refresh()` de las entidades. Sin eso, un flush de cualquier otra cosa en la misma petición
guardaría cambios que nadie aprobó. Verificado: tras previsualizar, la BD queda intacta.

#### 🔔 «Te responderá una persona»: `escalar_al_equipo`

Esa frase era **sólo texto**. El agente la decía —a veces porque una skill se lo sugería, a veces
por su cuenta— y no pasaba nada más: ni marca, ni aviso, ni forma de preguntarle al sistema qué
conversaciones tenían algo prometido. Se cumplía porque alguien miraba el chat, no porque el
sistema lo recordara.

Existía **un solo** camino automático, `AiConversationProcessor::generar()`: cuando el modelo no
usaba ninguna skill (`motivo === 'sin_skill'`) se devolvía un acuse de recibo traducido a 7
idiomas… y un `logger->info()`. El WebPush al equipo
(`MessageConversationMercureListener`) se dispara con **cualquier** mensaje entrante, así que es
el mismo aviso para un «hola» que para una promesa pendiente.

⚠️ Y ese camino **nunca fue un escalado**: el acuse prometía una persona sin avisar a ninguna.
Hoy sale mucho menos —sólo cuando el motor no devuelve texto, ver §9, *El saludo se entrega*—
pero **sigue sin escalar**. Es lo que queda por cerrar aquí.

Ahora el escalado es un hecho con dos efectos, **en este orden a propósito**:

1. **La conversación queda sin leer** (`incrementUnreadCount`) y se reabre si estaba cerrada. Es
   lo que sobrevive aunque falle todo lo demás: no depende de ninguna red.
2. **WhatsApp a la guardia** — quien tenga `ROLE_CUSTOMER_SUPPORT` **y** móvil registrado— con el
   nombre del huésped, el motivo en una frase y el enlace directo al chat.

```
🔔 *Desiree Egg* necesita respuesta

Quiere salir a las 14:00 en vez de las 10:00 el sábado

👉 https://util.openperu.pe/chat?id=019f4e12-…
```

El enlace usa `?id=`, el mismo formato que el push de Mercure, para que la SPA auto-seleccione la
conversación: el operador toca y está dentro.

##### 🗓️ El aviso trae los márgenes de la estancia

Cuando llega «quiere salir a las 14:00», la primera pregunta del operador es siempre la misma:
*¿está vendida esa noche?*. Va en el propio aviso, así que puede contestar desde el móvil sin
abrir el calendario:

```
🔔 *César Hiroyasu* necesita respuesta

Quiere salir a las 14:00 en vez de las 10:00 el domingo

🏠 *Casita 1* (02/08 → 09/08)
   Noche anterior a su entrada: ⛔ ocupada (Yerzalif Onsueta Vasquez)
   Noche del día de su salida: ⛔ ocupada (Catherine Cochet)

👉 https://util.openperu.pe/chat?id=019ec6c6-…
```

Lo calcula `PmsDisponibilidadService::margenesDe()`, con la **misma regla que el resto del
servicio** (`IMPIDEN_VENTA` y solape por `DATE()`), así que un bloqueo por mantenimiento cuenta
como ocupado: no es vendible aunque no haya huésped. La noche que importa para un late check-out
es la **del día de salida**, que es la que ocuparía la extensión (§7.1.b del doc de sync).

Con varias casitas se listan todas, porque la respuesta puede ser distinta en cada una.

⚠️ **Sólo para el OPERADOR.** No se devuelve al huésped ni entra en `consultar_mi_reserva`
—verificado—: saber que su casita está libre mañana le hace dar por hecho que puede quedarse, y
eso no lo decide la disponibilidad sino una persona. La línea **informa, no autoriza**: libre
significa que la conversación puede seguir; ocupada sí es concluyente.

Lo propio no cuenta como ocupación ajena, y se compara **por reserva** y no por id de evento: las
extensiones son eventos aparte que cuelgan por `eventoOrigen`, y buscarlas una a una sería una
consulta extra por noche. Si el cálculo falla, el aviso sale sin esta línea — que llegue el
escalado importa más que el extra.

**Lo dispara el modelo**, no las skills: es quien sabe si acaba de prometer algo. No hay nada
determinista detrás — es su criterio, guiado por la descripción.

##### 🔥 …pero durante un tiempo el huésped no la tuvo

Se escribió para el huésped —`rolesRequeridos()` lleva `Roles::HUESPED` desde el primer día— y
aun así **no se le ofrecía nunca**. Estaba declarada `NivelRiesgo::Escritura`, y el chat del
huésped se abre con `permitirEscritura: false`, que entonces filtraba *todo* lo que no fuera
`Lectura`. Comprobado con `app:agent:permisos`: el perfil «Huésped (chat)» tenía 4 skills y
ninguna era ésta.

O sea: el prompt le ordenaba llamarla y la herramienta no existía en su contexto. El huésped
—que es quien tiene el problema y el primero que tiene que poder levantar la mano— sólo obtenía
el acuse de recibo genérico, y no se avisaba a nadie.

Se arregló con `NivelRiesgo::Interna`, no bajándola a `Lectura` ni abriendo la escritura del
canal: ver *🔥 `Interna`: por qué el filtro no es «¿escribe algo?»* más arriba en §11.

##### 🔥 La regla es PREGUNTAR vs PEDIR, no «lo sé / no lo sé»

La primera versión de la descripción decía «úsala para lo que no puedas resolver tú … **o
cualquier cosa que no esté en su guía**», y eso se contradice justo donde más duele.

Dos huéspedes preguntan lo mismo —«¿puedo quedarme hasta más tarde?»— y sus guías no son iguales:

| | Casita 1 | Casita 2 |
|---|---|---|
| Tema en la guía | ✅ «Horario de ingreso y salida» | ❌ no lo tiene |
| Qué sabe el agente | sujeto a disponibilidad, con coste, coordinar con 1 día | sólo la hora de salida |
| Qué hacía la descripción vieja | **le decía que NO escalara** (está en su guía) | escalar, obvio |

Es al revés de lo que parece: **el caso que más necesita a una persona es el que más información
tiene**. Su propia guía dice «coordínalo con un día de anticipación», o sea que exige a alguien
que mire si esa noche está libre. Y la paradoja es que cuanta más información tiene el agente,
más fácil es que se sienta capaz de cerrar el tema solo.

La regla correcta separa dos cosas que no son lo mismo:

```
PREGUNTAR → quiere SABER algo ya escrito («¿a qué hora es el check out?»)
            → lo responde el agente con la guía, NO escala
PEDIR     → quiere que PASE algo que depende de nosotros (salir tarde, cambiar
            fechas, una avería, un cobro raro)
            → escala SIEMPRE, aunque su guía explique las condiciones
```

Que la guía diga «sujeto a disponibilidad y con coste» le deja **contar las condiciones**, no
**concederlo**: nadie ha mirado si esa noche está vendida. Al revés, que la guía pida coordinar
es la prueba de que hace falta una persona.

##### El mecanismo estandarizado: la orden viaja en el DATO

Dejarlo en «criterio del modelo» no basta, porque la regla general hay que recordarla justo
cuando se acaba de leer un texto que parece resolverlo todo. Así que se marca en el ítem —
`PmsGuiaItem::$agenteRequiereHumano`, casilla «🔔 Requiere confirmar con una persona»— y
`consultar_guia` devuelve la instrucción **pegada al contenido**:

```
tema:          Horario de ingreso y salida
debes_escalar: "Cuéntale lo que dice la guía, pero NO se lo concedas: esto lo confirma
                una persona. Llama a escalar_al_equipo en este mismo turno…"
```

Se anuncia además en el **catálogo** (`necesita_confirmacion_humana`), para que el modelo pueda
decidir desde el índice y no después de haber redactado la respuesta.

Lo decide quien edita la guía, sin tocar código. Y el `help` del CRUD insiste en lo
contraintuitivo: **márcalo aunque el texto ya explique las condiciones**, que es justo cuando
hace falta.

Los temas informativos —la ducha, el wifi— no lo llevan y siguen resolviéndose solos.

##### 🪞 El system prompt iba en contra, y también se corrigió

El prompt del autoresponder es anterior a `escalar_al_equipo` y decía tres veces «ofrece que un
compañero lo confirme» / «dile que un compañero le atiende enseguida» **sin nombrar la skill**:
autorizaba la promesa y no daba el mecanismo. Ahora lleva la distinción preguntar/pedir y la
regla dura:

> ⚠️ SIEMPRE que le digas que alguien le va a contestar, llama a «escalar_al_equipo» en ese mismo
> turno. Si lo prometes y no la llamas, no se entera nadie y se queda esperando.

Y se le prohíbe explícitamente prometer plazos («enseguida», «en 5 minutos»), que es lo que decía
antes y no puede saber.

##### 🔁 La insistencia: lo que el flag del ítem NO puede cubrir

```
huésped:  «no sale agua caliente»
agente:   [manda las instrucciones de la ducha]        ← correcto
huésped:  «ya lo probé, sigue fría»                    ← ¿y ahora?
```

Sin regla, el modelo repite la misma explicación. Y repetir lo mismo a quien ya tiene un problema
es lo que más enfada.

**`agenteRequiereHumano` no sirve aquí**, y conviene entender por qué: ese flag es por TEMA, y el
ítem de la ducha vale para las dos situaciones —«¿cómo funciona?» (informar) y «no funciona»
(avería)—. Marcarlo haría escalar también a quien sólo pregunta cómo se abre el grifo. La
diferencia no está en el tema sino en el TURNO, así que la regla vive en el prompt:

> ⚠️ SI YA SE LO EXPLICASTE Y VUELVE A INSISTIR, no repitas la explicación: mira el historial. Que
> diga otra vez «sigue sin funcionar» o «ya lo probé» significa que las instrucciones no eran el
> problema — es una AVERÍA y necesita que alguien vaya.

El modelo tiene con qué cumplirla: `historial()` le pasa los últimos turnos, así que ve lo que ya
respondió.

Y en este caso concreto la propia guía lo respalda desde la primera respuesta —«las duchas
funcionan con gas propano… si se termina el agua caliente, es probable que el tanque esté vacío,
avísanos y lo reemplazaremos»—, así que el agente puede ofrecer el aviso ya de entrada en vez de
esperar a la segunda queja.

**Regla general que se ve aquí:** lo que depende del TEMA se marca en el dato
(`agenteRequiereHumano`); lo que depende de CÓMO VA LA CONVERSACIÓN sólo puede vivir en el prompt.

##### 🔥 La ventana de 24 h de Meta lo bloquea, y hay que saberlo

WhatsApp sólo deja mandar texto libre a quien te escribió en las últimas 24 h. Un operador de
guardia **no escribe al número del negocio todos los días**, así que lo normal es estar fuera de
ventana. Probado de punta a punta: el mensaje se crea y se encola bien, y muere con

```
Operación denegada. La ventana de 24 horas de WhatsApp ha caducado.
Para iniciar o retomar el contacto … DEBES seleccionar una Plantilla Oficial.
```

Para que el WhatsApp salga siempre hace falta **una plantilla de aviso interno aprobada por
Meta** con dos variables (huésped y enlace). No la hay: las 11 existentes son todas para
huéspedes. Es trámite externo, no código.

Por eso el orden importa: la **marca de no leído se pone primero y con su propio flush**, así que
el caso queda visible en el panel aunque el aviso no salga. Y la respuesta al modelo distingue
los dos mundos —`marcada_pendiente` siempre, `aviso_encolado` sólo si salió— para que no le
prometa al huésped un aviso que no existe.

##### Sin guardia configurada no se calla

Si nadie tiene el rol **y** teléfono, se marca igual y se devuelve una `advertencia` que le dice
al modelo que no prometa plazos, más un `warning` en el log. Es un fallo de configuración, no de
la skill, y esconderlo lo haría eterno.

##### Las conversaciones `staff`

`WhatsappMetaSendEnqueuer` saca el número destino de `MessageConversation::$guestPhone`, así que
para escribirle a un operador hace falta una conversación suya. Se crea la primera vez con
`contextType = 'staff'` y `contextId` = su UUID, y **no** se reutiliza `manual` —el walk-in de un
desconocido— porque quien filtre la bandeja tiene que poder separarlas: esto no es un huésped.

El teléfono se refresca en cada aviso: si el operador cambia de móvil, los siguientes van al
nuevo sin tocar nada.

#### 🔤 Encontrar al huésped: el eslabón del que cuelgan todos los demás

Casi toda cadena empieza en `buscar_reserva`. Si ahí no aparece nadie, no hay guía que enviar ni
pago que registrar — y los nombres reales del parque son **Théa Landeau**, **Aurélie
Landemaine**, **Ghiță Viorel**: acentos y grafías que nadie teclea igual dos veces.

Probado contra el dump, el `LIKE` sobre la cadena entera fallaba en cuatro casos cotidianos:

| Se escribe | Antes | Por qué |
|---|---|---|
| `Aurelie Landemaine` | ✅ | Las columnas son `utf8mb4_unicode_ci`: **los acentos ya casan solos** en MySQL |
| `landemaine aurelie` | ❌ | El `CONCAT` sólo contempla nombre-apellido |
| `Aurelie··Landemaine` | ❌ | Dos espacios. Un tropiezo de teclado, no otra búsqueda |
| `aurelie landemane` | ❌ | Una letra de menos dentro de la palabra |
| `aurely landemaine` | ❌ | Nombre mal escrito |

Se arregla en dos fases, y **no hacía falta ni full-text ni una extensión de MySQL**: son 307
reservas con 271 nombres distintos.

1. **Por palabras.** Cada token tiene que aparecer en nombre o apellido, en cualquier orden. El
   orden y los espacios dejan de importar. Los acentos **no se normalizan**: ya lo hace la
   collation, y hacerlo aquí sería trabajo duplicado.
2. **Por parecido.** Si no encaja nadie, se compara con `levenshtein()` en PHP contra los 271
   nombres, también invertidos, con tolerancia proporcional al largo y con techo — o «Ana»
   acabaría pareciéndose a cualquiera.

##### Para elegir hacen falta el estado y los tramos, no las fechas envolventes

Encontrar a la persona es medio trabajo; el otro medio es que el operador pueda **distinguir
entre sus reservas**. `getMessageVariables()` nació para rellenar plantillas de una reserva *ya
elegida*, así que da `checkin_date`/`checkout_date` —el envolvente— y **no da el estado**.

Con eso, los tres casos reales del dump son inelegibles:

```
Dheeraj Palakurthy        antes: dos filas idénticas, 21/05→24/05, casitas 6 y 7
  WXFM34  [cancelada]     ← la diferencia era ésta, y no se devolvía
  GMJ4UY  [confirmada]
      Casita 7  05-21 → 05-22
      Casita 7  05-22 → 05-24
      Casita 6  05-22 → 05-24

Karina Barrio  K5VUDA     misma casita en dos tramos con un hueco de un día
      Casita 7  03-27 → 04-02
      Casita 7  04-03 → 04-08

Jeremy Hart  9RSDXH       cuatro casitas a la vez — el envolvente decía «Casita 7»
      Casita 7 / 6 / 5 / 4   04-15 → 04-18
```

Por eso cada reserva devuelve ahora `estado` y `estancias` (casita, entra, sale y estado de cada
tramo). Los `bloqueo` y `extension` se excluyen: son el efecto de una salida tardía, no un tramo
del huésped, y al elegir sólo son ruido.

**Una reserva sin ningún tramo vivo es `cancelada`**, y eso manda sobre todo lo demás: lleva un
`aviso_cancelada` que la descripción obliga a decir lo primero. Sin él, «mándale su guía a
Dheeraj» tenía un 50% de acabar en una reserva muerta.

##### 🏷️ Plantillas: había que etiquetarlas para que el agente pudiera elegir

Las 11 plantillas estaban registradas con lo que hace falta para **enviarlas** (`code`, `name`,
`contextType`, `allowedSources`, un cuerpo por canal con su `is_active`) y sin nada de lo que
hace falta para **elegirlas**:

- **`name` es una etiqueta de panel, no un criterio.** Una se llama *«Autorespuesta ER130497»*,
  que a un modelo no le dice absolutamente nada.
- **`parameters` está vacío en las once.** No declara qué variables necesita.
- **Nada distinguía las automáticas.** Seis de las once las dispara el motor de reglas en su hito
  —`welcome_*` al crear, `recordatorio_llegada` al empezar, `check_out` y `despedida_*` al
  terminar—. Se sabe uniendo con `msg_rule`, o sea infiriendo, no leyendo.

Se añaden dos columnas a `msg_template` (migración `Version20260805190000`; la segunda,
renombrada por `Version20260807161847` al cambiar de significado):

| Campo | Para qué |
|---|---|
| `agente_uso` | **Cuándo usarla, escrito para el modelo.** Es lo único que lee para elegir |
| `autoenvio_habilitada` | Si el **huésped** puede pedírsela él mismo por el chat. **`false` por defecto** |

⚠️ **`autoenvio_habilitada` se llamó `agente_habilitada` y significaba otra cosa** («el agente
puede mandarla a petición de un operador»). Ese candado se quitó: **el agente puede mandar
cualquier plantilla con `agente_uso` escrito**, porque al operador ya lo protege su propia
confirmación —ve el texto y aprueba—, el uso de las automáticas dice en mayúsculas que LAS
MANDA SOLA el motor de reglas, y la correspondencia de OTA se valida por código
(`allowedSources`: la bienvenida de Booking al huésped de Booking, la de Airbnb al de Airbnb).
El flag renombrado guarda lo otro: el **autoenvío**, donde no hay operador que confirme.

`MessageTemplate::disponibleParaAgente()` (uso escrito) es la puerta del agente;
`disponibleParaAutoenvio()` (uso escrito **y** flag) es la del huésped. Se editan desde el
panel de plantillas («Asistente de IA»), así que habilitar una nueva no toca código.

##### El catálogo viaja en la descripción, compuesto al vuelo

`EnviarPlantillaSkill::definicion()` enumera las que tienen uso escrito leyéndolas de la
base — mismo recurso que el porcentaje de comisión en `registrar_pago`. Sin eso, elegir plantilla
sería adivinar un `code`.

Los frenos que quedan, por código:

```
sin agente_uso       → «no tiene escrito su “cuándo usarla”, así que no está en
                        circulación para el agente»
enviar_bienvenida    → «no existe… usa uno de: …»   ← códigos reales, no inventados
solicitar_numero_…   → «es sólo para reservas de booking o airbnb, y ésta vino de
   sobre una directa     «directo»»   ← respeta allowedSources
```

##### 📮 El autoenvío: `enviarme_plantilla`, la hermana del huésped

`EnviarmePlantillaSkill` (`Roles::HUESPED`, `NivelRiesgo::Interna`) es la misma idea con los
papeles invertidos: el huésped se pide un material a sí mismo — «mándame mi guía», «el menú de
tours». Es **acción propia**, como borrar tu propio post: el destinatario es quien pide, el
contenido está pre-aprobado y traducido, y el peor caso es recibir dos veces algo que él mismo
pidió. Por eso puede escribir «hacia fuera» siendo `Interna` y sin confirmación de nadie.

Sus tres candados, en orden:

1. **El destino no es un parámetro**: la conversación sale de `contextoId()` del actor, patrón
   `consultar_mi_reserva`. No hay UUID con el que apuntar al chat de otro.
2. **Sólo la lista corta**: `disponibleParaAutoenvio()`. «No existe» y «existe pero no es de
   autoenvío» dan el mismo error a propósito: al modelo no le incumbe la diferencia.
3. **La misma correspondencia de OTA** que la skill del operador.

El mensaje sale como `SENDER_SYSTEM`, **no** `SENDER_HOST`: HOST activaría el guardia de
«humano al mando» y callaría al bot 30 minutos justo después de atender bien.

La previsualización enseña **el texto que va a recibir el huésped**, en su idioma, no sólo el
nombre de la plantilla: aprobar «se enviará enviar_guia» a ciegas no es aprobar nada.

##### No confundir con `SendTemplateActionHandler`

Existe desde antes en `src/Agent/Action/` y hace algo distinto: lo dispara un mensaje **entrante**
vía regla, sin humano en medio, y puede **forzar** un canal. `EnviarPlantillaSkill` la pide un
operador y pasa por aprobación. Son los dos caminos de la §8 —determinista y no determinista— y
conviven.

#### 🚫 Una reserva cancelada no se nota en ninguna otra parte

Probado con WXFM34, la reserva cancelada de Dheeraj: **el chat sigue vivo**
(`estado_chat: closed`, que no bloquea nada), el teléfono es válido, y
`ChannelEnqueuerInterface::isValid()` da `whatsapp_meta` como **disponible**. Nada en la cadena
`localizar_conversacion → enviar_mensaje_huesped` delataba que no hay estancia: se le podía
mandar la guía de llegada a alguien que ya no viene, y eso lo ve el huésped.

El `aviso_cancelada` de `buscar_reserva` no bastaba, porque **la cadena no lo arrastra**: quien
llega con un `reserva_id` de `consultar_ocupacion` o de `listar_entradas_salidas` nunca pasó por
ahí. Por eso el aviso se repite en los dos eslabones siguientes, cada uno con el suyo.

**Se avisa, no se bloquea.** Hay envíos legítimos a una reserva cancelada —confirmar el
reembolso, disculparse, recuperarla—, así que prohibirlo dejaría al agente inútil justo cuando
más falta hace escribir bien.

###### 🔑 La regla vive en la entidad

`PmsReserva::estaCancelada()`: **una reserva no tiene estado propio**, lo llevan sus eventos, y
está cancelada cuando no le queda ningún tramo vivo. Los `bloqueo` y `extension` no cuentan —una
reserva cancelada puede conservarlos.

Escrita una vez y usada desde `BuscarReservaSkill::paraDistinguir()`,
`LocalizarConversacionSkill` y `EnviarMensajeHuespedSkill`. Iba camino de estar en tres sitios
distintos, que es como se garantiza que un día dejen de coincidir.

##### ⚠️ Lo importante no es encontrarla: es no mentir sobre cómo

`thea landau` devuelve **Théa Landeau y Théa Signor**. Hay dos Théa. Devolver eso como una
coincidencia normal y dejar que el modelo tome la primera es mandarle la guía a la otra huésped.

Por eso la respuesta distingue **tres desenlaces**, y no dos:

| | Cuándo | Qué dice |
|---|---|---|
| exacta | Todas las palabras encajan | — |
| `coincidencia_aproximada` | Sólo algunas (parcial), o hubo que ir por parecido | Aviso de confirmar el nombre completo con su localizador **antes** de enviar o tocar nada |
| vacía | Ni por parecido | `total: 0` |

Una búsqueda difusa sin ese aviso es peor que una búsqueda estricta: la estricta falla de forma
visible, la difusa acierta *casi siempre* y el fallo se cuela hasta una escritura.

#### 📋 Toda escritura previsualiza, enumera consecuencias y pide aprobación

Las cuatro skills de escritura (`aplicar_cambio_horario`, `registrar_cargo`, `registrar_pago`,
`enviar_mensaje_huesped`) devuelven con `confirmado: false` el mismo contrato:

1. **A quién afecta**, con nombre, casita y localizador — nunca sólo un id.
2. **Qué va a pasar**, entero.
3. Una `pregunta_aprobacion` literal con la que el modelo tiene que cerrar.

##### ⚠️ Enumerar lo que dispara, no lo que escribe

`aplicar_cambio_horario` escribe **un booleano**. Ese booleano dispara tres cosas más al hacer
flush, en tres servicios distintos, y la previsualización decía sólo «se marcará la salida
tardía… bloqueará esa noche en los canales». El operador aprobaba una cosa y ocurrían cuatro:

```
1. Se marca la salida tardía. Las horas de entrada y salida NO cambian: esta
   acción no fija una hora concreta.
2. Se crea un evento de extensión que ocupa la noche del día de salida en
   Casita 2, así que Casita 2 deja de estar disponible esa noche.
3. La extensión viaja al canal como bloqueo, así que Casita 2 deja de venderse
   esa noche en TODOS los portales (esta reserva vino de Booking.com).
   Deshacerlo exige desmarcarlo y esperar otro push.
4. Se abre en la cuenta una línea «Salida tardía (noche bloqueada) · Casita 2»
   con importe 0.00. NO cobra nada por sí sola: el importe se lo pone el
   operador desde el panel financiero.
```

**La regla: la previsualización describe el efecto, no la escritura.** Lo que el operador
aprueba es que la casita deje de venderse en Booking, no que un booleano pase a `true`.

##### `crear_reserva`: un INSERT que dispara cuatro cosas

Es la skill de mayor alcance del catálogo. Escribe dos filas y provoca, **sin orquestar nada**:

| # | Qué pasa | Quién lo hace |
|---|---|---|
| 1 | Se abre la cabecera financiera | `PmsInformacionFinancieraCoherenciaListener` (auto-provisión) |
| 2 | Se crean los cargos del tarifario y la limpieza | `PmsCargosAutomaticosService` |
| 3 | **La casita sale de la venta** en Beds24 y en todos los portales | `Beds24BookingsPushQueueListener` |
| 4 | **Se programan dos mensajes reales** al huésped | Motor de reglas, hitos `start` y `end` |

Comprobado tras crear una de prueba: `recordatorio_llegada` quedó en cola para el 2027-01-11 y
`check_out` para el 2027-01-14 — con la reserva creada en agosto de 2026. La previsualización
enumera las cuatro **antes**, porque aprobar «se creará una reserva» sin saber que retira
inventario de Booking y que se programan mensajes no es aprobar lo que pasa.

###### Obligatorios y recomendados no son lo mismo

«Crea una reserva en la casita 1 del 12 al 15» no trae ni la mitad de lo necesario. Se reúne
**todo lo que falta y se pregunta de una vez**:

```
falta_datos:          ["nombre", "idioma", "adultos"]     ← sin esto no se crea
conviene_preguntar:   ["telefono"]                        ← se puede crear sin él
pregunta: "¿a nombre de quién?; ¿en qué idioma le escribimos? (es, en, pt…);
           ¿cuántos adultos?; ¿tienes su teléfono con prefijo? sin él no hay
           WhatsApp ni le llegan la guía ni los recordatorios"
```

El teléfono **no lo exige la base**, pero sin él la reserva nace muda: no hay WhatsApp, ni guía
que enviar, ni recordatorio que llegue. Que la skill distinga los dos niveles es lo que permite
al operador decir «no lo tengo, créala igual» en vez de quedarse bloqueado.

La disponibilidad se comprueba **antes** de preguntar nada: si la casita está ocupada, hacer que
el operador teclee nombre e idioma para nada sería hacerle perder el tiempo.

###### Los tres desvíos del cálculo automático

Por defecto el sistema pone alojamiento del tarifario noche a noche, limpieza fija de 15.00 y
**exonera el servicio**. Al vender se negocia otra cosa, así que la skill acepta apartarse:

```
Alojamiento                180.00   precio cerrado por el operador (NO se usa el tarifario)
Suplemento de limpieza       0.00   PERDONADO por el operador
Cargo por servicio (10%)    18.00   lo añade el operador — normalmente se exonera
TOTAL                      198.00 USD          (el tarifario habría dado 210.00 + 15.00)
```

Se aplican **corrigiendo lo que el listener ya generó**, no reimplementando la generación: el
precio del tarifario sale de `PmsCargosAutomaticosService::estimarAlojamiento()`, el mismo método
que luego cobra, expuesto para poder previsualizarlo. Una fórmula, no dos.

###### Las horas salen del establecimiento

`PmsEstablecimiento::getHoraCheckIn()` / `getHoraCheckOut()`. Codificar 14:00 y 10:00 en la skill
habría creado una segunda verdad que se rompe el día que un establecimiento cambie su horario.

##### 🔥 `aplicar_cambio_horario` NO fija una hora

Marca la bandera; **no toca `inicio` ni `fin`**. «Que salga a las 18h» no se puede pedir por
aquí, y la previsualización lo dice en su primer punto para que el modelo no lo dé por hecho al
oír una hora en la petición.

##### ⚠️ Con la noche ya ocupada, la pregunta cambia de tono

La casilla sigue siendo el mismo bit de siempre —marcarla concede el horario **y** bloquea la
noche adyacente, sin excepción—. Lo que cambió es sólo la pregunta que se le hace al operador
cuando `alertaDeOcupacion()` detecta que esa noche **ya la tiene otra reserva**: en vez de la
genérica «¿apruebas el cambio?», `pregunta_aprobacion` ofrece explícitamente la opción de NO
aplicar nada («¿aplico igual, con el solape, o prefieres que no haga nada?»). Si el operador dice
que no, el modelo simplemente no vuelve a llamar con `confirmado: true` — no hace falta ningún
dato ni parámetro nuevo, es la misma casilla y la misma consecuencia de siempre.

##### 🔥 Después de marcarla, NO uses `registrar_cargo`

`PmsCargosAutomaticosService::sincronizarExtras()` **ya abre la línea a 0.00**, a propósito:
cuánto vale salir más tarde se negocia caso por caso y sugerir un precio sería peor que no poner
ninguno. Añadir un cargo dejaría **dos conceptos iguales** en la cuenta sin saber cuál es el
bueno. El `siguiente_paso_sugerido` de la skill decía justo lo contrario y estaba mal.

#### 🧮 La previsualización enseña la cuenta entera, no una frase

`registrar_pago` y `registrar_cargo` devuelven un bloque `simulacion` con **cargos, pagado y
saldo, antes y después**, y una `pregunta_aprobacion` literal con la que el modelo tiene que
cerrar. Un operador aprueba mejor viendo en qué queda todo que leyendo «se cargarán 20».

```
             cargos    pagado     saldo
  antes      217.86    217.86      0.00
  después    217.86    223.75     -5.89   USD     ← pago
  después    223.75    217.86      5.89   USD     ← cargo del mismo importe
```

Lo calcula `PmsCuentaSimulador`, **un solo servicio para las dos skills** y para cualquier
«previsualizar» que quiera el panel mañana. Si cada skill hiciera su resta, bastaría con que una
redondeara distinto para que la previsualización dejara de coincidir con lo que se guarda — que
es justo el fallo que una previsualización existe para evitar.

🪞 El saldo se calcula igual que `PmsInformacionFinanciera::getSaldo()`: cargos menos pagos con
`number_format(…, 2)`. **Si allí cambia la fórmula, aquí también.**

`saldo_a_favor_del_huesped` va como booleano y no se deja deducir del signo: quien lo lee es un
modelo, y un `-5.89` no siempre se interpreta como lo que es.

#### ⚠️ Un número anómalo no se señala solo

Charline Morin tenía la cuenta **saldada** (217.86 de 217.86). Un pago encima la deja en
negativo, y la previsualización lo mostraba —`saldo_despues: -5.89 USD`— sin decir que fuera
raro. Un modelo pequeño lee eso como un dato más, pregunta «¿confirmo?» y el operador dice que
sí sin fijarse.

Ahora hay un campo `advertencia` **en palabras**, que la descripción obliga a leer en voz alta:

> *ATENCIÓN: esta cuenta YA estaba saldada (saldo 0.00). Este pago la deja en -5.89 USD a favor
> del huésped. Díselo al operador y pregúntale si falta registrar algún cargo antes.*

La regla, que es la misma que aparece en `aplicable_por_el_agente` y en el rango de
`listar_entradas_salidas`: **si la skill sabe que algo es raro, tiene que decirlo con palabras.**
Dejarlo deducido en un campo numérico es confiar en que el modelo haga una inferencia que nadie
le ha pedido.

#### El porcentaje no se teclea en ningún prompt

Sale de `PmsMedioPago::comisionPorcentaje()` **incluso en el texto que lee el modelo**: la
descripción se compone en `definicion()` interpolando el valor real. Así la regla está siempre
delante del modelo —que es lo que se quería— sin crear una segunda verdad que se olvide de
actualizar el día que el banco cambie la comisión.

#### 🪞 Espejo con el panel

Las fórmulas son las de `util/src/types/pmsFinanzasModel.ts` (`totalConComision()`,
`netoDesdeTotal()`). **Si cambia una, cambian las dos**, o el agente y el formulario guardarán
importes distintos para el mismo pago.

#### ⚠️ Dos localizadores distintos

`pms_evento_calendario.localizador` y `pms_reserva.localizador` existen los dos y **no son el
mismo código** (probado: evento `JK746S`, reserva `9EBXHW`, mismo huésped). El que ve el huésped
es el de la **reserva**: es el que arma la URL de su guía. Las skills devuelven ése; devolver el
del evento hacía que dos skills dieran códigos distintos de la misma persona y que el operador le
dictara al huésped uno que su guía no reconoce.

### 💱 Conversión de moneda: la hace la skill, no el modelo

El operador dice «20 soles» y la cuenta puede llevarse en dólares. `registrar_cargo` guarda
siempre en la moneda de la cabecera, convierte con `TipoCambioDelDia` y **deja registrado el
tipo aplicado** en el cargo — que es lo que permite auditar después de dónde salió la cifra.

```
"importe_indicado":  "20.00 PEN"
"importe_en_cuenta": "5.88 USD"
"tipo_cambio_aplicado": "3.400"
```

La descripción de la skill le dice al modelo explícitamente que **no convierta él**. Y sin
tipo de cambio disponible no se inventa uno: se devuelve el error y lo mete una persona — un
cargo con una cifra inventada es peor que un cargo que falta.

#### 🔥 El TC se sella aunque no haya nada que convertir

Las dos skills consultan `TipoCambioDelDia::venta()` **siempre**, coincidan o no las monedas, y
lo guardan en el registro. Antes sólo lo hacían cuando había conversión (el `if` colgaba del
mismo `$tipoAplicado` que se rellenaba dentro del bloque de conversión), y eso producía cargos y
pagos **cojos**: el día que alguien cambia la moneda base de la cuenta, todos los que se
guardaron «en la moneda de casa» se quedan sin TC y **pasan a aportar 0** al total (§12.2 de
`PmsBeds24ReservasSync.md`) — hay que ir uno por uno a repararlos.

Es el mismo criterio que el panel ya aplicaba (`tcSiempre` en
`util/src/components/reservas/ReservaFinanzasPanel.vue`), y por la misma razón: sellarlo de más
nunca deforma un total, porque `PmsInformacionFinanciera::aMonedaBase()` lo ignora cuando la
moneda del registro ya es la de la cabecera; que falte sí lo rompe.

⚠️ **Dos variables distintas a propósito:** `$tipoDelDia` es lo que se persiste; `$tipoAplicado`
(sólo poblado si hubo conversión) es lo que viaja al modelo. Reportarle un tipo «aplicado»
cuando las monedas coincidían le hace narrarle al operador una conversión que no ocurrió.

#### 🏠 A qué casita se carga: se pregunta, no se adivina

«Añade la limpieza a Carlitos» no dice a qué casita, y Carlitos puede tener tres. Un cargo
manual **no tiene `beds24BookingId`** del que colgarse —esa pista sólo la traen los invoiceItems
del canal (§11.6 de `PmsBeds24ReservasSync.md`)—, así que su estancia la fija la FK `evento`. Sin
rellenarla el cargo se guarda igual, pero el panel lo pinta en «Cargos de la reserva», repartido
contra todas las casitas en vez de contra la que lo generó.

`registrar_cargo` acepta `evento_id` opcional y resuelve en tres casos:

| Estancias imputables | Qué hace |
|---|---|
| 1 | La elige sola, sin preguntar — igual que `abrirNuevoCargo()` en el panel |
| Varias, sin `evento_id` | Devuelve `falta_datos: ["evento_id"]` con la lista (casita + fechas) y una `pregunta` que el modelo traslada al operador |
| `evento_id` indicado | Se valida **contra las estancias de esa reserva**, no con un `find()` suelto: un id de otra reserva imputaría el cargo a un huésped que no lo debe |

⚠️ La lista usa el mismo criterio que `buscar_estancias_de_reserva` (fuera extensiones §7.1.b y
canceladas), y **no** el de `PmsInformacionFinanciera::getEstancias()`, que no filtra nada porque
su trabajo es otro: traducir claves para agrupar lo ya guardado. Ofrecerle al operador una lista
distinta de la que acaba de ver le haría elegir sobre algo que no reconoce.

Comprobado contra una reserva real de 7 eventos → 4 estancias ofrecidas:

```
falta_datos: ["evento_id"]
estancias:   Casita 4 (09-19→09-25) · Casita 6 (09-19→09-24) · Casita 7 · Casita 1
pregunta:    "Esta reserva tiene 4 casitas. Pregúntale al operador a cuál se le carga
              «Limpieza extra», y vuelve a llamarme con su evento_id."
```

#### 🚫 Cuenta anulada: `registrar_cargo` avisa de que el cargo no sumará

Sobre una cabecera con `activa = false` (reserva cancelada, §12.7), el recálculo sólo suma los
cargos de tipo `penalizacion`:

```sql
AND (i2.activa = 1 OR c.tipo_cargo = 'penalizacion')   -- PmsInformacionFinancieraRecalculoService
```

Cualquier otro tipo se guarda pero **no mueve el saldo**. La skill lo detecta antes de
confirmar: resuelve el `tipo` arriba (antes de la previsualización), simula con **delta 0** y
devuelve `advertencia` sugiriendo `tipo="penalizacion"` si lo que se quiere es cobrar la
cancelación. Su descripción ordena al modelo leérsela al operador.

Sin esto la previsualización prometía «cargos 30 → 50» y el listener de coherencia lo descartaba
justo después: la skill respondía «Cargado 20.00 USD» sobre un saldo que no se había movido —
exactamente el fallo que una previsualización existe para impedir. Por eso la respuesta de
confirmación devuelve además `saldo_real`, releído tras el flush, como ya hacía `registrar_pago`.

`registrar_pago` **no** necesita este guardia: el subquery de pagos no filtra por `activa`, así
que un pago siempre resta.

### Detalle sí, pero sólo cuando se pide

`consultar_cuenta` devuelve los cargos y pagos línea a línea. **No duplica los totales**:
`buscar_reserva` y `consultar_mi_reserva` ya traen `total_amount`, `paid_amount` y `balance`,
que es lo que responde «¿cuánto debe Carlos?».

Meter el detalle en todas las skills habría hinchado cada respuesta con veinte líneas que el
90% de las veces no se miran — y eso se paga en tokens **en cada consulta**. Separarlo es una
decisión de coste, no de organización.

Dos cosas que la skill filtra a propósito:

- **Las notas de los pagos no salen.** Son apuntes internos («el huésped discutió el cargo»),
  y esta respuesta puede acabar copiada y pegada a un huésped.
- **🔥 Los placeholders de Beds24 se traducen.** Los cargos importados del canal pueden traer
  la plantilla sin sustituir —`[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`, hay casos así en
  producción—. En el panel se ve raro pero se entiende; puesto delante de un modelo, se lo lee
  al operador tal cual. Cuando el concepto es sólo placeholders se cae al nombre del tipo de
  cargo («Alojamiento»), que es información verdadera y legible.

Es un patrón a repetir en cualquier skill nueva: **un dato que un humano tolera en pantalla
puede ser ruido o mentira dicho en voz alta por el agente.**

#### 🔒 La cuenta la consulta también el huésped, y ahí el contexto MANDA

`consultar_cuenta` está en `[ROLE_HUESPED, ROLE_RESERVAS_SHOW]`. Cuánto debe es de las dos o
tres cosas que un huésped pregunta de verdad, y «¿por qué me cobráis esto?» o «¿cómo pago lo
que queda?» sólo se contestan con el desglose y con la línea de recargo de tarjeta.

Pero el huésped **no tiene `buscar_reserva`**: no puede llegar a un `reserva_id` legítimamente.
Un id que aparezca en su turno es un id que alguien le sopló o que el modelo se inventó.

Por eso, cuando la conversación tiene contexto `pms_reserva`, **el parámetro `reserva_id` se
IGNORA** y se usa `ActorInterface::contextoId()` (`ConsultarCuentaSkill::reservaDelContexto()`).
No se valida el parámetro contra el contexto: **se descarta**. Comparar deja la puerta abierta
a que un cambio futuro invierta la condición; descartar no tiene forma de fallar hacia el lado
malo. Desde el panel no hay contexto de chat, así que ahí el parámetro manda como siempre.

Es la variante «con parámetro opcional» del patrón de `ConsultarMiReservaSkill` —que directamente
no tiene dónde escribir otra reserva—, y se acepta sólo porque la misma skill la usan los dos
lados. Comprobado con las dos reservas de Susan: pasando el id de la otra desde el chat, la
skill devuelve la del contexto.

### Escribirle al huésped: localizar y enviar

Dos skills, porque son dos preguntas distintas:

```
  localizar_conversacion(reserva_id)   → chat + POR QUÉ CANALES se puede escribir AHORA
        ↓
  enviar_mensaje_huesped(...)      ✍️  → borrador → «¿lo mando?» → sale
```

**`localizar_conversacion` no reimplementa las reglas de canal: se las pregunta a quien las
tiene.** Recorre los `ChannelEnqueuerInterface` y llama a su `isValid()` con un `Message` en
memoria, **sin persistir**. Es el mismo juez que decidirá en el envío real, así que lo que
diga aquí es lo que pasará después. Copiar aquí «Beds24 no admite directas» o la ventana de
24 h habría creado una segunda verdad que se desincroniza a la primera.

Verificado sobre dos reservas reales: en una de OTA salen los dos canales; en una directa,
Beds24 aparece como no disponible **con el motivo en lenguaje de operador**.

**El texto lo compone el modelo, no la skill.** No hay un `enviar_estado_de_cuenta` que arme
la tabla por dentro: el modelo consulta `consultar_cuenta`, redacta en el idioma del huésped y
`enviar_mensaje_huesped` lo manda. Así sirve igual para un estado de cuenta, una instrucción
de llegada o lo que haga falta mañana — sin una skill nueva por cada tipo de mensaje.

**Formato por canal:** el mismo texto no se lee igual en los dos sitios. WhatsApp interpreta
`*negrita*` y respeta los saltos de línea; el chat de una OTA es texto plano en una caja
estrecha. Eso se le dice al modelo **en la descripción de la skill**, no se reformatea por
código: reescribir a ciegas un texto que un humano acaba de aprobar es cambiar el mensaje
después de la revisión.

Tres decisiones más:

- **`MENSAJES_WRITE`, no `RESERVAS_WRITE`.** Escribirle a un huésped es mensajería; quien
  gestiona reservas no tiene por qué poder hacerlo.
- **`SENDER_HOST`, no `SENDER_SYSTEM`.** Lo manda una persona a través del asistente y en el
  chat debe verse así. Además `SENDER_HOST` es lo que activa el guardia «humano al mando» y
  calla al autoresponder 30 minutos — justo lo que corresponde tras escribir a mano.
- **Un canal pedido que no vale se descarta, no rompe el envío.** Pedir «email, whatsapp» y
  que salga por WhatsApp es mejor que no enviar nada.

### Comprobar el alcance

```bash
php bin/console app:agent:permisos                    # qué ve cada perfil
php bin/console app:agent:preguntar "…" --como=susan@ejemplo.com

# Ejecutar UNA skill sin modelo: separa «¿falla la skill o falla el prompt?»
php bin/console app:agent:skill listar_entradas_salidas '{"tipo":"salidas","dias":5}'
php bin/console app:agent:skill buscar_reserva '{"busqueda":"Acuña"}'
php bin/console app:agent:skill buscar_reserva '{"busqueda":"x"}' --como-huesped   # debe denegar
```

El primero es la comprobación obligatoria al añadir una skill: si aparece en la fila de un
perfil que no debería, ese perfil puede invocarla.

## 12. Proveedores de IA: elegir motor y modelo

Hasta aquí el módulo hablaba con **un** proveedor, fijado por un alias en
`services_agent.yaml`. Cambiarlo pedía desplegar, y sobre todo impedía lo único que de verdad
resuelve la duda de qué motor usar: **hacerle la misma pregunta a los dos, seguidos, con las
mismas skills**. Eso es lo que añade `AgentEngineRegistry`.

### Cómo se registra un motor

```
  AgentEngineInterface  ──(tag app.agent_engine, por _instanceof)──►  AgentEngineRegistry
       ▲         ▲                                                          │
  AnthropicEngine  GoogleAIEngine                                           │
                                                              ┌─────────────┴─────────────┐
                                                        PanelAssistant          AiConversationProcessor
                                                     (proveedor por petición)   (siempre el por defecto)
```

El tag lo pone `_instanceof` en `config/services/services_agent.yaml`: **implementar la
interfaz basta**. Un proveedor nuevo es una carpeta en `src/Agent/Provider/` y sus variables
de entorno; no se toca el cableado ni ninguna de las 22 skills.

### Quién contesta cada llamada

| Quién pregunta | Motor | Por qué |
|---|---|---|
| Chat del huésped (`AiConversationProcessor`) | `AGENT_IA_PROVEEDOR`, siempre | Qué proveedor atiende a un cliente real es decisión de configuración, no algo que se negocie por conversación |
| Panel (`PanelAssistant`) | El del desplegable, o el de por defecto | Es la caja de pruebas: aquí se compara antes de decidir |
| `app:agent:preguntar` | `--motor` / `--modelo`, o el de por defecto | Comparar desde la terminal sin abrir el navegador |

Dos reglas de resolución **asimétricas a propósito** (`AgentEngineRegistry::elegir()`):

- **Sin nombre** → el de `AGENT_IA_PROVEEDOR`; si ese no tiene clave, el primero que sí la
  tenga. Un entorno a medio configurar tiene que seguir contestando.
- **Con nombre** → ese y sólo ese. Aquí **no** se cae hacia otro: quien está comparando
  proveedores necesita enterarse de que el que pidió no está configurado, no recibir en
  silencio la respuesta del otro y sacar la conclusión contraria.

Por eso `PanelAssistant::preguntar()` devuelve también `proveedor` y `modelo`: una respuesta
sin firmar no sirve para comparar. La barra del panel la pinta bajo cada turno, y la guarda
**por turno** —no repinta el hilo con el motor seleccionado ahora—.

### La lista blanca de modelos

`<PROVEEDOR>_MODEL` es el de por defecto; `<PROVEEDOR>_MODELS` es la lista que el panel puede
pedir. Es **lista blanca, no catálogo informativo**: `AgentEngineRegistry::validarModelo()`
rechaza con un 400 lo que no esté en ella. Sin eso, un POST suelto a `/agent/consulta` podría
disparar el modelo más caro del proveedor tantas veces como quiera.

`CatalogoModelos::desde()` garantiza la invariante que evita la configuración rota: **el
modelo por defecto siempre está en la lista**, aunque uno se olvide de añadirlo a `_MODELS`.

| Variable | Para qué |
|---|---|
| `AGENT_IA_PROVEEDOR` | `anthropic` \| `google`. Quién contesta por defecto |
| `ANTHROPIC_API_KEY` / `GOOGLE_AI_API_KEY` | Vacía = ese proveedor no existe para el registro |
| `ANTHROPIC_MODEL` / `GOOGLE_AI_MODEL` | Modelo por defecto de cada uno |
| `ANTHROPIC_MODELS` / `GOOGLE_AI_MODELS` | Lista blanca, separada por comas |
| `GOOGLE_AI_THINKING` | `low` \| `high` \| vacío. Acota el razonamiento de Gemini — ver «Latencia y cuota» |

### El conector de Google, en lo que se aparta de Anthropic

Google **no publica cliente PHP oficial** para la API de Gemini, y los de la comunidad van por
detrás justo en el *function calling*. `GoogleAIClient` envuelve la API REST con el
`HttpClientInterface` que ya usa `src/Exchange/`: menos código que vigilar que una dependencia
más. La clave viaja en la cabecera `x-goog-api-key` y no en `?key=`, donde acabaría en los
logs de acceso de cualquier proxy por el que pase.

**El bucle de herramientas es manual.** El SDK de Anthropic trae `toolRunner` y ejecuta los
callbacks solo; en Gemini lo lleva `GoogleAIEngine::conversar()`: pedir → mirar si vinieron
`functionCall` → ejecutar → devolver `functionResponse` → repetir. De ahí que
`GoogleAISkillAdapter` exponga dos mitades sueltas (`declaraciones()` y `ejecutar()`) donde el
de Anthropic expone una sola con callback dentro.

⚠️ **Gotchas de Gemini que costaron encontrar** —los tres fallan con un 400 opaco que no dice
cuál de las 22 herramientas es la culpable—:

1. **Una declaración con `parameters` de tipo OBJECT y sin propiedades revienta el turno
   entero**, no sólo esa llamada. Anthropic acepta el `{}` vacío. `GoogleAISkillAdapter::esquema()`
   devuelve `null` y la clave se omite; hoy afecta a `consultar_mi_reserva`.
2. **El esquema es OpenAPI, no JSON Schema**: el tipo es un enum en MAYÚSCULAS (`STRING`,
   `INTEGER`, `BOOLEAN`). En minúsculas cuela según la versión de la API y falla en otras.
3. **`functionResponse.response` tiene que ser un objeto JSON.** Una skill que devuelva una
   lista, o un `[]` vacío, se serializa como array y da 400 — por eso el adaptador **no** usa
   `SkillResult::aJson()` (que sí usa el de Anthropic) y envuelve bajo `resultado` cuando hace
   falta.
4. **`functionCall.args` vuelve a `{}` y hay que devolverlo como `{}`.** Cuando el modelo llama
   a una skill sin argumentos, `json_decode(…, true)` convierte ese `{}` en `[]` y al reenviar
   el turno sale una lista. `args` es un `Struct`: la API tumba el turno entero con
   `Proto field is not repeating, cannot start list`. Lo arregla
   `GoogleAIEngine::devolverParte()`. **Sólo salta en la segunda vuelta**, con la skill ya
   ejecutada, así que no aparece en ninguna prueba que no use herramientas.

El 1, el 3 y el 4 son la misma raíz —**PHP no distingue objeto vacío de lista vacía** y Gemini
sí—. Si aparece un 400 raro al tocar este motor, empieza por ahí.

Y dos diferencias de comportamiento que conviene tener presentes al comparar:

- **Sin caché de prompts.** Gemini lo tiene, pero explícito y con mínimo de tokens; para el
  tamaño de system prompt de este proyecto no compensa. El segundo turno de Gemini es
  proporcionalmente más caro que el de Anthropic, donde el `cacheControl: ephemeral` del
  system prompt se lleva la mayor parte del ahorro.
- **Los rechazos llegan por dos sitios**: `promptFeedback.blockReason` cuando los filtros
  tumban la pregunta, y `finishReason` (`SAFETY`, `RECITATION`…) cuando cortan la respuesta a
  medio generar. Los dos acaban en `ConversationResponse::rechazada()`, igual que el
  `stopReason === 'refusal'` de Anthropic.

`MAX_VUELTAS = 8` es el freno que en Anthropic pone el SDK: sin él, un modelo que insista en
llamar a la misma skill deja el turno girando y quemando dinero hasta el timeout de PHP.

### ⏱️ Latencia y cuota: lo que mide de verdad

Un turno de Gemini tardaba **35 s** la primera vez que funcionó. El desglose, medido, sirve
para no volver a buscar donde no está:

| Sospechoso | Medido | Veredicto |
|---|---|---|
| Las skills | `consultar_disponibilidad` → **3 ms** | Descartado. `app:agent:skill` las cronometra sin modelo |
| El tamaño del payload | 34 KB de declaraciones = **9.169 tokens** de entrada | Grande, pero una llamada con él y salida corta va en **2 s** |
| El razonamiento | «di solo: hola» gastaba **159 tokens de pensamiento** | 🔴 Culpable. Los Gemini 3.x razonan por defecto |

**`GOOGLE_AI_THINKING=low`** lo acota (159 → 0 tokens en el caso trivial). Se aplica en
`GoogleAIEngine::conversar()` como `generationConfig.thinkingConfig.thinkingLevel`.

⚠️ **`thinkingBudget` NO vale**: es el parámetro de la generación 2.5 y los modelos 3.x lo
rechazan con `Request contains an invalid argument`. Al revés también: si vuelves a meter un
2.5 en la lista blanca, `thinkingLevel` le dará 400 — deja la variable vacía y cada modelo usa
su defecto. Es la razón de que el valor sea configurable y no una constante.

Se paga en **cada vuelta** del bucle de herramientas, no una vez por turno: por eso pesa más
aquí que en un chat sin tool use.

**🚧 Cuota del plan gratuito de AI Studio: 20 peticiones AL DÍA y POR MODELO.**

No por minuto — es el error más fácil de cometer, porque el 429 miente:

```
Google AI 429: limit: 20, model: gemini-3.6-flash — Please retry in 49s
```

Ese `retryDelay` de ~50 s invita a reintentar, y no sirve de nada: la cuota no se repone hasta
el reinicio diario. El dato bueno está en `error.details[].quotaId`, que el mensaje resumido
no enseña. Para verlo, un `curl` directo (los 429 **no** consumen cuota, así que preguntar es
gratis):

```
quotaId:    GenerateRequestsPerDayPerProjectPerModel-FreeTier
quotaValue: 20
```

`PerProjectPerModel`: el saldo es **por modelo**, no del proyecto entero. Con
`gemini-3.6-flash` agotado, `gemini-3.1-pro` y `gemini-3.5-flash-lite` siguen enteros — el
desplegable del panel sirve de válvula de escape, y para medir sin gastar del modelo bueno.

Un turno del agente gasta **1 petición por vuelta**: 1 si contesta de memoria, 2 si usa
skills (aunque pida varias a la vez: van en la misma vuelta), 3 si necesita el resultado de
una para saber qué pedir después. Medido, `«¿quiénes entran hoy?»` = 2. O sea **~8 preguntas
al día por modelo**, entre todo el equipo: suficiente para probar, no para operar. **Con el
plan gratuito, `AGENT_IA_AUTORESPONDER=1` sobre Google no es viable** — hay que activar
facturación en el proyecto o dejar el autoresponder en Anthropic.

Hoy un 429 sube como `RuntimeException` y el operador ve «El asistente no está disponible
ahora mismo», sin distinguir cuota agotada de caída: si el panel se usa en serio con Gemini,
eso hay que separarlo.

Para diagnosticar, `GoogleAIEngine::conversar()` registra una línea por vuelta con tiempo y
tokens de entrada / pensamiento / salida. Sin eso, un turno lento es una caja negra:

```
Agent (google): vuelta 1 · 2.3 s · entrada 9169 · pensamiento 0 · salida 43 tokens.
```

### 💰 El caché de prompt en Anthropic: dónde va la marca y por qué

**El catálogo de skills es la partida gorda del prompt, no el prompt.** Medido sobre el
fuente (sólo los literales de `definicion()`, así que es un suelo):

| | ~tokens |
|---|---|
| Catálogo completo (22 skills) | 7 826 |
| Catálogo del huésped (6 skills, tras `paraActor()`) | 1 852 |
| Reglas del huésped (bloque estable) | 859 |
| Contexto del huésped (nombre + idioma) | 24 |

Las cinco más gordas: `registrar_pago` (889), `buscar_reserva` (648),
`aplicar_cambio_horario` (510), `crear_reserva` (499), `consultar_guia` (496).

#### 🔥 Dónde va la marca, y el bug que había

En Anthropic el prompt se ordena **herramientas → `system` → mensajes**, y una marca de
`cache_control` cachea *todo lo que tiene por delante*. Había una sola marca, en el `system`
— y el `system` del huésped empezaba con su nombre:

```
ANTES                                    AHORA
tools    (1 852) ─┐                      tools    (1 852) ─┐ marca ①  ← igual para TODOS
system   «Hablas con Daniel…»  ─┤ marca   reglas   (  859) ─┘ marca ②  ← igual para TODOS
                  └ prefijo ÚNICO        contexto (   24)     sin marca ← lo único suelto
                    por conversación
```

El prefijo cacheado incluía el nombre, así que **era distinto en cada conversación**: cada
una escribía su propio caché —que se paga más caro que un token normal— para leerlo un par
de turnos y tirarlo. En conversaciones de un solo mensaje, cachear **costaba más que no
cachear**.

Ahora el prefijo es idéntico para todas: lo escribe la primera consulta de la hora y lo leen
todas las demás, **vengan del huésped que vengan**.

#### ⏱️ Por qué `ttl: 1h` y no el defecto de 5 min

Escribir caché cuesta más que un token normal y leerlo cuesta una décima. Con el prefijo del
huésped (2 711 tokens compartidos + 74 sueltos por llamada):

| | tokens de entrada facturados por llamada |
|---|---|
| Sin caché | 2 785 |
| Caché 1 h · 1 llamada/hora | 5 496 ⚠️ |
| Caché 1 h · 2 llamadas/hora | 2 921 ⚠️ |
| Caché 1 h · 3 llamadas/hora | 2 062 |
| Caché 1 h · 10 llamadas/hora | 860 |
| Caché 1 h · 20 llamadas/hora | 603 |

⚠️ **Con muy poco tráfico el caché PIERDE**: por debajo de ~3 llamadas por hora se paga la
escritura y no la lee nadie. El punto de equilibrio se alcanza igualmente porque **un turno
del agente son 2-3 llamadas a la API**, no una: el propio bucle de herramientas amortiza la
escritura dentro del mismo mensaje. Con 5 min de TTL harían falta ~24 llamadas/hora, que este
chat no tiene.

#### Las dos reglas que hay que respetar al tocar prompts

1. **En el bloque estable no va NADA interpolado.** Ni nombre, ni idioma, ni saldo. Un `{$…}`
   ahí dentro rompe el caché de todo el mundo, y no da ningún error: sólo sube la factura.
   Se comprueba en un segundo — `reglas()` no debe tener ni un `{$`.
2. **Lo volátil va en `contexto`,** que viaja al final del `system` y sin marca. Cada token
   ahí se paga entero en cada llamada.

`ConversationRequest` lleva los dos campos separados justamente para que esto no dependa de
que alguien se acuerde: `systemPrompt` es lo estable, `contexto` lo volátil.

#### Cómo se comprueba que está funcionando

`AnthropicEngine` registra por turno:

```
Agent (anthropic): 2 vuelta(s) · entrada 148 · caché leído 5422 · caché escrito 0 · salida 31 tokens.
```

**`caché escrito 0` y `leído` alto es el objetivo.** Si `escrito` sale alto en cada consulta,
el prefijo está cambiando entre conversaciones: busca el `{$` que se ha colado en el bloque
estable.

### 🧪 Recalibrar el prompt es obligatorio

Lo que la frontera **no** abstrae son los prompts (§11). El system prompt del panel está
afinado contra Anthropic; Gemini con el mismo texto invoca skills con otra frecuencia y
resume más. Antes de mover `AGENT_IA_PROVEEDOR` en producción, pasa por el desplegable del
panel las preguntas que ya sabes contestar y compara **qué herramientas usó cada uno** —la
firma bajo cada respuesta lo dice—, no sólo si el texto suena bien.

```bash
# La misma pregunta, los dos motores, y a comparar herramientas y latencia.
php bin/console app:agent:preguntar --motor=anthropic "¿qué casitas tengo libres del 12 al 15 de marzo?"
php bin/console app:agent:preguntar --motor=google --modelo=gemini-2.5-pro "¿qué casitas tengo libres del 12 al 15 de marzo?"
```

## 13. Triaje de entrada y tramos de potencia

Hasta aquí, un turno del agente costaba lo mismo dijera lo que dijera el huésped. «Hola» y
«¿por qué me cobráis 40 soles de más?» pagaban idéntico: el catálogo entero de skills en el
prompt, el bucle de herramientas girando y el modelo grande decidiendo. En un chat donde una
buena parte de los mensajes son cortesía, eso es pagar el precio del caso difícil por el caso
trivial — y es lo que hace que un asistente con IA no salga a cuenta.

Este apartado describe las dos piezas que lo parten: **el triaje** (qué clase de mensaje es) y
**los tramos de potencia** (qué modelo atiende cada paso).

### 13.1 El camino de un mensaje, ahora

```
  mensaje entrante del huésped
            │
            ▼
  ┌──────────────────────────────────────────────────────┐
  │ TRIAJE  ·  App\Agent\Triage\Triaje                   │
  │ · sin herramientas · una sola llamada · JSON forzado │
  │ · prompt = reglas + índice de skills (1 línea c/u)   │
  │   + índice global de temas de la guía (§13.8)        │  ← 2 066 tok, cacheable entero
  │ · contexto volátil (idioma, nombre, casita): ~30 tok  │  ← fuera del caché
  │ · tramo AGENT_IA_TRIAJE_POTENCIA (media por defecto) │
  └──────────────────────────────────────────────────────┘
            │
            ├─ conversacion  ─►  la «respuesta» viene EN el propio JSON del triaje
            │                    (0 llamadas más; si vino vacía, cae al camino largo)
            │
            ├─ emergencia    ─►  camino largo, tramo ALTO
            │                    + orden explícita de llamar a escalar_al_equipo
            │
            ├─ peticion      ─►  camino largo, tramo de la skill elegida
            │                    + la skill sugerida como PISTA, no como orden
            │
            └─ indeterminado ─►  camino largo, tramo MEDIO
                                 (exactamente lo de antes del triaje)
```

El camino largo es el de siempre: `AgentEngineInterface::conversar()`, con el catálogo entero
del actor y el bucle de herramientas. No ha cambiado nada dentro.

### 13.2 🔑 Lo que el triaje NO hace, y es lo importante

**No recorta el catálogo.** Elige skill, sí, pero la manda como sugerencia en el bloque de
contexto: el paso siguiente sigue viendo todas sus herramientas y puede llamar a otra, o a
ninguna. Recortar sería lo obvio —ahí está el grueso de los tokens— y es justo lo que no se
hace:

> **Un filtro previo puede AÑADIR, nunca QUITAR.**

El motivo no es teórico: son los dos fallos que ya costaron caro en este módulo. Cuando hay dos
que deciden y uno puede quitarle opciones al otro, lo descartado **no aparece en ningún log** —
la skill que no se ofreció no deja rastro— y el error se descubre semanas después, en la cara
del huésped. La sugerencia sí deja rastro: la línea `Agent: triaje con …` dice qué propuso, y
la firma de la respuesta dice qué se usó de verdad. Si no coinciden, se ve.

**No tira ningún mensaje.** Las cuatro salidas terminan en una respuesta. `indeterminado` no es
«ninguna de las anteriores»: es «no lo sé», y lleva al camino largo. Por eso el triaje no puede
dejar a nadie sin contestar — cuando falla, el agente se comporta como antes de que existiera.
Lo mismo con la charla: si el JSON vino sin `respuesta`, se sigue por el camino largo.

`indeterminado` **no se le ofrece al modelo** (`TipoDeMensaje::opciones()` lo omite): teniendo
la salida fácil disponible, un clasificador la usa para todo lo que le da pereza y el triaje
deja de ahorrar nada. Que no sepa decidir tiene que notarse como un fallo, no como una opción.

### 13.3 Por qué el prompt del triaje se cachea entero

Las reglas y el índice de skills no llevan **nada** del huésped: son idénticos byte a byte en
todas las conversaciones, y son el bloque con la marca de caché. Lo del huésped —idioma y
nombre, que hacen falta desde que el triaje contesta la charla— va en el bloque de contexto,
DESPUÉS del corte: dos líneas que se pagan enteras contra el prefijo que ya pagó otra
conversación. El mensaje va en `messages` (§12, «dónde va la marca»).

El caché de Anthropic vive **1 hora** (`ttl: '1h'`) y el prefijo es **el mismo para todos los
huéspedes**: no hay un caché por conversación, hay uno por catálogo. Cada mensaje que entra en
esa hora —da igual de qué reserva— lo lee a 0,1× y le renueva el TTL.

> 🚧 **TODO (Google):** Gemini no cachea prefijos tan pequeños — su caché explícito exige un
> mínimo de tokens que el catálogo actual no alcanza, así que con Google el prefijo se paga
> entero en cada mensaje. Cuando el catálogo de skills crezca lo bastante para superar ese
> mínimo, evaluar el context caching explícito de Gemini (crear el caché del catálogo y
> referenciarlo por nombre). Hasta entonces la optimización de caché es sólo Anthropic, que es
> el proveedor para el que se optimiza primero.

El índice se monta con la **primera frase** de cada `definicion()->descripcion`, así que no hay
una segunda lista que mantener: añadir una skill la mete en el triaje sola. Y es la frase que
dice *para qué sirve*, que es lo único que el clasificador necesita — no tiene que saber usar la
herramienta, sólo reconocerla.

Medido con `var/medir-triaje.php` (no llama a ninguna API), para el actor huésped y sus 6
skills:

| Prompt | Tokens ≈ | Cacheable |
|---|---:|---|
| Catálogo de skills del huésped (camino largo) | 1 842 | sí |
| Reglas del camino largo (`reglas()`) | 754 | sí |
| **Prefijo del camino largo** | **2 596** | **sí** |
| Prompt del triaje (reglas + índice de skills + reglas de charla) | 1 187 | sí, entero |
| Índice global de temas de la guía (§13.8) | 787 | sí, mismo bloque |
| **Prompt del triaje completo** | **2 066** | **sí, entero** |
| Contexto volátil (nombre + idioma + casita) | ~30 | no |
| Pista o tema_id del triaje, cuando los hay | ~40 | no |

### 13.4 Qué se ahorra de verdad, y qué se paga de más

Hay que decirlo en los dos sentidos, porque **el triaje no es gratis**:

| Tipo de mensaje | Antes | Ahora | Efecto |
|---|---|---|---|
| Cortesía («hola», «gracias») | prefijo 2 596 sobre el tramo medio + bucle de herramientas | **una** llamada de 2 066 (triaje, medio), que además contesta | El catálogo no se toca, no hay bucle, y no hay segunda llamada |
| Petición | prefijo 2 596 sobre el tramo medio | lo mismo **+ 2 066** del triaje | Se paga de más; con caché acertando son ~205 tokens efectivos |
| Pregunta de guía | lo anterior **+ una vuelta extra** del bucle (pedir el catálogo: 2.º pase del prefijo + el catálogo como resultado) | el triaje trae `tema_id` y la skill responde a la primera | La vuelta ahorrada pesa mucho más que lo que cuesta el índice, §13.8 |
| Emergencia | prefijo 2 596 sobre el tramo medio | lo mismo, sobre el tramo **alto** | Más caro a propósito: son unos pocos mensajes al año |

O sea: **las peticiones pagan un recargo pequeño para que la cortesía deje de pagar de más.**
Si el chat resultara ser casi todo peticiones, el triaje no compensa y se apaga con
`AGENT_IA_TRIAJE=0` — por eso tiene interruptor propio.

> ⚠️ **Las cifras de dinero están sin confirmar.** Todo lo de arriba son **tokens contados en
> local**; convertirlos a euros exige los multiplicadores reales de escritura y lectura de caché
> del proveedor, y `ANTHROPIC_API_KEY` está vacía en este entorno. La primera consulta real con
> clave los da: la línea `Agent (anthropic): … caché leído N · caché escrito M …` de §12 es
> justo eso.

### 13.5 Los tramos de potencia

Tres tramos, cada uno resuelto por una variable de entorno en la forma `proveedor:modelo`:

```
AGENT_IA_POTENCIA_ALTA=anthropic:claude-opus-5
AGENT_IA_POTENCIA_MEDIA=anthropic:claude-sonnet-5
AGENT_IA_POTENCIA_BAJA=anthropic:claude-haiku-4-5-20251001
```

**Pueden cruzar proveedores.** Es el motivo de que la clave lleve el proveedor dentro en vez de
heredar el de `AGENT_IA_PROVEEDOR`: el tramo bajo en Gemini Flash Lite y el alto en Opus es una
combinación perfectamente válida, y con una clave por tramo se prueba sin desplegar.

Qué va en cada uno:

| Tramo | Para qué | Regla para decidir |
|---|---|---|
| **Alta** | Emergencias; lo que compromete algo caro de deshacer | ¿Equivocarse aquí cuesta más que la diferencia de precio? |
| **Media** | El trabajo normal: elegir herramienta, encadenar consultas, responder con lo devuelto | Es el defecto, y es lo que hace hoy el agente entero |
| **Baja** | Charla, rellenar huecos, dar formato | ¿Queda alguna decisión de negocio por tomar? Si no, baja |

#### 🔻 Aquí SÍ se cae hacia otro motor, al revés que en §12

`AgentEngineRegistry::elegir()` no hace fallback a propósito: quien elige proveedor en el
desplegable del panel está comparando, y recibir en silencio la respuesta del otro le arruina la
comparación. `SelectorDePotencia` hace lo contrario, y por el mismo tipo de razón: el tramo de
potencia es una **optimización interna que el huésped no pidió**. Si el tramo bajo apunta a un
proveedor sin credenciales, la alternativa correcta no es dejar de contestarle — es contestarle
con el motor de siempre y dejar constancia. Un ajuste de coste mal configurado no puede tumbar
el chat.

Todos los caminos degradan y **todos avisan** con un `warning` por consulta. Es molesto a
propósito: significa que se está pagando el tramo que no era.

Con las tres claves **vacías**, todo se atiende como antes: el motor de `AGENT_IA_PROVEEDOR`
con su modelo por defecto. El mecanismo se puede desplegar sin cambiar nada.

> 🚧 **Estado real de este entorno hoy.** Las tres claves apuntan a Anthropic (`opus-5`,
> `sonnet-5`, `haiku-4.5`), pero `ANTHROPIC_API_KEY` está **vacía**, así que los tres tramos
> degradan al mismo motor. Comprobado con `php var/probar-triaje.php`:
>
> ```
>   alta   → google/gemini-3.6-flash (alta)
>   media  → google/gemini-3.6-flash (media)
>   baja   → google/gemini-3.6-flash (baja)
> ```
>
> Es decir: **el mecanismo está puesto y verificado, pero todavía no separa nada** — hasta que
> haya clave de Anthropic, los tres tramos son el mismo modelo y el ahorro de la charla se
> reduce al catálogo que no se manda. Cada consulta deja un `warning` diciéndolo.

#### La potencia de cada skill

`SkillDefinition::$siguientePaso` dice cuánta cabeza hace falta **después** de que el triaje
haya elegido esa skill: pedir los datos que falten y redactar con lo que devuelva. No es la
potencia para *elegirla* —eso lo decide el triaje, que ve todo el catálogo—.

Por defecto `Media`, que es exactamente lo que hace hoy el agente entero: **ninguna de las 22
skills cambia de comportamiento** hasta que alguien la baje a mano.

> ⚠️ Hoy **ninguna** está bajada, y es deliberado. El candidato obvio eran `consultar_wifi` y
> `consultar_codigos` —lo que queda tras elegirlas es leer un literal en voz alta—, pero su
> salida es una **credencial que el huésped va a teclear**: un dígito mal en el código de la
> puerta deja a alguien fuera de su casa a las 23:00 en Cusco. «Bajar de tramo es reversible»
> deja de ser cierto cuando el error ya llegó al huésped. Se bajarán cuando haya con qué medir
> la tasa de error, no antes.

### 13.6 El turno seco: `turnoDirecto()`

Las dos piezas nuevas necesitan algo que `conversar()` no daba: **una llamada sin herramientas
y sin bucle**. `AgentEngineInterface::turnoDirecto()` es eso, y la diferencia no es de tamaño
sino de coste — `conversar()` adjunta el catálogo del actor y gira el bucle hasta que el modelo
deja de pedir herramientas, y para clasificar o para decir «hola» eso es tirar dinero.

Con un JSON Schema, la salida viene **forzada por el proveedor**, así que no hay que defenderse
de un ```` ```json ```` ni de un «Claro, aquí tienes:» delante. Los dos proveedores lo tienen y
**no hablan el mismo dialecto**:

| | Anthropic | Google |
|---|---|---|
| Dónde va | `outputConfig.format` (`outputFormat` está deprecado en el SDK) | `generationConfig.responseSchema` |
| Qué hace falta además | nada | `responseMimeType: application/json` — **sin él el esquema se ignora en silencio** |
| Qué rechaza | — | `additionalProperties` y `type` en lista (`["string","null"]`): 400, no aviso |

Esa última fila la resuelve `GoogleAIEngine::esquemaGemini()`, que traduce el JSON Schema al
subconjunto de OpenAPI que Gemini admite: borra `additionalProperties` y convierte los tipos
opcionales en «no exigido» sacándolos de `required`.

`turnoDirecto()` **ignora** `permitirEscritura` y las skills del actor, porque no hay
herramientas que autorizar. Un turno seco no puede tocar nada por construcción, y eso es lo que
lo hace seguro para contestar la charla. Nunca lanza por culpa del proveedor: devuelve `null` y
quien llama decide.

### 13.7 La charla: la contesta el propio clasificador

La charla **no tiene llamada propia**: el campo `respuesta` del JSON del triaje trae la
contestación cuando el tipo es `conversacion`, escrita por el mismo modelo que clasificó.
Clasificar un «hola» y contestarlo son el mismo trabajo de leerlo; separarlos —como estuvo un
tiempo, con un `charlar()` en el tramo bajo— era pagar **dos** llamadas por mensaje de
cortesía: el triaje en tramo medio y la charla en tramo bajo. Ahora es una, en el tramo del
triaje, que además es el que hace falta: si en mitad de la charla aparece una petición, quien
la detecta es el mismo clasificador que estaba charlando — no hay «modo charla» del que salir,
ni forma de quedarse atrapado en él. Por eso tampoco hay ningún corte ni cooldown de charla:
cada mensaje pasa por el clasificador igual, charle lo que charle.

> 🔑 **La seguridad de este camino no está en el prompt: está en que no hay herramientas.** En
> la llamada del triaje el modelo no puede consultar nada, así que tampoco puede citar mal
> nada. Lo peor que puede hacer es contestar de memoria, y para eso el prompt le prohíbe dar
> cifras y le da la salida buena.

Esa salida buena es **cambiar el tipo**: el prompt le dice que si al escribir la respuesta ve
que había una pregunta escondida, no era `conversacion` — reclasifica y deja la respuesta
vacía, y el mensaje entra por el camino que sí puede consultar. Y `interpretar()` remata por
código lo que el prompt pide por las buenas: una `respuesta` que venga con tipo `peticion` o
`emergencia` **se tira** — contestar sin herramientas no es su trabajo.

El «¿te ayudo con algo?» tras varias vueltas ya no necesita contador: es una regla del propio
prompt del triaje («si el historial muestra varias vueltas de charla, cierra ofreciendo ayuda
concreta»), y el historial que ve el clasificador ya lo dice. La constante
`CHARLA_ANTES_DE_OFRECER` y los métodos `charlar()` / `reglasDeCortesia()` /
`contextoDeCortesia()` del procesador **ya no existen**.

⚠️ **La charla no pasa por el guardia de `sin_skill`.** No puede: ese motivo lo pone
`ConversationResponse`, y aquí no hay `ConversationResponse` — la respuesta viene en el JSON
del triaje. Hoy da igual para el huésped, porque `sin_skill` ya no tira nada (§9, *El saludo se
entrega*), pero **sí importa para auditar**: una respuesta de cortesía no aparece en el log como
`IA: respuesta sin skill entregada`, sino como `IA: charla resuelta en el propio triaje en la
conversación <id>: «…»` (y entera en la línea `Agent: triaje con …`). Son dos grep distintos
para la misma pregunta —«¿qué dijo el bot sin consultar nada?»—, y quien vuelva a poner una
política sobre `sin_skill` tiene que acordarse de este camino o creerá que la ha aplicado a
todo.

### 13.8 La pista, y por qué está redactada como sugerencia

Lo que el triaje le cuenta al camino largo va en el bloque volátil del `system`
(`pistaDelTriaje()`), y son dos líneas como mucho:

> Una revisión previa del mensaje sugiere que «consultar_guia» puede responder a esto. El tema
> parece ser «ducha». Es una sugerencia, no una orden: tienes todas tus herramientas y decides
> tú cuál usar, o ninguna.

El triaje ve el mensaje pero **no** lo que devolverán las herramientas; quien responde ve las
dos cosas. Con un «USA consultar_guia», un triaje equivocado arrastraría al modelo bueno a una
skill que no toca y el error no aparecería más que en la respuesta al huésped.

**La emergencia es la excepción y va imperativa**, porque ahí el coste de no hacer nada es peor
que el de avisar de más — y por eso el propio prompt del triaje le dice que ante la duda entre
`peticion` y `emergencia` elija `emergencia`.

#### El índice global de la guía: el triaje elige el tema exacto

El triaje lleva en su bloque cacheado un **índice global** de temas de la guía
(`IndiceDeGuia::construir()`): una línea por ítem con su uuid, su etiqueta y en qué casitas
aplica. Con él, el JSON del triaje puede traer `tema_id`, y el camino largo llama a
`consultar_guia(tema_id)` **directo al contenido**: se ahorra la vuelta entera de pedir el
catálogo (segundo pase del prefijo de 2 596 tokens + el catálogo como resultado de
herramienta).

Que el índice pueda ser global —y por tanto **cachearse en el mismo bloque que las reglas,
compartido por todo el parque**— no es suerte: los ítems de la guía son entidades N-a-N que
las casitas comparten. En los datos reales, 11 de los 39 ítems («Wifi», «Reglas», «Pago»…)
están en las 7 casitas **con el mismo uuid** y salen como una línea con «todas»; el resto son
variantes por casita de cuatro etiquetas (Ducha, Puerta, Descripción, Álbum). Lo único por
huésped es la línea volátil «Su casita: X» y la validación:

- **`interpretar()` valida el `tema_id` contra los temas de LA CASITA del huésped**
  (`porUnidad`), no contra el índice entero. Un uuid inventado, de otra casita, o elegido sin
  casita resuelta, se descarta con un log y queda la `pista` de palabras.
- **La skill re-filtra igual**: `buscarPorId()` busca dentro del árbol ya podado por acceso, y
  si no está devuelve el catálogo. La equivocación del triaje degrada al comportamiento de
  siempre, nunca a contenido de otra casita.
- Con una reserva de **varias casitas**, la línea volátil las nombra todas y los temas
  permitidos son la unión: los generales comparten uuid igual, y en los propios el modelo no
  puede estar seguro — que es exactamente cuando el prompt le manda dejar el tema vacío.

El índice pesa **~787 tokens cacheados** (39 temas, 7 unidades) y se reescribe el caché solo
cuando alguien edita la guía, que es lo correcto. 🚧 Crece O(casitas × ítems propios): si el
parque crece hasta que pese miles de tokens, la compresión prevista es sustituir el uuid por
un ordinal y mapear ordinal→uuid en `IndiceDeGuia` al interpretar — el mapa se reconstruye en
la misma petición con la misma consulta, así que no puede desincronizarse del prompt.

La **pista** sigue existiendo como red: si el triaje no pudo fijar el ítem, la palabra alimenta
`busqueda` y se descarta si trae más de tres palabras — con una frase larga la búsqueda acierta
por casualidad, el fallo documentado en `ConsultarGuiaSkill::MAX_PALABRAS_BUSQUEDA`.

### 13.9 🐛 El `system` de `toolRunner()` iba mal, y no saltaba

Al montar esto salió un fallo que llevaba tiempo escondido. El SDK declara:

```php
toolRunner(int $maxTokens, array $messages, Model|string $model,
           array $tools = [], ?int $maxIterations = null, array $extraParams = [])
```

`system` **no es un parámetro suyo**: va dentro de `extraParams`. `AnthropicEngine::conversar()`
lo pasaba como argumento con nombre (`system:`), lo que en PHP es un
`Error: Unknown named parameter $system` — el turno entero reventaba **antes de llegar a la
API**.

No saltó nunca porque `AGENT_IA_PROVEEDOR=google` en este entorno y esa rama no se ejecutaba.
Es el aviso que deja: **sin suite de tests, una rama que no se ejecuta no está verificada**, por
muy bien que se lea. Arreglado; queda por ejercitarla de verdad con una clave puesta.

### 13.10 Cómo se mira si esto funciona

```bash
# Tamaño de cada prompt, sin llamar a ninguna API.
php var/medir-triaje.php

# Las dos piezas frágiles, sin API: qué hace el triaje con lo que devuelva el modelo (skills
# inventadas, pistas que son el mensaje entero, JSON envuelto en backticks) y la traducción
# del esquema a Gemini. Termina imprimiendo qué motor resuelve cada tramo en ESTE entorno.
php var/probar-triaje.php

# Qué decidió el triaje en cada mensaje, y con qué modelo.
grep 'Agent: triaje con' var/log/dev.log

# Si el caché acierta. «leído» alto y «escrito» a 0 es lo que se busca (§12).
grep 'Agent (anthropic)' var/log/dev.log

# Las charlas que contestó el propio triaje, con el texto que leyó el huésped.
grep 'IA: charla resuelta' var/log/dev.log
```

La línea del triaje trae la decisión entera: `conversacion → — (motivo)`, `peticion →
consultar_guia («ducha») — …`. Cruzarla con la firma de skills de la respuesta es lo que dice
si el triaje acierta. Y `Agent: el triaje propuso una skill inexistente` avisa de que el
clasificador se está inventando nombres, que es el fallo más probable de este diseño.

---

## 14. El formato del texto libre y su degradación por canal

Un mensaje de texto libre se escribe **una vez** y sale por varios canales que no hablan el
mismo formato. El canónico en que se guarda (`contentExternal`) es **el de WhatsApp**, porque
es el único canal de salida con formato propio, más una marca nuestra:

```
  *negrita*   _cursiva_   ~tachado~   __subrayado__ (nuestra: WhatsApp no tiene subrayado)
```

```
                       texto libre (operador, IA, skills)
                                    │  canónico en contentExternal
              ┌─────────────────────┼──────────────────────┐
              ▼                     ▼                      ▼
        Panel (ChatView)      WhatsApp Meta            Beds24 (OTA)
        formatoAHtml() TS     paraWhatsapp() PHP       paraTextoPlano() PHP
        <strong>/<em>/<u>/<s> marcas tal cual,         SIN marcas: acaba en la
        + enlaces clicables   __subrayado__ se pierde  bandeja de Airbnb/Booking
```

La fuente de verdad es `App\Message\Service\Formato\FormatoDeTexto`, llamada desde la rama de
texto libre de las dos *mapping strategies* (`Beds24SendMappingStrategy`,
`WhatsappMetaSendMappingStrategy`). Ahí pasan **todos** los caminos de escritura —el chat del
operador, la respuesta del agente de IA y las skills (`enviar_mensaje_huesped`)— así que no
hay que formatear en ningún otro sitio.

🔁 **Espejo PHP ↔ TS.** `util/src/utils/formatoDeTexto.ts` replica las reglas para pintar el
HTML del panel (`ChatView.vue`, `formatMessageText`), la barra B/I/U/S del compositor
(`envolverSeleccion`) y la respuesta del asistente interno (`AsistenteBar.vue`). **Si tocas una
marca o una regla en un lado, tócala en el otro** — o el operador verá en el panel un formato
distinto del que recibe el huésped.

#### ⚠️ El espejo se separa a propósito en UN punto: `[texto](url)`

| Salida | `[Marcelo Calderón](https://…)` se convierte en |
|---|---|
| **HTML** (`formatoAHtml`) | `<a href="…">Marcelo Calderón</a>` — la URL queda detrás del nombre |
| **WhatsApp / Beds24** (`FormatoDeTexto`) | `Marcelo Calderón: https://…` — la URL **tiene** que verse |

No es una divergencia que corregir. Ninguno de los dos canales de texto tiene enlaces con
etiqueta: si allí escondiéramos la URL, el huésped se quedaría sin ella. En el panel es al
revés — un enlace con el nombre visible es lo que hace legible una lista de salidas, en vez de
un UUID de setenta caracteres en mitad de cada línea.

El resto de reglas sí son espejo estricto.

#### 🔗 El asistente del panel pinta HTML, no texto plano

`AsistenteBar.vue` mostraba `{{ turno.texto }}` con `whitespace-pre-line`, así que los `**` del
modelo llegaban crudos al operador y las URLs no se podían pinchar. Ahora pasa por
`formatoAHtml()` igual que el chat de mensajería.

De la mano, `listar_entradas_salidas` devuelve un campo **`url`** por fila: la ficha de esa
estancia en el panel, con el drawer ya desplegado. Es la misma URL que abre `verEnReservas()`
en `util/src/views/HomeView.vue` al pinchar una fila de «salen hoy»:

```
{util_host_url}/reservas?evento={eventoId}&reserva={reservaId}
```

🪞 **Espejo con el front:** los dos ids viajan por la query porque es lo único que necesita
`abrirEdicion()` del drawer. Si allí cambia la ruta o el nombre de los parámetros,
`ListarSalidasSkill::fichaDe()` hay que tocarlo también, o el enlace llevará al calendario sin
abrir nada. La skill ya tenía los dos ids; lo único que le faltaba era saber la URL base.

### ⚠️ Las plantillas NO se degradan

Cada plantilla tiene su cuerpo POR CANAL, ya escrito con el formato que le toca. Las
estrategias sólo formatean cuando `getTemplate() === null`; pasar una plantilla por el
degradador rompería texto pensado a mano. Es la razón de que la rama de plantilla y la de
texto libre no compartan este paso.

### `normalizar()`: la red para el Markdown del modelo

Los modelos emiten Markdown aunque el prompt lo prohíba: `**negrita**`, `# títulos`, listas
con `- `, enlaces `[texto](url)`. Antes de degradar se normaliza al canónico (`**x**` → `*x*`,
`# Título` → `*Título*`, `- item` → `• item`, `[texto](url)` → `texto: url`), para que al
huésped nunca le lleguen los asteriscos dobles literales — el fallo pendiente que dejó Gemini
sirviendo `**negrita**` a WhatsApp. Es una red, no un permiso: el prompt sigue pidiendo texto
de chat, sin listas ni títulos.

### Las URLs son intocables

Una URL lleva `_` y `*` con todo el derecho (`/mi_guia`), y el quitado de marcas la mutilaría
en silencio. Los dos lados apartan las URLs con marcadores `\x00N\x00` antes de transformar y
las devuelven intactas al final — y lo pegado al final de una URL («mira *url*», «visita
url.») se devuelve al texto, para que las marcas se vean en pareja. Verificado en
`var/probar-formato.php` (19 comprobaciones, sin API).

## 15. Dónde tocar para cambiar X

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
| **Añadir una capacidad al agente** | `src/Agent/Skill/` | Una clase con `SkillInterface` — §11. No se toca motor ni adaptador |
| Cambiar cómo nace una estancia (horas, estados, canal) | `PmsEstanciaCreator` | `crear()` — lo comparten `crear_reserva` y `crear_estancia`, **no lo dupliques** |
| Añadir otra casita o tramo a una reserva existente | `CrearEstanciaSkill` | `ejecutar()` — §11. Para un huésped nuevo es `crear_reserva` |
| Corregir datos de una reserva desde el agente | `ModificarReservaSkill` | `camposDelHuesped()` (reserva) / `camposDeLaEstancia()` (evento) |
| Cambiar qué estados de pago confirman y abren la guía | `PmsEventoEstadoPago` | `ESTADOS_PAGO_CONFIABLES` — lo leen la auto-confirmación **y** `PmsGuiaAcceso` |
| Cambiar quién puede usar una skill | la propia skill | `rolesRequeridos()` — comprueba con `app:agent:permisos` |
| Que el agente sepa que puede dar un dato que ya devuelve | la propia skill | `definicion()->descripcion` — **no el system prompt**, §11 |
| Permitir que el agente mueva fechas de estancia | `AplicarCambioHorarioSkill` | `in_array()` de `ejecutar()` **y** `aplicable_por_el_agente` en `EvaluarCambioHorarioSkill::evaluarMoverFecha()` — §11, los dos o ninguno |
| Cambiar el enlace a la guía o al catálogo de tours | `config/services/services_parameters.yaml` | `pax_book_guide_url` = `PAX_HOST_URL` + localizador; se arma en `PmsMessageDataResolver::getMessageVariables()` |
| Cambiar si algo pide confirmación o PIN | la propia skill | `nivelRiesgo()` — §11 |
| Acotar una skill a la reserva del que pregunta | la propia skill | Usa `$actor->contextoId()`, no un parámetro — §11 |
| **Cambiar el proveedor de IA por defecto** | `.env` | `AGENT_IA_PROVEEDOR` — §12. Recalibra el prompt antes: la frontera no abstrae los prompts |
| **Añadir un proveedor nuevo** | `src/Agent/Provider/<Proveedor>/` | Implementa `AgentEngineInterface`; `_instanceof` lo etiqueta solo. No se toca ninguna skill — §12 |
| Añadir o quitar un modelo del desplegable del panel | `.env` | `ANTHROPIC_MODELS` / `GOOGLE_AI_MODELS` — es lista blanca: lo que falte se rechaza con 400, §12 |
| Cambiar quién contesta cuando el proveedor pedido no tiene clave | `AgentEngineRegistry` | `elegir()` vs `porDefecto()` — asimetría deliberada, §12 |
| Cambiar cómo se traduce una skill al formato del proveedor | `AnthropicSkillAdapter` / `GoogleAISkillAdapter` | `esquema()` — únicos sitios que conocen el formato de cada API |
| Tocar el bucle de herramientas de Gemini | `GoogleAIEngine` | `conversar()` — es manual, sin SDK; `MAX_VUELTAS` es el freno de coste, §12 |
| Que Gemini responda más rápido / piense más | `.env` | `GOOGLE_AI_THINKING` — `low` quita el razonamiento, que se paga en CADA vuelta. `thinkingBudget` no vale en 3.x, §12 |
| Saber por qué un turno de Gemini tardó tanto | `var/log/dev.log` | Línea `Agent (google): vuelta N` con tiempo y tokens — §12 |
| Que el panel deje elegir motor y modelo | `AsistenteBar.vue` + `PanelAssistantController::motores()` | El catálogo lo sirve el backend: depende de qué claves tenga ESE entorno, §12 |
| Cambiar qué se hace cuando el modelo no usa ninguna skill | el adaptador del canal | `PanelAssistant::texto()` / `AiConversationProcessor::generar()` — §11 |
| Cambiar en qué idioma se guarda un mensaje del agente | `MessageTranslator` | `process()` — el caso saliente se distingue por `DIRECTION_OUTGOING`, §9 |
| Entender por qué un mensaje no salió | `var/log/` | busca "Omisión preventiva", "Rescate descartado", "Poda de canales", "Caducidad", "Sanidad" |
| Cambiar cuándo se sella el tipo de cambio de un cargo/pago del agente | `RegistrarCargoSkill` / `RegistrarPagoSkill` | `$tipoDelDia` (se persiste, siempre) vs `$tipoAplicado` (se reporta, sólo si hubo conversión) — §11, espejo del `tcSiempre` del panel |
| Cambiar qué estancias se ofrecen al imputar un cargo del agente | `RegistrarCargoSkill` | `estanciasImputables()` — mismo filtro que `BuscarEventoEstanciaSkill`, **no** el de `getEstancias()` |
| Cambiar quién puede figurar como cobrador de un pago | `PmsEnumAjaxController::getCobradores()` **y** `RegistrarPagoSkill::cobradoresPosibles()` | Filtro `ROLE_COBRADOR` sobre `u.roles` — los dos o el agente admite a quien el panel no ofrece |
| Cambiar en qué medios de pago se pregunta por el cobrador | `PmsMedioPago` | `seCobraEnMano()` — fuente única |
| Cambiar a quién se atribuye un pago si no se dice quién cobró | `User` | Flag `esCobradorPrincipal`, editable en el CRUD de usuarios |
| Probar una skill como una persona real (sus roles, su identidad) | — | `app:agent:skill <skill> '{...}' --usuario=<username>` |
| Construir un actor del agente (siempre por aquí) | `AgentActorFactory` | `delPanel()` / `delEquipoPorChat()` — expanden la jerarquía de roles. `AgentActor::` a secas se queda en los literales |
| Registrar el móvil de alguien del equipo | `User::$telefono` + CRUD de usuarios | Se normaliza solo (`UserIntegrityListener`); §12.9.b del doc de sync |
| **Quién recibe los avisos del asistente** | CRUD de usuarios | Rol «🆘 Atención al cliente» (`ROLE_CUSTOMER_SUPPORT`) **+ móvil**. Las dos cosas o no llega nada |
| Cambiar qué se escribe en el aviso de escalado | `EscalarAlEquipoSkill` | `redactar()` |
| Que el aviso salga fuera de la ventana de 24 h | Meta, no el código | Dar de alta una plantilla oficial de aviso interno y enchufarla en `avisar()` |
| Cambiar cuándo un número «es» de una reserva | `PmsReservaRepository` | `findVivasByTelefono()` + `DIAS_GRACIA_TRAS_SALIDA` / `DIAS_ANTICIPACION` |
| Cambiar qué estados dan derecho a consultar la propia reserva | `PmsEventoEstado` | `IDENTIFICAN_HUESPED` — **no** reutilizar `OCUPAN_UNIDAD`, ver su docblock |
| Cambiar cuál se elige si un número tiene varias reservas | `PmsReservaRepository` | `findVivasByTelefono()` (orden) + `tramoRelevante()` — actual > próxima |
| Que el operador vea (o no) las reservas canceladas al buscar | `BuscarReservaSkill` | Filtro por `isCancelada()` + parámetro `incluir_canceladas` |
| Cambiar el orden en que se le presentan las reservas al operador | `BuscarReservaSkill` | `ordenarPorPertinencia()` — alojado ahora > por llegar > ya se fue |
| **Reconocer al equipo en el chat entrante** (pendiente) | `AiConversationProcessor::generar()` | Hoy siempre `AgentActor::huesped()`. Falta consultar `UserRepository::findByTelefono()` y, si hay usuario, `delEquipoPorChat()`. El actor se arma **una vez** y lo usan los tres pasos (triaje, charla, camino largo): cambiarlo ahí cambia también qué skills ve el clasificador, §13.1 |
| Cambiar qué tipos de cargo suman en una cuenta anulada | `PmsInformacionFinancieraRecalculoService` **y** `RegistrarCargoSkill` | el `AND (i2.activa = 1 OR …)` del SQL y la `$advertencia` de la skill — los dos o la previsualización vuelve a mentir |
| **Cuánto espera el bot a que el huésped termine de escribir** | `.env` | `AGENT_IA_ESPERA_RAFAGA` — §9. Si lo subes, revisa `check_delayed_interval` en `messenger.yaml`: el retraso real es la suma |
| Que el retraso de la espera de ráfaga se note en el chat | `config/packages/messenger.yaml` | `check_delayed_interval` — cada cuánto mira el worker los diferidos, §9 |
| Cambiar qué mensajes se agrupan por ráfaga | `MessageAutoResponderListener` | `processIntent()` — hoy sólo `free_text`; botones y alertas salen sin retraso, §9 |
| Cambiar cómo se decide qué mensaje de la ráfaga contesta | `AiConversationProcessor` | `hayMensajePosterior()` — `createdAt` manda, el UUIDv7 desempata el mismo segundo. **No** lo pases a `COALESCE(scheduledAt, …)`, §9 |
| Filtrar por una entidad con id UUID en un QueryBuilder | cualquier repositorio | `setParameter(…, $entidad->getId(), 'uuid')` — sin el tipo devuelve **cero filas en silencio**, §9 |
| **Que el huésped pueda contestarse algo nuevo por sí mismo** | una skill de `Lectura` + `Roles::HUESPED` | Nunca metiendo el dato en `systemPrompt()`: ahí lo responde sin herramienta y sin rastro que auditar, §9 |
| Qué recibe el huésped cuando el modelo no usa ninguna skill | `AiConversationProcessor::generar()` | Se entrega el texto tal cual. **Volver a tirarlo exige devolver antes los datos al prompt**, o el bot deja de saber saludar, §9. Ojo: la charla del triaje **no** pasa por aquí, §13.7 |
| Cuándo sale el acuse de recibo genérico | `AiConversationProcessor::generar()` | Sólo si el motor no devuelve texto. Es el suelo, no la política — y **no escala**, §11 |
| **Añadir algo al prompt del huésped** | `reglas()` si vale para todos, `contexto()` si es suyo | Un `{$…}` en `reglas()` rompe el caché de todos y **no da error**, sólo sube la factura, §12 |
| Dónde se marca el caché de Anthropic | `AnthropicSkillAdapter::traducir()` (última herramienta) y `AnthropicEngine::bloquesDeSistema()` | Las dos marcas juntas cachean catálogo + reglas; lo volátil va detrás, §12 |
| Cuánto vive el catálogo cacheado | `AnthropicSkillAdapter::TTL_CACHE` | `1h`. Con `5m` harían falta ~24 llamadas/hora para amortizar la escritura, §12 |
| Que una skill que escribe llegue al chat del huésped | la propia skill | `nivelRiesgo(): NivelRiesgo::Interna` — sólo si escribe *hacia dentro* y el peor caso es perder un minuto, §11 |
| Cambiar qué se filtra en el chat del huésped (sólo lectura) | `SkillRegistry::paraActor()` | `NivelRiesgo::exigePermisoDeEscritura()` — **no** comparar con `Lectura`, §11 |
| Que el huésped consulte su saldo o el desglose de su cuenta | `ConsultarMiReservaSkill` (cifra) / `ConsultarCuentaSkill` (detalle) | La segunda ignora `reserva_id` si hay contexto: `reservaDelContexto()`, §11 |
| Que el huésped pueda avisar al equipo | `EscalarAlEquipoSkill` | Ya la tiene (`Roles::HUESPED` + `Interna`). El prompt le obliga a llamarla si promete respuesta, §9 |
| **Que el huésped pueda pedirse una plantilla** (su guía, tours) | CRUD de plantillas | Interruptor «autoenvío» (`autoenvio_habilitada`) + «Cuándo usarla» rellenado. La manda `EnviarmePlantillaSkill`, §11 |
| Qué plantillas puede mandar el agente del panel | CRUD de plantillas | TODAS las que tengan «Cuándo usarla» escrito — ya no hay flag; guardan la confirmación del operador y `allowedSources`, §11 |
| Que una plantilla sólo salga a su OTA (Booking vs Airbnb) | CRUD de plantillas | `allowedSources` — lo validan por código las dos skills de plantillas, §11 |
| **Añadir o cambiar una marca de formato** (*negrita*, __subrayado__…) | `FormatoDeTexto` **y** `util/src/utils/formatoDeTexto.ts` | Son espejo: los dos o el panel pinta distinto de lo que llega, §14 |
| Que el asistente del panel enlace a una ficha | `ListarSalidasSkill` | `fichaDe()` — espejo de `verEnReservas()` en `HomeView.vue` |
| Cambiar cómo degrada un canal el texto libre | `Beds24SendMappingStrategy` / `WhatsappMetaSendMappingStrategy` | `paraTextoPlano()` / `paraWhatsapp()`, sólo con `getTemplate() === null`, §14 |
| Cambiar la barra B/I/U/S del compositor del chat | `ChatView.vue` | `aplicarFormato()` + `envolverSeleccion()` de `formatoDeTexto.ts`, §14 |
| Probar el formateador sin gastar API | — | `php var/probar-formato.php` — §14 |
| **Apagar el triaje y volver al agente de antes** | `.env` | `AGENT_IA_TRIAJE=0` — todo pasa por el camino largo con el catálogo entero, §13 |
| **Cambiar qué modelo atiende cada paso** | `.env` | `AGENT_IA_POTENCIA_ALTA` / `_MEDIA` / `_BAJA`, en la forma `proveedor:modelo`. Vacías = como antes, §13.5 |
| Subir o bajar la potencia del clasificador | `.env` | `AGENT_IA_TRIAJE_POTENCIA` — `alta` \| `media`. Nunca `baja`: corre en TODOS los mensajes, §13.5 |
| Bajar de tramo el trabajo que queda tras elegir una skill | la propia skill | `definicion()->siguientePaso` — hoy ninguna está bajada, y §13.5 dice por qué |
| Cambiar qué cuenta como emergencia | `Triaje::reglas()` | El prompt, no un regex. Ante la duda elige `emergencia` a propósito, §13.2 |
| Que el triaje ofrezca otra clase de mensaje | `TipoDeMensaje` | El `case` **y** `opciones()`, que es lo que ve el modelo — `Indeterminado` se omite a propósito, §13.2 |
| Que la sugerencia del triaje mande de verdad | `AiConversationProcessor::pistaDelTriaje()` | Está redactada como sugerencia a propósito: §13.8. Piénsalo antes |
| Cambiar qué temas de la guía ve el triaje | `IndiceDeGuia::construir()` | Índice GLOBAL (uuid + etiqueta + casitas), determinista para no romper el caché. Lee `getTitulo()`, no `getTituloParaCliente()` (transitorio, llega vacío), §13.8 |
| Cambiar cómo se valida el tema que propuso el triaje | `Triaje::interpretar()` | Contra los temas de LA CASITA (`porUnidad`), no contra el índice entero, §13.8 |
| Comprimir el índice de guía cuando el parque crezca | `IndiceDeGuia` | 🚧 uuid → ordinal con mapa reconstruido por petición; el umbral y el porqué, en su docblock y §13.8 |
| Ajustar qué puede decir el bot en la charla (y cuándo ofrece ayuda) | `Triaje::reglas()` | Las reglas de la «respuesta» viven en el prompt del clasificador. La seguridad viene de que NO hay herramientas, §13.7 |
| Añadir una llamada sin herramientas a un motor nuevo | `AgentEngineInterface` | `turnoDirecto()` — con esquema, la salida la fuerza el proveedor, §13.6 |
| Que un esquema JSON funcione también en Gemini | `GoogleAIEngine::esquemaGemini()` | Gemini rechaza `additionalProperties` y los `type` en lista con un 400, §13.6 |
| Medir cuánto ocupa cada prompt sin gastar API | — | `php var/medir-triaje.php` — §13.3 |
| Comprobar el triaje y el esquema de Gemini sin gastar API | — | `php var/probar-triaje.php` — 15 comprobaciones + qué motor resuelve cada tramo, §13.10 |
