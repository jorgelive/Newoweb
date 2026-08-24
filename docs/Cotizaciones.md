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

### El nombre de un SEGMENTO: un solo campo, y por qué no se contamina (2026-08-17)

A diferencia del servicio —que tiene tres nombres (código, operativo, comercial)—, un
`CotizacionSegmento` tiene **uno solo**: `nombreSnapshot`. No hay `nombrePublicoSnapshot`
separado. El motivo es semántico: un segmento es **narrativa para el pasajero** (storytelling),
no algo que se le pida a un proveedor. No tiene un «lado interno» distinto del público.

**Copia el TÍTULO del maestro, nunca el `nombreInterno`.** El maestro `TravelSegmento` tiene
`nombreInterno` (código, `VIS-VALLE_VIP…-CHINCHERO`) y `titulo` (comercial, «Visita a
Chinchero»). El snapshot toma `getTituloSafe(seg)` = el **título**. Por eso NO está
contaminado con el código: éste se queda en el catálogo, igual que el slug del itinerario.

El título se copia a `nombreSnapshot` en **cuatro** puntos del store, que son todas las formas
de meter un segmento en el servicio:

| Función | Cuándo |
|---|---|
| `aplicarPlantilla()` | al aplicar una plantilla, cada segmento que trae |
| `agregarSegmentoIndividual()` | añadir un segmento suelto (sin plantilla) |
| `procesarInsercionSegmento()` (replace) | sustituir un segmento por otro |
| `procesarInsercionSegmento()` (insert/append) | insertar o encolar un segmento |

**Quién lo lee:** el `nombreSnapshot` del segmento está en `pax_cotizacion:read` —el cliente
SÍ lo ve— y lo pinta la app pax (`PaxCotizacionGuiaView`, cuatro usos). En el editor, la lista
del itinerario lo usaba como nombre del servicio **sólo cuando el servicio no tenía plantilla**
(sin plantilla → se listan los segmentos; con plantilla → manda el nombre del servicio).

**Conclusión: el segmento NO necesita el tratamiento del servicio.** Su nombre ya es el título
comercial legible que ve el cliente, en un solo eje. La dualidad interno/operativo del servicio
—que obligó a separar `nombreSnapshot` del título— aquí no existe ni hace falta. El código del
segmento (`nombreInterno`) sólo molesta, si acaso, en EasyAdmin; no viaja a ninguna cotización.

#### Segmento homogeneizado + el servicio mono-segmento (2026-08-17)

Dos piezas que cierran el caso «servicio genérico con un solo segmento» (ej. «Transporte en
Cusco», «Vuelo»), donde los tres nombres del servicio fallan a la vez.

**1) El maestro `TravelSegmento` se homogeneizó** con el de itinerarios (migración
`Version20260817280000`): se añadió `slug`, `slug ← nombreInterno` (el código), y
`nombreInterno ← titulo['es']`. Así el segmento tiene el mismo modelo que el itinerario —`slug`
= código, `nombreInterno` = nombre real—. El UPDATE va por SQL porque ni `slug` ni
`nombreInterno` llevan `#[AutoTranslate]`; `titulo` sólo se lee (queda intacto). En EasyAdmin el
`slug` va primero.

**2) Por qué importa en el editor.** Para un servicio con **un solo segmento y sin plantilla**:
- el nombre OPERATIVO del servicio es genérico y no dice qué cosa es;
- el nombre PÚBLICO del servicio **se descarta** en la vista del cliente: `PaxCotizacionGuiaView`
  sólo pinta `nombrePublicoSnapshot` del servicio cuando `totalSegmentosServicio > 1`
  (`mostrarTituloServicio`); con un segmento, el cliente ve el título del **segmento**, no el del
  servicio;
- el que carga el significado es el segmento.

**Fix visual, sin persistir** (`CotizacionEditorView.vue` + store): cuando
`esMonoSegmentoSinPlantilla(servicio)` —`cotsegmentos.length === 1 && !itinerarioMaestroId`—,
las vistas de operador muestran el segmento en lugar del nombre genérico:
- En el **editor**, los inputs «Nombre operativo/Público» se reemplazan por displays read-only:
  el `nombreInterno` del segmento maestro (operativo) y su título en el idioma seleccionado
  (público), con una nota de por qué. No se persiste nada.
- En la **card de la lista**, el nombre grande pasa a ser el título del segmento y debajo su
  `nombreInterno`.
- El `nombreInterno` del segmento **no está en el snapshot**: se lee del maestro vivo en
  `catalogos.poolSegmentos` (helper `segmentoUnicoMaestro`), con fallback al `nombreSnapshot['es']`
  si el pool no cargó.
- **Reactivo**: aplicar una plantilla (setea `itinerarioMaestroId`) o añadir un 2º segmento (sube
  el `length`) desactiva la condición y devuelven los inputs editables, solo.

**3) Y en La Biblia igual** (2026-08-18). El mismo caso llega a la orden de servicio: la fila
mostraba `contextoServicio` (= nombre del servicio, «Alojamiento»), genérico. Ahora, para
mono-segmento sin plantilla, la fila usa el **nombre interno del segmento** («Alojamiento en
Cusco»). Se resolvió **solo display, en vivo, sin snapshot ni reconciliación** —para no reabrir
el diff—:
- Backend: `OperacionServicio::getSegmentoUnicoMaestroId()` (getter serializado, no persistido)
  expone el `segmentoMaestroId` del único segmento sólo si el servicio es mono-segmento sin
  plantilla; `null` en el resto. Navega `cotizacionServicio → getCotsegmentos()/getItinerarioMaestroId()`.
- Front (`operacionStore`): `resolverNombresDeSegmento()` hace batch por id contra
  `/platform/travel/segmentos` y mapea `id → nombreInterno` (mismo patrón que los contactos de
  proveedor); `nombreSegmentoDeServicio()` lo resuelve por fila. `OperacionView` usa ese nombre
  como título cuando existe, con fallback a `contextoServicio`.
- **No toca `ETIQUETAS`/`valoresActuales()`**: no es un campo de reconciliación, así que no
  reintroduce el problema de convergencia (§Operacion.md 3.5).

Y el **nombre del componente** (Guía, Transporte…), que identifica cada fila dentro de un
servicio con varios, tampoco vive en el snapshot: viene del maestro. Se resuelve **en el mismo
batch que ya trae los lugares** (`resolverLugaresDeServicios` en `operacionStore`, cero peticiones
nuevas): del `/travel/componentes` que se pide para los badges se saca también el `nombre`
(`nombreComponenteDeServicio`).

**Jerarquía de la fila (2026-08-18).** El **componente es el titular** de la fila —lo único que la
distingue dentro de un servicio de varios— con su icono de tipo a la izquierda, y **pegada debajo
la tarifa**: lo que la fila identifica y lo que se negocia por ello van juntos. El **servicio baja
a contexto** (línea tenue, más abajo, con el expediente y el prestador): ubica la fila, no la
identifica. Antes el titular era el nombre genérico del servicio, repetido idéntico en cada fila,
y metido entre el componente y la tarifa los partía.

**Modal de expediente y persistencia (2026-08-18).** El modal que abre una fila muestra el
**localizador + versión** (`HJDLDB-v1`) **copiable**, y un enlace a la **vista del cliente** que
abre en otra pestaña (`${pax}/file/{localizador}/v/{version}`, otro dominio). El dato viaja en la
propia fila: `OperacionServicio::getCotizacionVersion()` (nuevo, junto a `getCotizacionId()`) y
`CotizacionFile::getLocalizadorPublico()` al que se le añadió el grupo `operacion:item:read`. Y
los **filtros de La Biblia se persisten en `localStorage`** (`biblia:filtros`: fechas, tipos,
lugares, estados, filtro OS, expediente y versión) y se restauran al montar —antes de la carga
inicial—: entrar a la cotización y volver ya no deja el cuadro en blanco. El modal tiene dos
accesos —**Expediente** (`file_detalle`) y **Cotización** (`cotizaciones_editor`)— y permite
**subir documentos** al expediente sin salir (`subirDocumentoExpediente` → el mismo endpoint
multipart `/cotizacion_filedocumentos` de FileDetalle): esos documentos —vouchers, confirmaciones
de reserva— se generan justo al operar.

**El costo de la fila: layout y desglose (2026-08-18).** El editor de costo (`EditorCostoNegociado`)
muestra el negociado y, en línea, `antes ‹cotizado›` tachado **sólo si el negociado difiere** —sin
cambio, una sola cifra—. Junto al valor, un `InfoTooltip` (patrón compartido: hover en escritorio,
tap en táctil, teletransportado al body; ver `docs/UI_Componentes_Compartidos.md`) abre el
**desglose del cotizado**: una línea por tarifa con `unitario × cantidad × noches/días = subtotal`,
y el total. Sale de `OperacionServicio::getDesgloseCotizado()` (getter serializado, en vivo desde
las cottarifas de la cotización): es la MISMA fórmula que congela el snapshot
(`montoCosto × cantidad_tarifa × unidades_componente`, sin las ALTERNATIVA), pero abierta para que
el operador vea de dónde sale el número en vez de confiar en él.

### Los nombres de un servicio, resueltos (2026-08-17)

Tras el análisis de la asimetría (abajo), el modelo quedó en tres ejes limpios:

| Eje | Campo | Idioma | Editable | Cliente lo ve |
|---|---|---|---|---|
| **Código / slug** | `TravelItinerario.slug` (maestro) | uno | EasyAdmin | no |
| **Operativo** (interno + proveedor) | `nombreSnapshot` (cotservicio) | **uno (es)** | ✅ sí (cotizador) | no |
| **Comercial** | `nombrePublicoSnapshot` (cotservicio) | i18n (7) | ✅ sí | ✅ sí |

Cambios que lo implementaron:

- **`TravelItinerario.slug`** (migración): el código (`HD-COMBINADA-POOL-AM`) sale de
  `nombreInterno` a un campo propio, copiado. En EasyAdmin el slug va primero y `nombreInterno`
  —ahora un nombre operativo de verdad— a continuación. `nombreInterno` se queda con el código
  hasta que se edite plantilla por plantilla.
- **`nombreSnapshot` = nombre operativo, no título.** Nace del `nombreInterno` del servicio; al
  aplicar plantilla se transforma en el `nombreInterno` de la plantilla (más específica). En un
  solo idioma —al proveedor se le habla en uno— vía `nombreOperativoComoI18n`. Ya no se copia
  del título ni el título se copia de él.
- **Editable en el cotizador**: campo «Nombre operativo» propio, separado del «Nombre Público».
  Antes salía bloqueado con candado.

⚠️ **`nombreSnapshot` sigue con `#[AutoTranslate]` en la entidad**, pero se llena con un solo
idioma a propósito. Si se quiere prohibir la traducción de raíz, hay que quitarle el atributo
—decisión pendiente, no bloquea—.

La Biblia (`contextoServicio`) lee `nombreSnapshot`, así que el operador ve ahora el nombre
operativo, no el título comercial. Las cotizaciones existentes conservan su `nombreSnapshot`
viejo (título) hasta que se reediten: sólo cambia el comportamiento de las nuevas.

### ⚠️ Los tres nombres de un SERVICIO, y la asimetría interno/público (2026-08-17)

Un `CotizacionCotservicio` tiene **tres** campos de nombre, y no dan lo mismo:

| Campo | Qué es | Grupo | Editable |
|---|---|---|---|
| `nombreSnapshot` | Nombre del servicio del catálogo. **INTERNO** (Biblia, voucher) | no pax | ❌ bloqueado (candado) |
| `itinerarioNombreSnapshot` | Nombre de la **plantilla** (`TravelItinerario`) aplicada. Interno | no pax | ❌ sólo etiqueta |
| `nombrePublicoSnapshot` | **TÍTULO público** (lo ve el cliente) | pax | ✅ sí |

**La plantilla es más específica que el servicio, y por eso debe ganar.** Un servicio genérico
«Alojamiento» tomado de la plantilla «Alojamiento en Lima» debería llamarse por la plantilla,
no por el genérico. Cómo está hoy:

| | Título PÚBLICO (`nombrePublicoSnapshot`) | Nombre INTERNO (`nombreSnapshot`) |
|---|---|---|
| Override de la plantilla al aplicarla | ✅ **sí** (`aplicarPlantilla`, store: se copia el título del itinerario) | ❌ **no** — queda el genérico del servicio |
| Fallback al servicio si no hay plantilla | ✅ sí (`nombrePublicoSnapshot \|\| nombreSnapshot`) | (es el propio servicio) |
| Editable en la cotización | ✅ sí (input) | ❌ **no** — sale con candado (`servicioMaestroId` + componentes ⇒ bloqueado) |

**El hueco, confirmado (lo reportó Jorge, 2026-08-17):**

1. **El nombre interno NO recibe el override de la plantilla.** El público sí (`nombrePublicoSnapshot`
   se sobrescribe con el título del itinerario al aplicarlo); el interno se queda con el nombre
   genérico del servicio. Y ese interno es el que usa **La Biblia** (`contextoServicio` =
   `getNombreSnapshot()`) y el que alimenta el voucher — así que el operador ve «Alojamiento» y
   no «Alojamiento en Lima».
2. **El nombre interno no es editable** a nivel servicio: cuando el servicio viene del maestro,
   el editor lo pinta bloqueado. Sólo el componente tiene su nombre editable, no el servicio.

**Corrección propuesta (no aplicada — cambia el comportamiento de nombres):**

- El nombre interno efectivo debería resolverse con la misma prioridad que el público:
  `plantilla (itinerarioNombreSnapshot) → servicio (nombreSnapshot)`. Un helper único que las dos
  caras usen, para que no vuelvan a divergir.
- Hacer editable el nombre interno del servicio en la cotización, con la plantilla como valor
  sembrado y el servicio como fallback — igual que ya funciona el título público.

Dónde vive cada pieza: se aplica la plantilla en `cotizacionEditorStore.aplicarPlantilla()`
(copia título → `itinerarioNombreSnapshot` y `nombrePublicoSnapshot`, NO `nombreSnapshot`); el
nombre interno lo lee `BibliaSnapshotService::calcularValores()` (`contextoServicio`).

### Textos i18n
Los textos multi-idioma son `I18nContent[]` = `[{ language, content }]`.
- util: `store.getI18nText(arr, lang)` (con fallback).
- pax: `store.traducir(arr)` (fallback idioma actual → en → es → primero).

> ⚠️ **`util/src/types/api.d.ts` miente en los i18n.** El backend serializa objetos
> `{language, content}` —son `I18nContent[]`— y el generador nunca acierta con esa
> forma. Lo mismo con `Proveedor.proveedorImagenes`, declarado como IRIs cuando el
> grupo de lectura manda los objetos completos.
>
> **El motivo cambió el 2026-08-16; la necesidad no.** Antes emitía `(string | null)[]`,
> que era falso a secas. Hoy emite `{ [key: string]: string | null }[]`, que **se parece
> a la verdad y por eso engaña más**: una firma de índice abierta no garantiza que
> existan `language` ni `content`, ni que sean `string` y no `null`. Comprobado quitando
> un `Omit`: el compilador rechaza pasar el campo a cualquier helper tipado con
> `I18nContent[]`. Si ves un `Omit` de i18n y te tienta retirarlo porque «ahora el schema
> ya está bien», no lo está.
>
> **No lo arregles con `as any`** (era lo que había): corrige el tipo con `Omit<…>`
> donde se consume — ya hecho en `Proveedor`, `Segmento` y `Cotizacion`
> (`cotizacionEditorModel.ts`). Al regenerar `api.d.ts` estas correcciones siguen
> haciendo falta: el generador no las va a inferir.
>
> ⚠️ **El campo corregido TIENE que ir en el `Omit`**, no basta con redeclararlo
> debajo. `Schema & { preciosDesde: PrecioDesdeRango[] }` cuando el schema ya
> declara `preciosDesde?: string[]` da la intersección `string[] & PrecioDesdeRango[]`:
> **no falla al compilar** y deja un tipo inservible, así que el error aparece
> lejos, al usar el campo. Le pasaba a `titulo`, `preciosDesde`,
> `clasificacionFinanciera` y `clasificacionFinancieraCliente` de `Cotizacion`.

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


## 3.b El estado del componente dice **una** cosa

`CotizacionCotcomponente::$estado` (`ComponenteEstadoEnum`) responde sólo a *«¿este
componente sigue en pie dentro de la cotización?»*:

| Valor | Consecuencia |
|---|---|
| `activo` | cuenta en el cálculo y se publica |
| `cancelado` | no suma costo, no llega al cliente, y en La Biblia sale con badge y atenuado |

**No dice si el proveedor confirmó.** Eso es `App\Operacion\Enum\EstadoReservaProveedorEnum`
sobre la fila de La Biblia (`sin-solicitar → solicitado → confirmado → reconfirmado`),
que es donde se gestiona y donde se edita.

### Por qué tenía cuatro valores y ahora tiene dos

Venía de cuando cotización y operación eran lo mismo: `pendiente`, `confirmado`,
`reconfirmado`, `cancelado`. Al rastrear sus lecturas, **sólo `cancelado` hacía algo** —
los dos `if (estado === 'cancelado' || modo === 'reemplazado') return;` de
`resumenFinanciero` y `construirInclusiones`, y el badge de la vista de tráfico. Los
otros tres alimentaban un `<select>` y nada más.

Y no era ruido inofensivo: **aparentaban hacer el trabajo de otro módulo**. El vendedor
marcaba «Confirmado» en el editor y podía creer razonablemente que quedaba registrada la
confirmación del proveedor. Había 2 componentes marcados así en la base, sin ningún
efecto y sin que nadie se enterara.

Un flag que no hace nada es ruido; uno que imita a otro que sí hace algo es una trampa.

### 🔥 El default de columna tiene que ser un `case` válido

`cotizacion_cotcomponente.estado` y `cotizacion_cotizacion.estado` tenían
`options: ['default' => 'Pendiente']` — **con mayúscula**, un valor que ninguno de los
dos enums acepta. Cualquier fila insertada sin ese campo (un `INSERT` crudo, una
importación, un fixture) queda **inhidratable**: `ValueError` al leerla, no al
escribirla. No se notaba porque Doctrine siempre escribe la propiedad desde el objeto,
así que el default nunca llegaba a usarse — la mina estaba puesta y sin pisar.

Al declarar una columna con `enumType`, comprueba que `options.default` coincide
exactamente con el `value` de un case. `doctrine:schema:update --dump-sql` **no** lo
detecta: para él es una cadena como cualquier otra.

### Al cambiar el enum, los datos van primero

`Version20260811230000` migra las filas **antes** de tocar el default, y usa
`WHERE estado <> 'cancelado'` en vez de una lista de valores conocidos: así arrastra
también cualquier resto inesperado en lugar de dejarlo fuera. Si sobreviviera un solo
`pendiente`, el fallo no aparecería al desplegar sino al abrir una pantalla.

Espejos que hay que tocar a la vez (son cuatro):
`ComponenteEstadoEnum` (PHP) · `ComponenteEstadoValue` y `ESTADO_COMPONENTE_CONFIG` en
`cotizacionEditorModel.ts` · el `ESTADO_COMPONENTE_CONFIG` de `operacionModel.ts` (el
snapshot de La Biblia usa el mismo vocabulario) · y los literales `estado: 'activo'` con
que el store crea componentes nuevos.

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


### 4.b `publicable`: qué impide publicar una cotización

`guardarCotizacion()` **aborta el guardado** si el estado es `enviado`, `confirmado` u
`operado` y el resumen financiero no es publicable:

```ts
publicable = !tieneConflictos && advertencias.length === 0
```

Las dos mitades son distintas y las dos son legítimas:

| Mitad | Qué detecta | Dónde nace |
|---|---|---|
| `tieneConflictos` | tarifas que no encajan en **ningún perfil real** de pasajero. Se crea una clase `⚠️ CONFLICTO` con `isReal: false` y se anota la ruta del origen | `asignar()`, cuando `bestIdx === -1` |
| `advertencias` | situaciones en las que **el cliente vería una propuesta distinta de la que se quiso vender** | `resumenFinanciero` y `construirInclusiones()` |

#### El listón para añadir una advertencia

Siempre el mismo: *el cliente vería una propuesta distinta de la que se quiso vender*.
No sirve para avisos de estilo ni recordatorios — **cada advertencia es un bloqueo de
guardado**, así que una regla demasiado laxa deja la cotización inguardable.

Antes de añadir una, **mídela contra los datos reales**. Si dispara sobre cotizaciones
que hoy se guardan, o la regla está mal o hay un problema de datos que arreglar primero.

#### Las que existen

| Advertencia | Por qué no se puede publicar |
|---|---|
| Grupo alternativo cuyo nº de pax no cuadra con el global ni con la base | un upgrade que cubre 3 de 5 pasajeros no se puede vender: si lo aceptan, ¿qué pasa con los otros 2? |
| Componente `incluido` **sin tarifa estándar** | el renderizado lo degrada a «Opción N» en Opcional: el cliente recibe como elegible algo vendido como incluido |
| Componente `incluido` **sin ninguna tarifa publicable** | no genera ni una línea: desaparece de la propuesta |
| Componente **sin título público y sin ítems** | invisible en la propuesta, y si lleva tarifa es costo sin contrapartida visible |
| Ítem con **modo desconocido** | `destino()` manda a «Incluye» todo lo que no reconoce: una errata regala lo que se quería cobrar aparte |

Las cuatro últimas viven en `construirInclusiones()`, que recorre servicios→componentes
**sin pasar por el votante** y por eso ve lo que el cálculo financiero no ve.

⚠️ Ese parámetro `advertencias` estuvo **declarado y sin usar**: la función lo recibía y
no empujaba nada. El mecanismo estaba montado para varias reglas y sólo tenía una, la del
grupo alternativo. Si añades una comprobación nueva ahí, usa el helper `avisar()` para que
el rótulo `"Servicio ➔ Componente"` salga igual que en el resto.

**Dato de contexto (agosto 2026):** en la base no hay ni una tarifa con rol `alternativa`
—127 de 127 son `estandar`—, así que la advertencia del grupo alternativo **no puede
dispararse hoy**. En la práctica `publicable` depende sólo de `tieneConflictos` y de las
cuatro nuevas.


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

### Idioma del cliente: quién manda (`idiomaCliente`)

Es el campo que decide **en qué idioma ve el cliente todo**. Vive por duplicado, y
esa es la parte que confunde:

| Campo | Gobierna |
|---|---|
| `CotizacionFile::$idiomaCliente` | La **portada del expediente** (`PaxFilePortadaView`) |
| `Cotizacion::$idiomaCliente` (uno por versión) | La **propuesta / guía** (`PaxCotizacionGuiaView`) |

No confundirlo con la relación `CotizacionFile::$idioma` (`MaestroIdioma`), que es
el idioma del prospecto para uso **interno** y no llega a la vista pública.

Flujo completo:

```
Alta del expediente (DashboardView)
    │  el desplegable "Idioma Base" fija AMBOS: la relación `idioma` y `idiomaCliente`
    ▼
CotizacionFile.idiomaCliente ──┐
    │                          │ (herencia al CREAR, crearCotizacionVacia)
    │                          ▼
    │                    Cotizacion.idiomaCliente  (por versión, editable en el editor)
    │                          │
    ▼                          ▼
PaxFilePortadaView      PaxCotizacionGuiaView
        └──── paxCotizacionStore: `maestroStore.setIdioma(...)` ────┘
                (salvo que el cliente haya elegido idioma a mano:
                 el flag `paxIdiomaManual` de localStorage gana)
```

**Regla asimétrica que hay que conocer**: cambiar el idioma del expediente
**propaga a todas sus cotizaciones**, incluidas las ya enviadas o confirmadas — lo
hace `CotizacionFileIdiomaClienteListener` (preUpdate). Es decir, un cliente con un
enlace ya abierto verá la propuesta cambiar de idioma. Es una decisión explícita
del negocio: manda el expediente. Lo contrario NO ocurre: cambiar el idioma de una
versión concreta en el editor no toca al expediente ni a sus hermanas.

El listener vive en el backend, y no en la SPA, para que la regla se cumpla venga
el cambio de donde venga (app util, EasyAdmin o una importación).

### Los dos interruptores de la guía (y por qué no comparten vocabulario)

`PaxCotizacionGuiaView.vue` tiene dos controles independientes que el usuario confundía
porque ambos decían "detalle":

| Control | Estado | Qué conmuta | Vocabulario |
|---|---|---|---|
| **Tarjeta de precio** (cuelga del hero con margen negativo) | `finanzasAbiertas` | Colapsada: agregado (precio por pasajero + total del viaje). Expandida: desglose por **perfil** de pasajero, barra de total y alternativas. | "precios", "perfil". **Nunca** "detalle"/"resumen". |
| **Modo de lectura** (píldoras bajo la tarjeta) | `modoResumen` | Cuánto texto del **itinerario** se pinta: `Detalle` (descripciones y fotos) vs `Resumen` (títulos, subtítulos y horas). | "Detalle"/"Resumen", exclusivo de esta píldora. |

- La tarjeta es **una sola pieza con dos disparadores** (fila superior y pie): abierta, la
  fila de arriba se sale de pantalla, así que el pie repite el toggle. No son `<button>`
  anidados — por eso el **selector de moneda vive en el hero** y no dentro de la tarjeta.
- Claves i18n del pie: `cot_ver_precios_perfil` / `cot_ocultar_precios`; el encabezado
  expandido reutiliza `cot_precio_por_pasajero`. Si el precio está oculto pero hay
  alternativas, la tarjeta sigue existiendo y el pie ofrece "ver opciones".

> **Gotcha i18n — el fallback esconde el hueco.** `maestroStore.t('clave')` devuelve vacío si
> no hay fila en `pax_ui_i18n`, y el template cae al literal `|| 'Texto en español'`. En
> castellano no se nota nada; el cliente extranjero ve esa cadena suelta en español entre el
> resto traducido. **Toda clave nueva necesita su fila sembrada por migración** (patrón:
> `Version20260803140000`, `INSERT … AS nuevo ON DUPLICATE KEY UPDATE` que respeta lo ya
> editado a mano). Para detectar las que falten:
> ```bash
> grep -rhoE "\.t\(\s*'[a-z0-9_]+'" pax/src | grep -oE "'[a-z0-9_]+'" | tr -d "'" | sort -u
> php bin/console dbal:run-sql "SELECT id FROM pax_ui_i18n"   # y se cruzan ambas listas
> ```
> Antes de inventar una clave, mira si ya existe una equivalente: `cot_precio_por_pasajero`
> ya estaba y se reutilizó en vez de crear `cot_precios_por_perfil`.
- `hayPanelPrecio` decide si hay tarjeta; el hero lo consulta para reservar el aire del
  solape. Si se toca uno, se toca el otro.

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

### Tarjeta del tour: portada y duración (`TourTarjetaResolver`)

Ni la portada ni los días son columnas de `Cotizacion`:

- **Portada** = `imagenPortada` (override editorial, se fija a mano en el editor) **o**, si es
  null, la primera imagen con `isPortada` recorriendo los segmentos en orden de itinerario; y
  si ninguna la tiene, la primera imagen que aparezca.
- **Días** = span de las fechas nominales del itinerario (`max - min + 1`).

Ambas reglas viven **solo** en `src/Cotizacion/Service/TourTarjetaResolver.php`, que las
resuelve **en lote con queries escalares** — recorrer `getCotservicios()` por tour hidrataría
el árbol completo de cada uno. Dos consumidores:

| Consumidor | Provider | Cómo llega al front |
|---|---|---|
| Catálogo público (pax) | `CotizacionCatalogoPublicProvider` | `toursParaCliente[].imagenPortada` / `.numDias` |
| Panel interno (`CatalogoDashboard.vue`) | `CotizacionCatalogoAdminProvider` | props **virtuales** `Cotizacion::$imagenTarjeta` / `$numDias`, grupo `catalogo:item:read` |

El panel pinta las tarjetas con el mismo lenguaje visual que el cliente (foto, chip de días,
título flotante, "Desde") para revisar el producto sin abrir el enlace público; encima añade
lo interno: orden, `T{version}`, estado y pax base. Diferencia deliberada: el provider público
filtra por estados públicos, el interno **no** — en el panel un borrador también luce su foto.

**Anatomía de la tarjeta** (`PaxCatalogoPortadaView.vue`, espejada en `CatalogoDashboard.vue`):
foto 16:9 (`aspect-video`, igual que el filtro `travel_cliente`) → chip de días arriba-izquierda → título **sin caja**, sobre el degradado →
resumen recortado a 2 líneas → chips de rangos si hay más de uno → pie con la acción a la
izquierda (`cat_ver_detalles`) y el "Desde" con la flecha a la derecha. **La tarjeta entera es
el enlace**: no hay botón CTA dentro. El botón naranja a todo ancho se retiró — repetido en
cada experiencia competía con la foto y alargaba el scroll.

Sin foto **no** se pinta un marcador gris: se rota un degradado de color por posición
(`TINTES`), para que un catálogo aún sin portadas se lea como una lista de tarjetas distintas
y no como imágenes rotas.

> **Gotcha — UUID binario en DQL (costó una portada que nunca se vio).** `IDENTITY(s.cotizacion)`
> devuelve el UUID **binario crudo de 16 bytes**, mientras que `c.id` hidrata como objeto `Uuid`
> (`(string)` → forma con guiones). Casar ambos con `(string)` a secas **no coincide nunca**: el
> mapa sale vacío, no hay error, y la portada derivada simplemente "no aparece". Del mismo modo,
> un `WHERE ... IN (:ids)` con objetos `Uuid` sin tipo de parámetro devuelve **cero filas en
> silencio**. Por eso el servicio normaliza todo id que entra o sale por `TourTarjetaResolver::clave()`
> y liga el `IN` con `ArrayParameterType::BINARY`. Cualquier query nueva sobre ids de cotización
> debe hacer lo mismo.

### Dos precios que no son el mismo (no confundirlos)

En modo catálogo conviven dos cosas que suenan igual y viven en cajas distintas del editor:

| | `preciosDesde[]` — "Precios de Exhibición (Desde)" | `totalesOcultos` — "Ocultar Total de Grupo" |
|---|---|---|
| Qué es | **Dato**: rangos comerciales escritos a mano (perfil + moneda + valor). | **Flag de render**: no crea ningún precio. |
| Dónde se pinta | Sólo el escaparate: `PaxCatalogoPortadaView.vue` y `CatalogoDashboard.vue`. **No** entra en la guía. | Sólo la guía: `PaxCotizacionGuiaView.vue`. |
| Efecto | Muestra "desde $X" por perfil. | Suprime el `2X` del perfil, el `× N pax · total` y la barra "Precio total del viaje". El precio **por pasajero** sigue visible. |

**El flag no está hardcodeado por `esCatalogo`.** En pax, `esCatalogo` (`route.meta`) sólo gobierna
identidad y fechas — "Día N" en vez de fecha absoluta (`formatearFecha()`, `fechaChip()`), chips de
tarifa colapsados a valor unánime, "Volver al catálogo" en vez de `nombreGrupo`. El precio va por otra
vía: `ocultarTotales` depende **únicamente** de `cotizacion.totalesOcultos`. Si el flag está apagado,
un tour de catálogo muestra el total de un grupo que no existe.

Por eso `cotizacionEditorStore::crearCotizacionVacia()` lo crea **activo cuando `modoCatalogo`**, y
`Version20260803120000` hace backfill (`totales_ocultos = 1 WHERE catalogo_id IS NOT NULL`) de los
tours anteriores al cambio. Se apaga a mano sólo en el caso legítimo: **salida de grupo fijo**, donde
el "Num Pax (Base)" sí es el tamaño real que se vende y el total es una cifra vendible.

> Espejo PHP ↔ TS: la semántica vive en el docblock de `Cotizacion::$totalesOcultos` y en el comentario
> de `ocultarTotales` en `PaxCotizacionGuiaView.vue`. Si cambia la regla, se tocan los dos.

---

## 6.c Dos papeles sobre una sola entidad: prestador y comprador

**`Proveedor` es la entidad maestra** — el contacto-empresa, el otro lado de `Cliente`. Lo
que el componente guarda no es esa entidad otra vez: es el **papel** que juega. Y a nivel de
cotización sólo hacen falta dos.

| | Responde | ¿Lo ve el cliente? | ¿Lo usa Operación? |
|---|---|---|---|
| **prestador** | ¿quién presta este servicio? | sí, si se decide | sí — recojo, teléfono, dirección |
| **comprador** | ¿a quién le encargo ejecutar la compra? | **nunca** | sí — a quién se dirige la OS |

### Hubo un tercero y sobraba

Existió `proveedor*` en el componente, respondiendo «a quién le compro». Se retiró en
`Version20260816260000` tras medir sus lectores: **su único consumidor era la serialización
pública** —cero en Operación, cero en finanzas— y lo que enseñaba al cliente era, en la
práctica, quién presta el servicio. Eran **dos caras públicas para un mismo hecho**, cada
una con su propia bandera.

Su bloque rico (título i18n, url, imágenes, servicio contratado, bandera) **es** hoy el
prestador: se movió tal cual. El prestador conservó lo suyo —teléfono y dirección
congelados— y ganó un correo.

### Campos planos: enlace + nombre histórico, y nada más

El componente guarda **cinco cosas**:

```
prestadorMaestroId               enlace al catálogo
prestadorNombreSnapshot          nombre histórico
prestadorServicioMaestroId       enlace al servicio contratado
prestadorServicioNombreSnapshot  nombre histórico del servicio
prestadorVisible                 bandera
```

Título, url, imágenes y contacto **no se guardan**: los inyecta el backend leyendo el
catálogo al servir, y la orden los resuelve al enviarse.

Llegaron a estar copiados en nueve columnas y el problema no era el tamaño —se midió:
5,8 KB sobre 815, el 0,7%— sino que **filtrar era ambiguo**: la misma empresa estaba en
trece columnas del componente y, si alguien la renombraba en el catálogo, ninguna coincidía
con las otras. Con un id hay una sola cosa contra la que filtrar.

⚠️ **Se perdieron los overrides.** Ya no se puede enseñar en una propuesta un título
distinto al del catálogo: se edita el maestro y cambia en todas. Es la contrapartida
deliberada de que todos los prestadores se den de alta.

### El nombre no es una copia: es la degradación

El soft-link no tiene integridad referencial a propósito. Si borran la empresa del
catálogo, **el nombre guardado es el último dato que sobrevive** — y con él la propuesta
antigua sigue contando quién prestó el servicio, sin tarjeta a medias. Lo mismo el nombre
del servicio, que hasta `Version20260816280000` ni siquiera se guardaba.

Por eso se guarda aunque se pueda resolver.

### El gate de lo que ve el cliente

```
se muestra  ⟺  NO (Cotizacion::proveedorOculto)  Y  componente.prestadorVisible
```

El global es el interruptor white-label de toda la propuesta y gana siempre; la bandera del
componente sólo afina hacia abajo. ⚠️ Ojo a la asimetría: el global está en negativo y el
del componente en positivo.

🔥 **Antes esa decisión se re-derivaba de `modo`**, y reclasificar un componente cambiaba la
propuesta en silencio: pasar a `incluido` borraba al prestador de una propuesta enviada, y
pasar a `no_incluido` publicaba uno que nadie había revisado. Hoy la regla vieja es sólo el
**default al asignar**, y lo guardado manda.

### Lo que sirve está VIVO

Con maestro, los campos públicos se sobreescriben al serializar con lo que dice hoy
`travel_proveedor`: renombrar una empresa se ve en todas las propuestas **sin re-guardar
ninguna**. El snapshot sigue escribiéndose y es el respaldo si el maestro desaparece.

⚠️ **Siempre en lote.** `ProveedorVivoResolver::precargar()` se llama UNA vez por cotización
desde `CotizacionPublicNormalizer`, antes de bajar a los componentes.

🔥 **Los UUID de un soft-link no casan solos.** El campo es `VARCHAR(36)` con guiones y la
clave del maestro es `BINARY(16)`: compararlos no coincide **nunca** y no da error, devuelve
cero filas y el prestador «no aparece». Pasó durante la implementación —`HEX()` en el
comando de backfill dio 254 líneas sin pareja y 0 enlazadas—. Todo id se normaliza con
`ProveedorVivoResolver::clave()` y los binarios con `Uuid::fromBinary()`, nunca con `HEX()`.

Sondas: `var/probar-prestador-visible.php` y `var/probar-comprador.php`.

### Los filtros por prestador son BLANDOS

Si el componente (o su día) fija prestador, **dos** selectores del editor se estrechan. Ambos con
`SearchableSelect` y las mismas reglas:

| Selector | Qué muestra | Dónde |
|---|---|---|
| **Tarifa maestra** | sólo tarifas cuyo `proveedor` es el prestador | `opcionesTarifasFiltradas` |
| **Proveedor de la tarifa** | sólo ese proveedor | `opcionesProveedoresTarifa` |

Las cuatro reglas que los mantienen como ayuda y no como trampa:

- **Se desactivan con un clic** («ver todas» / «ver todos»), y el aviso dice por quién se está
  filtrando. Prestador ≠ proveedor comercial: un filtro duro haría inseleccionable la tarifa del
  consolidador.
- **No tocan lo ya asignado.** Las tarifas existentes de un componente se muestran y se calculan
  igual aunque su proveedor no case. Filtrar el catálogo es comodidad; filtrar lo guardado sería
  pérdida de datos.
- **Sin coincidencias no se filtra.** Si ninguna tarifa del catálogo es de ese prestador, se ven
  todas: dejar la lista vacía sería dejar al operador sin salida.
- 🔥 **La opción ya seleccionada nunca se esconde**, aunque no case con el filtro.
  `SearchableSelect` deriva la etiqueta visible de `options.find(o => o.value === modelValue)`:
  si la opción elegida desaparece de `options`, el campo pinta el **placeholder** como si no
  hubiera nada asignado. El dato seguiría en su sitio, pero la pantalla estaría mintiendo — y el
  operador volvería a elegir, machacándolo.

⚠️ **Hoy el filtro de tarifas casi nunca se activa, y no es un fallo del filtro.** El vínculo que
necesita es `travel_tarifa.proveedor_id`, y en el catálogo maestro está prácticamente vacío
(**5 de 904**; `proveedor_servicio_id`, 0). En la práctica el proveedor se fija a mano sobre la
cotización, y `onTarifaMaestraChange()` **no** lo copia desde la maestra al elegirla.

Ese abandono y la anidación son **el mismo problema**: nadie repite el proveedor en las 19
tarifas que puede tener un componente. Bajarlo a un solo campo por componente (§6.c) quita
la razón por la que el catálogo quedó vacío, así que poblar `travel_tarifa.proveedor_id` —o
su equivalente a nivel de componente maestro— vuelve a ser realista. Comprobación:

```bash
php bin/console doctrine:query:sql --force-fetch \
  "SELECT COUNT(*) total, SUM(proveedor_id IS NOT NULL) con_proveedor FROM travel_tarifa"
```

Para que el filtro rinda hay que poblar el proveedor en el catálogo maestro. Mientras tanto
degrada solo: sin coincidencias, no filtra.

### Dónde acaba

```
cotcomponente.prestador*  ──┬──► cotización detallada / pax   «Su reserva: Casa Andina»
                            ├──► La Biblia (OperacionServicio.prestador* + tel + dirección)
                            └──► la Orden de Servicio sigue usando el proveedor comercial
```

Ver `docs/Operacion.md` §3.3 para el lado operativo. En pax la referencia viaja **dentro de
`clasificacionFinancieraCliente.inclusiones`** (`construirInclusiones()` la añade a las líneas
`no_incluido`), no sólo en la entidad — como todo lo que pasa por snapshot, **hay que re-guardar
la cotización** para que aparezca en una propuesta antigua.

### Cómo se llena la línea: el primero manda, el segundo avisa (20/08/2026)

Los tres papeles viven en la **tarifa maestra** (`TravelTarifa`), no en el componente — un
componente puede tener tarifas de empresas distintas, ver `docs/Travel.md` §11. Al colgar una
tarifa de una línea pasan dos cosas, y conviene no confundirlas:

1. **Se congelan en la cottarifa**, siempre y sin preguntar. Es el snapshot: registra de quién
   era ESE precio, y no cambia aunque después se edite el maestro.
2. **Se siembran en el cotcomponente** sólo si el hueco está vacío. `sembrarPapelesEnLinea()`
   en `cotizacionEditorStore.ts`.

```
tarifa 1ª  →  la línea no tiene nada  →  toma prestador, servicio y comprador
tarifa 2ª  →  la línea ya tiene       →  NO se pisa; si difiere, se avisa
```

**Por qué manda el primero y no el último.** Porque el segundo suele ser el despiste. Colgar de
una línea una tarifa de otra empresa es legítimo —le compras al consolidador lo que opera otro—
pero es también el error más caro de cotizar cuando no es intencionado, y no se nota hasta que
hay que pedirle el servicio a alguien que nunca lo cotizó. Si ganara el último, ese despiste
reescribiría en silencio lo que ya estaba bien.

**Por qué no bloquea.** Un candado dejaría esa tarifa inseleccionable y el caso legítimo existe.
`desajustesDeTarifa` en `CotizacionEditorView.vue` compara los tres papeles y redacta el aviso;
la insignia se pone en rojo y el tooltip dice qué no encaja. Nada más: no cambia datos.

⚠️ **El servicio va atado al prestador, no suelto.** Sólo se siembra si la línea acaba de tomar
ese prestador o ya lo tenía. Rellenarlo desde una tarifa de otra empresa dejaría la línea
diciendo «Hotel A» con «habitación del Hotel B» — la misma incoherencia que
`TravelTarifa::validarConsistenciaLogica()` impide en el catálogo.

El **comprador sí es independiente**: a quién se le encarga la compra no depende de quién preste,
que es justo su razón de ser. Y es el que más cuesta si se cuela: la Orden de Servicio sale a su
nombre.

**Sin test automático.** `util/` no tiene runner (`package.json` sólo trae `typecheck` y `lint`),
así que esto se verifica a mano: colgar una tarifa con prestador de una línea vacía y ver que la
toma; colgar después una de otra empresa y ver que la línea **no** cambia y sale el aviso.

## 6.e Dónde recoge y dónde deja cada servicio (22/08/2026)

En la tarjeta de cada servicio del editor sale una línea con `fa-route`:

```
Full Day Valle Sagrado Tradicional
  🛣 el alojamiento del pasajero → Plaza de Armas de Cusco
```

Y en ámbar, con un `!`, cuando algún componente no tiene declarado su punto. El `title` dice
cuál y qué le falta.

**Para qué está aquí y no sólo en la orden de servicio.** Porque quien cotiza es quien mejor ve
que algo no cuadra: la orden se emite semanas después y para entonces el error ya viajó. Y el
caso que lo justificó apareció en la primera prueba con datos reales — una cotización cuyo Full
Day Valle Sagrado **termina en «Descanso en el Valle Sagrado»** en vez de «Retorno al centro de
Cusco», porque se le cambió el segmento final (y de paso le falta Chinchero). El catálogo dice
que ese tour deja en la Plaza de Armas; esa cotización deja en el hotel. Las dos cosas son
legítimas — lo que no lo es, es no verlo.

### De dónde sale

`GET /cotizacion/user/puntos/{cotizacionId}` → `CotizacionPuntosController` →
`CotizacionPuntosDelServicio::paraCotizacion()`.

Es el hermano de `TravelPuntosDelServicio` (ver `docs/Travel.md` §11 quater) con **la misma regla
y distinta fuente**: allí los segmentos vienen de la plantilla del catálogo; aquí, de los
`CotizacionSegmento` de esta cotización.

```
servicio que abarca el día        inicio = 1.er cotsegmento de ese día
(horaServicioCompleto)            fin    = último cotsegmento de ese día
cualquier otro                    inicio y fin = su propio cotsegmento
```

Los puntos en sí se leen del `TravelSegmento` maestro (`segmentoMaestroId`), en **una sola
consulta** para toda la cotización.

⚠️ **Endpoint aparte, no un campo de la cotización.** No es parte del documento: es una lectura
derivada del catálogo que se refresca sola cuando alguien corrige un segmento. Meterlo en
`cotizacion:read` lo habría metido también en el `PUT` de vuelta y en la vista del cliente.

⚠️ **Refleja lo GUARDADO.** Reordenar segmentos sin guardar no se ve hasta guardar. Se aceptó a
cambio de **no reescribir la regla en TypeScript**: el store sólo pinta lo que llega. Por eso se
recarga tras guardar además de al abrir (`fetchPuntosDeServicios`).

⚠️ **`aplica: false` no es un hueco.** Significa que ese servicio no recoge ni deja a nadie —un
alojamiento, un ticket, una comida— y entonces **no se pinta nada**. Un aviso en la mitad de la
cotización haría que el aviso dejara de significar algo.

⚠️ **`PuntosDeServicioCot` en `cotizacionEditorModel.ts` se declara A MANO.** El endpoint no es
`ApiResource`, así que no entra en `api.d.ts`. Si cambia el array de `paraServicio()`, hay que
cambiarlo aquí: no hay nada que lo cace.

### La cabecera es el SERVICIO ENTERO, no uno de sus componentes

El primer segmento del servicio que declare un inicio y el **último** que declare un fin,
recorriendo todos sus días en orden.

⚠️ **Antes salía del primer componente con extremos, y en un paquete de varios días eso mentía.**
«Skylodge con actividades» sale del hotel de Cusco y **vuelve al hotel de Cusco dos días después**,
pero la cabecera la marcaba el traslado de ida —cuyo segmento no declara fin— y la tarjeta decía
«sin declarar». El retorno estaba escrito, en el último segmento del día 2, y no se miraba nunca
porque pertenecía a otro componente.

⚠️ **Y no vale «el primero y el último a secas»**: en Skylodge el segmento intermedio de la vía
ferrata no declara nada, y quedarse con él dejaría la tarjeta muda teniendo el hotel escrito dos
líneas más abajo. Hay que buscar el primero que DECLARE.

Para un servicio de un día es lo mismo que antes. El **detalle por componente y los avisos siguen
siendo por componente**: ahí sí interesa cuál concreto se quedó sin punto.

### La cabecera es el servicio que abarca el día

Y sólo si no hay ninguno, el primer componente con extremos. Al revés —el primero que aparezca—
la línea la marcaría un ticket de bus de dos paradas en vez de la excursión entera, que es la que
el operador está mirando.

## 6.f Insertar un segmento ANTES de otro (22/08/2026)

El modal «¿Dónde ubicar el segmento?» tenía tres opciones —al final, después de, reemplazar— y le
faltaba la cuarta.

⚠️ **Sin «antes de», el primer puesto de un día era inalcanzable.** El segmento nuevo hereda el
**día del destino**, así que para poner algo al principio del día 2 había que colgarlo detrás del
último del día 1… y entonces se quedaba en el día 1. Y de un día a otro **no se puede arrastrar**.

Salió al montar el contacto con el pasajero en «Two Day Camino inca», que va justo al abrir el
día 2: no había forma de meterlo desde el editor.

La acción `insertBefore` es idéntica a `insert` salvo por el índice del `splice`
(`index` en vez de `index + 1`). El selector de destino muestra ahora **`[Día N]`** delante de cada
párrafo: sin eso no se ve a qué día va a parar el segmento nuevo, que es justo lo que se estaba
decidiendo a ciegas.


## 6.g Los detalles del componente: audiencias, no tipos (22/08/2026)

`CotizacionCotcomponente::$detallesOperativos` es un JSON con las cosas sueltas que el cotizador
anota sobre un componente: frecuencias de un tren, número de vuelo y hora de aterrizaje, nombre y
dirección del hotel.

### Lo que se midió antes de tocar nada

El campo nació con un `tipo` por bloque —`cliente` u `operativa`— y la foto de producción del
22/08/2026 fue concluyente:

```
17 componentes con detalles
15 tienen los DOS bloques  →  los 15 son IDÉNTICOS palabra por palabra (100 %)
 0 tienen sólo el operativo
 7 idiomas por bloque, también el operativo
```

**La distinción nunca se ejerció.** Lo único que conseguía era obligar a escribir dos veces lo
mismo y a mantener las dos copias en siete idiomas. Se sustituyó por banderas: un bloque, una
lista de audiencias.

```json
{ "id": "…", "audiencias": ["cliente", "prestador"],
  "detalle": [{ "language": "es", "content": "Latam LA-2356 aterriza 13:35" }] }
```

### Una audiencia = un documento

La lista de `AudienciaDetalleEnum` **no crece con roles: crece con
superficies que reciben papel**. Una bandera que no cambie a dónde llega el texto no se añade,
porque enseña a ignorar las demás.

| Audiencia   | Dónde aterriza                | Quién lo lee |
|-------------|-------------------------------|--------------|
| `cliente`   | cotización y app del pasajero | `getDetallesParaCliente()` |
| `interno`   | La Biblia                     | `detallesPara()` |
| `prestador` | Orden de Servicio             | `textosPara()` → ver `docs/Operacion.md` §13 |

⚠️ **La audiencia de casa se llama `interno`, no `operador`.** En turismo «operador» es muchas
veces una agencia de fuera, así que ese nombre habría dicho justo lo contrario de lo que marca.

⚠️ **Y no es un many-to-many.** Son tres casos que define el código y no cambian nunca; una tabla
intermedia añadiría dos joins y una pantalla de mantenimiento a cambio de nada. El día que una
audiencia sea un dato que el operador crea —«sólo para ESTE proveedor»— será otra forma.

### Se traduce todo, y operación lee español

Podría traducirse sólo lo que lleva `cliente`, y sería lo elegante. **No se hace a propósito:**
`AutoTranslationService::processNestedStructure()` camina el campo entero y enseñarle a saltarse
entradas es tocar el servicio que traduce **todo** el sistema. Unas traducciones de más cuestan
céntimos; ese cambio arriesga las traducciones de todos los módulos. Quien consume una nota
interna pide español y ya está — `textosPara($audiencia, 'es')`.

### La conversión

`app:cotizacion:detalles-a-audiencias` (`--dry-run`, `--todas`). Convierte el `tipo` viejo y
**funde los bloques que dicen lo mismo en español**, uniendo sus audiencias: 32 bloques → 17, con
las 7 traducciones intactas.

⚠️ **Por defecto nadie sale a `prestador`**, porque es el único cambio de esta familia que no se
puede deshacer: que a un proveedor le falte una línea se ve y se añade; que le sobre, se ve cuando
ya la leyó.

`--todas` marca las tres en todos los bloques, y es lo que se decidió el 22/08/2026 mirando el
contenido: de los diecisiete, quince son número de vuelo, frecuencia de tren y dirección de hotel
—justo lo que el proveedor necesita para trabajar—. **Quitarle la marca a dos sale más barato que
ponérsela a quince**, y nada llega a nadie hasta que se emite una orden. Estado tras aplicarlo:
17 bloques con `cliente+interno+prestador`.

⚠️ **La entidad tolera el `tipo` viejo al leer** (`normalizarBloque()`), para que entre el
despliegue y el comando el editor no reviente. El comando compara contra el JSON **guardado**, no
contra lo que devuelve el getter: si comparase contra el getter —que normaliza al leer— un bloque
sin repetidos saldría «sin cambios» y se quedaría con la forma antigua en base para siempre,
volviendo permanente una tolerancia que sólo debe durar el despliegue.

### Espejo TS

`util/src/types/cotizacionEditorModel.ts` → `AudienciaDetalle` y `AUDIENCIA_DETALLE_CONFIG`.
**Al tocar una, tocar la otra.** El editor pinta tres botones-bandera en vez del desplegable, y
`cotizacionEditorStore.alternarAudienciaDetalle()` impide quitar la última: un detalle sin
audiencia no lo lee nadie y el backend lo rechaza.

⚠️ El tipo del bloque era `tipo: DetalleOperativoTipo | string`, y eso **no tipaba nada**: una
unión con `string` colapsa el tipo entero. Ahora es `audiencias: AudienciaDetalle[]`, cerrado.

## 6.h El componente hecho A MANO, y el bucle que lo impedía (23/08/2026)

Caso que lo forzó: un Full Day Paracas–Ica en el que unos amigos invitan a los pasajeros a comer
en «La Olla de Juanita», y hay que meter **dos transportes que no existen en los maestros y que no
van a existir nunca**, porque es una parada irrepetible.

### El bucle

`agregarComponente()` crea el componente con `tipo: 'extras'`, `nombreSnapshot: []`,
`sinHorario: true` y `componenteMaestroId: null`. De ahí no se salía:

```
nombreSnapshot: []
  └─ isComponenteSoloItems() = true
       └─ el input de nombre se cambiaba por uno DISABLED,
          «Componente Contenedor (Solo ítems)»
            └─ única salida: elegir un INSUMO MAESTRO
                 └─ onComponenteMaestroChange() pisa el tipo con el del maestro
                      └─ y el desplegable sólo ofrece el pool del servicio maestro
                           └─ todo componente hecho a mano acababa siendo «pool»
```

**Para poder escribir el nombre hacía falta que ya tuviera nombre.** No había ningún default
`pool` en el código: el «pool» era el síntoma de este bucle, no su causa.

### Lo que se cambió

| Qué | Dónde | Ahora |
|---|---|---|
| `isComponenteSoloItems()` | `CotizacionEditorView.vue` | Un contenedor lo es porque **tiene ítems**: `nombreSnapshot` vacío **Y** `snapshotItems` no vacío. Uno recién creado no es ninguna de las dos cosas |
| `getNombreMaestroRef()` | `CotizacionEditorView.vue` | Sin maestro manda **su** `nombreSnapshot`; «Insumo sin seleccionar» sólo si tampoco hay nombre propio |
| Categoría Operativa | ficha del componente + `onTipoManualChange()` en el store | Selector, **sólo si `componenteMaestroId` es null** |

### El modo manual es una DECISIÓN, no la ausencia de maestro

Desbloquear los campos no bastaba: el buscador de insumos seguía presidiendo la ficha, y un
buscador arriba del todo dice «elige uno». Lo marca `CotizacionCotcomponente::$esManual`.

⚠️ **`esManual` no es `componenteMaestroId === null`, y por eso es una columna y no un cálculo.**
Un componente recién creado tampoco tiene maestro, pero está *esperando* que le elijan uno. La
marca dice que **ya se decidió que no lo tendrá**. Inferirla de que el nombre interno esté relleno
funcionaría hasta el primer manual sin nombre, y entonces volvería a pedir un maestro sin que
nadie entendiera por qué.

#### ⚠️ Un botón de alta, no dos

La primera versión puso dos —«+ Añadir Extra» y «Manual»— y **era una decisión mal colocada**: los
dos creaban exactamente lo mismo y la diferencia sólo se veía *después*, al abrir la ficha. Nadie
puede elegir entre dos botones cuya diferencia está en la pantalla siguiente.

La elección vive **dentro de la ficha**, en un conmutador `Del catálogo` / `A mano`. Así se cambia
de opinión sin borrar y volver a crear, y se ve qué implica cada lado: con catálogo el nombre y el
tipo los pone el maestro; a mano se escriben.

- Nace en **catálogo**, que es el caso normal. Nacer manual haría que el 90 % tuviera que
  deshacerlo.
- `marcarComponenteManual()` **suelta el maestro**: `esManual` con un `componenteMaestroId` puesto
  es un estado que se contradice, y el que gana depende de qué pantalla lo lea. El nombre y el tipo
  que hubiera **se conservan** — vienen de un maestro real y suelen ser el punto de partida de lo
  que se quiere escribir.
- Elegir un insumo **desmarca** el modo manual (`onComponenteMaestroChange()`): son excluyentes.
- El conmutador **no sale** si el componente vino inyectado desde un segmento: ahí no se elige
  nada, y ofrecerlo sería mentir.

Los dos campos exclusivos del manual —nombre interno y Categoría Operativa— se muestran por
`esManual`, **no** por «no tiene maestro»: en modo catálogo sin insumo todavía elegido, ofrecerlos
sería ofrecer campos que el maestro va a pisar en cuanto se elija.

#### El insumo se puede soltar

`SearchableSelect` gana una prop **`limpiable`**: con ella y un valor puesto, aparece una «×» que
emite `null` por `update:modelValue` **y por `change`** —los padres cuelgan de `change` sus efectos
en cascada, y desvincular tiene que dispararlos igual que vincular—.

⚠️ Es **opt-in**. La mayoría de estos selectores son obligatorios —un servicio maestro, una
moneda— y ahí un botón de limpiar sólo sirve para dejar el formulario inválido.

### El nombre INTERNO, que el componente no tenía

`CotizacionCotcomponente` sólo guardaba `nombreSnapshot`, que es el **título público**: el nombre
interno venía siempre del maestro, y por eso «siempre existía». Un manual no tiene maestro, así
que La Biblia acababa rotulando la fila con el texto del cliente —o con «Servicio sin nombre»—.

`$nombreInternoSnapshot` (string, nullable) lo cubre, y entra en `resolverDescripcion()` **por
delante del nombre interno de la tarifa**: éste nombra *el servicio* («Traslado a La Olla de
Juanita (ida)») y aquél la *línea de precio*, que en un componente hecho a mano se suele quedar
con el «Nueva Tarifa» de fábrica. Ver `docs/Operacion.md` §3.3.

⚠️ **Sin `#[AutoTranslate]` a propósito**: no se le enseña a ningún pasajero, así que traducirlo a
siete idiomas es coste puro. Mismo criterio que la nota al prestador.

Comprobado con datos reales (`var/probar-componente-manual.php`, en transacción con `rollback`):
con nombre interno La Biblia rotula «Traslado a La Olla de Juanita (ida)»; sin él sigue cayendo al
título público, que es el comportamiento de siempre.

⚠️ **El selector no aparece con maestro, y es deliberado.** El tipo lo pone el maestro y ahí tiene
que seguir mandando: un componente que dijera una cosa y su maestro otra no lo denunciaría nada
después.

⚠️ **El tipo arrastra el horario.** `sinHorario` no es una preferencia sino una consecuencia —un
`transporte` tiene hora y un `extras` no—, así que `onTipoManualChange()` lo recalcula con
`sinHorarioDeTipo()`. Sin eso, elegir «Transporte» dejaba el componente sin selector de hora y el
traslado sin hora, en silencio.

Y no es cosmético: de la Categoría Operativa cuelga si el componente **aporta punto de recojo y
entrega** (`ComponenteTipoEnum::puntosDeServicio()`). `transporte` da los dos; `extras` —con el
que nace— no da ninguno **y no da error**.

### Lo que un componente manual sigue necesitando

El editor ya no estorba, pero la cadena aguas abajo exige cosas que nadie recuerda:

| Requisito | Si falta |
|---|---|
| Al menos **una tarifa** (basta manual, con moneda) | `OperacionServicio::isSoloReferencia()` → **no entra en una Orden**, 422 en tres guardas |
| **Prestador** | La orden se crea pero **no se puede enviar**: «no hay a quién escribirle» |
| `nombreSnapshot` o `snapshotItems` | `construirInclusiones()` no genera línea → **advertencia bloqueante** al guardar |
| Tarifa con `rolSnapshot: 'estandar'`, si `modo: incluido` | se publica como «Opción N» dentro de *Opcional* |

⚠️ **Y La Biblia no se entera sola.** `BibliaSnapshotService` es idempotente **por existencia de
fila**, no por contenido: re-confirmar no añade ni repara. Un componente creado después de
confirmar entra por el **Plan de Operación** (`PlanOperacionModal.vue`) o por
`php bin/console operacion:resincronizar`.

### Los puntos de un componente manual NO se declaran aquí

Se descartó darle campos de inicio/fin propios. Hoy **nadie persiste puntos** salvo la orden
emitida: `TravelSegmento` es la única fuente de verdad y las dos capas intermedias
—`CotizacionPuntosDelServicio` y `OperacionPuntosDelServicio`— derivan en caliente. Añadirlos al
componente no crearía un snapshot, pero sí **una segunda superficie donde declarar el mismo
hecho**, con su regla de precedencia, y el punto sería texto libre o exigiría dar de alta el
`TravelPunto` igual.

La vía es el **override del operador en La Biblia** (`puntoRecojo` / `puntoEntrega`), y lo que el
cliente tiene que leer no es un punto sino **narrativa** — para eso está el texto del segmento.
Ver `docs/Operacion.md` §12 y §13.

## 6.i Qué cuelga de cada párrafo, y qué se lleva su papelera (23/08/2026)

La papelera del Constructor de Storytelling borraba **mucho más de lo que decía**, sin avisar:

```
removerCotSegmento()          quita el segmento Y todos sus componentes del árbol en memoria
   ▼ al guardar (PUT del árbol completo)
orphanRemoval: true           CotizacionCotservicio → DELETE real de las filas
   ▼
cottarifas                    orphanRemoval en el componente → mueren con él
   ▼
OperacionServicio             ManyToOne nullable:false + onDelete CASCADE
   ▼                          → se va la fila del cuadro de tráfico
OperacionEstadoBitacora       CASCADE → se va el historial de estados
```

⚠️ **El esquema no obliga a nada de esto**: `CotizacionCotcomponente.cotsegmento` es `SET NULL`.
La destrucción la decide el **front**, y está bien que la decida —un componente sin párrafo no lo
ve el cliente y sigue costando dinero, que es peor que borrarlo—, pero hasta ahora no se veía.

Agravante: `isComponenteBloqueado()` devuelve `true` para todo componente con segmento, así que
**desde la vista normal no se pueden borrar uno a uno**. La papelera del párrafo era la única vía.

La **orden ya emitida sobrevive**: su ítem guarda `operacionServicioId` como texto, no como FK. El
documento sigue diciendo lo que decía.

**Lo que se añadió**, en `CotizacionEditorView.vue`:

- Un **pie en cada tarjeta** con cuántos componentes cuelgan y cuáles. Visible siempre, **no en
  hover**: esto se usa en el móvil y ahí no hay hover. El detalle largo va en `InfoTooltip`, que se
  teletransporta a `body` — obligatorio, porque el modal tiene varios `overflow-hidden`.
- **Segunda pulsación en la papelera**, y sólo cuando hay algo que perder: el botón se pone rojo y
  dice «¿Borrar? se lleva 3». Con el párrafo vacío borra al primer clic — montar un itinerario es
  borrar muchos vacíos seguidos, y cobrar dos clics por cada uno enseña a pulsar dos veces sin
  leer.
- Dato: `store.idSegmentoDeComponente()`, que ya existía y **ahora se exporta**. El vínculo llega
  unas veces como IRI y otras como id plano, así que un `===` a mano falla la mitad de las veces.

## 6.j Versiones e históricos: dos clones en direcciones opuestas (23/08/2026)

Un expediente tiene N `Cotizacion`, numeradas `version`. **Son propuestas**, no versiones de un
documento: lo que el cliente elige entre varias. Y hasta ahora sólo compartían `file_id` — no
había forma de saber que la v2 salió de la v1.

### El problema: clonar hacia adelante rompe las órdenes

`CloneCotizacionProcessor` hace que la copia sea **la nueva** (v2, `PENDIENTE`) y la v1 se quede
como estaba. Eso vale **antes de vender**. Después, no:

```
v1 confirmada ──▶ OperacionServicio ──▶ órdenes emitidas
                        │
   clonas ▶ v2          │ hay que sacar v1 de confirmado para que
                        │ La Biblia no muestre los días dos veces
                        ▼
                  filas CANCELADAS  ← aquí colgaban las órdenes

   v2 confirmada ──▶ filas NUEVAS (componentes con UUID nuevo) ──▶ SIN órdenes
```

Las órdenes cuelgan de `OperacionServicio`, que cuelga de los **componentes**, y `duplicar()` les
da UUID nuevo a todos. Resultado: hay que reemitirlo todo, incluidos los servicios que no cambiaron.

### La solución: clonar hacia ATRÁS

`GuardarHistoricoProcessor` (`POST /platform/sales/client/cotizacion/{id}/historico`) hace que **la
copia sea el pasado**. La cotización viva conserva su id, sus componentes, sus filas de La Biblia y
sus órdenes; lo que cambie después lo denuncia `OperacionOrdenServicio::getDivergencias()`, que ya
distingue lo que obliga a reemitir de lo que sólo hay que completar.

**Son dos acciones distintas y la dirección depende de si ya vendiste**, no del tamaño del cambio:

| Acción | Dirección | Cuándo | Qué pasa con las órdenes |
|---|---|---|---|
| **Nueva propuesta** (`/clonar`) | adelante: la copia es v+1 | antes de vender, el cliente elige | se pierden: hay que reemitir |
| **Guardar histórico** (`/historico`) | atrás: la copia es el pasado | después de confirmar | **no se mueven** |

### El histórico NO consume número de versión

El histórico de la v1 sigue siendo **v1**, distinguido por su `createdAt`. Gastar un número haría
que la siguiente propuesta real se llamara v3 sin que existiera ninguna v2.

⚠️ **Consecuencia: `(file_id, version)` no puede ser único**, y quien busque por versión tiene que
filtrar el estado **en la misma consulta**. `CotizacionFilePublicProvider` hacía `findOneBy(file,
version)` y comprobaba `esPublico()` después: MySQL podía entregarle la foto congelada y responder
404 aunque hubiera una versión pública viva — un enlace que el cliente ya tenía dejando de
funcionar sin que cambiara nada suyo. Ya va filtrado.

### El vínculo

`Cotizacion::$derivadaDe` (self ManyToOne) — sólo lo llevan los `HISTORICO`, y apunta a la viva.

- **`SET NULL`, no `CASCADE`**: borrar la viva **no** se lleva su histórico. Es el rastro de lo que
  ya se le vendió a alguien.
- **Sin `#[Groups]` en la relación**: serializarla anida la cotización padre entera y es recursiva.
  El front recibe `getDerivadaDeId()`, plano.
- ⚠️ **La colección `$historicos` es `EXTRA_LAZY` y tiene que seguir siéndolo.** Sin eso,
  `getTotalHistoricos()` hidrata las cotizaciones enteras y las ordena por fecha; una fila de esta
  tabla lleva varios JSON grandes y MySQL responde **«Out of sort memory»**. Lo cazó la sonda con
  datos reales, no un test.
- `duplicar()` **no copia** ni el vínculo ni la colección: la copia empieza suelta y quien la crea
  decide qué es.

### El estado `HISTORICO`

No es público, no es confirmable, y en el `match` de `CotizacionConfirmadaEventListener` va
**explícito** — ese `match` acaba en `default => null`, así que un estado nuevo pasaría en silencio
y dejaría filas de operación activas sin que nada lo denunciara.

### En pantalla

- **`FileDetalle.vue`**: el listado usa `versionesVivas` —si no, habría dos tarjetas «V1» sin forma
  de saber cuál es la buena—, y los históricos cuelgan plegados de la suya, identificados por
  **fecha**. Botón de cámara al lado del de clonar: son la misma operación en direcciones opuestas,
  y confundirlas es lo caro.
- **Cabecera del editor**: si estás **dentro** de un histórico, aviso en violeta —el resto de la
  pantalla se ve exactamente igual que en la viva y se puede trabajar media hora sobre algo que no
  operará nadie—. Si estás en la viva, cuántas fotos hay detrás, con enlace al expediente.

Comprobado con datos reales (transacción con `rollback`) sobre una v1 confirmada con 42 filas de
operación: la foto sale `v1 / historico / derivadaDe = la viva`, no pública; la viva conserva sus
42 componentes y sus 42 filas, y el enlace `/v/1` sigue resolviendo a la viva.

## 6.k `Filedocumento` → `Filearchivo`: el nombre que hacía leerla al revés (23/08/2026)

`CotizacionFiledocumento` **nunca guardó documentos de identidad**. Es `Vich\Uploadable` con
`MediaTrait` y un POST multipart, y su enum es `ArchivoTipoEnum` —boleto, factura, confirmación de
reserva—. Son **adjuntos del expediente**.

El nombre engañaba lo bastante como para que se equivocara quien la diseñó: dentro había un campo
`vencimiento` cuyo formulario decía literalmente *«útil para alertar sobre Pasaportes o Visas
vencidas»*. Un campo de identidad en una entidad de archivos. **0 de 7 filas lo usaron nunca**, que
es lo que pasa con un campo que no pertenece a donde está.

| Antes | Ahora |
|---|---|
| `CotizacionFiledocumento` | `CotizacionFilearchivo` |
| tabla `cotizacion_file_documento` | `cotizacion_file_archivo` |
| campo `tipodocumento` (¡con `ArchivoTipoEnum`!) | `tipoArchivo` / columna `tipo_archivo` |
| campo `vencimiento` | **eliminado** |
| `CotizacionFile::$filedocumentos` | `$filearchivos` |
| API `/sales/cotizacion_filedocumentos` | `/sales/cotizacion_filearchivos` |
| mapping Vich `cotizacion_file_documentos` | `cotizacion_file_archivos` |
| `/carga/cotizacion/documentos` | `/carga/cotizacion/archivos` |

### ⚠️ `src/Oweb/` NO se renombra, y no es por pereza

Existe `App\Oweb\Entity\CotizacionFiledocumento` con **el mismo nombre de clase**, y colgando de
él un `CotizacionFiledocumentoAdmin` de Sonata registrado en
`config/services/services_oweb_cotizacion.yaml` con `model_class` apuntando a esa entidad. Tocar el
nombre allí rompe el panel legado sin que nada lo denuncie hasta que alguien lo abra.

Y no hace falta: **son tablas distintas**.

| | tabla | filas en producción |
|---|---|---|
| `App\Cotizacion\Entity\CotizacionFilearchivo` | `cotizacion_file_archivo` | 7 |
| `App\Oweb\Entity\CotizacionFiledocumento` | `cot_filedocumento` | 563 |

El renombre se hizo excluyendo `src/Oweb/` explícitamente del barrido, y se verificó después que el
admin sigue resolviendo en el contenedor. Su etiqueta en Sonata, por cierto, ya decía «Archivos
adjuntos»: el nombre correcto llevaba tiempo en la interfaz y sólo faltaba en el código.

Mientras `src/Oweb/` siga vivo, **cualquier renombre en `src/Cotizacion/` tiene que comprobar si
hay un gemelo allí**. Los hay: `CotizacionFile`, `CotizacionCotizacion`, `CotizacionTipofiledocumento`…

⚠️ **`tipodocumento` de `CotizacionFilepasajero` NO se tocó**: ése sí es identidad
(`DocumentoTipoEnum`: DNI, CE, RUC, PASAPORTE, CI) y se queda. El renombre tuvo que ser quirúrgico
en el front, porque los dos campos se llamaban igual y viven en la misma pantalla.

### Al desplegar esto, tres pasos que no son el `pull`

1. **`composer dump-autoload --no-dev --optimize`** encadenado al `pull`. `CLAUDE.md` lo documenta:
   con `optimize-autoloader` puesto, el classmap apunta a los archivos viejos hasta que se
   regenera, y una petición que caiga en ese minuto lanza `include(): Failed opening`.
2. **Mover la carpeta en el servidor**, porque el parámetro cambió:
   `mv public/carga/cotizacion/documentos public/carga/cotizacion/archivos`
   `imageUrl` es virtual —lo inyecta `CotizacionFilearchivoAssetListener`— así que **no hay URLs
   guardadas que reescribir**: los archivos se sirven desde el prefijo nuevo en cuanto se muevan.
3. **Front y back van juntos.** El `shortName` cambió, así que la ruta de la API cambió: un build
   viejo posteando a `/cotizacion_filedocumentos` recibe 404.

## 6.l Los documentos de identidad del pasajero (23/08/2026)

`CotizacionFilepasajero` tenía **un** `tipodocumento` y **un** `numerodocumento`, sin vencimiento.
Un padrón real lo desmiente en las tres cosas: en el de Punta Cana 2026, **130 de 133 personas
llevan DNI y pasaporte a la vez** con fechas distintas —un DNI que caduca en 2026 junto a un
pasaporte que caduca en 2031—, y **100 de los 133 son menores**, que además necesitan autorización
notarial para salir del país.

```
CotizacionPasajeroIdentificacion       tabla cotizacion_pasajero_identificacion
    pasajero      ManyToOne, CASCADE
    tipo          DocumentoTipoEnum    DNI · CE · RUC · PASAPORTE · CI (ya existía)
    numero        string               se recorta al guardar
    vencimiento   date nullable
    paisEmisor    MaestroPais nullable
    ÚNICO (pasajero, tipo)
```

### ⚠️ Nulo es «sin comprobar», no «vigente»

Es la regla de la que cuelga todo lo demás. `estaVigenteEl()` devuelve **`null`** si no hay fecha,
y `identificacionesVencidasAl()` **no** incluye esos documentos — quien llame tiene que contarlos
aparte con `identificacionesSinComprobar()`.

Está pagado: la hoja *Resumen* de ese padrón afirmaba «Pasaporte vence antes del retorno: 0», y era
cierto. Nadie miró el **DNI**, que es con lo que se embarca el tramo nacional, y había **once
vencidos** y **veintidós sin fecha**. Un listado que cuente lo desconocido como bueno reproduce
exactamente ese error.

### Por qué filas y no columnas

Con columnas serían ocho campos, la mitad nulos para los adultos, y una migración el día del carné
de extranjería o la visa. Y hay un motivo más fuerte: la unicidad `(pasajero, tipo)` es la que hace
que **reimportar el padrón corregido no duplique nada**, y ese Excel se vuelve a subir varias veces
antes de un viaje.

⚠️ **No confundir con {@see CotizacionFilearchivo}** (§6.k): eso son adjuntos —boletos, facturas—.
Aquí no hay archivo, es un dato: se consulta por vencimiento, no se descarga.

### ⚠️ La colección va `EAGER`, y es lo único que aguanta un grupo real

Con `LAZY` —el defecto— pintar el manifiesto es **una consulta por pasajero**. Medido sobre un
expediente de 142, que es el tamaño de un grupo de colegio y no el de las dos personas con las que
se diseñó:

```
LAZY    143 consultas · 70 ms
EAGER     3 consultas · 44 ms
```

Doctrine agrupa el EAGER de una colección en un solo `WHERE pasajero_id IN (…)`.

Con dos pasajeros la diferencia **no se ve**, y ése es el motivo de que esté escrito: la regresión
no la nota nadie hasta que la nota un grupo entero. Los tres `#[ApiProperty(fetchEager: false)]` de
`CotizacionFile` no ayudan aquí —sólo el de `cotizaciones` tiene un motivo escrito; los otros dos
parecen copiados—, así que el arreglo va en la propia asociación.

### La migración, en dos pasos y a propósito

`app:cotizacion:pasajeros-a-identificaciones` (`--dry-run`) copia la columna vieja a una fila.
Idempotente por la unicidad.

⚠️ **Las columnas viejas no se tiraron en el mismo despliegue.** El comando leía de ellas, así que
tirarlas a la vez lo habría dejado sin fuente. La secuencia fue —y sirve de patrón para la próxima
columna que se retire—:

1. Tabla nueva, columnas viejas fuera de la API y `tipodocumento` a nulable —si no, un pasajero
   nuevo llegaría sin él y el `NOT NULL` lo rechazaría—.
2. **Correr el comando en producción** y comprobar. *(23/08/2026: 2 pasaportes, con su país
   emisor. Segunda pasada: 0 creadas.)*
3. Despliegue siguiente: `DROP tipodocumento, DROP numerodocumento`, y el comando **se borra** —sin
   columnas de las que leer, ya no puede hacer nada—.

⚠️ La migración del paso 3 lleva un `preUp()` que **cuenta los pasajeros con documento en la
columna vieja y sin su fila, y aborta si hay alguno**. Comprobar sale más barato que confiar, y una
migración que aborta es infinitamente mejor que una que tira el dato.

Lo copiado nació **sin vencimiento**, porque la columna vieja no lo guardaba. El comando lo avisaba
en voz alta: no es un hueco que rellenar luego, es información que nunca existió.

⚠️ Y `DOCUMENTO_IDENTIDAD_LABELS` de `fileDetalleModel.ts` ahora deriva su tipo del schema de la
**identificación**, no del pasajero. Al desaparecer la columna, el `Record<>` dejó de compilar — y
eso es el compilador haciendo su trabajo: el espejo apuntaba a un sitio que ya no existe.

### En el front

El formulario del pasajero pasa de dos campos a una **lista**: tipo, número y vencimiento por fila,
y los tipos ya usados no se vuelven a ofrecer —la unicidad es de base, y repetir sólo consigue un
422 después de escribir el número—. Avisa en ámbar cuando alguna fila va sin fecha.

⚠️ Al guardar **se manda la colección entera** y `orphanRemoval` reemplaza. Casar por IRI exigiría
que la identificación fuese un `ApiResource` propio, y no lo es: sólo existe colgando de su
pasajero.

⚠️ Y `operacionStore.ExpedienteDetalle.filepasajeros` dejó de ser `Record<string, unknown>`. Con la
forma laxa, iterar `identificaciones` daba `never` y sólo se vio al compilar: lo laxo esconde justo
lo que hace falta el día que el backend cambia de forma.

## 6.m Subgrupos del expediente: ejes cruzados, no un árbol (24/08/2026)

Un padrón de grupo grande no agrupa de una forma sino de varias a la vez. Medido sobre el de Punta
Cana 2026:

```
5 salones · 10 grupos · 43 combinaciones distintas
9 de los 10 grupos aparecen en MÁS DE UN salón — el grupo 1, en los cinco
```

Una persona está a la vez en el salón B, el grupo 5, la habitación HA13 y sus dos reservas aéreas.
**Eso no es una jerarquía**, y modelarlo anidado se rompe en cuanto quieres filtrar por el otro eje.

```
CotizacionFileGrupo                    CotizacionPasajeroGrupo
   file      ← del EXPEDIENTE             pasajero ─┐
   tipo      GrupoTipoEnum                grupo    ─┤ N:M
   clave     'B' · '5' · 'HA13'           esJefe    │
   nombre    opcional                               │
   ÚNICO (file, tipo, clave)              ÚNICO (pasajero, grupo)
```

### El eje va como enum; la clave, nunca

De los cuatro ejes, **dos reciben su valor de fuera**: 66 habitaciones las numera el hotel y 20
códigos de reserva los dan las aerolíneas. Ahí un enum es imposible.

Lo que evita los duplicados por tecleo no es congelar una lista sino **la unicidad
`(file, tipo, clave)`** más normalizar la clave al guardar —sin espacios, en mayúsculas—. Con eso,
reimportar el Excel corregido no duplica nada, y ese archivo se vuelve a subir varias veces antes
de un viaje.

⚠️ **Cuelga del expediente**: el «Grupo 5» de Punta Cana no es el de otro viaje.

### El código es dato; el papel es archivo

`clave` guarda el PNR (`JA2CWN`), no el PDF. Si viviera dentro del archivo no se podría buscar por
él, ordenarlo, imprimirlo en la orden ni pegarlo en la web de la aerolínea. El namelist se sube
aparte y cuelga del grupo — ver las dos claves nulables de `CotizacionFilearchivo`:

| `pasajero` | `grupo` | qué es |
|---|---|---|
| ✓ | — | suyo: boarding pass, autorización notarial |
| — | ✓ | del grupo: el namelist con el PNR |
| — | — | del expediente: lo de siempre |

El propio padrón describe el orden: *«la asignación individual se define al cargar los
namelists»*. El archivo no **es** la pertenencia: la **define**, y luego queda de respaldo.

### ⚠️ La pertenencia es entidad, no `ManyToMany`

Porque lleva `esJefe`, que es atributo **de la pertenencia**: se lidera *un* grupo, no en general.
Doctrine no deja colgar columnas de la tabla de unión de un `ManyToMany`.

### ⚠️ La coherencia de expediente NO la impone el esquema

`cotizacion_pasajero_grupo` **no lleva `file_id`** —sería una tercera copia del mismo hecho—, así
que nada a nivel de base impide unir un pasajero del expediente A con un grupo del B. Lo cierran
dos callbacks de ciclo de vida:

- `CotizacionPasajeroGrupo::validarMismoExpediente()`
- `CotizacionFilearchivo::validarDuenoDelMismoExpediente()`

Es un invariante que **no se rompe nunca a mano y que un importador rompe en lote**.

### En pantalla

- **Sección «Subgrupos»** en `FileDetalle`, antes del manifiesto: se definen primero y se asignan
  después. Alta con eje + clave + nombre opcional; al borrar avisa a cuántos pasajeros se quita y
  aclara que **ellos no se borran**.
- **En la ficha del pasajero**, píldoras por eje: se marcan varias a la vez y la corona marca de
  cuál es jefe.
- `pertenencias` va **`EAGER`** por lo mismo que las identificaciones (§6.l): con `LAZY` son 140
  consultas que con dos pasajeros no se ven.

## 6.n La plantilla del padrón: una definición, dos usos (24/08/2026)

`PadronFormato` describe las columnas **una sola vez**, y de ahí salen tanto el .xlsx que se
descarga como —cuando exista— el lector que lo importa. Con dos definiciones, el día que alguien
añada una columna la plantilla saldría con ella y el importador la ignoraría: sin error, sin aviso,
con el dato perdido.

### Tres familias de columna

```
FIJAS        Nombres · Apellidos · Nacionalidad · Sexo · F. Nacimiento · Observaciones
DOCUMENTOS   una por DocumentoTipoEnum + su «Venc. »
             DNI · Venc. DNI · Pasaporte · Venc. Pasaporte · …
EJES         cualquiera que empiece por «#»
             #Salón · #Grupo · #Habitación · #Reserva aérea
```

Documentos y ejes **salen de los enums**, así que dar de alta un tipo lo añade a la plantilla y al
lector a la vez.

### Qué la hace servir para los dos casos

**Sólo «Nombres» es imprescindible.** El resto es opcional y las columnas se buscan **por cabecera,
nunca por posición**: se reordenan, y las que no se usen se borran. El mismo archivo vale para dos
personas con un pasaporte y para 133 con dos documentos y cinco agrupaciones.

La plantilla trae **dos filas de ejemplo a propósito**: una con todo y otra con lo mínimo —un
nombre y un pasaporte—. La segunda es la que enseña que las columnas sobran, que es lo que nadie
deduce mirando una plantilla llena.

⚠️ **La marca `#` no es decorativa.** Sin ella no habría forma de distinguir un eje de una columna
de notas que el operador añadió por su cuenta, y el lector tendría que adivinar. Con ella el
archivo se describe a sí mismo: borras `#Salón` y ese eje deja de existir.

⚠️ Y un `#Bus` **se rechaza con un mensaje que lo dice**, en vez de crear un eje fantasma:
`GrupoTipoEnum` es lo que garantiza que el código sepa pintar y filtrar cada eje. Añadir uno de
verdad es un `case`, no una columna.

### Se genera, no se guarda

`GET /cotizacion/user/padron/plantilla` la construye al vuelo con PhpSpreadsheet y `no-store`.
Guardarla como archivo reintroduce el problema que resuelve generarla: **una plantilla
desactualizada es peor que ninguna** — la rellenan igual y el dato se pierde al importar.

Lleva una segunda hoja de **Instrucciones** con qué significa cada columna, qué valores admite y
qué pasa si falta. Es lo que hace que sobreviva a que alguien la reenvíe por correo sin contexto.

⚠️ Y dice en voz alta lo que más caro sale: *«sin fecha de vencimiento el documento queda como SIN
COMPROBAR, que no es lo mismo que vigente»*.

### Dónde está el botón

En la cabecera de **Subgrupos** de `FileDetalle`, junto a donde se cargará el padrón: quien va a
subir uno es exactamente quien no sabe qué columnas lleva.

## 6.o Cuando no todos llevan lo mismo: precio por persona, no total de grupo (24/08/2026)

El padrón de Punta Cana ganó **diez columnas de SÍ/NO**, una por servicio: vuelo nacional, vuelo
internacional, seguro, traslados, Tour Saona, Coco Bongo, hotel, comidas. Medido sobre las 133
personas:

```
13 combinaciones distintas de servicios
   105 personas  paquete completo (10/10)
    13 personas  todo salvo «Comidas Lima»
    15 personas  casos sueltos: 0/10, 1/10, 2/10, 4/10…

⚠️ el TIPO de pasajero NO explica la combinación:
   «Alumno» tiene 4 · «Padre de familia» 4 · «Invitado» 4
```

**No son paquetes por rol: es un paquete con excepciones individuales.** Eso descarta modelarlo
como «clases de pasajero», que es por perfil.

### Lo que se arregló hoy, y era una condición de más

`Cotizacion::$totalesOcultos` ya hacía exactamente lo que hace falta —oculta el «2X» del perfil, el
«× N pax · total» y la barra «Precio total del viaje», dejando ver el precio unitario—, pero **su
interruptor estaba tras `v-if="modoCatalogo"`**, así que en un expediente no se podía activar.

El flag nunca estuvo limitado; sólo el botón. Ahora sale siempre, con el texto de ayuda cambiando
según el caso.

⚠️ Con 13 combinaciones, **un «precio total del viaje» no describe a nadie**: ni al que lleva los
diez servicios ni al que lleva dos. Lo único cierto que se le puede enseñar a cada familia es su
propio precio.

### Lo que NO resuelve, y es el siguiente paso

Que el precio por persona sea **el suyo** exige saber qué servicios lleva cada uno, y eso hoy no
existe: la cotización tiene una estructura de precios para el grupo entero. Las diez columnas del
padrón describen justo ese dato.

Dos caminos, sin decidir:

- **Participación como subgrupo** (`GrupoTipoEnum::SERVICIO`): «los que van a Coco Bongo» es un
  conjunto de personas, y encaja con lo ya construido — la orden de servicio de Coco Bongo saldría
  con su lista de quién va. Pero un grupo no sabe a qué `CotizacionCotcomponente` corresponde, así
  que **no da el precio**.
- **Participación contra el componente** (`pasajero ↔ cotcomponente`): da el precio exacto porque
  cada uno suma lo suyo, y es lo correcto conceptualmente. Es la relación que hoy no existe.

## 6.q El modo del expediente: una decisión, no cinco banderas (24/08/2026)

`CotizacionFile::$modo` — `ESTANDAR` o `GRUPO` — y de ahí cuelga todo:

| | estándar | grupo / incentivo |
|---|---|---|
| `exigeIdentificacion()` | no | documento + fecha de nacimiento |
| `ocultaTotalDeGrupo()` | no | precio por persona |
| `usaPadron()` | no | subgrupos, importación, panel por participante |

### Por qué un modo y no banderas

Iban a ser tres flags y eran **la misma decisión escrita tres veces**. Con banderas sueltas existen
combinaciones que no significan nada —«padrón sí, identificación no»— y alguien tendría que decidir
qué hacen.

Es la misma idea que `modoCatalogo`, que hoy **no es un flag**: sale de que la cotización cuelgue de
un `CotizacionCatalogo` en vez de un `CotizacionFile`. Aquí se consigue lo mismo sin una entidad
aparte — basta decir qué es el expediente.

⚠️ **Va en el EXPEDIENTE, no en la cotización.** Un expediente tiene N versiones y el modo es
propiedad del **negocio**: la v2 de un viaje de colegio sigue siendo un viaje de colegio. Por
versión abriría la puerta a que la v1 pida documento y la v2 no, sin que eso quiera decir nada.

### Qué cambia en modo grupo

El calculador financiero **deja de ser lo que ve el cliente** y pasa a ser coste interno. Aquí no
se vende «coste + margen» sino un precio de paquete acordado, con gratuidades y excepciones que
ninguna fórmula describe. Lo que se publica son tres superficies:

```
1. INCLUYE / NO INCLUYE del paquete base      construirInclusiones(), como siempre
2. SERVICIOS ADICIONALES con su precio         el bucket `opcionales`, como siempre
3. INCLUSIONES ESPECÍFICAS del participante    los grupos de tipo SERVICIO
```

⚠️ La tercera es **informativa y sin precios**. Al no tener que calcular nada, no toca el motor
financiero: «los que van a Coco Bongo» es un conjunto de personas, o sea un grupo, y la pertenencia
*es* la participación. Sale gratis la lista de quién va que necesita la orden de ese proveedor.

### El «salón» se quitó

Era control académico del colegio —qué aula le toca a cada alumno— y no describe nada del viaje. Un
eje que no cambia ninguna decisión operativa sólo enseña a ignorar los demás. Cero filas en
producción cuando se retiró.

### Dos marcadores en la plantilla, no uno

```
#Grupo · #Habitación · #Reserva aérea     ejes CON VALOR
+Seguro · +Tour Saona · +Coco Bongo       servicios: SÍ/NO por persona
```

Una columna `#Coco Bongo` con «SÍ» crearía un grupo llamado «SI», que no significa nada. Y las
columnas `+` **son las de tu viaje**: la plantilla trae las del padrón real como ejemplo porque una
plantilla con columnas plausibles se entiende sin leer nada, y una con «Servicio 1, Servicio 2» hay
que explicarla.

⚠️ **Vacío se lee como NO.** En 133 filas una celda en blanco es «no me consta», y apuntar a alguien
a un servicio por descuido cuesta dinero.

## 6.p Quién ve a quién en un padrón (24/08/2026)

### Dos ejes, y confundirlos es la fuga

```
ALCANCE     qué expediente ve        PasajeroTipoEnum::alcance()
EXPOSICIÓN  si aparece ante otros    PasajeroTipoEnum::esExpuesto()
```

Un invitado **no es «el que menos ve»: es el que no se ve.** Eso no es un límite de alcance sino
una propiedad de la persona, y por eso son dos preguntas y no una.

| tipo | ve | le ven |
|---|---|---|
| Supervisor | el expediente **menos invitados** | sí |
| Coordinador | sus grupos, menos invitados | sí |
| Participante · Acompañante · No participa | sólo su ficha | sí |
| **Invitado** | sólo su ficha | **no** |
| sin tipo | sólo su ficha | sí |

⚠️ **Los nombres describen el PAPEL, no el contexto.** `PARTICIPANTE` y no «alumno», `ACOMPANANTE` y
no «padre de familia»: el modo grupo sirve igual para la promoción de un colegio que para un
incentivo de empresa, y allí nadie es alumno ni padre de nadie. Un acompañante puede ser un padre,
una pareja o un directivo. En un colegio los dos nombres se siguen leyendo bien; al revés, no.

### Por qué los invitados desaparecen para TODOS

Son **gratuidades de la agencia**, acordadas fuera del contrato con el colegio —7 de 133 en Punta
Cana, con 1 a 5 servicios cada uno—. Si el colegio los ve, pregunta por ellos, y la respuesta no
es del colegio.

⚠️ **No se filtran por rol: se caen de toda vista pública.** Filtrarlos dejaría huecos —una
habitación con una cama de menos, una cuenta que no cuadra— y un hueco se pregunta igual que un
nombre. Desaparecer para todos es lo único que no invita a preguntar.

⚠️ **Salvo el propio invitado, que siempre se ve a sí mismo.** `esExpuesto()` habla de los DEMÁS;
aplicarlo también a uno mismo dejaría a las siete gratuidades sin poder consultar su viaje, que es
lo contrario de regalárselo.

### La agencia entra por la puerta que ya existe

`FRAMEWORK_SESION_COOKIE_DOMAIN` es `.openperu.pe` y los dos `apiClient` mandan `withCredentials`,
así que **la sesión de `util` viaja sola** cuando `pax` llama a la API. No hace falta un segundo
sistema de autenticación: una condición.

```
is_granted(ROLE_RESERVAS_SHOW)  →  TODO, invitados incluidos    ← la agencia
si no                           →  documento + fecha de nacimiento, y filtro por rol
```

⚠️ Se escribe con el **rol** y no con «estar logueado». Hoy da lo mismo —sólo el personal de
OpenPeru tiene cuenta y el colegio nunca la tendrá—, pero el rol dice lo que la regla dice de
verdad, *es la agencia*, y no cuesta nada.

En pantalla: con sesión de agencia **no se pinta el formulario**; sin ella, el formulario y debajo
un «Login agencia» discreto que **vuelve a donde estabas** — si te deja en el panel, nadie lo usa
dos veces.

### ⚠️ `esJefe` se cayó el mismo día que nació

La primera versión puso una bandera de jefatura en la pertenencia. El padrón la desmintió: hay **9
coordinadores para 9 grupos**, uno cada uno, así que quién lidera ya lo dice el rol. Una bandera
que no cambia nada **y que además podía abrir una puerta de acceso** es peor que no tenerla.

`CotizacionPasajeroGrupo` **sigue siendo entidad** de todas formas: lleva `id` y `timestamps`, hace
falta para la unicidad con nombre propio, y aloja `validarMismoExpediente()`.

### El flag de acceso es APARTE del de precio

```
totalesOcultos       presentación de precio: unitario, sin total de grupo
accesoIdentificado   acceso: exige documento + fecha y filtra por rol
```

Si fueran el mismo, ocultar el total implicaría pedir el documento — y un catálogo público oculta
totales sin pedirle nada a nadie.

### Comprobado con el reparto real

```
PÚBLICO   Supervisor  → 7 personas, sin el invitado
          Coordinador → 2 (su grupo)
          Alumno      → 1
          Invitado    → 1 (él mismo)
          sin tipo    → 1
          sin identificar → 0
AGENCIA   todos → 8, invitado incluido
```

## 6.r El importador del padrón (24/08/2026)

`PadronImportador` consume la **misma** `PadronFormato` con la que se genera la plantilla. Con dos
definiciones, el día que alguien añada una columna la plantilla saldría con ella y el importador la
ignoraría: sin error y con el dato perdido.

`app:cotizacion:importar-padron <expediente> <archivo.xlsx> [--aplicar]`.

### Idempotente, porque el archivo se sube varias veces

Un padrón de colegio se corrige tres o cuatro veces antes del viaje. Las tres claves ya estaban en
el modelo:

| se casa por | qué lo garantiza |
|---|---|
| la **persona**, por su documento `(tipo, numero)` | los nombres se escriben distinto cada vez |
| los **grupos**, por `(file, tipo, clave)` | único en base |
| las **identificaciones**, por `(pasajero, tipo)` | único en base |

Verificado con el padrón real: primera pasada 133 creados · segunda pasada **0 creados, 133
actualizados, 0 grupos, 0 pertenencias**.

⚠️ **Las pertenencias SÍ se sincronizan.** Si el archivo corregido dice que ya no va a Coco Bongo,
deja de ir — es justo lo que se está corrigiendo al resubir. Probado: 4 celdas a «NO» → 4
pertenencias quitadas y nada más movido.

⚠️ **Las personas NO se borran.** A quien está en el sistema y no en el archivo se le **avisa**. Una
fila que falta puede ser una baja o puede ser que alguien filtró el Excel antes de mandarlo, y
borrar cuarenta personas por un filtro mal puesto no se deshace.

### El ensayo escribe y deshace

⚠️ La primera versión se limitaba a no hacer `flush()`, y eso hacía el ensayo **más permisivo que
la carga**: un `NOT NULL` sólo salta al escribir. Un padrón pasó el ensayo limpio y reventó al
aplicar por dos celdas de género vacías — que es exactamente lo que un ensayo existe para evitar.

Ahora el ensayo hace `flush()` dentro de una transacción y la deshace. Lo que se enseña antes de
aceptar es lo que va a pasar, constraints incluidas.

### Lo que el padrón real enseñó, y ninguna prueba de laboratorio habría dado

- **La cabecera no está en la fila 1 ni en la hoja 1.** El de Punta Cana tiene tres filas de título
  y empieza con una hoja «Resumen». Se buscan **hoja y fila**: exigir «hoja 1, fila 1» es exigir que
  reformateen el archivo antes de subirlo.
- **Acaba en una fila `TOTAL "SI"`.** Sin detectarla se crea un pasajero llamado TOTAL y, peor, esa
  fila trae recuentos en las columnas de documento que pueden casar con alguien de verdad.
- ⚠️ **Dos personas con el mismo pasaporte.** `123343260` estaba en dos filas —una alumna y una
  coordinadora— y sin comprobarlo la segunda casaba con la primera y **la sobrescribía**: una
  persona desaparecía del viaje sin que nada lo dijera. Ahora se **aborta antes de tocar nada** y se
  dicen los dos nombres.
- **El género llegaba en 131 de 133.** La columna era `NOT NULL` porque la entidad se diseñó para
  cotizaciones de dos personas donde se teclea todo; bloquear 133 cargas por dos celdas es
  desproporcionado. Pasó a nulable — el tipo en PHP ya lo toleraba.

### Alias sí, marcadores no

Se aceptan nombres alternativos donde **no hay duda**: «Gén.» → Sexo, «F. Nacimiento», «País».

⚠️ Los marcadores `#` y `+` **no tienen alias**. Sin ellos no se puede distinguir un eje de una
columna de notas, y adivinarlo es lo que este formato existe para evitar. Una columna marcada que no
corresponda a ningún eje —`#Bus`— se **avisa por su nombre** y se ignora, en vez de crear un grupo
fantasma.

⚠️ Una columna «Nombres y Apellidos» se acepta y se parte por convención peruana —las dos últimas
palabras son apellidos—, **avisando de que es una conjetura**: acierta con nombres locales y falla
con extranjeros («Todd Joseph Rouse» daría apellido «Joseph Rouse»).

### Resultado con el padrón real (133 personas)

```
133 pasajeros · 262 identificaciones · 1 619 pertenencias
106 grupos:  66 habitaciones · 21 reservas aéreas · 10 servicios · 9 grupos
roles:  99 alumnos · 14 padres · 9 coordinadores · 7 invitados · 2 supervisores · 2 no participa
Coco Bongo: 122 — el mismo número que sale de contar el Excel a mano
```

## 6.s La carga del padrón, y dos callejones sin salida (24/08/2026)

### Dónde se carga, y por qué NO va con el modo grupo

`FileDetalle`, después del manifiesto:

```
Modo del expediente
Manifiesto de pasajeros
Cargar pasajeros desde una hoja   ← plantilla + carga, SIEMPRE visible
Subgrupos                          ← sólo en modo grupo
Historial de versiones
```

⚠️ **La carga y la plantilla están fuera del `usaPadron`.** Cargar un namelist de dos personas es
tan válido como cargar 133, y esconderlo en modo normal obligaba a teclear a mano lo que ya está en
una hoja. Lo exclusivo de un grupo son los **subgrupos**, no la carga.

⚠️ Y el padrón va **después** del manifiesto. Estuvo delante, y eso hacía que lo excepcional
presidiera lo corriente: en un expediente normal los pasajeros son lo único que hay.

### Valores estrictos, porque la plantilla los da

`desdeTexto()` **ya no adivina**. Aceptaba «alumno», «acompa», «ppff», y era un error de criterio:
la plantilla trae hoja de instrucciones, una tabla de **198 países**, las equivalencias de sexo, los
roles con lo que ve cada uno, y **desplegables con validación en las propias columnas**.

Con la lista delante, exigir el valor exacto deja de ser una trampa. Adivinar convertía un «Alumbo»
mal escrito en un silencio — y el rol decide qué ve cada persona.

Un valor fuera de lista **detiene la carga nombrándolo**: *«el rol "Alumno" no existe. Válidos:
Participante, Padre de familia…»*.

### Tres bandas de color en la plantilla

```
verde    Nombres                        sin esto no hay fila
azul     apellidos · país · sexo · nacimiento · teléfono · observaciones · documentos
naranja  Rol · #Grupo · #Habitación · #Reserva aérea · +Servicios
```

Las naranjas van **al final y en bloque**, para poder borrarlas de una pasada. Quien abre la
plantilla para cargar dos pasajeros necesita saber qué puede quitar, y con dos colores no se sabía.

### El antiguo «Dónde se carga»

`FileDetalle` → **Subgrupos** → «Cargar padrón». Junto a «Descargar plantilla», que es donde lo
busca quien va a subir uno.

`POST /cotizacion/user/padron/cargar/{id}?ensayo=1|0`.

⚠️ **Siempre ensayo primero, y el informe dice el nombre del expediente.** Cargar 133 personas en
el que no toca es un error caro y silencioso — pasó durante el desarrollo, sobre un expediente real.

### El teléfono del pasajero

`CotizacionFilepasajero::$telefono`, con el **mismo `PhoneSanitizer`** que
`CotizacionFile::sanitizarCampos()`: si cada sitio limpiara a su manera, el mismo número quedaría
escrito de dos formas y buscar por teléfono dejaría de encontrar a nadie.

⚠️ Con una mejora: el expediente asume `'PE'` porque no tiene nada mejor; el pasajero **sí sabe su
país**, así que un número extranjero se interpreta bien en vez de llevarse un `+51`. Se pinta con
`formatearTelefono()`, el mismo espejo que usan reservas y chat.

### ⚠️ El callejón de `ContactoDeIdentidad`

`editable` se calculaba con `props.telefono !== undefined`. La API **omite las claves nulas**, así
que un expediente creado **sin teléfono ni correo** mandaba las dos `undefined`, el componente
concluía «nadie me pasó modelos», pintaba sólo lectura, y **no había forma de añadir el primero**:
para poner el teléfono hacía falta ya tener teléfono.

Ahora mira si existe el **listener de `v-model`** (`attrs['onUpdate:telefono']`), que es lo que de
verdad se estaba preguntando: el listener está siempre que el padre escriba `v-model`, tenga valor
o no.

⚠️ Es el segundo bucle de esta forma en el mismo día —el otro fue `isComponenteSoloItems`, §6.h—.
El patrón es siempre igual: **una condición que deduce «puedo editar» del propio dato que falta**.
Cuando el estado inicial es vacío, se cierra sobre sí misma.

## 7. Mapa de vistas (dónde se pinta qué)

| Vista | Archivo | Fuente de datos |
|---|---|---|
| Editor principal (árbol, tarifas, ítems, upgrades) | `util/.../CotizacionEditorView.vue` | `store.servicioActivo` / `componenteActivo` / `tarifaActiva`, `store.gruposUpgrade` |
| Reporte financiero interno ("Incluye/No incluye", upgrades) | `util/.../components/cotizacion/ResumenClasificacion.vue` | `store.resumenFinanciero`, `store.gruposUpgrade` |
| Vista cliente ("Tu itinerario", "Opciones alternativas") | `pax/.../views/cotizacion/PaxCotizacionGuiaView.vue` | `store.inclusiones`, `store.gruposUpgrade` (de `clasificacionFinancieraCliente`) |
| Dashboard catálogos (listado, tours por segmento, precio **por rango**) | `util/.../Cotizaciones/CatalogoDashboard.vue` | `cotizacion_catalogos` + `tour.preciosDesde` (sin total de grupo; ver §6.b) |
| Detalle de expediente (grupo real: manifiesto, versiones, **total vendible**) | `util/.../Cotizaciones/FileDetalle.vue` | `cotizacion_files` + `cot.totalVenta`/`ganancia` (ver §6.b) |

En las 3 tarjetas internas de alternativa se muestra **`Componente · Tarifa`** (nombre interno del maestro + nombre interno de tarifa) y la línea **"Reemplaza"** con la estándar espejo. En pax se muestran solo datos públicos.

### El inspector y `dataActiva`: un ref, cuatro tipos

El panel derecho del editor edita **un nodo del árbol**, y de cuál se trata depende
del nivel abierto: `dataActiva` es una `Cotizacion`, un `CotServicio`, un
`ComponenteCompleto` o una `TarifaSnapshot` (tipo `NodoInspector`).

**El discriminante es `inspectorActivo`**, y es fiable porque `abrirNivel()`
asigna nivel y nodo **siempre juntos** (hay un comentario en el store explicando
por qué: si se separan, el render intermedio revienta en los `findIndex` de la
cabecera).

Sobre esa invariante se apoyan los tres accesores del store, que son **la única
forma correcta de leer el nodo**:

| Accesor | Devuelve | Null cuando |
|---|---|---|
| `store.servicioActivo` | `CotServicio` | el inspector no está en 'servicio' |
| `store.componenteActivo` | `ComponenteCompleto` | el inspector no está en 'componente' |
| `store.tarifaActiva` | `TarifaSnapshot` | el inspector no está en 'tarifa' |

Reglas al tocar esta zona:

- **No leas `dataActiva` directamente.** Fue `any` durante mucho tiempo y eso
  dejaba escribir campos de un tipo sobre un nodo de otro sin ningún aviso: así
  se coló durante meses una cabecera que pintaba `tarifaActiva.nombreSnapshot`,
  campo que no existe en `TarifaSnapshot` (es `tituloSnapshot`), y que por tanto
  salía **siempre vacía**.
- **Cada bloque del template cuelga de su accesor** (`v-if="store.servicioActivo"`,
  no `v-if="store.inspectorActivo === 'servicio'"`): además de elegir el nivel,
  garantiza el nodo y deja que `vue-tsc` estreche el subárbol entero.
- ⚠️ **`vue-tsc` NO estrecha dentro de handlers inline ni de callbacks**
  (`@input="e => …"`, `findIndex(s => … store.servicioActivo.id)`), aunque el
  bloque cuelgue del `v-if`. Ahí hay que repetir el guard en la propia expresión
  o usar `?.`. No es un fallo del código: es el límite del chequeo de plantillas.
- **En funciones `async` el nodo se captura al entrar**, no se relee después de
  cada `await`. Si el usuario navega a otro nodo con un fetch en vuelo, la
  escritura cae sobre el nodo que estaba abierto al lanzar la acción — mismo
  criterio que el contador `navGen` que ya cortaba las hidrataciones tardías.

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

**Gotcha #5 — el PUT puede desenganchar la cotización de su expediente**: `Cotizacion::$file` es
**nullable**, y el editor guarda con un PUT del árbol completo. Un payload que llegue sin `file`
—o con `file: null`— dejaba la cotización huérfana y Doctrine lo aceptaba sin una palabra: el
expediente perdía su versión en silencio.

Sólo dio la cara porque al confirmar, ese file se copia a `OperacionServicio`, donde la columna
**sí** es NOT NULL, y el guardado entero moría con un `Column 'file_id' cannot be null` que no
menciona la cotización (14/08/2026, `PUT /platform/sales/cotizacions/38e83a6c…`). Hoy
`Cotizacion::setFile()` **ignora el null que desengancharía** un expediente ya asignado —
reasignar a otro file sigue permitido, porque eso sí es deliberado. Mecanismo completo y la
segunda guarda del lado de operaciones: `docs/Operacion.md` §3.7.

---

## 9. "Quiero cambiar X — ¿dónde toco?"

- **Que un dato más salga en la tarjeta del componente** → el bloque de pastillas junto a `prestadorNombreSnapshot` en `CotizacionEditorView.vue` (§6.d).
- **Que el bloque «Catálogo Maestro» arranque cerrado** → `leerPreferencia()` en `CotizacionEditorView.vue`; hoy abre salvo que se haya cerrado antes (§6.d).
- **Cómo se calcula costo/venta/markup/alternativas** → `resumenFinanciero` en `util/.../cotizacionEditorStore.ts`.
- **Qué ve el cliente (y qué se oculta)** → `expurgarParaCliente` en `util/.../cotizacionEditorModel.ts` (+ tipos `OpcionUpgradeCliente`, `InclusionServicio`, etc.). Recordar re-guardar para regenerar snapshots.
- **Tipos compartidos del árbol/tarifas** → `util/src/types/cotizacionEditorModel.ts` (interno) y `pax/src/types/paxCotizacionModel.ts` (cliente). Mantenerlos coherentes.
- **Nombre interno del componente** → helper `nombreInternoDeComponente` (store). **Título público / por ítems** → `tituloClienteDeComponente` (store).
- **Badges modalidad/categoría** → `modCatBadges` (`cotizacionEditorModel.ts` en util; local en `PaxCotizacionGuiaView.vue` en pax).
- **Serialización pública / ocultar precio o proveedor** → `src/Cotizacion/Serializer/CotizacionPublicNormalizer.php` + grupos `pax_cotizacion:read` en las entidades.
- **Portada o duración de un tour de catálogo (en el panel o en pax)** → `TourTarjetaResolver` (§6.b). Nunca reimplementar la derivación en la entidad ni en el front.
- **La tarjeta de precio de la guía (colapsada/expandida, textos del pie)** → sección "TARJETA DE PRECIO" de `PaxCotizacionGuiaView.vue` + `finanzasAbiertas` / `hayPanelPrecio`. Ojo con el vocabulario: §6.
- **Que un tour de catálogo muestre (o no) el total de grupo** → flag `totalesOcultos`: default al crear en `crearCotizacionVacia()` (store), toggle "Ocultar Total de Grupo" en `CotizacionEditorView.vue`, consumo en `ocultarTotales` de `PaxCotizacionGuiaView.vue`. Ver §6.b.
- **Precio "desde" del escaparate del catálogo** → `preciosDesde[]` (bloque "Precios de Exhibición" del editor) → `PaxCatalogoPortadaView.vue` / `CatalogoDashboard.vue`. No es lo mismo que el flag anterior (§6.b).
- **En qué idioma ve el cliente la propuesta** → `idiomaCliente` (§6). Alta: `handleCreate` en `DashboardView.vue`. Cambio posterior: selector de `FileDetalle.vue` → propaga `CotizacionFileIdiomaClienteListener`. Por versión: selector del editor.
- **Qué pasa al pasar una versión a `confirmado`** → dispara `CotizacionConfirmadaEventListener` y genera el cuadro de tráfico del Centro de Operaciones. Es un **snapshot y sólo en la transición**: lo que edites después no llega. El botón «Revisar cambios de operación» (editor, junto al selector de estado) y el icono de diff de `FileDetalle.vue` abren un panel que compara y aplica **sólo lo que apruebes, campo a campo**: `POST .../operacion/plan` y `POST .../operacion/aplicar`. Detalle completo en `docs/Operacion.md` §3.5 y §7.1. **Ojo:** para llegar a `confirmado` hay que pasar el guard de `publicable` — ver §4.b.
- **Quién presta un servicio (hotel del pasajero, vuelo no incluido)** → `prestador*` en `CotizacionCotcomponente`. Sin cascada y sin default por día: los dos se retiraron. `resolverPrestador()` es un lector, **espejo en PHP y TS**. Filtros blandos: `opcionesTarifasFiltradas` (tarifa maestra) y `opcionesProveedoresTarifa` (proveedor) en `CotizacionEditorView.vue`. Ver §6.c. La fuente es `travel_tarifa.prestador_id` (y `comprador_id`), que vuelven a existir desde el 20/08/2026 — nacen vacíos y se llenan a mano en el tarifario.
- **Que al prestador se le nombre (o no) ante el cliente** → `CotizacionCotcomponente::$prestadorVisible`. **Espejo triple**: el normalizer, `construirInclusiones()` y `onPrestadorComponenteChange()` (que lo siembra). Ya **no** se deriva del `modo`; el flag de anonimato global sigue sin taparlo. Ver §6.c.
- **Quién presta un componente** → `CotizacionCotcomponente::$prestador*`, soft-link a `Proveedor` o escrito a mano. Se edita en el inspector del COMPONENTE, junto al comprador. Ver §6.c.
- **A quién se le encarga ejecutar la compra** → `compradorMaestroId` + `compradorNombreSnapshot` en `CotizacionCotcomponente`, soft-link al catálogo de proveedores. Cascada en `resolverComprador()`, **espejo en PHP y TS**. Llega a Operación por `BibliaSnapshotService`. Ver §6.c — **siempre un `Proveedor`, nunca una persona**, y **nunca** le pongas grupo público.
- **Que al proveedor se le nombre (o no) ante el cliente** → `$proveedorVisible` del componente, en OR con el global `Cotizacion::$proveedorOculto`. Lo aplica `CotizacionCotcomponenteProveedorPublicNormalizer`. Sonda: `var/probar-proveedor-componente.php`.
- **De dónde sale el nombre/logo del proveedor que ve el cliente** → `ProveedorVivoResolver`, resuelto contra el catálogo maestro AL SERVIR y precargado en lote desde `CotizacionPublicNormalizer::precargarProveedores()`. **No** es el snapshot: ése es sólo el respaldo si el maestro desaparece. Ver §6.c.
- **Enlazar una línea de inclusión con su componente** → `componenteId`, que emite `construirInclusiones()`. Para propuestas viejas: `app:cotizacion:backfill-componente-id`. Nunca reconstruir el vínculo con una clave natural en tiempo de render.
- **Aligerar `clasificacionFinanciera` / ordenar el store por capas** → está medido y decidido en `docs/Pendientes.md` («El JSON financiero pesa 10× lo que dice»); la forma del servicio, en `docs/NodeEnElStack.md` §8. **Fixtures antes que nada.**
- **TTL de caché del cliente** → `CACHE_TTL` en `pax/.../paxCotizacionStore.ts`.
- **Cómo se cargan los assets (dev/prod, puertos)** → `templates/util/app.html.twig`, `templates/pax/app.html.twig`.
- **Guardar el estado de una cotización antes de tocarla** → botón de cámara en `FileDetalle` → `GuardarHistoricoProcessor`. ⚠️ **No es clonar**: clonar crea la versión siguiente y hace perder las órdenes. Ver §6.j.
- **Cargar un padrón** → `app:cotizacion:importar-padron` / `PadronImportador` (§6.r). Idempotente; sincroniza pertenencias pero **no borra personas**; el ensayo escribe y deshace.
- **Cambiar las columnas del padrón (plantilla e importación)** → `PadronFormato`, una sola definición para generar y para leer. La plantilla se genera al vuelo desde los enums. Ver §6.n.
- **Quién ve a quién en un padrón** → `PasajeroTipoEnum` (dos ejes: `alcance()` y `esExpuesto()`) + `AlcanceDelPadron`. ⚠️ Los invitados son gratuidades de la agencia y **desaparecen para todos**. Ver §6.p.
- **Agrupar pasajeros (salón, grupo, habitación, reserva aérea)** → `CotizacionFileGrupo` + `CotizacionPasajeroGrupo` (§6.m). ⚠️ Ejes cruzados, no un árbol; y el `esJefe` va en la pertenencia.
- **El DNI o el pasaporte de un pasajero, con su vencimiento** → `CotizacionPasajeroIdentificacion`, una fila por documento (§6.l). ⚠️ Sin fecha es «sin comprobar», nunca «vigente».
- **Adjuntar un archivo a un expediente** → `CotizacionFilearchivo` (antes `…Filedocumento`, ver §6.k). ⚠️ No confundir con `CotizacionFilepasajero::$tipodocumento`, que sí es identidad.
- **Crear un servicio que no está en el catálogo** → botón «Manual» → `agregarComponente(id, true)` → `esManual`. Aporta su propio `nombreInternoSnapshot` (interno) y `nombreSnapshot` (público). Ver §6.h.
- **Que un componente sin maestro se pueda nombrar y tipar** → `isComponenteSoloItems()` y `getNombreMaestroRef()` en `CotizacionEditorView.vue`, y `onTipoManualChange()` en el store. Ver §6.h — y ojo con lo que la cadena sigue exigiendo (tarifa, prestador, nombre).
- **Saber qué se lleva la papelera de un párrafo** → el pie de la tarjeta en el Constructor de Storytelling, alimentado por `store.idSegmentoDeComponente()`. Ver §6.i.
- **Quién ve un detalle del componente** → banderas de `AudienciaDetalleEnum` en `detallesOperativos`; se leen con `detallesPara()` / `textosPara()` y se filtran para el cliente en `getDetallesParaCliente()`. Ver §6.g, y **toca también el espejo** `AudienciaDetalle` en `cotizacionEditorModel.ts`.

### Checklist al tocar la lógica de alternativas/inclusiones
1. Editar `resumenFinanciero` (interno) y/o `expurgarParaCliente` (cliente).
2. Actualizar tipos en `cotizacionEditorModel.ts` **y** `paxCotizacionModel.ts` si cambian campos.
3. Ajustar vistas: `ResumenClasificacion.vue` / `CotizacionEditorView.vue` (interno) y `PaxCotizacionGuiaView.vue` (cliente).
4. `npx vue-tsc --noEmit` en `util/` y `pax/`.
5. **Re-guardar** una cotización de prueba en el editor y recargar pax (limpiar localStorage del pax si hace falta).

---

- **Dónde recoge y dónde deja un servicio (la línea `fa-route` del editor)** → `src/Cotizacion/Service/CotizacionPuntosDelServicio.php` (la regla) + `puntosDeServicio()` en `CotizacionEditorView.vue` (la redacción). Los PUNTOS se editan en el maestro: `TravelSegmento` → «Dónde empieza y dónde termina». Ver §6.e y `docs/Travel.md` §11 quater.

## 7. Gotcha de framework: los normalizers públicos y Symfony 7

Los normalizers públicos de `src/Cotizacion/Serializer/` implementaban
`CacheableSupportsMethodInterface`. **Symfony 7 la eliminó** (estaba deprecada desde 6.3).
Hoy son tres: `CotizacionPublicNormalizer`,
`CotizacionCotcomponenteProveedorPublicNormalizer` y
`CotizacionCotcomponentePrestadorPublicNormalizer` — el que trabajaba sobre la tarifa se
retiró con las columnas que vigilaba (§6.c).

El reemplazo es `getSupportedTypes(?string $format): array`, que los tres ya tenían. Al
subir a 7.4 sólo hubo que quitar la interfaz del `implements`, su `use`, y el método
`hasCacheableSupportsMethod()` que delegaba al normalizer decorado.

**Qué NO cambia:** `getSupportedTypes()` devuelve `['*' => false]` en los tres. Ese `'*'`
es deliberado y hay que dejarlo así — es la misma razón que ya explica §5 sobre
`supportsNormalization()`: estos servicios decoran
`api_platform.jsonld.normalizer.item`, el normalizer general de **toda** la API. Si el
gate de entrada se restringiera a los tipos de cotización, se rompería la serialización
del resto de entidades del sistema. El `false` significa «no cachear la decisión», que
es lo que hacía el método eliminado.

**De regalo:** la baseline de PHPStan tenía tres entradas ocultando que
`supportsNormalization()` se invocaba con 3 parámetros cuando la interfaz de Symfony 6
declaraba 2. En Symfony 7 el `$context` es parte oficial de la firma, así que el error
desapareció solo y esas entradas se eliminaron.
