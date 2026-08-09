# Newoweb — Guía para Claude

## Stack

- **Backend:** Symfony (PHP >= 8.4), API Platform, Doctrine ORM, EasyAdmin (panel legacy).
  Comandos: `php bin/console ...`. Sin suite de tests (`tests/` está vacío): la verificación
  es `php -l`, `lint:container` y, cuando aplica, ejecutar el flujo real.
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
| `src/Pms/Service/Reserva/PmsDisponibilidadService.php`, `PmsEventoEstado::IMPIDEN_VENTA` | `docs/PmsDisponibilidad.md` |
| `src/Finanzas/`, resolvers `*/Finanzas/*OrigenCobroResolver.php`, cobros en `util/` y `pax/` | `docs/FinanzasEnlacesPago.md` |
| `src/Service/Phone/`, listeners de integridad de teléfonos, `util/src/utils/telefono.ts` | `docs/Telefonos.md` |

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

Verificación: `cd util && npm run typecheck` (idem en `pax/`) — es `vue-tsc --noEmit`, la
misma comprobación que corre el build. Sin errores, siempre. Ojo: la inspección propia de
PhpStorm sobre `.vue` es menos fiable que `vue-tsc`; ante una discrepancia, manda el script.
