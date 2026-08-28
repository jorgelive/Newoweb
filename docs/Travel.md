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
| Columnas | `proveedor_id` → `prestador_id` (migración `Version20260819230000`, con `CHANGE`) |
| **API** (`shortName`, `uriTemplate`) | `/proveedores` → `/organizaciones`, `/proveedor-servicios` → `/organizacion-servicios`, `/proveedor_imagens` → `/organizacion_imagens` |
| Ruta de la SPA | `/proveedores` → `/organizaciones`, **con redirección** desde la vieja |
| Mapeos de VichUploader | **sin tocar**: resuelven a carpetas en disco con imágenes ya subidas |

La API entró en el renombrado en una segunda tanda (`46a0d25a`), a petición explícita: «ya no son
proveedores, esto debe quedar limpio, nada de referencias antiguas».

⚠️ **Y ahí está la factura, que se pagó el 19/08/2026.** Cambiar `uriTemplate` mueve la ruta
entera, pero **las URLs del front son cadenas**: no las ve PHPStan, no las ve `vue-tsc`, no las
ve ESLint. Quedaron trece apuntando a rutas que ya no existían, repartidas en tres stores:

| Archivo | URLs muertas |
|---|---|
| `util/src/stores/travel/organizacionStore.ts` | 7 |
| `util/src/stores/cotizacion/cotizacionEditorStore.ts` | 4 |
| `util/src/stores/operacion/operacionStore.ts` | 2 |

Lo que se veía por fuera: el desplegable de prestador en el editor de cotizaciones decía «no se
encontraron resultados» para cualquier búsqueda. No hay error en pantalla porque el store captura
el 404 y deja la lista vacía — **el fallo se disfraza de catálogo sin coincidencias**.

**La regla que sale de aquí:** al mover un `uriTemplate`, `grep` de la ruta vieja en `util/` y
`pax/` es parte del cambio, no una comprobación posterior. Y regenerar `api.d.ts`, que tampoco
avisa solo.

### Un filtro que no está no falla: devuelve de más

Buscando lo anterior salieron dos filtros que **nunca existieron** y que el front llevaba
llamando desde siempre. API Platform ignora en silencio un parámetro que no esté declarado con
`#[ApiFilter]`, así que la petición no daba error: devolvía la colección entera.

| Petición | Qué pasaba |
|---|---|
| `organizacion-servicios?proveedor_id=X` | volvían los servicios de **todas** las empresas |
| `organizaciones?id[]=a&id[]=b` | volvía el maestro entero con `pagination=false` |

La segunda funcionaba de casualidad —quien llamaba buscaba luego por id dentro de la lista—. La
primera **no**: el editor etiquetaba como del prestador elegido todo lo que llegara, así que el
desplegable de servicios enseñaba el catálogo entero disfrazado. Los filtros ya están declarados
en las dos entidades.

Se comprueba en el propio esquema, que es donde no se puede mentir:

```bash
php bin/console api:openapi:export -o /tmp/api.json
# y mirar los `parameters` del GET de la colección
```

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

## 6 ter. Las tarifas desde el agente (19/08/2026)

Cuatro skills, para el rol de **operaciones**, pensadas para encadenarse:

```
buscar_componentes  →  buscar_tarifas  →  modificar_tarifa
   (¿cuál es?)          (¿cuánto y         (cambiar, con
                         con qué             previsualización)
                         condiciones?)
```

| Skill | Riesgo | Rol | Qué hace |
|---|---|---|---|
| `buscar_componentes` | Lectura | `OPERACIONES_SHOW` | encuentra el servicio por nombre, **lugar**, prestador o tipo |
| `buscar_tarifas` | Lectura | `OPERACIONES_SHOW` | sus tarifas con restricciones y prestador; acepta `componente_id` |
| `crear_tarifa` | **Escritura** | `OPERACIONES_WRITE` | añade una tarifa a un componente |
| `modificar_tarifa` | **Escritura** | `OPERACIONES_WRITE` | cambia una que ya existe |

### Por qué hace falta `buscar_componentes`

Para tocar una tarifa hay que traer su `tarifa_id`, y para eso hay que dar con su componente.
`buscar_tarifas` ya lo hacía **si se acertaba el nombre** — y ahí estaba el problema: hay 221
componentes y el operador dice «el bus a Puno» cuando en el catálogo pone «Transporte Aeropuerto
Juliaca - Hotel Puno». Sin una forma de mirar el catálogo, la única salida era abrir el panel, y
entonces el agente no sirve para esto.

Por eso busca **ancho** —nombre, lugar, prestador y tipo a la vez— y devuelve **poco**: lo justo
para reconocer el servicio y encadenar. **No trae precios**: llenaría la respuesta de importes
que nadie ha pedido, y para eso está la siguiente.

⚠️ Lo que sí trae siempre es **cuántas tarifas tiene cada uno**, que es el dato que decide el
paso siguiente. Un componente con 0 no es un fallo de búsqueda: es uno sin precios cargados, y
hay que decirlo así. Uno con 19 avisa de que «¿cuánto cuesta?» va a necesitar condiciones antes
que un importe.

Y `buscar_tarifas` acepta `componente_id`: cuando ya se eligió uno, **el id manda sobre el
nombre** y no se re-adivina.

### Crear y modificar están separadas, y el empate las cubre

Son dos intenciones distintas y el operador las dice distinto. El riesgo de separarlas era que
el modelo eligiera mal —«ponle otra tarifa» y «cámbiale la tarifa» se parecen demasiado—, pero
**las dos son de escritura, así que empatan y salta la aclaración**
(`AclaracionDeEmpate::obliga()`): el agente pregunta en vez de adivinar. Es exactamente el caso
para el que existe ese mecanismo, y hasta el 19/08/2026 no podía dispararse.

Las reglas de qué es una tarifa válida viven en `TarifaDesdeEntrada`, compartidas. Escritas dos
veces, dentro de tres meses una aceptaría lo que la otra rechaza.

### Tres cosas que van pegadas al dato, no al prompt

- **Las restricciones viajan SIEMPRE**, y los límites sin poner salen como «sin límite» en vez
  de omitirse. Un importe sin condiciones al lado invita a cotizarle a un extranjero la tarifa
  de nacionales, y omitir un eje se lee como que no aplica.
- **Lo que elige el modelo se valida contra listas cerradas**: modalidad, categoría,
  procedencia y moneda salen de enums y del maestro. Un valor inventado se rechaza **con la
  lista de los válidos en el mensaje**, no se guarda «como venga».
- **Los rangos al revés se rechazan.** `edad_minima: 30, edad_maxima: 5` no lo caza ningún tipo
  y deja una tarifa que no aplica a nadie — invisible hasta que alguien pregunta por qué no
  sale ese precio.

### Lo que NO se toca al modificar

Las condiciones que no se mencionan se quedan como están; eso es lo que permite «súbele el
precio» sin borrar las edades. Para **quitar** un límite hay que mandarlo explícitamente en `0`,
que es la única forma de decir «sin tope» dictando por voz.

### 🐛 La previsualización que se colaba

`modificar_tarifa` con `confirmado=false` deja la entidad **gestionada y ya modificada en
memoria**: cualquier `flush()` posterior del mismo turno —de otra skill, de un listener— la
habría escrito sin que nadie la aprobara. Por eso el camino de previsualización hace
`$em->refresh()` y descarta los cambios. `crear_tarifa` no lo necesita: sin `persist()`,
Doctrine no sabe que la entidad existe.

### `dominios()` vacío, a propósito

El catálogo Travel es transversal —tours, tickets, traslados y también componentes de
alojamiento— y hoy el único frente declarado es `hotelero`. Acotarlas ahí las volvería
invisibles para todo lo demás. Ver CLAUDE.md, «lista vacía = sin acotar».

### Verificación

`var/probar-buscar-tarifas.php` y `var/probar-guardar-tarifa.php`, contra datos reales y el
segundo en transacción con **rollback**. Seis casos, incluido el del bug de arriba:

```
0. buscar «puno» tipo transporte → Transporte Aeropuerto Juliaca - Hotel Puno
   y por su componente_id        → Auto 150 PEN (1–4 pax), Bus 600 (9–25), Master 300 (5–14)
1. previsualizar crear     → no escribe            904 → 904
2. procedencia inventada   → rechazada con la lista de válidas
3. rango al revés (30–5)   → rechazado
4. confirmar               → creada                904 → 905
5. modificar precio        → 55.00, procedencia CONSERVADA
6. previsualizar + flush   → en base sigue 55.00 (no se coló)
```

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

- **El catálogo Travel NO dice quién presta un servicio, y es deliberado** desde el
  20/08/2026 (`Version20260820120000`). Merece leerse entero porque el campo estuvo en dos
  sitios en una semana y en los dos estaba mal.

  Colgaba de `TravelTarifa` y ahí quedó vacío: **5 de 904**, con `proveedor_servicio_id` en
  **0 de 904**. El diagnóstico de entonces —«nació impagable: un componente llega a tener 19
  tarifas y nadie repite la misma empresa 19 veces»— era correcto, y por eso
  `Version20260816240000` lo subió a `TravelComponente`, donde se llena una vez.

  ⚠️ **Y era la conclusión equivocada, porque el argumento de ergonomía tapaba uno de
  corrección.** Un componente **puede tener tarifas de empresas distintas**: «Tren Ollanta –
  Machu Picchu» se compra a PeruRail y a IncaRail; el Boleto de Machu Picchu, al Ministerio o
  a un revendedor. Un prestador único arriba no es un valor por defecto incómodo — es una
  afirmación **falsa sobre todas las tarifas menos una**, y encima impedía cargar las demás.
  «Tren Mapi Poroy» tiene 15 tarifas: ninguna cifra de «una empresa por componente» sobrevive
  a ese dato.

  Que el campo se abandonara en la tarifa no significaba que estuviera en el sitio
  equivocado: significaba que **nadie tenía todavía un motivo para llenarlo**. Se resolvió
  moviéndolo, que es lo que parecía barato, en vez de preguntando por qué no se llenaba.

  **Dónde vive ahora:** quién presta el servicio es una decisión de **cotización**, no de
  catálogo. Se resuelve al elegir la tarifa y ya estaba modelado allí
  (`CotizacionCotcomponente::resolverPrestador()`, `docs/Cotizaciones.md` §6.c).

  Se fueron con él `prestadorServicio` —sólo existía para acotar al de arriba—, la validación
  cruzada `validarServicioDelPrestador()` y el panel «Prestador por defecto» del CRUD, cuya
  ayuda además prometía activar un filtro de tarifas por prestador que **nunca miró este
  campo**: `prestadorEsperadoDeTarifaActiva` lee el prestador del *cotcomponente* y devuelve
  `null` si no lo hay. Llenarlo en el catálogo no activaba nada.

  Se queda en la tarifa `nombreParaPrestador`: eso **sí** es por línea de precio —cómo llama
  esa empresa a esa tarifa concreta— y es lo que la Orden de Servicio usa para hablarle en
  su idioma («Del Origen Al Presente de Lima») en vez de con nuestro código interno («Pool
  City Lima CT002»). Que ese campo sí tenga sentido por tarifa es, de hecho, la pista que
  estaba ahí desde el principio.

  **Y el 20/08, la conclusión de las dos vueltas: vuelven a `TravelTarifa`**
  (`Version20260820140000`), que es la única granularidad en la que la afirmación es cierta —
  el componente no tiene un prestador, cada tarifa sí. Con ellos llega **`comprador`**, que es
  lo que faltaba: da el motivo para llenarlos, porque la Orden de Servicio sale a nombre de
  quien recibe el encargo. Vacío = se le compra al prestador, el caso normal.

  La validación cruzada vuelve con los campos, a `TravelTarifa::validarConsistenciaLogica()`:
  el servicio elegido tiene que ser del prestador **de esa tarifa**.

  **Consecuencia para el agente:** `buscar_componentes` y `buscar_tarifas` no buscan ni
  devuelven la empresa. Buscar «Ministerio de Cultura» y esperar sus componentes no funciona:
  el dato está ahora una capa más abajo, en cada tarifa.

### Cómo llegan los tres papeles a la cotización

Las tres relaciones viajan como **IRI** (`readableLink: false`), que es lo correcto para no
arrastrar la ficha entera ni abrir recursión. Pero el editor tiene que **congelar el nombre** en
su snapshot, y con un IRI a secas eso costaría una petición por tarifa.

Por eso el nombre viaja al lado, plano: `prestadorNombre`, `prestadorServicioNombre` y
`compradorNombre` son getters expuestos en `componente:item:read`. Son gratis —la entidad ya está
cargada— y le ahorran al consumidor una cascada de peticiones para escribir un campo de texto.

Al elegir una tarifa, `cotizacionEditorStore.prestadorDeTarifaParaSnapshot()` copia los seis
valores a `CotizacionCottarifa`. **No se pintan en el formulario a propósito**: son referencia
histórica, no campos que se editen.

⚠️ **El comprador NO cae al prestador al congelarlo.** Se guarda lo que hay escrito; qué significa
el vacío lo decide `CotizacionCotcomponente::resolverComprador()` en PHP. Rellenarlo en el front
duplicaría la regla y las dos copias acabarían diciendo cosas distintas.

Y una que estuvo rota en silencio: el mapa `proveedorPorTarifa` leía `c.proveedor` del componente
maestro. Ese campo pasó a llamarse `prestador` el 19/08, así que la guarda `'proveedor' in c` era
**siempre falsa**: el mapa quedaba vacío y de él colgaban el subtítulo del selector de tarifas
—que es lo que evita colgar de un componente la tarifa de otro— y el snapshot entero. Ahora lee
de la tarifa, que es donde vive el dato.

### 🐛 Un getter nuevo no aparece en el esquema: es la caché de metadatos

Cuesta media hora y no da ningún error. Al añadir un **getter virtual** con `#[Groups]`
—`getPrestadorNombre()`— el campo no salía en `api:openapi:export`, mientras que las propiedades
ORM añadidas a la vez **sí** salían. `debug:serializer` lo veía; el esquema no.

No es el código: es que `cache:clear` **no purga los pools de metadatos de propiedad** de API
Platform. Las propiedades ORM se recalculan y los getters virtuales se sirven de la foto vieja.

```bash
php bin/console cache:pool:clear --all   # ← el que hace falta
php bin/console cache:clear
php bin/console api:openapi:export -o /tmp/api.json
```

Se diagnostica en un minuto añadiendo un getter tonto (`getZzExperimento()`): si **tampoco** él
aparece, no es tu campo, es la caché.

### Los trenes se compran a OpenPeru Tickets, no a PeruRail

Regla de negocio que no se ve en el modelo: los billetes de **PeruRail** e **IncaRail** no se le
piden al operador. Los saca **OpenPeru Tickets**, una división nuestra modelada como organización
—un solo catálogo, una sola pregunta—. Y como la Orden de Servicio sale a nombre del **comprador**,
dejarlo vacío haría que el encargo se le mandara al tren.

Sembrado con `app:travel:comprador-trenes` (idempotente, con `--dry-run`): **69 tarifas en 6
componentes**, las que empiezan por `PR ` (57) o `IR ` (12).

Identifica **por el prefijo del nombre**, no por el prestador, porque el prestador todavía está
sin poblar. Y no lo rellena: deducirlo del mismo prefijo sería una afirmación sobre de quién es el
precio, y eso merece decidirse aparte.

⚠️ **El comando apaga la traducción antes de guardar**, y hay que copiar ese gesto en cualquier
carga masiva sobre `TravelTarifa`: lleva `#[AutoTranslate]` en el título y `ejecutarTraduccion`
**no está mapeado y arranca en `true`**, así que un `flush` normal dispara el traductor de todas
las filas tocadas para rellenar idiomas que nadie pidió. Poner un id de empresa no cambia ningún
texto.

Y el dato que justifica todo el rediseño: **«Tren Ollanta Mapi» tiene 12 tarifas `PR` y 4 `IR`**.
El mismo componente, dos operadores. Con el prestador colgando del componente, ese componente
tendría que mentir sobre 4 tarifas o sobre 12.

### 🔥 `SearchFilter` NO puede filtrar por UUID en este proyecto

La trampa más cara del módulo, porque **no da error**: devuelve lista vacía y parece que no hay
datos.

Los identificadores son `binary(16)` (`#[ORM\Column(type: 'uuid')]` en `IdTrait`) y
`api-platform/doctrine-orm` enlaza el valor **sin declarar su tipo**:

```php
// vendor/api-platform/doctrine-orm/Filter/SearchFilter.php
->setParameter($valueParameter, $values[0]);   // ← falta el tercer argumento: el tipo
```

Compara texto contra binario y no casa nunca. Medido contra producción el 20/08/2026 sobre
`/platform/travel/organizacion-servicios`:

| Petición | Resultado |
|---|---|
| `?nombre=Grand` (texto, `partial`) | **1** ✅ |
| `?id=<uuid>` (uuid, `exact`) | **0** ❌ |
| `?organizacion=<uuid>` | **0** ❌ |
| `?organizacion=<IRI completo>` | **0** ❌ |

⚠️ **De ahí sale la regla que cuesta dinero: un filtro de UUID declarado es PEOR que no
declararlo.**

- **Sin declarar**, API Platform ignora el parámetro y devuelve la colección entera. Está mal,
  pero **se ve** — y medio editor funcionaba así de casualidad: pedía `?id[]=a&id[]=b` con
  `pagination=false`, recibía el maestro completo y buscaba dentro.
- **Declarado**, el filtro **sí se aplica** y devuelve **cero**. Ese mismo día se añadió
  `id => 'exact'` a `TravelOrganizacion` creyendo que se arreglaba algo, y el selector de
  prestadores del editor de cotizaciones se quedó en blanco hasta revertirlo.

**Cuando haga falta filtrar por uuid:** un endpoint propio que lo resuelva en PHP, como
{@see \App\Api\Controller\Travel\TravelOrganizacionServicioOpcionesController} —
`Uuid::fromString()` y `setParameter(…, 'uuid')`, que es justo el argumento que la librería
omite—. Con su `#[IsGranted]`: el host de la API cae en `PUBLIC_ACCESS`.

### El desplegable dependiente del CRUD de tarifas

«Servicios de ESE prestador» se arma con `AdminFieldHelper::controlsAjax()` y
`assets/controllers/panel/dependent-select-ajax_controller.js`. Tres cosas que ya fallaron:

1. **El endpoint no puede ser la colección de API Platform** — por lo de arriba. Apunta a
   `/platform/travel/organizacion-servicios-opciones`, que devuelve `[{id, nombre}]` y nada más.
2. **Nada de `->autocomplete()` en esos campos.** Con él, EasyAdmin renderiza el select **vacío**
   —una sola opción, «Ninguno»—: las opciones se cargan por su propio endpoint remoto, que aquí
   no se dispara. Y en el padre además pelea por el `data-controller` que escribe `controlsAjax()`.
3. **Hay que vaciar también las `<option>` del `<select>` nativo.** `ts.clearOptions()` limpia la
   lista interna de TomSelect, pero el select de debajo conserva lo que pintó el servidor y
   `ts.sync()` lo relee: el filtro **añadía** los servicios de la empresa elegida y **no quitaba**
   los de las demás. Comprobado: 4 opciones antes, 1 después.

Verificado en producción con el navegador — Tambo del Inka → su habitación; Hotel Terra → la
suya; PeruRail → vacío, porque no tiene servicios cargados.

Y `var/probar-crud-tarifa.php` cruza los campos del CRUD contra las propiedades reales de la
entidad. Nació porque un barrido de renombrado dejó `TextField::new('nombreParaOrganizacion')`
apuntando a una propiedad que se llama `nombreParaPrestador` — una cadena que no ve PHPStan, ni
`vue-tsc`, ni ningún test, y que sólo revienta al abrir el formulario.
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

## 11 bis. Escribirle a un proveedor (21/08/2026)

`src/Travel/` entra en la mensajería por primera vez, y por la puerta pequeña: una
`TravelOrganizacion` pasa a ser **alguien a quien se le puede escribir**.

```
src/Travel/Service/Message/
├── TravelOrganizacionMessageContext.php   el adaptador: la organización vista como interlocutor
└── TravelProveedorDeContexto.php          «ármame el contexto de esta organización»
```

`CONTEXT_TYPE = 'travel_organizacion'`.

### ⚠️ Un proveedor NO es un asunto, y por eso casi todo va vacío

Los dos contextos que existían —una reserva, un expediente— son **asuntos**: algo que empieza,
termina, se cobra y se cancela. Una organización no: es un interlocutor permanente. Lo que
devuelve vacío **es la respuesta correcta**, no un hueco por rellenar:

| Método | Devuelve | Por qué |
|---|---|---|
| `getMilestones()` | vacío | No hay hitos que programar. Se le escribe a mano, no por reglas |
| `getItems()` | vacío | No hay casitas ni servicios que listar |
| `getFinancialTotal()` | `null` | Lo que se le deba vive en el expediente, no en la organización |
| `isCancelled()` | `false` | Una organización no se cancela: deja de usarse |
| `getVinculo()` | `Cliente` | Y no es un error de nombre — ver abajo |

⚠️ **`VinculoComercial::Cliente` para un proveedor.** El enum dice «hay un contexto vivo detrás y
se le atiende», que es exactamente la situación. `Interesado` abriría las reglas de venta sobre
alguien a quien no le vendemos nada.

⚠️ **Idioma `es`, y sin fijar.** El catálogo no guarda el idioma de la organización, así que es
una suposición: son proveedores locales. Al no fijarla, el primer mensaje que llegue en otro
idioma la corrige. El defecto del PMS (`en`, pensado para huéspedes extranjeros) acertaría menos.

### Beds24 se apaga solo — sin desactivar nada

No hay `MessageDataResolverInterface` para `travel_organizacion`, así que la metadata sale vacía,
`es_plataforma` no viene, y tanto `MessageFactory` como `Beds24SendEnqueuer` descartan el canal.
**No hace falta desactivarlo**: no traer la clave ya lo dice. Ver la tabla de claves en
`MessageDataResolverInterface::getMetadata()` y `docs/Mensajeria.md` §24.

WhatsApp y correo funcionan con el teléfono y el correo del catálogo, sembrados como identidades
al abrir el hilo.

### El hilo nace cuando alguien decide escribir, no al guardar

Es el único de los tres dominios **sin listener detrás**, y a propósito: una organización se
guarda muchas veces —se le corrige la dirección, se le sube una foto— y ninguna de esas veces
significa «quiero hablar con ella». Se abre con `POST /message/conversations/abrir`
(`docs/Mensajeria.md` §24, «Abrir un hilo»), que es idempotente.

⚠️ **Sin teléfono ni correo no se abre**, con el motivo escrito. Un hilo que no resuelve a nadie
no recibe, no sale y deja en la bandeja una fila que nadie puede cerrar.

### Dónde se pulsa: la FICHA del proveedor

Catálogo → **Proveedores** (`util/src/views/Catalogo/OrganizacionesView.vue`). El panel tiene
ahora dos modos, con el mismo interruptor que `ReservaEditDrawer`:

```
lista → clic en un proveedor  →  FICHA (sólo lectura)   ← «Escribir» vive aquí
                                   ↕ botones Editar / Ver
                                 FORMULARIO              ← «Guardar» y «Eliminar» viven aquí
«Nuevo proveedor»             →  FORMULARIO directo, que no hay ficha que mirar
```

⚠️ **La acción de escribir NO cabía en el formulario, y ése fue el fallo.** Enterrada en un pie
pensado para cerrar una edición —entre «Eliminar» y «Guardar»— no se encontraba. Abrir una ficha
es casi siempre para **mirarla**: consultar un teléfono dentro de un `<input>` obliga a leer texto
cortado que no se puede pulsar, y deja el panel a un despiste de guardar algo sin querer.

En la ficha el teléfono es un `tel:`, el correo un `mailto:` y la web un enlace: es lo que se
viene a buscar.

⚠️ **Tras dar de alta se queda en FORMULARIO, no en la ficha.** Servicios y galería sólo existen
allí —necesitan el IRI del proveedor ya creado—, así que devolver a la ficha justo después de
crear mandaría a dar un rodeo.

El botón se desactiva —con el motivo en el `title`— cuando no hay ni teléfono ni correo. 🪞 Es un
espejo de `AperturaDeHilo::abrir()`, que es quien de verdad lo impide; aquí sólo evita ofrecer un
botón que va a responder 409. Mira el **formulario** y no el proveedor guardado, para que el aviso
desaparezca en cuanto se teclea el teléfono, sin tener que guardar primero.

### ⚠️ El «atrás» se salía hasta el home

El panel es una capa, no una ruta, así que el navegador no sabía que estaba abierto: darle atrás
para «volver al listado» salía de la vista entera, porque la entrada anterior del historial era la
de antes de entrar a Proveedores. En móvil, donde el atrás del sistema es el gesto natural, se
perdía el trabajo del formulario sin un aviso.

`onBeforeRouteLeave` lo arregla con el mismo patrón que `ReservasView`: cada capa abierta consume
un «atrás».

⚠️ Ese guard **cancela cualquier salida de ruta, no sólo el back**. Por eso `escribirle()` cierra
el panel ANTES de su `router.push` — si no, el guard se tragaría la navegación y el botón parecería
no hacer nada. Es el mismo requisito que documenta `ReservaEditDrawer::abrirChatInterno()`.

Al abrir, navega a `/chat` con el hilo ya seleccionado. El chat degrada bien un `contextType`
desconocido: `getExternalContextUrl` y `getReservaContextId` devuelven `null`, y de plantillas
sólo se ofrecen las genéricas —las que no declaran `contextType`—.

### Lo que NO tiene todavía

- **Ni enlace de conversación ni `ConversacionEnlaceInterface`.** Deliberado: un proveedor no
  tiene asuntos que colgar. El día que los tenga —una orden de servicio concreta, un reclamo—
  ése será el momento de crear la entidad de enlace, no antes.
- **Ni resolver de datos**, así que no hay variables de plantilla propias. Se le escribe texto
  libre.

## 11 ter. El etiquetado de lugares (21/08/2026)

`app:travel:etiquetar-lugares`.

### El vocabulario existía y no se usaba

7 lugares dados de alta y **224 de 225 componentes sin una sola etiqueta**. Un filtro que nadie
llenó no filtra: el cuadro de tráfico lo ofrecía y devolvía todo.

### ⚠️ Las etiquetas SE ACUMULAN hacia arriba

Es la decisión de producto, y es lo que hace útil el filtro:

```
Valle Sagrado  ─┐
Machu Picchu   ─┼─► también CUSCO      (se despachan desde Cusco)
Valle Sur      ─┤
Vinicunca, Humantay, Palcoyo… ─┘

Ica (y PARACAS) ─┬─► también LIMA      (se venden y salen desde Lima)
Nasca           ─┘
```

Filtrar por «Cusco» trae también el Valle y Machu Picchu —lo que espera quien opera desde
Cusco— **sin inventar una jerarquía en el modelo**. El vocabulario sigue plano y multivaluado,
como lo describe `TravelLugar`: quien acumula es la tabla de reglas del comando, no una relación
padre-hijo. Es coherente con el rechazo a la jerarquía que ya estaba documentado («Valle Sagrado
dentro de Cusco» sería falso).

⚠️ **Paracas NO tiene etiqueta propia**: sus 16 componentes van a Ica, y de ahí a Lima. Se opera
dentro de ese circuito. Lo mismo Quillabamba y Corredor Sur, que caen en Cusco y Puno.

### Lugares nuevos

`Riviera Maya` (9), `Valle Sur` (7), `Nasca` (6), `Quillabamba` (5) y `Aruba` (1). El primero
tapa un hueco de categoría: **no había ningún lugar fuera de Perú** y hay producto de Cancún,
Playa del Carmen, Chichén y Xcaret.

⚠️ **Quillabamba arrastra Cusco** —de ahí se despacha, 6 h por Amparaes—, así que sus cinco
componentes llevan las dos. `Aruba` no arrastra nada: no hay hub desde el que se opere.

### ⚠️ Los productos cuyo nombre no dice dónde ocurren

«Super Valle», «Combinada», «Escénico», «By Car», «ruta del misterio 4 en 1», y las actividades
por su nombre —bungee, slingshot, cuatrimoto, «Transporte Parque»— son productos de la casa: el
nombre es comercial o dice la ACTIVIDAD, no el sitio, así que el etiquetado por nombre los dejaba
fuera **para siempre**. Van a Cusco, que es desde donde se despachan, y están listados uno a uno
en el patrón — no hay forma de deducirlos.

Es el límite del método: **un catálogo con nombres comerciales necesita una lista de excepciones
mantenida a mano**, y conviene revisarla cada vez que entren productos nuevos.

### Resultado

| Lugar | Componentes |
|---|---|
| Cusco | 143 |
| Lima | 41 |
| Valle Sagrado | 40 |
| Ica | 28 |
| Machu Picchu | 23 |
| Puno | 18 |
| Arequipa | 10 |
| Riviera Maya | 9 |
| Valle Sur | 7 |
| Nasca | 6 |
| Quillabamba | 5 |
| Aruba | 1 |

De 224 sin etiqueta a **9**, y esos 9 **no deben llevarla**: «Box Lunch», «Box Breakfast»,
«Walking Sticks», «Impuestos aeroportuarios», «Ticket aereo», «Movilidad a disposición»,
«Alquiler de caballos», «Función de Cine» y «Transporte Walking» son insumos transversales que se
usan en cualquier destino. **Que sigan sin etiqueta es la respuesta correcta**, no una tarea
pendiente: ponerles una sería afirmar que sólo valen ahí.

### El comando: idempotente y aditivo

- Sólo **añade** lo que falte. Un componente etiquetado a mano conserva lo suyo y gana lo que le
  toque; correrlo dos veces no cambia nada la segunda. Por eso se puede relanzar cuando entren
  componentes nuevos, que es justo cuando hace falta.
- ⚠️ **No quita etiquetas.** Si una regla cambia y deja de aplicar, la vieja se queda: quitarla
  sola borraría el trabajo manual de alguien sin preguntar.
- `--dry-run` enseña qué haría; `--crear-lugares` da de alta los que falten. Sin ese segundo
  flag **se niega** si falta alguno: un comando de etiquetado que además crea vocabulario a la
  primera es el que acaba llenando el maestro de etiquetas con faltas de ortografía, y el
  `unique` del nombre las convertiría en filas distintas para siempre.

### ⚠️ Lo que el etiquetado por nombre NO puede arreglar

**«Bus Bimodal a Ollantatyambo» tiene un typo** —le sobra una `t`— y por eso ninguna regla lo
alcanza. Es el tipo de fila que se queda fuera para siempre sin que nadie lo note. Corregir el
nombre y relanzar el comando lo resuelve.

## 11 quater. Dónde empieza y dónde termina un servicio (22/08/2026)

`ComponenteTipoEnum::puntosDeServicio()` → {@see PuntosDeServicio}.

**Es lo primero que pregunta un proveedor al recibir la Orden de Servicio: dónde recojo y dónde
dejo.** Y la respuesta no depende del servicio concreto sino de su **naturaleza**, así que vive
en el tipo y no en cada componente: un ticket no recogerá a nadie por mucho que se le rellene el
campo.

| | Tipos | Nune | catálogo |
|---|---|---|---|
| `INICIO_Y_FIN` | transporte · tren · vuelo · **pool · privada** | 17 | 147 |
| `SOLO_INICIO` | guiado | 2 | 9 |
| `NINGUNO` | tickets · comidas · extras · personal · alojamiento | 23 | 69 |

⚠️ **Las excursiones llevan punto de recojo, y no es un detalle.** Un `pool` pasa por el hotel a
buscar al pasajero: es de lo primero que se coordina. En el catálogo son **47 componentes**, el
segundo tipo más numeroso — meterlas en «no aplica» dejaría sin dato justo a las que más lo
necesitan.

⚠️ **`GUIADO` tiene dónde presentarse pero no destino.** El guía queda con el pasajero y ahí acaba
su parte; devolverlo es del transporte. Ponerle destino sería inventarle una obligación que nadie
pactó.

⚠️ **`NINGUNO` no significa «todavía no se sabe»: significa que NO APLICA.** Dejarlo como
pendiente invita a rellenarlo, y a que el proveedor lea un punto de recojo que nadie va a
atender.

### Un enum de tres casos, no dos booleanos

Porque «tiene fin pero no inicio» **no existe**, y con dos `bool` sí se puede escribir. Los
estados posibles son tres y el enum los cierra: quien reciba el valor no puede construir uno
imposible. Los ayudantes `programaInicio()` / `programaFin()` son la lectura cómoda.

### Las otras dos preguntas del tipo

Son distintas y tienen su método, en vez de un `=== 'alojamiento'` repartido por ahí:

- **`esAnclaDeUbicacion()`** — sólo `ALOJAMIENTO`. No traslada a nadie, pero es lo que dice
  **dónde está** el pasajero cada noche. Encadenando sus noches (`fecha` + `cantidad`) sale el
  punto de recojo de todo lo demás: *se recoge donde durmió y se deja donde dormirá*. En el
  itinerario de Nune la cadena cubre las 15 noches **sin un hueco**.
- **`esSalto()`** — `VUELO` y `TREN`. Parten el día en dos: lo anterior termina en su punto de
  salida y lo posterior empieza en el de llegada. Sin esto, un traslado de la mañana se leería
  como si terminara en el hotel de destino, que está en otra ciudad.

### El modelo: el punto vive en el SEGMENTO (22/08/2026)

El enum dice *qué servicios* tienen extremos. Lo que dice *cuáles son* es esto:

```
TravelPunto                     un sitio concreto, con dirección
  nombre · tipo · lugar · direccion · referencia · organizacion?

TravelSegmento
  inicioModo  PuntoModoEnum     sin_definir | alojamiento | fijo
  inicioPunto ?TravelPunto      obligatorio si el modo es «fijo»
  finModo     PuntoModoEnum
  finPunto    ?TravelPunto
```

**`PuntoModoEnum::ALOJAMIENTO` es direccional según el lado**: en el inicio significa *dónde
durmió* y en el fin *dónde va a dormir*. Es lo que hace que un traslado Cusco → Ollantaytambo
salga bien con un solo caso en vez de `ALOJAMIENTO_ANTERIOR` / `ALOJAMIENTO_SIGUIENTE`, que se
podrían poner al revés.

⚠️ **`SIN_DEFINIR` es el valor por defecto y tiene que serlo.** Un extremo que naciera diciendo
«alojamiento» por comodidad es una mentira que nadie revisa: el proveedor recibiría un hotel
donde toca una estación de tren y la orden saldría *plausible*.

#### Por qué en el segmento y no en el componente

Lo decidieron los datos: hay **33 segmentos compartidos entre itinerarios**, y lo son
*precisamente porque significan el mismo sitio* — «Retorno al centro de Cusco» aparece en cuatro
plantillas y en las cuatro deja al pasajero donde mismo. En el componente habría que repetirlo
por plantilla y mantenerlo sincronizado a mano.

Y trae gratis los **overrides**, que era lo que parecía más difícil:

```
Full Day Valle Sagrado Tradicional      Full Day Valle Sagrado VIP
 1 Recojo e inicio de excursión          1 Recojo en el Hotel (Privado VIP)
 …                                       …
 7 Retorno al centro de Cusco            7 Descanso en el Valle Sagrado
   → Plaza de Armas de Cusco               → el hotel del pasajero
```

⚠️ **La variante de una plantilla no es una excepción: es otro segmento final.** No hizo falta
modelar overrides porque el itinerario ya elige qué segmentos inyecta.

### La regla del servicio que abarca el día

El problema real era otro: por comodidad de edición, un pool cuelga del **primer** segmento, así
que su segmento dice dónde empieza pero no dónde acaba.

Lo resuelve un dato que **ya existía**: `TravelSegmentoComponente::$horaServicioCompleto`, con
unicidad por `(plantilla, día)` garantizada por `TravelSegmentoComponentePromocionUnicaListener`
pase por donde pase la escritura. Es el marcador de «este servicio es el dueño del día».

```
servicio que ABARCA el día        inicio = 1.er segmento de (plantilla, día)
(horaServicioCompleto = true)     fin    = último segmento de (plantilla, día)

cualquier otro                    inicio y fin = su propio segmento
```

Lo implementa `TravelPuntosDelServicio::para()`, y devuelve un `PuntosResueltos` — un objeto y no
un array porque hay **tres** estados por extremo (resuelto · «es el hotel del pasajero» · sin
declarar) y un array los aplasta a dos.

⚠️ **`ALOJAMIENTO` cuenta como COMPLETO en el informe de pendientes.** El catálogo ya dijo todo lo
que podía decir; cuál hotel lo pone la reserva. Contarlo como hueco pondría todos los pool en la
lista para siempre, y una lista así se deja de mirar.

⚠️ **Este servicio NO resuelve el hotel.** Devuelve el modo y nada más: cuál hotel depende del
pasajero y de la noche. Mezclarlo obligaría al catálogo a conocer el expediente.

### Por qué NO se creó `travel_itinerario_componente`

La propuesta —colgar los servicios abarcadores de la plantilla entera como contenedor, en vez de
del segmento— **describe mejor la realidad**, y se descartó igualmente por lo que cuesta:

| | Tabla nueva | Puntos en el segmento |
|---|---|---|
| Entidad nueva | sí, con 9 de 10 campos duplicados | no |
| CRUD nuevo | sí | no |
| Listener de promoción única | duplicar | ya sirve |
| Lectores del pivote (12 archivos) | dos caminos, para siempre | sin cambios |

Y sobre todo: **la mitad del contenedor ya estaba construida**. `horaServicioCompleto` con clave
`(itinerarioContexto, día)` ya declaraba cuál es el servicio dueño del día; lo único que le
faltaba era el eje geográfico, que es lo que se añadió. Se descartó también un campo `abarcaHasta`
en el pivote, por redundante con esa marca.

**El contraejemplo que lo justificaría** existe y es uno: «Starlodge con actividades» día 1 tiene
**tres** servicios abarcadores (Ascenso, Vía Ferrata, Descenso) en 5 segmentos. De 28 días de
plantilla, es el único con más de uno. Ahí sólo uno lleva la marca y los otros dos toman origen y
fin de su propio segmento, que es correcto.

### El comando de carga

`app:travel:proponer-puntos [--dry-run] [--crear-puntos]` — idempotente y **aditivo**: sólo
escribe sobre extremos en `SIN_DEFINIR`, así que lo puesto a mano nunca se pisa.

Funciona por nombre porque los nombres de este catálogo son excepcionalmente explícitos
(«Traslado a la estación de Poroy», «Viaje en tren de retorno a Ollantaytambo»). No es heurística
sobre texto libre: es leer un dato que ya estaba escrito, en la columna equivocada para
consultarlo.

⚠️ **Gana la PRIMERA regla que case**, al revés que `app:travel:etiquetar-lugares`, donde las
etiquetas se acumulan. Un segmento tiene un único origen y un único destino, así que acumular
sería sobrescribir y el orden de la tabla decidiría en silencio.

Resultado sobre el catálogo real: **11 puntos maestros creados, 90 extremos escritos, 68
segmentos sin regla** —visitas, comidas, alojamientos: correctamente sin puntos—. Con la segunda pasada, los **12** servicios abarcadores que tienen plantilla quedan resueltos.
Quedan fuera 5 que llevan la marca **sin plantilla de contexto** — ver «Deuda de datos».

#### El cierre por regla de negocio: privado → hotel, compartido → centro

Hay plantillas donde el nombre no dice nada del sitio: «Full Day Humantay Estandar» tiene **un
único segmento** —«Laguna de Humantay»— que es a la vez el primero y el último del día, y su
nombre habla de la actividad. Ahí no hay nada que leer, y decide la regla del operador:

```
privado    (transporte · privada)   recoge en el hotel  →  DEVUELVE al hotel
compartido (pool)                   recoge en el hotel  →  DEJA en el centro de la ciudad
```

El porqué es operativo: un bus compartido con doce pasajeros de nueve hoteles no puede hacer
nueve paradas. Vive en `ComponenteTipoEnum::esCompartido()`, no en un `=== 'pool'` suelto.

⚠️ **`TRANSPORTE` cuenta como privado.** En este catálogo las versiones privadas de una excursión
se montan con un transporte propio —«Transporte Vinicunca», «Transporte Combinada»— y no con
`EXCURSION_PRIVADA`, del que sólo hay **dos** componentes en todo el maestro. Clasificar por el
nombre del caso en vez de por cómo se usa habría dejado a los privados devolviendo al centro.

⚠️ **El centro se busca por los LUGARES del componente**, con `CENTRO_POR_LUGAR`, y hoy sólo Cusco
lo tiene. Un componente sin etiquetar cae al hotel **y lo dice en la salida**. Rellenar la tabla
«por simetría» pondría a un Lima City Tour dejando a la gente en la Plaza de Armas de Cusco: algo
que compila, pasa los tests y es una orden falsa.

Es una pasada **aparte** de la lectura de nombres y se anuncia como tal, porque quien lea el
informe tiene que poder distinguir lo deducido de un nombre explícito de lo dado por supuesto.
Sigue siendo aditiva: sólo escribe sobre `SIN_DEFINIR`.

### Paquetes de proveedor externo y plantillas de varios días

`horaServicioCompleto` —«Servicio principal del día» en el panel— **no es sólo una hora**. Es la
marca de qué componente ES el día, y de él salen tres cosas: el horario que se enseña, el punto
de recojo y el de entrega que van al proveedor, y el titular de la tarjeta en el editor de
cotizaciones.

**Cuándo se usa:** servicios empaquetados de un proveedor externo, o los que incluyen varios
puntos. Un día compuesto por tren + bus + ingreso + guía **no lleva marca y está bien así**: cada
componente cuelga de su segmento y saca de ahí sus puntos. De ahí que el Full Day MAPI no la
tenga.

**En una plantilla de varios días va uno POR DÍA.** La unicidad es `(plantilla, día)`, así que un
Camino Inca de 4 días admite cuatro marcados sin pisarse. El patrón es crear un componente por
día —«Segundo día Camino Inca»—, **aunque sea de costo 0**, sólo para que aporte hora de inicio y
de fin y se pueda promover.

⚠️ **La Categoría Operativa de ese componente decide si la marca sirve para algo.** Con `extras`
o `ticket`, `ComponenteTipoEnum::puntosDeServicio()` devuelve `NINGUNO` y la promoción **no aporta
ningún punto de recojo, sin dar error**. Es la familia de fallo que este repo tiene documentada:
un dato mal puesto que no falla, simplemente deja de hacer su trabajo. Tiene que llevar el tipo
que refleje la realidad (`pool`, `privada`, `transporte`).

**Estado al 22/08/2026:** las cinco plantillas de varios días del catálogo —Two Day Camino Inca,
Two Day MAPI bimodal, Two Day Vertical Sky, Skylodge y Starlodge— **no tienen ninguna marca por
día**. `app:travel:proponer-puntos` las saca en una sección propia, «para revisar», cada vez que
se ejecuta: es un recordatorio, no una lista de errores.

#### Por qué NO se añadió una bandera «principal» aparte

Se evaluó y se descartó. `horaServicioCompleto` ya es de hecho esa marca, y **dos banderas que
quieren decir casi lo mismo acaban divergiendo**: el 99 % de las filas tendrían las dos o
ninguna, y el día que alguien ponga una sin la otra, algo usa la equivocada sin un solo error.
Además obligaría a un tercer dominio de unicidad —hoy `(plantilla, día)`; suelto haría falta
`(segmento, día)`; en la cotización `(cotservicio, día)`—, que es donde viven los fallos
silenciosos.

El anticipo de ese problema ya existe: las **5 filas con la marca puesta y sin plantilla de
contexto**, donde hoy no significa nada.

⚠️ **Lo que sí queda pendiente y sería la mejora correcta:** relajar
`TravelSegmentoComponentePromocionUnicaListener` para que la marca valga también **sin plantilla**
—con la unicidad por `(segmento, día)`—, y así cubrir el servicio armado a medida. Una sola
bandera, un solo significado, y de paso las 5 huérfanas dejarían de ser ruido.

**Lo que haría replantear la decisión:** encontrar días donde el componente que marca la hora y el
que se considera principal sean **distintos**. Hoy no hay ninguno.

### Las dos privadas del Valle, y el nombre que mentía (22/08/2026)

«Full Day Valle sagrado tradicional privado» **no llevaba logística del Valle tradicional**:
llevaba `Guiado Super Valle` + `Transporte Super Valle`, que es la familia que alimenta
«Full Day Valle Vip». Era la privada del VIP con el nombre de la otra. Se renombró a
**«Full Day Valle Vip privada»** con `app:travel:renombrar-valle-vip-privada`.

Estructuralmente las dos privadas son **idénticas salvo el primer segmento** —o2..o7 son las
mismas filas—. Toda la diferencia es qué se compra:

| | Valle Sagrado privado | Valle Vip privada |
|---|---|---|
| Guía, base | 65 USD | **90 USD** |
| Van | 89 (1–8 pax) | **115 (1–6 pax)** |
| Master | 99 | **150** |
| Sprinter | 120 | **190** |
| Bus | 180 | **250** |
| «con Ruinas» | sí (75) | — |

Super Valle sale entre un **30 % y un 50 % más caro** en todos los tramos y su Van lleva dos
pasajeros menos. Son dos productos con el mismo itinerario, no dos nombres del mismo. El caso de
uso de la VIP privada: cliente con pocos días que quiere esa maratón en privado.

El juego queda simétrico:

```
servicio «Valle Sagrado»   1D VALLE POOL      Full Day Valle Sagrado Tradicional
                           1D VALLE PRIV      Full Day Valle Sagrado privado
                           1D VALLE VIP PRIV  Full Day Valle Vip privada
servicio «Valle Vip»       1D VALLE VIP POOL  Full Day Valle Vip
```

⚠️ La VIP privada **se queda en el servicio «Valle Sagrado»**, no se movió a «Valle Vip»: mover
de servicio cambia qué segmentos ofrece su pool, y eso no es un rename.

Su servicio principal es `Transporte Super Valle` —igual que la privada del Valle a secas lleva
`Transporte Valle Sagrado`—, y resuelve **hotel → hotel**, como corresponde a un privado.

⚠️ **«Full Day Valle Vip» (la pool) sigue sin principal.** Es la única de las cuatro del Valle a
la que le falta, y su caso es idéntico al de la Tradicional: le tocaría `Pool Super Valle`, igual
que a aquélla `Pool Valle Sagrado`. Sin la marca no sale la línea de «recoge → deja» en el editor
de cotizaciones.

### 🐛 Cambiar un texto traducido sin activar la sobrescritura

Es la trampa que casi se cuela en el rename, y vale para **cualquier campo con
`#[AutoTranslate]`**. `AutoTranslationService` sólo pisa una traducción existente si
`sobreescribirTraduccion` está activo:

```php
if (!$overwrite && !$isContentEmpty) continue;
```

Sin activarlo, el español habría pasado a decir «Valle Vip» y los otros seis habrían seguido
diciendo «Sacred Valley» / «Vale Sagrado» / «Heilige Tal». **Un producto VIP anunciado como el
tradicional en todos los idiomas menos uno** — y no lo ve nadie, porque quien revisa lee español.

Las dos reglas que salen de ahí:

1. **`setSobreescribirTraduccion(true)` va ANTES de tocar el campo**, en el mismo flush: el
   listener lo lee en `preUpdate`.
2. **Verificar después.** El comando comprueba que los seis idiomas mencionen «VIP» y avisa si
   alguno no cambió, en vez de dar por hecho que la traducción corrió. Una llamada de red que
   falla deja el campo sólo en español y no lanza nada.

### El alojamiento cierra el día (22/08/2026)

Un segmento «Alojamiento en …» no traslada a nadie, pero **sí dice dónde acaba el día**. Y eso
importa por la regla del abarcador: cuando es el último segmento del día, es de él de quien el
servicio que abarca ese día toma su punto de entrega.

Sin declararlo, un Camino Inca de dos días decía «sin declarar» **teniendo el hotel escrito dos
líneas más arriba, en su propia plantilla**. Eran 18 segmentos, todos sin tocar.

La regla del comando pone **sólo el fin**: al alojamiento se llega desde donde sea, y eso lo dice
el servicio anterior, no éste.

### 🐛 El «override» que en realidad aplastaba

La regla del abarcador —inicio del primer segmento del día, fin del último— estaba escrita **sin
mirar si esos segmentos declaran algo**:

```php
// ✗ borra información
$segmentoInicio = $extremos[0] ?? $propio;

// ✓ el día MANDA si declara; si no, cae al segmento del componente
$segmentoInicio = $extremos[0]?->getInicioModo()->esDeclarado() === true ? $extremos[0] : $propio;
```

Con la versión vieja, un pool colgado de un segmento que **sí** dice dónde recoge, dentro de un día
cuyo primer segmento no dice nada, se quedaba **sin punto de recojo** — borrando un dato que estaba
escrito, y sin ningún error.

⚠️ **No se notaba, y ése es el problema.** Hoy los 17 abarcadores cuelgan del primer segmento de su
día, así que los dos caminos coinciden y la salida es idéntica. Deja de serlo en cuanto se cuelgue
un abarcador de un segmento intermedio — que es algo que el modelo permite y que se preguntó
explícitamente.

Corregido en los **dos** resolvedores, `TravelPuntosDelServicio` y `CotizacionPuntosDelServicio`, y
cubierto por `CotizacionPuntosDelServicioTest`, que prueba `paraServicio()` con el mapa de maestros
inyectado a mano: **sin base de datos y en centésimas**, porque ese método es público justamente
para eso.

### El contacto con el pasajero es un COMPONENTE, no un campo del guiado (22/08/2026)

El guiado de Machu Picchu ocurre **arriba**, en el santuario. El encuentro con el pasajero ocurre
en la estación o en su hotel, **horas antes y a cuatro horas de distancia**. Mientras fueron la
misma cosa, el «dónde recojo» de la orden decía uno de los dos y el proveedor iba al otro — con
una orden que se lee perfectamente bien.

⚠️ **Se descartó estirar el `inicio` del guiado** para que significara «dónde se hace contacto».
Un campo que quiere decir dos cosas según quién lo lea se rompe la primera vez que alguien lee la
que no era. Y de paso mató una heurística que estaba a punto de aplicarse: encadenar el inicio de
un segmento con el fin del anterior habría rellenado esos guiados con «Santuario de Machu Picchu»,
que es donde se guía y **no** donde se contacta.

#### Cómo queda modelado

```
ComponenteTipoEnum::CONTACTO        SOLO_INICIO · prioridad 0 (encabeza el manifiesto)

componente  «Contacto con el cliente»            tipo contacto · tarifa Base 0.00 USD
segmento    «Contacto en la estación de Machu Picchu»   inicio = fijo → Estación de Machu Picchu
segmento    «Contacto en el hotel»                      inicio = alojamiento
```

**Dos segmentos, no un campo con opciones**, y la plantilla elige cuál inyecta — el mismo
mecanismo con el que la variante VIP del Valle Sagrado cambia su segmento final. El segmento dice
DÓNDE y el componente dice QUÉ.

Y **quitarlo del itinerario significa algo concreto y querido**: el pasajero sube por su cuenta y
el guía lo espera arriba.

⚠️ **En la estación se dice EXPRESAMENTE que el encuentro es fuera.** Al andén no se entra y no se
permite el ingreso de acompañantes, pero mucha gente espera dentro convencida de que ahí la
recogen. Es una frase en el texto del cliente que ahorra una llamada por grupo y una espera con
maletas — y darla por supuesta es lo que hace que pase.

⚠️ **La tarifa de 0 no es decorativa: sin ella el contacto nunca llega al proveedor.** Un
componente sin tarifa es «sólo referencia» —`OperacionServicio::isSoloReferencia()`— y **no puede
entrar en una Orden de Servicio**. Se quedaría visible en La Biblia e invisible para quien tiene
que ir a la estación con el cartel. Hay 217 tarifas de 0 en el catálogo; no es un caso forzado.

#### El reparto, y de dónde sale

Lo dictan los datos: donde el grupo **llega en tren esa mañana**, el contacto es en la estación;
donde **durmió en Machu Picchu Pueblo**, en su hotel.

| Plantilla | Día · orden | Segmento |
|---|---|---|
| Full Day MAPI: CUZ OLLA MAPI OLLA CUZ (bimodal) | 1 · 3 | Contacto en la estación |
| Two Day MAPI: OLLA MAPI OLLA CUZ (bimodal) | 2 · 1 | Contacto en el hotel |
| Two Day Camino inca | 2 · 1 | Contacto en el hotel |

Lo monta `app:travel:crear-contacto-machupicchu`, idempotente y con `--dry-run`.

⚠️ **Al insertar, los segmentos de detrás se corren ANTES de meter el nuevo.** Al revés, dos filas
compartirían `orden` durante el mismo flush y el orden de la plantilla quedaría a merced de cómo
desempate la base.

### 🐛 La trampa que casi lo deja mudo: `setParameter()` con un UUID

```php
// ✗ devuelve CERO filas, sin error. findBy() sobre lo mismo devolvía siete.
->setParameter('itinerario', $itinerario)

// ✓
->setParameter('itinerario', $itinerario->getId(), UuidType::NAME)
```

Sin el tipo explícito, Doctrine liga el valor como cadena contra una columna `BINARY(16)`. Y el
fallo era **plausible**: el resolvedor se caía a su rama de reserva —el segmento propio— y decía
que el pool del Valle terminaba en el punto de recojo. El informe de cobertura del comando, que
usaba `findBy()`, decía a la vez que estaba todo completo. Lo delató la contradicción entre los
dos, no la lectura del código.

Es la convención del repo: `getId()` + `'uuid'` / `UuidType::NAME`. Ver `FinEnlacePagoRepository`,
`InboundMenuResolver`, `PushSubscriptionRepository`.

### Deuda de datos: las 5 promociones huérfanas (limpiada el 22/08/2026)

Había **5 filas con `horaServicioCompleto = true` y `itinerarioContexto` NULL**, anteriores a las
dos defensas que hoy lo impiden —`validarPromocionRequierePlantilla()` en la validación y el
listener de unicidad en el flush—. Se retiró la marca con
`app:travel:limpiar-promociones-huerfanas`.

| Componente | Colgaba de | Ese segmento se usa en |
|---|---|---|
| Pool Maras Moray | «Recojo e inicio de excursión (Grupal)» | Half Day Chinchero, Maras y Moray |
| Pool Valle Sagrado | «Recojo e inicio de excursión (Grupal)» | Full Day Valle Sagrado Tradicional |
| Pool Vinicunca | «Montaña de 7 Colores (Caminata Clásica)» | Full Day Vinicunca Estandar |
| Transporte Maras | «Recojo en el Hotel (Privado)» | Half Day Chinchero, Maras y Moray privado |
| Transporte Valle Sagrado | «Recojo en el Hotel (Privado)» | **(ninguna)** |

⚠️ **Se quitó la marca y NO se les asignó plantilla, aunque cada segmento se use en una sola.**
Porque no es lo mismo: `itinerarioContexto = null` significa «este componente se inyecta siempre
que se use el segmento» —la logística global del pool, ver §4—. Ponerle una plantilla
**restringiría la inyección**, y eso no es limpieza: es cambiar cuándo entra el componente, y
saltaría el día que alguien monte un servicio a medida con ese segmento. Se comprobó: 192 filas y
las 121 de inyección global, intactas.

⚠️ **No se tocaron las cotizaciones ya guardadas.** Dos componentes habían heredado la marca
(`Pool Vinicunca` ×2). El flag copiado en un `CotizacionCotcomponente` es parte de un documento
emitido y en ese caso además acierta —ese pool **es** su día—. Limpiado el origen, no se hereda
ninguna más.

### Darles servicio principal: se AÑADE fila, no se modifica la global (22/08/2026)

Las tres plantillas que la marca huérfana intentaba cubrir se resolvieron con
`app:travel:promover-servicio-principal`:

| Plantilla | Componente que ES su día |
|---|---|
| Full Day Vinicunca Estandar | Pool Vinicunca |
| Half Day Chinchero, Maras y Moray | Pool Maras Moray |
| Half Day Chinchero, Maras y Moray privado | Transporte Maras |

⚠️ **No se tocó la fila global: se creó una segunda fila ligada a la plantilla.** Es el patrón que
ya estaba vivo en producción con `Pool Valle Sagrado`, que tiene exactamente eso — una fila global
sin marca, que sigue inyectando, y otra con `itinerarioContexto` y la marca. La alternativa
—poner plantilla a la fila global— habría **restringido la inyección** (§4) y el componente
dejaría de entrar en un servicio armado a medida que reutilice el segmento, sin aviso.

Resultado: 192 → 195 filas, las **121 globales intactas**, 12 → 15 marcas válidas. Y los tres
salen con sus extremos por la regla del catálogo: los dos pool devuelven al centro de Cusco y el
privado al hotel.

⚠️ **La tabla de promociones es explícita, no deducida.** «Cuál de estos componentes abarca el
día» es decisión de producto: una regla automática acertaría casi siempre, que es la peor
frecuencia — nadie revisa lo que casi siempre funciona.

### 🚫 Lo que NO era basura: «Recojo en el Hotel (Servicio Privado)» del Valle

Hay **dos segmentos con ese nombre**, y el que no aparece en ninguna plantilla parecía sobrar. No
sobra: es la **variante privada del Valle Sagrado**, con su logística completa —Guiado Valle
Sagrado + Transporte Valle Sagrado + BTPV— y ofrecida en el pool del servicio «Valle Sagrado». Lo
que falta es la plantilla que la use; el segmento está bien.

| Segmento homónimo | En plantillas | Ofrecido en | Componentes |
|---|---|---|---|
| (usado) | 1 | Chinchero, Maras y Moray | Guiado Maras · Transporte Maras · BTPV |
| (sin plantilla) | **0** | Valle Sagrado | Guiado Valle Sagrado · Transporte Valle Sagrado · BTPV |

Borrarlo habría destruido una variante privada configurada entera. **Un segmento sin plantilla no
es un segmento muerto**: puede estar en el pool de un servicio, listo para una cotización a
medida. Antes de retirar uno hay que mirar sus tres usos —plantillas, pool de servicios y
`cotizacion_segmento.segmento_maestro_id`—, no sólo el primero.

**Se le montó su plantilla** con `app:travel:crear-valle-sagrado-privado`:
«Full Day Valle Sagrado privado», clonando la estructura de la privada existente y cambiando sólo
el segmento de recojo. Así los o2..o7 son **las mismas filas de segmento**, no copias — que es lo
que hace que corregir un punto se refleje en las tres plantillas del Valle a la vez.

⚠️ **Hay DOS segmentos llamados «Recojo en el Hotel (Servicio Privado)»** y sólo se distinguen por
lo que llevan colgado —uno el de Chinchero/Maras, otro el del Valle—. Buscarlos por nombre a secas
devuelve cualquiera de los dos según el orden de la base y monta la plantilla con la logística
equivocada **sin dar ningún error**. Por eso el comando los desambigua por su componente
(`Transporte Valle Sagrado`), no por el nombre del segmento.

⚠️ **«VIP» en este catálogo es etiqueta comercial, no nivel de servicio.** Lo que se llama VIP es
el pool **más barato**; el nombre viene de cómo lo vende el mercado. No sirve para deducir qué
variante es más privada, ni cuál debería ser la principal, ni para ordenar nada por calidad. Es de
la familia de «campos que parecen un error y no lo son» del `CLAUDE.md`: lo que induce a error es
el nombre, y el dato está bien.

El título público se copió tal cual con sus siete idiomas: comercialmente las dos **son**
«excursión privada al Valle Sagrado». Lo que las distingue —qué transporte y qué guía— es
operativo y vive en `nombreInterno`, que es lo que ve quien cotiza.

### Los dos resolvedores difieren a propósito

| | ¿Exige plantilla para que un componente abarque el día? |
|---|---|
| `TravelPuntosDelServicio` (catálogo) | **sí** |
| `CotizacionPuntosDelServicio` (cotización) | **no** |

No es un descuido. **El «servicio» sólo existe como contenedor ORDENADO en la cotización.** En el
catálogo, `TravelServicio` agrupa segmentos en un pool sin orden ni días; los contenedores
ordenados son la plantilla y el segmento. Así que:

- en la cotización, «este componente abarca su día dentro de su `CotizacionCotservicio`» está bien
  definido — y es lo que hace falta para el servicio armado a medida, sin plantilla;
- en el catálogo sin plantilla **no hay nada que abarcar**: no existe una lista ordenada de
  segmentos a ese nivel. Por eso el listener desmarca, y hace bien.

La marca significa lo mismo en los dos sitios —«este componente es el dueño de su día dentro de su
contenedor»—; lo que cambia es que un lado tiene contenedor a ese nivel y el otro no.

## 11.bis Los vuelos van por RUTA, un componente cada uno (2026-08-27)

Un vuelo **es** su ruta. «Lima → Cusco» es lo que se compra, así que su identidad pertenece al
**componente**, no al segmento que lo envuelve.

Los trenes ya estaban así —«Tren Ollanta Mapi», «Tren Mapi Ollanta», «Tren Poroy Mapi»…, seis
rutas— y por eso ninguno da problemas. Los vuelos eran la excepción: un único **«Ticket aereo»**
genérico, y para saber de qué vuelo se hablaba había que leer el segmento. En el cuadro de tráfico
eso dejaba fichas que decían «Ticket aereo» y nada más.

Alta: `app:travel:crear-vuelos-por-ruta "Lima<>Cusco"` (idempotente por `nombre`, con `--dry-run`).

⚠️ **Va por comando y no por SQL**: `TravelComponente::$titulo` lleva `#[AutoTranslate]`, y ese
listener cuelga de `prePersist`. Un `INSERT` directo deja la ficha sólo en español y no lo denuncia
nadie.

⚠️ **No se generaliza a «todo componente de nombre genérico».** Cabe un segmento «Vuelo Cusco a
Lima», pero **no** uno «Walking Sticks en Vinicunca»: los bastones son un extra colgado de una
excursión y se resuelven viendo lo que tienen al lado. La regla es **por tipo de servicio** —los
que se venden como trayecto: vuelo, tren, transporte entre dos puntos—, no por si el nombre parece
genérico. El razonamiento largo está en `docs/Operacion.md`, en «Los dos nombres SIEMPRE».

⚠️ Y **«sin lugar» tampoco es la señal**: de 226 componentes hay 10 sin lugar, y entre ellos están
«Ticket aereo» (genérico) junto a «Box Lunch» y «Walking Sticks», que se nombran perfectamente
solos.

### Etiquetas y tarifas de una ruta

**Los dos extremos, siempre.** Cada ruta se etiqueta con origen y destino, porque un vuelo se busca
desde cualquiera de sus dos puntas: «qué tengo en Cusco ese día» tiene que sacar tanto el que llega
como el que sale. El genérico «Ticket aereo» no tenía ninguna etiqueta y no salía nunca.

⚠️ **El aeropuerto nombra la ruta; la etiqueta agrupa la operación.** No siempre coinciden, y ahí
el sufijo `@`: `"Lima<>Juliaca@Puno"` crea «Vuelo Lima Juliaca» etiquetado **Puno**. Crear
«Juliaca» al lado de «Puno» partiría en dos el filtro de un mismo sitio, y quien filtrara por Puno
no vería el vuelo. Mismo caso con `"Lima<>Cancún@Riviera Maya"`.

**Las tarifas son las variantes de equipaje**: artículo personal, mano, mano y bodega, bodega — que
es como tarifan las aerolíneas. Se copian del componente que las tenga con
`app:travel:copiar-tarifas --desde="Ticket aereo" --a="Vuelo %"`, idempotente por `nombreInterno`.
Nacen a 0.00 y el importe se rellena ruta por ruta.

⚠️ Ese comando **quita el prefijo «(Clon) »** que pone `TravelTarifa::__clone()`. El prefijo es
correcto para el botón de duplicar de la UI —ahí el clon convive con el original— y es justo lo
contrario aquí, donde la tarifa aterriza en otro componente y no hay con qué confundirla.

Las cotizaciones que ya usan el genérico **no se repuntan**: dos de ellas son versiones
`confirmado` e `historico`, y cambiarles el componente de origen reescribe lo que se vendió. El
cambio vale hacia adelante; para leerlas, el cuadro ya enseña la ruta desde el segmento.

## 11.ter Las ferroviarias y sus clases de servicio (27/08/2026)

PeruRail e IncaRail se muestran al cliente (§«A quién se nombra» en `docs/Cotizaciones.md`), y en
un tren **el «servicio» es la clase**: no es lo mismo un Expedition que un Hiram Bingham, y es
justo lo que el pasajero quiere saber.

| Empresa | Clases |
|---|---|
| PeruRail | Local · Expedition · Vistadome · Vistadome Observatory · Hiram Bingham |
| IncaRail | Voyager · The 360° · Prime · First Class |

Alta: `app:travel:crear-servicios-ferroviarios` (idempotente por empresa+nombre, con `--dry-run`).
El comando corrige además el título público si viene mal escrito — IncaRail lo tenía como
«incaRail», y eso lo lee el cliente.

⚠️ **Va por comando y no por SQL**: `TravelOrganizacionServicio::$titulo` y `$descripcion` llevan
`#[AutoTranslate]`. Un `INSERT` directo deja las fichas sólo en español sin denunciarlo, y la
errata del título tendría el mismo problema: arreglada en español y las otras seis lenguas
diciendo lo anterior.

⚠️ **Falta url e imágenes en ambas.** La tarjeta pública sale con el nombre y la clase, pero sin
foto ni enlace, al contrario que los hoteles. Es trabajo de catálogo y va por el panel.

### El trío viaja en la TARIFA, no en el componente

La tarifa maestra lleva **prestador + servicio del prestador + comprador**, y el editor los copia
al componente al elegirla. Puesto ahí se propaga solo: quien cotice un tren no tiene que acordarse
de nada.

```
travel_tarifa «PR Vistadome Adulto»
    prestador          PeruRail
    prestadorServicio  Vistadome           ← la clase, que es lo que el pasajero pregunta
    comprador          OpenPeru Tickets
```

Alta y relleno: `app:travel:vincular-servicio-ferroviario`, que mapea por el prefijo del nombre
(`PR` / `IR`) y **comprueba que la empresa cuadre** antes de enlazar: si alguien renombra una
tarifa mal, se salta en vez de colgar la clase de otra compañía.

⚠️ **Tres tarifas de tren van SIN prestador a propósito** y no hay que «arreglarlas»:

| Tarifa | Por qué |
|---|---|
| `Guía` | Es el billete de tren **del guía**. Caso especial: va en blanco |
| `No necesario` | Marcador de que ese tramo no lleva tren |
| `Local` | Sin empresa asignada todavía — probablemente el tren Local de PeruRail |

### Una grafía por clase

`IR 360` e `IR T360` convivieron: la misma clase escrita distinta según qué día se cargó cada
componente —la primera en las rutas de Poroy, la segunda en las de Ollantaytambo—, y los títulos
públicos habían derivado igual, unos con `°` y otros sin. Se unificó con
`app:travel:unificar-360-incarail`.

⚠️ **No fue un descuido de nadie: es lo que un catálogo grande produce solo.** Quien da de alta una
tarifa ve esa tarifa, no el conjunto, y no tiene forma de saber que la misma clase ya existe con
otro nombre tres componentes más allá. Conviene barrer de vez en cuando agrupando por nombre
normalizado (sin mayúsculas, acentos ni símbolos) y por título público repetido; el barrido del
27/08/2026 encontró además once tarifas con el mismo nombre y títulos distintos.

## 11.quater Actividades de resort: el segmento es genérico, la tarifa sabe de qué hotel (28/08/2026)

Es la cara de catálogo de `docs/Cotizaciones.md` §6.t, y el caso donde el patrón se ve entero.

### El reparto

Un desayuno buffet se cuenta igual en Punta Cana que en Cancún. Lo que cambia entre un resort
y otro **no es el texto: es la foto**. Así que:

```
  segmento genérico   ──►  componente genérico  ──►  tarifa POR RESORT  ──►  servicio del
  «Piscina y playa»        «Piscina y playa»         Occidental: 0 USD       prestador (fotos)
  slug sin el destino      uno solo, reutilizado     una por hotel           donde se sube todo
```

**Ni el segmento ni el componente llevan el destino en el nombre ni en el slug.** El slug es
`ACT-RESORT-PISCINA_PLAYA`, no `ACT-PUJ_RESORT-PISCINA_PLAYA`: meterle `PUJ` ataría a Punta Cana
una ficha que sirve para cualquier resort, y obligaría a duplicarla en el segundo destino — que es
justo lo que este reparto evita.

**Añadir el resort número dos no crea ningún segmento.** Crea su organización, sus servicios con
sus fotos, y una tarifa más colgando de los mismos doce componentes.

### ⚠️ `visibleParaCliente` de la organización es lo que enciende las fotos

`cotizacionEditorStore.ts` siembra `prestadorVisible` del componente desde
`TravelOrganizacion::isVisibleParaCliente()`, y el normalizer público **sólo inyecta las imágenes
si ese flag está puesto**. Un resort con fotos cargadas y la organización sin marcar visible sale
sin ninguna, y no da error en ningún sitio.

Es el primer sitio donde mirar cuando «las fotos no salen».

### Siete servicios de prestador para doce tarifas, y es a propósito

Varias tarifas comparten el mismo servicio del prestador:

| Servicio del prestador | Lo usan |
|---|---|
| Lobby y recepción | los dos check-in y los dos check-out |
| Restaurante buffet | desayuno, almuerzo y cena buffet |
| Restaurantes temáticos | cena temática |
| Piscinas y playa | piscina y playa |
| Actividades y deportes | actividades recreativas |
| Espectáculos nocturnos | espectáculo nocturno |
| El resort | día libre (resumen) |

El motivo es doble. **Subir fotos es trabajo manual**, y con un servicio por actividad habría que
subir el lobby cuatro veces; esas cuatro copias se separan con el tiempo y nadie se entera. Y
**la guía las enseñaría una sola vez igualmente**, porque `galeriaPorBloque` deduplica por foto: las
copias serían trabajo invisible.

Si algún día un desayuno merece foto propia, se crea su servicio y se repunta esa tarifa. Partir es
barato; unificar cuatro copias divergentes, no.

### El servicio contenedor no tiene itinerario

`ACT_RESORT` («Actividades en resort») expone sus doce segmentos y doce componentes por los
**pools** —`travel_segmento_servicio_pool` y `travel_servicio_componentes_pool`— y no define ningún
`TravelItinerario`. Es el mismo patrón de `ALO`, `TRF_CUZ` y `VUELO`: un repertorio del que se toma
lo que haga falta, en vez de una plantilla fija.

Encaja con cómo se arma un resort: el primer día lleva check-in y la secuencia completa de
actividades; los días siguientes, un solo segmento de día libre.

### La tarifa va sin ningún filtro

`procedencia`, `edadMinima/Maxima` y `capacidadMinima/Maxima` quedan en `null`, y el monto en 0: son
actividades incluidas en el todo incluido, no se venden ni se restringen. El único dato que la
tarifa aporta es **a qué hotel pertenece esta versión de la actividad**.

### El comando

`app:travel:crear-actividades-resort` (con `--dry-run`). Idempotente por clave natural —slug de
segmento, nombre comercial de la organización, nombre del servicio—, así que repetirlo no duplica.

Va por comando y no por migración porque `titulo` y `contenido` llevan `#[AutoTranslate]`: un
`INSERT` en SQL se salta el listener y dejaría las fichas sólo en español.

Para el segundo resort **no vale re-ejecutarlo tal cual**: hoy la organización está en una
constante. Hay que parametrizarla (una opción `--organizacion`) y saltarse la creación de segmentos,
que ya existirán — el comando ya los detecta y los informa como «ya existe».

### Las excursiones que se intercalan, y sus DOS orígenes de foto (28/08/2026)

`SAONA` e `COCO_BONGO` son servicios aparte, no segmentos de `ACT_RESORT`. El motivo es de la
guía, no del catálogo: los bloques de un cotservicio se pintan **seguidos** (se agrupan por
`servicio.id`), y el pool de un cotservicio es el de su servicio maestro. Meterlas dentro pondría
«Actividades en resort» de encabezado sobre una excursión a Isla Saona.

Se intercalan **partiendo el día del resort en dos cotservicios** y colando la excursión en medio;
el orden lo da la hora. Repetir un servicio maestro varias veces en una cotización es el patrón
normal —«Alojamiento» aparece 12 veces en una propuesta real—.

⚠️ **Y aquí las dos excursiones NO sacan las fotos del mismo sitio, a propósito:**

| | De dónde salen sus fotos | Por qué |
|---|---|---|
| **Isla Saona** | del **propio segmento** (regla 1) | Es un **lugar**: la playa es la misma la opere quien la opere. Es el caso de Paracas. Sus tarifas nacen **sin prestador**. |
| **Coco Bongo** | del **servicio del prestador** | Es una **marca con locales en varias ciudades**: el segmento es genérico y el local concreto entra por la tarifa, igual que un resort. |

⚠️ **Las dos modalidades de Coco Bongo NO comparten servicio de prestador**, al revés que el lobby
del resort. Son **alternativas** —el pasajero contrata una o la otra, nunca las dos—, así que la
deduplicación de la galería no las taparía nunca y separarlas sí gana fotos distintas. La regla
completa: se comparte buzón cuando las fotos coincidirían; se separa cuando son excluyentes.

⚠️ Sus tarifas nacen **a 0**: son el vehículo para colgar prestador y servicio, no un precio.

### Las horas no son decorado: colocan el bloque en el día

Los grupos de un día se ordenan por su hora más temprana; sin hora caen a un desempate por
`orden`, que es frágil justo cuando hace falta —dos cotservicios del mismo servicio en un día,
que es lo que produce intercalar una excursión—.

Por eso los componentes de resort nacen con hora por defecto (desayuno 07:00, almuerzo 12:30, cena
19:00, espectáculo 21:30, Saona 08:00, Coco Bongo 22:00), y el comando las **sincroniza también en
los que ya existían, sin pisar una hora ya puesta**. Son horarios de resort all-inclusive, no de
un hotel concreto: se ajustan por cotización.

`ACT-RESORT-DIA_LIBRE` va **sin hora** a propósito: representa el día entero, y darle una lo
colocaría antes o después de cosas que en realidad lo contienen.

## 12. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| **Cambiar si un tipo lleva punto de recojo/entrega** | `src/Travel/Enum/ComponenteTipoEnum.php` | `puntosDeServicio()` (§11 quater) |
| **Cambiar qué lugar le toca a un componente** | `src/Travel/Command/TravelEtiquetarLugaresCommand.php` | `REGLAS` — patrón y a qué otros lugares arrastra (§11 ter) |
| **Escribirle a una organización proveedora** | `src/Travel/Service/Message/TravelProveedorDeContexto.php` | `para()` — el hilo lo abre `POST /message/conversations/abrir` |
| Cambiar qué datos de contacto siembra el hilo de un proveedor | `src/Travel/Service/Message/TravelOrganizacionMessageContext.php` | `getIdentificadores()` |
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
| Añadir un punto de recojo o entrega | `src/Travel/Entity/TravelPunto.php` | CRUD «Puntos de recojo» — **con dirección**, o no sirve |
| Decir dónde empieza y termina un segmento | `src/Travel/Entity/TravelSegmento.php` | `$inicioModo`/`$inicioPunto`, `$finModo`/`$finPunto` |
| Cambiar cómo se resuelve el origen/destino de un servicio | `src/Travel/Service/TravelPuntosDelServicio.php` | `para()`, `extremosDelDia()` |
| Marcar cuál es el servicio dueño del día | `src/Travel/Entity/TravelSegmentoComponente.php` | `$horaServicioCompleto` — único por (plantilla, día) |
| Añadir reglas de carga de puntos por nombre | `src/Travel/Command/TravelProponerPuntosCommand.php` | `REGLAS` — **gana la primera**; `PUNTOS` para los maestros |
| Cambiar qué se considera «falta el punto» | `src/Travel/Service/PuntosResueltos.php` | `estaCompleto()`, `faltantes()` |
| Marcar el servicio principal de un día (paquetes, multi-día) | `src/Travel/Controller/Crud/TravelSegmentoComponenteCrudController.php` | «Servicio principal del día» — uno por (plantilla, día); **ojo con la Categoría Operativa** |
| Limpiar promociones sin plantilla | `src/Travel/Command/TravelLimpiarPromocionesHuerfanasCommand.php` | `--dry-run`; **no toca `itinerarioContexto` ni las cotizaciones** |
| Dar servicio principal a una plantilla | `src/Travel/Command/TravelPromoverServicioPrincipalCommand.php` | `PROMOCIONES` — **añade fila, no modifica la global** |
| Montar la plantilla privada del Valle que faltaba | `src/Travel/Command/TravelCrearValleSagradoPrivadoCommand.php` | clona la privada existente y cambia el recojo; **desambigua por componente, no por nombre** |
| Renombrar una plantilla con título traducido | `src/Travel/Command/TravelRenombrarValleVipPrivadaCommand.php` | **`setSobreescribirTraduccion(true)` antes de tocar el título**, y verificar después |
| Afinar los textos del contacto (7 idiomas) | `src/Travel/Command/TravelAjustarTextosContactoCommand.php` | activa `sobreescribirTraduccion` y **compara contra lo anterior**, no contra una palabra testigo |
| Añadir el contacto con el pasajero a una plantilla | `src/Travel/Command/TravelCrearContactoMachupicchuCommand.php` | `PLANTILLAS` — **la tarifa de 0 es lo que lo hace pedible** |
