# Newoweb — Guía para Claude

## Stack

- **Backend:** Symfony (PHP >= 8.4), API Platform, Doctrine ORM, EasyAdmin (panel legacy).
  Comandos: `php bin/console ...`. Verificación: `php bin/phpunit`, `vendor/bin/phpstan analyse`,
  `php -l`, `lint:container` y, cuando aplica, ejecutar el flujo real.

  **Análisis estático:** PHPStan **nivel 6** sobre `src/` (menos `src/Oweb/`, que se retira
  entero), con `phpstan-baseline.neon` congelando la deuda que ya existía. Correrlo antes de
  cerrar un cambio no es opcional; es más barato que cualquier test que se pueda escribir para
  cubrir lo mismo.

  Estuvo en nivel 2 hasta el 15/08/2026, porque ése caza **variables indefinidas y métodos que
  no existen** —un `$message` donde la variable era `$msg` pasó `php -l`, pasó los tests y habría
  tumbado TODO el envío saliente en el primer mensaje—. Subió a 5 tras medir que el `TypeError`
  que tumbó la sincronización de reservas canceladas **no lo cazaba ningún nivel del 2 al 9**: el
  valor salía de un `array` sin tipar, que es `mixed` implícito. El 5 es el que revisa tipos de
  argumento, que es esa familia entera, y del 4 al 5 sólo hay once avisos más.

  Y de ahí al **6**, que es el que cierra el agujero de raíz: **prohíbe el `array` sin declarar
  qué lleva dentro**. Con el 6 puesto, la firma que dejó pasar el fallo no habría compilado.

  ⚠️ **Un `array` pelado en una firma nueva ya no pasa.** Escribe el tipo del valor
  (`array<string, string>`) o, mejor, un tipo propio — ver `MomentoDeHito`/`MapaDeHitos` en
  `docs/Mensajeria.md` §22.16. Quedan ~760 anotaciones viejas en el baseline, y se quitan por
  módulo al tocarlos: escribir 760 `@var` de golpe garantiza escribir algunos mal, y un tipo
  mentiroso es peor que ninguno.

  ⚠️ La baseline **no es una lista de perdonados**: es la foto del día que se instaló, para que
  salte sólo lo nuevo. Si tocas un archivo que tiene errores dentro, arréglalos y quítalos de
  ahí.

  **Tests:** PHPUnit 13 (`phpunit.dist.xml`), suite en `tests/` espejando `src/`. Hoy sólo hay
  tests **unitarios puros** —sin contenedor ni base de datos— sobre las piezas de decisión del
  agente: vínculo comercial, restricción de canal, perfil de conversación, actor y desbloqueo
  de WhatsApp. Corren en centésimas y no necesitan nada montado.

  Lo que NO está cubierto y hay que probar a mano: todo lo que toca base de datos
  (`PmsGuiaArbolFiltro`, `ConsultarCuentaSkill`, los listeners de coherencia financiera). Para
  eso haría falta base de test y `dama/doctrine-test-bundle`; hasta entonces, ese código se
  verifica ejecutando el flujo real. Y ojo: los dos fallos más caros de agosto de 2026 fueron
  de **datos**, no de código —cargos huérfanos y flags mal puestos—, y eso no lo pesca ningún
  test unitario: se pesca auditando datos reales.
- **Frontend:** dos apps Vue 3 + TypeScript independientes.
  - `util/` — app interna de operación (calendario de reservas, tarifas, cotizaciones, chat).
  - `pax/` — app pública del huésped (guía, vista cliente de cotizaciones).
  Verificación: `cd util && npm run typecheck` **y** `npx eslint .` (idem en `pax/`). Las dos
  tienen que salir limpias.

  **ESLint** (config plana, `eslint.config.js` en cada app: `eslint-plugin-vue` +
  `typescript-eslint`). Está afinado para que **cada aviso sea un aviso**: todas las reglas de
  formato de plantilla —sangría, orden de atributos, saltos de línea— están desactivadas
  explícitamente y con el motivo escrito en el propio archivo. No es dejadez: mezcladas con las
  demás sepultaban lo que importa bajo más de mil quejas cosméticas, y una lista así no la revisa
  nadie. Lo que queda son reglas que cazan **errores**: `v-for` sin `key`, atributos inválidos,
  claves duplicadas entre prop y ref, `any`, expresiones sin efecto.

  Si una regla molesta de verdad, se desactiva **con el porqué al lado** (`-- motivo`), no se
  silencia el archivo entero. `src/types/api.d.ts` y `src/vite-env.d.ts` están ignorados porque
  se generan enteros.
- **Integraciones:** Beds24 (channel manager) vía el motor genérico de `src/Exchange/`.

## Documentación — obligatoria

**Todo cambio importante termina en `docs/`, en la misma sesión en que se hace.** No es un
paso opcional ni "para después": un `.md` desactualizado cuesta más que no tenerlo.

### Qué se documenta

Lo que **no se ve leyendo el código**:

- **Reglas de negocio** y sus condiciones de borde, sobre todo las implícitas o asimétricas
  (ej.: registrar un pago confirma la reserva, pero quitarlo no la des-confirma).
- **Flujos entre módulos**: quién dispara a quién, en qué orden, con qué garantías.
- **Decisiones de arquitectura y su porqué**, incluidas las alternativas descartadas.
- **Gotchas**: trampas de librerías, cachés, orden de listeners, comportamientos no obvios
  del framework. Si costó media hora entenderlo, va documentado.
- **Espejos PHP ↔ TypeScript**: cuando una regla vive en los dos lados, ambos archivos se
  citan mutuamente y el doc dice explícitamente que hay que tocar los dos.

### Qué NO se documenta

Refactor cosmético, renombres, ajustes de estilo, fixes triviales, o cualquier cosa que el
código ya diga con claridad. Documentación de relleno es ruido que envejece mal.

### Mapa módulo → documento

| Tocas… | Actualiza |
|---|---|
| `src/Pms/`, `src/Exchange/` (reservas, webhooks, push/pull Beds24) | `docs/PmsBeds24ReservasSync.md` |
| `src/Calendar/`, vistas de calendario de `util/` (Reservas, Tarifas) | `docs/Calendar_architecture.md` |
| `src/Cotizacion/`, cotizaciones y catálogo en `util/` y `pax/` | `docs/Cotizaciones.md` |
| `util/src/components/common/`, `util/src/types/modulosApp.ts` (UI y navegación reutilizables entre módulos) | `docs/UI_Componentes_Compartidos.md` |
| `src/Operacion/`, La Biblia y Órdenes de Servicio en `util/` | `docs/Operacion.md` |
| `src/Pms/Guia/`, entidades `PmsGuia*`, guía del huésped y catálogo en `pax/` | `docs/PmsGuiaHuesped.md` |
| `src/Message/` (chat, reglas de envío, colas Beds24/WhatsApp), `src/Pms/Service/Message/` | `docs/Mensajeria.md` |
| `src/Agent/` (el agente: entradas, actores, contratos, potencia) | `docs/Agent.md` — el detalle del chat sigue en `docs/Mensajeria.md` |
| `src/Contract/` (núcleo compartido: vínculo, frente, categoría) | `docs/Agent.md` §5 |
| `util/public/push-sw.js`, `*/vite.config.ts` (bloque `VitePWA`), `*/scripts/pwa-*.mjs`, `src/Service/WebPushNotificationService.php`, `PushSubscription*` | `docs/PwaNotificaciones.md` |
| `src/Pms/Service/Reserva/PmsDisponibilidadService.php`, `PmsEventoEstado::IMPIDEN_VENTA` | `docs/PmsDisponibilidad.md` |
| `src/Finanzas/`, resolvers `*/Finanzas/*OrigenCobroResolver.php`, cobros en `util/` y `pax/` | `docs/FinanzasEnlacesPago.md` |
| `src/Service/Phone/`, listeners de integridad de teléfonos, `util/src/utils/telefono.ts` | `docs/Telefonos.md` |
| `src/Agent/Alexa/`, `VoiceAssistant`, `AlexaController` (el agente por voz) | `docs/AgentVoz.md` |
| `src/Logging/`, `config/packages/monolog.yaml`, rotación de logs | `docs/Logging.md` |
| `config/packages/mailer.yaml`, variables `MAILER_*`, envío por Graph | `docs/CorreoSaliente.md` |
| `src/Domotica/`, `TuyaExchangeClient`, aparatos inteligentes (consumo y estado) | `docs/Domotica.md` |
| Procesos Node, sidecars, cálculo compartido TS ↔ PHP | `docs/NodeEnElStack.md` |
| `src/Travel/` (catálogo maestro: servicios, itinerarios, segmentos, componentes, tarifas, proveedores) | `docs/Travel.md` |
| `src/Api/Filter/` (filtros de API compartidos entre módulos) | `docs/Operacion.md` §8 — ahí está el porqué de `UuidRelacionFilter` y la regla relación vs. texto |

Si el módulo que tocas no tiene doc (`src/Pax/`…), **créalo**
siguiendo el formato de los existentes y agrégalo a esta tabla.

### Formato

Los docs están en **español**, escritos para reconstruir contexto sin releer todo el código:

1. Encabezado con propósito y alcance.
2. Índice numerado si pasa de ~150 líneas.
3. Diagramas ASCII para los flujos.
4. Citas por **nombre de símbolo** (`Clase::método()`), no por número de línea: las líneas
   se corren al editar.
5. Una sección final **"Dónde tocar para cambiar X"** con tabla necesidad → archivo → método.
   Es lo primero que se consulta; mantenla al día.

Al modificar un doc, actualiza la sección existente en lugar de apilar una nueva al final.

### Red de seguridad

`.claude/hooks/docs-guard.sh` (registrado como hook `Stop` en `.claude/settings.json`) corta
el cierre del turno si se escribieron archivos bajo cualquier `src/` y ningún archivo bajo
`docs/`. No es un permiso para relajarse: es el último filtro. Si el cambio de verdad no
requiere doc, dilo en una línea y sigue.

## Convenciones de código

- Comentarios y nombres de dominio en **español**; términos técnicos del framework en inglés.
- Los comentarios explican **por qué**, no qué hace la línea siguiente.
- Reglas de negocio compartidas: una sola fuente de verdad (método en la entidad), y el
  espejo TS documentado como espejo en ambos lados.

### TypeScript: nada de `any`

**El código nuevo va tipado, siempre.** `any` apaga el compilador justo donde más falta
hace: un campo renombrado en la API o un callback con otra firma dejan de dar error y
revientan en producción. No se escribe `any` ni se deja el que ya había al tocar un archivo.

Qué usar en su lugar:

- **Tipos de la librería.** Casi siempre existen y están exportados. FullCalendar, por
  ejemplo, exporta `CalendarOptions`, `EventClickArg`, `DatesSetArg`, `EventMountArg`…, y
  `@fullcalendar/resource` amplía `CalendarApi` con `refetchResources()` vía `declare
  module`: basta importar el plugin para que quede tipado, sin castear a `any`.
- **Respuestas de la API**: una interfaz que sea espejo del DTO/serializer del backend,
  citándolo en el comentario (ej. `util/src/types/calendarFeedModel.ts` ↔
  `src/Calendar/Dto/CalendarEventDto.php`).
- **`unknown` + estrechamiento** cuando el dato es de verdad desconocido (un `catch`, por
  ejemplo). `unknown` obliga a comprobar; `any` deja pasar cualquier cosa.
- **Un cast puntual y comentado** (`x as Tipo`) cuando la librería expone un diccionario
  abierto y el contrato lo fija el backend: el caso de `event.extendedProps`. El cast se
  acota a esa línea y el comentario dice quién garantiza la forma.

Dos trampas que ya costaron caro, porque **no fallan al compilar**:

- **Una unión con `any` colapsa el tipo entero.** `{ start?: string; ... } | any` es `any`
  a secas: las llaves de la izquierda no tipan nada. Si un campo admite formas distintas,
  enuméralas todas o usa un índice `[clave: string]: unknown`.
- **Redeclarar un campo del schema sin quitarlo del `Omit`** da una intersección
  inservible (`string[] & I18nContent[]`), y el error aparece lejos, al usarlo. Ver el
  aviso de §2 en `docs/Cotizaciones.md`.

### TypeScript: los tipos de la API se GENERAN

`util/src/types/api.d.ts` **y `pax/src/types/api.d.ts`** salen de `npm run gen:api` (exporta el
OpenAPI de API Platform y lo pasa por `openapi-typescript`). **Al añadir o cambiar un campo
expuesto por la API, se regeneran LAS DOS**, en la misma sesión.

⚠️ **`pax` no tenía el script hasta el 27/08/2026**, y su `api.d.ts` llevaba **8 días congelado**:
se había generado una vez a mano y nadie lo volvió a tocar. Al darle su `gen:api` y regenerarlo
salieron **6106 líneas** de diferencia. No había roto nada de milagro —lo que `pax` lee del esquema
no estaba entre lo que cambió—, pero un `.d.ts` viejo no falla: **describe una API que ya no
existe**, y el typecheck lo da por bueno porque sólo sabe lo que dice el `.d.ts`.

⚠️ **`cache:clear` NO invalida los grupos de serialización, y el fallo es mudo.** El script usaba
`cache:clear --quiet` y el export salía con la foto ANTERIOR: el campo recién publicado no
aparecía en `api.d.ts`, el front lo leía como `undefined` y no fallaba nada — ni el typecheck, que
sólo sabe lo que dice el `.d.ts`. Costaba una segunda pasada a ciegas darse cuenta.

La metadata vive en pools de caché que `cache:clear` no toca. Medido el 25/08/2026, añadiendo
`operacion:item:read` a un campo: `cache:clear` + export → **no aparece**; `rm -rf var/cache/dev`
+ export → aparece; `cache:pool:clear --all` + export → aparece. El script usa desde entonces
`cache:pool:clear --all`, que es lo mismo sin borrar directorios a mano.

Si publicas un campo y no lo ves en `api.d.ts`, **no es que el grupo esté mal**: comprueba
primero que el export no venga de caché.

No se declara el campo a mano en un `*Model.ts`: un tipo escrito a mano que se queda corto **no
falla al compilar**, miente — y el día que alguien sí regenere, la intersección de las dos formas
revienta lejos de aquí.

Los `*Model.ts` (`pmsReservaModel.ts`, `pmsFinanzasModel.ts`…) derivan del esquema
(`components['schemas'][…]`, `Pick<>`, `[number]`) y añaden lo que el esquema no puede decir:

- **Estrechamientos**: un `string` que en realidad es una unión cerrada, como `PmsSyncStatus`.
  Vienen de un getter, y OpenAPI no ve ahí un enum.
- **Endpoints que no son `ApiResource`**: controladores planos como `/tipo/user/enum/pms/*`,
  que no entran en la introspección (`PmsLimpiadorOption`, `PmsCobradorOption`).
- **Helpers y espejos de reglas de negocio**, que es a lo que de verdad vienen estos archivos.

Si de un getter sale un tipo inservible (`array` a secas, `string[][]`), la forma se declara en
**PHP** con `#[ApiProperty(openapiContext: […])]` —ver `PmsEventoCalendario::getLimpieza()`— y
se regenera. Así el arreglo vale para todos los consumidores y no sólo para el que lo notó.

Verificación: `cd util && npm run typecheck` (idem en `pax/`) — es `vue-tsc --noEmit`, la
misma comprobación que corre el build. Sin errores, siempre. Ojo: la inspección propia de
PhpStorm sobre `.vue` es menos fiable que `vue-tsc`; ante una discrepancia, manda el script.

### ⚠️ Un atributo mal puesto NO falla: deja de hacer su trabajo

Es el fallo más caro de este código, porque no produce ni un error. Tres casos reales, todos
encontrados por herramientas y ninguno por leer el archivo:

| Qué pasó | Consecuencia | Lo cazó |
|---|---|---|
| `#[Assert\Valid]` en `PmsChannel` **sin el `use`** de `Validator\Constraints` | Resolvía a `App\Pms\Entity\Assert\Valid`, que no existe: **la validación en cascada de la colección llevaba tiempo apagada** | PHPStan (`attribute.notFound`) |
| `getTipoCambio()` insertado **entre el docblock de `getSaldo()` y su `#[Groups]`** | El grupo se lo quedó el getter equivocado y **`saldo` desapareció del esquema de la API** | `npm run typecheck`, tras regenerar `api.d.ts` |
| El docblock de `getNoches()` pegado a `isSalidaTardia()` | Un `@return int` sobre un método `bool`, documentando el cálculo equivocado | PHPStan (`return.phpDocType`) |

Las reglas que salen de ahí:

- **El docblock va encima del bloque de atributos ENTERO.** Insertar un método «justo antes» de
  otro es la forma más fácil de robarle sus atributos al de abajo. Al añadir un getter, mira qué
  hay inmediatamente encima antes de escribir.
- **Un atributo sin su `use` no es un error de sintaxis**: PHP lo resuelve contra el namespace
  actual y se queda tan tranquilo. Si añades `#[Assert\…]`, `#[Groups]` o `#[ApiProperty]` a un
  archivo, comprueba que el `use` está.
- **Corre PHPStan y el typecheck del front antes de cerrar.** Los tres se cazaron así, y ninguno
  habría salido en una revisión a ojo ni en los tests.

### Campos que parecen un error y no lo son

Algunos nombres invitan a «corregirlos» y al hacerlo se rompe algo en silencio. Antes de
renombrar un campo que venga de una API externa, comprueba el payload real.

| Campo | Por qué NO se toca |
|---|---|
| `Beds24BookingDto::$country2` | Beds24 manda `country` (minúsculas) **y** `country2` (mayúsculas). El DTO expone sólo la segunda porque `MaestroPais` usa mayúsculas. Cambiarlo a `country` dejó muerta la inferencia de idioma por país, sin un solo error. Ver `docs/PmsBeds24ReservasSync.md` §3.3. |
| `BookingPullPersister::resolveEstado()` → `(int)$statusApi === 0` | Resto de la API v1, donde el estado era numérico. Con la v2 los estados son texto y `(int)"new"` es `0`, así que la rama entra **siempre** — y eso es lo que se quiere: lo que llega como `new`/`request` **se queda así a propósito**, para que el equipo local verifique la reserva. «Arreglar» el cast reactiva la rama que auto-confirma Airbnb y VRBO, y la verificación desaparece sin un solo error. Ver `docs/PmsBeds24ReservasSync.md` §5.4. |

---

## Dominios y contratos

**Un servicio transversal nunca lleva dentro conocimiento de un dominio.** Si el núcleo necesita
algo específico del PMS, de Turismo o de Cotizaciones, se define un **contrato** que cada dominio
implementa y el núcleo consume sin entender.

Incorporar un dominio nuevo tiene que ser **crear una clase que implemente el contrato**, y nada
más: ni un `match`, ni un `if` por `context_type`, ni tocar el registro.

- **Autolocalización con `#[AutowireIterator]`** (o la alternativa que corresponda). Es ya el
  patrón dominante —`SkillRegistry`, `IntentRouter`, `EnumeradorDeFrentes`,
  `FinOrigenCobroRegistry`…—: una clase nueva se enchufa sola.
- **Los identificadores de dominio son opacos para el núcleo**: `booking`, `pms_reserva`,
  `airbnb` se transportan, **no se interpretan**. Quien conoce sus consecuencias es el dominio, y
  es él quien redacta el texto; el núcleo lo concatena.
- **Antes de añadir campo o servicio, mirar si el contrato ya existe.**
  `ConversacionEnlaceInterface` ya exponía `getOrigen()`/`getAgencia()` y sólo faltaba la frase.
  `InstruccionesDeDominioInterface`, `IndiceDeTemasInterface`, `SkillDominioInterface` y
  `FrentesPorDominioInterface` cubren buena parte de los ejes.
- **Lo que pertenece al ASUNTO va en el enlace, no en la conversación.** Una persona puede tener
  una estancia de Booking y un tour directo en el mismo hilo: cualquier dato «de la conversación»
  que en realidad sea del asunto es falso en cuanto haya dos.
- **Lista vacía = sin acotar**, en skills, guía y conocimiento. Va contra la intuición y es
  deliberado: un olvido al clasificar deja el ítem de más —inofensivo— en vez de invisible, y lo
  segundo no se descubre nunca porque nadie echa de menos lo que no sabía que existía.

## Node y PHP: cada lógica en un solo sitio

El stack es PHP, y sigue siéndolo. Pero **algunos trabajos son calles estrechas**: una conexión
persistente a una cola, un fan-out por SSE, o una regla que ya está escrita en TypeScript. Ahí Node
entra — no porque sea mejor, sino porque la alternativa es duplicar.

**La regla es una sola: ninguna regla de negocio puede estar escrita dos veces.** De ahí salen dos
respuestas opuestas para el mismo lenguaje:

- Un **sidecar** que sostiene una conexión va **tonto**: empuja eventos crudos y no sabe qué es una
  reserva. El dominio ya vive en PHP; meterle conocimiento duplicaría.
- El **cálculo financiero** va **listo**: ya vive en TypeScript y lo necesitan el editor del
  operador *y* el agente IA. Reescribirlo en PHP duplicaría.

⚠️ **Node calcula; PHP persiste.** En cuanto un proceso Node escriba en MySQL hay dos autores sobre
el dinero. Esa frontera es la que mantiene barato el segundo lenguaje.

**Propónlo cuando** haya conexión persistente, lógica que ya existe en TS, o el único cliente
decente esté fuera de PHP. **No lo propongas** por rendimiento a secas, ni si el proceso tendría que
escribir en base de datos, ni si la lógica ya vive en PHP y sólo hay que llamarla desde otro sitio.

El detalle, los costes y el porqué de la analogía están en `docs/NodeEnElStack.md`.

## Qué entra por migración y qué tiene que entrar por comando

La regla la decide **si hay listeners de por medio**, no el tamaño del cambio:

| Va por **migración** (SQL directo) | Va por **comando** (ORM) |
|---|---|
| Esquema: tablas, columnas, índices | Cualquier campo con `#[AutoTranslate]` |
| Texto que **sólo lee el agente** (`agente_contenido`, `agente_pasos`): plano, sin traducir | Contenido **publicado** (`titulo`, `descripcion`): se traduce a 7 idiomas |
| Datos que ninguna entidad recalcula al guardarse | Todo lo que dispare sincronizaciones o listeners de coherencia |

**El motivo:** `AutoTranslationEventListener` se engancha a `prePersist`/`preUpdate`. Un `UPDATE`
en SQL **se lo salta**, así que crea fichas sólo en español o —peor— deja siete traducciones vivas
diciendo lo contrario que el original. Lo mismo vale para los listeners de coherencia financiera y
los de integridad de teléfonos: si el dato pasa por ellos al guardarse, tiene que entrar por el
ORM.

Los comandos de carga de contenido viven en `src/<Modulo>/Command/`, son **idempotentes** (por
`nombreInterno` o clave natural) y llevan `--dry-run`. Ver `app:pms:guia:crear-televisor`.

Dos reglas más de datos:

- **No se borra: se marca.** Una reserva cancelada, un asunto retirado o un enlace muerto siguen
  siendo parte de lo que le pasó a esa persona, y borrarlos deja el hilo contando una versión
  incompleta.
- **Lo que los tests unitarios no cubren se verifica con datos reales**, en transacción con
  `rollback` (ver `var/probar-*.php`). Los dos fallos más caros de agosto de 2026 fueron de
  **datos**, no de código, y eso no lo pesca ningún test unitario.

## El agente: lo que el modelo obedece y lo que no

**El prompt es una petición; el cierre es código.** Es la regla de la que cuelgan las demás, y
está pagada: una instrucción en mayúsculas para que no soltara los teléfonos del personal de
limpieza se ignoró **tres veces seguidas**.

- **La supresión no funciona.** «No le menciones el depósito», «no digas que hay otro huésped»,
  «no repitas la explicación» son peticiones que el modelo incumple. Si algo no debe salir, **no
  se manda**: se filtra en la fuente.
- **Las condiciones se escriben en positivo en todas las ramas.** «Airbnb: ya cobrado, sin
  depósito» funciona; «a los de Airbnb no les menciones el depósito» es supresión disfrazada.
  Bifurcar con el hecho delante es de lo que mejor hace un modelo — lo que falla es que le falte
  el hecho.
- **⚠️ Lo que se le quita del texto, lo NIEGA.** Probado: al sacar el depósito de garantía del
  contenido, el asistente respondía «no es necesario dejar ningún depósito». Si un dato se muda a
  otro ítem, hay que **dejarle un puntero** de que existe, sin darle el contenido.
- **Lo que decide el modelo, valídalo con código.** Un `tema_id` o un `item_id` elegidos por él se
  comprueban contra una lista blanca antes de resolverse: un modelo se inventa identificadores con
  toda la seguridad del mundo.

## Cómo se estructura el contenido de la guía

`PmsGuiaItem` tiene tres superficies y **no dicen lo mismo**: `descripcion` es lo que el huésped
lee en la app, `agenteContenido` lo que el asistente cuenta, y `agentePasos` lo que se dice sólo
si lo anterior no resolvió.

**La regla de reparto:** en el peldaño 0 va lo que **sirve** a quien pregunta. Lo que **niega,
limita, advierte o cuesta dinero** baja un peldaño y sale sólo cuando la pregunta lo pide.

```
peldaño 0   agenteContenido   uso normal, lo que se contesta a una pregunta blanca
peldaño 1   agentePasos[0]    no le funcionó: lo que puede probar él
peldaño 2   agentePasos[1]    sigue sin ir: la comprobación que decide qué se manda
→ agotados, se escala contando lo ya intentado. NO se repite el peldaño 0.
```

- **No se dicen las cosas negativas a priori.** A quien pregunta cómo funciona la ducha no se le
  contesta con avisos de avería; a quien pregunta cómo llegar no se le abre con «no tenemos
  traslado». Es anticomercial y genera mal ambiente con alguien que no venía a quejarse.
- **⚠️ La instrucción al agente se queda; la negativa dicha al cliente baja.** «NO tenemos
  traslado propio: no lo prometas nunca» sigue en el peldaño 0 —si el agente deja de saberlo, se
  lo inventa—. Lo que baja de peldaño es *empezar por ahí*.
- **Dos pasos bastan.** Si hacen falta cuatro, el tema está mal partido.
- **Casi todos los ítems van sin escalera**, y así debe ser. Llenar el campo por costumbre
  reintroduce el problema al revés.
- **Lo que sólo se dice si lo preguntan NO es un peldaño.** El depósito de garantía no depende de
  que insista, sino de que pregunte: eso se arregla **partiendo el ítem**, para que el índice de
  temas lo elija cuando toca y el resto del tiempo ni exista.
- **`agenteContenido` sustituye, no acompaña.** Lo que no esté ahí, el asistente no lo sabrá del
  tema: no lo completa leyendo el cuerpo publicado, porque no lo recibe.
- **Los `agenteTerminos` se escriben como pregunta la gente** («cochera», «dónde dejo el auto»),
  no como lo llamamos nosotros.
- **Las tres superficies tienen que decir lo mismo.** Estuvieron contradiciéndose en las siete
  duchas —la app decía «derecha», el agente «izquierda»— y no se vio porque cada una era coherente
  consigo misma. Al corregir un dato, corregir las tres.

El mecanismo completo está en `docs/Mensajeria.md` §22.

## Despliegue

`push` → `pull` en el servidor → build → **`doctrine:migrations:migrate`**.

⚠️ **El paso de migraciones no es opcional y falla en silencio.** El 13/08/2026 se desplegó código
con una columna nueva sin migrar: la entidad pedía `agente_pasos`, la columna no existía, y **la
guía del huésped dio 500 para todos los huéspedes** hasta que alguien lo notó por casualidad. El
único rastro era una línea en `var/log/error.log`.

Después de desplegar algo que toque una entidad, comprobar:
`php bin/console doctrine:migrations:status` — y que `New` sea 0.

⚠️ **Si el despliegue RENOMBRA o MUEVE clases, hace falta `composer dump-autoload` en el
servidor**, y cuanto antes: `optimize-autoloader` está activo, así que el classmap sigue
apuntando a los archivos viejos hasta que se regenera. El 19/08/2026, renombrando `Proveedor` a
`TravelOrganizacion`, una petición real cayó en el minuto entre el `pull` y el dump y dejó dos
`Warning: include(): Failed opening …ProveedorVivoResolver.php` en `error.log`.

No está en el hook `post-merge` a propósito —encarecer todos los despliegues por algo que pasa
una vez al año no compensa—, así que **va a mano y encadenado al `pull`**, no después de las
migraciones:

```bash
git pull --ff-only && composer dump-autoload --no-dev --optimize && php bin/console doctrine:migrations:migrate --no-interaction
```

Node vive en nvm y no está en el PATH de una sesión ssh no interactiva; los logs de producción
ocultan el nivel `info`, así que los errores se buscan en `var/log/error.log`.
