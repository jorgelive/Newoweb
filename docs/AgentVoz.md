# El agente por voz (Alexa)

Cómo se pregunta al agente del PMS desde un Echo, qué lo separa del asistente del panel y por
qué el canal es de solo lectura.

Este documento cubre `src/Agent/Alexa/`, `src/Agent/Service/VoiceAssistant.php` y
`src/Agent/Controller/Api/AlexaController.php`. Para el agente en sí —skills, motores,
permisos, triaje— manda `docs/Mensajeria.md` §11–§12; aquí sólo está lo que añade la voz.

## Índice

1. [La idea: un canal más, no un agente nuevo](#1-la-idea-un-canal-más-no-un-agente-nuevo)
2. [El sobre de Alexa](#2-el-sobre-de-alexa)
3. [Seguridad: qué autentica realmente este endpoint](#3-seguridad-qué-autentica-realmente-este-endpoint)
4. [La respuesta: por qué casi todo es 200](#4-la-respuesta-por-qué-casi-todo-es-200)
5. [Quién habla: el mapa de dispositivos](#5-quién-habla-el-mapa-de-dispositivos)
    · [5.1 Qué queda registrado de una consulta por voz](#51-qué-queda-registrado-de-una-consulta-por-voz)
6. [Los 8 segundos](#6-los-8-segundos)
7. [Solo lectura, y por qué](#7-solo-lectura-y-por-qué)
8. [Configuración y despliegue](#8-configuración-y-despliegue)
9. [El skill en la consola de Amazon](#9-el-skill-en-la-consola-de-amazon)
10. [Cómo probarlo](#10-cómo-probarlo)
11. [Dónde tocar para cambiar X](#11-dónde-tocar-para-cambiar-x)

---

## 1. La idea: un canal más, no un agente nuevo

El agente ya estaba preparado para esto y **no se tocó ni una skill**. `SkillInterface` es
dominio puro, `AgentActor` unifica orígenes y `SkillRegistry::paraActor()` filtra el catálogo
por rol. La voz entra por los mismos huecos que el panel y el chat:

```
  Echo ──► Amazon ──HTTPS──► AlexaController          (src/Agent/Controller/Api/)
                                  │
                                  ├─► AlexaFirma       ¿viene de Amazon?      §3
                                  ├─► PeticionAlexa    parsea el sobre        §2
                                  ├─► AlexaUsuarios    ¿de quién es la voz?   §5
                                  │
                                  └─► VoiceAssistant   (src/Agent/Service/)
                                          │
                                          └─► AgentEngineRegistry ─► motor ─► skills
                                                  ConversationRequest(
                                                      permitirEscritura: FALSE   §7
                                                  )
                                  ◄── RespuestaAlexa   sobre hablable         §4
```

**Decisión de diseño principal: un solo intent, no treinta.** El modelo de interacción de
Alexa tiene un único `ConsultaIntent` con un slot `AMAZON.SearchQuery`, y el enrutado lo hace
el modelo con el catálogo que ya conoce. Mapear cada skill a un intent de Amazon habría
duplicado ese enrutado en una consola web, y cada skill nueva —hoy: crear una clase, el
`AutoconfigureTag` la registra sola— habría exigido además editar el modelo a mano y volver a
compilarlo. Con el catch-all, **añadir skills no toca Alexa nunca**.

`VoiceAssistant` **no hereda ni envuelve a `PanelAssistant`**: su prompt es de pantalla y su
política de escritura es la contraria. Reutilizarlo obligaría a llenarlo de condicionales por
canal; treinta líneas de orquestación duplicadas salen más baratas, que es exactamente lo que
ya hace `PanelAssistant` respecto del chat del huésped.

## 2. El sobre de Alexa

`PeticionAlexa::desde()` reduce un JSON de seis niveles a lo que usa este skill. Dos trampas:

- **El `applicationId` y el `userId` viajan en dos sitios.** `context.System` es el bueno;
  `session` sobrevive por compatibilidad y puede traer valores distintos. Se lee `context`
  primero y `session` sólo como respaldo.
- **El historial viaja en `session.attributes`.** Alexa guarda lo que devolvimos y lo reenvía
  en el siguiente turno, así que el endpoint sigue **sin estado**, igual que el del panel. Se
  relee con desconfianza (`PeticionAlexa::historial()`) y recortado: el sobre de respuesta tiene
  tope de tamaño y un hilo largo lo agota.

## 3. Seguridad: qué autentica realmente este endpoint

⚠️ **`AlexaController` no lleva `#[IsGranted]`, y es intencionado.** Alexa no manda cookie ni
cabecera de autorización. El endpoint cuelga del host de API, que **no está en
`access_control`** y por tanto es `PUBLIC_ACCESS` — el mismo aviso que ya está escrito en
`config/routes.yaml` sobre `agent_api_controllers`.

Lo único que separa `/alexa` de un POST anónimo contra el PMS es `AlexaFirma`, en este orden
(descartan de más barato a más caro):

| # | Control | Qué impide |
|---|---|---|
| 1 | La URL del certificado es `https://s3.amazonaws.com/echo.api/...` | Que nos hagan descargar «su certificado» desde cualquier servidor |
| 2 | Ruta normalizada antes de comparar | `…/echo.api/../evil/cert.pem`, que pasa un `str_starts_with` ingenuo |
| 3 | Certificado en fecha y con `SAN = DNS:echo-api.amazon.com` | Un certificado de otro servicio |
| 4 | La cadena sube a una CA del sistema | Un autofirmado con el SAN correcto, que los pasos anteriores darían por bueno |
| 5 | `openssl_verify` del **cuerpo crudo** con RSA-SHA1 | Un cuerpo alterado en tránsito |
| 6 | `timestamp` a menos de 150 s | Reenviar una petición capturada |
| 7 | `applicationId` == `ALEXA_SKILL_ID` | **Que otro skill de Alexa apunte a esta URL.** La firma sólo prueba que viene de Amazon, no que venga del NUESTRO |

El paso 7 es el que se olvida y el que más duele: sin él, cualquiera publica un skill, apunta
su endpoint aquí y habla con el PMS con una firma impecable.

⚠️ El paso 5 va sobre `$request->getContent()`, **byte a byte**. Decodificar el JSON y volver a
serializarlo cambia espacios y orden de claves y la firma deja de cuadrar.

Amazon firma con **SHA-1**. Es heredado y no es elección nuestra.

## 4. La respuesta: por qué casi todo es 200

Superada la firma, **todo se responde 200 con un texto hablable**, incluidos los errores. Un
500 hace que el dispositivo diga *«hubo un problema con la skill solicitada»* y el operador se
queda sin saber si falló la red, el permiso o el dato; un texto nuestro se entiende.

La excepción es lo que no supera §3: ahí va **400 sin cuerpo**. No hay ningún usuario a quien
explicarle nada, y contestar 200 es invitar a insistir.

`shouldEndSession: false` mantiene el micrófono abierto — sin eso habría que repetir «Alexa,
pregúntale a recepción…» en cada pregunta, que es lo que hace inútil un asistente
conversacional. Cuando la sesión sigue abierta, el `reprompt` es **obligatorio** para
certificar.

**Gotcha**: `sessionAttributes` vacío se serializa `[]` en PHP y Alexa espera un objeto. Se
fuerza a `{}` con un `stdClass`. Sólo ocurre en los turnos de bienvenida y de cierre, así que
pasa las pruebas manuales y falla en producción.

## 5. Quién habla: el mapa de identidades

**Un altavoz identifica un sitio, no una persona.** Y es un escalón por debajo del WhatsApp: allí el
número lo verifica Meta en cada mensaje y hace falta tener el móvil desbloqueado, mientras que
a un Echo le habla cualquiera que pase cerca sin haber tocado nada. No hay contraseña que
pedirle a un micrófono. Por eso el chat del equipo sí abrió la escritura y la voz no.

### Los tres identificadores no son intercambiables

| Identificador | Dónde viene | Qué distingue |
|---|---|---|
| `amzn1.ask.person.…` | `context.System.person.personId` | **PERSONAS.** Sólo llega si esa voz tiene perfil entrenado y Alexa la reconoció |
| `amzn1.ask.device.…` | `context.System.device.deviceId` | **SITIOS.** El Echo del mostrador frente al de la oficina |
| `amzn1.ask.account.…` | `context.System.user.userId` | La cuenta de Amazon. **No distingue nada** si todos los Echo cuelgan de la misma cuenta, que es lo normal en un alojamiento |

El error que esto evita: registrar sólo la cuenta y creer que se están registrando los
dispositivos. Tres Echos de la misma cuenta mandan el **mismo** `userId`, así que todo el
mundo consultaría con la misma identidad.

### La cascada

`AlexaUsuarios::resolver()` prueba los tres **del más específico al más vago** y se queda con
el primero que esté en el mapa (`PeticionAlexa::identidades()`):

```
persona reconocida?  ──sí──► ese usuario          «lo pregunta Susan»
       │ no
       ▼
dispositivo en mapa? ──sí──► ese usuario          «lo pregunta el Echo de recepción»
       │ no
       ▼
cuenta en mapa?      ──sí──► ese usuario          «lo pregunta alguien de la casa»
       │ no
       ▼
   NADIE — cierra en falso
```

Una sola variable para los tres: los prefijos ya los distinguen, así que las claves se mezclan
en el mismo JSON.

```
ALEXA_USUARIOS='{
  "amzn1.ask.person.AB...":  "susan",
  "amzn1.ask.device.CD...":  "recepcion",
  "amzn1.ask.account.EF...": "operaciones"
}'
```

⚠️ **El `personId` no es una prueba de identidad**: es el reconocimiento de voz de Amazon, que
se equivoca y que no llega si nadie entrenó su perfil. Sirve para **atribuir**, no para
autorizar. Es otra razón por la que el canal entero es de solo lectura (§7).

**Cierra en falso** en los cuatro casos: sin `ALEXA_USUARIOS`, con ningún id en el mapa, con un
`username` que no existe en la base de datos, o con el usuario deshabilitado. Dar de baja a
alguien en el panel le cierra también esta puerta.

### Cómo se sacan los ids del log

Cuando **no** reconoce a nadie, el log escribe los tres completos. Son exactamente lo que hay
que copiar a `ALEXA_USUARIOS`, y van los tres porque cuál registrar —persona, sitio o casa
entera— es una decisión que el código no puede tomar por ti.

```
WARNING  Alexa: consulta no autorizada. persona=… dispositivo=… cuenta=…
```

⚠️ **Esa línea desaparece en cuanto registras una entrada de dispositivo o de cuenta**, porque
a partir de ahí siempre se reconoce algo. Y con ella se iría la única forma de averiguar el
`personId` de alguien para darlo de alta por voz: el huevo y la gallina de esta configuración.
Por eso `resolver()` registra también lo que **sí** resolvió, con el `personId` cuando viene:

```
INFO     Alexa: consulta de «susan.acuna» (por cuenta). persona=amzn1.ask.person.XXXX
                                                ↑              ↑
                                    voz / dispositivo / cuenta  el id que hay que registrar
                                    (cómo se resolvió)          para que esa voz sea ella misma
```

Así es como se pasa de «toda la casa consulta como Susan» a «cada voz es quien es»: que hable
cada uno una vez, se copian los `persona=` del log y se añaden al mapa.

Es además la traza de **quién** preguntó. Sin ella, con una red de seguridad puesta, todas las
consultas del log se ven idénticas pregunte quien pregunte — que es justo lo contrario de lo
que se busca al mapear identidades.

El actor se construye con `AgentActorFactory::delEquipoPorChat($usuario, 'alexa')` — no hizo
falta un constructor nuevo, el origen ya era un parámetro. Los roles van **efectivos**, con la
jerarquía de `security.yaml` expandida, igual que en el panel.

### 5.1 Qué queda registrado de una consulta por voz

Alexa es el único canal **sin base de datos detrás**: el cuerpo crudo del POST se lee una vez
para comprobar la firma y se tira, no hay tabla de auditoría como la `msg_meta_webhook_audit`
de Meta, y el historial de la conversación viaja en los `sessionAttributes`, o sea que vive en
Amazon, no aquí. Todo lo que sobrevive a un turno son líneas de log:

| Qué | Quién lo escribe | Nivel |
|---|---|---|
| Quién preguntó y cómo se le reconoció | `AlexaUsuarios::resolver()` | INFO |
| Qué skill se ejecutó | el adaptador del motor | INFO |
| **Qué se preguntó y qué se contestó** | `AlexaController::registrar()` | INFO |
| Alexa oyó algo que su modelo no encajó | `AlexaController::resolver()` | WARNING |
| Petición no autorizada, con los tres ids | `AlexaUsuarios::resolver()` | WARNING |

```
INFO  Alexa: «susan.acuna» preguntó «cuántas salidas hay hoy» → «Hay tres salidas: …»
```

Hasta 2026-08-12 faltaba justo la fila en negrita: se sabía **quién** habló y **qué skill**
corrió, pero no qué se dijo. En un canal que consulta cuentas de huéspedes y códigos de acceso
eso no basta para auditar nada.

⚠️ **Los códigos van tachados.** `AlexaController::sinSecretos()` sustituye toda tirada de 4 o
más dígitos por `····` antes de escribir la línea. `ConsultarCodigosSkill` devuelve códigos de
puerta y de caja fuerte y el asistente los dicta —para eso está—, pero un código hablado se
olvida y uno escrito en `info-2026-08-12.log` se queda ahí meses después de que el huésped se
haya ido. El log dice que se consultó el código; el código no es asunto suyo. El precio de la
regla es que un importe de cuatro cifras también sale tachado.

⚠️ Es un registro de **uso**, no un archivo: vive en el log `info-…` y rota a diario. Si algún
día hace falta conservarlo, eso ya es una tabla, no una línea.

## 6. Los 8 segundos

Alexa corta la petición a los ~8 s. El camino largo del agente —motor, N llamadas a skills,
segunda pasada— se los come con facilidad. Lo que hay hoy:

- `VoiceAssistant::MAX_TOKENS = 600`. Un turno hablado no pasa de dos frases; pedir más sólo
  gasta segundos.
- `ALEXA_PROVEEDOR` y `ALEXA_MODELO` permiten fijar un motor rápido **sólo para voz**, sin
  tocar el del panel. Vacíos, se usa el de por defecto.
- El prompt de voz prohíbe listas y resúmenes largos, lo que acorta la generación.

**Lo que NO está hecho y es la siguiente palanca si se queda corto:** la *Progressive Response
API*, un directivo que hace hablar al dispositivo («dame un segundo») mientras se sigue
procesando. Compra tiempo, no lo elimina.

## 7. Solo lectura, y por qué

`ConversationRequest(permitirEscritura: false)`. No es una preferencia:

- Un Echo no autentica a quien habla (§5).
- Las skills de escritura de este sistema mueven dinero (`RegistrarPagoSkill`,
  `RegistrarCargoSkill`) o cambian reservas (`ModificarReservaSkill`).
- El reconocimiento de voz se equivoca. «sesenta» y «setenta» a tres metros de distancia son
  la misma palabra más veces de las que uno cree.

`SkillRegistry::paraActor()` ya sabe filtrarlas, y **deja pasar `NivelRiesgo::Interna`** a
propósito: avisar al equipo o escalar sigue siendo posible por voz.

El prompt además se lo dice al modelo explícitamente, para que ante un «apúntame que Natalia
pagó 60» conteste que eso se hace desde el panel en vez de buscar una herramienta que no tiene.

## 8. Configuración y despliegue

Tres variables, **todas opcionales en el sentido técnico** y todas necesarias para que el
skill haga algo:

| Variable | Para qué | Sin ella |
|---|---|---|
| `ALEXA_SKILL_ID` | `amzn1.ask.skill.…` del skill propio | El endpoint rechaza **todo** con 400 |
| `ALEXA_USUARIOS` | JSON de id de Alexa a `username`; claves de persona, dispositivo y/o cuenta mezcladas (§5) | Contesta «no está configurado todavía» |
| `ALEXA_PROVEEDOR` / `ALEXA_MODELO` | Motor rápido sólo para voz | Se usa el de por defecto |

⚠️ Se leen con `#[Autowire('%env(default::VAR)%')]`, con **`default::`**. Una variable
inexistente referida como `%env(VAR)%` lanza `EnvNotFoundException` **al resolver parámetros**,
y eso no rompe sólo este endpoint: tumba cualquier cosa que resuelva parámetros. Es el caso de
`CULQI_ENDPOINT` que se llevó por delante el calendario entero (§12 de
`docs/FinanzasEnlacesPago.md`). Con `default::`, sin variable el skill queda mudo y el sistema
sigue en pie.

Van en `.env.local` del servidor. Y como siempre: **`composer dump-env prod` no lo hace
`git pull`** — una variable nueva no existe para la aplicación hasta que se vuelca.

No hay que tocar `config/routes.yaml`: `agent_api_controllers` ya mapea
`src/Agent/Controller/Api/` al host de API. Ni `services_agent.yaml`: `App\Agent\:` carga la
carpeta entera con autowire.

## 9. El skill en la consola de Amazon

Tipo **Custom**, endpoint **HTTPS** (no Lambda), apuntando a `https://<host-api>/alexa`, con la
opción *«mi certificado de desarrollo es un certificado comodín de una autoridad
certificadora»* si el dominio va con un wildcard. Idioma: el español que corresponda
(`es-MX` / `es-US`); el skill sólo existe en los idiomas que se le añadan.

### 🔇 «No sé cómo puedo ayudarte con eso»: el idioma del dispositivo

Es el fallo que más despista, porque **no deja rastro en nuestros logs**: Alexa responde ella
misma y nunca llama al endpoint. Si el skill parece muerto en un Echo pero el simulador va bien,
mira esto ANTES que nada.

El skill existe **por idioma**, no por cuenta. Si está construido sólo en `es-MX` y el Echo está
configurado en `es-US`, para ese dispositivo el skill **no existe**: el nombre de invocación no
resuelve y Alexa contesta que no sabe ayudarte. En Perú los dispositivos vienen a menudo en
`es-US`, así que el caso es la norma, no la excepción.

Cómo distinguirlo de un problema nuestro, en un vistazo:

| Síntoma | Dónde está |
|---|---|
| Alexa dice «no sé cómo puedo ayudarte con eso» y **no hay petición en el log** | Consola: idioma o nombre de invocación |
| Llega la petición y responde «este dispositivo no está autorizado» | `ALEXA_USUARIOS`: falta el id, y el log lo trae |
| Llega y contesta con un saludo que no toca | `AlexaController::BIENVENIDA` sin actualizar |

```bash
ssh <servidor> 'grep -c app_agent_alexa /var/www/<proyecto>/var/log/info-$(date +%F).log'
```

Cero peticiones tras hablarle al Echo = no es nuestro.

**El arreglo** es añadir el idioma que use el dispositivo en la consola (*Distribution → Skill
locales*) y copiar ahí el modelo de interacción; el endpoint y el código no cambian, el skill es
el mismo. Para una prueba rápida basta con poner el Echo en el idioma que sí está construido,
desde la app de Alexa: Dispositivos → el Echo → Idioma.

⚠️ Y al añadir un idioma hay que **reconstruir el modelo** («Build Model»): el nombre de
invocación y los intents se compilan por idioma, así que uno recién añadido nace vacío aunque el
skill lleve meses funcionando en el otro.

### Nombre de invocación

Es lo que se dice en voz alta y **no está en el código**: se elige en la consola. El acordado es
**`openperu`**:

```
Alexa, abre openperu                          → LaunchRequest, micrófono abierto
Alexa, pregúntale a openperu qué salidas hay hoy    → IntentRequest de un tiro
```

Empezó siendo `el sistema`, que cumplía el formato de Amazon —dos palabras, minúsculas, sin
palabras de activación— pero era **genérico**: la revisión de certificación rechaza por eso un
skill público, y sobre todo no dice nada a quien lo usa. `openperu` es el nombre de la casa.

⚠️ Al cambiarlo hay que tocar **una línea del código**: `AlexaController::BIENVENIDA`. Es la
única atadura entre la consola y el repositorio, y es fácil de olvidar porque nada falla — el
skill sigue funcionando, sólo que se abre con un nombre y responde con otro, que suena a haber
llamado a quien no era.

Ojo con el formato si algún día se publica: Amazon pide dos palabras salvo que el nombre sea una
marca propia. `openperu` lo es, pero eso lo decide la revisión, no nosotros. En *beta testing*,
que es como se distribuye (§9), no hay revisión.

Con la sesión abierta ya no hace falta repetir «Alexa» ni el nombre: se pregunta directamente,
que es lo que hace utilizable el canal. `AMAZON.StopIntent` («Alexa, para») cierra.

### Modelo de interacción

```json
{
  "intents": [
    { "name": "AMAZON.HelpIntent",     "samples": [] },
    { "name": "AMAZON.StopIntent",     "samples": [] },
    { "name": "AMAZON.CancelIntent",   "samples": [] },
    { "name": "AMAZON.FallbackIntent", "samples": [] },
    {
      "name": "ConsultaIntent",
      "slots": [{ "name": "consulta", "type": "AMAZON.SearchQuery" }],
      "samples": [
        "que {consulta}",
        "dime {consulta}",
        "dime que {consulta}",
        "pregunta {consulta}",
        "sobre {consulta}",
        "necesito saber {consulta}",
        "quiero saber {consulta}",
        "averigua {consulta}",
        "revisa {consulta}",
        "busca {consulta}",
        "consulta {consulta}",
        "cuántas {consulta}",
        "cuántos {consulta}",
        "cuál es {consulta}",
        "cuándo {consulta}",
        "a qué hora {consulta}",
        "quién {consulta}",
        "dónde {consulta}",
        "hay {consulta}"
      ]
    }
  ]
}
```

⚠️ **Un `{consulta}` a secas NO es válido con `AMAZON.SearchQuery`**: Amazon exige al menos una
palabra portadora delante del slot y rechaza el modelo al compilarlo.

**Y de ahí sale la trampa que costó una tarde de depuración**, porque no falla en ningún sitio
donde se pueda ver: si la frase no empieza por una de las portadoras de la lista, Alexa **no
enruta al skill y la petición NUNCA LLEGA AL SERVIDOR**. No hay log, no hay error, no hay 4xx —
el dispositivo contesta «no tengo claro cómo puedo ayudarte con eso» y desde el backend parece
que nadie ha hablado. Con solo cinco portadoras, un «cuántas casitas hay libres mañana» —la
forma en que habla cualquiera— era irrutable.

Por eso la lista es larga y hay que ampliarla cuando aparezca una forma de preguntar que se
quede fuera. Dos familias, con distinta consecuencia:

- **Portadoras vacías** (`dime`, `pregunta`, `averigua`, `busca`…): Alexa se las come y el slot
  llega íntegro. Son las buenas.
- **Palabras de pregunta** (`cuántas`, `cuándo`, `dónde`…): también funcionan, pero **la
  portadora se pierde**: «cuántas casitas hay libres mañana» llega al modelo como «casitas hay
  libres mañana». El sentido se sostiene y el modelo responde bien, pero conviene saber que el
  texto que ve no es el que se dijo.

Diagnóstico rápido cuando «no contesta»: mira si hay petición en nginx
(`grep " /alexa " access.log`). **Sin petición, el problema es el modelo de interacción, no el
código.** Con petición y sin línea `Agent (…)` detrás, el problema es de permisos o de motor.

`PeticionAlexa::slot()` busca `consulta` y, si no está, coge el primer slot con valor:
renombrarlo en la consola no debería dejar el skill mudo sin ninguna pista de por qué.

**Atajo que evita la certificación**: en *beta testing* el skill se distribuye a cuentas
concretas sin pasar por la revisión de Amazon. Para uso interno es lo que se quiere.

## 10. Cómo probarlo

Sin suite de tests, la verificación es la del proyecto más un arranque del kernel:

```bash
php -l src/Agent/Alexa/*.php
php bin/console lint:container
php bin/console router:match /alexa --method=POST   # avisa del host que exige la ruta
```

El endpoint entero se puede ejercitar sin servidor web arrancando el kernel y pasándole un
`Request::create()` contra el host de API (`%env(API_HOST)%`): sin cabeceras de firma tiene que
responder **400**.

Verificado así al construirlo: se rechazan `http://`, otro host, ruta fuera de `/echo.api/`,
puerto distinto de 443, salto de directorio, marca de tiempo de hace una hora, del futuro e
ilegible; y un certificado caducado de Amazon (las URLs versionadas viejas siguen publicadas y
sirven de caso real). **El camino feliz completo sólo se puede probar con una petición
auténtica**, porque exige una firma que sólo Amazon puede producir: para eso está el simulador
de la consola de desarrollo.

## 11. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Dar de alta una persona, un Echo o la casa entera | `.env.local` del servidor | `ALEXA_USUARIOS` (los tres ids salen del log, §5) |
| Saber quién preguntó, o pescar un `personId` con la red ya puesta | `AlexaUsuarios::resolver()` | la línea `INFO Alexa: consulta de …`, §5 |
| Saber qué se preguntó y qué se contestó (§5.1) | `AlexaController` | `registrar()` |
| Cambiar qué se tacha del log (§5.1) | `AlexaController` | `sinSecretos()` |
| Cambiar la prioridad persona → sitio → cuenta | `PeticionAlexa` | `identidades()` |
| Cambiar qué dice al abrir o al no entender | `AlexaController` | `BIENVENIDA`, `REINTENTO`, `AYUDA` |
| Cambiar cómo habla el asistente | `VoiceAssistant` | `systemPrompt()` |
| Permitir escritura por voz (piénsalo dos veces, §7) | `VoiceAssistant::preguntar()` | `permitirEscritura` |
| Acelerar las respuestas | `.env.local` + `VoiceAssistant` | `ALEXA_MODELO` / `MAX_TOKENS`, §6 |
| Cuánta conversación se recuerda | `AlexaController` | `MAX_TURNOS_RECORDADOS` |
| Ajustar la ventana anti-repetición | `AlexaFirma` | `TOLERANCIA_SEGUNDOS` |
| Añadir un intent de Alexa | `PeticionAlexa` + `AlexaController::resolver()` | `pideAyuda()`, `pideSalir()` |
| Añadir una capacidad al agente | — | Crear la skill; **Alexa no se toca** (§1) |
