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
  - Reservas: `{ context: 'reserva'|'bloqueo', eventoId, reservaId }`
  - Tarifas raw: `{ context: 'tarifaRango', tarifaRangoId, active }`
  - Tarifas compactadas: `{ context: 'tarifaRangoCompactado', tarifaRangoId }` (id del rango
    de tarifa que originó ese segmento compactado — mismo criterio que usaba el legacy para el link)

Las claves de calendario correspondientes en `services_pms.yaml` son:
`pms_eventos_no_cancelados_spa`, `pms_eventos_todos_spa`, `tarifa_rangos_raw_spa`,
`tarifa_rangos_compactados_spa`. Mismos `filters`/`fields` que sus pares legacy, sin bloque `event.url`.

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
{ "id": "uuid-unidad", "title": "Habitación 101", "orden": 0 }
```

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
| `util/src/views/Reservas/ReservasView.vue` | Calendario + menú contextual + drag & drop |
| `util/src/components/reservas/ReservaEditDrawer.vue` | Drawer ver/crear/editar (acordeón de estancias) |
| `util/src/stores/reservas/reservasStore.ts` | Catálogos + CRUD de evento/reserva |
| `util/src/types/pmsReservaModel.ts` | Tipos, helpers de IRI/fecha y reglas OTA |

Calendarios: `pms_eventos_no_cancelados_spa`, `pms_eventos_todos_spa`.

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
- **Fechas.** `fechaInicio`/`fechaFin` son columnas `date`. La API las serializa a
  medianoche UTC, así que el día se extrae por string (`iso.slice(0,10)`), nunca con
  `new Date().getDate()` — en UTC-5 eso devuelve el día anterior. En el calendario, en
  cambio, el provider inyecta horas de UI (12:00 → 11:59) y FullCalendar mueve en
  múltiplos de 24 h, por lo que ahí sí vale leer el día local de `event.start/end`.
- **El borrado no necesita blindaje.** `Beds24RatesPushQueueListener` encola el push
  del intervalo afectado también en `scheduledEntityDeletions`, a diferencia de
  `PmsEventoCalendario`, que sí tiene `isSafeToDelete()`.
- **`generar-masivo` delega en `GeneradorTarifaMasivaService`**, el mismo servicio que
  usa EasyAdmin: el processor solo traduce el payload HTTP al DTO interno.
