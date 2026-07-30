# Cotizaciones — Arquitectura y guía de trabajo

> Documento de referencia para modificar el módulo de cotizaciones sin recargar
> todo el contexto. Cubre: apps Vue (util/pax), backend, modelo de dominio,
> lógica de servicios/tarifas, el pipeline editor→cliente, caché y "gotchas".
>
> Rutas y líneas citadas son orientativas (pueden correrse al editar); usa los
> nombres de símbolo para reubicarlas.

---

## 1. Panorama general

Tres piezas:

| Pieza | Qué es | Ubicación | Build → | Dev server |
|---|---|---|---|---|
| **util** | App Vue 3 + Pinia del **editor interno** (staff/agencia). Arma la cotización y **calcula** todo lo financiero. | `util/src` | `public/app_util` | `https://util.openperu.test:5174` |
| **pax** | App Vue 3 + Pinia de la **vista del cliente** (solo lectura, sin costos/márgenes). | `pax/src` | `public/app_pax` | `https://pax.openperu.test:5173` |
| **backend** | Symfony + API Platform (PHP). Entidades y serialización pública. | `src/Cotizacion` | — | — |

### Cómo se sirven (dev vs prod)
Las plantillas Twig deciden con `{% if is_dev %}`:
- **dev** → carga el JS desde el **Vite dev server** (util `:5174`, pax `:5173`). Los bundles de `public/app_*` **se ignoran**. Ver `templates/util/app.html.twig:41-47` y `templates/pax/app.html.twig:41-44`.
- **prod** → carga el bundle compilado de `public/app_*/{{ js_file }}`.

`public/app_util` y `public/app_pax` están **gitignored**. Con `npm run dev` **no hay que compilar**; `npm run build` es solo para prod.

Ambas apps son **PWA con service worker** (`vite-plugin-pwa`, `generateSW`). Si hay caché raro en dev, el sospechoso es el SW (DevTools → Application → Service Workers → Unregister), no el bundle.

---

## 2. Modelo de dominio (el "árbol" de la cotización)

Backend (`src/Cotizacion/Entity/`), todo con `cascade: persist/remove` de padre a hijo:

```
CotizacionFile (expediente)
  └─ Cotizacion (una versión/propuesta)         Cotizacion.php
       ├─ clasificacionFinanciera        (JSON, interno: costos+márgenes)
       ├─ clasificacionFinancieraCliente (JSON, público: sin costos)   ← lo lee pax
       └─ cotservicios[]  (CotizacionCotservicio)
            ├─ cotsegmentos[]  (CotizacionSegmento)   ← contenido del itinerario (texto, fechas, imágenes)
            └─ cotcomponentes[] (CotizacionCotcomponente)
                 ├─ cotsegmento  (ManyToOne CotizacionSegmento)  ← a qué segmento pertenece su contenido
                 ├─ snapshotItems[]  (JSON, NO relación)  ← ítems: "Guía", "Transporte", "Almuerzo"…
                 └─ cottarifas[]  (CotizacionCottarifa)   ← precios/costos del componente
```

También: `Cotizacion.catalogo` (ManyToOne `CotizacionCatalogo`, modo catálogo de tours), `CotizacionFiledocumento`, `CotizacionFilepasajero`.

### Concepto clave: "snapshots"
Cada nodo guarda **copias congeladas** (`*Snapshot`) tomadas de los **maestros** del catálogo al momento de agregarlo. Ej: `nombreSnapshot`, `tituloSnapshot`, `montoCosto`, `modalidadSnapshot`. Editar el maestro luego **no** cambia lo ya cotizado (salvo re-sincronización explícita).

- El **nombre interno** de un componente **no** está en su snapshot: viene del **maestro** (`componenteMaestroId` → `catalogos.allComponentes[].nombre`). Siempre existe.
- `nombreSnapshot` del componente = **título público** (opcional; para el cliente). Un componente sin `nombreSnapshot` es un "contenedor solo-ítems" (`isComponenteSoloItems`).

### Textos i18n
Los textos multi-idioma son `I18nContent[]` = `[{ language, content }]`.
- util: `store.getI18nText(arr, lang)` (con fallback).
- pax: `store.traducir(arr)` (fallback idioma actual → en → es → primero).

---

## 3. Tarifas (`CotizacionCottarifa` / `TarifaSnapshot`)

Campos que mandan (todos `*Snapshot`):

| Campo | Significado |
|---|---|
| `nombreInternoSnapshot` | Nombre **interno** de la tarifa (genérico, p.ej. "Orientour", "Americana Royal Class"). **Siempre** presente. **NO** se expone al cliente. |
| `tituloSnapshot` | Título **público** de la tarifa (opcional; para el cliente). |
| `modalidadSnapshot` | `privado` \| `compartido` \| null. |
| `categoriaSnapshot` | `economico` \| `estandar` \| `superior` \| `premium` \| null. |
| `rolSnapshot` | `estandar` \| `alternativa`. El rol **solo aplica en modo `incluido`**; en otros modos la `alternativa` se trata como `estandar`. |
| `grupoTarifa` | Agrupa tarifas de un mismo escenario ("Alternativa N" / "Opción N"). |
| `procedenciaSnapshot`, `edadMinimaSnapshot`, `edadMaximaSnapshot` | Perfil de pasajero al que aplica. |
| `esGrupal` | Si el costo es por grupo (se reparte entre `numPax`) o por pasajero. |
| `cantidad`, `montoCosto`, `moneda` | Cantidad, costo unitario y moneda (`USD`/`PEN`). |
| `comisionOverrideSnapshot` | Markup específico que pisa el global. |

**Modalidad ≠ grupal.** Modalidad es privado/compartido; "grupal" es el flag `esGrupal`.

Configs UI (iconos/labels): `MODALIDAD_CONFIG`, `CATEGORIA_CONFIG` y helper `modCatBadges(modalidad, categoria)` en `util/src/types/cotizacionEditorModel.ts`. En pax hay equivalentes locales (`MODALIDAD_UI`/`CATEGORIA_UI`/`modCatBadges`) en `PaxCotizacionGuiaView.vue`.

### Ítems del componente (`snapshotItems` / `SnapshotItem`)
Sub-elementos del componente (ej. "Guía Profesional", "Transporte"). Campos relevantes:
- `nombreSnapshot`, `modo` (`incluido`/`no_incluido`/`opcional`/`cortesia`), `incluido`.
- **Flags de visibilidad** (herencia tarifa→ítem hacia el cliente): `tituloTarifaVisible`, `modalidadTarifaVisible`, `categoriaTarifaVisible`. Controlan si el ítem muestra el título/modalidad/categoría **heredados de la tarifa** al cliente.

---

## 4. Cálculo financiero (el "votante") — solo en util

Fuente única: el computed **`resumenFinanciero`** en `util/src/stores/cotizacion/cotizacionEditorStore.ts`. Produce un `ClasificacionFinancieraInterna` (`cotizacionEditorModel.ts`). Resumen del algoritmo:

1. Recorre `cotservicios → cotcomponentes`. Ignora componentes `cancelado`/`reemplazado`; solo procesa modos `incluido`/`no_incluido`/`cortesia`.
2. Por cada `cottarifa`:
   - Resuelve grupal vs por-pax (`resolverGrupal`), cupos, costo total, markup, venta. Trabaja en **bimoneda** (`{ soles, dolares }`).
   - Buckets `incluido`/`noIncluido`/`cortesia`. Cortesía → venta 0; no_incluido → venta = costo.
   - `rolCrudo` normalizado: `alternativa` fuera de `incluido` → `estandar`.
   - Las tarifas con `rol === 'alternativa'` **no** van a las líneas principales: se acumulan en **`opcionesUpgrade`**.
3. **Alternativas / upgrades**: se agrupan por `grupoTarifa`. Cada alternativa se compara contra su **estándar espejo** (`estandarPorFirma`, firma = `procedencia|edadMin|edadMax`) o, si no hay espejo exacto, contra el **promedio ponderado** del bloque estándar (`basePP`). De ahí salen los deltas de venta por pax/total.
4. Salidas del objeto: `resumenGeneral`, `clasesPasajeros` (por perfil), **`opcionesUpgrade`**, `inclusiones`, `advertencias`, totales (`totalCostoNeto`, `totalVentaBruta`, `ganancia`, `comisionGlobal`).

Getter derivado para las vistas: **`gruposUpgrade`** (agrupa `opcionesUpgrade` por escenario "Alternativa/Opción N").

### Tipos de opción de upgrade
`cotizacionEditorModel.ts`:
- `OpcionUpgradeCliente` (base, **client-safe**): `componenteNombre`, `tarifaTitulo`, `modalidad`, `categoria`, `notaRol`, deltas, y la estándar reemplazada **pública**: `tieneEstandarEspejo`, `estandarTitulo`, `estandarModalidad`, `estandarCategoria`.
- `OpcionUpgradeInterna extends Cliente`: agrega datos internos (`tarifaNombreInterno`, `componenteNombreInterno`, `estandarNombreInterno`, costos/comisiones) **y** los insumos para expurgar al cliente: `componenteNombreCliente`, `mostrarTituloCliente`, `mostrarModalidadCliente`, `mostrarCategoriaCliente`.

---

## 5. Pipeline editor → cliente (CRÍTICO)

**El pax NO calcula nada.** El editor calcula `resumenFinanciero` (`fin`) y, **al guardar**, persiste dos JSON en la entidad `Cotizacion` (`cotizacionEditorStore.ts` ~1760):

```ts
payload.clasificacionFinanciera        = fin;                       // interno (costos, márgenes)
payload.clasificacionFinancieraCliente = expurgarParaCliente(fin);  // público (sin costos ni nombres internos)
```

- **`expurgarParaCliente(fin)`** (`cotizacionEditorModel.ts:656`) transforma `Interna → Cliente`: quita costos/márgenes, **nunca** copia `*NombreInterno`, redondea, y para las alternativas:
  - `componenteNombre` ← `componenteNombreCliente` (título público o primeros ítems; ver §6).
  - `tarifaTitulo` ← `tituloSnapshot` **gateado** por `mostrarTituloCliente` (antes mandaba `notaRol` por bug; corregido).
  - `modalidad`/`categoria` gateados por sus flags.
  - agrega la estándar reemplazada pública, también gateada.
- El pax lee ese JSON vía **`CotizacionPublicNormalizer`** (`src/Cotizacion/Serializer/`, grupo `pax_cotizacion:read`), que además: si `precioOculto` redacta SOLO los campos monetarios (`totalVenta`, `adelanto`, y dentro de `clasificacionFinancieraCliente`: `totalVentaBruta`, `montoAdelanto`, `resumenGeneral`, `clasesPasajeros`, y los deltas de `opcionesUpgrade`) — `inclusiones` y los datos descriptivos de `opcionesUpgrade` se mantienen; y maneja `proveedorOculto`.

> ⚠️ **El snapshot cliente solo se regenera al GUARDAR en el editor.** Cambios en la
> lógica de `resumenFinanciero`/`expurgarParaCliente` **no** afectan cotizaciones
> viejas hasta re-abrirlas y **re-guardarlas**. Si "no se ve el cambio" en pax,
> casi siempre es esto (o caché, ver §8).

---

## 6. Reglas de la vista del cliente (pax)

El cliente **no puede ver nombres internos**. Reglas ya implementadas para las tarjetas de alternativa:

1. **Título del componente**: `nombreSnapshot` (título público) si existe; si no, los **primeros 3 ítems incluidos** unidos por " · " (helper `tituloClienteDeComponente` en el store). Nunca el nombre interno del maestro ni el título del segmento.
2. **Herencia tarifa→ítems gateada** (criterio **permisivo**: se muestra si **algún** ítem del componente tiene el flag): `mostrar{Titulo,Modalidad,Categoria}Cliente = !items.length || items.some(flag)`.
3. **Estándar reemplazada**: solo datos **públicos** (`estandarTitulo` + mod/cat), gateados. Nunca `estandarNombreInterno`.

**Pendiente / no hecho**: aplicar la misma lógica de "3 ítems + flags" al **listado de ítems del itinerario** cliente si hiciera falta (lo implementado cubrió solo las tarjetas de alternativa). Ver detalles en la memoria `vista-cliente-alternativas-titulo`.

---

## 6.b Catálogo de Tours vs. Expediente de grupo (dos dashboards, dos lógicas)

El árbol `Cotizacion` se reúsa en **dos contextos** con reglas de precio opuestas.
No confundirlos: lo que en uno es el dato principal, en el otro sobra.

| | **Catálogo** (`CatalogoDashboard.vue`) | **Expediente / grupo** (`FileDetalle.vue`) |
|---|---|---|
| Qué es | Producto pre-armado por segmento (`Cotizacion.catalogo`, modo catálogo). | Venta real a un grupo concreto (`CotizacionFile` con manifiesto de pasajeros). |
| Pax | **No definido por rango** (`numPax` es solo "pax base"). | Definido: `filepasajeros[]` + `numPax` reales. |
| Dato de precio que importa | **`preciosDesde[]`**: precio *por rango* (extranjero/peruano, adulto/niño). Es **informativo** para la venta ("un niño cuesta X, un extranjero Y"). | **`totalVenta`** (+ `ganancia`): el total **sí es vendible** porque se sabe cuántos hay de cada rango. |
| Total de grupo (`totalVenta`) | **No aplica**: no hay grupo, así que no se muestra. | Es el dato central. |

- `CatalogoDashboard.vue` es solo **lectura/resumen**: lista los tours de cada catálogo y muestra sus rangos de precio. Los valores se editan en el motor del tour (`/catalogo/:cat/version/:id`).
- `preciosDesde` es un JSON de la entidad `Cotizacion`; cada rango = `{ titulo: I18nContent[], moneda, valor }`. Se rinde por rango (una línea cada uno) con el helper `rangosDesde(tour)`; el título va en el idioma activo vía `t18()`.

---

## 7. Mapa de vistas (dónde se pinta qué)

| Vista | Archivo | Fuente de datos |
|---|---|---|
| Editor principal (árbol, tarifas, ítems, upgrades) | `util/.../CotizacionEditorView.vue` | `store.dataActiva`, `store.gruposUpgrade` |
| Reporte financiero interno ("Incluye/No incluye", upgrades) | `util/.../components/cotizacion/ResumenClasificacion.vue` | `store.resumenFinanciero`, `store.gruposUpgrade` |
| Vista cliente ("Tu itinerario", "Opciones alternativas") | `pax/.../views/cotizacion/PaxCotizacionGuiaView.vue` | `store.inclusiones`, `store.gruposUpgrade` (de `clasificacionFinancieraCliente`) |
| Dashboard catálogos (listado, tours por segmento, precio **por rango**) | `util/.../Cotizaciones/CatalogoDashboard.vue` | `cotizacion_catalogos` + `tour.preciosDesde` (sin total de grupo; ver §6.b) |
| Detalle de expediente (grupo real: manifiesto, versiones, **total vendible**) | `util/.../Cotizaciones/FileDetalle.vue` | `cotizacion_files` + `cot.totalVenta`/`ganancia` (ver §6.b) |

En las 3 tarjetas internas de alternativa se muestra **`Componente · Tarifa`** (nombre interno del maestro + nombre interno de tarifa) y la línea **"Reemplaza"** con la estándar espejo. En pax se muestran solo datos públicos.

---

## 8. Caché y gotchas

| Store | Persiste en localStorage | TTL | Nota |
|---|---|---|---|
| **pax** `paxCotizacionStore` | Sí (`persist.paths`: `portada`, `detalle`, `lastUpdate*`, …) | **30 s** (`CACHE_TTL`) | Al recargar, si el dato tiene >30s se re-consulta al server; si no, se sirve de localStorage. Protege del abuso y refresca en ≤30s. |
| pax `maestroStore` (i18n/labels) | Sí | 30 s | |
| pax `paxHuespedGuiaStore` | Sí | 30 s | |
| pax `paxHuespedReservaStore` | Sí | 15 min | |
| **util** (todos) | **NO** | — | Ningún store del editor persiste (no hay `persist:` en `util/src`). |

**Gotcha #1 — snapshot viejo**: si pax muestra datos viejos, primero re-guarda en el editor (regenera `clasificacionFinancieraCliente`).

**Gotcha #2 — localStorage del pax**: el pax cachea `detalle` en localStorage (30s). Puede enmascarar un cambio recién guardado hasta que refresque o se limpie (DevTools → Application → Local Storage). Fue la causa de "no veo el cambio" en la sesión que originó estas mejoras.

**Gotcha #3 — service worker (PWA)**: puede servir assets/precache viejos. Unregister si hace falta.

**Gotcha #4 — dev vs build**: si abres el editor desde la app PHP en modo prod (`is_dev` false) usa el bundle de `public/app_util` (hay que `npm run build`). En dev usa el Vite server (fuente + HMR).

---

## 9. "Quiero cambiar X — ¿dónde toco?"

- **Cómo se calcula costo/venta/markup/alternativas** → `resumenFinanciero` en `util/.../cotizacionEditorStore.ts`.
- **Qué ve el cliente (y qué se oculta)** → `expurgarParaCliente` en `util/.../cotizacionEditorModel.ts` (+ tipos `OpcionUpgradeCliente`, `InclusionServicio`, etc.). Recordar re-guardar para regenerar snapshots.
- **Tipos compartidos del árbol/tarifas** → `util/src/types/cotizacionEditorModel.ts` (interno) y `pax/src/types/paxCotizacionModel.ts` (cliente). Mantenerlos coherentes.
- **Nombre interno del componente** → helper `nombreInternoDeComponente` (store). **Título público / por ítems** → `tituloClienteDeComponente` (store).
- **Badges modalidad/categoría** → `modCatBadges` (`cotizacionEditorModel.ts` en util; local en `PaxCotizacionGuiaView.vue` en pax).
- **Serialización pública / ocultar precio o proveedor** → `src/Cotizacion/Serializer/CotizacionPublicNormalizer.php` + grupos `pax_cotizacion:read` en las entidades.
- **TTL de caché del cliente** → `CACHE_TTL` en `pax/.../paxCotizacionStore.ts`.
- **Cómo se cargan los assets (dev/prod, puertos)** → `templates/util/app.html.twig`, `templates/pax/app.html.twig`.

### Checklist al tocar la lógica de alternativas/inclusiones
1. Editar `resumenFinanciero` (interno) y/o `expurgarParaCliente` (cliente).
2. Actualizar tipos en `cotizacionEditorModel.ts` **y** `paxCotizacionModel.ts` si cambian campos.
3. Ajustar vistas: `ResumenClasificacion.vue` / `CotizacionEditorView.vue` (interno) y `PaxCotizacionGuiaView.vue` (cliente).
4. `npx vue-tsc --noEmit` en `util/` y `pax/`.
5. **Re-guardar** una cotización de prueba en el editor y recargar pax (limpiar localStorage del pax si hace falta).
