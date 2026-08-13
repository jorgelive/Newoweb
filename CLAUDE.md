# Newoweb — Guía para Claude

## Stack

- **Backend:** Symfony (PHP >= 8.4), API Platform, Doctrine ORM, EasyAdmin (panel legacy).
  Comandos: `php bin/console ...`. Verificación: `php bin/phpunit`, `vendor/bin/phpstan analyse`,
  `php -l`, `lint:container` y, cuando aplica, ejecutar el flujo real.

  **Análisis estático:** PHPStan nivel 2 sobre `src/`, con `phpstan-baseline.neon` congelando la
  deuda que ya existía. Está en nivel 2 y no más porque ése es el que caza **variables
  indefinidas y métodos que no existen**, que es la clase de fallo que ya costó caro: un
  `$message` donde la variable era `$msg` pasó `php -l`, pasó los 104 tests —ninguno tocaba ese
  archivo— y habría tumbado TODO el envío saliente en el primer mensaje. Correrlo antes de
  cerrar un cambio no es opcional; es más barato que cualquier test que se pueda escribir para
  cubrir lo mismo.

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
  Verificación: `cd util && npx vue-tsc --noEmit` (idem en `pax/`).
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
| `src/Message/` (chat, reglas de envío, colas Beds24/WhatsApp), `src/Pms/Service/Message/`, `src/Agent/` (autorespuestas e IA) | `docs/Mensajeria.md` |
| `util/public/push-sw.js`, `*/vite.config.ts` (bloque `VitePWA`), `*/scripts/pwa-*.mjs`, `src/Service/WebPushNotificationService.php`, `PushSubscription*` | `docs/PwaNotificaciones.md` |
| `src/Pms/Service/Reserva/PmsDisponibilidadService.php`, `PmsEventoEstado::IMPIDEN_VENTA` | `docs/PmsDisponibilidad.md` |
| `src/Finanzas/`, resolvers `*/Finanzas/*OrigenCobroResolver.php`, cobros en `util/` y `pax/` | `docs/FinanzasEnlacesPago.md` |
| `src/Service/Phone/`, listeners de integridad de teléfonos, `util/src/utils/telefono.ts` | `docs/Telefonos.md` |
| `src/Agent/Alexa/`, `VoiceAssistant`, `AlexaController` (el agente por voz) | `docs/AgentVoz.md` |
| `src/Logging/`, `config/packages/monolog.yaml`, rotación de logs | `docs/Logging.md` |
| `config/packages/mailer.yaml`, variables `MAILER_*`, envío por Graph | `docs/CorreoSaliente.md` |

Si el módulo que tocas no tiene doc (`src/Travel/`, `src/Agent/`, `src/Pax/`…), **créalo**
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

`util/src/types/api.d.ts` sale de `npm run gen:api` (exporta el OpenAPI de API Platform y lo
pasa por `openapi-typescript`). **Al añadir o cambiar un campo expuesto por la API, se
regenera**, en la misma sesión. No se declara el campo a mano en un `*Model.ts`: un tipo
escrito a mano que se queda corto **no falla al compilar**, miente — y el día que alguien sí
regenere, la intersección de las dos formas revienta lejos de aquí.

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
