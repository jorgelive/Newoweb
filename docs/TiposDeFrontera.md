# Tipos de frontera: DTO donde entra un dato de fuera

Todo dato que entra al sistema desde fuera —un webhook, la respuesta de una API, lo que escribe el
modelo en una skill, el cuerpo de una petición— llega como `array<mixed>`. Este documento dice
**dónde se convierte en tipos, con qué, y cómo se comprueba que la conversión no cambió nada.**

Nació de la subida de PHPStan al **nivel 9** (el de `mixed`), en septiembre de 2026. El nivel no
es el objetivo: lo es que cada frontera se lea **una vez**, en un sitio, y que el resto del código
trabaje con objetos que dicen lo que llevan.

## Índice

1. La regla
2. El lector: `App\Dto\Lee`
3. Cómo se cambia una frontera sin cambiar lo que se guarda
4. El mapa de fronteras y su estado
5. Lo que destapó por el camino
6. Dónde tocar para cambiar X

## 1. La regla

```
JSON de fuera ──► DTO::fromArray()  ──► el resto del código, tipado
                   └─ único sitio que toca el array crudo, con Lee::…
```

Por orden de preferencia:

| Situación | Qué se pone |
|---|---|
| El framework ya lo trae | eso: `#[MapRequestPayload]` para cuerpos HTTP, `#[Argument]`/`#[Option]` para comandos |
| Una forma externa que se lee en más de un sitio, o que tiene reglas | **un DTO** con `fromArray()` |
| El resultado de una consulta SQL cruda | un `@var list<array{…}>`: la forma la define la consulta y no se reutiliza |
| Un `getResult()` de Doctrine | `@var list<Entidad>` (regla de `CLAUDE.md`) |
| Un dato sin forma fija (una credencial en un JSON de configuración) | **último recurso**: el lector `Lee` en el sitio |

⚠️ **Un lector genérico en la lógica de negocio es la señal de que falta un DTO.** `Lee` vive
dentro de los `fromArray()`; si aparece en un servicio, algo se está leyendo dos veces.

## 2. El lector: `App\Dto\Lee`

**Lo que no es del tipo esperado es «no llegó» (`null`)**, no un `(string)` a ciegas. Un array donde
se esperaba texto era, con el cast, la palabra «Array» guardada y un warning.

| Método | Qué hace |
|---|---|
| `texto()` | el texto **tal cual**; números a texto como una interpolación |
| `textoLimpio()` | recortado, y el vacío es `null` |
| `entero()` / `decimal()` | aceptan el número en texto (`"1727312345"`, `"120.50"`) |
| `booleano()` | `true`/`"false"`/`"1"`…; ausente o basura es `null` («no se sabe» no es `false`) |
| `mapa()` / `listaDeMapas()` / `listaDeTextos()` | estructuras, descartando lo que no encaja |
| `objeto()` | un mapa con sus claves de texto: para lo que se **publica** con tipo (ver §5) |
| `en($x, 'a', 'b', 0)` | un campo anidado sin avisos por lo que falte |

⚠️ **`texto()` y `textoLimpio()` no son intercambiables.** Cambiar una por otra en un DTO que ya
está en producción cambia lo que se guarda. Los de Meta y pagos usan `texto()` porque sustituían
lecturas crudas y no debían cambiar ni un carácter; `Beds24BookingDto` usa la limpia porque su
problema era justo que el vacío se guardaba de dos formas.

🔥 **`booleano()` mira `null` y `''` antes de `filter_var()`.** Con `FILTER_NULL_ON_FAILURE`,
`filter_var(null, …)` devuelve `false`: un `conRecargo` ausente se leía como «sin recargo» —el
contrario del valor por defecto— en un enlace de pago. Lo cazó `DtoDePagosTest` antes de desplegar.

## 3. Cómo se cambia una frontera sin cambiar lo que se guarda

El patrón que se usó con Meta y con pagos, y que se repite en cada frontera con datos guardados:

1. **Inventario**: qué campos lee hoy el código (`grep` de las lecturas crudas).
2. **El DTO**, con esos campos y ni uno más.
3. **Antes de tocar el consumidor**, una prueba en `tools/pruebas/probar-dto-*.php` que recorre
   los datos REALES guardados —la auditoría de webhooks, las respuestas de las pasarelas— y compara
   la lectura cruda de antes con la del DTO, campo a campo. Se corre en el servidor, desde `/tmp`,
   sin tocar el árbol de producción: se copian el script y las clases NUEVAS a `/tmp/<algo>` y se
   cargan con `require_once` antes de usarlas, porque el autoloader de producción tiene las viejas
   (`probar-dto-canales.php` lo hace con `APP_DIR` y `DTO_DIR`).
4. Sólo con ✅ idénticos, se pasa el consumidor al DTO.
5. Después del despliegue, la misma prueba otra vez, y el flujo real con la transacción deshecha.

| Frontera | Prueba | Datos reales comparados |
|---|---|---|
| Webhook de Meta | `probar-dto-meta.php` | 3 250 webhooks: 456 mensajes, 2 794 estados |
| Pagos (Culqi, Izipay) | `probar-dto-pagos.php` | 18 enlaces, 15 cargos, 22 intentos auditados, 17 avisos |
| Booking de Beds24 | comparación de los dos caminos del DTO | 400 webhooks + 366 respuestas de pull |
| Webhook de Beds24 (sobre, mensajes, facturas) | `probar-dto-beds24-webhook.php` | 2 304 webhooks: 41 133 mensajes, 4 362 líneas de factura |
| Respuestas de los canales (Beds24, Meta, correo) | `probar-dto-canales.php` | 1 043 push, 49 962 tarifas, 7 697 envíos, 14 678 pulls (48 069 reservas), 173 075 mensajes y 15 028 líneas de factura recibidos, 6 201 cuerpos de Meta, 3 correos |
| Plantillas de Meta + columnas JSON de Mensajería | `probar-dto-plantillas.php` | 16 plantillas / 112 versiones de idioma (reconstruidas de lo guardado), 398 conversaciones, 8 323 mensajes |
| Respuestas de los modelos de IA | `probar-dto-ia.php` + `LineasDeConsumoTest` | 41 huellas reales de la escalera; no se guardan respuestas crudas, así que las líneas de consumo de `info.log` se comparan con el motor viejo y el nuevo sobre respuestas grabadas |
| Configuración de los calendarios | `probar-calendario-config.php` (foto `--guardar` con el código viejo, `--contra` con el nuevo) | 9 calendarios reales + 22 configuraciones sintéticas: 294 respuestas, 4 960 eventos/recursos idénticos, contra la base local |

## 4. El mapa de fronteras y su estado

| Frontera | DTO | Estado |
|---|---|---|
| Booking de Beds24 (pull y webhook) | `Beds24BookingDto::fromArray()` | ✅ un solo camino (26/09/2026) |
| Webhook de Meta | `src/Message/Dto/Meta/` | ✅ |
| Pagos: respuestas de Culqi, avisos, transacción, cuerpo del enlace | `src/Finanzas/Dto/` | ✅ |
| Webhook de Beds24: el paquete (reserva, mensajes, facturas, instante) | `Beds24WebhookSobre` | ✅ lo leían a mano el controlador y el worker |
| Entrada de las skills del agente (la escribe el modelo) | `EntradaDeSkill`, leída por el nombre que declara la `SkillDefinition` | ✅ `EntradaDeSkillTest` exige que cada nombre leído esté declarado |
| Consultas de Doctrine sin `@var` | el `@var` con el tipo que el propio método ya declaraba | ✅ 41, con un transformador guiado por PHPStan |
| Respuestas de los proveedores de IA (DeepSeek, Gemini) · JSON del triaje · sobre de Alexa | `RespuestaDelModelo` vía `DeepSeekRespuesta`/`GoogleRespuesta::fromArray()` · `RespuestaDeTriaje` · `PeticionAlexa` | ✅ Anthropic no aplica: su SDK ya tipa. El turno del modelo se devuelve a la API intacto (DeepSeek casa cada `tool_call_id`, Gemini 3 exige su `thoughtSignature`) |
| Respuestas de Beds24 (push, tarifas, mensajes, pull, facturas, paginación) | `src/Exchange/Dto/Beds24/Beds24Respuesta` | ✅ ids `int\|string` sin convertir; cada consumidor conserva su orden de ids y su éxito por defecto |
| Respuesta de la Graph API de Meta (envío y plantillas) | `src/Exchange/Dto/Meta/RespuestaGraphMeta` | ✅ destapó que los rechazos síncronos se daban por enviados (§5) |
| Correo: payload estrategia→cliente y resultado cliente→estrategia | `CorreoSaliente`, `ResultadoDelCorreo` | ✅ no es externa, pero cruza el motor como `array<mixed>`: un solo objeto escribe y lee |
| Sobre de Tuya | `src/Exchange/Dto/Tuya/RespuestaTuya` | ✅ sin datos guardados; lo cubre `RespuestaTuyaTest` |
| Credenciales de `MetaConfig` | `getCredential(): ?string` con `Lee::texto()` | ✅ último recurso (JSON de configuración) |
| Listado de plantillas de Meta | `PlantillaMeta::listaDesdeRespuesta()` (+ componente y botón) | ✅ lo leían tres sitios (sync, push, inventario) |
| Metadata del asunto que da el resolver de mensajes | forma `MetadatosDeAsunto` en `MessageDataResolverInterface` | ✅ contrato; PHPStan lo comprueba donde se construye |
| Cuerpo HTTP `{contextType, contextId}` | `App\Message\Dto\AsuntoPedido` | ✅ compartido por abrir hilo y cambiar titular |
| JSON de `MessageConversation::contextData` y `Message::metadata` | getters con comprobación (`textoDeContexto()`, `bloqueDeMetadata()`…) | ✅ columnas que Doctrine hidrata sin setters |
| Configuración de los calendarios (`parameters.calendars_*`) | `ConfiguracionCalendario::fromArray()` en `CalendarConfigResolver`, bloques en `src/Calendar/Config/` | ✅ interna, pero cada provider la leía a mano: 170 avisos |
| Argumentos y opciones de consola | `App\Command\EntradaDeConsola` (falla nombrando la opción) | ✅ Pms, Cotización, Travel, Operación, Domótica, Mensajería. Los del agente y los de `src/Exchange/Command` pasaron a invocables (`#[Argument]`/`#[Option]`); los 12 del cron de producción se comprobaron uno a uno con su invocación exacta. ⚠️ En un invocable, una opción numérica se declara `string` y se lee con `EntradaDeConsola::entero()`: con `int`, `--limit=abc` es un `TypeError` sin nombre |
| PATCH de un vuelo | `App\Cotizacion\Dto\CuerpoDeVuelo` (guarda qué campos vinieron: `trae()`) | ✅ |
| JSON de carga de vuelos (comando y expediente) | `VuelosImportador` con `Lee` | ✅ lo que no es objeto se dice y se salta; candidato a DTO |
| Logística específica del modal de segmento | `App\Travel\Dto\FilaDeLogistica::lista()` | ✅ valida TODO antes de purgar: antes una fila mala dejaba borradas las viejas |
| Suscripción push del navegador | `App\Api\Dto\CuerpoDeSuscripcionPush` | ✅ |
| Celdas del padrón (Excel sin formato) | `PadronFormato::celda()` | ✅ un `1` numérico en una columna de servicio era un `TypeError` |
| Respuesta de la API de tipo de cambio | `TipocambioManager::parseResponse()` → `ExchangeRateDto` | ✅ una fila no numérica se descarta y vale la última buena |
| `manifest.json` de Vite | `App\Service\Front\ManifiestoVite` | ✅ |
| Variables de ruta en providers y processors de API Platform | `App\Api\VariableDeRuta::texto()` | ✅ |
| Filas SQL y DQL escalares | `@var list<array{…}>` según el SELECT; `COUNT` como `int\|string` | ✅ |
| Cuerpos de un solo campo (`json` de vuelos, `canal` de una orden, `fecha` de tipo de cambio) | `Lee::texto()` en el controlador | ✅ sin DTO propio, a propósito |

**Cerrado el 26/09/2026: nivel 9 en `phpstan.dist.neon`, cero avisos.** Una frontera nueva entra
con su fila aquí; si no, el analizador no la deja pasar.

### Por qué la entrada de las skills no es un DTO por skill

Cada skill ya declara sus parámetros —nombre, tipo, obligatoriedad— en su `SkillDefinition`, que es
lo que ve el modelo. Un DTO por skill obligaría a escribir cada campo dos veces, y el día que
discrepen el modelo mandaría un campo que el código no lee, sin error: la skill contestaría «indica
la casita» para siempre. `EntradaDeSkill` lee por nombre con la semántica exacta del cast de antes
—salvo el array que se volvía «Array»—, y `EntradaDeSkillTest` recorre las skills y falla si una lee
un nombre que su definición no declara. Una sola fuente, y el descuido sale en los tests.

## 5. Lo que destapó por el camino

- **Beds24**: el pull construía el DTO por el serializer y el webhook por `fromArray()`. El mismo
  dato vivía de dos formas en la base (`''` y `NULL`). Ver `docs/PmsBeds24ReservasSync.md` §12.20.
- **Pagos**: `conRecargo` se leía con `(bool)`, y `(bool) "false"` es `true`.
- **Pagos**: el `?? 'Error desconocido'` de los rechazos de Meta nunca podía actuar: `json_encode()`
  falla con `false`, no con `null`.
- **El lector mismo**: el `filter_var(null)` de la sección 2, cazado antes de salir.
- **WhatsApp**: `WhatsappMetaSendMappingStrategy::parseResponse()` buscaba las claves del cuerpo de
  Meta en la fila que ya había normalizado el cliente. **Todo rechazo síncrono de Meta se daba por
  enviado**: 193 colas entre marzo y septiembre de 2026. Arreglado; ver `docs/Mensajeria.md` §14.c.
- **Plantillas**: al botón guardado le faltaba `content` en su tipo; tres entradas de la baseline
  protegían justo ese hueco.
- **Calendario**: `resources.establecimientoId` compara un UUID en texto contra `binary(16)` y da
  cero filas (hoy vale `null` en todos los calendarios). Y las exclusiones de
  `services_calendar.yaml` y `services_exchange.yaml` apuntaban a rutas que no existen.
- **Tarifas**: un precio nocturno que no era número se leía 0.00 y ese día salía gratis (calendario y
  push a Beds24). Ahora se ignora ese rango y cubre la tarifa base (`docs/PmsBeds24ReservasSync.md`
  §12.21).
- **Vuelos y nombres**: `emitido: "false"` marcaba el billete como emitido, y en
  `RevisorDeOrdenDeNombre` el texto `"false"` pedía invertir el nombre. El mismo `(bool)` que pagos.
- **Un comando que comparaba ids en texto contra un mapa indexado por el binario**
  (`detalles-a-audiencias`): contaba todo como pendiente. La familia de los UUID de `CLAUDE.md`.
- **Un `array<mixed>` en un getter publicado cambia el esquema de la API.** API Platform lee el
  docblock: `array<string, mixed>` es un objeto en el OpenAPI y `array<mixed>` es una lista. Al
  aflojar dos tipos para callar al analizador (`Cotizacion::$imagenTarjeta` y el `titulo` de
  `CotizacionFile::$propuestasFechas`), el `api.d.ts` regenerado pasó a decir «lista» de lo que
  viaja como objeto. Se estrecha en el provider con `Lee::objeto()`, no en el tipo. **Tras tocar
  tipos de entidades, regenerar `api.d.ts` y leer el diff.**
- **Arreglar lo que el tipado destapa cambia lo que se reintenta.** Dar por fallido un rechazo de
  Meta —correcto— convirtió en duplicados dos fallos que antes eran mudos: respuestas de un lote
  cruzadas por índice y timeouts de los que no se sabe si salieron (`docs/Mensajeria.md` §14.c).
  Antes de cambiar un «éxito» en «fallo», mirar qué hace el motor con un fallo.
- **La lectura estricta no siempre es la buena.** En la entrada de las skills, `Lee::entero()` hacía
  de «2 adultos» un 0 y los niños de una reserva pasaban a cero sin error. `EntradaDeSkill` volvió
  al cast para números; donde un vacío SIGNIFICA algo («canales» vacío = todos), una lista del
  modelo se lee como lista (`noEsTexto()`) en vez de como `''`.
- **Un `vendor` enlazado no carga el `src/` del worktree.** Con `vendor` como symlink al repo
  principal, el classmap optimizado resuelve `App\` contra el repo principal: `phpunit` y
  `bin/console` dentro de un worktree prueban el código de `master` y salen en verde. PHPStan no se
  ve afectado. Quien trabaje en un worktree necesita un autoloader que anteponga su `src/`.

## 6. Dónde tocar para cambiar X

| Necesidad | Dónde |
|---|---|
| Leer un campo nuevo de una frontera | el `fromArray()` de su DTO — y su `probar-dto-*.php` |
| Cambiar cómo se interpreta un tipo suelto | `App\Dto\Lee` — y su `LeeTest` |
| Añadir una frontera | un DTO en `src/<Modulo>/Dto/`, su prueba contra datos reales, y una fila en §4 |
