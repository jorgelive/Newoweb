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

**Ver el BUILD sin cambiar el entorno: `?mode=build`.** `PaxAppController` (y su gemelo de `util`)
aceptan ese parámetro y sirven el bundle compilado aunque `APP_ENV=dev`. Es la puerta prevista para
comprobar lo que de verdad se va a desplegar.

⚠️ **Y es la única forma de mirar la app desde un navegador que aísle orígenes.** El panel de
navegador del agente bloquea los módulos del dev server —viven en otro puerto—, así que la página
carga en blanco con `ERR_BLOCKED_BY_CLIENT` y ningún error que hable de eso. Con `?mode=build` todo
sale del mismo origen y funciona. Costó media hora descubrirlo la primera vez.

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
Cada nodo guarda **copias congeladas** (`*Snapshot`) tomadas de los **maestros** del catálogo al momento de agregarlo. Ej: `tituloSnapshot`, `nombreInternoSnapshot`, `montoCosto`, `modalidadSnapshot`. Editar el maestro luego **no** cambia lo ya cotizado (salvo re-sincronización explícita).

- El **nombre interno** de un componente **no** está en su snapshot: viene del **maestro** (`componenteMaestroId` → `catalogos.allComponentes[].nombreInterno`). Siempre existe.
- `tituloSnapshot` del componente = **título público** (opcional; para el cliente). Un componente sin `tituloSnapshot` es un "contenedor solo-ítems" (`isComponenteSoloItems`).

### Bajar de nuevo el texto del maestro a un expediente abierto (30/08/2026)

«Salvo re-sincronización explícita» era, hasta hoy, una promesa sin comando detrás para el
**texto del cliente**. `app:cotizacion:refrescar-nombres-maestros` refresca el `nombreInterno`
—lo que ve el operador—, y no había nada que bajara `tituloSnapshot` ni `contenidoSnapshot`. La
única vía era quitar el segmento del expediente y volverlo a meter.

`app:cotizacion:refrescar-textos-maestros --cotizacion=<uuid>` lo hace, con `--dry-run` y con
`--solo=<slugs>` para acotar.

⚠️ **Copia los SIETE idiomas, no sólo el español.** El snapshot es lo que `pax` lee en el idioma
del huésped; dejarlo con un español nuevo y seis traducciones viejas sería peor que no tocarlo —el
huésped en inglés seguiría viendo el texto anterior y nadie lo notaría, porque nadie revisa la
versión que no habla.

⚠️ **Un parámetro `uuid` sin tipo devuelve una consulta VACÍA, no un error.** El id de las
entidades es `#[ORM\Column(type: 'uuid')]` (bridge de symfony/uid), y `setParameter('cot', $uuid)`
sin el tercer argumento `'uuid'` no convierte el objeto: la consulta sale sin filas. El síntoma fue
«0 segmentos» y salida limpia, indistinguible de «no había nada que refrescar». Por eso el comando
ahora **avisa si el expediente no tiene segmentos** en vez de informar cero: un cero legítimo y un
cero por consulta rota tienen que decirse distinto.

⚠️ **Y el límite que no tiene arreglo técnico: un retoque a mano y un texto viejo son
indistinguibles.** Si el operador editó la descripción dentro del expediente, el comando ve
exactamente lo mismo que ante un snapshot desactualizado: «difiere del maestro». No hay heurística
que los separe, así que el comando **no adivina**: enseña las dos versiones y se corre primero en
ensayo. Fingir una heurística aquí sería peor que no tenerla, porque pisaría trabajo humano sin
avisar.

### El nombre de un SEGMENTO: un solo campo, y por qué no se contamina (2026-08-17)

A diferencia del servicio —que tiene tres nombres (código, operativo, comercial)—, un
`CotizacionSegmento` tiene **uno solo**: `tituloSnapshot`. No tiene nombre interno propio
separado. El motivo es semántico: un segmento es **narrativa para el pasajero** (storytelling),
no algo que se le pida a un proveedor. No tiene un «lado interno» distinto del público.

**Copia el TÍTULO del maestro, nunca el `nombreInterno`.** El maestro `TravelSegmento` tiene
`nombreInterno` (código, `VIS-VALLE_VIP…-CHINCHERO`) y `titulo` (comercial, «Visita a
Chinchero»). El snapshot toma `getTituloSafe(seg)` = el **título**. Por eso NO está
contaminado con el código: éste se queda en el catálogo, igual que el slug del itinerario.

El título se copia a `tituloSnapshot` en **cuatro** puntos del store, que son todas las formas
de meter un segmento en el servicio:

| Función | Cuándo |
|---|---|
| `aplicarPlantilla()` | al aplicar una plantilla, cada segmento que trae |
| `agregarSegmentoIndividual()` | añadir un segmento suelto (sin plantilla) |
| `procesarInsercionSegmento()` (replace) | sustituir un segmento por otro |
| `procesarInsercionSegmento()` (insert/append) | insertar o encolar un segmento |

**Quién lo lee:** el `tituloSnapshot` del segmento está en `pax_cotizacion:read` —el cliente
SÍ lo ve— y lo pinta la app pax (`PaxCotizacionGuiaView`, cuatro usos). En el editor, la lista
del itinerario lo usaba como nombre del servicio **sólo cuando el servicio no tenía plantilla**
(sin plantilla → se listan los segmentos; con plantilla → manda el nombre del servicio).

**Conclusión: el segmento NO necesita el tratamiento del servicio.** Su nombre ya es el título
comercial legible que ve el cliente, en un solo eje. La dualidad interno/operativo del servicio
—que obligó a separar el nombre interno del título— aquí no existe ni hace falta. El código del
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
  sólo pinta `tituloSnapshot` del servicio cuando `totalSegmentosServicio > 1`
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
  `catalogos.poolSegmentos` (helper `segmentoUnicoMaestro`), con fallback al `tituloSnapshot['es']`
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
| **Operativo** (interno + proveedor) | `nombreInternoSnapshot` (cotservicio) | **uno (es)** | ✅ sí (cotizador) | no |
| **Comercial** | `tituloSnapshot` (cotservicio) | i18n (7) | ✅ sí | ✅ sí |

Cambios que lo implementaron:

- **`TravelItinerario.slug`** (migración): el código (`HD-COMBINADA-POOL-AM`) sale de
  `nombreInterno` a un campo propio, copiado. En EasyAdmin el slug va primero y `nombreInterno`
  —ahora un nombre operativo de verdad— a continuación. `nombreInterno` se queda con el código
  hasta que se edite plantilla por plantilla.
- **`nombreInternoSnapshot` = nombre operativo, no título.** Nace del `nombreInterno` del servicio; al
  aplicar plantilla se transforma en el `nombreInterno` de la plantilla (más específica). En un
  solo idioma —al proveedor se le habla en uno— vía `nombreOperativoComoI18n`. Ya no se copia
  del título ni el título se copia de él.
- **Editable en el cotizador**: campo «Nombre operativo» propio, separado del «Nombre Público».
  Antes salía bloqueado con candado.

⚠️ **`nombreInternoSnapshot` sigue con `#[AutoTranslate]` en la entidad**, pero se llena con un solo
idioma a propósito. Si se quiere prohibir la traducción de raíz, hay que quitarle el atributo
—decisión pendiente, no bloquea—.

La Biblia (`contextoServicio`) lee `nombreInternoSnapshot`, así que el operador ve ahora el nombre
operativo, no el título comercial. Las cotizaciones existentes conservan su `nombreInternoSnapshot`
viejo (título) hasta que se reediten: sólo cambia el comportamiento de las nuevas.

#### ⚠️ El operativo se lee en `es` FIJO, nunca en el idioma de edición (2026-08-30)

Es la consecuencia directa de la tabla de arriba y se olvidaba sola: **un campo de un solo idioma
pedido en otro idioma no da error, da cadena vacía.** `getI18nText()` busca la entrada por
`language` y devuelve `''` si no está — no cae al español, y hace bien: para un campo traducido,
inventar un idioma sería peor.

Tres sitios lo pedían con `store.cotizacion.idiomaEdicion`, y los tres se vaciaban en cuanto la
cotización no se editaba en español:

| Dónde | Qué se veía en una cotización en inglés |
|---|---|
| Cabecera del Constructor de Storytelling | «Servicio:» y nada detrás |
| Lista de reordenar servicios | todas las fichas con «Sin nombre» |
| `etiquetaDeParrafo()` (desplegables que COLOCAN un párrafo) | caía al título — justo el dato que ese helper documenta no querer usar |

**La regla:** si el campo no lleva `#[AutoTranslate]`, se lee con `'es'` escrito a mano. En este
mismo archivo ya había cuatro sitios haciéndolo bien (el inspector del servicio, su input); lo que
faltaba era que fuese regla y no costumbre.

#### El nombre operativo del PÁRRAFO, en la ficha del storytelling (2026-08-30)

La cabecera de cada párrafo enseñaba sólo `tituloSnapshot`, y con eso no se sabe qué tramo es: el
título es prosa de cliente, y dos párrafos seguidos se titulan «Check-in» y «Check-out». El
operativo —el mismo que el pool de la izquierda enseña sobre cada tarjeta— va ahora encima, en la
línea de arriba.

No es cosmético: `CotizacionSegmento::$nombreInternoSnapshot` es el `nombreSegmento` que
`BibliaSnapshotService` manda a tráfico. Verlo en la ficha es ver lo que le va a llegar al que
opera el servicio.

**De sólo lectura, y eso es deliberado.** `actualizarTextosSegmentos()` lo reescribe desde el
maestro en cada «Actualizar», junto con título, contenido, notas e imágenes. Un input aquí
prometería una edición que el siguiente refresco se lleva sin decir nada — se cambia en el
`TravelSegmento`. Cuando está vacío (párrafo escrito a mano, sin maestro) se enseña «Sin nombre
operativo» en gris: esa fila llegará a La Biblia sin nombre de tramo, y eso también conviene verlo
antes y no en la orden.

### ⚠️ Los tres nombres de un SERVICIO, y la asimetría interno/público (2026-08-17)

Un `CotizacionCotservicio` tiene **tres** campos de nombre, y no dan lo mismo:

| Campo | Qué es | Grupo | Editable |
|---|---|---|---|
| `nombreInternoSnapshot` | Nombre del servicio del catálogo. **INTERNO** (Biblia, voucher) | no pax | ❌ bloqueado (candado) |
| `itinerarioNombreSnapshot` | Nombre de la **plantilla** (`TravelItinerario`) aplicada. Interno | no pax | ❌ sólo etiqueta |
| `tituloSnapshot` | **TÍTULO público** (lo ve el cliente) | pax | ✅ sí |

**La plantilla es más específica que el servicio, y por eso debe ganar.** Un servicio genérico
«Alojamiento» tomado de la plantilla «Alojamiento en Lima» debería llamarse por la plantilla,
no por el genérico. Cómo está hoy:

| | Título PÚBLICO (`tituloSnapshot`) | Nombre INTERNO (`nombreInternoSnapshot`) |
|---|---|---|
| Override de la plantilla al aplicarla | ✅ **sí** (`aplicarPlantilla`, store: se copia el título del itinerario) | ❌ **no** — queda el genérico del servicio |
| Fallback al servicio si no hay plantilla | ✅ sí (`tituloSnapshot \|\| nombreInternoSnapshot`) | (es el propio servicio) |
| Editable en la cotización | ✅ sí (input) | ❌ **no** — sale con candado (`servicioMaestroId` + componentes ⇒ bloqueado) |

**El hueco, confirmado (lo reportó Jorge, 2026-08-17):**

1. **El nombre interno NO recibe el override de la plantilla.** El público sí (`tituloSnapshot`
   se sobrescribe con el título del itinerario al aplicarlo); el interno se queda con el nombre
   genérico del servicio. Y ese interno es el que usa **La Biblia** (`contextoServicio` =
   `getNombreSnapshot()`) y el que alimenta el voucher — así que el operador ve «Alojamiento» y
   no «Alojamiento en Lima».
2. **El nombre interno no es editable** a nivel servicio: cuando el servicio viene del maestro,
   el editor lo pinta bloqueado. Sólo el componente tiene su nombre editable, no el servicio.

**Corrección propuesta (no aplicada — cambia el comportamiento de nombres):**

- El nombre interno efectivo debería resolverse con la misma prioridad que el público:
  `plantilla (itinerarioNombreSnapshot) → servicio (nombreInternoSnapshot)`. Un helper único que las dos
  caras usen, para que no vuelvan a divergir.
- Hacer editable el nombre interno del servicio en la cotización, con la plantilla como valor
  sembrado y el servicio como fallback — igual que ya funciona el título público.

### Una plantilla sólo entra en un servicio VACÍO

`puedeAplicarPlantilla = !servicioActivo?.cotsegmentos?.length`
(`CotizacionEditorView.vue`). Con un solo párrafo en el panel, «Aplicar» queda deshabilitado —
aplicar encima duplicaría lo que ya está.

La consecuencia: si el servicio se armó a mano desde el pool, o la plantilla se creó después, el
servicio se queda en «Sin plantilla» para siempre, porque `itinerarioNombreInternoSnapshot` sólo
lo escribe `aplicarPlantilla()`. Para ponérsela hay que vaciar los párrafos y volver a aplicarla,
perdiendo los retoques de texto hechos a mano.

⚠️ **Esto NO explica un desplegable que no lista nada.** Si las plantillas no aparecen siquiera,
el botón gris es un síntoma aparte y se está mirando el sitio equivocado: ver la trampa del
`z-index` de `SearchableSelect` en `docs/UI_Componentes_Compartidos.md`.

Dónde vive cada pieza: se aplica la plantilla en `cotizacionEditorStore.aplicarPlantilla()`
(copia título → `itinerarioNombreSnapshot` y `tituloSnapshot`, NO `nombreInternoSnapshot`); el
nombre interno lo lee `BibliaSnapshotService::calcularValores()` (`contextoServicio`).

### Textos i18n
Los textos multi-idioma son `I18nContent[]` = `[{ language, content }]`.
- util: `store.getI18nText(arr, lang)` (con fallback).
- pax: `store.traducir(arr)` (fallback idioma actual → en → es → primero).

> ⚠️ **`dominio/api.d.ts` miente en los i18n.** El backend serializa objetos
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

## 2.b `tituloSnapshot` y `nombreInternoSnapshot`: el vocabulario (27/08/2026)

Todo el árbol usa **dos nombres y sólo dos**, con el mismo significado en todas partes:

```
Cotizacion
└── CotizacionCotservicio   el DÍA
        nombreInternoSnapshot  → INTERNO   «Full Day HUAYNA: MAPI OLLA CUZ (bimodal)»
        tituloSnapshot         → PÚBLICO   «Excursión a Huayna Picchu de 1 día»
    ├── CotizacionSegmento   el TRAMO
    │       tituloSnapshot         → PÚBLICO   «Vuelo desde la ciudad de Lima a Cusco»
    └── CotizacionCotcomponente   lo que se COMPRA
            nombreInternoSnapshot  → INTERNO   «Traslado a la Huacachina»
            tituloSnapshot         → PÚBLICO   «Ticket aereo»
```

Y los maestros, con el mismo par: `nombreInterno` + `titulo`, en **`TravelComponente`**,
`TravelSegmento`, `TravelTarifa` y `TravelServicio`.

### Cómo distinguirlos sin memorizar nada

**Si está traducido, es para el cliente. Si es un `string` pelado, es para nosotros.** Está en la
firma:

| campo | tipo | traducido |
|---|---|---|
| `tituloSnapshot` / `titulo` | `json` + `#[AutoTranslate]` | **7 idiomas** |
| `nombreInternoSnapshot` / `nombreInterno` | `string` o `json` sin atributo | sólo español |

De ahí sale, sin más argumento, por qué el interno manda en La Biblia: el cuadro de tráfico lo
leemos nosotros, no el huésped. Y por qué el título llega a `pax`: para eso está en siete idiomas.

⚠️ **Escribir un `tituloSnapshot` por SQL lo deja sólo en español**, porque `AutoTranslate` cuelga
de `prePersist`/`preUpdate`. En los internos da igual.

### Quién decide si el cliente ve al prestador (27/08/2026)

**Un solo interruptor, y vive en el componente.**

```
TravelOrganizacion.visibleParaCliente   ¿se le puede nombrar siquiera?   → SEMILLA
        │  se copia al asignar el prestador, y sólo entonces
        ▼
CotizacionCotcomponente.prestadorVisible   la decisión de ESTA línea     → MANDA
```

La semilla cae en dos momentos: al añadir el componente del catálogo y al asignar o cambiar el
prestador (`sembrarPrestadorDesdeMaestro` / `onPrestadorComponenteChange`). Después es snapshot y
se edita con el checkbox **«Nombrarlo al cliente»**.

⚠️ **Marcar la empresa en el catálogo NO cambia las cotizaciones ya hechas.** La semilla ya cayó.
Pasó el 27/08/2026: se marcaron dos hoteles como visibles y 9 componentes siguieron ocultos porque
se habían armado antes. Se corrigieron con `Version20260828020000`, que **sólo enciende, nunca
apaga** —un componente ocultado a mano se queda oculto aunque el maestro diga que sí—. Si vuelve a
pasar, la salida es reasignar el prestador o repetir esa corrección; **no se convirtió en herencia
viva a propósito**, porque la cotización manda sobre el catálogo.

⚠️ **Encender la marca pide confirmación; apagarla no.** Revelar quién opera un servicio le dice al
cliente a quién podría contratar directamente el año que viene, así que la fricción va sólo en esa
dirección (`alternarNombrarPrestador`). Ponerla en ambas enseñaría a darle a «Aceptar» sin leer, y
ahí se pierde la protección entera.

⚠️ **El flag global `Cotizacion::$proveedorOculto` se retiró.** Había dos mecanismos para lo mismo
y apuntaban en direcciones opuestas: el global por defecto decía «no ocultes» y el del componente
«no muestres», y como el restrictivo gana con un OR, el global no podía usarse nunca en la
dirección útil — sólo servía para ocultar lo que ya estaba oculto. Estuvo a `false` en **0 de 11**
cotizaciones desde que existe.

⚠️ **Los ids del prestador ya no se publican en `pax_cotizacion:read`.** El normalizer que los
tapaba decora sólo el normalizer de **JSON-LD**, así que pidiendo el mismo enlace público con
`Accept: application/json` salían los `prestadorMaestroId` de todos los componentes, incluidos los
marcados como ocultos. `pax` no los lee en ninguna parte, así que no publicarlos cierra el agujero
en cualquier formato — más barato y más seguro que un segundo decorador.

#### A quién se nombra y a quién no (criterio, 27/08/2026)

No es una preferencia estética: **se nombra a quien no puedes perder, y se calla a quien sí.**

| Se nombra | Por qué |
|---|---|
| Hoteles | Es el dato que más pesa antes de comprar, y el cliente lo va a ver igual al llegar |
| Ferroviarias (PeruRail, IncaRail) | Son las dos únicas opciones a Machu Picchu: ocultarlas no protege nada |

| Se calla | Por qué |
|---|---|
| Operador, guía, transportista | Son a quienes el cliente podría contratar directo la próxima vez |

Por eso el default del maestro es **oculto** y habilitar una empresa es una decisión consciente,
empresa por empresa — y por eso encender la marca en un componente **pide confirmación** mientras
que apagarla no.

### Maestro → snapshot, campo por campo

Qué copia cada nivel al añadirlo desde el catálogo. Lo hace el **editor**; PHP no compone ninguno.

| Nivel | Maestro | `tituloSnapshot` ← | `nombreInternoSnapshot` ← | Otros |
|---|---|---|---|---|
| **Servicio** | `TravelServicio` o `TravelItinerario` | `.titulo` | **`.nombreInterno`** | `itinerarioNombreInternoSnapshot` ← **`.nombreInterno`** de la plantilla |
| **Itinerario** | — | *no tiene snapshot propio: se aplana en el servicio* | | `itinerarioMaestroId` |
| **Segmento** | `TravelSegmento` | `.titulo` | **`.nombreInterno`** | `contenido`, `notas`, `imagenes` |
| **Componente** | `TravelComponente` | `.titulo` | **`.nombreInterno`** | — |
| **Tarifa** | `TravelTarifa` | `.titulo` | **`.nombreInterno`** | `nombreParaProveedor`, modalidad, categoría, procedencia, rol, edades, capacidades |

Al aplicar una plantilla, **ésta pisa al servicio**: es más específica.

✅ **Los cinco niveles copian su `nombreInterno`** desde el 27/08/2026. Segmento y componente eran
los dos que faltaban: se resolvían **en vivo** contra el catálogo desde el navegador, en el mismo
lote que las etiquetas de lugar — un lote cuyo `catch` lo vacía entero porque «los badges son
decoración». Cuando esa petición fallaba, la ficha caía al respaldo y enseñaba el nombre del
itinerario como si fuera el servicio: **no un hueco, otro nombre plausible.**

Ahora la ruta es una sola —maestro → snapshot al añadirlo → La Biblia → Orden— y la deriva del
catálogo la denuncia la reconciliación en vez de aplicarse a escondidas. `resolverNombreComponente()`
ya no recibe consultas al maestro, y su test lo fija con un EntityManager que **estalla si alguien
lo usa**: sin eso, reintroducir la consulta pasaría los tests sin despeinarse.

⚠️ En el componente, `nombreInternoSnapshot` es **el único de los cuatro que escribe una persona**,
no una copia. Nace `null` y sólo existe si el operador lo teclea. Es lo que le da su precedencia:
es una decisión sobre ESE expediente, no una foto del catálogo.

#### El rastro de la plantilla congela el nombre, no el título (27/08/2026)

`CotizacionCotservicio::$itinerarioNombreInternoSnapshot` guarda **cómo se llamaba la plantilla el
día que se aplicó**. Existe aparte de `$nombreInternoSnapshot` porque aquél se edita libremente y
éste no: su única función es decir de dónde salió el servicio.

Guardaba el **título** y se llamaba `itinerarioNombreSnapshot`, que no aclaraba cuál de los dos
nombres era. Ahora congela el **operativo**, que es el que sirve: el campo **no está en
`pax_cotizacion:read`** —el cliente no lo ve nunca— y quien lo lee busca la plantilla en el
catálogo.

```
antes   «Excursión de día completo a Paracas y la Huacachina»   ← título, para el cliente
ahora   «Full Day Paracas y Huacachina»                          ← operativo, para nosotros
```

⚠️ **Y se le retiró `#[AutoTranslate]`, junto con `$nombreInternoSnapshot`.** Los dos son internos
y estaban traduciéndose a siete idiomas —`itinerarioNombre` en el 100% de las filas,
`nombreInterno` en 49 de 79—, contradiciendo el docblock del helper que los rellena, que ya decía
«como es operativo va en un solo idioma». Es la regla de arriba aplicada: **si está traducido es
para el cliente; si no, es para nosotros.** Los idiomas ya guardados se dejan: son inertes y
borrarlos sería arriesgar por pulcritud.

⚠️ Gotcha de la migración: `itinerario_maestro_id` es `varchar(36)` **con guiones** y
`travel_itinerario.id` es `binary(16)`. Comparados en crudo **no casa ninguno y no da error** —da
cero filas—. Es la misma trampa que documenta `App\Api\Filter\UuidRelacionFilter`, y aquí volvió
a morder: la primera consulta dijo «0 maestros vivos» cuando eran 33.

#### El campo se edita en TODOS los componentes (27/08/2026)

Estuvo limitado a los manuales —«los de catálogo ya tienen el del maestro»—, y era cierto pero
insuficiente: **no había forma de ajustar el nombre operativo en un expediente concreto**. La única
salida era renombrar el maestro, que cambia el catálogo entero por un caso puntual y encima **no
llega solo a La Biblia**: queda como divergencia y hay que aprobarlo por reconciliación
(`BibliaReconciliacionService::ETIQUETAS['nombreComponente']`).

Ahora: **vacío = manda el maestro; escrito = manda lo escrito.** El `placeholder` del campo enseña
el nombre del catálogo para que se vea qué saldrá si se deja en blanco — de `placeholder` y no de
`value`, porque rellenarlo convertiría una herencia viva en una copia congelada.

### De dónde sale cada uno

**Lo compone el EDITOR, y PHP no lo toca nunca.** No hay ningún `setTituloSnapshot()` llamado desde
el servidor: el valor viaja hecho en el payload.

```
TravelComponente.titulo        (maestro · PÚBLICO · 7 idiomas)
        │  getTituloSafe() + copia profunda, al añadir del catálogo
        ▼
CotizacionCotcomponente.tituloSnapshot     ← y aquí se queda congelado
```

| Qué añades | Fuente del `tituloSnapshot` |
|---|---|
| Componente del catálogo | `TravelComponente.titulo` |
| Segmento del catálogo | `TravelSegmento.titulo` |
| Servicio nuevo | literal «Nuevo Servicio» |
| Componente manual | `[]` — lo escribe el operador |

⚠️ **Copia `titulo`, nunca `nombreInterno`.** Por eso el nombre operativo del maestro no entra en la
cotización y La Biblia tiene que ir a buscarlo al catálogo
(`BibliaSnapshotService::resolverNombreComponente()`).

⚠️ **Es copia PROFUNDA**, y ahí está el «snapshot»: desengancha el texto para que renombrar el
catálogo no reescriba una cotización enviada. Congelar y envejecer son la misma propiedad vista de
los dos lados — por eso un vuelo podía seguir diciendo «Ticket aereo» con el maestro ya renombrado.

### Quién lee cada uno

| Campo | La Biblia / Órdenes | Editor (`util`) | Huésped (`pax`) |
|---|---|---|---|
| **Cotservicio**`.nombreInternoSnapshot` | sí → se congela como `contextoServicio` | sí | no |
| **Cotservicio**`.tituloSnapshot` | no | sí | **sí** |
| **Segmento**`.tituloSnapshot` | **no** (ver aviso) | sí | sí |
| **Cotcomponente**`.tituloSnapshot` | sólo como último recurso del nombre | sí | sí |
| **Cotcomponente**`.nombreInternoSnapshot` | **sí, y manda sobre todo** | sí | no |

⚠️ **El nombre de segmento que ve el tráfico NO sale del snapshot.** La Biblia lo resuelve en vivo
contra el maestro (`travel_segmento.nombre_interno`, vía `OperacionServicio::getSegmentoUnicoMaestroId()`)
porque quiere el **operativo**, mientras el snapshot guarda el **público**. Dos textos distintos
para la misma cosa, a propósito: «Transporte Aeropuerto Cusco - Hotel Cusco» frente a «Transporte
desde el Aeropuerto de Cusco al hotel en Cusco».

### El contenido del JSON también se migró

`snapshotItems[].tituloSnapshot` era el último sitio con el nombre viejo. **No es una columna: es
una clave dentro de un `json`**, así que no bastaba un `ALTER TABLE` — hubo que reescribir el
contenido de 62 componentes y 184 ítems (`Version20260827180000`, con la transformación en PHP
porque en SQL serían tres funciones anidadas por elemento que nadie va a saber releer).

⚠️ Fue en la MISMA migración que el código que lo lee. Separarlos habría dejado los 184 ítems sin
nombre en pantalla entre un paso y otro — y sin error: un hueco, que es peor de detectar.

## 2.c El chequeo de coherencia (27/08/2026)

`app:cotizacion:revisar-coherencia` — sin opciones sólo mira; con `--reparar` arregla.

### Por qué hace falta

**En este dominio «no aparece» es un estado legítimo.** Un componente puede no tener proveedor, ni
habitación, ni foto, y la pantalla se ve igual de bien. Eso hace que un fallo real y un caso vacío
correcto sean **indistinguibles a ojo**, y por eso los cuatro que se encontraron ese día llevaban
meses ahí:

| Estaba bien | Faltaba | Se veía como |
|---|---|---|
| el nombre en el maestro | copiarlo al snapshot | otro nombre plausible |
| el `id` en la entidad | el grupo de serialización | una tarjeta que no sale |
| el nombre de la habitación | su título público | «no tiene habitación» |
| el id del servicio del prestador | su nombre congelado | «hotel 4 estrellas» a secas |

Ninguno daba error. Lo que los delata no es un síntoma, es la **incoherencia interna**: *un id
puesto con su nombre vacío es una combinación que ninguna acción del editor produce.*

### La regla que separa reparar de avisar

**Se repara lo que tiene una sola respuesta posible.** Si el id apunta a un maestro que existe y el
nombre está vacío, el nombre es el del maestro. No hay segunda lectura.

**Se avisa de lo que es una decisión.** Que un prestador esté oculto teniendo permiso el maestro
puede ser deliberado; que una tarifa no tenga clase puede ser que aún no toque. Un script que
«arregla» decisiones ajenas hace más daño que el hueco que rellena.

⚠️ **Nunca toca documentos emitidos.** Las líneas de una Orden congelada se listan, no se corrigen:
dicen lo que se le mandó al proveedor. Se arreglan reemitiendo.

⚠️ El último reparable —`biblia-desincronizada`— va **después** a propósito, para arrastrar lo que
los anteriores acaban de arreglar. Y sincroniza **sin pasar por la reconciliación**: son campos
vacíos que se rellenan, no cambios que alguien deba aprobar.

⚠️ Todos los JOIN convierten los ids a mano: los `*_maestro_id` son `varchar(36)` **con guiones** y
los `id` de `travel_*` son `binary(16)`. En crudo dan **cero filas sin error** — y un chequeo que
nunca encuentra nada es peor que no tenerlo.

Devuelve código 1 si queda algo por mirar, para poder encadenarlo en un cron.

### El botón, en el editor

Debajo de «Revisar cambios de operación» hay **«Revisar coherencia de datos»**, acotado a la
cotización abierta. Dos endpoints y no un parámetro: `POST …/coherencia` mira y lo puede lanzar
quien sólo consulta; `POST …/coherencia/reparar` escribe y exige permiso de escritura. Un
`?reparar=1` acaba disparándose desde un enlace copiado.

⚠️ **Dos pasos deliberados**: el botón de reparar sólo aparece **después** de enseñar qué se va a
tocar. Reparar escribe en una cotización que puede estar ya enviada, y ver antes la lista es lo que
convierte eso en una decisión en vez de en una sorpresa.

⚠️ Y cuando no hay nada, **lo dice**. Un panel que sólo habla ante problemas deja la duda de si
llegó a mirar.

Está en el editor y no sólo en el cron porque **el momento en que estos huecos aparecen es al
cargar catálogo**, y quien lo carga está en esa pantalla.

### El cron nocturno (instalado el 27/08/2026)

Corre a las **03:20**, con `--reparar`, en el crontab de `www-data`:

```cron
20 3 * * * /usr/bin/php /var/www/openperu.pe/bin/console app:cotizacion:revisar-coherencia --reparar --env=prod >> /var/www/openperu.pe/var/log/cotizacion_coherencia.log 2>&1
```

⚠️ **El crontab NO está en el repo**: se edita a mano en el servidor, así que esta sección es el
único rastro que queda de que ese trabajo existe. Si se cambia allí, se cambia aquí.

⚠️ **Repara sin supervisión, y es seguro por diseño**: sólo se toca lo que tiene una única
respuesta posible. Lo que es una decisión queda como aviso en el log, para leerlo cuando se quiera.

⚠️ **Devuelve código 1 cuando quedan avisos.** Es a propósito —sirve para encadenarlo— pero si
algún día se montan alertas por salida de cron, este trabajo «fallará» los días que haya algo que
mirar. No es un error: es el aviso.

Comprobado ejecutándolo como `www-data` con el redirect incluido, que es donde fallan estas cosas:
el log es suyo (`www-data:www-data`, como los demás del cron) y el comando sale con 0.

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
- `tituloSnapshot`, `modo` (`incluido`/`no_incluido`/`opcional`/`cortesia`), `incluido`.
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
| ~~Componente `incluido` **sin tarifa estándar**~~ | **Ya no bloquea (04/09/2026)** — ver abajo |
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

#### 🔥 «Incluido sin estándar» ES el diseño, y la regla lo trataba de error (04/09/2026)

Un componente `incluido` **sin tarifa estándar** es exactamente **cómo se monta un opcional con
precio**: sin modo `opcional` en el componente, la ausencia de estándar es lo que manda todas sus
tarifas a «Opción N» dentro de Opcional, con su diferencia por persona. Es el
`ADICIONAL · C/U $ 100,32` de Coco Bongo que ve el cliente.

La advertencia se escribió después, pensando en el olvido —«marqué incluido y se me pasó la
estándar»— y **no puede distinguirlos: en los datos son idénticos**. Como cualquier advertencia
pone `publicable = false`, el resultado era que **tener un opcional impedía publicar la
propuesta**, y cada guardado pedía confirmación.

Se separa en dos cubos:

| | Cuenta para `publicable` | Se ve en el panel «Información» |
|---|---|---|
| `advertencias` | **sí** | sí |
| `informativas` | no | sí |

La de «incluido sin estándar» pasa a `informativas` y se reescribe: dice **lo que va a pasar** —el
cliente lo verá como opcional y podrá añadirlo— y deja la decisión, en vez de dar por hecho que es
un error. En pantalla las dos listas se pintan juntas: para quien lee, un aviso es un aviso; la
distinción es de la máquina.

#### La intención se declara MIRÁNDOLA, no con un campo nuevo (05/09/2026)

Quedaba pendiente «un modo `opcional` en el componente» para distinguir el olvido de la intención.
Se descartó: **no hace falta un dato nuevo, hace falta que el que hay se vea**. Un componente
`incluido` sin tarifa estándar ya dice lo que es; lo que fallaba es que había que **abrirlo y
deducirlo** de que ninguna tarifa estuviera marcada.

Ahora se dice en los dos sitios donde se trabaja:

```
Componentes Logísticos   [ ? 1 opcional ]        ← la cabecera del servicio
  ┌──────────────────────────────────────┐
  │ Noche en Coco Bongo                  │
  │ [ ? Opcional ]  [ 2 subgrupos · 88 ] │       ← la tarjeta del componente
```

⚠️ **Una sola definición**: `esOpcionalParaElCliente()` en `cotizacionEditorModel.ts`, que usan el
badge y la cuenta del servicio. `construirInclusiones()` la calcula inline porque necesita
`estandares`/`opcionables` para otras cosas — y lleva escrito que si una cambia, la otra también.
Dos definiciones acabarían diciendo cosas distintas del mismo componente.

🐛 **Y ya habían empezado a discrepar.** El panel emite la línea del componente sólo
`if (tieneNombre)`; el badge no miraba el título, así que un componente sin título público salía
marcado «Opcional» en el editor y **no aparecía por ningún lado** en la propuesta. Un badge que
promete algo que el cliente nunca ve es peor que no tenerlo: el operador da por publicado lo que
no se publicó. Corregido el 05/09/2026 añadiendo la misma condición. Es exactamente el riesgo que
el aviso de arriba anunciaba, cumplido — de dos cálculos que tienen que decir lo mismo, uno se
movió.

⚠️ **Y así el aviso del panel deja de ser una sorpresa.** El operador ya sabía que ese componente
era opcional cuando lo montó: al leerlo en el resumen dirá que sí, en vez de preguntarse qué se le
pasó. Ése era el problema real — no que el sistema no supiera distinguir, sino que **no enseñaba lo
que sabía**.

#### Y con esto la pregunta queda CERRADA, no pendiente

Durante un tiempo esto se anotó como deuda: «falta frenar el olvido de verdad». **No lo era.** Un
opcional deliberado y una estándar olvidada son **el mismo dato**: mismo modo, mismas tarifas,
mismos roles. No hay señal que los separe, porque el modelo decidió —bien— no guardar la intención.
Pedir que el sistema los distinga es pedir que adivine lo que el operador pensaba.

Lo único que se puede hacer con dos casos indistinguibles es **enseñarlos y preguntar**, y eso es
exactamente lo que hay: el badge en la tarjeta, la cuenta en el servicio y la línea en el panel. Si
era a propósito, se lee y se sigue; si fue un olvido, se ve tres veces antes de publicar.

⚠️ **Un mecanismo que enseña y deja decidir no es un remedio a medias**: es el correcto cuando el
dato no dice la intención. Escribirlo como pendiente invita a que alguien vuelva a intentar
«arreglarlo», y lo único que puede salir de ahí es un campo que el operador tendrá que rellenar
para repetir lo que ya dijo al montar el componente.

⚠️ **El listón para `advertencias` sube con esto:** una regla ahí dentro no vale sólo con señalar
algo raro — tiene que ser algo que **no pueda ser deliberado**. Si el operador puede haberlo hecho
a propósito y el dato no lo distingue, va en `informativas`.


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

1. **Título del componente**: `tituloSnapshot` (título público) si existe; si no, los **primeros 3 ítems incluidos** unidos por " · " (helper `tituloClienteDeComponente` en el store). Nunca el nombre interno del maestro ni el título del segmento.
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
#### El pie decía siempre lo mismo, y casi siempre era mentira (04/09/2026)

El disparador enseñaba **«Toca para ver precios por perfil»** hubiera un perfil o cinco. Con uno
solo —el caso normal de un grupo de colegio— prometía una comparación que no existe, y quien lo
abría encontraba una tarjeta con el mismo precio que ya estaba viendo. 🔥 **Un control que miente
sobre su contenido se deja de pulsar**, y detrás estaban las opciones.

Ahora lo calcula `etiquetaDisparador`, a partir de lo que el desplegable va a enseñar de verdad:

| Precio visible | Perfiles | Opciones | Dice |
|---|---|---|---|
| sí | varios | sí | Toca para ver precios por perfil y opciones |
| sí | varios | no | Toca para ver precios por perfil |
| sí | uno | ≥1 | Toca para ver el detalle de la(s) opción(es) |
| sí | uno | no | Toca para ver detalle |
| **no** | *(da igual)* | ≥1 | Toca para ver el detalle de la(s) opción(es) |

⚠️ **La fila del precio oculto va primero y no es un detalle.** Con `precioOculto` la tarjeta
existe **sólo** para llegar a las opciones: los perfiles siguen en los datos, pero el panel no
enseña ni un precio. La primera versión de este cálculo miraba los perfiles antes que el precio y
volvía a prometer «precios por perfil» justo donde no hay ninguno — el mismo fallo que venía a
quitar, reintroducido por otra puerta. **Se vio simulando el caso, no leyendo el código.**

#### Dos partes del mismo servicio decían lo mismo (04/09/2026)

Un servicio repartido pinta una línea por parte —`06:50 – 08:35` y `07:15 – 08:55`— y **las dos
decían «Vuelo de Cusco a Lima»**: en vuelo, tren y transporte el título lo pone el SEGMENTO
(`mandaElSegmento`) y hay **un** segmento para las dos. El operador miraba dos horarios sin saber
cuál era el de Sky y cuál el de JetSMART.

⚠️ **El título del componente no sirve para desempatar**, y por eso no se usó: en esos tres tipos
nombra una ruta y quedó congelado en un sentido —«Vuelo desde la ciudad de Cusco a la ciudad de
Arequipa»—, que es justo el motivo de que mande el segmento.

La **tarifa** sí: hay una por parte, ya viaja en `pax_cotizacion:read` y es donde se escribe con
qué se compró. `selloDeComponente()` la pinta como pastilla al final de la línea.

```
06:50 – 08:35   Vuelo de Cusco a Lima   [ SKY AIRLINE ]
07:15 – 08:55   Vuelo de Cusco a Lima   [ JETSMART ]
```

⚠️ **Se calla la tarifa que repite el título** y la de rol `operativo`: una coletilla que dice lo
mismo que la línea de al lado sólo estorba. Y sólo aparece cuando hay **más de un** componente con
hora, que es el único caso en que hay algo que desempatar.

⚠️ **Si el título trae corchetes, el sello es lo de dentro.** Es la convención que el operador ya
usaba —`[ SKY AIRLINE ] con articulo personal y equipaje de cabina`—: dentro va con qué se vuela,
que es lo que desempata; fuera, la condición de la tarifa, que no desempata nada y convertía la
pastilla en un párrafo.

```
06:50 – 08:35   [ SKY AIRLINE ]
07:15 – 08:55   [ JETSMART ]
```

⚠️ **Se lee lo de dentro, NO se recorta por longitud.** Cortar a N caracteres parece lo mismo hasta
el día que alguien escribe `[ LATAM ]` y `[ LATAM Premium ]`.

⚠️ **Y envuelve, no recorta.** La primera versión ponía el sello en `shrink-0` y la fila en una
sola línea: una tarifa larga —«[JetSMART] con artículo personal»— **se comía el título hasta
dejarlo en nada Y encima se salía de la pantalla**, o sea que se perdían las dos cosas a la vez.
Ahora lo único intocable es la hora; el resto fluye a la línea siguiente.

⚠️ **El título se calla cuando repite el de la tarjeta.** En vuelo, tren y transporte lo pone el
segmento, así que las dos partes decían lo mismo que el encabezado tres líneas más arriba.
Callándolo, el sello —lo único que desempata— se lleva el ancho entero.

#### El tipo era un proxy: quien calla al componente son sus hermanos (06/09/2026)

`mandaElSegmento(tipo)` decía «en vuelo, tren y transporte manda el segmento», y en la guía del
huésped eso además **borra** el título del componente —es la única pantalla con UNA ranura; en La
Biblia y en la orden el que no manda baja a la secundaria y no se pierde nada—.

Funcionaba por accidente. Lo que la regla quería decir es que **el título del componente no
distingue nada**, y eso es cierto cuando los hermanos se llaman igual:

```
Vuelo de Cusco a Lima                        ← un solo nombre entre los hermanos
  06:50 – 08:35  «Vuelo desde la ciudad de Cusco a la ciudad de Lima»  [ SKY AIRLINE ]
  07:15 – 08:55  «Vuelo desde la ciudad de Cusco a la ciudad de Lima»  [ JETSMART ]
```

No es cierto en el **traslado bimodal** —3 segmentos en producción—, donde los dos componentes son
tramos consecutivos con nombres distintos y el título es la información:

```
Traslado Bimodal de Ida (Vía Ollantaytambo)  ← dos nombres
  04:20 – 04:40  «Transporte dentro de Cusco hasta Terminal de bus»       Sedán
  05:10 – 07:10  «Transporte desde Terminal de bus a Estación de Olla…»   Autobús
```

Ahí ida y vuelta ya viven en **segmentos separados**, así que la ruta congelada que justificó
`mandaElSegmento` no existe, y callarlos le quitaba al huésped el de-dónde-a-dónde a las 04:20.

**La regla, en `hablaCadaComponente()`:** el tipo decide a quién se le cree la dirección; los
hermanos deciden a quién se le quita la voz. Un solo nombre en el bloque → manda el segmento; dos
o más → habla cada componente.

⚠️ **Decide el BLOQUE entero, no cada fila.** Con dos gemelos y un tercero distinto hablan los
tres, aunque los dos primeros repitan: una lista donde una línea tiene título y la de al lado no
se lee como un fallo de datos, y callarlos a todos por culpa de los gemelos se llevaría por
delante al único que dice hacia dónde va.

⚠️ **La comparación va normalizada** (sin acentos, caja ni puntuación). En esta misma base
conviven `[ ARAJET ]` y `[ Arajet ]`: un espacio de más bastaba para que dos títulos iguales
contaran como distintos.

⚠️ **Riesgo asumido.** Dos compañías cuyos productos de catálogo traigan la misma ruta redactada
con frases distintas volverían a enseñar la dirección congelada bajo un segmento que dice lo
contrario. El síntoma se ve en la propia guía y se cura renombrando el producto; el precio de no
arriesgarlo era perder el bimodal.

⚠️ **La cláusula vive SÓLO en la guía del huésped.** `mandaElSegmento` sigue siendo el mismo
espejo en los cuatro sitios (enum PHP, `util/utils/componenteTipo.ts`, La Biblia, la copia de
`pax`). Lo que se afinó es la cláusula de silencio, que ya era exclusiva de `pax` antes de esto.

#### Carga masiva de boarding passes por ZIP (08/09/2026)

Un grupo de 133 personas que vuela Cusco–Lima, Lima–Panamá y Panamá–Punta Cana ida y vuelta son
**~1 060 boarding passes**. De uno en uno por un formulario no es una molestia: es inviable.

**La convención del nombre es `DOCUMENTO-VUELO`** — «12345678-DM6771.pdf»:

- **el documento y no el nombre**, porque el nombre trae tildes, se escribe en otro orden y se
  repite; un DNI no;
- **el número de vuelo y no el PNR**, porque un PNR cubre ida y vuelta y volvería a no distinguir
  `DM6771` de `DM6770`. Esto obligó a que el adjunto pudiera apuntar a un **vuelo**, que es de lo
  que de verdad es un boarding pass.

El separador es tolerante —guion, guion bajo, espacio— y el orden da igual: se prueban todos los
trozos contra los documentos y contra los vuelos.

🔥 **La validación cruzada es lo que de verdad protege.** El camino
`pasajero → subgrupo(reserva_aerea) → vuelo` ya existía, así que no basta con que el documento y el
vuelo existan por separado: se comprueba que **esa persona vuele ese vuelo**. Un renombrado torcido
—el DNI de uno con el vuelo de otro— se marca en vez de guardarse mal, que es el fallo que nadie
descubriría hasta el gate.

⚠️ **Y esa comprobación estuvo escrita al revés en su caso de duda (corregido el 09/09/2026).**
`vuelaEseVuelo()` comparaba `$suyo->getId()?->equals($vuelo->getId() ?? $suyo->getId())`: con un
vuelo sin id, ese `??` comparaba **el vuelo consigo mismo** y devolvía `true` contra el primero que
tuviera la persona. O sea, ante la duda **aprobaba** — y aprobar es justo lo que esta función no
puede hacer: su único trabajo es cazar el renombrado torcido, y un fichero aprobado a ciegas sale
en verde en el plan y no se descubre hasta el gate.

Además sobraba: `AbstractUid::equals()` acepta `mixed` y ya devuelve `false` con un `null`
(comprobado). Era un guarda de más —de los que se escriben para callar a un analizador— que apagaba
el guarda de verdad, la misma familia que describe `CLAUDE.md` en «una firma falsa apaga los guardas
de alrededor». No mordía hoy porque el `$vuelo` sale de un mapa cargado de la base y siempre tiene
id; era latente.

⚠️ **Sin test.** `vuelaEseVuelo()` y `queFalta()` son privadas y el servicio necesita el ORM, así
que esta regla de decisión no la cubre la suite. Se verificó leyendo `AbstractUid::equals()` y
probándolo (`equals(null)` → `false`).

⚠️ **Dos pasos, y el primero no escribe.** `/archivos-zip/plan` devuelve fila por fila qué haría;
`/archivos-zip/aplicar` guarda lo que casa. Con mil ficheros, aplicar a ciegas es pedir un desastre
callado.

⚠️ **Aplicar NO se fía del navegador**: recalcula a quién pertenece cada fichero desde el nombre
original, que viajó aparte en un `.nombres`. Si el cliente pudiera mandar «este fichero es de esta
persona», bastaría editar la petición para colgarle a alguien el boarding pass de otro.

⚠️ **Y el nombre original nunca se usa como nombre en disco**: un `../` dentro del ZIP escribiría
fuera de la carpeta —el «zip slip»—. Se extrae por índice, con un nombre nuestro, y el original
viaja como dato.

⚠️ **Topes de subida.** `upload_max_filesize` estaba en 10 MB y se subió a 64 MB, que es lo que ya
permitía nginx (`client_max_body_size`). Aun así, **un ZIP por vuelo es mejor que uno gigante**:
cada vuelo lleva ≤25 pax, entra de sobra, y la tabla de revisión es corta.

#### Entregado todo, el panel se encoge a una línea (08/09/2026)

La tarjeta de documentos va **arriba del itinerario** porque es lo único de esa pantalla que le
pide algo al pasajero. En cuanto ya no le pide nada, ese sitio deja de ser suyo: quien ya entregó
vuelve a mirar su viaje, y encontrarse tres filas resueltas empujando el itinerario hacia abajo es
cobrarle todos los días por haber hecho los deberes.

Con los tres subidos queda una línea: ✔ verde, «Recibidos. No tienes que hacer nada más.» Se puede
volver a abrir —una foto se puede querer cambiar—, pero cerrado es el estado normal.

⚠️ **El botón de cerrar sólo existe cuando está todo.** Si falta algo, plegar el panel sería
esconder lo que se le está pidiendo.

#### La pantalla se olvidaba de lo que ya subiste (08/09/2026)

El primer pasajero real subió sus tres documentos, volvió a entrar y los tres botones decían
«SUBIR» otra vez. Los ficheros estaban guardados —2400×1800, con su filtro correcto—; lo que
faltaba era decírselo.

🔥 **El coste no es la molestia: es que deja de intentarlo.** O lo manda de nuevo —trabajo
repetido para él y para el operador, con dos escaneos que hay que comparar— o da por hecho que el
sistema no funciona. Lo segundo **no se descubre nunca**, porque nadie escribe para decir que se
rindió: sólo se ve al final, en los que faltan.

Lo arregla `miIdentidad.documentosEnviados`, y ⚠️ **lleva sólo el TIPO: ni id, ni url, ni nombre de
fichero.** Un escaneo de identidad no se le devuelve ni a su dueño, así que esto contesta
«recibido» sin darle nada con lo que abrirlo. Por eso no cabe en `miIdentidad.documentos`, que sí
lleva enlaces.

⚠️ En el componente se **une** con lo de esta sesión en vez de sustituirlo: el prop viene de la
respuesta del servidor, y lo que se acaba de subir todavía no está en ella.

#### Lo que encontró la revisión (08/09/2026)

Cinco defectos que pasaron PHPStan 7, 576 tests, `vue-tsc` y ESLint. Ninguno daba error.

**1. Todo adjunto de la portada daba 404 a todo cliente.** `ArchivoPrivadoController::puedeVerlo()`
decía «sin dueño no hay a quién devolvérselo» — prudente y falso: la entrada a Machu Picchu, el
tren y la confirmación de reserva **no tienen dueño** y son justo lo que la portada ofrece. Y como
el 404 es mudo por diseño, parecía que el archivo no existía. Ahora manda el TIPO —`esPublico()`,
la misma regla con la que se arma la lista, para que lista y permiso no puedan discrepar— y, si el
expediente exige identificarse, esto también.

**2. Un número de vuelo que se repite se archivaba contra el tramo equivocado.** `CotizacionVuelo`
es único por `(numero, fecha)`: el JA7027 vuela el 25 **y** el 27. El mapa `numero → vuelo` dejaba
ganar al último, y `12345678-JA7027.pdf` iba al tramo que tocara. Sin alarma: la persona vuela los
dos —mismo PNR—, así que la validación cruzada decía que sí y el plan lo pintaba en verde. El
pasajero abría en la puerta el boarding pass del otro día. Ahora el ambiguo **se saca del mapa** y
la fila explica «el JA7027 vuela el 25/09 y el 27/09: añade la fecha al nombre».

**3. Dos ficheros del mismo pasajero y vuelo en el MISMO ZIP creaban dos tarjetas.** `setFile()` es
un setter plano: el adjunto nuevo no entra en `getFilearchivos()`, así que `boletoPrevio()` sólo
veía la base. `12345678-DM6771.pdf` y `12345678_DM6771.jpg` —o el mismo nombre en `ida/` y
`vuelta/`— pasaban los dos. El duplicado que todo esto evita, colado por dentro.

**4. La fecha se corría un día en vuelos de noche.** `salida` es hora local de Lima; enviada con
`format('c')` lleva `-05:00`, y el front pinta en UTC para que una fecha sin hora no se mueva. Un
vuelo de las 23:50 del 22 salía «23 sep». Ahora viaja como `Y-m-d`.

**5. El `unlink` del cron mentía.** El comentario prometía «si falla, la fila se queda y el próximo
pase lo reintenta», pero se ignoraba el resultado y la fila se borraba igual — dejando el fichero
en disco sin nadie que supiera de quién era.

⚠️ **Y `Cache-Control` pasó de `max-age=3600` a `no-cache`**: este enlace se abre en el móvil
compartido de la familia —por eso existe «No soy yo»—, y con `max-age` el «atrás» servía la
tarjeta de A a B durante una hora **sin pasar por PHP**, que es donde se comprueba de quién es.
`no-cache` no prohíbe guardar, obliga a revalidar: el service worker sigue conservándola para el
aeropuerto sin señal.

#### La columna del código individual no salía en la plantilla en blanco (08/09/2026)

`Cód. #Vuelo Internacional` —el localizador de **esa** persona dentro del PNR del grupo— está
documentado, lo lee el importador y lo saca la **exportación** de un expediente. Pero
`cabecerasDeGrupo()`, que es la plantilla **en blanco**, no la emitía.

🔥 **Una columna que sólo existe si ya tienes datos no la descubre nadie.** Quien empieza de cero
—que es siempre la primera vez— baja la plantilla, no la ve, y acaba pidiendo el código individual
por WhatsApp y tecleándolo 133 veces. Es justo el dato que luego el pasajero busca en «Lo tuyo».

⚠️ Va **pegada a la columna de su eje** y no al final, por lo mismo que en la exportación: se
rellena mirando la clave que tiene al lado, y con diez columnas de por medio se rellena la del
vuelo equivocado.

#### La entidad NO es anidada: sólo el formato de carga (08/09/2026)

Es la pregunta que hizo Jorge al ver el JSON, y la respuesta cambia el diseño: **un
`CotizacionVuelo` son siete campos planos** —número, aerolínea, origen, destino, salida, llegada—
y eso es un formulario corriente. Lo anidado es el JSON, y lo está porque va **por PNR**, que es
como escribe la aerolínea: «el localizador BONT3N ahora vuela estos cuatro tramos».

Son dos trabajos distintos, y por eso conviven los dos caminos:

| Qué pasó | Con qué se arregla |
|---|---|
| La aerolínea movió el JA7013 veinte minutos | **el formulario**: se abre la ficha del vuelo y se cambia la hora |
| Llega el correo con las reservas emitidas | el **JSON**: 24 PNR de una vez |
| Un PNR se reubicó en otro vuelo | el **JSON**: el vínculo es de la reserva, no del vuelo |

⚠️ **El formulario NO toca los vínculos.** Quién viaja en este vuelo lo declara el PNR. Aquí se
corrige el HECHO —a qué hora sale—, no a quién le pasa; mezclarlo daría dos sitios para cambiar lo
mismo. La ficha lo dice en su pie.

⚠️ **El selector es `FechaHoraPicker`, no `<input type="datetime-local">`.** Se escribió con el
nativo y el propio componente compartido advierte que no se use: saca AM/PM en un equipo con el SO
en inglés —aquí se trabaja siempre en 24 h—, cambia de aspecto en cada navegador y no admite
máscara al teclear. Con `borrable: false`, porque un vuelo sin hora de salida no existe.

⚠️ **Y el suelo de la llegada es el DÍA de la salida, nunca la fecha completa.** `VueDatePicker`
toma `min-date` también como límite de HORA y reajusta el valor: con la salida entera, un DM6770
que despega a las 20:22 no dejaría poner la llegada a las 00:30 del día siguiente — que es
exactamente ese vuelo. Es la misma trampa que ya documentaba `ReservaEditDrawer::soloDia()`.

⚠️ **No hay campo «fecha»**, a propósito: `setSalida()` la fija, porque es la mitad de la identidad
del vuelo y dos campos para un mismo hecho acaban discrepando. El efecto es que mover una salida a
otro día puede chocar con `uniq_vuelo_file_numero_fecha` —pasa de verdad: el JA7027 vuela el 25 y
el 27—, y eso se contesta con una frase que se entiende, no con un 500.

#### La tabla de vuelos perdía el destino en el móvil

Cada celda llevaba `whitespace-nowrap`, así que en una pantalla estrecha la tabla se desbordaba y
**el destino se salía**: un vuelo se leía «CUZ →» y ahí acababa. Son fichas desde el 08/09/2026 —
una ficha se envuelve, una fila con `nowrap` no.

#### Se descarga lo que hay, se edita y se vuelve a cargar (08/09/2026)

**Un formulario para esto no compensa.** Un PNR con cuatro tramos —cada uno con número, fecha,
aerolínea, ruta y dos horas— más las notas de la reserva es un formulario de veintitantos campos
anidados en dos niveles. Y lo que de verdad se hace **no es crearlo de cero**: es corregir un
horario cuando la aerolínea reprograma.

`VuelosExportador` saca el expediente en **el mismo JSON que se carga**. Descargar, cambiar la
línea, volver a pegar. De paso el formato deja de ser un examen: se aprende leyendo el propio.

🔥 **Es espejo exacto de `VuelosImportador`. Al tocar uno, tocar el otro.** Si el exportador
escribiera una clave que el importador no lee —o al revés—, el viaje de ida y vuelta perdería datos
**en silencio**: se descarga, se edita una línea, se vuelve a cargar, y lo que se nombró distinto
desaparece sin un solo error. No se puede unificar en una constante como hace `PadronFormato`
—uno lee y el otro escribe—, así que la garantía es la comprobación: **exportar, reimportar en
ensayo y no obtener ningún cambio**.

⚠️ **`pnr_nuevo` no se exporta.** Es una instrucción de renombrado, no un dato del expediente:
escribirlo haría que reimportar sin tocar nada renombrara el PNR a sí mismo.

⚠️ **Sólo el eje de reserva aérea.** Una habitación no tiene vuelos, y sacarla con `"vuelos": []`
haría que reimportar **desvinculara** lo que ese grupo tuviera — la lista de un PNR reemplaza.

⚠️ **`.txt` y no `.json`**: se abre de un toque en cualquier móvil, que es donde llega el correo de
la aerolínea. Y la descarga va por `apiClient`, no por un `<a href>`: un enlace suelto perdería la
sesión y bajaría un 401 con extensión `.txt`.

#### El orden importa: primero el padrón, después los vuelos

`VuelosImportador` **no crea localizadores**: un PNR que no está en el expediente se reporta y se
salta (`«BONT3N no existe en el expediente: no se crea.»`). Y el padrón es quien crea los grupos de
reserva aérea, con su clave.

Así que subir el JSON de vuelos antes del Excel no da error: da un informe con **todas** las
reservas rechazadas y cero cambios. No es reversible al revés — es simplemente que no hizo nada.

Crear el PNR desde el JSON convertiría una errata en un grupo huérfano sin pasajeros, que es la
familia de fallo que este modelo viene a cerrar.

#### El ejemplo del cargador de vuelos enseñaba a perder la vuelta (08/09/2026)

El diálogo traía **una** reserva con **un** vuelo, y su ayuda decía «se toca sólo lo que traes… nunca
borra». Lo segundo es falso, y de la forma cara: **los vuelos de un PNR se REEMPLAZAN por los de la
lista.** Un PNR declara ahí dónde viaja hoy, así que mandar la ida sin la vuelta desvincula la
vuelta. Medido en un ensayo contra producción, declarando dos de los cuatro tramos de una conexión:

```
AX3LLL deja de viajar en CM749·22/09, CM337·22/09
```

Con el ejemplo viejo delante —un solo vuelo— eso es lo que alguien habría copiado.

Ahora el ejemplo son **dos reservas completas**: un ida y vuelta directo con llegada al día
siguiente, y una conexión de cuatro tramos. Entre las dos aparecen todas las claves que el
importador entiende. Comprobado ejecutándolo contra `VuelosImportador` en ensayo: 0 problemas.

⚠️ **Falta `pnr_nuevo` a propósito**: RENOMBRA, y en un ejemplo que se pega y se aplica eso es una
trampa.

⚠️ **Los PNR son inventados** (`AAAAAA`, `BBBBBB`). Uno real abre la reserva en la web de la
aerolínea con sólo un apellido, y esto es código que se lee en muchos sitios. Además así pegar el
ejemplo y aplicarlo no toca nada: el importador avisa de que ese PNR no existe y se salta la
reserva — que es justo lo que debe hacer un ejemplo.

⚠️ Y el importador acepta `pnr` **o** `localizador` como clave de la reserva; el ejemplo usa `pnr`,
que es la que dice el diálogo.

#### «A quién aplica»: el vuelo estaba detrás del ⓘ (08/09/2026)

Cuatro filas idénticas —`Vuelo · Internacional · Copa Airlines`— distinguidas sólo por un PNR de
seis letras. Con varias reservas de la misma aerolínea en **días distintos**, elegir exigía abrir
el `ⓘ` de una en una. Y elegir a ciegas y comprobar después es cómo un componente acaba colgado
del vuelo equivocado.

Ahora cada fila lleva una **tercera línea** con el primer tramo:

```
Vuelo · Internacional · Copa Airlines          BNZXNE   8 pax  ⓘ
CM264 · 18/09 02:35 LIM→PTY · +3
```

⚠️ **Sólo el PRIMER tramo, no los cuatro.** Una reserva de ida y vuelta con conexión son cuatro, y
con 24 subgrupos eso serían cinco líneas por fila: la lista dejaría de poder recorrerse, que es el
problema de partida con otra cara. Lo que distingue dos reservas de la misma aerolínea es **cuándo
sale la primera**; los demás siguen en el `ⓘ`, y el `+N` dice que están ahí.

⚠️ **Va a lo ancho de la fila, no dentro de la columna del rótulo.** Metida ahí, el localizador y
los «44 pax» le comían el sitio y quedaba `H2 5002 · 17/09 06:50…` — cortada **justo antes de la
ruta**, que es lo que distingue la ida de la vuelta. Es el mismo fallo que ya tenía el rótulo:
truncar donde lo importante va detrás. Sangrada bajo el rótulo, no bajo la casilla.

⚠️ Formato compacto a propósito para el móvil: `18/09 02:35 LIM→PTY` cabe donde no cabe una frase.

La misma línea aparece al **repasar** los subgrupos ya elegidos en la tarjeta del componente, que
es cuando más barato sale descubrir que se marcó el vuelo de otro día.

#### El manifiesto se lee para rellenar OTROS formularios (08/09/2026)

Es lo que de verdad se hace con él: el del seguro, el de la aerolínea, el del hotel. Y hasta hoy
eso era seleccionar con el ratón un número dentro de una línea con más cosas. Con 131 personas y
varios formularios cada una, teclear un DNI a mano es donde aparecen los dígitos cambiados que
nadie descubre hasta el mostrador.

Ahora la ficha lleva **botón de copiar** en el nombre completo, en cada número de documento, en
cada vencimiento y en la fecha de nacimiento. ⚠️ El aviso de «copiado» es **por campo y no
global**: hay tres números seguidos que se parecen, y lo que hace falta saber es cuál se copió.

⚠️ **Y faltaba la FECHA DE NACIMIENTO**, que sólo salía como edad. La edad se calcula y sirve para
saber si es menor; la fecha es la que piden la aerolínea, el seguro y migraciones — y es además la
mitad de la contraseña con la que el pasajero entra a ver su viaje. Estaba únicamente en el
editor, así que había que abrir el formulario para leerla.

⚠️ **Nacimiento y sexo suben al principio del editor.** Estaban al final, después de los documentos
y de los subgrupos, y son los dos datos que pide todo control migratorio. El orden de un formulario
que se rellena 131 veces no es estética.

#### Quién ha subido sus documentos, en una hoja (08/09/2026)

El manifiesto ya enseña los escaneos de cada persona, pero la pregunta que se hace de verdad no es
«¿qué tiene Fulano?» sino **«¿a quién le escribo hoy?»** — y eso es una lista que se ordena, se
filtra y se pega en un mensaje, no un panel que se recorre 133 veces.

El botón **Documentos cargados** del manifiesto baja un `.xlsx`
({@see `App\Cotizacion\Service\Padron\ReporteDeDocumentos`}) con una fila por persona: grupo,
apellidos, nombres, tipo, número de DNI, de pasaporte y de cualquier otro documento, y después el
estado de los **tres escaneos** que se piden — DNI anverso, DNI reverso y pasaporte —, la columna
`Archivos` con el total, y `Qué falta` ya redactada.

⚠️ **El icono es una flecha de descarga, no `fa-file-excel`.** Ése dibuja una X sobre el papel y,
con la papelera roja del expediente a dos dedos, el botón se leía como «borrar documentos» — que es
lo contrario de lo que hace. Un botón que parece destructivo no se pulsa.

⚠️ **No es la exportación del padrón, y no se vuelve a subir.** Aquella lleva los DATOS —números,
vencimientos, ejes— y trae la columna `Id` precisamente para reimportarse. Ésta lleva el ESTADO y
no tiene importador: son dos hojas distintas porque son dos preguntas distintas.

##### El orden es por GRUPO, y el coordinador encabeza el suyo

Los documentos se reclaman por grupo, así que la hoja sale ordenada por grupo y, dentro de él, por
`PasajeroTipoEnum::rangoDeLectura()`: primero el coordinador —que es a quien se le escribe—, después
los participantes por apellido. Los 23 que no van en ningún grupo —acompañantes, supervisores,
invitados— caen al final en bloque, con `— sin grupo` escrito: **una celda vacía en una hoja de
faltantes se lee como «esto no se ha rellenado»**, que es justo lo contrario de lo que pasa.

⚠️ **No hay un grupo de coordinadores, ni un eje con más jerarquía.** Los cuatro ejes de
`GrupoTipoEnum` son planos y cruzados; `grupo` son las nueve unidades operativas del viaje y punto.
Quien lidera lo dice el **rol de la persona** (`PasajeroTipoEnum::COORDINADOR`), no un grupo aparte:
hay 9 coordinadores para 9 grupos, uno cada uno, y por eso `esJefe` se quitó de la pertenencia — es
la misma razón que ya está escrita en la cabecera del enum.

⚠️ La clave del grupo es **texto libre** —hoy son cifras, pero valdría «A» o «Bus rojo»—, así que
se acolcha a la izquierda antes de comparar: sin eso, `10` iría antes que `9`.

⚠️ **Respeta los filtros de la pantalla**, y por eso manda la lista de ids por `POST` en vez de
mandar los filtros: repetirlos en el servidor serían dos implementaciones de la misma pregunta, y
la que se quedase corta lo haría en silencio. Sin filtros puestos es un `GET` y baja el expediente
entero. Es el mismo par de verbos que `cotizacion_padron_exportar`, por el mismo motivo (los UUID
de 133 personas no caben en una URL).

##### La celda cuenta ARCHIVOS, no marca sí/no

Cada celda de escaneo lleva **la cantidad y la fecha del más reciente**: `1 · 01/09/2026`,
`2 · 05/09/2026`, `0` si no hay nada. Verde con uno, ámbar con varios, rojo con ninguno. Y una
columna `Archivos` con el total de la persona, en ámbar si pasa de tres.

⚠️ **La cantidad va siempre, aunque sea «1».** Escribirla sólo cuando hay varios convierte la
ausencia del número en un dato que hay que deducir, y una columna en la que casi todo son fechas no
se lee como un recuento: el duplicado pasaría desapercibido justo cuando importa. Y no es adorno,
porque **las dos vías de subida no se comportan igual**.

- La del **pasajero** (`SubirDocumentoPasajeroController`) *reemplaza*: al subir un segundo
  pasaporte borra el anterior, porque la segunda foto siempre es la buena — la primera salió
  movida.
- La del **operador** (API Platform, el formulario de la bóveda) *acumula*: no borra nada, y no hay
  índice único en `cotizacion_file_archivo` que lo impida.

O sea que sí, un operador puede dejar dos pasaportes o dos anversos colgados de la misma persona.
Comprobado en producción el 08/09/2026: **cero duplicados** hoy (32 pasaportes, 30 anversos, 30
reversos, todos de personas distintas). Se deja como está a propósito —hay casos legítimos, como el
pasaporte viejo y el nuevo de quien lo renovó a mitad de trámite— y esta hoja es donde se ve si
alguna vez deja de ser legítimo.

#### El pasajero no veía los horarios de SU vuelo (08/09/2026)

En «Lo tuyo», el subgrupo aéreo decía «Copa Airlines · BNZXNE · 8 personas»: con quién vuela y con
qué localizador, pero **no cuándo ni desde dónde** — que es lo que se busca la noche antes. El
operador sí lo veía en el manifiesto.

`miIdentidad.subgrupos[].vuelos` lleva ahora número, ruta y horas de cada tramo. Y el itinerario
del viaje no servía para esto: el vuelo es de **su** subgrupo, no del grupo entero.

⚠️ **Y el botón que los esconde los NOMBRA**: «Ver mis grupos **y vuelos**» cuando hay tramos
dentro. Un disparador que no nombra lo mejor que esconde se pulsa menos, y quien busca a qué hora
sale su vuelo no abre algo que sólo promete «grupos». Sin vuelos, el rótulo no promete lo que no
está.

⚠️ **El «+1 día» no es un adorno.** Un vuelo que sale a las 20:22 y llega a las 00:30 aterriza al
día siguiente, y quien lea sólo las horas hará mal las cuentas del traslado, del hotel y de a quién
avisa para que le recoja.

⚠️ Las horas viajan **sin formatear** y se comparan por su cadena `Y-m-d`, sin construir un `Date`:
los dos instantes están en hora local de su aeropuerto, y pasarlos por la zona del navegador
movería el cálculo justo para quien mire desde otro país.

#### El formulario de la bóveda no llegaba a dos de los cuatro alcances (08/09/2026)

`CotizacionFilearchivo` sostiene cuatro alcances —pasajero+vuelo, pasajero, grupo, expediente— y el
formulario sólo ofrecía dos y medio:

| Alcance | Antes | Ahora |
|---|---|---|
| pasajero + **vuelo** | El selector decía «¿De qué vuelo?» y escribía en **`grupo`** | Escribe en `vuelo` |
| **grupo** solo | **No se ofrecía**: el alcance existía en la base y no había forma de usarlo | «¿O de qué subgrupo?», cualquier eje |

🔥 **El primero es el que duele.** La clave de un subgrupo aéreo es el **PNR**, y un PNR cubre ida y
vuelta: `DM6771` y `DM6770` caen en el mismo. Por eso se añadió el campo `vuelo` el 07/09/2026 — y
la carga por ZIP ya lo usaba, pero **el formulario manual se quedó escribiendo en `grupo`**. O sea
que un boarding pass subido a mano no se distinguía del de vuelta.

⚠️ **Y `CotizacionVuelo` no publicaba su `id`.** La tabla de vuelos se apañaba con `numero|salida`
como clave del bucle, así que el hueco no se notaba hasta que hubo que **escribir** la relación:
sin id no hay IRI. Redeclarado sobre `IdTrait` con `file:item:read`, como ya hacía `CotizacionFile`.

⚠️ La etiqueta del campo decía **«Archivo (PDF / IMG)»** y aquí cabe cualquier fichero — se subió un
vídeo el mismo día. Un rótulo que describe menos de lo que admite hace dudar antes de intentarlo.

#### Encontrar un archivo entre 1 500, y arreglarlo cuando está en las manos de otro (09/09/2026)

Tres carencias de la misma lista, que sólo se ven cuando el expediente es grande de verdad.

**1. No había buscador.** Con ~1 500 archivos, llegar al de una persona era bajar a ojo. El campo
busca por **palabras sueltas en cualquier orden** —«ana dni» encuentra el DNI de Ana— y sobre lo
mismo que se ve en la fila: nombre, tipo y dueño. Es deliberado que no busque por nada invisible:
lo que se teclea es lo que se lee, y si no aparece se entiende por qué.

Va **antes** que la carga por ZIP, que hasta ahora abría el panel: subir es ocasional, encontrar es
lo de cada día.

**2. El dueño se cortaba justo donde empieza a distinguir.** Compartía renglón con el tipo
—«DNI — ANVERSO · Edgar Joaqu…»— y en una familia con el apellido repetido eso es exactamente lo que
hay que leer. Ahora va en su propia línea.

⚠️ **Y `duenoDelArchivo()` ignoraba el `vuelo`**, que es el alcance **más** frecuente: los ~1 060
boarding passes que entran por ZIP se guardan con pasajero + vuelo, así que las ocho tarjetas de una
misma persona se leían idénticas. Lee los tres alcances: persona, vuelo, subgrupo.

**3. Reasignar de una persona a otra, sin borrar el archivo.** El bloque «¿De quién es?» estaba
escondido al editar, y el comentario decía por qué: *«cambiarlo movería el archivo de manos sin
decirlo; para eso se borra y se vuelve a subir, que deja rastro»*.

🔥 **Se revierte, porque el rastro salía carísimo.** En las familias que comparten apellido el
reparto se tuerce solo —lo hace quien sube, y lo hace el ZIP cuando el fichero viene mal nombrado—,
y borrar obliga a **pedirle el documento otra vez a alguien que ya lo mandó**. Lo que se corrige es
a quién APUNTA la fila; el fichero no se toca, y sigue sin poder reemplazarse desde aquí.

⚠️ **Los tres alcances son excluyentes y el formulario esconde el que no toca**, así que se
resuelven en un solo sitio —`alcanceDelDoc()`— y no campo a campo:

| Se elige | `pasajero` | `grupo` | `vuelo` |
|---|---|---|---|
| una persona | ✓ | **null** | el suyo, si es boleto |
| un subgrupo | null | ✓ | null |
| nada | null | null | null |

Sin eso, elegir persona sobre un archivo que era de un subgrupo **dejaba el `grupo` viejo puesto**:
la fila decía las dos cosas y no era de ninguna. Era un fallo latente ya en la subida, donde los dos
desplegables podían quedar rellenos; ahora las dos rutas —POST multipart y PATCH— salen de la misma
función. La diferencia es que al **crear** se omite lo vacío (un IRI en blanco no lo resuelve API
Platform) y al **editar** se manda `null`, que es lo que desasigna.

No hizo falta tocar PHP: `pasajero`, `grupo` y `vuelo` ya estaban en el grupo `file:write`, que es el
de denormalización del `Patch`.

⚠️ **Y el invariante ya estaba puesto para esto sin que nadie lo hubiera previsto:**
`CotizacionFilearchivo::validarDuenoDelMismoExpediente()` lleva `#[ORM\PrePersist]` **y**
`#[ORM\PreUpdate]`, así que reasignar a una persona de OTRO expediente revienta igual que
importarlo mal en lote. Se escribió pensando en la carga masiva; cubre la reasignación gratis.

⚠️ **Los tres selectores son `limpiable`.** Desvincular es media reparación: un archivo que se coló
en la persona equivocada muchas veces no es de nadie en concreto, y devolverlo al expediente entero
tiene que ser un clic. Sin la bandera, `SearchableSelect` no enseña la «×» —es opt-in a propósito,
porque la mayoría de esos selectores son obligatorios.

#### Comparar UUIDs con `endsWith` falla en silencio y siempre hacia el mismo lado (09/09/2026)

`duenoDelArchivo()` casaba el IRI de la relación contra el id de la fila con
`iri.endsWith(String(id))`. Con UUIDs eso muerde por la caja: un id que llegue como `0198E5F1…`
frente a `0198e5f1…` no casa, no encuentra al dueño, y **el archivo parece del expediente entero
cuando en realidad es de alguien**. El fallo se disfraza del caso más común, que es como no se
descubre nunca.

Ahora las tres relaciones se resuelven contra `indiceDeDuenos`, un `Map` con **las claves en
minúsculas**, que arregla la caja y el coste a la vez (ver abajo).

#### El dueño costaba 43 ms de render y se recalculaba en cada tecla (09/09/2026)

Medido con los tamaños reales de un grupo grande —1 500 archivos, 133 pasajeros, 99 subgrupos,
24 vuelos—:

| Qué | Antes | Ahora |
|---|---|---|
| Filtrar la bóveda (una tecla) | 22 ms | ~0 (la paja se calcula una vez por lista) |
| Render de 1 500 filas | 43 ms | **0,7 ms** |

Eran tres `find()` por archivo, pagados **dos veces por fila** —la plantilla llama a
`duenoDelArchivo()` en el `v-if` y otra vez para pintar—. En un móvil eso son ~200 ms, y no sólo
al teclear: **Vue no cachea las llamadas a función de la plantilla**, así que se repetía en
cualquier cambio reactivo con la bóveda abierta.

`indiceDeDuenos` es un `computed` que se reconstruye sólo cuando cambia `file.value`;
`duenoDelArchivo()` pasa a ser tres `Map.get()`. Y `bovedaIndexada` calcula la paja de búsqueda
una vez por lista en vez de una por tecla.

#### El vuelo viajaba escondido, y el vuelo era de otra persona (09/09/2026)

Dos fallos mudos que abrió el propio formulario de reasignación, los dos en la misma pareja
pasajero↔vuelo. Ninguno da error; los dos dejan la fila diciendo algo falso.

**1. La regla del formulario y la del guardado no eran la misma.** El selector de vuelo se veía con
`pasajeroId && tipoArchivo === 'boleto'`; `alcanceDelDoc()` guardaba con `pasajeroId && vueloId`. Y
`abrirEdicionDoc()` precarga `vueloId` siempre. Resultado: editar un boarding pass y cambiarle el
tipo a «Pasaporte» guardaba **un pasaporte con vuelo**, con el selector escondido — imposible de
limpiar sin volver a ponerle `boleto`.

⚠️ **La regla es ahora una sola, `ofreceVuelo`**, y gobierna las dos cosas. Un vuelo ya puesto
mantiene el selector abierto aunque el tipo deje de ser `boleto`: **nada que se mande puede estar
fuera de la pantalla**.

**2. El desplegable ofrecía los 24 vuelos del expediente, no los 8 de esa persona.** El docblock de
`CotizacionFilearchivo::$vuelo` decía que ofrecía «sus ocho, no los veinticuatro» y era falso: nada
comprobaba la pareja, ni en el formulario ni en la API. Sólo lo hacía la carga por ZIP
(`CargaMasivaDeArchivos::vuelaEseVuelo()`, privado). Y al reasignar de Ana a Beatriz **el vuelo de
Ana seguía preseleccionado**, así que el caso de uso que motiva el formulario era justo el que
dejaba la pareja torcida.

Tres cosas, en la pantalla y no en la base:

- `vuelosDelElegido` acota a los suyos. ⚠️ **Se cruza por el PNR**, no por la relación:
  `CotizacionFileGrupo::$vuelos` **no está serializado** —el `#[Groups]` que hay pegado debajo es
  de `$notas`, no suyo— y publicar esa `ManyToMany` la metería entera en cada subgrupo. Desde el
  navegador el camino es el de `vuelosDe()`: la `clave` del subgrupo aéreo es el PNR y cada vuelo
  trae los suyos en `pnrs`.
- Si la persona **no tiene vuelos atados todavía**, no se acota: bloquear el guardado por un dato
  que aún no ha llegado es peor que ofrecer de más.
- El vuelo **que ya está puesto no se cae de la lista** aunque no sea suyo — se marca «⚠️ no lo
  vuela». Si desapareciera, `SearchableSelect` enseñaría un hueco con un valor detrás que el
  operador no puede ver ni quitar.

⚠️ **Y elegir otra persona suelta el vuelo del anterior — colgado de `@change`, no de un `watch`.**
Un `watch` sobre `docForm.pasajeroId` también dispara cuando `abrirEdicionDoc()` **precarga** el
formulario, así que borraría el vuelo bueno justo al abrir la edición de un boarding pass: el dato
se perdería *al mirarlo*. `SearchableSelect` sólo emite `change` cuando el operador elige o vacía.

**Lo que NO se hizo:** subir `vuelaEseVuelo()` a la entidad como invariante. Recorre pertenencias y
grupos por cada archivo, y en `PrePersist` eso son N+1 consultas sobre una carga de ~1 060 boarding
passes que **ya valida esa pareja** antes de guardar. Queda como agujero conocido para quien
escriba por la API directamente.

#### `validarDuenoDelMismoExpediente()` no miraba el `vuelo` (09/09/2026)

El bucle recorría `pasajero` y `grupo`. Se escribió cuando el único alcance con riesgo era la
importación en lote, y **`vuelo` se añadió después** (07/09/2026). Por la pantalla no se alcanza
—el desplegable sale del mismo expediente—, pero por la API se le podía colgar el vuelo de otro.
Es una entrada más en el mismo array; `CotizacionVuelo::getFile()` ya existía.

⚠️ El resto del invariante sí estaba bien y **cubre la reasignación por PATCH gratis**:
`#[ORM\PreUpdate]` dispara al cambiar una asociación *to-one*, y el `DomainException` sale como 422
por `exception_to_status`, así que el error llega al aviso del formulario.

**Auditoría en producción el 09/09/2026** (260 archivos): `pasajero` y `grupo` a la vez → 0; vuelo
sin pasajero → 0; vuelo en algo que no es boleto → 0; vuelo de otro expediente → 0; parejas
pasajero↔vuelo que no vuela → 0. **Nada torcido**: los arreglos son preventivos.

#### Leer el pasaporte: la MRZ es lo que separa «el modelo dice» de «está comprobado» (09/09/2026)

Primera pieza del reconocimiento de documentos: **lee y propone, no escribe nada todavía.**

##### Por qué no es un Skill del agente, ni un tramo de potencia

Dos encuadres que parecían naturales y no lo son:

- **Skill no.** `SkillInterface` es una capacidad que el agente invoca *dentro de una
  conversación*, y su contrato dice literal «Dominio puro: aquí no entra ningún proveedor». Un
  «skill de Gemini» lo contradice de raíz.
- **Tramo de potencia tampoco.** Alta/Media/Baja miden «cuánta cabeza hace falta». Leer una MRZ y
  devolver JSON con esquema es una **capacidad** —multimodal, salida estructurada—, no más cabeza:
  el modelo más caro sin visión vale cero aquí. Por eso `AGENT_IA_VISION_MODELO` va aparte, y así
  cambiar el modelo del chat no mueve de rebote el que lee documentos de identidad.

Y no pasa por `AgentEngineInterface`: `ConversationRequest::$mensaje` es un `string`, así que meter
imágenes ahí obligaría a los **tres** motores a implementar visión para que uno la usara. No hay
bucle, ni herramientas, ni historial: es una función con una imagen dentro.

##### El reparto, que es lo que hace esto mantenible

| Dónde | Qué sabe |
|---|---|
| `src/Agent/Vision/LectorDeImagenInterface` | «esta imagen, esta forma». **No sabe qué es un pasaporte.** |
| `src/Agent/Vision/GoogleLectorDeImagen` | `inline_data`, `responseSchema`, `temperature 0` |
| `src/Cotizacion/Documento/` | qué campos tiene un documento, qué es una MRZ, qué hay que dudar |

No hizo falta tocar `GoogleAIClient`: `generarContenido()` recibe el cuerpo crudo.

⚠️ **`responseSchema` en vez de pedir el JSON en el prompt.** Pedirlo en prosa funciona casi
siempre, y ese «casi» es el fallo: un día llega con ```json delante y el `json_decode` revienta con
un documento que se había leído bien. Y `temperature: 0`, porque dos lecturas del mismo pasaporte
tienen que dar el mismo número o no se pueden comparar ni repetir un fallo.

##### 🔑 La MRZ: aritmética en vez de confianza

`CLAUDE.md` manda validar con código lo que decide un modelo, y aquí se puede cumplir a rajatabla
porque **la banda del pasaporte lleva sus propios dígitos de control** (ICAO 9303, pesos 7-3-1).
Un `7` leído como `1` rompe la suma y se caza sin preguntarle a nadie.

`Mrz` es **el único trozo de todo esto con tests de verdad** —8, sobre el ejemplar publicado en la
especificación, que trae sus dígitos ya calculados—, y puede tenerlos justamente porque no habla
con nadie: sin red, sin base, sin contenedor. Cubren el caso que lo justifica todo (un dígito mal
leído en el número no cuadra), que una fecha imposible dé **nada** en vez de correrse tres días
—`createFromFormat` acepta el 30 de febrero—, y que un año de nacimiento de dos dígitos caiga en
el siglo que toca.

⚠️ **Sólo TD3 (pasaporte). El TD1 de las cédulas se RECHAZA en vez de leerse a medias**, porque
son tres líneas de 30 con otras posiciones: leerlo con las de TD3 sacaría campos de los sitios
equivocados y los dígitos no cuadrarían, así que parecería un error de lectura y no un formato no
contemplado. Dos diagnósticos distintos que hay que poder distinguir.

##### Se piden las DOS lecturas, y se contrastan

Se le pide la MRZ transcrita carácter a carácter **y además** los campos de la zona impresa. Con
eso, `LectorDeDocumentoIdentidad` hace tres cosas:

1. Comprueba la MRZ con sus dígitos de control.
2. Si cuadra, **la MRZ manda**. Si no cuadra, manda lo impreso — preferir una MRZ rota sería
   elegir justo el dato del que ya sabemos que falla.
3. Compara las dos: si el número impreso y el de la MRZ difieren, eso es un **aviso**, no un
   empate que se resuelve solo.

⚠️ El paso 3 es el que más vale y el más fácil de omitir. Pedir sólo la MRZ dejaría pasar una
transcripción coherente consigo misma pero equivocada; pedir las dos la caza casi gratis.

⚠️ **`DatosDeDocumento::verificadoPorMrz()` tiene que verse en pantalla.** Un dato respaldado por
dígitos de control y otro que sólo dijo un modelo **no son la misma clase de dato**, y si se pintan
igual la confirmación humana se convierte en un clic en vez de una revisión.

##### Antes de dejarle escribir

`app:cotizacion:leer-documento <uuid> [--todos]` lee y enseña, **sin guardar**. Existe para medir
la tasa de acierto con los documentos de verdad de un grupo —fotografiados con su móvil, sobre una
mesa— antes de decidir si esto puede tocar el manifiesto. Misma disciplina que el ZIP: plan
primero, aplicar después.

**Lo que falta**, y va después de esa medición: el visor con giro, comparar contra
`CotizacionPasajeroIdentificacion` para archivos ya asignados, y crear/completar el manifiesto
desde los no asignados. ⚠️ Sobre esto último, ojo con la clave: `PadronImportador` casa por
`(tipo, numero)` y **no** por el nombre, y aborta ante dos personas con el mismo documento — hay un
incidente detrás en el que una persona desapareció del padrón. El mismo pasaporte dos veces casa
por su número y es seguro; DNI y pasaporte de la misma persona sólo se enlazan por el nombre, y eso
tiene que ser una **propuesta que alguien confirma**, nunca una fusión silenciosa.

#### El control de validación vive en el MANIFIESTO, no en el archivo (09/09/2026)

🔑 **El giro que ordena todo el proceso.** El documento escaneado no es lo que se pone en duda —es
el documento oficial de una persona—: lo que se valida es **lo que alguien tecleó en el
manifiesto**. Así el sello se ve donde se mira el dato, al lado de cada número, y no en una lista
de ficheros aparte.

Se llegó aquí después de construirlo al revés. La primera versión ponía el estado en
`CotizacionFilearchivo`; se descartó sin migrar nada porque las 273 filas seguían en `no_validado`.

##### El reparto de columnas, y por qué abarata todo

| Dónde | Qué guarda |
|---|---|
| `cotizacion_file_archivo` | **la lectura** (`datos_leidos`, `leido_en`, `lectura_error`) |
| `cotizacion_pasajero_identificacion` | **el veredicto** (`estado_validacion`, `discrepancias`, `notas_validacion`, `validado_en`, `validado_con_id`) |

🔑 **Se guarda la lectura, no el veredicto, y el veredicto se recalcula.** El documento no cambia
nunca; el manifiesto cambia todo el rato. Con la lectura cacheada, cada documento cuesta **una
llamada a la IA en toda su vida** (~$0,0016) y un campo recién corregido desaparece de la cola al
instante. Guardar el veredicto en el archivo habría obligado a invalidarlo a mano en cada edición
del manifiesto — y nadie se acuerda de eso.

⚠️ Por eso `LectorDeDocumentoIdentidad` está partido en **`extraer()`** (la llamada cara) e
**`interpretar()`** (puro, gratis). Afinar una regla mañana reinterpreta los 400 documentos ya
leídos **sin pagar una sola llamada**.

⚠️ **`lectura_error` hace falta además de `datos_leidos`.** Sin él, un documento ilegible y otro
que nunca se intentó son la misma cosa —los dos con la lectura vacía— y la tanda lo reintentaría en
cada pasada, pagando cada vez por el mismo fallo.

⚠️ **`validado_con_id` es `SET NULL`, no `CASCADE`.** Si alguien borra el escaneo, el veredicto
**se queda** —se emitió y es cierto que se emitió— pero pierde su respaldo, y eso tiene que poder
verse. Un `CASCADE` lo borraría en silencio y el número seguiría marcado como validado sin nada
detrás.

##### Cuatro estados, porque CÓMO se validó cambia cuánto vale

`ValidacionIdentificacionEnum`: `no_validado`, `observado`, `validado_ocr`, `validado_mrz`.

| | Qué lo respalda | Se puede equivocar en |
|---|---|---|
| `VALIDADO_MRZ` | dígitos de control de la banda: aritmética | nada que un OCR pueda leer mal |
| `VALIDADO_OCR` | la lectura coincide con lo guardado | los dos a la vez, si el error venía del padrón original |

⚠️ **`VALIDADO_MRZ` sólo existe para el pasaporte**: el DNI peruano no lleva banda TD3. Que un DNI
se quede siempre en `VALIDADO_OCR` no es que esté peor leído — **no hay banda que comprobar**, y
confundirlo llevaría a perseguir una calidad de escaneo que no cambiaría nada. `aplicaA()` existe
para que la pantalla no ofrezca un estado imposible: un desplegable que lo permite acaba teniéndolo
en la base.

##### Las discrepancias van estructuradas

`[{campo, documento, manifiesto}]`, no una frase. **La pantalla las pinta al lado de cada campo**, y
una cadena suelta obligaría al front a adivinar de qué campo habla. Guardan **los dos valores**
porque en el caso más frecuente de este expediente —un dedazo en el año, `2026` por `2036`— verlos
juntos *es* la resolución, sin abrir el escaneo. Las `notas` sí van en prosa: son lo que no es de
ningún campo (vencido, banda ilegible) y es para leerlo, no para ramificar.

Campos cotejados: **número, tipo, vencimiento, nacimiento, nacionalidad y nombre**.

🔥 **La nacionalidad necesita un puente y sin él la cola es inservible.** El documento habla ISO-3
(`PER`) y `maestro_pais` tiene el **ISO-2 como clave** (`PE`, 198 filas). Comparar `PER` con `PE` no
falla: **siempre difiere**, así que cada documento sacaría una discrepancia falsa. Lo traduce
`Countries::getAlpha2Code()` de `symfony/intl` —ya instalado— y se guarda ya resuelto en
`DatosDeDocumento::$nacionalidadIso2`. Un código que no existe en ISO da `null`, que es «no se pudo
comprobar», **no** «no coincide».

⚠️ Y ojo con el maestro: hay **dos** `MaestroPais`. El manifiesto usa `App\Entity\Maestro`
(`maestro_pais`, id = ISO-2), no el de `src/Oweb/` (`mae_pais`, id entero + columna `iso2`).

##### La tanda, y por qué se puede colgar de un botón

`app:cotizacion:validar-documentos <expediente> [--forzar] [--limite=N]`.

Es **incremental**: `estaResuelto()` salta lo ya validado, así que una segunda pasada sólo cuesta
lo que falta. Y aunque no lo saltara, la lectura está cacheada: lo caro se paga una vez por
documento, no una por pasada. Pulsar el botón dos veces seguidas no cuesta el doble.

⚠️ **Escribe el veredicto y NUNCA corrige el manifiesto.** Si el documento dice `2036` y el
manifiesto `2026`, deja escrito que no coinciden y para ahí. Corregir es una decisión de una
persona mirando los dos valores — a veces el equivocado será el escaneo.

⚠️ **Cada número se coteja contra SU ficha, no contra «la primera que tenga».** Con DNI y pasaporte
a la vez, cotejar el pasaporte contra el número del DNI daría «número no coincide» **siempre**: un
aviso falso repetido en medio manifiesto.

⚠️ **`CE` y `CI` no se pueden validar** —no hay tipo de archivo que les corresponda— y se quedan en
`NO_VALIDADO` con su nota. Es correcto, no un fallo: inventarles un archivo genérico haría que se
cotejaran contra el documento de otra cosa.

#### El botón, y por qué no lleva confirmación (09/09/2026)

`POST /platform/sales/client/cotizacion_file/{id}/validar-manifiesto` →
`ValidarManifiestoProcessor`. En pantalla, un botón en la cabecera del manifiesto con el contador
de observados al lado.

⚠️ **No pide confirmar, y es una decisión, no un olvido.** Pulsarlo dos veces seguidas **no cuesta
el doble**: lo ya resuelto se salta y la lectura de cada documento está cacheada en el archivo. No
hay nada que confirmar porque no hay nada que perder.

⚠️ **Devuelve el expediente entero** (`file:item:read`) en vez de un resumen: con 135 personas, una
segunda vuelta para repintar se nota.

⚠️ **El botón va FUERA del botón que pliega la sección.** Dentro, pulsarlo plegaría el manifiesto
justo cuando llegan los resultados que se quieren ver.

⚠️ Y al lado dice **«escribe el veredicto, no corrige el manifiesto»**. Un botón llamado «validar»
invita a pensar que arregla; aquí lo que se corrige lo corrige una persona mirando los dos valores,
porque a veces el equivocado es el escaneo.

##### El sello va pegado al número

En la ficha de cada persona, no en una pantalla aparte: lo que se valida es lo que alguien tecleó,
así que el veredicto tiene que verse **donde se mira el dato**. Una lista aparte hay que acordarse
de ir a mirarla.

La discrepancia se pinta con **los dos valores juntos** —`2036` ← `2026`— porque en el caso más
frecuente eso *es* la resolución: se ve el dedazo sin abrir el escaneo ni cambiar de pantalla.

⚠️ **Las `no_validado` SIN nota no se pintan.** Son las que nadie ha mirado todavía, y marcar las
263 en gris antes de la primera pasada llenaría el manifiesto de ruido. Las que traen nota —«no hay
escaneo en la bóveda»— sí: eso es información.

⚠️ **`SELLO` en `FileDetalle.vue` es espejo de `ValidacionIdentificacionEnum::getColor()`: se tocan
los dos.** Y los dos verdes son distintos a propósito — `validado_mrz` son dígitos de control,
`validado_ocr` son dos lecturas que coinciden y pueden fallar las dos si el error venía del padrón
original. Pintarlos igual borraría la única diferencia que importa.

##### Lo que dio la primera tanda real (263 números)

| | |
|---|---|
| Validado (MRZ) | 63 |
| Validado (OCR) | 90 |
| **Observado** | **30** |
| Sin validar | 80 — todos por «no hay escaneo en la bóveda» |

De los 183 con escaneo, **153 validados (84 %)**. Y los 30 observados, por campo: **17
vencimientos**, **8 números**, 1 nacimiento. Diecisiete fechas mal tecleadas y ocho números que no
son los del documento — un número mal en un manifiesto es un problema en el aeropuerto.

Coste de la tanda entera: **~$0,30**. Y no se vuelve a pagar: las lecturas quedaron cacheadas.

#### «No hay escaneo» seguía escrito con los escaneos delante (09/09/2026)

🔥 **Un veredicto es la foto de un momento, y una foto de una AUSENCIA caduca en cuanto aparece
lo que faltaba.** Caso real, con las horas:

```
20:56   la tanda escribe «no hay escaneo de este documento en la bóveda»   ← era verdad
23:34   se suben los tres documentos
        la ficha sigue diciendo que no hay ninguno, con el visor enseñándolos
```

Y no era el único caso: **un escaneo mejor de un documento ya `validado_mrz` no se miraba nunca**,
porque la tanda salta lo resuelto.

`EscaneoNuevoInvalidaVeredictoListener` devuelve a la cola el veredicto del número que ese escaneo
respalda, en cuanto el escaneo llega o cambia de manos.

⚠️ **Cubre también la reasignación.** El changeset dice de quién ERA el archivo, así que mover
un documento de una persona a otra invalida los dos veredictos: el nuevo, que gana respaldo, y el
anterior, **que se queda con un sello apoyado en un escaneo que ya no es suyo**.

⚠️ **`onFlush` y no `postPersist`.** Hay que modificar otra entidad dentro de la misma
transacción, y en `postPersist` el `UnitOfWork` ya cerró los cambios: haría falta un `flush()`
anidado. `recomputeSingleEntityChangeSet()` es el mecanismo previsto, y ya se usa así aquí.

⚠️ `NUMERO_DE` es **espejo** de `ValidadorDeManifiesto::ESCANEO_DE`: si un día el DNI se
valida contra otro escaneo, hay que tocar los dos.

#### «Sin emitir» no se podía quitar (10/09/2026)

🔥 **`emitido` sólo lo ponía el cargador de vuelos por JSON, y no había ninguna pantalla que lo
tocara.** Es un estado que cambia solo con el tiempo —la aerolínea emite los billetes y ya está—,
así que dejarlo únicamente en la carga significaba **volver a cargar el JSON entero para corregir un
booleano**, o que la cabecera de Vuelos siguiera diciendo «3 sin emitir» para siempre.

El campo ya estaba en `file:write`: se podía escribir por API y nadie lo hacía. Ahora hay un
interruptor en el modal del subgrupo.

⚠️ **Vive en el SUBGRUPO y no en el vuelo, y ésa es la razón de que cueste encontrarlo.** Lo
que se emite son los billetes de una **reserva**, y una reserva cubre ida y vuelta: los dos vuelos
de un mismo PNR se emiten juntos. Ponerlo en el vuelo obligaría a marcar dos veces lo mismo y
permitiría estados imposibles —la ida emitida y la vuelta no, con el mismo billete.

Por eso el interruptor sólo sale cuando el subgrupo es del eje aéreo: en una habitación no
significa nada.

#### «Sin foto» y «Observado» son dos trabajos distintos (10/09/2026)

Dos chips en la fila de Documentos, y **la distinción importa más que los chips**:

| | Qué significa | Quién lo resuelve |
|---|---|---|
| **Observado** | el escaneo no dice lo mismo que el manifiesto | quien corrige el manifiesto, mirando |
| **Sin foto** | el número está tecleado y **nadie subió el escaneo** | quien **escribe** al pasajero |

🔥 **Juntarlos habría mezclado dos trabajos que hacen personas distintas en momentos
distintos.** «Sin foto» no es una validación pendiente: no hay nada que comprobar todavía. Son 44
personas a las que hay que pedirles el documento, y ésa es una lista que se usa entera de una
sentada.

##### El mapeo escaneo↔número llegó a estar en tres sitios

Para saber a quién le falta la foto hay que saber **qué escaneo respalda cada número**, y esa
pareja estaba en `ValidadorDeManifiesto`, repetida en `EscaneoNuevoInvalidaVeredictoListener`, y a
punto de deducirse a ojo en el front — un cuarto.

⚠️ Son mapeos que **tienen que decir lo mismo o el sistema se contradice**: uno valida el DNI
contra el anverso y otro cree que lo respalda otra cosa. Ahora la única definición es
`ArchivoTipoEnum::respaldaA()` —con su inverso `paraValidar()`— y la identificación expone
`tipoDeEscaneo` calculado, así que el front **lee un valor** en vez de reimplementar la regla.

#### El filtro «Observado», junto a los de vencimiento (09/09/2026)

Un chip más en la fila de **Documentos**, con su recuento: es lo que convierte los 30 observados en
una lista de trabajo en vez de una cifra.

⚠️ **Va en la MISMA fila que Vencido / Vence < 1 año / Sin comprobar, no en un grupo aparte.**
El primero describe *trabajo pendiente* y los otros tres describen *el documento*, así que la
tentación es separarlos. Pero quien abre estos filtros se hace **una sola pregunta** —«¿qué
documentos me dan problema?»— y tener que mirar en dos sitios para responderla es lo que hace que
no se mire ninguno.

Sale primero de los cuatro, porque es el único sobre el que hay algo que hacer hoy.

#### Lo que sacó la revisión de Fable (09/09/2026)

Nada de esto lo cazaba PHPStan, ni los tests, ni el typecheck. Cinco eran mudos.

##### El veredicto no se invalidaba nunca

🔥 **Corregir mal un número ya validado lo dejaba en verde para siempre.** La tanda salta lo
resuelto, así que nada volvía a mirarlo. Ahora los setters de los campos que se cotejan —número,
tipo, vencimiento— llaman a `invalidarVeredicto()` y el número vuelve a la cola con su motivo.

🔥 **Y un veredicto sin su escaneo tampoco cuenta como resuelto.** `validado_con_id` es
`SET NULL` al borrar el archivo, así que cuando el pasajero re-sube su documento desde `pax` —que
borra el viejo y crea otro— el número seguía en verde **y el escaneo nuevo no se leía jamás**.
`estaResuelta()` exige ahora el respaldo vivo.

##### Una ficha creada desde el escaneo se validaba contra sí misma

El panel de sueltos escribe número y nombre desde la lectura; en la siguiente tanda el cotejo veía
un manifiesto que coincide al 100 % —porque salió de ahí— y lo sellaba `VALIDADO_OCR`. **Un sello
verde puesto por el propio dato que había que comprobar.**

⚠️ La guarda de `Cotejo` («sin nada guardado no se valida solo») protegía **sólo la ruta en
memoria**: en cuanto la ficha persiste, deja de existir. Por eso hace falta `copiada_del_escaneo`,
que la sobrevive — y se apaga en cuanto una persona toca el dato, porque a partir de ahí ya no es
una copia: es lo que alguien afirma.

##### El botón perdía las lecturas pagadas

php-fpm corta a los **90 s** y un expediente nuevo son ~183 lecturas × 3,5 s ≈ **10 minutos**. Con
un solo `flush()` al final, el corte tiraba **todas las lecturas ya pagadas** y la siguiente
pulsación volvía a pagarlas para tampoco terminar. Ahora se guarda **según se lee**: cada pulsación
avanza lo que le dé tiempo y nada se paga dos veces.

##### `strtr()` con dos cadenas opera byte a byte

`«José Pérez Núñez»` salía como `JOSO` y `REZ`. Latente aquí —los doce nombres con tilde compartían
otras palabras— pero «José Pérez» a secas habría dado un «nombre no coincide» falso. Ahora usa
`Transliterator`, que cubre cualquier alfabeto y no la lista de acentos que uno recuerde.

##### «Dos palabras en común» no distinguía a dos hermanos

`PEDRO QUISPE MAMANI` con el número de `JUAN QUISPE MAMANI` compartía los dos apellidos y se
validaba — **el caso exacto que este control existe para cazar**. En una familia, los apellidos son
lo que TIENEN en común; lo que separa es el nombre de pila.

⚠️ **El primer arreglo —mirar «las dos primeras palabras»— también fallaba**, porque en «Pedro
Quispe Mamani» el apellido cae ahí dentro. La solución no era adivinar mejor: era **dejar de
concatenar**. Las dos fuentes traen nombre y apellidos separados de origen, así que se comparan
campo con campo y no hay nada que adivinar.

##### La carrera al girar

`file_exists()` + `copy()` son dos pasos y entre ellos cabe otra petición: dos giros a la vez y el
segundo copiaría como «original» el fichero que el primero acaba de girar. Ahora usa `link()`, que
crea sólo si no existe, en una sola operación.

##### 58 notas fósiles

«El escaneo está girado N°» las escribió la versión que ponía el giro en el veredicto. Al sacarlo
de ahí dejaron de escribirse, pero las guardadas **no se van solas** —la tanda salta lo validado—
así que un documento ya enderezado seguía diciendo que estaba torcido. Y eso manda a girarlo otra
vez, a un 14 % de calidad por giro. Limpiadas por migración.

##### Y código muerto

`ValidadorDeDocumento::analizar()`, `buscarDueno()`, `fichaDe()`, `ResultadoDeValidacion` y `Accion`
quedaron sin consumidor al mover la orquestación a `ValidadorDeManifiesto`. `fichaDe()` además
**duplicaba `FichaGuardada::de()` con la regla contraria** —«la primera identificación que tenga»
en vez de la que se está validando—, que es justo el fallo del que se dejó aviso escrito.

#### Dos fallos que sólo se veían mirando los datos (09/09/2026, revisión)

**1. La regla del dígito verificador escondió un pasaporte mal tecleado.** Se aplicaba a
**cualquier** tipo, y un pasaporte **no lleva dígito de control**. En producción:

```
PASAPORTE   manifiesto 1222988343   documento 122298834   →  VALIDADO_MRZ
```

Un número de pasaporte con un dígito de más, en verde y «respaldado por aritmética». Es justo el
problema de aeropuerto que este control existe para cazar, y **la regla que limpiaba el ruido se lo
comió**. Un filtro de ruido que se come una señal es peor que el ruido.

Ahora se exigen las tres cosas: **que sea un DNI**, longitudes **exactamente 8 y 9**, y prefijo. Un
DNI truncado a 7 vuelve a ser una diferencia. Hay un test de regresión con el caso real.

⚠️ Y este doc llamaba a ese caso «convención del padrón»: era un pasaporte. El error estaba también
en la explicación.

**2. Toda la interfaz de giro estaba invisible.** La pantalla leía `datosLeidos.rotacion`, una clave
que **el modelo dejó de devolver** al cambiarle la pregunta a `bordeSuperior`. Medido: **211 de 211
lecturas tienen `bordeSuperior` y cero tienen `rotacion`**. Ni «falta girar», ni el botón resaltado
— y sin un solo error.

El mapeo llegó a estar en tres sitios (lector, girador, y un cuarto que el front leía). Ahora vive
en **`Orientacion`** y sólo ahí, la entidad lo expone calculado (`rotacionPendiente`) y el front
**lee un número**: un espejo en TypeScript se habría vuelto a desincronizar.

#### El giro estaba en el veredicto, y ése era el error de fondo (09/09/2026)

Un giro tardaba **~20 segundos**. Medido en el servidor: girar y reescribir son **0,42 s** y una
lectura de IA **3,5 s**. Los otros **16 los ponía la pantalla**, recargando el expediente entero
—133 personas, sus identificaciones, 267 archivos y los vuelos— para reflejar el cambio de UN
fichero.

🔥 **La causa era haber metido el aviso de giro en el veredicto de la identificación**, y de ahí
salían los tres síntomas a la vez:

| Síntoma | Por qué |
|---|---|
| 20 s por clic | enderezar obligaba a recalcular el veredicto → releer con IA + recargar todo |
| «el DNI está girado» sin decir cuál | el veredicto sólo mira **el escaneo que respalda ese número** |
| girar uno borraba el aviso del otro | el reverso nunca se valida, así que su giro era invisible |

**El giro es una propiedad del ARCHIVO, no del veredicto de un número.** Vive en `datos_leidos` y
la pantalla lo lee de ahí, **escaneo por escaneo**: ahora hay un aviso por cada uno, diciendo cuál
es y cuántos grados le faltan.

##### Y girar ya no relee: corrige la orientación con aritmética

**Girar no cambia lo que dice el documento.** El número, las fechas y la MRZ son los mismos; lo
único que deja de ser cierto es la orientación, y ésa se sabe sin preguntarle a nadie — la acabamos
de aplicar nosotros. Si al escaneo le faltaban `r` grados y le aplicamos `g`, ahora le faltan
`r − g`.

Así que la lectura **se corrige, no se tira**. Un escaneo que se leyó bien estando torcido no
necesita releerse por enderezarlo — y eran muchos: de los 105 validados por MRZ, buena parte están
girados.

⚠️ Y el endpoint devuelve **sólo lo que cambió** —la marca de tiempo y la orientación nueva— para
que el front parchee en sitio. Recargar el expediente por un fichero era el 80 % del tiempo.

#### El DNI peruano SÍ lleva banda, y estaba en el anverso (09/09/2026)

🔥 **Se daba por hecho que no la tenía.** Está escrito en tres sitios de este doc: «el DNI peruano no
lleva banda TD3», «que un DNI se quede siempre en `VALIDADO_OCR` no es que esté peor leído». Era
falso: **el DNI nuevo lleva MRZ en el ANVERSO, formato TD1** —tres líneas de 30—, y se vio mirando
un escaneo de cerca.

Con eso, **un DNI puede llegar a `VALIDADO_MRZ`**, respaldado por aritmética igual que un pasaporte.
Son 86 anversos del padrón que pasan de «dos lecturas que coinciden» a «los dígitos cuadran».

| | Formato | Dónde |
|---|---|---|
| Pasaporte | TD3, 2 × 44 | página de datos |
| **DNI peruano** | **TD1, 3 × 30** | **anverso** |

⚠️ **El formato se decide por la LONGITUD, no por la letra inicial.** La `P` del pasaporte no vale
como única señal: un TD1 también empieza por letra (`I<PER…`).

⚠️ **Y `tipo()` decía «hay MRZ ⇒ es un pasaporte».** Con TD1 eso guardaría un DNI como PASAPORTE,
con el número del DNI dentro. Ahora manda el prefijo del formato.

⚠️ Los dígitos de control son **los mismos cuatro**, en otras posiciones. La cuenta se escribe una
vez; duplicada acabaría divergiendo y un DNI daría por bueno lo que un pasaporte rechaza.

Lo que sigue sin poder llegar a MRZ es lo que no tiene banda de ninguna clase —`CE`, `RUC`—, y hay
un test para cada mitad.

⚠️ **El test que decía «un DNI NUNCA puede llegar a MRZ» falló en rojo el día que se corrigió**, que
es exactamente para lo que estaba escrito.

##### Y por eso no hace falta juntar anverso y reverso

Con la banda en el anverso, **el anverso se sostiene solo**: número, fechas, sexo y nacionalidad
salen de ahí con sus dígitos. El reverso sigue sin ser validable y eso es correcto — no es un
documento mal leído, es uno que no tiene qué cotejar.

#### El giro: «falta girar», no «parece girado» (09/09/2026)

El número es una **acción** —cuántos grados en sentido horario hay que aplicar— y el rótulo lo
describía como un **estado**. Un escaneo que se ve girado 90° necesita **270°** para enderezarse,
así que «parece girado 270°» contradice al ojo y hace dudar del botón que está bien. El valor
siempre fue correcto; mentía la frase.

⚠️ **Y la imagen no se refrescaba tras girar.** El fichero se reescribe **en su sitio** —a
propósito, para que los píxeles sean la verdad— así que la URL no cambia y el navegador seguía
sirviendo la versión vieja: se giraba, la petición iba bien, y en pantalla no pasaba nada. Se le
cuelga `?v=<updatedAt>`, que cambia en cada escritura.

#### Girar un escaneo: el ángulo se detecta, pero se hornea en los píxeles (09/09/2026)

La extracción devuelve además **`rotacion`** —0, 90, 180 o 270 grados en sentido horario— y viaja
gratis dentro de `datos_leidos`, porque sale de la misma pasada que los datos. El visor precarga el
botón con ese valor, así que el caso normal es **un clic** y no adivinar el sentido.

🔥 **Pero girar REESCRIBE el fichero, y esto es lo que no se debe cambiar.** Guardar el ángulo y
aplicarlo al mostrar es exactamente el fallo que documenta `liip_imagine.yaml` del 08/09/2026: la
orientación vivía en el EXIF, el navegador la respetaba y el procesador no, así que **el huésped
veía su pasaporte derecho, lo confirmaba, y al operador le llegaba tumbado** — sin forma de
comprobarlo, porque el escaneo no se le devuelve.

Un campo aplicado al mostrar reintroduce ese fallo **con más consumidores que antes**: la bóveda,
el visor, la descarga del gate y **el propio lector de IA**, que leería la imagen cruda y volvería
a fallar. Cualquiera que se olvide de aplicarlo la ve torcida, y ninguno da error.

##### Girar limpia su propia sugerencia — y dos fallos que salieron al hacerlo

Al enderezar un escaneo, el aviso «está girado 270°» tiene que desaparecer. Vive en el **veredicto
de la identificación**, no en el archivo, así que no basta con tirar la lectura: hay que
**recalcular el veredicto**. `GiradorDeEscaneo` revalida al dueño en el acto.

⚠️ Sin eso, el documento queda derecho y la ficha sigue diciendo que está torcido — **peor que no
avisar**, porque manda a girar otra vez uno que ya está bien, y cada giro cuesta calidad.

🔥 **Y girar dejaba el documento inservible para siempre.** Usaba `registrarLectura(null)`, que
vacía la lectura pero **deja `leidoEn` puesto** — y esa combinación significa «se intentó y falló»:
`ValidadorDeDocumento::lecturaDe()` devuelve `null` para siempre y el documento se queda en
`no_validado` sin que nada vuelva a mirarlo. **La única acción cuyo propósito es que se relea era
justo la que impedía releerlo**, y no daba ningún error.

Ahora hay `olvidarLectura()`, que devuelve el archivo a «nunca se ha leído» —los tres campos a
`null`— y está separado de `registrarLectura()` precisamente para que no se confundan otra vez. El
docblock de `registrarLectura()` lo dice.

##### Dónde aparece el flag

Se detecta **al leer el documento** —en la misma llamada que saca los datos, sin coste extra— y
sale en **dos sitios**:

| Dónde | Qué se ve |
|---|---|
| la **fila del manifiesto** | una nota: «el escaneo está girado 90°: se puede enderezar desde el visor» |
| el **visor** | el botón de ese ángulo, resaltado, con «parece girado 90°» |

🔥 **La nota es informativa, NO bloqueante, y ésa es toda la sutileza.** Un documento girado con la
MRZ cuadrando está **perfectamente leído**: enderezarlo es cosmética y ayuda a las lecturas futuras,
no arregla ésta. Si bajara el sello a `observado`, cada foto torcida llenaría la cola de trabajo de
cosas que no hay que decidir — y una cola con más ruido que trabajo se deja de mirar entera. Hay un
test por cada mitad: que avisa, y que no baja el sello.

⚠️ **Y tenía que salir en la fila, no sólo en el visor.** La primera versión sólo lo enseñaba
dentro del modal de cada persona: con 135 personas, un aviso que hay que ir a buscar abriendo a cada
una no lo encuentra nadie.

⚠️ **Sólo múltiplos rectos (90/180/270).** Un escaneo torcido 7° existe, pero corregirlo obliga a
reinterpolar todos los píxeles y rellenar esquinas: se pierde nitidez justo donde importa, en la
letra pequeña. Los rectos son una permutación de píxeles y no pierden nada.

🔥 **Cada giro REENCODEA, y eso cuesta calidad.** Aquí llegó a decir que «no se recomprime» y era
**falso**. Medido en el servidor sobre un escaneo real de 2400×1800:

| | Peso |
|---|---|
| original | 192 KB |
| 1 giro | 164 KB (**−14 %**) |
| 2 giros | 156 KB (−19 %) |
| 4 giros | 139 KB (**−27 %**) |

Es **pérdida de generación**: reescribir un webp con pérdida vuelve a cuantizar lo ya cuantizado.
⚠️ Y `setImageCompressionQuality()` **no cambia nada** —88, 92 y 95 dan el mismo tamaño que el
defecto—: este Imagick sólo distingue con pérdida de sin pérdida. Sin pérdida son 2 426 KB, **doce
veces más**, ~1 GB para los 400 documentos del grupo: descartado.

Una pasada es asumible; encadenarlas no. Por eso el visor **sugiere el ángulo detectado**: que el
caso normal sea un solo clic no es cosmética, es lo que acota la pérdida.

**Si llega a molestar**, el arreglo es guardar una copia antes del primer giro y regirar siempre
desde ella — acota la pérdida a una generación para siempre, a cambio de un fichero más por
documento girado. No se hizo todavía porque sólo paga la pena si se gira mucho.

⚠️ **Los PDF se rechazan con una frase**, no se giran. Hacerlo obliga a rasterizarlos, y eso cambia
un PDF de identidad por una imagen peor sin que nadie lo haya pedido.

🔑 **Girar TIRA la lectura** (`registrarLectura(null)`). Un documento torcido casi siempre se leyó
mal —es la razón de girarlo—, así que conservarla dejaría el veredicto apoyado en lo que se leyó
del revés. Sin lectura, la siguiente tanda lo relee ya derecho: ~$0,0016, que es justo lo que se
buscaba al girarlo. La pantalla lo avisa: ese veredicto se queda sin respaldo hasta la próxima
pasada.

La caché de miniaturas se regenera sola — `CotizacionFilearchivoCacheListener` ya escucha
`preUpdate`, así que basta con que el `flush()` toque la entidad.

#### La hoja de documentos: las observaciones dentro, y decir que va filtrada (09/09/2026)

**Los filtros ya se respetaban** —el front manda la lista de ids en vez de repetir los filtros en el
servidor, que serían dos implementaciones de la misma pregunta— y el botón ya mostraba el recuento.
Lo que faltaba era lo de después.

⚠️ **Una vez descargada, la hoja no decía que fuera un subconjunto.** Puede llevar 30 de 133
personas y se reenvía por correo como si fuera el manifiesto entero — quien la reciba concluye que
a los otros 103 no les falta nada. Ahora el título lo dice (`⚠ SELECCIÓN FILTRADA: 30 de 133`) **y
el nombre del fichero también** (`documentos-filtrado-30.xlsx`): un adjunto se reenvía por su
nombre, muchas veces sin abrirlo.

##### La columna `Observaciones`

Va **la última**, a propósito. Las columnas de la izquierda contestan «¿me falta un documento?», que
es a lo que se abre esta hoja; ésta contesta «¿lo que tengo está bien?», que es otra pregunta y
llegó después. Metida en medio empujaría a la derecha las tres columnas de estado que la gente ya
sabe dónde están.

⚠️ **Se compone con el campo y los DOS valores** —«DNI vencimiento: doc 2036-07-31 ≠ guardado
2026-07-19»—, no con un «tiene observaciones». Esta hoja se manda por correo a quien tiene que
corregir, y ahí no hay botón que pulsar para ver el detalle: o va escrito, o hay que volver a la
aplicación, que es justo lo que la hoja evita.

⚠️ **Las notas informativas —el giro— NO entran.** Son para la pantalla, donde hay un botón al lado
que lo arregla. En una hoja de correcciones sólo serían ruido.

El resumen del pie añade «N con observaciones» cuando las hay.

#### El dígito verificador del DNI: 8 de 10 avisos de número eran falsos (09/09/2026)

Medido sobre el expediente real. De las 10 discrepancias de `número`, **sólo 2 eran de verdad**:

| Lo que dice el documento | Lo que hay guardado | Casos |
|---|---|---|
| `73716768-8` | `73716768` | 6 — el escaneo trae el dígito, el padrón no |
| `122298834` | `1222988343` | 2 — al revés: el padrón lo trae de más |
| `125853071` | `61859757` | **2 — diferencias reales** |

El DNI peruano son 8 dígitos **más uno de control**, y cada lado guarda una convención distinta sin
que nadie lo haya acordado.

⚠️ **La regla es SIMÉTRICA**, porque cualquiera de los dos lados puede ser el largo. Escrita en una
sola dirección habría limpiado seis avisos y dejado dos — peor que no hacer nada, porque daría la
impresión de estar resuelto.

⚠️ **Y exactamente un carácter, no «hasta dos».** Con dos, `12229883` y `1222988343` pasarían por el
mismo documento, y eso ya no es una convención: es un número mal tecleado. `Cotejo::mismoNumero()`,
con un test por cada caso — incluido el de dos dígitos, que **sí** se señala.

Las 2 diferencias reales no se parecen en nada entre sí, así que ninguna regla de prefijo las toca.

#### Reprocesar a una sola persona (09/09/2026)

`POST /cotizacion/user/manifiesto/pasajero/{id}/revalidar`, y un botón en su fila.

⚠️ **No relee el documento**: coteja la lectura cacheada contra lo que hay guardado **ahora**. Es
justo lo que hace falta tras corregir un dato —comprobar si el aviso se fue— y **cuesta cero**. Sólo
paga si el escaneo se giró, porque girar tira la lectura a propósito.

⚠️ **Va siempre a fondo**, sin saltarse lo resuelto. En la tanda, saltárselo es lo correcto; aquí
sería no hacer nada y parecer que sí, porque quien pulsa acaba de tocar algo de esa persona.

⚠️ `validarUna()` está **extraído** para que la tanda y el reprocesar hagan exactamente lo mismo.
Duplicado, una regla cambiaría en un sitio y no en el otro, y nadie lo notaría hasta que los dos
caminos dieran veredictos distintos del mismo documento.

#### Cada valor lleva escrito de dónde sale (09/09/2026)

La discrepancia se pintaba sólo con color —verde el documento, ámbar el manifiesto— y **el color no
dice cuál es cuál**: había que deducirlo, y quien deduce mal **corrige el lado equivocado**. Ahora
cada valor lleva su etiqueta (`DOC` / `GUARDADO`). Un color es un refuerzo, nunca la etiqueta.

#### Dos fallos del visor, y los dos ya estaban documentados en otro sitio (09/09/2026)

**1. Girar contestaba «Not Found».** `CotizacionFilearchivo` **no exponía `id`** —`IdTrait` no lleva
grupos—, así que el front sólo veía el `@id`. `extractIdStr(doc.id)` devolvía `''` y la URL quedaba
`/documentos-sueltos//girar`: un 404 **del router**, no del controlador. Que el mensaje fuera «Not
Found» en inglés y no «No encontré el documento» es lo que lo delató.

⚠️ Y rompía otra cosa a la vez, en silencio: `:key="doc.id"` en la bóveda hacía que **todas las
filas compartieran la clave `undefined`**. Es el mismo incidente ya documentado para
`PaxFilearchivo` en §2.

Arreglado como se arregló `CotizacionVuelo` cuando hubo que escribir su relación: **redeclarando
`getId()` con `#[Groups(['file:item:read'])]`**. `pax_file:read` sigue sin él, que es lo correcto.

**2. El gesto de atrás salía del expediente en vez de cerrar el panel.** El visor y el panel de
sueltos se abrían con un `v-if` suelto, **sin registrarse en `useCapasEnHistorial`** — que existe
exactamente para esto. Un diálogo que no está en la pila no existe para el botón atrás, así que el
móvil hacía lo único que podía: retroceder en el historial de verdad.

Ahora van por `capas.abrir('visor-doc' | 'sueltos', …)`, como el resto.

⚠️ **La regla que sale de los dos:** un diálogo nuevo en esta vista se abre **siempre** por
`capas`, y un recurso al que haya que construirle una URL **tiene que exponer su `id`**. Los dos
fallos existían ya en el proyecto, escritos, y los repetí igual.

#### El visor por persona: el puente entre «no coincide» y «mira el papel» (09/09/2026)

Botón **«Ver sus documentos»** en cada fila del manifiesto, justo debajo del veredicto. Abre un
modal con **los escaneos de esa persona y lo que se leyó de cada uno**.

🔑 **Era el eslabón que faltaba.** El veredicto ya dice «vencimiento: `2036` ← `2026`», pero para
cerrarlo hay que mirar el documento — y hasta ahora eso obligaba a ir a la bóveda y buscarlo entre
~1 500 archivos, que es justo el trabajo que este módulo venía a quitar.

⚠️ **Abrirlo no cuesta NADA.** No hay llamada a la IA: los escaneos ya están guardados y su lectura
está cacheada en el propio archivo. Sólo paga un documento nuevo que nunca se haya leído
(~$0,0016), y de eso se encarga la tanda, no el visor.

⚠️ **Ni una petición nueva.** Los archivos vienen enteros en `file:item:read`, así que el visor
filtra sobre lo que ya está cargado. Pedirlos por persona serían 135 peticiones para nada.

⚠️ **La discrepancia se repite ARRIBA, dentro del modal.** Si hay que bajar a buscarla, se acaba
mirando el documento sin recordar qué se estaba comprobando.

⚠️ `esImagen()` pregunta a **`tipoMedio`**, que el backend calcula desde la extensión real en disco
—la que puso el `Namer` a partir de lo que Symfony dedujo del contenido—, no del nombre que trajo
el cliente. Fiarse del nombre es lo que hacía que un vídeo se anunciara como PDF (§ «El icono decía
PDF para todo»). Un PDF se enlaza en vez de meterlo en un `<img>`, que quedaría en blanco.

⚠️ El botón sólo sale si esa persona **tiene** escaneos: un botón que abre un modal vacío es peor
que no tenerlo.

#### El panel de resolución: la ambigüedad se enseña, no se resuelve sola (09/09/2026)

Botón **«Sin dueño»** en la cabecera de la bóveda. Va ahí y no en el manifiesto porque el problema
es del archivo —«¿de quién es esto?»— y no de la persona.

`GET /cotizacion/user/documentos-sueltos/{id}` da el plan; `POST …/{id}/resolver` resuelve uno.

⚠️ **Dos rutas y no una.** El plan se puede pedir las veces que haga falta y no toca nada; resolver
escribe y puede **crear una persona**. Un endpoint que «hace lo que corresponda» es como se acaba
creando gente por recargar una pantalla.

##### Un documento sin dueño era un callejón sin salida

🔥 **La tanda de validación nunca toca un archivo sin dueño.** Recorre `pasajeros →
identificaciones → su escaneo` (`escaneoDe()` hace `findBy(['pasajero' => …])`), así que un
huérfano no entra por ninguna parte. Y el panel sólo ofrece acciones sobre lo ya leído.

Resultado: subías una foto sin asignar, el panel la listaba diciendo «pasa antes *Validar contra
los escaneos*» — **y esa tanda no iba a leerla jamás**. La instrucción no es que no ayudara:
mandaba a un sitio que no iba a hacer nada.

Ahora cada suelto sin leer trae su botón **«Leer el documento»**, con lo que tarda escrito al lado.

⚠️ **A petición y de uno en uno, no al abrir el panel.** Leer cuesta ~$0,0016 y ~3,5 s; con
cincuenta sueltos, hacerlo al abrir serían cincuenta llamadas y tres minutos que nadie pidió.

⚠️ El endpoint **hace `flush()`**: `lecturaDe()` deja la lectura puesta en la entidad pero no
guarda —quien orquesta decide cuándo—, y sin ese flush la llamada a la IA se pagaría otra vez en la
siguiente vuelta.

⚠️ Al leer uno **se recarga la lista entera**: pueden aparecerle candidatos, y también cambiarles
los candidatos a los demás si el número casa con alguien.

##### `candidatosPara()` devuelve TODOS, y ése fue el cambio

La primera versión devolvía `null` ante dos personas con el mismo nombre — «no sé» dicho de la peor
manera: el panel no podía ofrecer las dos y quien miraba tenía que buscarlas a mano.

🔥 **Dos hermanos con los mismos apellidos no son un fallo del buscador: son el caso normal de una
familia.** Lo único que falta es que alguien señale cuál. Ahora se listan todos, cada uno con su
motivo, y **la certeza va en el dato y no en el orden**: una lista «el mejor primero» hace que el
primero se elija por estar arriba.

| `por` | Qué es | Cómo se pinta |
|---|---|---|
| `numero` | su documento ya está guardado: un **hecho** | verde |
| `nombre` | sólo se parece el nombre: una **sugerencia** | ámbar |

⚠️ Si el número casa con uno y el nombre con otro, **salen los dos**. Eso es una contradicción que
hay que poder ver, no esconder.

##### Crear es el camino más largo, a propósito

`crear` va siempre al final, en gris, con pregunta de confirmación, y su texto cambia a «**No es
ninguno**: crear ficha nueva» cuando hay candidatos. Es la única acción que **añade una persona**, y
dos fichas de la misma persona rompen todos los conteos del manifiesto sin que nadie las eche de
menos.

⚠️ **`ResolutorDeDocumentoSuelto::crear()` va en transacción.** Crear la persona, crear su
identificación y colgarle el archivo son tres escrituras que no pueden quedar a medias: una persona
sin su documento es una fila fantasma en el manifiesto.

⚠️ **Y comprueba que el archivo sigue sin dueño antes de escribir.** Entre que el panel se pintó y
alguien pulsó, otra persona pudo asignarlo; sin esto, dos operadores mirando la misma cola crean la
misma persona dos veces.

⚠️ **Se resuelve de uno en uno.** Un «resolver todos» aplicaría también las corazonadas por nombre
—las que se equivocan en las familias— y deshacerlo son tantas decisiones como se tomaron de golpe.

⚠️ **Al resolver se recarga la lista ENTERA, no se quita la fila.** Crear una persona cambia los
candidatos de los demás documentos: el siguiente ya puede casar por nombre con la que acaba de
nacer, y una lista que sólo pierde su fila enseñaría candidatos caducados.

##### El país al crear

Se resuelve por `nacionalidadIso2`, que ya viene traducida del ISO-3 del documento. Si ese país no
está en `maestro_pais` (198 de ~249), **se deja vacío**: un país que falta en el maestro es un dato
que añadir al maestro, no un error del documento — y no se inventa la fila.

⚠️ `DocumentoSuelto` en `fileDetalleModel.ts` es un **tipo a mano**, y aquí está justificado: el
controlador **no es un `ApiResource`**, así que no entra en la introspección de OpenAPI y no sale en
`api.d.ts`. Es espejo de `DocumentosSueltosController::plan()`: si cambia uno, cambia el otro.

#### La bóveda arranca plegada (08/09/2026)

Vive en la barra lateral, encima de todo lo demás, y en un expediente grande son ~1 500 archivos.
Abierta empujaba hacia abajo lo que se mira a diario. El contador va en la cabecera para que
plegada no se lea como vacía, y el botón de subir aparece al abrirla.

#### El icono decía PDF para todo (08/09/2026)

Se subió un vídeo a la bóveda y la lista lo anunció como PDF. El icono estaba escrito a fuego
—`far fa-file-pdf`— en las **dos** apps, así que un vídeo, una foto de pasaporte o una hoja de
cálculo se veían igual que un boleto. No rompe nada, y por eso llevaba ahí desde siempre: sólo hace
que la lista mienta. En una bóveda de ~1 500 archivos eso obliga a abrirlos para saber qué son.

Lo dice ahora `CotizacionFilearchivo::getTipoMedio()` —`pdf`, `imagen`, `video`, `audio`, `hoja`,
`otro`—, sacado de la **extensión del nombre en disco**, que la puso el `Namer` a partir de lo que
Symfony dedujo del contenido, no del nombre que traía el cliente. Cada app elige su icono.

⚠️ **Y al anclar `PaxFilearchivo` al esquema salió un fallo que el tipo a mano tapaba:** declaraba
un `id` que `pax_file:read` **no serializa**, y la portada lo usaba como `:key` del `v-for`. Todas
las filas compartían la clave `undefined`. Es exactamente lo que avisa `CLAUDE.md`: un tipo escrito
a mano no se queda corto, **miente** — y aquí llevaba meses mintiendo sin un solo error.

#### La tarjeta de embarque, con el vuelo delante (08/09/2026)

El pasajero tiene hasta ocho y **todas se llaman «boleto»**. En la cola del embarque lo que sirve
para elegir la buena es `CUZ → LIM · 17 sep · JA7018`, así que la tarjeta se rotula con el vuelo y
sólo cae al nombre del fichero si no hay vuelo asociado. Ordenadas por fecha: la de mañana arriba.

🔥 **Y salen de `miIdentidad.documentos`, no de `documentosParaCliente`.** Ésa filtraba por TIPO y
no por persona: con ocho tramos y 133 pasajeros, listar ahí las tarjetas serían ~542 entradas
visibles para cualquiera que abriera el expediente con el localizador. El fichero en sí ya estaba
protegido —`ArchivoPrivadoController` devuelve 404 al que no es suyo—, pero **la lista habría
contado quién vuela qué**, y eso ya es información. `getDocumentosParaCliente()` deja fuera todo lo
que tiene dueño; ahí quedan la entrada a Machu Picchu y el tren, que son de todos.

⚠️ **`esDevolvibleAlPasajero()` se comprueba también en la lista**, no sólo en el controlador del
fichero: el escaneo de su propio pasaporte es suyo y aun así no se le devuelve. Si sólo lo mirara
el controlador, la lista lo anunciaría y el enlace daría 404 — peor que no enseñarlo.

⚠️ La fecha viaja en **ISO** y la formatea el front: esta app habla siete idiomas. Y las cuatro
cadenas nuevas se siembran con `pax:textos:itinerario` el mismo día que nacen, que es la lección
de las seis anteriores — un respaldo `||` en castellano no falla, sólo deja al extranjero delante
de un texto que no entiende.

#### La foto se guardaba tumbada, y nadie podía verlo (08/09/2026)

Los escaneos **no se guardan crudos**: `VichWebpConversionListener` los convierte a WebP porque
`CotizacionFilearchivo` usa `MediaTrait`. Y ese filtro llevaba `strip: ~` sin girar antes, así que
borraba la etiqueta EXIF de orientación **sin haber girado los píxeles**. Medido:

```
ORIGEN     2400x1200  orientación=6   → el navegador la ve de pie
ANTES      1600x800   orientación=0   → tumbada, para siempre
DESPUÉS     800x1600  orientación=0   → de pie
```

🔥 **Y la combinación era la peor posible**: el navegador SÍ respeta el EXIF, así que el pasajero
veía su pasaporte derecho en la previsualización, lo confirmaba, y al operador le llegaba girado
—sin forma de comprobarlo, porque el escaneo no se le devuelve—. Cero errores en el log.

Arreglado con `auto_rotate` **antes** de `strip`, en todos los sets de `liip_imagine.yaml`. Llevaba
ahí desde siempre: afecta también a las galerías.

⚠️ Y se quitó la salida rápida por «el MIME ya es el de destino»: dejaba pasar un webp **sin
tocar**, ni girado ni acotado. El filtro no sólo cambia de formato.

#### Un DNI se lee; una foto de habitación se mira (08/09/2026)

Filtro propio `documento_identidad` (2400 px, calidad 88) en vez del normal (1600 px, calidad 80).
A 1600 px, un DNI fotografiado sobre una mesa —que ocupa media foto— deja el número en ~800 px de
ancho y la letra pequeña desaparece. Y **no hay segunda oportunidad**: el escaneo no se devuelve,
así que un documento ilegible no se corrige mirándolo otra vez, se le vuelve a pedir a la persona.

Lo elige {@see RequiereAltaFidelidadInterface}, que es un **método y no una interfaz marcadora**:
la misma entidad guarda un boleto y un pasaporte, así que lo decide el ejemplar
(`ArchivoTipoEnum::esEscaneoDeIdentidad()`), no la clase.

Cuesta ~430 KB por documento contra los 145 KB medidos del filtro normal. Por los 399 documentos
del grupo son 110 MB más.

#### La carpeta del ZIP no se borraba nunca (08/09/2026)

El comentario decía «se borra sola al aplicarla» y no había una línea que la borrara. Peor: para el
camino del ZIP **Vich copia en vez de mover** —sólo mueve los `UploadedFile`, y ahí se le pasa un
`File`—, así que cada carga aplicada dejaba **el extracto entero duplicado**, permanente.

Tres frenos:

| Cuándo | Qué |
|---|---|
| Al aplicar | `limpiar()`, y 🔥 **después del `flush()`**: la copia de Vich ocurre al guardar, borrar antes deja los adjuntos vacíos |
| Al descartar | endpoint `…/archivos-zip/descartar` — antes «Descartar» sólo limpiaba la pantalla |
| Al planificar | `barrerCargasViejas()`, las de más de un día: quien cierra la pestaña no pasa por ningún endpoint |

Un disco lleno aquí ya tumbó producción una vez.

#### El ZIP sustituye, no acumula (08/09/2026)

Los ZIP de boarding passes no llegan una vez: llega uno, luego el corregido, luego «los que
faltaban», luego uno «por si acaso». 🔥 **Sin sustituir, cada pasada deja una copia más** y el
cliente llega al gate eligiendo entre dos documentos sin saber cuál vale — que es exactamente lo
que la pantalla de revisión existe para evitar.

`CargaMasivaDeArchivos::boletoPrevio()` busca el boleto que esa persona ya tenía **para ese mismo
vuelo** y `aplicar()` lo borra antes de crear el nuevo. El plan lo enseña antes (`reemplaza` en la
fila, «sustituye al que ya tenía» en violeta): el anterior se pierde, y si el bueno era aquél, ésa
es la única oportunidad de pararlo.

⚠️ **`aplicar()` vuelve a buscarlo y no se fía del `reemplaza` del plan**: entre la
previsualización y el «Guardar» pudo entrar otro ZIP.

#### El pasajero sube sus documentos desde su móvil (08/09/2026)

Perseguir 133 pasaportes por WhatsApp, renombrarlos y subirlos uno a uno es el trabajo que esto
quita. La puerta ya existía —`IdentidadDelPasajero`, documento y fecha de nacimiento, con su freno
a los intentos—; sólo faltaba la subida.

🔥 **La previsualización no es un adorno: es la única oportunidad de ver si la foto vale.** Un
escaneo de identidad **no se le devuelve nunca** a quien lo subió, así que si sale movida, cortada o
con el flash reventando el holograma, no hay forma de comprobarlo después. Por eso el flujo es
*elegir → mirar en grande → confirmar*, y no un `input` que envía al soltar.

⚠️ **`<input capture>` y no una cámara propia con `getUserMedia`.** El `input` abre la cámara del
sistema, con su enfoque, su HDR y su recorte; una cámara hecha a mano da peor foto, pide un permiso
que asusta y falla en los navegadores embebidos —el de Instagram, el de Gmail—, que es justo por
donde llega un enlace de viaje. Y sin `capture` fijo: mucha gente ya tiene la foto en la galería.

⚠️ **El pasajero sale de la SESIÓN, no de la petición.** Si viniera en el cuerpo, bastaría con
cambiarlo para subirle algo a otro.

⚠️ **Reemplaza en vez de acumular**: la segunda foto es la buena, y guardar las dos deja al
operador eligiendo entre una borrosa y otra.

#### Los adjuntos salen de `public/` (07/09/2026)

Hasta hoy los adjuntos del expediente vivían dentro de `public/` y se servían por URL directa, así
que **la URL era la llave**: quien la tuviera, la adivinara o la recibiera reenviada, entraba. Con
8 boletos era discutible. Con lo que viene deja de serlo:

```
escaneos    133 pax × 3 (pasaporte + DNI anverso y reverso)  ≈   400 ficheros
boarding    133 pax × 8 vuelos                               ≈ 1 060 ficheros
```

Cien de esas personas son menores, y un boarding pass lleva nombre completo y PNR — con un PNR se
toca una reserva en media aerolínea.

**Ahora los entrega `ArchivoPrivadoController`, y PHP no lee el fichero:**

```
1. GET /archivo/{id}      → PHP comprueba quién pregunta   ~2 ms, cuerpo vacío
2. X-Accel-Redirect: /_privado/archivos/TOKEN.pdf
3. nginx lo manda con sendfile
```

Sin esto, 133 pasajeros bajando 8 boarding passes serían mil transferencias **a través de PHP**,
con un proceso de php-fpm ocupado en cada una. Es la razón por la que se acaba dejando todo en
`public/`, y no hace falta.

⚠️ **La línea que hace que esto sirva de algo es `internal;`** en la ruta de nginx. Sin ella, la
carpeta privada queda accesible a pelo:

```nginx
location /_privado/ { internal; alias /var/www/openperu.pe/var/documentos/; }
```

⚠️ **404 y nunca 403.** Un 403 confirma que el archivo existe; quien no tiene permiso no debe poder
distinguir «no es tuyo» de «no existe».

**Quién ve qué:**

| Quién | Qué |
|---|---|
| Operador con sesión | todo |
| Pasajero identificado | sólo lo suyo, y sólo lo **devolvible** |
| Cualquier otro | 404 |

⚠️ **El escaneo del propio pasaporte NO se le devuelve al pasajero**, aunque sea suyo. Lo sube y
ahí se acaba: poder redescargarlo no le da nada que no tenga ya —lo lleva en el bolsillo— y añade
una vía más por la que esa imagen puede salir. **El boarding pass sí**, y es indispensable: lo
enseña él en el gate, así que baja a su móvil y allí funciona sin cobertura.

⚠️ **La cuarta combinación de alcance.** `pasajero` + `grupo` a la vez significa «su boarding pass
**de ese vuelo**»: con `pasajero` a secas se sabe de quién es pero no de qué vuelo, y quien vuela
Cusco–Lima, Lima–Panamá y Panamá–Punta Cana ida y vuelta tiene ocho. El subgrupo `reserva_aerea`
ya existía —24 en el expediente que lo motivó— y es justo lo que los distingue.

⚠️ **Y el DNI son DOS tipos, no uno con campo «cara»**: un control migratorio quiere las dos. Con
un caso por cara, «¿a quién le falta algo?» se contesta mirando qué tipos tiene.

**Caducan al mes del retorno**: `app:cotizacion:purgar-archivos` borra fichero y fila. **No toca
`CotizacionPasajeroIdentificacion`** —el número de pasaporte con el que se emitió un boleto se
queda—: esa separación es lo que permite borrar la foto sin romper el expediente.

🔥 **El plazo NO vive en el comando: vive en `ArchivoTipoEnum::mesesDeRetencion()`**, `null` =
no caduca. Así, añadir un tipo nuevo es decidir su plazo donde se declara, en vez de acordarse
de un comando que está en otra carpeta.

| Tipo | Plazo tras el retorno |
|---|---|
| Pasaporte, DNI ×2, autorización | 1 mes |
| **Boleto / boarding pass** | **1 mes** |
| Factura, confirmación de reserva, otros | no caduca |

⚠️ **El boarding pass caduca igual que el pasaporte** (decidido el 08/09/2026; antes se quedaba
para siempre «por si hay una reclamación»). Lleva nombre, vuelo, asiento y el **localizador** — y
con apellido y localizador se entra a la reserva en la web de la aerolínea. Son ~542 ficheros por
grupo grande y nadie los borraba. Lo que sostiene un expediente meses después es la **factura**,
que no caduca.

⚠️ **`OTROS` no caduca a propósito**: es el cajón de lo que no se clasificó, así que no sabemos qué
hay dentro. Borrar por defecto lo no clasificado es como se pierde el único ejemplar de algo.

**Corre por cron a las 4:10**, y ⚠️ **en el cron sólo apunta lo que borra**: la tabla entera son
~50 KB cada noche con 542 boarding passes —18 MB al año de un log que nadie lee—, y una noche sin
nada que borrar no escribe ni una línea. Un log que dice «0» todas las madrugadas es un log que se
deja de mirar, y entonces tampoco se ve el día que dice otra cosa. En `--dry-run` sí sale la tabla
completa: ahí es el resultado, no el registro.

⚠️ **Y no hay columna `caduca_el`.** La fecha se calcula cada vez desde el retorno del expediente
(la última fecha de segmentos y componentes), así que si el viaje se mueve, la caducidad se mueve
con él. Una fecha guardada seguiría apuntando al viaje que se planeó.

⚠️ Y una redacción que hubo que corregir de paso: el docblock del tipo decía «esto **no** es un
documento de identidad», y se leía como que un pasaporte escaneado no cabía aquí. No era eso: lo
que no cabe es **el número**, que es un dato y vive en la identificación. El escaneo es un archivo
como cualquier otro. Esa frase costó una entidad entera escrita y borrada.

#### Noches y días no son lo mismo, y contarlos igual torció un dato (07/09/2026)

Un hotel del 18 al 22 son **4 noches**. Un seguro de viaje del 18 al 22 son **5 días**. Las mismas
dos fechas, dos cantidades — y el editor sólo sabía una: `calcularPernoctes()` hacía `fin − inicio`
para todo, y el nombre confesaba la suposición.

⚠️ **La consecuencia no fue un número mal pintado: fue que el operador tuvo que torcer el dato.**
Para cobrar los 5 días de estancia en Punta Cana escribió el seguro como `18 → 23`, porque era la
única forma de que la resta diera 5. La cantidad la deriva el editor y la pisa cada vez que cambian
las fechas, así que la fecha era la única palanca. El itinerario quedó diciendo que la cobertura
llegaba a un día en que ya nadie estaba allí, y quien leyera con atención vería la incoherencia.

**Dos ejes, no uno.** Meterlos en el mismo enum haría que creciera con el catálogo:

| | Qué decide | Valores |
|---|---|---|
| Aritmética | cómo se cuenta | `NOCHES` (fin − inicio) · `DIAS` (fin − inicio + 1) · `UNIDADES` (no sale de fechas) |
| Sustantivo | cómo se llama | «noche», «día», «desayuno», «masaje»… |

Así «5 desayunos» es `DIAS` con sustantivo «desayuno» y **no toca la aritmética**.

**Dónde vive la verdad: en el catálogo.** `TravelComponente::$unidadDeConteo`, que lo declara una
vez quien crea el producto — no quien cotiza, que es quien tendría que acordarse cada vez. NULL =
lo que diga el tipo (`ComponenteTipoEnum::unidadPorDefecto()`), que acierta en **31 de los 33**
componentes multi-día de producción.

⚠️ **El tipo no puede ser la única fuente, y el culpable es `EXTRAS`**: ahí caben un seguro (días),
una propina (una unidad) y un upgrade. Por eso el tipo da el defecto y el componente lo corrige.

⚠️ **La tabla tipo → unidad vive en PHP y sólo en PHP.** Cruza al front ya resuelta por
`CotizacionCotcomponente::getUnidadDeConteo()`, igual que `ordenNarrativo`. Escribirla en
TypeScript sería la segunda copia de una regla, y este repo ya pagó ese precio una vez.

**Lo que cambia al leer el itinerario:**

```
18 → 22   hotel   4 bloques (18, 19, 20, 21)   la cama cierra el día
18 → 22   seguro  5 bloques (18 … 22)          cubre la jornada: la ABRE
```

⚠️ **Y lo sin-hora deja de amontonarse al final.** El escalón «con hora» iba entero antes que el
«sin hora», así que un almuerzo variable aterrizaba tras la excursión de la tarde: un cliente que
lee su día de arriba abajo se enteraba **al pie** de que tenía el almuerzo incluido. Ahora un
bloque sin reloj se mide con la **hora nominal** de su momento del día, que es una clave de
ordenación y no se guarda, no se pinta y no viaja a nadie. La alternativa era inventarle un minuto
en la base, que luego se pinta y acaba siendo un compromiso con el proveedor.

`TravelComponente::$momentoDelDia` afina lo que el tipo no puede: desayuno, almuerzo y cena son los
tres `ALIMENTACION_HORARIO_VAR` y ocurren en tres momentos distintos.

**Textos e iconos clavados que se fueron con esto:** «N noches» con luna en el editor y «Noche i/N»
con luna en la guía — los dos rotulaban también el seguro. Y el «×5» de «qué incluye», que no decía
de qué.

⚠️ **El sustantivo del catálogo no está traducido**: «noche» y «día» sí, por sus claves de i18n;
una palabra propia sale en español en los siete idiomas. El campo es una columna plana sin
`#[AutoTranslate]`; traducirlo pide moverlo a la maquinaria de i18n del catálogo.

**Las fechas ya torcidas no se arreglan solas:** `app:travel:asignar-unidades-de-conteo` declara la
unidad, la congela en los expedientes y **denuncia** las fechas que no cuadran con su cantidad.
Sólo con `--ajustar-fin` las corrige, y no es el modo por defecto a propósito — en el 5SRAJV la
operativa tiene la fecha torcida y la cantidad buena (ajustar acierta) y la confirmada tiene la
fecha buena y la cantidad corta, donde ajustar encogería la cobertura en vez de arreglar el precio.

#### El corchete parte el título en dos, y ahora lo entienden los tres sitios (06/09/2026)

Un título de tarifa con corchetes son **dos datos**: dentro, con quién se va; fuera, en qué
condiciones. Hasta ahora sólo lo entendía el sello del itinerario — en «qué incluye» y en las
opciones alternativas el título salía **en crudo, con los corchetes colgando**, que ahí no
significan nada y se leen como un error.

`partirTituloTarifa()` lo separa una vez y cada sitio pinta lo que le cabe:

| Dónde | Qué se pinta |
|---|---|
| Línea de horarios | **sólo el sello** — el ancho es lo que escasea y lo único que hace falta es desempatar |
| «Qué incluye» (`chipsDeLinea`) | dos pastillas: `SKY AIRLINE` + `con artículo personal y equipaje de cabina` |
| Opciones alternativas / upgrades | dos pastillas, lo mismo |

Sin corchetes no hay nada que partir: el título entero es el sello.

⚠️ **La convención se le explica al operador donde ESCRIBE, en los dos sitios**, porque hasta
ahora se aprendía copiando lo que había escrito otro — que es como aparecieron `[ ARAJET ]` y
`[ Arajet ]` en la misma base:

| Dónde se escribe | Ayuda |
|---|---|
| Catálogo maestro | `TravelTarifaCrudController`, `setHelp()` del campo «Título Visible al Cliente» |
| Editor de cotizaciones | `CotizacionEditorView.vue`, la «i» junto a «Nombre para cliente» |

Si cambia la regla se cambian **los dos textos** además del código.

#### La misma enfermedad en el selector «A quién aplica»

El rótulo es `Eje · Sufijo · Nombre` y **lo que distingue va al final**. Con `truncate`, en un móvil
«Vuelo · Internacional · …» cortaba exactamente la palabra que hacía falta —Arajet o Copa— y
dejaba cuatro filas idénticas. Ahora envuelve (`break-words`, fila con `items-start`).

**La regla:** truncar sólo vale cuando lo importante va delante. Si la cola es lo que identifica,
truncar es peor que ocupar dos líneas — deja al operador eligiendo entre opciones que parecen la
misma.

**Dónde se escribe:** en el título de la tarifa del componente, en el editor. No hace falta campo
nuevo.

#### Con varios perfiles, el número grande decía el precio de otro (04/09/2026)

Cerrada, la tarjeta encabezaba con `claseDominante` —el perfil **con más gente**— y lo rotulaba
`POR PASAJERO` a secas. En un grupo de 60 adultos y 40 menores eso enseña **1907,46** y calla que
un menor paga **1425**: el padre de un menor lee el precio del adulto como si fuera el suyo, y no
se entera hasta abrir el desplegable. 🔥 **Un número grande se lee como EL precio**, por mucho que
el pie invite a mirar los perfiles.

Con más de un perfil encabeza ahora `claseMasBarata` y lleva **«Desde»** delante: el número deja
de ser una afirmación sobre todos y pasa a ser el suelo, que es lo que de verdad es. Se reutiliza
el par `cot_precio_desde` + `cot_por_pasajero`, que es exactamente como ya lo decía la portada.

⚠️ **Sólo con varios perfiles.** Con uno, «desde» insinuaría una variedad que no existe — sería la
mentira contraria. Lo decide `claseDeCabecera`, y ése es el único sitio donde se elige qué precio
encabeza.

#### Un opcional escondido no se vende (04/09/2026)

Cerrada, la tarjeta enseñaba precio y disparador, y nada más. Detrás había una noche en Coco Bongo
por 100 $ que **hay que sospechar que existe** para ir a buscarla. Peor que perder la venta: el
cliente se entera de que existía cuando ya no puede contratarlo.

`opcionesAdelanto` pinta las **dos primeras** con su importe, en pequeño, bajo el precio:

```
POR PASAJERO                         $ 1907,46
                 PRECIO TOTAL DEL VIAJE: $ 190.746,41
⊕ Noche en Coco Bongo · Fiesta Blanca      +$ 100,32
⊕ Upgrade a habitación vista al mar        +$ 185,00
   + 3 opciones más
↓ TOCA PARA VER EL DETALLE DE LAS OPCIONES
```

- ⚠️ **Dos, y el resto contado.** Con una sola se vería la primera y se escondería el resto sin
  decirlo, que es el mismo fallo con otra cara; con todas, un viaje con seis opcionales empuja el
  itinerario fuera de la pantalla y el adelanto deja de ser un adelanto.
- ⚠️ **El adelanto va FUERA del bloque del precio**, porque también hay opciones cuando el precio
  está oculto — y ahí es cuando más falta hacen. En ese caso se pinta el nombre y **no** el
  importe: el importe es dinero y se calla igual que el resto del panel.
- Los negativos se pintan con `−` y en verde (`fa-circle-minus`): una opción puede **restar**
  —«sin almuerzo del día 3»— y un `+$ 22,40` verde diría lo contrario de lo que pasa.
- El detalle sigue dentro: categoría, modalidad, qué reemplaza, la nota y el total del grupo.

Claves i18n del pie: `cot_ver_precios_perfil`, `cot_ver_perfiles_y_opciones`,
`cot_ver_detalle_opcion`, `cot_ver_detalle_opciones`, `cot_ver_detalle`, `cot_ocultar_precios`,
`cot_opcion_mas`, `cot_opciones_mas`. El encabezado expandido reutiliza `cot_precio_por_pasajero`.

> **Gotcha i18n — el fallback esconde el hueco.** `maestroStore.t('clave')` devuelve vacío si
> no hay fila en `pax_ui_i18n`, y el template cae al literal `|| 'Texto en español'`. En
> castellano no se nota nada; el cliente extranjero ve esa cadena suelta en español entre el
> resto traducido.
>
> ⛔ **Y NO se siembra por migración**, aunque este documento lo dijera hasta el 04/09/2026.
> `UiI18n::$contenido` lleva `#[AutoTranslate]` y ese listener cuelga de `prePersist`: un
> `INSERT` en SQL **se lo salta** y la fila nace sólo en español — que es exactamente el hueco
> que la migración venía a tapar. Entra por comando ORM, idempotente por la clave natural:
> `PaxCrearTextosPanelPrecioCommand`, `PaxCrearTextosItinerarioCommand`.
>
> ```bash
> php bin/console pax:textos:panel-precio --dry-run
> ```
>
> Para detectar las que falten:
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

Sondas: `tools/inspeccion/probar-prestador-visible.php` y `tools/inspeccion/probar-comprador.php`.

⚠️ **Las dos estuvieron rotas desde el 19/08/2026 y nadie se enteró** (arregladas el 31/08). El
renombrado a `TravelOrganizacion` se llevó por delante `ProveedorVivoResolver`, `travel_proveedor`
y `App\Travel\Entity\Proveedor`, y las sondas seguían llamándolos: una reventaba al arrancar y
la otra imprimía un ✘ que llevaba doce días en pantalla. Una sonda muerta es peor que ninguna,
porque la doc la cita como si comprobara algo — **no están en CI, así que se corren a mano cuando
se toca esta zona**, y eso incluye después de cualquier renombrado.

### Los filtros por prestador son BLANDOS

Si el componente (o su día) fija prestador, **dos** selectores del editor se estrechan. Ambos con
`SearchableSelect` y las mismas reglas:

| Selector | Qué muestra | Dónde |
|---|---|---|
| **Tarifa maestra** | sólo tarifas cuyo `prestador` es el de la línea | `opcionesTarifasFiltradas` |
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

⚠️ **El filtro se activa poco todavía, y no es un fallo del filtro: es que el catálogo aún no
está poblado.** El vínculo que necesita es `travel_tarifa.prestador_id` —se llamó
`proveedor_id` hasta el 19/08/2026— y el 31/08/2026 iba por **55 de 863**
(`prestador_servicio_id`, 53; `comprador_id`, 43). El prestador se sigue fijando a mano sobre la
cotización en la mayoría de líneas, porque `onTarifaMaestraChange()` **no** lo copia de la
maestra; quien lo copia es `sembrarPapelesEnLinea()`, y sólo si el hueco está vacío (ver abajo).

🔥 **La salida que este párrafo proponía —«bajarlo a un solo campo por componente»— se probó y
se revirtió.** El campo estuvo en `TravelComponente` seis días (`Version20260816240000` →
`Version20260820140000`) y volvió a la tarifa, que es la única granularidad donde la afirmación
es cierta: «Tren Ollanta Mapi» tiene 12 tarifas PeruRail y 4 IncaRail, así que un prestador por
componente miente sobre 12 o sobre 4. Las dos vueltas están contadas en `docs/Travel.md` §11.

Y lo que desatascó el abandono no fue mover el campo, sino **darle un motivo para llenarse**.
Nadie repetía el proveedor en 19 tarifas mientras sólo servía para estrechar un desplegable;
ahora la tarifa es lo único que sabe **a quién se le encarga la compra** (el `comprador`, y la
Orden de Servicio sale a su nombre) y **qué fotos se enseñan** (§6.t: el servicio del prestador
ilustra el segmento genérico). Con eso poblarlo dejó de ser higiene y pasó a ser lo que enciende
dos funciones. Comprobación:

```bash
php bin/console doctrine:query:sql --force-fetch \
  "SELECT COUNT(*) total, SUM(prestador_id IS NOT NULL) con_prestador,
          SUM(prestador_servicio_id IS NOT NULL) con_servicio,
          SUM(comprador_id IS NOT NULL) con_comprador FROM travel_tarifa"
```

Mientras tanto el filtro degrada solo: sin coincidencias, no filtra.

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

## 6.e.2 Un párrafo SIN maestro ya puede decir dónde empieza y acaba (24/08/2026)

Los puntos de un servicio salen del `TravelSegmento` maestro de cada párrafo
(`CotizacionPuntosDelServicio::modo()` / `texto()`). Un párrafo **escrito a mano** —el traslado a
«La Olla de Juanita», que no está en ningún catálogo ni va a estarlo— no tiene maestro, así que se
quedaba en **«sin definir» para siempre**: el único sitio donde declararlo era
`OperacionOrdenServicioItem::$puntoRecojoConfirmado`, o sea el override de la orden **ya emitida**
— dos capas más abajo y sólo después de confirmar.

Ahora `CotizacionSegmento` tiene `inicioTexto` y `finTexto`, y el resolver los usa **sólo cuando
no hay maestro**.

⚠️ **Con maestro NO se miran, y eso es a propósito.** No es un override: una regla de precedencia
entre el maestro y un texto local sería exactamente la «segunda superficie declarando el mismo
hecho» que se descartó al diseñar esto (§6.e). Sin maestro no hay primera superficie, así que no
se duplica nada — es el único sitio donde se puede decir.

Los campos sólo se pintan en el Constructor de Storytelling cuando `segmentoMaestroId` es nulo.

⚠️ **Texto libre, no un `TravelPunto`.** Dar de alta un punto maestro para una parada irrepetible
cuesta más que el problema, y quien lo lee es una persona.

Verificado sobre datos reales, en transacción con `rollback`: con los 7 párrafos de un servicio
puestos a mano, los extremos pasan de `alojamiento`/heredado a `fijo` con el texto tecleado.

## 6.e bis 🔥 Reemplazar un segmento no movía el puntero al maestro (30/08/2026)

`procesarInsercionSegmento()`, rama `replace`: copiaba los cinco snapshots del maestro nuevo
—título, nombre interno, contenido, notas, imágenes— y **dejaba `segmentoMaestroId` apuntando al
viejo**. La rama `insert` sí lo asigna, porque el segmento nace con él; sólo fallaba al
reemplazar.

**El síntoma llega mucho después del fallo, y por eso costó verlo.** El párrafo se queda con el
texto nuevo y todo parece bien. Hasta que alguien pulsa **«Actualizar»** —que relee del maestro
por si cambió—, y esa relectura hace `mapaMaestros.get(cotSeg.segmentoMaestroId)`: **devuelve el
contenido ANTERIOR y deshace el reemplazo sin decir nada.**

Los cinco snapshots son la FOTO; `segmentoMaestroId` es la fuente de la que se vuelve a sacar. Al
reemplazar hay que mover las dos cosas o la foto y su negativo dejan de ser del mismo sujeto.

⚠️ **La comprobación de daño tiene un límite que conviene conocer.** Un desajuste se detecta
comparando `nombre_interno_snapshot` con el `nombre_interno` del maestro al que apunta:

```sql
SELECT ... FROM cotizacion_segmento cs
JOIN travel_segmento ts ON ts.id = cs.segmento_maestro_id
WHERE JSON_UNQUOTE(JSON_EXTRACT(cs.nombre_interno_snapshot,'$[0].content')) <> ts.nombre_interno
```

El 30/08/2026 daba **cero sobre 256 segmentos**. Pero eso **no prueba que no haya pasado**: en
cuanto alguien pulsa «Actualizar», el snapshot se reescribe al del maestro viejo y la
discrepancia desaparece **junto con el reemplazo perdido**. La consulta encuentra el fallo
pendiente, no el que ya revirtió.

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

## 6.g.2 En tarifa GRUPAL la ficha mentía el subtotal (24/08/2026)

El subtotal de la ficha de tarifa era `montoCosto × cantidad`, **sin mirar `esGrupal`**. Con una
tarifa grupal de S/ 80 y 2 pax decía **S/ 160**, mientras la tarjeta de la lista y el cálculo que
se guarda decían S/ 80 —los dos sí lo tienen en cuenta (`× (esGrupal ? 1 : cantidad)`)—.

Se guardaba bien. El problema es que mientras editabas leías un número que no era, y no había
forma de saber cuál de los dos mentía: el campo «Cant (Pax)» seguía enseñando el 2 en gris.

Ahora en grupal el campo **enseña «1 grupo»** en vez del número. El valor se conserva en el modelo
—volver a unitario lo recupera— pero no se muestra mientras no se usa: enseñar una cifra que el
cálculo ignora es lo que hace parecer que algo falla.

⚠️ La regla, que vale para cualquier sitio nuevo que sume tarifas:
`montoCosto × (esGrupal ? 1 : cantidad)`. En grupal el monto **ya es el total**.

### 6.g.3 La misma regla faltaba en los TRES cálculos que suman de verdad (27/08/2026)

La ficha se arregló en agosto, pero los sitios que calculan el dinero **seguían multiplicando sin
mirar `esGrupal`**:

| Dónde | Qué corrompía |
|---|---|
| `BibliaSnapshotService::calcularCostoCotizado()` | el `costoCotizado` de La Biblia → y de ahí el importe de la Orden |
| `OperacionServicio::getDesgloseCotizado()` | el desglose que explica ese número |
| `cotizacionEditorStore` (`costoTotal`) | **el costo neto y la ganancia de la cotización** |

Con una tarifa grupal de S/40 y `cantidad = 2`, los tres daban **80**.

⚠️ **Por qué nadie lo vio antes.** En el editor el error **se cancela al dividir**: el por-pax es
`costoTotal / cupos`, y en grupal `cupos = numPax`. Con `cantidad = 2` y 2 pax, `80 / 2 = 40` —
que es justo lo que enseña la ficha. **La pantalla cuadraba y el total iba doblado.** Con 3 pax ni
el por-pax habría cuadrado. Y en `resolverGrupal()` el flag se resolvía **bien**, trece líneas
antes; sólo faltaba en la multiplicación.

⚠️ **Ninguno de los tres consulta el maestro**: los dos de PHP recorren `getCottarifas()` del
snapshot, y el fallback al catálogo del store es código muerto —`es_grupal` es `NOT NULL` y en
producción hay **0 nulos de 179**—. El dato correcto estaba siempre en el snapshot; simplemente no
se usaba.

**Alcance real, medido en producción el 27/08/2026:** de 61 tarifas grupales, sólo **2** tenían
`cantidad > 1`. Las otras 59 tienen 1, y por eso el fallo llevaba meses sin morder. Los datos se
corrigieron recalculando por ORM.

### 6.g.4 El «0.00» que tapaba el costo de La Biblia (27/08/2026)

La ficha pintaba `costoNegociado ?? costoCotizado`. Pero `costo_negociado` es **`NOT NULL` y
arranca en «0.00»**, así que el `??` —que sólo salta con `null`— **no entraba nunca**: las 47 filas
de producción enseñaban `0.00` en vez de su costo cotizado.

Y peor: la clase de color preguntaba `costoNegociado ?`, y en JavaScript la cadena `'0.00'` es
**verdadera**. Así que lo pintaba en negro, como un precio negociado de cero — no como un dato que
falta. Un cuadro de costos que dice cero es peor que uno que no dice nada.

⚠️ La regla: **cero es «sin registrar», no un importe.** Se pregunta con `hayNegociado()`
—`Number(costoNegociado) !== 0`—, que es lo que `deltaOperativo()` ya hacía bien desde el
principio. Si un proveedor cobrara cero de verdad, eso es una cortesía y se marca como tal en la
cotización.

## 6.h El componente hecho A MANO, y el bucle que lo impedía (23/08/2026)

Caso que lo forzó: un Full Day Paracas–Ica en el que unos amigos invitan a los pasajeros a comer
en «La Olla de Juanita», y hay que meter **dos transportes que no existen en los maestros y que no
van a existir nunca**, porque es una parada irrepetible.

### El bucle

`agregarComponente()` crea el componente con `tipo: 'extras'`, `tituloSnapshot: []`,
`sinHorario: true` y `componenteMaestroId: null`. De ahí no se salía:

```
tituloSnapshot: []
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
| `isComponenteSoloItems()` | `CotizacionEditorView.vue` | Un contenedor lo es porque **tiene ítems**: `tituloSnapshot` vacío **Y** `snapshotItems` no vacío. Uno recién creado no es ninguna de las dos cosas |
| `getNombreMaestroRef()` | `CotizacionEditorView.vue` | Sin maestro manda **su** `tituloSnapshot`; «Insumo sin seleccionar» sólo si tampoco hay nombre propio |
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

Los tres campos exclusivos del manual —nombre interno, Categoría Operativa y **Ubicaciones**— se
muestran por `esManual`, **no** por «no tiene maestro»: en modo catálogo sin insumo todavía
elegido, ofrecerlos sería ofrecer campos que el maestro va a pisar en cuanto se elija.

#### Las ubicaciones de un componente manual (25/08/2026)

Las ubicaciones —Lima, Ica, Cusco— son de `TravelLugar` y en el catálogo cuelgan del componente
maestro (`TravelComponente::$lugares`, tabla `travel_componente_lugar_pool`). La cotización **no
las congelaba**: guarda `componenteMaestroId` y quien las necesita las resuelve **en vivo**
pidiendo el maestro por id. Es deliberado —re-etiquetar en el catálogo se refleja en el siguiente
clic—, pero dejaba a los manuales sin ninguna: no tienen a quién preguntarle, y salían en blanco
en el cuadro de tráfico.

Ahora las escriben ellos, en `CotizacionCotcomponente::$lugaresManuales`: una columna **JSON con
uuids** de `TravelLugar` en RFC 4122 minúsculas, el mismo formato que `componenteMaestroId`.
Columna y no relación **a propósito**: Operaciones no tiene ni una relación Doctrine hacia el
catálogo —borrar un lugar no debe arrastrar historial— y meter la primera aquí abriría esa puerta
por detrás.

⚠️ **UNA fuente de la verdad, garantizada en la entidad y no en el formulario.** Los dos no
conviven:

| Camino | Qué pasa |
|---|---|
| `setComponenteMaestroId(<id>)` | **Vacía** `lugaresManuales`: a partir de ahí las pone el catálogo |
| `setLugaresManuales([...])` con maestro puesto | Se ignora en silencio, no revienta |

Los dos caminos convergen, **llegue el payload en el orden que llegue** — que es justo por lo que
la regla vive en la entidad. Se ignora en silencio y no con un 400 porque quien manda el payload
no está haciendo nada malo: el editor limpia el campo al vincular, y un error rompería un guardado
entero por un dato que sobra. Verificado sobre datos reales con
`tools/pruebas/probar-lugares-manuales.php` (transacción con rollback), en los dos órdenes.

El setter además **normaliza**: a minúsculas, sin duplicados y descartando lo que no sea un uuid.
Es el formato con el que compara el filtro del cuadro de tráfico, y un uuid en mayúsculas no
casaría con nada — en silencio, que es la peor forma de no casar.

En el front, `onComponenteMaestroChange()` las limpia también al vincular. No es redundante con el
backend: es para que el operador lo **vea** en el acto y no se entere al recargar.

⚠️ Desvincular el maestro **no las devuelve**: nunca fueron suyas. El editor las vuelve a pedir a
mano, que es lo correcto.

El consumidor está en Operaciones: `docs/Operacion.md` §«Etiquetas de lugar».

#### El insumo se puede soltar

`SearchableSelect` gana una prop **`limpiable`**: con ella y un valor puesto, aparece una «×» que
emite `null` por `update:modelValue` **y por `change`** —los padres cuelgan de `change` sus efectos
en cascada, y desvincular tiene que dispararlos igual que vincular—.

⚠️ Es **opt-in**. La mayoría de estos selectores son obligatorios —un servicio maestro, una
moneda— y ahí un botón de limpiar sólo sirve para dejar el formulario inválido.

#### Y se puede elegir VARIOS (25/08/2026)

`SearchableSelect` gana una prop **`multiple`**, también opt-in: con ella `modelValue` es una
**lista** y cada clic añade o quita. La usa el selector de ubicaciones.

Tres decisiones que no son cosméticas:

- **La lista NO se cierra al elegir.** Elegir tres ubicaciones seguidas es el caso normal, y
  cerrarla entre una y otra obliga a reabrir y a volver a buscar.
- **El disparador enseña los NOMBRES, no «3 seleccionadas».** Con la lista cerrada es lo único
  que se ve; un contador obliga a abrirla para saber qué hay dentro. Si no caben, el `truncate`
  los corta — verlos a medias sigue diciendo más que contarlos.
- **Internamente todo es una lista**, en los dos modos (`seleccion`), y sólo `select()` y
  `limpiar()` se bifurcan. Repetir el `if (multiple)` en cada sitio es donde se cuela la
  diferencia de comportamiento entre uno y otro modo.

Sin la bandera el componente se comporta **exactamente** como antes, con un valor suelto: las
instancias que ya existen no se tocan.

### El nombre INTERNO, que el componente no tenía

`CotizacionCotcomponente` sólo guardaba `tituloSnapshot`, que es el **título público**: el nombre
interno venía siempre del maestro, y por eso «siempre existía». Un manual no tiene maestro, así
que La Biblia acababa rotulando la fila con el texto del cliente —o con «Servicio sin nombre»—.

`$nombreInternoSnapshot` (string, nullable) lo cubre, y entra en `resolverDescripcion()` **por
delante del nombre interno de la tarifa**: éste nombra *el servicio* («Traslado a La Olla de
Juanita (ida)») y aquél la *línea de precio*, que en un componente hecho a mano se suele quedar
con el «Nueva Tarifa» de fábrica. Ver `docs/Operacion.md` §3.3.

⚠️ **Sin `#[AutoTranslate]` a propósito**: no se le enseña a ningún pasajero, así que traducirlo a
siete idiomas es coste puro. Mismo criterio que la nota al prestador.

Comprobado con datos reales (`tools/inspeccion/probar-componente-manual.php`, en transacción con `rollback`):
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
| `tituloSnapshot` o `snapshotItems` | `construirInclusiones()` no genera línea → **advertencia bloqueante** al guardar |
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

## 6.j.0 No son versiones: son PROPUESTAS (02/09/2026)

El campo se llama `version` y eso hizo razonar mal a más de uno, porque «versión» implica *sustituye
a la anterior*. **No se sustituyen.** Un expediente puede tener varias vivas a la vez, y el cliente
puede aprobar **más de una** — porque a veces son complementarias: una la parte de Lima del
itinerario y otra la de Bolivia.

⚠️ **El propio dato ya lo estaba denunciando.** `getVersionesFechas()` devuelve por cada fila
`{id, version, estado, titulo, fechaInicio, fechaFin}`. Una *revisión* no tiene título propio ni
tramo de fechas propio; una **propuesta** sí. La estructura describía propuestas mientras el nombre
decía versiones.

### Las tres capas, que antes se confundían

```
Expediente
 └── PROPUESTA          alternativas o COMPLEMENTOS · el cliente elige una o varias
       ├── HISTÓRICO ×N       fotos de esa propuesta · no pública · `derivadaDe` → la viva
       ├── la aprobada        pública
       └── OPERATIVA          opcional · la realidad de lo que se opera (ver §6.j.2)
```

Ninguna de las tres consume número de propuesta: se distinguen por estado (§6.j).

⚠️ **La OPERATIVA todavía no existe**: está diseñada, no construida. El plan —con el orden por
riesgo y las trampas localizadas— está en `docs/PlanPropuestaOperativa.md`.

### Qué se renombró

| | Antes | Ahora |
|---|---|---|
| URL de la guía y del catálogo | `/…/v/{n}` | **`/…/p/{n}`** |
| Parámetro de ruta, prop, variables | `version` | **`propuesta`** |
| **Columna** `cotizacion_cotizacion` | `version` | **`propuesta`** |
| **Campo de la API** y sus dos `api.d.ts` | `version` | **`propuesta`** |
| `versionesParaCliente` · `versionesFechas` | | **`propuestasParaCliente`** · **`propuestasFechas`** |
| Textos de interfaz | «Versión N» · «V2» | «Propuesta N» · «P2» |

**Se hizo entero el 02/09/2026, no a medias.** La primera intención fue renombrar sólo lo visible y
dejar la columna con su nombre heredado, esperando una migración que lo llevara gratis. Se descartó
al comprobar dos cosas:

1. **La ocasión no iba a llegar.** El trabajo que viene —la propuesta operativa— **no toca esta
   tabla**: `estado` es `varchar(30)` (un `case` nuevo no necesita migración) y `derivadaDe` ya
   existe. El recordatorio habría esperado para siempre dando falsa tranquilidad.
2. **La deuda no crece con los datos, pero sí con las funciones.** `RENAME COLUMN` en MySQL 8 es
   metadatos —no reescribe filas, daba igual 12 que 12.000—, pero cada función nueva sobre
   cotizaciones sumaba un sitio más leyendo el nombre equivocado. Y lo que viene son justo
   funciones sobre cotizaciones, donde razonar «versión = revisión» lleva al diseño equivocado.

**La interfaz ya decía «Propuesta»** (`cot_propuesta` en `PaxFilePortadaView`). Lo que iba por
detrás era la fontanería, no el vocabulario del usuario.

⚠️ **El renombrado de la URL fue seguro porque lo compartido con clientes es el EXPEDIENTE**
(`/file/{localizador}`), no una propuesta concreta. Si algún día se comparte un enlace a una
propuesta, este cambio ya no se puede repetir sin alias: un enlace que deja de funcionar sin que
cambie nada del lado del cliente es de los fallos más caros que tiene este sistema —ya pasó con el
provider público, ver §6.j—.

🔥 **El typecheck fue la red, y encontró 21 sitios.** Regenerar los dos `api.d.ts` desde el esquema
nuevo hizo que TypeScript señalara uno por uno cada consumidor del campo viejo — stores, vistas,
modelos, el payload de guardado. Ninguno se encontró leyendo: los encontró el compilador. Es
exactamente el argumento de por qué los tipos se generan y no se escriben a mano.

⚠️ **Lo que el typecheck NO podía ver, y casi se escapa:** `uriVariables` de API Platform declaraba
`'version' => new Link(…)`. Es una clave de array en un atributo PHP, así que ningún tipo la
vigilaba: la API devolvía **404 con «Parameter "version" not found»** y sólo salió al pedir el
endpoint de verdad. La lección de siempre — un atributo mal puesto no falla, deja de hacer su
trabajo — con una vuelta más: aquí sí fallaba, pero en un sitio donde nada estático miraba.

## 6.j.1 La visibilidad es un eje propio: `publicado` (02/09/2026)

**El estado ya NO decide si el cliente ve una propuesta.** Lo decide `Cotizacion::$publicado`.

Hasta hoy la visibilidad salía de `CotizacionEstadoEnum::esPublico()` —`enviado` o `confirmado`—,
y eso obligaba a **mentir sobre un acto comercial para conseguir una visibilidad**: *«para ver la
cotización antes de mandarla tengo que ponerle enviada»*. Son dos preguntas distintas:

```
estado      dónde está comercialmente    pendiente · enviado · confirmado · cerrado…
publicado   ¿el cliente puede verlo?     bool, independiente
```

La migración tradujo el dato exacto —`publicado = true` donde el estado era `enviado` o
`confirmado`— así que ningún cliente vio nada distinto el día del despliegue. Es la única forma
aceptable de mover una regla de visibilidad sobre enlaces que ya están en manos de gente.

### Lo que resuelve, y no era sólo la molestia

| | Antes | Ahora |
|---|---|---|
| Ver una propuesta antes de mandarla | ponerle «enviada» | previsualizar sin tocar el estado |
| Reorganizar sin que el cliente lo vea | no se podía | despublicar mientras se trabaja |
| Varias filas públicas en una propuesta | había que desempatar | **no puede pasar** (invariante) |

### ⚠️ La invariante: UNA publicada por propuesta

Una propuesta tiene varias filas —sus históricos, la aprobada, y en su día la operativa— y todas
comparten número. `CotizacionPublicadaEventListener` despublica a las hermanas al publicar una.

**Con eso el desempate del provider deja de existir**, en vez de resolverse mejor: filtra por
`publicado = true` y sólo puede encontrar una. Ese desempate ya mordió una vez (§6.j).

⚠️ **Va en el servidor, no en el botón.** El editor no es el único que escribe —la API acepta un
`PATCH`, y mañana lo hará el agente—. Una invariante que sólo cumple la interfaz es una costumbre.

### 🔥 Pasar una ENTIDAD como parámetro de una relación UUID devuelve cero, sin error

Al probar la invariante con datos reales no se cumplía, y el código parecía correcto:

```php
->setParameter('padre', $padre)                            // 0 filas · en silencio
->setParameter('padre', $padre->getId(), UuidType::NAME)   // 3 filas
```

Con clave UUID, Doctrine **no casa nada y no se queja**: la consulta devuelve cero y parece que
simplemente no había hermanas que despublicar. Es el mismo patrón que los providers públicos ya
usaban —`->setParameter('file', $file->getId(), UuidType::NAME)`— y por eso a ellos no les pasa.

⚠️ **Sólo se ve comprobando el RESULTADO, no la ausencia de excepciones.** Una prueba que sólo mira
que no reviente da esto por bueno.

### La previsualización del operador

El provider deja pasar lo no publicado si `isGranted('ROLE_USER')`. No hace falta enlace ni token:
`util` y `pax` comparten dominio de cookie (`FRAMEWORK_SESION_COOKIE_DOMAIN`) y el host de la API
está bajo el firewall `main`, que es *stateful*.

⚠️ **La caducidad NO se salta.** Una propuesta expirada tampoco se previsualiza: si no, el operador
vería algo que el cliente no puede ver y creería que sí.

### Y el guarda de conflictos financieros vigila lo que siempre quiso

`guardarCotizacion()` bloqueaba el paso a `enviado`/`confirmado`/`operado` con conflictos, y su
propio comentario decía: *«lo que hay que proteger es PUBLICAR algo con conflictos»*. Tenía que
aproximarlo con una lista de estados porque publicar no existía. Ahora vigila el paso a `publicado`,
y la aproximación fallaba por los dos lados: pasar de `enviado` a `confirmado` disparaba el aviso
sin publicar nada nuevo, y publicar una `pendiente` no lo disparaba en absoluto.

## 6.j.2 El estado `OPERATIVA` (02/09/2026)

Otra fila de la **misma** propuesta, distinguida por estado, como el histórico. El histórico mira
atrás y ésta mira adelante, y las dos cuelgan de la confirmada por `derivadaDe`. **No consume
número de propuesta.**

⚠️ **Entró sin migración**: `estado` es `varchar(30)`, así que un `case` nuevo no toca el esquema.
`doctrine:schema:validate` sigue en sync.

### 🔥 Lo que cazó cada herramienta, y lo que no cazó ninguna

Al añadir el `case`, el resultado fue exactamente el que el plan predecía:

| | ¿Saltó? |
|---|---|
| Los `match` exhaustivos del enum | 🔊 sí — obligan a decidir |
| `ESTADO_COTIZACION_CONFIG` en `util`, vía el union regenerado de `api.d.ts` | 🔊 sí — `TS2741: Property 'operativa' is missing` |
| **El `match` de `CotizacionConfirmadaEventListener`** | 🔇 **NO** — tiene `default`, y PHPStan lo da por bueno |

El listener es el único sitio donde un estado nuevo entra en silencio, y su propio comentario lo
advertía desde antes: *«caer en el `default` las dejaría activas sin que nada lo denunciara»*.
Ahora `OPERATIVA` está explícita —sus filas de operación van **activas**, como las de una
confirmada— y el `default` lleva escrito que sólo lo pisa `OPERADO`, donde «no tocar nada» sí es
lo correcto.

**La regla:** en ese `match`, un estado nuevo va arriba con su caso. El `default` no es un
comodín: es la rama de `OPERADO`.

### `esPublico()` se borró

Ya no decidía nada —la visibilidad es `publicado` (§6.j.1)— y dejarlo habría sido peor que
borrarlo: el siguiente que lo leyera creería que sigue mandando, y respondería que una operativa no
es pública cuando sí puede serlo.

## 6.j.2.b El selector de estado no ofrece lo que hace un proceso (03/09/2026)

`ESTADOS_ELEGIBLES` — los seis que el operador **puede elegir**, en el orden en que se ofrecen:

```
Pendiente → Enviado → Confirmado → Operado    la venta, de principio a fin
──────────────────────────────────────────
Cancelado · Cerrado                           los dos finales que NO son venta
```

⚠️ «Operado» va **detrás de confirmado**, no abajo: es el último paso del camino bueno, no una
salida. Ordenar por el recorrido real es lo que permite elegir sin leer.

### ⚠️ `historico` y `operativa` NO están, y es lo importante de esa lista

No son estados que se escojan: son el **resultado de un proceso**, cada uno con su botón y sus
consecuencias. `historico` congela una copia; `operativa` **traspasa las 47 filas de La Biblia**.
Ofrecerlos en un desplegable invitaba a provocar eso sin saberlo — y, peor, a marcar «Operativa» en
una cotización cualquiera y dejarla en un estado **que ningún proceso creó**: sin `derivadaDe`, sin
traspaso, sin financiero heredado. Una operativa de mentira que el resto del código da por buena.

⚠️ **Que no se ofrezcan no significa que se pisen.** Una fila que ya está en uno de esos estados lo
conserva, y el editor lo enseña **bloqueado con un candado** y el porqué en el `title`. Coaccionarla
a un estado elegible al abrir el editor habría sido la otra forma del mismo error.

### Y de paso, tres cosas que faltaban ahí

| | |
|---|---|
| El `<select>` nativo | Sustituido por un desplegable con el color y el icono de cada estado, que ya estaban en `ESTADO_COTIZACION_CONFIG` y no se usaban |
| El orden | «Operado» y «Cancelado» al final: se eligen una vez y ya está |
| **`publicado` no se veía** | Interruptor propio, al lado del estado — **no dentro**. Son dos ejes (§6.j.1) y mezclarlos es lo que obligaba a poner «Enviado» sólo para poder mirar |

⚠️ El respaldo del selector enseña el valor crudo en gris si el estado no está en el mapa: un
estado nuevo en el backend dejaría la cabecera **vacía**, y feo se ve pero invisible no.

## 6.j.3 Abrir la operativa: el traspaso de la operación (02/09/2026)

`POST /client/cotizacion/{id}/operativa` → `AbrirOperativaProcessor`. Clona la confirmada hacia
**adelante**: misma propuesta, estado `OPERATIVA`, `derivadaDe` apuntando a ella, **sin publicar**,
y con el `clasificacionFinancieraCliente` heredado tal cual —ya se vendió y ya se cobró—.

```
antes    CONFIRMADA  47 filas de operación activas   ·  OPERATIVA  no existe
después  CONFIRMADA   0 filas activas (congelada)    ·  OPERATIVA  47 activas
```

Medido con datos reales sobre `2KVBMX`: **874 ms**, en una sola transacción.

### Por qué es una acción explícita y no un efecto de confirmar

El plan la describía naciendo sola al confirmar. Crear y persistir una entidad **dentro del
`onFlush`** que confirma obliga a gimnasia con la unidad de trabajo, y ahí es donde se rompen las
cosas de forma difícil de ver. Con una acción propia el traspaso cabe en una transacción legible —
y además el operador decide cuándo abrirla, que es lo que se pidió.

### ⚠️ Tres fallos mudos que sólo vio el sondeo con datos reales

Los tres pasaban PHPStan, los 540 tests y una lectura a ojo:

| Qué | Síntoma | Por qué |
|---|---|---|
| La cancelación no cancelaba | Las 47 filas seguían activas | Un `Uuid` dentro de un `IN` **no casa ninguna fila y no da error**. Con `ArrayParameterType::BINARY` y `toBinary()`, sí |
| La operativa nacía con **cero filas** | El cuadro de operación, vacío | `CotizacionConfirmadaEventListener` sólo recorre `getScheduledEntityUpdates()`, y la operativa nace como **inserción**: su `case OPERATIVA` no se pisaba nunca |
| Lo `COMPLETADO` se habría cancelado | Se borraría el registro de lo ya operado | El listener sí protege ese estado; este camino no pasa por él y había que repetir la regla |

⚠️ **Y el sondeo tuvo el mismo fallo antes que el código:** contaba las filas con
`IN (:cotservicios)` pasando entidades, así que decía «(ninguna)» sobre una cotización con 47.
Un verificador que comparte el punto ciego de lo que verifica no verifica nada — por eso cuenta en
SQL crudo.

### ⚠️ Lo que se vio sólo al mirar la pantalla

Dos cosas que ningún test ni el typecheck podían denunciar, porque el código era correcto **hasta
que existió la operativa**:

| Qué se veía | Por qué |
|---|---|
| Dos tarjetas diciendo «P1 · Propuesta 1», sin nada que dijera que la segunda sale de la primera | La operativa comparte número a propósito. Se resolvió como los históricos —pegada a su confirmada— pero **sin plegar**: un histórico se archiva, una operativa es la fila viva |
| El botón del plan de operación seguía en la **confirmada**, que ya tiene sus 47 filas canceladas | Su `v-if` decía `estado === 'confirmado'`, que era exacto mientras la confirmada fuera el único sitio donde vivía la operación |

El segundo es el más instructivo: **una condición correcta puede dejar de serlo sin que nadie la
toque**. `estado === 'confirmado'` no significaba «la confirmada», significaba «donde vive la
operación» — y el día que eso dejó de coincidir, el botón apuntaba a un plan vacío mientras la fila
con las 47 filas no lo ofrecía. Ahora dice lo que quiere decir:
`operativa || (confirmado && sin operativa)`.

⚠️ **Merece buscarse en otros sitios.** Cualquier `estado === 'confirmado'` escrito antes del
02/09/2026 hay que releerlo preguntando cuál de las dos cosas quería decir.

### Idempotente

Si ya hay una operativa para esa propuesta, se devuelve la que hay. Abrir dos sería tener dos
sitios donde mirar qué se va a operar. `generarParaCotizacion()` también lo es —salta el
componente que ya tiene fila—, así que el `case OPERATIVA` del listener y esta llamada explícita
no se pisan.

### ⚠️ Clonar ya no retraduce

Duplicar un árbol de cotización llamaba a Google por cada una de sus 162 entidades: `persist()`
tardaba **245 segundos**. Ahora tarda 73 ms. Afectaba también a «Guardar foto». El detalle, y por
qué el `origenHash` no lo evitaba, en `docs/Autotraduccion.md` §9.

## 6.j.4 La vista del cliente se compone de DOS filas (02/09/2026)

```
financiero  ←  SIEMPRE la confirmada
itinerario  ←  la operativa
```

Ya se vendió y ya se cobró: lo que pase en la operación es un tema proveedor–agencia.

### ⚠️ Heredar el financiero al abrir NO basta

`AbrirOperativaProcessor` copia el financiero al crear la operativa, y aun así hacía falta más. El
editor **recalcula y envía `totalVenta` y `clasificacionFinancieraCliente` en cada guardado**
—`cotizacionEditorStore.ts`, «Inyección de la estructura financiera al payload»—, así que el valor
heredado dura hasta el primer guardado. Y guardar es exactamente lo que se hace con una operativa:
partir vuelos, mover cantidades.

Sin la composición, **reorganizar la operación le cambiaría los precios al cliente**. Probado con
datos reales: la operativa guardada a `99999.00` seguía enseñando `5922.09`.

### Dónde vive, y por qué en dos sitios

| | |
|---|---|
| `Cotizacion::origenFinancieroParaCliente()` | La regla. Devuelve `derivadaDe` si es OPERATIVA, si no `$this` |
| `getTotalVentaParaCliente()` · `getClasificacionFinancieraParaCliente()` | Los dos campos que el editor recalcula, servidos por getter con `#[SerializedName]` — la API sigue diciendo `totalVenta` |
| `CotizacionFilePublicProvider`, consulta de la portada | ⚠️ **Lo repite**, porque lee columnas y no entidades: ahí el getter no llega a ejecutarse. `LEFT JOIN c.derivadaDe o` |

⚠️ **La condición mira el ESTADO, no que `derivadaDe` esté puesto.** Un histórico también lo tiene
—apunta a la viva— y debe enseñar **su propio** dinero, que es justo lo que fue a congelar.

### Por qué en lectura y no congelando el campo

Se pensó en un candado que devolviera los valores heredados al guardar. Se descartó porque rompe el
otro caso que sí se quiere: si alguien edita **la confirmada** —renegociar es rutina, y el plan lo
permite a propósito—, el cliente debe ver los precios nuevos. Leyendo de la confirmada eso sale
gratis; con una copia congelada habría que acordarse de propagarla.

### ⚠️ Un campo servido por getter cambia el esquema

`totalVenta: string` pasó a `readonly totalVenta?: string` en los dos `api.d.ts`: API Platform no
puede prometer que un método esté siempre ahí como promete una columna. El typecheck de las dos
apps salió limpio —nadie escribía ese campo desde `pax`—, pero conviene saberlo antes de repetir
el patrón.

## 6.j.5 Quién ve qué en un expediente de grupo (02/09/2026)

```
Portada                          abierta · SIN manifiesto
 ├── confirmadas e históricas    abiertas — se navegan enteras
 └── la OPERATIVA                FORMULARIO · no funciona nada detrás
```

### El manifiesto se cae de la portada, y no es cosmética

`pax_file:read` servía `filepasajeros` entero. En `5SRAJV` eso eran **133 nombres con su número de
documento**, a la vista de cualquiera con el enlace — y al probarlo resultó que son **menores**: es
un viaje de colegio.

`FileModoEnum::ocultaManifiesto()` lo corta en modo grupo. En un expediente de dos personas se
queda: el padrón son ellos mismos, y ver «vamos los dos» es lo que se espera de una confirmación.

⚠️ **Se corta en el SERVIDOR, no en la vista.** Un `v-if` deja los 133 nombres viajando en la
respuesta, a un F12 de distancia. Se sirve por `getManifiestoParaCliente()`, con `SerializedName`
para que la API siga diciendo `filepasajeros` y `pax` no cambie: recibe una lista vacía y su `v-if`
ya se encarga.

### La operativa: formulario o nada

Documento **y** fecha de nacimiento. Se descartó enseñar el itinerario común escondiendo lo
personal: obliga a clasificar cada campo nuevo, y **olvidarse lo deja a la vista** — un fallo que
no da error y que sólo se descubre cuando ya lo vio quien no debía. Con la puerta entera cerrada,
la pregunta «¿este campo es personal?» deja de existir.

⚠️ **No pretende ser un secreto fuerte.** El número de documento circula —está en una reserva, en
un correo, en una lista de colegio—; la fecha de nacimiento no suele acompañarlo. Esto separa a las
133 familias entre sí, no defiende de un atacante decidido. **Por eso el freno de intentos no es
opcional**: sin él, un documento fijo y un barrido de fechas encuentra a cualquiera en una tarde.

| | |
|---|---|
| Dónde vive la identidad | La **sesión** del servidor, llave `pax_identificado.<fileId>`. Una por expediente: identificarse en un viaje no abre el de al lado |
| Por qué no un token | Un token en la URL se reenvía por WhatsApp sin querer — el reenvío que esto viene a limitar |
| Freno | 8 intentos / 15 min, **por expediente Y por IP**. Sólo por IP castigaría a un colegio tras un NAT; sólo por expediente, a las 133 por culpa de un curioso |
| Mensaje de fallo | **Uno solo para los dos casos.** Decir «ese documento no está en el grupo» convierte el formulario en un buscador de documentos |
| Normalización | El documento se compara sin puntos ni espacios y en mayúsculas: la gente no lo escribe dos veces igual |

### 🔥 El directorio del controlador ES la configuración de host

`config/routes.yaml` ata cada carpeta a un host: `Cotizacion/Controller/Publico/` cuelga de
`%app.host.pax%` y `Cotizacion/Controller/Api/` de `%app.host.api%`.

El endpoint de identificación nació en `Publico/` —parece el sitio: es una pantalla pública— y
**quedó publicado en el host equivocado**. `pax` llama por `apiClient`, o sea a `api.openperu.pe`,
así que identificarse habría dado 404 en producción.

⚠️ **No lo cazó nada hasta el `debug:router` del despliegue**: en local los dos hosts responden, y
ninguna prueba pide la ruta por su URL completa. La regla: **un controlador al que llama `pax` por
`apiClient` va en `Controller/Api/`, aunque su pantalla sea pública.** Lo que decide es quién hace
la petición, no quién la mira.

### ⚠️ 403 y no 404

El 403 lleva el código `IDENTIFICACION_REQUERIDA` en el cuerpo. Un 404 diría «no existe» —mentira,
y alarmante para quien mira su propio viaje— y `pax` no tendría cómo saber que debe enseñar el
formulario. El front mira el **código**, no el texto: mirar el mensaje sería atarse a una
traducción.

⚠️ En `pax`, el bloque del formulario va **antes** que el de «no encontrada» en el `v-else-if`.
Invertirlos le diría al cliente que su viaje no está.

⚠️ **Al identificarse se recarga, y hay que invalidar la caché a mano.** `pax` retiene el detalle
30 s; si el 403 llegó teniendo una copia guardada de esa propuesta, `cargarPropuesta()` la daría
por fresca y volvería sin pedir nada — el cliente se identificaría bien y **seguiría sin ver su
viaje, sin ningún error que lo explicara**.

### «No soy yo»: salir de la identificación

El enlace de un viaje de grupo se abre en dispositivos compartidos —el móvil de la familia, el
ordenador del colegio—, y sin salida la primera persona que entra deja su nombre, su localizador
de vuelo y su habitación puestos para el siguiente. El botón vive en la tarjeta «Lo tuyo», que es
donde se ve el nombre ajeno.

```
DELETE /platform/sales/client/cotizacion/{localizador}/identificar
```

Misma ruta que el `POST` de entrar, con el verbo opuesto: entrar y salir son la misma puerta.
`OlvidarIdentidadController` → `IdentidadDelPasajero::olvidar()`, que sólo quita la llave de
sesión de **ese** expediente.

| Decisión | Por qué |
|---|---|
| Dice **«No soy yo»**, no «Cerrar sesión» | Quien pulsa casi nunca es quien se identificó, sino el siguiente, que ve un nombre ajeno. Dicho como acción de sesión, ni se le ocurre que sea eso |
| Responde **200 aunque no hubiera nadie** | Es idempotente por naturaleza: quien llama quiere quedar sin identidad, y ya estarlo es el resultado que pedía |
| **NO borra el contador de intentos** | Si lo borrara, salir y volver sería la forma de saltarse el freno: ocho pruebas, cerrar, otras ocho. El contador va por IP y expediente en caché, así que ni se toca |
| Se **recarga la propuesta**, no se borra la tarjeta | El itinerario venía recortado a los subgrupos de quien estaba dentro. Hay que volver a pedirlo; el servidor contesta 403 y el formulario aparece solo |

⚠️ **Se invalidan LAS DOS cachés, no sólo la del detalle.** «Lo tuyo» sale de `miIdentidad`, que
viaja en la respuesta de la portada **y** en la del detalle. Dejar la portada fresca devolvería a
alguien recién salido a una pantalla con su nombre puesto — el mismo descuido que ya costó caro al
entrar, en el otro sentido.

⚠️ El controlador vive en `Controller/Api/` y **el directorio es la configuración**: `pax` llama
por `apiClient`, o sea al host de la API. Es la trampa que ya se pagó con
`IdentificarPasajeroController`, que nació en `Controller/Publico/` y quedó publicado en el host
equivocado.

### 🔥 «Incluye / No incluye» desapareció de toda operativa (04/09/2026)

Sin un error, sin un hueco: el panel entero se callaba. 18 servicios con inclusiones, 43 líneas,
cero pintadas.

`getClasificacionFinancieraParaCliente()` devuelve el bloque de la **confirmada** cuando la
propuesta es una operativa —el dinero es el vendido, ver `origenFinancieroParaCliente()`—, y eso
está bien. Lo que nadie miró es que ese mismo blob lleva dentro `inclusiones`, y **cada línea trae
el `servicioId` al que pertenece**. La operativa es un clon con ids nuevos:

```
inclusiones[0].servicioId   b633c1bc-…    ← la confirmada
itinerario[0].servicio.id   01a06e82-…    ← el clon
```

`pax` cruza esos ids contra los servicios de SU itinerario (`inclusionPorServicio`), no casaba ni
uno, y `inclusionesPorDia` devolvía vacío para los siete días.

**Las inclusiones no son dinero**: describen lo que la persona va a recibir, y la operativa existe
justamente para decir eso mejor que la confirmada. Se toman de `$this`; los importes y
`opcionesUpgrade` siguen viniendo del origen.

⚠️ **`opcionesUpgrade` se queda con las del origen** a propósito: ahí sí hay dinero —el delta que
se cobraría— y no se cruzan por id contra el itinerario, se pintan solas en la tarjeta de precio.

⚠️ **Cómo se encontró, porque no había nada que leer.** Los ids son ids: casan o no. Tres sondas
dijeron que todo estaba bien —la columna tenía los ids nuevos, las fechas caían en días válidos,
18 de 18 casaban en PHP— y todas preguntaban por **la columna**, no por lo que la API sirve. Sólo
apareció comparando en el navegador `inclusiones[].servicioId` con `itinerario[].servicio.id`.
**Cuando la pantalla y el dato no coinciden, el sospechoso es el getter que hay en medio.**

#### Lo que la revisión encontró encima (05/09/2026)

**1 · El panel se saltaba el filtro por subgrupo, y lo estrenó el propio arreglo.** Devuelto el
panel, un pasajero del PNR de Sky veía en «¿Qué incluye este día?» las líneas del componente de
JetSMART —el que se le había ocultado—: `inclusionesPorDia` cruza por **servicio y fecha**, nunca
por componente. Ahora `Cotizacion::acotarInclusiones()` deja sólo lo de los componentes que esa
persona ve. Medido en producción: 43 líneas sin filtro, **33 acotado a `YMFLHB`**, con 33 de 43
componentes visibles.

⚠️ **Una línea sin `componenteId` pasa.** Las de ítem no lo llevaban hasta hoy —se añadió en
`cotizacionEditorStore.ts`— así que una propuesta guardada antes perdería media lista, y perder lo
legítimo es peor que enseñar de más. Se acotan solas en cuanto alguien guarde.

🐛 **Y las OPCIONALES siguieron sin llevarlo hasta el 05/09/2026.** De las cuatro secciones que
`acotarInclusiones()` filtra —`incluidos`, `noIncluidos`, `cortesias`, `opcionales`— la última era
la única cuyas líneas nacían sin `componenteId`, así que la puerta de arriba las dejaba pasar
todas: un pasajero del PNR de Sky leía entre los opcionales los del JetSMART, el mismo componente
que se le había ocultado. El filtro estaba bien y la lista estaba bien; lo que faltaba era el hilo
entre las dos. Arreglado en el `push` de `bloque.opcionales`, con la misma línea que ya llevaban
las otras tres.

**2 · Una operativa recién abierta nacía con el panel vacío.** `AbrirOperativaProcessor` hereda el
blob tal cual, con los `servicioId` de la confirmada; se arreglaba al primer guardado desde el
editor, pero abrir y publicar desde el ojo del expediente no pasa por ahí — y es el camino corto.
Ahora `remapearInclusiones()` traduce los ids al clon: **18 de 18 servicios y 36 de 36
componentes** en la prueba.

⚠️ **La correspondencia se toma por POSICIÓN**, que es como `duplicar()` construye la copia. Si las
formas no coinciden no se remapea **nada**: un mapa a medias ataría líneas al componente
equivocado, y eso es peor que un panel vacío.

**3 · Un invitado desaparecía de su propia habitación.** `companerosDe()` descartaba a todo
`!esExpuesto()` sin excepción. Se le esconde de los demás, no de sí mismo.

**4 · Un `NO_PARTICIPA` salía como tu compañero de cuarto.** `esExpuesto()` lo da por visible
—existe en el manifiesto— pero no viaja. Enseñarlo dice que duermes con alguien que no va.

🐛 **4 bis · Y al arreglarlo se cayó el pasajero SIN rol** (visto el 05/09/2026). La guarda quedó
como `$tipo === null || !$tipo->esExpuesto() || …`, y `null` no es una decisión: es lo que
devuelve `PasajeroTipoEnum::desdeTexto()` cuando la hoja trae un rol que no está en la plantilla.
Son pasajeros del grupo con nombre y apellido —**2 de 135** en producción— que desaparecían de
«mis grupos» sin que nadie pudiera notarlo, porque no se echa de menos a quien no sabías que
estaba. Vale la regla general del proyecto: **sin clasificar es sin acotar**; de más se ve, de
menos nunca.

**5 · «Desde $ 0,00».** `normal` sólo suma líneas `incluido`, así que un perfil liberado vale 0 y
encabezaba la tarjeta con un viaje gratis. El «desde» se calcula ahora entre los perfiles con
precio, y si ninguno lo tiene manda el dominante y no se anuncia «Desde».

**6 · Dieciséis claves i18n usadas por `pax` no estaban sembradas**, entre ellas **el formulario de
identificación entero**: lo PRIMERO que ve un pasajero extranjero, en castellano y sin un error que
lo denunciara. Se descubrió cruzando las 183 claves que usa `pax` con las 233 de la tabla:

```bash
grep -rhoE "\.t\(\s*'[a-z0-9_]+'" pax/src | grep -oE "'[a-z0-9_]+'" | tr -d "'" | sort -u
php bin/console dbal:run-sql --force-fetch "SELECT id FROM pax_ui_i18n" --env=prod
comm -23 usadas.txt sembradas.txt
```

Quedan fuera las de catálogo (`cat_*`) y reservas (`res_*`): mismo agujero, otra pantalla.

**7 · Tres textos decían lo contrario del código** y ya lo dicen bien: el comentario de
`ponerIdentidad()` («la relación entera nunca se expone»), la cabecera de `AlcanceDelPadron` y
`PasajeroTipoEnum::alcance()`.

Y cuatro fragilidades: «tú» se marca por **posición** y no por texto (dos homónimos se lo llevaban
los dos), el orden usa una clave **sin tildes** (`strcoll` con locale `C` mandaba «Ñuñez» detrás de
la Z), `selloDeComponente()` se calcula una vez por fila y no dos, y se fue un `use` muerto.

### «Lo tuyo» dice ahora CON QUIÉN (04/09/2026)

Cada subgrupo de la tarjeta trae `miembros`: los nombres de quienes están en **ese** subgrupo suyo.
La primera pregunta de quien viaja en grupo es con quién duerme y con quién embarca; el expediente
lo sabía —la habitación y el PNR son subgrupos con miembros— y la tarjeta lo callaba.

```
HABITACIÓN · SONESTA          VUELO · NACIONAL
DOBLE                         Sky Airline
▸ 2 personas                  ▸ 4 personas
   · Dariana Jaramillo           · Militsa Villafuerte Florez   ← coordinadora
   · Alejandra Valdivia  TÚ      · Braulio Acosta Castro
                                 · Alejandra Valdivia    TÚ
```

| Decisión | Por qué |
|---|---|
| **Sólo los subgrupos de quien pregunta** | Sigue sin ser el padrón: son SUS grupos. Los 133 nombres no salen |
| **Sólo el nombre** | Ni documento, ni fecha, ni el `codigo` del vecino — un localizador ajeno con un apellido abre la reserva de otro en la web de la aerolínea |
| **Los invitados no salen** | No es un filtro por rol: `PasajeroTipoEnum::esExpuesto()` dice que no existen en ninguna vista pública. Son gratuidades de la agencia, y un hueco se pregunta igual que un nombre |
| **El responsable primero**, luego por apellido | Coordinador, supervisor, resto: es a quien se busca cuando algo pasa. Y una lista de pasajeros se lee por apellido |
| **Te marca a ti**, no te esconde | Verse confirma que la reserva es la tuya; una lista de la que faltas invita a preguntar si hubo un error |
| **Colapsado** | Un PNR lleva 44 nombres: desplegados sepultan lo que la tarjeta existe para decir |

#### La tarjeta nace CERRADA, con «Ver mis grupos (4)»

Nació abierta porque es lo primero que busca quien viaja en grupo, y con dos subgrupos ocupaba
poco. Con cuatro —habitación, grupo, vuelo nacional, vuelo internacional— y la gente dentro, empuja
el itinerario entero fuera de la pantalla: quien entra a mirar el día 3 tiene que pasar por encima
de su propia ficha.

```
LO TUYO · Alejandra Valdivia            [ NO SOY YO ]
        ▸ VER MIS GRUPOS (4)
```

⚠️ **Lo que NO se colapsa es la cabecera.** El nombre se queda a la vista porque dice de quién es
esta sesión —en un móvil compartido eso es lo primero que hay que poder desmentir— y con él «No soy
yo», que escondido dentro no lo encuentra nadie.

⚠️ **El disparador dice cuántos hay.** «Ver mis grupos (4)» invita a abrirlo; «Ver mis grupos» a
secas podría no llevar a nada, y un control que quizá esté vacío se pulsa una vez y no más.

⚠️ **Esto cambia una regla escrita.** `AlcanceDelPadron` dice «Alumno · Padre · Invitado → sólo su
ficha», y esto les da los miembros de sus propios subgrupos. Se decidió así a conciencia —la
comodidad primero, y a esa gente la ven en el aeropuerto—. Ese servicio **sigue sin consumirse por
nadie**; el día que se conecte, esta excepción hay que escribirla ahí.

🔥 **El `IN` con UUID binario no casa, y ni pasando la entidad.** Medido contra producción sobre
una habitación con dos personas:

```
g = :g            (la ENTIDAD)              → 0 filas
g.id = :id        (Uuid suelto)             → 0
g.id = :id        (Uuid + UuidType::NAME)   → 2  ✅
g.id IN (:ids)    (Uuid[] suelto)           → 0
g.id IN (:ids)    (binario + BINARY)        → 2  ✅
g.clave = :c      (control, sin uuid)       → 2
```

**Pasar la entidad tampoco vale**, que es lo que uno probaría primero: Doctrine la convierte al id
y ahí se pierde igual. La nota del proyecto avisaba de los `Uuid`; esa fila es nueva. Cero filas y
ni un error: el panel salía vacío como si nadie compartiera habitación.

⚠️ **Y una consulta para todos los grupos, no una por grupo.** Recorrer `$grupo->getMiembros()`
hidrata pertenencias y pasajeros de cada uno: con un vuelo de 44 son 45 consultas por subgrupo. Es
la misma trampa que ya costó cara en `OperacionServicio::getPasajeros()`.

### El filtrado por persona ya está — ver §6.j.6

Se completó el 03/09/2026, en la misma sesión: quien se identifica ve sólo los componentes sin
subgrupo y los de los suyos. Falta pintarle su `codigo`.

## 6.j.5.b «Lo tuyo»: lo que ve quien se identifica (03/09/2026)

```
Grupo         · Grupo 2
Habitación    · DOBLE
Vuelo Nacional      · Sky Airline   XKP4QT   ← su localizador
Vuelo Internacional · Arajet
```

### ⚠️ Es un campo CALCULADO, no la relación

La tentación es exponer `filepasajeros.pertenencias` a `pax` y que el front busque su fila. Eso
**manda el padrón entero**: los 133 nombres con el localizador y la habitación de cada uno, a un
F12 de distancia. Es exactamente lo que la identificación viene a impedir.

`CotizacionFile::$miIdentidad` sale de `CotizacionFilePublicProvider::ponerIdentidad()` y contiene
**una** persona: la de la sesión. Probado con datos reales — otra persona identificada en el mismo
expediente no ve el código ajeno.

### El eje `servicio` se queda fuera

Es binario —se va a Coco Bongo o no— y no lleva valor. Sus 10 filas llenaban la tarjeta de cosas
que ya cuenta el itinerario y enterraban las dos que sí son personales. Lo decide
`GrupoTipoEnum::esEjeConValor()`, que ya existía.

### Cómo se rellena el código: columna hermana en el padrón

```
#Reserva aérea internacional   Cód. #Reserva aérea internacional
BONT3N                         XKP4QT
```

Mismo patrón que `Venc. ` para el vencimiento de un documento: una columna que cualifica a otra.

⚠️ **Y no pegada a la clave.** La exportación parte «clave + rótulo» por el espacio, y aquí no
vale: un localizador de grupo y un código individual son los dos seis caracteres sin espacios, así
que no hay forma de saber cuál es cuál. Con el rótulo se puede porque es una palabra
(«Jetsmart»); con dos códigos, no.

⚠️ **Una celda en blanco NO borra.** En un reenvío del archivo significa «no lo sé», no
«bórralo»: quien puso el localizador a mano no lo pierde porque el Excel no lo traiga.

⚠️ **Y se empareja por POSICIÓN, no por eje.** Un pasajero puede estar en dos grupos del mismo
(eje, tramo) —dos vuelos «Nacional» distintos— y entonces la plantilla saca **dos** `#Vuelo
Nacional`, cada una con su `Cód.` al lado y **con cabeceras idénticas**. Indexando por eje la
segunda pisaba a la primera y las dos pertenencias se llevaban el mismo código, sin ningún error.

Hoy no muerde —en `5SRAJV`, con 24 grupos de vuelo, **ningún** pasajero está en dos del mismo
tramo—, pero la plantilla ya sabe emitir esas columnas (`$cuantosPorEje` usa `max`), así que era
una trampa esperando datos. Un código que no va pegado a su clave ahora **se avisa y se ignora**,
en vez de aplicarse al grupo equivocado.

## 6.j.6 Cuando el grupo se parte: subgrupos y códigos (03/09/2026)

```
Servicio «Vuelo»
  Componente «LIM–PUJ»  grupo = #Vuelo Nacional        22 pax
  Componente «LIM–PUJ»  grupo = #Vuelo Internacional   18 pax
  Componente «Hotel»    grupo = null                   los 40
```

⚠️ **Las cantidades por componente dejan de sumar el total del grupo, y es correcto.** Era
exactamente lo que antes no se podía representar.

### 🔥 VARIOS subgrupos por componente, no uno

Empezó siendo `?CotizacionFileGrupo`, uno solo, y se rompió contra los datos reales:

```
H2 5002 · 17-Sep 06:50    2 PNRs    88 personas
JA7018  · 17-Sep 07:15    7 PNRs    32 personas
JA7030  · 17-Sep 20:05    1 PNR      2 personas
```

El vuelo JA7018 lleva **32 personas repartidas en 7 PNRs**. Con un solo subgrupo harían falta
**siete copias del componente** —mismo vuelo, misma hora, misma orden al proveedor— sólo porque el
modelo no sabía decir «estos siete». El reparto operativo es **por vuelo**, no por localizador.

⚠️ **Y no se ató al VUELO**, que fue la otra idea sobre la mesa. Apuntar a `CotizacionVuelo`
resolvía el caso aéreo y **sólo** ése. Con una lista de subgrupos el mismo campo sirve para «los de
la habitación 101 y 102», para «los que sí van a Coco Bongo» y para cualquier corte que alguien
invente con el eje `GRUPO`, que es texto libre. **El acotador general es la gente, no el medio de
transporte.**

### La franja: partes colapsadas y el detalle del vuelo al pulsar

```
REPARTIDO EN 2 · Vuelo Lima ↔ Cusco
  ▸ Vuelo · Nacional · Sky Airline     ×1    88 pax
  ▸ 8 reservas                          ×1    35 pax      ← colapsado
  Cubre 123 de 133 pax (del manifiesto; se cotizó para 100) · faltan 10
```

⚠️ **Colapsadas por defecto.** Una parte puede llevar ocho reservas y sus rótulos **se repiten**
—«Vuelo · Nacional · JetSMART» ocho veces—, así que desplegadas convierten la franja en una pared
de texto que tapa lo único que se mira de un vistazo: cuánta gente cubre.

Al desplegar, cada reserva con su localizador y un botón **ⓘ**:

```
YMFLHB
H2 5002 · 17-09 06:50→08:35 · CUZ→LIM
H2 5721 · 23-09 06:35→08:20 · LIM→CUZ
```

### 🔥 La fecha del servicio la ancla el componente ORIGINAL

```
Servicio «Vuelo»
  Componente  18-set 22:00   ← ORIGINAL: manda
  Copia       19-set 02:00   ← no cuenta
  ────────────────────────
  fechaInicioAbsoluta = 18-set
```

Un servicio repartido tiene partes en fechas distintas y **es correcto**: un vuelo sale a las 22:00
de un día y el otro a las 02:00 del siguiente. Son el mismo servicio y dos componentes con fecha
distinta.

⚠️ **Si la copia contara, arrastraría al servicio.** `sincronizarFechaServicio()` toma el mínimo, y
bastaría con que una copia saliera antes que el original para llevarse el servicio al día anterior
—y con él los segmentos, el relato y el orden del itinerario—. Cada reparto movería la escaleta
entera.

Y no sólo en el caso raro: en uno **normal**, una copia recién creada con la hora todavía sin
ajustar mandaría sobre el original mientras se la termina de configurar.

⚠️ Si sólo quedan copias —posible si se borra el original de un reparto ya hecho— se usan igual:
mejor una fecha que ninguna.

### ⚠️ Y al mover un segmento de día, se DESPLAZA

`onSegmentoDiaChange()` fijaba la fecha del segmento en todos sus componentes, lo que **aplastaba
el desfase**: el vuelo de las 02:00 del día siguiente volvía al día del original, en silencio.
Ahora calcula los días de diferencia **desde el ancla** y mueve a todos esa cantidad, así que las
copias conservan su distancia. Hay un test que lo fija.

### 🔥 Un `any` que no era de TypeScript: era un nombre inventado

Al ir a exponer la segunda función para probarla, `vue-tsc` sacó **tres `TS7006`** en archivos que
no se habían tocado: parámetros de callback perdiendo su tipo en `ResumenClasificacion.vue`, en la
vista del editor y hasta en otro test. La lectura fácil —y la que escribí aquí primero— fue «el
store tiene 110 miembros y TypeScript se rinde infiriéndolo».

**Era falso.** El error que importaba estaba tres líneas más arriba, tapado por el ruido:

```
TS18004: No value exists in scope for the shorthand property 'moverSegmentoDeDia'
```

La función no se llamaba así: es `onSegmentoDiaChange`, **y ya estaba expuesta**. Un identificador
inexistente en el `return` convierte el tipo del objeto entero en un tipo de error, y desde ahí el
store completo pasa a `any` **en toda la app** — de golpe, ningún callback vuelve a inferir.

⚠️ **La lección, que vale más que el arreglo:** cuando una edición pequeña saca errores de tipo en
archivos que no tocaste, el culpable casi nunca es el compilador rindiéndose. Es **un símbolo roto
cerca del cambio** cuyo tipo de error se propaga. Se lee el error de MÁS ARRIBA, no el más
llamativo — `TS18004` estaba ahí, entre tres `TS7006` que no significaban nada por sí solos.

### 🔥 Un botón, no una pulsación larga sobre el texto

La primera versión usaba `v-tooltip-tactil` sobre la fila. **En el móvil no salía nunca**: el
long-press sobre texto activa la **selección del sistema**, que se queda el gesto antes de que
llegue el temporizador. El globo funcionaba en la prueba sintética —donde se disparan los eventos a
mano— y no en un dedo real.

El botón se **toca**, sin ambigüedad. Y el detalle sale **en línea**, no flotando: no tapa nada y
se puede copiar. El `title` se queda para el hover de escritorio, que ahí sí funciona.

⚠️ La directiva `v-tooltip-tactil` sigue valiendo para **botones-icono sin rótulo** —una fila de
papeleras y cámaras—, que es para lo que se escribió. Sobre texto seleccionable, no.

⚠️ **Se enseñan todos los vuelos, incluidos los de otras fechas.** Una reserva cubre ida y vuelta,
y ver que ese PNR también vuela el 23 es **justo lo que dice si es el que toca hoy**. Esconder los
demás dejaría al operador eligiendo a ciegas entre ocho «JetSMART» idénticos.

### ⚠️ Los vuelos llegan en UNA consulta

`$vuelos` es una `ManyToMany` **inversa**: pedirla subgrupo a subgrupo son **109 consultas** para
pintar una lista de opciones. `CotizacionFileGrupoCollectionProvider` trae los enlaces de golpe y
los reparte en memoria — mismo motivo que `CotizacionFileItemProvider`.

🔥 **Y compara HEX contra HEX.** La primera versión pasaba el UUID sin guiones contra una columna
`BINARY(16)`: **no casaba ninguna fila y no daba error**, así que la lista salía vacía y parecía
que no había vuelos enlazados. Envolver la columna en `HEX()` renuncia al índice y aquí da igual
—son 56 filas—; la alternativa, convertir a binario en PHP, vuelve a poner el error a un descuido
de distancia.

### La cobertura, también dentro del componente

```
A QUIÉN APLICA        [ 2 subgrupos · 88 pax ▾ ]
  ⚠ ESTE SERVICIO VA REPARTIDO EN 2
      A este componente le tocan          88 pax
      Entre las 2 partes              88 de 100 pax
      Sin asignar a ninguna parte         12 pax
```

⚠️ **Una idea por línea y con su rótulo.** La primera versión encadenaba las tres cifras en una
frase —«Esta parte 29 pax · entre todas 122 de 133 · faltan 11»— y no se entendía cuál era cuál. Un
número sin nombre delante obliga a reconstruir qué mide, y aquí hay tres midiendo cosas distintas.

La franja completa vive en el panel del **servicio** y el selector en el del **componente**:
mientras asignabas no veías el efecto, y con cinco partes eso son cinco viajes de ida y vuelta para
saber si ya cubres a los 133. Es el mismo número, al lado del control que lo mueve.

⚠️ **Sólo si el componente está repartido.** Un aviso permanente que casi siempre dice «todo bien»
deja de leerse, y entonces no avisa el día que importa.

⚠️ Y **«Esta parte sin acotar»** cuando la copia todavía no tiene subgrupos: es el estado en que
nace, y decirlo es la mitad del recordatorio de que hay que repartirla.

### 🔥 `Collection::filter()` conserva las claves, y el front revienta lejos

**«Propuesta no encontrada · `e.sort is not a function`»**, 04/09/2026, en la operativa del
colegio. Lo veía un pasajero identificado; el operador no, porque su sesión no filtra.

`CotizacionCotservicio::getCotcomponentesParaCliente()` devolvía el resultado de
`Collection::filter()`, **que conserva las claves**. Si el filtro se llevaba por delante el
**primer** componente del servicio, quedaban las claves `[1, 2…]` y `json_encode` escribía un
**objeto** —`{"1": {…}}`— donde `pax` esperaba un array. El servidor no falla: falla el navegador,
más tarde y lejos, en `ordenarComponentesPorRelato()`, al llamar `.sort()` sobre algo que no lo es.

Se arregla con `new ArrayCollection($filtrados->getValues())` en los dos getters filtrados
—componentes y segmentos—. Reproducido antes de tocar nada, contra producción:

```
subgrupo 01a03480 | servicio 01a06848 | comps claves [1] -> OBJETO
```

Tres cosas que deja este fallo:

- ⚠️ **Dependía de qué subgrupo tocara.** Un hueco sólo aparece si cae el primer elemento, así
  que la mitad de los pasajeros lo veía y la otra mitad no. Nada en el servidor lo distinguía.
- ⚠️ **La lección ya estaba pagada en otro módulo.** `PmsReserva::getEventosCalendario()` lleva el
  mismo `getValues()` con el comentario escrito —«resetea los índices para que en JSON sea
  `[{}, {}]` y no `{"1": {}, "2": {}}`»— desde hace tiempo. No cruzó de módulo.
- 🔥 **La prueba que existía lo escondía.** `CotservicioFiltradoPorSubgrupoTest` leía el resultado
  con `array_values(array_map(...))`: el contenido salía bien y las claves no las miraba nadie. Se
  añadió `testLaColeccionServidaEsUnaLISTA`, que pregunta por `toArray()` y **nunca** por
  `getValues()` — a `getValues()` preguntarle esto siempre da que sí, que es el mismo punto ciego.

### La franja de repartos se retiró de encima de la lista

Encima de los componentes vivía una franja «Repartido en 2 · Vuelo Cusco–Lima … Cubre 88 de 100
pax». Se quitó: decía, con otras palabras, lo mismo que ahora dicen la pastilla de cada tarjeta y
el desglose del panel del componente — y lo decía **lejos de donde se actúa**. Había que leerla
arriba y bajar a averiguar cuál de las cinco tarjetas era la que se había quedado corta.

De ella sobrevive lo único que no estaba en ningún otro sitio: **la ⓘ con los vuelos de cada
reserva**, que se mudó al desplegable de la tarjeta. Enseña todos los tramos, incluidos los de
otras fechas —una reserva cubre ida y vuelta—, que es justo lo que dice si ese PNR es el que toca
hoy.

⚠️ **Y con la franja se fue `repartosDelServicio`, que era un espejo PARCIAL de
`CotizacionCotservicio::repartos()`**: sumaba donde el servidor deduplica, porque el front sólo
recibe `totalMiembros` y no quiénes son. Hoy el único cálculo de reparto en el navegador es
`repartoDelActivo`, que mide **un** componente. Si vuelve a hacer falta el total del servicio, se
pide por la API en vez de reescribirlo aquí.

### Y en la propia tarjeta del componente

Desde la lista no se veía si un componente ya estaba acotado: había que abrirlo para saberlo, y con
cinco partes eso es abrir cinco. Ahora cada tarjeta lleva su pastilla —«2 subgrupos · 88 pax» o
«Todo el grupo»— desplegable a la lista con localizadores.

### El orden del selector: marcados arriba, resto alfabético

Con 109 subgrupos, lo que ya elegiste se pierde entre los demás: hay que desplazarse para comprobar
qué llevas puesto, o se marca dos veces por no verlo. Arriba se lee de un vistazo y desmarcar deja
de ser una búsqueda.

El resto por etiqueta y no por el orden de la base: buscar «Habitación» deja las habitaciones
seguidas, que es como se recorre una lista cuando no sabes exactamente qué buscas.

### En el selector: el vuelo desplegable y lo ya repartido fuera

```
9 subgrupos · 35 pax                    ▾
  🔍 sky
  ☐ Vuelo · Nacional · Sky Airline   YMATXY   44 pax   ⓘ
  ⓘ 1 ya está en otra parte de este reparto
```

Dos cosas al elegir:

**El vuelo, desplegable ahí mismo.** Elegir entre ocho «JetSMART» idénticos sin ver sus horarios es
adivinar; el ⓘ abre los tramos —incluidos los de otras fechas— sin salir de la lista. Sólo aparece
donde hay algo que enseñar: una habitación no tiene vuelos, y un botón que abre un hueco enseña a
no pulsarlo.

**Lo ya puesto en otra parte del reparto desaparece.** Cada PNR va en **una** parte: verlo
disponible en la segunda es una trampa, y marcarlo dos veces cuenta a esas 44 personas dos veces.
Quitándolo, lo que queda es exactamente **lo que falta por repartir**, y la lista encoge conforme
avanzas.

⚠️ **Sólo dentro del REPARTO**, no de la cotización: un hotel y un vuelo comparten subgrupo con
toda naturalidad —los de la habitación 101 duermen ahí y además vuelan—. Lo que no puede repetirse
es el mismo subgrupo en dos partes **del mismo componente partido**, que son las que se suman entre
sí.

⚠️ **Y se dice cuántos se ocultaron.** Que un subgrupo desaparezca sin explicación se lee como un
fallo, y el operador lo busca en vano.

### 🔥 `servicioActivo` es `null` cuando hay un componente abierto

La primera versión buscaba las otras partes en `store.servicioActivo.cotcomponentes`. Los dos
salen de `dataActiva` según el nivel abierto y son **excluyentes**: con el panel del componente
delante, `servicioActivo` es `null`, la lista salía vacía y **el filtro existía sin filtrar nada**.

Sin error, sin aviso: la lista se veía igual que antes. Se localiza el servicio recorriendo
`cotizacion.cotservicios` hasta encontrar el que contiene al componente activo.

### El selector: buscador y CLAVE siempre visible

```
🔍 Buscar por nombre o localizador…
  ☐ Vuelo · Nacional · Sky Airline    YMATXY   44 pax
  ☑ Vuelo · Nacional · Sky Airline    YMFLHB   44 pax
```

⚠️ **La clave se enseña siempre**, y no es adorno: dos subgrupos pueden tener rótulo idéntico. Los
dos de arriba son «Sky Airline · Nacional» con 44 pax cada uno, y **lo único que los distingue es
el localizador**. Sin él la lista parece el mismo repetido y se marca el que no era.

⚠️ El buscador mira **también la clave**, por lo mismo: buscar «Sky Airline» devuelve los dos y no
sirve para elegir; buscar `YMFLHB` deja uno.

⚠️ Y hace falta porque son **109 subgrupos** en un colegio: desplazarse por todos hasta encontrar
un localizador es exactamente cómo se marca el equivocado.

### 🔥 Los subgrupos NO salen a `pax`

Con el singular, un componente llevaba **su** subgrupo y el filtro exigía pertenecer a él. Con el
plural, un componente acotado a los 7 PNRs del vuelo JA7018 servía **los siete** a cualquiera de
ellos — y `CotizacionFileGrupo` expone `clave`, que **es el localizador**.

Con un apellido, ése es el dato con el que se entra a gestionar la reserva de otro en la web de la
aerolínea. Es exactamente lo que `miIdentidad` se construyó para impedir, entrando por la puerta de
al lado el mismo día.

`$grupos` ya no lleva `pax_cotizacion:read`. El cliente no los necesita: recibe **filtrado** lo que
le toca, y lo suyo se lo cuenta «Lo tuyo».

⚠️ **La lección del cambio de cardinalidad:** al pasar de `?grupo` a `grupos`, el grupo de
serialización se copió tal cual. Y lo que era inocuo en singular —un dato tuyo— dejó de serlo en
plural, sin que el campo «cambiara». **Un `#[Groups]` heredado en un cambio de cardinalidad hay que
volver a justificarlo, no arrastrarlo.**

### 🔥 Previsualizar tenía que elegir entre varias, y nadie había escrito la regla

Sin el filtro `publicado = true` —o sea, previsualizando— una propuesta tiene **varias filas**: la
confirmada, sus históricos y la operativa comparten número a propósito. El `findOneBy` de antes
elegía una **en silencio**, con el orden que quisiera MySQL. Al pasar a DQL, esa elección invisible
se volvió un `NonUniqueResultException` **en producción**.

⚠️ **El fallo no lo introdujo la consulta: lo destapó.** El operador llevaba previsualizando «una
de las tres» sin saber cuál, que es peor que un error — un 500 se arregla, una previsualización que
enseña la fila equivocada se cree.

La regla, ahora escrita:

```
manda la PUBLICADA
si ninguna lo está → la OPERATIVA, que es la fila viva
```

⚠️ Y se resuelve en PHP, no con un `ORDER BY`: `estado ASC` pondría «operativa» primero por
**orden alfabético**, y confiar una regla de negocio a que dos palabras caigan bien es cómo se
rompe al añadir un estado.

### 🔥 Y el plural trajo un N+1 que el singular no tenía

`esParaTodos()` pregunta `isEmpty()` sobre una `ManyToMany` perezosa: **una consulta por
componente**. Medido, 36 componentes → 36 consultas, y la operativa del colegio ronda los 150,
multiplicado por los 133 que abren la app.

Antes no pasaba porque `$grupo` era una `ManyToOne`: un proxy no consulta hasta que se le pide algo,
y `=== null` no se lo pide. `CotizacionFilePublicProvider` los precarga ahora con un `LEFT JOIN`.

### ⚠️ Y `matching()` no es «la versión barata de contar»

`getTotalEnElManifiesto()` usaba `matching()` «para no hidratar». **Falso**: sobre una colección que
no es `EXTRA_LAZY`, Doctrine hace `new ArrayCollection($persister->loadCriteria(...))` y carga todo
igual — y encima no deja la colección inicializada, así que quien la recorra después vuelve a
pagarlo. Medido: 133 fichas hidratadas, mismo coste que `count()`.

El arreglo no estaba en la llamada sino en el **mapeo**: `fetch: 'EXTRA_LAZY'` en `$filepasajeros`,
y entonces `count()` ya es un `SELECT COUNT(*)`. En `getTotalHistoricos()` sí funcionaba porque esa
colección **sí** es extra lazy — de ahí la confusión.

### ⚠️ Basta con pertenecer a UNO

Al filtrar, un componente acotado a siete PNRs lo ve quien esté en **cualquiera** de los siete: la
lista dice «a quiénes aplica», no «a quién hay que pertenecer entero». Exigir todos lo escondería
de todo el mundo.

### ⚠️ Contar es una UNIÓN, no una suma

Dos subgrupos pueden compartir gente —dos habitaciones no, pero dos cortes arbitrarios sí—. Sumar
`totalMiembros` daría de más justo donde el solape importa, y diría «cubre 120 de 100» en vez de
señalarlo. `CotizacionCotservicio::personasCubiertas()` cuenta **pasajeros distintos**.

### ⚠️ `duplicar()` necesita colección PROPIA

`clone` es superficial: sin `new ArrayCollection($this->grupos->toArray())`, la copia y el original
compartirían la misma `PersistentCollection`. Añadirle un subgrupo a la copia se lo añadiría al
original y el `flush` guardaría las dos filas con lo mismo. Sin error: el reparto dejaría de
repartir.

⚠️ Se copian los **miembros**, no se vacía: los subgrupos cuelgan del expediente, así que una
cotización clonada sigue acotando igual. Quien quiere la copia en blanco es el **editor** al partir
un servicio, y eso lo decide él.

### ⚠️ Al front llegan EMBEBIDOS, y hay que normalizarlos

En el contexto del editor `CotizacionFileGrupo` sólo serializa sus timestamps, así que API Platform
lo embebe como `{'@id', createdAt, updatedAt}` — un objeto que no identifica nada salvo por su
`@id`, que es además lo que hay que mandar al escribir. `normalizarGruposDeComponentes()` los deja
como IRIs **al cargar**, para que el selector y la franja trabajen con una sola forma.

### Vacío = todos, y es deliberado

Misma decisión que en skills, guía y conocimiento: **sin acotar en vez de invisible**. Un olvido al
clasificar deja el componente **de más** —alguien lo ve y lo corrige— en vez de escondido, que no
se descubre nunca porque nadie echa de menos lo que no sabía que existía.

Es además lo que abarata el caso normal: partir un vuelo no obliga a etiquetar los otros veinte
componentes.

### El selector «A quién aplica» (F5.3)

Vive en el panel del componente, junto a «Modo comercial» y «Estado». **Sólo aparece si el
expediente tiene subgrupos**: en uno individual, «a quién» no es una pregunta.

⚠️ **«Todo el grupo» va el primero y es `null`.** Es el valor correcto en la enorme mayoría de
componentes, y que sea el que está a mano es lo que evita acotar por accidente.

Los subgrupos se piden aparte —`GET /sales/cotizacion_file_grupos?file=…` con un grupo de
serialización propio, `grupo:option:read`— y **no** vienen con la cotización: `grupos` sólo está en
`file:item:read`, y traerlos dentro arrastraría las 133 pertenencias de cada uno en cada carga del
editor.

### 🔥 La paginación cortaba la lista en silencio

La primera versión ofrecía **30 subgrupos de 109**. La paginación de API Platform son 30 ítems por
defecto, y un desplegable no tiene página 2: los otros 79 sencillamente no existían para quien
elegía. Sin error, sin aviso — una lista que parece completa.

`paginationItemsPerPage: 300`, y **no** «sin paginación»: la consulta se filtra por expediente pero
nada obliga a que se filtre, y desactivarla dejaría un endpoint capaz de volcar los subgrupos de
todos los expedientes de golpe.

⚠️ Se vio leyendo el estado de Pinia en el navegador, no en la respuesta de la API — que devolvía
un `totalItems: 109` perfectamente honesto junto a 30 elementos.

### El código va en la PERTENENCIA, no en el pasajero

`CotizacionPasajeroGrupo::$codigo` — su localizador, su habitación, su asiento. No es de la
persona: es suyo **en ese grupo**. La misma persona lleva un localizador en la reserva de ida y
otro en la de vuelta. Colgarlo del pasajero obligaría a un campo por eje y a elegir cuál gana.

Es también **el dato que obliga a pedir identidad** (§6.j.5): el itinerario es igual para todos,
esto no.

### El filtrado, y la trampa que tiene dentro

`CotizacionCotservicio::getCotcomponentesParaCliente()` sirve sólo lo que toca. El filtro
—`Cotizacion::$filtroSubgrupos`, transitorio— lo pone `CotizacionFilePublicProvider` cuando un
pasajero identificado abre la operativa.

⚠️ **Devuelve una colección NUEVA.** `$cotcomponentes` tiene `orphanRemoval: true`: quitarle
elementos para filtrar marcaría esos componentes para **borrado** en el siguiente flush. El cliente
miraría su itinerario y la cotización perdería la mitad. No daría error: borraría. Hay un test que
sólo vigila eso.

⚠️ **Lista vacía ≠ `null`.** Vacía es «se filtró y no está en ningún subgrupo»: ve lo general.
`null` es «no se preguntó» y sirve todo. Confundirlas enseña el expediente entero.

### 🔥 El relato vive en el SEGMENTO, y eso cambia cómo se filtra

`componerItinerario()` recorre **segmentos** y les cuelga los componentes que apuntan a cada uno.
El texto que lee el cliente es del segmento, no del componente ni del servicio.

Consecuencia que casi se escapa: un segmento cuyos componentes se filtraron **todos** seguía
pintándose. El pasajero leía «Vuelo de Cusco a Lima» con su narrativa completa **para un vuelo en
el que no va**. No es una fuga —el relato es el mismo para todos— pero es **peor que no enseñar
nada**: le dice que hace algo que no hace, y sobre eso planifica.

`getCotsegmentosParaCliente()` los deja fuera. ⚠️ **Salvo el que nunca tuvo componentes**: eso es
legítimo —así se trabaja en el editor mientras se arma— y esconderlo cambiaría lo que ve el cliente
sin que nadie lo haya filtrado. Sólo se cae el que **tenía y perdió**.

### ⚠️ Y por eso partir un vuelo NO es «+ Añadir Extra»

Para que el cliente vea los dos componentes, los dos tienen que **apuntar al mismo segmento** —el
del vuelo— y distinguirse por `grupo`. Así comparten relato, que es lo correcto: la narrativa
«vuelas de Cusco a Lima» es idéntica; lo que cambia es quién va en cuál.

Hoy eso **no se puede hacer desde el editor**:

| | |
|---|---|
| «+ Añadir Extra» | Crea el componente con `cotsegmento: null` — y un componente sin segmento **no aparece nunca** en la vista del cliente, porque el bucle sólo recorre segmentos |
| Asignar el segmento a mano | No existe: no hay selector de segmento en el panel del componente |
| Duplicar un componente | Tampoco existe |

### «Duplicar componente» — hecho el 03/09/2026

Botón de copia en cada componente. La copia conserva el **segmento** —y con él el relato— y guarda
en `duplicadoDe` **el id del componente del que salió**.

| | |
|---|---|
| El `grupo` se **vacía** | La copia nace «para todo el grupo», igual que el original: dos componentes idénticos y visibles. Es incoherente **a la vista** a propósito — obliga a decir a quién aplica cada uno en vez de dejar una copia silenciosa duplicando cantidades |
| Las tarifas se copian **con id nuevo** | Sin eso las dos filas compartirían tarifa y editar el precio de un vuelo cambiaría el del otro |
| Se inserta **justo detrás** del original | Partir un vuelo es una decisión sobre ese vuelo; al final de la lista hay que buscarla |

### ⚠️ Por qué el ID y no un booleano

Empezó siendo `esDuplicado` sí/no. Basta para pintar la marca y desbloquear el borrado, y **se
queda corto en cuanto hay dos copias en el mismo servicio**: dice que las dos son copias, no de
cuál. Con el id, el reparto es un conjunto identificable —el original y los que apuntan a él— y
**eso se puede sumar**:

```
Vuelo LIM–PUJ   nacional        22
Vuelo LIM–PUJ   internacional   18   →  40 de 40 ✓
```

Que las partes sumen 35 en un grupo de 40 es un fallo que **nadie ve** mirando dos tarjetas por
separado. `CotizacionCotservicio::repartos()` lo calcula; con un booleano no había conjunto que
sumar. Cuesta lo mismo —una columna anulable— y dice estrictamente más.

⚠️ **Apunta siempre a la RAÍZ**, nunca a otra copia: duplicar una copia crearía una cadena, y
agrupar exigiría recorrerla. Con la raíz el conjunto es plano pase lo que pase.

⚠️ **Es un id suelto, no una FK.** Mismo criterio que `componenteMaestroId`: una FK obligaría a
decidir qué pasa al borrar el original, y la respuesta correcta —que las copias sobrevivan— es lo
que un `SET NULL` no expresa: borraría la marca y volvería la copia **imborrable**. Un id colgado
significa «era copia de algo que ya no está», que es cierto.

### Las horas reales: `app:cotizacion:horas-desde-vuelos`

```bash
php bin/console app:cotizacion:horas-desde-vuelos 5SRAJV --dry-run
```

Un componente de vuelo nace con la hora de la plantilla —a menudo un `08:00` de relleno, igual en
inicio y fin—. La de verdad está en `CotizacionVuelo`, y **cuál** depende del subgrupo:

```
componente ──grupo──> CotizacionFileGrupo ──vuelos──> CotizacionVuelo (salida, llegada)
```

### 🔥 Elige por FECHA, no por «el único vuelo del grupo»

La primera versión sólo actuaba si el subgrupo tenía **un** vuelo. Medido sobre datos reales, eso
**no pasa nunca**: los 24 subgrupos aéreos de `5SRAJV` tienen 2 vuelos (ida y vuelta) o 4 (con
escala). Un subgrupo es una **reserva** —un localizador— y cubre el viaje entero; el componente es
un **tramo**. La regla habría sido correcta y no se habría disparado jamás.

Lo que distingue el tramo es la **fecha del propio componente**, que ya está puesta aunque la hora
sea de relleno:

```
componente 18-Sep  →  DM6771  18-Sep 03:00 → 09:19    (ida)
componente 22-Sep  →  DM6770  22-Sep 20:22 → 00:30    (vuelta)
```

⚠️ **Varios vuelos el mismo día son una ESCALA, no una ambigüedad**: «Lima → Punta Cana» vía Panamá
son dos filas, y el componente sale con el primero y llega con el último. Tratarlo como ambiguo
dejaría sin hora justo los vuelos largos, que son los que más falta hacen en una orden.

### ⚠️ NO adivina el subgrupo, y eso es el cuello de botella

Si el componente no tiene subgrupo, se salta. Elegir por fecha y ruta acertaría con los servicios
de un solo vuelo y **fallaría justo en los repartidos**, que son los que se están arreglando: el 17
de setiembre hay **tres** vuelos Cusco→Lima —06:50, 07:15 y 20:05—, cada uno con su gente. Un
acierto silencioso en el caso fácil no compensa un error mudo en el difícil.

O sea: primero se reparte (duplicar + «A quién aplica»), y después el comando pone las horas.

### La franja de reparto, encima de los componentes

```
REPARTIDO EN 2 · Vuelo Lima ↔ Cusco
  Todo el grupo                    ×1   todos
  Vuelo · Nacional · Sky Airline   ×1   44 pax
  Cubre 44 de 100 pax · hay una parte «para todos», que no se suma
```

Va **encima** porque es lo que hay que mirar antes de tocar los componentes: dos tarjetas por
separado no dejan ver que entre las dos cubren 44 de 100.

### 🔥 Contra cuánta gente se mide: el MANIFIESTO, no lo cotizado

```
5SRAJV   numPax = 100      lo que se vendió y se cobró
         manifiesto = 133   los que al final se apuntaron
```

`numPax` se queda congelado —es lo que el cliente aprobó— y **la operativa existe justamente para
representar lo que de verdad va a pasar**. Medir la cobertura contra `numPax` **miente al revés**:
con 122 personas repartidas diría «122 de 100 · hay 22 contados dos veces» cuando en realidad
**faltan 11**. Un número que parece una comprobación y apunta al lado contrario es peor que no
tenerlo.

| Estado | Se mide contra |
|---|---|
| **OPERATIVA** | `getTotalEnElManifiesto()` — los que van de verdad |
| Cualquier otro | `numPax` — lo cotizado, que es de lo que se habla |

⚠️ **Se dice en pantalla de dónde sale el número** —«(del manifiesto; se cotizó para 100)»— cuando
los dos no coinciden. Callarlo haría dudar del total.

⚠️ **`matching()` y no `count()`**: `$filepasajeros` no es `EXTRA_LAZY`, así que `count()`
hidrataría las 133 fichas con sus identificaciones y grupos EAGER para devolver un entero. Misma
lección que costó el «Out of sort memory» de `getTotalHistoricos()`.

### ⚠️ DOS números, y el segundo es el que importa

| | |
|---|---|
| `×cantidad` | Lo que se **cobra** |
| `N pax` | A cuánta gente **cubre** — los miembros de su subgrupo |

En un servicio por persona coinciden. **En uno grupal no**: la cantidad vale `1` —se cobra una
vez— y cubre a 44. Con un solo número, un vuelo repartido parecería cubrir «1 + 1» y no habría
forma de ver a quién le falta. El conteo sale de `CotizacionFileGrupo::getTotalMiembros()`, que es
un `COUNT(*)` gracias al `EXTRA_LAZY`.

⚠️ **Una parte «para todos» no se suma**, y se dice aparte. Sumar el total del grupo ahí daría
siempre «de sobra» y taparía justo lo que se busca.

⚠️ **Espejo**: la regla de agrupar vive en `CotizacionCotservicio::repartos()` (PHP) y en
`repartosDelServicio` (`CotizacionEditorView.vue`). Hay que tocar las dos. El front añade los
asignados, que el servidor no calcula porque necesitaría los subgrupos del expediente y el editor
ya los tiene cargados.

⚠️ **La franja está en el panel del SERVICIO y el selector en el del componente**, así que al
asignar no se ve el efecto hasta volver. Se sabe y se acepta: mover la franja al panel del
componente la partiría en trozos que no suman.

### 🔥 Al clonar la cotización hay que REAPUNTARLO

`duplicar()` es un `clone` superficial, así que la copia arrastra el `duplicadoDe` del original: un
id de la cotización **de origen**. Sin remapear, las copias del clon apuntan a componentes de otra
cotización — un vínculo que cruza cotizaciones, **no da ningún error** y agrupa mal en silencio
para siempre.

Se remapea en `CotizacionCotservicio::duplicar()`, en el mismo sitio y por el mismo motivo que
`cotsegmento`, y **en una segunda pasada**: una copia puede venir antes que su original en la
colección —el orden lo decide la base—, así que en una sola pasada el mapa estaría a medias justo
para los casos que importan. Hay un test que sólo vigila eso.

### ⚠️ Es también lo que permite BORRARLO

`isComponenteBloqueado()` bloquea todo componente atado a un segmento, porque lo puso la plantilla
del itinerario y borrarlo rompe el relato. **Una copia no la puso la plantilla: la hizo una
persona, y una persona se equivoca al crearla.** Sin el campo, un duplicado por error se quedaba
para siempre contando pax que no existen.

⚠️ **Y por eso NO se reutiliza `esManual`**, que significa «no viene del catálogo y no va a venir»
y de ahí cuelga que el editor esconda el buscador de insumos. Un duplicado **sí** tiene maestro
—lo copia del original—, así que marcarlo como manual le quitaría el buscador sin motivo. Son dos
hechos distintos sobre el mismo componente.

### La marca «COPIA», y por qué no es decorativa

Los dos componentes **se llaman igual** —son el mismo vuelo repartido—. Sin la marca, ver «Vuelo de
Cusco a Lima» dos veces parece un error de carga, y alguien borra el que no debe: el original, que
es el único que no se puede recuperar sin rehacer la plantilla.

### Quién se queda fuera

`CoberturaDeSubgrupos` avisa de la persona 41 que no está ni en el vuelo nacional ni en el
internacional: abre su viaje y **no ve ningún vuelo**. Sin error y sin fila roja — y no lo reporta,
porque no sabe que le falta algo.

| Regla | |
|---|---|
| `unión(miembros de los subgrupos de un eje) ⊇ pasajeros` | Por **eje**: estar en una habitación no cubre no estar en ningún vuelo |
| Un eje sin ningún subgrupo **no** se avisa | Significa que el viaje no usa ese eje. Avisar llenaría de rojo cualquier expediente, y una lista que casi siempre miente no la lee nadie |
| Un subgrupo **vacío** sí cuenta como eje declarado | Alguien lo creó y no metió a nadie: eso es un descuido |

Sale en el editor, arriba del historial de propuestas y **sin plegar**: es un aviso sobre gente que
no puede reclamarlo.

## 6.j.7 Tres fallos que la revisión encontró y las pruebas no (03/09/2026)

Los tres pasaban PHPStan nivel 7, 550 tests, `schema:validate` y una verificación con datos reales.
Los tres se reprodujeron pidiendo el **endpoint de verdad**, que es lo que ninguna de las anteriores
hacía.

### 🔥 1 · El cliente veía el viaje GRATIS

`getTotalVentaParaCliente()` hacía `$this->origenFinancieroParaCliente()->totalVenta` — leyendo la
**propiedad**, no el getter.

`derivadaDe` llega casi siempre como **proxy sin inicializar**: el provider público resuelve la
lista de propuestas con `getArrayResult()`, así que la confirmada nunca entra en el mapa de
identidad. Los proxies de esta versión de Doctrine interceptan **llamadas a métodos**, no lecturas
de propiedad: desde dentro de la clase, `$padre->totalVenta` devuelve **el valor por defecto sin
cargar nada**.

```
detalle de la operativa   totalVenta '0.00'   ·   clasificacionFinancieraCliente AUSENTE
la portada, por DQL       totalVenta '5922.09'          ← y por eso no cantaba
```

⚠️ **Lo que hizo que la verificación anterior lo diera por bueno:** el sondeo construía el grafo en
memoria, y ahí `derivadaDe` es un objeto de verdad. **La regla: un `?->` sobre una relación que el
provider no cargó explícitamente hay que probarlo con `$em->clear()` delante.**

### 🔥 2 · «Guardar foto» sobre la operativa abría la puerta cerrada

`duplicar()` es un `clone` superficial y arrastraba `publicado`. Guardar un histórico de una
operativa publicada lo dejaba **a él** publicado, el listener despublicaba a la operativa por la
invariante, y el enlace del cliente pasaba a resolver al histórico. Y un histórico **no es
`OPERATIVA`**, así que ni la identificación ni el filtrado por subgrupo se le aplican:

```
sin sesión → HTTP 200 · estado servido: historico · 47 componentes, de todos los subgrupos
```

El fallo de fondo —el histórico heredando `publicado`— era anterior; lo que lo convirtió en fuga
fue poner una puerta que mira el **estado**. `GuardarHistoricoProcessor` fuerza ahora
`publicado = false`: congelar una foto no es publicarla.

### 🔥 4 · `filter()` sobre una colección `EXTRA_LAZY` tumbó el expediente

Al corregir que la operativa contara como «1 histórico», `getTotalHistoricos()` pasó de
`->count()` a `->filter(...)->count()`. Parecía equivalente y no lo es:

```
->count()            EXTRA_LAZY → SELECT COUNT(*), no toca ni una fila
->filter(...)        INICIALIZA la colección entera, con todos sus JSON
```

Resultado en producción: **500 en el detalle de cualquier expediente con históricos**, con
`SQLSTATE[HY001] Out of sort memory: 1038` — exactamente el fallo que ese `EXTRA_LAZY` existe para
evitar, reintroducido por cambiar una línea.

⚠️ **El síntoma engañaba:** los expedientes sin históricos abrían bien y los que sí tenían daban
500. Parecía cosa de un expediente concreto, no del código. Lo cazó la traza, que apuntaba a la
línea exacta.

**La regla:** sobre una colección `EXTRA_LAZY`, `matching()` sí y `filter()` **no**. `matching()`
empuja el criterio a SQL y sigue sin hidratar; `filter()` es PHP y necesita todo cargado. Lo mismo
vale para `map()`, `slice()` con offset y cualquier cosa que recorra.

### 🔥 3 · El freno de intentos era un candado

Se apoyaba en `expiresAfter()` sobre un ítem PSR-6 releído, y **Symfony Cache no restaura la
caducidad al leer**: del segundo intento en adelante el contador se guardaba con el
`defaultLifetime` de `cache.app`, que es 0 — sin caducidad. Un colegio detrás de un NAT que juntara
8 fallos en días distintos quedaba fuera **de por vida**, con un mensaje diciéndole «vuelve a
probar en un rato».

Ahora el límite viaja **dentro del valor**. Lo caza sólo una prueba que deje pasar la ventana; hay
una.

### Y uno más, de vuelta por la puerta de al lado

`reactivar()` no distinguía una fila cancelada por el operador de una cancelada **por el traspaso a
la operativa**. Cancelar la confirmada por error y volver a confirmarla —el caso exacto para el que
existe ese método— dejaba las 47 filas de la confirmada y las 47 de la operativa activas a la vez:
el «pedirle y pagarle dos veces lo mismo al proveedor» que el propio código dice evitar.

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

### El reporte financiero son FICHAS, no tabla (26/08/2026)

El detalle por clase de pasajero era una `<table class="min-w-[560px]">`. En un teléfono había que
**arrastrar de lado** para llegar a la venta por pax — y arrastrar de lado es peor que no enseñar
el dato: no se sabe que está ahí. Además una fila obliga a que todo entre en tres celdas del mismo
ancho, cuando lo que hay es un texto largo (el servicio), unas etiquetas y **dos importes que se
leen en golpes distintos**.

Cada línea es ahora una ficha: el servicio manda arriba, la **venta por pax a la derecha** —que es
la cifra que se busca— y el monto cotizado con sus etiquetas abajo, tras un separador. Una columna
en móvil, dos desde `lg`. Mismo criterio que ya se aplicó a La Biblia al pasar de tabla a rejilla.

### Orden narrativo: por qué el cronológico no sirve (26/08/2026)

Los componentes se sirven por `fechaHoraInicio` (`#[ORM\OrderBy]` en `CotizacionCotservicio`). Como
el **check-in de un hotel es a media tarde**, el alojamiento aterrizaba **en medio del día** —entre
el traslado de la mañana y la excursión— en el editor, en el reporte y en la vista del cliente a la
vez. Nadie cuenta un día así: se llega, se hace lo del día, se come y se duerme.

`ComponenteTipoEnum::ordenNarrativo()` da un rango por tipo (10 llegar/moverse · 20 contacto ·
30 excursiones · 40 tickets · 50 comidas · 60 extras · **90 alojamiento**). Números con hueco para
intercalar sin renumerar. Se expone como `CotizacionCotcomponente::getOrdenNarrativo()`.

⚠️ **La regla vive en PHP, y ése es todo el motivo del getter.** `util/` y `pax/` son dos apps Vue
que **no comparten código**: escribir los números en el front sería escribirlos dos veces, y dos
copias de una regla discrepan el día que alguien toca una. El front sólo ordena por el número, que
no es una regla sino un `sort`. PHPStan valida que el `match` es exhaustivo: si mañana se añade un
tipo, salta.

⚠️ **Ordenar los componentes DENTRO de cada servicio no basta, y fue el primer intento fallido.**
Un día del reporte junta líneas de **varios servicios** —el hotel es un servicio y el traslado
otro—, así que ordenar por dentro no cambia nada al mezclarlos. El rango tiene que viajar **en la
línea** (`LineaDetalleClaseInterna.ordenNarrativo`) y ordenarse **al agrupar por día**.

⚠️ **El `dia`/`orden` de `CotizacionSegmento` NO sirve para esto.** Son relativos a **cada
servicio**: un tour de dos días tiene `dia` 1–2 y `orden` 1–11 *dentro de él*, así que tres
servicios distintos del mismo día natural tienen todos `dia: 1, orden: 1`. Ordenan el relato
**dentro de un servicio**, no entre servicios de una jornada. Verificado en datos reales.

**Las fichas van partidas por JORNADA**, con el día encabezando las suyas y el número de líneas a
la derecha. Cuarenta fichas seguidas no dicen de cuántos días son ni dónde empieza cada uno: hay
que leerlas todas para situarse, y un viaje se piensa por días —«el miércoles cuánto sale»—. El
encabezado es pegajoso dentro del panel: al desplazar con veinte fichas abiertas se pierde de
vista de qué jornada se está leyendo.

⚠️ **La etiqueta se compone con `fmtNaive()`, no con `new Date(ymd).toLocaleDateString()`.** La
fecha viene naive (`2026-09-02`) y construir un `Date` con ella la interpreta en UTC: en Lima
—UTC−5— el día se corría al anterior. Un reporte que dice «1 sep» de lo que pasa el 2 no es un
detalle de formato, es un dato equivocado. Ver `utils/naiveDate.ts`.

⚠️ **Las líneas sin fecha van al FINAL.** Ordenando la clave a secas, la cadena vacía gana a
cualquier fecha y el bloque «Sin fecha» abriría el reporte: lo primero que se vería sería lo que
menos se sabe.

**Cada ficha dice quién lo opera y a quién se le compra.** Es el dato que convierte el reporte en
algo accionable: «el tren lo opera PeruRail pero se lo compramos a Xtreme Tourbulencia».

- **Prestador** (🚚): quien lo opera, de `resolverPrestador()`.
- **Comprador** (🛒, violeta): sólo si el componente **encarga la compra a alguien distinto**.
  Cuando está vacío, la regla de negocio es que se le compra a quien opera, y pintar el mismo
  nombre en dos etiquetas enseña a no leer ninguna. Mismo criterio que `mostrarComprador()` en
  La Biblia.

⚠️ **Los dos son INTERNOS, y el comprador especialmente: a quién le encargaste la compra no es
asunto del cliente.** Por eso van declarados en `LineaDetalleClaseInterna` y no en la base — la
base ES el contrato del cliente, así que lo que se declare allí sale publicado salvo que alguien
se acuerde de quitarlo, que es exactamente cómo el costo de proveedor acabó una vez en el JSON del
huésped. `expurgarParaCliente()` es lista blanca y no los copia; si algún día se convierte en lista
negra, esto se publica solo.

⚠️ **El «Resumen general» sigue siendo tabla, y está bien.** Son tres columnas numéricas cortas
—costo, venta, ganancia— que caben sin scroll. La regla no es «nunca tablas»: es que una tabla que
necesita `min-w` mayor que el teléfono ya no es una tabla, es un cajón con el dato escondido.

### Cómo se lee un histórico en la lista (26/08/2026)

La fila enseña **pax, compra y venta**, no sólo su fecha. Una foto del pasado se consulta para
responder una pregunta concreta —«¿cuánto habíamos cotizado antes?»— y con sólo «V1 · 11 jul»
había que **abrirlas una a una** y comparar de memoria. Con las cifras delante la pregunta se
contesta desde la lista y sólo se abre la que interesa.

**Las mismas tres cifras que la tarjeta de la versión viva y en el mismo orden**: `numPax`,
`totalVenta` y `ganancia`, con `monedaGlobal` en pequeño. Dos cifras que se leen distinto no se
pueden comparar, y comparar es justo para lo que está esa lista. El **idioma** no se repite porque
es de la versión, no de la foto.

**Y cada una lleva su DIFERENCIA con la vigente**, que es lo que convierte la lista en una
respuesta: sin ella hay que restar de cabeza tres pares de números por cada foto, que es
exactamente el trabajo que la fila viene a quitar.

| Regla | Por qué |
|---|---|
| Signo = **histórico − vigente** | Se lee «esta foto tenía tanto de más o de menos que lo que está en vigor» |
| Verde arriba, rojo abajo | Es una **dirección, no un juicio**: que una foto vendiera menos no está mal, es que se subió el precio después |
| Diferencia cero → **no se pinta** | Un «+0.00» en cada columna enseña a no mirar la columna. Sólo se enseña lo que cambió |
| Por debajo de **medio céntimo** → no se pinta | Eso es redondeo, no un cambio |
| Pax sin decimales; importes con dos | «+1 pax» y «+110.64» |
| Falta el dato en alguno de los dos → no se pinta | Inventar un cero sería decir «no cambió» sin saberlo |

El menos es **U+2212** y no un guion: a 9 px un `-` se confunde con un separador.
`diferenciaConVigente()` y `formatoDiferencia()` en `FileDetalle.vue`, junto a `historicosDe()`.

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
y los tipos ya usados no se vuelven a ofrecer —la unicidad es de base, y repetir sólo consigue que
las dos filas se fundan en una después de escribir los dos números—. Avisa en ámbar cuando alguna
fila va sin fecha.

⚠️ Al guardar **se manda la colección entera y sin identidad**. Casar por IRI exigiría que la
identificación fuese un `ApiResource` propio, y no lo es: sólo existe colgando de su pasajero. Es
el servidor el que casa cada entrada con la fila que ya existía —ver abajo—.

### El guardado NO puede reemplazar la colección a ciegas (24/08/2026)

Durante unas horas sí lo hizo, y **cada guardado de un pasajero con documentos daba 500**, aunque
sólo se viniera a cambiar el teléfono. Se descubrió por casualidad, mirando `error.log`.

El mecanismo, que no se ve leyendo el código:

1. El deserializador de API Platform recibe la lista sin ids, así que **saca de la colección las
   filas viejas y mete objetos nuevos** equivalentes.
2. `orphanRemoval` programa el borrado de las viejas y `cascade: persist` la inserción de las
   nuevas.
3. ⚠️ **Doctrine ejecuta los INSERT antes que los DELETE en el mismo flush.** La fila nueva del DNI
   entra mientras la vieja sigue viva, las dos con el mismo `(pasajero, tipo)`, y MySQL corta con
   un 1062.

Y sale como **500, no como 422**: una violación de índice único no pasa por el validador.

Lo arregla `CotizacionFilepasajeroProcessor`, que se interpone antes del processor de Doctrine y
casa cada entrada con la foto que la `PersistentCollection` guardó al cargarse —por `tipo` en las
identificaciones, por `grupo` en las pertenencias, que tienen el mismo defecto y el mismo índice—.
Los datos se copian sobre la fila **original** y el objeto nuevo se descarta: lo que sale es un
UPDATE, y de paso la fila conserva su `createdAt`.

Es el mismo criterio que ya usaba `PadronImportador`, que reutiliza con `identificacionDe($tipo)`.
Por eso reimportar el padrón corregido nunca duplicó nada y la ficha sí: eran dos caminos con dos
reglas, y sólo uno estaba escrito.

La mitad que los tests unitarios no pueden cubrir —el orden de los INSERT y los DELETE dentro de
un mismo flush— se verifica con datos reales en `tools/pruebas/probar-identificaciones-1062.php`, que corre
el mismo guardado con el arreglo y sin él: sin él reproduce el 1062 exacto de producción; con él,
las filas conservan su id y su `createdAt`.

⚠️ **El índice único se queda donde está.** Es lo que garantiza que reimportar el padrón no
duplique; quitarlo habría tapado el síntoma y devuelto el problema que lo motivó.

⚠️ Y el `PUT` de `CotizacionFilepasajero` lleva `standard_put => false` **por esto mismo**: con el
PUT estándar de API Platform 4, el processor del vendor copia las propiedades por reflexión sobre
la entidad cargada —colecciones incluidas—, la `PersistentCollection` entera se cambia por otra y
se vuelve al 1062 por una puerta donde ya no hay foto contra la que comparar. Nadie usa ese PUT,
pero armado era una trampa esperando.

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

#### Los vuelos se miran en un sitio y se cargaban en otro (04/09/2026)

El panel «Vuelos» enseña los 16 tramos con sus PNRs y **no tenía ni una acción**: la carga vivía
dentro de «Cargar datos», tres paneles más abajo y plegado. Quien miraba la lista concluía que era
de sólo lectura — y tenía razón desde lo que veía.

Ahora el mismo botón está también ahí («Cargar o corregir vuelos»), abriendo el mismo modal, con
una línea que dice de dónde salen: **se pegan en JSON** y el `PNR` es lo que ata cada vuelo con su
subgrupo y con la gente que va en él. No hay edición fila a fila a propósito: se vuelve a pegar el
PNR corregido, que es como llega la corrección de la aerolínea.

- **Sección «Subgrupos»** en `FileDetalle`, antes del manifiesto: se definen primero y se asignan
  después. Alta con eje + clave + nombre opcional; al borrar avisa a cuántos pasajeros se quita y
  aclara que **ellos no se borran**.
- **En la ficha del pasajero**, píldoras por eje: se marcan varias a la vez y la corona marca de
  cuál es jefe.
- ⚠️ **Los «no participa» salen de todos los conteos**, salvo que se marque la casilla —que lleva
  al lado cuántos son, porque una casilla sin número no dice de qué habla—. No se borran: se
  apuntaron y luego se cayeron, y la lista tiene que seguir contando lo que pasó. Pero
  **conservan su grupo y sus reservas aéreas**, así que sumaban donde no debían: con sólo dos
  personas caídas, en Punta Cana 2026 hay **cinco conteos mintiendo** —`JA2CWN` decía 25 y vuelan
  24, `PV7PFM` 10 por 9, `BBBBB` 44 por 43, `X9SYVZ` 9 por 8, y el grupo 6, 12 por 11—.
  Por eso el «N pax» de cada subgrupo se calcula en el front y **no** se toma de `totalMiembros`,
  que es del servidor y los incluye. El aviso al borrar un subgrupo sí usa `totalMiembros`: ahí la
  pregunta es a cuánta gente se le quita la pertenencia, y ésos son todos.
- **El GRUPO va arriba, junto al rol, y en sólido.** Es la unidad con la que se opera todos los
  días —«que suba el grupo 5 al bus»— y estaba sólo dentro de la ficha, a dos toques. La
  habitación va al lado pero apagada: se consulta al llegar al hotel, no cada mañana.
  ⚠️ El grupo se rotula con su `nombre` («Grupo 5») y la habitación con su `clave` («HA13»): el
  nombre de una habitación es «DOBLE», que no identifica a ninguna.
- **El manifiesto se ordena por JERARQUÍA**: supervisor → coordinador → participante →
  acompañante → invitado → no participa, y alfabético dentro de cada rango. Quien abre la lista
  busca primero a quien manda, porque es con quien se habla.

  ⚠️ **Qué pasa si esos roles no existen**, que es la pregunta buena y está probada:
  quien no tiene rol cae a `?? 90` —al final, en bloque y ordenado alfabéticamente entre los
  suyos—, y la fila de facetas **no se pinta** si sólo hay una clase (`> 1`). Un expediente
  normal de dos personas sin rol ni subgrupos se ve exactamente como antes: sin fila de rol, sin
  aerolíneas, sin subgrupos, sin desplegable. Un rol desconocido —una migración a medias— también
  cae al final en vez de romper el `sort`. Y la clave interna `sin_rol` se rotula «Sin rol»: no
  se enseña cruda.
- **Filtro de documentos**: `Vencido`, `Vence < 1 año` y `Sin comprobar`, con su conteo. Los tres
  son estados distintos y ninguno se lee como los otros — «sin comprobar» **no** es «casi
  vigente», es que no sabemos, y es justo lo que hay que mirar antes de un viaje.
- **La barra de filtros se pliega**, pero el **buscador y el contador se quedan siempre fuera**:
  son lo que se usa cada vez, y cinco filas de píldoras empujaban la lista fuera de la pantalla en
  un móvil.
- **Los subgrupos de la ficha del pasajero van plegados**, enseñando sólo los que ya tiene.
  Desplegados son 108 píldoras entre el teléfono y el botón de guardar: quien abre a corregir un
  apellido tenía que recorrerlas todas para llegar abajo.
- **Exportar sólo lo filtrado.** El botón sale al lado del contador y únicamente con filtros
  puestos —sin ellos ya está «Descargar con lo cargado», y dos botones que hacen lo mismo obligan
  a pensar cuál es cuál—.

  ⚠️ Los ids van por **POST** aunque no escriba nada: son UUID de 36 caracteres, y 131 personas
  son 4 700 caracteres de URL —por encima de lo que aguantan varios proxys, y lo que se corta ahí
  no da error, da una exportación a la que le faltan filas—.

  ⚠️ Se manda **la lista de personas, no los filtros**. Repetir los filtros en el servidor serían
  dos implementaciones de la misma pregunta, y la que se quedara corta lo haría en silencio.

  ⚠️ Y filtra **filas, nunca columnas**: los ejes y servicios salen del expediente entero. Una
  exportación de «los de Copa» que perdiera la columna `#Reserva aérea nacional` volvería a
  subirse sin ella, y el importador leería que a esa gente le quitaron el vuelo nacional. Sobrar
  una columna es inofensivo; faltar, no.
- **La tarjeta entera abre la ficha en LECTURA; la plumita entra a editar.** En un móvil el
  blanco es la mitad de la tarjeta y apuntar a un icono de 28 px con el pulgar es la parte
  incómoda. Los dos botones llevan `@click.stop`, o abrirían además la tarjeta.
- **El itinerario sólo en la ficha abierta.** En la lista basta la aerolínea y el localizador —es
  para reconocer—; abierta una persona lo que se consulta es a qué hora sale, y ahí se pinta el
  `detalle` del grupo con `formatoAHtml()`.
- **La cara de lectura no repite.** Grupo y habitación arriba, vuelos en su bloque, y el bloque de
  abajo sólo los **servicios** («Lleva»). Antes salían los tres tipos juntos y la misma
  información aparecía tres veces.
- **El ROL va primero en la ficha, y con color.** Antes la píldora decía «Adulto PR» —que es la
  tarifa de PeruRail, adulto o niño para el tren— y **no decía si era coordinador, supervisor o
  participante**, que es lo que se busca al mirar la lista. En 131 fichas, encontrar a los 9
  coordinadores leyendo texto es imposible; con color se ven de un vistazo. Las píldoras del
  filtro llevan además un punto del color aunque estén apagadas: es lo que enseña a reconocerlo
  antes de haberlo usado nunca.

  ⚠️ Las clases van **escritas enteras** en `PASAJERO_TIPO_CONFIG.clase`, no compuestas con el
  `color`. Tailwind lee los ficheros como texto: `bg-${'{'}color{'}'}-100` no existe para él, y esa
  píldora saldría **con color en desarrollo y sin color en producción** — la peor combinación,
  porque se ve bien mientras lo haces y se rompe al desplegar. Se comprueba buscando la clase en el
  CSS compilado (`public/app_util/assets/main-*.css`).
- **Filtros por FACETAS: Y entre ejes, O dentro del mismo eje.** Acumular todo con Y era un fallo
  —elegir dos habitaciones daba **cero**, porque nadie está en dos a la vez—. La pregunta real es
  «los del grupo 5 que estén en HA01 **o** HA02». Sobre Punta Cana: `HA01 + HA02` → 4 personas,
  no 0.
- **Rol y aerolínea son facetas propias**, con sus píldoras y su conteo. La aerolínea sale del
  `nombre` de los grupos aéreos, no de una lista nuestra: ocho localizadores distintos son la
  misma Arajet, y lo que se quiere es «los de Arajet», no ocho códigos uno a uno. Cada tramo es
  su propia faceta, así que «JetSMART nacional **y** Copa internacional» es una pregunta que se
  puede hacer —6 personas—.
- **El vuelo se ve en la ficha de cada persona** (tramo · aerolínea · localizador). Era el dato
  que había que ir a buscar abriendo a cada uno, y es justo el que se mira para armar el
  aeropuerto.
- **La ficha del pasajero se recorre sin cerrarla**: flechas ‹ › en la cabecera, «N de M», y un
  «Guardar y siguiente». ⚠️ Navega sobre **los filtrados**, no sobre todos — es lo que la hace
  útil: filtras «Copa internacional» y repasas a esos 18 seguidos en vez de abrir y cerrar 18
  veces buscándolos. Si al guardar alguien deja de cumplir el filtro, sale del recorrido: ya no es
  uno de los que estabas repasando.
- **Cada subgrupo se puede CORREGIR**, no sólo crear y borrar. El lápiz va siempre visible en la
  píldora y abre un **modal** con eje, tramo, clave, nombre y detalle.

  ⚠️ En modal y no en línea: la sección lista hasta 66 habitaciones, así que un formulario al
  principio queda a media pantalla de la píldora que se tocó — se corrige a ciegas o hay que subir
  a buscarlo. Se engancha a `useCapasEnHistorial` como los demás, así que «atrás» lo cierra.

  ⚠️ Sin esto, una errata obligaba a **borrar**: un vuelo de Arajet cargado bajo «Vuelo Nacional»
  —pasó de verdad— sólo se arreglaba eliminando el grupo, y eso se lleva por CASCADE las
  pertenencias de todos los que iban dentro. El `Patch` de la API ya existía; lo que faltaba era
  la pantalla.

  ⚠️ **Cambiar la clave RENOMBRA, no duplica**: las pertenencias apuntan al `id`, así que la gente
  se queda dentro. Pero el padrón casa **por clave**, de modo que un .xlsx con la clave vieja
  crearía un grupo nuevo al reimportarlo. El formulario lo avisa: hay que corregir también la hoja.
- ⚠️ **La papelera de cada subgrupo va detrás de un interruptor** («Borrar subgrupos»). Con 66
  habitaciones había 66 botones de borrar al alcance del pulgar, en la misma píldora que se toca
  para leer el conteo; un roce se llevaba un subgrupo con sus pertenencias. Y las listas de más de
  12 se pliegan, igual que en la ficha.
- El desplegable de filtro es **`SearchableSelect`**, no un `<select>`: 108 subgrupos en el móvil
  son una pared de 108 filas. La segunda línea lleva el eje y cuántos van, y la búsqueda mira las
  dos, así que teclear «habitación» acota de golpe.
- ⚠️ **Los ejes de más de 12 píldoras se pliegan** (`TOPE_PILDORAS` en `FileDetalle.vue`). Plegado
  enseña **sólo aquellas a las que pertenece**; abierto, todas con un filtro de texto. El hotel
  numera 66 habitaciones y las aerolíneas dan una veintena de localizadores: desplegados dejaban
  el eje «Grupo» —nueve píldoras, y el que más se usa— a tres pantallas de scroll, y la ficha
  inservible justo en el expediente grande.
- La píldora enseña `clave` **y `nombre`** en gris: el nombre es lo que dice QUÉ es ese código
  —la aerolínea y sus vuelos, «DOBLE» en una habitación—. Sin él, elegir entre veinte
  localizadores es adivinar.
- `pertenencias` va **`EAGER`** por lo mismo que las identificaciones (§6.l): con `LAZY` son 140
  consultas que con dos pasajeros no se ven.

### ⚠️ El grupo NO devuelve sus miembros, y no es un olvido

`CotizacionFileGrupo::$miembros` **no se serializa**. Si lo hiciera, el grafo se cierra sobre sí
mismo:

```
pasajero ──► pertenencias ──► CotizacionPasajeroGrupo ──► grupo
                    ▲                                       │
                    └──────────── miembros ◄────────────────┘
```

…y el serializador aborta el `GET` entero con *«A circular reference has been detected when
serializing the object of class CotizacionPasajeroGrupo (configured limit: 1)»*. Un 500, no un
campo de menos.

⚠️ **Lo que lo hace caro es cuándo aparece.** Con los grupos vacíos la colección no tiene por
dónde volver, así que el ciclo no existe: pasa las pruebas, pasa el despliegue, y revienta **el
día que alguien carga un padrón de verdad**. Ocurrió tal cual — el expediente abría sin problema
hasta que entraron los 133 de Punta Cana con sus 106 grupos y 1620 pertenencias.

La regla general, que vale para cualquier `OneToMany` nuevo: **de una relación bidireccional sólo
un lado puede ir en el grupo de lectura.** Aquí el lado bueno es el del pasajero, porque la
pantalla pinta el manifiesto persona a persona.

Lo que la tarjeta del grupo necesita es **cuántos son**, y eso lo da `getTotalMiembros()` con
`fetch: 'EXTRA_LAZY'`: un `SELECT COUNT(*)` en vez de hidratar 1620 filas para contarlas. Medido
sobre el expediente real, los 106 grupos salen en **15 KB y 0,04 s**.

⚠️ **Y la regla no se cumple sola: hay que declarar los grupos también al ESCRIBIR.** Sacar
`miembros` del grupo de lectura no basta si la operación no filtra por grupos, porque entonces se
serializa **todo** y el ciclo vuelve. Es lo que pasaba con `POST`/`PUT`/`PATCH` de
`CotizacionFilepasajero` y `CotizacionFileGrupo`: sólo declaraban `denormalizationContext`, así
que la **respuesta** —que devuelve el recurso guardado— salía sin filtrar y daba 500. Se vio el
24/08/2026 al asignarle un vuelo a un pasajero: el guardado se hacía y el cliente recibía un 500,
que es la peor forma de fallar —parece que no se guardó, y sí se guardó—.

Hoy las cinco operaciones de escritura llevan `normalizationContext` con `file:item:read`. Lo
comprueba `tools/pruebas/probar-circular-pasajero.php`, que arma el grafo con el círculo cerrado y serializa
cada operación con el contexto que declara su propio `#[ApiResource]`.

⚠️ **Y por eso `CotizacionFilepasajero::$file` es sólo de escritura.** Filtrar por grupos cortó el
ciclo pero destapó lo caro: con `file` dentro del grupo de lectura, guardar UN pasajero devolvía el
expediente entero colgando de él —**1,13 MB por guardado**, medidos en producción— y el front tira
ese cuerpo a la basura, porque recarga el expediente aparte. Devolver el expediente no le sirve a
nadie: quien lee un pasajero ya sabe de dónde lo sacó. La misma respuesta pesa ahora unos pocos KB.

### Los padrones vienen GRITADOS, y el importador los baja (24/08/2026)

Se teclean con el bloqueo de mayúsculas puesto —«VALDIVIA BERRIOS»— y de ahí salían tal cual a la
ficha del huésped y a los mensajes. El importador pasa `Nombres` y `Apellidos` por
`App\Service\Nombre\NombreSanitizer`, **el mismo que ya normalizaba lo que llega de Beds24**: una
regla, un sitio.

Sólo toca lo que está **claramente gritado** —una sola minúscula y lo deja intacto—, así que
«de la Cruz», «McDonald» u «O'Brien», que alguien escribió así a propósito, se quedan. Y respeta
las partículas: `LADRON DE GUEVARA` → `Ladron de Guevara`, pero `DE WILSON` abriendo el apellido
→ `De Wilson`.

⚠️ **No restituye tildes**: `JOSE` sale `Jose`. La tilde no está en el dato y aquí no se adivina.

⚠️ Y no arregla el **corte** del nombre, que es otra cosa: partir «Nombres y Apellidos» por las dos
últimas palabras falla con los apellidos compuestos —`ACOSTA PINTO DE WILSON` se parte por
`DE WILSON`—. Se avisa al importar; se corrige poniendo nombre y apellido en columnas propias.

### La hoja «Grupos»: qué es cada código (24/08/2026)

Un localizador no dice nada. `IFBI5Q` es ARAJET y `Y9KZ7J` es JetSmart, y elegir entre veinte a
ojo es adivinar. Lo mismo con `HA50`, que es DOBLE.

Ese dato ya cabía: `CotizacionFileGrupo::$nombre` estaba ahí, vacío. Lo que faltaba era **por
dónde entra**, y la respuesta NO es una columna en la hoja de pasajeros:

> El nombre pertenece al GRUPO, no a la persona. En la fila del pasajero habría que repetirlo 133
> veces, y el importador lo escribiría 133 veces sobre el mismo grupo: **gana la última fila del
> bucle**. Una errata en la fila 90 renombraría la reserva entera sin un solo aviso.

Por eso va en **hoja aparte**, con la identidad `(eje, clave)` — el mismo `UNIQUE (file, tipo,
clave)` de la tabla — y se lee **antes** del bucle, para que el grupo nazca ya con su nombre:

```
Eje                            Clave    Nombre     Detalle
#Reserva aérea nacional        Y9KZ7J   JetSmart   * **Ida** JA7854 · LIM 03:00 → CUZ 04:25
                                                   * **Retorno** JA3888 · CUZ 20:22 → LIM 21:45
#Reserva aérea nacional        XSRD4    SKY
#Reserva aérea internacional   BONT3N   ARAJET     * **Ida** DM6771 · LIM 03:00 → PUJ 09:19
#Habitación                    HA50     DOBLE
```

El mismo eje repite fila por cada código: son grupos distintos. Es **opcional** —sin ella los
grupos entran sin rótulo— y en un grupo que ya existía **refresca** lo que la hoja traiga.

⚠️ **Sólo lo que la hoja TRAE.** Una celda vacía es «no lo digo», no «bórralo»: si borrara, subir
una hoja con la columna «Detalle» en blanco vaciaría los itinerarios de los 106 grupos sin que
nadie lo hubiera pedido.

**La clave admite el rótulo pegado.** «Y9KZ7J Jetsmart» en una sola celda se parte por el primer
espacio, y **sólo si la columna «Nombre» no dice nada**: con las dos puestas manda la explícita. Se
acepta porque es como se teclea de un tirón, y separar por el primer espacio acierta con lo que hay
—un localizador son seis caracteres sin espacios, un número de habitación tampoco los lleva—.

⚠️ Lo que **no** cambia es la identidad: la clave del grupo sigue siendo `Y9KZ7J` a secas, porque
es lo que casa con la columna de las 133 filas de la hoja de pasajeros. Si la identidad fuera
«Y9KZ7J Jetsmart» habría que escribir eso mismo en cada fila, que es justo lo que esta hoja existe
para evitar.

⚠️ **Y si el rótulo se cuela en la fila del PASAJERO** —«IFBI5Q ARAJET» donde va sólo el
código— se usa el grupo del código. Sin eso se creaba una reserva aparte de «IFBI5Q»: dos donde
hay una, y ninguna de las dos completa. La prueba de que es el mismo es que **la hoja «Grupos»
declara ese código**, no que el grupo exista ya: los grupos se crean sobre la marcha, así que
mirar los existentes dependía del orden de las filas —si la fila con el rótulo pegado venía
primero, ganaba ella—. Se avisa siempre: el archivo hay que arreglarlo.

⚠️ **La hoja «Grupos» se busca la ÚLTIMA al localizar a los pasajeros.** Su cabecera `Nombre` es
alias de `Nombres` (`PadronFormato::ALIAS`), así que califica como hoja de personas. Con la
pestaña arrastrada delante, `buscarHojaYCabecera()` la elegía a ella: entraban **pasajeros
llamados «JetSMART» y «Sky Airline»**, y encima los rótulos se perdían —`leerHojaDeGrupos()` se
salta la hoja de la que salieron las personas—. Todo en silencio. Se deja como último recurso en
vez de excluirla, por si alguien llama «Grupos» a su hoja de gente.

⚠️ **Cabecera repetida: gana la PRIMERA.** `array_flip` se quedaba con la última, y copiar la hoja
sin borrar la columna vieja hacía leer la de la derecha —vacía—: los detalles entraban en blanco
sin un solo aviso. En la hoja de PASAJEROS sí se repiten a propósito, que ahí dos ejes iguales son
dos columnas.

### Dos campos y no uno: `nombre` corto, `detalle` largo

Son **dos lecturas distintas**, y por eso no se concatenan:

| | Para qué | Dónde se pinta |
|---|---|---|
| `nombre` | **ELEGIR** entre veinte localizadores de un vistazo — «ARAJET», «DOBLE» | dentro de la píldora, junto a la clave |
| `detalle` | **COMPROBAR** un horario cuando ya sabes cuál miras | debajo, y sólo de los grupos a los que pertenece |

Metidos en el mismo campo, el corto deja de servir para lo primero: la píldora se convierte en un
párrafo y la lista de 66 habitaciones es ilegible. Por eso la píldora lleva **aerolínea y código y
nada más**, ni siquiera en el `title`.

`detalle` es `TEXT` y se escribe en el **canónico de la casa** —el mismo del chat, que normaliza el
Markdown que la gente teclea: `**x**` → negrita, `* ` → viñeta—. Se pinta con `formatoAHtml()`
(`util/src/utils/formatoDeTexto.ts`), que **escapa el HTML antes** de aplicar marcas, así que el
`v-html` es seguro. No hace falta ninguna librería de Markdown.

El libro queda **Pasajeros · Grupos · Instrucciones · Tablas**.

### ⚠️ El TRAMO es una etiqueta, no un tipo (24/08/2026)

`RESERVA_AEREA` estuvo partido en `_NACIONAL` e `_INTERNACIONAL`. **Era un error de modelado
mío**: no son dos tipos, son el mismo tipo con una etiqueta. Un multitramo Lima→Cusco→Puno→Lima
habría pedido un `case` por vuelo — **un despliegue para apuntar un billete**.

Ahora hay **un solo eje de vuelo** y el tramo vive en `CotizacionFileGrupo::$subeje`, texto libre:

```
#Vuelo                 → reserva_aerea, sin tramo      (un viaje con un solo vuelo)
#Vuelo Nacional        → reserva_aerea, «Nacional»
#Vuelo Cusco-Puno      → reserva_aerea, «Cusco-Puno»   ← sin tocar código
#Reserva aérea Ida     → reserva_aerea, «Ida»          (alias, para hojas viejas)
#Habitación doble      → null: se avisa y se ignora    ← sólo el vuelo admite etiqueta
```

⚠️ **Sólo los ejes con `admiteSubeje()` se parten.** Sin eso, `#Habitacion doble` entraría como
eje habitación con tramo «doble» en vez de denunciarse — y el tipo de habitación tiene su sitio,
que es la columna «Nombre» de la hoja «Grupos».

#### 🔥 `grupos` volvía incrustado y el editor no podía guardar (04/09/2026)

**500 en producción al guardar**, con este cuerpo:

```
Nested documents for attribute "grupos" are not allowed. Use IRIs instead.
```

`CotizacionCotcomponente::$grupos` está en `cotizacion:read`, y el contexto de lectura del editor
es `['cotizacion:read', 'timestamp:read']`. `CotizacionFileGrupo` usa `TimestampTrait`, cuyos
`createdAt`/`updatedAt` llevan **`timestamp:read`** — y basta con que **una** propiedad del destino
caiga en un grupo activo para que API Platform **incruste el objeto** en vez de emitir el IRI. El
editor devolvía lo que había recibido y la escritura lo rechazaba.

Se arregla con `#[ApiProperty(readableLink: false)]`, que fuerza el enlace pase lo que pase. Es el
mismo truco que ya usaban `TravelComponenteItem` y `TravelItinerario`.

⚠️ **El grupo que rompe la forma no se menciona en la línea que falla.** `timestamp:read` se
declara en el recurso y viaja a **todo** lo que cuelgue de él. Leyendo `#[Groups(['cotizacion:read',
'cotizacion:write'])]` no hay forma de saber que esa relación va a llegar incrustada: hay que
serializar y mirar.

⚠️ **Y esperó a que la funcionalidad se usara.** Mientras ningún componente tuvo subgrupos, la
lista iba vacía y no había nada que incrustar: el expediente guardaba sin problema. El fallo
apareció con el **primer** componente acotado, semanas después de escribir la relación.

⚠️ **El tipo del front también mentía.** `api.d.ts` declaraba
`grupos?: CotizacionFileGrupo[]` mientras todo el código de `util` la trataba como `string[]` —con
un cast— y funcionaba, porque el editor sólo escribe IRIs. Al regenerar, el tipo pasó a `string[]`:
el esquema y el código dicen por fin lo mismo. **Un cast que tapa un tipo equivocado tapa también
el día que el dato cambia de forma.**

**La regla:** cualquier relación servida en `cotizacion:read` cuyo destino sea otro `ApiResource`
—y casi todos llevan marcas de tiempo— necesita `readableLink: false` salvo que se quiera de
verdad incrustada. Para comprobarlo, serializar y buscar `@type` ajenos al árbol:

```php
// (la sonda `probar-iris.php` que recorría el JSON denunciando lo que no es del árbol
//  editable se perdió: no está en `tools/` ni en `var/obsoletos/`. Si hace falta, se rehace)
$delArbol = ['Cotizacion', 'CotizacionCotservicio', 'CotizacionCotcomponente',
             'CotizacionSegmento', 'CotizacionCottarifa'];
```

#### El sufijo, a mano vale para CUALQUIER eje (04/09/2026)

La frase de arriba —«sólo el vuelo admite etiqueta»— sigue siendo cierta **para el .xlsx**, y sólo
para él. En el formulario manual de `FileDetalle` el campo estaba escondido tras
`tipo === 'reserva_aerea'` y no debía estarlo: en un viaje con hotel en Lima, en Cusco y en Punta
Cana, la `HA13` del Sonesta y la `HA13` del Terra son dos habitaciones distintas y chocaban contra
`uniq_file_grupo_tipo_clave`. Lo mismo con los servicios, que en ese expediente ya son diez.

**La columna siempre fue genérica** y ya entraba en la clave única, así que no hubo nada que
cambiar en PHP: sólo dejar de esconder el campo.

⚠️ **Y `GrupoTipoEnum::admiteSubeje()` NO se tocó**, a propósito. Gobierna el parseo de la
cabecera del padrón, donde eje y sufijo viajan en **una sola cadena** (`#Vuelo Nacional`):
abrirlo allí haría que `#Habitacion doble` entrara como tramo «doble» en vez de denunciarse. En el
formulario son **dos campos separados** y no hay nada que adivinar — la ambigüedad sólo existe al
partir un texto.

⚠️ **El rótulo del campo cambia con el eje**, y no es cosmético: de un vuelo se dice el «Tramo»,
de una habitación el «Hotel», de un grupo o un servicio el «Matiz». Con una sola palabra para los
cuatro, el campo parecía pedir otra cosa y se dejaba vacío.

Los **ejemplos también son por eje** (`EJEMPLOS_EJE` en `FileDetalle.vue`). El del detalle enseñaba
un itinerario de vuelo aunque estuvieras creando una habitación, y el de la clave amontonaba los
cuatro ejes en una fila —`B · 5 · HA13 · JA2CWN`— para no tener que elegir. Un ejemplo que no
corresponde a lo que estás haciendo es peor que no ponerlo: da instrucciones para otra tarea.

⚠️ **`subeje` es NOT NULL con cadena vacía, y eso NO es cosmético.** En InnoDB un índice único
admite **cuantos `NULL` quiera**: con la columna nullable, meterla en `uniq_file_grupo_tipo_clave`
dejó fuera de la unicidad a todo grupo sin tramo —habitaciones, grupos, servicios: casi todo lo
que existe—, que es justo la protección que había ANTES de partir la columna. Un doble POST
creaba dos «HA13» en silencio. Lo cazó una revisión; no se habría visto de otra forma.

⚠️ **La unicidad incluye el tramo**: `(file, tipo, subeje, clave)`. `#Vuelo Ida` y `#Vuelo Retorno`
con el mismo localizador —las aerolíneas reutilizan códigos— son dos grupos distintos, y sin la
columna en la clave el segundo se fundía con el primero en silencio.

⚠️ **El tramo se compara SIN mayúsculas, como lo compara MySQL.** La colación es
`utf8mb4_unicode_ci`, así que para el índice «Nacional» y «nacional» son el mismo. Comparándolo
con `===` en PHP se intentaba crear el segundo y saltaba una violación de unicidad al hacer
flush: un error feo por una diferencia que a nadie le importa.

La migración `Version20260824060000` convierte los datos ya cargados, así que **no hay que volver
a subir ningún padrón**.

### ⚠️ Dos columnas con la misma cabecera perdían el tramo

Había dos `#Reserva aérea`, la nacional y la internacional, y **cuál era cuál dependía de la
posición de la columna**. El importador mapea *cabecera → eje*, así que las dos caían en
`reserva_aerea` y el tramo desaparecía sin error: nada en el archivo lo decía.

Se arregló con dos ejes propios —`RESERVA_AEREA_NACIONAL`, `RESERVA_AEREA_INTERNACIONAL`— y así
la cabecera se describe a sí misma. **Los tres conviven a propósito**: una pareja que vuela a
Cusco tiene UNA reserva y no hay tramo del que hablar; obligarla a elegir «nacional» sería
inventarle una distinción.

⚠️ `cotizacion_file_grupo.tipo` tuvo que pasar de `VARCHAR(20)` a `VARCHAR(40)`:
`reserva_aerea_internacional` son 27 caracteres, y MySQL fuera de modo estricto **trunca en vez de
fallar** — el error habría salido al LEER, no al escribir.

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

### Idempotencia: tres peldaños, de exacto a adivinanza

```
1. Id            exacto     — sólo lo trae la descarga «con lo cargado»
2. documento     bueno      — pero corregir un pasaporte duplicaría a esa persona
3. nombre        el peor    — existe para que quien no tiene documento no se duplique
```

⚠️ **La columna `Id` es lo que convierte la idempotencia en exacta**, y por eso la exportación
existe: probado cambiándole a la misma fila el nombre, el apellido, el DNI **y** el pasaporte a la
vez → **0 creados, 133 actualizados**. Volvió a su persona igual.

La plantilla **en blanco no la trae**: una columna vacía llamada «Id» sólo invita a rellenarla con
cualquier cosa.

Dos comprobaciones antes de tocar nada, y las dos abortan:

- **`Id` repetido** — llega copiando una fila para crear a alguien parecido. Las dos escribirían
  sobre la misma persona y una desaparecería. El mensaje dice qué hacer: borra el Id de la copia.
- **`Id` de otro expediente** — llega copiando filas entre dos hojas exportadas. Tratarlo como «no
  lo encuentro» crearía un duplicado del que nadie sospecharía.

### La descarga «con lo cargado»

`GET /cotizacion/user/padron/exportar/{id}`. La misma hoja, ya rellena. Es la forma cómoda de
completar un padrón a medias: se baja lo que hay, se rellenan los huecos y se vuelve a subir.

⚠️ **No parte de la plantilla en blanco.** Ésta trae servicios de ejemplo —`+Coco Bongo`— y el
expediente los tiene ya normalizados —`+COCO BONGO`—: juntarlos daba **dos columnas para lo mismo**
y al reimportar ganaba una por casualidad de orden. La exportación saca **sólo lo que ese
expediente usa**.

⚠️ Y de cada eje saca **tantas columnas como grupos tenga quien más tenga**. Con una sola, alguien
con dos reservas aéreas —la nacional y la internacional— perdía la segunda al exportar, y al
resubir se la quitaba de verdad. Como varias columnas comparten cabecera, el volcado **no puede
usar `array_flip`**: colapsa las repetidas.

⚠️ Los servicios se vuelcan **SÍ/NO explícito**, no sólo los «SÍ»: el importador lee el vacío como
NO, así que dejar en blanco al que no participa haría indistinguible «no va» de «nadie lo ha
mirado».

### Nada se guarda en el servidor

Ni la plantilla ni la exportación existen como archivo: se construyen en memoria en cada petición y
el temporal que PhpSpreadsheet necesita se borra en el acto. Guardarlas reintroduce el problema que
resuelve generarlas — **una plantilla desactualizada es peor que ninguna**.

⚠️ La librería es **PhpSpreadsheet 1.30.6**, la última de su rama, y ya estaba en el proyecto. No se
actualizó a propósito: saltar a la 5.x son cuatro majors sobre una librería que **todavía sirve el
panel legado de `src/Oweb/`** (3 archivos). Cuando Oweb se retire, el salto es de dos archivos y sin
riesgo.

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

## 6.t La foto la pone el prestador; el relato lo pone el segmento (27/08/2026)

### El problema es aritmético

Un resort de Punta Cana se cuenta con actividades genéricas: «Desayuno buffet», «Piscina y playa»,
«Cena en restaurante temático», «Espectáculo nocturno». El **texto** de esas actividades es el mismo
en cualquier resort — cambia la **cara**: la piscina del Barceló no es la del Meliá.

Si las fotos viven dentro del segmento, hacen falta **N resorts × M actividades** segmentos, y cada
resort nuevo obliga a redactar M veces el mismo párrafo. Con diez actividades y seis resorts son
sesenta segmentos que dicen lo mismo, y sesenta sitios donde corregir una errata.

La salida es partir las dos cosas:

```
  segmento genérico            componente + tarifa               servicio del prestador
  «Piscina y playa»     ──►    (resuelve QUÉ resort)      ──►    fotos de ESA piscina
  el relato, escrito 1 vez     el enlace, por cotización         la cara, en el catálogo
```

Con eso hacen falta **M segmentos**, y añadir un resort no cuesta ninguno: cuesta cargar su ficha de
catálogo con sus servicios y sus fotos. La actividad de piscina se escribe una vez y cada cotización
la enseña con la piscina que de verdad se contrató.

### Las tres reglas, en orden

Viven en `galeriaPorBloque` (`pax/src/views/cotizacion/PaxCotizacionGuiaView.vue`):

1. **Si el segmento trae galería propia, manda.** Los segmentos redactados para un sitio concreto
   —Paracas, Islas Ballestas— ya tienen sus fotos y no hay nada que promover. La promoción es un
   **recurso para los genéricos**, no un sustituto de lo escrito a mano.
2. **Si no, se concatenan las de TODOS sus componentes**: primero las del servicio del prestador
   (la habitación, la actividad) y después las de la empresa. Un segmento puede llevar almuerzo y
   piscina a la vez, y las dos galerías suman.
3. **Ninguna foto se repite en toda la guía.**

### ⚠️ La deduplicación es por FOTO, no por posición

Es el matiz que decide si la guía cansa o no, y la alternativa fácil está mal.

Lo intuitivo sería «las fotos del hotel salen sólo en el check-in». Eso funciona para la galería del
hotel, pero **mata justo lo que se buscaba**: si la actividad de piscina tiene fotos propias en el
catálogo —otro servicio del mismo prestador—, una regla posicional las esconde y el resto de días
se queda sin cara.

Deduplicando por URL de imagen se obtienen las dos cosas a la vez, sin decidir nada:

- la galería de la **habitación** cae en el primer segmento que la trae —el check-in— y no vuelve a
  aparecer en los siete días siguientes, porque son literalmente las mismas fotos;
- la galería de la **piscina** sí aparece en su día, porque son fotos distintas.

**Lo repetido se calla solo; lo distinto siempre se enseña.**

El recorrido se hace de una vez sobre `itinerarioVista`, que ya viene ordenado por día, porque «la
primera vez que aparece» sólo tiene sentido leyendo el itinerario entero en orden. Calcularlo dentro
del `v-for` daría un resultado distinto según qué día estuviera abierto.

### ⚠️ Las fotos pasan por el mismo filtro que el nombre del prestador

`CotizacionCotcomponentePrestadorPublicNormalizer` inyecta `prestadorImagenes` y
`prestadorServicioImagenes` **sólo si `isPrestadorVisible()`**. Eso no es una limitación que haya que
sortear: **una foto de la piscina del Barceló identifica al hotel igual de bien que su nombre**, así
que revelarla con el prestador oculto sería la misma fuga por otra puerta.

La consecuencia práctica: **la promoción funciona donde enseñas al proveedor** —resorts, hoteles,
trenes— y en un componente con el prestador oculto el segmento se queda con su galería propia o sin
galería. Es lo correcto, y por eso el interruptor es uno solo.

### Qué exige esto de los datos

La potencia sale del catálogo, no del código. Para que un resort nuevo funcione:

| Hace falta | Dónde |
|---|---|
| La organización con su título público | `TravelOrganizacion` |
| Un **servicio por actividad** que quieras ilustrar, con sus fotos | `TravelOrganizacionServicio` + sus imágenes |
| Que la tarifa lleve su organización y su servicio | `travel_tarifa.prestador_id` / `prestador_servicio_id` → se congela en `CotizacionCottarifa` y siembra el componente |
| Que el componente quede con `prestadorVisible = true` | ficha del componente |
| *(opcional)* La prosa de cada uno | `descripcion` de la organización y del servicio — ver abajo |

Un servicio sin fotos no rompe nada: el segmento simplemente no gana galería. Y un servicio con
fotos pero **sin título** es la incoherencia que caza `CoherenciaCatalogoChecker` — hoy la tiene
Terra Andina Colonial Mansion.

### La descripción: el campo que existía y no llegaba (31/08/2026)

`TravelOrganizacion` y `TravelOrganizacionServicio` tienen **dos** superficies i18n, no una:

```
titulo       ──►  cómo se llama    «Occidental Caribe», «Piscinas y playa»
descripcion  ──►  qué es           la prosa: tres piscinas, una con bar acuático…
```

Las dos llevan `#[AutoTranslate]` y se traducen a siete idiomas al guardar. Pero **`descripcion`
no salía a `/pax`**: el normalizer inyectaba título, url e imágenes y ahí se acababa, así que un
texto escrito en el panel se traducía siete veces y no lo leía nadie. Cero filas llenas en las
dos tablas — que es la pista de que el campo llevaba muerto desde su creación, no de que nadie
tuviera nada que contar.

Ahora viaja como `prestadorDescripcion` y `prestadorServicioDescripcion`, y se pinta en el modal
del proveedor: la del **servicio** pegada a su título, la de la **empresa** debajo.

⚠️ **Por el mismo camino que el título, no por un grupo de serialización nuevo.** Comparte su
gate —`isPrestadorVisible()`— porque describir la piscina identifica al hotel igual de bien que
nombrarlo, exactamente el mismo argumento que ya se aplicó a las fotos. Un grupo aparte sería una
segunda puerta al mismo dato, y de esas ya se cerró una: los ids del prestador se colaban en
`Accept: application/json` porque el `unset()` sólo protegía en JSON-LD.

**El reparto con el segmento**, que es lo que evita duplicar: el segmento cuenta **qué se hace**
—vale para cualquier resort—, la descripción del servicio cuenta **cómo es aquí**. «Piscina y
playa» lo escribes una vez; «tres piscinas, una climatizada y una con bar acuático» va en el
servicio del Occidental y no contamina el segmento genérico.

Se edita en EasyAdmin (`TravelOrganizacionServicioCrudController`, campo «Descripción»), no en
`util/`. Un servicio sin descripción no rompe nada: el modal se queda con el título.

### El nombre del proveedor sigue la misma regla, pero por RACHA (28/08/2026)

Armar cada día de resort como su propio cotservicio —lo que permite intercalar una excursión
entre el desayuno y la cena, ver §6.u— rompió una costumbre que funcionaba: marcar el prestador
visible en **un** componente y que la pastilla saliera una vez. Con la estadía partida en siete
cotservicios, «una vez por servicio» pasó a ser **una vez por día**.

`lineaQueNombraProveedor` aplica el mismo principio que la galería —lo repetido se calla— con una
diferencia deliberada:

⚠️ **No es «una vez en toda la guía»: se callan sólo los días CONSECUTIVOS.** Un viaje que empieza
y termina en el mismo hotel de Lima dejaría la vuelta sin nombre con la regla global. Volver a un
hotel lo vuelve a nombrar; encadenar noches, no.

Un día sin ninguna mención no rompe la racha: la estancia sigue siendo la misma aunque ese día se
saliera de excursión.

La pastilla de «Su reserva» (`prestadorDeLinea`, sólo `modo === 'no_incluido'`) **queda fuera**: no
es un proveedor nuestro sino lo que el pasajero ya tiene contratado, y ahí repetir informa.

Esto no sustituye a `prestadorVisible`, que decide si el proveedor se puede nombrar siquiera; sólo
evita repetir lo que se acaba de decir.

Medido en la propuesta de Nune & Todd: **nueve menciones para cinco proveedores**, con Terra Andina
repetida tres veces seguidas.

### Estado medido el 31/08/2026

Cuatro servicios del catálogo tienen fotos (Tambo del Inka 5, Hatun Inti 4, Terra Andina 3, Sonesta
Miraflores 2). Alcanzan a **18 componentes**, los 18 con el prestador visible y **los 18 en segmentos
sin galería propia**: es decir, los 18 promueven.

**Punta Cana tiene ya todo el cableado y ninguna foto**, que es el caso que conviene mirar porque
enseña dónde está el trabajo de verdad. `app:travel:crear-actividades-resort` dejó montado
Occidental Caribe con `visibleParaCliente = 1`, **7 servicios de prestador** y **12 tarifas a 0**
que apuntan cada una al suyo:

```
Lobby y recepción       ← check-in AM/PM, check-out AM/PM
Restaurante buffet      ← desayuno, almuerzo, cena buffet
Restaurantes temáticos  ← cena temática
Piscinas y playa        ← piscina y playa
Actividades y deportes  ← actividades recreativas
Espectáculos nocturnos  ← espectáculo nocturno
Habitación Doble        ← día libre (resumen)   ← era «El resort», ver docs/Travel.md §11.quater
```

Ese mismo día se cargaron además los **siete tipos de habitación** del hotel, con título y
descripción en los siete idiomas: son lo que de verdad se contrata, y por tanto el
`prestadorServicio` correcto de una línea de alojamiento.

Quedan **0 imágenes en los 13 servicios**. La cadena entera está viva y no promueve nada, porque lo
que promueve es contenido — y la descripción, que desde hoy sí llega al cliente, no sustituye a la
foto: son las dos superficies de la misma ficha. Añadir el resort número dos no cuesta ningún
segmento ni ninguna línea de código: cuesta subir fotos y escribir párrafos.

## 6.u Cómo se ordena un día (28/08/2026)

La regla vive en `itinerarioVista` (`pax/src/views/cotizacion/PaxCotizacionGuiaView.vue`) y decide
en qué orden ve el pasajero lo que le pasa en un día. **No es intuitiva**, y de ella salen dos
trampas que ya se midieron.

### 🔥 Un spinner de página completa borra el trabajo del operador (01/09/2026)
⚠️ **Y quitar ese spinner dejó guardar sin ninguna señal.** El aviso de «estoy trabajando» era un
efecto colateral de la pantalla que se destruía: al dejar de destruirse, pulsar Guardar no producía
nada visible y parecía roto. El aviso se mudó a dos sitios que **no tapan el trabajo**: el propio
botón, que pasa a «Guardando…» con su spinner y se deshabilita, y una barra de 3 px bajo la
cabecera. La barra existe además del botón porque las operaciones largas —aplicar plantilla,
actualizar textos— se lanzan **desde dentro del modal**, donde el botón Guardar no se ve.

No es una barra de progreso: no sabemos cuánto falta, así que no llena, sólo recorre. Fingir un
porcentaje habría sido peor que no poner nada.



El editor pintaba su estado de carga así:

```html
<div v-if="store.isLoading"> … spinner … </div>
<div v-else-if="store.cotizacion">   ← el editor ENTERO cuelga de aquí
```

Con el editor entero en el `v-else`, **cualquier cosa que levante el flag desmonta el árbol y lo
remonta**: todos los `scrollTop` vuelven a cero. Y no lo levantaba sólo la carga inicial —también
`aplicarPlantilla()` y `actualizarTextosSegmentos()`, que son operaciones que ocurren **dentro del
Constructor de Storytelling, con el modal abierto**—. Cada retoque devolvía al operador al
principio de la lista, a buscar otra vez el servicio en el que estaba. En un programa de veinte
días eso es rehacer el camino cada vez.

⚠️ **Y `isLoading` no se podía quitar de esas acciones, que es la trampa.** No es «pinta un
spinner»: `guardarCotizacion()` lo consulta para **negarse** —«el editor está ocupado terminando
otra operación»—. Quitarlo habría dejado guardar a mitad de una plantilla a medio aplicar, que es
mucho peor que perder el scroll.

La salida es separar las dos responsabilidades que el flag tenía mezcladas:

| | Quién lo levanta | Para qué |
|---|---|---|
| `isLoading` | carga inicial, aplicar plantilla, actualizar textos | **Candado**: bloquea guardar y deshabilita botones |
| `isCargaInicial` | **sólo** `inicializarEditor()` | Pintar el spinner de página completa |

En la carga inicial desmontar es correcto: no hay nada que preservar. En las demás, el aviso ya lo
dan los botones, que tienen su propio spinner y se deshabilitan solos con el candado.

**La regla general:** un `v-if` de carga que envuelve la pantalla entera no puede colgar de un flag
que también usan las operaciones parciales. Si el flag sirve además de candado, hacen falta dos.

### ⚠️ `itinerarioVista` era la ÚNICA fuente del itinerario, y estaba encerrada (02/09/2026)

Salió al intentar generar un PDF del itinerario para los clientes que piden «un papel». Cualquier
consumidor nuevo —un PDF, un correo, un resumen— que quiera enseñar el viaje **tiene que rehacer
este cálculo**, porque no existe en ninguna otra parte. Y no es una lista de párrafos ordenados:
son al menos tres reglas que no se adivinan leyendo las entidades.

| Regla | Qué pasa si un consumidor nuevo no la replica |
|---|---|
| La hora de un párrafo es **min(inicio) / max(fin)** de sus componentes | Enseña la hora de un componente cualquiera |
| Se **excluyen los `horaServicioCompleto`**: su hora es de la excursión entera, no de ese párrafo | La excursión aparece empezando a la hora de su ancla, dos veces |
| **Estadías**: un párrafo sin horas cuyos componentes acaban en fecha posterior se repite **cada día** del periodo | Un viaje con hotel **pierde los días intermedios** |

La tercera es la que convierte el error en algo visible. Medido sobre la propuesta `2KVBMX`: la
composición real da **16 días**, y el servicio de PDF escrito en PHP daba **11**. Cinco días de
hotel desaparecidos, sin un solo error.

**Lo que se hizo, y lo que NO.** No se replicó en PHP: sería un espejo de una lógica que este mismo
capítulo abre diciendo que «no es intuitiva». Se hicieron dos cosas:

1. **La composición salió del componente**, a `pax/src/dominio/itinerarioVista.ts`. Vivía como
   `computed` dentro de un `.vue` de 1.953 líneas, y allí **no la podía importar nadie**: ni un
   PDF, ni un test, ni un proceso Node. La vista la sigue llamando desde un `computed` de una
   línea, así que **la reactividad no cambió** — es la misma función desde el mismo sitio.
2. **El PDF se imprime desde la propia página** (ver abajo). No hay servicio, ni plantilla, ni
   librería: lo que se imprime es la misma vista, así que no puede desalinearse con ella.

El servicio PHP (`CotizacionItinerarioPdf`), su controlador y su plantilla Twig **se borraron**, y
con ellos `Cotizacion::esVisibleParaCliente()`, que sólo existía para aquella ruta.

### El módulo no decide nada de pantalla (02/09/2026)

⚠️ La primera versión devolvía `mostrarTituloServicio` y `mostrarAccionInclusiones`. Son
**decisiones del panel del huésped**, y estaban dentro del único módulo compartido: el día que
`util` lo importara las habría recibido vacías, y el paso siguiente habría sido un
`modo: 'editor' | 'guia'` — el primer `if` por consumidor.

Ahora devuelve el **hecho estructural**, `esPrimeroDelServicioEnElDia`, y cada consumidor cuelga
de él lo suyo. La guía lo hace en dos funciones de una línea (`tituloGrandeDeServicio()`,
`mostrarAccionInclusiones()`); otro consumidor hará otra cosa, o nada.

**La regla:** un módulo compartido devuelve hechos, no decisiones de pintado. Un flag de
presentación ahí dentro es una bomba de relojería con el nombre del segundo consumidor escrito.

### La entrada es un contrato ESTRECHO, no la serialización de nadie

`componerItinerario()` declara **los doce campos que lee** (`ServicioMinimo`, `SegmentoMinimo`,
`ComponenteMinimo`) y es genérico sobre el resto. Los tipos de segmento y componente se **derivan**
del servicio que entra, así que se llama sin escribir ningún tipo y cada app recupera los suyos:
`bloque.servicio.tituloSnapshot` sigue tipado en `pax` y lo estará en `util`.

⚠️ **La prueba de que el contrato es correcto es que en el test no hay ni un `as`.** Antes había
uno, porque el módulo pedía la serialización pública entera y un fixture con los doce campos reales
no encajaba. Que los JSON compilen tal cual demuestra que describe lo que el módulo usa y no la
forma de un consumidor concreto.

⚠️ **Y esto NO es fusionar los tipos de `pax` y `util`**, que se midió y sería posible —`pax` es
subconjunto estricto— y sigue sin hacerse: los campos de diferencia son los que la API decide no
mandarle al cliente, y el compilador de `pax` es la única comprobación automática de esa frontera.

**Cómo se verificó que no cambió nada:** los snapshots no se movieron, y el DOM renderizado es
idéntico al del código anterior en producción sobre la misma propuesta — 16 días, 8 títulos
grandes, 19 botones de inclusiones, 30 pastillas de hora en los dos.

⚠️ **El módulo no importa nada de `@/stores` ni de `vue`, y eso es deliberado.** Es la condición
para que pueda mudarse al paquete compartido con `util` cuando exista (`docs/NodeEnElStack.md` §9).
El día que alguien le meta una dependencia de store, deja de poder salir.

**El espejo que sigue vivo:** `posicionDeServicio()` está aquí **y** en
`util/src/stores/cotizacion/cotizacionEditorStore.ts`. Es el espejo que la capa compartida borra;
hasta entonces, si cambia una se cambian las dos o el editor y la guía enseñan días distintos.

### El PDF del itinerario es una hoja de impresión, no un documento (02/09/2026)

El botón **Imprimir** de la guía fuerza el modo Resumen, espera un `nextTick()` y llama a
`window.print()`. El modo Resumen ya deja fuera fotos y descripciones largas —existía desde antes
para leer en pantalla—, así que el papel no necesita una segunda maqueta.

⚠️ **La restauración va en `afterprint`, no en la línea siguiente.** `window.print()` devuelve el
control en cuanto se abre el diálogo, no cuando se cierra: restaurar el modo justo después lo
deshace mientras el navegador todavía está componiendo las hojas.

⚠️ **Y `nextTick()` no es adorno**: sin él se imprime el DOM anterior al cambio de modo y salen las
fotos igual.

**Dos caminos, no uno.** El botón no es la única forma de imprimir: un `Cmd+P` desde el menú del
navegador **no pasa por él**, así que las galerías se ocultan *también* en CSS
(`[data-galeria]`). No es duplicar el requisito: son dos caminos distintos y cada uno necesita su
defensa.

⚠️ **Chrome no imprime fondos salvo que marques «Gráficos de fondo», y nadie lo marca.** Todo lo
que en pantalla es texto blanco sobre color —el número del día, las pastillas de hora— saldría
blanco sobre blanco: **invisible**. Y las horas son justamente lo que el cliente se lleva. Por eso
`.chip-dia` y `.pastilla-hora` se invierten en `@media print` a texto oscuro con borde. Se
descubrió mirando el simulacro, no leyendo el CSS.

Qué se quita del papel (clase `no-imprimir`): la fila superior de la cabecera, el conmutador de
moneda, el panel de precio, la barra Detalle/Resumen, la navegación de días y las filas de acción
que abren modales. Y el panel de inclusiones se **despliega** (`.panel-inclusiones`): en pantalla
lo abre un botón, y en papel no hay botón que pulsar — sin esa regla se imprimen 128 px de lista y
el resto no existe.

### 🔥 Lo que sólo se ve imprimiendo de verdad (02/09/2026)

La hoja se había comprobado **simulando** —inyectando las reglas `@media print` en la página— y esa
técnica valida los colores y lo que se oculta, pero **no puede mostrar la paginación**. Al imprimir
de verdad con Chrome en modo headless (`--print-to-pdf` sobre `?mode=build`) salieron tres fallos
que la simulación no podía enseñar:

| Fallo | Por qué pasaba |
|---|---|
| **19 descripciones cortadas a media frase**, con un «LEER MÁS» muerto debajo | El recorte es `max-h-36 overflow-hidden` y lo abre un botón. En papel no hay botón |
| **Las notas del segmento no salían** | Son `<details>` cerrados, y un `<details>` sin `open` no imprime su contenido |
| `break-inside: avoid` sobre el día **no hacía nada** | Un día real ocupa entre una y tres hojas: la regla no se puede cumplir y el navegador la ignora |

⚠️ **La tercera es la lección que vale.** Pedir `break-inside: avoid` sobre un bloque más alto que
una página no falla: **se ignora en silencio**, y quien la escribió se queda con la sensación de
haberlo resuelto. Lo que sí se puede garantizar, y es lo que de verdad estropea un itinerario
impreso, es que **la cabecera del día no se quede huérfana al pie de la hoja** — eso es
`break-after: avoid` sobre la cabecera, no sobre el día. Y que una tarjeta no se parta por la
mitad, que sí cabe.

⚠️ **Y la primera generaliza:** en pantalla, recortar contenido tras un botón es «ver menos»; en
papel es **«no existe»**. Cualquier bloque plegado —descripciones, inclusiones, `<details>`— tiene
que abrirse en `@media print`. Ya había pasado con el panel de inclusiones y volvió a pasar con
las descripciones y las notas: es un patrón, no un descuido puntual.

**Cómo se comprueba, sin abrir el diálogo:**

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new \
  --ignore-certificate-errors --no-pdf-header-footer --virtual-time-budget=15000 \
  --print-to-pdf=/tmp/prueba.pdf "https://pax.openperu.test:8890/file/LOCALIZADOR/v/1?mode=build"
pdftotext -layout /tmp/prueba.pdf - | grep -c "LEER MÁS"   # tiene que dar 0
```

Resultado del arreglo, sobre la misma propuesta: 26 → **24 páginas**, «LEER MÁS» 19 → **0**, y
7.700 caracteres más de texto útil que antes no se imprimían.

**Qué NO va en la hoja de impresión:** ocultar *contenido*. Eso va en el modo Resumen. Si una regla
de contenido vive sólo en el CSS de impresión, la pantalla dice una cosa y el papel otra.

### 🔥 Una clave de i18n que falta no falla: se lee en castellano (02/09/2026)

El botón se probó sobre una propuesta **en inglés** y salió «IMPRIMIR» entre «DETAILS» y «SUMMARY».
`maestroStore.t()` devuelve `''` para una clave inexistente y el `|| 'Imprimir'` la rellena: la
pantalla se pinta entera y no falla nada.

Es la misma familia de fallo mudo de todo este proyecto, y **no lo caza ninguna herramienta**: ni
el typecheck ni ESLint saben qué claves existen en la base. Se ve mirando la pantalla en un idioma
que no sea el español.

Las cadenas entran por **comando ORM, nunca por SQL**: `UiI18n::$contenido` lleva
`#[AutoTranslate]`, y un `INSERT` directo se salta el listener y la cadena nace sólo en castellano.
Ver `App\Pax\Command\PaxCrearTextosItinerarioCommand` (idempotente por la clave, con `--dry-run`).

### El algoritmo, en tres pasos

```
1. AGRUPAR   los bloques por cotservicio  (`servicio.id`)
2. ORDENAR   los grupos:
                 tier 0  tiene alguna hora   → por su hora MÁS TEMPRANA
                 tier 1  ninguna hora        → por su `orden` más bajo
                 tier 2  es estadía          → siempre al final
3. ORDENAR   dentro de cada grupo, por `segmento.orden`
```

La hora de un grupo sale de las horas de sus bloques **más la hora promovida** del componente con
`horaServicioCompleto`, si lo hay (`promoPorServicio`). Por eso una excursión cuyo único horario
está en el ancla se coloca bien.

### ⚠️ Trampa 1: el grupo es ATÓMICO

Un cotservicio se coloca por su hora **más temprana** y **todo lo suyo se pinta seguido**. Medido
con las horas reales del resort:

```
┌ Actividades resort   tier 0 (07:00)
│   07:00  Desayuno buffet
│   19:00  Cena buffet            ← ¡antes de una excursión de las 08:00!
│   21:30  Espectáculo nocturno
┌ Isla Saona           tier 0 (08:00)
│   08:00  Isla Saona
```

No hay forma de intercalar algo *dentro* de un grupo. La solución es **partir el día en dos
cotservicios** —resort mañana / excursión / resort noche—, que además es el patrón normal del
sistema: en una propuesta real «Alojamiento» aparece **12 veces** como cotservicios distintos.

Con el resort partido, el mismo día ordena bien: 07:00 desayuno → 08:00 Saona → 19:00 cena →
21:30 show.

### ⚠️ Trampa 2: sin hora se va al final, no al principio

`tier 1` va **después de todo lo que tenga hora**, y eso rompe la intuición de que «lo que no tiene
hora es de fondo, va primero».

```
┌ Coco Bongo           tier 0 (22:00)   ← ¡primero!
│   22:00  Coco Bongo
┌ Actividades resort   tier 1 (orden 1)
│     ·    Día libre en el resort       ← sin hora
```

Por eso **`ACT-RESORT-DIA_LIBRE` lleva 07:00** aunque represente la jornada entera: aquí la hora
no describe, **posiciona**. Es contraintuitivo y por eso está escrito.

La regla que sale de las dos: **si un segmento tiene que caer en un sitio concreto del día, dale
hora.** Dejarla vacía no es «sin preferencia», es «al final».

### Estadía = PERNOCTE, y por eso cierra el día

```js
esEstadia = !horaInicio && !horaFin && finPeriodo > base
```

Un segmento **sin horas** cuyos componentes terminan en fecha posterior. No se pinta una vez: se
**repite cada día** del periodo `[check-in .. check-out)` con su contador `noche N de M`, y el día
de salida no lleva ninguna — se recorren **noches**, no días.

Por eso «siempre al final» es correcto: **la tarjeta es la noche**, no el hotel como lugar. Y
encaja mejor desde que existe `ACT-RESORT-CHECKIN_PM`: el *momento* de registrarse tiene su bloque
a su hora, y la tarjeta de estadía queda como «dónde duermes».

### ⚠️ Un alojamiento con hora deja de ser estadía, y rompe dos cosas a la vez

Si `horaInicio` existe, `esEstadia` es `false` y pasa esto, sin un solo error:

1. **deja de repetirse**: `fechas = [base]`, así que sale sólo el primer día y las demás noches
   desaparecen del itinerario;
2. **baja a tier 0** y se coloca a esa hora, en mitad de la tarde, en vez de cerrar el día.

Un hotel de tres noches se ve como una actividad suelta de las 15:00.

Había uno en el catálogo —`ALO-MACHU`, con 15:00, el único de 19 relaciones de alojamiento—. No
llegó a morder porque las cotizaciones que lo usan traen `sin_horario = 1` y `compConHora()` mira
ese flag antes que la hora, pero era una trampa para el siguiente que lo copiara.

Hoy hay tres defensas: la migración que lo limpió, la validación
`TravelSegmentoComponente::validarAlojamientoSinHora()` y el chequeo `alojamiento-con-hora` de
`CoherenciaCatalogoChecker`, que repara marcando `sin_horario` — un hotel nunca se vende por hora,
así que sólo hay una respuesta posible.

### ⚠️ La hora de llegada al hotel SÍ existe: es otro campo

Es la pregunta que surge sola, y el modelo ya la tenía resuelta con dos campos en La Biblia:

| Campo | Qué es | Editable | Viaja al cliente |
|---|---|---|---|
| `horaComponente` | la hora **como se vendió** | no | sí (es la de la cotización) |
| `horaRecojo` | la que fija **el operador** | **sí**, en línea | **no**, ni se snapshotea |

Avisar al hotel de a qué hora llega el huésped es `horaRecojo`. Meterlo en el catálogo lo publica
en la propuesta *y* rompe la estadía: dos efectos indeseados por usar el campo equivocado.

### Lo que sí funciona sin tocar nada

- **Empates**: dos bloques a la misma hora dentro del grupo desempatan por `orden`, de forma
  estable.
- **Excursión con guion**: el ancla presta su hora al grupo y los segmentos que sólo cuentan
  —sin componente, luego sin hora— mantienen su secuencia por `orden`. Es el caso de `WALK_MIR`.
- **Estadías al final**: el alojamiento nunca se cuela entre las actividades del día.

### Dónde está escrito para quien edita

En el panel, la ayuda plegable de **Hora Inicio** y **Orden** en
`TravelSegmentoComponenteCrudController` cuenta las dos trampas donde se toman las decisiones. El
reparto segmento ↔ componente está en `docs/Travel.md` §11.quinquies.

## 6.ah El arrastre guardaba el orden y la ficha volvía a su sitio (29/08/2026)

Reportado por el operador: se arrastra un servicio, se suelta, y **regresa a donde estaba**.

El `orden` **sí** se guardaba. Lo que fallaba es que la cascada seguía poniendo la hora primero,
así que el número sólo desempataba entre los que no tenían ninguna — y el editor recolocaba al
instante como si el gesto no hubiera existido.

**Un día ordenado a mano se ordena SÓLO por su `orden`.** Todo o nada, que es como lo numera
`reordenarServicios()`: o el día entero lo colocó una persona, o lo coloca el reloj.

```
automático            08:30 Transporte · 12:10 Vuelo · 13:35 Transporte · sin hora Alojamiento
se arrastra el hotel al primer puesto
a mano                10 Alojamiento · 20 Transporte · 30 Vuelo · 40 Transporte
```

⚠️ **Y era una incoherencia mía, no un olvido.** Construí `orden-contradice-hora` —un chequeo que
sólo tiene sentido si el orden PUEDE contradecir la hora— y a la vez dejé el orden sin poder
hacerlo. **Un chequeo que vigila algo que el código no deja ocurrir no vigila nada**, y su
existencia me hizo creer que la pieza estaba completa.

La revisión externa lo había señalado con el nombre exacto —«snap-back»— y aun así no lo arreglé:
lo leí como un caso borde en vez de como el flujo principal. Lo encontró el operador al primer
intento.

⚠️ En `pax` se aplica igual, o el operador coloca el día y el huésped lo lee en otro orden. Con
una excepción: **las estadías repetidas siguen cerrando el día**. Son la nota de «sigues aquí», no
una parada del relato, y no es eso lo que nadie está colocando cuando arrastra.

⚠️ **Y la excepción era demasiado ancha: se comía también la PRIMERA noche (arreglado el
07/09/2026).** El escalón 2 se resolvía antes de mirar el `orden`, así que arrastrar un hotel lo
movía en el editor —que no tiene escalones— y en la guía volvía al final. **El mismo snap-back
que esta sección dice haber arreglado, sobrevivido en las estadías**, y silencioso porque el
número sí se guardaba.

Si alguien coloca el alojamiento en mitad del día es porque significa algo: el **check-in**, un
momento del día aunque el hotel no tenga hora, después del cual puede seguir habiendo
actividades. Lo que no es una parada del relato son las **repeticiones**.

```
día curado, hotel arrastrado al medio
  10  Hotel  (check-in)     ← obedece: es donde lo puso la persona
  20  Cena
  …   noche 2/3             ← «sigues aquí»: cierra igual
```

**En un día automático no cambia nada** —el alojamiento tiene `ordenNarrativo` 90 y sigue
cerrando—, y los snapshots de las tres cotizaciones reales salieron idénticos.

⚠️ **Y el `orden` es del SERVICIO, no del día.** Un hotel curado el día 1 se lleva su número a las
noches 2, 3 y 4, y `diaAMano` lo daba por «alguien colocó este día» sin que nadie lo tocara: los
servicios de esos días perdían el orden por reloj y empataban todos en `MAX_SAFE_INTEGER`. Ahora
las repeticiones no cuentan para esa pregunta. No llegó a verse en producción —hay 6 servicios con
`orden > 0` y ninguno dura más de un día— pero estaba armado.

**La orden del proveedor NO cambia**: sigue siendo cronológica. Es un horario de trabajo, no un
relato — allí la hora manda siempre.

## 6.ag Lo que encontró la revisión externa del ordenamiento (29/08/2026)

Cuatro correcciones sobre la implementación recién desplegada. La primera es la que duele.

### ⚠️ Escribí la tabla de tipos en el front, que el propio código prohíbe

`ComponenteTipoEnum::ordenNarrativo()` **ya viajaba serializada** como
`CotizacionCotcomponente::getOrdenNarrativo()`, en los grupos `cotizacion:read` y
`pax_cotizacion:read`, y **los dos fronts ya la consumían**. A 300 líneas de mi código, en el
mismo archivo, estaba escrito:

> «poner los números en el front sería escribirlos dos veces — y dos copias de una regla discrepan
> el día que alguien toca una»

Creé las dos copias. Ninguna herramienta lo caza —los números coincidían— y el typecheck no tiene
forma de saber que el campo ya existía. **Sólo se ve leyendo lo que hay antes de escribir.**
Ahora `posicionDeServicio()` lee `c.ordenNarrativo` en los dos lados y no hay ninguna tabla en TS.

### El interruptor decía «por día» y era global

`modoReordenar` era un `ref(false)` único, con un comentario al lado afirmando que iba por día:
pulsarlo colapsaba **todos** los días a la vez. Ahora guarda la fecha (`diaEnReorden`). El
comentario describía la intención y el código hacía otra cosa — la clase de mentira que este repo
dice no tolerar.

### `multidia-sin-noche` miraba la fecha equivocada

Detectaba multidía por fechas de **inicio** distintas, pero `pax` decide la repetición por
`fechaHoraFin`. Un componente que va del día 1 al 3 tiene **una** fecha de inicio y aun así se
repite por días — justo el caso que el chequeo dice vigilar.

⚠️ Y la primera corrección **se pasó**: mirar el fin a secas hacía saltar 7 falsos positivos, los
traslados de las 22:00 que acaban a las 00:30. **Cruzar medianoche no es durar dos días.** La
condición buena es la misma que usa `esEstadia`: sin horario y fin posterior al inicio.

### Lo que se deja como está, y por qué

- **La Biblia ordena por posición antes que por hora**, al revés que las otras cuatro. No es un
  descuido: su propio docblock lo dice desde el principio —«un cuadro ordenado sólo por reloj
  parte el relato»— y tiene interruptor para verla por hora. Queda declarado aquí.
- **La colisión residual**: `posicionDelServicio()` es `min(ordenNarrativo)`, y casi todo servicio
  con transporte da 10. Dos excursiones el mismo día siguen empatando y desempatan por hora. El
  arreglo separa naturalezas distintas, no todo — el commit lo dijo más ancho de lo que es.
- **`orden-contradice-hora` no ve la contradicción manual-vs-automático** (exige que los dos
  tengan `orden > 0`), ni los empates. Cubre el caso que produce el arrastre, que es para el que
  se escribió.

## 6.af Modo reordenar: la ficha se colapsa y aparece el asa (29/08/2026)

El interruptor «Ordenar» va **en la cabecera de cada día**, no global: se ordena un día concreto y
tenerlo ahí deja claro sobre cuál actúa el arrastre.

```
Día 3 · Cronología operativa ──────────────  [Ordenar]  [Automático]

  ⠿  Transporte en Cusco                        13:35
  ⠿  Full Day Valle Sagrado               sin hora
  ⠿  Alojamiento en Cusco                 sin hora
```

**Por qué un modo y no un asa siempre visible**: reordenar y editar son tareas distintas. Con la
ficha entera delante, arrastrar compite con abrir. Y colapsar deja el día completo en pantalla —
no se puede ordenar lo que no se ve junto.

⚠️ **La hora se enseña al lado del asa a propósito**: es el dato que el arrastre puede
contradecir, y una contradicción hay que verla **al hacerla**, no descubrirla tres pantallas
después.

### Todo el día o ninguno

Al soltar se numera el día entero con huecos de 10. Un día con unos servicios fijados y otros
automáticos es un estado que nadie sabe leer; así `orden = 0` en todos significa «este día se
ordena solo» y cualquier otra cosa, «lo ordenó una persona». Los huecos permiten insertar entre
dos sin renumerar.

Y hay **salida explícita**: el botón «Automático» devuelve el día a `orden = 0`. Sin él, una vez
fijado a mano un cambio de hora deja de recolocar nada y la única forma de volver sería adivinar
los números.

⚠️ **No se puede soltar en otro día.** La fecha de un servicio sale de sus componentes, así que un
arrastre entre días tendría que reescribirlas o mentir: dos operaciones en un gesto. El store
rechaza el movimiento y **no avisa** — un gesto que no significa nada no es un error, y anunciar
cada uno enseñaría a ignorar los avisos.

### El chequeo que lo respalda: `orden-contradice-hora`

Es el precio de haber hecho el orden manual pisable, y se aceptó a sabiendas. Salta cuando un
servicio movido a mano queda antes que otro **del mismo día** que empieza más tarde.

⚠️ **«Del mismo día» lo añadió la prueba.** La primera versión comparaba hermanos de la misma
cotización sin más, así que habría gritado con cualquier itinerario ordenado a mano en varios
días. Se vio porque los dos servicios que el script eligió al azar eran del 22 y del 23 de julio.

⚠️ Y la prueba también estuvo mal antes que el chequeo: cogía dos servicios de **cotizaciones
distintas**, el JOIN no los emparejaba y daba «no salta» sobre código correcto. Se arregló con
**control negativo y positivo** —ordenado a favor del reloj no salta, invertido sí—, que es lo que
distingue «funciona» de «no lo estoy midiendo».

## 6.ae Dónde va un servicio cuando el reloj no lo decide (29/08/2026)

`CotizacionCotservicio::$orden`. **`0` = automático**, y es lo que arranca todo el mundo: el
comportamiento no cambia hasta que alguien mueva algo, así que la migración no siembra nada.

La cascada, idéntica en las dos vistas:

```
1  la hora más temprana de sus componentes          ← manda siempre que exista
2  el `orden` del servicio, si alguien lo puso      ← 0 = no lo han tocado
3  el orden narrativo del tipo                      ← llegar abre el día (10), dormir lo cierra (90)
```

### ⚠️ Lo que había antes era un número de otra escala

En la guía el desempate era `min(segmento.orden)` — **un número pensado para ordenar DENTRO de un
servicio, usado para comparar ENTRE servicios**. Cada plantilla empieza por su segmento 1, así que
valía 1 para todas y el resultado lo decidía el orden de inserción. En el editor era peor: un
`return 0` explícito.

Que dos escalas distintas compartan nombre —«orden»— es lo que lo hizo invisible durante tanto
tiempo.

### El orden narrativo es un TERCER espejo

`ComponenteTipoEnum::ordenNarrativo()` (PHP) · `ORDEN_NARRATIVO` en `util/src/types/operacionModel.ts`
· `ORDEN_NARRATIVO` en `pax/.../PaxCotizacionGuiaView.vue`. **Los tres cambian juntos**, o el
huésped y el operador ven días distintos — que es justo lo que acabábamos de arreglar.

### Lo que se aceptó al hacer el manual pisable

Que una posición puesta a mano **pueda contradecir al reloj**. Se prefirió a no dar control: la
contradicción la caza un chequeo explícito, mientras que «se ve raro y alguien lo nota» es una
señal accidental, y de ésas este código ya se ha quemado bastante.

⚠️ **Un servicio multidía tiene un solo número para varias apariciones.** Hoy no muerde: los siete
que existen son escalón 0 en todos sus días. El día que uno se quede sin hora en alguno, sí.

⚠️ Y medido: **hoy ninguna cotización tiene dos servicios sin hora el mismo día**. El campo no
cambia nada visible todavía — es la base sobre la que se apoyará el modo reordenamiento, y esa es
la razón de meterlo ahora y no cuando haga falta corriendo.

## 6.ad Una noche repetida arrastraba el orden de la primera (29/08/2026)

Un hotel que vive **dentro** de un servicio con actividades —el Skylodge, con su cápsula
colgante— se repite una vez por noche, y cada repetición conservaba el `orden` de su segmento.
Ese número era correcto el primer día y falso en los siguientes:

```
12-set   [tier 0 · 15:00]  Skylodge con actividades
           1  Traslado desde el Valle Sagrado      15:00
           2  Ascenso por Vía Ferrata              16:00
           3  Alojamiento en Cápsula                 —     🛏 noche 1/3

13-set   [tier 0 · 09:00]  Skylodge con actividades
           3  Alojamiento en Cápsula                 —     🛏 noche 2/3   ← ⚠️ encabeza la mañana
           4  Descenso por Zipline                 09:00
           5  Retorno hacia Cusco                  11:00
```

Al cliente le salía **«sigues alojado en la cápsula» abriendo un día que empieza con una
tirolesa a las 09:00**.

**El arreglo**: las repeticiones van en su propio grupo (`${servicio.id}::repeticion`), así que
flotan a donde estarían si el hotel fuera un servicio suelto — escalón 2, al final del día, junto
a los demás alojamientos.

```
13-set   [tier 0 · 09:00]  Skylodge · Zipline · Retorno
         [tier 2 estadía]  Alojamiento en Cusco
         [tier 2 estadía]  Alojamiento en Cápsula   🛏 noche 2/3
```

La primera noche **sí conserva su sitio en el relato** —llegar y dormir allí es parte de lo que se
compró—; las repeticiones son un «sigues aquí», que es nota de cierre y no de apertura.

⚠️ **El escalón 2 exige que TODO el grupo sea estadía** (`gb.every(b => b.esEstadia)`), y ésa es la
razón de que el hotel de dentro no se fuera al final por sí solo: su grupo tenía horas. Separar
las repeticiones es lo que hace que la regla las alcance.

⚠️ El título no se duplica: `mostrarTituloServicio` se pone en el primer bloque **por servicio y
día**, así que en un día con actividades la repetición sale sin encabezado; en un día en el que
sólo queda la noche, lo lleva —que es lo que hace falta para saber de qué hotel se habla.

Verificado simulando el caso: se estiró el hotel a 3 noches en una transacción, se miró la guía
día a día, y `rollback`. Razonarlo no habría bastado — la posición sale de tres reglas que
interactúan (repetición, agrupación por servicio, escalón).

## 6.ac Dos chequeos para lo que sostiene el orden del día (29/08/2026)

El itinerario coloca cada servicio por la hora más temprana de sus componentes, y los que no
tienen hora desempatan por el `orden` de sus segmentos. **Dos supuestos de los que depende que el
día salga bien, y que nadie vigilaba.**

| clave | qué caza | por qué importa |
|---|---|---|
| `orden-empatado` | dos párrafos hermanos con el mismo `orden` en el mismo día | cuál sale antes lo decide el azar del guardado |
| `multidia-sin-noche` | un servicio que abarca días sin llevar alojamiento | o las fechas están mal, o es una forma que el ordenamiento no contempla |

### ⚠️ El supuesto NO es «sólo los hoteles duran varios días»

Es **«lo que dura varios días es porque incluye una noche»**, y la diferencia importa. Hay siete
servicios de dos días —«Two Day Camino inca», «Skylodge con actividades», «Two Day MAPI»— y
ninguno es un hotel: **todos llevan alojamiento dentro**, y es la noche la que los hace durar.

La primera formulación habría dado un chequeo que grita con siete casos legítimos. Comprobarlo
contra el dato antes de escribirlo es lo que lo evitó.

### Los dos nacen en cero, y eso también hubo que comprobarlo

Se probaron **fabricando cada caso en una transacción y deshaciéndola**: clonar un segmento con el
`dia` y `orden` de su hermano, y estirar un componente un día atrás en un servicio sin noche.
Ambos contadores suben de 0 a 1 y vuelven.

⚠️ Y una consulta a ojo dijo lo contrario antes de la prueba: agrupando por NOMBRE parecía haber
un empate real —dos «Transporte desde el hotel en Lima al Aeropuerto»— y eran **cinco cotservicios
distintos**, cada uno con su segmento en `orden 1`, que es lo normal. Agrupar por lo que se lee en
vez de por la clave es la forma más fácil de inventarse un hallazgo.

## 6.ab Colocar es una tarea operativa: el desplegable enseña el nombre interno

El modal «¿Dónde ubicar el segmento?» listaba los párrafos por su **título**. Se usa para
**colocar** uno entre otros, y para eso hace falta reconocer el tramo, no leer su prosa comercial:

```
interno   Retorno Bimodal a Cusco (Vía Ollantaytambo)
título    Recepción y traslado al hotel en Cusco        ← se confunde con cualquier otra llegada

interno   Contacto en el hotel
título    Encuentro con nuestro personal en tu hotel
```

Sólo **13 de 227** segmentos tienen los dos textos distintos, y son justo aquellos en los que
colocar mal es fácil. El título queda de respaldo para los párrafos escritos a mano.

**La regla general**: un desplegable que sirve para *operar* se rotula con el nombre operativo; el
título es para las superficies donde alguien *lee* el viaje. Vale igual para el selector de
plantilla, el de componentes y éste.

## 6.aa El editor ordenaba por el reloj y el cliente por el guion (29/08/2026)

`ordenarComponentesCronologicamente()` ordenaba sólo por fecha y hora. `pax` agrupa por servicio y
dentro ordena por **`segmento.orden`** —el guion de la plantilla—, así que un check-in sin hora
caía al final en el editor y salía en su sitio en la guía: **dos vistas del mismo día que no
coincidían**, y el operador construyendo sobre la que no era.

Ahora el desempate va en cascada, y cada peldaño tiene su porqué:

```
1  la fecha                        un servicio puede cruzar días
2  el `orden` del segmento         el guion; sin segmento → Infinity, al final de su día
3  la hora                         dentro del mismo segmento
4  quien exige hora, antes         para dos sin segmento
```

⚠️ Un componente **sin segmento** —los extras añadidos a mano— se va al final con `Infinity`, que
es donde el operador los espera: no forman parte del relato.

### ⚠️ «Horario libre» es del componente; «al final del día» es del servicio

Estaban pegados en la misma etiqueta:

```
Check-in en el resort
∞ HORARIO LIBRE / FINAL DEL DÍA      ← junto a un almuerzo de las 12:30
```

Ese servicio ordena a mediodía, no al final: **la mitad de la frase era falsa**. Basta un
componente con hora para que el bloque entero se coloque por ella — es la misma regla que ordena
la guía del huésped, donde el escalón 0 son los servicios con alguna hora y el 1 los que no.

La coletilla sólo aparece si **ningún** componente incluido tiene hora (`servicioActivoSinHora`).
Mira los incluidos a propósito: un «no incluido» es referencia para el cliente y no se despacha,
así que su hora no debería colocar el bloque.

## 6.z Un campo expuesto que nadie pintaba (29/08/2026)

`CotizacionCottarifa::$nombreInternoSnapshot` llevaba `pax_cotizacion:read`. **Ninguna pantalla de
`pax` lo pintaba** —verificado: cero usos fuera del `.d.ts` generado— pero viajaba en el JSON de
cualquier huésped con cotización:

```
Van (Incluido en paquete externo)
Hotel 4 estrellas por grupo
Tacama (Base 2 Pax)
Xtreme Tourbulencia Adulto
```

Jerga de compras, nombres de proveedor y bases de cálculo. Los nombres internos del **segmento**,
el **componente** y el **cotservicio** sí estaban limpios; éste era el único que se había quedado
con el grupo, y no había motivo.

⚠️ **Exposición sin uso: no da error nunca, así que puede durar años.** No lo delató un síntoma
—no hay ninguno— sino la incoherencia interna: cuatro campos hermanos con la misma naturaleza y
uno con un grupo de más. Es exactamente el criterio de `CoherenciaCatalogoChecker`, aplicado a
mano.

Antes de quitarlo se comprobó que no rompía nada: cero usos en `pax/src`, cero en los modelos,
cero en la vista cliente de `util`, y en el backend sólo lo leen La Biblia y los comandos, que no
son contextos de huésped.

⚠️ Y se regeneraron **los dos** `api.d.ts`, como manda CLAUDE.md: el campo desapareció de los 6
esquemas de tarifa que sirven a `pax`.

## 6.y El título del componente también dejó de decir la dirección (29/08/2026)

Al fusionar ida y vuelta se decidió **no tocar `titulo`** por ser prosa de cliente. Fue un error a
medias: el nombre operativo pasó a «Vuelo Cusco ↔ Arequipa (ida o vuelta)» y el título público se
quedó congelado en un sentido —«Vuelo desde la ciudad de Cusco a la ciudad de Arequipa»—.

Y `pax` lo pinta **con prioridad sobre el título del segmento**, así que un huésped con el tramo
de vuelta leería la dirección al revés. No se dejó de decir algo: **se dejó diciendo lo contrario**,
que es peor.

La corrección es aplicar allí el **mismo criterio de prioridad** que en el despacho: en los tipos
que nombran una ruta manda el segmento. `PaxCotizacionGuiaView.vue::tituloDeComponente()`, espejo
de `ComponenteTipoEnum::mandaElSegmento()`.

**Los espejos son TRES y están fijados así desde el 31/08/2026:**

| Copia | Dónde | Qué rotula |
|---|---|---|
| El enum | `ComponenteTipoEnum::mandaElSegmento()` | La orden y La Biblia |
| `util` | `util/src/utils/componenteTipo.ts` | El editor **y** Operaciones, que antes tenía la suya aparte |
| `pax` | `PaxCotizacionGuiaView.vue` | La guía del pasajero |

⚠️ Eran cuatro copias en camino de ser cinco: `OperacionView.vue` llevaba la regla duplicada y el
editor de cotizaciones no la tenía. Al arreglar el editor se extrajo el helper en vez de copiarla
otra vez, así que `util` volvió a tener una.

### 🔥 Faltaban dos superficies, y la causa es que una CONGELA y las otras PINTAN (31/08/2026)

La regla se puso donde el nombre se resuelve **al leer** y nadie miró al que lo resuelve **al
escribir**. El resultado era una misma propuesta contándose dos cosas:

```
pax · itinerario        tituloDeComponente()        resuelve AL PINTAR    «Del aeropuerto a Miraflores» ✓
util · La Biblia        OperacionOrdenServicioItem  resuelve AL PINTAR    correcto                      ✓
pax · «qué incluye»     construirInclusiones()      congela AL GUARDAR    «…Lima ↔ Miraflores»          ✗
util · editor           la cabecera y las tarjetas  no conocía la regla   «…Lima ↔ Miraflores»          ✗
```

`construirInclusiones()` vive en `cotizacionEditorStore.ts`, corre al pulsar Guardar y escribe el
texto ya resuelto dentro de `clasificacionFinancieraCliente`. Pax sólo lo lee. Por eso la regla,
añadida a los dos consumidores que resuelven en tiempo de lectura, no llegó nunca al que resuelve
en tiempo de escritura.

**La lección general:** al añadir una regla de presentación, la lista de sitios a tocar no es «los
que pintan», es «los que deciden el texto» — y un snapshot decide texto aunque no pinte nada.

### El editor era la superficie que más dolía, y no por el cliente

Con un componente por ruta, las dos líneas de una escala **se llaman exactamente igual** en el
editor. Medido en la propuesta de Lima:

```
componente                                 segmento                      hora
Transporte Aeropuerto Lima ↔ Miraflores    Del aeropuerto a Miraflores   08:30   ← el IN
Transporte Aeropuerto Lima ↔ Miraflores    Regreso al aeropuerto         10:00   ← el OUT
```

El operador tenía que **abrir cada una y deducir cuál era por la hora que ya tenía puesta** — o
sea, por el dato que venía a poner. `etiquetaDeComponente()` rotula ahora esas líneas con el
título del segmento en la tarjeta de la lista y en la cabecera del inspector, y baja el nombre del
insumo a la línea pequeña con un icono de caja.

⚠️ **No se cambia en todas partes.** Donde el rótulo dice «Insumo Maestro», donde cuelga una tarifa
y en la lista de componentes de un segmento, lo pertinente sigue siendo el nombre del componente:
la tarifa es del componente bidireccional y es la misma para los dos sentidos, y dentro de la
tarjeta de un segmento el título del segmento ya está arriba.

⚠️ **Las propuestas ya guardadas no se arreglan solas.** El «qué incluye» es snapshot: hay que
**re-guardar** la cotización para que la corrección le entre.

### Los tres nombres de una línea, y quién lee cada uno

Auditado el 31/08/2026 leyendo los grupos de serialización, porque la intuición falla: los tres
parecen «internos» y sólo uno lo es de verdad.

| | Campo | Grupos | Quién lo lee |
|---|---|---|---|
| **Insumo maestro** | `componenteMaestroId` | interno | Nadie fuera del editor. Es el vínculo, y de su `nombreInterno` se **copia** el nombre interno al añadir el componente |
| **Nombre interno** | `nombreInternoSnapshot` | `cotizacion:*`, `operacion:item:read` — **sin `pax_cotizacion:read`** | **El proveedor.** `BibliaSnapshotService::resolverNombreComponente()` lo congela en la orden |
| **Nombre público** | `tituloSnapshot` | incluye `pax_cotizacion:read` | El cliente… salvo en transporte, tren y vuelo |

⚠️ **En esos tres tipos el nombre interno NO es referencia muerta: el proveedor lo lee.**
`OperacionOrdenServicioItem` pone el segmento arriba y **el componente como segunda línea** —
`getSecundarioParaProveedor()` devuelve literalmente «el que NO subió». La orden lleva los dos a
propósito: con el genérico arriba, al proveedor le llegaba una flecha de dos puntas en grande y el
destino de hoy en letra pequeña, así que se invirtió el orden, no se quitó nada.

El que sí quedó sin lector en esos tipos es el **nombre público**: el itinerario y el «qué incluye»
toman el título del párrafo. Sobrevive sólo como último recurso de La Biblia si el nombre interno
estuviera vacío — y el editor repone el del catálogo al vaciarlo, así que en la práctica no se
llega ahí. Por eso su asterisco de obligatorio **desaparece** en transporte, tren y vuelo: pedía
pulir un texto que no se publica.

`pax` no recibe `nombreInternoSnapshot` en absoluto. Lo confirma el grupo, no el código que lo usa.

### La tarjeta de identificadores tiene dos caras, y el tipo decide cuál va delante

De lo anterior sale el diseño: en el inspector del componente, los nombres viven en una tarjeta con
**delante lo que alguien lee de verdad** y **detrás lo secundario**.

```
transporte · tren · vuelo     delante: el PÁRRAFO (título + nombre interno, sólo lectura)
                              detrás:  el componente (interno, público, insumo)

los demás tipos               delante: el componente
                              detrás:  el párrafo
```

⚠️ **El intercambio no es una decisión de pantalla: espeja `getSecundarioParaProveedor()`.** Si esa
regla cambiara y la tarjeta no, el editor enseñaría delante justo lo que la orden manda detrás.

⚠️ **La cara de atrás se rotula por quién la lee, no como «datos internos».** El nombre interno se
imprime en la orden; llamarlo referencia técnica haría que alguien dejara de cuidarlo.

⚠️ **La cara del párrafo es de SÓLO LECTURA.** Ese dato vive en el segmento y editarlo aquí sería
una segunda puerta al mismo campo — con dos versiones al final. La tarjeta lo dice en una línea.

⚠️ **La hora se queda FUERA de la tarjeta.** Es lo que se viene a hacer en esta pantalla y no es un
identificador: meterla dentro la escondería medio tiempo detrás de un clic.

### Sin párrafo manda el componente, sea del tipo que sea

`mandaElSegmento()` dice quién manda **cuando existen los dos**; no promete que el segmento exista.
El backend ya lo tenía en cuenta y la tarjeta no:

```php
if ($this->mandaElSegmento() && $segmento !== '') { return $segmento; }
return $componente ?: …                       // ← la segunda condición es la que faltaba
```

Un componente **manual** de tipo transporte no cuelga de ningún párrafo, y mirando sólo el tipo la
tarjeta abría por una cara que únicamente podía disculparse —«no cuelga de ningún párrafo,
voltea»—: un clic obligatorio para llegar a lo único que había. No es un caso teórico; medidos el
31/08/2026 en producción: **2 de transporte y 3 de ticket variable sin párrafo**.

Ahora, sin párrafo:

- manda el componente y su cara va delante;
- **el botón de voltear desaparece** —no hay segunda cara— y en su sitio queda una línea que dice
  por qué, para que no parezca que falta algo;
- y el asterisco de «Nombre Público» **vuelve**, porque ahí sí se publica. Es lo que impide el
  fallo real de esta rama: sin él la línea saldría sin nombre en la propuesta.

Las otras dos superficies ya lo hacían bien por casualidad —`etiquetaDeComponente()` y
`nombrePublicoDeLinea()` caen al componente cuando el título del párrafo viene vacío—, pero
conviene que la condición esté escrita y no sea un efecto colateral del `||`.

### 🔥 `servicioActivo` es `null` fuera de su propio nivel

La trampa que hizo que la primera versión de esto **funcionara en la lista y se apagara dentro del
inspector**, sin dar un solo error:

```ts
const servicioActivo = computed(() =>
    inspectorActivo.value === 'servicio' ? (dataActiva.value as CotServicio) : null);
```

Es un computed **por nivel del inspector**, no «el servicio en el que estoy trabajando». En cuanto
se entra al componente vale `null`, así que cualquier resolutor escrito como
`store.servicioActivo?.cotsegmentos` recibe una lista vacía y concluye que la línea **no cuelga de
ningún párrafo** — que es exactamente lo que llegó a decir la tarjeta de un componente que sí
colgaba de uno.

El síntoma es cruel porque **la mitad visible funciona**: en la lista de componentes el inspector
sí está en «servicio», así que ahí el nombre del párrafo salía bien y parecía todo correcto.

**La regla:** para llegar de un componente a su servicio se usa `findServicioByComponenteId()`, que
lo busca por id y funciona mire quien mire. De ahí sale `segmentoDeComponente()` en el store, y por
eso vive en el store y no en la vista: la vista no tiene forma de saber en qué nivel está el
inspector cuando la llaman desde dos sitios.

⚠️ **Y esto no lo caza `vue-tsc` ni `eslint`.** El tipo es `CotServicio | null` y el `?.` es
correcto; lo que falla es la semántica. Se caza abriendo la pantalla — que es lo que faltó.

### 🐛 El volteo NO es un `rotateY`, y es deliberado

La tentación es un flip 3D. `transform` crea **contexto de apilamiento**, y dentro de ese panel hay
inputs y —dos bloques más abajo— un `VueDatePicker` con `teleport`. Un `rotateY` en el contenedor
rompe el posicionamiento de lo que se teleporta desde dentro, y el síntoma aparece lejos: el
calendario abriéndose en el sitio equivocado o recortado.

Se intercambia el contenido con un desplazamiento lateral corto y `mode="out-in"` (que además evita
el salto de altura al solaparse las dos caras). El gesto se lee igual. Misma familia que la «x» de
`clearable`: la propiedad *parecía* la natural y el fallo no habría dado ningún error.

⚠️ **Manda por un motivo distinto en cada lado, y conviene no confundirlos.** En La Biblia porque
el nombre OPERATIVO ya no dice la dirección; en `pax` porque el TÍTULO PÚBLICO tampoco puede
decirla y encima lo intenta. Misma regla, dos razones — si algún día una de las dos cambia, la
otra no se mueve sola.

El título del segmento sí sirve porque **hay uno por sentido**: «Vuelo Cusco – Arequipa», «Viaje
en tren desde Ollantaytambo». Los segmentos no se fusionaron; sólo los componentes.

⚠️ Las cotizaciones ya hechas están a salvo: congelaron el título viejo, que para ellas era
correcto. Esto sólo afectaba a las nuevas.

## 6.x Guardar reemplaza el árbol, y el historial se queda atrás (29/08/2026)

Reportado como **dos** fallos intermitentes del editor: «añado una tarifa y no aparece — guardo y
sí» y «el basurero a veces no borra». Es **uno**, y sólo pasa después de guardar sin salir del
componente:

```
1. abres componente     el historial guarda ESE objeto
2. abres una tarifa     el historial guarda el componente
3. GUARDAS              cotizacion.value = savedData   ← árbol nuevo entero
                        dataActiva SÍ se revincula…
                        …historialNavegacion NO
4. vuelves atrás        dataActiva = copia vieja, desconectada del árbol
```

Desde ahí `agregarTarifa()` y `eliminarTarifa()` buscan por id en el **árbol vivo** y aciertan —
pero la vista está pintando **otro objeto**. Por eso la tarifa aparecía al guardar: el árbol sí
la tenía.

**El arreglo es un resolutor único**, `revincularNodo()`, que devuelve el nodo vivo equivalente y
lo usan los dos sitios: el post-guardado —que antes repetía el rebusque a mano en tres bucles— y
`retrocederNivel()`, que ahora resuelve al sacar del historial en vez de confiar en la referencia.
Si el nodo ya no existe, sigue retrocediendo en vez de dejar el inspector sobre un fantasma.

⚠️ **Revincular al sacar, y no al guardar.** El historial tiene muchas entradas y la mayoría no se
llegan a usar; recorrerlas todas en cada guardado sería pagar por lo que no se pide. Además cubre
cualquier otra causa de desconexión, no sólo el guardado.

⚠️ **Y dos comparaciones de id en crudo.** `agregarTarifa` hacía `c.id === componenteId` y
`eliminarTarifa` `t.id !== tarifaId`, mientras `findServicioByComponenteId` ya normalizaba con
`extractIdStr`. El id llega unas veces como IRI y otras como uuid pelado: con uno de cada lado,
`agregarTarifa` **salía del método sin añadir nada y sin decir nada**. Así se ve un botón que «a
veces no funciona».

## 6.w El chequeo vigila la duplicación del catálogo (29/08/2026)

El refactor de transporte dejó las tarifas en 239 partiendo de 336, con 25 rutas bidireccionales
donde había 48 componentes. **Nada impedía que volviera a crecer igual** — y crecerá: cada copia
se creó por una razón razonable en su momento. Lo que faltaba era algo que lo dijera mientras son
dos y no veinte.

Cuatro chequeos nuevos, con el comando que los resuelve en el propio `detalle`:

| clave | qué caza | se arregla con |
|---|---|---|
| `tarifas-repetidas` | copias exactas dentro de un componente | `app:travel:limpiar-tarifas-repetidas` |
| `transporte-direccional` | un componente por sentido en vez de por ruta | `app:travel:fusionar-transportes-bidireccionales` |
| `tarifas-por-sentido` | «desde»/«hasta» dentro de un componente ya bidireccional | `app:travel:fusionar-tarifas-por-sentido` |
| `bidireccional-sin-marca` | un «↔» que no avisa de que lo es | **se repara solo** |

**Sólo el último se repara**, y es la aplicación literal de la regla de §6.v: añadir «(ida o
vuelta)» no cambia lo que el componente es, sólo lo dice, así que no hay segunda lectura. Los
otros tres **tocan dinero** —borrar o fundir tarifas— y eso no lo decide un chequeo.

### ⚠️ Un chequeo que nunca encuentra nada no se distingue de uno que funciona

El propio docblock del checker ya avisaba de que un JOIN mal escrito da **cero filas sin error**.
Escribir cuatro consultas nuevas y verlas devolver 0 sobre un catálogo recién limpiado no prueba
absolutamente nada.

Por eso se probaron **fabricando cada caso en una transacción y deshaciéndola**, con el patrón
`var/probar-*.php` del proyecto —local, porque `var/` no se versiona—: medir antes, insertar un
duplicado exacto, un par direccional, un par desde/hasta y un bidireccional sin marca, comprobar
que los cuatro contadores suben, y `rollback`.

```
tarifas-repetidas          0 → 1   ✅
transporte-direccional     0 → 2   ✅
tarifas-por-sentido        0 → 2   ✅
bidireccional-sin-marca    0 → 1   ✅
```

Es la forma barata de distinguir «no hay nada» de «no lo estoy mirando». **Repítela al añadir un
chequeo**: es lo único que separa una consulta correcta de una que devuelve cero para siempre.

## 6.v El chequeo sólo avisa de lo que se puede arreglar (28/08/2026)

`CoherenciaCatalogoChecker` reparte en dos bloques: lo que repara solo y lo que deja para una
persona. El segundo bloque **tiene que poder vaciarse**, o deja de leerse.

⚠️ El chequeo `orden-congelada-incompleta` estaba pidiendo lo imposible: listaba líneas de órdenes
**canceladas**. Su propio consejo es «se corrigen reemitiendo», y una orden cancelada no se
reemite. Cuatro líneas de las dos órdenes de prueba del 22/08 salían **en cada inspección** desde
que `nombre_componente` nació el 27/08.

Ahora se acota a órdenes **vivas**: `estado_os NOT IN ('completada','cancelada')`.

La frontera no es nueva. `OperacionOrdenServicio::getEdicionPermitida()` ya llama **cerrada** a
completada o cancelada, con el motivo escrito: *«a toro pasado no completa nada, sólo reescribe
historia»*. El chequeo reusa esa misma línea en vez de inventar otra.

### La regla que sale de aquí

**Un aviso sobre el que no se puede actuar es peor que ninguno**, porque enseña a saltarse el
bloque donde viven los que sí importan. Es lo mismo que llevó a desactivar las reglas cosméticas de
ESLint —ver la sección «Frontend» de CLAUDE.md—: mil quejas de formato sepultaban los errores de
verdad.

Al añadir un chequeo, la pregunta no es «¿esto está mal?» sino **«¿quién lo lee puede hacer algo
hoy?»**. Si la respuesta es no, o se acota o no se añade.

## 6.w Los vuelos dejan de ser texto (28/08/2026)

Un vuelo vivía dentro de `cotizacion_file_grupo.detalle`, en texto libre:

```
* Retorno JA7013 · LIM 23/09 06:30 → CUZ 23/09 07:55
```

Con eso, comprobar si una aerolínea movió un horario es **comparar cadenas** —hubo que cotejar 16
segmentos a mano para encontrar el único que cambió— y «¿a quién afecta?» no se puede preguntar.

### El modelo

```
cotizacion_vuelo                     cotizacion_grupo_vuelo
· numero, fecha   ⊙ identidad        · vuelo_id ──► cotizacion_vuelo
· aerolinea                          · grupo_id ──► cotizacion_file_grupo
· segmentos  JSON                    (N:M, con FK)
· notas      JSON
```

**Los segmentos van en JSON** porque se leen enteros y no se filtran; un vuelo es uno o dos saltos
—Copa hace `LIM → PTY → PUJ` bajo un billete—.

⚠️ **El vínculo con los localizadores NO va en JSON.** Un vuelo lo comparten varios PNRs —ocho
comparten el `DM6771`— y guardarlos como array de cadenas dejaría que un localizador mal tecleado
casara con cero grupos **sin dar error**. La regla: *a JSON lo que se lee entero y no se filtra; a
tabla lo que el motor tiene que garantizar.*

### ⚠️ `emitido` es de la RESERVA, no del vuelo

Estuvo mal puesto en `cotizacion_vuelo` durante unas horas. El `H2 5002` es un vuelo real y
emitido para quien tenga su billete; lo que está pagado sin emitir son **esos 88 billetes**. Un
mismo vuelo puede llevar reservas emitidas y sin emitir a la vez, así que en el vuelo la bandera
no significaba nada.

Vive en `CotizacionFileGrupo`, junto a la `clave`: mientras es `false`, esa clave es un
localizador **provisional** —«AAAAA»—, y eso no se distingue mirándolo de uno real.

### El archivo va POR PNR, porque así llega la información

La aerolínea no escribe «el DM6771 se movió»: escribe **sobre un localizador**.

```json
[{ "pnr":       "YMFLHB",
   "emitido":   false,
   "notas":     ["Lista de nombres aún no cargada en el portal de Sky (plazo 12/09/2026)"],
   "vuelos": [{
     "numero": "H2 5002", "fecha": "2026-09-17", "aerolinea": "Sky Airline",
     "segmentos": [{ "numero": "H2 5002", "origen": "CUZ", "destino": "LIM",
                     "salida": "2026-09-17 06:50", "llegada": "2026-09-17 08:35" }]
   }] }]
```

`pnr` es el nombre bueno; `localizador` se acepta porque así se llamó al principio.

### ⚠️ Se llama `leg`, no «segmento»

En este sistema un `TravelSegmento` es un **capítulo del relato** de un viaje —«Parque Kennedy»,
«Piscina y playa»— y un vuelo no es eso. El término del sector para un salto entre dos aeropuertos
es **leg**, y usarlo evita que dos cosas distintas compartan nombre en el mismo modelo.

`leg` es un **objeto**, no una lista: un vuelo es un leg y una conexión son **dos vuelos**. Una
lista se rechaza, y un `segmentos` se rechaza también con un mensaje que dice cómo renombrarlo —no
se acepta en silencio, porque volvería a colar el billete de Copa disfrazado de vuelo. También se
aceptan los campos sueltos sin envolver.

⚠️ Las **notas del PNR** no son decoración: de la carga real de Sky salieron el plazo del portal de
grupos (12/09), el número de solicitud y un cambio de nombre en curso con su ticket. Sin sitio
donde ponerlas, viven en la bandeja de correo de alguien.

⚠️ **El expediente no va en el archivo**: se importa desde él, así que lo pone el contexto. Así
«localizador» significa una sola cosa —el PNR—, que es lo que significa en el sector. La primera
versión lo usaba para el expediente y se prestaba a confusión.

⚠️ `salida` y `llegada` son fecha-hora completas: una bandera «llega al día siguiente» junto a las
dos fechas es el mismo hecho dos veces, y basta que alguien corrija una para que se contradigan.

### Los tres cambios que manda una aerolínea

| Lo que pasó | Qué hace el importador |
|---|---|
| cambia el horario | actualiza los segmentos; los otros PNRs del vuelo no se tocan |
| el PNR se reubica | el vínculo pasa al otro vuelo |
| **el vuelo se traslada de fecha** | el PNR apunta al vuelo nuevo, y el viejo queda **sin nadie** |

⚠️ El tercero es el que obliga a que los vínculos del PNR **se reemplacen, no se sumen**: el
archivo declara dónde viaja ese localizador hoy. Un vuelo que se queda sin ningún PNR no se borra
—puede ser un traslado a medias— pero **se avisa**, y ese aviso es la señal de que algo quedó
atrás.

### `pnr_nuevo`: el provisional pasa a definitivo

Cuando la aerolínea emite, el código cambia. `pnr_nuevo` renombra el grupo y **los pasajeros ni se
enteran**, porque cuelgan del grupo y no de la clave. Antes eso obligaba a reeditar el Excel y
volver a subir 133 filas.

Dos guardas: si el destino ya existe **se para** —son dos reservas con su gente cada una, y
fundirlas perdería a unos u otros— y si el origen no existe también, porque crear el nuevo
convertiría una errata en un grupo huérfano.

### Servicio, no comando

La lógica vive en `VuelosImportador` porque **lo mismo se hace desde el expediente**, pegando el
JSON en un cuadro de texto junto al cargador de Excel. El comando
`app:cotizacion:cargar-vuelos <expediente> <archivo>` es una envoltura fina.

⚠️ **Ensaya por defecto**; escribe con `--aplicar`. Lo dispara un correo y los correos se leen con
prisa.

### ⚠️ Dos gotchas que costaron rato

**MySQL reordena las claves de un objeto JSON** al guardarlo —por longitud y luego
alfabéticamente— y el `!==` de PHP sobre arrays **sí mira ese orden**. Cuando los segmentos eran
JSON, comparar el itinerario nuevo con el guardado daba «cambia» en los catorce vuelos con el antes
y el después **idénticos en pantalla**. Dejó de aplicar al pasar a columnas, pero **sigue valiendo
para cualquier columna JSON con objetos dentro**.

**`findOneBy()` no ve lo que aún no se ha volcado.** Los cuatro PNRs de Copa creaban cada uno su
propio `CM264`, y al aplicar reventaba el índice único. El importador mantiene un índice en
memoria de los vuelos del expediente y lo actualiza con lo que crea.

### Se carga desde el expediente, pegando el JSON

El padrón llega en Excel porque lo llena el colegio; los vuelos llegan en un **correo de la
aerolínea**, y pedir que alguien los pase a una hoja para volver a subirlos es trabajo inventado.
Por eso en la ficha del expediente hay un botón **«Cargar vuelos»** que abre un cuadro de texto
donde se pega el JSON, con su ayuda plegada y un ejemplo de un clic.

`VuelosCargaController` es una envoltura sobre el mismo `VuelosImportador` que usa el comando: no
hay una segunda copia de las reglas.

⚠️ **El ensayo escribe y deshace**, como el del padrón: se abre transacción, se hace `flush` y se
revierte. Un ensayo más permisivo que la carga no sirve de nada — así se comprueban el índice
único y las claves foráneas, que es donde falla lo que no se ve leyendo el archivo. Y en HTTP no
vale `em->clear()`: desengancharía el propio expediente que el controlador tiene en la mano.

**«Aplicar» sólo se habilita tras un ensayo con cambios y sin problemas**, así que no hay forma de
escribir sin haber visto antes el diff.

Los vuelos se pintan en el expediente en **orden cronológico** —así se mira un expediente: «qué
pasa el día 23»— con sus PNRs al lado y un aviso de «+1 día» cuando la llegada cruza medianoche.

### En la ficha del pasajero, plegado

⚠️ Vaciar el `detalle` dejó **muda** la ficha de cada persona, que era la única que pintaba el
itinerario. Ahora lo compone cruzando los vuelos del expediente con el PNR del pasajero.

Va **plegado**: la mayoría de las veces se abre una ficha para ver quién es, no a qué hora vuela, y
desplegar cuatro tramos por persona entierra el resto. Se abre con **clic o toque** —un `<button>`
y un `Set`, sin `hover`, que en un móvil no existe—.

Lo que se ve **sin abrir** es lo que hay que perseguir: el sello **«sin emitir»**. Y al abrir, los
tramos con su «+1 día» y las notas del PNR —plazos y trámites que antes vivían en una bandeja de
correo—.

⚠️ `CotizacionVuelo::getPnrs()` publica los códigos como **texto**, no la colección: serializar los
grupos arrastra `grupo → file → vuelos → grupo` y el serializador corta con
`CircularReferenceException`.

### El agente lee lo mismo que el operador

`ConsultarPadronSkill` sacaba los horarios de `$grupo->getDetalle()`. Ahora los compone desde los
vuelos (`itinerarioDe()`), así que **no hay dos versiones que puedan discrepar**.

Añade además dos cosas que el texto no podía decir:

- **`sin_emitir`**, y sólo cuando lo está: decir «emitido: sí» en las 22 normales es ruido.
- **los avisos del PNR** —plazos, trámites— cuando se pregunta por alguien concreto.

⚠️ Y el «llega al día siguiente» **se dice con palabras** en vez de dejar que el modelo reste dos
fechas: es de lo que más se equivoca al resumir un itinerario nocturno.

Los horarios siguen saliendo **sólo al preguntar por una persona**, no al listar 40: en una lista
son miles de tokens que nadie pidió.

### El texto libre se retiró

Con el itinerario en dos sitios, el que se quedaría viejo sería justo el que ve el operador. La
migración `Version20260829010000` vació el `detalle` de los **24 grupos aéreos** que ya tienen sus
vuelos cargados.

⚠️ Sólo de esos. Un grupo aéreo **sin** vuelos conserva su texto —es lo único que se sabe de él— y
los otros 85 grupos ni se tocan.

### Lo que NO cambia

`cotizacion_file_grupo.detalle` **sigue existiendo**, y libre. Era el único sitio donde vivían los vuelos y ha dejado de
serlo, pero es un campo genérico y puede servir para describir una habitación. El importador de
vuelos no lo toca fuera de los grupos aéreos.

⚠️ Ojo si se le da ese uso: lleva `pax_file:read`, así que **viaja al navegador del pasajero**
aunque hoy ninguna vista lo pinte. Para notas internas, quitar ese grupo primero.

### ⚠️ `inversedBy` que falta: la relación se vuelve unidireccional sin decirlo (30/08/2026)

`CotizacionVuelo` tiene dos relaciones hacia el expediente, y a las dos les faltaba `inversedBy`:

| Lado dueño (en `CotizacionVuelo`) | Lado inverso | Faltaba |
|---|---|---|
| `ManyToOne` → `CotizacionFile` | `CotizacionFile::$vuelos` (`mappedBy: 'file'`) | `inversedBy: 'vuelos'` |
| `ManyToMany` → `CotizacionFileGrupo` | `CotizacionFileGrupo::$vuelos` (`mappedBy: 'grupos'`) | `inversedBy: 'vuelos'` |

**Qué provoca.** Con `mappedBy` en un lado y nada en el otro, Doctrine **no considera la relación
bidireccional**: la trata como dos mapeos sueltos. Al asignar `$vuelo->setFile($file)`, la
colección `$file->getVuelos()` no se entera dentro de la misma petición — sigue devolviendo lo que
tenía cuando se cargó. El `INSERT` sí se hace, porque el lado dueño es el que escribe; lo que se
queda corto es **lo que se lee después de escribir**, en el mismo request.

⚠️ **Y por eso es de los caros: no da error, da una lista incompleta.** Un vuelo recién añadido no
aparece al recorrer el expediente hasta que alguien recarga la página, y eso se lee como «se
perdió» o como un problema de caché. Nada en el log.

**No lo encontró nadie leyendo el código: lo dijo `doctrine:schema:validate`.** Llevaba tiempo
avisando —los dos `[FAIL]` estaban también en producción— y ese comando no está en la rutina de
verificación. Debería: es la única herramienta que compara las dos mitades de una relación.

Arreglarlo **no toca la base**: la columna `file_id` y la tabla puente `cotizacion_grupo_vuelo` ya
existían, sólo faltaba declarar la correspondencia. `doctrine:schema:validate` completo —mapping y
sincronía— sale limpio sin migración.

## 6.x El expediente, plegado por secciones (29/08/2026)

Un expediente de grupo son **131 personas, 16 vuelos y 109 subgrupos**. Desplegado todo, llegar a
lo de abajo eran seis pantallas de scroll, y quien abre un expediente viene a **una** cosa.

Cuatro secciones, todas **plegadas por defecto**, con el resumen en el rótulo:

```
▸ Manifiesto      131 personas
▸ Vuelos          16 vuelos · 2 sin emitir
▸ Subgrupos       …
▸ Cargar datos    padrón en Excel · vuelos en JSON
```

⚠️ **El resumen es lo que evita abrir para saber si es la que buscas.** Un desplegable cuyo rótulo
sólo dice «Vuelos» obliga a abrirlo siempre, y entonces plegarlo no ahorró nada.

Y por eso «2 sin emitir» va en el rótulo: es lo único de esa sección que exige perseguir a alguien,
y esconderlo tras un clic es como no tenerlo.

### ⚠️ El plegado de subgrupos sólo plegaba el formulario

Era un `v-if` sobre el formulario y un `v-else` sobre la lista, así que plegar **enseñaba la
lista**: la sección no se encogía, cambiaba de contenido. Ahora las dos ramas cuelgan de la misma
bandera con `v-if`.

Es un fallo fácil de no ver revisando el código —`v-if`/`v-else` parece lo correcto— y evidente en
cuanto se usa.

### El modo es un dato, no una sección

Estaba en una tarjeta suelta encabezando la columna derecha, y eso lo hacía parecer una sección más
—del rango de «Manifiesto» o «Vuelos»— cuando es **un campo del expediente**, del mismo rango que
el estado o el idioma. Vive en «Datos del Expediente»: en la cara de lectura como una fila más, y
en la de edición como su desplegable.

⚠️ **Pero no espera al «Guardar» del formulario: se aplica al elegirlo.** De él cuelga si hay
padrón, si el precio es por persona y si se exige documento, así que media pantalla se reconfigura
con el cambio. Guardarlo junto al nombre del titular haría que el resto del formulario cambiara al
pulsar Guardar, que es justo cuando nadie lo está mirando.

### Todo lo que ENTRA va junto, y al final

La carga estaba partida: la hoja arriba, y en medio la tabla de vuelos que separaba el rótulo de su
propio botón de subida. Son la misma tarea con dos orígenes —el colegio manda el Excel, la
aerolínea manda el JSON— así que van en una sección.

Al final porque **es el andamio**: se usa al montar el expediente, no al consultarlo. Es el mismo
criterio que ya tenían los subgrupos.

## 6.y 🐛 Guardar una cotización nueva y verla desaparecer (29/08/2026)

Síntoma, reportado como recurrente: *«guardo, crea la v1, los datos vuelven en blanco, y encima
aparece v2. Salgo al expediente y la v1 está guardada.»*

Los tres hechos son ciertos a la vez, y uno solo los explica: **tras crear, la URL se quedaba en
`/cotizacion/:fileId/version/nueva`.**

```
guardas    →  POST 201, v1 creada · correcta en la base
              …pero la URL sigue diciendo «nueva»
recargas   →  inicializarEditor() ve cotizacionId === 'nueva'
              → crearCotizacionVacia()  → BORRA el árbol de la pantalla
              → version = maxVersion + 1 = 2   → aparece como V2
sales      →  el expediente lista la v1, que nunca se perdió
```

⚠️ **El guardado no tenía nada malo**: 201, el árbol completo en la respuesta, los grupos de
serialización correctos. Lo roto era la navegación, y por eso el dato sobrevivía —lo que se perdía
era lo que la persona tenía delante—.

`handleGuardar()` hace ahora `router.replace` al id real cuando el guardado fue una **creación**.

- **`eraNueva` se lee ANTES de guardar**: al terminar, la cotización ya existe y no hay forma de
  distinguir crear de actualizar.
- **`replace`, no `push`**: volver atrás a «nueva» llevaría al mismo sitio roto.
- **No remonta la vista**: los parámetros se leen sólo en `onMounted` y los `props` declarados no
  se usan en ninguna parte, así que cambiar la URL no toca el estado.

### La lección

Un formulario de creación que no suelta su ruta «nueva» es una bomba de relojería: funciona hasta
la primera recarga. Y el fallo **no deja rastro** —ni en los logs, ni en la base, ni en el estado
de la petición— porque técnicamente todo salió bien.

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
  se coló durante meses una cabecera que pintaba un campo inexistente en
  `TarifaSnapshot` —el título se llama ahí `tituloSnapshot`—, y que por tanto
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

- **Cuándo sale la lista de horarios bajo la tarjeta** → `compsConHora(item).length > 1 ||
  ...some(c => c.esParteRepartida)` en `PaxCotizacionGuiaView.vue`. Con un solo componente la hora
  ya está en la pastilla de la cabecera y repetirla debajo sobra — **salvo** si ese componente es
  parte de un servicio repartido: la vista del huésped está acotada a lo suyo, así que de un vuelo
  repartido entre dos aerolíneas él ve UNA línea, y sin la lista se queda sin el sello que dice
  con cuál vuela. `CotizacionCotcomponente::getEsParteRepartida()` lo resuelve en el backend
  porque la marca (`duplicadoDe`) vive en un solo lado del par: la copia la tiene y la raíz no.
- **Qué dice el sello de al lado del horario** → `selloDeComponente()`: el **`tituloSnapshot` de la
  tarifa**, que es el texto escrito para el cliente y traducido —no `nombreInternoSnapshot`, que es
  jerga de compras y salió de `pax` el 29/08/2026—. Si el título trae corchetes se pinta **lo de
  dentro**: es la convención del operador, «[ SKY AIRLINE ] con articulo personal y equipaje de
  cabina». En producción la usan 12 de 254 tarifas —6 pares raíz+clon—, todas de aerolínea o bus
  de reparto; ninguna tarifa fuera de un reparto usa corchetes.
- **En qué se cuenta un componente que dura** → `TravelComponente::$unidadDeConteo` (lo declara el
  catálogo), con defecto en `ComponenteTipoEnum::unidadPorDefecto()` y congelado en
  `CotizacionCotcomponente::$unidadDeConteoSnapshot`. La aritmética, en `dominio/cotizacion/unidades.ts`.
- **Cómo se llama la unidad («5 desayunos»)** → `TravelComponente::$sustantivoUnidad`, y
  `etiquetaDeUnidades()` para el plural.
- **Dónde se lee algo sin hora dentro del día** → `TravelComponente::$momentoDelDia`, que afina el
  `ordenNarrativo` del tipo. La traducción a reloj es `horaNominal()` en `itinerarioVista.ts`: no se
  guarda ni se pinta, sólo ordena.
- **Partir un título de tarifa en «con quién» y «en qué condiciones»** → `partirTituloTarifa()`.
  Lo usan los tres sitios que pintan ese título: el sello del horario (sólo el primero), los chips
  de «qué incluye» y los de opciones alternativas (los dos, por separado).
- **Explicarle la convención del corchete al operador** → `TravelTarifaCrudController` (`setHelp()`
  del campo «Título Visible al Cliente») y la «i» junto a «Nombre para cliente» en
  `CotizacionEditorView.vue`. Son dos textos y se cambian los dos.
- **Cuándo el título del componente se calla en la línea de horarios** → `hablaCadaComponente()`:
  un solo nombre entre los hermanos del bloque → manda el segmento; dos o más → habla cada
  componente. La comparación va normalizada, decide el bloque entero, y la cláusula existe **sólo
  aquí** —`mandaElSegmento` sigue siendo el mismo espejo en los cuatro sitios—.
- **El enlace de la vista cliente (abrirlo o copiarlo)** → `linkPublicoPropuesta()` en
  `FileDetalle.vue`, y los dos botones pegados de la cabecera de cada propuesta: el ↗ que abre y
  el 📋 que copia. La vista cliente se abre **siempre**, publicada o no —como operador ves también
  lo que el cliente todavía no—, y lo que se manda casi nunca es el expediente entero sino una
  propuesta concreta: por eso el copiar es por propuesta y no sólo el del file. El ✓ de dos
  segundos se guarda en `propuestaCopiada` como NÚMERO y no como booleano, porque la cabecera se
  repite por propuesta y un flag compartido ponía el visto en las tres.
- **Que un dato más salga en la tarjeta del componente** → el bloque de pastillas junto a `prestadorNombreSnapshot` en `CotizacionEditorView.vue` (§6.d).
- **Que el bloque «Catálogo Maestro» arranque cerrado** → `leerPreferencia()` en `CotizacionEditorView.vue`; hoy abre salvo que se haya cerrado antes (§6.d).
- **Cómo se calcula costo/venta/markup/alternativas** → `resumenFinanciero` en `util/.../cotizacionEditorStore.ts`.
- **Qué ve el cliente (y qué se oculta)** → `expurgarParaCliente` en `util/.../cotizacionEditorModel.ts` (+ tipos `OpcionUpgradeCliente`, `InclusionServicio`, etc.). Recordar re-guardar para regenerar snapshots.
- **Tipos compartidos del árbol/tarifas** → `util/src/types/cotizacionEditorModel.ts` (interno) y `pax/src/types/paxCotizacionModel.ts` (cliente). Mantenerlos coherentes.
- **Nombre interno del componente** → helper `nombreInternoDeComponente` (store). **Título público / por ítems** → `tituloClienteDeComponente` (store).
- **Badges modalidad/categoría** → `modCatBadges` (`cotizacionEditorModel.ts` en util; local en `PaxCotizacionGuiaView.vue` en pax).
- **Que el cliente lea una descripción del hotel o de lo que contrató** → `descripcion` (i18n) de `TravelOrganizacion` y `TravelOrganizacionServicio`, en EasyAdmin. Viaja como `prestadorDescripcion` / `prestadorServicioDescripcion` desde `CotizacionCotcomponentePrestadorPublicNormalizer` y se pinta en el modal del proveedor de `PaxCotizacionGuiaView.vue`. Mismo gate que el nombre: sin `prestadorVisible` no sale. Ver §6.t.
- **De dónde salen las fotos de la tarjeta de un segmento** → `galeriaPorBloque` en `PaxCotizacionGuiaView.vue`. Propias del segmento si las hay; si no, las del servicio del prestador de sus componentes, deduplicadas por foto en toda la guía (§6.t). Si no sale ninguna, mira antes `prestadorVisible` que el código: sin él no se inyectan.
- **Cómo se compone el itinerario del cliente (días, bloques, estadías, orden)** → `componerItinerario()` en `pax/src/dominio/itinerarioVista.ts`. **No lo replicques en PHP** (§6.u): la vista lo llama desde un `computed` de una línea y cualquier consumidor nuevo debe importar ese módulo. Ojo con el espejo de `posicionDeServicio()` en el store del editor.
- **Lo que se imprime del itinerario (el «PDF»)** → los dos bloques `@media print` al final de `PaxCotizacionGuiaView.vue` + la función `imprimir()`. **Contenido** que sobre en papel se quita en el modo Resumen, no en el CSS (§6.u).
- **Una cadena de UI de la guía que sale en castellano estando en otro idioma** → falta la clave en `pax_ui_i18n`; se crea por comando (`pax:textos:itinerario`), nunca por SQL — lleva `#[AutoTranslate]` (§6.u).
- **Serialización pública / ocultar precio o proveedor** → `src/Cotizacion/Serializer/CotizacionPublicNormalizer.php` + grupos `pax_cotizacion:read` en las entidades.
- **Portada o duración de un tour de catálogo (en el panel o en pax)** → `TourTarjetaResolver` (§6.b). Nunca reimplementar la derivación en la entidad ni en el front.
- **La tarjeta de precio de la guía (colapsada/expandida, textos del pie)** → sección "TARJETA DE PRECIO" de `PaxCotizacionGuiaView.vue` + `finanzasAbiertas` / `hayPanelPrecio`. Ojo con el vocabulario: §6.
- **Lo que dice el disparador de esa tarjeta** → `etiquetaDisparador` en `PaxCotizacionGuiaView.vue`. Se calcula de `clasesPasajeros` y `opcionesPlanas`: si el panel gana una sección, la etiqueta se queda corta y se toca ahí.
- **Cuántos opcionales se ven con la tarjeta cerrada** → `OPCIONES_EN_ADELANTO` en `PaxCotizacionGuiaView.vue`.
- **Una cadena de UI de `pax` (crear o traducir)** → un comando en `src/Pax/Command/`, NUNCA una migración: lleva `#[AutoTranslate]`. Ver §6.
- **Que un tour de catálogo muestre (o no) el total de grupo** → flag `totalesOcultos`: default al crear en `crearCotizacionVacia()` (store), toggle "Ocultar Total de Grupo" en `CotizacionEditorView.vue`, consumo en `ocultarTotales` de `PaxCotizacionGuiaView.vue`. Ver §6.b.
- **Precio "desde" del escaparate del catálogo** → `preciosDesde[]` (bloque "Precios de Exhibición" del editor) → `PaxCatalogoPortadaView.vue` / `CatalogoDashboard.vue`. No es lo mismo que el flag anterior (§6.b).
- **Lo que enseña cada fila de versión en el dashboard (estado, título, tramo de fechas)** → `CotizacionFileCollectionProvider` lo calcula en UNA consulta batched y viaja en `CotizacionFile::$versionesFechas`; lo pinta `DashboardView.vue` (`tituloDeVersion`, `rangoDeVersion`). **`fechaFin` no es `MAX(fechaInicioAbsoluta)`**: ver la sección del dashboard antes de tocar el DQL.
- **En qué idioma ve el cliente la propuesta** → `idiomaCliente` (§6). Alta: `handleCreate` en `DashboardView.vue`. Cambio posterior: selector de `FileDetalle.vue` → propaga `CotizacionFileIdiomaClienteListener`. Por versión: selector del editor.
- **Qué pasa al pasar una versión a `confirmado`** → dispara `CotizacionConfirmadaEventListener` y genera el cuadro de tráfico del Centro de Operaciones. Es un **snapshot y sólo en la transición**: lo que edites después no llega. El botón «Revisar cambios de operación» (editor, junto al selector de estado) y el icono de diff de `FileDetalle.vue` abren un panel que compara y aplica **sólo lo que apruebes, campo a campo**: `POST .../operacion/plan` y `POST .../operacion/aplicar`. Detalle completo en `docs/Operacion.md` §3.5 y §7.1. **Ojo:** para llegar a `confirmado` hay que pasar el guard de `publicable` — ver §4.b.
- **Quién presta un servicio (hotel del pasajero, vuelo no incluido)** → `prestador*` en `CotizacionCotcomponente`. Sin cascada y sin default por día: los dos se retiraron. `resolverPrestador()` es un lector, **espejo en PHP y TS**. Filtros blandos: `opcionesTarifasFiltradas` (tarifa maestra) y `opcionesProveedoresTarifa` (proveedor) en `CotizacionEditorView.vue`. Ver §6.c. La fuente es `travel_tarifa.prestador_id` (y `comprador_id`), que vuelven a existir desde el 20/08/2026 — nacen vacíos y se llenan a mano en el tarifario.
- **Que al prestador se le nombre (o no) ante el cliente** → `CotizacionCotcomponente::$prestadorVisible`. **Espejo triple**: el normalizer, `construirInclusiones()` y `onPrestadorComponenteChange()` (que lo siembra). Ya **no** se deriva del `modo`; el flag de anonimato global sigue sin taparlo. Ver §6.c.
- **Quién presta un componente** → `CotizacionCotcomponente::$prestador*`, soft-link a `Proveedor` o escrito a mano. Se edita en el inspector del COMPONENTE, junto al comprador. Ver §6.c.
- **A quién se le encarga ejecutar la compra** → `compradorMaestroId` + `compradorNombreSnapshot` en `CotizacionCotcomponente`, soft-link al catálogo de proveedores. Cascada en `resolverComprador()`, **espejo en PHP y TS**. Llega a Operación por `BibliaSnapshotService`. Ver §6.c — **siempre un `Proveedor`, nunca una persona**, y **nunca** le pongas grupo público.
- **Que al proveedor se le nombre (o no) ante el cliente** → `$proveedorVisible` del componente, en OR con el global `Cotizacion::$proveedorOculto`. Lo aplica `CotizacionCotcomponenteProveedorPublicNormalizer`. Sonda: **desaparecida** —`probar-proveedor-componente.php` no está en `tools/` ni en `var/obsoletos/`—; lo más cercano que sí corre es `tools/pruebas/probar-proveedor-visible.php`.
- **De dónde sale el nombre/logo del proveedor que ve el cliente** → `ProveedorVivoResolver`, resuelto contra el catálogo maestro AL SERVIR y precargado en lote desde `CotizacionPublicNormalizer::precargarProveedores()`. **No** es el snapshot: ése es sólo el respaldo si el maestro desaparece. Ver §6.c.
- **Enlazar una línea de inclusión con su componente** → `componenteId`, que emite `construirInclusiones()`. Para propuestas viejas: `app:cotizacion:backfill-componente-id`. Nunca reconstruir el vínculo con una clave natural en tiempo de render.
- **Aligerar `clasificacionFinanciera` / ordenar el store por capas** → está medido y decidido en `docs/Pendientes.md` («El JSON financiero pesa 10× lo que dice»); la forma del servicio, en `docs/NodeEnElStack.md` §8. **Fixtures antes que nada.**
- **TTL de caché del cliente** → `CACHE_TTL` en `pax/.../paxCotizacionStore.ts`.
- **Cómo se cargan los assets (dev/prod, puertos)** → `templates/util/app.html.twig`, `templates/pax/app.html.twig`.
- **Abrir la operativa de una confirmada** → botón naranja de ruta en `FileDetalle` → `AbrirOperativaProcessor`. Traspasa las órdenes y congela la confirmada. Ver §6.j.3.
- **Que el cliente vea otro dinero del que guarda la fila** → `Cotizacion::origenFinancieroParaCliente()`, y **también** la consulta de portada de `CotizacionFilePublicProvider`. Ver §6.j.4.
- **Que el cliente pueda ver una propuesta** → `Cotizacion::$publicado`, **no** el estado. Ver §6.j.1.
- **Guardar el estado de una cotización antes de tocarla** → botón de cámara en `FileDetalle` → `GuardarHistoricoProcessor`. ⚠️ **No es clonar**: clonar crea la versión siguiente y hace perder las órdenes. Ver §6.j.
- **Cargar un padrón** → `app:cotizacion:importar-padron` / `PadronImportador` (§6.r). Idempotente; sincroniza pertenencias pero **no borra personas**; el ensayo escribe y deshace.
- **Cambiar las columnas del padrón (plantilla e importación)** → `PadronFormato`, una sola definición para generar y para leer. La plantilla se genera al vuelo desde los enums. Ver §6.n.
- **Quién ve a quién en un padrón** → `PasajeroTipoEnum` (dos ejes: `alcance()` y `esExpuesto()`) + `AlcanceDelPadron`. ⚠️ Los invitados son gratuidades de la agencia y **desaparecen para todos**. Ver §6.p.
- **Agrupar pasajeros (salón, grupo, habitación, reserva aérea)** → `CotizacionFileGrupo` + `CotizacionPasajeroGrupo` (§6.m). ⚠️ Ejes cruzados, no un árbol; y el `esJefe` va en la pertenencia.
- **El DNI o el pasaporte de un pasajero, con su vencimiento** → `CotizacionPasajeroIdentificacion`, una fila por documento (§6.l). ⚠️ Sin fecha es «sin comprobar», nunca «vigente».
- **Adjuntar un archivo a un expediente** → `CotizacionFilearchivo` (antes `…Filedocumento`, ver §6.k). ⚠️ No confundir con `CotizacionFilepasajero::$tipodocumento`, que sí es identidad.
- **Buscar en la bóveda, o mover un archivo de una persona a otra** → `bovedaDocs` / `bovedaIndexada` y `alcanceDelDoc()` en `FileDetalle.vue`. ⚠️ Los tres alcances son **excluyentes**: se resuelven juntos, nunca campo a campo. Reasignar no toca el fichero; para cambiar el fichero sigue habiendo que borrar y subir.
- **Que el vuelo de un boarding pass sea de esa persona** → `ofreceVuelo` (cuándo se ve Y cuándo se guarda: una sola regla) y `vuelosDelElegido` (cruza por PNR). ⚠️ En la API **no hay red**: `vuelaEseVuelo()` es privado de la carga por ZIP. Ver §6.k.
- **Quién puede ser dueño de un archivo** → `CotizacionFilearchivo::validarDuenoDelMismoExpediente()`, `PrePersist` + `PreUpdate`. Comprueba los **tres**: pasajero, grupo y vuelo.
- **Leer un documento de identidad escaneado** → `LectorDeDocumentoIdentidad` (dominio) sobre `LectorDeImagenInterface` (proveedor). Probar sin guardar: `app:cotizacion:leer-documento`. ⚠️ El modelo se fija en `AGENT_IA_VISION_MODELO`, **no** en los tramos de potencia: es capacidad, no cabeza.
- **Saber si un dato extraído es fiable** → `DatosDeDocumento::verificadoPorMrz()`. ⚠️ Tiene que **verse** en pantalla: con MRZ es aritmética, sin MRZ es sólo lo que dijo un modelo.
- **Cambiar cuándo un documento queda validado u observado** → `Cotejo::de()`, y **sólo ahí**. Es la única pieza que juzga, no tiene dependencias y está cubierta por tests: si el criterio cambia sin tocar esos tests, el criterio está mal.
- **Cambiar a quién se propone asociar un documento suelto** → `ValidadorDeDocumento::buscarDueno()`. ⚠️ Número antes que nombre, sólo dentro del expediente, y varios homónimos = no se elige.
- **Que el proceso escriba de verdad** → `app:cotizacion:validar-documentos --aplicar`. Crear fichas y asociar por nombre no los hace nunca.
- **Crear un servicio que no está en el catálogo** → botón «Manual» → `agregarComponente(id, true)` → `esManual`. Aporta su propio `nombreInternoSnapshot` (interno) y `tituloSnapshot` (público). Ver §6.h.
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

---

## vCard del expediente (28/08/2026)

La cabecera de «Datos del Expediente» lleva un botón **vCard** que descarga el contacto para
la agenda del móvil: `GET /cotizacion/files/{id}/vcard`.

Va en **esa** tarjeta y no en la barra de acciones de arriba porque lo que descarga es justo
lo que hay debajo —contacto, titular, país, idioma, estado—; la barra ya tiene las acciones
del expediente entero (copiar link, vista cliente, eliminar, nueva versión).

**Sin teléfono no se ofrece**: un contacto sin número no sirve de nada en una agenda.

El detalle de qué lleva dentro, por qué el nombre de agenda es `localizador · grupo` y por qué
no comparte código con la vCard de reservas está en `docs/Telefonos.md` §5.

⚠️ El endpoint vive en `src/Cotizacion/Controller/Api/`, que **hubo que declarar en
`config/routes.yaml`**: era el primer controlador del módulo fuera de API Platform y sin esa
línea las rutas no existen — 404 mudo, no error.

## El dashboard de expedientes (30/08/2026)

### ⚠️ `archivado` es LO BUENO y `cerrado` LO MALO — estaba al revés

`FileEstadoEnum` decía «Cerrado (Ganado)» y «Archivado (no venta)». En el chat
(`MessageConversation`) es justo al contrario: `archived` es el ex-cliente cuya estancia terminó
y `closed` lo pone `isCancelled()`.

O sea que **la misma palabra nombraba el mejor y el peor resultado según la pantalla**. Un
operador —o el agente, o quien lea la base dentro de un año— no podía saber si «cerrado» era una
venta hecha o una reserva cancelada sin mirar de qué módulo venía.

Se invirtió el **significado**, no los valores, y **se pudo porque no había ni una fila usando
ninguno de los dos**: los seis expedientes de producción estaban todos en `abierto`. Con datos
dentro habría hecho falta migrarlos, y el fallo habría sido mudo — cada expediente ganado
pasando a perdido sin que nada avisara.

🔁 **Espejo:** `FileEstadoEnum::getLabel()` ↔ `ESTADO_FILE_LABELS` y `ESTADO_FILE_CONFIG` en
`util/src/types/cotizacionEditorModel.ts`. Si se toca uno, se toca el otro.

#### Y la COTIZACIÓN tenía la otra mitad del choque: su `archivado` → `cerrado` (30/08/2026)

Arreglar el expediente dejó las dos palabras a un nivel de distancia: `FileEstadoEnum::ARCHIVADO`
= venta ganada, y `CotizacionEstadoEnum::ARCHIVADO` = la propuesta que se aparta **sin** vender.
Conviven en la misma pantalla —el expediente arriba, sus versiones debajo—, así que «archivado»
seguía sin decir si algo había salido bien: había que mirar a qué fila pertenecía.

`CotizacionEstadoEnum::ARCHIVADO` pasa a **`CERRADO = 'cerrado'`**, que es lo que el chat y el
expediente ya llaman a lo que no prosperó. El significado no cambia en nada: sigue cancelando las
filas de operación por `CotizacionConfirmadaEventListener`, exactamente igual que antes.

Aquí sí se renombró el **valor guardado**, no sólo la etiqueta, y por eso lleva migración
(`Version20260830060000`). En producción no había ni una fila en ese estado —los once registros
estaban en enviado, confirmado, operado e histórico—, pero la migración va igual: el `UPDATE`
tiene que existir para cualquier entorno que sí las tenga.

⚠️ **La migración es SQL a propósito.** Pasarlo por el ORM dispararía el listener, que al ver un
cambio en `estado` volvería a cancelar las filas de operación de esas cotizaciones — trabajo ya
hecho el día que entraron en el estado, y que reabriría el historial de cada fila sin motivo.
Aquí no cambia el estado: cambia la palabra.

🔁 **Espejo:** `CotizacionEstadoEnum` ↔ `ESTADO_COTIZACION_CONFIG` (`cotizacionEditorModel.ts`).
Ese `Record<CotizacionEstadoValue, …>` está anclado al esquema generado, así que **al renombrar el
caso el typecheck falla hasta que se regenera `api.d.ts` y se corrige la clave**: es el único
sitio de este cambio donde no hacía falta acordarse de nada.

**Cómo queda el vocabulario, en los tres módulos:**

| | Vivo | Salió bien | Salió mal |
|---|---|---|---|
| Chat (`MessageConversation`) | `open` | `archived` | `closed` |
| Expediente (`FileEstadoEnum`) | `abierto` | `archivado` | `cerrado` |
| Cotización (`CotizacionEstadoEnum`) | `pendiente`/`enviado` | `confirmado`/`operado` | **`cerrado`**/`cancelado` |

### Arranca acotado a los abiertos

El dashboard traía todo y ordenaba por fecha de creación, así que un expediente ganado en marzo
empujaba hacia abajo los que hay que mover hoy. Ahora nace con `estado=abierto` y cuatro pestañas
—Abiertos · Ganados · No venta · Todos—.

⚠️ **El filtro es del SERVIDOR** (`SearchFilter` sobre `estado`), no del cliente. Filtrando en el
cliente la paginación traería veinte y enseñaría tres, y «cargar más» pediría la página siguiente
de una lista que no es la que se está viendo.

### Cada versión enseña su estado y su título

`versionesFechas` llevaba sólo `version` y `fechaInicio`, así que un expediente con tres
propuestas —una confirmada, una cancelada y un histórico— se leía **igual** que uno con tres
pendientes: el dato que dice si hay algo que hacer no estaba.

`CotizacionFileCollectionProvider` manda ahora también `estado` y `titulo` en la misma consulta
batched. **Sigue siendo `getArrayResult()`**: escalar, sin hidratar ni una `Cotizacion` — que es
el motivo por el que este provider existe.

**Agrupa por `c.id` y no por `c.version`**: así los dos campos dependen funcionalmente de la
clave y MySQL los acepta en el `SELECT` sin meter una columna JSON en el `GROUP BY`.

⚠️ **Y ese cambio de agrupación tiene una consecuencia que hay que conocer: la VERSIÓN NO ES
ÚNICA dentro del expediente.** Un `historico` comparte número con la viva a propósito —es su foto
congelada antes de tocarla—, así que un expediente puede tener dos filas «V1». Agrupando por
`c.version` se fundían en una y se perdía justo la distinción que este cambio venía a dar;
agrupando por `c.id` salen las dos, que es lo correcto.

Por eso cada versión viaja con su **`id`**, y es la clave del `v-for`. Con `version` de clave
habría dos claves iguales en el mismo `v-for` — Vue no lo trata como error y el síntoma sería una
fila que no se repinta al cambiar. Hoy le pasa a `2KVBMX`.

El título viaja como **i18n crudo** y lo resuelve el panel. En `util` se toma siempre el
**español**: es el panel del operador y no tiene selector de idioma — leer ahí un título en
francés porque el cliente es de Lyon no ayuda a quien barre su cartera.

Se pintan en **filas y no en pastillas**: el título es texto libre y de largo variable, y
apretado en una pastilla se recortaba a dos palabras, que es dejar de distinguir una versión de
otra justo para lo que sirve.

### Y su TRAMO: del primer servicio al fin del viaje (30/08/2026)

Con sólo la fecha de salida, dos expedientes que empiezan el mismo día se leían idénticos aunque
uno fuera de tres días y el otro de tres semanas. La pregunta que se le hace a esta lista al
barrerla es **cuándo viaja esta gente**, y esa pregunta tiene dos puntas.

`versionesFechas` lleva ahora `fechaFin` además de `fechaInicio`.

#### ⚠️ `fechaFin` NO es `MAX(fechaInicioAbsoluta)`, y tampoco `MAX(fechaHoraFin)`

Las dos formas obvias fallan, cada una por un lado:

| Candidato | Qué se le escapa |
|---|---|
| `MAX(s.fechaInicioAbsoluta)` | Un viaje que acaba en un **checkout sin servicio propio**: el último bloque del itinerario es la víspera, y el tramo sale un día corto |
| `MAX(k.fechaHoraFin)` en bruto | El **traslado de las 22:00 que acaba a las 00:30**: adelanta el fin un día entero. *Cruzar medianoche no es durar dos días* |

Se toma el mayor de los dos, con el fin de componente acotado a **estadías**:

```sql
LEFT JOIN s.cotcomponentes k
     WITH k.sinHorario = true AND DATE_DIFF(k.fechaHoraFin, k.fechaHoraInicio) > 0
```

Ese `WITH` es la condición **exacta** de `esEstadia`, que ya estaba escrita dos veces en el repo:
en `PaxCotizacionGuiaView` (`!horaInicio && !horaFin && finPeriodo > base`) y en el chequeo
`multidia-sin-noche` de `CoherenciaCatalogoChecker`, donde su ausencia hacía saltar 7 falsos
positivos. Es la misma regla, tercera vez — si cambia, cambian las tres.

⚠️ **Medido el 30/08/2026 sobre los datos reales: hoy los dos candidatos coinciden en las ocho
cotizaciones que existen.** La rama de la estadía no se ha llegado a usar nunca. Está porque el
caso que cubre —checkout sin traslado de salida— es normal en un viaje, no exótico, y porque el
día que ocurra el síntoma sería una fecha un día corta: nadie la notaría.

#### El `WITH` no multiplica el resultado

`MIN`/`MAX` son inmunes a las filas duplicadas que introduce el join, así que la consulta sigue
siendo **una sola** para toda la página del dashboard. El `getArrayResult()` no cambia: escalar,
sin hidratar ni una `Cotizacion`.

⚠️ **Las tres agregaciones llegan como cadena, no como `DateTimeImmutable`.** `getArrayResult()`
no aplica la conversión de tipo de Doctrine a lo que sale de un `MIN`/`MAX`, así que
`fechaHoraFin` viene tal cual (`'2026-09-15 18:30:00'`). Las normaliza `soloFecha()`, que conserva
la rama del `DateTimeInterface` porque esa conversión depende de plataforma y driver — perderla
convertiría un cambio de versión en un `fechaFin` mudo a `null`.

#### En pantalla: el tramo va DEBAJO del título

```
V1  Viaje a Punta Cana 5D 4N              [ENVIADO]
    24 ago. – 28 ago. 2026
```

Al lado del título no cabe: el título es texto libre y ya se trunca, así que una fecha a su
derecha le quita justo las palabras que lo distinguen de la versión de arriba. Cuando **no** hay
título, el tramo ocupa su sitio — que es lo que se veía antes, sólo que con las dos puntas.

⚠️ **El año se escribe una sola vez** cuando las dos fechas caen en el mismo. Y la punta
izquierda se compone **por partes** (`Intl.DateTimeFormat.formatToParts`), no con
`toLocaleDateString(…, { day, month })`: sin año, el patrón de `es-PE` une con **guion**
—`31-ago.`— y el tramo salía escrito en dos formatos distintos, con el guion peleando además con
la raya del propio rango.

#### El tipo se dejó de escribir a mano

`ApiCotizacionFile.versionesFechas` estaba declarado como `{ version, fechaInicio }[]` y ya se
había quedado corto: desde que viajan `id`, `estado` y `titulo`, la plantilla tenía que castear a
`VersionDelFile`. Ahora **reutiliza ese mismo tipo** en vez de reescribirlo, y el cast desaparece.

El estrechamiento sigue siendo legítimo —`getVersionesFechas()` devuelve un `array` y OpenAPI lo
exporta como diccionario abierto, motivo 1 de la regla de CLAUDE.md—, pero se ancla a una sola
declaración. Los docblocks de `CotizacionFile` prometían dos claves de las seis reales; ahora
declaran la forma entera.

### El icono del expediente decía siempre «Abierto»

Estaba escrito a mano, verde y fijo: un expediente perdido se veía idéntico a uno vivo. Ahora
sale de `ESTADO_FILE_CONFIG` — verde lo vivo, azul lo ganado, gris lo perdido.

---

## Subir un documento de identidad: dos fricciones quitadas (11/09/2026)

Las dos salieron del mismo gesto —subir el pasaporte de un grupo, persona por persona— y ninguna
era un fallo: eran dos convenciones que no se aplicaban donde tocaba.

### 1. El nombre del documento ya no se pide para un escaneo de identidad

El campo `nombre` era obligatorio y su marcador de posición decía *«Ej. Entrada Machupicchu»*:
está pensado para lo que sube el operador a mano y hay que distinguir de un vistazo. En un
pasaporte no distingue nada —el tipo ya lo dice, y tras el OCR el sistema sabe de quién es—, así
que era un campo obligatorio en el paso **más repetitivo** del expediente.

Ahora, si el tipo es un escaneo de identidad, el nombre es opcional y el servidor lo rellena con
la etiqueta del propio enum (`Pasaporte (escaneo)`, `DNI — anverso`).

⚠️ **Quién decide que «es de identidad» ya existía: `ArchivoTipoEnum::esEscaneoDeIdentidad()`**,
la misma pregunta que responde para la compresión de alta fidelidad. No se abrió una segunda
lista que mañana diga otra cosa. Ojo con no confundirla con `respaldaA()`, que es **más
estrecha**: deja fuera el reverso del DNI y la autorización, que no llevan número que cotejar
pero sí son documentos de identidad.

⚠️ **Se rellena en el SERVIDOR, no sólo en el formulario.** El front deja de exigirlo, pero un
POST directo a la API lo dejaría vacío y un archivo sin nombre se ve como una fila en blanco en la
bóveda. Lo hace `CotizacionFilearchivoMultipartProcessor::nombrarSiEsDeIdentidad()`, y **sólo si
viene vacío**: quien escriba «Pasaporte de la madre» manda.

El front tiene su espejo en `ARCHIVO_TIPOS_DEL_PASAJERO` (`util/src/types/fileDetalleModel.ts`),
que ya listaba los mismos cuatro. **Si entra un tipo nuevo en el enum, entra también ahí.**

> 🚧 Pendiente que esto destapa: `nombre` lleva `#[AutoTranslate]`, así que cada escaneo de
> identidad **gasta una traducción a siete idiomas** de un texto que nadie lee — es un adjunto
> interno que ni siquiera se le devuelve al pasajero (`esDevolvibleAlPasajero()` es `false`).

#### ⚠️ Y dos cosas que salieron al revisar el cambio, no al escribirlo

**`CotizacionFilearchivo::getNombre(): array` devolvía `null`.** La propiedad es `?array` y el
getter no lo contemplaba: sobre un archivo sin nombre era un `TypeError`, o sea un 500. Y `null`
no es un caso raro — la columna es `nullable`, el formulario sólo manda `nombre[0][...]` si el
operador escribió algo, y desde este cambio los escaneos de identidad se suben **a propósito sin
nombre**. Nadie lo veía porque nadie preguntaba por el nombre de un archivo recién subido: PHPStan
no marca ese `return.type` dentro de `Entity/` y no había test de esa ruta.

Se arregla haciendo verdad el tipo declarado (`?? []`), no ensanchando la firma: quien lee un
nombre quiere una lista de traducciones, y «no hay ninguna» es una lista vacía. Ensancharlo a
`?array` habría repartido el `null` por los diez sitios que lo consumen.

**El procesador estaba sólo en el `Post`.** Crear un pasaporte lo dejaba llamado «Pasaporte
(escaneo)» y **editarlo borrando el nombre lo dejaba sin ninguno**: la misma pantalla con dos
resultados según por dónde entres. Ahora el `Patch` usa el mismo procesador — su parte de subida
no estorba, comprueba si viene el fichero antes de tocarlo.

Las dos están cubiertas por `tests/Cotizacion/State/NombrePorConvencionTest.php`, que prueba
justamente el archivo **sin** nombre.

### 2. Los nombres del escaneo se capitalizan, con cotejo

Un pasaporte imprime `RAY DANTE DIAZ ARREDONDO`. La app muestra nombres capitalizados. El lector
los guardaba en mayúscula porque su instrucción dice, a propósito, **«Transcribe lo que VES. No
corrijas»** — y eso no se toca: la transcripción era correcta.

La caja es una **convención de presentación, no una lectura**, así que se pide aparte. La misma
llamada a Gemini que ya lee el documento devuelve ahora `nombresCapitalizados` y
`apellidosCapitalizados`; los campos crudos siguen diciendo lo que dice el papel.

Sale gratis —es el mismo viaje— y es el mismo argumento con el que se resolvió la capitalización
de los nombres del PMS. Por eso **comparten guardián**: `OrdenDelNombre::conLaCajaBuena()` pasó de
`private` a público.

⚠️ **El cotejo es lo que hace esto aceptable.** Comprueba con `Transliterator` que sean las MISMAS
letras y descarta la propuesta entera si difiere en algo más que caja y tildes. Sobre un escaneo
importa **más** que en el PMS, no menos:

| el documento dice | el modelo propone | qué pasa |
|---|---|---|
| `DIAZ ARREDONDO` | `Diaz Arredondo` | se aplica |
| `JOSE PEREZ NUNEZ` | `José Pérez Núñez` | se aplica — la tilde es caja |
| `B0UZA` (cero por O) | `Bouza` | **se descarta** |
| `MART1NEZ` (uno por I) | `Martínez` | **se descarta** |
| `JOHN` | `Juan` | **se descarta** |

Los dos rechazos del medio son el fallo típico del OCR. Sin el cotejo, una propuesta «bonita»
taparía el error de lectura justo donde nadie va a volver a mirar.

Y hay un efecto de borde que sale bien solo: si la MRZ ganó la partida —`preferir()` devolvió su
versión— la propuesta se descarta sola, porque la MRZ va en ASCII sin tildes y casi nunca casará.
Es el lado correcto: la MRZ está ahí precisamente porque lo impreso no se leyó.

Cubierto por `tests/Cotizacion/Documento/CapitalizacionDelEscaneoTest.php`, que prueba el
guardián y no el lector — el lector no decide nada y no se puede ejercitar sin llamar al modelo.

### Dónde tocar

| Necesidad | Archivo | Método |
|---|---|---|
| Que un tipo nuevo cuente como escaneo de identidad | `src/Cotizacion/Enum/ArchivoTipoEnum.php` | `esEscaneoDeIdentidad()` **y** el espejo `ARCHIVO_TIPOS_DEL_PASAJERO` |
| Cambiar el nombre por convención | `CotizacionFilearchivoMultipartProcessor` | `nombrarSiEsDeIdentidad()` |
| Cómo se le pide la caja al modelo | `LectorDeDocumentoIdentidad` | `INSTRUCCION` + el esquema |
| Qué propuestas se aceptan | `src/Pms/Nombre/OrdenDelNombre.php` | `conLaCajaBuena()` |

---

## Guardar un pasajero recargaba el expediente entero (11/09/2026)

Reportado como «cada vez que guardo un ítem del manifiesto carga toda la página». Las dos mitades
del problema eran distintas y se arreglaron por separado.

### La pantalla se quedaba en blanco

`cargarFile()` ponía `isLoading = true` siempre, y el `v-if="isLoading"` del `<main>` **sustituye
todo el contenido por un spinner**. Con **doce** sitios llamando a `cargarFile()`, el expediente
desaparecía y volvía por cualquier cosa.

Ahora sólo tapa la pantalla si no hay nada pintado todavía (`!file.value['@id']`). La regla se
mantiene sola: ningún llamador tiene que acordarse de pasar un flag. Los refrescos posteriores
enseñan una franja «Actualizando el expediente…» que **ocupa alto propio** — una cinta superpuesta
taparía la primera fila justo cuando se mira qué cambió.

⚠️ El indicador no es decorativo: sin él, un refresco de cuatro segundos se lee como que ya
terminó, y alguien edita encima de datos viejos.

### Y el viaje no hacía falta

`/platform/sales/cotizacion_files/{id}` pesa **717 KB de media y hasta 4,3 MB**, con 1,69 s de
servidor y picos de 8,44 s (log de tiempos de nginx, 10/09/2026). Con «Guardar y siguiente» eso
ocurría **una vez por persona**: en un grupo de treinta, treinta viajes para cambiar un apellido.

`fileStore.updatePassenger()` devolvía `boolean` y tiraba la respuesta del PATCH, así que quien
llamaba no tenía más remedio que recargar. Ahora devuelve el pasajero guardado y
`sustituirPasajero()` lo cambia en la lista.

**Por qué es seguro:** el PATCH normaliza con `file:item:read`, la misma forma con la que el
pasajero viaja dentro del expediente — comprobado que no hay ningún campo suyo en `file:read` que
no esté también en `file:item:read`. Y todo lo derivado (contadores, alertas, agrupaciones) sale
de computeds sobre `file.value.filepasajeros`, así que sustituir el elemento los recalcula.

Tres decisiones que sostienen esto:

1. **Sólo al EDITAR.** Crear cambia *la lista* —aparece una persona, y de ella dependen el orden,
   los contadores y a quién salta «Guardar y siguiente»—, así que la creación sigue recargando.
2. **Se empareja por UUID, no por índice ni por `@id` a secas.** La lista se reordena por grupo y
   por coordinador, y `abrirEdicionPax()` ya contempla que `@id` pueda faltar.
3. **Si no encuentra a quién sustituir, recarga.** Devolver `false` y caer en `cargarFile()` es un
   viaje de más; quedarse callado sería una ficha enseñando lo de antes, que es peor y mudo.

⚠️ **Lo que esto NO arregla:** el endpoint sigue pesando lo que pesa, y los otros once llamadores
siguen recargándolo entero. Eso está anotado en `docs/Pendientes.md` con el trabajo del endpoint —
aquí se arregló lo que se ve, no lo que pesa.

---

## El sobre de documentos para el hotel (11/09/2026)

Un alojamiento pide los escaneos de identidad de los huéspedes antes de la llegada —en Perú los
necesita para el registro—. Se hacía bajando los ficheros de la bóveda uno a uno y renombrándolos
a mano: con un grupo de treinta, media tarde y un reparto que se tuerce.

`PaqueteDeEscaneos` arma un ZIP; el botón «Enviar al hotel» está junto al de la hoja, y respeta
los filtros de la pantalla igual que ella (mandando la lista de ids resuelta, no repitiendo los
filtros en el servidor).

### La convención del nombre, que NO es la de entrada

    Diaz Arredondo, Ray Dante - PAS 125998545.jpg
    Quispe Mamani, Ana - DNI 46523178.jpg

Al **subir** por lote la convención es `DOCUMENTO-VUELO` y su docblock explica por qué el número y
no el nombre: «el nombre trae tildes, se escribe en otro orden y se repite; un DNI no». Ahí el
lector es una **máquina** que tiene que casar sin ambigüedad.

Aquí el lector es **una persona en recepción** buscando un apellido en una lista, así que el orden
se invierte: el apellido delante hace que el ZIP salga **ordenado por persona** al abrirlo, que es
como se recorre. El número sigue porque es lo único que no se repite, y el prefijo (`PAS`, `DNI`,
`DNI-rev`) distingue a quien manda los dos.

⚠️ **Sin tildes ni «ñ».** Un ZIP recorrido en Windows con las herramientas del sistema todavía
interpreta los nombres en CP437: `Núñez` llega como `NÃºÃ±ez`. Se translitera con la misma pieza
que usa el resto del proyecto.

El número sale de la identificación que **ese** escaneo respalda (`ArchivoTipoEnum::respaldaA()`,
la única definición de esa pareja). Si la persona no lo tiene cargado, el fichero se queda sin
número en vez de coger otro: un pasaporte etiquetado con el número del DNI es peor que uno sin
número.

### ⚠️ Sin comprimir, y está medido

Un JPEG ya está comprimido. Sobre los 374 escaneos del expediente más grande (104 MB en disco):

| | tiempo | tamaño |
|---|---|---|
| `CM_DEFLATE` | **4,2 s** | 107,4 MB |
| `CM_STORE` | **0,5 s** | 107,7 MB |

Casi cuatro segundos de CPU por el **0,3 %**, con php-fpm cortando a los 90 s. Los escaneos van
con `CM_STORE`; la hoja y el `LEEME.txt` sí se comprimen, que son texto.

> La preocupación de partida —«374 imágenes no caben en 90 segundos»— resultó infundada: son
> medio segundo. Pero sólo se supo midiendo, y la medición cambió el diseño igual.

### Lo que NO entra, y se dice dentro

Un escaneo **sin pasajero asignado** no se puede nombrar. En vez de meterlo con su nombre original
—que es como el documento de alguien acaba en el sobre de otro— se queda fuera y sale contado en
`LEEME.txt`, junto a la lista de quién no tiene ningún documento.

⚠️ **Los huecos se cuentan DENTRO del paquete a propósito.** Quien lo reenvía al hotel no va a
volver al panel a comprobar quién falta: si falta alguien tiene que enterarse abriendo lo único
que va a abrir.

Dentro va también `manifiesto.xlsx`, el mismo `ReporteDeDocumentos` que ya existía — es lo que
convierte un montón de imágenes en algo que el hotel puede cotejar, y no costaba nada.

### Que esto saca pasaportes de casa

| | |
|---|---|
| Permiso | `RESERVAS_WRITE`, el mismo que guarda el manifiesto. No es enlace público ni firmado |
| Caché | `no-store` |
| El temporal | `deleteFileAfterSend()` — sin eso queda un sobre con documentos en `/tmp` |
| Cómo se sirve | `BinaryFileResponse`, no un string: 107 MB en memoria es pedir un `memory_limit` |

Y **no** se le devuelve al pasajero: `ArchivoTipoEnum::esDevolvibleAlPasajero()` explica por qué su
propio escaneo no baja a su móvil. Ésta es la vía del operador hacia el alojamiento, que es otra
cosa y tiene otro dueño.

### Dónde tocar

| Necesidad | Archivo | Método |
|---|---|---|
| Qué tipos se PUEDEN mandar | `PaqueteDeEscaneos` | `TIPOS` — pasaporte y DNI (las dos caras); la autorización notarial no, es un permiso, no una identidad |
| Cuáles se mandan de verdad | lo elige quien descarga | `tiposPedidos()` los valida contra `TIPOS` |
| Cómo se llaman los ficheros | `PaqueteDeEscaneos` | `nombreEnElZip()` |
| Lo que se cuenta en el sobre | `PaqueteDeEscaneos` | `leeme()` |
| Permisos y cabeceras | `PaqueteEscaneosController` | `__invoke()` |

---

## Lo que la revisión encontró y las herramientas no (11/09/2026)

Dos fallos introducidos el mismo día en que se «arreglaron» esas pantallas. PHPStan, PHPUnit,
`vue-tsc` y ESLint pasaron limpios con los dos dentro.

### El `v-else` se enganchó al hermano equivocado

Al meter la franja de «Actualizando el expediente…» **entre** el spinner y el contenido:

```html
<main v-if="isLoading">spinner</main>
<div  v-if="refrescando">franja</div>   ← insertada aquí
<main v-else>contenido</main>           ← su v-else pasó a ser el de la FRANJA
```

`v-else` se engancha al hermano inmediatamente anterior con `v-if`. Resultado, **lo contrario de
lo que el cambio pretendía**: en la primera carga se pintaban spinner y contenido a la vez, y en
cada refresco desaparecía el `<main>` entero — el blanqueo seguía, en los once llamadores que aún
recargan.

Se arregla escribiendo la condición (`v-if="!isLoading"`) en vez de heredarla. Con la condición
escrita, insertar algo en medio deja de importar.

⚠️ **No lo ve nada.** `vue-tsc` no analiza el emparejamiento, y `vue/valid-v-else` tampoco protesta
porque sí hay un `v-if` delante — el marcado es válido, sólo que dice otra cosa. Es la misma
familia que el aviso de CLAUDE.md sobre atributos mal puestos: **el fallo no es un error, es un
cambio de significado.**

### Un `null` que el serializador rechaza antes de llegar al procesador

`CotizacionFilearchivo::setNombre(array $nombre)` no admite `null`, y el formulario mandaba
`nombre: docForm.nombre ? [...] : null` al editar. Ese `null` era **inalcanzable** mientras el
campo fue obligatorio; en cuanto un escaneo de identidad pudo guardarse sin nombre, vaciarlo pasó
a ser un camino normal — y devolvía un 400.

O sea: el procesador se añadió al `Patch` para nombrar por convención al editar, y desde la
pantalla **nunca llegaba a ejecutarse para el caso que lo justificó**. Ahora se manda `[]`.

> **La lección de método:** el test de `NombrePorConvencionTest` estaba verde y ejercitaba la
> pieza correcta. Lo que no ejercitaba era el serializador, que es donde moría la petición. Un
> test verde sobre la unidad no dice que la pantalla funcione.

### Y una que resultó no aplicar

Se sospechó que el caché de metadata de API Platform pudiera sobrevivir a `cache:pool:clear`
porque `cache.system` encadena APCu, y un clear desde CLI no vacía el APCu de php-fpm. **En este
servidor APCu no está instalado** (`php -m` no lo lista, no hay `conf.d`), así que el pool es
PhpFiles y el clear basta. Queda escrito porque la hipótesis era razonable y alguien la volverá a
tener.

---

## El botón «Arajet» no existe como dato (11/09/2026)

En el filtro del manifiesto hay una pastilla **Arajet** y otra **Copa Airlines**. No hay ningún
campo «aerolínea» en ninguna parte:

```js
porTramo.get(tramo).add(String(g.nombre));   // un Set de cadenas
```

La pastilla sale de que `CotizacionFileGrupo::$nombre` esté **escrito igual** en los ocho
subgrupos de esa aerolínea. Un `Set` colapsa las repetidas; ocho PNR con `Arajet` dan una entrada.

⚠️ **Es una coincidencia de texto, no una agrupación modelada.** Escrito `ARAJET` en uno y
`Arajet` en otro salen **dos pastillas**, y ninguna de las dos trae a toda la gente de esa
aerolínea. El campo es texto libre, está marcado como opcional, y nada avisa. En los diez
servicios del padrón de Punta Cana el `nombre` está **vacío**, así que ahí no agrupa nada — no es
que servicios funcione distinto, es que nadie rellenó el campo del que depende.

### Las tres vistas del mismo dato, que se confunden

| dónde | qué agrupa |
|---|---|
| Lista de subgrupos | **nada** — una fila por subgrupo (un PNR cada una) |
| Cabecera de sección | `getEtiquetaDeEje()` = `tipo` + `subeje` → «Vuelo Internacional (13)» |
| Pastillas del filtro | `nombre`, dentro de cada `subeje` |

Por eso la lista enseña `54X6ZM · Arajet · 2 pax` y `JA2CWN · Arajet · 24 pax` como filas
distintas, y el filtro los junta en un botón. Son dos preguntas distintas: «qué reservas hay» y
«de qué aerolínea es la gente».

### Lo que se hizo, y lo que NO

Se escribió en la interfaz. Cada campo del formulario de subgrupos lleva una «i» que dice qué hace
**en el eje que se está usando** —el sufijo se llama Tramo en un vuelo, Hotel en una habitación y
Matiz en un servicio, y ya lo hacía—, y la del **Nombre** va destacada y además repetida bajo el
campo, porque es la única cuyo efecto no se ve en ningún sitio. También en el modal de corregir,
que es donde alguien reescribe un nombre y lo separa del resto sin enterarse.

**No se tocó el modelo.** Agrupar de verdad pide o un maestro de aerolíneas o normalizar al
guardar y sugerir las que ya existen en el expediente. Decisión aplazada a propósito: escribirlo
convierte una trampa invisible en una regla que se puede seguir, y eso era lo que hacía falta hoy.

> 🚧 Pendiente relacionado: **la pertenencia sólo sabe decir «sí»**. No hay forma de distinguir «a
> éste se le ofreció el servicio y NO va» de «todavía no se le ha asignado», y son cosas distintas
> al armar lo que se le manda a un proveedor.

---

## La negación del filtro de subgrupos (11/09/2026)

La pregunta que llega de un proveedor casi nunca es «dame los del Coco Bongo»: es «los del Coco
Bongo **que no** lleven traslado», o «los que **no** están en ningún vuelo nacional». Eso se
resolvía descargando todo y descartando a mano — justo el trabajo que el filtro existe para
quitar.

Ahora una pastilla de subgrupo ya elegida se toca y se invierte. La negada se pinta en rojo y
**lleva «SIN» delante**.

⚠️ **La palabra no sobra por tener el color.** Una captura reenviada por WhatsApp llega en
cualquier contraste, y confundir «los del Coco Bongo» con «los que NO van» es mandarle al
proveedor la lista contraria. El color se pierde; la palabra no.

### La semántica: positivos O, negaciones Y

| | regla | por qué |
|---|---|---|
| Positivos, mismo eje | **O** | «HA01 o HA02»: nadie está en dos habitaciones |
| Positivos, ejes distintos | **Y** | «Coco Bongo **y** vuelo internacional» |
| **Negaciones** | **Y, siempre** | Son restricciones, no alternativas |

⚠️ **Las negaciones NO siguen la regla del eje, y no es un descuido.** Meterlas en el mismo O daría
«que le falte alguno de los dos», que es casi todo el mundo. Comprobado con seis personas de
ejemplo antes de escribirlo:

```
los del Coco Bongo                     → Ana, Beto
los que NO van al Coco Bongo           → Cira, Dani, Eva, Fito
Coco Bongo SIN traslado                → Beto
ni Coco ni traslado (dos negados)      → Dani, Eva, Fito
HA01 o HA02 (positivos, mismo eje)     → Eva, Fito
```

La tercera línea es la que justifica todo esto, y la cuarta la que se habría torcido con la regla
del eje.

### ⚠️ Esto NO es el campo «no tiene»

Esta negación contesta **«no está en el subgrupo»**, lo que incluye a quien **todavía no se le ha
asignado**. Un campo de negación explícita —«se le ofreció y dijo que no»— sigue sin existir: la
pertenencia sólo sabe decir «sí».

Para armar lo que se le manda a un proveedor, esta negación basta. Para saber a quién ya se le
preguntó, no — y son dos preguntas que se parecen lo suficiente como para confundirlas.

### Dónde tocar

| Necesidad | Dónde |
|---|---|
| Invertir una pastilla | `alternarNegado()` |
| La regla de combinación | el `computed` de `pasajerosFiltrados`: `porEje` vs `negados` |
| Que la exportación la respete | nada: la hoja y el ZIP mandan `pasajerosFiltrados` ya resuelto |

---

## Tres correcciones del sobre, salidas de usarlo (11/09/2026)

### El botón decía a quién mandarlo

Se llamaba **«Enviar al hotel»**. El ZIP se le manda a quien lo pida —un hotel, la discoteca del
Coco Bongo, una aerolínea—, y el nombre lo estrechaba a un destinatario: hacía dudar de si servía
para los demás. Ahora dice **«Descargar escaneos»**, que es lo que hace; a quién se le manda lo
decide quien descarga.

### No se podía elegir qué documentos van

`TIPOS` era una constante fija: pasaporte + DNI anverso + DNI reverso, siempre los tres.

⚠️ **No es comodidad, son documentos de identidad de terceros.** Un hotel pide el documento; una
discoteca que exige mayoría de edad, sólo el que lleva la fecha de nacimiento; una aerolínea, el
pasaporte. Mandar los tres siempre es enviarle a alguien el DNI de una persona que sólo pidió el
pasaporte.

El botón abre ahora un panel con las tres casillas, **las tres marcadas de salida** para que no
cambie el resultado de quien no se pare a elegir. Y el `LEEME.txt` dice qué se incluyó: quien
recibe el sobre no sabe qué se dejó fuera, y «no está el DNI» se lee como un olvido si no pone que
fue a propósito.

⚠️ **La lista de tipos se valida contra `TIPOS` en el servidor** (`tiposPedidos()`). Llega del
cuerpo de una petición: sin filtrar, pedir `["factura"]` sacaría de la casa las facturas del
expediente por un endpoint pensado para identidades. Lo que no está en la lista se ignora; si no
queda nada, se mandan todos.

Detalle de implementación: con tipos elegidos hay que ir por `POST` aunque no haya filtro de
gente, porque un `GET` no lleva cuerpo. Por eso, sin filtros pero con selección, se mandan los ids
de todo el mundo.

### La negación no se encontraba

Funcionaba, pero sólo aparece **después** de añadir un subgrupo desde el desplegable: hasta
entonces no hay ninguna etiqueta que invertir, así que había que descubrirlo por casualidad. Ahora
lo dice una línea encima de las etiquetas — «Toca una etiqueta para invertirla: **SIN** = los que
NO lo tienen».

### Y las «i» no hacían nada en el móvil

Se pusieron con el atributo `title`: tooltip de escritorio, que necesita **hover**. Esta app se usa
desde el teléfono. Ahora las «i» son botones que despliegan el texto bajo su campo; el `title` se
queda para escritorio.

---

## La hoja del sobre y la cuenta que no cuadraba (12/09/2026)

### Dentro iba el informe de control, y no era lo que tocaba

El ZIP llevaba `manifiesto.xlsx`, o sea `ReporteDeDocumentos` — el informe de CONTROL: quién ha
subido qué, qué falta, qué observó la validación. Se metió porque ya existía y salía gratis, y eso
era justamente el error.

⚠️ **Quien recibe el sobre no tiene nuestro problema.** Necesita cotejar los ficheros que le
llegaron con una lista de personas, números y caducidades. Mandarle además nuestras observaciones
internas —«nombre no coincide», «sin comprobar»— es enseñarle dudas sobre sus propios huéspedes que
no le tocaba resolver, y de paso le entierra el dato que sí buscaba.

Ahora va `documentos.xlsx` ({@see ListaDelPaquete}): **Apellidos · Nombres · Documento · Número ·
Vence · Archivo**, y nada más.

Dos decisiones que la hacen contable:

- **Una fila por DOCUMENTO, no por persona.** Quien lleva pasaporte y DNI sale dos veces. Así
  **una fila = un fichero** y la lista se puede contar contra el ZIP. Con una fila por persona y
  columnas por tipo, la cuenta sólo cuadraba si nadie tenía dos documentos.
- **Sólo de los tipos que se piden.** Si se manda únicamente el pasaporte, la hoja no menciona el
  DNI: una columna vacía en un documento que sale de casa se lee como un dato que falta, no como
  uno que no se pidió.

Y las filas se apuntan **mientras se añaden los ficheros al ZIP**, no recorriendo el expediente
otra vez: recalcularlas aparte sería una segunda implementación de «qué va dentro», y la que se
quedara corta mentiría.

⚠️ El número va como TEXTO en la celda. Un DNI peruano empieza por cero más veces de las que
parece, y Excel se lo come: `08123456` llega como `8123456` y ya no casa con nada.

### El botón prometía personas y entregaba ficheros

Decía **«Descargar escaneos (124)»** — las personas del filtro. Pero quien no ha subido su foto
cuenta como persona y **no** como fichero, así que el ZIP traía menos. El que lo recibía contaba y
no le cuadraba, y el que lo mandó no podía saberlo hasta abrirlo.

Ahora el panel enseña, junto a cada casilla, **cuántos escaneos de ese tipo hay de verdad** en la
selección actual, el botón dice el total de ficheros, y el nombre del ZIP lleva ese mismo número —
se reenvía sin abrirlo, y `documentos-124` sobre un sobre de 117 es una promesa que alguien va a
contar.

Se calcula en el front sobre `filearchivos`, que ya está cargado: no hace falta preguntar al
servidor qué vas a mandar antes de mandarlo.
