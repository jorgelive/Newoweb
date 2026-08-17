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
- **Quién presta un servicio (hotel del pasajero, vuelo no incluido)** → `prestador*` en `CotizacionCotcomponente`. Sin cascada y sin default por día: los dos se retiraron. `resolverPrestador()` es un lector, **espejo en PHP y TS**. Filtros blandos: `opcionesTarifasFiltradas` (tarifa maestra) y `opcionesProveedoresTarifa` (proveedor) en `CotizacionEditorView.vue`. Ver §6.c — y ojo: el filtro de tarifas necesita `travel_tarifa.proveedor_id`, hoy casi vacío.
- **Que al prestador se le nombre (o no) ante el cliente** → `CotizacionCotcomponente::$prestadorVisible`. **Espejo triple**: el normalizer, `construirInclusiones()` y `onPrestadorComponenteChange()` (que lo siembra). Ya **no** se deriva del `modo`; el flag de anonimato global sigue sin taparlo. Ver §6.c.
- **Quién presta un componente** → `CotizacionCotcomponente::$prestador*`, soft-link a `Proveedor` o escrito a mano. Se edita en el inspector del COMPONENTE, junto al comprador. Ver §6.c.
- **A quién se le encarga ejecutar la compra** → `compradorMaestroId` + `compradorNombreSnapshot` en `CotizacionCotcomponente`, soft-link al catálogo de proveedores. Cascada en `resolverComprador()`, **espejo en PHP y TS**. Llega a Operación por `BibliaSnapshotService`. Ver §6.c — **siempre un `Proveedor`, nunca una persona**, y **nunca** le pongas grupo público.
- **Que al proveedor se le nombre (o no) ante el cliente** → `$proveedorVisible` del componente, en OR con el global `Cotizacion::$proveedorOculto`. Lo aplica `CotizacionCotcomponenteProveedorPublicNormalizer`. Sonda: `var/probar-proveedor-componente.php`.
- **De dónde sale el nombre/logo del proveedor que ve el cliente** → `ProveedorVivoResolver`, resuelto contra el catálogo maestro AL SERVIR y precargado en lote desde `CotizacionPublicNormalizer::precargarProveedores()`. **No** es el snapshot: ése es sólo el respaldo si el maestro desaparece. Ver §6.c.
- **Enlazar una línea de inclusión con su componente** → `componenteId`, que emite `construirInclusiones()`. Para propuestas viejas: `app:cotizacion:backfill-componente-id`. Nunca reconstruir el vínculo con una clave natural en tiempo de render.
- **TTL de caché del cliente** → `CACHE_TTL` en `pax/.../paxCotizacionStore.ts`.
- **Cómo se cargan los assets (dev/prod, puertos)** → `templates/util/app.html.twig`, `templates/pax/app.html.twig`.

### Checklist al tocar la lógica de alternativas/inclusiones
1. Editar `resumenFinanciero` (interno) y/o `expurgarParaCliente` (cliente).
2. Actualizar tipos en `cotizacionEditorModel.ts` **y** `paxCotizacionModel.ts` si cambian campos.
3. Ajustar vistas: `ResumenClasificacion.vue` / `CotizacionEditorView.vue` (interno) y `PaxCotizacionGuiaView.vue` (cliente).
4. `npx vue-tsc --noEmit` en `util/` y `pax/`.
5. **Re-guardar** una cotización de prueba en el editor y recargar pax (limpiar localStorage del pax si hace falta).

---

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
