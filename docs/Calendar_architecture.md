# Arquitectura del Calendario FullCalendar — Trazado Completo

> Fecha: 2026-07-31
> Propósito: Documentar el pipeline completo de renderizado de los calendarios de **Reservas** y **Tarifas Rango** para replicarlo en Vue 3 + FullCalendar (sin EasyAdmin).

---

## 1. Visión General

```
YAML config  →  CalendarConfigResolver
                    ↓
             ProviderRegistry  →  Provider concreto  →  DTOs  →  JSON
                                                                   ↑
Twig template  →  HTML div (data-attributes)
Stimulus controller  →  fetch(eventUrl / resourceUrl)  →  FullCalendar
```

---

## 2. Configuración YAML

Ubicación: `config/services/services_pms.yaml`
Clave raíz: `parameters.calendars_pms` (prefijo `calendars_` escaneado por CalendarConfigResolver)

### Reservas

| Clave | Provider |
|---|---|
| `pms_eventos_no_cancelados` | `pms_eventos_raw` |
| `pms_eventos_todos` | `pms_eventos_raw` |

Filtros relevantes: `estado.not_in: [cancelada]` para el calendario sin canceladas.

URLs generadas por el provider (bifurcación reserva vs bloqueo):
- Con reserva: `reservaEdit` / `reservaShow`
- Sin reserva (bloqueo): `eventoCalendarioEdit` / `eventoCalendarioShow`

### Tarifas Rango

| Clave | Provider |
|---|---|
| `tarifa_rangos_raw` | `tarifa_ranges_raw` |
| `tarifa_rangos_compactados` | `tarifa_compressed_ranges` |

Campos clave del config de tarifas:
- `fields.resourceRoot: unidad` — navega getUnidad()
- `fields.start/end: fechaInicio/fechaFin`
- `fields.price/minStay/currency/active/weight/important`
- `eventTime.start: '12:00:00'` y `eventTime.end: '11:59:00'` — horas visuales inyectadas sin mover el día

---

## 3. CalendarConfigResolver

`src/Calendar/Service/CalendarConfigResolver.php`

Escanea todos los parámetros del DI con prefijo `calendars_`, los fusiona en un map único
y entrega la sub-config por clave cuando el controller la solicita.

---

## 4. Endpoints HTTP

`src/Calendar/Controller/FullcalendarLoadController.php`

```
GET /fullcalendar/load/event/{calendarKey}?start=ISO&end=ISO&current_page=BASE64
GET /fullcalendar/load/resource/{calendarKey}?start=ISO&end=ISO
```

- `current_page` = `btoa(window.location.href)` desde JS → se usa solo para generar links returnTo en EasyAdmin; en Vue no es necesario.
- Responde `JsonResponse` con array de DTOs serializados.

---

## 5. Providers

`src/Calendar/Provider/`

| Clase | supports() |
|---|---|
| `DoctrineCalendarProvider` | config tiene `entity` y NO tiene `provider` |
| `PmsEventosRawCalendarProvider` (legacy/EasyAdmin) | `config['provider'] === 'pms_eventos_raw'` |
| `PmsEventosSpaCalendarProvider` (SPA/Vue) | `config['provider'] === 'pms_eventos_spa'` |
| `TarifaRangesRawCalendarProvider` (legacy/EasyAdmin) | `config['provider'] === 'tarifa_ranges_raw'` |
| `TarifaRangesSpaCalendarProvider` (SPA/Vue) | `config['provider'] === 'tarifa_ranges_spa'` |
| `TarifaCompressedRangesCalendarProvider` (legacy/EasyAdmin) | `config['provider'] === 'tarifa_compressed_ranges'` |
| `TarifaCompressedRangesSpaCalendarProvider` (SPA/Vue) | `config['provider'] === 'tarifa_compressed_ranges_spa'` |

`ProviderRegistry` exige exactamente 1 match (0 o >1 → HTTP 500).

### Providers "Spa" (implementados para desacoplar de EasyAdmin)

A partir de 2026-07-31 existen variantes `*Spa*CalendarProvider` para cada calendario, pensadas
para ser consumidas directamente por una SPA (Vue) sin pasar por EasyAdmin:

- **No generan** `urledit`/`urlshow` (nada de `router->generate()` contra rutas admin).
- **No dependen** de `runtime_returnTo` / `current_page`.
- **No hacen** chequeos `ROLE_*` para decidir si exponer un link (esa autorización la hace
  la API REST real que consuma el id, no el calendario).
- En su lugar, exponen `extendedProps` con `context` + ids crudos:
  - Reservas: `{ context: 'reserva'|'bloqueo', eventoId, reservaId, isOta }`
  - Tarifas raw: `{ context: 'tarifaRango', tarifaRangoId, active }`
  - Tarifas compactadas: `{ context: 'tarifaRangoCompactado', tarifaRangoId }` (id del rango
    de tarifa que originó ese segmento compactado — mismo criterio que usaba el legacy para el link)

### Los datos sueltos de `extendedProps` (y por qué no basta el `title`)

Además de los ids, los providers SPA mandan los **campos sueltos** que la SPA pinta:

| Calendario | Campos |
|---|---|
| Reservas | `canalId`, `cliente`, `unidad`, `pax`, `noches`, `estado`, `estadoIcono`, `estadoColor`, `estadoPago`, `descripcion`, `referenciaCanal`, `simbolo`, `total`, `saldo` |
| Tarifas | `precio`, `minStay`, `moneda`, `importante`, `active` |

#### Las cifras financieras de la barra (`simbolo`, `total`, `saldo`)

Las añade `PmsEventosSpaCalendarProvider::buildContext()` y son las que pinta la segunda
fila de la barra. Tres cosas que no se ven leyendo el código:

1. **Son de la RESERVA, no de la estancia.** `PmsInformacionFinanciera` cuelga de
   `PmsReserva`, así que una reserva de dos casitas muestra el MISMO total y el mismo saldo
   en sus dos barras. Es deliberado —el saldo se cobra una vez, no por casita— pero al leer
   el calendario hay que saberlo o se cuenta dos veces el dinero.
2. **Se cargan en lote, nunca por evento.** La relación va de las finanzas hacia la reserva
   (`JoinColumn` unique del lado de las finanzas), o sea que el evento no puede navegar
   hasta ella. `PmsEventosSpaCalendarProvider::fetchFinanzas()` resuelve todas las cabeceras
   del rango de una vez, indexadas por id de reserva: un `findOneBy` por evento serían ~200
   consultas en la vista de mes. Cualquier dato nuevo que venga de otra entidad debe seguir
   este patrón — **con la salvedad del punto siguiente**.

   > **Gotcha: ese lote NO puede ser un `IN (:reservas)` en DQL.** `reserva_id` es
   > `BINARY(16)`, y pasar entidades u objetos `Uuid` por `setParameter()` sin tipo de
   > parámetro los serializa mal: la consulta **no falla**, devuelve cero filas, y el
   > calendario se pinta sin cifras sin un solo error en el log. Es exactamente la trampa
   > que ya documenta `TourTarjetaResolver::binarios()` en el módulo de cotizaciones, y se
   > cayó en ella al escribir este método. Lo que sí funciona es `findBy(['reserva' => …])`
   > con las entidades: el persister de Doctrine conoce el tipo de la columna destino del
   > mapeo. Es la versión en lote del `findOneBy(['reserva' => …])` que ya usaba
   > `PmsReservaPaxProvider`. El precio es perder el eager load de la moneda — sale a una
   > consulta por moneda DISTINTA, que el identity map deduplica, no por fila.
3. **`null` cuando `totalCargos` es 0.** Bloqueos y reservas recién creadas no traen cifras,
   y la barra simplemente omite esa parte de la fila en vez de pintar «0».

En el front cada cifra va en una pastilla con **fondo plomo claro opaco** (`#eef0f3`), y ese
detalle es el que sostiene todo lo demás: al darle a la pastilla un lienzo neutro propio, el
color del texto deja de depender del morado o el azul de la barra, y se puede usar el mismo
código contable del panel financiero — verde el total, **rojo lo que se debe y azul lo
saldado** (`saldoPill()`). Sin ese fondo habría que recurrir a colores de alto contraste que
no significan nada.

La tolerancia de `0.005` en `saldoPill()` evita que un residual de coma flotante pinte como
pendiente una reserva ya cobrada.

El importe de la barra va **sin decimales** (`importeCorto()`): en 60 px no caben. Los
céntimos exactos están en el tooltip y en el panel financiero.

#### La barra de un evento SIN reserva (`descripcion`)

Un bloqueo de mantenimiento no tiene cliente ni cifras, pero se pinta con **la misma
anatomía de dos filas** que una estancia: icono a la izquierda, qué es arriba, el detalle
abajo. No es simetría por gusto — es que tenía dos cosas que decir y sólo se le daba una
línea:

- **Arriba va el estado** (`estado`, «Bloqueo»), no el `title`. El título de estos eventos lo
  fabrica `buildTitle()` como `Evento (Bloqueo)`, que repite lo que el color de la barra y el
  icono ya dicen. Sigue siendo el respaldo si el evento llegara sin datos sueltos.
- **Abajo va `descripcion`**, el motivo que alguien escribió a mano («Pintado»,
  «Fumigación»). Es el ÚNICO dato propio del bloqueo y antes sólo se veía abriendo el
  tooltip. Ocupa la fila entera —donde una estancia lleva sus pastillas— y va como texto
  suelto: el lienzo opaco de `.fc-reserva-dato` existe para defender un código de color sobre
  la barra, y aquí no hay ninguno que defender.
- **El icono es el del ESTADO, no el del canal.** Un bloqueo es siempre `directo` y el
  apretón de manos de ese canal no dice nada; el `fa-ban` del estado sí.

`descripcion` viaja para todos los eventos, también los de reserva, pero ahí no se pinta: esa
segunda fila la ocupan pax, noches y saldo.

#### El icono del estado (`estadoIcono`, `estadoColor`)

La barra pinta el estado como una pastilla más de esa fila (`iconoEstadoHtml()`), y hay tres
decisiones detrás que no se ven leyendo el código:

1. **Es un DATO del maestro, no un `match()` en PHP.** La clase de FontAwesome y el color
   viven en `pms_evento_estado.icono` / `.color` y los reenvía
   `PmsEventosSpaCalendarProvider`. Un estado nuevo que alguien dé de alta en el panel llega
   al calendario con su icono **sin tocar código ni recompilar el front**. El precio es que
   el valor lo teclea un operador — ver el punto 3.
2. **El color es el del ESTADO, que no siempre es el de la barra.** El fondo de la barra
   puede venir tintado por el estado de PAGO (`resolveColor()` en el provider). Ahí está toda
   la gracia del icono: dice algo que el color de fondo ya no siempre dice. Y por eso va en
   pastilla y no suelto encima de la barra, que es como nació: el rojo de «Cancelada» o el
   ámbar de «Pendiente» sobre un fondo morado o azul no se leían. Es el mismo motivo que
   justifica el `#eef0f3` de las cifras, aplicado a un glifo.

   > **El precio de estar en esa fila**: `.fc-reserva-meta` es lo primero que se recorta al
   > estrecharse la barra, así que en una estancia de una noche el estado NO se ve. Queda en
   > el tooltip. Fue una decisión consciente frente a la alternativa —apilarlo bajo el icono
   > del canal, donde nunca se recorta pero no se puede leer sobre el fondo—.
3. **`icono` acaba dentro de un atributo `class`, y lo escribe un humano.** Se acota a la
   forma de una clase de FontAwesome (letras, dígitos, guiones y espacios) **antes** de
   escaparlo: escapar protege del cierre de atributo, no de que alguien cuele
   `fa-x" onload="`. El color sólo se pinta si es un hex exacto de 6 dígitos, aunque
   `PmsEventoEstado::normalizeColor()` ya lo normalice en el maestro — el estilo se compone
   en el front y no se confía en el origen. Lo que no encaje, no se pinta.

El `title` y el `tooltip` de texto plano **siguen existiendo**: son parte del contrato del
`CalendarEventDto`, los usa el calendario legacy de EasyAdmin, y la SPA cae a ellos si un evento
llegara sin datos sueltos. Pero la SPA prefiere los campos, por dos motivos que no se ven mirando
solo el backend:

1. **Iconos.** El título manda la INICIAL del canal (`A x8 | Nombre | Casita`); una «A» y una «B»
   no se distinguen de un vistazo. Con `canalId`, `eventContent` pinta el logo de Airbnb o de
   Booking (`canalInfo()` en `util/src/types/pmsReservaModel.ts` — la MISMA tabla que usa el
   enlace a la extranet del menú contextual, para que no haya dos mapas de iconos).
2. **No re-parsear.** Sacar el nombre del huésped de `A x8 | Nombre | Casita` obliga a partir por
   `|`, y un huésped con `|` en el nombre lo rompe.

> **Ambos renders inyectan HTML crudo**: `eventContent` recibe una cadena (no una plantilla Vue) y
> tippy va con `allowHTML: true`. Todo valor pasa por `escaparHtml()` (`util/src/utils/html.ts`).
> Y sus estilos van en un `<style>` **sin `scoped`**: FullCalendar inyecta fuera del árbol que Vue
> compila, y tippy monta en `document.body`, así que ninguno lleva el atributo de scope.

Las claves de calendario correspondientes en `services_pms.yaml` son:
`pms_eventos_no_cancelados_spa`, `pms_eventos_todos_spa`, `pms_eventos_ocupacion_spa`,
`tarifa_rangos_raw_spa`, `tarifa_rangos_compactados_spa`. Mismos `filters`/`fields` que sus pares
legacy, sin bloque `event.url`.

`pms_eventos_ocupacion_spa` es el único sin par legacy: no lo consume el calendario de Reservas
sino el de **Tarifas**, como segunda fuente de eventos pintada de fondo (ver §12, «Ocupación de
fondo en el calendario de tarifas»).

Los legacy (`*_raw`, sin sufijo `_spa`) **no se tocaron** — quedan intactos para EasyAdmin y
son candidatos a deprecarse cuando el calendario legacy deje de usarse.

### PmsEventosRawCalendarProvider — Reservas

getEvents:
- Query: `PmsEventoCalendario` + joins a unidad, reserva, estado, estadoPago
- Filtro de fechas: `inicio < :to AND fin > :from` (intersección real, no igualdad)
- Color: si estadoPago.colorOverride → color del pago, si no → color del estado
- Título: `"{canal} x{pax} | {nombre} | {unidad}"`
- URL: bifurca entre rutas de reserva o de evento según si existe reserva asociada

getResources:
- Mismo fetch → deduplica por unidad.id → ordena alfabético → asigna índice `orden`

### TarifaRangesRawCalendarProvider — Tarifas

getEvents:
- Query: `PmsTarifaRango` con intersección de fechas
- Horas inyectadas desde eventTime.start/end sin tocar el día de la BD
- Scoring prioridadImportante:
  - +10_000_000 si importante === true
  - +(prioridad * 10_000)
  - +(10_000 - días_duración)  ← rangos cortos = más prioridad visual
- Título: `"{precio} ({neto20%} | 20% - {neto30%} | 30%) {minStay}N"`

getResources: igual que reservas (deduplica unidad, ordena, asigna orden)

---

## 6. DTOs — Formato JSON

### Evento

```json
{
  "id": "uuid",
  "title": "B x2 | Juan López | Hab 101",
  "start": "2025-03-01T12:00:00",
  "end": "2025-03-05T11:59:00",
  "resourceId": "uuid-unidad",
  "backgroundColor": "#4CAF50",
  "urledit": "/admin/...",
  "urlshow": "/admin/...",
  "tooltip": ["Línea 1", "Línea 2"],
  "prioridadImportante": 10009998
}
```

FullCalendar mueve urledit/urlshow/tooltip/prioridadImportante a extendedProps automáticamente.

### Recurso

```json
{
  "id": "uuid-unidad",
  "title": "Casita 1",
  "orden": 0,
  "extendedProps": { "activo": true, "slug": "casita-1", "establecimientoSlug": "casita" }
}
```

`extendedProps` lo llena `CalendarResourceCatalog`, que además de `activo` admite **campos
sueltos declarados en el YAML**:

```yaml
resources:
    extraFields:                                # → extendedProps.<clave>
        slug: slug
        establecimientoSlug: establecimiento.slug
```

El valor es una **ruta de getters** separada por puntos (`establecimiento.slug` →
`getEstablecimiento()->getSlug()`), y devuelve `null` en cuanto un tramo falta: un recurso
incompleto no puede reventar el feed.

Se declaran en el YAML y no en el servicio porque `CalendarResourceCatalog` es **genérico** —
sirve a los seis calendarios y no debe saber qué es una `PmsUnidad`. Hoy solo los piden
`pms_eventos_no_cancelados_spa` y `pms_eventos_todos_spa`, para que al tocar el nombre de una
casita en el calendario se pueda abrir o copiar su **catálogo público** (`pax`:
`/{establecimiento}/{unidad}`) sin una segunda petición.

> **Cuidado con dónde se ponen.** `merge()` da prioridad al catálogo sobre lo derivado de los
> eventos, así que basta con declararlos en el bloque `resources`. Añadirlos también en el
> `getResources()` del provider sería redundante: esa versión solo sobrevive para unidades que
> el catálogo no devuelve.

> **El clic en la etiqueta se ata a mano.** FullCalendar no expone un `resourceLabelClick`; el
> listener se engancha en `resourceLabelDidMount`. No hace falta soltarlo en
> `resourceLabelWillUnmount`: FullCalendar descarta el nodo entero al repintar, y con él su
> listener.

---

## 7. FullCalendarStimulusExtension (Twig — solo EasyAdmin)

`src/Calendar/Twig/FullCalendarStimulusExtension.php`

Genera el <div data-*> con:
- `data-fullcalendar-calendars-value` = JSON array [{nombre, eventUrl, resourceUrl}]
- `data-fullcalendar-default-view-value`
- `data-fullcalendar-views-value`
- `data-fullcalendar-resource-area-width-value`

Llamada reservas:
```twig
{{ fullcalendar_stimulus('pms_reserva', {
    'No canceladas': 'pms_eventos_no_cancelados',
    'Todas': 'pms_eventos_todos'
}, 'resourceTimelineOneMonth', {1:'dayGridMonth',2:'listMonth',3:'resourceTimelineOneMonth'}, 70) }}
```

Llamada tarifas:
```twig
{{ fullcalendar_stimulus('pms_tarifa_rango', {
    'Todas': 'tarifa_rangos_raw',
    'Compactados': 'tarifa_rangos_compactados'
}, 'resourceTimelineTwoMonths', {1:'resourceTimelineTwoMonths',2:'resourceTimelineOneMonth'}) }}
```

---

## 8. Stimulus Controller (fullcalendar_controller.js)

`assets/controllers/fullcalendar_controller.js`

Opciones críticas de FullCalendar:
```js
{
  schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
  locale: 'es',
  timeZone: 'local',
  refetchResourcesOnNavigate: true,
  resourceOrder: 'orden',
  eventOrder: '-prioridadImportante,-duration,start',
  eventOrderStrict: true,
}
```

Fetch de recursos (simplificado):
```js
resources: (fetchInfo, success, failure) => {
  const url = new URL(config.resourceUrl, window.location.origin)
  url.searchParams.set('start', fetchInfo.startStr)
  url.searchParams.set('end', fetchInfo.endStr)
  url.searchParams.set('_t', Date.now())
  fetch(url, { credentials: 'include' }).then(r => r.json()).then(success).catch(failure)
}
```

Fetch de eventos: igual + añade `current_page=btoa(window.location.href)` (solo para EasyAdmin returnTo).

Clicks:
- Click simple → extendedProps.urlshow
- Doble click → extendedProps.urledit

Tooltips: tippy.js en eventDidMount, content = tooltip.join('<br>').

Persistencia: localStorage guarda fecha, vista y scroll horizontal.

Cambio de calendario: refetchResources() + refetchEvents().

---

## 9. Diagrama de Secuencia

```
Navegador                 Backend
    |                        |
    | GET /admin/pms/res...   |
    | ←── HTML (div data-*) ──|  (Twig + FullCalendarStimulusExtension)
    |                        |
[Stimulus connect()]         |
    |                        |
    |── GET /fullcalendar/load/resource/pms_eventos_no_cancelados?start&end ──>|
    |                        | CalendarConfigResolver → config
    |                        | ProviderRegistry → PmsEventosRawCalendarProvider
    |                        | getResources() → dedup unidades, orden
    | <── [{id,title,orden}] |
    |                        |
[FC renderiza filas]         |
    |                        |
    |── GET /fullcalendar/load/event/pms_eventos_no_cancelados?start&end ──>|
    |                        | getEvents() → query + joins → DTOs
    | <── [{id,title,...}]   |
    |                        |
[FC renderiza eventos]       |
[tippy.js tooltips]          |
```

---

## 10. Replicar en Vue 3 + FullCalendar

### Backend: dos endpoints por calendario

```
GET /api/calendar/resources/{key}?start=ISO&end=ISO
GET /api/calendar/events/{key}?start=ISO&end=ISO
```

Respuesta recursos: `[{ "id": "uuid", "title": "...", "orden": 0 }]`

Respuesta eventos: igual que el JSON del apartado 6, con urledit/urlshow/tooltip/prioridadImportante dentro de `extendedProps`.

### Componente Vue 3 mínimo

```vue
<template>
  <div>
    <select v-if="calendars.length > 1" v-model="currentIndex">
      <option v-for="(cal, i) in calendars" :key="i" :value="i">{{ cal.nombre }}</option>
    </select>
    <FullCalendar ref="calRef" :options="calendarOptions" />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import FullCalendar from '@fullcalendar/vue3'
import resourceTimelinePlugin from '@fullcalendar/resource-timeline'
import dayGridPlugin from '@fullcalendar/daygrid'
import listPlugin from '@fullcalendar/list'
import esLocale from '@fullcalendar/core/locales/es'
import tippy from 'tippy.js'

const props = defineProps({
  calendars: { type: Array, required: true },  // [{nombre, eventUrl, resourceUrl}]
  defaultView: { type: String, default: 'resourceTimelineOneMonth' },
  resourceAreaWidth: { type: Number, default: 120 }
})

const currentIndex = ref(0)
const calRef = ref(null)
const currentCal = computed(() => props.calendars[currentIndex.value])

watch(currentIndex, () => {
  const api = calRef.value?.getApi()
  if (!api) return
  api.refetchResources()
  api.refetchEvents()
})

const calendarOptions = computed(() => ({
  plugins: [resourceTimelinePlugin, dayGridPlugin, listPlugin],
  schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
  locale: esLocale,
  timeZone: 'local',
  initialView: props.defaultView,
  nowIndicator: true,
  contentHeight: 'auto',
  editable: false,
  refetchResourcesOnNavigate: true,
  resourceOrder: 'orden',
  eventOrder: '-prioridadImportante,-duration,start',
  eventOrderStrict: true,
  resourceAreaWidth: props.resourceAreaWidth + 'px',

  headerToolbar: {
    left: 'today prev,next',
    center: 'title',
    right: 'resourceTimelineOneMonth,listMonth,dayGridMonth'
  },

  views: {
    resourceTimelineOneMonth: {
      type: 'resourceTimeline',
      duration: { months: 1 },
      buttonText: 'Mes',
      slotDuration: '24:00:00',
    }
  },

  resources: (fetchInfo, success, failure) => {
    const url = new URL(currentCal.value.resourceUrl, window.location.origin)
    url.searchParams.set('start', fetchInfo.startStr)
    url.searchParams.set('end', fetchInfo.endStr)
    url.searchParams.set('_t', Date.now())
    fetch(url, { credentials: 'include' })
      .then(r => r.json())
      .then(data => success(Array.isArray(data) ? data : (data?.data ?? [])))
      .catch(failure)
  },

  events: (fetchInfo, success, failure) => {
    const url = new URL(currentCal.value.eventUrl, window.location.origin)
    url.searchParams.set('start', fetchInfo.startStr)
    url.searchParams.set('end', fetchInfo.endStr)
    url.searchParams.set('_t', Date.now())
    fetch(url, { credentials: 'include' })
      .then(r => r.json())
      .then(data => success(Array.isArray(data) ? data : (data?.data ?? [])))
      .catch(failure)
  },

  eventDidMount: (info) => {
    const lines = info.event.extendedProps.tooltip
    const content = Array.isArray(lines) ? lines.join('<br>') : (lines ?? info.event.title)
    if (info.el._tippy) info.el._tippy.destroy()
    tippy(info.el, { content, allowHTML: true, appendTo: document.body, placement: 'top' })
  },

  eventClick: (info) => {
    info.jsEvent.preventDefault()
    const url = info.event.extendedProps.urlshow
    if (url) window.location.href = url
  }
}))
</script>
```

### Lo que puedes descartar (específico de EasyAdmin)

| Elemento | Por qué |
|---|---|
| FullCalendarStimulusExtension | En Vue pasas props directamente |
| Stimulus controller | Vue reemplaza el ciclo de vida |
| current_page / runtime_returnTo | Mecanismo returnTo de EasyAdmin, no aplica |
| Checks ROLE_* en providers para URLs | El frontend simplemente omite el botón si la URL es null |

### Lo que debes replicar fielmente

- Intersección de fechas: `inicio < :end AND fin > :start`
- resourceId en eventos debe coincidir exactamente con id del recurso
- Campo `orden` asignado tras ordenar recursos alfabéticamente
- `refetchResourcesOnNavigate: true`
- Cache bust con `_t=Date.now()`
- `prioridadImportante` en extendedProps para que funcione el eventOrder

---

## 11. Resumen de Archivos

| Archivo | Responsabilidad |
|---|---|
| `config/services/services_pms.yaml` | Config YAML de calendarios PMS |
| `src/Calendar/Service/CalendarConfigResolver.php` | Fusiona configs de parámetros calendars_* |
| `src/Calendar/Provider/ProviderRegistry.php` | Elige el provider por supports() |
| `src/Calendar/Controller/FullcalendarLoadController.php` | Endpoints /fullcalendar/load/event|resource/{cal} |
| `src/Calendar/Provider/PmsEventosRawCalendarProvider.php` | Eventos PMS con filtros (legacy/EasyAdmin) |
| `src/Calendar/Provider/PmsEventosSpaCalendarProvider.php` | Eventos PMS sin urledit/urlshow (SPA/Vue) |
| `src/Calendar/Provider/TarifaRangesRawCalendarProvider.php` | Tarifas con scoring de prioridad (legacy/EasyAdmin) |
| `src/Calendar/Provider/TarifaRangesSpaCalendarProvider.php` | Tarifas sin urledit/urlshow (SPA/Vue) |
| `src/Calendar/Provider/TarifaCompressedRangesCalendarProvider.php` | Tarifas compactadas (legacy/EasyAdmin) |
| `src/Calendar/Provider/TarifaCompressedRangesSpaCalendarProvider.php` | Tarifas compactadas sin urledit/urlshow (SPA/Vue) |
| `src/Calendar/Dto/CalendarEventDto.php` | DTO → JSON evento (incluye `extendedProps` opcional) |
| `src/Calendar/Dto/CalendarResourceDto.php` | DTO → JSON recurso |
| `src/Calendar/Twig/FullCalendarStimulusExtension.php` | Genera div data-* (solo EasyAdmin) |
| `assets/controllers/fullcalendar_controller.js` | Stimulus: FC, fetch, scroll, tooltips |
| `templates/panel/pms/pms_reserva/index.html.twig` | Vista reservas (legacy EasyAdmin) |
| `templates/panel/pms/pms_tarifa_rango/index.html.twig` | Vista tarifas (legacy EasyAdmin) |

---

## 12. Módulos Vue ya implementados (app `util/`)

Ambos consumen los providers `*_spa` del apartado 5 para PINTAR el calendario, y un
recurso REST de API Platform propio para EDITAR. Ningún dato de edición pasa por
`/fullcalendar/load/*`: ese endpoint es de solo lectura.

### Reservas — equivalente de `PmsReservaCrudController`

| Archivo | Responsabilidad |
|---|---|
| `util/src/views/Reservas/ReservasView.vue` | Calendario + menú contextual + drag & drop + buscador |
| `util/src/components/reservas/ReservaEditDrawer.vue` | Drawer ver/crear/editar (acordeón de estancias) |
| `util/src/stores/reservas/reservasStore.ts` | Catálogos + CRUD de evento/reserva + búsqueda |
| `util/src/types/pmsReservaModel.ts` | Tipos, helpers de IRI/fecha y reglas OTA |
| `src/Pms/Controller/Api/PmsReservaBuscarController.php` | `GET /pms/reservas/buscar?q=` (buscador del calendario) |

Calendarios: `pms_eventos_no_cancelados_spa`, `pms_eventos_todos_spa`.

#### 🧹 Quién limpia se asigna aquí

El drawer lleva, en cada estancia, las casillas del equipo de limpieza (`muestraLimpieza`,
`alternarLimpieza`). **No es sólo reparto de trabajo:** esa asignación es el filtro de
privacidad que decide qué estancias —con el nombre y el teléfono del huésped— ve cada
compañera al preguntar por el chat, así que una estancia sin asignar no le aparece a nadie de
campo. El selector no sale al CREAR (la primera estancia nace por otro endpoint) ni en los
bloqueos, y **omitir el campo no es lo mismo que mandarlo vacío**. El contrato entero y sus
porqués están en `docs/Mensajeria.md` §«Cada quien ve sus limpiezas, y sólo las suyas».

#### La barra de reserva es de DOS filas (y eso fija la altura de la fila)

`eventContent` pinta icono de canal a la izquierda —centrado sobre el alto completo— y a su
derecha una columna: nombre arriba, cifras abajo (pax, noches, total, saldo). Antes era una
sola línea con canal + pax + nombre; el dato que de verdad se busca al barrer el calendario
—cuánto falta por cobrar— obligaba a abrir la reserva.

**Hay un acoplamiento entre archivos que no se ve desde ninguno de los dos:**

| Archivo | Qué aporta |
|---|---|
| `ReservasView.vue` → `eventContent`, `.fc-reserva*` | El HTML de dos filas y sus estilos |
| `assets/fullcalendar-overrides.css` → `.fc-timeline-lane-frame` | El `min-height: 44px` que hace que quepan |

Si la barra vuelve a una sola fila, ese `44px` baja con ella (era `34px`). Y al revés: subir
contenido sin subir el `min-height` recorta por abajo sin dar ningún error.

La misma regla vale para `.fc-timeline-event .fc-event-main { height: 100% }` — sin eso el
harness de FullCalendar no propaga la altura y el icono, que se alinea al centro, se pega
al borde superior.

En estancias de una noche la barra es estrechísima: la fila de cifras se recorta entera
antes que el nombre, por diseño (`overflow: hidden` sobre `.fc-reserva-meta`). El tooltip
sigue trayendo todo, con céntimos incluidos.

#### El acordeón de estancias enfoca lo que abre

Al expandir una estancia —o añadir una nueva— el panel crece hacia abajo y el navegador no
mueve el scroll: con varias casitas apiladas se acababa mirando la mitad de un formulario sin
ver de cuál era. `enfocarEstancia(i)` lleva al scroller del drawer la **tarjeta entera**, no el
cuerpo: la cabecera (casita + fechas + pax) es justo lo que dice dónde estás.

Se dispara al expandir (no al plegar: ahí el operador ya está donde quiere), al crear una
estancia nueva —nace al final de la lista, casi siempre fuera de pantalla— y al abrir el drawer
sobre una estancia que no es la primera, que es el caso de clicar la segunda casita de una
reserva en el calendario.

Dos detalles que no se ven leyendo la función:

- **No usa `scrollIntoView()`.** Ese método escala hasta el primer ancestro desplazable y con
  el drawer abierto acaba moviendo también el fondo. La aritmética vive en
  `util/src/utils/scrollEnfoque.ts` (§3.b de `UI_Componentes_Compartidos.md`), compartida con
  los mini-formularios del panel financiero.
- **Al abrir el drawer el salto es instantáneo** (`suave: false`), no animado: el panel entra
  deslizándose y dos movimientos a la vez se leen como un fallo de render.

El **panel financiero** aplica lo mismo: al abrir «Nuevo cargo», «Editar cargo», «Nuevo pago» o
«Editar pago», el mini-formulario se lleva a la vista. Y va **encerrado en un recuadro con
cabecera de color** (verde para cargos, azul para pagos) porque antes quedaba suelto entre las
filas de la lista: no se veía dónde empezaba ni acababa, y con la lista llena parecía un cargo
más a medio pintar. El formulario de edición vive dentro del `v-for` de la lista, así que su
`ref` **tiene que ser de función** — ver el aviso de §3.b.

#### Dos borrados distintos en la misma pantalla

Conviven, y confundirlos cuesta caro:

| Acción | Dónde | Qué hace | Condición |
|---|---|---|---|
| **«Quitar»** (papelera en la cabecera) | barra de la estancia | Saca la fila del formulario. **No llama a la API.** | `!eventoId` — la estancia nunca llegó al servidor |
| **«Eliminar»** (zona roja del final) | pie del formulario | `DELETE` real + encola el borrado en Beds24 | `puedeBorrarseYa()` (§ anterior) |

La papelera existe para deshacer un «Agregar estancia» pulsado por error. Por eso su condición
es `!eventoId` y no `safeToDelete`: sobre algo que no existe en el servidor no hay reglas de
canal ni de sincronización que consultar. Nunca se ofrece sobre la última fila que queda.

**Las filas necesitan clave propia (`entry.uid`).** La clave del `v-for` era
`eventoId ?? 'nuevo-' + i`, y una estancia nueva no tiene `eventoId`: al descartar una
intermedia, las de abajo cambiaban de índice, Vue reutilizaba el DOM de la equivocada y el
formulario de una casita quedaba montado sobre los datos de otra. `uid` es un contador local,
vive sólo mientras el drawer está abierto y no viaja al backend.

**Affordance de la barra:** «Mismas fechas» y «Quitar» llevan etiqueta además del icono —el
doble calendario solo no se entendía—, y se repliegan a icono en pantallas estrechas. El botón
«Agregar estancia en otra casita» pasó de borde punteado gris (se leía como deshabilitado y no
lo encontraba nadie) a botón sólido con el color de acción del drawer.

#### La hora de entrada no mueve la de salida

`onCambiarInicio()` arrastra el check-out **sólo cuando cambia el DÍA**, medido con
`diasEntre()`, que ignora la hora. Ajustar la hora de llegada —un huésped que avisa de que
entra a las 18:00— no dice nada sobre cuándo se va, y la hora de salida es del establecimiento.
Si sólo cambió la hora se sale antes incluso de la red de seguridad del rango: con las fechas
ya válidas no hay nada que recomponer y entrar ahí sólo podía estropearlo.

La otra mitad de esta regla está en el template y es la que de verdad rompía: el picker de
salida recibe `:min-date="soloDia(entry.form.inicio)"`, sin la hora. Ver §1.6 de
`docs/UI_Componentes_Compartidos.md` — `VueDatePicker` usa la hora de `min-date` como suelo del
reloj, así que pasarle la fecha completa hacía que tocar el check-in reajustase el check-out.

#### Guardar NO cierra el drawer

`ReservaEditDrawer` tiene dos botones de guardado y el grande **deja el editor abierto**:

| Botón | Emite | La vista |
|---|---|---|
| «Guardar Cambios» (principal) | `saved { cerrar: false }` | refresca el calendario y **mantiene** el drawer |
| «Guardar y cerrar» (pequeño, a su izquierda) | `saved { cerrar: true }` | refresca y cierra |

La razón no es estética: guardar casi siempre es el **paso previo** a otra cosa sobre la misma
reserva —cancelar una estancia y esperar a que Beds24 lo confirme para poder borrarla, poner
importe a un cargo recién nacido, revisar el resultado—. Cerrando había que volver a buscar la
reserva en el calendario para cada uno de esos pasos.

Al guardar sin cerrar se hace `cargarDatos()` en vez de confiar en el formulario: `safeToDelete`,
`motivoNoBorrable` y `syncStatus` los calcula el backend y son justo los que deciden si la zona
de borrado se abre. El caso de «horario extra» ya funcionaba así desde antes (§7.1.b de
`PmsBeds24ReservasSync.md`); ahora es la norma y no la excepción.

#### Borrado desde el drawer: por qué espera a la sincronización

La zona de borrado sólo aparece en edición, sobre una estancia que ya existe, y exige **dos**
condiciones — la segunda es la que no se ve venir:

```
puedeBorrarseYa(borrable, sync)          util/src/types/pmsReservaModel.ts
  ├─ safeToDelete === true               veredicto del backend (OTA / en Beds24 sin cancelar / cola tomada)
  └─ syncStatus ∈ {synced, local}        que NO quede push por delante
```

**Con `safeToDelete` solo no basta.** En cuanto la estancia pasa a «cancelada» entra en
`ESTADOS_BORRABLES_CON_ID` y el motivo desaparece, aunque el push de esa cancelación siga
encolado (`pending` no bloquea, sólo `processing`). Verificado en datos reales:

```
estado=pendiente   syncStatus=pending   safeToDelete=true   motivo=—
```

Si se borra en esa ventana, al eliminar el link `cancelPendingPostForLink()` **mata el POST de
la cancelación** y el DELETE llega a Beds24 sobre una reserva todavía confirmada — lo único que
Beds24 no acepta borrar. Resultado: la reserva se queda viva en el canal bloqueando las noches.

Por eso, cuando lo único que falta es el push, el drawer no ofrece «Eliminar» sino **«Esperar y
eliminar»**: un sondeo de `cargarDatos()` cada 4 s (máx. ~3 min) que habilita el borrado en
cuanto `syncStatus` pasa a `synced`. Es un sondeo y no un evento porque el push lo procesa un
worker asíncrono: desde el navegador no hay nada a lo que suscribirse. Se corta solo si la
sincronización falla (`error`) o si se agotan los intentos, y el operador puede pararlo.

Se ofrecen dos borrados distintos, y la diferencia importa cuando la reserva tiene varias
casitas: **«Eliminar esta estancia»** (`DELETE` del evento) y **«Eliminar reserva completa»**
(`DELETE` de la reserva, que cascadea a todas sus estancias). Cada uno con su propio veredicto,
porque una reserva puede tener una estancia borrable y otra que no.

Tras borrar, el drawer emite `deleted` y la vista (`onBorrado`) refresca y cierra: ya no hay
nada sobre lo que quedarse abierto.

> **Espejo backend.** El campo `syncStatus` se serializa desde
> `PmsEventoCalendario::getSyncStatus()` (y `syncStatusAggregate` desde
> `PmsReserva::getSyncStatusAggregate()`) **sólo para esto**. Si tocas la regla, mira también
> §12.12 de `PmsBeds24ReservasSync.md`: el comentario de la entidad documenta la misma carrera
> desde el otro lado.

#### Buscador de reservas y salto al día

El feed del calendario **solo carga el rango visible** (`e.inicio < :to AND e.fin > :from`,
ver `PmsEventosSpaCalendarProvider::fetchEventos()`): sin buscador no hay forma de dar con
la reserva de un huésped sin saber de antemano su mes. Esa es la razón de ser de
`PmsReservaBuscarController`, y por eso **no filtra por rango de fechas**.

Decisiones que no se ven en el código:

- **Devuelve estancias, no reservas.** Una reserva de dos casitas son dos
  `PmsEventoCalendario` con fechas y unidad propias; el salto en el calendario es a un
  tramo concreto, no a la reserva. De ahí una fila por estancia en el desplegable.
- **Un término = una palabra, AND entre términos**, cada uno contra
  `CONCAT(nombreCliente, ' ', apellidoCliente)`, localizador, `referenciaCanal` y nombre de
  casita. Así "juan perez" encuentra al huésped aunque nombre y apellido vivan en columnas
  distintas, y "perez casita" no devuelve a todos los Pérez.
- **Orden por cercanía a hoy** (`ABS(DATE_DIFF(e.inicio, CURRENT_DATE()))`), no por fecha
  descendente: lo que se busca casi siempre es la reserva de estos días.
- **`estadoId` viaja al frontend** para un caso concreto: si la estancia encontrada está
  cancelada y el calendario activo es "No canceladas", `irAResultado()` cambia a "Todas"
  antes de saltar. Sin eso el salto acaba en una fila vacía y parece que la búsqueda mintió.

**Gotcha de FullCalendar — el día, no solo el mes.** `gotoDate()` coloca el *rango* (el
mes) pero no toca el scroll horizontal de la línea de tiempo. El desplazamiento fino es
`scrollToTime({ days: n })`, que en vistas timeline cuenta desde `view.activeStart`; de ahí
el cálculo de días de `scrollAlDia()`, hoy delegado en
**`util/src/utils/calendarTimeline.ts`** (ver más abajo). La vieja limitación de "hay que esperar a que
renderice" **está resuelta en FC 6**: `ScrollResponder` (en `@fullcalendar/core`) encola la
petición y la reintenta en cada update hasta que el timeline tiene coordenadas. El matiz que
sí queda: con `scrollTimeReset` (activo por defecto) un cambio de rango re-dispara el scroll
por defecto y pisaría una petición anterior — por eso el salto se emite en el
`requestAnimationFrame` *posterior* al cambio de rango, y `datesSet` / `loading` reintentan
el `diaPendiente`.

#### Gotcha PWA iOS — `window.open()` después de un `await` se pierde en silencio

El submenú **"Enviar plantilla"** de WhatsApp resuelve los enlaces de *todas* las plantillas
al abrirse (`precargarLinksWhatsapp()`, `Promise.allSettled`) y pinta cada una como un `<a
href>` de verdad. Parece un rodeo — lo natural sería un `<button>` que pida el texto al
backend y abra WhatsApp — pero ese camino **no funciona en la PWA instalada de iOS**:

> Safari solo permite abrir ventanas **dentro del gesto del usuario**. Un `window.open()`
> ejecutado tras un `await` ya está fuera de él y iOS lo descarta **sin error y sin ventana**:
> desde el móvil parece que la app se colgó. En modo standalone (icono en la pantalla de
> inicio) es sistemático, no intermitente.

Reglas que se siguen de esto, aplicables a cualquier vista de `util/`:

- Todo lo que abra algo externo cuelga de un `<a href target="_blank">` con la URL **ya
  resuelta antes** de pintarse — nunca de un handler asíncrono. `pmsReservaModel.ts` expone
  por eso `whatsappUrl()` (construye la URL) y **no** una función que la abra.
- Si el enlace depende de una llamada, la llamada se hace **al abrir el menú**, no al pulsar
  la opción. N peticiones pequeñas en paralelo son preferibles a un popup bloqueado.
- Mientras no haya URL, la fila se pinta inerte (sin `href`, atenuada), no como un botón que
  parece funcionar y no hace nada.

Quedan `window.open()` en `ChatView.vue` (adjuntos) y `CotizacionEditorView.vue` (vista
previa): esos sí están dentro del gesto, pero si alguna vez se les mete un `await` delante,
caen en la misma trampa.

#### El «atrás» del móvil cierra capas (y su acoplamiento con el drawer)

`ReservasView` y `TarifasView` registran `onBeforeRouteLeave`: si hay algo abierto encima
del calendario (menú contextual → drawer → resultados de búsqueda), el back cierra **una
capa** y cancela la navegación devolviendo `false`; solo con el calendario limpio se sale al
portal. Es el gesto instintivo en móvil para cerrar un formulario. Vue Router restaura la
posición del historial al abortar, así que el back sigue disponible para el siguiente
intento.

⚠️ **Consecuencia no obvia:** el guard cancela *cualquier* salida de ruta, no solo el back.
Todo `router.push()` disparado desde dentro del drawer tiene que **cerrarlo antes** o el
guard se lo traga. Es lo que hace `ReservaEditDrawer::abrirChatInterno()` (emite `close` y
luego navega a `/chat?id=`). Si mañana se añade otro botón que navegue desde el drawer,
mismo requisito.

### Tarifas — equivalente de `PmsTarifaRangoCrudController`

| Archivo | Responsabilidad |
|---|---|
| `util/src/views/Tarifas/TarifasView.vue` | Calendario + menú contextual + drag & drop |
| `util/src/components/tarifas/TarifaEditDrawer.vue` | Drawer ver/crear/editar/eliminar de un rango |
| `util/src/components/tarifas/TarifaMasivaDrawer.vue` | Acción "Generar Masivo" |
| `util/src/stores/tarifas/tarifasStore.ts` | Catálogos (unidad/moneda) + CRUD + masivo |
| `util/src/types/pmsTarifaModel.ts` | Tipos, helpers de IRI/fecha y presentación |

Calendarios: `tarifa_rangos_raw_spa` (editable) y `tarifa_rangos_compactados_spa` (solo lectura).

Endpoints REST (`PmsTarifaRango`, `routePrefix: /pms`):

```
GET    /platform/pms/pms_tarifa_rangos            (colección, con filtros unidad/fecha/activo)
GET    /platform/pms/pms_tarifa_rangos/{id}
POST   /platform/pms/pms_tarifa_rangos
PATCH  /platform/pms/pms_tarifa_rangos/{id}
DELETE /platform/pms/pms_tarifa_rangos/{id}
POST   /platform/pms/pms_tarifa_rangos/generar-masivo
```

Puntos que costaron y conviene no re-descubrir:

- **Los compactados no son filas de la tabla.** Son segmentos calculados a partir de
  los rangos solapados: se pueden abrir (vía `extendedProps.tarifaRangoId`, que apunta
  al rango ganador) pero NO admiten drag & drop, de ahí el flag `editable` por calendario.
- **`fechaFin` es EXCLUSIVA: se cuenta en NOCHES, no en días.** `TarifaDailyPriceFlattener::flatten()`
  normaliza todo rango a `[inicio, fin)` y descarta el día `fin`
  (`if ($day >= $cand['end']) continue`). Una tarifa **del 1 de abril al 1 de mayo** pone
  precio a las noches del 1 al 30 de abril: **la del 1 de mayo no se envía a Beds24**. Es la
  convención de una reserva —`fin` es la salida—, y es coherente con cómo se dibuja la barra
  (el provider inyecta 12:00 → 11:59, así que el día `fin` sólo se pinta hasta media mañana).

  Corolario que muerde: con **`fin === inicio` el rango cubre CERO noches** y el flattener lo
  descarta entero (`if ($re <= $rs) continue`). Se guardaba sin error y no hacía nada. Por eso
  ahora `PmsTarifaRango::$fechaFin` lleva `Assert\GreaterThan` (era `GreaterThanOrEqual`, con
  un comentario que decía admitir «rangos de un solo día» — no admitía nada), el
  `PmsTarifaMasivaProcessor` ya exigía `fin > inicio`, y los dos drawers ponen
  `:min="fechaInicio + 1"`.

  En el front la cuenta es `nochesDeRango()` (`fin - inicio`, en `pmsTarifaModel.ts`), que
  sustituye a `diasDeRango()` (inclusiva, `+ 1`). Todo lo derivado estaba una noche corrido:
  la ventana por defecto, el rango al arrastrar sobre el calendario y los textos del
  formulario. Caso peor: **arrastrar sobre UNA celda** producía `fin === inicio` —
  `onSelect()` restaba un milisegundo al `end` de FullCalendar para «hacerlo inclusivo»— y
  creaba una tarifa muerta. Ahora `info.end` se copia tal cual: ya es exclusivo, igual que
  `fechaFin`.

  Puede haber filas antiguas con `fecha_fin = fecha_inicio`; nunca se aplicaron
  (`SELECT ... WHERE fecha_fin = fecha_inicio` las lista).
- **Fechas.** `fechaInicio`/`fechaFin` son columnas `date`. La API las serializa a
  medianoche UTC, así que el día se extrae por string (`iso.slice(0,10)`), nunca con
  `new Date().getDate()` — en UTC-5 eso devuelve el día anterior. En el calendario, en
  cambio, el provider inyecta horas de UI (12:00 → 11:59) y FullCalendar mueve en
  múltiplos de 24 h, por lo que ahí sí vale leer el día local de `event.start/end`.
- **`is_scalar()` es FALSO para un `Uuid`.** Trampa que costó dos bugs en
  `TarifaCompressedRangesSpaCalendarProvider`, porque falla en silencio: no hay
  error, simplemente se toma la rama del fallback.
  - En `groupByUnit()` el `resourceId` caía a `spl_object_id()`, un entero
    efímero del proceso PHP. Como eventos y recursos se piden en **requests
    separados**, sólo coincidían por casualidad (mismo orden de hidratación de
    Doctrine), y ningún otro calendario podía cruzar ese id con una unidad real.
  - En `rangeAccessor()` el id llegaba como objeto a
    `TarifaDailyPriceFlattener::computeSourceId()`, que también filtra por
    `is_scalar()`: generaba un `sourceId` de tipo `hash:…` en vez de `id:<uuid>`,
    y como el provider sólo acepta el prefijo `id:`, `extendedProps.tarifaRangoId`
    salía `null` y los tramos no eran clicables.

  La solución en ambos casos es `scalarToStringOrNull()`, que sí resuelve el
  `Uuid` por su `__toString()`. Ojo también con el compresor: exige
  `is_string($sourceId)` para propagarlo.

  **El provider legacy `TarifaCompressedRangesCalendarProvider` sigue con los dos
  bugs** (mismas líneas), así que sus `urledit`/`urlshow` de tramos compactados
  apuntan mal. No se tocó por la regla de no modificar el legacy.

- **El borrado no necesita blindaje.** `Beds24RatesPushQueueListener` encola el push
  del intervalo afectado también en `scheduledEntityDeletions`, a diferencia de
  `PmsEventoCalendario`, que sí tiene `isSafeToDelete()`.

- **El título del evento lo manda el YAML, no el código.** `formatTitle()` de ambos
  providers `_spa` resuelve placeholders (`{price} {currency} {minStay} {neto20}
  {neto30}`); antes ignoraba el `titleFormat` recibido y lo tenía hardcodeado. Los
  netos al 20%/30% viven ahora sólo en el tooltip. Relacionado: `fields.currency`
  apuntaba a `moneda.codigo`, un getter que **no existe** en `MaestroMoneda`, así
  que la moneda siempre resolvía a `null`; las claves `_spa` usan
  `moneda.simbolo`. Los configs legacy conservan el `moneda.codigo` roto.
- **`generar-masivo` delega en `GeneradorTarifaMasivaService`**, el mismo servicio que
  usa EasyAdmin: el processor solo traduce el payload HTTP al DTO interno.

#### Valores por defecto al crear una tarifa

Una tarifa nueva casi nunca se inventa de cero: nace para **retocar la que ya rige** ese día
(subirla un puente, bajarla en temporada baja). El formulario en blanco obligaba a ir al
calendario, leer el precio vigente, teclearlo, y encima adivinar una prioridad que dejara la
tarifa nueva por encima — si no, se guardaba sin efecto y nadie se enteraba.

Al abrir el drawer **en modo creación** se precarga:

| Campo | Valor por defecto | De dónde sale |
|---|---|---|
| `fechaInicio` / `fechaFin` | ventana de **3 noches** (`VENTANA_POR_DEFECTO_NOCHES` en `TarifasView.vue`, sumada entera porque `fechaFin` es exclusiva) | sólo cuando se crea desde el botón de la cabecera; la selección en el calendario manda |
| `precio`, `minStay` | los del tramo vigente ese día en esa casita | `GET /fullcalendar/load/event/tarifa_rangos_compactados_spa?start=D&end=D+1` |
| `prioridad` | **prioridad del rango ganador + 1** | `GET /platform/pms/pms_tarifa_rangos/{tarifaRangoId}` |
| `moneda` | la del rango ganador | ídem |

Detalles que no se ven en el código:

- **El `end` del feed es EXCLUSIVO**: `[D, D+1)` es «sólo el día D». Misma técnica que
  `cargarTarifaDelDia()` en `ReservasView`.
- **La prioridad no viaja en `extendedProps`** —el compactado sólo lleva el precio ganador—,
  por eso hace falta el segundo GET al rango que identifica `extendedProps.tarifaRangoId`.
  Se pide con `apiClient` y **no** con `tarifasStore.fetchTarifa()`: el store guardaría ese
  rango en `tarifaActiva` y encendería su `isLoading`, y aquí no se está editando, sólo
  espiando.
- **`precioTocado`**: en cuanto el operador teclea en el precio, la precarga deja de pisarlo.
  Cambiar casita o día después de eso ya no lo revierte.
- **`ultimaPrecarga`** evita la petición duplicada: `cargarDatos()` precarga al abrir y el
  watch de `[unidad, fechaInicio]` volvería a hacerlo con los mismos valores.
- Si no hay tarifa vigente ese día (o el feed falla) el formulario se queda como estaba: es
  una comodidad, no un requisito para crear.

**Fin nunca antes que el inicio**, en tres capas: el `min` del `<input type="date">` (el
navegador no ofrece días anteriores), un watch que **arrastra** el fin conservando la duración
si el inicio lo adelanta, y el guard de `guardar()` para lo tecleado a mano.

#### La tarifa base de la unidad, visible en el calendario

El precio base **no vive en los rangos**, sino en la unidad (`PmsUnidad::$tarifaBasePrecio`,
`$tarifaBaseMinStay`, `$tarifaBaseMoneda`, `$tarifaBaseActiva`) y es el único insumo de
«Generar Masivo». Estaba oculto: había que abrir la ficha de la unidad en EasyAdmin para
saberlo.

Ahora viaja en `pms_unidad:read` y se pinta bajo el nombre de cada casita
(`resourceLabelContent` en `TarifasView.vue`, con `resourceAreaWidth` subido a 110 px).

- La moneda va **aplanada** — `getTarifaBaseMonedaId()` / `getTarifaBaseMonedaSimbolo()`—
  porque `/platform/pms/pms_unidads` normaliza sólo con `pms_unidad:read` y `MaestroMoneda`
  no publica nada en ese grupo: la relación saldría como IRI.
- El feed de recursos sólo trae `title`, así que el precio se cruza por id contra el catálogo
  REST (`tarifasStore.unidades`). Por eso `TarifasView` llama a `fetchMasters()` y, al
  resolverse, hace `refetchResources()`: **FullCalendar no reacciona a Pinia**.
- Tras tocar los grupos hay que **regenerar `api.d.ts`** (ver `docs/Operacion.md` §8), o
  `vue-tsc` no conoce los campos nuevos.

#### «Generar Masivo»: qué propone el formulario

`GeneradorTarifaMasivaService` crea un rango por cada unidad con `activo = true` y
`tarifaBaseActiva = true`, con precio `round(base × (1 + %/100))` — **sin decimales**: el
precio de venta se publica redondo en los canales y no tiene sentido tarificar al céntimo.
El formulario no pide importe, y eso lo hacía opaco: no se veía el resultado hasta generar.

| Campo | Valor por defecto | De dónde sale |
|---|---|---|
| `fechaInicio` / `fechaFin` | el rango **visible**, del 1 al 1 | `view.currentStart` / `view.currentEnd` de `datesSet`, que `TarifasView` pasa como prop `rangoDefecto` — un mes o dos según la vista. Del 1 al 1 es exactamente el mes en noches, porque `fechaFin` es exclusiva |
| `prioridad` | **la más alta de TODAS las casitas en esa ventana + 1** | `GET /platform/pms/pms_tarifa_rangos?activo=true&fechaInicio[before]=fin&fechaFin[after]=inicio&order[prioridad]=desc` |

Y una **leyenda en vivo** lista casita → `base → efectivo` mientras se teclea el porcentaje
(verde si sube, rojo si baja). Es **espejo PHP ↔ TS** del redondeo: `filasEfectivas` en
`TarifaMasivaDrawer.vue` repite el `round()` de `GeneradorTarifaMasivaService::procesar()`.
Si cambia uno, hay que tocar el otro o la leyenda mentirá.

Detalles:

- **El solapamiento se consulta con `strictly_before` / `strictly_after`**, no con
  `before`/`after`: un rango que termina justo el día en que empieza la ventana no la pisa
  —su última noche es la víspera—, y uno que empieza el día de fin tampoco. Con los
  operadores no estrictos se contaban esos dos vecinos y la prioridad salía inflada.
- **La prioridad se pide de todas las unidades a la vez**, no por casita: el masivo escribe en
  todas y basta una tarifa más alta para dejarlo tapado — generado y sin efecto, que es el peor
  resultado posible porque no da error.
- **No hace falta paginar** para el máximo: `order[prioridad]=desc` deja el mayor en la primera
  fila. Funciona porque `FilterExtension` (prioridad −16) corre ANTES que `OrderExtension`
  (−32), y ésta se abstiene si el `QueryBuilder` ya trae un `orderBy` — así que el
  `order: ['fechaInicio' => 'ASC']` del `#[ApiResource]` **no** se antepone. `itemsPerPage` no
  está habilitado como parámetro de cliente.
- `prioridadTocada` congela la sugerencia en cuanto el operador teclea la suya.

#### El portal también consume el feed: panel «Hoy» de HomeView

`HomeView` abre con dos tarjetas —**Check-in** y **Check-out** del día— que salen del mismo
`/fullcalendar/load/event/pms_eventos_ocupacion_spa`, **no de un endpoint nuevo**: el backend ya
resuelve ahí el solape con el rango y manda cliente, casita y pax en `extendedProps`. Es el
tercer consumidor de esa clave, después del fondo beige de Tarifas.

- Se pide `[hoy, mañana)` y se clasifica en el front: `start` del día → llega, `end` del día →
  sale. Una estancia larga aparece solo el día que entra y el día que se va.
- **El día se compara por string** (`slice(0, 10)`), nunca con `new Date()`: el feed manda hora
  local sin zona y construir un `Date` para leer el día es lo que produce el clásico desfase de
  un día en UTC-5.
- Hereda el criterio de `PmsEventoEstado::OCUPAN_UNIDAD`: un bloqueo o un *inquiry* no son un
  check-in. Cambiar qué cuenta como llegada se hace en esa constante, no en la vista.
- El fetch solo se lanza **con sesión activa**; si no, el 401 abriría el modal de login nada más
  entrar al portal. Un fallo deja las tarjetas vacías y no avisa: el portal tiene que seguir
  siendo navegable.
- **Cada fila abre la ficha**: navega a `/reservas?evento=<id>[&reserva=<id>]` y `ReservasView`
  despliega el drawer en modo LECTURA desde su `onMounted`. Viajan ids por la query —y no un
  objeto por `state`— porque el drawer solo necesita eso (`abrirEdicion()` usa
  `eventoId`/`reservaId`) y así el enlace es compartible y sobrevive a un refresco.
  `ReservasView` **limpia la query** con un `replace` en cuanto abre: si no, cerrar la ficha y
  volver atrás la reabriría.
- En las salidas la hora que se pinta es la de **fin** de la estancia, no la de inicio.

#### Ocupación de fondo en el calendario de tarifas

Tarifar a ciegas era el problema: la grilla mostraba precios pero no qué días están vendidos,
así que no se veía dónde hace falta ajustar precio o estancia mínima. `TarifasView` carga por eso
una **segunda fuente de eventos** con las estancias y las pinta como eventos de fondo.

```
TarifasView.calendarOptions.eventSources
├── 'tarifas'    GET /fullcalendar/load/event/{tarifa_rangos_raw_spa|…_compactados_spa}
│                └── pintarPorCasita()  → barras de precio (color por casita)
└── 'ocupacion'  GET /fullcalendar/load/event/pms_eventos_ocupacion_spa   (opcional)
                 └── comoFondo()        → display:'background' + COLOR_OCUPADO (beige)
```

Decisiones y por qué:

- **Calendario propio en el YAML, no reúso de `pms_eventos_no_cancelados_spa`.** Ocupa quien
  está en `PmsEventoEstado::OCUPAN_UNIDAD` — `pendiente`, `confirmada`, `requerimiento` —, y el
  YAML lo referencia con `!php/const` en `filters.estado.in` para no mantener dos listas:

  ```yaml
  estado:
      in: !php/const App\Pms\Entity\PmsEventoEstado::OCUPAN_UNIDAD
  ```

  Quedan fuera `cancelada` (la casita volvió a estar libre), `abierto` (*inquiry* de Airbnb:
  consulta sin reserva; teñirlo daba por ocupados días perfectamente vendibles) y `bloqueo`
  (no es una venta que tarifar). **Es lista blanca a propósito**: un estado nuevo no pintará
  ocupación hasta que se agregue a la constante, que es donde vive el porqué de cada exclusión.

  ⚠️ Esa lista es exactamente el complemento de
  `PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES`, pero **es casualidad**: aquélla regula
  qué estado puede elegir un humano en un evento OTA, ésta qué se pinta como ocupado. No las
  derives la una de la otra o cambiar una arrastrará a la otra sin querer.
- **Front, no provider.** El provider de tarifas no sabe nada de esto: son dos feeds
  independientes que FullCalendar compone. Así la ocupación se puede apagar sin coste de
  servidor y ninguno de los dos providers de tarifas cambia.
- **`display: 'background'` no es cosmético.** Los eventos de fondo de FullCalendar no son
  arrastrables ni clicables y **no interceptan el `dateClick`**: el flujo de «crear tarifa aquí»
  sigue funcionando igual sobre un día ocupado, que es justo cuando más se usa. `eventContent`,
  `eventDidMount` y `onEventClick` ramifican por `event.display === 'background'`.
- **Gotcha del id.** Los dos feeds conviven en el mismo calendario y FullCalendar trata dos
  eventos con el mismo `id` como el mismo evento. `comoFondo()` prefija con `ocupacion-`.
- **Gotcha del CSS.** `.fc-bg-event` viene con `opacity: .3` de fábrica; a ese nivel el beige
  desaparecía contra el blanco de la grilla. La regla `.fc-ocupado` fuerza `opacity: 1` y añade
  el rayado diagonal; el color sigue siendo `COLOR_OCUPADO` (`pmsTarifaModel.ts`), que viaja en
  el propio evento para no duplicar la fuente de verdad.
- **Los ids de recurso cuadran** porque ambos feeds usan el uuid de `PmsUnidad`
  (`resourceId`); si no coincidieran, FullCalendar descartaría los eventos de fondo en silencio.
  Ver la trampa de `is_scalar()` más arriba: es el mismo id que allí se rompía.
- **El desfase horario es intencionado.** Las barras de tarifa llevan horas de UI 12:00 → 11:59
  (`eventTime` del YAML) y las estancias sus horas reales de check-in/out (14:00 → 10:00), así
  que el fondo no cuadra al píxel con la barra de precio. Es coherente con lo que muestra
  `ReservasView` y evita el error contrario: redondear a días enteros pintaría como ocupada la
  noche del día de salida, que sí se puede vender.
- El interruptor «Ocupación» de la cabecera persiste en `localStorage`
  (`fc_state_<pathname>_ocupacion`), en la misma línea que la fecha y la vista.

#### Texto de la barra pegado al scroll (ambas vistas)

Un rango de tres semanas —o una estancia larga— se pinta como una sola barra, y FullCalendar
dibuja su contenido al principio del rango: basta desplazar la línea de tiempo para quedarse
mirando barras de color **sin un solo dato**. El contenido de `eventContent` va por eso en
`position: sticky` y acompaña al scroll mientras la barra siga en pantalla:

| Vista | Selector |
|---|---|
| Tarifas | `.fc-tarifa` (todas sus vistas son `resourceTimeline`) |
| Reservas | `.fc-timeline-event .fc-reserva` — el selector queda acotado a la timeline aunque hoy sea la única familia de vistas, por si vuelve alguna sin scroll horizontal |

No basta con `position: sticky`, hacen falta las tres cosas:

| Declaración | Por qué |
|---|---|
| `position: sticky; left: 0` | El scrollport que manda es el `.fc-scroller` del cuerpo de la timeline; ningún ancestro del evento (`.fc-timeline-event`, `.fc-event-main`) declara `overflow`, así que el cálculo llega hasta él. Si algún día FullCalendar les pusiera `overflow: hidden`, el sticky dejaría de moverse **sin romper nada visible**: ese es el síntoma a buscar. |
| `width: fit-content` | Con el ancho completo de la barra no hay margen donde desplazarse y `sticky` no hace nada. Es el error silencioso más fácil de cometer aquí. |
| `max-width: 100%` | Sin él, en las barras cortas el texto se sale por la derecha: ni `.fc-timeline-event` ni `.fc-event-main` recortan. |

FullCalendar trae una clase `.fc-sticky` (`position: sticky`) que usa en las etiquetas de los
slots y en el «+N más», pero **no** en el contenido de los eventos: hay que ponerlo a mano.

#### Tooltip y menú contextual en táctil (ambas vistas)

En escritorio el tooltip (hover) y el menú (clic) no compiten: el tooltip se va al mover el
ratón. En táctil **un solo tap dispara los dos**, y sobre las filas de abajo el tooltip flotante
caía justo encima del menú. La solución, igual en `ReservasView` y `TarifasView`:

- `const ES_TACTIL = window.matchMedia('(hover: none)').matches`.
- tippy se crea con **`touch: false`**: en táctil no aparece flotando.
- La misma ficha se pinta **dentro del menú**, como cabecera (`.fc-tip-menu`). En Reservas es
  el HTML de `tooltipHtml()` vía `v-html` (todo valor sale escapado de ahí); en Tarifas, las
  líneas de `extendedProps.tooltip` que ya manda el provider, guardadas en `MenuState`.
  Se sigue viendo todo de un tap, pero apilado y sin solaparse nunca.
- El fondo oscuro lo ponía `.tippy-box`; dentro del menú blanco hay que repetirlo en
  `.fc-tip-menu` o el texto claro queda ilegible.

Descartado a propósito: pasar el tooltip a *long-tap* (`touch: 'hold'` de tippy) escondía
justo el dato que se busca al tocar, y anclar el tooltip flotante al menú con
`getReferenceClientRect` deja el mismo problema de siempre —tooltip + menú no caben apilados
sobre el punto del tap— pero resuelto por Popper, que puede voltearlo fuera de la pantalla.

**Posición del menú: medida, no estimada.** Las constantes `MENU_ALTO`/`MENU_ANCHO` solo evitan
que el panel *nazca* fuera de la pantalla. La posición real la fija `reubicarMenu()` leyendo
`offsetWidth/offsetHeight` del panel, disparada por un `ResizeObserver` sobre él. Hace falta un
observer y no un `nextTick`: el alto cambia **después** del primer render cada vez que llega un
fetch (la ficha del huésped, el enlace a la extranet del canal, la lista de plantillas de
WhatsApp, la tarifa del día). El panel lleva además `max-h-[85vh] overflow-y-auto` como última
red por si aun así no cabe.

#### Vistas y encuadre del scroll (ambas vistas)

**Las dos vistas son las mismas en Reservas y en Tarifas**: `resourceTimelineTwoMonths`
(«2 Meses») y `resourceTimelineOneMonth` («Mes»), ambas con `slotDuration: '24:00:00'`.

Reservas tenía además `resourceTimelineOneWeek` («Semana») y `listMonth` («Lista»); se
**retiraron**: nadie operaba con ellas y quien caía por error se quedaba sin la rejilla de
casitas, que es lo que se viene a mirar. Con ellas se fueron los plugins `@fullcalendar/daygrid`
y `@fullcalendar/list` del `plugins:` de la vista (siguen en `package.json`, sin usar). Un valor
antiguo guardado en `localStorage` no pasa `FC_VISTAS_PERMITIDAS` y cae al por defecto, así que
nadie queda atrapado en una vista que ya no existe.

**El botón «Hoy» es propio y se llama `irHoy`, no `today`.** Dos capas de trampa aquí:

1. El botón nativo solo llama a `today()`, y `today()` **no hace nada si el mes actual ya es
   el visible**: seguías mirando el día 1 sin ninguna señal de que el botón hizo algo. La
   versión propia navega *y* desplaza.
2. **Redefinir `customButtons.today` no basta.** El clic sí se sustituye (los custom ganan al
   estándar en `buildToolbarProps`), pero el estado deshabilitado se decide por el **nombre**:
   `isDisabled = !isTodayEnabled && buttonName === 'today'`, con
   `isTodayEnabled = !rangeContainsMarker(currentRange, now)`. O sea que el botón salía
   apagado exactamente en el caso que se quería arreglar. Con otro nombre (`irHoy`) está
   siempre activo. El `headerToolbar` lo referencia por ese nombre.

**Encuadre de cada rango** — `encuadrarTimeline()` en `datesSet`. Manda la **dirección de
la navegación**, no dónde caiga hoy:

| Situación | Scroll |
|---|---|
| Se pulsó «anterior» (el rango empieza antes que el anterior) | al final: los días pegados a aquel del que vienes |
| Se pulsó «siguiente» | al principio |
| Sin navegación con la que comparar (primer montaje, o cambio de vista que conserva el inicio del rango) | a hoy si cae dentro; si no, al extremo por el que se llega |

> ⚠️ **La prioridad importa, y falla en un solo mes.** La primera versión ponía «si hoy está
> en el rango, ir a hoy» por delante de todo, y eso rompía el **mes en curso y solo ese**:
> retroceder de septiembre a agosto —con hoy en agosto— saltaba al día de hoy en vez de al
> final del mes. Parece un problema de caché o de `localStorage` y no lo es. Para ir a hoy ya
> está el botón «Hoy».

Ir al extremo derecho pide **los dos caminos a propósito**: un `scrollToTime()` de la
duración completa del rango (el oficial: su `ScrollResponder` reaplica la última petición y así
sobrevive a los re-renders que FullCalendar hace cuando llegan los eventos) y, acto seguido, un
`scrollLeft = scrollWidth` directo sobre el scroller del cuerpo, que no depende de que las
columnas estén ya medidas. De ahí que `encuadrarTimeline()` reciba el elemento raíz del
calendario: el scroller se localiza desde `.fc-timeline-body` (`closest('.fc-scroller')`),
porque FullCalendar monta varios `.fc-scroller` y solo el del cuerpo scrollea en horizontal.

> ⚠️ **Y hay que ESPERAR a que el scroller desborde.** Es el fallo que se comió este arreglo
> en el primer intento y no deja rastro: en el `requestAnimationFrame` siguiente a `datesSet`
> la rejilla nueva todavía mide `scrollWidth === clientWidth`, así que el `scrollLeft` se queda
> en cero y no pasa nada. `conScrollerListo()` reintenta cada 50 ms hasta 1 s —exactamente lo
> que hacía `applyScroll()` en el controlador Stimulus legacy— y solo entonces aplica el
> scroll. Si el rango entero cabe en pantalla, los reintentos se agotan sin hacer nada, que es
> el resultado correcto.

**El criterio es la DIRECCIÓN de la navegación, no si el rango es pasado o futuro.** Al pulsar
«anterior» se salta al final del rango aunque sea futuro: lo que se busca al retroceder son los
días pegados a aquel del que vienes. El rango anterior se guarda en un `WeakMap` por
`CalendarApi` dentro del helper —así la lógica no se reparte entre las vistas—; sin él (primer
montaje) se decide por pasado/futuro, que da lo mismo por otro camino. Es el mismo criterio del
`lastViewRange` del controlador legacy.

Quién manda cuando hay varios candidatos a mover el scroll:

1. **`diaPendiente`** — un día concreto al que hay que ir en cuanto el rango esté montado.
   `datesSet` lo consume ANTES de encuadrar y sale. Lo usan, en ambas vistas, el botón «Hoy»
   (si no, el encuadre por dirección se llevaría el scroll al extremo del rango recién
   navegado), la búsqueda de reservas en Reservas, y en Tarifas el arranque con
   `?fecha=YYYY-MM-DD` con el que se llega desde Reservas.
2. **`encuadrarTimeline()`** — si no hay nada pendiente.

El botón «Hoy» marca `diaPendiente` **antes** de llamar a `today()` y lo resuelve también
después: si el mes ya era el visible no hay `datesSet` que lo consuma, y ese es justo el caso
que el botón de fábrica no cubría.

El cálculo vive en **`util/src/utils/calendarTimeline.ts`**
(`scrollTimelineADia`, `encuadrarTimeline`, `hoyLocal`), compartido por las dos vistas: es la
misma rejilla y antes cada una repetía —y desincronizaba— la misma aritmética de días.

**Antes de tocar ese archivo, léete `assets/controllers/fullcalendar_controller.js`** (el
controlador Stimulus del calendario legacy de EasyAdmin). Aunque sea código viejo que no se
mantiene, tenía ya resuelto todo esto a base de cicatrices —`applyScroll()` con reintentos,
`getMainScroller()` por desbordamiento real, dirección con `lastViewRange`— y el helper de la
SPA es su port. Los mismos errores se vuelven a cometer si se escribe de cero.
