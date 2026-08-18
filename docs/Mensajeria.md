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
16. [Cómo se paga: medios de cobro y tipo de cambio](#16-cómo-se-paga-medios-de-cobro-y-tipo-de-cambio)
17. [La conciencia del tiempo y del espacio](#17-la-conciencia-del-tiempo-y-del-espacio)
18. [Sincronizar plantillas con Meta](#18-sincronizar-plantillas-con-meta-por-qué-aparecen-duplicados-y-qué-no-hacer)
19. [Con quién se habla: vínculo, restricción de canal y categoría de conocimiento](#19-con-quién-se-habla-vínculo-restricción-de-canal-y-categoría-de-conocimiento)
20. [La cirugía: separar la persona del asunto](#20-la-cirugía-separar-la-persona-del-asunto)

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

**Espejo PHP ↔ TypeScript de los estados.** La lista cerrada de estados pintables vive también
en `util/src/types/mensajeEstadoModel.ts` (`EstadoMensaje` + `aEstadoMensaje()`), porque la API
los expone como `string` —salen de un getter y OpenAPI no ve un enum ahí— y `MessageStatusIcon`
necesita saber cuáles son para elegir icono. **Si nace un estado nuevo en PHP, hay que tocar los
dos lados**: si no, el mensaje llega y no se pinta nada.

`aEstadoMensaje()` es un normalizador y no un cast a propósito: lo desconocido cae en `pending`,
el icono más neutro. Un estado nuevo del backend, o un `null` de una fila a medio migrar,
pasarían el compilador sin problema y reventarían al buscar el icono.

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

La unidad de trabajo del motor es la **AGENDA de un asunto** (`AgendaDeAsunto`), no la
conversación. Si el hilo tiene enlaces persistidos (§20), cada enlace aporta su agenda; si no
tiene —hoy, casi todos—, la agenda única sale de `contextType`/`contextId`/`contextData` de la
conversación y todo esta sección aplica tal cual. El detalle del modo por-asunto está en §20.5.

Por cada regla del `contextType` de la agenda, el motor calcula dos cosas independientes:

- **`calculateRunAt()`** — la fecha: hito + `offsetMinutes`. `null` si el hito no existe.
- **`ruleAppliesToAgenda()`** — si la regla tiene derecho a existir aquí y ahora.

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

⚠️ Esta tabla describe el **modo legado** (conversación sin enlaces). En modo por-asunto la
muerte viaja en el ENLACE y la regla es otra —y más dura—: ver §20.5.

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

### 4.3 Identidad del mensaje: (REGLA, ASUNTO), no la plantilla

`Message::getRule()` (columna `rule_id`, migración `Version20260805120000`) empareja un mensaje
con la regla que lo creó, y `Message::getAsuntoType()/getAsuntoId()` (columnas `asunto_type` y
`asunto_id`, migración `Version20260812300000`) con el asunto por el que se programó.

Las dos mitades existen por la misma clase de bug:

- **La regla y no la plantilla**: dos reglas que compartieran plantilla —un recordatorio a −7
  días y otro a −1 con el mismo texto— se reconocían como el mismo mensaje y se pisaban el
  `scheduledAt`. `MessageRuleEngine::messageBelongsToRule()` cae en la plantilla sólo para
  mensajes anteriores a la columna (`rule_id IS NULL`).
- **El asunto**, porque con varios enlaces en el mismo hilo la misma regla programa N mensajes
  legítimos, uno por reserva. `MessageRuleEngine::messageBelongsToAsunto()` deduce para los
  mensajes sin estampar (`asunto_type IS NULL`): pertenecen al asunto propio de su conversación,
  porque en esa era la conversación ERA el asunto — y los ADOPTA (los estampa) al primer toque.
  No hay backfill en la migración a propósito: sería la misma deducción repetida en SQL, dos
  criterios condenados a separarse.

### 4.4 Los cuatro umbrales temporales

Todos son constantes de `MessageRuleEngine` y todos se miden en `America/Lima`:

| Constante | Valor | Qué decide |
|---|---|---|
| `PAST_THRESHOLD` | −2 h | Más atrás que esto, el mensaje ya no se programa en su fecha original |
| `RESCUE_WINDOW` | −24 h | Antigüedad máxima que admite el rescate de última hora |
| `EXPIRY_WINDOW` | −12 h | Un pendiente más atrasado que esto se cancela por caducidad |
| `TZ` | `America/Lima` | Zona en la que se interpretan hitos y "hoy" |

**El rescate** (`canRescue`) crea el mensaje con `runAt = now` cuando su fecha teórica ya pasó.
Se activa en `INSERT`, en `COMMAND + force` o cuando el ASUNTO acaba de nacer (`esNueva`: enlace
con `createdAt === null`, es decir, creado en esta misma unidad de trabajo — §20.5), y sólo
dentro de `RESCUE_WINDOW`. Ese suelo es imprescindible: sin él, importar el histórico de un
canal —donde toda conversación entra como `INSERT`— disparaba de golpe los mensajes de
bienvenida de reservas hechas hace meses.

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

### «Ver Reserva» abre la ficha SOBRE el chat, no el panel

El botón del encabezado de `ChatView.vue` llevaba a EasyAdmin (`{panel}/pms-reserva/{id}`,
en otra pestaña). Consultar la reserva es parte de contestar el mensaje —¿qué día llega, qué
pagó, en qué casita está—, así que salir de la SPA para leerla costaba la conversación: al
volver, el borrador se había perdido y había que rebuscar el hilo.

Hoy monta el mismo `ReservaEditDrawer` que usa el calendario de reservas, **sin el
calendario detrás**. Cerrarlo devuelve al operador exactamente donde estaba.

```
contextType = 'pms_reserva'  →  chatStore.getReservaContextId  →  <ReservaEditDrawer>
otro contexto                →  chatStore.getExternalContextUrl →  <a> al panel
```

Detalles que no se ven en el diff:

- **`contextId` es opaco**: sólo significa «reserva» cuando `contextType` es `pms_reserva`.
  La comprobación vive en `getReservaContextId` (store), no en la plantilla, para que el día
  que haya drawer de cotizaciones no se copie el `if` a otra vista.
- **El drawer es autosuficiente**: `cargarDatos()` pide sus propios maestros y la reserva, así
  que no necesita nada de `ReservasView`. Por eso se puede montar aquí tal cual.
- **`:key="reservaDrawerId"`** fuerza el remontado al cambiar de reserva, y un `watch` sobre la
  conversación activa lo cierra: sin él, cambiar de hilo dejaba la ficha del anterior encima
  de los mensajes del nuevo.
- **`onBeforeRouteLeave`** hace que el «atrás» del móvil cierre la ficha en vez de sacarte del
  chat — mismo criterio que `ReservasView`.
- **`abiertoDesdeChat`** apaga el botón de «chat interno» del propio drawer: desde aquí sólo
  cerraría la ficha para llevarte a la conversación en la que ya estás.
- `getExternalContextUrl` **sigue existiendo**: es la salida al panel de los contextos que aún
  no tienen pantalla en la SPA. Cuando no quede ninguno, se retira entero.

### 🔥 `unreadCount` es un contador desnormalizado, y derivó

`MessageConversation::$unreadCount` **no se calcula**: es una columna que se
incrementa al insertar cada mensaje entrante. Como todo dato desnormalizado, puede
separarse de la realidad — y se separó.

`Message::onPrePersist()` ya incrementa el contador para **todo** mensaje entrante.
Pero los persisters de recepción llamaban además a `incrementUnreadCount()` por su
cuenta:

- `Beds24ReceivePersister` (rama `SENDER_GUEST`)
- `WhatsappMetaReceivePersister`, en sus **dos** caminos de alta

Resultado: el contador subía **de dos en dos**. En producción se vio una
conversación marcando 24 no leídos cuando solo había 12 mensajes `incoming` en
estado `received`. El síntoma es engañoso porque el número *parece* plausible: nadie
sospecha de un contador, se sospecha del chat.

Los tres duplicados están quitados. Lo que hay que recordar:

> **La conversación se actualiza sola al persistir el mensaje.** `lastMessageAt`,
> `lastInboundAt` y `unreadCount` los pone `Message::onPrePersist()`. Un persister
> nuevo **no** debe tocarlos. Poner la fecha otra vez es inofensivo (es idempotente);
> incrementar el contador, no.

La verdad, cuando haya que comprobarla, es `direction = incoming AND status =
received`: exactamente lo que `MarkConversationReadController` pasa a `read`.

#### La deriva contraria: contador 0 con mensajes en `received`

`RebuildConversationContextCommand` pone `unreadCount = 0` cuando el último mensaje
real de la conversación es **saliente** (el anfitrión ya contestó, luego lo anterior
está leído). La heurística es buena, pero bajaba **solo el contador** y dejaba los
mensajes entrantes en `received` para siempre. En producción llegó a haber **54
conversaciones con contador 0 y 156 mensajes «sin leer»**.

Eso convierte el recálculo en una trampa: recalcular «honestamente» desde los
mensajes resucita 156 avisos que el operador dio por vistos hace meses. Por eso:

- El comando de reconstrucción ahora marca **también** los mensajes como `read` al
  resetear el contador. Las dos fuentes dejan de separarse.
- El comando de recálculo **solo baja contadores** por defecto. Subir uno requiere
  `--permitir-subir` y hay que saber por qué se hace.

#### Delegación en el triaje

Cuando el autoresponder está encendido, el resumen **no cuesta una llamada aparte**: el
clasificador ya ha leído esos mensajes para decidir de qué van, así que devuelve también el
resumen en su mismo JSON (`Triaje::esquema()`, campo `resumen`).

`AiConversationProcessor` lo persiste junto con `resumenIaHasta`, y el trabajo de
`GenerarResumenDispatch` —que se encola **detrás** del triaje cuando el agente está activo—
se encuentra la marca puesta y se va sin llamar al modelo.

```
agente ENCENDIDO   triaje (20 s) escribe resumen → job (25 s) ve la marca y se va   → 1 llamada
agente APAGADO     no hay triaje                 → job (8 s) genera el resumen      → 1 llamada
```

> **La capacidad no depende del agente**, solo se delega en él cuando está disponible.
> Apagar `AGENT_IA_AUTORESPONDER` no deja al equipo sin resúmenes: el listener vuelve a la
> espera corta y `ResumenConversacionService` los genera por su cuenta. Si el modelo no
> rellena el campo `resumen`, pasa lo mismo — el servicio lo cubre.

**Red de seguridad** — el contador no se recalcula solo en ningún flujo, así que hay
un comando idempotente para reconciliarlo:

```bash
# Ver qué cambiaría, sin escribir
php bin/console app:message:recalcular-no-leidos --dry-run

# Aplicar
php bin/console app:message:recalcular-no-leidos

# Además, dar por leídos los pendientes viejos de conversaciones
# cerradas/archivadas que nadie va a contestar
php bin/console app:message:recalcular-no-leidos --marcar-leidas-antes-de=2026-07-01
```

Quién lee este contador: el badge del icono de la PWA y los contadores del portal,
vía `UnreadSummaryController`. Ver [`PwaNotificaciones.md`](PwaNotificaciones.md).

#### Quién consume el resumen

Cuatro sitios, todos leyendo el mismo campo:

| Dónde | Qué pinta |
|---|---|
| Notificación push | El cuerpo del aviso |
| Panel de chats sin leer (Home) | La línea bajo el nombre del huésped |
| Bandeja del chat | La línea de vista previa |
| Ventana flotante del *stalker* | La banda naranja de arriba |
| Aviso de escalado (`EscalarAlEquipoSkill`) | Contexto junto al motivo |

En el escalado entra **dentro de `{{motivo}}`**, no como variable nueva: la plantilla
`aviso_escalado_interno` está aprobada por Meta y añadirle un `{{resumen}}` obligaría a
re-aprobarla, con días de espera. En el camino de texto libre —dentro de la ventana de
24 h— sí va en su propia línea, que ahí no hay límite de formato.

### El resumen IA de lo pendiente

`msg_conversation.resumen_ia` guarda, en una frase, **qué está pidiendo el huésped y
todavía no se le ha contestado**. Lo leen tres sitios: el cuerpo de la notificación push,
el panel de chats sin leer del portal y la bandeja del chat. Una llamada al modelo, tres
consumidores — por eso es un campo y no un cálculo al vuelo.

**La ventana es el motivo de que sea barato.** No se resume la conversación entera: solo
los mensajes del huésped **posteriores a la última respuesta del equipo**. Normalmente son
dos o tres líneas de entrada y sale una frase, con el tramo `baja` de potencia (Haiku).

```
… huésped … huésped … EQUIPO RESPONDE … huésped … huésped … huésped
                          ▲             └──────── ventana ────────┘
                       el corte
```

Si el equipo contesta, la ventana queda vacía y el resumen se borra: ya no hay nada
pendiente que resumir.

| Pieza | Archivo |
|---|---|
| Cálculo y prompt | `ResumenConversacionService::actualizar()` |
| Encolado (con espera de ráfaga) | `MessageResumenListener` |
| Ejecución en el worker | `GenerarResumenDispatchHandler` |
| Texto de respaldo sin IA | `ResumenConversacionService::textoDeRespaldo()` |

**Dos guardas que no hay que quitar:**

- **`resumen_ia_hasta`** — la fecha del mensaje más reciente ya reflejado en el resumen. Es
  lo que hace idempotente al handler: una ráfaga de cuatro mensajes encola cuatro trabajos,
  el primero resume y los otros tres se van sin llamar al modelo. Sin este campo se pagan
  cuatro llamadas por lo mismo.
- **`AGENT_IA_RESUMEN_ESPERA`** (8 s) — el retraso del encolado. Es lo que da tiempo a que
  la ráfaga esté completa cuando el primer trabajo se ejecuta. Ponerlo a 0 no rompe nada,
  pero multiplica el gasto.

**Degrada, no rompe.** Sin motor disponible, con `AGENT_IA_RESUMEN_CONVERSACION=0` o si la
llamada falla, el resumen se queda como estaba y los tres consumidores caen al texto del
último mensaje. Una notificación nunca se queda sin cuerpo.

**El push sale el ÚLTIMO, a propósito: fiable antes que rápido.**

Sobre el mismo mensaje entrante corren dos procesos que cambian lo que el operador debería
leer en el aviso. Enviando al instante, la notificación mentía por omisión de dos formas: el
cuerpo llevaba el resumen del turno **anterior**, y avisaba de algo que el agente iba a
resolver solo unos segundos después.

```
mensaje entra ──┬─► GenerarResumenDispatch          (+8 s)   → escribe resumen_ia
                ├─► ProcessInboundIntentDispatch    (+20 s)  → el agente puede contestar
                └─► EnviarPushConversacionDispatch  (+30 s)  → lee todo y avisa
```

El retraso del push es `max(AGENT_IA_RESUMEN_ESPERA, AGENT_IA_ESPERA_RAFAGA) + 10 s`. Ese
margen cubre lo que tarda el worker en coger el trabajo más la llamada al modelo.

Gracias a esperar, el aviso puede afirmar algo que antes no sabía: si el último mensaje es
del agente (`SENDER_SYSTEM`), el cuerpo empieza por **«🤖 Respondido por IA»**. No es lo
mismo «alguien espera respuesta» que «esto ya está atendido, échale un ojo».

> **No hay acoplamiento entre los dos procesos.** Con el agente apagado el aviso llega
> igual, solo unos segundos más tarde: `AGENT_IA_ESPERA_RAFAGA` sigue configurada aunque
> nadie la use. Ninguno de los dos tiene que existir para que el push salga.

Efectos secundarios buenos de haberlo sacado del listener: el envío HTTP a FCM/APNs ya no
ocurre dentro de un flush de Doctrine, y el handler descarta el aviso si para entonces
alguien ya abrió el chat (`unreadCount === 0`).

### 🔥 La espera de ráfaga ya no es un número fijo

> **La ventana bajó de 40 a 15 s en agosto de 2026.** Remedidas las **1.950 continuaciones**
> reales del histórico, la curva es plana y de cola larga —10s: 12 %, 20s: 23 %, 30s: 29 %,
> 40s: 34 %, 60s: 43 %, 120s: 64 %—: no existe el punto donde «ya está». Cada 10 s compran unos
> 5 puntos, y a cambio la latencia observada en esos mensajes era de **47-75 segundos**. Los 16
> puntos que se pierden no quedan desprotegidos: la segunda mirada de ráfaga descarta la
> respuesta si el huésped escribió mientras se generaba. La espera es el cinturón; esa
> comprobación, los tirantes.

Era `AGENT_IA_ESPERA_RAFAGA` para todos. Medido sobre 873 mensajes reales (mayo-agosto 2026),
un número fijo fallaba por los dos lados: los 20 s solo capturaban el **37 %** de las
continuaciones —al que escribe despacio se le contestaba a medias igual— y el **81 %** de los
mensajes no se continúa nunca, así que esperaban para nada.

`PreRouterRafaga` decide por mensaje. Tres reglas deterministas resuelven el 46 % del tráfico
sin llamar al modelo, con su tasa de continuación medida:

| Rasgo | Tráfico | Continúa ≤40 s | Decisión |
|---|---|---|---|
| Saludo suelto | 2 % | **60 %** | espera |
| Termina en `?` | 20 % | 12 % | ejecuta |
| 12+ palabras | 24 % | 8 % | ejecuta |
| Resto (2-11 palabras, sin `?`) | 54 % | 24 % | **al modelo** |

> ⚠️ **No inventes un umbral de longitud dentro de la banda 2-11.** La curva es plana y
> ruidosa ahí (13-27 % sin tendencia). «Pásame la reserva del 4» son cinco palabras y está
> terminada; «hola, mira» son dos y no. Solo el significado las distingue.

**Dónde se llama, y por qué ahí.** En el HANDLER, no en el listener: hace una petición de red
y el listener corre dentro de un flush de Doctrine. El listener encola con
`AGENT_IA_ESPERA_CORTA` (3 s) y, si el pre-router dice «espera», el handler reencola con la
ventana larga. El flag `yaEsperado` corta el bucle — sin él, un modelo que respondiera
siempre «espera» reencolaría el mensaje indefinidamente.

**La asimetría es el diseño.** Un «espera» equivocado retrasa unos segundos; un «ejecuta»
equivocado hace que el bot conteste a medias a un cliente real. Por eso el prompt está sesgado
a esperar y **todo fallo devuelve «espera»**: sin motor, JSON roto, excepción o pre-router
apagado. Nunca se arriesga a contestar antes de tiempo por un problema técnico.

### 🔥 Quién escribe por WhatsApp: equipo o huésped, lo decide el NÚMERO

`AiConversationProcessor::generar()` construía **siempre** un actor huésped, hardcodeado. Un
operador preguntando por WhatsApp «¿quién sale mañana?» recibía *«no tengo acceso a esa
información»*: el agente le daba las skills de un cliente.

`UserRepository::findByTelefono()` y `AgentActor::delEquipoPorChat()` existían desde el
principio —el docblock del primero describe exactamente este caso— y **no los llamaba nadie**.
Faltaba el cable. Ahora:

```
número entrante → user.telefono ?
                    ├── sí  → delEquipoPorChat()  → sus roles, skills de operación
                    └── no  → huesped()           → acotado a SU reserva
```

**El formato del teléfono es lo que más falla.** La comparación es contra el número ya
normalizado por `PhoneSanitizer::cleanPhoneNumber($n, 'PE')`, que devuelve código de país +
dígitos, sin `+` ni espacios (`51921166466`). Guardar «+51 921 166 466» en `user.telefono`
hace que no case **nunca**, y el síntoma es que el operador sigue siendo tratado como huésped
sin un solo error en ningún log.

Esto convierte el número en una **credencial** — y es una credencial sólida, mejor de lo que
suena a primera vista.

#### El número de WhatsApp NO se puede suplantar

Es la diferencia con el correo electrónico, donde el remitente es texto que escribe cualquiera.
Aquí el mensaje no lo manda quien quiere: **lo manda Meta a nuestro webhook, firmado con
HMAC-SHA256 sobre el cuerpo entero** (`MetaWebhookController::firmaValida()`), y el número va
dentro de lo firmado. Para hacerse pasar por el número de un operador haría falta el App Secret.

Como método de identidad es **un factor de posesión verificado en cada mensaje**, y eso es más
fuerte que una contraseña — sobre todo que una contraseña compartida o expuesta, que no prueba
posesión de nada y viaja en cuanto alguien la reenvía.

> 🔒 **De lo que depende:** si `exchange_meta_config.credentials.appSecret` se queda vacío, el
> controlador **deja pasar sin validar** y entonces sí, cualquiera podría inventarse un payload
> con el número de un operador. Está puesto en producción y el log grita si falta, pero es el
> supuesto que sostiene todo lo anterior. Si alguna vez se vacía, esta identificación deja de
> valer el mismo día.

Lo que el número **no** cubre no es la suplantación, es la **transferencia**: SIM swap, un móvil
desbloqueado en manos ajenas, un WhatsApp Web abierto, o el número reciclado años después. Es la
misma familia de riesgo que una sesión robada, no la de una contraseña adivinada.

> 🔓 **Sobre esa base, el canal dejó de ser de sólo lectura para el equipo** (2026-08-17): un
> operador identificado por su número puede ejecutar escrituras por WhatsApp, con sus roles de
> siempre y con la previsualización de rigor. Al huésped no le cambia nada — no tiene ningún rol
> de escritura.
>
> La transferencia se asume, por proporción: quien te roba el móvil desbloqueado va a por tus
> cuentas bancarias, no a cambiar el código de la caja de las llaves. Un segundo factor aquí
> costaría más de lo que protege.
>
> ⚠️ La razón que **no** vale, y circuló: que una escritura «tendría que confirmarse y aquí no
> hay a quién preguntar». Confirmar una escritura es enseñársela a quien la pidió (§11 A), así
> que en un chat siempre hay a quién. Lo que falta no es el interlocutor.

**Una consulta interna resuelta se da por leída.** Cuando quien escribe es del equipo y el
agente le contesta, `marcarLeidaSiEsDelEquipo()` pone a cero el contador **y** el estado de
los mensajes. Si no, esa consulta se quedaría para siempre en la bandeja de pendientes, en el
badge del icono y en el panel de Home, compitiendo con huéspedes que sí esperan respuesta —
y nadie del equipo va a contestarle a un compañero.

Solo se marca cuando la respuesta **salió**. Si el bot se calló por cualquiera de los seis
guardias, la conversación sigue sin leer a propósito: ahí sí hay algo pendiente. Y con un
huésped no se hace nunca, conteste el bot o no: el operador quiere revisar qué se le dijo.

**Los privilegios se ACUMULAN, como en cualquier ACL.** Alguien del equipo con una reserva a
su nombre es las dos cosas a la vez, así que el actor lleva sus roles **más** `ROLE_HUESPED`
(`AgentActorFactory::delEquipoPorChat(..., tambienHuesped: true)`). Sin eso, registrar el
móvil de una persona del equipo le quitaba en silencio las skills de huésped en su propia
conversación de reserva —`enviarme_plantilla` y `escalar_al_equipo`, que ningún perfil de
operación tiene—.

Sumar el rol **no abre nada de nadie más**: las skills de huésped están acotadas por el
CONTEXTO de la conversación, y `ConsultarMiReservaSkill` ni siquiera acepta un parámetro con
el que apuntar a otra reserva.

El flag va apagado por defecto porque en el panel y en la CLI el actor no es huésped de nada.

### 🔥 En DQL, pasar la ENTIDAD como parámetro no casa con un id `binary(16)`

Los ids del proyecto son UUID guardados en `binary(16)` (ver `IdTrait`). En una consulta
del QueryBuilder, esto **devuelve cero filas siempre**:

```php
->where('m.conversation = :c')
->setParameter('c', $conversacion)          // ❌ la entidad
```

Doctrine extrae el identificador y lo enlaza como la **cadena canónica con guiones**
(`019d13e3-f010-…`), que no es lo que hay en la columna. No lanza ninguna excepción, y el
SQL generado se ve impecable:

```sql
SELECT COUNT(m0_.id) FROM msg_message m0_ WHERE m0_.conversation_id = ? AND m0_.direction = ?
```

Lo correcto es pasar el id **con su tipo**:

```php
->setParameter('c', $conversacion->getId(), 'uuid')   // ✅
```

> Cómo se cazó: la misma conversación daba **190 mensajes en SQL crudo y 0 en DQL**.
> Si una consulta por asociación devuelve vacío contra toda lógica, mira el binding antes
> que el SQL. `findBy(['conversation' => $entidad])` **sí** funciona —ahí Doctrine conoce el
> tipo de la asociación—, así que el fallo solo aparece en el QueryBuilder.

Lo mismo vale para un objeto `Uuid` suelto (`setParameter('x', $e->getId())`) y para las
listas: una lista de entidades en un `IN (:ids)` hay que pasarla como binario crudo
(`array_map(fn ($e) => $e->getId()->toBinary(), $lista)` + `ArrayParameterType::BINARY`).

#### El destrozo que causó: el mismo mensaje enviado hasta 6 veces

Esta trampa estaba documentada aquí y en dos sitios del PMS, pero **nadie barrió el resto del
código**, y la barrera de idempotencia de las colas la tenía dentro:

```php
// Beds24SendEnqueuer::isAlreadyEnqueued() — roto hasta 2026-08-12
->where('q.message = :message')
->setParameter('message', $message)        // ❌ COUNT siempre 0
```

Con esa barrera muerta, **cada** `preUpdate` del mensaje —y `MessageEnqueuerEntityListener`
llama a `fabricarColas()` en todos ellos— fabricaba otra cola para el mismo mensaje. Todas con
el mismo `run_at`, todas `success`, todas POSTeadas de verdad a Beds24. Medido en producción:

| Qué | Cuánto |
|---|---|
| Mensajes con más de una cola beds24 viva | 54 |
| Mensajes con más de una cola whatsapp viva | 49 |
| Copias del mismo recordatorio a un huésped | hasta **6** (Yael, 27-03-2026) |
| Huéspedes afectados | 23, entre 2026-03-09 y 2026-08-05 |

Se distingue de un reintento porque los ids de Beds24 son **distintos y consecutivos** con un
segundo de diferencia (`155989144`, `…45`, `…46` a las 08:00:05/06/07): son POSTs de verdad, no
un reenvío del mismo. Y siempre a la hora en punto, que es cuando el motor de reglas pasa.

#### El barrido (2026-08-12)

Se auditaron todos los `setParameter()` del código moderno. Estaban rotos, y se arreglaron con
el tipo `UuidType::NAME`:

| Sitio | Qué hacía mal en silencio |
|---|---|
| `Beds24SendEnqueuer::isAlreadyEnqueued()` | duplicaba envíos a las OTA |
| `WhatsappMetaSendEnqueuer::isAlreadyEnqueued()` | lo mismo en WhatsApp |
| `InboundMenuResolver::ultimoMensajeReal()` | ningún menú numérico resolvía **nunca** |
| `PmsEspacioEstancia` | no veía vecinos: early check-in sobre casita ocupada |
| `PmsUnidadBeds24MapRepository` (4 consultas) | pull sin roomIds; el filtro por unidades no filtraba |
| `PushSubscriptionRepository::findByUser()` | cero suscripciones → ninguna push (método sin uso vivo) |
| `PmsRatesPushQueueRepository::findPendingForUnit()` | cola de tarifas por unidad, siempre vacía |
| `PmsDisponibilidadService` (filtro por establecimiento) | cero unidades disponibles al filtrar |
| `PmsReservaHuespedRepository::findByReservaId()` | cero huéspedes (método sin uso vivo) |

Ya estaban bien —y sirven de ejemplo— `ResumenConversacionService`, `AiConversationProcessor`,
`NotificadorPushConversacion`, `PmsInformacionFinancieraRepository` y `FinEnlacePagoRepository`.

### 🔥 Con `read: false`, API Platform NO aplica `security`

Una operación custom que no lee de Doctrine se declara así:

```php
new GetCollection(
    uriTemplate: '/conversations/unread-summary',
    controller: UnreadSummaryController::class,
    read: false, deserialize: false, output: false,
)
```

**El `security` del `ApiResource` no la protege, y el `security:` de la propia
operación tampoco.** La etapa que evalúa esos atributos va colgada del *read*, y con
`read: false` no se ejecuta. El endpoint estuvo en producción devolviendo nombres de
huéspedes y contadores **sin sesión**, mientras la colección normal de al lado sí
redirigía a login. No hubo ningún aviso: responde 200 y ya.

La comprobación tiene que ir **dentro del controlador**:

```php
public function __invoke(): JsonResponse
{
    $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW);
    // …
}
```

> **Al añadir una operación con `read: false`, pruébala con `curl` sin cookies antes
> de darla por buena.** Debe redirigir o dar 403, nunca 200 con datos:
> ```bash
> curl -s -o /dev/null -w '%{http_code}\n' https://api.openperu.pe/platform/…
> ```

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

> ⚠️ Corrección (2026-08-12): ese orden era necesario, pero **no era el motivo** de que los
> menús no funcionaran. La consulta ligaba la conversación como entidad y por tanto devolvía
> cero filas *siempre* (§7, el gotcha del `binary(16)`): `ultimoMensajeReal()` era `null` pasara
> lo que pasara y ningún número resolvía jamás. Las 151 conversaciones eran candidatas
> teóricas, no resoluciones reales. Arreglados los dos, el criterio de arriba sigue siendo el
> correcto.

**Por qué WhatsApp no sufría esto:** allí los botones son pulsables y llegan como
`button`/`interactive` **con su payload**, sin pasar por el resolutor. El interceptor numérico
es, en la práctica, la única puerta del motor determinista para las OTA — por eso el bug lo
dejaba prácticamente muerto en Beds24 (2 disparos frente a 835 mensajes) y no se notaba en Meta.


### 🔥 El desplegable de plantillas del chat SÍ filtra por canal (desde 2026-08-12)

`ChatView.vue` enseña las plantillas en `plantillasVisibles`, que cruza dos cosas:

1. `chatStore.validTemplates` — permiso por `contextType` y `allowedSources`.
2. **Los canales marcados en ese momento** (`selectedChannels`) contra `tpl.channels`.

El punto 2 faltaba, y el desplegable llevaba desde siempre diciendo «No hay plantillas para
este canal» sin mirar el canal: en una conversación de OTA salían plantillas que solo tienen
cuerpo de WhatsApp, y al revés. Elegir una de ésas no avisa de nada —el despacho se queda sin
canales y el mensaje termina en `failed` con `dispatch_errors`.

`channels` lo calcula el backend en `MessageTemplate::getChannels()` a partir del `is_active` de
cada bloque, así que el desplegable y `MessageDispatcher::resolveChannels()` usan la misma
verdad. Sin ningún canal marcado no se filtra: una lista vacía se leería como «no hay
plantillas» cuando lo que falta es elegir por dónde enviar.

⚠️ El equivalente de EasyAdmin (`MessageCrudController::getValidTemplateIds()`) **sigue sin
filtrar por canal**, y ahí es defendible: los canales se eligen en el propio formulario, después
de la plantilla. El de la reserva (`ReservasView.vue`) filtra por `whatsappLinkContent`, que es
su criterio correcto — ése es el enlace manual, no un canal.

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
| `AGENT_IA_ESPERA_RAFAGA` | Segundos de silencio antes de contestar a un `free_text`. **`15`**; `0` lo desactiva. Sólo se aplica cuando el pre-router dice que el huésped sigue escribiendo (~15 % del tráfico). Se suma a `check_delayed_interval` — sección anterior |
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

Tres reglas al escribirla:

- **La descripción es prompt, no documentación.** Di *cuándo* usarla y prohíbe responder de
  memoria si el dato tiene que salir de ahí. Eso es lo que sube la tasa de invocación.
- **Los errores de negocio se devuelven** (`SkillResult::error()`), no se lanzan: el modelo
  puede reformular. Una excepción es un fallo de infraestructura; «no encuentro a ese huésped»
  no lo es.
- **Un helper que devuelve varias cosas a la vez devuelve un objeto, no un array con la forma
  prometida en un docblock.** El docblock es una promesa que nadie ejecuta —PHPStan compara
  formas de array en nivel 3, no en el 2 del proyecto— y ya se rompió en producción: una
  salida temprana de `parsearDistribucion()` devolvió `[]` sin las claves prometidas, leer la
  clave ausente evaluó a `null` sin más que un warning, y la skill de disponibilidad murió en
  el caso normal con el agente de WhatsApp callado. El patrón es `final readonly` con
  constructor privado y un constructor nombrado — ver
  `DistribucionLeida` y el porqué completo en su docblock.

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

#### 🔥 «No inventes el valor de un parámetro» va en el prompt, no en cada skill

El caso que lo destapó: **«natalia me pagó 60»** se registró como *60.00 **USD** en **efectivo**,
cobrado por **Susan***. Tres datos que nadie dijo — la moneda, el medio y el cobrador (el «me»
significaba justo lo contrario). El operador aprobó la previsualización creyendo que se los
habían preguntado.

Y las tres barreras **estaban puestas y funcionaban**:

```
sin medio_pago  → error: "Falta el medio de pago. Pregunta al operador cómo pagó…"
sin moneda      → falta_datos: ['moneda'] → "¿los 60.00 son soles o USD?"
cobrador="yo"   → falta_datos: ['cobrador'] → "tu usuario no tiene el rol de cobrador…"
```

No se dispararon porque el modelo **rellenó los huecos en vez de omitirlos**. Una barrera sólo
salta si el parámetro llega vacío, y un modelo que adivina no deja ninguno vacío.

Por eso la regla va en `PanelAssistant::systemPrompt()` y no en la descripción de
`registrar_pago`: no es una particularidad de los pagos, es cómo hay que llamar a **cualquier**
skill. Va la primera de la lista, con el ejemplo concreto de lo que no debe hacer, y acompañada
de su pareja: **cuando una skill devuelve `falta_datos`, se pregunta — no se vuelve a llamar
quitando el dato que causó el aviso**, que era exactamente cómo el «me pagó» acababa en el
cobrador por defecto sin que nadie se enterara.

Comprobado con el mismo modelo que fallaba (`gemini-3.6-flash`):

| Frase | Antes | Después |
|---|---|---|
| «natalia me pagom 60» | registraba USD/efectivo/Susan | pregunta medio y moneda, y dice «cobrado por ti» |
| «…60 dólares en efectivo, lo cobró susan» | — | no pregunta nada, va directo a la previsualización |
| «quien sale mañana?» | — | responde directo, sin preguntas de más |

⚠️ **Una regla de prompt no es una garantía.** Reduce mucho el problema pero no lo elimina: lo
que de verdad protege son las barreras de las skills, que siguen ahí para cuando el modelo se
salte la instrucción. Si aparece un caso nuevo de dato inventado, el arreglo se busca **primero**
en hacer que la skill exija el dato, y sólo después en el prompt.

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
| `AgentActor::huesped($origen, $tipo, $id)` | Quien escribe **con** reserva | `ROLE_HUESPED` |
| `AgentActor::prospecto($origen)` | Quien escribe **sin** reserva | `ROLE_PROSPECTO` |

**El huésped no es un caso sin permisos**: es un actor más. Lo que lo distingue es que sus
skills están acotadas a su reserva por el contexto.

#### 👥 Dos workers contestando lo mismo: pasó de verdad

**10/08/2026, 14:21:28.** El huésped preguntó por Netflix y salieron DOS respuestas casi
idénticas *en el mismo segundo*, más una tercera tres segundos después:

```
14:21:17  huésped: «Dijeron que había Netflix en su plataforma»
14:21:28  bot: «Sí, la TV cuenta con Roku, donde tienes acceso…»
14:21:28  bot: «Sí, la televisión cuenta con un dispositivo Roku…»
14:21:30  huésped: «Pero no se puede ver por falta de pago me aparece»
14:21:31  bot: «Sí, la televisión cuenta con dispositivo Roku…»      ← y otra vez
```

**Los guardias no podían pararlo.** Preguntan «¿ya hay respuesta?» y contra dos trabajos
simultáneos pierden siempre: ninguno ha escrito nada cuando el otro mira.

⚠️ **La causa era el re-despacho por `postUpdate`.** El listener volvía a encolar el trabajo ante
CUALQUIER actualización del mensaje, y la más frecuente no tiene nada que ver con contestar:
**marcar como leído**. Basta con que un operador abra la conversación en el panel al ver el aviso
—justo lo que hace— para duplicar el trabajo del bot. Medido: 46 de 378 mensajes se actualizaron
dentro del primer minuto, y los guardias mataron 27 trabajos duplicados. Uno se escapó.

Se arregla en dos capas, y las dos hacen falta:

| Capa | Qué hace | Qué NO cubre |
|---|---|---|
| Filtro en `postUpdate` | Sólo re-despacha si cambió **el `inbound_intent`** dentro de `metadata` | Dos trabajos que entren por caminos distintos |
| `GET_LOCK` por mensaje | Un solo worker atiende cada mensaje; el segundo se retira | Nada, es el suelo |

El lock es de **MySQL y no del componente Lock de Symfony**: no está instalado y meterlo obligaría
a un `composer` en producción para algo que la base ya hace. Es consultivo —ni bloquea filas ni
abre transacción—, así que no retiene nada durante los 20-60 s que puede tardar el modelo. Vive
en la SESIÓN, así que sobrevive a los commits internos del turno y se suelta solo si el worker
muere: un proceso caído no deja el mensaje bloqueado para siempre.

Con espera `0`: el segundo trabajo no hace cola, se retira. Si de verdad hubiera algo nuevo que
contestar, ya vendrá su propio mensaje con su propio trabajo.

⚠️ **No basta con preguntar «¿cambió `metadata`?».** La primera versión del filtro hacía eso, y
el marcado-leído del panel —el disparador medido del incidente— **también escribe ahí**:
`setWhatsappMetaReadAt()` y `addWhatsappMetaMetadata('read_by_system')`, más el `_debug_trace`
que apila cada `add*`. El filtro dejaba pasar exactamente el caso que venía a cortar. Lo que
decide es si cambió el `inbound_intent`, que es lo único que este listener atiende.

⚠️ **Y comprobar → bloquear → VOLVER A COMPROBAR.** El `resolved` se lee antes del lock, y entre
esa lectura y el turno pasa el pre-router: una petición de red de segundos. En ese hueco otro
worker puede atender el mensaje entero y soltar el turno, y este entraría con un `resolved` de
antes. En `free_text` se salvaba de rebote —`yaSeRespondio()` recarga la colección—, pero la ruta
determinista **no tiene guardia**: sería una plantilla duplicada al huésped. El tercer paso
faltaba.

⚠️ **`GET_LOCK` devuelve `NULL` en error interno, sin lanzar.** Un `(int) null` es 0, o sea «lo
tiene otro», y el trabajo se retiraba dejando el mensaje sin contestar — lo contrario de la
política declarada. Se trata igual que la excepción: se deja pasar y se anota.

#### ⏱️ Por qué NO hay un watchdog del lock

La pregunta sale sola: si un turno se cuelga, el lock no expira y los trabajos siguientes de ese
mensaje se retiran. ¿Hace falta un proceso que vigile y limpie?

**No, y conviene saber por qué**, porque es una máquina que alguien propondrá otra vez:

1. **El cuelgue infinito no existe: hay tope por llamada.** Google 120 s
   (`GoogleAIClient::TIMEOUT`), Anthropic 120 s desde agosto de 2026 — antes usaba el defecto
   del SDK, **600 s**, que con ocho vueltas de herramientas permitía un turno de *ochenta
   minutos*. Igualar los dos era el arreglo real: una línea, no un proceso.
2. **El radio de daño es UN mensaje.** El nombre del lock lleva su id, así que un turno largo no
   bloquea nada más.
3. **Los trabajos que se retiran durante ese rato son justo los duplicados** que el lock existe
   para cortar. Saltárselos es lo correcto, no un efecto colateral.
4. **Un watchdog que matara sesiones de MySQL mataría la conexión del worker a mitad de turno**,
   que es peor que el problema: respuesta a medias en vez de respuesta tardía.

Lo que sí hacía falta era **verlo**, y para eso está el log del retiro.

Todo el sistema de fallos del lock **escribe log**, incluido el retiro normal. Es lo único que
distingue «mi gemelo está trabajando» de «un worker lleva media hora colgado reteniendo el
turno», que dejaría todos los trabajos siguientes de ese mensaje sin contestar y en silencio.

#### 🕳️ Cinco sitios por donde un mensaje se quedaba sin respuesta

Todos salieron de la revisión de arquitectura, y **cuatro de los cinco eran latentes**: el fallo
estaba, pero aún no había mordido. Se anota cuál sí, porque distingue lo urgente de lo prudente.

| Qué pasaba | ¿Mordió? |
|---|---|
| `ya_respondido` contaba plantillas PROGRAMADAS a futuro y el bot se callaba ante una pregunta real | **No** — las 3 veces que se activó fueron legítimas |
| Un fallo del motor (`error_ia`) era silencio total: ni respuesta, ni acuse, ni reintento | No medido; ahora manda el acuse |
| Audios, fotos fallidas y ubicaciones **no recibían intent**: no llegaban al router nunca | Sólo 2 casos, de abril |
| Las notas internas del operador entraban en el historial que ve el modelo | **No** — 0 notas internas en producción |
| El guardia de «humano al mando» no se re-comprobaba tras generar | No medido |

⚠️ **El de las notas internas es el que más miedo da de los cinco.** Una nota del equipo
—«disputó un cargo, no ofrecer descuento»— entraba como turno del *asistente*, lista para que el
modelo se la citara al propio huésped. No ha pasado porque nadie ha escrito ninguna todavía.

⚠️ **Y el del humano tiene una lección transversal**: el guardia iteraba
`$conversacion->getMessages()`, una colección cargada al empezar el turno. Generar tarda
segundos —con Gemini, hasta ocho vueltas de herramientas— y en ese hueco cabe de sobra que un
operador conteste. Con la colección en memoria no se le veía. Ahora consulta a la TABLA, como ya
hacía `hayMensajePosterior()`, y se repite **después** de generar. Cualquier guardia que decida
sobre algo que pudo cambiar mientras se generaba tiene que preguntar a la base, no a la memoria.

#### 📅 El historial va fechado

Cada turno viaja como `[05/08] texto`. Sin la fecha, una cotización que el propio bot dio hace
una semana entra en contexto como si fuera de ahora, y «responde SOLO con lo que devuelvan las
herramientas» no lo frena: aquella cifra **sí** salió de una herramienta… en otro turno. Es el
fallo que ya ocurrió con los precios. Con la fecha delante y el «hoy es» del prompt, el modelo
puede ver que un dato es viejo — se le da hecho en vez de pedírselo.

#### 📥 Un fallo al importar de Beds24 NO es un éxito

`Beds24ReceiveHandler::handleSuccess()` tenía un `catch` que llamaba a `markSuccess()` con un
`status: failed` dentro del JSON del resultado. Un mensaje del huésped que fallara al importar
se perdía **para siempre**: sin `Message`, sin conversación, sin no leídos. Sólo lo veía quien
abriera el CRUD de la cola a leer el resultado.

Y el caso que lo dispara es normal: `upsertMessages()` lanza si la reserva todavía no está
sincronizada, y **el mensaje del huésped puede llegar antes que el webhook de su reserva**. Es
una carrera, no una anomalía. Ocurrió dos veces en producción (23/03/2026, reservas 84146132 y
84153241).

Ahora se marca como fallo y se reintenta a los 5 minutos, igual que una caída de red: esa
carrera se cura sola en el siguiente intento.

#### 🔐 El webhook de Meta se comprueba firmado

Meta manda `X-Hub-Signature-256` con el HMAC del cuerpo. No se miraba, y eso convertía la URL en
la única barrera: quien la descubriera podía POSTear un payload con el `wa_id` de un operador y
recibir de vuelta las salidas del día, saldos y ocupación. El «el teléfono identifica pero no
autentica» de `AiConversationProcessor` se sostenía en que el número lo verificaba Meta — y eso
sólo es cierto si se comprueba la firma.

⚠️ **Sin `appSecret` configurado, deja pasar y escribe un `error` en cada petición.** Es la única
concesión y es deliberada: `exchange_meta_config` no guardaba ese secreto, así que fallar cerrado
habría dejado el WhatsApp mudo en el mismo despliegue. **Hay que pegarlo en el panel** (Meta →
Configuración → Básica) para que la comprobación entre en vigor.

#### 🧩 Lo que vale para los tres asistentes vive en un sitio

Hay tres puntos de entrada al modelo y cada uno arma su prompt: WhatsApp, el chat del panel y el
de voz. Escribir una regla en uno y olvidarla en los otros no falla en voz alta — falla el día
que alguien entra por la puerta que no la tiene. Ya pasó con la fecha de hoy.

`ReglasCompartidas` guarda las dos que no pueden faltarle a ninguno:

| Regla | A quién le faltaba | Por qué importa |
|---|---|---|
| `DATOS_QUE_SE_COPIAN` | panel y voz | El del **panel** era el que más la necesitaba: ahí se pide redactar textos «para copiárselo al huésped», así que un número de cuenta inventado no lo lee un bot — lo pega un operador que se fía |
| `NO_INVENTES_PARAMETROS` | WhatsApp | Es donde el modelo tiene un historial largo del que copiar un `pax` o unas fechas caducadas |

#### 🎭 Cuatro perfiles, cuatro personas

Por el mismo WhatsApp entran cuatro clases de persona, y durante mucho tiempo el prompt del
sistema era uno solo: «Hablas con un huésped por el chat de su reserva». Fallaba por los dos
lados — al compañero se le trataba de usted y se le escondían cifras que puede ver, y a quien va
a limpiar se le podía soltar el saldo de un huésped por no haberle dicho que no.

`PerfilConversacion::deActor()` decide quién es, y `AiConversationProcessor::reglas()` compone
**tronco común + bloque del perfil**:

| Perfil | Quién | Voz | Lo que NO hace |
|---|---|---|---|
| `Prospecto` | Número desconocido, sin reserva | Vender, de usted | Nombrar a otros huéspedes; comprometer precios |
| `Huesped` | Con reserva viva | Asistir, de usted | Conceder lo que se PIDE (eso escala) |
| `Personal` | Equipo, grupo **OFICINA** | Compañero, tuteo, al grano | Lo que le den sus roles, **incluida la escritura** (desde 2026-08-17) — previa previsualización y su «sí». Un usuario deshabilitado conserva la consulta y pierde la escritura |
| `Colaborador` | Equipo, grupo **CAMPO** | Directo y muy breve | Dinero, deudas o contacto de un huésped |

**La frontera personal/colaborador es la que ya existía**: `Roles::getChoices('OFICINA')` frente
a `CAMPO`. No se enumera una lista de roles aquí, se pregunta a la misma función — una copia se
desincronizaría con el maestro a la primera. Los roles de `SISTEMA` cuentan como oficina: un
admin gestiona reservas por definición, y dejarlo fuera lo habría mandado al perfil más
restringido de los cuatro.

⚠️ **Se mira primero si es del equipo.** Alguien del equipo puede además ser huésped en su propia
reserva (`tambienHuesped: true`), y ahí manda su condición de compañero: es quien más puede ver y
a quien menos falta le hace la cortesía.

⚠️ **El perfil NO sustituye a los permisos.** Lo que cada uno *puede* hacer lo decide
`SkillRegistry::paraActor()` con los roles; si a un colaborador no le toca `consultar_cuenta`, la
herramienta no aparece en su lista y no hay prompt que la invoque. El perfil aporta la otra
mitad, la que ningún filtro puede dar: el tono y el criterio. **Los permisos evitan el acceso; el
perfil evita el desliz.**

El tronco común va primero y el bloque del perfil después, para que éste pueda endurecerlo pero
no aflojarlo: no inventar datos y no escribir de memoria un número de cuenta valen para los
cuatro.

#### 🧹 Cada quien ve sus limpiezas, y sólo las suyas

`listar_entradas_salidas` devuelve el nombre del huésped, su teléfono y el enlace a su chat, y
se entregaba **entera** a cualquiera con `ROLE_LIMPIEZA`: quien iba a la Casita 3 tenía también
el contacto del huésped de la 6.

Ahora cada estancia dice de quién es, en `pms_evento_limpieza`, y la skill filtra por ella
cuando el perfil es `Colaborador`. La oficina sigue viéndolo todo, y de propina la columna
`limpieza` con quién la lleva — para saber a quién avisar sin abrir la ficha.

| Decisión | Por qué |
|---|---|
| Por **evento**, no por casita | Quien limpia cambia por día; colgarlo de la unidad sería falso a la semana |
| Una **tabla**, no una columna | Una casita grande se limpia entre dos; con un FK simple habría que migrar datos el día que hagan falta |
| Sin asignar ⇒ **no le sale a nadie** | Fallo seguro: que la oficina note el hueco es un incordio; que un teléfono acabe donde no toca, no se deshace |
| El perfil se pregunta a `PerfilConversacion` | Para no tener dos definiciones de «es de campo» que se separen con el tiempo |

**El defecto es un dato, no una constante.** `user.es_limpieza_por_defecto` marca a quién se
asigna sola cada estancia nueva; lo aplica `PmsLimpiezaAsignacionListener` en `prePersist`. Es el
mismo patrón que `es_cobrador_principal`, y por el mismo motivo: **no hay ningún nombre escrito
en el código**. El día que quien limpia hoy tome otro camino, se marca a otra persona en el panel
y las estancias nuevas la cogen sin desplegar nada.

Va en un **listener y no en la fábrica** porque los eventos se crean desde cinco sitios (drawer
de `util`, API, `crear_estancia`, pull de Beds24 y `PmsEventoCalendarioFactory`); poner el
defecto en uno solo habría dejado los otros cuatro sin asignar, y ese hueco no se ve hasta que
alguien se queja de que no le avisaron. Se salta extensiones, bloqueos y canceladas: ninguna da
trabajo.

⚠️ **La consulta del defecto NO se cachea**, aunque crear una reserva de cuatro casitas la repita
cuatro veces. Los workers de messenger viven horas: con caché, cambiar la persona por defecto no
surtiría efecto hasta el siguiente reinicio, y el síntoma —«las estancias nuevas siguen saliendo
a nombre de la anterior»— es de los que se investigan dos veces antes de sospechar de un caché.

⚠️ **El listener NO filtra por `enabled`**, y aquí decía lo contrario. `enabled` dice si la
persona ENTRA AL PANEL, no si trabaja aquí: quien limpia no tiene login, así que está
deshabilitada por norma. Con el filtro puesto, la asignación automática no hacía nada para
exactamente la persona para la que se construyó, en silencio. Quien deje de trabajar aquí se
retira marcando a otra persona por defecto y borrándole el móvil —que es su credencial en este
canal—.

#### 🧽 Asignarla desde el calendario

La asignación se edita en el **drawer de Reservas de `util`**, en cada estancia: casillas con el
equipo de limpieza, y debajo el aviso de que sin nadie marcado esa estancia no le aparece a
nadie de campo. El panel (EasyAdmin) sigue teniendo el campo, pero ya no es el único sitio.

Llegar hasta ahí obligó a arreglar el contrato de la API, que **no funcionaba**: `User` no es un
`ApiResource` ni tiene un solo `#[Groups]`, así que exponer la colección `limpiezaAsignada` daba
`[{}, {}]` —un objeto vacío por persona— en lectura, y en escritura no había IRI que mandar. De
paso iba en `pax_reserva:read`: al huésped, que no pinta nada en esto.

| Sentido | Campo | Forma |
|---|---|---|
| Lectura | `PmsEventoCalendario::getLimpieza()` | `[{id, nombre}]` |
| Escritura | `PmsEventoCalendario::setLimpiezaIds()` | `["<uuid>", …]`, ids planos |
| Desplegable | `GET /tipo/user/enum/pms/limpiadores` | `[{id, label}]`, los de `ROLE_LIMPIEZA`. Exige `RESERVAS_SHOW` |

⚠️ **El prefijo `/tipo/user/` no protege nada, y esto ya costó un dato real.** El docblock de
`PmsEnumAjaxController` decía que se agrupaba bajo `user` «para heredar las reglas del
firewall»; es falso. En `security.yaml` el acceso se decide **por host** —panel, util y oweb
piden `ROLE_USER`— y el host de la API cae en el `PUBLIC_ACCESS` del final. `/limpiadores`
salió a producción sin candado y devolvió los nombres del personal de limpieza a cualquiera que
pidiera la URL, hasta que se le puso `#[IsGranted]`. Lo mismo se le añadió a `/cobradores`, que
sólo se libró porque en producción todavía no hay nadie con `ROLE_COBRADOR`. Todo endpoint
colgado de ahí necesita su propio rol, como ya avisaba `FinEnlacePagoApiController`.

Quien resuelve los ids es `PmsEventoCalendarioProcessor::aplicarLimpieza()` —la entidad no tiene
repositorio con el que buscar usuarios—, y de ahí salen tres reglas que hay que conocer antes de
tocar nada:

- ⚠️ **Ausente ≠ vacío.** `limpiezaIds` fuera del payload significa «no lo toques»; `[]`
  significa «quítalos a todos». Sin esa distinción, cualquier PATCH del drawer —cambiar el
  estado, tocar el importe— habría borrado la asignación, y eso no se nota hasta el día de la
  salida, cuando la estancia no le aparece a nadie. El drawer sólo manda el campo cuando el
  selector está en pantalla.
- **Un id sin `ROLE_LIMPIEZA` se rechaza con 400**, no se ignora: la etiqueta se pintaría igual
  y la estancia no le llegaría nunca a esa persona, porque el filtro de la skill sólo se aplica
  a quien tiene ese rol. La lista de candidatos sale de `UserRepository::findByRole()`, la misma
  que llena el desplegable. 🪞 **Si cambia el criterio de uno, cambia el del otro.**
- **En la creación no se ofrece.** La primera estancia de una reserva nueva nace por
  `PmsReservaCrearProcessor`, con su propio DTO, que no acepta el campo; un selector que
  funcionara en unas estancias y en otras no es peor que no tenerlo. Se asigna la persona por
  defecto y el drawer se reabre en edición, ya con el selector.

El espejo TS **no se escribe a mano**: sale de `api.d.ts`, que se regenera con `npm run gen:api`
desde `util/`. Por eso `getLimpieza()` declara su forma con `#[ApiProperty(openapiContext: …)]`
—sin eso, del PHPDoc salía un `string[][]` inservible—, y `pmsReservaModel.ts` sólo deriva:
`PmsLimpiezaAsignada` es un `[number]` sobre el campo del esquema. El único tipo a mano es
`PmsLimpiadorOption`, porque el desplegable lo sirve un controlador plano que OpenAPI no ve.

Comprobación de todo esto contra la BD real, sin dejar rastro: `php var/probar-limpieza.php`.

#### 🗣️ El triaje también tiene que saber con quién habla

El triaje **resuelve él mismo las charlas** —«hola», «gracias», «prueba»— sin llamar al modelo
grande. Y su prompt es idéntico byte a byte en todas las conversaciones **a propósito**: es el
bloque con marca de caché, y ahí no puede entrar nada de quien escribe sin perder ese ahorro.

Consecuencia vista en producción: un compañero del equipo escribió «Prueba» y recibió
«¿en qué te puedo ayudar con **tu reserva o tu estancia**?». La voz del huésped, a alguien del
equipo. Todo el trabajo de los cuatro perfiles se lo saltaba ese camino.

El arreglo no toca el bloque cacheado: `PerfilConversacion::enUnaLinea()` viaja en el **contexto
volátil** —el mismo que ya lleva el idioma y el nombre—, que va fuera de la caché por diseño.
Una línea, y sólo cuando hace falta: para el huésped devuelve cadena vacía, porque es el caso
que el prompt ya asume y decir lo obvio sólo gasta contexto.

⚠️ Es un recordatorio general de dónde vive cada cosa: **lo que depende de quién escribe va en el
contexto, no en las reglas.** Si algún día hay que enseñarle algo más al triaje sobre el
interlocutor, ese es el sitio.

#### 🛒 El prospecto: quien pregunta sin ser todavía nadie

Un número desconocido que escribe a preguntar precios **no es un huésped con menos permisos: es
un actor sin CONTEXTO**, y esa es toda la diferencia.

Antes caía en `huesped()` y el resultado era una pared: de sus nueve skills, siete mueren en
«Esta conversación no está asociada a ninguna reserva» y ninguna sabe de disponibilidad ni de
tarifas. Escribir para preguntar cuánto cuesta una casita no tenía respuesta.

`AgentActor::prospecto()` **no acepta parámetros de contexto**, y eso no es un olvido: es lo que
le cierra `consultar_cuenta` y `consultar_mi_reserva` sin necesidad de una lista negra que
alguien tenga que acordarse de ampliar. A cambio se le abre lo que un desconocido sí puede
saber:

| Skill | Por qué sí |
|---|---|
| `consultar_disponibilidad` | Qué hay libre y a qué precio. No nombra a ningún huésped |
| `consultar_guia` | Sólo por la puerta pública (ver abajo) |
| `consultar_tipo_cambio` | Un dato público |
| `escalar_al_equipo` | Un prospecto sin respuesta es un **lead**, no un callejón sin salida |

#### 🔁 Y el huésped alojado entra también a `consultar_disponibilidad`

Estaba excluido **a propósito**, con este argumento escrito en la skill: *«un huésped con
reserva que pregunta "¿qué más tenéis libre?" está pidiendo que le vendan algo, y eso lo cierra
una persona»*. La preocupación era buena; la consecuencia, no: **el huésped que quiere ampliar
su estadía es el caso de venta más fácil que existe —ya está dentro y quiere quedarse— y era el
único al que el agente no podía ni informar.** De las cuatro skills abiertas al prospecto, tres
admitían ya también al huésped; la única que no era justo la de vender.

Lo que motivaba la exclusión sigue en pie y no dependía de los roles: la skill es de
`NivelRiesgo::Lectura` y **no reserva nada**. Enseña el hueco y el precio; ampliar la estancia lo
cierra una persona, por `escalar_al_equipo` o por el operador. Cambió quién puede **preguntar**,
no quién puede **vender**.

⚠️ Esto es sólo la mitad del caso. Con el rol pero sin contexto, el agente puede consultar
disponibilidad pero le habla al huésped como si preguntara por la estancia que ya tiene. La otra
mitad —saber que está comprando algo NUEVO— es el **frente de venta** del rediseño de dominios;
hasta que exista, el operador verá consultas de ampliación resueltas con voz de estancia.
Compruébalo con `app:agent:permisos`, que lista el catálogo real por perfil.

`consultar_ocupacion` se queda fuera y es el ejemplo que explica el resto: devuelve
`PmsOcupacionDto`, que lleva `huesped`, `localizador` y `reserva_id`. Era la skill que contestaba
«¿cuándo está libre la 6?» diciendo «sale Sarah Beament el 15/08», y esa frase no puede salir del
equipo.

**Quién es prospecto lo decide tener una reserva detrás**, no el número:
`AiConversationProcessor` mira `contextType !== 'pms_reserva'` una vez identificado que no es del
equipo. Los otros valores vivos (`manual`, `staff`) no acreditan a nadie como huésped.

⚠️ **El prospecto es siempre DIRECTO.** Quien escribe a nuestro número no viene por una OTA, así
que a su cotización no se le aplica ningún porcentaje de servicio. El dato de la OTA viaja como
argumento de venta —«reservando directo se ahorra el 16%»—, nunca sumado al total.

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
| `Escritura` | Datos de la reserva o de la cuenta | Se le enseña **a quien lo pidió** y se espera su «sí» | No |

La confirmación no desconfía del usuario: protege de que **el modelo se equivoque de persona**.

#### Qué significa exactamente «confirmarse»

🔥 **La palabra «confirmar» se usa en este sistema para DOS cosas que no tienen nada que ver.**
Confundirlas lleva a diseños opuestos, y ya ha pasado:

| | Quién lo dispara | Qué significa ahí «confirmar» |
|---|---|---|
| **A. Confirmar una ESCRITURA** | Sólo un operador, según su rol | Enseñarle los cambios **a él mismo** y pedirle que los acepte |
| **B. Confirmarle algo a un CLIENTE** | Un huésped o prospecto pide algo | El agente **no puede concedérselo por su cuenta**: escala para que responda la oficina |

En A la respuesta la da quien preguntó. En B hace falta otra persona — **pero eso no es una
«escritura sin confirmar», es una decisión comercial que el agente no toma.**

##### Antes de nada: escribir es cosa de operadores

No es una convención, está en los roles. Hoy, sin excepción:

| Nivel | Skills | Rol que piden |
|---|---|---|
| `Escritura` | `registrar_pago`, `cambiar_codigo_caja`, `modificar_reserva`, `crear_reserva`, `ajustar_tarifas`, `registrar_cargo`, `enviar_mensaje_huesped`… (13) | `RESERVAS_WRITE` o `MENSAJES_WRITE` |
| `Interna` | `escalar_al_equipo`, `enviarme_plantilla` | `HUESPED` / `PROSPECTO` |
| `Lectura` | el resto | varios |

**Un cliente no llega a ninguna `Escritura`.** Las dos únicas cosas no-lectura a su alcance son
`Interna` y son acciones sobre sí mismo: levantar la mano (`escalar_al_equipo`) y pedirse una
plantilla ya aprobada (`enviarme_plantilla`). Ninguna decide nada, y por eso ninguna pide el
paso de aceptación — ver el bloque `Interna` más abajo.

##### A. La confirmación de una escritura: mismo usuario, dos turnos

> **Confirmar es enseñarle el cambio a QUIEN LO PIDIÓ y esperar que lo acepte.**
> No es buscar a un tercero que lo autorice.

```
turno 1   operador  «cambia la clave de la caja principal a 2627E»
          modelo    → cambiar_codigo_caja(confirmado: false)
          skill     → NO TOCA NADA. Devuelve la previsualización.
          modelo    «La usan 3 huéspedes ahora mismo (Ana, Luis, Marta).
                     ¿La cambio?»
turno 2   operador  «sí»
          modelo    → cambiar_codigo_caja(confirmado: true)
          skill     → aplica
```

No hay aprobador, ni supervisor, ni segundo par de ojos. **Quien pide y quien acepta son el
mismo**, y lo único que se añade es que vea el efecto antes de que ocurra.

De ahí sale lo que protege: no duda de que el operador quiera hacerlo —acaba de decirlo—, duda
de que **el modelo le haya entendido**. La previsualización es el instante en que un humano caza
que la fecha, el importe o la persona son otros, y por eso su contenido sale de los datos reales
y no de lo que el modelo cree que va a pasar (§11).

Esto vale para **todas** las escrituras del operador, sin excepción de canal ni de urgencia.

##### B. Lo que el agente no puede confirmarle a un cliente

Cuando en la documentación se lee que algo «requiere confirmación» y quien pregunta es un
huésped, casi siempre es este otro caso: **el agente no está autorizado a decidirlo.** Un
late check-out, una tarifa especial, una excepción a la política — eso lo concede la oficina, no
el modelo.

La herramienta para eso es `escalar_al_equipo`, y su trato es el contrario del de A: **se ejecuta
sin preguntar nada**. Pedirle a alguien que acaba de plantear un problema que confirme si quiere
que se avise al equipo es un paso de más en el peor momento.

⚠️ Y de paso, el malentendido que originó todo esto: **en un chat SIEMPRE hay a quién
preguntar** — es quien acaba de escribir. Una conversación soporta la confirmación de tipo A en
dos turnos mejor que un panel, porque el historial ya viaja en cada petición. Si un canal cierra
la escritura, el motivo será otro; nunca que falte el interlocutor.

#### Las tres capas, y cuál es el cierre de verdad (2026-08-17)

Que una skill se ejecute pasa por tres comprobaciones, y conviene no confundirlas porque dos
son de **catálogo** y sólo una es de **ejecución**:

| | Dónde | Qué pregunta | Cuándo |
|---|---|---|---|
| 1 | `AiConversationProcessor` → `permitirEscritura` | ¿Este canal admite escrituras para este actor? | Al armar el catálogo |
| 2 | `SkillRegistry::paraActor()` | ¿Qué herramientas le corresponden por rol y dominio? | Al armar el catálogo |
| 3 | **`GuardiaDeSkills::motivoDeBloqueo()`** | ¿Se le permite ejecutar ÉSTA? | Justo antes de `ejecutar()` |

Las dos primeras deciden **qué se le enseña** al modelo. La tercera es la única que decide **qué
se ejecuta**, y es la que faltaba.

##### Por qué hace falta la tercera si las otras dos ya filtran

Porque el catálogo es una **oferta**, no un cierre. Se arma una vez por turno, y detrás no había
nada: si algo lo ensanchaba —un canal que pasa `permitirEscritura: true` de más, una skill que
se cuela por un dominio mal declarado, o que alguien toque la búsqueda por nombre de los
adaptadores sin saber que ésa era la única barrera— no había segunda línea.

Es la regla de la casa aplicada al pie de la letra: **el prompt es una petición, el cierre es
código.** Que la descripción de una skill diga «pide confirmación» no impide nada.

##### Qué comprueba: los roles, y sólo eso

Una comparación, `null` = adelante. Devuelve el motivo **en texto** y no un booleano porque
quien se lo come es el modelo: necesita saber qué decirle al usuario, y un «no puedes» a secas
produce una disculpa vaga y un reintento.

Lo llaman los tres puntos que ejecutan skills: `AnthropicSkillAdapter`, `GoogleAISkillAdapter` y
`AgentSkillCommand`. La CLI hacía esa comprobación por su cuenta —la misma política escrita dos
veces— y se retiró en favor de ésta.

⚠️ **Lo que NO comprueba: la confirmación de escritura.** El paso «previsualiza y espera el sí»
sigue dependiendo de que el modelo llame primero con `confirmado: false`, porque nada recuerda
entre llamadas si hubo previsualización. Está asumido (ver §11 A): forzarlo en servidor sería
complejidad real para cubrir un caso cuyo único perjudicado es el operador que acaba de pedirlo.

Tests en `tests/Agent/Access/GuardiaDeSkillsTest.php`.

#### 🔥 `Interna`: por qué el filtro no es «¿escribe algo?»

Por WhatsApp se pasa `permitirEscritura: $actor->esDelEquipo()`, y conviene separar a quién
afecta:

- **Al operador, nada**: desde 2026-08-17 puede escribir por WhatsApp igual que en el panel,
  acotado por sus roles. Ver §7 para por qué el número basta como credencial.
- **Al huésped, tampoco**: no tiene ningún rol de escritura, así que ninguna `Escritura` le
  llegaría de todos modos. Se ata al actor y no se pone `true` a secas para que eso quede
  dicho en el código, en vez de depender de que los roles estén bien puestos.

Con dos niveles, ese filtro era `nivelRiesgo() !==
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
  cobrador="yo"    → ❓ falta_datos: ["cobrador"] → «tu usuario no tiene el rol de
                        cobrador, así que el pago NO se te puede apuntar a ti.
                        ¿Quién recibió el dinero? Puede ser Maria Apaza, Milka,
                        Susan Acuña»
```

⚠️ **Ese caso es `falta_datos`, no `error`.** Era un `SkillResult::error()` y el modelo lo trataba
como un fallo a reintentar: volvía a llamar **sin cobrador**, el pago caía en el cobrador por
defecto y el operador veía «Cobrado por: Susan Acuña» sin enterarse de que su «me pagó» se había
descartado por el camino. Un error invita a reintentar; `falta_datos` dice qué preguntar. Entra
por la misma puerta que un nombre desconocido o ambiguo, que es lo que de verdad es: **falta
saber quién cobró**.

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
`PmsCargosAutomaticosService::aplica()` no abre ninguna línea y la estancia nace gratis sin que nada lo
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

#### ✅ `confirmar_estancia`: confirmar sin decir que pagó

«Ponle confirmado a Théa» no tenía skill, así que el modelo llegaba por el desvío: marcaba
`estadoPago = pago-alojamiento`, que confirma **de rebote** por la auto-confirmación.
Funcionaba, pero dejaba escrito que había un pago donde nadie había pagado.

| | Cambia | ¿Abre la guía? |
|---|---|---|
| `confirmar_estancia` | `estado` → confirmada | ❌ **NO** |
| `modificar_reserva` con `estado_pago` | `estadoPago`, y el estado de rebote | ✅ sí |

**Lo que abre el contenido reservado es el estado de PAGO, no el estado de la estancia** —
`PmsGuiaAcceso::paraEvento()` mira `ESTADOS_PAGO_CONFIABLES`. Confirmar sin más deja al huésped
confirmado y sin guía, que suele ser justo lo que se quiere hasta que pague.

Por eso la skill **ofrece** el otro camino en vez de tomarlo:

```
sugerencia_pago: «quedará confirmada pero SIN acceso a la guía… Si el huésped paga al
                  llegar, pregúntale al operador —DESPUÉS de confirmar y como cosa
                  aparte— si quiere marcarla como pago-alojamiento. No lo hagas tú.»
```

Con un pago ya confiable esa sugerencia no aparece, y la consecuencia se dice al revés («ya
tenía acceso y lo mantiene»): decirle «no se abre la guía» sobre una reserva que ya la tenía
abierta sería falso y le haría dudar de si algo se rompió.

⚠️ **No des-confirma.** Con un pago confiable es imposible —el listener de integridad la
devuelve a confirmada en el mismo flush— y sin pago es una operación con más consecuencias que
su contraria, que no se hace de pasada por chat.

⚠️ **En OTA lo dice.** El canal manda sobre el estado, así que un pull posterior puede
revertirlo si el cambio no se hizo también en el portal.

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

##### 📵 El aviso caía casi siempre fuera de la ventana de 24 h

El aviso va por WhatsApp al móvil del operador, y ahí manda la regla de Meta: texto libre sólo a
quien te ha escrito en las últimas 24 h. **Un operador de guardia no le escribe al número del
negocio casi nunca**, así que su ventana está cerrada prácticamente siempre — el caso raro no era
el escalado fuera de ventana, era el de dentro.

Fuera de ventana hace falta una plantilla aprobada por Meta, y las 11 que había eran todas para
huéspedes. Resultado: el aviso se encolaba y moría ahí. Ahora existe `aviso_escalado_interno`
(migración `Version20260808161207`), con **sólo la parte de WhatsApp** rellena: `email_tmpl`,
`beds24_tmpl` y `whatsapp_link_tmpl` quedan `{}` a propósito — Beds24 es el canal del huésped en
las OTAs y aquí no pinta nada, y así `resolveChannels()` resuelve un único canal.

El aviso sale de dos formas según dónde caiga, y **son distintas a propósito**:

| | Cómo sale | Qué lleva |
|---|---|---|
| Dentro de ventana | Texto libre | Todo: motivo **y los márgenes de ocupación**, multilínea |
| Fuera de ventana | Plantilla `aviso_escalado_interno` | Huésped, motivo y el botón al chat |

La plantilla lleva menos porque **Meta rechaza el envío si un parámetro tiene saltos de línea**,
tabuladores o más de cuatro espacios seguidos — y el bloque de márgenes es multilínea por
definición. Por eso `EscalarAlEquipoSkill::unaLinea()` aplana huésped y motivo antes de mandarlos,
y por eso la skill adjunta la plantilla **sólo si la ventana está cerrada**: dentro, el texto
libre gana, y cambiarlo por la plantilla sería empeorar el único caso que ya estaba bien.

###### 🔑 Las variables viajan en el MENSAJE, no en el resolver

Lo normal es que las variables de una plantilla las ponga el resolver del contexto
(`MessageDataResolverInterface`), que las saca de la entidad dueña de la conversación: la reserva
da `guest_name`, `checkin_date`…

Aquí eso **no puede funcionar**, y no por falta de un resolver de `staff`: la conversación es la
del *operador*, y lo que hay que contarle —qué huésped, qué pidió, qué chat abrir— no se deduce
del operador. Un resolver keyed por operador devolvería datos del operador. El dato depende del
**hecho**, no del contexto.

Así que el mensaje puede traer las suyas: `Message::setVariablesPlantilla()`, guardadas en su
metadata (`variables_plantilla`), y `WhatsappMetaSendMappingStrategy` las **fusiona pisando** a
las del resolver. Sirve para cualquier plantilla cuyo contenido dependa del suceso y no del
contexto, no sólo para ésta.

De paso, esa fusión se resuelve **una vez** por mensaje en lugar de tres (header, cuerpo,
botones), que es como estaba.

###### ⚠️ La plantilla existe en el PMS pero todavía NO en Meta

La migración la inserta con `status: PENDING`, que es la verdad. Hasta que Meta la apruebe,
`WhatsappMetaSendEnqueuer` **se niega a encolarla** fuera de ventana — y hace bien. Lo que falta,
a mano:

1. Crear en el WhatsApp Manager una plantilla **UTILITY** llamada `aviso_escalado_interno` con el
   mismo cuerpo, los mismos parámetros con nombre (`huesped`, `motivo`) y el botón de URL
   dinámica `https://util.openperu.pe/{{1}}`.
2. Ya aprobada: `php bin/console app:whatsapp:sync-templates`. El sync casa por
   `meta_template_name`, pisa el `status` con el real y **conserva el `resolver_key`** de los
   botones, que es lo único que Meta no sabe.

`chat_path` es relativo (`chat?id=<uuid>`) igual que `guide_path` en `PmsMessageDataResolver`,
porque el botón de Meta ya lleva el dominio y sólo admite el sufijo; `chat_url` es el absoluto
que usa el respaldo en texto libre.

###### 🔥 El encolado falla en silencio, así que se comprueba después del flush

`MessageDispatcher::dispatch()` **no propaga** las excepciones de los encoladores: las atrapa por
canal, deja el mensaje en `FAILED` y anota el motivo en `dispatch_errors`. La skill contaba como
avisado a todo operador cuyo `avisar()` no lanzara — o sea, a todos.

Ahora `ejecutar()` guarda el `Message` de cada operador, y **después del flush** mira su estado:
los que quedaron en `FAILED` se van a `no_avisados` y su motivo al log. Importa porque la
respuesta de la skill decide qué le dice el agente al huésped: «ya avisé a una persona» sobre un
aviso que no salió es exactamente la promesa vacía que esta skill vino a eliminar.

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

##### 🚪 Las dos puertas de `consultar_guia`

La guía cuelga de la **unidad**, pero durante mucho tiempo el único camino hasta ella atravesaba
una reserva (`$actor->contextoId() ?? $entrada['reserva_id']`). Consecuencia: «¿cuál es la
acomodación de la Casita 3?» no tenía respuesta ni para un prospecto ni para el equipo, aunque el
texto estuviera escrito, marcado `publico` y a un JOIN de distancia. Escalaba a un humano.

Ahora hay dos entradas, y lo único que cambia entre ellas es **a qué unidad se llega y con qué
`PmsGuiaAcceso`**:

| Puerta | Se entra con | Acceso | Ve |
|---|---|---|---|
| Reserva | contexto del huésped, o `reserva_id` del equipo | `PmsGuiaAcceso::paraEvento($evento)` | Según el estado de su estancia |
| Pública | `casita` (nombre o número), sin reserva | `PmsGuiaAcceso::publico()` | Sólo `visibilidad = publico` |

De ahí para abajo el árbol ya viene podado y **el tramo de respuesta es el mismo método**
(`responder()`). Duplicarlo sólo habría servido para que un día las dos puertas contesten
distinto.

🔒 **No hay ninguna lista blanca en el código.** Qué puede ver un desconocido lo marca el operador
ítem a ítem en `visibilidad`, y lo aplica el mismo `podar()` que protege la guía del huésped —
`permite(Publico)` es el peldaño más bajo de LA MATRIZ. Una lista aquí sería una segunda fuente
de verdad, y precisamente la que nadie se acordaría de actualizar.

Y el ataque obvio está cerrado: pedir un `tema_id` privado por su UUID exacto desde la puerta
pública devuelve `tema_no_disponible`, porque `buscarPorId()` busca **dentro del árbol ya
podado** y no con un `find()`. Comprobado con el ítem del código de la caja fuerte.

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
| 2 | Se abre **una línea en 0.00** y la skill escribe encima los importes aprobados | `PmsCargosAutomaticosService::generarParaEvento()` + `escribirAprobados()` |

⚠️ Desde el 16/08/2026 `consultar_cuenta` devuelve **`por_moneda`** (una entrada por moneda, con
el saldo ya restado) en vez de `total_cargos`/`saldo_pendiente` escalares, más un bloque `cuadre`
cuando hay dos monedas. El desglose es lo exacto; el cuadre es la cifra para cerrar, y su `nota`
le dice al modelo que la presente como aproximada.
| 3 | **La casita sale de la venta** en Beds24 y en todos los portales | `Beds24BookingsPushQueueListener` |
| 4 | **Se programan dos mensajes reales** al huésped | Motor de reglas, hitos `start` y `end` |

Comprobado tras crear una de prueba: `recordatorio_llegada` quedó en cola para el 2027-01-11 y
`check_out` para el 2027-01-14 — con la reserva creada en agosto de 2026. La previsualización
enumera las cuatro **antes**, porque aprobar «se creará una reserva» sin saber que retira
inventario de Booking y que se programan mensajes no es aprobar lo que pasa.

##### 💱 `registrar_pago` no se simplificó: cambió de pregunta

Al dejar de convertir (§12.2b de `PmsBeds24ReservasSync.md`), la tentación era quitarle la
conversión y ya. **Habría empeorado el caso más frecuente.** Con cargos de Booking en dólares y un
cobro por Yape en soles, el agente contestaría *«saldo US$ 65.97 pendiente y S/ 223.70 a favor del
huésped»* — las dos cifras ciertas y la conclusión falsa: el huésped pagó y se fue.

Así que la skill **pregunta**: cuando el cobro entra en una moneda donde no hay ningún cargo, ese
dinero no puede saldar nada suyo, y hay que decidir a qué deuda se aplica.

```
el cobro entra en PEN y en esa moneda no hay ningún cargo, así que no puede saldar
nada suyo: ¿va contra la deuda en USD? Al cambio de hoy abonaría 66.42 USD.
Si no —es una propina, o un extra que todavía no se ha cargado—, dilo y queda como
saldo a favor en PEN
```

- **Se pregunta, no se decide.** A veces esos soles son de verdad otra cosa. Aplicarlos en
  silencio sería cobrar algo que nadie acordó.
- **Sólo con UNA candidata.** Con deuda en dos monedas distintas de la del cobro, elegir por el
  operador sería adivinar.
- **Va en la MISMA tanda que el resto de repreguntas**, no en un viaje aparte: es la regla de
  abajo. Sólo se puede formular cuando la moneda del cobro ya se conoce; si `moneda` está en
  `$faltan`, la pregunta espera al turno siguiente, que es cuando de verdad se puede hacer.
- La pregunta lleva **la equivalencia calculada**. «¿Va contra la deuda en USD? abonaría 66.42» se
  responde mucho mejor que la misma pregunta a secas.

⚠️ **La alarma de sobrepago respeta la tolerancia del cuadre.** Un cobro imputado deja casi siempre
un residuo —el cambio del mostrador no es el de los cargos, y sobre 66 dólares son 45 céntimos—, y
avisar de «el pago excede lo pendiente» por eso convierte la alarma en ruido. Con residuo dentro de
tolerancia el mensaje es otro: *«es el redondeo del cambio, no un sobrepago: dalo por saldado»*.

`registrar_cargo`, en cambio, **sí se simplificó de verdad**: el cargo se guarda en la moneda que
se dictó y no hay nada que convertir. La equivalencia en la otra moneda viaja como
`equivalencia_informativa`, marcada como lo que es.

##### 🔁 Todo lo que falta se pregunta DE UNA VEZ

La regla, y aplica a **todas** las skills que repreguntan: si faltan tres datos, se piden los
tres juntos. Cada repregunta es un viaje más al operador por la misma tarea.

El patrón que lo rompía era siempre el mismo — **un `SkillResult::error()` para un dato que
falta**, colocado antes del bloque que acumula:

```php
if ($medio === null) {
    return SkillResult::error('Falta el medio de pago…');   // ❌ corta aquí
}
…
$faltan[] = 'moneda';                                        // nunca llega
```

El operador contestaba «yape», y sólo entonces la skill llegaba a mirar la moneda y le
preguntaba otra vez. Tres vueltas para un pago. Corregido en cuatro skills:

| Skill | Antes | Ahora |
|---|---|---|
| `registrar_pago` | medio → moneda → cobrador | `['medio_pago', 'moneda']` juntos |
| `registrar_cargo` | concepto → importe → casita | `['concepto', 'importe', 'evento_id']` |
| `crear_reserva` | fechas → casita → datos | `['fechas', 'casita', 'idioma', 'adultos']` + teléfono |
| `crear_estancia` | fechas → casita → adultos | `['fechas', 'casita', 'adultos']` |

⚠️ **Un error de validación NO es un dato que falta.** «La salida es anterior a la entrada» o
«ese evento_id no es de esta reserva» siguen cortando solos: enterrarlos entre otras tres
preguntas es esconderlos.

⚠️ **Las dependencias reales se respetan, y son las únicas que justifican una segunda vuelta.**
La comisión sólo se puede preguntar sabiendo el medio (`tarjeta` la tiene, `efectivo` no), y el
cobrador sólo si ese medio se cobra en mano. Sin el medio, esas dos se dejan para la vuelta
siguiente en vez de inventarlas.

⚠️ **La disponibilidad se sigue comprobando ANTES de pedir datos del huésped.** En
`crear_reserva` y `crear_estancia` es deliberado: si la casita está ocupada, preguntar el
nombre y el idioma es hacerle perder el tiempo al operador. Lo que cambió es que ahora sólo se
mira **cuando se puede** —con fechas y casita ya resueltas—; si esas faltan, se preguntan con
todo lo demás y la disponibilidad se comprueba en la vuelta siguiente.

⚠️ **Con `$faltan` vacío, los datos existen «por construcción»** — pero eso es un razonamiento
que hay que rehacer en cada lectura. Las cuatro skills lo dejan explícito con una guarda antes
de usarlos sin `?->`, en vez de confiar en que el flujo lo garantiza.

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

⚠️ **Estas líneas no son sólo una previsualización: son los cargos que se escriben.** Desde el
15/08/2026 el panel ya no crea importes al insertar una estancia directa —abre una línea en
`0.00` y el precio lo pone quien vende, ver §12.0.1 de `PmsBeds24ReservasSync.md`—, así que aquí
no queda nada generado que corregir.

Lo que hay en su lugar es mejor: **el operador aprueba un desglose y se escribe ese desglose**.
`escribirCargos()` recalcula `cargosPrevistos()` con los mismos argumentos y se lo pasa a
`PmsCargosAutomaticosService::escribirAprobados()`, que retira la línea en cero de esa estancia
—y **sólo si sigue en cero**: con importe es dinero que alguien valoró— y persiste las aprobadas.

**Por qué escribir vive en el servicio y no en la skill:** `crear_estancia` hace exactamente lo
mismo. Con una copia en cada una, la primera corrección se quedaría en una de las dos.

**Por qué previsualizar y escribir salen del mismo sitio:** cuando eran dos cálculos separados se
separaron de verdad. A la previsualización le faltaba el suplemento por persona, y con 7 personas
donde la tarifa cubre 5 y 3 noches el operador confirmaba **36.00 menos** de lo que luego veía en
la cuenta.

Una línea aprobada en `0.00` —la limpieza perdonada— **no se escribe**: es informativa, y
escribirla llenaría la cuenta de ceros que no significan nada. Sale en `ajustes_aplicados` para
que el agente pueda contarlo.

El precio del tarifario sale de `PmsCargosAutomaticosService::estimarAlojamiento()`, que hoy
delega en `preciosDeNoches()` — la misma lectura que alimenta el tooltip de coste teórico del
panel. Una fórmula, no tres.

###### Las horas salen del establecimiento

`PmsEstablecimiento::getHoraCheckIn()` / `getHoraCheckOut()`. Codificar 14:00 y 10:00 en la skill
habría creado una segunda verdad que se rompe el día que un establecimiento cambie su horario.

##### 🔑 Bloquear la noche es OPCIONAL, y con la noche vendida es imposible

Marcar la casilla retira de la venta la noche **anterior** a la entrada —o la **posterior** a la
salida— para tener margen de limpieza. Es una decisión del operador, no una consecuencia
automática de apuntar una hora:

| Estado de esa noche | Qué hace la skill |
|---|---|
| **Libre** | Pregunta si bloquearla: *«¿Bloqueo también la noche anterior a la entrada (08/08)? Se retira de la venta en todos los portales»* |
| **Ya vendida** | **No pregunta nada**: no se puede bloquear. Registra la hora y avisa de que la limpieza tendrá menos margen esa mañana |

Antes preguntaba lo contrario y en todo-o-nada: *«¿Aplico igual (marca y bloquea, con el solape)
o prefieres que NO haga nada?»*. Un solape no llega a ocurrir nunca —con la noche vendida ya no
se bloquea— y «no hacer nada» tampoco es la alternativa: la hora se registra igual.

⚠️ **Con la noche vendida tampoco se abre la línea de cargo**, porque cuelga de la misma casilla
(`PmsCargosAutomaticosService::sincronizarExtras()` mira `isSalidaTardia()`). Lo que se le quiera
cobrar por ese horario se añade a mano en el panel. Decisión consciente, no un olvido.

⚠️ **`$bloquea` y `$vaABloquear` son variables distintas y no se pueden fusionar.** `$bloquea`
significa «esta hora es horario extra»; `$vaABloquear` añade «y además se puede bloquear». Al
pisar la primera con la segunda, registrar una entrada a las 08:00 sobre una noche vendida se
anunciaba como *«cabe dentro del horario (14:00)»* — y las 08:00 son entrada temprana de manual.

Nada de «adyacente» en los textos: se dice **anterior** o **posterior**, que es lo que el
operador tiene en la cabeza.

##### 🐛 Tres formas de mentirle al operador con una hora

Los tres salieron del mismo caso real: registrar una salida a las **00:00** con check-out a las
10:00.

**1. La previsualización anunciaba un horario extra que no ocurría.** Decía siempre «quedará
marcada con salida tardía», también cuando la hora cabe dentro del horario y no se marca nada.
El modelo lo repetía al pie de la letra: *«Voy a registrar la salida tardía a las 00:00»* — diez
horas **antes** del check-out. Ahora el texto depende de `$bloquea` y en ese caso dice
explícitamente que NO es una salida tardía y que no se lo cuente así al operador.

**2. El aviso de ocupación daba el margen al revés.** Cuando la noche adyacente está ocupada
pero no se bloquea nada, el texto era fijo: *«el margen de limpieza es más corto»*. Falso en la
mitad de los casos — quien sale a las 00:00 deja **diez horas más**, no menos. Ahora
`alertaDeOcupacion()` recibe la hora y el límite, y decide con `$daMasMargen`:

```
sale 00:00 / check-out 10:00  →  ✅ «deja MÁS margen de limpieza que el horario normal»
entra 18:00 / check-in 14:00  →  ✅ ídem
entra 14:00 / check-in 14:00  →  ⚠️ «el margen es más corto» (sin cambio de hora efectivo)
entra 09:00 / check-in 14:00  →  ⛔ conflicto: sí es horario extra y sí bloquea
```

**3. «A las 6» se registraba como las 18:00.** El modelo elegía la tarde por su cuenta. La
descripción del parámetro fija ahora la regla —un número suelto es la hora del reloj de 24 h,
«6» son las 06:00— y, sobre todo, le prohíbe adivinar: ante la duda pregunta, porque
equivocarse son doce horas de diferencia en una salida.

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

#### Lo que la skill dice del prepago y de cada cargo

Dos campos más, y los dos existen para que el agente no contradiga a la pantalla del huésped:

- **`prepago_pendiente`** — el adelanto que todavía hay que pedir. La cifra sale de
  `PmsPrepagoCalculador::pendiente()`, **la misma llamada que alimenta el estado de cuenta de
  `pax`**. No se recalcula aquí a propósito: dos cálculos son dos cifras, y el huésped tiene
  una de ellas abierta en el móvil mientras el agente le contesta. Sólo viaja si queda algo
  que pedir — con cualquier pago registrado, ese pago ERA el prepago y el campo desaparece.
- **`explicacion_para_huesped`** en cada cargo — `descripcionClienteEn($idioma)`, la redacción
  que escribió el operador (`docs/FinanzasEnlacesPago.md` §8). `concepto` viene del canal y
  puede ser un código; ésta se le puede repetir tal cual. La mayoría de los cargos no la
  tienen y `array_filter` la quita, así que no se pagan tokens por un campo vacío.

⚠️ **La `claveI18n` del calculador NO se le pasa al modelo.** Es una clave del diccionario de
`pax` (`res_prepago_mitad_total`), que se resuelve en el navegador del huésped; aquí sería un
identificador que el modelo acabaría leyendo en voz alta o traduciendo a ojo. Viaja
`PmsPoliticaPrepago::etiqueta()`, que ya es español legible, y el modelo lo redacta en el
idioma que toque. Mismo criterio que `medio` en los pagos: **al modelo se le dan etiquetas, no
identificadores.**

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

### `generar_enlace_prepago`: emite el cobro del adelanto, y ahí se para

Emite el enlace con el que el huésped paga su adelanto y devuelve la URL.
**No lo envía.** El flujo completo son dos llamadas encadenadas, igual que
`localizar_conversacion` → `enviar_mensaje_huesped`:

```
generar_enlace_prepago(reserva_id, confirmado=false)  → importe + pregunta_aprobacion
        ↓  el operador dice que sí
generar_enlace_prepago(reserva_id, confirmado=true)   → url
        ↓  el modelo redacta en el idioma del huésped
enviar_mensaje_huesped(...)                       ✍️  → borrador → «¿lo mando?» → sale
```

El encargo pedía «una skill que genera y envía». Se partió en dos por las mismas dos razones
que ya viven en esta sección: **el texto lo compone el modelo**, y **el envío ya tiene su
puerta**. Meter el envío dentro sacaría un enlace de cobro hacia un huésped real sin pasar por
la confirmación de envío — con el autorespondedor encendido, sin que lo viera nadie. Un enlace
de dinero mal dirigido no se recoge.

El importe **no lo elige el modelo**: sale de `PmsPrepagoCalculador::pendiente()`, la misma
llamada que alimenta el estado de cuenta del huésped. Y `pendiente()` devuelve `null` en cuanto
hay un pago registrado, así que a quien ya adelantó algo no se le puede emitir un segundo
enlace aunque el modelo insista.

#### 🔌 Una skill se puede APAGAR, y entonces no existe

`SkillConmutableInterface` es opcional: quien no la implementa está siempre disponible, que es
el caso normal. La implementan las que dependen de algo que todavía no está listo — aquí, una
pasarela en modo test (`FINANZAS_ENLACES_PREPAGO=0`, ver `docs/FinanzasEnlacesPago.md` §11 bis).

Apagada, `SkillRegistry::paraActor()` la salta **antes que los roles**: no es cuestión de
permisos, la skill no puede funcionar se tengan los que se tengan. Es la misma regla que ya
gobierna el catálogo — *lo que no se puede usar ni se le menciona al modelo*. Una skill listada
que siempre falla es peor que no tenerla: el modelo la elige, gasta un turno y le cuenta al
huésped una versión inventada de por qué no pudo.

⚠️ **El filtro del registro no es seguridad.** Filtra el catálogo, no la ejecución: por nombre
se llega igual desde `app:agent:skill` o desde un plan de una conversación que empezó antes de
apagar el flag. Una skill apagada que además escriba **tiene que volver a comprobarlo en
`ejecutar()`**, y `generar_enlace_prepago` lo hace.

#### 🔥 El usuario del actor no siempre está en la base de datos

`AgentSkillCommand` fabrica un `new User()` con SUPER_ADMIN para poder probar skills desde la
consola. Ponerlo en una relación —`FinEnlacePago::$creadoPor`— revienta al flush con *«A new
entity was found through the relationship»*, un mensaje que no dice nada del problema real.

Comprobar el id **no sirve**: `IdTrait` lo genera en el constructor, así que el usuario fantasma
también tiene uno. Lo que los distingue es estar gestionado (`EntityManager::contains()`). Vale
para cualquier skill futura que quiera guardar quién hizo algo.

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

### 12 bis. ⚠️ Una clave vacía degrada en silencio (y llevaba meses)

`SelectorDePotencia` **no distingue «tramo mal escrito» de «proveedor sin credenciales»**: los
dos caen al motor de `AGENT_IA_PROVEEDOR` con un `warning` y la conversación sigue. Está bien
pensado —un ajuste de coste no puede tumbar el chat— pero tiene un efecto que cuesta ver:

> Con `ANTHROPIC_API_KEY` vacía y los tres tramos apuntando a Anthropic, **el agente entero
> contestaba con `gemini-3.6-flash`** mientras la configuración decía Opus, Sonnet y Haiku.
> Verificado en producción el 10/08/2026: la clave tenía **0 caracteres** en `.env` y en
> `.env.local`, y el log llevaba días con 11 avisos de tramo medio y 17 de tramo bajo.

Nada fallaba y nadie miraba: el huésped recibía respuestas, el coste era el de Flash y el aviso
vive en `info-*.log`, no en `error-*.log`. **Que el `.env` nombre un modelo no significa que ese
modelo esté respondiendo.** Para saber cuál contesta de verdad:

```bash
grep -ho "potencia [a-z]* mal configurada" var/log/info-*.log | sort | uniq -c
```

Cero líneas = se está usando lo que dice la configuración. Cualquier otra cosa es un tramo que
no se está pagando ni sirviendo como se cree.

Desde entonces los tres tramos apuntan a Google explícitamente. El tramo **alto** también, y no
a `gemini-3.1-pro` como pediría su semántica: en cuenta gratuita un 429 llega antes, y ese
fallback **no cubre errores de cuota** —sólo de configuración—, así que una emergencia se
quedaría con el acuse de recibo genérico. Se sube a Pro cuando haya facturación.

---

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

### 13.6 bis 🐛 El `elseif` que apagaba el triaje en Gemini (y no se veía)

En `GoogleAIEngine::turnoDirecto()`, la configuración de razonamiento colgaba de un `elseif`
del bloque del esquema:

```php
if ($esquema !== null) {
    // … responseMimeType + responseSchema
} elseif (($nivel = $this->google->razonamiento()) !== null) {
    $generacion['thinkingConfig'] = ['thinkingLevel' => $nivel];   // ← nunca con esquema
}
```

La intención estaba escrita al lado y era razonable: «con esquema no se pide pensamiento, la
salida está tan acotada que pagarlo no cambia la clasificación». **Pero no poner
`thinkingConfig` no lo apaga: deja el default de Gemini 3.x, que es razonar.** Y como
`maxOutputTokens` **incluye los tokens de pensamiento**, el triaje se gastaba sus 500 razonando
y devolvía el JSON cortado a media clave:

```
indeterminado — respuesta no era JSON: {"tipo":"peticion","skill
```

Lo que lo hizo durar es que **degrada limpio**: `Triaje::interpretar()` lo da por
`indeterminado`, el turno sigue por el camino largo y el huésped recibe su respuesta. El aviso
vive en `info-*.log`, no en `error-*.log`, y nadie mira los `info`.

Medido en producción el 10/08/2026, antes de arreglarlo: **4 de cada 6 triajes salían
`indeterminado`**. Se perdía la pista de skill (§13.8), el atajo de la charla (§13.7) y se
pagaba una llamada de más en cada mensaje — justo lo contrario de lo que el tramo venía a
ahorrar. Tras separarlo en dos `if`, los cuatro turnos del replay de V4JE5Q clasifican con su
skill y no queda ni un `indeterminado`.

**La regla que deja:** en Gemini, «no configurar» nunca significa «desactivar». Si algo tiene
que estar apagado, se apaga explícitamente. Y un presupuesto de tokens corto con razonamiento
por defecto no da una respuesta corta: da una respuesta **cortada**.

#### El presupuesto: 500 → 900, y por qué subirlo no cuesta

Apagado el pensamiento, el truncado bajó mucho pero **no desapareció**: seguía saltando con los
mensajes largos, de forma intermitente. La peor manera de fallar, porque no se reproduce cuando
la buscas.

`Triaje::MAX_TOKENS` estaba en 500 con el argumento de que «más presupuesto invita a razonar en
voz alta». Ese argumento no aplica aquí, y son tres cosas:

- **Quien acota la salida es `responseSchema`, no el techo de tokens.** Con esquema el modelo no
  puede irse por las ramas: los campos son los que son.
- **`maxOutputTokens` es un tope, no una reserva.** Se factura lo que se emite, así que subirlo
  no cuesta nada mientras no se use. Bajarlo no ahorra: sólo corta.
- Y cortar aquí **no da una respuesta corta, da una respuesta rota**. El JSON llega a medias,
  `interpretar()` lo manda a `indeterminado` y el turno se va por el camino largo pagando una
  llamada de más. Se ahorraban tokens en el paso barato para gastarlos en el caro.

#### Y que se vea si vuelve a pasar

`turnoDirecto()` ya no devuelve el texto cortado como si fuera bueno: si `finishReason` es
`MAX_TOKENS` devuelve `null` —que es lo que ya significa «no pude»— y deja un **`warning`** con
el desglose de en qué se fue el presupuesto:

```
turno directo truncado para … — no cabe en maxTokens=500 (pensamiento: 487, salida: 13)
```

Ese desglose es la mitad del valor del aviso: separa «se lo comió el pensamiento» (apágalo) de
«la salida no cabe» (súbelo). Antes el síntoma aparecía tres capas más arriba, como un `info`
de `Triaje` con los primeros 120 caracteres de un JSON que **parecía válido** —lo era, hasta que
se acabaron los tokens—, y nadie lo relacionaba con un presupuesto corto.

Para vigilarlo:

```bash
grep -c "indeterminado — respuesta no era JSON" var/log/info-*.log
```

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
(`PmsIndiceDeTemas::bloqueParaElPrompt()`): una línea por ítem con su uuid, su etiqueta y en qué casitas
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
un ordinal y mapear ordinal→uuid en `PmsIndiceDeTemas` al interpretar — el mapa se reconstruye en
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

##### Tres accesos por fila, no uno

La lista servía para saber quién sale, pero desde ahí no se podía hacer nada: para escribirle
al huésped había que buscarlo otra vez. Cada fila trae ahora hasta tres enlaces, y el modelo
los pinta como iconos al final de la línea —el nombre se queda en negrita, sin enlazar:

| Campo | Icono | A dónde va | `null` cuando |
|---|---|---|---|
| `url` | 📋 | La ficha en el panel | nunca (siempre hay evento) |
| `url_whatsapp` | 🟢 | `wa.me` con ese huésped | la reserva no tiene ningún número |
| `url_chat` | 💬 | `/chat/:id` en el panel | no hay conversación abierta |

Un campo vacío **omite el icono**, sin sustituirlo por texto ni explicar que falta.

El teléfono sale ya en dígitos —`PmsReservaIntegrityListener` lo normaliza al guardar—, así que
vale tal cual para `wa.me` sin limpiarlo en la skill. Y se respeta `telefono2_es_principal`:
escribir al número que el huésped no usa es no escribir.

##### Los mismos accesos en el panel de hoy y mañana

`HomeView` pinta la misma información y tenía el mismo problema. El feed
(`PmsEventosSpaCalendarProvider::buildContext()`) manda ahora `telefono` y `conversacionId`.

⚠️ La conversación se resuelve **en lote** con `fetchConversaciones()`, mismo patrón que
`fetchFinanzas()` y por el mismo motivo: este proveedor sirve el calendario entero, y preguntar
evento a evento serían decenas de consultas por carga. `context_id` es un varchar con el UUID en
texto, no una FK, así que se compara contra la forma de cadena del id.

⚠️ En el template los accesos son **hermanos** del `<button>` de la fila, no hijos: un `<a>`
dentro de un `<button>` es HTML inválido. Por eso el `hover` se movió al `<li>`, para que la
fila entera siga resaltándose como una sola pieza.

#### 🔄 El asistente escribe, pero la pantalla no se enteraba

«El pasajero de la casa 5 se va a las 8» → la skill guarda `fin = 08:00` y responde «listo».
Y el panel de salidas, tres centímetros más arriba, seguía mostrando las 10:00. `HomeView`
carga sus datos una vez en `onMounted()`, y el asistente que lleva embebido no avisaba de nada.
Parecía que el cambio no se había aplicado; en la base estaba perfectamente guardado.

Ahora la respuesta del asistente trae **`hubo_escritura`**, y `AsistenteBar` emite
`datosCambiados` cuando es `true`. `HomeView` lo engancha a su `cargarPanelHoy()`.

⚠️ **Lo decide el backend, no el front.** `PanelAssistant::huboEscritura()` resuelve el nivel
de riesgo real de cada skill usada con
{@see \App\Agent\Access\NivelRiesgo::modificaDatos()}. Mantener en el front una lista de
nombres de skills que escriben sería garantizar que se desincronice: se añade una skill nueva,
nadie se acuerda de apuntarla, y el operador vuelve a mirar datos viejos.

Dos decisiones dentro de ese método:

- **`Interna` cuenta como escritura.** No pide permiso ni confirmación, pero `escalar_al_equipo`
  marca la conversación como no leída — y eso también deja la pantalla desactualizada. La
  pregunta aquí es «¿cambió algo?», no «¿hacía falta permiso?»; por eso es un método distinto
  de `exigePermisoDeEscritura()`.
- **Una skill desconocida cuenta como que SÍ escribió.** Refrescar de más cuesta una petición;
  refrescar de menos deja al operador creyendo que su cambio no se aplicó.

El componente sólo avisa de que *algo* cambió: no sabe qué pinta cada vista. Cada una engancha
el evento a su propia recarga (`@datos-cambiados="cargarPanelHoy"`).

#### 📜 El hilo: tope de altura y una hora de vida

Dos problemas del hilo de `AsistenteBar`, los dos por vivir sólo en memoria y sin tope:

- **Crecía sin límite hacia abajo.** La barra está ARRIBA de `HomeView`, así que una
  conversación de seis turnos empujaba el panel de llegadas y salidas fuera de la pantalla.
  Ahora para en `max-h-96` y hace scroll.
- **Y crecía en el sentido equivocado.** Se escribe en la caja de arriba y la respuesta salía
  al final: cada pregunta alejaba un poco más el resultado. El hilo se pinta del intercambio
  **más nuevo al más viejo** (`intercambios`), así que la respuesta aparece justo debajo de lo
  que acabas de teclear, y `subirAlUltimo()` lleva el scroll al principio.

⚠️ Se agrupa en **pares** y se invierte el orden de los pares, no la lista de turnos pelada:
invertir los turnos pondría cada respuesta ENCIMA de su propia pregunta. Dentro de cada
intercambio se lee como siempre. Una pregunta aún sin contestar es un par con `respuesta`
vacía, y aparece arriba en cuanto se envía.
- **Se perdía al recargar.** Y como el asistente ahora provoca recargas de datos, era fácil
  quedarse sin el hilo justo después de una acción. Se guarda en `localStorage`
  (`asistente_hilo`) con **una hora** de vida.

##### Que se vea dónde acaba cada consulta, y que siga trabajando

Con los intercambios apilándose hacia arriba, tres detalles pasaron de cosméticos a necesarios:

- **La línea separadora** subió de `divide-slate-50` a `divide-slate-200`. Casi invisible, era
  imposible saber dónde acaba una consulta y empieza la anterior — y es la única señal de que
  son cosas distintas. Cada pregunta-respuesta es **su propio bloque**: una consulta con dos
  repreguntas ocupa tres bloques, decidido así a propósito.
- **El aviso de que está trabajando va DONDE va a aparecer la respuesta** («Consultando el PMS…»
  con los puntos animados), no sólo girando el icono del botón de enviar en la otra punta de la
  barra. El icono de la varita también late mientras consulta, para verlo de reojo.
- **El foco vuelve al campo al terminar**, con respuesta o con error: casi siempre lo siguiente
  es escribir —contestar una repregunta, reintentar—. Va después de soltar `cargando`, porque
  hasta ese momento el `disabled` impide que el campo acepte el foco; ese mismo `disabled` es lo
  que se lo quita al empezar.

El reloj se reinicia **en cada cambio**, no en el primer turno: lo que caduca es el hilo que
lleva una hora sin tocarse, no una conversación viva que empezó hace 59 minutos.

Una hora y no más porque pasado ese rato el contexto ya no es el mismo —otro día, otra
incidencia— y arrastrarlo sólo sirve para que el modelo responda sobre algo que nadie recuerda.

⚠️ **Todo lo que se lee de `localStorage` se valida.** JSON corrupto, un formato viejo (el
array pelado de antes de este cambio), `guardadoEn` ausente o el almacenamiento bloqueado en
modo privado devuelven hilo vacío en vez de reventar la barra al entrar al portal. Comprobado
con los seis casos; sólo restauran «recién guardado» y «hace 59 minutos».

El botón *Empezar de nuevo* quedó **fuera** del área con scroll: dentro, en un hilo largo
habría que bajar hasta el final para encontrarlo. Vaciar el hilo borra también la clave.

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

### 💰 `consultar_tarifas`: a cuánto sale una casita, noche a noche

El precio real sólo se podía ver **creando la reserva** — `estimarAlojamiento()` vivía dentro de
`crear_reserva`. Preguntar «¿a cuánto está la 3 del 12 al 15?» obligaba a abrir el calendario de
tarifas y resolver de cabeza qué rango gana cada día.

#### 🏆 El precio de una noche NO es la tarifa base

Sobre un mismo día pueden solaparse varios `PmsTarifaRango`, y gana **uno**. El criterio está en
`TarifaDailyPriceFlattener`, en este orden:

```
1. important = true       ← una promo marcada gana a todo
2. prioridad (weight) mayor
3. duración más corta     ← un rango de 3 días gana al de todo el mes
4. id más reciente
```

Sólo si ningún rango cubre el día se cae a la tarifa base de la unidad. Por eso cada noche viene
con **`de_donde_sale`**: sin eso, «65.00» no distingue una promo de un hueco sin tarifa cargada.
Comprobado con datos reales — Casita 1, del 10 al 19 de agosto:

```
2026-08-10 … 08-17   65.00   rango 01/08→01/09 prioridad 1
2026-08-18           45.00   rango 18/08→01/09 prioridad 4   ← el corto y prioritario gana
                    ─────
TOTAL               565.00 USD     (la base sola habría dado 630.00)
```

`aviso_sin_tarifa` cuenta aparte las noches que salen a la base: suele ser un olvido de carga, no
una decisión, y el operador querría saberlo sin leer línea a línea.

#### La disponibilidad cotiza con el precio REAL, no con la base

`consultar_disponibilidad` devolvía sólo `tarifa_base`, y el modelo la presentaba como
«Tarifa base orientativa: 70.00 USD/noche» aunque para esas fechas hubiera una tarifa cargada
de 45.00. El operador cotizaba con un número que no era el que se iba a cobrar.

Ahora cada casita trae el total del tramo y **la base no viaja**:

```
Casita 5 (capacidad 2 pax):  75.00 USD total (25.00 USD/noche)
Casita 1 (capacidad 8 pax): 135.00 USD total (45.00 USD/noche)
```

Un intento intermedio la dejaba al final como referencia, y el modelo recitaba los dos seguidos
—«Total: 135.00 USD · Tarifa base ref: 70.00 USD»—: dos números para lo mismo, con el que NO se
cobra al lado del que sí. Quien cotiza no necesita el suelo.

⚠️ **Quitarla de la respuesta no basta: el modelo la arrastra del historial.** En una repregunta
(«y para 8?») seguía escribiendo «Tarifa base: 75.00» con los valores de una consulta anterior,
aunque la skill ya no los devolviera. La descripción se lo prohíbe ahora de forma explícita
—«ni aunque la tengas de una consulta anterior de esta misma conversación»—, porque una
instrucción que sólo cubre el turno actual no cubre un hilo.

`noches_sin_tarifa` sí se queda: cuenta las noches que salen a la base por no tener rango — en
marzo de 2027, sin tarifas cargadas, sale `3` de 3.

##### `consultar_tarifas_base`, aparte y para poco

Skill propia porque responde a otra pregunta: no «cuánto cobro» sino «cuánto es el suelo». Sirve
el día que alguien olvida cargar las tarifas de un mes y esas noches se venden al mínimo sin que
nadie lo decida. **Se usa poco y está bien que así sea**: si hiciera falta a menudo, el problema
sería que faltan tarifas, no que falte consultarlas.

⚠️ **Una tarifa base desactivada no significa gratis.** Significa que esa casita no se puede
vender en fechas sin rango: el motor no encuentra precio. Se dice con esas palabras porque
`activa: false` leído por un modelo invita a decir «sin tarifa base», que suena a cero:

```
Casita 6 → "Tarifa base DESACTIVADA: esta casita no se puede vender en fechas sin
            tarifa cargada. No es que sea gratis, es que no hay precio."
```

#### 🏷️ `ajustar_tarifas`: subir o bajar precios sobre el vigente

«Baja 10 % la Casita 1 en las noches libres de agosto» era media hora de calendario. La skill
crea **rangos nuevos por encima** de los que ya hay, sin tocar ninguno.

**Se ajusta sobre el precio VIGENTE de cada noche** —el rango que hoy gana— y no sobre la
tarifa base: calcularlo sobre la base daría un precio sin relación con lo que se vende, y una
bajada del 10 % podría acabar siendo una subida.

##### 🥇 Prioridad = la mayor que pisa, +1

Del máximo de los rangos que HOY ganan esas noches, no del máximo global de la unidad: lo único
que hay que superar es lo que se está pisando, y coger el global inflaría las prioridades hasta
que dejaran de significar nada. Un tramo sin tarifa cargada (venía de la base) estrena en 1.

##### 🧮 Varias tarifas en el tramo → promedio, y se avisa

Un `PmsTarifaRango` tiene **un solo precio**, así que un tramo que cruza dos temporadas se
aplana:

```
15,16,17 → 65.00     promedio 57.00 · −10% → 51.30
18,19    → 45.00

aviso_promedio: «esas noches tienen 2 precios distintos (45.00, 65.00). Un rango nuevo
                 sólo admite UNO, así que parto del promedio y las dejo todas a 51.30»
```

Se dice aparte porque es lo que el operador **no** espera: dos temporadas distintas quedan
igualadas.

##### ✂️ Las noches vendidas parten el ajuste en varios rangos

Con `solo_libres`, las ocupadas se saltan — y como un rango es continuo, los huecos obligan a
crear uno por tramo seguido. Comprobado en la Casita 1 de agosto, con tres reservas dentro:

```
11 noches libres · 18 vendidas respetadas · 2 rangos creados
   2026-08-18 → 2026-08-22     (la reserva de Lita, 23-24, queda fuera)
   2026-08-25 → 2026-08-30

Resultado: 18-22 a 40.00 (prioridad 5) · 23-24 siguen a 45.00 · 25-26 a 40.00
```

⚠️ **`fechaFin` es EXCLUSIVA.** El tramo acaba la noche `$fin`, así que el rango se crea con
`fin + 1 día`. Con `fin = inicio` el flattener descarta el rango entero y se guarda **sin error
y sin hacer nada** — el fallo más caro, porque es mudo.

⚠️ **Deshacer es borrar el rango nuevo**: los anteriores siguen debajo intactos y el precio
vuelve solo. Verificado.

⚠️ **Sale a los portales.** Crear el rango encola el push a Beds24 (`Beds24RatesPushQueueListener`
escucha las inserciones de `PmsTarifaRango`) — cuatro colas en la prueba. Por eso la
previsualización lo dice con esas palabras en vez de hablar de «guardar una tarifa».

#### 👥 El suplemento por persona adicional

La tarifa de una noche cubre a un grupo de cierto tamaño; a partir de ahí cada persona suma.
Vivía en la cabeza del equipo y se aplicaba a mano al cotizar, así que ni el agente ni el cargo
automático la conocían. Ahora son dos columnas en `PmsUnidad`:

| Casita | Capacidad | `paxIncluidos` | `precioPaxAdicional` |
|---|---|---|---|
| 1 | 8 | 5 | 6.00 |
| 2 | 7 | 4 | 6.00 |
| 3 | 8 | 4 | 6.00 |
| 4 | 4 | 3 | 6.00 |
| 5 | 2 | 2 | **0.00** — su tarifa cubre las dos plazas |
| 6 · 7 | 10 | 7 | 6.00 |

**Dos columnas y no un JSON**: con esta regla no hay tramos escalonados, así que un JSON sólo
añadiría parsing y validación a mano. Si algún día el 6º paga 6 y del 8º en adelante 10, se
migra entonces — con el caso real delante, no adivinando.

La cuenta vive en `PmsUnidad::suplementoPorPax()`, fuente única:

```
adicionales × precio × NOCHES        ← por noche, como el alojamiento
```

⚠️ **`paxIncluidos = 0` significa «sin regla», no «todos pagan extra».** Es el valor por defecto
de la columna, y una unidad recién creada no debe empezar a cobrar recargos que nadie decidió.
`cobraPaxAdicional()` exige las dos cosas —tope y precio— para que esa condición no se repita
en cada sitio que la mire.

⚠️ **Los niños cuentan igual que los adultos.** `$pax` es el total de la estancia.

⚠️ **Sin saber cuántos van, el precio NO incluye suplemento** y `consultar_disponibilidad` lo
avisa con `aviso_pax`. Dar un total que se quedará corto al concretar el grupo es peor que
decir que falta el dato:

```
7 pax · Casita 1 · 3 noches
  precio              171.00 USD
  precio_por_noche     45.00 USD
  suplemento_por_pax   36.00 USD  (2 personas por encima de las 5 que cubre la tarifa,
                                   6.00 por persona y noche)
```

El suplemento va **desglosado y no escondido en el total**: el operador tiene que poder explicar
de dónde sale la diferencia, no soltar una cifra mayor sin motivo aparente.

#### 🛒 «No hay» casi nunca es verdad

El filtro de `pax` pide **una** casita que quepa el grupo entero. Un grupo de 20 devolvía la
lista vacía y el agente contestaba «no hay capacidad» — teniendo tres casitas libres que sumaban
exactamente 20. El dato era correcto y la respuesta, inútil.

Cuando el filtro deja la lista en cero se vuelve a preguntar **sin él**, y la respuesta trae un
bloque `reparto` que dice las tres cosas que hacen falta: que juntos no caben, cuántas plazas
suman las libres, y qué preguntar para poder cerrar.

⚠️ En un reparto **no se pasa el `pax` a `resumen()`**. No se sabe cuántos van en cada casita, y
pasar los 20 a todas cobraría el grupo entero siete veces. Cada casita sale con `precio_desde`
hasta que el cliente diga cómo se reparten.

#### 📅 Qué día es hoy, y por qué faltaba

El prompt de WhatsApp **no decía la fecha**; el del panel sí, desde siempre. Ésa era toda la
diferencia entre una cotización buena y una mala, con el MISMO modelo y la MISMA skill.

Preguntando «del 8 al 10 de noviembre» sin año, el modelo eligió **2025**. Y entonces todos sus
números fueron correctos… para 2025: 32.00 y 65.00 por noche (tarifa base, porque no hay rango
cargado en un noviembre pasado) en vez de 25.00 y 45.00. Cotizó 260.00 donde eran 206.00, y su
nota «esas noches no tienen tarifa cargada» era **cierta** — para el año equivocado.

Es el peor tipo de fallo: nada chirría. El precio sale de otra temporada, suena razonable, y ni
el operador ni el cliente tienen forma de notarlo.

Dos capas, porque una no basta:

1. **El prompt lleva la fecha** y la regla de que una fecha sin año es la PRÓXIMA vez que
   ocurre, nunca una pasada.
2. **`consultar_disponibilidad` se niega a cotizar el pasado.** Si el rango terminó, devuelve un
   error que dice el año probable y pide confirmarlo. Un aviso depende de que alguien lo lea; un
   error no. Vender una noche que ya pasó no existe como caso de uso — para mirar atrás está
   `consultar_ocupacion`.

#### 💸 Lo cotizado ES lo cobrado (y no lo era)

Durante un día entero la cotización usó la limpieza configurable de la unidad mientras el CARGO
real seguía con una constante `TARIFA_LIMPIEZA = '15.00'`, fija e incondicional. Coincidían de
casualidad, porque todas las casitas tenían 15.00 puestos. En cuanto una pasara a porcentaje, o
a otro importe, o a cero, se cotizaba una cosa y se cobraba otra — y el cargo se creaba incluso
en las casitas que no cobran limpieza.

Hoy los dos lados llaman a **los mismos métodos de la entidad**: `costoLimpieza()` y
`suplementoPorPax()`. Si cambia la regla, cambia en un sitio.

También se arreglaron las dos PREVISUALIZACIONES (`crear_reserva`, `crear_estancia`), que
enseñaban un total por debajo del que luego aparecía en la cuenta: no incluían el suplemento por
persona —que sí se cobra— y calculaban el servicio sobre una base que incluía la limpieza.

⚠️ **La trampa de contar noches.** `$inicio->diff($fin)->days` con las horas dentro devuelve 1
para dos noches: la estancia va de las 14:00 a las 10:00 y eso es «1 día y 20 horas». Hay que
normalizar a día (`setTime(0,0)`) antes de restar. Se cayó en ella al escribir el arreglo y el
suplemento salió a la mitad —18.00 donde eran 36.00—; el propio `PmsCargosAutomaticosService` ya
lo documentaba.

#### 🔒 Quien va a la casita no recibe el contacto del huésped

El filtro por asignación acota QUÉ estancias ve una persona de campo, pero las filas seguían
llevando `localizador`, `url_whatsapp` —que contiene el teléfono— y `url_chat`. Ahora esos
campos no salen del equipo de oficina.

Se recorta en el SQL y no confiando en que el prompt lo prohíba: **lo que no debe salir, no se
manda**.

⚠️ Y el filtro por asignación es **sólo para quien limpia**, que es quien tiene filas en
`pms_evento_limpieza`. Atado a todo el perfil de campo dejaba a MANTENIMIENTO —que también tiene
esta skill— con la lista vacía para siempre.

#### 🧹 `enabled = false` NO significa «ya no trabaja aquí»

Significa «no entra al panel», y es el estado **normal** de quien limpia. Las dos personas de
limpieza que hay están deshabilitadas y son plantilla activa.

Esto tiene dos consecuencias que ya mordieron:

1. `PmsLimpiezaAsignacionListener` exigía `enabled = true` para elegir a la persona por defecto,
   así que la asignación automática **no hacía nada** para exactamente la persona para la que se
   construyó, en silencio.
2. `UserRepository::findByTelefono()` **no filtra por `enabled` a propósito** (está documentado
   en el propio método). Ponerle el filtro «por seguridad» convertiría a quien limpia en una
   desconocida en cuanto escriba.

⚠️ **Queda un riesgo abierto y consciente**: un ex-empleado con el móvil aún en `user.telefono`
conserva acceso de lectura por WhatsApp con sus roles, incluidos los códigos de puerta y de la
caja de llaves. El control hoy es **borrarle el teléfono** al darle de baja, porque el teléfono
ES la credencial de este canal. Si hiciera falta algo más fuerte, sería un campo propio para eso
—nunca reutilizar `enabled`, que significa otra cosa—.

#### 🧮 El modelo no suma: `distribucion` y `total_combinado`

Nació de un fallo real, medido en producción. Preguntando por la Casita 5 y la 2 para 9 personas
(8–10 nov), el agente devolvió:

```
Casita 5    $79.00   ✅ correcto
Casita 2   $145.00 base, «sube a $169 si van 7»   ❌ son $181
Total      $248.00   ❌ son $260
En soles   S/ 826    ❌ no cuadra con ninguna de las dos cifras
```

**Los números de la skill eran correctos.** Lo que falló fue la aritmética que le quedaba al
modelo: cogió el precio base de la Casita 2 y le sumó el suplemento por su cuenta, contando 2
personas adicionales donde había 3 (`pax_incluidos` es 4, no 5).

Ocurrió porque en un reparto cada casita sale con `precio_desde` —sin suplemento, porque no se
sabe cuántos van en cada una— y el modelo rellenó el hueco calculando.

La solución no es un aviso más, es **quitarle la operación**. Con `distribucion` («2:7, 5:2») se
cotiza cada casita con SU número de personas y se devuelve **`cotizacion`**: un texto ya armado,
listo para copiar, con la cuenta de cada casita y el total.

⚠️ **El total y el desglose van en el MISMO campo, y eso es deliberado.** Al principio eran dos
—`total_combinado` por un lado, `desglose` por casita por otro— y el modelo cogía el total,
resumía y mandaba el suplemento y la limpieza a una nota al pie: «Casita 2: $181.00 *(incluye
$36.00 de suplemento)*». Quien recibe una cotización tiene que ver de dónde sale cada cifra.
Yendo juntos, no puede dar uno sin el otro.

La cotización dice además, en su última línea, que el precio es **directo** y no lleva el
porcentaje de las OTA — porque quien pregunta por este canal reserva directo.

| Regla | Por qué |
|---|---|
| Sólo cotiza casitas **libres** | Pedir el precio de una ocupada debe fallar, no devolver una cifra invendible |
| `total_combinado` sólo si **todas** se cotizaron | Un total al que le falta una casita parece completo, y es peor que no darlo |
| El parseo es **tolerante** («2:7», «Casita 2 = 7») | El formato exacto es lo primero que se le olvida al modelo |
| Lo que no encaja se **descarta** | Mejor cotizar de menos que inventar un reparto |

⚠️ **Hay tres prompts de sistema, no uno**: `AiConversationProcessor::reglas()` (WhatsApp),
`PanelAssistant::systemPrompt()` (chat del panel) y `VoiceAssistant`. Una instrucción sobre cómo
cotizar añadida sólo en uno **no la ven los otros dos**. Por eso lo que no puede fallar va en la
DESCRIPCIÓN DE LA SKILL, que es lo único que los tres comparten.

#### 🛏️ Comodidad no es aforo

`capacidad` dice cuántos caben y con eso no se contesta «quiero estar más cómodo»: en la Casita 3
(2 habitaciones) y en la 6 (3 habitaciones, 2 baños privados) caben los mismos 8, y no es lo
mismo. Comodidad es **privacidad**.

`PmsUnidad` lleva `habitaciones`, `camas` y `banos_privados`, y viajan en cada resultado de
`consultar_disponibilidad` para poder **comparar sin llamadas extra**.

⚠️ **Son un espejo de la guía, no la fuente.** El original es el bloque «Distribución» del ítem
«Descripción» de cada guía —que es lo que el cliente lee, traducido a siete idiomas—; esto es el
resumen corto para el catálogo de ventas. Al cambiar uno hay que mirar el otro.

Que no sea una estructura de camas por habitación es deliberado: hoy sólo hace falta para
nombrarlo al vender. Cuando haya que filtrar por «cama doble», se migra con el caso delante.

#### 🧹 La limpieza

`PmsUnidad::precioLimpieza`, **por estancia y no por noche** — se limpia al salir, una vez, y una
semana no ensucia siete veces. Se cobra en TODOS los canales y entra en el total.

**Un importe y un interruptor.** `precio_limpieza = 15.00` son 15 dólares o un 15% del
alojamiento según `limpieza_es_porcentaje`. En `0.00` **no se cobra ni se menciona** —la línea
desaparece del desglose y el campo no viaja—, tenga el flag el valor que tenga. Un «limpieza
0.00» en una cotización sólo invita a preguntar por algo que no existe.

⚠️ **Por qué un flag y no dos columnas de importe.** El primer intento fue `precio_limpieza` +
`porcentaje_limpieza` con la regla «si hay porcentaje, manda». Funcionaba, pero esa regla vivía
sólo en el código: mirando la fila no se sabe cuál se cobra. Bastaba olvidarse de poner una a
cero al cambiar de criterio para cobrar lo que no era, sin que nada chirriara. Con un valor y un
flag, el dato dice por sí solo lo que significa, y cambiar de criterio es un clic sin volver a
teclear el número.

El parque pasará a porcentaje casita por casita, no de golpe: por eso es configurable por unidad
y no un ajuste global.

**La base del porcentaje es alojamiento + suplemento por persona**, la misma que
`porcentaje_servicio`: más gente ensucia más, así que la limpieza sube con el grupo. Lo que no
entra en ninguna de las dos bases es la limpieza misma — el servicio no se cobra sobre ella, y
ella no se cobra sobre sí misma.

⚠️ **Que las dos bases coincidan no las hace la misma regla.** Cada una vive en su método
(`costoLimpieza()` y `servicioSobre()`) y **no se deriva de la otra**, por el mismo motivo por el
que `IMPIDEN_VENTA` y `OCUPAN_UNIDAD` se declaran aparte aunque coincidan: responden preguntas
distintas. El día que la limpieza deje de contar los acompañantes, se cambia en su método sin
tocar lo que cobra Booking.

```
Casita 2 · 7 personas · 2 noches · limpieza 15%
  45.00 × 2 = 90.00 + 3 personas adicionales = 36.00 + limpieza 15% = 18.90 → TOTAL 144.90
                                                      └─ 15% de (90.00 + 36.00)
```

Antes no existía como concepto: se tecleaba a mano en `pms_cargo_financiero` como «suplemento de
limpieza», siempre 15.00 USD. Se encontraron **tres grafías distintas conviviendo**
(`suplemento de limpieza`, `Suplemento de limpieza`, y la variante con el tipo de cambio), que es
la huella de una costumbre y no de un dato. Ninguna cotización automática lo incluía, así que
todo precio que diera el agente salía corto.

#### 🏷️ El porcentaje de servicio de la OTA

`PmsUnidad::porcentajeServicio` **no lo cobra este sistema**: lo aplica Booking o Airbnb por su
cuenta. Se guarda para poder cuadrar lo que el huésped acaba pagando allí con lo que se cotiza
aquí — sin él los dos números no coinciden nunca y no hay forma de saber si la diferencia es la
comisión o un error.

⚠️ **Su base es alojamiento + suplemento de pax. La limpieza NO entra.** La regla vive en
`PmsUnidad::servicioSobre()` y no se reescribe en ningún otro sitio.

**A qué canales aplica lo dice una tabla, `pms_unidad_servicio_canal`, y no un booleano.** El
porcentaje no es del alojamiento sino de cada canal: Booking y Airbnb aplican el suyo y un
directo no aplica ninguno. Un `aplica_servicio` de sí/no obligaría a preguntar aparte «¿en
cuáles?», y esa pregunta acabaría contestada con una constante en PHP — justo lo que este
proyecto evita en los maestros. Vacía = no se aplica en ninguno, que es el valor seguro.

En `consultar_disponibilidad` va **fuera del total** y con la cifra ya sumada:

```
8 pax · Casita 3 · 3 noches
  precio              204.00 USD   ← 135 alojamiento + 54 suplemento + 15 limpieza
  suplemento_por_pax   54.00 USD
  limpieza             15.00 USD   (por estancia, no por noche)
  servicio_en_otas    «NO incluido en el total de arriba. Por airbnb y booking el huésped
                       vería 234.24 USD: son 30.24 de servicio (16% sobre alojamiento +
                       suplemento, la limpieza no entra en la base)…»
```

Dos decisiones de esa cadena de texto, y las dos son sobre el modelo y no sobre el dinero:

1. **El total ya viene sumado**, no «un 16% sobre 189». Pedirle una multiplicación al modelo es
   pedirle que se equivoque con dinero.
2. **Empieza diciendo que NO está incluido.** Si el modelo sólo lee media frase, que la media que
   lea sea ésa.

#### 🔁 `PmsTarifaCalculadora`: una sola respuesta a «cuánto cuesta esta noche»

Tres sitios hacen la misma pregunta —`consultar_tarifas`, `consultar_disponibilidad` y el cargo
de alojamiento al crear una reserva— y resolverla por separado en cada uno es garantizar que un
día el precio que se enseña deje de ser el que se cobra. El servicio expone:

| Método | Para qué |
|---|---|
| `preciosPorNoche()` | El detalle día a día, con el `sourceId` del rango que ganó |
| `resumen()` | Total, moneda, `min_stay` y cuántas noches caen en la base |
| `vieneDeLaBase()` / `rangoDe()` | Leer el `sourceId` sin conocer su formato |

Comprobado que las dos skills dan lo mismo: Casita 1 del 20 al 23 de agosto → **135.00 USD** por
los dos caminos.

#### 🐛 ARREGLADO: el cobro ignoraba el tarifario entero

`PmsCargosAutomaticosService::estimarAlojamiento()` tenía **su propia** consulta de rangos con
`createQueryBuilder()->setParameter('unidad', $unidad)` — la trampa del UUID binario— así que
devolvía cero rangos sin fallar y **todas las estancias se cobraban a la tarifa base**. No era
sólo del agente: `generarParaEvento()` lo llama desde
`PmsInformacionFinancieraCoherenciaListener`, o sea que afectaba a los cargos de toda reserva
directa, creada desde el panel o desde el chat.

```
Casita 1 · 10→19 agosto (9 noches)
  ANTES   630.00   ← tarifa base 70 × 9, ignorando los rangos
  AHORA   565.00   ← 8×65.00 + 1×45.00, los rangos reales
```

Ahora delega en `PmsTarifaCalculadora`, que ya tenía el `findBy()` correcto. **Una fórmula para
los tres caminos** —consultar tarifas, ofrecer disponibilidad y cobrar— en vez de tres copias
que podían divergir. No toca reservas ya creadas: sus cargos siguen como estaban.

#### 💵 Un tramo puede tener DOS precios, y el promedio miente

`precio_por_noche` era `total / noches`. Con 65 tres noches y 45 dos, eso da **57.00**: un
número que no se cobra ninguna noche y que el operador repetiría al huésped.

`resumen()` devuelve ahora `precios_por_noche` con los valores distintos que hay dentro del
tramo, y quien pinta decide:

```
un solo precio   →  "55.00 USD"
varios           →  "de 55.00 a 90.00 USD (varía por noche)"
```

El desglose día a día sigue siendo cosa de `consultar_tarifas`.

#### 👥 El suplemento también al COBRAR, como cargo aparte

`crear_reserva`, `crear_estancia` y el panel pasan todos por `generarParaEvento()`, así que el
suplemento se aplica en los tres sin tocar el Vue —que no calcula precios, sólo crea la reserva
y deja que el backend genere los cargos—.

Va como **cargo separado** y no sumado al alojamiento: el huésped tiene derecho a ver por qué
paga más que la tarifa anunciada, y metido dentro falsearía el precio por noche de cualquier
informe. Comprobado creando una reserva real —Casita 3, 7 personas, 5 noches:

```
Alojamiento                              225.00
Persona adicional (3 × 6.00 por noche)    90.00
Suplemento de limpieza                    15.00
```

⚠️ Va con `PmsTipoCargo::ALOJAMIENTO` y no como SERVICIO: es dinero por dormir ahí, y como
servicio se mezclaría con los horarios extra en los informes.

#### 🔥 La trampa del UUID en DQL, otra vez

```php
// ❌ devuelve CERO filas, sin fallar
->createQueryBuilder('t')->andWhere('t.unidad = :unidad')->setParameter('unidad', $unidad)

// ✅
->findBy(['unidad' => $unidad, 'activo' => true])
```

El id es un `BINARY(16)` y en DQL ese parámetro se serializa mal: la consulta **no da error**,
devuelve una lista vacía. Es la misma trampa de §12.6 y de `PmsExtensionEstanciaService::buscar()`.
Aquí el síntoma era que todas las noches decían «tarifa base» aunque tuvieran su rango. El
solape se filtra en PHP por el mismo motivo: son pocas filas por unidad y no compensa volver al
query builder.

⚠️ **`PmsCargosAutomaticosService::estimarAlojamiento()` tiene ESA MISMA consulta y sigue sin
arreglar** — ver el aviso al final de esta sección.

## 15. Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| **Impedir que una skill se EJECUTE** (el cierre real) | `src/Agent/Access/GuardiaDeSkills.php` | `motivoDeBloqueo()` — lo llaman los 3 adaptadores/CLI |
| **Abrir o cerrar la escritura de un canal** | el que arma la petición (`AiConversationProcessor`, `VoiceAssistant`, `PanelAssistant`…) | el argumento `permitirEscritura:` de `ConversationRequest` — **no hay sitio central, cada canal trae el suyo** |
| Cambiar qué herramientas se le OFRECEN al modelo | `src/Agent/Skill/SkillRegistry.php` | `paraActor()` |
| Cambiar el trato según el daño de una skill | `src/Agent/Access/NivelRiesgo.php` | los tres `case` — no hay nivel destructivo, y su ausencia es deliberada |
| **Cambiar cuándo el agente pregunta «¿cuál de las dos?»** | `src/Agent/Conversation/AclaracionDeEmpate.php` | `obliga()` — hoy: sólo si alguna empatada exige permiso de escritura (§22.20, §22.24) |
| Cambiar qué aclaración se considera enviable | `src/Agent/Conversation/AclaracionDeEmpate.php` | `motivoDeDescarte()` + `MAX_CARACTERES` |
| Cambiar cómo se redacta esa pregunta | `src/Agent/Service/AiConversationProcessor.php` | `reglasDeAclaracion()` — es un turno seco, sin herramientas |
| Añadir un hito nuevo (ej. "pago recibido") | `src/Message/Contract/ConversationMilestoneInterface.php` | constante + `MessageConversation::addContextMilestone()` + `MessageRule::setMilestone()` + choices del CRUD |
| Cambiar de dónde sale una fecha del PMS | `src/Pms/Service/Message/PmsReservaMessageContext.php` | `getMilestones()` |
| Ajustar cuándo caduca o se rescata un mensaje | `src/Message/Service/Queue/MessageRuleEngine.php` | constantes `PAST_THRESHOLD`, `RESCUE_WINDOW`, `EXPIRY_WINDOW` |
| Cambiar qué estados de conversación/asunto admiten reglas | `src/Message/Service/Queue/MessageRuleEngine.php` | `ruleAppliesToAgenda()` — §4.2 y §20.5 |
| Saber de qué asunto es un mensaje encolado | `src/Message/Entity/Message.php` | `getAsuntoType()`/`getAsuntoId()` — §4.3 |
| Tocar cómo se arma la agenda de un asunto (hitos, muerte, novedad) | `src/Message/Service/Queue/AgendaDeAsunto.php` | `deEnlace()`, `estaMuerta()` — §20.5 |
| Que los asuntos de un módulo nuevo entren al motor | `src/Message/Entity/MessageConversation.php` | `getEnlaces()` — punto único, como `Message::getAllQueues()` |
| Añadir un filtro de segmentación (ej. por tipo de unidad) | `src/Message/Entity/MessageRule.php` | `matchesSegmentation()` — **fuente única**, la usa también `isSatisfiedBy()` |
| Que un cambio de la conversación re-dispare reglas | `src/Message/EventListener/Queue/MessageRuleEngineListener.php` | `CAMPOS_CRITICOS` |
| Añadir un canal de salida nuevo | nuevo `ChannelEnqueuerInterface` + entidad de cola | `supports()`, `createQueueEntity()`, `isValid()`, `getChannelId()` |
| Cambiar la prioridad plantilla vs. operador | `src/Message/Service/Queue/MessageDispatcher.php` | `resolveChannels()` |
| Cambiar cómo se deduce el estado de un mensaje | `src/Message/Service/Queue/MessageRuleEngine.php` | `resolveMessageStatus()` + `healZombieMessages()` |
| Qué abre «Ver Reserva» del chat (§7) | `util/src/stores/chat/chatStore.ts` **y** `util/src/views/ChatView.vue` | `getReservaContextId` (qué contextos tienen ficha propia) + `<ReservaEditDrawer>` de la vista |
| Reparar mensajes fallidos de una reserva | — | `php bin/console app:message:sync-rules <uuid> --force` |
| Aplicar una regla recién creada al parque existente | — | `php bin/console app:message:sync-rules --all` |
| Cambiar cómo se numera el menú al SALIR por una OTA | `Beds24SendMappingStrategy` | `map()` — §8, simétrico con el resolutor |
| Cambiar cómo se interpreta el número al VOLVER | `InboundMenuResolver` | `resolveActionCode()` — §8, simétrico con el render |
| Añadir un idioma al footer «responde con el número» | `Beds24SendMappingStrategy` | `getMenuTranslations()` |
| Que una opción del menú pueda responderse por Airbnb/Booking | EasyAdmin | `beds24Tmpl.is_active` de la plantilla de respuesta — §8 |
| **Añadir una capacidad al agente** | `src/Agent/Skill/` | Una clase con `SkillInterface` — §11. No se toca motor ni adaptador |
| Cambiar cómo nace una estancia (horas, estados, canal) | `PmsEstanciaCreator` | `crear()` — lo comparten `crear_reserva` y `crear_estancia`, **no lo dupliques** |
| Cambiar qué cargos escribe una skill tras la aprobación | `PmsCargosAutomaticosService` | `escribirAprobados()` — lo comparten las dos skills, **no lo dupliques** |
| Cambiar el desglose que el operador aprueba (`crear_reserva`) | `CrearReservaSkill` | `cargosPrevistos()` — se enseña Y se escribe, es el mismo cálculo |
| Cambiar el desglose que el operador aprueba (`crear_estancia`) | `CrearEstanciaSkill` | `$lineas` en `conDatosCompletos()` — mismo criterio |
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
| Que el agente pueda decidir un early check-in sin escalar | 🚧 falta el dato | No hay registro de salida real ni de fin de limpieza — §17.5. Hasta entonces, propone y escala |
| Cambiar en qué idioma se guarda un mensaje del agente | `MessageTranslator` | `process()` — el caso saliente se distingue por `DIRECTION_OUTGOING`, §9 |
| Entender por qué un mensaje no salió | `var/log/` | busca "Omisión preventiva", "Rescate descartado", "Poda de canales", "Caducidad", "Sanidad" |
| Cambiar cuándo se sella el tipo de cambio de un cargo/pago del agente | `RegistrarCargoSkill` / `RegistrarPagoSkill` | `$tipoDelDia` (se persiste, siempre) vs `$tipoAplicado` (se reporta, sólo si hubo conversión) — §11, espejo del `tcSiempre` del panel |
| Cambiar qué estancias se ofrecen al imputar un cargo del agente | `RegistrarCargoSkill` | `estanciasImputables()` — mismo filtro que `BuscarEventoEstanciaSkill`, **no** el de `getEstancias()` |
| Cambiar quién puede figurar como cobrador de un pago | `PmsEnumAjaxController::getCobradores()` **y** `RegistrarPagoSkill::cobradoresPosibles()` | Filtro `ROLE_COBRADOR` sobre `u.roles` — los dos o el agente admite a quien el panel no ofrece |
| Cambiar en qué medios de pago se pregunta por el cobrador | `PmsMedioPago` | `seCobraEnMano()` — fuente única |
| Cambiar a quién se atribuye un pago si no se dice quién cobró | `User` | Flag `esCobradorPrincipal`, editable en el CRUD de usuarios |
| **Asignar quién limpia una estancia** | drawer de Reservas de `util` | Casillas «Limpieza» en cada estancia; también en EasyAdmin. Vacío = no le sale a nadie de campo |
| Cambiar quién puede figurar como limpieza | `PmsEnumAjaxController::getLimpiadores()` **y** `PmsEventoCalendarioProcessor::aplicarLimpieza()` | Filtro `ROLE_LIMPIEZA` vía `findByRole()` — los dos o habrá asignaciones que el desplegable no puede deshacer |
| Cambiar a quién se asigna sola una estancia nueva | `User` | Flag `esLimpiezaPorDefecto`, en el CRUD de usuarios. **No** filtra por `enabled`: quien limpia no tiene login |
| Cambiar la forma en que viaja la limpieza por la API | `PmsEventoCalendario` | `getLimpieza()` (lee `[{id,nombre}]`) / `setLimpiezaIds()` (escribe ids). Ausente ≠ `[]` |
| Probar una skill como una persona real (sus roles, su identidad) | — | `app:agent:skill <skill> '{...}' --usuario=<username>` |
| Construir un actor del agente (siempre por aquí) | `AgentActorFactory` | `delPanel()` / `delEquipoPorChat()` — expanden la jerarquía de roles. `AgentActor::` a secas se queda en los literales |
| Registrar el móvil de alguien del equipo | `User::$telefono` + CRUD de usuarios | Se normaliza solo (`UserIntegrityListener`); §12.9.b del doc de sync |
| **Quién recibe los avisos del asistente** | CRUD de usuarios | Rol «🆘 Atención al cliente» (`ROLE_CUSTOMER_SUPPORT`) **+ móvil**. Las dos cosas o no llega nada |
| Cambiar qué se escribe en el aviso de escalado | `EscalarAlEquipoSkill` | `redactar()` |
| Cambiar el aviso de escalado **fuera** de la ventana de 24 h | plantilla `aviso_escalado_interno` | El cuerpo se edita en el panel; los parámetros los pone `EscalarAlEquipoSkill::variablesDelAviso()`. Si añades uno, tiene que llegar SIEMPRE con valor o el envío revienta |
| Que la plantilla del escalado empiece a salir de verdad | Meta + `app:whatsapp:sync-templates` | Está insertada como `PENDING`: hasta que Meta la apruebe, el encolador la rechaza |
| Mandar una plantilla con datos que NO están en el contexto | `Message::setVariablesPlantilla()` | Se fusionan pisando a las del resolver en `WhatsappMetaSendMappingStrategy` |
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
| Mandarle al huésped el enlace para pagar su adelanto | `GenerarEnlacePrepagoSkill` + `EnviarMensajeHuespedSkill` | Son DOS llamadas: genera, y luego envía. §11 |
| Apagar una skill sin borrarla | `SkillConmutableInterface` en la propia skill | Desaparece del catálogo; repite la guarda en `ejecutar()`. §11 |
| Que el huésped pueda avisar al equipo | `EscalarAlEquipoSkill` | Ya la tiene (`Roles::HUESPED` + `Interna`). El prompt le obliga a llamarla si promete respuesta, §9 |
| **Que el huésped pueda pedirse una plantilla** (su guía, tours) | CRUD de plantillas | Interruptor «autoenvío» (`autoenvio_habilitada`) + «Cuándo usarla» rellenado. La manda `EnviarmePlantillaSkill`, §11 |
| Qué plantillas puede mandar el agente del panel | CRUD de plantillas | TODAS las que tengan «Cuándo usarla» escrito — ya no hay flag; guardan la confirmación del operador y `allowedSources`, §11 |
| Que una plantilla sólo salga a su OTA (Booking vs Airbnb) | CRUD de plantillas | `allowedSources` — lo validan por código las dos skills de plantillas, §11 |
| **Añadir o cambiar una marca de formato** (*negrita*, __subrayado__…) | `FormatoDeTexto` **y** `util/src/utils/formatoDeTexto.ts` | Son espejo: los dos o el panel pinta distinto de lo que llega, §14 |
| Que el asistente del panel enlace a una ficha | `ListarSalidasSkill` | `fichaDe()` — espejo de `verEnReservas()` en `HomeView.vue` |
| Que una vista se recargue tras una acción del asistente | la propia vista | engancha `@datos-cambiados` de `AsistenteBar` a su función de carga |
| Cambiar qué cuenta como «escritura» para ese refresco | `NivelRiesgo` | `modificaDatos()` — un solo sitio, no una lista en el front |
| Cuánto sobrevive el hilo del asistente a un refresco | `AsistenteBar.vue` | `HILO_TTL_MS` (1 h) y `restaurarHilo()` |
| Cambiar cómo degrada un canal el texto libre | `Beds24SendMappingStrategy` / `WhatsappMetaSendMappingStrategy` | `paraTextoPlano()` / `paraWhatsapp()`, sólo con `getTemplate() === null`, §14 |
| Cambiar la barra B/I/U/S del compositor del chat | `ChatView.vue` | `aplicarFormato()` + `envolverSeleccion()` de `formatoDeTexto.ts`, §14 |
| Probar el formateador sin gastar API | — | `php var/probar-formato.php` — §14 |
| **Cambiar qué dice el resumen de lo pendiente** | `ResumenConversacionService` | `SYSTEM_PROMPT` — una frase, 12 palabras, §7 |
| Cambiar la ventana que se resume | `ResumenConversacionService` | `ventanaSinResponder()` — el corte es la última salida, §7 |
| Apagar el resumen IA | `.env` | `AGENT_IA_RESUMEN_CONVERSACION=0` — todo cae al texto del último mensaje, §7 |
| Abaratar o encarecer el resumen | `.env` | `AGENT_IA_RESUMEN_POTENCIA` y `AGENT_IA_RESUMEN_ESPERA`, §7 |
| **Apagar el triaje y volver al agente de antes** | `.env` | `AGENT_IA_TRIAJE=0` — todo pasa por el camino largo con el catálogo entero, §13 |
| **Cambiar qué modelo atiende cada paso** | `.env` | `AGENT_IA_POTENCIA_ALTA` / `_MEDIA` / `_BAJA`, en la forma `proveedor:modelo`. Vacías = como antes, §13.5 |
| Subir o bajar la potencia del clasificador | `.env` | `AGENT_IA_TRIAJE_POTENCIA` — `alta` \| `media`. Nunca `baja`: corre en TODOS los mensajes, §13.5 |
| Bajar de tramo el trabajo que queda tras elegir una skill | la propia skill | `definicion()->siguientePaso` — hoy ninguna está bajada, y §13.5 dice por qué |
| Cambiar qué cuenta como emergencia | `Triaje::reglas()` | El prompt, no un regex. Ante la duda elige `emergencia` a propósito, §13.2 |
| Que el triaje ofrezca otra clase de mensaje | `TipoDeMensaje` | El `case` **y** `opciones()`, que es lo que ve el modelo — `Indeterminado` se omite a propósito, §13.2 |
| Que la sugerencia del triaje mande de verdad | `AiConversationProcessor::pistaDelTriaje()` | Está redactada como sugerencia a propósito: §13.8. Piénsalo antes |
| Cambiar qué temas de la guía ve el triaje | `PmsIndiceDeTemas::bloqueParaElPrompt()` | Índice GLOBAL (uuid + etiqueta + casitas), determinista para no romper el caché. Lee `getTitulo()`, no `getTituloParaCliente()` (transitorio, llega vacío), §13.8 |
| Cambiar cómo se valida el tema que propuso el triaje | `Triaje::interpretar()` | Contra los temas de LA CASITA (`porUnidad`), no contra el índice entero, §13.8 |
| Comprimir el índice de guía cuando el parque crezca | `PmsIndiceDeTemas` | 🚧 uuid → ordinal con mapa reconstruido por petición; el umbral y el porqué, en su docblock y §13.8 |
| Ajustar qué puede decir el bot en la charla (y cuándo ofrece ayuda) | `Triaje::reglas()` | Las reglas de la «respuesta» viven en el prompt del clasificador. La seguridad viene de que NO hay herramientas, §13.7 |
| Añadir una llamada sin herramientas a un motor nuevo | `AgentEngineInterface` | `turnoDirecto()` — con esquema, la salida la fuerza el proveedor, §13.6 |
| Que un esquema JSON funcione también en Gemini | `GoogleAIEngine::esquemaGemini()` | Gemini rechaza `additionalProperties` y los `type` en lista con un 400, §13.6 |
| Medir cuánto ocupa cada prompt sin gastar API | — | `php var/medir-triaje.php` — §13.3 |
| Comprobar el triaje y el esquema de Gemini sin gastar API | — | `php var/probar-triaje.php` — 15 comprobaciones + qué motor resuelve cada tramo, §13.10 |
| Saber si el triaje está fallando en silencio | `var/log/info-*.log` | `grep -c "indeterminado — respuesta no era JSON"` — §13.6 bis. Degrada limpio, así que sólo se ve aquí |
| **Ver qué contestaría hoy el agente a una charla que ya ocurrió** | — | `php bin/console app:agent:replay <uuid-reserva> --guion=<json>` — §16.7. No guarda nada, pero las skills sí se ejecutan |
| Que el agente sepa en qué momento de la estancia está el huésped | `AiConversationProcessor` | `faseDeLaEstancia()` — §17. Va en el contexto, no en una skill: el triaje no llama a herramientas |
| Que sepa si la casita está libre antes o después de su estancia | `PmsEspacioEstancia` | `alrededorDe()` — §17.3. Ocupación con `PmsEventoEstado::IMPIDEN_VENTA`, la misma del calendario |
| Cambiar cuánta flexibilidad se ofrece en entrada y salida | panel → ítem «Early check in Late Check Out» | Campo «🗣️ Lo que dice el asistente». Es política: NO va en el contexto, §17.4 |
| Decidir dónde poner algo nuevo: contexto, guía o skill | — | §17.4. Hechos de la conversación → contexto; norma → guía; datos que se piden → skill |
| **Cambiar un número de cuenta o de Yape** | panel → Configuración → Cobros | «Medios de cobro» — §16. No se escribe en la guía ni en el código |
| Que el agente pueda decir por dónde se paga | `ConsultarMediosPagoSkill` | `medios()` — lo que no se enumera ahí no llega al modelo, §16.7 |
| Cambiar el tipo de cambio que se le dice al huésped | `TipoCambioDelDia` | `venta()` — misma fuente que el TC de cargos y pagos, §16.2 |
| Que el agente ofrezca el enlace de pago con tarjeta | `.env` | `FINANZAS_ENLACES_PREPAGO=1` — §16.2 |
| Cambiar a quién se le ofrece cada medio de cobro | `FinAudienciaCobro` | `aplicaA()` — no es un permiso, es dinero, §16.3 |

---

## 16. Cómo se paga: medios de cobro y tipo de cambio

Esta sección existe por un incidente concreto, y conviene leerla con él delante porque el fallo
**no fue del modelo**: fue un hueco de datos que el modelo tapó como pudo.

### 16.1 El incidente de la reserva V4JE5Q

El 09/08/2026, entre las 23:09 y las 23:22, una huésped preguntó cuatro veces seguidas cómo
pagar su adelanto. El agente contestó con:

| Lo que dijo | Lo que era | De dónde salió |
|---|---|---|
| adelanto 30.00 USD, saldo 187.50 | correcto | `consultar_cuenta` → `prepago_pendiente` |
| recargo del 5.5% con tarjeta | correcto | `consultar_cuenta` → `pago_con_tarjeta` |
| `https://micasita.com/pagar-tarjeta` | **no existe** | inventado |
| tipo de cambio 3.80 | era 3.391 | inventado |
| Yape al `984 000 000` | no existe | inventado |

A las 23:19 escaló de verdad —`escalar_al_equipo` avisó a dos operadores— y a las 23:35 entró
una persona a corregir el tipo de cambio. O sea: **las herramientas funcionaron y la escalada
funcionó**. Lo que no había era de dónde leer un número de cuenta.

Dos lecciones, y las dos van contra el reflejo de tocar el prompt:

1. **El modelo llamó a las herramientas para todo lo que cubrían e inventó exactamente lo que
   no cubrían.** No era un problema de criterio ni de modelo pequeño: `consultar_cuenta`
   decía cuánto y ninguna decía por dónde.
2. **La prohibición del prompt era enumerativa.** Decía «nunca inventes precios,
   disponibilidad, horarios ni políticas». Una URL no es ninguna de las cuatro; un número de
   Yape tampoco. El modelo cumplió la regla al pie de la letra.

De ahí que el arreglo sea, en este orden: **primero el dato, luego la skill, y sólo al final el
prompt** — y que la prohibición nueva sea por categoría («si es un DATO, se copia») en vez de
por lista.

Un detalle que se paga caro si se olvida: `consultar_cuenta` **sí** devolvía `tipo_cambio` en
cada cargo, pero es el del día en que se cobró, no el de hoy. Un dato correcto leído fuera de
contexto es tan malo como uno inventado, y por eso el TC vive en su propia skill.

### 16.2 Dónde vive cada cosa, y por qué no en el mismo sitio

El reparto lo decide **cada cuánto caduca el dato**:

```
  ┌───────────────────────────────────────────────────────────────────────┐
  │ NÚMEROS DE COBRO          estables — se escriben una vez y valen un año│
  │ FinMedioCobro  (catálogo GLOBAL, una fila por cuenta)                 │
  │   Yape · Plin · 4 bancos × soles/dólares · Western Union · efectivo   │
  │   → los edita el equipo en Configuración → Cobros                     │
  │   → los sirve  ConsultarMediosPagoSkill (vía FinMedioCobroRepository) │
  │   → los pinta  {{ medios_pago }} en la guía                           │
  │   → los reusa  las condiciones de pago de una cotización (§16.8)      │
  ├───────────────────────────────────────────────────────────────────────┤
  │ TIPO DE CAMBIO            volátil — cambia a diario                    │
  │ TipoCambioDelDia::referencia()  (venta SUNAT, con caché y fallback)   │
  │   → lo sirve   ConsultarTipoCambioSkill                               │
  │   ⚠️ NO se escribe en la guía: envejece en silencio                    │
  ├───────────────────────────────────────────────────────────────────────┤
  │ ENLACE DE PAGO            no existe todavía                            │
  │ GenerarEnlacePrepagoSkill, apagada con FINANZAS_ENLACES_PREPAGO=0     │
  │   → mientras esté apagada, consultar_medios_pago devuelve             │
  │     pago_con_tarjeta.disponible = false + la orden de no inventarlo   │
  └───────────────────────────────────────────────────────────────────────┘
```

#### Por qué un catálogo global y no una columna por establecimiento

La primera versión de esto vivía en `PmsEstablecimientoVirtual::$mediosCobro`, un JSON por
listing comercial. **Duró un día.** Falla por dos sitios a la vez:

1. Las cuentas son **las mismas para todos** los establecimientos —el dinero entra a la misma
   empresa—, así que aquel diseño obligaba a corregir el mismo número en cada listing, y el que
   se olvidara quedaba mintiendo con un número viejo.
2. Hacen falta **fuera del PMS**: las condiciones de pago de una cotización necesitan
   exactamente estos datos y ahí no hay reserva ni establecimiento virtual de por medio.

Por eso `FinMedioCobro` vive en `src/Finanzas/` y no en `src/Pms/`: `src/Finanzas/` no importa
de `App\Pms` en ningún archivo, y las flechas van Pms → Finanzas y Cotizacion → Finanzas. Es lo
que permite que el segundo consumidor no herede el PMS entero.

**Por qué los números NO van escritos en el ítem de guía**, que era lo natural: es el mismo
criterio que ya separó el WiFi de su ítem (§11 y `ConsultarWifiSkill`). `ConsultarGuiaSkill`
recorta cada ítem a 900 caracteres y devuelve como mucho 4; un CCI de 20 dígitos partido por la
mitad es una transferencia perdida. Además, un campo se valida y un párrafo no.

Y **por qué el tipo de cambio no va con los medios de cobro**, aunque se pregunten juntos: los
medios son configuración y el TC es una cotización. Ponerlos en el mismo sitio obliga a que
alguien edite a mano todos los días la ficha donde está el número de cuenta, que es justo la
que no se quiere tocar.

### 16.3 La audiencia no es un permiso, es dinero

Cada medio declara a quién sirve (`FinAudienciaCobro`): `todos`, `peru`, `internacional`.
No es control de acceso — es que ofrecer el medio equivocado **le cuesta al huésped**: una
transferencia internacional a una cuenta peruana se lleva en comisiones buena parte de un
adelanto de 30 USD, y al revés, mandar a un peruano a Western Union es cobrarle un giro que no
necesitaba.

Por eso **filtra la skill y no el modelo**: el modelo sólo vería un número de cuenta, y un
número de cuenta no dice cuánto cuesta llegar hasta él.

⚠️ **El prefijo 51 no demuestra que alguien sea peruano.** `PhoneSanitizer::cleanPhoneNumber()`
antepone el 51 a cualquier móvil de 9 dígitos que llegue sin prefijo, que es la mayoría. Leer
ese 51 como «es de Perú» es leer nuestro propio valor por defecto. De ahí que la evidencia sea
asimétrica en `ConsultarMediosPagoSkill::esDePeru()`, que es lo contrario de lo que uno
escribiría:

| Señal | Conclusión | Por qué |
|---|---|---|
| `PmsReserva::$pais` relleno | manda, sin más | Lo dijo el canal |
| Teléfono que **no** empieza por 51 | `false` | Eso lo tecleó alguien: nadie pone un +34 por defecto |
| Teléfono que empieza por 51, sin país | `null`, **no** `true` | El 51 puede haberlo puesto el saneador |
| Sin teléfono ni país | `null` | — |

Con `null` pasan **todos** los medios: enumerar de más es recuperable; esconderle al huésped el
único que le servía, no.

#### Consecuencia: no hay transferencias internacionales, y por eso no hay SWIFT

Las cuentas están todas en `peru`, así que a quien pague desde fuera **no le llega ninguna** —ve
Western Union y efectivo—. De ahí se sigue algo que no es obvio leyendo el catálogo: el SWIFT no
se pide nunca, porque nadie a quien se le ofrezca una cuenta va a hacer una transferencia
internacional. El agente tiene prohibido mencionarlo para no abrir una puerta que cuesta dinero.

El **CCI** es otra cosa y sí hace falta: es lo que se usa para transferir entre dos bancos
peruanos distintos. Está vacío en las doce filas a propósito —se teclea cuando alguien lo
comprueba, no se publica de oficio— y la regla es explícita: si la cuenta no lo trae, el agente
**no lo compone a partir del número de cuenta**. Se parecen lo bastante como para que un modelo
lo intente, y un CCI mal armado manda el dinero a un tercero. Sin CCI se escala.

### 16.4 Lista vacía ≠ silencio

Si el establecimiento no tiene medios configurados, la skill **no** devuelve `medios: []` y ya.
Devuelve además una `instruccion` explícita de escalar. Una lista vacía se lee como «este
alojamiento no cobra» y el hueco se vuelve a rellenar con imaginación — que es exactamente lo
que pasó en V4JE5Q. Lo mismo con `pago_con_tarjeta.disponible: false`, que viaja siempre en vez
de omitirse: **el silencio es lo que el modelo rellena solo.**

Por el mismo motivo, un medio al que le falte el `numero` se descarta en vez de viajar a medias:
un titular y un banco sin destino son la materia prima con la que antes se inventaba el resto.

### 16.5 Lo que cambió en los dos prompts

Los dos, porque el triaje también contesta solo (§13.7) y tenía el mismo agujero enumerativo:

- **`AiConversationProcessor::reglas()`** — bloque nuevo «datos que se copian, nunca se escriben
  de cabeza» (enlaces, cuentas, CCI, Yape/Plin, titulares, tipos de cambio), la prohibición de
  ofrecer enlace de pago sin `pago_con_tarjeta.disponible: true`, y una regla de escalada **por
  falta de dato** — no sólo por haber prometido un humano, que era el único disparador y por eso
  llegó tres turnos tarde.
- **`Triaje::reglas()`** — la lista de lo que no puede decir en la charla se cierra con «la lista
  no es cerrada: si es un DATO, no sale de este turno».

### 16.6 La pantalla y el chat dicen lo mismo, por construcción

El huésped ve los mismos medios en su guía (`{{ medios_pago }}`) que le dicta el asistente por
el chat, y eso **no depende de que nadie se acuerde de sincronizarlos**: los dos piden la misma
lista al mismo repositorio con el mismo filtro.

```
   PmsProcedenciaHuesped::pagaDesdePeru($reserva)   ← una sola regla
                          │
          ┌───────────────┴───────────────┐
          ▼                               ▼
  ConsultarMediosPagoSkill        PmsGuiaHuespedProvider
  (chat)                          (guía en pax)
          │                               │
          └────► FinMedioCobroRepository::ofrecibles($desdePeru) ◄────┘
```

Que la deducción de procedencia sea un servicio y no un método privado es justo el motivo:
si el chat y la pantalla la calcularan cada uno por su lado, un huésped podría ver una cuenta
del BCP en su guía y oír del asistente que no tenemos cuentas para él. No sabría a cuál creer.

Diferencias deliberadas entre los dos lados, que no son divergencia:

- **La guía no tiene ventana.** Al revés que el WiFi, los medios se ven antes de llegar: quien
  todavía no ha entrado es justo el que necesita adelantar el pago.
- **La `nota` viaja sin traducir** a `pax` (array i18n crudo, lo resuelve `maestroStore.traducir()`
  con el idioma que el huésped tenga puesto ahora) y **ya traducida** al modelo, que redacta en
  el idioma del huésped. El backend no sabe qué idioma está mirando alguien en este segundo.
- **La lista vacía dice lo mismo en los dos sitios**: «consúltanos por el chat». Ni la pantalla
  ni el modelo improvisan instrucciones de pago cuando no hay datos.

⚠️ El widget se resuelve por el VALOR del placeholder (`WIDGET_REGISTRY` en
`RichContentEngine.ts`), no por la clave `widget`. Antes la clave apuntaba directa a la tarjeta
de WiFi y `{{ widget: loquesea }}` pintaba WiFi igual; con un solo widget no se notaba, con dos
habría salido el bloque de pagos como una tarjeta de WiFi vacía.

### 16.7 Comprobar que no vuelve a alucinar

Que una skill devuelva el dato correcto **no demuestra que el huésped lo reciba correcto**: en
medio hay un modelo que redacta. Para eso está `app:agent:replay`, que le vuelve a hacer al
agente de hoy las preguntas de una conversación que ya ocurrió:

```bash
php bin/console app:agent:replay <uuid-reserva> --guion=var/guion-v4je5q.json
```

Recorre el mismo camino que `AiConversationProcessor::generar()` —triaje y, si no era charla,
el catálogo entero con `permitirEscritura: false`— y lee los prompts **por reflexión**, para que
la prueba no se quede probando una copia vieja. No persiste nada: el historial vive en memoria.

⚠️ **Lo único con efectos son las skills, y son DOS, no una.** Con actor de huésped todo es de
lectura salvo las dos `NivelRiesgo::Interna` —que es justo el matiz que las deja llegar al chat
del huésped (§11)— y las dos mandan mensajes a personas reales:

| Skill | A quién le llega | Cómo protegerse |
|---|---|---|
| `escalar_al_equipo` | WhatsApp a quien tenga `ROLE_CUSTOMER_SUPPORT` **y** móvil | Vaciar esos teléfonos mientras pruebas (y **acuérdate de restaurarlos**). Sin destinatarios deja un `Escalado sin guardia` en el log, que es la señal de que se invocó |
| `enviarme_plantilla` | Un mensaje **AL PROPIO HUÉSPED**, por su canal | Usar una reserva de mentira. No hay flag que lo pare |

🔥 **Vaciar los teléfonos de guardia NO deja la prueba limpia.** Eso protege a los operadores,
no al huésped. Probando el flujo ampliado de V4JE5Q contra una copia de producción, ante
«¿ofreces tours?» el modelo llamó a `enviarme_plantilla` y se creó un `Message` real con su cola
de Beds24 **apuntando a una huésped de verdad**. No salió por casualidad —la plantilla «Menú de
tours» no tenía ID de Meta para `es` y el encolado falló—, pero pudo salir.

Si el guion va a tocar tours, menús o cualquier cosa que se «envíe», usa una reserva inventada.
`--con-guardia` asume las dos cosas a la vez, no sólo la escalada.

Y un efecto colateral que también ocurre en producción: la skill **le dijo al huésped que el
menú se había enviado** cuando el encolado había fallado. El fallo se registra como `warning`
(«encolado con fallos parciales») y la skill devuelve éxito igual, así que el modelo lo anuncia
con toda confianza.

Resultado del replay de V4JE5Q con el agente nuevo, turno a turno:

| Pregunta | Antes (09/08) | Ahora |
|---|---|---|
| «¿el pago no es por Booking?» | inventó el desglose | enumera los medios reales, sin dar números todavía |
| «por tarjeta de crédito» | `https://micasita.com/pagar-tarjeta` | **escala**: no hay enlace y no se lo inventa |
| «¿qué tipo de cambio manejan?» | 3.80 → 114 soles | **3.391 → S/ 101.73**, copiado literal de `consultar_tipo_cambio`, más el Yape real |
| «el número de cuenta en dólares» | avisó al equipo | pregunta **con qué banco**, en vez de soltar las ocho cuentas |

🚧 **Con una salvedad que importa:** el replay corrió contra Gemini, porque en local no hay
`ANTHROPIC_API_KEY` y `SelectorDePotencia` cae al motor por defecto. Producción atiende el tramo
medio con `anthropic:claude-sonnet-5`. La prueba dice que **los datos y las reglas están en su
sitio**; para afirmar lo mismo del modelo que responde de verdad, hay que repetirla con la clave
de Anthropic puesta.

### 16.8 Reutilizarlo desde las cotizaciones

El catálogo es agnóstico a propósito: no sabe qué es una reserva ni qué es una cotización.
Quien quiera ofrecer medios de pago pide **la misma lista** y decide cómo pintarla.

```
                        FinMedioCobroRepository::ofrecibles(?bool $desdePeru)
                                          │
                 ┌────────────────────────┴────────────────────────┐
                 ▼                                                 ▼
   ConsultarMediosPagoSkill                          Condiciones de pago de
   (chat del huésped)                                una cotización  🚧
     · ¿de Perú? ← país de la reserva                   · ¿de Perú? ← país del
       o prefijo del teléfono                             cliente de la cotización
     · aplana a texto para el modelo                    · renderiza a HTML para el PDF
```

Lo único que cada consumidor pone de su parte es **de dónde saca el `desdePeru`**, porque el
dato de origen no está en el mismo sitio: en el PMS es `PmsReserva::$pais` con el teléfono de
suplente (§16.3); en una cotización será el país del cliente. Ese cálculo NO se sube al
repositorio a propósito — subirlo obligaría a que Finanzas supiera qué es una reserva, que es
justo lo que este reparto evita.

🚧 **Todavía no existe el campo «condiciones de pago» en `Cotizacion`.** Cuando se cree, la
regla es: no duplicar números en un texto libre de la cotización. Si el comercial escribe la
cuenta a mano en cada presupuesto, vuelve el problema de V4JE5Q por la puerta de atrás —ocho
cuentas copiadas a mano envejecen igual de mal que una inventada—. Lo que se guarda en la
cotización es la **decisión** (qué medios se le ofrecen a este cliente, qué plazos); los números
se resuelven al renderizar, contra el catálogo.

### 16.9 Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Cambiar un número de Yape o de cuenta | panel → Configuración → Cobros → Medios de cobro | Una fila por cuenta. **No** se toca código ni se escribe en la guía |
| Añadir una clase de medio (PayPal, cripto…) | `FinMedioCobroTipo` | El `case` + `label()`. El CRUD y la skill lo recogen solos |
| Cambiar a quién se le ofrece un medio | panel, campo «¿A quién se le ofrece?» | Respaldo: `FinAudienciaCobro::aplicaA()` |
| Cambiar cómo se deduce si el huésped paga desde Perú | `PmsProcedenciaHuesped` | `pagaDesdePeru()` — **fuente única** del chat y de la guía; lee el aviso del prefijo 51 antes de tocarlo |
| Cambiar de qué punta sale el tipo de cambio (venta/compra) | `TipoCambioDelDia` | `venta()` — **es la misma que se snapshotea en cargos y pagos**; cambiarla aquí descuadra el agente con la contabilidad |
| Que el agente empiece a ofrecer el enlace de pago | `.env` | `FINANZAS_ENLACES_PREPAGO=1` — `pago_con_tarjeta.disponible` pasa a `true` solo |
| Que un medio deje de ofrecerse sin perder sus datos | panel | Casilla «Activo» de esa fila |
| Añadir un campo al medio (ej. alias interbancario) | `FinMedioCobro` + su CRUD + `ConsultarMediosPagoSkill::medios()` **y** `PmsGuiaHuespedProvider::mediosPago()` | Los cuatro: lo que no se enumera no llega ni al modelo ni a la pantalla, §11 |


---

## 17. La conciencia del tiempo y del espacio

El agente no tenía ninguna. El contexto de la conversación eran dos líneas —nombre e idioma— y
con eso da exactamente igual que falten tres semanas para el check-in o que el huésped se fuera
ayer. Dos casos reales del 10/08/2026 lo dejaron a la vista.

### 17.1 Los dos fallos que lo destaparon

**César Hiroyasu.** Se marchó el 09/08 tras un late check-out con cargo. Al día siguiente
escribió disculpándose y el agente cerró con «quedo a su disposición si necesita cualquier otra
cosa **durante su estancia**». La respuesta era buena —una disculpa al día siguiente se acepta y
ya está, que es lo que conviene antes de los comentarios— salvo por el detalle de que no había
estancia.

**Alejandra Rodríguez.** Avisó de que llegaba a Cusco «aproximadamente 12» y el agente contestó
«**te esperamos a esa hora**», con su check-in a las 14:00. Acertó de casualidad: la salida de
ese día estaba cancelada y la casita llevaba dos días libre. Con otra reserva encima, habría
mandado a alguien a un departamento ocupado.

Los dos salieron por el **triaje**, que contesta la charla sin llamar a ninguna herramienta
(§13.7). O sea: el único camino sin datos es el que atiende los mensajes que parecen
inofensivos, que son justo los que salen caros.

### 17.2 La fase va en el contexto, no en una skill

`AiConversationProcessor::faseDeLaEstancia()` añade una línea al contexto:

```
Hablas con César Hiroyasu.
SE FUE AYER. Su estancia terminó; no le hables como si siguiera alojado.
Responde SIEMPRE en el idioma con código "es".
```

Va **en el contexto y no en una skill** por el motivo de arriba: el triaje no llama a
herramientas, así que una skill habría dejado ciego justo al camino que la necesitaba.

Sale de `contextData.milestones`, que la conversación ya trae cargado — **cero consultas**. Son
unos 25 tokens en la parte no cacheada, y compran que el agente no le hable a un huésped que ya
se fue como si siguiera dentro.

Fases, en el orden en que se comprueban (primero lo que ya pasó, porque un huésped que se fue
también encaja en «está alojado» si se mira al revés):

| Fase | Qué se le dice al modelo |
|---|---|
| Se fue ayer / hace N días | Su estancia terminó; no le hables como si siguiera alojado |
| Se va hoy | A las HH:MM. Puede estar recogiendo o ya fuera |
| Llega hoy | A partir de las HH:MM. Antes puede no estar lista |
| Llega mañana | A partir de las HH:MM |
| Entra en N días | La fecha |
| Está alojado | Se va el DD/MM a las HH:MM |
| *(sufijo)* Mañana es su último día | Se añade a «está alojado»: es cuando preguntan por la salida tarde |

### 17.3 El espacio: qué hay antes y después

La fase dice en qué momento de SU estancia está. Lo que decide si cabe adelantar una entrada es
otra cosa: **qué hay alrededor en la misma casita**. Lo calcula
`PmsEspacioEstancia::alrededorDe()` y son cuatro hechos:

| Dato | Para qué |
|---|---|
| `sale_alguien_el_dia_que_llega` (+ hora) | Si hay salida ese día, no hay margen: almacén y escalar |
| `libre_la_vispera` | Si nadie ocupa la noche anterior, cabe pedir margen |
| `desde_cuando_libre` | Para decir «libre desde el 08» en vez de un «sí» seco |
| `entra_alguien_el_dia_que_se_va` (+ hora) | Si entra otro, la salida es a su hora |

La ocupación se cuenta con `PmsEventoEstado::IMPIDEN_VENTA`, la misma constante que el
calendario. Reimplementar el criterio habría hecho que el agente y el calendario contaran
noches distintas, y la que se equivoca siempre es la que nadie mira.

Llega por **dos caminos**, y no se pisan porque son el mismo servicio: en el chat del huésped,
dentro del contexto de la conversación; y en `consultar_mi_reserva`, como `casita_ese_dia` y
`casita_tras_su_salida`. El segundo hace falta porque **el panel no tiene contexto**: quien
pregunta desde ahí «¿puede entrar antes?» no debería encadenar otra consulta.

#### ⚠️ Los flags `entradaTemprana` / `salidaTardia` no son ocupación aparte

`PmsEventoCalendario` los tiene como booleanos, y `PmsEventoEstado::CODIGO_EXTENSION` existe para
la «noche fantasma» de una entrada temprana o salida tardía. Ninguno de los dos entra en este
cálculo, y es correcto: **la hora del propio evento ya refleja lo pactado**. Medido sobre las 383
estancias de la base: 0 con `entradaTemprana`, **1** con `salidaTardia` —y ésa tiene `fin` a las
17:00 en vez de a las 10:00— y **cero** eventos en estado `extension`.

O sea que leer `inicio`/`fin` cuenta la verdad sin necesidad de mirar los flags. Contarlos además
sería contar dos veces y bloquear un día entero por una salida que termina a las 17:00. Es la
diferencia entre «no se puede vender esa noche» y «hay alguien dentro»: los flags responden a la
primera pregunta, no a la segunda.

**Sólo se calcula en los bordes** —llega hoy, llega mañana, se va hoy, mañana es su último día—.
El propio texto de la fase hace de filtro. Fuera de ahí no hay consulta ni tokens: preguntar por
los vecinos de una estancia que empieza en tres semanas es gastar en un dato que nadie va a usar.

### 17.4 Por qué NO es una skill: cada capa de su clase

La pregunta que lo decidió fue «¿quién gana si una skill, la guía y una plantilla se pisan?».
La respuesta es que **esa pregunta no debería llegar a plantearse**, y se evita separando por
clase de contenido en vez de por jerarquía:

| Capa | Qué contiene | Ejemplo |
|---|---|---|
| **Contexto** | Hechos de ESTA conversación | «llega hoy a las 14:00», «libre desde el 08» |
| **Guía / `agente_contenido`** | La norma del negocio | «con la casita libre caben 3 h; nunca lo concedas tú» |
| **Skills** | Datos que hay que ir a buscar | saldo, tipo de cambio, medios de cobro |

Mientras cada una se quede en lo suyo no compiten: el contexto no dice qué se puede conceder, y
la guía no sabe a qué hora llega este huésped. **El conflicto sólo aparece si se mete política
en el contexto o hechos en la guía.**

Y cuando dos skills sí se solapan, el proyecto ya tenía respuesta, y no es una tabla de
prioridades: **cada fuente declara lo que NO es suyo**. `consultar_guia` remite el WiFi a
`consultar_wifi`; `consultar_medios_pago` remite el tipo de cambio a `consultar_tipo_cambio`.
Referencias cruzadas en las descripciones (§11), no jerarquía.

Hay además un motivo que zanja el caso: **el triaje no llama a herramientas**. Una skill habría
dejado ciego justo al camino por el que salieron los dos fallos.

### 17.5 Lo que esto NO resuelve

**No existe el «ya salió de verdad».** `PmsEventoCalendario` guarda el `fin` PREVISTO y un
booleano `salidaTardia`; no hay registro de la salida real. Por eso la regla de negocio «si
consta que el anterior se fue con 5 horas de margen, ofrécele entrar antes» **no se puede
evaluar hoy**: sólo está implementada la rama de «libre la víspera».

**Tampoco si la limpieza terminó.** `pms_event_assignment` dice quién tiene asignada la
actividad —sólo existe «Limpieza»— sin fecha ni estado de completado. Que el anterior se fuera a
las 5 no significa que esté listo.

Consecuencia práctica, y es la que sostiene toda la política: lo que sale del contexto sirve
para **descartar** («hoy sale alguien, ni lo plantees») y para **matizar** («está libre, pero la
limpieza puede seguir»), nunca para conceder. **El agente propone y escala; decide una persona.**

Registrar la salida real es lo que más rendimiento daría: desbloquea la regla de las 5 horas y,
de paso, deja saber cuándo la casita está lista.

⚠️ Y hay un dato que **no existe en el sistema**: si la limpieza terminó.
`pms_event_assignment` guarda quién tiene asignada cada actividad, pero no lleva fecha ni estado
de completado. Que el anterior se fuera a las 5 no significa que esté listo, así que ninguna
regla automática de «ofrécele entrar antes» puede ser fiable mientras eso falte. La regla segura
mientras tanto: **el agente propone y escala, nunca concede.**

### 17.6 Quién manda cuando dos fuentes cubren lo mismo

La regla, y no admite excepción: **lo diseñado a medida gana sobre la guía.**

No es preferencia de estilo. Una skill dedicada comprueba permisos que un texto no puede
comprobar —`consultar_codigos` mira la ventana de acceso antes de soltar el código de la caja— y
es la que se actualiza cuando cambia el dato. Un párrafo de guía que diga lo mismo es una copia
que envejece sin avisar.

Se aplica **declarándolo en la guía**, igual que llevaba haciendo el WiFi desde el principio:

| Ítem | Ya no cuenta | Remite a |
|---|---|---|
| Llaves | el código de la caja | `consultar_codigos` |
| Wifi | la contraseña | `consultar_wifi` |
| Pago | los números de Yape y las cuentas | `consultar_medios_pago` |

Lo de Pago tiene un motivo que se ve solo al mirarlo desde el catálogo: si la guía nombrara los
medios, **el día que se desactive uno en `FinMedioCobro` la guía lo seguiría ofreciendo**.

#### Se aprende del revés: corregir el texto donde manda

Se intentó arreglar en el ítem «Pago» el aviso de que el chat de Booking no admite adjuntos, y
no sirvió de nada: el agente leía la **nota del catálogo** —que venía de `consultar_medios_pago`—
y la repetía literal. Si la skill gana, el texto hay que corregirlo **en la skill**. Ver
`Version20260810220000`.

#### ⚠️ Antes de enseñar, comprobar — también en las skills

Que la skill gane no la exime de lo que sí hacía la guía. **Cada una comprueba que el huésped
tenga derecho a ese dato**, con el mismo criterio, y sólo se salta la comprobación cuando quien
pregunta es del equipo:

| Skill | Nivel que exige |
|---|---|
| `consultar_codigos`, `consultar_wifi` | **Ventana abierta** (`PmsGuiaAcceso::estaAbierto()`): estancia en curso |
| `consultar_medios_pago` | **Cliente** (`getEventosActivosGuia()` no vacío) |

La diferencia importa y es fácil equivocarse: usar `estaAbierto()` para los medios de pago
habría dejado sin cuentas **justo a quien todavía no ha llegado**, que es el que necesita pagar
el adelanto. La ventana es para credenciales; para el resto basta con ser cliente de una reserva
viva.

#### El mismo texto lo leen dos actores

`agente_contenido` no sabe quién pregunta. El ítem de horarios explica **las dos vías** por las
que llegan los datos de ocupación —el contexto en el chat del huésped, `casita_ese_dia` desde el
panel— porque el campo de la skill es sólo para el equipo (§17.3) y el contexto sólo existe en
el chat. Un texto que nombre una sola deja al otro actor buscando algo que no le llega.

### 17.7 Qué cambió en las respuestas

| Caso | Antes | Ahora |
|---|---|---|
| César, un día después de irse | «…cualquier otra cosa durante su estancia» | «Esperamos que hayas tenido un **buen viaje de regreso**» |
| Alejandra, llega a las 12 con check-in a las 14 | «Te esperamos a esa hora» | «El ingreso es a partir de las 14:00. Si llegas a las 12:00, puedes **dejar tu equipaje**… ya he avisado al equipo para **revisar si fuera posible ingresar antes** según disponibilidad» |

---

## 18. Sincronizar plantillas con Meta: por qué aparecen duplicados y qué NO hacer

`WhatsappMetaTemplateSyncService::processTemplateRecord()` empareja lo que devuelve Meta con lo
local **por `meta_template_name`, nunca por `code`**. Si ninguna plantilla local reclama el
nombre que llega, crea una fila nueva con el código generado
`sprintf('%s_META', strtoupper($metaName))`.

Eso explica los códigos en mayúsculas del panel: no los escribió nadie, son plantillas de Meta
que aquí no tenían dueño.

> 📌 Hubo uno vivo —`WELCOME_BOOKING_META`— y se cerró el 2026-08-12: ver «La gemela de Booking,
> cerrada» más abajo. Esta sección explica el mecanismo por el que nacen; aquélla, cómo se mata
> uno sin que vuelva a las 03:15.

### 🧹 La gemela de Booking, cerrada (2026-08-12)

`WELCOME_BOOKING_META` era el caso vivo de todo lo anterior, y se cerró así:

| Pieza | Dónde | Qué hace |
|---|---|---|
| Validación | `MessageTemplate::validarNombreDeMeta()` | si la plantilla está **activa para Meta**, el «Nombre en Meta» pasa a ser obligatorio. Es lo que habría impedido lo de `menu_tours` |
| Filtro | `WhatsappMetaTemplateSyncService::NOMBRES_IGNORADOS` | el sincronizador ya no trae `welcome_booking` (ni `hello_world`), así que no puede volver a fabricarla a las 03:15 |
| Migración | `Version20260812200000` | repunta a la buena lo que la referenciara y borra la fila |

El repunte no movió nada —tenía **0 envíos** frente a los 210 de la buena— pero va de todas
formas: borrar una fila referenciada deja el mensaje sin plantilla o revienta por la clave
foránea, y son dos `UPDATE`.

⚠️ **El filtro es la red, no la solución.** Mientras `welcome_booking` siga existiendo en Meta,
lo único que impide que vuelva es esa lista. Lo definitivo es borrarla en la consola de Meta:

```
DELETE /{wabaId}/message_templates?name=welcome_booking
```

Se guardó copia de sus 7 idiomas con sus ids antes de tocar nada. Y ojo: borrar en Meta
**bloquea reutilizar ese nombre unos 30 días**, cosa que aquí da igual porque la plantilla viva
se llama `welcome_booking_command`.

### 🚪 La ventana de 24 h cerrada: se avisa, no se decide por el operador

Fuera de las 24 h siguientes al último mensaje del huésped, WhatsApp no acepta texto libre. El
operador escribe, le da a enviar, y **no sale**. Su texto no se puede colar en una plantilla:
una plantilla oficial lleva texto fijo aprobado por Meta.

Lo único que reabre la ventana es que el huésped conteste, y para provocarlo hay que mandarle
una plantilla. **Cuál, lo elige la persona.**

#### ❌ La plantilla automática que se descartó

Se creó `ventana_cerrada` («le hemos escrito, conteste») y un enganche que la mandaba sola al
detectar el fallo. Se borró el mismo día sin llegar a subirse a Meta
(`Version20260812230000` la crea, `Version20260812240000` la borra), por decisión del dueño del
producto:

> Si hay que gastar una plantilla —fuera de la ventana **Meta las cobra**— más vale que sea una
> que el huésped agradezca. `enviar_guia`, `menu_tours` o `recordatorio_llegada` reabren la
> conversación exactamente igual (vale cualquier respuesta) y además le sirven de algo. Un
> «alguien quiere hablarte» cuesta lo mismo y no aporta nada.

Y cuál toca depende de para qué le escribías, que es justo lo que el sistema no sabe.

#### ✅ Lo que sí hace el sistema

`AvisoEnvioFallidoListener` → `AvisarEnvioFallidoDispatch` →
`NotificadorPushConversacion::avisarEnvioFallido()`, con el motivo y la salida:

```
No salió tu mensaje a Blandine
La ventana de 24 horas de WhatsApp ha caducado… → Mándale una plantilla
(guía, tours, llegada): en cuanto conteste, podrás escribirle normal.
```

`AvisarEnvioFallidoDispatchHandler::esPorLaVentana()` es quien distingue ese motivo de los demás
—reserva directa por Beds24, teléfono ausente— para añadir la frase sólo cuando aplica.

### 🔇 Dos silencios que se acabaron (2026-08-12)

#### Un mensaje vacío no sale

`MessageDispatcher::dispatch()` descarta el mensaje que no lleva **ni texto, ni plantilla, ni
adjunto**, lo marca `FAILED` con el motivo escrito y no crea ninguna cola.

No era hipotético: en producción hay **cuatro** mensajes así, y **tres llegaron a Beds24 con
`sent_at`** — Airbnb y Booking recibieron un mensaje en blanco nuestro. Nada lo impedía: ni la
entidad, ni el controlador, ni el encolador comprobaban que hubiera contenido.

Las tres formas de tener contenido, y basta una: texto (local o externo), plantilla —el cuerpo
se hidrata al enviar, así que aquí todavía no existe— o adjunto, que es una foto y se manda sin
una palabra. El `MessageMultipartProcessor` engancha el adjunto **antes** de persistir, así que
al llegar aquí la colección ya está poblada; una foto sin texto pasa.

#### Un envío que falla se lo dice a quien lo escribió

`AvisoEnvioFallidoListener` → `AvisarEnvioFallidoDispatch` →
`NotificadorPushConversacion::avisarEnvioFallido()`.

Antes, un mensaje que no salía se quedaba en `FAILED` en la base de datos y el operador se iba
convencido de que había llegado. El 2026-05-05 alguien le ofreció a Blandine un guía en francés;
reserva directa, así que WhatsApp era el único canal, y la ventana de 24 h estaba cerrada. No
salió, nadie se enteró, y la huésped se quedó sin respuesta.

| Detalle | Por qué |
|---|---|
| `postPersist` **y** `postUpdate` | hay dos caminos a `FAILED`: nacer fallido (el despachador no generó cola) o morir después (todas las colas fracasaron) |
| En el update se mira el **changeset** | si se mirara el estado actual, marcar leído o guardar metadata sobre un mensaje ya fallido volvería a avisar. Es el fallo que ya se pagó en `MessageAutoResponderListener` |
| Sólo `SENDER_HOST` | lo automático es cosa del motor de reglas; avisar de cada uno llena el móvil del equipo con algo que no puede arreglar |
| Va por el bus | esto corre dentro de un flush y mandar push es I/O de red |

El cuerpo del aviso lleva el motivo tal cual lo guardó el despachador —«la ventana de 24 h ha
caducado», «no se permite enviar a reservas directas por Beds24»—, que es lo único que dice qué
hacer a continuación.

### 🧭 El catálogo de tours: un enlace, no una lista

`menu_tours` llevaba el catálogo entero pegado en el cuerpo —ocho tours con precios, horarios y
entradas— y por eso no se podía subir a Meta: **3701-4228 caracteres frente a un tope de 1024**.

Desde `Version20260812210000` el cuerpo es corto (209-252 caracteres según idioma) y el detalle
vive en el catálogo de pax. Los tres canales cambian a la vez, porque los tres enseñaban la
misma lista:

| Columna | Qué lleva |
|---|---|
| `whatsapp_meta_tmpl` | cuerpo corto + botón `url` «Ver tours y precios» (`resolver_key: tours_catalog_path`) |
| `beds24_tmpl` | el mismo texto con `{{ tours_catalog_url }}` escrito en el cuerpo |
| `whatsapp_link_tmpl` | ídem, para el enlace manual desde la reserva |

En Beds24 va con `disable_meta_buttons = true` y el enlace dentro del texto, igual que
`welcome_airbnb` y `enviar_guia`: con la emulación de botones encendida el enlace saldría dos
veces.

Los `status: PENDING` de los siete idiomas se borraron. Eran texto local de una plantilla que
**nunca se envió**; sin ese campo el panel dice «SIN ENVIAR», que es la verdad, y
`hasWhatsappMetaOfficialData()` sigue en `false` hasta que Meta la apruebe de verdad.

#### 🔥 `/catalog/tours` no existía en pax

El parámetro `pax_catalog_url` apuntaba a `%env(PAX_HOST_URL)%/catalog/tours`. Las rutas de pax
son `/catalogo/:localizador` (`pax/src/router/index.ts`): esa URL caía en el comodín
`:pathMatch(.*)` y el huésped veía un «no encontrado». Iba en `tours_catalog_url` y
`tours_catalog_path`, o sea **en el botón «Ver Tours» de `welcome_airbnb`**, que lleva tiempo
mandando gente a una página muerta sin que fallara nada visible.

Ahora apunta al catálogo real, «Oferta Cusco»:

```yaml
pax_catalogo_localizador: '2W883T'
pax_catalog_url: '%env(PAX_HOST_URL)%/catalogo/%pax_catalogo_localizador%'
```

El localizador está en el parámetro porque hoy hay **un** catálogo. Cuando haya varios —«Oferta
Lima-Paracas», «Paquetes completos»— esto tiene que pasar a ser un campo de la plantilla, y el
parámetro se queda como el catálogo por defecto.

### 📏 Los topes de Meta, y por qué el menú de tours no cabe

Meta corta por componente, y no lo dice en ninguna respuesta de la API: lo descubres cuando te
lo saltas, con un «Invalid parameter | El campo Body no puede superar los 1024 caracteres»
repetido una vez por idioma y sin decir cuánto mide el tuyo.

| Componente | Tope |
|---|---|
| Body | 1024 |
| Header | 60 |
| Footer | 60 |

`WhatsappMetaTemplatePushService::medirExcesos()` lo comprueba **antes** de llamar, y dice el
número: «Body mide 3701 caracteres y el tope de Meta son 1024: sobran 2677». Se mide con
`mb_strlen` porque Meta cuenta caracteres y estos textos van llenos de emojis: contar bytes
daría un falso positivo en cuanto aparezca un 🌄.

**El caso real:** `menu_tours` mide entre **3701 y 4228** caracteres según el idioma — cuatro
veces el tope. Eso no se arregla podando frases: un catálogo de tours con precios, horarios y
entradas no cabe en una plantilla de WhatsApp y no va a caber nunca.

La salida es la que ya usa `welcome_airbnb`: **cuerpo corto + botón `url` al catálogo de pax**
(`resolver_key: tours_catalog_path`). El catálogo largo se queda donde sí cabe, en
`beds24_tmpl`, que es texto plano de OTA y no tiene ese límite — para eso las columnas están
separadas. Hoy `menu_tours` no tiene ningún botón en su JSON de Meta (`buttons_map: []`).

### 👁️ «Ver plantillas en Meta»: la pantalla que no escribe

`MessageTemplateCrudController::executeInventarioMeta()` (botón del listado de plantillas)
pregunta a la Graph API y enseña lo que Meta tiene, **sin guardar nada**.

Existe porque el botón de al lado, «Revisar estado en Meta», **escribe**: baja lo que Meta
devuelve y lo mete en local. Después de pulsarlo ya no hay nada que comparar, y lo que no
encajaba no se ve — o desapareció, o se convirtió en una fila `*_META` nueva. Esta pantalla se
queda mirando, y por eso enseña las dos anomalías que el listado normal no puede:

| Lo que marca | Qué significa |
|---|---|
| **«sin dueño aquí»** en una fila de Meta | ninguna plantilla local reclama ese nombre; el cron de las 03:15 le fabricará su gemela `*_META` cada noche mientras exista allí |
| El aviso amarillo de arriba | plantillas locales que dicen ser de Meta y Meta no conoce: su estado en el panel es texto escrito a mano, no una revisión en curso |

La tabla vive dentro de un contenedor con `max-height: 60vh; overflow: auto` y cabecera fija:
son 10 filas hoy y pueden ser 40, y no tiene por qué crecer la página.

Es de solo lectura (`Roles::MENSAJES_SHOW`), a diferencia del push.

### 🔥 Sin `meta_template_name` la plantilla es invisible en las DOS direcciones

Peor que apuntar al nombre equivocado es no tener ninguno, porque no produce un duplicado que se
vea: no produce nada. `menu_tours` estuvo así desde marzo.

Con el campo a `null`:

- **Al subir**, `WhatsappMetaTemplatePushService` lo manda como `name` del payload. Con `null`
  la plantilla nunca llega a Meta, así que **nunca entra en revisión**.
- **Al bajar**, el sincronizador empareja por ese mismo nombre. No casa con nada, y pasa de largo
  —aunque corra cada noche y toque las once restantes.

Y el panel, mientras tanto, enseña `PENDING` en los siete idiomas. Ese `PENDING` **es texto
local escrito a mano**, no un estado de Meta: parece una aprobación que tarda y es una plantilla
que jamás se envió. Costó 9 envíos fallidos entre marzo y el 2026-08-02, todos huéspedes que
pidieron el menú de tours con la ventana de 24 h cerrada.

Cómo distinguirlo de una aprobación que de verdad está en curso, sin salir de la base de datos:

```sql
SELECT code,
       JSON_EXTRACT(whatsapp_meta_tmpl, '$.meta_template_name') AS nombre_meta,
       JSON_EXTRACT(whatsapp_meta_tmpl, '$.is_official_meta')   AS lo_vio_meta,
       updated_at
FROM msg_template;
```

`is_official_meta` lo pone el sincronizador en **todo** lo que toca. En `false` —con el nombre a
`null` y un `updated_at` viejo pese al cron de las 03:15— significa que Meta no sabe que esa
plantilla existe. Correr el comando a mano no cambia nada; se comprobó.

Desde 2026-08-12 el push **se niega en voz alta** en vez de mandar `name: null`, y el sync
**avisa** cuando crea una gemela `*_META`. El nombre que le faltaba a `menu_tours` lo pone
`Version20260812190000`; subirla a Meta sigue siendo el botón del panel, a mano y a propósito.

### 🔁 Borrar el duplicado en el panel no sirve

El servicio es **create-or-update puro**: no hay una sola línea de borrado ni de desactivación.
Mientras la plantilla exista en Meta y ninguna local reclame su nombre, **cada sincronización la
vuelve a crear**. La sincronización corre por cron **a diario, a las 03:15**, así que el
duplicado reaparece esa misma noche.

Se resuelve en uno de los dos extremos, no en medio:

- **En Meta**, archivando o borrando la plantilla sobrante. Meta es la fuente de la verdad.
- **En local**, apuntando `meta_template_name` de la plantilla buena al nombre que llega, para
  que lo reclame. Ojo con lo de abajo antes de hacerlo.

### 🔥 Repuntar `meta_template_name` puede romper los envíos en silencio

Es la trampa cara, y no falla al guardar: falla al enviar, días después.

El sincronizador **preserva `resolver_key`** al actualizar un botón que ya existe —está puesto a
propósito, para no perder el cableado interno— pero **sí pisa `type` y `content`**. Si las dos
plantillas no tienen el mismo diseño de botones, queda un híbrido imposible:

| Botón | Antes | Lo que llega de Meta | Queda |
|---|---|---|---|
| 0 | `quick_reply` + `CMD_OBTENER_TOURS` | `url` → pax | `url` **+ `CMD_OBTENER_TOURS`** |
| 2 | *(no existía)* | `quick_reply` | `quick_reply` **+ `resolver_key: null`** |

Y en `WhatsappMetaSendMappingStrategy`, al enviar:

- La rama `url` hace `$variables[$resolverKey]`. Con `CMD_OBTENER_TOURS` —que es un payload de
  comando, no una variable de URL— sale **cadena vacía**: botón sin enlace.
- La rama `quick_reply` con `resolver_key` vacío **lanza `RuntimeException`** y tumba el envío
  entero, no sólo el botón.

Así que repuntar el nombre obliga a **arreglar los `resolver_key` en la misma sesión**, antes de
que corra el cron de las 03:15. Las claves en uso hoy son `guide_path`, `tours_catalog_path` y
`chat_path` (ver `welcome_airbnb`, que ya usa las dos primeras).

### Los dos diseños de botón, y qué se gana y se pierde

| | `quick_reply` + `CMD_*` | `url` + `*_path` |
|---|---|---|
| Qué hace | vuelve como mensaje entrante y el resolver dispara otra plantilla | abre pax en el navegador |
| Rastro | **queda en la conversación**: se ve quién pulsó | ninguno |
| Pasos para el huésped | dos | uno |
| Ejemplo | `welcome_booking` | `welcome_airbnb`, `enviar_guia`, `recordatorio_llegada` |

No hay uno correcto: el de comando da señal y el de URL da inmediatez. Lo que no se puede es
mezclarlos en el mismo botón, que es justo lo que produce el repunte de nombre.

### Dónde tocar

| Necesitas… | Dónde |
|---|---|
| Que deje de reaparecer un `*_META` | Archivar esa plantilla en la consola de Meta |
| Adoptar una plantilla nueva de Meta | Repuntar `meta_template_name` **y** rehacer los `resolver_key` |
| Subir a Meta la versión local | `WhatsappMetaTemplatePushService` (valida que no falte ningún `resolver_key`) |
| Saber cuándo corre la sincronización | Cron de `www-data`: `app:whatsapp:sync-templates`, 03:15 |
| Ver qué tiene Meta sin escribir nada | Botón «Ver plantillas en Meta» → `WhatsappMetaTemplateInventario::listar()` |
| Averiguar por qué una plantilla lleva meses en `PENDING` | Mirar `meta_template_name` y `is_official_meta`: si es `null`/`false`, nunca llegó a Meta |
| Que una plantilla de Meta deje de recrearse cada noche | `WhatsappMetaTemplateSyncService::NOMBRES_IGNORADOS` (y borrarla en Meta, que es lo definitivo) |
| Impedir que se guarde una plantilla de Meta sin nombre | `MessageTemplate::validarNombreDeMeta()` |
| Ajustar los topes de tamaño de Meta | `WhatsappMetaTemplatePushService::TOPE_BODY` / `TOPE_HEADER` / `TOPE_FOOTER` |
| Cambiar a qué catálogo apuntan los botones de tours | `config/services/services_parameters.yaml` → `pax_catalogo_localizador` |
| Cambiar qué cuenta como mensaje vacío | `MessageDispatcher::estaVacio()` |
| Cambiar a quién avisa un envío fallido | `NotificadorPushConversacion::avisarEnvioFallido()` / `AvisoEnvioFallidoListener` |
| Cambiar la frase que sugiere qué hacer con la ventana cerrada | `NotificadorPushConversacion::avisarEnvioFallido()` |
| Volver a encender el correo | `Version20260812220000` (`down`) **y** `email_tmpl.is_active` en cada plantilla |

---

## 19. Con quién se habla: vínculo, restricción de canal y categoría de conocimiento

Nace de una fuga real (2026-08-12). Una interesada llegó por Airbnb, aún sin confirmar, y el
agente le habló de «su reserva», le dio la dirección exacta —calle, número y referencia— y le
prometió cinco veces unas fotos que ese canal no puede enviar. Ninguna de las tres cosas fue un
error de código: el sistema creía, con la información que tenía, que estaba haciendo lo correcto.

### 19.1 Por qué falló: un solo eje para tres preguntas

`AiConversationProcessor::generar()` decidía el trato mirando **sólo** el `context_type`:

```php
$esProspecto = $delEquipo === null && 'pms_reserva' !== $conversacion->getContextType();
```

Una consulta de OTA **es** `pms_reserva`, con `status_tag = 'inquiry'`. Así que caía en el
perfil `Huesped`, que asume estancia confirmada. El dato para distinguirla se venía guardando
en `context_data.status_tag` desde hacía meses y **no lo leía nadie**.

Hoy hay tres ejes, y son independientes:

| Eje | Pregunta | Dónde vive |
|---|---|---|
| `VinculoComercial` | ¿qué relación tiene conmigo? | `Message/Contract` |
| `RestriccionCanal` | ¿qué NO sale por este tubo? | `Agent/Access` |
| `CategoriaConocimiento` | ¿de qué trata este contenido? | `Message/Contract` |

**El pago no está en el vínculo, y es a propósito.** Un cliente con saldo pendiente sigue
siendo cliente: el dinero condiciona QUÉ se le entrega (los códigos), no CON QUIÉN se habla.
Ese eje ya vive en `NivelRiesgo` y en `PmsGuiaVisibilidad`.

### 19.2 El vínculo lo declara el dominio, no el agente

`MessageContextInterface::getVinculo()` es parte del contrato. Al principio el agente traducía
`inquiry`/`confirmed`/`cancelled` por su cuenta, pero esos strings son vocabulario del PMS y
`getStatusTag()` los declara como `?string` libre: dos módulos acordando valores mágicos sin
que ningún contrato lo dijera. Un tour que emitiera `pagado` habría caído en el default
conservador y su cliente pagado sería tratado como interesado — **sin error y sin log**.

Qué convierte a alguien en cliente es conocimiento del dominio: en alojamiento lo decide el
estado de la reserva (`PmsReservaMessageContext::getVinculo()`); en un tour podría decidirlo el
pago. `VinculoComercial::deStatusTag()` sobrevive sólo como fallback para conversaciones
anteriores al campo, cuyo upsert aún no ha vuelto a correr.

`esProspecto` ya no compara contra ningún `context_type`: es «no hay vínculo».

### 19.3 La restricción es una RESTA, no un escalón

`PmsGuiaVisibilidad` es una escalera: a más confianza, más contenido. `RestriccionCanal` se
aplica **encima** y quita, sea cual sea el escalón.

Se ve con el caso real: la interesada merece MÁS que el escaparate público —cuántas
habitaciones, cuántas camas, cómo es el check-in, o se pierde la venta— y a la vez MENOS en una
dimensión concreta: la dirección, que la plataforma prohíbe antes de confirmar. Modelarlo como
un peldaño intermedio obligaba a cerrarle las camas para poder cerrarle la calle.

Por eso `PmsGuiaItem` tiene ahora **dos** campos ortogonales: `visibilidad` (cuánta confianza)
y `categoria` (de qué habla). La dirección puede seguir siendo `Publico` en la web y no salir
por una OTA sin confirmar.

### 19.4 Se corta en la fuente, no en el prompt

**Un prompt es una petición; si el dato llega al modelo, puede acabar escrito.**
`PmsGuiaArbolFiltro::podar()` recibe las categorías bloqueadas y descarta esos ítems antes de
que exista el turno. Desaparecen **sin candado**: anunciar «hay una dirección que no puedo
darte» es invitar al modelo a hablar de ella.

El aviso que sí viaja en el prompt (`RestriccionCanal::avisoParaElPrompt()`, en el bloque
volátil junto a `PerfilConversacion::enUnaLinea()`) sólo sirve para que sepa EXPLICARLO — sin
él, al no encontrar el dato, se lo inventa o promete que «el equipo te lo envía».

Cuatro skills tienen guarda de canal, y tres se descubrieron auditando las 30:

| Skill | Qué se cortó |
|---|---|
| `consultar_guia` | filtra ítems por categoría |
| `consultar_medios_pago` | **bloqueada**: devolvía titulares, Yape y cuentas |
| `consultar_mi_reserva` | elimina `guide_url`, `guide_path`, `tours_catalog_url`, `tours_catalog_path` |
| `enviarme_plantilla` | **bloqueada**: las plantillas llevan `guide_url` dentro |

Los enlaces son lo más grave: sacan la venta de la plataforma **y** la guía al otro lado lleva
dentro la dirección, el wifi y los teléfonos — un solo enlace se salta toda la resta.

`consultar_wifi` y `consultar_codigos` ya estaban a salvo (exigen ventana abierta o estancia
activa, que una inquiry nunca alcanza).

### 19.5 El hilo no es el contexto

`ActorInterface` gana `conversacionId()`, que **no** es `contextoId()` y no lo sustituye. El
contexto es la FRONTERA: lo que acota a un huésped a su propia reserva, y lo que un prospecto
no tiene a propósito. El hilo es sólo QUÉ CONVERSACIÓN está abierta.

Confundirlos dejaba a los prospectos sin escalada: `EscalarAlEquipoSkill` localizaba la
conversación por el contexto, el prospecto nace sin él, y la skill —que declara `ROLE_PROSPECTO`
justo para que un lead pueda pedir ayuda— fallaba siempre con «No sé de qué conversación
hablas», remitiendo a `localizar_conversacion`, que exige roles de equipo y ni le aparece.
**Todo lead que pidió hablar con alguien murió ahí.** Darle contexto habría arreglado el síntoma
abriéndole de paso las siete skills acotadas.

### 19.6 Quién escribe vs. de qué se habla

`PerfilConversacion` mezclaba los dos: sus prompts nombraban «casita» y «check out», así que
cada perfil nuevo nacía hotelero. Ahora el enum sólo tiene el eje de relación (los cinco casos,
`etiqueta()` y `enUnaLinea()`), y el bloque de negocio lo sirve
`InstruccionesDeDominioInterface` por `context_type`, con el mismo tag iterator que
`MessageDataResolverRegistry`. `PmsInstruccionesDominio` es hoy el único y el marcado por
defecto — quien pregunta sin contexto pregunta, por fuerza, por alojamiento.

Un módulo de tours implementa esa interfaz y hereda los cinco perfiles sin tocar el agente.

### 19.7 Lo que NO está resuelto

- **La clasificación del contenido está vacía.** La migración crea `categoria` con todo en
  `general`; hasta que se marquen los ítems, la resta no tapa nada. Pendientes al menos
  `Ubicación (general)` → `DireccionExacta` y `Horario solicitudes (general)` → `Contacto`
  (este último tiene dos teléfonos y el número de Yape, y hoy es alcanzable por una inquiry).
- **No hay guarda léxica de salida.** Todo lo anterior impide que el dato LLEGUE al modelo;
  nada impide que el modelo escriba un teléfono de su cosecha. Esa capa va en el envío.
- **`consultar_cuenta` devuelve `prepago_pendiente`** también en canal restringido. Es decisión
  de negocio: para Booking.com de pago en destino puede ser legítimo; para Airbnb la propia
  guía dice que no se pide depósito.
- **`getAgencyId()` sigue devolviendo `null`**: el B2B no está modelado, así que «cliente de
  agencia que no ve precios» todavía no se puede expresar.
- **`RestriccionCanal::deOrigenYVinculo()` asume que todo lo que no es `directo` es una OTA**, y
  `variablesBloqueadas()` nombra variables del resolver del PMS. Ambas cosas habrá que rehacerlas
  con el segundo dominio.

### 19.8 Dónde tocar para cambiar X

| Necesidad | Archivo / método |
|---|---|
| Qué hace cliente a alguien en alojamiento | `PmsReservaMessageContext::getVinculo()` |
| Añadir un vínculo nuevo | `VinculoComercial` + el `match` de `PerfilConversacion::deActor()` |
| Qué canales restringen | `RestriccionCanal::deOrigenYVinculo()` |
| Qué categorías bloquea un canal | `RestriccionCanal::categoriasBloqueadas()` |
| Qué variables del resolver no salen | `RestriccionCanal::variablesBloqueadas()` |
| El texto que lee el modelo sobre sus límites | `RestriccionCanal::avisoParaElPrompt()` |
| Clasificar un ítem de guía | Panel → ítem de guía → «De qué habla» (`PmsGuiaItemCrudController`) |
| Dónde se aplica la resta al árbol | `PmsGuiaArbolFiltro::podarItems()` |
| Los prompts de negocio de alojamiento | `PmsInstruccionesDominio` |
| Añadir un dominio nuevo (tours) | Implementar `InstruccionesDeDominioInterface` + `MessageContextInterface` |
| Probar una skill como prospecto con hilo | `app:agent:skill <skill> --como-prospecto --conversacion=<uuid>` |

### 19.9 La nota en voz del huésped: por qué el bot escribió su siguiente mensaje

Tercer fallo del mismo incidente, y el que más desconcertó. El 12-08 a las 10:59 salió por el
canal, hacia la huésped, este texto:

> `[12/08] Un favor, si le podría comentar al equipo para que me envíen foto del lavadero y de
> la ducha del baño, ya que no se logra ver esas zonas en las fotos de la plataforma. Muchas
> gracias!`

Está escrito **en su voz**, pidiéndole a ella que hable con el equipo. Un minuto después
preguntaba «¿Por qué medio me comunico con el equipo? No entendí».

**No fue el escalado ni el resumen.** El aviso de `EscalarAlEquipoSkill::redactar()` lleva 🔔 y
un enlace; el `resumen_ia` está limitado a una frase de 12 palabras sin saludos. El mensaje
llevaba `generado_por: ia`, es decir, salió del camino de respuesta normal: **lo escribió el
modelo como su turno**.

La causa está en `historial()`. Cada turno se le entrega con la fecha **dentro del texto**:

```php
'texto' => sprintf('[%s] %s', $cuando->format('d/m'), $texto)
```

Eso resolvió un fallo real y anterior —sin fecha, una cotización que el propio bot dio hace una
semana volvía a contexto como si fuera de hoy— pero tuvo un precio: al ir dentro del texto, la
marca deja de ser metadato y pasa a ser **contenido a imitar**, y va en los turnos de los dos
roles. El modelo aprendió el patrón y continuó la transcripción en vez de responderla,
redactando el siguiente turno del huésped.

El arreglo son dos capas, y sólo la segunda es una garantía:

1. Una regla en `reglasComunes()` explicando que la marca es nuestra y que no debe reproducirla
   ni continuar la transcripción.
2. `AiConversationProcessor::limpiarMarcasDeHistorial()`, que quita un `[dd/mm]` **al principio**
   del texto generado antes de encolarlo. Sólo al principio: a mitad de frase puede ser una
   fecha legítima que el huésped preguntó, y ahí el bot cita en vez de imitar.

La lección se repite en todo este capítulo: **un prompt es una petición; el cierre va en
código.** Igual que la resta por categorías no se le pide al modelo sino que se aplica en la
fuente.

Queda anotado que la raíz —fecha como contenido en lugar de como metadato— sigue ahí. Cambiar
el formato del historial arriesga el fallo de las cotizaciones viejas, así que se optó por la
guarda determinista en la salida.

### 19.10 La cuenta de un huésped cuyo canal ya le cobró

Salió tirando de otro hilo: al revisar si `prepago_pendiente` debía callarse en canal
restringido, resultó que **esa parte ya estaba bien** —`PmsPrepagoCalculador::pendiente()`
devuelve `null` en `PmsChannel::CANAL_PAGO_TOTAL` (Airbnb, VRBO) desde siempre— pero apareció
una inconsistencia mayor al lado.

`PmsReservaPaxProvider::cifras()`, que alimenta la guía web del huésped, excluye la
contabilidad espejo del canal y, si no queda nada, devuelve `soloProgreso` — la barra al 100 %
y **ni una sola cifra**. El motivo está escrito en `PmsCargoFinanciero`:

> En esos canales el importe que guardamos es lo que la OTA nos remite, no lo que el huésped
> pagó (que incluye la comisión de servicio de la OTA). Enseñárselo invita a una conversación
> incómoda sobre por qué las cifras no cuadran.

**`ConsultarCuentaSkill` no heredaba esa política.** Ni una referencia a `CANAL_PAGO_TOTAL`: le
recitaba al modelo `total_cargos`, `total_pagado`, `saldo_pendiente` y el desglose entero. Dos
superficies con los mismos datos y criterios opuestos — la web se lo oculta a propósito y el
chat se lo cantaba. Y el riesgo es peor que dar un dato de más: si nuestro saldo no cuadra con
lo que él pagó a la plataforma, parece que le estamos reclamando dinero.

Es el mismo patrón que el incidente que abre este capítulo: **una política que vive en una
superficie y que la del agente no heredó.** Conviene mirarlo como una clase de fallo, no como
dos casos sueltos.

Ahora la skill aplica la misma regla:

- Se excluye el espejo del canal por el flag `esAutomatico` —de cargos y de pagos—, sin
  adivinar por importe ni por subtipo. ⚠️ `esAutomatico` **no** significa «lo generó el
  sistema»: los cargos de reservas directas también se generan solos y llevan el flag en
  `false` a propósito, porque el huésped nos los paga a nosotros y tiene que verlos.
- Si los extras suman cero, no se devuelve un saldo `0.00` sino que **el pago está cerrado con
  la plataforma**. Un cero invita al modelo a decir «no debes nada» y de ahí a hablar de
  importes que no debe mencionar; una frase sin cifras cierra la puerta.
- Si hay extras —una cena, un traslado, una noche añadida—, se devuelven **sólo ésos**, que son
  los que el huésped reconoce y sí nos paga a nosotros.

El corte por el total y no por la lista vacía es deliberado: es el mismo de la web, y
comprobar además si la lista viene vacía habría hecho que las dos superficies dijeran cosas
distintas cuando los extras se anulan entre sí.

Afecta a 59 reservas de Airbnb en producción.

#### El invariante que blinda la regla anterior

Al comprobar la regla contra datos reales apareció el caso que la rompe. De los 16 cargos de
reservas de Airbnb, 14 están marcados como espejo del canal y **2 no lo están**: un
«Alojamiento» de 130.00 y un «Suplemento de limpieza» de 15.00, de una reserva que llega el
2026-09-11 y por tanto está viva.

Con el flag mal puesto, el filtro los toma por extras y el mensaje **los afirma**: dice que lo
devuelto son «extras que se pagan a nosotros». Es decir, le reclamaría 145.00 a un huésped que
ya se los pagó a Airbnb. Peor que no haber hecho nada, porque antes se daba una cifra y ahora
se le pone una etiqueta que asegura de quién es el dinero.

⚠️ **La web tiene exactamente el mismo fallo de datos** —usa el mismo flag— y hoy le enseña esos
145.00 a ese huésped. Corregirlo allí, y corregir el dato, son tareas aparte.

`ConsultarCuentaSkill` aplica ahora un invariante duro: **en un canal que ya cobró, el
alojamiento nunca es nuestro**. Si un cargo de tipo `alojamiento` sobrevive al filtro, el dato
se contradice con el canal, y ante la contradicción no se elige un lado: se cae al caso sin
cifras —el único que no puede mentir— y se registra un `warning` con la reserva para que
alguien arregle el flag.

Es el mismo criterio de todo este capítulo: donde el coste de equivocarse lo paga el huésped,
la salvaguarda va en código y falla hacia el lado callado.

### 19.11 Corregir el teléfono levanta el bloqueo de WhatsApp

`whatsappDisabled` lo pone `WhatsappMetaReceivePersister` cuando Meta rechaza un envío —típico
`Meta Error 131026: Message undeliverable`, un número que no tiene WhatsApp o está mal escrito—
y hasta ahora **nadie lo quitaba nunca**: no había un solo `setWhatsappDisabled(false)` en todo
el proyecto. La conversación quedaba muerta para WhatsApp de forma permanente, y corregir el
número no servía porque `WhatsappMetaSendEnqueuer` mira el flag y aborta antes de intentarlo.

Lo que despistaba del síntoma es que **la re-evaluación de reglas sí funcionaba**. `guestPhone`
está en los campos críticos de `MessageRuleEngineListener` desde que se documentó que «rellenar
el teléfono que faltaba tiene que poder resucitar los mensajes que se cancelaron por no
tenerlo», y la cadena entera está bien montada:

```
editar telefono_contacto en la reserva
  → PmsReservaRecalculoListener (caso 1: PmsReserva)
  → PmsReservaRecalculoService → upsertFromContext()
  → MessageConversation::setGuestPhone()
  → MessageRuleEngineListener ve `guestPhone` en el changeset
  → re-evaluacion de reglas ✅
```

Las reglas se recalculaban bien. Lo que no se levantaba era el veto, y el veto gana.

El arreglo va en `MessageConversation::setGuestPhone()` y no en un listener nuevo, para que
cubra cualquier ruta que toque el teléfono —panel, sync de Beds24, edición manual— sin depender
del orden de los listeners. El bloqueo era sobre el número ANTIGUO; con uno nuevo hay que
volver a intentarlo, y si también es malo Meta lo rechazará otra vez y el flag se repondrá solo.

⚠️ **Sólo cuando el valor cambia de verdad.** `upsertFromContext()` corre en cada mensaje
entrante de Beds24 y reescribe el teléfono con el mismo valor: sin esa comparación, cualquier
mensaje entrante desbloquearía un número legítimamente vetado.

Como `whatsappDisabled` también está en los campos críticos del motor de reglas, levantarlo
dispara por sí solo una re-evaluación en el mismo flush.

En producción hay 2 conversaciones bloqueadas, una de ellas abierta.

### 19.12 El contexto volátil también lo pone el dominio

Segundo paso de la deshardcodización, después de sacar los prompts de perfil del enum (§19.6).

`AiConversationProcessor` construía él mismo el bloque volátil del prompt —«SE VA HOY a las
10:00», «ANTES: ese día sale otro huésped»— y para ello importaba `PmsReserva`, inyectaba
`PmsEspacioEstancia` y comparaba `context_type` contra `'pms_reserva'` a pelo. Tres
acoplamientos al PMS en el corazón del agente, justo donde peor sientan: el primer módulo de
tours habría tenido que tocar esta clase para decir «el tour sale mañana a las 6:00».

`InstruccionesDeDominioInterface` gana `contextoVolatil(MessageConversation)`, y
`PmsInstruccionesDominio` se queda con `faseDeLaEstancia()`, `espacioAlrededor()` y su helper
de fechas. **El texto se movió tal cual**, sin reescribir: son prompts en producción.

Detalle de la resolución: se hace por el `context_type` de la CONVERSACIÓN y no por el del
actor. Son el mismo valor en el chat, pero el actor de un prospecto nace sin contexto a
propósito, y aquí lo que se pregunta es de qué negocio va el hilo, no a qué está atado quien
escribe.

Resultado: `grep 'App\Pms\' src/Agent/Service/AiConversationProcessor.php` devuelve **cero**.

Lo que sigue acoplado al PMS dentro de `src/Agent/`: sólo las 30 skills de `Skill/Pms/` —que
son PMS por definición— y `Command/AgentReplayCommand.php`, una herramienta de depuración.
Ver §19.13.

### 19.13 El índice de temas del triaje también lo pone el dominio

Tercer y último corte al núcleo, después de los prompts de perfil (§19.6) y del contexto
volátil (§19.12).

`Triaje` construía la lista blanca de temas cruzando a mano el mapa `porUnidad` que le devolvía
`IndiceDeGuia`, añadía «Su casita: …» al contexto y comprobaba con un literal si el actor tenía
`consultar_guia`. Cuatro conceptos de alojamiento —unidades, casitas, el nombre de una skill del
PMS y la forma del mapa— dentro del clasificador de mensajes, que no debería saber de qué
negocio se habla.

`IndiceDeTemasInterface` los esconde todos detrás de cuatro métodos:

| Método | Qué devuelve |
|---|---|
| `skillQueLoHabilita()` | el nombre de la skill; el triaje ya no escribe ninguno |
| `bloqueParaElPrompt()` | texto **estable entre conversaciones**: va en el prefijo cacheado |
| `temasPermitidos(actor)` | la lista blanca ya cruzada; `porUnidad` no sale del dominio |
| `lineaVolatil(actor)` | «Su casita: …», lo único que cambia por conversación |

`IndiceDeGuia` pasó a ser `App\Pms\Service\Agent\PmsIndiceDeTemas`. La lógica del índice no
se tocó: sigue siendo global y determinista, con su orden estable por etiqueta y uuid, porque de
eso depende que el caché del prompt acierte.

⚠️ El registro de índices **no tiene dominio por defecto**, al contrario que el de
instrucciones. Un prospecto sin contexto necesita el prompt de venta de algún negocio —de ahí
aquel default—, pero no tiene temas que consultar: sin índice, el triaje no ofrece elegir tema,
que es lo correcto y no una degradación.

**Resultado del conjunto:** `Service`, `Conversation`, `Triage` y `Access` no importan una sola
clase de `App\Pms\`. Lo único que queda dentro de `src/Agent/` son las 30 skills de
`Skill/Pms/` —PMS por definición— y `AgentReplayCommand`, una herramienta de depuración.

### 19.14 Frentes: de qué asunto se está hablando

`InstruccionesDeDominioInterface` e `IndiceDeTemasInterface` resuelven **de qué NEGOCIO** va un
hilo, mirando el `context_type` de la conversación. No basta, y el caso que lo demuestra no es
exótico:

> Un huésped alojado escribe preguntando si puede quedarse dos noches más. Tiene una estancia en
> curso (operación) y está comprando (venta), **las dos cosas en alojamiento y a la vez**.

Con dos asuntos vivos del mismo negocio, `VinculoComercial` dice «cliente» y no distingue nada:
alguien tiene que decidir de cuál se habla, y ese alguien sólo puede ser quien lee el mensaje.
El **frente** es esa unidad.

| Concepto | Cuántos | Quién lo decide | Para qué sirve |
|---|---|---|---|
| `ActorInterface::dominios()` | varios | los datos (anclas vivas ∪ negocios vendibles) | qué skills se le enseñan |
| `Frente` | uno por turno | el triaje, leyendo el mensaje | la voz, los hitos, de qué se habla |

**El frente responde «¿de qué me hablas?»; el rol responde «¿qué te dejo hacer?».** Son
preguntas distintas, y por eso conviven las dos respuestas.

#### La venta es una puerta que nunca se cierra

Además de los asuntos con entidad detrás, cada negocio vendible aporta un frente de venta
**sintético**, sin entidad, presente en la lista de todo el mundo. Con eso, dos casos que
parecían excepciones pasan a ser la misma regla:

- un cliente de tours que escribe «no tengo alojamiento, ¿qué ofreces?»
- un huésped alojado que quiere ampliar

Los dos eligen el frente de venta del negocio por el que preguntan. Y el prospecto sin nada deja
de ser un caso aparte: simplemente ve sólo las puertas.

#### Dos vacíos que dicen «no acotes»

⚠️ La regla más importante del andamiaje, y es contraintuitiva a propósito:

- una skill **sin** `SkillDominioInterface` es transversal;
- un actor con `dominios()` **vacío** es uno que todavía nadie ha poblado, y se comporta como
  antes de que existieran los dominios.

La alternativa —vacío = ningún negocio— convierte cualquier olvido en un actor mudo: sin una
sola skill de negocio, el agente contesta que no puede ayudar y **no hay error en ningún log que
lo delate**. Se acepta que un olvido quede permisivo porque el dominio **no es un permiso**: lo
que se puede hacer lo siguen decidiendo los roles, y una herramienta de más en un catálogo no
abre datos de nadie.

#### Qué hay construido y qué no

| Pieza | Estado |
|---|---|
| `Frente`, `MomentoDeFrente`, `FrentesPorDominioInterface` | hechos, en `src/Message/Contract/` |
| `EnumeradorDeFrentes` (lista, defecto, bloque del prompt, lista blanca de ids) | hecho, `src/Message/Service/` |
| `PmsFrentes` — envuelve `findVivasByTelefono()`, no reinventa su desempate | hecho |
| `SkillDominioInterface` + filtro en `SkillRegistry::paraActor()` | hecho, y **29 de las 30 skills declaran `['hotelero']`** |
| `ActorInterface::dominios()`, poblado por `AgentActorFactory` | hecho: `dominiosPara(contextoTipo)` = lo vendible ∪ el negocio del contexto |
| Que el triaje elija frente (`tipo: aclaracion`, campo `frente`) | **pendiente** |
| `resolveConversation()` sobre `frentesVivos()` | **pendiente** |
| Frentes de turismo | **pendiente**: `CotizacionFile` no tiene repositorio con búsqueda por teléfono |

El id de un frente es un hash opaco de negocio+momento+entidad —estable entre turnos, para que
una pregunta de desambiguación de ayer siga siendo mapeable hoy, y sin uuids dentro para que
enseñárselo al modelo no filtre nada—.

#### El catálogo, medido

`app:agent:permisos` **no sirve** para comprobar esto: construye los actores con `AgentActor::`
a secas, sin dominios, así que nunca ejerce el filtro. Se comprueba con
`php var/probar-dominios.php`, que los arma por la factoría, como nacen en producción:

| Actor | Dominios | Skills |
|---|---|---|
| huésped (`pms_reserva`) | `hotelero` | 10 — **las mismas de antes** |
| prospecto (sin contexto) | `hotelero` | 4 — **las mismas de antes** |
| pasajero de turismo (simulado) | `turistico` | 1 |

Las dos primeras filas son la prueba de que no se rompió nada; la tercera, de que el mecanismo
hace algo — sin ella, el filtro podría estar inerte y nadie se enteraría.

Hoy, con un solo negocio registrado, **no cambia ningún comportamiento**: todo actor real cae en
`hotelero` y ve exactamente lo que veía.

#### ⚠️ El filtro todavía no atrapa nada, y hay que saberlo

`dominiosPara()` devuelve **los negocios vendibles ∪ el del contexto**, y el alojamiento es
vendible. O sea que **todo actor real lleva `hotelero`** y la intersección pasa siempre. El día
que exista turismo, un pasajero llevará `['hotelero','turistico']` y **seguirá recibiendo el
catálogo hotelero de operación** —`consultar_mi_reserva`, `consultar_wifi`…—, que morirá con
«esta conversación no está asociada a ninguna reserva».

La causa de fondo: las skills se etiquetan sólo por NEGOCIO, sin el eje venta/operación. Es
deliberado —etiquetarlas por momento dejaría fuera al huésped que quiere ampliar—, pero deja la
tensión abierta: incluir lo vendible en el actor arrastra las skills de **operación** de ese
negocio.

Se resuelve cuando los asuntos de cada negocio sean **entidades propias** y `dominios()` se
derive de ellas en vez de «lo vendible». Hasta entonces, la fila del pasajero de
`probar-dominios.php` es una simulación: se arma a mano con `dominios: ['turistico']`, una
combinación **que la factoría nunca produce**. Prueba la mecánica del registro, no el camino de
producción. No construyas encima dando por hecho que el recorte ya funciona.

#### Dos fuentes para los frentes del PMS, y no es duplicación

`PmsFrentes::frentesVivos()` llama a **dos** consultas y cada una fija el momento:

| Consulta | Estados | Momento | Para qué |
|---|---|---|---|
| `findVivasByTelefono()` | `IDENTIFICAN_HUESPED` | `Operacion` | **de quién es un número** — frontera de autorización |
| `findConsultasAbiertasByTelefono()` | `abierto` | `Venta` | qué se le está vendiendo |

Están separadas a propósito: mezclarlas en una consulta con más estados convertiría un `inquiry`
de Airbnb en un huésped identificado, que es el incidente que motivó `VinculoComercial`.

⚠️ La segunda **nació de un fallo mudo**: sin ella, `momentoDe()` preguntaba «¿esto es una
consulta?» a una lista que por construcción nunca contiene `abierto`, así que la respuesta era
siempre «no» y **`MomentoDeFrente::Venta` con entidad era código inalcanzable**. El caso que
motivó el modelo entero —huésped alojado con una consulta de ampliación pendiente— no podía
producirse, y el test lo daba por bueno porque usaba dobles que se saltan `PmsFrentes`. Si
alguna vez tocas esto, comprueba el camino real con `var/probar-frentes.php`, no sólo la suite.

---

## 20. La cirugía: separar la persona del asunto

Estado: **el motor ya sabe programar por asunto (§20.5); los enlaces siguen sin poblarse.**
Las tablas existen, `maestro_contacto` está poblada, y `MessageRuleEngine` lee los enlaces si
los hay — mientras `pms_conversacion_enlace` esté vacía, todo se comporta como siempre.

### 20.1 El diagnóstico, con números

`msg_conversation` intenta ser dos cosas a la vez, y tiene **dos creadores con claves de
identidad distintas** escribiendo en ella:

| Creador | Identidad | Resultado |
|---|---|---|
| `WhatsappMetaReceivePersister::resolveConversation()` | `guestPhone` + `status = OPEN` | un hilo por **persona**, y sólo mientras esté abierto |
| `MessageConversationFactory` | `(contextType, contextId)` | un hilo por **activo** — ni mira el teléfono |

No pueden coincidir. Medido en producción:

- **20 teléfonos con más de una conversación**, 50 conversaciones entre ellos.
- No son cáscaras vacías: `51921166466` tiene **247 mensajes repartidos en 2 hilos**;
  `51958191965`, 122 en 4; otros seis pasan de 40.

Cuando el agente atiende a esa persona, toma **la más reciente entre las abiertas** y el
historial de los otros hilos no existe para él.

⚠️ **Dato corregido por el dueño (2026-08-12).** El «titular con 7 reservas creadas el mismo
día» que esta sección usaba como ejemplo era FALSO: esas 7 filas de `pms_reserva` son basura de
un bug al guardar —están canceladas, y lo correcto era UNA reserva con 4 eventos—. Se contaron
filas y se sacó una conclusión de negocio que no se sostiene. Lo que la basura sí enseña es
otra cosa: **la base trae asuntos podridos** (canceladas duplicadas, y 2 conversaciones
apuntando a reservas BORRADAS — `context_id` es texto sin integridad referencial), y el motor
tiene que atravesarlos sin reventar ni encolar de más. Ver §20.5.

⚠️ **No basta con unificar por teléfono.** La identidad por activo no es un capricho:
`MessageRuleEngine` leía `$conversation->getContextMilestones()` para programar los envíos y
filtraba las reglas por el `contextType` **de la conversación**. Una conversación por reserva
era lo que le daba a cada estancia su propia agenda. Fusionar hilos sin enseñarle al motor a
programar por asunto dejaba a un titular con N reservas con una sola agenda de envíos — resuelto
en §20.5.

### 20.2 El reparto

| Se queda en la conversación | Se muda a `MaestroContacto` | Se muda al enlace del módulo |
|---|---|---|
| `status`, `unreadCount` | `guestName`, `guestPhone` | `contextType`, `contextId` |
| `resumenIa`, `resumenIaHasta` | `idioma`, `idiomaFijado` | `contextData['vinculo']` |
| `lastMessageAt`, `lastInboundAt` | | `contextData['milestones']` |
| `messages` | | `['origin']`, `['agency']`, `['status_tag']` |

`whatsappDisabled` y `whatsappSessionValidUntil` se quedan **de momento** en la conversación:
son del NÚMERO, no del hilo ni del activo, y su sitio correcto sería una entidad por canal. No
bloquean nada y moverlos después es barato.

`contextData['financials']` e `['items']` tampoco se mudan: son una foto de la cuenta que usa el
motor de plantillas, y su sitio natural es el activo (`PmsInformacionFinanciera`), no una copia
más. Se decide al conectar el motor.

### 20.3 Lo construido

| Pieza | Qué es |
|---|---|
| `App\Entity\Maestro\MaestroContacto` | la **persona**. En `Maestro` porque no es de ningún módulo: el mismo señor reserva una casita, contrata un tour y escribe por WhatsApp |
| `App\Message\Contract\ConversacionEnlaceInterface` | el contrato de los asuntos: `MessageContextInterface` **persistido** |
| `App\Pms\Entity\PmsConversacionEnlace` | primera implementación, con FK real a `PmsReserva` |
| `MessageConversation::$enlacesPms` | la colección uno-a-muchos. La lee el motor vía `getEnlaces()` (§20.5) |
| `app:contactos:poblar` | crea los contactos desde las tres fuentes |

**Una tabla por módulo, no una polimórfica.** Doctrine sabe hacer herencia de tabla única, pero
mete a los tres módulos en una tabla con las columnas de todos — y con eso `Cotizacion` volvería
a depender del esquema del PMS, que es justo la dependencia que se está quitando. Lo que los une
es el contrato, no una tabla.

**El teléfono del contacto no es único, a propósito.** Un matrimonio comparte número y una
agencia usa el suyo para los grupos que manda: son personas distintas, no duplicados. Y la tabla
se puebla desde columnas sucias —números de OTA con el código de país repetido— que harían
fallar una restricción de unicidad por un puñado de filas.

### 20.4 El poblado, y lo que confirma

`app:contactos:poblar` va de la fuente más rica a la más pobre y **el primero que entra manda**:
completar huecos entre fuentes mezclaría personas distintas en cuanto dos compartan número.
Deduplica por los **últimos 8 dígitos del número normalizado con `PhoneSanitizer`** — el mismo
criterio que ya usa `PmsReservaRepository`.

⚠️ Por eso es un comando y no una migración: en SQL habría que reescribir la normalización con
`REGEXP_REPLACE` y serían dos criterios condenados a separarse. Además los ids son UUID v7, que
MySQL no genera.

Resultado de la primera pasada:

| Fuente | Filas | Contactos nuevos |
|---|---|---|
| `pms_reserva` | 285 | 260 |
| `cotizacion_file` | 3 | 3 |
| `msg_conversation` | 298 | **6** |

**Esos 6 son la prueba del diagnóstico**: de 298 conversaciones, 292 son con personas que ya
existían como clientes de una reserva. La duplicación era real, y el contacto la colapsa.

Es idempotente y **no toca ni una columna de los módulos de origen**: no reescribe
`pms_reserva`, no borra, no enlaza. Se puede correr en producción sin ventana de mantenimiento.

### 20.5 El motor programa por ASUNTO (paso 3, hecho)

`MessageRuleEngine` ya no programa contra la conversación sino contra **agendas**
(`AgendaDeAsunto`, en `src/Message/Service/Queue/`), una por asunto:

```
 syncConversationRules(conversación, trigger, force)
        │
        ├─ healZombieMessages()                        igual que siempre, por conversación
        ├─ agendasDe()          ─── ¿tiene enlaces? ───┐
        │        │ no                                  │ sí
        │        ▼                                     ▼
        │   1 agenda LEGADA                       N agendas, una por enlace
        │   (contextData de la conversación)      (milestones/origen/agencia DEL ENLACE)
        │
        ├─ cancelarPendientesDeAsuntosRetirados()      sólo con panorama completo
        └─ por agenda: evaluarReglasDeAgenda()         aislada en try/catch
                └─ por regla: calculateRunAt() + ruleAppliesToAgenda()
                              → cancelar / sincronizar / crear (con asunto estampado)
```

**Compatibilidad hacia atrás, sin ventana.** Una conversación sin enlaces produce una única
agenda legada con los mismos datos de siempre y la MISMA barrera de estado de §4.2: el
comportamiento es idéntico byte a byte. Se puede desplegar con `pms_conversacion_enlace` vacía
y no cambia nada; los enlaces van encendiendo el modo nuevo hilo a hilo cuando el paso 2 los
puble.

**Identidad e idempotencia por asunto.** Cada mensaje del sistema queda estampado con su par
`(asunto_type, asunto_id)` (§4.3). Los mensajes de la era anterior (sin estampar) se reconocen
por deducción —pertenecen al asunto propio de su conversación— y se **adoptan** al primer
toque, de modo que estrenar un enlace cuyo par coincide con el de la conversación NO duplica lo
ya encolado. Duplicar aquí es escribirle dos veces al huésped; hay test que lo clava.

**La muerte de un asunto es categórica** — regla del dueño del producto: *«cuando regenere, el
engine que no se muera»*. En modo por-asunto, un enlace cancelado (hito `cancelled_at`) o con
vínculo `Terminado` no encola **ni un mensaje** (`AgendaDeAsunto::estaMuerta()`), y lo suyo
pendiente se cancela. Consecuencia asumida: **el aviso de cancelación por asunto queda mudo**
—en modo legado sigue funcionando como en §4.2—. El motivo es la basura medida (canceladas
duplicadas por el bug de guardado): regenerar sobre ellas habría sido un envío real por cada
duplicado. Recuperar el aviso por asunto exige una guarda nueva y es una decisión de producto
pendiente.

**Los asuntos podridos se saltan, no tumban el barrido.** Un enlace que no resuelve a nada
(sin activo detrás, o cuyo proxy revienta al cargar) se registra en el log y se salta;
`evaluarReglasDeAgenda()` va además aislada por asunto en try/catch, para que uno malo no deje
a los demás sin sus recordatorios. Y con el panorama incompleto la barrida de huérfanos NO
corre: no se puede distinguir «asunto retirado» de «asunto que hoy no cargó», y cancelar sobre
una foto rota apagaría mensajes legítimos.

**Asunto nuevo = enlace sin flushear.** El rescate de última hora y las reglas `CREATED`
aceptan, además de `INSERT` y la reparación manual, un enlace con `createdAt === null` (nació
en esta misma unidad de trabajo). Es la señal que sustituye al `INSERT` cuando los hilos estén
fusionados y una reserva nueva llegue como `UPDATE` de una conversación vieja. Un poblado
masivo nunca la activa, porque flushea antes de que el motor corra — por eso la señal es
«null», y no «creado hace poco».

**Gotcha pendiente de cableado:** `MessageRuleEngineListener::CAMPOS_CRITICOS` vigila columnas
de la CONVERSACIÓN; tocar un enlace no dispara el motor. Mientras el flujo que refresque
enlaces sea el recálculo del PMS (que llama al motor explícitamente) no importa, pero si algo
más escribe enlaces, tiene que disparar el motor él mismo o los cambios no reprograman nada.

### 20.6 Lo que falta

1. ✅ **Enganchar reservas y expedientes a su contacto** — `pms_reserva.contacto_id` y
   `cotizacion_file.contacto_id`, nullable y `ON DELETE SET NULL`. Los engancha la segunda
   pasada de `app:contactos:poblar`, con **la misma clave de teléfono** que usa para
   deduplicar: separarlo en otro comando habría significado escribir esa regla dos veces.
   Resultado: 285 de 285 reservas y 3 de 3 expedientes, ninguno sin cruzar.
   *Falta la FK en `MessageConversation`, que espera al paso 3 para no chocar con él.*
2. ✅ **Poblar `pms_conversacion_enlace`** — `app:conversaciones:enlazar`, **204 enlaces**.
   Idempotente y aditivo: no toca las columnas viejas.
   ⚠️ Falta decidir **quién refresca el enlace cuando la reserva cambia**: hoy
   `PmsReservaRecalculoService` reescribe el `contextData` de la conversación, y con enlaces
   tiene que reescribir también el enlace —hitos, vínculo, `status_tag`— en el mismo
   movimiento, o el motor programará con fechas viejas.
3. ✅ **El motor programa por ASUNTO** — §20.5. Las columnas viejas siguen siendo la verdad
   para las conversaciones sin enlaces, así que se despliega sin que nada cambie.
4. **Fusionar los hilos duplicados por contacto**, con su historial. Los mensajes viajan con su
   asunto estampado; **los que no lo tengan hay que adoptarlos ANTES de mover**, porque la
   deducción usa el par de contexto de la conversación de origen. Éste es el paso que hay que
   medir.
5. **Retirar `contextType`/`contextId`/`contextData`** de la conversación. Antes, TODOS los
   mensajes vivos tienen que estar estampados: la adopción es perezosa y un barrido con
   `app:message:sync-rules --all` la completa.
6. **Que los hitos nuevos lleguen al motor** (§20.7). Ya están **guardados** en
   `pms_conversacion_enlace.hitos` y al día en cada cambio de la reserva; falta que
   `MessageRule` pueda apuntar a ellos y que el motor programe **una ocurrencia por hito** y no
   una por tipo — dos escapadas son dos mensajes de salida temporal.
7. **Decisión de producto pendiente**: si vuelve el aviso de cancelación por asunto, y con qué
   guarda contra las canceladas duplicadas (§20.6 explica por qué es delicado: un tercio de las
   conversaciones cuelga de una reserva cancelada).

### 20.6 Lo que la base arrastra, y que el motor tiene que atravesar sin morirse

Poblar los enlaces obligó a mirar los datos de verdad, y salieron dos cosas que **ya estaban
rotas** y que hoy no se notan porque una conversación es un asunto:

| Qué | Cuántas | Por qué importa mañana |
|---|---|---|
| Conversaciones que apuntan a una reserva **CANCELADA** | **106 de 310** | Al programar por asunto, el motor las encontraría vivas y regeneraría mensajes por cada una |
| Conversaciones que apuntan a una reserva **BORRADA** | 2 | `context_id` es un string sin integridad referencial; no resuelven a nada |

Un tercio de las conversaciones cuelga de algo cancelado. Entre ellas hay **reservas duplicadas
nacidas de un bug de guardado** —siete filas donde debía haber una con cuatro eventos—, que
quedaron canceladas y siguen ahí.

Por eso `app:conversaciones:enlazar` **no enlaza reservas canceladas**: el enlace es justo la
puerta por la que esa basura entraría al motor. Y por eso la regla para el paso 3 es explícita:

> **Un asunto cancelado no regenera nada, y un asunto que no resuelve no puede tumbar a los
> demás.** Hoy, si una conversación es basura, falla sólo lo suyo. Mañana, un asunto malo
> conviviendo con seis buenos bajo el mismo hilo puede dejar a los seis sin sus mensajes. Hay
> que aislar por enlace.

⚠️ **Corrección de una lectura anterior de este doc.** Aquí se afirmaba que un contacto con «7
reservas» era la prueba del modelo. Era falso: son 7 filas de un bug de guardado, canceladas, y
lo correcto habría sido una reserva con cuatro eventos. Contar filas no es contar reservas. Lo
que sí está medido y sigue en pie es la fragmentación del historial: 20 teléfonos con más de una
conversación, uno con 247 mensajes repartidos en dos hilos y otro con 122 en cuatro.
4. Fusionar los hilos duplicados por contacto, con su historial.
5. Retirar `contextType`/`contextId`/`contextData` de la conversación.

Los pasos 1 y 2 se pueden desplegar sin que nada cambie. El 3 es el que hay que medir.

### 20.7 Los hitos que siempre faltaron: salida temporal, reingreso y cambio de casita

Los hitos de hoy resumen la estancia en dos fechas, `start` y `end`, y las dos salen de
`PmsReserva::getFechaLlegada()`/`getFechaSalida()` — **el mínimo y el máximo de todos los
tramos**. Es el mismo fallo de agregados que ya apareció en la etiqueta del frente, pero aquí
cuesta mensajes:

```
  Casita 1   10 → 12        se va a Machu Picchu
  Casita 3   15 → 18        vuelve, y además a otra casita

  lo que ve la mensajería:  start = 10,  end = 18
```

Bienvenida el 10, instrucciones de salida el 18. **El 12 se va sin que nadie le diga cómo dejar
la casita; el 15 vuelve sin que nadie le diga que ahora es la 3.**

`PmsHitosDeEstancia::para()` los deriva de los TRAMOS y devuelve una **lista** —no un mapa—
porque una estancia larga puede tener varias escapadas y un mapa `clave => fecha` sólo admite
una de cada. Tipos nuevos en `ConversationMilestoneInterface`: `TEMPORARY_END`, `REENTRY`,
`UNIT_CHANGE`.

#### ⚠️ La trampa: dos casitas A LA VEZ no son un cambio

El caso más común de reserva multitramo **no** es la estancia partida: es el grupo que ocupa la
Casita 2 y la Casita 5 las mismas noches. Comparar tramos consecutivos sin más habría anunciado
un «cambio de casita» a cada familia que reserva dos.

Por eso los tramos se agrupan primero en **bloques por solape temporal** —un bloque es «dónde
está el huésped durante este rato», tenga una casita o cuatro— y los hitos salen de comparar
bloques. Lo que separa bloques es el tiempo, no la unidad.

Esa agrupación no es teórica: una consulta SQL que comparaba tramos sin agrupar decía que había
**20** reservas con hueco. Al derivarlas de verdad son **5**; las otras 15 eran casitas
simultáneas. La consulta ingenua se habría equivocado en 15 de 20.

#### Contra datos reales (25 reservas multitramo)

| Hito | Reservas |
|---|---|
| `temporary_end` + `reentry` | 5 |
| `unit_change` | 7 |

```
Karina      start 27/03 Casita 7 · temporary_end 02/04 · reentry 03/04 · end 08/04
Jean        start 17/04 Casita 2 · unit_change 18/04 → Casita 7 · end 20/04
Giulianna   start 03/04 Casita 4 · unit_change 04/04 → Casita 2 · end 07/04
```

Doce personas que se fueron y volvieron, o que cambiaron de casita, sin un solo mensaje.

#### Lo que falta para que salgan mensajes

La derivación está hecha y probada; **no la consume nadie todavía**. Falta:

1. Exponerla en `ConversacionEnlaceInterface` —el asunto es quien tiene los hitos— sin romper
   `getMilestones()`, que sigue siendo lo que el motor lee hoy.
2. Que `MessageRule` pueda apuntar a los tipos nuevos (el desplegable del CRUD sale de las
   constantes).
3. Que el motor sepa programar **una ocurrencia por hito** y no una por tipo: dos escapadas son
   dos mensajes de salida temporal, no uno.

El 3 depende del trabajo de programar por asunto y va con él.

### 20.8 Tres agujeros que el modelo nuevo dejó abiertos, y cómo se cerraron

Al revisar el flujo completo aparecieron tres sitios donde **el modelo nuevo existía y el código
seguía leyendo el viejo**. Los tres eran del mismo tipo, y dos habrían fallado en silencio.

#### Nadie creaba ni refrescaba enlaces en caliente

`MessageConversationFactory::upsertFromContext()` —por donde pasa **cada** cambio de reserva, vía
`PmsReservaRecalculoService`— no sabía de enlaces. Consecuencias: una reserva nueva nacía sin
enlace y se quedaba en el camino legado para siempre, y —peor— mover las fechas de una reserva
que **sí** tenía enlace dejaba la conversación al día y el enlace con los hitos viejos. Como el
motor en modo enlace lee el enlace, habría programado con fechas caducas. Con 204 enlaces
poblados, ese riesgo estaba vivo.

Lo cierra `SincronizadorDeEnlaceInterface` (tag `app.message.sincronizador_enlace`), que la
factoría recorre al final del upsert. `PmsSincronizadorDeEnlace` crea o actualiza el enlace, y
**retira el enlace si la reserva se cancela** — la barrera en el origen, además de la del motor.

⚠️ Es un contrato y no un `if` en la factoría porque ésta vive en `src/Message` y el enlace en
`src/Pms`: escribirlo allí ataría la mensajería al esquema del PMS.

#### Los hitos se derivan, no se copian

El poblado inicial copiaba `milestones` del JSON de la conversación. El sincronizador los
**deriva de los tramos** con `PmsHitosDeEstancia`, así que un evento que se mueve, se añade o
**se borra** cambia los hitos en el acto: aparece o desaparece la salida temporal, el reingreso o
el cambio de casita. Copiarlos habría heredado el defecto de origen —`start`/`end` son el mínimo
y el máximo— justo cuando el objetivo es dejar de aplastar la estancia en dos fechas.

`PmsConversacionEnlace::setHitos()` mantiene además el mapa plano en sintonía (primer `start`,
último `end`) para que las reglas de hoy sigan encontrando lo que esperan. **Una sola derivación
alimenta los dos**, para no tener dos verdades sobre las mismas fechas.

En la base real: 202 de 204 enlaces con hitos, **12 con intermedios**.

#### La hidratación tomaba el asunto equivocado

```php
// antes, en las dos estrategias de envío
$variables = $resolver->getMessageVariables($conversation->getContextId());
```

Las variables salían del `contextId` de la **conversación**, no del asunto del mensaje —que ya
viaja estampado en `asunto_type`/`asunto_id`—. Hoy coinciden siempre; **dejan de coincidir en
cuanto se fusionen los hilos**, y entonces el recordatorio de la reserva B se redactaría con las
fechas, la casita y el nombre de la A. Sin error: un mensaje impecable con los datos de otro.

Ahora ambas estrategias resuelven `asuntoType`/`asuntoId` del mensaje y sólo caen al contexto de
la conversación si el mensaje no lo lleva.

#### Lo que NO había que tocar

`WhatsappMetaSendEnqueuer` manda a `$conversation->getGuestPhone()`. Eso es **la persona**, que
bajo el modelo nuevo es justo lo que la conversación representa: correcto tal cual, y de hecho
confirma el diseño.

### 20.9 Lo que se rompió al arreglar, y cómo se pescó

La revisión de los tres arreglos de §20.8 encontró que **dos de los tres estaban mal**, y uno
habría tumbado el envío entero. Se anota aquí porque el patrón se repite y conviene reconocerlo.

#### El fatal: una variable mal escrita, y ninguna red debajo

Las dos estrategias de envío usan `$msg` para el mensaje del lote. El arreglo escribió
`$message`, que no existe en ese ámbito: `Error: Call to a member function getAsuntoType() on
null` **en el primer mensaje saliente**, por WhatsApp y por Beds24.

⚠️ **Lo que no lo pescó, y por qué:** `php -l` no ve variables indefinidas; los tests no tocan
las estrategias —no existe nada bajo `tests/Message/Service/Exchange/`—; y las dos
comprobaciones contra la base real (`sync-rules --dry-run`, `probar-regeneracion.php`) ejercitan
el **motor de reglas**, nunca el mapeo de envío. El agujero de verificación era exacto: el único
código que se tocó sin una red debajo fue el que quedó roto.

#### El silencioso: `start` con memoria

`setHitos()` fusionaba sobre el mapa existente con `!isset($plano['start'])`. Como la derivación
emite un solo `START`, esa guarda no significaba «el primero de esta derivación» sino **«sólo si
nunca hubo start»**: se escribía una vez en la vida del enlace y ya nunca se actualizaba.

Mover la llegada del 10 al 12 dejaba la lista diciendo 12 y el mapa diciendo 10 — y el motor
programa con el mapa. **El check-in salía calculado contra la fecha vieja**, que es exactamente
el síntoma que §20.8 decía cerrar, reintroducido por una guarda de más. `end` sí se actualizaba:
asimetría invisible.

Ahora `setHitos()` **reconstruye** `start`/`end` (los borra antes del bucle) y respeta las claves
que no son suyas.

#### El apagón: enlaces nuevos sin `created_at`

El sincronizador ponía sólo los hitos derivados de los tramos. `created_at` y
`expected_arrival` **no salen de los tramos** —los pone el contexto— y hay reglas colgadas de los
dos: la bienvenida y el aviso previo a la llegada. Una regla sin su hito no se programa y no
avisa: **toda reserva nueva se habría quedado sin bienvenida**, en silencio, y encima al pasar a
modo enlace podría cancelar la que ya estuviera encolada.

Ahora se pone el mapa completo del contexto **primero** y los derivados **encima**: `created_at`
y `expected_arrival` sobreviven, y `start`/`end` quedan con las fechas reales de la estancia en
vez del mínimo y el máximo agregados.

#### Auditoría después del arreglo

De los 204 enlaces, 202 con hitos: **202 con el `start` del mapa igual al del primer hito**, y
**202 con `created_at`**. Cero discrepancias. Esa consulta es el canario si algo vuelve a torcerse.

#### Lo que sigue pendiente y no es de este trabajo

- **`Beds24SendEnqueuer`** resuelve el `beds24_book_id` por el contexto de la CONVERSACIÓN. Eso
  decide **en qué hilo de Beds24 aterriza el mensaje**, y es del asunto: tras fusionar hilos, el
  recordatorio de la reserva B se publicaría en el hilo de la A. Mismo fallo que se acaba de
  cerrar en las estrategias, un paso antes en la tubería.
- **El auto-archivado sigue siendo por hilo**: cancelar una reserva cierra la conversación entera
  y silenciaría las agendas vivas de las demás cuando los hilos se fusionen.
- **No hay ni un test bajo `tests/Message/Service/Exchange/`**, que es justo donde vive el código
  que se envía al huésped.

### 20.10 Un asunto cancelado se MARCA, no se borra

La primera versión del sincronizador borraba el enlace cuando la reserva se cancelaba, y el
poblado se saltaba las canceladas. El miedo era correcto —106 de 310 conversaciones cuelgan de
una reserva cancelada, muchas duplicados de un bug de guardado, y regenerar sobre ellas
significaba una tanda de envíos por cada duplicado— pero **la solución era la equivocada: perder
el dato**.

Borrar tenía dos costes:

1. **Se perdía el rastro.** Un asunto cancelado es parte de lo que le pasó a esa persona. Sin él,
   el hilo cuenta una versión incompleta de su historia.
2. **Cerraba la puerta al aviso de cancelación.** `AgendaDeAsunto::estaCancelada()` lee el hito
   `cancelled_at` **del enlace**. Sin enlace no hay hito, así que esa rama era código muerto de
   hecho y el aviso quedaba mudo **por accidente, no por decisión**.

Ahora se marca: `PmsConversacionEnlace::marcarCancelado()` sella la fecha y el vínculo pasa a
`Terminado`. Los hitos derivados **se conservan** —lo que iba a pasar sigue ahí— y el recálculo
no borra la marca.

⚠️ **Marcarlo no despierta nada.** El motor sigue tratando el asunto como muerto por
`AgendaDeAsunto::estaMuerta()`: ni un mensaje. Lo que cambia es que **el dato existe**, y con él
la decisión de si el aviso vuelve se puede plantear. Sin el dato ni siquiera era una decisión.

Verificado tras marcar los 310 enlaces (106 de ellos cancelados): el dry-run del motor sigue
dando **0 mensajes y 0 colas**, y la regeneración desde cero sigue devolviendo **todos** los
mensajes vivos idénticos.

#### La regla, implementada

> Un aviso de cancelación **no tiene por qué ser específico si afecta a todos los eventos** de la
> reserva: ahí basta un mensaje genérico, uno solo. Pero **si deja de involucrar al menos uno**
> —se cancela una casita de dos, se cae un tramo— eso sí tiene sentido contarlo, porque la
> estancia del huésped cambia sin desaparecer.

- **Total** (`PmsReserva::isCancelada()`: todos los tramos cancelados) → el enlace se marca con
  `cancelled_at` y el asunto queda muerto para el motor. Un aviso genérico, cuando producto
  decida recuperarlo.
- **Parcial** → hito nuevo `PARTIAL_CANCELLATION`, con **qué** se perdió («Casita 5»). La reserva
  sigue viva y el resto de sus hitos también.

Lo detecta `PmsSincronizadorDeEnlace` comparando las unidades que cubría el enlace **antes** con
las que cubre **después** del recálculo. Si desaparece alguna y quedan otras, es parcial; si no
queda ninguna, es total y la trata la rama de arriba.

⚠️ **Dos trampas, las dos con test:**

1. Una cancelación parcial es un **hecho**, no un estado derivado: los tramos que la provocaron
   ya no existen, así que no se puede volver a deducir. `setHitos()` reemplaza los hitos
   derivados y **conserva** los históricos; si los borrara, la pérdida desaparecería en cuanto
   la reserva volviera a tocarse.
2. La casita perdida **no puede seguir contando como cubierta**. `unidadesDerivadas()` ignora las
   cancelaciones ya anotadas: si las contara, el siguiente recálculo la daría por perdida otra
   vez y anotaría un duplicado **en cada guardado**.

#### Y una tercera que sólo salió al probarlo contra la base real

El mapa plano se quedaba **sin** la clave `partial_cancellation` tras el siguiente guardado: el
sincronizador repone el mapa del contexto en cada recálculo, y como la pérdida ya no se volvía a
detectar, la clave se perdía y la regla que colgara de ella se quedaba sin fecha.

Es la **segunda** vez que el mapa plano falla por tratarse como un almacén paralelo —la primera
fue el `start` que sólo se escribía una vez—. Ahora es una **proyección**: `proyectarEnMapaPlano()`
lo rehace entero desde la lista en cada cambio, así que no puede desincronizarse.

Comprobado con `php var/probar-parcial.php`, que cancela una casita de una reserva real dentro de
una transacción, verifica que se anota **una** vez y **vuelve a guardar tres veces más** para
confirmar que no se duplica.

### 20.11 Qué hitos se pueden usar YA en una regla, y cuáles no

`MessageRuleCrudController` ofrece los cinco de siempre más `partial_cancellation`. Los cuatro
intermedios —`temporary_end`, `reentry`, `unit_change`, `service`— **existen, se derivan y se
guardan, pero no se ofrecen**:

| Hito | ¿En el desplegable? | Por qué |
|---|---|---|
| `created_at`, `expected_arrival`, `start`, `end`, `cancelled_at` | ✅ | Los de siempre |
| `partial_cancellation` | ✅ | Se proyecta al mapa plano, así que el motor lo encuentra |
| `temporary_end`, `reentry`, `unit_change`, `service` | ❌ | Viven **sólo en la lista**; el motor programa leyendo el mapa |

⚠️ Ofrecerlos sin que el motor lea la lista sería peor que no poder crearlos: la regla se
guardaría, parecería activa y **no dispararía nunca, sin un error que lo delatara**. Se añaden el
día que el motor programe **una ocurrencia por hito** — que es lo que ya pide §20.7, y lo que
distingue «dos escapadas» de «una salida temporal».

---

## 21. El conocimiento genérico: la última red antes de escalar

Cuando ninguna skill encaja, el agente escala al equipo. Y buena parte de lo que escala son
**preguntas repetidas cuya respuesta no cambia nunca** —si hay estacionamiento, a qué hora abre
la puerta, si aceptan Yape—. Cada una interrumpe a una persona para decir lo mismo otra vez.

`AgentConocimiento` es lo último que se mira antes de ese escalado.

### 21.1 Qué NO es

- **No sustituye a ninguna skill.** Lo que se calcula o se consulta —disponibilidad, cuenta, el
  wifi de una casita— sigue siendo de ellas, y esas respuestas están más al día que cualquier
  texto escrito a mano. Esto es para lo que **no tiene skill y no cambia**.
- **No es la guía del huésped** (`PmsGuiaItem`), que es del alojamiento y para el huésped. Esto
  es transversal: el horario de la oficina se le responde igual a un pasajero de tours.

### 21.2 Las dos fases, y por qué

La tabla está pensada para **crecer mucho**: se alimenta con el tiempo, cada vez que alguien nota
que una pregunta se repite. Mandársela entera al modelo en cada turno sería insostenible.

```
  fase 1   se le enseñan las CATEGORÍAS (unas líneas) y elige de qué va la pregunta
  fase 2   se le enseñan las ETIQUETAS de esa categoría, sin contenido
  →        el CONTENIDO sólo cuando ya eligió un ítem
```

Se podría buscar por texto y devolver lo parecido, y sería peor: una búsqueda por palabras
acierta cuando el huésped usa las nuestras y falla cuando usa las suyas —«cochera», «dónde meto
el auto»—. Quien resuelve eso es el modelo, que entiende la pregunta. Lo que hace falta es
enseñarle **lo justo** para que elija.

⚠️ Las **etiquetas se escriben como pregunta la gente**, no como lo llamamos nosotros. Es lo
único de la ficha que el modelo usa para elegir.

### 21.3 Dos caminos, a propósito

| Camino | Cuándo |
|---|---|
| Skill `consultar_conocimiento` | El modelo la llama cuando ninguna otra encaja. Transversal: sin dominio declarado, la ve cualquier actor |
| Red dentro de `EscalarAlEquipoSkill` | **Automática.** Antes de avisar, se mira si lo preguntado ya está escrito |

La segunda existe porque «pídeselo en el prompt» ya se demostró insuficiente más de una vez. No
bloquea el escalado —si el equipo debe enterarse, se entera— sino que **devuelve la respuesta
junto al aviso**, para contestar en el acto en lugar de dejar al huésped esperando.

Su comparación es tosca a propósito: palabras de cuatro letras o más, sin acentos. Aquí no se
busca acertar siempre, sino **no escalar lo obvio**; lo fino lo hace el modelo por el otro camino.

### 21.4 Quién ve qué

`dominios` y `perfiles` son listas, y **vacío = sin acotar** — la misma convención que
`SkillDominioInterface` y `ActorInterface::dominios()`, y por el mismo motivo: un olvido al
clasificar deja el ítem de más, no invisible. Lo segundo no se descubre nunca.

⚠️ **El filtro se aplica al construir la lista, no pidiéndoselo al modelo.** Un ítem que no le
toca a este interlocutor no llega a verse. Y el id se revalida en la segunda llamada aunque venga
de la lista que acabamos de dar: entre las dos, el modelo pudo inventárselo.

### 21.5 Decisiones

| Decisión | Por qué |
|---|---|
| La categoría es **tabla, no enum** | Se alimenta desde el panel. Con un enum, añadir «reclamos» a las nueve de la noche significa no añadirla |
| Id natural (`pagos`, `llegada`) | Sale en el prompt y en los registros; un uuid ahí no explica por qué el agente eligió lo que eligió |
| El «sistema» **es el dominio** | `hotelero`/`turistico`, los mismos de `ActorInterface::dominios()`. No hacía falta un eje nuevo ni una tabla de relación |
| Sin optimizar para caché | Decisión del dueño: con el volumen actual la granularidad vale más que el ahorro del prefijo cacheado. Cuando el volumen lo pida, la fase 1 puede moverse al bloque estable |

### 21.6 Lo que falta

- ~~El bloque de categorías en el contexto del turno.~~ **Hecho.** `contexto()` inyecta
  `ConocimientoGenerico::bloqueDeCategorias()` en lo volátil (no en el prefijo cacheado: la tabla
  se alimenta desde el panel y meterla en la caché la invalidaría en cada alta).

  ⚠️ Sin ese bloque la skill **mentía**: su descripción prometía «en el contexto tienes la lista
  de TEMAS», el modelo se inventaba una categoría verosímil, no existía, y la skill respondía «no
  hay nada escrito, no insistas: escala». Una pregunta **con** respuesta escrita acababa
  interrumpiendo a una persona. Arreglado además por los dos lados: `categoria` es opcional de
  verdad, y una categoría que no existe devuelve **el catálogo real** en lugar de una orden de
  escalar —la misma lección que `ConsultarGuiaSkill` ya había aprendido con `tema_id`—.
- **Alimentar la tabla.** Nace vacía: sin categorías no hay primera fase y el agente se comporta
  exactamente como hoy. Lo que se desplegó es la posibilidad, no un cambio de conducta.

---

## 22. La escalera de un tema: por qué no se contesta lo mismo dos veces

Una persona no responde igual la segunda vez. Dice «prueba esto», y si vuelven, «prueba lo otro»,
y si nada funciona manda al técnico. El agente hacía otra cosa: tenía **toda** la escalera escrita
de corrido dentro del contenido del tema, y la orden de dosificarla en prosa.

El ítem «Ducha (casa 2)» lo enseña entero — tres cosas distintas en un solo campo:

```
[uso]      «La manija izquierda es la caliente. Ábrela del todo… cuanta más agua pasa,
            menos caliente sale…»
[avería]   «Funcionan con gas propano en balones. Si se acaba el agua caliente, lo más
            probable es que el balón esté vacío…»
[escalado] «Si ya se lo explicaste y vuelve a decir que sigue sin salir caliente, no
            repitas las instrucciones: avisa al equipo.»
```

Dos problemas, y el segundo es el caro:

1. **Se le enseñaba entero y se le pedía que se autocensurara la mitad.** Ese patrón ya falló
   aquí varias veces — los teléfonos del personal de limpieza se colaron tres veces pese a una
   instrucción en mayúsculas. Lo que no debe salir, no se manda.
2. **Es anticomercial.** Quien sólo pregunta cómo funciona la ducha recibía, en el primer
   mensaje, que los balones se vacían y que avisaremos al equipo. Se le está respondiendo a un
   problema que no tiene, y eso genera mal ambiente con alguien que no venía a quejarse.

### 22.1 Cómo queda

`PmsGuiaItem::$agentePasos` (JSON, **vacío por defecto**) guarda lo que se dice sólo si lo
anterior no resolvió. `agenteContenido` no se toca: pasa a ser el peldaño 0.

```
   peldaño 0   agenteContenido    uso normal, lo que se contesta a una pregunta blanca
   peldaño 1   pasos[0]           no le funcionó: lo que puede probar él
   peldaño 2   pasos[1]           sigue sin ir: la comprobación que decide qué mandamos
   → agotados, se escala contando lo que ya se intentó. NO se repite el peldaño 0.
```

El peldaño lo decide **código**, no el prompt: `EscaleraDeTemas::peldanoPara()` cuenta las huellas
que ese mismo tema dejó en la metadata de los mensajes salientes (`tema_peldano`).

| Decisión | Por qué |
|---|---|
| Huella en el **mensaje**, no un contador en `MessageConversation` | La fusión de hilos por contacto (§20) corrompería un contador agregado; los mensajes viajan enteros con su metadata |
| Se **recalcula** leyendo, no se mantiene | Sin estado que sincronizar y sin migración |
| Un **humano de por medio reinicia** la cuenta | Lo que venga después es conversación con la persona, no insistencia con el bot: subir peldaños ahí sería escalar lo ya escalado |
| Ventana de **14 días** | La ducha en marzo y la ducha en junio no son insistencia, son dos averías |
| Escalera **opcional por ítem** | La mayoría de los temas se contestan de una vez. Sin pasos, el ítem se comporta exactamente como antes |

### 22.2 El aviso que hace que esto no sea contraproducente

⚠️ **Lo que se le quita del texto, el modelo no se lo calla: lo NIEGA.** Está probado en esta
misma guía — al sacar el depósito de garantía del contenido, respondía «no es necesario dejar
ningún depósito».

Por eso `ConsultarGuiaSkill::detalle()` devuelve, junto al peldaño actual, la clave
`si_no_le_funciona` cuando quedan pasos: le dice que **existe** un «y si no» sin darle el
contenido, y que si el huésped vuelve no improvise una solución. Sin eso, el campo de pasos sería
peor que tenerlo todo junto.

### 22.3 Cuatro trampas que costaron caro al construirlo

- **`setParameter()` con la ENTIDAD en vez del id + tipo `uuid`.** Los ids son `BINARY(16)`, y
  pasar el objeto **no da error: devuelve cero filas**. La escalera se habría quedado clavada en
  el peldaño 0 para siempre, repitiendo la misma respuesta, sin que nada lo delatara. El patrón
  correcto ya estaba en `NotificadorPushConversacion`.
- **`created_at` tiene precisión de segundo.** Dos mensajes del mismo segundo salían en orden
  arbitrario, y aquí lo primero que se mira es **quién habló último**: con el orden suelto, la
  respuesta de un operador podía quedar detrás de la del bot y no cortar la cuenta. Se desempata
  por `id` (UUID v7, ordenado por tiempo).
- **«Humano» no es «cualquiera que no sea la IA».** La primera versión cortaba la cuenta con
  `metadata['generado_por'] !== 'ia'`, y esa clave sólo la escriben `encolarRespuesta()` y
  `EnviarmePlantillaSkill`: los recordatorios programados de `MessageRuleEngine`, las plantillas
  del autoresponder y los avisos de escalado salen todos **sin** ella. Como prácticamente toda
  reserva tiene mensajes programados, la escalera se habría quedado clavada en el peldaño 0 casi
  siempre. El discriminador bueno es la columna `senderType = SENDER_HOST`, la misma que usa
  `hayHumanoAtendiendo()`.
- **`MAX_PELDANOS` no escalaba solo.** `peldanoPara()` satura en 3, y la rama de agotado miraba
  únicamente si el texto era `null`. Con un ítem de **tres** pasos, el peldaño se clavaba en 3,
  `pasos[2]` seguía existiendo y se servía **en bucle sin escalar nunca**; con dos o menos
  funcionaba por casualidad. La skill mira ahora las dos condiciones.

### 22.4 El estado del turno, y por qué se tira dos veces

`EscaleraDeTemas` guarda lo servido en el turno porque las dos mitades viven separadas: quien
**sabe** qué tema salió es la skill, y quien **escribe** el saliente es el procesador. Es un
servicio compartido, y el worker de Messenger es un proceso largo que atiende conversaciones
distintas en fila, así que un resto sin consumir se pega al mensaje siguiente. Como los ítems de
guía se comparten entre casitas, ese id colisiona de verdad: alguien que preguntaba por primera
vez recibiría el paso 1.

Por eso se llama a `limpiar()` en dos sitios:

- **Al abrir el turno**, porque `process()` tiene tres salidas después de `generar()` que nunca
  encolan (`ia_sin_respuesta`, `humano_atendiendo_al_responder`, `rafaga_superada_al_responder`):
  la guía ya corrió y anotó, pero no sale nada.
- **Antes del acuse de recibo**, porque si `generar()` revienta después de que la guía corriera,
  el huésped no llegó a leer ese peldaño. Estamparlo en un «lo estoy revisando» daría por
  explicado algo que nunca recibió.

La lista, además, es lista y no plaza única: un mensaje puede tocar dos temas —«no hay agua
caliente y el calefactor no prende»— y con una sola plaza el segundo pisaba al primero.

### 22.5 Los ítems con candado y la búsqueda por palabras no gastan peldaños

Dos rutas quedan fuera de la escalera, cada una por su motivo:

- **Ítem bloqueado.** `cuerpoParaElAgente()` devuelve el motivo del candado, no la explicación.
  Contarlo como «ya se le contó» haría que, al abrirse la ventana, se le sirviera el paso 1 —«si
  eso no te funcionó…»— a alguien que nunca llegó a leer la explicación normal.
- **Búsqueda por palabras.** Esta ruta no cuenta peldaños ni deja huella, así que servir el
  contenido por aquí significaba (a) que un huésped que reformula —«no hay agua caliente» en vez
  de «la ducha no funciona»— recibiera el peldaño 0 indefinidamente, y (b) que el texto llegara
  **sin** el aviso de que existen más pasos, con lo que el modelo negaría la avería en vez de
  callarse. Ahora, si el ítem tiene escalera, esta ruta devuelve el `tema_id` y manda a pedirlo
  por el camino bueno. Ojo: la descripción de la skill sigue ofreciendo el atajo `busqueda`, así
  que ésta es la ruta que el modelo intentará primero en los temas con escalera.

### 22.6 Lo que NO resuelve

**Lo que sólo se dice si lo preguntan no es un peldaño.** El depósito de garantía no depende de
que el huésped insista, sino de que pregunte por él, y hoy viaja dentro del ítem «Pago (general)»
—1756 caracteres con cinco temas pegados— con la instrucción «SOLO si pregunta. No lo saques tú».
Eso se arregla **partiendo el ítem**, para que el índice de temas lo elija cuando toca y el resto
del tiempo ni exista.

Diez de los 35 ítems con texto de agente llevan hoy contenido defensivo o condicional en la
primera respuesta: «Early check in / Late check out» (1848), «Pago (general)» (1756), las siete
duchas y «Calefactor». Varios necesitan las dos cosas: pasos **y** partirse.

### 22.7 Lo que falta

- **Cargar los pasos.** El campo nace vacío en los 44 ítems: lo desplegado es la posibilidad, no
  un cambio de conducta.
- **La comprobación del gas compartido no está escrita en ninguna parte.** Las casitas 7, 2, 4 y 3
  comparten el balón entre ducha y cocina, así que encender una hornilla dice si hay gas o no —y
  eso decide si se manda un balón o un técnico—. Ningún ítem de ducha menciona la cocina, y **no
  existe ningún ítem de cocina** en la guía.
- ~~Entrar directo al peldaño 1.~~ **Hecho**, ver §22.9.
- ~~Enfriamiento del escalado.~~ **Hecho**, ver §22.8.
- **El huésped que vuelve tras hablar con una persona recibe el peldaño 0.** El operador contesta
  «te mandamos un balón» (`SENDER_HOST`) → la cuenta se reinicia por diseño → pasan los 30 minutos
  de `HUMANO_AL_MANDO` → el huésped escribe «sigue igual» → el bot le explica otra vez la manija,
  a alguien cuya avería ya está en manos del equipo. Reiniciar es correcto para no **subir**
  peldaños; servir el 0 de nuevo es peor que las dos cosas. No tiene arreglo barato dentro de la
  escalera: la memoria del humano para este propósito debería ser más larga que la del guardia.

### Dónde tocar para cambiar la escalera

| Necesidad | Archivo / método |
|---|---|
| Cargar o corregir los pasos de un tema | Panel → guía → ítem, campo «🪜 Si eso no resolvió (pasos)» |
| Cambiar cuánto dura la memoria | `EscaleraDeTemas::DIAS_DE_MEMORIA` |
| Cambiar el tope de peldaños | `EscaleraDeTemas::MAX_PELDANOS` |
| Cambiar qué se le dice al agotarse | `ConsultarGuiaSkill::detalle()`, clave `debes_escalar` |
| Cambiar el aviso anti-invención | `ConsultarGuiaSkill::detalle()`, clave `si_no_le_funciona` |
| Que la huella se escriba en el saliente | `AiConversationProcessor::encolarRespuesta()` |
| Probarlo con datos reales | `php var/probar-escalera.php` (transacción + rollback) |

### 22.8 El enfriamiento del escalado

`EscalarAlEquipoSkill` no era idempotente entre turnos, y la escalera convirtió eso de riesgo
latente en garantizado: la rama de agotado no anota huella, así que la cuenta no se mueve y
**cada** insistencia posterior vuelve a ordenar el aviso. Tres «sigue sin funcionar» en una tarde
eran tres tandas de WhatsApp a **toda** la guardia por el mismo problema, y el segundo y el
tercero no le dicen nada nuevo a nadie.

Ahora, antes de buscar guardia, `yaAvisadoHacePoco()` mira si en los últimos 30 minutos salió un
aviso de **esta misma conversación de origen**. Si lo hubo, no se repite la ronda y se le devuelve
al modelo `ya_avisado` con la orden de decirle al huésped que su caso está en manos del equipo,
sin prometer plazo.

| Decisión | Por qué |
|---|---|
| La marca de no leído sale **siempre**, antes del enfriamiento | Lo que se ahorra es la segunda ronda de teléfonos, no la visibilidad: el caso sigue en el panel |
| Se mira en los **avisos que salieron** (`escalado_de` con el id del origen), no en una marca de la conversación | El enfriamiento se apoya en que el aviso EXISTIÓ, no en que alguien recordara anotarlo |
| Los avisos `FAILED`/`CANCELLED` **no enfrían** | Si el aviso se quedó en el sitio —plantilla sin aprobar por Meta, por ejemplo— hay que volver a intentarlo |
| El filtro por origen se hace **en PHP** | Son unas pocas filas en media hora; una consulta sobre JSON ataría esto a la versión de MySQL |
| 30 minutos | La misma ventana que `HUMANO_AL_MANDO`, y por el mismo motivo: es el tiempo en que se da por hecho que la guardia ya lo tiene delante |
| `emergencia` lo decide **el modelo** | Asimetría deliberada: un falso positivo cuesta un WhatsApp de más; un falso negativo silencia una emergencia real. Ante la duda, que suene |

Verificado con `php var/probar-enfriamiento.php`: no enfría sin avisos previos, ni con un aviso de
otra conversación, ni con uno que falló al encolarse, ni con uno de hace tres horas; sí con uno de
hace un momento.

### 22.9 Entrar directo al paso siguiente

El peldaño 0 por defecto es lo correcto: la mayoría de los «no funciona» son de gente que no ha
leído nada. Pero quien abre diciendo **«ya hice lo que decía la guía y sigue igual»** o **«en
Booking veo otro importe»** no está preguntando por primera vez: está objetando, y contestarle la
explicación de siempre es no escucharle.

`consultar_guia` acepta `ya_lo_intento`. Sube **un** peldaño (`max($peldano, 1)`) y nunca baja:
no puede saltar hasta el escalado, así que el modelo usándolo de más cuesta un paso, no la
escalera. Su descripción es deliberadamente estrecha — «no lo pongas porque suene molesto ni
porque diga *no funciona* a secas».

La misma puerta resuelve un caso que empieza en otra skill: `consultar_cuenta` devuelve ahora
`si_discute_el_importe`, que manda al tema de pagos de la guía con `ya_lo_intento` puesto. Así la
objeción del importe llega al **disclaimer** —por qué el canal muestra otra cifra— en vez de al
peldaño 0, que le repetiría quién cobra.

No hace falta volver al triaje: clasifica **antes** de que corran las herramientas y no ve sus
resultados, así que re-invocarlo a mitad de turno sería pagar una clasificación sin el dato que
provocó la objeción. La orden viaja pegada al dato, como `debes_escalar` en la guía.

### 22.10 La contabilidad de huellas: tres formas de mentir

La revisión final encontró tres fallos de la misma familia, todos silenciosos y con el mismo
síntoma —«repite un paso» o «adelanta un paso»—, invisible en logs porque no hay error:

1. **El tercer camino al acuse de recibo.** `limpiar()` estaba al abrir el turno y en el `catch`,
   pero cuando el motor termina **sin excepción y sin texto**, `generar()` devuelve el acuse como
   si fuera la respuesta y se encola por la vía normal: la huella acababa estampada en un «un
   compañero te responderá», dando por explicado algo que el huésped nunca leyó.
2. **`addMetadata()` sobrescribe.** Estampar una clave por vuelta dejaba sólo el último tema del
   turno, lo que anulaba el motivo mismo de haber convertido `$servido` en lista. Ahora la lista
   entera va en `temas_peldano`.
3. **Contar huellas asume que se sirvieron 0..N−1 en orden**, y `ya_lo_intento` rompe ese
   invariante: quien entra directo al paso 1 deja UNA huella y, contándola, volvía a recibir el
   paso 1. La huella guarda el peldaño servido; ahora se usa:
   `min(max($cuenta, $servidoMax + 1), MAX_PELDANOS)`.

⚠️ **La forma vieja de la huella sigue leyéndose.** `tema_peldano` (objeto suelto) convive con
`temas_peldano` (lista) en `huellasDe()`: hay mensajes con la forma antigua en producción, y dejar
de contarlos reiniciaría a cero la escalera de todas las conversaciones vivas el día del
despliegue.

Y en el enfriamiento, un fallo de capacidad, no de lógica: la consulta miraba los 100 salientes
más recientes de **todo el sistema**. Bajo carga —justo cuando más avisos hay— el aviso buscado se
caía fuera y el enfriamiento fallaba abierto, volviendo a sonar toda la guardia. Acotado con un
join a `contextType = 'staff'`, donde 100 filas en media hora sobran.

### 22.11 Qué se le enseña a quién del conocimiento

`bloqueDeCategorias()` recibe el actor y sólo nombra categorías con **al menos un ítem visible**
para ese interlocutor. El contenido nunca fugaba —los ítems filtran en `itemsDe()`— pero el
**nombre** de la categoría sí viaja en el prompt, y enseñarle a un prospecto que existe un tema de
«protocolos internos» es a la vez una fuga pequeña y una invitación a alucinar sobre él.

Lleva además tope (`MAX_CATEGORIAS = 30`, con aviso en log al pasarse): el bloque va en lo volátil
y se paga entero en cada turno, así que sin tope el precio del agente crecería cada vez que
alguien añade un tema en el panel, sin que nada lo delatara hasta la factura.

### 22.12 Qué temas llevan escalera, y la regla para decidirlo

Trece ítems de los 44. El criterio no es «avería / no avería» sino **qué se dice a priori**:

> En el peldaño 0 va lo que sirve a quien pregunta. Lo que **niega, limita, advierte o cuesta
> dinero** baja un peldaño y sale sólo cuando la pregunta lo pide.

| Ítem | Paso 1 | Paso 2 |
|---|---|---|
| Ducha ×7 | reintento con caudal alto | hornilla (sólo casas 2, 3, 4, 7) |
| Pago | por qué el canal muestra otra cifra | — |
| Early check-in | el margen real, condicionado | que tiene coste, y la noche adicional |
| Calefactor | por qué cuesta lo que cuesta | — |
| Estacionamiento | no sabemos el precio ni reservamos | — |
| Limpieza | la limpieza extra tiene coste | — |
| Traslados | no es nuestro; lo del recojo de Booking | — |

⚠️ **La distinción que decide qué se mueve:** lo que es **instrucción al agente** se queda en el
peldaño 0 aunque suene negativo; lo que se mueve es **la negativa dicha al cliente**.

«NO tenemos traslado propio: no lo prometas nunca» sigue en el peldaño 0 — si el agente deja de
saberlo, se lo inventa, y prometer un traslado inexistente es peor que cualquier frase seca. Lo
que bajó de peldaño es *empezar por ahí*, no el dato. Lo mismo con «la playa gratuita no es un
área vigilada», que se dice siempre a propósito: es honesto y evita el reclamo.

`Cancelación (general)` se revisó y **no lleva escalera**: «después se cobra la primera noche» es
la respuesta a la pregunta, no una advertencia de más. No todos los temas la quieren, y llenar el
campo por costumbre reintroduce el problema al revés.

### 22.13 Contenido pendiente, y por qué no lo hice solo

~~Televisor~~ **creado** (§22.14). Quedan tres: **Depósito de garantía** y **Comprobante de pago**
(hoy dentro de «Pago» con la instrucción «SOLO si pregunta», que es autocensura pedida al modelo),
y **Cocina**.

Crear un ítem no es sólo texto para el agente: **se publica en la app del huésped en siete
idiomas**, y las traducciones las genera `AutoTranslationEventListener` al persistir la entidad —
una migración SQL se lo salta. Habría que crearlos por el ORM y, sobre todo, escribir el texto
publicado, que es una decisión de contenido del negocio.

⚠️ Mientras el depósito siga dentro de «Pago», su instrucción de «no lo saques tú» sigue siendo
una petición al modelo. Sacarlo sin darle otro sitio es peor: está probado que entonces el
asistente **niega** que exista («no es necesario dejar ningún depósito»).

### 22.14 Contenido publicado: por comando, nunca por SQL

Un ítem de guía **se publica en la app del huésped en siete idiomas**, y las traducciones las
genera `AutoTranslationEventListener` en `prePersist`. Una migración escribe SQL directo y **se
salta el listener**: crearía fichas sólo en español, o —peor— dejaría traducciones vivas diciendo
lo contrario que el original.

Regla: **`agente_contenido` y `agente_pasos` pueden ir por migración** (texto plano, sin
traducir); **`titulo` y `descripcion` van por comando, con el ORM.**

Dos comandos en `src/Pms/Command/`, ambos con `--dry-run`:

- `app:pms:guia:crear-televisor` — crea el ítem «Televisor» de cada casita. Idempotente por
  `nombreInterno`. El aparato no es el mismo en todas: Roku en las casas 1–5, Mi TV Stick de
  Xiaomi en la 6, y Smart TV Android **con un solo mando** en la 7 —ahí no hay que cambiar de
  entrada, y sugerirlo confunde—. Paso 1 en las tres variantes: **no hay canales locales ni
  cable**, que es una negativa y no se dice a priori.
- `app:pms:guia:corregir-duchas` — ver §22.15.

### 22.15 Las duchas decían dos cosas distintas, y ninguna era cierta

El cuerpo **publicado** decía «manija derecha» en las siete casitas; el `agente_contenido` decía
«izquierda» en las siete. Se contradecían entre sí y ninguno valía para todas, porque el lado
**varía por casa y por baño**. No se veía porque cada superficie era coherente consigo misma.

```
casas 1, 3, 4, 5   un baño, dos manijas              caliente IZQUIERDA
casa 2             un baño, dos manijas              caliente DERECHA   ← la excepción
casa 6             DOS baños, ambos monocomando      abajo DERECHA · arriba IZQUIERDA
casa 7             DOS baños: palanca y dos manijas  ambos IZQUIERDA
```

⚠️ El texto de la casa 6 estaba **copiado del de la 7**: describía un baño de dos manijas que ahí
no existe. Y «abajo / arriba» sustituye a «Baño A / Baño B», para que el huésped sepa de cuál se le
habla sin ir a mirar el grifo.

Corregido en los **tres** sitios a la vez —cuerpo publicado, texto del agente y paso 1—: arreglar
uno solo deja la contradicción viva.

### 22.16 El motor de reglas moría con un hito que era objeto

En producción, 13/08/2026: `parseMilestone(): Argument #1 ($raw) must be of type string,
DateTimeImmutable given`. Cuatro veces en 96 segundos sobre una reserva, y después el dato en la
base se veía correcto — que es lo que hacía este fallo tan difícil de perseguir.

La causa: `PmsReservaMessageContext::getMilestones()` devuelve objetos `DateTimeImmutable` pese a
que el contrato de `PmsConversacionEnlace::setMilestones()` declara `array<string, string>`.
Guardados tal cual, el mapa queda con objetos **en memoria** hasta que la entidad da la vuelta por
JSON, y en esa ventana el motor los lee.

Consecuencia real: el asunto que revienta **se salta entero** —hay aislamiento por asunto para que
no arrastre a los demás— y esa reserva se queda **sin sus recordatorios**, con un `ERROR` en el log
como única señal.

Cerrado por los dos lados: `setMilestones()` normaliza a `Y-m-d H:i:s`, y `parseMilestone()` acepta
también `DateTimeInterface` — es el borde que lee un JSON de forma libre, y un `instanceof` es más
barato que volver a perseguir esto.

⚠️ **Y hay una TERCERA forma, que apareció al desplegar el arreglo:** las filas escritas antes de
la normalización guardaron el objeto **serializado**, `{"date": "…", "timezone": "America/Lima"}`,
así que al releerlas llega un `array`. Trece enlaces en producción. El daño no es el hito: **el
asunto entero se salta**, y esa reserva se queda sin ninguno de sus recordatorios —ni bienvenida,
ni guía de llegada, ni check-out—.

Las tres formas se aceptan ahora en `setMilestones()` y en `parseMilestone()`, y las claves que no
se pueden interpretar se descartan en vez de guardarse vacías —un hito vacío es peor que ninguno:
el motor lo trata como existente y programa contra una fecha ilegible—.

Lo ya escrito se repara con `app:pms:reparar-hitos`, que es «volver a guardar por el ORM»: el
normalizador de la entidad hace el trabajo, sin replicar la lógica en SQL.

#### La misma trampa, por otra puerta (15/08/2026)

Dos días después volvió a pasar, en `PmsConversacionEnlace::marcarCancelado()`. El método nació el
13/08 —el mismo día, en el commit «Un asunto cancelado se marca, no se borra»— escrito contra el
contrato **documentado** del mapa persistido (`array<string, string>`) en vez de contra lo que su
llamador tiene de verdad en la mano.

`PmsSincronizadorDeEnlace` lee dos veces del mismo array y sólo una lo trataba bien:

```
$enlace->setMilestones($contexto->getMilestones());                  ✅ normaliza
$enlace->marcarCancelado($contexto->getMilestones()[CANCELLED]);     ❌ TypeError
```

Con la firma en `?string`, PHP lanzaba **antes de entrar al cuerpo**. Y no era un caso raro: el
valor es `PmsReserva::getUltimaFechaModificacionCanal()`, declarado `?DateTimeInterface`, así que
cuando existe es **siempre** un objeto — fallaba en toda reserva cancelada que trajera esa fecha,
que en una cancelación de Beds24 es lo normal. 23 fallos en menos de cuatro minutos, por las dos
vías del worker: 2 procesando reservas y 21 procesando mensajes.

Nadie vio un 500 —el `catch` del worker lo registra y sigue—, pero la reserva se abandonaba a
medio procesar y el hito no llegaba a grabarse. Sin el hito,
`AgendaDeAsunto::estaCancelada()` no tiene qué leer: **el aviso de cancelación no podía existir**,
que es exactamente lo que el commit del 13/08 venía a habilitar.

#### El diagnóstico correcto, y el tipo que lo cierra

La primera explicación que se dio de esto —«`getMilestones()` significa dos cosas y por eso
chocan»— **es falsa**, y conviene que quede escrito porque suena razonable. Son dos métodos en dos
clases: PHP resuelve por el tipo del objeto y no los confunde jamás. Con nombres distintos habría
fallado igual.

La causa es que **`array` no dice nada de lo que lleva dentro**, y se midió:

| | Antes | Después |
|---|---|---|
| PHPStan nivel 2 (el del proyecto) | no lo ve | no lo ve |
| PHPStan nivel 3 | no lo ve | **caza la forma exacta del bug** |
| PHPStan nivel 5 | no lo ve | caza todas las variantes |
| PHPStan nivel 9 | no lo ve | — |
| nivel 9 + `checkImplicitMixed` | lo ve | — |

Antes **no lo cazaba ningún nivel**, porque el valor salía de un `array` sin tipo —eso es `mixed`
*implícito*, que sólo se revisa con una opción que no trae ninguno—. Y el único sitio que podía
decir qué había dentro, el `@return` de `MessageContextInterface`, estaba escrito `* * @return` :
con ese asterisco de más la línea deja de empezar por `@` y **ningún analizador la lee**. Un
contrato que no leía nadie, ni humano ni máquina.

**La solución no es que cada puerta acepte las dos formas** —eso es el mismo fallo escrito más
veces, y llegó a haber seis puertas: `setMilestones()`, `marcarCancelado()`,
`addContextMilestone()`, `setContextMilestones()`, `parseMilestone()` y `setHitos()`—. Es que haya
**una sola**:

- **`MomentoDeHito`** — la fecha de un hito. Constructor privado; se entra por `de()`, que es la
  única conversión del módulo, o por `ahora()`. `comoTexto()` da la forma persistida y
  `comoFecha()` la que hace cuentas.
- **`MapaDeHitos`** — el mapa completo, con validación de claves contra
  `ConversationMilestoneInterface::CLAVES`. Esa comprobación estaba antes suelta en un solo
  escritor y con una lista de cinco escrita a mano; los otros no la hacían, así que un hito con
  clave inventada entraba por el enlace y el motor no lo encontraba nunca.

Quien recibe un `MomentoDeHito` no tiene nada que comprobar, y `parseMilestone()` pasó de treinta
líneas de ramas a una que delega.

⚠️ **Un solo formato, y gana el de la `T`.** Había dos —el enlace escribía `Y-m-d H:i:s` y la
conversación `Y-m-d\TH:i:s`—, y nadie lo notó porque PHP traga los dos. Unificar hacia el espacio
habría roto el chat **en el iPhone sin tocar una línea de frontend**: `contextMilestones` sale por
la API y el front lo pasa por `new Date()`, que con espacio recibe sintaxis no estándar y Safari
puede devolver `Invalid Date`. El mapa del enlace no se serializa nunca —la entidad no tiene ni un
grupo—, así que el que se mueve es él.

Las filas antiguas se siguen leyendo: `MomentoDeHito::de()` acepta las tres formas. Verificado
contra producción el 15/08/2026 — 137 hitos de 50 enlaces reales, cero perdidos y cero instantes
desplazados.

### 22.17 La procedencia comercial viaja con el ASUNTO, y la redacta el dominio

Varios ítems de guía llevaban dentro condicionales por canal de venta —«a los de Airbnb no», «si
viene de una OTA, manda lo que diga SU reserva», «compruébalo con `consultar_mi_reserva`, campo
`channel_name`»—. Es el mismo patrón que ya falló tres veces aquí: enseñárselo todo al modelo y
pedirle que discrimine.

Y había una asimetría medible: `msg_template` y `msg_rule` filtran por canal **estructuralmente**
(`allowed_sources`, `allowed_agencies`); `pms_guia_item` y `agent_conocimiento` **no tienen ni una
columna** para eso.

⚠️ **La bifurcación no era el problema; la precondición sí.** Elegir entre dos ramas con el hecho
delante es fácil para un modelo. Lo que fallaba es que `channel_name` no estaba en ninguna parte
del contexto: había que ir a buscarlo con una herramienta, y si no la llamaba, elegía a ciegas.

#### Dónde vive

`ConversacionEnlaceInterface::procedenciaParaElPrompt()`. **En el enlace, no en la conversación**,
y ésa es la decisión que importa: con la fusión de hilos por contacto (§20) una misma persona
puede tener una estancia de Booking y un tour directo en el mismo chat. Una procedencia a nivel de
conversación sería falsa en cuanto hubiera dos asuntos — el error exacto que la separación
persona/asunto vino a corregir.

Viaja al prompt dentro de `Frente`, donde los asuntos ya se enumeran, así que funciona con N
asuntos y con Turismo sin tocar el núcleo.

⚠️ **La redacción vive en `PmsProcedenciaComercial::frase()`, no en la entidad**, y no es un
capricho: hay **dos** caminos que necesitan la misma frase y no comparten datos. El enlace la saca
de sus columnas `origen`/`agencia`; `PmsFrentes::frenteDe()` construye el frente **desde la
reserva** sin pasar por el enlace — y ése es el camino que de verdad llega al prompt. Con la
redacción sólo en la entidad, la funcionalidad entera era código muerto.

#### Quién escribe qué

`getOrigen()` devuelve un identificador **opaco** (`booking`, `airbnb`, `directo`). El núcleo lo
transporta y lo imprime; **no lo interpreta**. Las consecuencias —quién cobró, si hay depósito—
las redacta el dominio, porque en Turismo serán otras (quién gestiona un cambio de fecha, quién
emite el comprobante).

Un origen desconocido devuelve `null`: mejor sin línea que con una frase adivinada sobre quién
cobra.

Las líneas van **en positivo para todas las ramas** —«Airbnb: ya cobrado, sin depósito»— y nunca
como supresión —«no le menciones el depósito»—: lo segundo se ha ignorado varias veces en este
módulo.

#### Regla general

**Ningún servicio transversal lleva dentro conocimiento de un dominio.** Si hace falta, se define
un contrato que el dominio implementa y el núcleo consume sin entender. Antes de añadir campo o
servicio, mirar si el contrato ya existe: `ConversacionEnlaceInterface` ya exponía
`getOrigen()`/`getAgencia()`, y sólo faltaba la frase.

### 22.18 El equipo no paga el disfraz

Buena parte de la latencia del agente **está puesta a propósito**: el `.env` lo dice con todas las
letras —«una respuesta instantánea delata al bot»—. Para el huésped eso es un activo: cree que hay
alguien escribiendo y tolera la espera.

Para un colaborador es exactamente lo contrario. Ya sabe que es un bot, así que la espera no le
compra nada y lo único que percibe es **que el sistema va lento**. Le estamos cobrando el disfraz
sin venderle el disfraz.

Con `esDelEquipo()` —el teléfono resuelve a un `User`— se retira todo lo que existe por realismo:

| | Huésped | Equipo |
|---|---|---|
| `AGENT_IA_ESPERA_CORTA` | 3 s | **0** |
| `AGENT_IA_ESPERA_RAFAGA` (ventana larga) | 15 s | **0** |
| Tramo de potencia | media | **baja** |

Hasta 18 segundos de espera artificial que desaparecen, y una respuesta que además llega de un
modelo más rápido. No se pierde nada por el camino: el colaborador escribe consultas de una línea
—«quiénes llegan hoy»—, así que no hay ráfaga que agrupar, y lo que pide son datos, no redacción.

⚠️ **La emergencia se comprueba ANTES que el equipo.** Si un colaborador avisa de un incendio, eso
se sigue atendiendo con el tramo alto: quien escribe no cambia lo que está en juego.

### 22.19 «Público» significa todos menos el huésped, no sólo los prospectos

La primera versión de las fichas duplicadas se acotó a `prospecto` e `interesado`. El
razonamiento era correcto —el huésped debe recibir la ficha de SU casita, que es mejor— y tenía
una consecuencia que no se vio: **el equipo se quedó viendo menos que un desconocido**.

Se descubrió probando el bot desde el WhatsApp interno:

```
«Dan frazadas adicionales en los departamentos»  → correcto (esa ficha va sin acotar)
«Hay cochera»                                    → «No tenemos cochera propia.»
```

La segunda es una negativa seca donde había dos opciones que ofrecer. El agente no tenía la ficha
—quien preguntaba era del equipo— y tiró de memoria. Y quien prueba el bot es, precisamente, el
equipo: el fallo se habría quedado ahí, invisible, porque los prospectos sí recibían la respuesta
buena.

**La regla:** el único perfil que sobra es `huesped`, porque para él existe algo mejor. Todos los
demás la necesitan. `ValidadorDeConocimiento` comprueba exactamente eso —que el huésped no esté en
la lista—, no una lista blanca de perfiles concretos.

⚠️ Y queda el hueco de fondo, que esta migración **no** arregla: un miembro del equipo sin
`reserva_id` tampoco llega a los ítems `(general)` de la guía —`consultar_guia` le pide una casita—.
Por eso contestó de memoria en vez de leer «Estacionamiento (general)», que existe desde hace
meses. Abrir la puerta pública a los ítems generales sigue pendiente.

### 22.20 Dos herramientas podrían responder: se pregunta, no se adivina

Hoy «quiénes salen mañana» se resuelve bien, pero **no porque el agente lo haya decidido**: la
traza real dice `peticion → listar_entradas_salidas`, y la eligió porque su descripción lo dice
—«úsala para "quién sale mañana"»— y **era la única candidata**. No hubo desambiguación porque no
había nada que desambiguar.

Cuando exista Turismo aparecerá una skill con una descripción casi idéntica —«qué tours salen
mañana»— en el mismo catálogo, y el filtro por dominio **no las separa**: `dominios()` devuelve
siempre la unión de lo vendible, así que un colaborador lleva los dos. Los frentes tampoco sirven:
son «¿de cuál de TUS asuntos me hablas?», y quien pregunta por mañana no habla de un asunto suyo.

#### El mecanismo

El triaje devuelve `candidatos`: los nombres de las herramientas que **podrían** responder,
validados contra la misma lista blanca que `skill`. El procesador los resuelve contra el
catálogo real del actor (`AiConversationProcessor::candidatasResueltas()`) y, si quedan dos o
más, decide con `AclaracionDeEmpate::obliga()`:

```
candidatos > 1
   └─ resolver contra paraActor(incluirEscritura: $actor->esDelEquipo())
        └─ ¿alguna empatada exige permiso de ESCRITURA?
             no  → no se pregunta: el camino largo lleva las dos y el modelo elige (o consulta
                   ambas). Queda en el log, no en el chat
             sí  → se pregunta, y la pregunta la REDACTA el modelo en un turno seco
                   (AiConversationProcessor::redactarAclaracion())
```

| Decisión | Por qué |
|---|---|
| Lo lista el triaje, no una taxonomía en cada skill | Una familia (`salidas_del_dia` vs `operaciones_del_dia`) hay que decidirla a priori, se rompe en los bordes y una etiqueta mal puesta no la nota nadie. Preguntar no necesita mantenimiento: una skill nueva entra en el juego sola |
| La guarda mira `NivelRiesgo`, no el número de empatadas | La pregunta útil no es «¿cuántas empatan?» sino **«¿qué pasa si se elige la que no era?»**. Con dos lecturas, nada. Ver §22.24, que es lo que costó aprenderlo |
| Los candidatos se filtran por **roles** | Al personal de limpieza la skill de tours no le llega al catálogo, así que nunca le quedan dos: no se le pregunta nada. La ambigüedad sólo existe para quien de verdad tiene las dos funciones |
| Y con el mismo `incluirEscritura` que el camino largo | Contar sobre una lista que el modelo no va a ver es contar un empate que no existe. Consecuencia práctica: **a un huésped nunca se le pregunta**, porque no llega a ninguna skill de escritura |
| **El cierre sigue siendo código** | El modelo LISTA los candidatos y REDACTA la pregunta; quién decide preguntar es el procesador, contando. «Si dudas, pregunta» en el prompt es exactamente lo que este módulo lleva demostrando que no funciona |

⚠️ Preguntar y no responder, cuando hay una escritura por medio, es deliberado: **listar de más
es ruido, pero ejecutar sobre el dominio equivocado no se deshace.**

### 22.21 Los avisos del conocimiento decían «escala» y provocaban escalados

La skill declara en su descripción que es **la última red**, pero sus cuatro avisos de salida
decían, cada uno a su manera, «si no encaja: escala». Contradicción con consecuencia:

```
huésped: «¿hay cochera?»
  → el modelo prueba conocimiento primero (más barato, y su descripción se lo pide)
  → la categoría «servicios» le aparece —la ficha de frazadas la mantiene visible—
  → pero dentro sólo hay frazadas: estacionamiento está excluido por perfil
  → lee «si ninguna encaja, no está escrito: escala»
  → escala, SIN haber tocado consultar_guia, que tiene «Estacionamiento (general)»
```

Una pregunta con respuesta escrita desde hace meses acababa interrumpiendo a una persona. Lo
provocó la combinación de dos cosas correctas por separado: la acotación por perfil (§22.19) y un
aviso redactado como orden.

Los cuatro avisos mandan ahora **comprobar el resto de herramientas antes de avisar al equipo**, y
la descripción lo dice una sola vez: *«que YO no tenga algo no significa que no exista: soy la
última red, no la única»*.

⚠️ **Sin nombrar la guía.** La skill es transversal y no debe saber que existe el PMS: dice «tus
otras herramientas», no «consultar_guia». Ver CLAUDE.md §Dominios y contratos.

### 22.22 El validador aconseja según la política, no sólo avisa

Avisar de que la guía ya cubre un tema no dice qué hacer con el duplicado, y **las dos situaciones
posibles piden decisiones opuestas**:

| La guía… | Qué conviene | Por qué |
|---|---|---|
| tiene algo **mejor** (ficha por casita, datos de la estancia, ventana) | **excluir** al huésped | La genérica le quitaría el grifo de SU casita o sus horas |
| dice **lo mismo** (tema general, texto plano) | **no** excluirlo | La mejor y la peor son la misma, y excluirlo le quita la red si la guía no llega a servirle |

El veredicto lo emite **el dominio**, no el núcleo: `IndiceDeTemasInterface::temasQueCubren()`
devuelve ahora `TemaQueCubre` con `masEspecifica` y el porqué ya redactado. Qué hace específica a
una ficha es conocimiento de alojamiento —fichas por casita, `{{ placeholders }}`, ventanas de
acceso—; en Turismo será otra cosa, y el núcleo compone el consejo sin entender ninguno de los dos.

⚠️ **La señal de «ventana» se calibró.** La primera versión miraba «distinto de público», y como
32 de los 51 ítems son `cliente` —que sólo quiere decir «se le enseña al cliente»—, el veredicto
salía «es mejor» en casi todo. Un aviso que siempre dice lo mismo no ayuda a decidir: es el mismo
ruido que las coincidencias de una sola palabra (§22.12). Ahora sólo cuentan `solo-ventana` y
`cliente-confirmado`.

Resultado sobre las 7 fichas: sólo **«Agua caliente»** tiene algo mejor en la guía —las siete
fichas de ducha—. Las demás dicen lo mismo, así que excluir al huésped en ellas no le protege de
nada.

### 22.23 El tono no sobrevive a la traducción si no está escrito

Probando en producción:

```
«Aceptan mascotas»    → «No, lo sentimos, no aceptamos mascotas.»   ✅
«Are pets allowed?»   → «We do not accept pets.»                    ❌ sin la disculpa
```

El modelo tradujo el **hecho** y se dejó el **tono**. Pasa porque la instrucción está escrita en
español y él la lee como contexto, no como texto a decir: al redactar en otro idioma se queda con
el dato mínimo.

**La regla para escribir fichas:** lo que deba salir sí o sí va como **frase hecha**, y si el tono
importa, dada también en el otro idioma. Una instrucción sobre cómo decir algo —«dilo con
amabilidad»— es lo primero que se cae al cambiar de lengua.

Y el error anterior fue el simétrico: la primera versión decía «dilo claro y sin rodeos, **sin dar
explicaciones**» y salió un «No se aceptan mascotas» de tres palabras. El modelo hace exactamente
lo que la ficha dice, así que hay que separar **qué se dice** de **cómo se dice** — y escribir el
qué, no confiarlo al cómo.

### 22.24 La aclaración se le envió a una huésped, con las fichas internas dentro

18/08/2026, 13:57. Conversación `01a0163c-5428-7825-827e-decb55a2f0c4`:

```
huésped  «Hola buenas tardes cuentan con agua caliente, suficiente para 5 personas?»
agente   «Puedo mirarlo por dos lados y no quiero darte el que no es:
          · La GUÍA de la casita: cómo funciona la ducha o el agua caliente, la
            calefacción, cómo se entra, las normas, la limpie…
          · Respuestas ya escritas a preguntas frecuentes que no dependen de la
            reserva ni del día: horarios, normas, formas de p…
          ¿Cuál de los dos?»
14:01    el operador entra a disculparse: «Disculpe el mensaje no era para usted»
```

El log lo dejó dicho entero: `IA: 2 herramientas podrían responder (consultar_guia,
consultar_conocimiento); se pide aclaración.` No fue una alucinación del modelo: **fue el
código haciendo exactamente lo que se le había escrito**. Y en el barrido de todos los
salientes desde el 15/08 fue el único mensaje anómalo del parque.

#### Dos fallos, y ninguno es el que parece

**1 · La condición era el número de empatadas, cuando lo que importa es el daño de fallar.**
El empate era real: `consultar_guia` y `consultar_conocimiento` **pueden** responder las dos a
una pregunta sobre el agua caliente. Pero son dos **fuentes de texto** para lo mismo, y de cuál
sale la respuesta es una decisión nuestra: la huésped no tiene forma de contestarla, ni por qué.

El caso que justificó el mecanismo era otro —«quiénes salen mañana» con alojamiento y tours—, y
lo que lo distingue no es cuántas empatan sino qué pasa si se adivina:

| Empate entre… | Si se elige la que no era | Qué se hace |
|---|---|---|
| dos **lecturas** | se lee la fuente equivocada, y el camino largo lleva las dos en el catálogo: puede consultar la otra | no se pregunta |
| alguna **escritura** | se ejecuta sobre el dominio equivocado, y eso no se deshace | se pregunta |

`NivelRiesgo::Interna` cuenta como lectura, igual que en `SkillRegistry::paraActor()` y por el
mismo motivo: escribe hacia dentro —un aviso al equipo— y el daño de uno de más es que alguien
mire un chat que no hacía falta.

⚠️ **Consecuencia que conviene tener escrita: a un huésped ya no se le pregunta nunca.** No es
una regla aparte, sale sola — ninguna skill de escritura llega a su catálogo. La aclaración
quedó donde tiene sentido: entre operadores, sobre operaciones que tocan datos.

**2 · La pregunta se componía con `SkillDefinition::$descripcion`, que es prompt.** Lo dice la
propia clase: *«la descripción ES prompt, no documentación»*. Lleva mayúsculas de aviso, nombres
de parámetros y órdenes al modelo. `primeraFrase()` cortaba por el primer punto y a 117
caracteres — de ahí el «la limpie…» partido a mitad de palabra.

Se cambió por una llamada al modelo, `redactarAclaracion()`, con tres cierres de código
alrededor:

- Va por `AgentEngineInterface::turnoDirecto()`, que **no lleva herramientas por construcción**:
  el turno no puede acabar ejecutando ninguna de las candidatas que precisamente no sabíamos
  cuál era.
- Al prompt se le da el material **entero y limpio** y se le pide que lo diga con sus palabras.
  No se le pide que «no mencione detalles internos»: eso es supresión, y aquí la supresión no
  funciona (CLAUDE.md §El agente).
- La salida pasa por `AclaracionDeEmpate::motivoDeDescarte()`: si nombra una herramienta
  (`consultar_guia`) o pasa de 320 caracteres, **se tira y se sigue por el camino largo**. El
  motivo va al log; el descarte nunca es peor que mandar algo ilegible.

#### Por qué las dos guardas viven fuera del procesador

`AclaracionDeEmpate` es una clase suelta, con dos métodos estáticos y ninguna dependencia. No es
gusto por las capas: `AiConversationProcessor` tiene trece dependencias y no se puede instanciar
en un test unitario, así que las dos decisiones que fallaron habrían quedado otra vez sin cubrir.
Ahí están, en `tests/Agent/Conversation/AclaracionDeEmpateTest.php`, y el primer test **es el
mensaje de la huésped**.

#### La lección, que es más ancha que este bug

**Un texto compuesto por código con material escrito para el modelo acaba delante de un cliente.**
No hay error, no hay excepción, y el log dice que todo fue bien. Las dos preguntas que hay que
hacerse al escribir cualquier respuesta automática:

1. ¿De dónde sale cada trozo de este texto? Si alguno viene de un prompt, de una descripción de
   skill o de un identificador interno, **no está escrito para nadie**.
2. ¿Puede contestar esto quien lo recibe? Si la pregunta refleja una duda de nuestra
   arquitectura —de qué tabla, de qué herramienta, de qué dominio—, la respuesta no la tiene él.
