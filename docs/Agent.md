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
| `consultar_padron` | Responde «quiénes están en el grupo 6», «quiénes NO llevan vuelo nacional», «cuántos tienen el pasaporte vencido» |

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

### ⚠️ Todo lo que se devuelve va recortado, y con su total

Un padrón de colegio son 133 personas, 66 habitaciones y 23 localizadores. Volcarlos es media
ventana de contexto para una pregunta que casi siempre se responde con el número: `solo_conteo`
existe para eso, los nombres se cortan en 40 y las opciones se agrupan por eje con 12 valores cada
una. **El total va siempre**, que es lo que permite decir la verdad sin enseñarla entera.

Los «no participa» quedan fuera por defecto, como en el panel: conservan grupo y reservas aéreas,
así que contarlos infla cualquier respuesta sobre cuánta gente va.

## 6. Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Añadir una herramienta nueva | `src/Agent/Skill/**` | implementar `SkillInterface`; se autolocaliza |
| Añadir una acción a una regla de intención | `src/Agent/Action/**` | implementar `BotActionHandlerInterface`; recibe **id + `ParametrosDeAccion`**, nunca la entidad |
| Que una skill sólo exista para un negocio | la propia skill | `SkillDominioInterface::dominios()` — **lista vacía = sin acotar** |
| Cambiar qué puede hacer un actor | `src/Agent/Access/AgentActor.php` | el constructor nombrado que le toque |
| Añadir un trabajo de sistema que consulte al modelo | `src/<Modulo>/Dispatch` + `DispatchHandler` | `AgentActor::sistema()` + `turnoDirecto()` **con esquema** |
| Cambiar cuánta cabeza se paga en un paso | `src/Agent/Conversation/PotenciaRequerida.php` | y `AGENT_IA_POTENCIA_*` en `.env` |
| Cambiar el modelo de un tramo | `.env` | `AGENT_IA_POTENCIA_{ALTA,MEDIA,BAJA}` = `proveedor:modelo` |
| Añadir un proveedor de IA | `src/Agent/Provider/**` | implementar `AgentEngineInterface`; `AgentEngineRegistry` lo recoge |
| Cambiar cuándo se pregunta ante dos herramientas | `src/Agent/Conversation/AclaracionDeEmpate.php` | `obliga()` |
| Cambiar qué puede empatar o enrutarse directo | `src/Agent/Triage/CatalogoDelTriaje.php` | `veEscrituras()` / `permitidas()` / `enrutablesDirectas()` |
| Ver qué skills tiene cada perfil | — | `php bin/console app:agent:permisos` |
| Repetir una conversación pasada contra el agente de hoy | `src/Agent/Command/AgentReplayCommand.php` | `app:agent:replay` |
