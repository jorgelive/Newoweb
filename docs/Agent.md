# El módulo Agent — entradas, salidas y contratos

**Propósito.** Qué es el agente, por dónde entra el trabajo, qué devuelve, y con qué contratos
habla con los dominios. Es el doc que faltaba: hasta el 19/08/2026 el agente estaba documentado
**dentro** de `docs/Mensajeria.md`, y eso ya no describe lo que es — el chat es una de sus cuatro
entradas, no su casa.

**Alcance.** `src/Agent/` y los contratos que consume. El detalle de cada mecanismo del chat
—triaje, ráfagas, escalera de temas, colas, plantillas— sigue en `docs/Mensajeria.md`, que es
quien lo cuenta bien; aquí sólo se dice cómo encaja.

---

## Índice

1. [Las cuatro entradas](#1-las-cuatro-entradas)
2. [El actor: quién pregunta](#2-el-actor-quién-pregunta)
3. [Las dos salidas del motor](#3-las-dos-salidas-del-motor)
4. [Los contratos con los dominios](#4-los-contratos-con-los-dominios)
5. [Dónde vive cada contrato, y por qué ahí](#5-dónde-vive-cada-contrato-y-por-qué-ahí)
5.6. [Los cuatro sitios donde vive lo que el agente sabe del negocio](#56-los-cuatro-sitios-donde-vive-lo-que-el-agente-sabe-del-negocio-18092026)
5.8. [El negocio de turismo: un prospecto también compra tours](#58-el-negocio-de-turismo-un-prospecto-también-compra-tours-11102026)
6. [Dónde tocar para cambiar X](#6-dónde-tocar-para-cambiar-x)

---

## 1. Las cuatro entradas

El agente no tiene una puerta, tiene cuatro, y **no se parecen**. Lo que las distingue no es el
canal: es **si hay alguien esperando** y **qué se hace con la salida**.

```
                         ¿hay alguien esperando?
                          sí                    no
                    ┌──────────────┐     ┌──────────────────┐
  ¿la salida se     │  1. CHAT     │     │                  │
  le enseña a       │  2. PANEL    │     │  4. EVENTO       │
  alguien?     sí   │  3. VOZ      │     │     DE DOMINIO   │
                    └──────────────┘     └──────────────────┘
                no                        la salida se GUARDA
```

| # | Entrada | Quién la dispara | Punto de entrada | Salida |
|---|---|---|---|---|
| 1 | **Chat** | un mensaje entrante (listener → dispatch `async`) | `AiConversationProcessor` | texto al huésped, por su canal |
| 2 | **Panel** | un operador escribiendo en la barra | `PanelAssistant` | texto en pantalla |
| 3 | **Voz** | Alexa | `VoiceAssistant` | texto que se lee en voz alta |
| 4 | **Evento de dominio** | un cambio de datos | un `*DispatchHandler` | **un dato guardado** |

### 1.1 La cuarta es distinta, y conviene tenerlo escrito

Las tres primeras son conversaciones: alguien pregunta, el agente contesta, la respuesta se
entrega. La cuarta no. La dispara un `flush`, la respuesta no la lee nadie y **acaba en la base
de datos**.

La primera de este tipo es la revisión del orden del nombre
(`RevisarOrdenDelNombreDispatchHandler`, ver `docs/PmsBeds24ReservasSync.md` §7.2): entra una
reserva por el pull o por el webhook, y hay que decidir si el canal mandó cruzados el nombre y el
apellido. De eso salen tres reglas que valen para cualquier entrada futura de este tipo:

- **⚠️ La salida se valida con código, siempre.** El modelo devuelve un veredicto
  (`{invertido, confianza, motivo}`), **nunca el dato**. Quien escribe es el código, con las
  cadenas que ya estaban guardadas. En una conversación un desliz del modelo lo ve una persona;
  aquí no lo ve nadie, así que la única defensa es que el modelo no tenga acceso a la escritura.
- **Va asíncrona.** Una llamada al modelo dentro de un webhook lo alarga segundos, y Beds24 y
  Meta reintentan los webhooks lentos. Es el mismo motivo por el que el router del chat está en
  `async` (ver `config/packages/messenger.yaml`).
- **Necesita un corta-bucles.** Lo que escribe el handler vuelve a disparar el listener. Si la
  guarda no existe, se realimenta. `OrdenDelNombre::esNuestroIntercambio()` es la de este caso.

### 1.2 Ojo: un mensaje entrante también llega por un listener

Es fácil pensar que la entrada 1 es «por HTTP» y la 4 «por Doctrine», y no es así: **las dos
salen de un listener**. La diferencia está en otro sitio.

```
 1. CHAT                              4. EVENTO DE DOMINIO
 ────────                             ────────────────────
 llega un Message                     cambia un PmsReserva
    │                                    │
 MessageAutoResponderListener          PmsNombreOrdenListener
    │  postPersist                        │  onFlush + postFlush
    ▼                                     ▼
 ProcessInboundIntentDispatch  (async)  RevisarOrdenDelNombreDispatch  (async)
    │                                     │
    ▼                                     ▼
 AiConversationProcessor                RevisarOrdenDelNombreDispatchHandler
    │                                     │
 texto → colas de canal → huésped       $reserva->setNombreCliente(...) → flush
```

Lo que las separa es **a quién se le debe una respuesta**. En la 1 hay una persona con el chat
abierto, y de ahí salen la ventana de ráfaga, el idioma, el perfil de conversación y la
restricción de canal. En la 4 no hay nadie: no hay idioma que respetar, ni ventana de 24 h, ni
canal que restringir — y por eso su actor tampoco tiene nada de eso.

⚠️ **La ventana de 24 h de la entrada 1 es la del NÚMERO que escribió (13/10/2026).** Meta la
cuenta por número, y la persona puede tener dos. `AiConversationProcessor::motivoParaCallarse()`
mira la de `telefonoDeRespuesta()` —el número del entrante (`metadata.telefono_origen`), si es un
teléfono vivo del hilo— y `encolarRespuesta()` le contesta a ése. Antes miraba la del hilo, que es
la del principal: con Camille, que escribía desde su segundo número, se habría callado sin fallo ni
aviso. El principal no se toca. El detalle, en `docs/Mensajeria.md` §5, «La ventana de WhatsApp es
de cada NÚMERO».

### 1.3 Media entrada más: el resumen

`ResumenConversacionService` vive en `src/Message/` y llama al motor por su cuenta. Es de la
familia 4 —nadie espera, la salida se guarda en `resumenIa`— pero está del otro lado de la
frontera. Es la dependencia que mantiene el círculo `Agent ↔ Message` (§5).

---

## 2. El actor: quién pregunta

`ActorInterface` es la respuesta a «¿quién está preguntando?», y la consumen también el PMS y
mensajería. **Una sola implementación: `AgentActor`**, `final`, con constructor privado y cuatro
—ahora cinco— constructores nombrados. La variación no está en subclases.

| Constructor | Quién es | Contexto | Roles |
|---|---|---|---|
| `delPanel()` | operador en el panel | el que tenga | los suyos |
| `delEquipoPorChat()` | del equipo, escribiendo por el chat | el del hilo | los suyos |
| `huesped()` | el huésped, acotado a SU reserva | `pms_reserva:<id>` | de huésped |
| `prospecto()` | quien escribe sin ser todavía nadie | **ninguno, a propósito** | `PROSPECTO` |
| `sistema()` | **nadie: un trabajo disparado por datos** | ninguno | **ninguno** |

🔒 **Quién cuenta como «del equipo» por el chat lo decide `RemitenteDelEquipo` (16/10/2026)**, y
sólo así: el mensaje entró por **WhatsApp de Meta** y el número es el del **remitente**
(`Message::getTelefonoOrigen()`), que Meta firma. Antes se miraba el teléfono de la CABECERA en
cualquier canal. En un hilo de OTA ese teléfono es el que el huésped tecleó en la reserva (con el
móvil de un operador ahí, el chat de Booking recibía sus roles **con escritura**), y en un hilo con
varios números era el de otra persona. Lo usan las cuatro copias que había: el actor y el «leído»
de `AiConversationProcessor`, y las esperas de ráfaga de `MessageAutoResponderListener` y
`ProcessInboundIntentDispatchHandler`. Sin `telefono_origen` (anteriores al 12/10/2026) se trata
como huésped: caer a la cabecera reabriría el agujero.

⚠️ **Y el hilo de una skill es el EN CURSO (`$actor->conversacionId()`), no el que se busca por
`contextType`/`contextId`**: desde la fusión por contacto esa pareja no es única. `escalar_al_equipo`
y `enviarme_plantilla` podían ir a OTRO hilo (el archivado, o el del titular cuando escribe un
acompañante), y `localizar_conversacion` y `listar_entradas_salidas` usan ahora el hilo titular de
la reserva (`EnlacesDeConversacion::hiloTitularDe()`, `pms_conversacion_enlace.es_titular`), con la
cabecera sólo de respaldo.

### 2.0 Qué skills ve un actor, y por qué la guardia dice lo mismo (16/10/2026)

`SkillRegistry::motivoDeExclusion()` es **la** política del catálogo: apagada
(`SkillConmutableInterface`), de otro negocio, sin rol, de dinero en una consulta de OTA
(`SkillDeImportesInterface`) o **sin el contexto que necesita** (`SkillConContextoInterface`, nueva:
`anotar_peticion` y `confirmar_hora` piden la conversación de una reserva; `anotar_pedido_cotizacion`,
un hilo — se ofrecían al equipo en el panel, en Alexa y en su hilo de avisos, y fallaban siempre).
La usan `paraActor()` (lo que se ofrece) y `GuardiaDeSkills` (lo que se ejecuta), que antes sólo
miraba los roles: por `app:agent:skill`, que no pasa por el catálogo, se ejecutaba lo demás. La
escritura sigue fuera: depende del canal y la decide quien arma el catálogo.

⚠️ **De qué reserva pregunta el equipo** en `consultar_codigos`, `consultar_wifi` y
`consultar_guia`: `ReservaDeLaConsulta`. El huésped, siempre la suya; el equipo, la que nombre. Se
hacía `contextoId() ?? parametro` sin mirar el tipo, y en el hilo de avisos del equipo
(`staff:<uuid>`) el «contexto» era el UUID de una persona: el parámetro se ignoraba.

### 2.1 `sistema()`, y por qué no vale `prospecto()`

El actor de sistema nace **sin roles, sin `User`, sin contexto y sin conversación**, y las cinco
ausencias son la misma frase: no hay a quién pedirle permiso, ni reserva a la que esté acotado,
ni hilo al que contestar.

⚠️ **Sólo vale con `turnoDirecto()`**, que va sin herramientas por construcción. Pasárselo a
`conversar()` sería pedirle al modelo que eligiera dentro de un catálogo vacío. Si algún día un
trabajo de sistema necesita herramientas, lo que hay que diseñar es **su** control de acceso, no
reutilizar éste.

La primera versión usaba `prospecto('sistema')` y era mentira: un prospecto es alguien que
escribe sin ser todavía nadie —tiene su rol, su vínculo comercial y su camino para escalar—.
Aquí no escribe nadie. Reutilizarlo habría dado a un trabajo de fondo el catálogo de skills de un
cliente potencial, que es justo lo que no se quiere en algo que nadie mira.

---

## 3. Las dos salidas del motor

`AgentEngineInterface` tiene exactamente dos métodos, y elegir el que no es se paga:

| Método | Qué hace | Herramientas | Devuelve |
|---|---|---|---|
| `conversar()` | el bucle completo: el modelo elige skills, las ejecuta, encadena | **sí** | `ConversationResponse` |
| `turnoDirecto()` | una pregunta, una respuesta | **no, por construcción** | `?string` (texto o JSON con esquema) |

### 3.1 `ConversationResponse`: el resultado con su motivo

No es un texto: es `{texto, skillsUsadas, motivo}`, y se construye con constructores nombrados
—`ok()`, `sinSkill()`, `sinPermisos()`, `rechazada()`, `vacia()`, `noDisponible()`—. El `motivo`
existe para que un «no contestó» se pueda distinguir de otro en el log: sin él, quedarse sin
motor y que el modelo devuelva vacío son la misma línea.

### 3.2 `turnoDirecto()` con esquema: el contrato de salida de los trabajos de sistema

Con `$esquema` (JSON Schema), el motor obliga al modelo a devolver esa forma. Es lo que hace
utilizable la entrada 4: la salida deja de ser prosa y pasa a ser un valor que el código puede
comprobar.

**La regla del esquema, y es la importante:** pide **decisiones, no datos**. `{invertido: bool}`
sí; `{nombre: string}` no. La diferencia es qué puede salir mal — un booleano equivocado cruza
dos cadenas que ya existían; un `string` puede traer un nombre que nadie tecleó, y en un trabajo
que nadie mira eso no lo descubre nadie.

### 3.3 `SkillResult`: lo que devuelve una herramienta

`ok(array $datos)` o `error(string $mensaje)`. Lo que no esté en `$datos` **no existe** para el
modelo: no lo completa leyendo la entidad, porque no la recibe. Ver `docs/Mensajeria.md` §11,
«lo que devuelves y no anuncias, no existe».

### 3.4 Lo que devuelve el proveedor: `RespuestaDelModelo` (26/09/2026)

Hacia dentro, cada motor recibe el JSON de su API. Se lee **una vez**, en la carpeta del proveedor, y
el motor trabaja con el resultado (la regla general está en `docs/TiposDeFrontera.md`):

```
JSON de DeepSeek ──► DeepSeekRespuesta::fromArray() ──┐
                                                      ├──► RespuestaDelModelo
JSON de Gemini ────► GoogleRespuesta::fromArray() ────┘      hayTurno · texto · motivoFin · motivoBloqueo
                     (motor Y lector de documentos)          llamadas: list<LlamadaAHerramienta{id, nombre, argumentos}>
                                                             consumo: ConsumoDeTokens{entrada, cacheLeido, pensamiento, salida}
                                                             turnoCrudo  ← lo único sin leer
```

- **Anthropic no pasa por aquí**, y no es un olvido: su SDK ya devuelve objetos tipados
  (`BetaMessage->usage->cacheReadInputTokens`) y el bucle lo lleva su `toolRunner`. No hay JSON que
  leer; traducirlo sería una capa sin frontera detrás.
- **`turnoCrudo` se reenvía tal cual llegó** en la vuelta siguiente, y es a propósito que no se
  interprete: DeepSeek empareja cada `tool_call_id` contra ese mensaje, y Gemini 3 devuelve una
  `thoughtSignature` en la parte de la llamada que hay que devolverle intacta. Rehacerlo a partir de
  los campos leídos perdería lo que el DTO no conoce.
- ⚠️ **`ConsumoDeTokens::$entrada` es el TOTAL, cacheado incluido.** Gemini lo manda así; DeepSeek lo
  manda partido en `prompt_cache_hit_tokens` + `prompt_cache_miss_tokens` y se suman, de forma que
  `entradaSinCache()` devuelve exactamente el «caché fallo» que ya se registraba.
- ⚠️ **`pensamiento` y `salida` son nulables, y `null` no es 0.** El aviso de turno truncado de
  Google escribe `pensamiento: ?` cuando no vino, y esa diferencia es la que dice si el presupuesto
  se fue en razonar (se apaga) o en contestar (se sube).
- **Las cifras de las líneas `Agent (deepseek|google): …` de `info.log` no cambiaron**, y de ellas sale
  el coste real del agente. `LineasDeConsumoTest` monta el motor entero con respuestas grabadas y
  comprueba las líneas exactas; **pasa igual con el código de antes y con el de ahora**, que es la
  prueba de que la lectura no se movió.
- Los **argumentos** de una llamada se quedan con las claves de texto
  (`LlamadaAHerramienta::objetoJson()`): la skill lee por nombre, así que una lista que el modelo
  mandara donde iba un objeto nunca se habría leído. Así `SkillInterface::ejecutar()` recibe lo que
  su firma promete (`array<string, mixed>`). `app:agent:skill` usa lo mismo.
- **Único cambio de lectura:** el lector de documentos (`GoogleLectorDeImagen`) tomaba sólo
  `parts[0].text` y ahora concatena todas las partes de texto, como ya hacía el turno con esquema del
  motor. Con `responseSchema` Gemini manda una sola parte, así que en la práctica es lo mismo; si
  algún día la partiera, antes se leía medio JSON y fallaba.

**Qué datos reales hay para comparar: ninguna respuesta de modelo.** No se guardan ni en la base ni
en `info.log` (sólo las cifras ya calculadas). Por eso la comparación contra lo de antes está en
`RespuestaDelModeloTest` —payloads con la forma de cada API frente a una copia literal de la lectura
vieja— y en el test del motor de arriba. Lo que sí hay guardado, las huellas de la escalera de temas
en `msg_message.metadata` (41 mensajes el 26/09/2026), lo compara `tools/pruebas/probar-dto-ia.php`
contra producción: idénticas.

Lo mismo, en pequeño, para las otras fronteras del agente:

| Frontera | Se lee en | Nota |
|---|---|---|
| El JSON del clasificador | `RespuestaDeTriaje::fromArray()` | sólo la forma; la validación contra las listas blancas sigue en `Triaje::interpretar()` |
| El sobre de Alexa | `PeticionAlexa::fromArray()` | lleva también `apiEndpoint`/`apiToken` para `DiagnosticoAlexa` (`docs/AgentVoz.md` §2) |
| Los parámetros de los comandos `app:agent:*` | `#[Argument]` / `#[Option]` en `__invoke()` | la consola los entrega tipados; `--maximo-horas=abc` ahora es un error con nombre en vez de «1 hora» en silencio |

---

## 4. Los contratos con los dominios

El agente no sabe qué es una reserva. Lo que sabe es que hay dominios que implementan contratos,
y se autolocalizan con `#[AutoconfigureTag]` / `#[AutowireIterator]`: **una clase nueva se
enchufa sola**, sin tocar ningún registro ni ningún `match`.

| Contrato | Qué le pide el agente al dominio | Registro |
|---|---|---|
| `SkillInterface` | una herramienta ejecutable | `SkillRegistry` |
| `SkillDominioInterface` | a qué negocios pertenece esa skill | `SkillRegistry::paraActor()` |
| `InstruccionesDeDominioInterface` | qué reglas van en el `system` para este contexto | `InstruccionesDominioRegistry` |
| `IndiceDeTemasInterface` | qué temas de guía existen y cuáles le tocan a éste | `IndiceDeTemasRegistry` |
| `FrentesPorDominioInterface` | de qué asuntos vivos se puede hablar | `EnumeradorDeFrentes` |
| `MessageDataResolverInterface` | los datos con los que se rellena una plantilla | `MessageDataResolverRegistry` |
| `BotActionHandlerInterface` | qué HACER cuando una regla de intención dispara | `IntentRouter` |

⚠️ **Los identificadores de dominio son opacos.** `pms_reserva`, `airbnb`, `hotelero` se
transportan, **no se interpretan**: quien conoce sus consecuencias es el dominio, y es él quien
redacta el texto. El núcleo lo concatena.

---

### 4.1 ⚠️ Una entidad ajena no aparece en una firma que otro tiene que implementar

Es la regla que resume los dos arreglos del 19/08/2026, y la línea es esa: **firma que otros
implementan, no; dentro de un servicio propio, sí.**

| Antes | Ahora |
|---|---|
| `InstruccionesDeDominioInterface::contextoVolatil(MessageConversation $c)` | `contextoVolatil(ActorInterface $actor, MapaDeHitos $hitos)` |
| `BotActionHandlerInterface::execute(Message $m, array $parameters)` | `execute(string $mensajeEntranteId, ParametrosDeAccion $parametros)` |

Los dos eran contratos con `#[AutoconfigureTag]` —escritos para que los implemente cualquier
dominio— con una entidad de mensajería dentro. Eso obliga a **todos** los implementadores a
conocer el chat para hacer algo que quizá no tiene nada que ver con un chat.

**Se pasa el ID, no la entidad**, y quien implementa carga de su propio dominio lo que necesite.
Es el mismo patrón que los handlers asíncronos (`ProcessInboundIntentDispatch` lleva `messageId`;
`RevisarOrdenDelNombreDispatch`, `reservaId`), y trae de regalo la comprobación honesta: entre el
disparo y la ejecución el registro pudo desaparecer, y ahora hay un sitio donde decirlo.

Y la implementación **se fue con su trabajo**: `SendTemplateActionHandler` vive ahora en
`src/Message/Service/Agent/`, que es donde están los datos que toca —busca una plantilla,
resuelve el canal, encola un mensaje—. Del agente sólo toma el contrato, igual que el PMS
implementa `IndiceDeTemasInterface` desde `src/Pms/Service/Agent/`. Con eso **`src/Agent/Action/`
no importa nada de mensajería**: sólo quedan el contrato y su value object.

⚠️ Lo que no puede cambiar al moverla es `getActionKey()`: `send_template` está guardado en
`agent_autoresponder_rule.action_type` y es lo que empareja la regla con la clase. La clase se
autolocaliza por `#[AutoconfigureTag]`, así que cambiarla de sitio no toca ningún registro —
comprobado con `debug:container --tag=app.bot_action_handler`.

De paso, `array $parameters` pasó a `ParametrosDeAccion`: el origen es un `json` que teclea un
operador en EasyAdmin, así que puede venir cualquier cosa. El value object descarta lo que no es
escalar en la puerta y trata `""` y `"   "` como ausencia — que es como queda un campo que
alguien abrió y no rellenó, y con el array pelado hacía que `force_channel: ""` buscara un canal
con id vacío en vez de caer al canal de entrada. Las **dos entradas** que esto tenía en
`phpstan-baseline.neon` se quitaron en vez de heredarlas.

## 5. Dónde vive cada contrato, y por qué ahí

```
 App\Contract          ← núcleo compartido: lo usan TRES módulos o más
   VinculoComercial · Frente · MomentoDeFrente
   ConversationMilestoneInterface · MapaDeHitos · MomentoDeHito
   CategoriaConocimiento · TemaQueCubre

 App\Agent\...         ← el agente y sus contratos
   ActorInterface · SkillInterface · SkillDefinition · NivelRiesgo · AgentEngineInterface
   Contract\IndiceDeTemasInterface · Contract\InstruccionesDeDominioInterface

 App\Message\Contract  ← mensajería: lo que de verdad es suyo
   ChannelEnqueuerInterface · ConversacionEnlaceInterface · MessageContextInterface
   MessageDataResolverInterface · FrentesPorDominioInterface · HitoDeAsunto · …
```

**`Agent → App\Message\Contract` es hoy CERO** salvo `ChannelEnqueuerInterface` en las dos
skills que envían, que es justo para lo que existe ese contrato. Lo demás que el agente importa
de mensajería son **entidades**, y sólo donde el chat es el asunto: el orquestador, las skills de
envío, el router de intenciones y el pre-router de ráfagas —ahí la entidad es la materia prima—.

En `Conversation/`, `Access/` y `Contract/` **no queda ninguna entidad**. El único import de esas
tres carpetas es `AgentActorFactory → EnumeradorDeFrentes`, que es un **servicio**: la forma en
que este proyecto siempre cruzó los módulos, y la que no molesta.

### 5.1 El criterio no es «quién lo entiende», es «quién lo usa»

Un tipo baja al núcleo compartido cuando **varios módulos lo consumen por motivos propios**. No
por parecer genérico. Aplicarlo mal en las dos direcciones ya costó dos correcciones:

- `CategoriaConocimiento` y `TemaQueCubre` vivían en `src/Message/Contract/` y **mensajería no
  los usaba**: estaban alojados en un módulo que ni los mira. Bajaron a `App\Contract`.
- `IndiceDeTemasInterface` estaba en el mismo sitio y por el mismo error, pero **no** era núcleo
  compartido: es del agente. Subió a `App\Agent\Contract`, no bajó.

### 5.2 ⚠️ `ActorInterface` NO es núcleo compartido, y parecía que sí

Cuesta verlo porque el PMS lo tiene en tres firmas. Pero al mirar quién lo usa de verdad, fuera
de `src/Agent` no queda casi nada:

| Dónde | Qué es |
|---|---|
| `ConversacionEnlaceInterface:51` | **un comentario** |
| `PmsIndiceDeTemas` (3 firmas) | el **único** implementador de `IndiceDeTemasInterface` |

Y ese implementador vive en **`src/Pms/Service/Agent/`** — la carpeta se llama «Agent». Es el
adaptador del PMS *hacia el agente*, no un servicio del PMS que resulte necesitar la noción de
actor. Dos de sus tres firmas llevan `ActorInterface` porque el contrato las obliga; la tercera
sólo la llaman esas dos.

O sea: **el actor tiene un solo dueño**, y bajarlo al núcleo compartido sería repetir el error
que acabamos de deshacer, con las flechas al revés. Se queda en `App\Agent\Access`. Que
`src/Pms/Service/Agent/` importe de `App\Agent` no es una fuga: es un dominio implementando un
contrato del agente, que es exactamente el diseño que pide CLAUDE.md.

### 5.3 Lo que queda del círculo `Agent ↔ Message`

Tres movimientos lo dejaron en lo mínimo. El último —`InstruccionesDeDominioInterface`— no fue
una mudanza sino **un cambio de firma**, porque el problema no era dónde vivía:

```php
// antes: un contrato que existe para no conocer ningún dominio… con un dominio dentro
public function contextoVolatil(MessageConversation $conversacion): string;

// ahora
public function contextoVolatil(ActorInterface $actor, MapaDeHitos $hitos): string;
```

De la entidad usaba tres cosas: los hitos, el `contextType` y el `contextId`. Las dos últimas ya
estaban en el actor; los hitos ya tenían tipo propio. La entidad sobraba entera — y su contrato
hermano, `IndiceDeTemasInterface::lineaVolatil(ActorInterface $actor)`, lo tenía bien desde el
principio. La asimetría no era una decisión: era que éste se escribió después.

⚠️ **Quién resuelve la implementación no cambió, y no es lo mismo que quién la usa.**
`InstruccionesDominioRegistry` sigue eligiendo por el `contextType` de la **conversación**, que
el llamador le pasa como `?string`: un prospecto nace sin contexto a propósito, y lo que se
pregunta ahí es de qué negocio va el hilo, no a qué está atado quien escribe.

Lo que queda:

| Dirección | Quién | ¿Legítimo? |
|---|---|---|
| Message → Agent | `MessageAutoResponderListener` → `ProcessInboundIntentDispatch` | **sí**: es la entrada 1 |
| Agent → Message | `AiConversationProcessor`, skills de envío → entidades | **sí**: el chat y quien envía |
| Agent → Message | `PreRouterRafaga` → `Message` | **sí**: agrupa mensajes |
| Agent → Message | `AgentActorFactory` → `EnumeradorDeFrentes` | discutible: un **servicio**, no un contrato |
| Message → Agent | `ResumenConversacionService` → `SelectorDePotencia`, `ConversationRequest`, `AgentActorFactory` | **no**: es una entrada del agente (§1.3) viviendo del otro lado |

Ya no hay ningún contrato cruzado. Lo único claramente fuera de sitio es
`ResumenConversacionService`: por lo que hace es la entrada 4 —nadie espera, la salida se guarda—
y por dónde vive es mensajería.

## 5.4 Las skills del padrón: buscar el expediente y preguntarle (24/08/2026)

`src/Agent/Skill/Cotizacion/` — dos skills que se usan **en ese orden**:

| | |
|---|---|
| `buscar_expediente` | Encuentra el file por localizador o nombre, y devuelve **el mapa**: los ejes que tiene, algunos valores de cada uno, y los servicios |
| `consultar_padron` | Responde «quiénes están en el grupo 6», «quiénes NO llevan vuelo nacional», «cuántos tienen el pasaporte vencido», «los vuelos de Santiago Gómez» |

### Por qué son dos y no una

`consultar_padron` exige el localizador exacto. Sin la primera, el modelo tendría que inventárselo
— y **un modelo se inventa identificadores con toda la seguridad del mundo**. Es el mismo motivo
por el que `buscar_reserva` y `consultar_mi_reserva` están separadas.

### Por qué los filtros son parámetros y no una frase

Aceptar «los del grupo 6 sin vuelo» sería volver a escribir un intérprete de lenguaje natural
**dentro** de la skill, y el suyo se equivoca en silencio: «grupo 6» y «habitación 6» son los dos
«6», y elegir mal devuelve una lista plausible de gente equivocada. Con `subgrupo`, `servicio` y
`rol` separados, el modelo tiene que decir en qué eje busca; si no lo sabe, pregunta.

### ⚠️ Nombre errado ⇒ opciones, NUNCA lista vacía

`subgrupo` y `servicio` se validan contra lo que ese expediente tiene. Si no encajan, la respuesta
trae `error_de_nombre` y las opciones reales.

> Una lista vacía se lee como «no hay nadie»: una respuesta **falsa y creíble**. Las opciones se
> leen como «te has equivocado de nombre», que es la verdad.

### ⚠️ La negación es un parámetro

«Quiénes NO tienen vuelo nacional» no se contesta buscando: hay que partir de todos y quitar. Por
eso existe `negar_servicio`. Y vacío se lee como NO, igual que en el padrón: si nadie marcó la
casilla, esa persona no lo lleva.

### ⚠️ `agrupar_por`: el desglose en UNA llamada, y por qué existe

**Costó 188 000 tokens en un solo turno descubrirlo.** El 24/08/2026, la primera versión de esta
skill sólo sabía contestar «quiénes cumplen X». Preguntarle «¿cuántos hay en cada grupo?» obliga
al modelo a llamarla **una vez por grupo**: con nueve grupos son nueve vueltas, el turno topa en
las ocho de `MAX_VUELTAS` y **se queda sin contestar** — habiendo reenviado el catálogo entero
ocho veces.

Así se ve en el log, y es la forma que tiene este fallo:

```
vuelta 1  entrada 19 328   consultar_padron
vuelta 2  entrada 19 846   consultar_padron
…                          ← la entrada CRECE: los resultados se acumulan
vuelta 8  entrada 31 292   consultar_padron
WARNING   8 vueltas de herramientas sin respuesta final
```

`agrupar_por` = `grupo` | `habitacion` | `vuelo` | `rol` | `servicio` devuelve el reparto entero
de una vez, y se combina con los demás filtros (`agrupar_por=habitacion` + `rol=coordinador` da
las habitaciones de los coordinadores).

#### ⚠️ El desglose DECLARA lo que deja fuera

Un reparto miente en las dos direcciones, y callarlo produce justo la respuesta falsa y creíble
que esta skill existe para evitar. Medido sobre el padrón real de 131 personas:

```
agrupar_por=grupo     9 valores · suma 108 · cumplen 131   ← 23 sin grupo
agrupar_por=vuelo    23 valores · suma 250 · cumplen 131   ← 121 con dos tramos
agrupar_por=rol       5 valores · suma 131 · cumplen 131   ← el rol es uno y sólo uno
```

Por eso la respuesta trae `sin_<eje>` y una `nota_desglose` que lo dice con palabras. Para
notarlo por su cuenta, el modelo tendría que sumar nueve números y compararlos con otro — lo
previsible es que presente el reparto como completo y **23 adultos queden invisibles**.

⚠️ **El eje se valida contra la lista blanca ANTES de contar, no según si sale alguien.** Antes se
devolvía «no es un eje por el que pueda agrupar» cuando el desglose salía vacío, y eso ocurre con
un eje perfectamente válido en cuanto los filtros dejan gente que no pertenece a él: los dos
supervisores no tienen grupo, así que `rol=supervisor` + `agrupar_por=grupo` contestaba que
«grupo» no era un eje… **con «grupo» dentro de la lista de opciones del propio error**. El modelo
reintenta con variantes, que es el bucle que este parámetro vino a matar.

⚠️ Y las tildes se quitan con **una tabla, no con `Transliterator`**: ésa exige `ext-intl`, que
este proyecto **no declara** en `composer.json` —sí `ext-iconv`, no `intl`—. Hoy está cargada en
los dos entornos, pero un servidor nuevo sin ella no daría un fallback: daría `Class
"Transliterator" not found` en **todas** las llamadas a la skill. Una dependencia oculta de una
extensión, para quitar seis tildes.

⚠️ **La lección general, que vale para cualquier skill nueva:** si una pregunta natural obliga a
llamarla N veces, no es que el modelo sea torpe — es que a la skill le falta esa forma. Y el
síntoma no es un error: es una factura.

⚠️ Y la comparación de nombres **quita las tildes**. «habitacion HA13» no encontraba «Habitación
HA13», la skill devolvía «no existe» con las opciones, y el modelo reintentaba con otra variante —
cada reintento, una vuelta entera con 15 000 tokens de catálogo detrás.

### ⚠️ `persona`: buscar por nombre, y por qué el recorte lo hacía falta

**Sin este parámetro, la skill no sabía buscar a nadie.** El 24/08/2026, en producción, preguntar
por «Santiago Gómez» funcionaba y preguntar por «Fabio Latorre» contestaba que no estaba. No era
un fallo de nombres: no había búsqueda. El modelo pedía el listado y leía los 40 nombres que
caben en `MAX_NOMBRES`, ordenados por apellido:

```
Gomez Acuña, Santiago      posición  4 de 131   → dentro del recorte, lo encuentra
Latorre Garcia, Henry …    posición 66 de 131   → fuera del recorte, «no está»
```

Un recorte necesario (arriba) produce, sin una forma de buscar, la peor respuesta posible: **una
negativa rotunda sobre alguien que sí figura**. Y encima cara — cada intento es una vuelta con el
catálogo detrás, que es lo que hacía que además fuera lento.

`persona` casa **palabra por palabra contra el nombre completo y los documentos**, sin tildes y
sin orden: `Fabio Latorre`, `latorre fabio`, `Santiago` (devuelve 6) y `24991600` encuentran. Es
deliberadamente laxo: quien pregunta escribe el nombre como lo recuerda, y una coincidencia de más
la desempata el modelo enseñando la lista; una de menos vuelve a ser la negativa falsa.

### Los vuelos: aparte de los subgrupos, y con horarios sólo si preguntas por alguien

`describirPersona()` saca las reservas aéreas de la lista plana de `subgrupos` a un array `vuelos`
propio, con `tramo`, `aerolinea`, `localizador` y —condicionalmente— `horarios`, que es el
`detalle` del grupo (`CotizacionFileGrupo::getDetalle()`, Markdown de varias líneas):

```
vuelos: [{tramo: Nacional,      aerolinea: JetSMART,      localizador: QYLS7T,
          horarios: "* Ida JA7018 · CUZ 17/09 07:15 → LIM 17/09 08:55
                     * Retorno JA7041 · LIM 24/09 18:20 → CUZ 24/09 19:40"}, …]
```

⚠️ **Los horarios sólo salen con `persona` y con `MAX_CON_HORARIOS` (4) o menos personas.** Son
dos vuelos por cabeza y varias líneas cada uno: en un listado de 40 son miles de tokens que nadie
pidió, y volverían a inflar el turno que `agrupar_por` acaba de adelgazar. Medido: la misma
consulta pesa **969 bytes** para una persona con horarios y **3 800** para nueve sin ellos.

⚠️ **La palabra es «vuelos» y «horarios», nunca «itinerario».** Está reservada para el itinerario
de `src/Travel/`, que es otra cosa —los segmentos del viaje— y va a tener su propia skill. Dos
herramientas que se anuncian con la misma palabra es un modelo eligiendo la equivocada la mitad de
las veces.

### ⚠️ Todo lo que se devuelve va recortado, y con su total

Un padrón de colegio son 133 personas, 66 habitaciones y 23 localizadores. Volcarlos es media
ventana de contexto para una pregunta que casi siempre se responde con el número: `solo_conteo`
existe para eso, los nombres se cortan en 40 y las opciones se agrupan por eje con 12 valores cada
una. **El total va siempre**, que es lo que permite decir la verdad sin enseñarla entera.

Los «no participa» quedan fuera por defecto, como en el panel: conservan grupo y reservas aéreas,
así que contarlos infla cualquier respuesta sobre cuánta gente va.

## 5.5 El bucle de herramientas: cómo se corta y cómo se diagnostica (25/08/2026)

> 🔒 **Las protecciones son de los TRES motores desde el 17/10/2026: `ControlDeVueltas`.** Vivían
> sólo en Google; Anthropic y DeepSeek no tenían ninguna. Lo que es del bucle —la llamada repetida
> que no se ejecuta, la racha de `MAX_FALLOS_SEGUIDOS` vueltas fallidas, el tope de `MAX_VUELTAS`,
> la orden de cierre— está en la clase compartida y con tests; lo que es del formato de cada API se
> queda en cada motor:
>
> | Motor | Repetida | Racha y tope | Cierre sin herramientas |
> |---|---|---|---|
> | Google | en su bucle | en su bucle | `functionCallingConfig.mode: NONE` |
> | DeepSeek | en su bucle | en su bucle | `tool_choice: none` (probado contra la API) |
> | Anthropic | **dentro de cada herramienta** (`AnthropicSkillAdapter`) | se deja de pedirle mensajes al runner | `setMessagesParams()` con `toolChoice: none` |
>
> **Anthropic conserva el `toolRunner` del SDK.** La palanca es `setMessagesParams()` durante la
> iteración: el runner entiende que llevamos el historial a mano, no añade el mensaje en curso ni
> ejecuta sus herramientas, y la siguiente vuelta es la petición del cierre. ⚠️ **El cierre sólo
> AÑADE** (18/10/2026): el mensaje en curso tal cual, un `tool_result` de error por cada herramienta
> que pidió y la orden detrás. Meter la orden en el último turno de usuario —ya enviado— es editar el
> historial, y Sonnet 5.5 / Haiku 5.5 lo castigan invalidando el razonamiento o con un 400. El precio: con la racha
> se tira una petición ya hecha, porque el runner ejecuta las herramientas al pedirle el siguiente
> mensaje; sólo se paga cuando el turno ya iba mal. Y sólo cuenta el texto de un mensaje SIN
> herramientas: el que acompaña a una llamada es un preámbulo («un momento, reviso…»).

Un turno del panel gastó **208 000 tokens de entrada en ocho vueltas y no contestó nada**. Así se
ve, y conviene reconocer la firma porque es siempre la misma:

```
vuelta 1 · entrada 20 635 · salida 33   → consultar_padron
vuelta 2 · entrada 20 860 · salida 32   → consultar_padron
…                                          la salida es SIEMPRE ~32 fichas: una llamada, nunca texto
vuelta 8 · entrada 32 545 · salida 32   → consultar_padron
WARNING  8 vueltas de herramientas sin respuesta final
```

**No era un eco.** Los saltos de entrada entre vueltas —+225, +1860, +2030, +1849, +2303, +1754,
+1889— son resultados de tamaños distintos, luego los argumentos cambiaban: el modelo probaba
**variantes** de una pregunta que la skill no sabía expresar (buscar a una persona, antes de que
existiera `persona`). Y cada «no existe, aquí tienes las opciones» le invitaba a la siguiente.

⚠️ **No era `mode: ANY`.** No hay `toolConfig` en el motor, así que Gemini iba en AUTO. Si
estuviera forzado harían bucle **todos** los turnos, y los de esa misma tarde contestaron a la
primera. Vale la pena descartarlo con el log antes que con la intuición.

### ⚖️ Comparar motores con conversaciones reales (17/10/2026)

`app:agent:replay <reserva> --motor=… --modelo=… --salida=archivo.json` reproduce una conversación
(o un `--guion`, que es la RUTA de un JSON con los mensajes del huésped) y **todas** las respuestas
las redacta ese motor: el triaje sigue clasificando con el de siempre, para que la pista y el tema
sean los mismos, pero su atajo de charla se salta. Cada turno queda con su respuesta, latencia,
herramientas y motivo.

🔒 **Cada conversación corre dentro de una transacción que se deshace al terminar** (18/10/2026,
con savepoints para que el fallo de una skill no envenene el resto del ensayo). Las skills `Interna`
se ejecutan de verdad y escriben: `confirmar_hora` dejó «salida 04:00» en la reserva de prueba
porque un huésped del guion dijo «nos retiraremos a las 4 am», y las conversaciones siguientes lo
leyeron como dato — la comparativa castigó a los modelos por una hora que no inventaron. Ahora la
hora, las peticiones anotadas y los avisos en cola (Messenger y `msg_*_send_queue` viven en la
misma base) se van con el rollback. Lo que no vuelve es lo que sale de la base en el momento: un
push, una llamada HTTP directa.

#### Lo que salió de la primera comparativa (18/10/2026)

10 conversaciones reales, 52 turnos, evaluadas a ciegas con las reglas de la casa (nota global de
1 a 5): DeepSeek 4,13 · Gemini 4,02 · Sonnet 5.5 3,92 · Haiku 5.5 3,33. Los tres primeros, empate
técnico con esta muestra; Haiku se desdice de servicios reales y escala de más. Sonnet no fue mejor
que Gemini, y **la causa era de configuración**, descrita en la guía de migración de Anthropic:

| Lo que vimos en Sonnet | Por qué | Qué se configura |
|---|---|---|
| Ante un «gracias», revisa, «corrige» lo que estaba bien y escala | Sonnet 5.5 vuelve sobre sus respuestas en cada mensaje; a esfuerzo `high` (su defecto) razona antes de casi cualquier respuesta | `ANTHROPIC_EFFORT=low` y la frase de «una respuesta dada está hecha» |
| Respuestas que empiezan «Además, …» | lo que escribe entre herramientas llega en bloques de razonamiento VACÍOS: su primera mitad se pierde | que el último mensaje valga solo |
| ~500 tokens de salida por turno para textos de 50–100 | razonamiento | el esfuerzo |

Las dos frases van en `AnthropicEngine::AJUSTES_DE_CHAT`, sólo para Claude (el prompt está afinado
con Gemini), detrás de `ANTHROPIC_AJUSTES_CHAT=1`; el esfuerzo, en `ANTHROPIC_EFFORT`. Las dos vienen
APAGADAS y tienen su valor por defecto en `services_agent.yaml` (`env(default:…)`), así que no
existir en el servidor no tumba el contenedor.

**Segunda ronda, mismo juego y mismo evaluador a ciegas, con seis versiones:**

| Versión | Global | Notas ≤ 2 | Notas = 5 | Turnos de «gracias» (15) |
|---|---|---|---|---|
| Sonnet, esfuerzo `low` + ajustes de chat | **4,44** | 2 | 33 | 4,73 |
| DeepSeek V4 | 4,33 | 2 | 26 | 4,40 |
| Sonnet, esfuerzo `low` | 4,21 | 6 | 30 | 4,07 |
| Gemini 3.8 Flash (producción) | 4,12 | 3 | 25 | 4,80 |
| Sonnet por defecto (`high`) | 4,02 | 6 | 21 | 3,60 |
| Sonnet, esfuerzo `medium` | 3,71 | 11 | 16 | 3,73 |

⚠️ **Pensar más empeora este chat, no lo mejora**: `medium` queda por debajo del defecto y de `low`.
Lo que arregla es la combinación: el esfuerzo bajo quita la revisión de lo ya contestado, y la
frase cierra los «gracias» (de 3,60 a 4,73). Lo que **no** arregla ninguna configuración es lo que
fallan todos los motores: anticipar costes y negativas no preguntados (recargo de horario, «no
puedo prometer»), y desdecirse de servicios que existen (Western Union, la custodia de equipaje).
Eso es contenido y prompt, no motor. Diferencias de 0,1–0,3 con 52 turnos son orientativas.

**Tercera ronda: esfuerzo × prompt por separado** (9 versiones a ciegas, Gemini de ancla). Además de
las dos frases de `AJUSTES_DE_CHAT` («reglas») se probó un **marco** delante, sólo para clientes:
«atiendes el chat de atención al cliente de un alojamiento…», cómo leer las mayúsculas de las reglas,
que lo confirmado por una herramienta sigue en pie, y que costes y limitaciones se cuentan cuando
la pregunta va de eso.

| Media global de Sonnet | sin ajustes | reglas | marco + reglas |
|---|---|---|---|
| `high` (defecto) | 4,04 | — | 4,42 |
| `medium` | 3,67 | 4,37 | 4,19 |
| `low` | 4,13 | 4,33 (1 nota ≤ 2; cierres 4,80) | 4,12 |

Gemini, en la misma ronda: 4,04.

- **El prompt pesa más que el esfuerzo.** Con las reglas se sube unas cuatro décimas (de 3,95 a
  4,35 de media). Cambiar el esfuerzo, en cambio, mueve la nota entre 0,1 y 0,15 sin una dirección
  clara.
- **`medium` sin reglas sale el peor en las dos rondas** (3,71 y 3,67). Con las reglas se recupera,
  porque el razonamiento extra es justo lo que le lleva a volver sobre lo ya contestado.
- **El marco no aportó** sobre las reglas: a esfuerzo `low` y `medium` restó. El único buen
  resultado (`high` + marco) no se repite en los otros dos esfuerzos y cuesta más: 2,0¢ por turno
  frente a 1,6¢. **Se quitó del código.** El contexto de «chat con un huésped» ya lo da el prompt
  común; lo que fallaba era otra cosa.
- **En producción desde el 18/10/2026**: `AGENT_IA_POTENCIA_ALTA` y `_MEDIA` (y con ellas el
  triaje) en `anthropic:claude-sonnet-5-5`, BAJA en Gemini Flash Lite. ⚠️ El servidor carga
  `.env.local.php`, un volcado: cambiar `.env` y desplegar **no cambia nada** hasta `composer
  dump-env prod` + `cache:clear` + reiniciar los workers. La clave llevaba días en `.env.local`
  sin volcar. Antes de volcar se comparó el volcado nuevo con el viejo (no se perdía ninguna
  variable); copia en `.env.local.php.bak-antes-claude-20261018`. Volver a Gemini es poner los
  dos tramos en `google:gemini-3.8-flash` en `.env.local` y repetir el volcado.
- **La configuración elegida para Claude es `ANTHROPIC_EFFORT=low` + `ANTHROPIC_AJUSTES_CHAT=1`.**
  Es la más estable de las dos rondas (4,44 y 4,33; 2 y 1 notas ≤ 2) y la que mejor cierra los
  «gracias».
- **Lo que siguen fallando todas, Gemini incluido:**
  - retractarse del almacén de equipaje ante un «gracias» (el turno c7-t2 lo fallan casi todas);
  - escalar dudas de pago que podía explicar;
  - la hora de salida «04:00»;
  - el enlace `wa.me/921166466` sin el prefijo 51.

  Gemini además se inventa el piso de la casita.

Para atribuir el coste, cada motor deja en el log una línea por turno con su MODELO: la de
Anthropic no lo llevaba hasta el 18/10/2026, y con Sonnet y Haiku en el mismo proveedor un coste
sin modelo no se podía repartir. Hasta el rollback del replay, los avisos que quedaban en cola tras
una prueba había que marcarlos `cancelled` a mano: con el `exchange:run` encendido en local habrían
salido a huéspedes reales.

### 🩺 La vigilancia del motor no espera a un huésped (17/10/2026)

`VigilanteDelMotor::motorCaido()` avisa a soporte cuando un turno falla, o sea **cuando un huésped
ya chocó** con el fallo. El 16–17/10/2026 se agotaron los créditos de Google y no lo supo nadie:
no entró ningún mensaje en ese rato, y se descubrió por casualidad al probar en local.

`app:agent:vigilar-motor` (cron, minutos 11 y 41) le pide al motor por defecto una generación
mínima —`MotorSondeableInterface::sondear()`; listar modelos no descubre unos créditos agotados— y
`VigilanteDelMotor::resultadoDelSondeo()` decide con `DecisionDelSondeo`:

| Situación | Aviso |
|---|---|
| falla por créditos o clave (401/402/403) | **ya**, con qué hacer («recargar en AI Studio», «revisar la clave») |
| falla por otra cosa | al **segundo** sondeo seguido: un corte de red no es un incidente |
| sigue caído y ya se avisó | **ninguno**; un recordatorio a las 24 h (caduca la marca) |
| vuelve a responder tras un aviso (del sondeo o de un turno) | **uno**: «vuelve a responder» |

⚠️ **Sin spam, también en el otro camino:** con la caída ya avisada por el sondeo,
`motorCaido()` deja de avisar por cada huésped que escriba (queda en el log). La marca vive en la
caché de la aplicación, así que un despliegue la borra: si el motor sigue caído tras desplegar,
sale un aviso más. Coste del sondeo: del orden de céntimos al mes.

### Las tres salvaguardas, y cuál hace qué

| Salvaguarda | Dónde | Qué evita |
|---|---|---|
| **Cierre forzado** sin herramientas | `GoogleAIEngine::cerrarSinHerramientas()` (`mode: NONE`), `DeepSeekEngine::cerrarSinHerramientas()` (`tool_choice: none`), `AnthropicEngine::forzarCierre()` (`toolChoice: none` vía runner) | Que el turno caro sea **además** mudo |
| **Racha de fallos** (`MAX_FALLOS_SEGUIDOS = 3`) | `ControlDeVueltas::finDeVuelta()`, en los tres motores | Que la racha llegue a ocho vueltas |
| **Llamada repetida** (`skill + argumentos`) | `ControlDeVueltas::yaServida()`; en Anthropic, dentro de cada herramienta | Pagar dos veces por la misma pregunta, y que una skill de escritura escriba dos veces |

**El cierre forzado es el que más vale.** Antes, agotar las vueltas devolvía
`ConversationResponse::vacia()`: el operador veía un silencio tras diez segundos y los 200 000
tokens no compraban ni una frase. Con `NONE` el modelo tiene que sintetizar con lo que ya está en
el hilo, que casi siempre basta para «encontré esto, me falta aquello».

⚠️ **Las `tools` siguen declaradas en la petición del cierre.** Con `functionCall` y
`functionResponse` ya dentro del hilo, quitar las declaraciones es un 400. Lo que se retira es el
permiso de volver a llamarlas.

🔥 **`NONE` solo no bastaba (12/10/2026).** Un prospecto preguntó a qué hora es el check-in, el
agente dio ocho vueltas sin encontrarla y el cierre volvió con **49 tokens de salida y cero de
texto**: el modelo quería seguir llamando y la API no le dejó. Turno perdido, acuse genérico y la
alerta de «el agente NO está contestando». El hilo acababa en los `functionResponse` y nada en él
decía que se habían acabado las consultas. Ahora el cierre añade al último turno de usuario
`GoogleAIEngine::ORDEN_DE_CIERRE` —contesta ya, con lo que sabes; lo que falte, que lo confirma el
equipo— y, si aun así vuelve vacío, el log dice el `finishReason` y cuántas llamadas pedía.

Y la causa de las ocho vueltas era un **dato que no llegaba**: la hora de entrada sólo viajaba con
una reserva. `ConsultarGuiaSkill::horasEstandar()` la da ahora al prospecto desde
`PmsEstablecimiento` (también sin casita). Probado sin ella: el modelo **se inventó «a partir de
las 15:00»** (son las 14:00). Un bucle largo casi siempre es un hueco de contenido, no un fallo del
motor: mira qué buscaba antes de tocar el tope.

⚠️ **En la última vuelta ya no se ejecuta nada**, como en DeepSeek: ejecutar sin poder volver a
llamar significa que una skill de escritura escribe y su resultado se tira, porque no queda quien
lo lea. Google lo hacía, y encima descartaba el resultado sin entregar respuesta.

### Qué cuenta como fallo, sin que el motor sepa de dominios

`RastroDeSkill::fueFallo()` mira **la forma de la respuesta**: `error` a secas —lo que emiten los
propios adaptadores cuando la skill no existe o está bloqueada— y cualquier clave `error_*`, como
`error_de_nombre`. La convención la fija el núcleo; el motor cuenta fracasos sin entender de qué
son.

⚠️ `error_de_nombre` viaja como resultado **correcto** a propósito —lleva las opciones reales para
que el modelo pregunte—, así que `SkillResult::esError()` dice `false` sobre él. Por eso el corte
no puede colgar de `esError()`.

### El log ahora dice con qué se llamó y qué se devolvió

El turno de las 208 000 fichas dejó ocho líneas `usa la skill "consultar_padron"` y **nada más**:
ni argumentos ni respuesta. Hubo que deducir lo que pasaba restando tamaños de entrada. Ahora:

```
Agent: admin (panel) usa la skill "consultar_padron" con {"expediente":"5SRAJV","subgrupo":"Fabio"}.
Agent: la skill "consultar_padron" devuelve error_de_nombre, opciones · 1 943 B.
```

Lo escribe `RastroDeSkill`, **una sola clase para los tres proveedores**: lo que se compara al
diagnosticar es «Gemini llamó así y Claude asá con la misma pregunta», y dos formatos obligan a
leer dos veces.

⚠️ **Del resultado salen las CLAVES y el tamaño, nunca el contenido.** El contenido son 131
personas con sus documentos: volcarlo sería duplicar la base de datos en un fichero de texto y
meter datos personales donde no pintan nada. Las claves bastan para lo que se pregunta, y el
tamaño explica cuánto crecerá la entrada de la vuelta siguiente.

### Lo que queda fuera, y por qué

- **Anthropic** no lleva cierre forzado: el bucle lo gobierna el `toolRunner` del SDK, no este
  código. Si aparece la misma firma allí, hay que mirar el SDK primero.
- **DeepSeek** ya cortaba bien —no ejecuta en la última vuelta y entrega lo que haya—, pero
  tampoco fuerza la síntesis. Cuando se use en serio, es el mismo método portado.
- **Presupuesto de tokens por turno** (cortar al superar ~100 000 acumulados) queda propuesto y
  sin hacer: con el cierre forzado y la racha de tres, el techo real baja a unas cuatro vueltas.

## 5.6 Los cuatro sitios donde vive lo que el agente sabe del negocio (18/09/2026)

Repitiendo una conversación real contra el agente de hoy, ofreció pagar «en efectivo en
**recepción**/check-in». No hay recepción. Y no había desobedecido nada: la nota del medio
`efectivo` decía «puedes pagar en efectivo **al hacer el check-in**» —el cuándo, nunca el
dónde—, y para un modelo el check-in de un alojamiento pasa en un mostrador. Se buscó
«recepción» en los 62 ítems de guía y no aparece, ni afirmada ni negada, así que nada le
contradecía. **Medio dato es peor que ninguno**: el hueco lo rellena con el hotel medio de su
entrenamiento.

La tentación es añadir otra prohibición. Ya está dicho «no inventes» y no sirvió: lo que faltaba
era el hecho, en positivo, allí donde el modelo lee. Y «allí donde lee» son tres sitios con
reglas distintas, que es lo que hay que tener claro para no crear dos fuentes que se separen:

| | qué le toca | qué NO puede decir |
|---|---|---|
| **Prompt** — `PmsInstruccionesDominio::COMO_ES_EL_SITIO` | los hechos invariantes del sitio: apartamentos independientes, sin recepción, entrada autónoma | horas, códigos, importes, políticas |
| **Guía** — `PmsGuiaItem` | todo lo que tiene casita, hora, código o política. **Manda siempre** | — |
| **Nota del medio** — `fin_medio_cobro.nota` (`fin:medios:notas`) | cómo se ejecuta **ese** pago concreto | cuándo hay que pagar, por dónde avisas |
| **Conocimiento** — `agent_conocimiento` | la respuesta larga a lo que se pregunta mucho | lo que la guía contesta mejor para un huésped |

El borrador del bloque de prompt decía «se atiende de 9:00 a 21:00» y se cayó por esa regla: ese
horario es la ficha `horario-solicitudes` y cambia desde el panel. El prompt que repite un dato
editable es el que miente el día que alguien lo edita.

### El conocimiento no estaba muerto, estaba vacío

La sospecha razonable era que nadie llama a `consultar_conocimiento`. El `info.log` de 30 días
dice lo contrario: **67 llamadas**, tercera skill más usada (`consultar_guia` 113,
`escalar_al_equipo` 95), de las que 35 llegaron a la lista de etiquetas y **12 entregaron
contenido escrito**.

Lo que sí estaba flaco era la despensa: 8 fichas, y 7 excluyen el perfil `huesped` a propósito
—para él la guía contesta mejor—, así que a un huésped confirmado el bloque de temas le enseña
**una sola categoría**. Las llamadas de actor «huésped» que sí reciben respuesta son casi todas
consultas de OTA sin confirmar, que son perfil `Interesado`.

### Lo que enseñó la revisión de la ficha nueva (mismo día)

Tres cosas que no se ven hasta que se da de alta una ficha y se mira alrededor:

1. **La etiqueta casa entera, no palabra a palabra.** `candidatosPara()` —la red que se tiende
   ANTES de escalar— intersectaba palabras sueltas de ≥4 letras. Las etiquetas de la ficha nueva
   aportan «tarde», «noche», «llego», «alguien»: un escalado con motivo «quiere salir más tarde»
   casaba, y `EscalarAlEquipoSkill` le entrega al modelo la ficha con un «díselo AHORA». Ahora
   cada etiqueta se exige completa. Regresión cubierta en
   `tests/Agent/Service/ConocimientoGenericoTest.php`.
2. **El tema lo elige la pregunta, no la materia.** La fase 1 enruta por la PISTA de la
   categoría, así que «llego de madrugada, ¿quién me abre?» cae en `llegada` («horarios de
   entrada y salida…») y nunca en `la-casa` («agua caliente, calefacción, cocina…»), por mucho
   que la ficha hable del edificio. Está en `llegada`.
3. **Un hecho nuevo deja cojos los textos vecinos.** Dos aparecieron solos: `consultar_codigos`
   decía «la caja del dinero NO EXISTE para el huésped» justo cuando la nota de `efectivo`
   acababa de explicarle que el dinero va ahí (lo reservado es el código, no la caja), y la ficha
   de equipaje prometía almacén sin decir quién recibe las maletas, que con «no hay personal en
   el sitio» obliga al modelo a inventarse a alguien. Al añadir un hecho hay que releer lo que lo
   rozaba.

### El conocimiento puede apuntar a la guía (19/09/2026)

Una entrada que dice «también hay calefactores» tiene dos salidas malas: copiar aquí el precio y
los horarios —que viven en la ficha «Alquiler de calefacción» y cambian ahí— o no remitir a
nada. Por eso el marcador `{{ ficha: calefactor }}` funciona ya en las dos fuentes: se convierte
en «(esto está en el tema «Alquiler de calefacción», que puedes consultar)», que el modelo puede
además SEGUIR con `consultar_guia`.

La resolución estaba dentro de `ConsultarGuiaSkill` y sale a `PmsEnlacesDeFicha`, que entra por
`ResolutorDeEnlacesInterface` (etiquetado `app.agent.resolutor_enlaces`).
`ConsultarConocimientoSkill` vive en `Skill/` y **no conoce ningún negocio** —la misma tabla la
leerá un pasajero de tours—, así que recibe la lista de resolutores: cada dominio entiende su
sintaxis y devuelve intacto lo demás. Un código que ya no existe se borra en silencio; remitir a
un tema que no está es peor que no remitir.

La conclusión no es aflojar esa acotación —se comprobó ficha por ficha que la guía cubre las
siete: `reglas` (mascotas), `ducha-casa-N` (agua caliente), `equipaje-horarios-flexibles`,
`estacionamiento`, `lavanderia`, `traslados`— sino **llenarla de lo que no está en ninguna
guía**: lo que el sitio ES. De ahí la ficha «Recepción y entrada autónoma», sin acotar, que
además hace de red antes de escalar por sus etiquetas (`candidatosPara()` las mira antes de
molestar a una persona, y hubo 95 escalados en 30 días).

---

## 5.7 Las fugas: lo que el agente decía sin que se lo preguntaran (02/10/2026)

Franco (W2YRVK) pulsó «Necesito más tiempo» y oyó dos veces que guardar el equipaje es gratis. El
texto decía «Es GRATIS para nuestros huéspedes: si pregunta el costo, díselo…»: **el dato suelto
delante de la condición**. Se revisaron las cuatro superficies que lee el modelo (§5.6) buscando
eso mismo, y había más.

**La guía publicada no se toca.** Lo que lee el huésped dice la política entera —costo, depósito,
cerrajero—, porque es lo que da claridad y respaldo (Jorge). Lo que se ajusta es lo que lee el
AGENTE, que es quien la suaviza.

Las tres formas en que se fugaba, y la que las sustituye:

| se escribía | qué hace el modelo | se escribe |
|---|---|---|
| el dato y luego «si pregunta, díselo» | lo dice de entrada | «SI PREGUNTA X: dato», con el dato DENTRO de la condición |
| «no le cuentes que hay otro huésped» con la hora al lado | lo cuenta | no se le manda el dato, sólo la conclusión |
| «cuéntale lo que dice su guía» | repite la política entera, costo incluido | de qué se habla: «dile que lo consultas con el equipo; la conversación es de horas y disponibilidad» |

Lo que cambió en el código ese día:

| dónde | antes | ahora |
|---|---|---|
| `PmsInstruccionesDominio::huesped()` (cada turno) | «que diga "con coste" te deja contarle las condiciones» | «dile que lo consultas… SI PREGUNTA SI TIENE COSTO: sí…» |
| `PmsInstruccionesDominio::espacioAlrededor()` | «sale otro huésped a las 17:00» + «NO le cuentes» | «la casita no está libre antes de las 17:00», «no cabe una salida tardía» |
| `PmsProcedenciaComercial` (cada turno) | «y sí aplica el depósito de garantía» | puntero al tema «Pago» |
| `escalar_al_equipo`, `confirmar_hora`, `consultar_guia` (`debes_escalar`) | «te deja contarle las condiciones», «lo que diga la política», «cuéntale lo que dice la guía» | «lo consultas con el equipo»; el costo, en su «SI PREGUNTA» |
| `anotar_peticion` | «mira cuánto cuesta y cuéntaselo» | «SI PREGUNTA CUÁNTO CUESTA, dale el precio de su guía» |
| `consultar_disponibilidad` | «úsalo como argumento de venta» (también en canal restringido, donde está prohibido) | «SI LA RESPUESTA TRAE servicio_en_otas: …» — el campo ya se quita en canal restringido |

⚠️ **Al añadir texto para el agente, mira primero si el dato va DENTRO de su condición.** El
«gratis» lo escribió una sesión que buscaba lo contrario —que ofreciera el almacén—, quitando el
freno de la oferta y llevándose con él el del precio.

## 5.8 El negocio de turismo: un prospecto también compra tours (11/10/2026)

Un desconocido escribió por WhatsApp «¿organizan transporte entre Cusco y Machu Picchu?» y el
agente contestó **«No organizamos transporte directo a Machu Picchu»**, con el tour «Machu Picchu:
Full day en tren turístico» publicado en openperu.pe. No fue el modelo: **para un prospecto el
negocio de tours no existía**, en ninguna de las tres capas.

| capa | qué había | qué hay |
|---|---|---|
| dominios del actor (`EnumeradorDeFrentes::dominiosPara()`) | sólo `hotelero`: era el único negocio vendible | `turistico` también, por `CotizacionFrentes::esVendible()` |
| skills | `anotar_pedido_cotizacion` (de `turistico`) filtrada fuera; ninguna que mirase el catálogo | `consultar_tours` + `anotar_pedido_cotizacion` |
| reglas (`InstruccionesDominioRegistry::para()`) | sin contexto ganaba UNO, el por defecto: «eres la primera impresión del alojamiento» | sin contexto hablan TODOS, el por defecto delante; tours añade `CotizacionInstruccionesDominio::VENTA` |
| conocimiento | la única ficha de transporte terminaba en «No ofrecemos traslado propio.» | la oferta en positivo: «si prefiere que le recojan, la agencia vende el traslado desde el aeropuerto», con puntero a `consultar_tours` (`Version20261011120000`) |
| guía (huésped) | la ficha «Traslados (general)» le decía «NO tenemos traslado propio» y, si insistía, «no lo hacemos, dilo claro» | lo mismo para el ALOJAMIENTO, más la oferta del traslado privado (`Version20261015120000`, 15/10/2026) |

⚠️ **La ficha de conocimiento NO llega al huésped.** Sus `perfiles` son prospecto, interesado,
personal y colaborador: al huésped de una casita le contesta la GUÍA (`consultar_guia` → tema
«Traslados»), y ésa siguió negando el traslado dos días después de que la agencia lo vendiera.
Ya había pasado el 07/10: «¿Disponen de servicio de traslado al aeropuerto?» → «No contamos con
servicio de traslado propio». **Una oferta nueva se busca en las dos superficies**: el conocimiento
(prospecto) y la guía (huésped). El reparto queda así: la guía o el conocimiento dicen CÓMO
llegar y que la agencia vende el traslado; `consultar_tours` (Travel) da el producto, el precio y
qué incluye; `anotar_pedido_cotizacion` lo deja pedido.

**Se ofrece con una pregunta, y como servicio nuestro (Jorge, 15/10/2026):** «la gente no
entenderá que el chat tiene dos personalidades». El cliente habla con UN chat; «el alojamiento no
tiene traslado, pero nuestra agencia sí» suena a dos casas. Así que en las dos superficies: se
contesta cómo llegar y se cierra con «¿quieres que te pase la información de un traslado privado
desde el aeropuerto?»; **sólo con un sí** se llama a `consultar_tours` (`Version20261015130000`). Y
en la app del huésped el traslado se presenta como nuestro y «con costo adicional»: «Escríbenos
por el chat y te pasamos el precio».

**El recojo de Booking** es gratis sólo para algunos clientes Genius, sobre todo en estancias
largas (Jorge). La ficha publicada dice «revisa tu reserva» y que se coordina «con Booking» —con
«con ellos» detrás de «clientes Genius», el «ellos» eran los clientes—; el agente, lo mismo
(`Version20261015140000`).

El modelo hizo lo que pudo con lo que tenía: buscó «servicios», luego «llegada», encontró la
negativa del aeropuerto y la generalizó. **Una negativa sin acotar en un texto del agente es una
negativa para todo lo que se le parezca.** Y si el producto existe —la agencia vende el traslado
del aeropuerto, catálogo «Traslados»—, la frase no se acota: se cambia por la oferta. Un «no» en el
conocimiento junto a un «sí» en el catálogo deja al modelo eligiendo.

```
prospecto (sin contexto)
  ├─ dominios: hotelero + turistico            ← los dos se declaran vendibles
  ├─ reglas:   PMS::prospecto() + Tours::VENTA ← el por defecto delante, «además…» detrás
  └─ skills:   consultar_disponibilidad, consultar_guia, …,
               consultar_tours, anotar_pedido_cotizacion
huésped de una casita (pms_reserva)
  ├─ reglas:   sólo PMS::huesped()             ← su contexto lo reconoce un dominio
  └─ skills:   … + consultar_tours             ← turistico es vendible: lo ve igual
```

**`consultar_tours`** (`src/Agent/Skill/Cotizacion/ConsultarToursSkill.php`):

- **Lee `CatalogoWebLector::paraElAgente()`: lo de la web y, además, lo marcado «lo ofrece el
  agente».** Mismas tarjetas que openperu.pe —tours publicados, precio oculto respetado, el «desde»
  de la ficha (`TourTarjetaResolver::preciosDesdeEfectivos()`)—; sólo cambia qué catálogos entran:
  `CotizacionCatalogo::loOfreceElAgente()` = activo y (publicado en la web **o**
  `ofrecidoPorAgente`). El enlace va a la ficha web o, si el catálogo no está en la web, a su
  página de pax (`/catalogo/{localizador}/p/{n}`).
- **Por qué no basta con «publicado en pax».** El enlace de pax se comparte en privado: un catálogo
  hecho para un colegio, con su nombre, vive ahí. El agente habla con cualquiera, y contarlo por
  WhatsApp es publicarlo. Primero se ató a la web (11/10/2026); el 13/10 se le dio su casilla
  porque los **traslados** se venden por chat pero, sin fotos, en la portada eran un hueco. La
  casilla sólo SUMA: lo publicado en la web lo ofrece siempre, así que no hay una segunda casilla
  que olvidar al publicar (`Version20261013090000`, en util: modal del catálogo, «Lo ofrece el
  agente»).
- **Filtra por palabras (`buscar`) y nunca devuelve vacío**: si nada coincide da el catálogo
  entero con el aviso de que lo que falte se arma a medida (`anotar_pedido_cotizacion`). Un «no
  tenemos Machu Picchu» por una palabra mal escrita es el fallo que vino a cerrar.
- **Con `tour` trae el detalle**: `incluye` / `no_incluye` / `opcional` / `cortesia` de
  `clasificacionFinancieraCliente.inclusiones` (lo mismo que «¿Qué incluye?» en pax, **sólo los
  nombres**, sin tarifas) y el itinerario por `ItinerarioCompuesto` (la regla de pax y del PDF),
  como «hora · título del segmento». El id se valida contra lo PUBLICADO antes de leer la base:
  lo escribe el modelo.
- **Enlaces según canal**: en una consulta de OTA sin reserva no viaja ninguno, por la misma
  lista que tapa `tours_catalog_url` (`RestriccionCanal::variablesBloqueadas()`). Los tours sí se
  cuentan: promocionarlos no está prohibido.
- **`idioma`** es `es` o `en` porque la web sólo tiene esos dos: decide los títulos y la URL.
- **Anotar no es escalar (Jorge, 13/10/2026).** `anotar_pedido_cotizacion` ya deja el pedido a
  Cotizaciones; `escalar_al_equipo` además —un WhatsApp al equipo— sólo si es para hoy o mañana.
  En la simulación de un traslado a cinco semanas vista hizo las dos cosas. Dicho en
  `CotizacionInstruccionesDominio::VENTA` y en el `aviso` que devuelve la propia skill al anotar.
- **Zona de venta (Jorge, 12/10/2026): todo el Perú y el Caribe.** Lo que no está en el catálogo
  pero cae ahí se arma a medida; otro destino ni se promete ni se niega. En los dos casos se anota
  (`anotar_pedido_cotizacion`). Sin la zona escrita, en la simulación afirmó «sí, organizamos» un
  tour a Puno sin saber si se hacía: un modelo al que se le dice «no digas que no» dice que sí a
  todo. La zona vive en `CotizacionInstruccionesDominio::VENTA` y en los avisos de la skill.

- **En turismo, cliente es quien PAGÓ (16/10/2026).** El vínculo del enlace `cotizacion_file` lo
  decide `CotizacionFile::vinculoComercial()`: abierto y con algún `CotizacionPago` vivo →
  `Cliente` (perfil `Huesped`); sin pagos → `Interesado`. Hasta ese día no existían pagos de
  turismo y nadie pasaba nunca a cliente. Se recalcula al registrar o anular un pago
  (`CotizacionFileConversacionListener`, el mismo que resincroniza el enlace al editar el
  expediente). Ver `docs/Cotizaciones.md` §6.a.

**Un expediente es de Turismo (16/10/2026):** `CotizacionInstruccionesDominio::supports('cotizacion_file')`.
Antes caía al camino sin contexto, y al **interesado** (expediente sin pagos) le llegaban las
reglas de consulta de OTA del alojamiento —«NO DES PRECIOS, lo ve en el anuncio de la
plataforma»— y la fase de la estancia («LLEGA HOY… la casita puede no estar lista»). Ahora recibe
la venta (`VENTA`) y ningún contexto volátil. Con el equipo Turismo no dice nada, y
`InstruccionesDominioRegistry::para()` tiene la regla que lo hace posible: **si el dominio propio
no tiene nada que decirle a ese perfil, cede el turno al resto** (el compañero conserva su bloque
de siempre).

**Al huésped de una casita, el hecho en positivo (17/10/2026):** «también somos agencia: tours por
todo el Perú y el Caribe, la respuesta sale de `consultar_tours`». Le habla sólo el alojamiento, y
lo que tenía de tours era una negación en la descripción de la herramienta —«nunca digas que no lo
organizamos»—, que el modelo incumple. Lo trae `PresentacionDeNegocioInterface`: un negocio puede
presentarse en conversaciones de OTRO, y el registro pone su frase detrás del bloque del dominio
propio. Turismo se presenta sólo al `Huesped` —al prospecto ya le llega `VENTA` y a una consulta de
OTA no se le ofrece nada fuera de la plataforma—. Y las negaciones de `consultar_tours` y de `VENTA`
se reescribieron en positivo: qué hacer y de dónde sale la respuesta.

**La regla de cuándo escalar viaja con la herramienta (17/10/2026):** «anotar ya avisa; escalar
sólo si es para hoy o mañana» vivía sólo en el bloque `VENTA`, que el huésped de una casita no
recibe (sólo le habla el alojamiento). Está ahora en la descripción de `anotar_pedido_cotizacion`,
escrita en positivo, y llega a cualquiera que tenga la herramienta.

⚠️ **Lo que queda por hacer:** el pasajero de un expediente no tiene bloque propio —su viaje, sus
fechas, su punto de encuentro—: recibe el de la venta. `CotizacionFrentes::frentesVivos()`
devuelve vacío porque la enumeración de frentes aún no la lee nadie para el prompt.

## 6. Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Añadir una herramienta nueva | `src/Agent/Skill/**` | implementar `SkillInterface`; se autolocaliza |
| Cambiar cuándo se corta un bucle de herramientas (los tres motores) | `src/Agent/Skill/ControlDeVueltas.php` | `MAX_VUELTAS`, `MAX_FALLOS_SEGUIDOS`, `ORDEN_DE_CIERRE`, `yaServida()` |
| Cambiar CÓMO se pide la respuesta sin herramientas en cada API | `GoogleAIEngine` / `DeepSeekEngine::cerrarSinHerramientas()` / `AnthropicEngine::forzarCierre()` | el formato es de cada motor; la decisión de cerrar, de `ControlDeVueltas` |
| Cuánto razona Claude y sus ajustes de chat | `.env` → `ANTHROPIC_EFFORT`, `ANTHROPIC_AJUSTES_CHAT` · `AnthropicEngine::AJUSTES_DE_CHAT` | apagados por defecto; para el chat, `low` + `1` (comparativa del 18/10/2026, tercera ronda) |
| Comparar cómo contesta otro motor con conversaciones reales | `src/Agent/Command/AgentReplayCommand.php` | `--motor`, `--modelo`, `--salida` (JSON por turno); el triaje clasifica con el de siempre y no responde |
| Cuándo avisa la vigilancia del motor (y cuándo calla) | `src/Agent/Service/DecisionDelSondeo.php` | `tras()`, `esDefinitivo()`, `pista()` — la frecuencia, en `tools/cron/www-data.crontab` |
| Que un negocio se presente en conversaciones de otro | `src/Agent/Contract/PresentacionDeNegocioInterface.php` | `presentacion()` — hoy Turismo al huésped de una casita |
| Cambiar qué se le dice al modelo al cortarle las herramientas | idem | `ORDEN_DE_CIERRE` |
| Qué horas de entrada/salida ve quien aún no reservó | `src/Agent/Skill/Pms/ConsultarGuiaSkill.php` | `horasEstandar()` — de `PmsEstablecimiento`; con reserva mandan las de la estancia |
| Cambiar qué se registra de cada llamada a skill | `src/Agent/Skill/RastroDeSkill.php` | `argumentos()`, `resultado()`, `fueFallo()` |
| Cambiar qué se devuelve de una persona del padrón | `src/Agent/Skill/Cotizacion/ConsultarPadronSkill.php` | `describirPersona()` |
| Cambiar cuánta gente recibe los horarios de sus vuelos | idem | `MAX_CON_HORARIOS` |
| Cambiar cómo se casa un nombre buscado | idem | `esEstaPersona()` / `comparable()` |
| Añadir una acción a una regla de intención | `src/Agent/Action/**` | implementar `BotActionHandlerInterface`; recibe **id + `ParametrosDeAccion`**, nunca la entidad |
| Que una skill sólo exista para un negocio | la propia skill | `SkillDominioInterface::dominios()` — **lista vacía = sin acotar** |
| Cambiar qué puede hacer un actor | `src/Agent/Access/AgentActor.php` | el constructor nombrado que le toque |
| Añadir un trabajo de sistema que consulte al modelo | `src/<Modulo>/Dispatch` + `DispatchHandler` | `AgentActor::sistema()` + `turnoDirecto()` **con esquema** |
| Cambiar cuánta cabeza se paga en un paso | `src/Agent/Conversation/PotenciaRequerida.php` | y `AGENT_IA_POTENCIA_*` en `.env` |
| Cambiar el modelo de un tramo | `.env` | `AGENT_IA_POTENCIA_{ALTA,MEDIA,BAJA}` = `proveedor:modelo` |
| Añadir un proveedor de IA | `src/Agent/Provider/**` | implementar `AgentEngineInterface`; `AgentEngineRegistry` lo recoge. Si habla JSON a pelo, su `*Respuesta::fromArray()` devuelve `RespuestaDelModelo` (§3.4) |
| Leer un campo nuevo de la respuesta de DeepSeek o Gemini | `src/Agent/Provider/{DeepSeek/DeepSeekRespuesta,Google/GoogleRespuesta}.php` | `fromArray()` — y su caso en `RespuestaDelModeloTest` |
| Cambiar cómo se cuentan los tokens de una vuelta | `src/Agent/Provider/Dto/ConsumoDeTokens.php` | ⚠️ cambia las cifras de `info.log` con las que se mide el coste: `LineasDeConsumoTest` |
| Leer un campo nuevo del JSON del triaje | `src/Agent/Triage/RespuestaDeTriaje.php` | `fromArray()`; la validación, en `Triaje::interpretar()` |
| Cambiar cuándo se pregunta ante dos herramientas | `src/Agent/Conversation/AclaracionDeEmpate.php` | `obliga()` |
| Cambiar qué puede empatar o enrutarse directo | `src/Agent/Triage/CatalogoDelTriaje.php` | `veEscrituras()` / `permitidas()` / `enrutablesDirectas()` |
| Ver qué skills tiene cada perfil | — | `php bin/console app:agent:permisos` |
| Repetir una conversación pasada contra el agente de hoy | `src/Agent/Command/AgentReplayCommand.php` | `app:agent:replay` |
| Cambiar qué cuenta el agente de los tours | `src/Agent/Skill/Cotizacion/ConsultarToursSkill.php` | `tarjeta()`, `inclusionesDe()`, `itinerarioDe()` — qué catálogos entran lo decide `CatalogoWebLector::paraElAgente()` (§5.8) |
| Que el agente ofrezca un catálogo que no está en la web | util → modal del catálogo → «Lo ofrece el agente» | `CotizacionCatalogo::$ofrecidoPorAgente`; la regla en `loOfreceElAgente()` **y** el DQL de `paraElAgente()` |
| Cambiar cuándo el agente ofrece tours | `src/Cotizacion/Service/Agent/CotizacionInstruccionesDominio.php` | `VENTA` (sin nombres de tours: van por la skill) |
| Que un negocio se le venda a cualquiera | su `FrentesPorDominioInterface` | `esVendible()` → entra en los dominios de todo actor |
| Cambiar qué negocios hablan en el prompt de un prospecto | `src/Agent/Conversation/InstruccionesDominioRegistry.php` | `para()` / `ordenados()` |
| Cambiar los hechos del sitio que el agente da por sabidos | `src/Pms/Service/Agent/PmsInstruccionesDominio.php` | `COMO_ES_EL_SITIO` — sin horas, códigos ni importes (§5.6) |
| Añadir una respuesta escrita para lo que se pregunta mucho | panel **Bot & IA → Conocimiento del agente**, o `AgentSembrarConocimientoCommand` | acotar con `perfiles`; el validador avisa si la guía ya lo dice |
| Remitir de un texto a otro sin copiarlo | el contenido, con `{{ ficha: codigo }}` | lo resuelve `PmsEnlacesDeFicha`; vale en guía y en conocimiento (§5.6) |
| Decir cómo se ejecuta un medio de cobro | `src/Finanzas/Command/FinNotasMedioCobroCommand.php` | `NOTAS` → `fin:medios:notas` (retraduce a los 7) |
