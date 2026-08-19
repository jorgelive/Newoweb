# Travel — catálogo maestro de producto

Qué se puede vender y cómo se cuenta. `src/Travel/` no cotiza, no cobra y no opera:
define el **catálogo** que Cotización, Operación y Finanzas consumen aguas abajo.

El módulo resuelve una tensión concreta: la **logística** (un ticket, un bus, un almuerzo)
se reutiliza entre decenas de productos y necesita precio, mientras que la **narrativa**
(«Día completo en el Valle Sagrado») se reutiliza entre itinerarios y necesita fotos y
texto traducible. Mezclarlas obliga a duplicar todo; separarlas obliga a tener pivotes que
las vuelvan a unir con reglas. Eso es casi todo lo que hace este módulo.

**Alcance de este documento:** el modelo relacional de las cuatro entidades centrales y sus
pivotes. Tarifas, proveedores e imágenes se cubren sólo en lo que toca a ese modelo.

---

## Índice

1. [Las cuatro entidades en una frase](#1-las-cuatro-entidades-en-una-frase)
2. [El grafo completo](#2-el-grafo-completo)
3. [Los dos pivotes (dónde vive la inteligencia)](#3-los-dos-pivotes-donde-vive-la-inteligencia)
4. [El cerebro del timeline: `itinerarioContexto` × `dia`](#4-el-cerebro-del-timeline-itinerariocontexto--dia)
5. [Propiedad y radio de impacto: qué rompes al editar](#5-propiedad-y-radio-de-impacto-que-rompes-al-editar)
6. [Clonación](#6-clonacion)
7. [Tarifas, proveedores y el diccionario](#7-tarifas-proveedores-y-el-diccionario)
8. [Lugares: el vocabulario geográfico](#8-lugares-el-vocabulario-geografico)
9. [Frontera con Cotización y Operación](#9-frontera-con-cotizacion-y-operacion)
10. [Enums](#10-enums)
11. [Trampas conocidas](#11-trampas-conocidas)
12. [Dónde tocar para cambiar X](#12-donde-tocar-para-cambiar-x)

---

## 1. Las cuatro entidades en una frase

| Entidad | Es… | No es… | Tabla |
|---|---|---|---|
| `TravelComponente` | Logística pura, el insumo financiero. Lleva tarifas y duración. | No tiene texto de venta, ni día, ni hora. | `travel_componente` |
| `TravelSegmento` | Narrativa. Título, contenido HTML traducible, imágenes y notas. | No tiene precio ni día fijo. | `travel_segmento` |
| `TravelItinerario` | Plantilla con duración (`duracionDias`). Reparte segmentos por día. | No es el producto: es **una variante** de él. | `travel_itinerario` |
| `TravelServicio` | El producto comercial. Agrupa los pools y sus variantes. | No define el orden ni los días. | `travel_servicio` |

La confusión habitual es entre Servicio e Itinerario. **Servicio es el producto**
(«Cusco Clásico»); **Itinerario es una variante de duración** de ese producto
(«Cusco Clásico 4D/3N», «Cusco Clásico 5D/4N»). Un servicio tiene N itinerarios y todos
beben de los mismos pools.

**`slug` vs `nombreInterno` (código vs nombre).** Tanto `TravelSegmento` como `TravelItinerario`
tienen `slug` = **código** (`VIS-VALLE_VIP…-CHINCHERO`, `HD-COMBINADA-POOL-AM`) y `nombreInterno`
= **nombre real** (el que puede ir al proveedor / verse en el cotizador). Antes `nombreInterno`
hacía de código; se separó por migración (`Version20260817240000` itinerario,
`Version20260817280000` segmento: `slug ← nombreInterno`, luego `nombreInterno ← titulo['es']`).
En EasyAdmin el `slug` va primero. Ninguno de los dos campos lleva `#[AutoTranslate]`.

---

## 2. El grafo completo

```
TravelServicio ────────────── el producto comercial
   │
   ├── componentes  M2M  travel_servicio_componentes_pool ──┐  POOL: qué logística
   │                                                        │  está disponible
   ├── segmentos    M2M  travel_segmento_servicio_pool ───┐ │  POOL: qué narrativa
   │                     (dueño: TravelSegmento)          │ │  está disponible
   │                                                      │ │
   └── itinerarios  1:N  cascade + orphanRemoval          │ │  VARIANTES de duración
          │                                               │ │
          │   TravelItinerario (duracionDias)             │ │
          │      │                                        │ │
          │      └── itinerarioSegmentos 1:N              │ │
          │             │                                 │ │
          │             ▼                                 │ │
          │      TravelItinerarioSegmentoRel ◀────────────┘ │   PIVOTE 1
          │         · dia, orden                            │   ordena la narrativa
          │         └── segmento ──▶ TravelSegmento ◀───────┘   en el calendario
          │                              │
          │                              ├── imagenes  1:N  TravelSegmentoImagen
          │                              ├── notas     M2M  TravelNota
          │                              │
          │                              └── segmentoComponentes 1:N
          │                                     │
          │                                     ▼
          └──────────────────────────▶ TravelSegmentoComponente          PIVOTE 2
             (itinerarioContexto, opcional)  · dia, hora, horaFin        cuelga logística
                                             · modo, orden               de la narrativa
                                             · horaServicioCompleto      con condiciones
                                             · tarifaPredeterminada
                                                    │
                                                    ▼
                                            TravelComponente ── la logística
                                                    ├── componenteItems 1:N ──▶ TravelItemDiccionario
                                                    └── tarifas         1:N ──▶ TravelOrganizacion
                                                                                TravelOrganizacionServicio
                                                                                MaestroMoneda
```

Lo que hay que retener: **Servicio no toca a Componente a través del itinerario**. Lo toca
por el pool, en paralelo. El camino que produce el timeline real es
`Itinerario → Rel → Segmento → SegmentoComponente → Componente`, y los pools sólo acotan
qué se ofrece al editor para elegir.

---

## 3. Los dos pivotes (dónde vive la inteligencia)

Ninguna de las cuatro entidades centrales guarda reglas. Las reglas viven en los pivotes,
y por eso casi cualquier cambio de comportamiento se toca ahí.

**`TravelItinerarioSegmentoRel`** — «este segmento va el día 2, en tercer lugar».
Sólo `dia` y `orden`. La colección viene ordenada por `['dia' => 'ASC', 'orden' => 'ASC']`
desde el mapeo de `TravelItinerario::$itinerarioSegmentos`, así que el orden es del ORM y
no hace falta reordenar en PHP ni en el front.

**`TravelSegmentoComponente`** — el pivote ternario, y el que causa las dudas. Une segmento
con componente, pero **condicionado al itinerario y al día**, y de paso guarda lo comercial
y lo horario: `modo`, `hora`, `horaFin`, `horaServicioCompleto`, `tarifaPredeterminada`.

La consecuencia práctica: un mismo segmento narrativo puede rendir logística distinta según
la plantilla en que se use, sin duplicar el segmento. Ahí está el ahorro de todo el diseño.

---

## 4. El cerebro del timeline: `itinerarioContexto` × `dia`

Los dos campos son anulables y `null` significa **«no filtres por esto»**. Combinados dan
cuatro comportamientos:

| `itinerarioContexto` | `dia` | Cuándo se inyecta el componente |
|---|---|---|
| `null` | `null` | Siempre que se use el segmento. Es la logística global del pool. |
| `null` | `2` | Sólo cuando el segmento cae en el día 2, en cualquier plantilla. |
| Itinerario X | `null` | Sólo dentro de la plantilla X, cualquier día. |
| Itinerario X | `2` | Sólo en la plantilla X y sólo el día 2. |

`null` es su propio cubo, no un comodín que colisione: dos filas con contexto `null` y otra
con contexto X conviven sin pisarse.

Sobre esta clave se apoya **`TravelSegmentoComponentePromocionUnicaListener`**, que garantiza
que exista a lo sumo **un** componente con `horaServicioCompleto = true` por cada par
(`itinerarioContexto`, `dia`) — la hora que representa el horario global de la excursión de
ese día. Vive en `onFlush` a propósito: la regla se cumple venga la escritura del modal AJAX
de logística, del CRUD suelto, del CRUD anidado o de la API. Al ver el flush completo también
resuelve el caso de varias filas promovidas en una sola edición, donde **gana la primera**.

---

## 5. Propiedad y radio de impacto: qué rompes al editar

Esta es la sección que evita accidentes. La regla se lee en los mapeos:

**Compartido — editarlo afecta a todo lo que lo referencia:**

- `TravelSegmento` vive por su cuenta y se comparte entre servicios (M2M) y entre itinerarios
  (vía el pivote). Cambiar su texto cambia todas las plantillas que lo inyectan.
- `TravelComponente` igual: se comparte por el pool y por el pivote. Cambiar su duración o su
  tipo repercute en cada segmento que lo cuelga.
- Para saber el alcance **antes** de editar, ambos exponen un lado inverso de sólo lectura:
  `TravelSegmento::getItinerarioSegmentosInyectados()` y
  `TravelComponente::$segmentoComponentesInyectados`.

**Propiedad exclusiva — se borra en cascada con su padre:**

- `TravelItinerario` pertenece al servicio (`cascade: ['persist','remove']`, `orphanRemoval`).
  Borrar el servicio borra sus plantillas.
- `TravelSegmentoComponente` pertenece al segmento, y `TravelItinerarioSegmentoRel` al
  itinerario, ambos con `orphanRemoval`. Sacarlos de la colección los borra de la base.
- `TravelComponenteItem` y `TravelTarifa` pertenecen al componente.
- Las imágenes (`TravelSegmentoImagen`, `TravelOrganizacionImagen`, `TravelOrganizacionServicioImagen`)
  pertenecen a su entidad y tienen listeners de asset y de caché asociados.

**El detalle asimétrico que sorprende:** en el M2M Segmento↔Servicio **el dueño es
`TravelSegmento`** (`inversedBy: 'segmentos'`, tabla `travel_segmento_servicio_pool`),
mientras que en Servicio↔Componente el dueño es `TravelServicio`. Si añades desde el lado
inverso sin sincronizar, Doctrine no persiste nada y el cambio se pierde en silencio.

---

## 6. Clonación

Tres entidades definen `__clone()` y cada una lo hace distinto, a propósito:

- `TravelServicio::__clone()` resetea identidad y renombra, pero **conserva las referencias**
  a los mismos `TravelComponente` ya persistidos. Al ser M2M, Doctrine inserta limpio en la
  tabla intermedia sin excepciones por entidades no gestionadas. El clon comparte pool.
- `TravelItinerario::__clone()` encadena clonado profundo de sus `TravelItinerarioSegmentoRel`.
- `TravelItinerarioSegmentoRel::__clone()` existe únicamente para ese encadenamiento: limpia
  su identidad y apunta al mismo segmento de catálogo.

Es decir: clonar duplica **la estructura** (qué va qué día) y comparte **el contenido**
(segmentos y componentes). Si esperabas copias independientes del texto, no es lo que pasa.

---

## 6 bis. Por qué la empresa se llama `TravelOrganizacion` y no `Proveedor` (19/08/2026)

Una misma ficha juega **tres papeles distintos**, y `CotizacionCotcomponente` los documenta:

> «El **proveedor** dice de quién es el precio; el **prestador**, quién presta el servicio; el
> **comprador**, a quién le mando el encargo.»

Los tres apuntan a la misma entidad. Futurismo es **prestador** en su propia excursión y
**comprador** cuando le encargas que contrate el Hotel Estelar. El boleto de Machu Picchu lo
presta el **Ministerio de Cultura** y lo compra **Tickets Openperu**, que es una división
nuestra modelada como una organización más.

Llamar `Proveedor` a esa ficha nombraba **uno de los papeles como si fuera la clase de
empresa**, y por eso chirriaba: `compradorMaestroId` apuntaba a un «Proveedor» que no provee
nada en esa fila. `TravelOrganizacion` no nombra ningún papel — y cubre al ministerio, que ni
siquiera es una empresa.

⚠️ **La regla operativa que sale de ahí:** si hay comprador asignado, la orden sale a su
nombre; si no, directamente al prestador.

### Qué se renombró y qué se dejó igual, a propósito

| | |
|---|---|
| Clases PHP y sus archivos | `Proveedor*` → `TravelOrganizacion*` |
| Tablas (migración `Version20260819200000`) | `travel_proveedor*` → `travel_organizacion*`, cinco en total |
| **Columnas** | **sin tocar**, incluidas las `proveedor_id` |
| **API** (`shortName`, `uriTemplate`) | **sin tocar**: sigue siendo `/proveedores` y `ProveedorServicio` |
| Mapeos de VichUploader | **sin tocar**: resuelven a carpetas en disco con imágenes ya subidas |
| Nombres de ruta | **sin tocar** |

La API se queda fuera porque su `shortName` viaja al esquema, de ahí a `api.d.ts` y de ahí a
`proveedorModel.ts` y `proveedorStore.ts` de `util/`: renombrarla es un segundo frente que no
aporta nada al modelo de dominio. Las columnas se quedan porque el papel que juega cada enlace
—prestador, comprador— es la **fase 2**, y decidirlo ahora sería adivinar.

### Lo que queda pendiente (fase 2)

- **La tarifa no tiene ningún vínculo con la organización**: sólo `nombreParaProveedor`, un
  `string(150)`. O sea que hoy el papel de una tarifa es *prosa*.
- El componente tiene **un** `proveedor` y **un** `proveedorServicio`, singulares: si dos
  tarifas del mismo componente fueran de organizaciones distintas, el modelo no puede decirlo.
- La idea a diseñar: que la tarifa lleve su prestador, y que el campo del componente sea un
  valor **derivado** —no copiado— cuando todas coinciden. Derivado no puede desincronizarse, y
  la validación «no mezcles prestadores en un componente» sale sola de ahí.
- `ProveedorVivoResolver` (en `src/Cotizacion/Service/`) conserva su nombre: resuelve la cara
  pública del **prestador**, así que su renombre pertenece a la fase 2 y no a ésta.

## 7. Tarifas, proveedores y el diccionario

`TravelTarifa` cuelga del componente y apunta a `MaestroMoneda`, y opcionalmente a
`TravelOrganizacion` y `TravelOrganizacionServicio`. Sus cuatro enums —categoría, rol, modalidad y
procedencia— clasifican el precio; `TravelSegmentoComponente::$tarifaPredeterminada`
sólo preselecciona cuál se propone al instanciar.

`TravelComponenteItem` desglosa el componente en ítems descriptivos tomados de
`TravelItemDiccionario` (vocabulario controlado, reutilizable). Dos rasgos que se olvidan:

- `$componenteAdicionalVinculado` apunta de vuelta a otro `TravelComponente`: es el gancho
  de **upsell**, un ítem que ofrece contratar otro componente. Es la única recursión del modelo.
- Los flags `tituloTarifaVisible`, `categoriaTarifaVisible` y `modalidadTarifaVisible`
  controlan qué se le enseña al cliente de la tarifa asociada.

### `TravelOrganizacion::$visibleParaCliente` — la bandera manda, el título aporta el texto

Hasta 2026-08-16 no había bandera: la regla era **«sin título, el cliente no lo ve»**, y
estaba escrita como una ventaja deliberada —un booleano menos que mantener— en
`util/src/types/proveedorModel.ts`. Se retiró porque deducir la visibilidad de la presencia
de un dato tiene tres costes que no se ven al decidirlo:

| Coste | Qué pasaba |
|---|---|
| Ocultar era **destructivo** | El título es la única copia del texto. Esconder a un proveedor obligaba a borrarlo, y volver a mostrarlo, a reescribirlo. |
| El estado real **no lo decidió nadie** | 93 de 98 proveedores estaban invisibles por no tener título, no porque se quisiera ocultarlos. |
| La intención era **inexpresable** | «Tiene título pero aquí no lo nombres» no tenía dónde escribirse. |

Ahora son dos hechos separados, y hacen falta los dos —lo comprueba
`TravelOrganizacion::puedeMostrarseAlCliente()`, con espejo en TS:

```
visibleParaCliente  ──►  ¿SE PUEDE nombrar?     bandera explícita, opt-in
titulo (i18n)       ──►  ¿CÓMO se le llama?     contenido, traducible
```

⚠️ **Es una SEMILLA que se lee al asignar, no un veto vivo.** Quien decide en cada propuesta
es la bandera del snapshot; ésta sólo la siembra. Si se consultara al serializar, editar el
catálogo cambiaría en silencio lo que ve un cliente con la propuesta ya abierta — que es el
mismo defecto que la bandera viene a cerrar, y rompería la regla de §9 de que la cotización
es una foto del catálogo.

⚠️ **El default es `false`, invirtiendo a propósito el criterio de «lista vacía = sin
acotar»** que rige en guía y conocimiento. Allí el olvido deja un ítem de más y es
inofensivo; aquí nombrar a un proveedor que no tocaba invita al cliente a saltarse la
intermediación y contratar directo. El olvido caro es el contrario, así que se entra por
opt-in.

`Version20260816120000` siembra la columna con la regla vieja (`visible = tenía título`), así
que **no cambia lo que ve ningún cliente**: los mismos 5 proveedores que podían mostrarse
siguen pudiendo. Sonda de verificación: `var/probar-proveedor-visible.php`.

---

---

## 8. Lugares: el vocabulario geográfico

`TravelLugar` es una lista **plana** de centros turísticos —Lima, Cusco, Valle Sagrado,
Ica…— con `nombre` único, `orden` (la fila de chips se lee de izquierda a derecha, y el
alfabético pondría Arequipa antes que Cusco) y `activo`, para retirar una etiqueta del
cuadro **sin destruir el etiquetado que ya se hizo**.

Cuelga de dos sitios por `ManyToMany`, y en los dos el **dueño es el otro lado**:

| Relación | Tabla pivote | Qué significa |
|---|---|---|
| `TravelComponente::$lugares` | `travel_componente_lugar_pool` | Dónde ocurre / desde dónde se opera esa logística |
| `TravelOrganizacion::$lugares` | `travel_organizacion_lugar_pool` | La **cobertura** del proveedor: dónde puede prestar servicio |

### Por qué etiquetas y no una jerarquía

Fue una decisión explícita, y es la que da valor a todo lo demás. Un árbol
País → Región → Ciudad parece lo correcto hasta que se intenta colocar el primer caso real:
**Cusco es una ciudad y Valle Sagrado es una región fuera de esa ciudad**. La contención
sería mentira, y de ahí salen las excepciones que hacen imposible clasificar.

El modelo es el de Gmail, no el de las carpetas de Correos: un componente lleva **varias**
etiquetas y no hay que decidir en cuál «vive». Un servicio de Ica que sale de Lima lleva
«Lima» **y** «Ica»; sólo los que se operan con proveedores locales de Ica llevan una.

⚠️ **La etiqueta significa a la vez «ocurre en» y «se opera desde», y esa ambigüedad es
deliberada.** Separar los dos ejes obligaría al operador a decidir de cuál se trata antes de
buscar, y él no busca así: busca al ojo. Partirlo en dos campos añadiría pasos que nadie va
a dar, y un campo que no se rellena es peor que uno impreciso.

### Es un etiquetado VIVO, no un snapshot

Ésta es la excepción a la regla de §9. El etiquetado se va refinando con el uso, y con
snapshot habría que re-aplicarlo en masa cada vez que se afina una etiqueta. Por eso el
filtro de Operaciones (`docs/Operacion.md` §6) **resuelve contra el catálogo en cada
consulta**: renombrar «Ica» a «Ica / Paracas» se ve al instante y sin tocar ni una
cotización. Va por UUID justamente para que renombrar salga gratis.

Sin `#[AutoTranslate]`: es una etiqueta interna de operaciones que el pasajero nunca ve.
Meterla en el pipeline de traducción añadiría formulario y una llamada a Google por cada
«Lima».

⚠️ **`TravelComponente::__clone()` copia la membresía a mano.** Sin eso el clon compartiría
el mismo `PersistentCollection` y etiquetar la copia reetiquetaría el original.

### Etiquetar en masa

`TravelLugarCrudController` expone el lado inverso (`componentes`) como
`AssociationField` con autocompletado y **`by_reference: false`**. Se abre «Lima», se
seleccionan cuarenta componentes de un tirón y se guarda. Sin ese flag —obligatorio por ser
lado inverso— el formulario no guarda nada **y no avisa**.

## 9. Frontera con Cotización y Operación

**La cotización no tiene claves foráneas hacia Travel.** El enlace es un *soft-link* por
UUID: `CotizacionCotservicio` guarda el id de la plantilla `TravelItinerario` de la que se
armó, y `CotizacionCotcomponente` guarda un soft-link al `TravelOrganizacion` maestro y propaga
valores como `horaServicioCompleto` desde el pivote.

La consecuencia es la que importa: **la cotización es una foto del catálogo en el momento de
armarla**. Editar un componente maestro no reescribe cotizaciones ya emitidas — y tampoco
las arregla si estaban mal. Es una decisión de diseño, no un bug.

Operación consume además `ComponenteTipoEnum::prioridad()` para el orden de despacho
(ver `docs/Operacion.md`).

### Los modos: el catálogo manda, el consumidor sólo elige

Los enums de modo casi no se *ejercen* dentro de Travel — quien los lee y actúa sobre ellos
es Cotización, y después Operación. **Viven aquí a propósito.** Definirlos en el catálogo
hace que el vocabulario comercial sea cerrado y propiedad del maestro: el consumidor elige
un modo por componente, pero no puede inventar uno nuevo ni redefinir qué significa. Si
vivieran en Cotización, cada consumidor podría abrir su propio set y la semántica se
fragmentaría.

El recorrido son tres saltos, y cada uno afloja un poco el vínculo:

| Capa | Cómo guarda el modo | Qué puede hacer |
|---|---|---|
| `TravelSegmentoComponente::$modo` | Enum tipado | Propone el modo por defecto del catálogo |
| `CotizacionCotcomponente::$modo` | Enum tipado, el mismo `ComponenteModoEnum` | Elige — pero sólo dentro del set cerrado |
| `OperacionServicio::$modoComponente` | **String**, «snapshot de ComponenteModoEnum» | Sólo lee; compara contra `->value` |

La intención llega más lejos todavía: `ComponenteModoEnum` no declara sólo los casos, también
las reglas. Y las separa en dos preguntas distintas que conviene no confundir:

| Modo | `afectaCosto()` — ¿sale de mi bolsillo? | `esComisionable()` — ¿lo cobro? |
|---|---|---|
| `INCLUIDO` | sí | sí |
| `CORTESIA` | sí | **no** |
| `NO_INCLUIDO` | no | no |
| `REEMPLAZADO` | no | no |

**La bisagra es `CORTESIA`**, el único caso donde ambas discrepan: el proveedor te factura
igual el traslado o el upgrade que regalaste, así que entra en la hoja de costos, pero no se
le cobra al pasajero. En `NO_INCLUIDO` paga el cliente directo al proveedor y en `REEMPLAZADO`
el costo se lo llevó el componente que lo sustituyó, así que ninguno de los dos toca tu costo.

Es decir, el diseño quiere que hasta la lógica de costeo sea propiedad del catálogo.
**Pero hoy esa parte no está conectada** — ver §10.

### Por qué los modos NO se exponen como recurso propio

Tentación recurrente: publicar el vocabulario con etiquetas e iconos poniendo `#[ApiResource]`
sobre el enum, que es la vía oficial de API Platform 4.3 (`BackedEnumProvider` se cablea solo).
**Se probó el 2026-08-15 y rompe el guardado.** Marcar el enum lo convierte en *relación* en
toda propiedad que lo use: el esquema de escritura de `CotizacionCotcomponente::$modo` y de
`TravelSegmentoComponente::$modo` pasa de

```
{"type":"string","enum":["incluido","no_incluido","cortesia","reemplazado"]}
```

a `{"type":"string","format":"iri-reference"}`, y guardar falla con `Invalid IRI "incluido"`.

La vía oficial sirve sólo para enums que **no** se usan como propiedad de otro recurso. Si
algún día se quiere el vocabulario servido, el camino es una clase-recurso aparte por
vocabulario (`ComponenteModoVocabulario`) con un provider genérico que lea el enum, dejando el
enum intacto como escalar. Decisión de 2026-08-15: no se hace sobre el cotizador —los
serializadores son delicados y el riesgo no compensa unas etiquetas—; se reservará para
código nuevo.

Dos detalles que se aprendieron por el camino y que aplican a cualquier intento futuro: el
esquema de un enum-recurso sólo declara `name` y `value`, así que hace falta un DTO de salida
para que los metadatos entren en el contrato; y **no se pueden mandar clases de Tailwind desde
PHP** —el JIT de la v4 sólo compila las que encuentra en el código fuente—, hay que mandar un
tono semántico y traducirlo en el front.

---

## 10. Enums

| Enum | Casos | Dónde manda |
|---|---|---|
| `ComponenteTipoEnum` | `TICKET_HORARIO_FIJO`, `TICKET_HORARIO_VAR`, `GUIADO`, `TRANSPORTE`, `ALOJAMIENTO`, `ALIMENTACION_HORARIO_FIJO`, `ALIMENTACION_HORARIO_VAR`, `EXCURSION_POOL`, `EXCURSION_PRIVADA`, `PERSONAL_EXTRA`, `EXTRAS`, `VUELO`, `TREN` | Clasifica el componente; su `prioridad()` ordena el despacho en Operación. |
| `ComponenteModoEnum` | `INCLUIDO`, `NO_INCLUIDO`, `CORTESIA`, `REEMPLAZADO` | Vocabulario comercial cerrado. Se **ejerce** en Cotización y Operación, no aquí (§8). Añadir un caso es cambio transversal. |
| `ItemModoEnum` | `INCLUIDO`, `OPCIONAL`, `NO_INCLUIDO` | Modo del ítem descriptivo en `TravelComponenteItem`. Misma lógica de propiedad. |
| `TarifaCategoriaEnum`, `TarifaRolEnum`, `TarifaModalidadEnum`, `TarifaProcedenciaEnum` | — | Clasificación de `TravelTarifa`. |

---

## 11. Trampas conocidas

- **El proveedor subió de `TravelTarifa` a `TravelComponente`** (`Version20260816240000`).
  Estaba en **5 de 904** tarifas y `proveedor_servicio_id` en **0 de 904** — no por desidia
  sino porque un componente llega a tener 19 tarifas y nadie repite el mismo proveedor 19
  veces. El campo no se abandonó: nació impagable. Ahora se llena una vez por componente, y
  eso desbloquea el filtro de tarifas por prestador del editor de cotizaciones, que depende
  de este vínculo. Mismo movimiento que ya se hizo en la cotización
  (`docs/Cotizaciones.md` §6.c), un nivel más arriba.

  Se queda en la tarifa `nombreParaProveedor`: eso **sí** es por línea de precio —cómo llama
  el proveedor a esa tarifa concreta— y es lo que la Orden de Servicio usa para hablarle en
  su idioma («Del Origen Al Presente de Lima») en vez de con nuestro código interno («Pool
  City Lima CT002»).
- **`ComponenteItemModoEnum` no lo usa nadie.** Es un duplicado de `ItemModoEnum` con dos
  casos extra (`CORTESIA`, `REEMPLAZADO`); `TravelComponenteItem` usa `ItemModoEnum`. Ojo al
  elegir cuál tocar: el que manda es `ItemModoEnum`.
- **El duplicado de enums ya produjo código muerto.** `TravelItemDiccionario::badgesModo()`
  ramificaba sobre `'UPSELL'` —un nombre que no existe en ningún enum de modo— y sobre
  `'CORTESIA'`, que sólo existe en el gemelo `ComponenteItemModoEnum`. Como `getModo()` devuelve
  `ItemModoEnum` (INCLUIDO, OPCIONAL, NO_INCLUIDO), ninguno de los dos brazos podía entrar.
  El de `UPSELL` se retiró; el de `CORTESIA` se dejó anotado, porque revive si algún día se
  unifican los enums. **Al ramificar por modo, comprueba primero qué enum devuelve el getter**:
  los nombres de los tres se parecen lo bastante como para escribir un brazo que nunca corre.
- **`esComisionable()` y `afectaCosto()` están declarados pero no se invocan en ningún sitio.**
  Los tres enums de modo los definen y una búsqueda en todo el repo (PHP, Twig, TS, Vue) no
  encuentra una sola llamada. Los consumidores reimplementan la regla comparando casos a mano:
  `CotizacionCotcomponentePrestadorPublicNormalizer` hace
  `getModo() !== ComponenteModoEnum::NO_INCLUIDO`, y `OperacionServicio` compara contra
  `->value`. **El control del catálogo sobre el costeo es hoy nominal**: la regla está escrita
  en el enum, pero quien decide es cada consumidor por su cuenta, así que puede derivar sin
  que nada avise. Si vas a tocar semántica de modos, el arreglo de fondo es llamar a estos
  métodos desde Cotización en lugar de comparar casos.
- **Los docblocks de esos dos métodos están mal; los nombres y los cuerpos no.** El de
  `esComisionable()` habla de «sumar un costo a la cotización», que es justo lo que hace el
  otro método; el de `afectaCosto()` habla de «mostrarse en la sección No Incluye», que es
  presentación y sólo coincide de casualidad. Guíate por los nombres, no por las descripciones
  —y corrígelas si tocas el archivo.
- **`readableLink: false` está por todas partes** a propósito, para que la API devuelva IRIs
  y corte la recursividad en Vue. Si al serializar aparece un objeto anidado donde esperabas
  un IRI, revisa ese atributo antes que el grupo de serialización.
- **Los grupos de lectura cortan ciclos deliberadamente** (marcados `🚫 CORTE CIRCULAR` en el
  código). Añadir un grupo a esas relaciones puede colgar la serialización.
- El endpoint `GET /travel/componentes/batch` existe para precargar varios componentes con
  detalle completo en una sola petición (`?id[]=`). Úsalo antes de escribir un bucle de N
  llamadas al item.

---

## 12. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Que un componente aparezca sólo en cierta plantilla o día | `src/Travel/Entity/TravelSegmentoComponente.php` | `$itinerarioContexto`, `$dia` |
| Cambiar la regla de «una sola hora de servicio completo por día» | `src/Travel/EventListener/TravelSegmentoComponentePromocionUnicaListener.php` | `onFlush()` |
| Reordenar segmentos dentro de la plantilla | `src/Travel/Entity/TravelItinerarioSegmentoRel.php` | `$dia`, `$orden` |
| Cambiar el orden por defecto de los segmentos | `src/Travel/Entity/TravelItinerario.php` | `#[ORM\OrderBy]` en `$itinerarioSegmentos` |
| Añadir un tipo de logística | `src/Travel/Enum/ComponenteTipoEnum.php` | `case` + `prioridad()` |
| Cambiar prioridad de despacho | `src/Travel/Enum/ComponenteTipoEnum.php` | `prioridad()` |
| Añadir una modalidad comercial | `src/Travel/Enum/ComponenteModoEnum.php` | `case` — **revisa Cotización, Operación y front** |
| Cambiar qué componentes se ofrecen en un servicio | `src/Travel/Entity/TravelServicio.php` | `$componentes` (pool, dueño) |
| Cambiar qué segmentos se ofrecen en un servicio | `src/Travel/Entity/TravelSegmento.php` | `$servicios` (**el dueño es Segmento**) |
| Saber dónde se usa un segmento antes de editarlo | `src/Travel/Entity/TravelSegmento.php` | `getItinerarioSegmentosInyectados()` |
| Saber dónde se usa un componente antes de editarlo | `src/Travel/Entity/TravelComponente.php` | `$segmentoComponentesInyectados` |
| Cambiar qué se clona al duplicar un servicio | `src/Travel/Entity/TravelServicio.php` | `__clone()` |
| Cambiar qué se clona al duplicar una plantilla | `src/Travel/Entity/TravelItinerario.php` | `__clone()` |
| Ajustar el upsell de un ítem | `src/Travel/Entity/TravelComponenteItem.php` | `$componenteAdicionalVinculado` |
| Ocultar o mostrar datos de tarifa al cliente | `src/Travel/Entity/TravelComponenteItem.php` | `$tituloTarifaVisible`, `$categoriaTarifaVisible`, `$modalidadTarifaVisible` |
| Tocar el modal AJAX de logística | `src/Api/Controller/Travel/TravelSegmentoComponenteAjaxController.php` | — |
| Que un proveedor pueda (o no) nombrarse ante el cliente | `src/Travel/Entity/TravelOrganizacion.php` | `$visibleParaCliente` — **semilla, no veto**; espejo TS en `proveedorModel.ts` |
| Añadir o retirar un centro turístico | `src/Travel/Entity/TravelLugar.php` | `$nombre`, `$orden`, `$activo` — **desactivar, no borrar** |
| Cambiar las etiquetas de un componente | `src/Travel/Entity/TravelComponente.php` | `$lugares` (lado dueño) |
| A quién se le compra un componente | `src/Travel/Entity/TravelComponente.php` | `$proveedor`, `$proveedorServicio` — **no** están en la tarifa |
| Cómo llama el proveedor a una tarifa | `src/Travel/Entity/TravelTarifa.php` | `$nombreParaProveedor` — lo usa la Orden de Servicio |
| Cambiar la cobertura de un proveedor | `src/Travel/Entity/TravelOrganizacion.php` | `$lugares` (lado dueño) |
| Etiquetar muchos componentes de golpe | `src/Travel/Controller/Crud/TravelLugarCrudController.php` | `componentes` + `by_reference: false` |
| Filtrar tarifas por componente o por lote | `src/Travel/Filter/TarifaComponenteExtension.php`, `TarifaBatchIdExtension.php` | — |
