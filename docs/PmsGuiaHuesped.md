# Guía del Huésped y Catálogo Público de Unidades

Cómo el mismo CMS de guía alimenta dos audiencias con contratos distintos —el huésped con
reserva y el visitante anónimo—, quién decide qué ve cada uno, y por qué esa decisión se tomó
del lado del servidor.

Alcance: `src/Pms/Guia/`, `src/Pms/Enum/PmsGuiaVisibilidad.php`, `src/Api/Provider/Pms/`,
las entidades `PmsGuia*` / `PmsUnidad` / `PmsEstablecimiento`, `src/Api/Controller/Pax/`
(heredado), y en `pax/`: `views/huesped/`, `stores/huesped/`, `core/RichContentEngine.ts`.

---

## Índice

1. [Vocabulario](#1-vocabulario)
2. [Las dos audiencias y el enum que las separa](#2-las-dos-audiencias-y-el-enum-que-las-separa)
2b. [El catálogo es una entidad propia](#2b-el-catálogo-es-una-entidad-propia-no-la-guía-filtrada)
3. [La matriz de acceso](#3-la-matriz-de-acceso)
4. [Flujo de una petición](#4-flujo-de-una-petición)
5. [Rutas, slugs y endpoints](#5-rutas-slugs-y-endpoints)
6. [Qué se arregló y qué sigue roto](#6-qué-se-arregló-y-qué-sigue-roto)
7. [Gotchas](#7-gotchas)
8. [Dónde tocar para cambiar X](#8-dónde-tocar-para-cambiar-x)
9. [Los cuatro temas que faltaban](#9-los-cuatro-temas-que-faltaban-y-las-reglas-que-ahora-viven-aquí)

---

## 1. Vocabulario

| Término | Significado |
|---|---|
| **Guía** | `PmsGuia`. Una por unidad (`OneToOne`). Es el CMS: título + secciones ordenadas. |
| **Sección** | `PmsGuiaSeccion`. Agrupa ítems. Reutilizable entre guías vía `PmsGuiaHasSeccion` (`esComun`). |
| **Ítem** | `PmsGuiaItem`. La unidad de contenido: título, cuerpo HTML i18n, galería, botón. |
| **Visibilidad** | `PmsGuiaVisibilidad`. Vive en el **ítem**. Cuatro niveles crecientes (§3). |
| **Catálogo** | `PmsCatalogo`. Una por unidad. El escaparate público, con estructura propia (§2.b). |
| **Bloque** | `PmsCatalogoBloque`. Agrupa el contenido del escaparate. Vive en la RELACIÓN, no en el ítem. |
| **Acceso** | `PmsGuiaAcceso`. Situación de quien pide la guía. Se calcula por petición, no se guarda. |
| **Ventana** | Desde 24 h antes del check-in hasta el final del día del check-out. |
| **Placeholder** | `{{ door_code }}` dentro del cuerpo de un ítem. Lo resuelve el backend. |

---

## 2. Las dos audiencias y el enum que las separa

Hasta ahora `PmsGuia::getSeccionesApi()` devolvía **todas** las secciones activas con **todos**
sus ítems, y ese mismo árbol servía a los dos públicos. Las credenciales nunca viajaron
—`codigoPuerta`, `codigoCaja` y `wifiNetworks` no tienen `#[Groups]`— pero sí la estructura
completa: normas de la casa, avisos internos y el texto que rodea a la caja fuerte, accesibles
para cualquiera con el UUID de una unidad.

`PmsGuiaVisibilidad` es el corte:

| Caso | Para qué | Ejemplos |
|---|---|---|
| `Publico` | **Jubilado** — ver el aviso de abajo | — |
| `Cliente` | Cualquiera con el localizador | Normas, instrucciones de la lavadora |
| `ClienteConfirmado` | Además, con pago confiable | Detalles que no se adelantan sin pago |
| `SoloVentana` | Además, dentro de la ventana | Código de puerta, caja fuerte, WiFi |

Son peldaños crecientes; el detalle de qué añade cada uno está en §3.

> ### ⚠️ `PmsGuiaVisibilidad` rige la GUÍA, no el escaparate
>
> Hasta `Version20260804200000`, `Publico` significaba «sale en el catálogo». Ya no:
> **el escaparate lo decide la pertenencia a `PmsCatalogo`** (§2.b), no un nivel de este enum.
>
> Son dos ejes **ortogonales**, y esa es la mejora: antes un solo enum hacía dos trabajos —decidir
> quién lo ve en la guía Y si sale en la página pública—, y por eso el catálogo heredaba la
> estructura del manual. Ahora:
>
> - **Visibilidad** → quién lo ve **en la guía**.
> - **Pertenencia a `PmsCatalogo`** → si sale **en el escaparate**, y en qué bloque.
>
> Un ítem puede estar en el catálogo y ser `SoloVentana` en la guía sin contradicción: son
> preguntas distintas. `Publico` quedó sin trabajo y **no debe usarse**.

**El enum vive en el ítem y NO en la sección.** `PmsGuiaSeccion` deriva su visibilidad de los
ítems que le quedan tras filtrar (`PmsGuiaArbolFiltro::podar()`): si se queda sin ninguno,
desaparece. Con el flag en los dos sitios habría dos campos capaces de contradecirse y secciones
vacías en el catálogo.

> El default es `Cliente`, y la migración `Version20260803230000` deja **todo** el contenido
> existente ahí. El catálogo público arrancó vacío a propósito: publicar era una decisión
> editorial que se toma ítem a ítem. La única excepción del backfill son los ítems cuyo cuerpo
> usa un placeholder sensible, que pasan a `SoloVentana` porque es exactamente lo que el sistema
> anterior ya enmascaraba fuera de ventana.
>
> Los nombres cambiaron en `Version20260804140000`, cuando el enum pasó de 3 niveles a 4:
> `privado`→`cliente` y `llegada`→`solo-ventana`, con `cliente-confirmado` naciendo vacío. La
> equivalencia es exacta, así que ningún ítem cambió de audiencia con esa migración.

## 2.b El catálogo es una entidad propia, no la guía filtrada

### El problema

El escaparate reutilizaba el árbol de secciones de la guía y solo dejaba pasar los ítems
`publico`. Heredaba por tanto la **arquitectura de la información de un manual operativo**: la
guía agrupa por *cuándo lo necesitas* (llegada, estancia, normas), así que al visitante le
aparecía un encabezado «Entrada a la casa · Llegada, llaves y acceso» en mitad de una página
comercial. Y no había forma de meter contenido que existiera **solo** en el escaparate.

Que el desajuste ya se notaba lo delataba `seccionesOrdenadas` en la vista pax: existía solo para
empujar la sección `descriptivo` al principio. Un parche sobre una jerarquía ajena.

### El modelo

```
PmsCatalogo                    1:1 con PmsUnidad (espejo de PmsGuia)
  ├─ titulo / subtitulo        comerciales, independientes de los de la guía
  ├─ activo                    despublica el escaparate entero
  └─ catalogoHasItems ─┐
                       │
PmsCatalogoHasItem  ◄──┘       "ManyToMany con vitaminas"
  ├─ item   → PmsGuiaItem      se REUTILIZA: el contenido no se duplica
  ├─ bloque → PmsCatalogoBloque
  ├─ orden                     posición DENTRO del bloque
  └─ activo
```

**El bloque vive en la relación, no en el ítem**, y esa es la clave: no describe al ítem, describe
el papel que juega en el escaparate. La dirección de la casa es «Entrada a la casa» en la guía y
«Ubicación» aquí — con el campo en el ítem habría que elegir una lectura y falsear la otra. Es el
mismo patrón que `PmsGuiaSeccionHasItem`, que ya resolvía esto para la guía.

**Ítems huérfanos de sección.** Un `PmsGuiaItem` no necesita pertenecer a ninguna sección: la
relación vive en el join. Así se puede crear contenido puramente comercial —«Por qué elegirnos»,
una promoción— que exista solo en el escaparate y no ensucie el manual del huésped.

**El orden de los bloques lo fija el enum**, no la base de datos: `PmsCatalogoBloque::cases()`
recorre en el orden de declaración, igual que `PmsTipoCargo` en el desglose financiero. La
secuencia que vende es una decisión de producto. Dentro de cada bloque manda `orden`.

**El título del bloque no viaja**: el backend manda el tipo y el front lo resuelve por i18n
(`cat_bloque_<tipo>`, sembradas en `Version20260804210000`), porque la página es pública y
multiidioma. Añadir un caso al enum obliga a sembrar su etiqueta **y** a añadirla al mapa de
respaldo de `CatalogoUnidadView`.

> `cat_bloque_destacado` es el caso raro: **tiene etiqueta pero la página no la pinta**. Se
> sembró vacía y luego se le puso texto («Lo destacado», `Version20260804220000`) porque en el
> editor la fila salía en blanco y no se sabía qué se estaba editando. Que ese bloque encabece
> sin título lo decide `esDestacado()` en `CatalogoUnidadView`, no el contenido de la semilla:
> vaciarla otra vez no cambiaría nada en la página pública.

> **La red de seguridad no cambia.** `PmsUnidadCatalogoProvider` construye
> `PmsGuiaAcceso::publico()` y un `PmsGuiaContexto` **sin estancia**, así que no se carga ni una
> credencial en memoria y cualquier `{{ door_code }}` se sirve con el mensaje de bloqueo.
> Colocar por error un ítem operativo en el escaparate no filtra nada.

### Editarlo

`PmsCatalogoCrudController` (menú *Guía Digital → Escaparate público*). Cuelga de ese submenú a
propósito: el escaparate **reutiliza** los ítems de la guía, y ponerlo en una sección aparte
sugeriría que hay que escribir el contenido dos veces.

El campo que hace útil el detalle es `virtualBloques`: recorre `PmsCatalogoBloque::cases()` y
pinta la página **en el orden real en que se verá**. La colección cruda sale ordenada por `orden`
y mezcla bloques, así que por sí sola no deja ver la forma final — que es lo único que le importa
a quien edita. Se apoya en `getVirtualBloques()`, un stub que devuelve `''`: EasyAdmin exige que
la propiedad exista aunque el contenido lo pinte el `formatValue` (mismo recurso que
`PmsGuia::getVirtualSecciones()`).

> **El orden ENTRE bloques no se edita desde el panel**, y es deliberado: lo fija el orden de
> declaración del enum. La secuencia que vende es una decisión de producto, no un campo que
> cada operador deba recordar. El formulario solo decide en qué bloque va cada contenido y su
> posición *dentro* de él.

### Migración

`Version20260804200000` crea las dos tablas y siembra: un catálogo por unidad con ítems públicos,
y una fila por ítem. El bloque se deduce del **icono** (`fa-images`→fotos,
`fa-location-dot`→ubicación, resto→descripción), que es el mismo criterio con el que
`Version20260804150000` pobló el catálogo en su día — y **la última vez que hace falta ese
heurístico**: a partir de aquí el bloque es un campo explícito que elige el editor. Era la deuda
que esa migración dejó anotada («si el editor cambia de convención de iconos, la clasificación
deja de tener base»).

---


## 3. La matriz de acceso

Son **dos ejes**. El vertical lo calcula `PmsGuiaAcceso::paraEvento()` a partir de la estancia;
el horizontal lo elige el editor en cada ítem (`PmsGuiaVisibilidad`). `permite()` los cruza, y
**es el único sitio donde se decide qué se ve.**

### Los cuatro niveles de visibilidad

Una escalera estrictamente creciente: `Publico ⊂ Cliente ⊂ ClienteConfirmado ⊂ SoloVentana`.
Cada peldaño añade **una** condición al anterior, y por eso no pueden contradecirse.

| Nivel | Condición que añade | Para qué |
|---|---|---|
| `publico` | ninguna | **Jubilado** (§2). El escaparate lo decide `PmsCatalogo` |
| `cliente` | tener el localizador | Cómo llegar, normas, contacto |
| `cliente-confirmado` | pago confiable | Lo que no se adelanta a quien no ha pagado |
| `solo-ventana` | ventana abierta | Códigos de puerta, caja fuerte, WiFi |

### La matriz

| Estado \ visibilidad | `publico` | `cliente` | `cliente-confirmado` | `solo-ventana` |
|---|---|---|---|---|
| `Publico` (sin estancia) | ✓ | ✗ | ✗ | ✗ |
| `SinPago` | ✓ | ✓ | 🔒 | 🔒 |
| `Pendiente` (faltan >24 h) | ✓ | ✓ | ✓ | 🔒 |
| `Activa` (ventana abierta) | ✓ | ✓ | ✓ | ✓ |
| `Expirada` (post check-out) | ✓ | ✓ | ✓ | 🔒 |

🔒 = no se puede ver, **pero el ítem se anuncia con candado** (siguiente apartado).

Tres decisiones que no se leen en el código:

- **`SinPago` conserva `cliente`.** Cómo llegar y las normas no son secretos, y quien tiene el
  localizador lo sacó de su correo. Lo que el pago protege empieza en `cliente-confirmado`.
- **`cliente-confirmado` sigue abierto en `Expirada`.** Lo que se pagó, pagado está; lo que
  caduca con el check-out es la ventana, o sea `solo-ventana`.
- **Estancia cancelada o con `guiaDisabled` no aparece en esta matriz.** Deja de ser cliente
  *de esa unidad* (puede tener otras vigentes) y `getEventosActivosGuia()` la descarta antes: ni
  sale en la lista de estancias de la reserva, ni se puede abrir su guía — `PmsGuiaHuespedProvider`
  devuelve 404. `paraEvento()` la degrada a `Publico` solo como red de seguridad, por si alguien
  llama al método con un evento crudo.

### Ítem bloqueado: ¿se oculta o se anuncia?

`PmsGuiaAcceso::debeAnunciarBloqueo()`. **A un huésped identificado siempre se le anuncia**: tiene
el localizador, no es un desconocido, y esconderle el ítem le hace creer que la guía no trae esa
información en vez de entender qué le falta para verla. Cada estado dice algo distinto:

- **`SinPago`** → `[Disponible al confirmar]`. La acción está en su mano; es justo la que se le
  quiere pedir.
- **`Pendiente`** → `[Disponible el 12/08 a las 15:00]`, con `bloqueadoHasta` poblado.
- **`Expirada`** → `[Reserva finalizada]`. Hubo algo ahí y caducó.

La única excepción es el visitante sin estancia: a él no se le insinúa ni la estructura de la
guía privada.

En todos los casos el valor real **nunca sale del servidor**: esto decide la visibilidad del
ÍTEM, no la del dato. De eso se ocupa `permite()`, y su matriz no cambia.

> ⚠️ **Esto no era verdad hasta agosto de 2026, aunque este doc y el comentario del filtro lo
> afirmaban.** `podarItems()` llamaba al mismo `interpolarItem()` que para un ítem visible, y
> eso sólo enmascara los PLACEHOLDERS: el texto editorial completo viajaba en el JSON y el
> front lo pintaba con un badge de «aún no disponible». No filtró nada porque hoy los ítems
> bloqueados guardan lo sensible en placeholders —medido: 0 ítems `cliente-confirmado` y 2
> `solo-ventana`—, pero el primero que escribiera un secreto en el TEXTO lo habría regalado.
>
> **Los `{{ marcadores }}` ya no se pierden al traducir.** Google no los dejaba quietos: les
> traducía el NOMBRE de dentro (`{{ medios_pago }}` → `{{ payment_methods }}`), y entonces el
> interpolador dejaba de reconocerlos y el huésped veía la llave en crudo. `ProtectorDeMarcadores`
> los enmascara antes de mandar el texto y los restaura al volver; si el traductor se come uno,
> esa traducción NO se guarda. Ver `docs/Mensajeria.md`.

> Hoy el cuerpo de un ítem bloqueado **se sustituye** por el mensaje de bloqueo en los siete
> idiomas (`PmsGuiaArbolFiltro::mensajeDeBloqueo()`); sólo el título se interpola, porque hace
> falta para nombrarlo.
>
> Y había un agravante en el chat: `ConsultarGuiaSkill::cuerpoParaElAgente()` empezaba por
> `agente_contenido` y lo devolvía **ignorando el bloqueo**, porque ese campo no lo toca el
> filtro. Ahora corta antes: un ítem con candado devuelve su motivo, no su contenido.

> **`bloqueado` es un campo propio, no `bloqueadoHasta !== null`.** Derivarlo de la fecha era
> correcto mientras solo se anunciaba `Pendiente`, pero `SinPago` y `Expirada` no tienen fecha que
> prometer: el ítem llegaba con `bloqueado: false`, sin candado, con pinta de ítem normal y un
> `[Disponible al confirmar]` por todo cuerpo. `PmsGuiaArbolFiltro` escribe hoy los dos campos —
> `setBloqueado()` marca el candado y `setBloqueadoHasta()` es solo el "cuándo" opcional.

### Tres comprobaciones, no una

`calcularAcceso()` (la versión anterior, en `GuiaHelperResponseTrait`) solo miraba el estado de
pago. `PmsGuiaAcceso::paraEvento()` comprueba, en orden:

1. `estado ∈ PmsEventoEstado::MOSTRAR_EVENTO_GUIA` — ni cancelada ni bloqueo.
2. `!isGuiaDisabled()` — el operador puede excluirla a mano.
3. `estadoPago ∈ PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES`.

Los dos primeros son nuevos y arreglan una fuga real. Ver §6.

---

## 3.b 🔥 Un dato que DEBE salir no puede vivir en un ítem de guía

Los teléfonos de atención estaban escritos dentro del texto del ítem «Horario solicitudes
(general)». Y un ítem sólo llega al modelo si **el índice de temas lo selecciona** por sus
`agenteTerminos` — que ahí eran `horario de atención`, `a qué hora atienden`, `contacto`.

El **27/08/2026** una huésped escribió *«acabamos de llegar»*. El triaje enrutó bien —a
`consultar_codigos`—, la skill contestó bien —«no está confirmada, los códigos salen al
confirmar el pago»— y el escalado salió bien. Pero **ningún teléfono llegó al modelo**, porque
esa frase no casa con ninguno de esos términos. Sin una salida que ofrecer, el modelo unió los
dos hechos que sí tenía —«falta pagar» y «avisé al equipo»— y se inventó el puente:
*«alguien les ayudará con el registro y el pago»*. Nadie iba a ir: la entrada es autónoma.
Estuvo **una hora en la puerta**.

La regla que sale de ahí:

> **Si un dato tiene que salir sí o sí en un momento concreto, va por el camino determinista.**
> La guía es para lo que se consulta *cuando se pregunta*; lo que se necesita *cuando pasa algo*
> lo devuelve la skill de ese algo.

Por eso `telefono_atencion` y `telefono_yape` son ahora campos de `PmsEstablecimiento` y los
devuelve `ConsultarCodigosSkill` en `contacto`, junto al `motivo` y sin segundo paso — igual que
ya hacía con el código de la caja.

⚠️ **Y no se duplican.** El ítem de guía deja de llevarlos escritos: un teléfono en dos sitios es
un teléfono que un día se cambia en uno solo. El ítem sigue contestando *«¿a qué hora atienden?»*,
que es su pregunta. Los borra `Version20260827234500` — hasta el 27/08/2026 la ficha seguía
teniendo la copia literal de `telefono_atencion` y `telefono_yape`.

⚠️ **Pero borrarlos a secas los habría NEGADO.** Está probado con el depósito de garantía (ver
`CLAUDE.md`): lo que se le quita del texto, el modelo lo convierte en «no existe» —y *«no tenemos
teléfono»* es peor que un número viejo—. Por eso la ficha conserva un **puntero sin contenido**:
dice que los números existen, que uno es el del Yape y que se piden a `consultar_codigos` en
`contacto`. No dice cuáles son.

Y para que ese puntero tenga destino, `contacto` pasó a salir **también cuando el código sí está
disponible**, no sólo cuando viene bloqueado. Si no, quitarlos de la ficha los habría dejado
inalcanzables para cualquiera que ya pudiera entrar.

⚠️ **`telefonoPrincipal` es otra cosa**: el comercial, el que está en la web y en las OTA y se
sirve al catálogo público. Son **tres números para tres usos**, y mandar al huésped al comercial
cuando está en la puerta es mandarlo al sitio equivocado en el peor momento.

## 4. Flujo de una petición

```
GET /platform/client/pax/pms/pms_guia/{localizador}
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│ PmsGuiaHuespedProvider::provide()                       │
└─────────────────────────────────────────────────────────┘
        │
        ├─ PmsReserva por localizador ──────────► null ⇒ 404
        │
        ├─ getEventosActivosGuia()  ◄── filtra estado + guiaDisabled
        │       └─ elegirEvento(slug) ─────────► null ⇒ 404
        │
        ├─ PmsGuiaAcceso::paraEvento(evento)   ── LA REGLA (§3)
        ├─ PmsGuiaContexto::construir(unidad, evento)
        │       ├─ $valores    (nombre, unidad, fechas)  → siempre
        │       └─ $sensibles  (door/safe/keybox)        → solo si Activa
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│ PmsGuiaArbolFiltro::podar()                             │
│   por cada sección → por cada ítem:                     │
│     acceso->permite(visibilidad) ?                      │
│        sí → interpolar y conservar                      │
│        no → debeAnunciarBloqueo() ? bloquear : DESCARTAR│
│   sección sin ítems ⇒ se descarta la sección            │
└─────────────────────────────────────────────────────────┘
        │
        ▼  PmsGuiaInterpolador::interpolar()   ({{ door_code }} → valor | mensaje)
        │
        ▼  propiedades virtuales de PmsGuia (no persistidas)
    seccionesParaCliente · contextoParaCliente · redesWifiParaCliente · accesoParaCliente
        │
        ▼  normalizationContext: ['groups' => ['pax_guia:read']]
```

El catálogo público (`PmsUnidadCatalogoProvider`) recorre lo mismo con
`PmsGuiaAcceso::publico()` y `PmsGuiaContexto::construir($unidad, null)` — que ni siquiera carga
las credenciales en memoria — y serializa con `pax_catalogo:read`.

### Interpolación: por qué se movió a PHP

`RichContentEngine.interpolateString()` sustituía los placeholders **en el navegador**. Para
poder hacerlo, el backend tenía que mandarle al cliente el diccionario completo de valores
—`text_fixed` con `door_code`, `safe_code`, `keybox_*`— y el front decidía cuál pintar. Bastaba
abrir las herramientas de desarrollo para leer el código de la puerta antes de tiempo.

`PmsGuiaInterpolador` hace la sustitución antes de serializar. Reparto actual:

| Placeholder | Quién lo resuelve | Por qué |
|---|---|---|
| `{{ door_code }}`, `{{ guest_name }}`, … | **PHP** (`PmsGuiaInterpolador`) | Son datos, y algunos son sensibles |
| `{{ video: url }}`, `{{ img: }}`, `{{ map: }}`, `{{ widget: wifi }}` | **Vue** (`RichContentEngine`) | Son maquetación; los componentes están en el front |
| `{{ video_ventana: url }}`, `{{ img_ventana: url }}` | **Los dos** | PHP decide si la URL sale; Vue pinta el bloque |
| `{{ medios_pago }}` | **Vue** (`MediosPagoWidget`) | Los números de cobro viven en el catálogo `FinMedioCobro`, no en el texto — mismo motivo que el WiFi. Llegan por `guia.mediosPago`, ya filtrados por procedencia. Ver `docs/Mensajeria.md` §16 |

El inventario completo y actualizado no está aquí, sino en el propio formulario:
`PmsGuiaItemCrudController::ayudaPlaceholders()` lo pinta bajo el cuerpo del ítem, que es donde
hace falta. **Un placeholder nuevo se toca en tres sitios**: su resolutor, esa chuleta, y esta
sección.

#### Medios con ventana (`*_ventana`)

Son la excepción al reparto. El editor escribe `{{ video_ventana: url }}` y
`PmsGuiaInterpolador::resolverMediaVentana()` reescribe **la clave** antes de serializar:

- Ventana abierta → `{{ video: url }}`. El front usa el bloque de siempre y no se entera de que
  había una condición.
- Ventana cerrada → `{{ videobloqueado: Disponible el 12/08… }}`. **La URL no viaja.**
  `MediaBloqueadaBlock.vue` pinta un marco con la proporción del medio y el texto en el centro.

> Esto NO se podía resolver en el front. Si el navegador recibe la URL y solo decide no pintarla,
> el enlace sigue en el payload y se lee en las herramientas de desarrollo — el mismo fallo que
> tenía `interpolateString()` con los códigos de puerta. Ocultar no es proteger.

`RichContentEngine` registra además `video_ventana` e `img_ventana` apuntando al bloque
bloqueado, como red de seguridad: si un texto llegara sin pasar por el interpolador, se pinta el
marco en vez de volcar la URL como texto plano. Por eso su regex acepta `_` en la clave.

Las dos expresiones regulares tienen que seguir siendo compatibles: la de PHP no admite `:`
dentro de la clave, que es justo lo que deja pasar los bloques de maquetación. **Si se toca una,
se toca la otra**, o habrá placeholders que un lado sustituye y el otro enseña crudos.

Se conserva la forma i18n `[{language, content}]` en lugar de resolver el idioma en el servidor:
el selector de idioma de la guía cambia el texto en caliente y no debe disparar una petición.

#### El contrato de estilo: `guia-dato`

La sustitución **no devuelve texto pelado**. `PmsGuiaInterpolador::envolver()` envuelve todo
valor resuelto en un `<span>` con clase, y ahí está el único punto de acuerdo entre los dos
lados:

| Clase emitida | Cuándo | Qué debe transmitir |
|---|---|---|
| `guia-dato` | valor real ya revelado | dato de verdad, resaltado dentro del párrafo |
| `guia-dato guia-dato--bloqueado` | ventana cerrada → mensaje `[Disponible el …]` | "esto se rellenará", nunca confundible con el dato |

**Es un contrato, no una sugerencia: PHP emite las clases y el front tiene que definirlas.**
Vivieron un tiempo sin CSS en ninguna parte de `pax/`, así que el `[Disponible el 11/09/2026 a
las 14:00]` se leía como texto corriente en mitad de la frase — el huésped no tenía forma de
saber que era un hueco pendiente y no una instrucción literal. Los estilos están hoy en
`pax/src/components/RichText/RichTextRenderer.vue`.

Los corchetes del mensaje (`PmsGuiaMensajes`) se mantienen a propósito **además** del estilo:
son la red de seguridad si el CSS no llega (correo, copiar-pegar, un renderer nuevo).

---

## 5. Rutas, slugs y endpoints

| Ruta pax | Qué es | Endpoint |
|---|---|---|
| **`/huesped/reserva/:localizador`** | **Entrada canónica** — índice de estancias | `GET /platform/client/pax/pms/pms_reserva/{loc}` |
| `/huesped/reserva/:localizador/:unidad` | **Guía de una estancia** (`guia_huesped`) | `GET /platform/client/pax/pms/pms_guia/{localizador}/{unidadSlug}` |
| `/huesped/reserva/:localizador/guia` | La misma, sin elegir unidad (`guia_huesped_corta`) | `GET /platform/client/pax/pms/pms_guia/{localizador}` |
| `/:establecimiento/:unidad` | **Catálogo público** | `GET /platform/public/pax/pms/pms_guia/{est}/{unidad}` |

Esas son **todas**. No hay circuito heredado: `/pax/client/guiahelper/{uuid}`,
`/pax/public/guiahelper/{uuid}` y `/public/pax/pms/pms_guia/pms_unidad/{uuid}` están borrados,
igual que el grupo de serialización `pax_evento:read` que los alimentaba.

### 5.1. El front de la guía

| Pieza | Archivo |
|---|---|
| Vista | `pax/src/views/huesped/HuespedGuiaView.vue` |
| Store | `pax/src/stores/huesped/paxHuespedGuiaStore.ts` |
| Tipos | `pax/src/types/paxHuespedGuiaModel.ts` (espejo de `pax_guia:read`) |
| Enlace de entrada | `PmsReservaView::verGuiaEvento()` |

**Navegación de 3 niveles**, heredada de la `GuiaUnidadView` anterior porque es lo que el
huésped ya conocía:

| Nivel | Qué muestra |
|---|---|
| 1 · Portada | hero (tap → sección descriptiva), aviso de acceso, anfitrión, **ingreso destacado con sus pasos como botones directos al nivel 3**, rejilla del resto de secciones |
| 2 · Sección | lista de sus ítems; con **un solo ítem se salta el paso** y se pinta el contenido |
| 3 · Ítem | texto (`RichTextRenderer`, que expande `{{ widget: wifi }}`), galería con lightbox **solo si `tipo === 'album'`** (ver §7) y botón |

Los niveles 2 y 3 son **capas que entran deslizándose sobre la misma ruta**, y su estado vive en
la **query** (`?section=…&item=…`), no en un `ref` del componente. El motivo no es estético: así
el «atrás» del móvil retrocede **un nivel** en vez de sacar al huésped de la guía. `volver()`
usa `router.back()` si hay historial y cae a `replace` si se entró por enlace directo.

> **Gotcha — al abrir un nivel hay que scrollear la VENTANA, no el contenedor.** El marco es
> `min-h-screen`, así que en móvil el `h-full` del nivel 1 no acota altura: quien scrollea es la
> ventana y el contenedor crece con el contenido (en escritorio sí manda el div, porque
> `md:h-[90vh]` da altura definida). Como las capas son `absolute inset-0`, se montan al
> principio del contenedor: si la ventana sigue a media página, se ve el hueco en blanco de
> debajo de una capa más corta que la portada. `resetScroll()` scrollea la ventana **y** los tres
> contenedores, con `behavior: 'auto'` — con desplazamiento suave el blanco se ve igual durante
> la animación de entrada. Ya se perdió una vez al reescribir la vista.

Consecuencia a respetar: **la recarga de datos observa solo la estancia**
(`[props.localizador, props.unidad]`). Observar `route.params` entero —o la ruta completa—
dispararía una recarga con spinner en cada navegación entre secciones.

Decisiones que no se ven leyendo el componente:

- **Ni un `v-if` de permisos.** El árbol llega podado y con los `{{ placeholders }}` resueltos:
  si un código está en el payload, se enseña. `acceso.estado` solo elige el aviso de cabecera y
  `redesWifi` llega vacío con la ventana cerrada — no hay contraseña enmascarada que interpretar,
  que era el contrato viejo (`texto.includes('*')`).
- **Vista separada del escaparate**, no un `mode: 'public' | 'guest'`. Ese parámetro fue el origen
  de la fuga que documenta §2.
- **El escaparate enlaza a sus casitas hermanas** (`unidadesHermanas`), y viajan en la MISMA
  respuesta: son un puñado por establecimiento y una segunda petición para pintar una tira de
  tarjetas costaría más que enviarlas. Sin esto el catálogo era un callejón sin salida — quien
  entraba a una casita no podía ver las demás salvo adivinando la URL.
  > **Cada hermana pasa la poda completa**, no solo `activo && guia`:
  > `PmsUnidadCatalogoProvider::hermanasPublicadas()` comprueba que le quede al menos una sección
  > pública. Una unidad con guía pero sin contenido publicado da 404 en el propio catálogo, y un
  > enlace muerto en una página pública es peor que no ofrecer el salto. Cuesta una poda por
  > hermana; si algún día son cientos, ahí es donde hay que paginar.
- **`store.loading` NO cubre el arranque.** Nace en `false` con `catalogo` en `null`, y esa
  combinación es indistinguible de «no encontrado». Como la vista espera primero a
  `cargarConfiguracion()`, la ventana no era un fotograma sino toda esa petición: el visitante
  veía «Alojamiento no disponible» y después el spinner. Se cubre con un flag `cargando` propio,
  igual que en `HuespedGuiaView`. **Mismo patrón para cualquier vista pax que encadene la config
  antes de su propia carga.**
- **El store no persiste y limpia al desmontar.** El anterior guardaba los códigos en
  `localStorage` sin caducidad: un móvil prestado seguía abriendo la puerta meses después.
- **El enlace va por `{localizador}/{slug de unidad}`**, no por el UUID del evento. Para eso
  `PmsUnidad::getSlug()` se añadió al grupo `pax_reserva:read`: sin él, el índice de estancias no
  puede construir la URL. El botón apuntaba a una ruta `guia_evento` borrada con el contrato
  viejo, y reventaba el router con `No match for {"name":"guia_evento"}`.
- **La sección `ingreso` se destaca** en la portada (en el escaparate encabeza la `descriptivo`):
  quien abre esto recién llegado busca cómo entrar, no la descripción del sitio. Por eso sus
  ítems son botones directos al nivel 3, sin pasar por la lista.
- **El WiFi no tiene tarjeta propia en la portada.** Se pinta donde el editor puso
  `{{ widget: wifi }}` dentro de un ítem; duplicarlo arriba mostraba las credenciales dos veces.

### `/huesped/reserva/:localizador` es intocable

Es la URL que se reparte a los clientes: va en correos de confirmación y en mensajes ya
enviados, así que **no se renombra, no se mueve y no se redirige**. Todo lo demás de la rama del
huésped cuelga de ella.

De ahí salen dos consecuencias de diseño:

- La guía de una estancia es una **hija** de esa ruta (`…/:localizador/:unidadSlug`), no una
  rama hermana con otro prefijo. El enlace que ya circula sigue siendo el ancla.
- El prefijo `huesped` está **reservado** en el router (ver el `path` de `catalogo_unidad`): un
  establecimiento cuyo slug fuera "huesped" no puede secuestrar esa rama.

El backend ya sirve las dos formas: `PmsGuiaHuespedProvider` responde a `{localizador}` a secas
—devolviendo la primera estancia cronológica, para que un enlace corto siempre lleve a algún
sitio útil— y a `{localizador}/{unidad}` para elegir una concreta.

### Slugs

`PmsEstablecimiento::$slug` + `PmsUnidad::$slug`, ambos `UNIQUE` global, generados por
`PmsSlugListener` con `AsciiSlugger`. El de la unidad se prefija con el del establecimiento para
que dos "Habitación 1" de hoteles distintos no compitan en un espacio de nombres que es global.

**El slug no se regenera al renombrar.** Una vez publicada, la URL es una dirección que la gente
comparte y que indexan los buscadores; cambiar el nombre comercial no debe romper enlaces vivos.
Para cambiarla, se edita el slug a mano.

El slug es **solo del escaparate**. La guía del huésped va por localizador de reserva.

### Orden en el router de Vue

`/:establecimiento/:unidad` va **la última antes del fallback**: es la ruta más genérica que
existe (dos segmentos libres). `vue-router` puntúa los segmentos estáticos por encima de los
dinámicos, así que `/file/XJ922M` y `/catalogo/...` ganan igualmente — pero declararla al final
lo deja explícito.

---

## 6. Qué se arregló y qué sigue roto

### Arreglado

| Problema | Dónde estaba | Arreglo |
|---|---|---|
| **Reserva cancelada pero pagada entregaba los códigos reales** | `calcularAcceso()` solo miraba `estadoPago`; cancelar no lo resetea | `PmsGuiaAcceso::paraEvento()`, comprobación 1 |
| **`guiaDisabled` se saltaba por URL directa** | `GuiaHelperClientController` hacía `find($id)` sin filtrar | comprobación 2 + `getEventosActivosGuia()` en el provider |
| **El árbol completo era público** | `getSeccionesApi()` no filtraba nada | `PmsGuiaVisibilidad` + `PmsGuiaArbolFiltro` |
| **Los códigos viajaban al navegador** | `text_fixed` los mandaba siempre | `PmsGuiaInterpolador` (§4) |
| **La tarjeta de anfitrión nunca se pintaba** | `buildResponse()` jamás puso `host_name`/`host_whatsapp` en `text_fixed`, pero `GuiaUnidadView` los leía | `PmsGuiaContexto::construir()` los cablea al establecimiento |

| **Enumeración de localizadores** | 6 caracteres sobre 31 símbolos, sin freno | `PmsGuiaThrottle` (§6.1) |

### 6.1. Por qué hay un throttle

El localizador **es** la credencial. `LocatorTrait` lo genera con 6 caracteres sobre un alfabeto
de 31: 31⁶ ≈ 887 millones. Suena a mucho hasta que se cuenta el espacio **útil** — al atacante no
le hace falta adivinar uno concreto, le vale cualquiera que exista:

| Reservas vivas | Intentos por acierto | A 100 req/s |
|---|---|---|
| 1.000 | ~887.500 | 2,5 h |
| 5.000 | ~177.500 | **30 min** |
| 20.000 | ~44.375 | **7 min** |

Y detrás de un acierto está el nombre del huésped, sus fechas y —dentro de la ventana— el código
de la puerta.

`PmsGuiaThrottle` cuenta **solo los fallos** por IP, no las peticiones: un huésped legítimo pide
siempre un localizador que existe, así que nunca consume presupuesto y puede recargar su guía
sin límite. Un enumerador produce casi solo fallos y se queda seco en 10 intentos por ventana de
15 minutos.

Dos detalles que no son adorno:

- **Todos los caminos de 404 consumen presupuesto**, no solo "la reserva no existe". Si un
  localizador válido pero sin estancias visibles saliera gratis, esa diferencia sería un oráculo
  para separar los reales de los inventados. De eso se encarga `PmsGuiaHuespedProvider::rechazar()`.
- **La ventana se fija en el primer fallo y no se renueva.** Si se renovara con cada intento,
  quien siguiera probando estiraría su propio castigo hasta hacerlo permanente.

Está sobre el pool de caché y no sobre `symfony/rate-limiter` para no añadir una dependencia; si
algún día entra ese componente, esta clase es el único punto a sustituir.

> **`CotizacionFile` tiene exactamente la misma exposición** y hoy no está protegido: su
> localizador sale del mismo `LocatorTrait` y `CotizacionFilePublicProvider` responde sin freno.
> `PmsGuiaThrottle` es reutilizable tal cual.

### Pendiente

1. ~~La guía del huésped no tiene front.~~ **Hecha**: `HuespedGuiaView.vue` +
   `paxHuespedGuiaStore.ts` + `paxHuespedGuiaModel.ts`. Ver §5.1.

2. **Zona horaria.** La ventana se calcula con `new \DateTimeImmutable()` del servidor.
   `PmsEstablecimiento::getTimezone()` existe pero no participa. Mientras servidor y alojamiento
   compartan `America/Lima` el cálculo es correcto; con un establecimiento en otra zona, no.

3. **`Cache-Control: no-store` en el provider privado — sigue pendiente en el SERVIDOR.**
   El lado cliente ya está cubierto: `paxHuespedGuiaStore` pide con `cache: 'no-store'`, no
   persiste en `localStorage` y limpia el payload al desmontar la vista. Falta que la respuesta
   viaje con la cabecera, que es lo que protege de proxies y cachés intermedias.

---

## 6.2. La guía por chat: `consultar_guia`

El agente sólo sabía dar `guide_url` —«mira tu guía»—, que es justo lo que el huésped ya tiene y
no le apetece leer a las 23:00 con el agua fría. La información estaba escrita y publicada; no
había forma de **citarla** en la conversación. `App\Agent\Skill\Pms\ConsultarGuiaSkill` la lee.

### Dos pasos, y el que decide es el modelo

```
1. consultar_guia {}                    → catalogo: [{tema_id, tema, se_pregunta_como}, …]
2. consultar_guia {"tema_id": "019c…"}  → el texto de ESE tema
```

**El primer diseño buscaba por diccionario aquí dentro y era el enfoque equivocado.** Un
`str_contains` no puede saber lo único que importa antes de responder: si el huésped PREGUNTA o
sólo cuenta, si «Aguas Calientes» es el pueblo de Machu Picchu o el agua de la casa, si «está
sucio» va con la limpieza o es una queja de otra cosa. Eso es comprensión, y el modelo la tiene
—ve la conversación entera—; la skill sólo ve una cadena.

Así que la skill **enseña la carta y se aparta**: qué temas tiene esta casita y con qué palabras
se pregunta cada uno (`se_pregunta_como`, los términos del operador). El modelo lee lo que dijo
el huésped, elige, y pide ese `tema_id`. Sin cuerpos en el catálogo: son ~15 líneas por casita, y
traer además el texto de cada ítem sería mandar la guía completa para usar un párrafo.

Queda un **atajo**: `busqueda` con una o dos palabras («ducha»), para cuando el tema es evidente.
Sigue siendo búsqueda léxica con todo lo que eso implica, y por eso la descripción dice que ante
la duda se pida el catálogo.

### 🏠 Con varias casitas se PREGUNTA: `PmsGuiaEstanciaResolver`

Una reserva puede tener varias casitas a la vez, y la guía **no es la misma en cada una**. No en
detalles cosméticos:

```
Ducha (casa 1) → «La manija IZQUIERDA controla el agua caliente»
Ducha (casa 2) → «La manija DERECHA  controla el agua caliente»
```

Coger la primera estancia y responder no da una respuesta imprecisa: da la **contraria**, y el
huésped abre el agua fría. Con los códigos de puerta es peor: el de otra casita no abre, y a las
23:00 con la maleta en la calle eso es una llamada.

| Situación | Qué hace |
|---|---|
| 1 estancia | Ésa, sin preguntar |
| Varias, pero **una en curso hoy** | La que está viviendo — es el cambio de casita a mitad, preguntar ahí sería absurdo |
| **Varias en curso a la vez** | `falta_datos: ['casita']` + la lista. Es el grupo con dos casitas, y no hay forma de saber en cuál está quien escribe |
| Ninguna empezada y varias | Se pregunta: aún no ha llegado a ninguna |

Mismo criterio que `findVivasByTelefono()` un nivel más abajo: lo de ahora manda y, ante el
empate, se pregunta en vez de adivinar. Lo usan **las dos** skills —`consultar_wifi` también,
porque cada casita tiene sus propias redes— y por eso vive en un servicio y no copiado en cada
una.

Comprobado con una reserva real de 4 casitas: sin decir cuál, las dos skills devuelven
`['Casita 4', 'Casita 6', 'Casita 7', 'Casita 1']` y la pregunta; con `casita: "Casita 4"`,
responde con el texto de esa guía y no de otra.

🔒 **El `tema_id` se resuelve DENTRO del árbol podado, no con un `find()`.** Los ítems se comparten
entre casitas, así que un id de otra guía —o de uno que el acceso escondió— devolvería contenido
que a este huésped no le toca. Probado: el id del «Using the shower» de una casita, pedido desde
la reserva de otra, se rechaza con `tema_no_disponible`; y un tema con candado sigue llegando
bloqueado aunque se pida por id.

### 🔒 No reimplementa la poda: pasa por el mismo sitio

La skill llama a `PmsGuiaAcceso::paraEvento()` + `PmsGuiaArbolFiltro::podar()`, igual que
`PmsGuiaHuespedProvider`. Leer `PmsGuiaItem` directo habría sido más corto y habría abierto dos
agujeros: los `{{ door_code }}` saldrían crudos o resueltos sin comprobar acceso, y el contenido
`solo-ventana` se serviría fuera de su ventana. Comprobado con dos huéspedes reales el mismo día:

| | Alojada hoy | Llega en 3 días |
|---|---|---|
| Código de la caja | `2499` | `[Available on 08/08/2026 at 14:00]` |
| Contraseña WiFi | `josusadi2212` | `[Available on 08/08/2026 at 14:00]` |

Además, el contexto **manda sobre el parámetro**: si quien pregunta está atado a una reserva
(huésped por chat), se usa la suya y se ignora cualquier `reserva_id`. Aceptarlo sería regalar la
guía de cualquiera —con sus códigos— escribiendo un UUID.

### 🏷️ El título no sirve para buscar: `PmsGuiaItem::$agenteTerminos`

El ítem se llama **«Uso de la ducha»** y el huésped escribe «no sale agua caliente», «el
calentador no funciona» o «hot water». Ninguna casa por texto con el título ni, necesariamente,
con el cuerpo — y el agente contestaba que la guía no dice nada del tema **teniéndolo escrito**.

Es el mismo problema que tenían las plantillas antes de `MessageTemplate::$agenteUso`, con una
diferencia: allí la frase sirve para ELEGIR entre once, y aquí para ENCONTRAR entre treinta. Por
eso son términos sueltos y no una explicación.

```
agente_terminos: ducha, agua caliente, calentador, gas, temperatura, shower, hot water
```

Comprobado con la guía real: las cuatro consultas de arriba fallaban antes y encuentran «Using
the shower» después de etiquetar. Se edita en el CRUD del ítem («Cómo lo preguntan»), así que
**añadir vocabulario no toca código**.

Detalles que no se ven leyendo el campo:

- Un acierto en los términos **pesa como el título**, por encima del cuerpo: son la respuesta
  literal a «con qué palabras preguntan por esto», mientras que en el cuerpo la palabra puede
  aparecer de pasada.
- La comparación va en **los dos sentidos** (`coincideEnTerminos()`): el término «agua caliente»
  tiene que saltar con la pregunta «no sale el agua caliente» (término ⊂ pregunta), y el término
  «wifi» con la pregunta «wifi» (pregunta ⊂ término). Con una sola dirección se cae la mitad de
  las consultas reales.
- 🔥 **Los términos de menos de 4 letras exigen PALABRA ENTERA.** Salió probando «salida tarde»
  en una casita, que devolvía «Registro de huéspedes»: el término `id` —de `passport, id`— está
  dentro de «sa**lid**a». Un término de dos letras engancha cualquier palabra que lo contenga, y
  el resultado parece razonable justo lo bastante para no mirarlo dos veces. Se delimita con
  `(?<![\p{L}\p{N}])…(?![\p{L}\p{N}])` y **no con `\b`**: en UTF-8 un acento rompe el límite de
  palabra, así que «ó» daría falsos negativos.
- **Y además por palabras sueltas** (`palabrasContenidas()`), porque la subcadena tampoco basta
  cuando la pregunta mete algo en medio: el término `how to get in` no está dentro de «how do i
  get in», y son lo mismo. Se exige que estén **todas** las palabras de ≥3 letras del término,
  en cualquier orden; con «alguna» bastaría, y entonces el término «agua caliente» saltaría ante
  cualquier pregunta que mencione el agua. Las de menos de tres letras se ignoran («de», «la»,
  «in», «to»): son las que cambian de una frase a otra sin cambiar lo que se pregunta.
- Vacío no rompe nada: se sigue buscando por título y cuerpo. Sólo se encuentra peor.

#### La siembra inicial: `Version20260806235500`

Los 40 ítems del parque son **16 conceptos** (7 duchas, 7 puertas, 7 descripciones, 7 álbumes y
un puñado de «(general)»). La migración los siembra agrupando por `nombre_interno LIKE 'Ducha
(casa%'`.

Va **por `nombre_interno` y no por ID**: los UUID no tienen por qué coincidir entre entornos y
esta migración corre en los dos. Y es **idempotente y no destructiva** — `up()` sólo escribe
donde hay `NULL`, y `down()` borra sólo lo que coincide exactamente con lo que sembró. Probado:
con un ítem editado a mano, ni `down()` se lo lleva ni `up()` se lo pisa. Es siembra inicial, no
una fuente de verdad: a partir de ahí se edita en el panel.

Los términos salen de **leer el contenido**, no el título: «Limpieza» habla de que va incluida
una vez por estadía (de ahí `toallas`, `sabanas`, `cambio`), y «Pago» del depósito de garantía.

Comprobado contra la guía real de una casita, con preguntas como las que llegan:

```
«no sale agua caliente»     → Using the shower
«how do i get in»           → Apartment Door
«puedo fumar»               → House rules and recommendations
«hace frio en la noche»     → Heating rental
«where can i find the keys» → Get the keys from the digital safe
«hay piscina» / «sirven desayuno» → correctamente NO encontrado
```

⚠️ Un «no encontrado» puede ser un **hueco de contenido**, no un fallo de la búsqueda: «a qué
hora es el check out» no aparece en la Casita 2 porque el ítem *Early check in / Late Check Out*
**no está asignado a esa guía**. Antes de tocar los términos, mira si el ítem está en la sección.

### 🔥 El peligro va al revés: cuanto MÁS texto se busca, peor

Los términos hacen que se encuentre más, y eso trae el problema contrario. Con el mensaje entero
pegado en `busqueda`, la coincidencia salta **por casualidad**: cuantas más palabras trae la
frase, más fácil es que alguna case. Un mensaje real:

> «la ducha del alojamiento donde estaba no funcionaba bien pero ésta está perfecta, me bañé en
> **Aguas Calientes**, estaba sucio»

Ahí el huésped **no pregunta nada** —cuenta algo, y quizá se queja de otra cosa—, pero saltaba
«Uso de la ducha» y el agente le explicaba cómo abrir el grifo. Encima *Aguas Calientes* es el
pueblo de Machu Picchu, no el agua de la casa: en este negocio ese topónimo aparece a diario.

Dos frenos, y ninguno intenta adivinar:

1. **`MAX_PALABRAS_BUSQUEDA = 8`.** Más de eso no es un tema, es un mensaje: la skill **no
   busca**, devuelve el índice y le dice al modelo que decida de qué se está preguntando —o que
   no la llame si sólo están contando algo—. Ocho deja pasar de sobra las preguntas reales («no
   sale agua caliente» son cuatro; «a qué hora es el check out», seis).
2. **`coincide_por`** en cada resultado (`termino` | `titulo` | `texto`). `texto` es la más
   floja: la palabra puede estar de pasada en mitad de un párrafo. El modelo tiene que poder
   dudar de ella en vez de leerla como una respuesta segura.

**Quien decide de qué se habla es el modelo**, que tiene la conversación entera; la skill sólo
busca lo que le digan que busque. Por eso el freno es un aviso y no un intento de interpretar la
frase: interpretar es justo lo que la skill no puede hacer bien.

Los dos frenos son la red de seguridad del **atajo**. El camino bueno —catálogo y `tema_id`— no
los necesita: ahí no hay ninguna búsqueda que pueda equivocarse, porque la elección ya la tomó
quien entiende la pregunta.

### 🔔 Temas que se explican pero no se conceden: `agenteRequiereHumano`

Casilla del ítem («🔔 Requiere confirmar con una persona»). Marca los temas sobre los que el
asistente puede **informar** pero no **decidir**: salida tardía, entrada temprana, servicios extra.

Nace de un caso que sale al revés de lo esperado. «Horario de ingreso y salida» explica que la
salida tardía está *sujeta a disponibilidad, con coste y coordinándolo con un día de antelación*.
Con eso en la mano el modelo se siente capaz de cerrar el tema solo — y cerrarlo solo significa
que el huésped cree que puede quedarse sin que nadie haya mirado si esa noche está vendida.
**Cuanta más información tiene el agente, más fácil es que no escale**, y es justo cuando más
falta hace: que la guía pida coordinar es la prueba de que hace falta una persona.

Marcado, `consultar_guia` devuelve la orden **pegada al contenido** (`debes_escalar`) y la anuncia
en el catálogo (`necesita_confirmacion_humana`), en vez de dejarla en una regla general del prompt
que el modelo tenga que recordar. Ver §11 de `Mensajeria.md`.

⚠️ **Márcalo aunque el texto ya explique las condiciones.** Es el caso que parece resuelto y no
lo está. Los temas informativos —la ducha, el wifi— no se marcan.

### 🔑 Los códigos de entrada: `consultar_codigos`

La caja de las llaves (`PmsEstablecimiento::$codigoCajaPrincipal`) y la puerta de la casita
(`PmsUnidad::$codigoPuerta`). Sirve igual para las dos formas de pedirlo, que son la misma
consulta: el huésped preguntando «¿cuál es el código?» y el operador diciendo «mándale el código
a Ana» — con el dato en la mano, el agente encadena `enviar_mensaje_huesped`.

**Tres condiciones, y cada una dice algo distinto:**

| Condición | De dónde sale | Qué pasa si falla |
|---|---|---|
| La estancia da acceso | `PmsGuiaAcceso::paraEvento()` | «la reserva no está confirmada» |
| La ventana está abierta | `estaAbierto()` (24 h antes → check-out) | «lo tendrá desde el 08/08 a las 14:00» |
| El campo está **relleno** | El propio valor | «falta configurarlo en el panel» |

La tercera es la que se olvida y la que más confunde: **«todavía no» y «falta ponerlo» no son lo
mismo**. Si el código no está configurado, decirle al huésped que espere lo deja esperando algo
que no va a llegar; el aviso dice explícitamente que *no es un problema de permisos* y que hay que
avisar al equipo.

Comprobado con datos reales: alojada → `caja_de_las_llaves: 2499E, puerta_de_la_casita: #2`;
llega en 3 días → sin ningún código y con la fecha; sin código de puerta → devuelve el de la caja
y avisa de que falta el otro.

#### 🔗 El código nunca viaja solo: aviso + enlace, en su idioma

Un código de caja **cambia**, y los mensajes ya enviados no se actualizan. Mandarlo a pelo por
WhatsApp crea una copia que envejece en el móvil del huésped: el día que se cambie, seguirá
teniendo el viejo y creyendo que es el bueno.

Por eso la respuesta trae `avisale_de_esto`, ya redactado **en el idioma de la conversación**, con
el enlace a su guía:

```
[en] This code may change. Always check your guide for the current one:
     https://pax.openperu.pe/huesped/reserva/9EBXHW
[es] Este código puede cambiar. Consulta siempre tu guía para tener el actualizado:
     https://pax.openperu.pe/huesped/reserva/FZWF94
```

Así el mensaje deja de ser la **fuente** y pasa a ser un **atajo**: la guía siempre da el valor de
ahora. Es lo que convierte el cambio de código en un problema menor —los que ya están dentro
siguen necesitando aviso, pero quien mire la guía verá el correcto—.

Los siete idiomas están en `ConsultarCodigosSkill::AVISO`, no en un archivo de traducciones: son
siete frases que sólo usa esta skill, y tenerlas al lado del código que las elige evita que se
queden a medio traducir sin que nadie lo note. El enlace se arma con `%pax_book_guide_url%` +
localizador, igual que `guide_url` de `PmsMessageDataResolver`.

#### 🚪 El día de la llegada, ese mismo campo dice otra cosa

`avisale_de_esto` **no siempre trae el aviso**. El día del check-in trae `PASOS` en vez de
`AVISO`, con el mismo enlace detrás:

```
[es] La entrada es autónoma, como se le indicó antes: las llaves están en una caja de
     seguridad y abre usted mismo. Los pasos están en su guía: https://…/FZWF94
```

El motivo es que **en la puerta las dos frases compiten y sólo una sirve**. «Este código puede
cambiar, consulta tu guía» es una advertencia sobre el futuro: correcta el martes, ruido cuando
el huésped está de pie con las maletas y lo que necesita es saber que la entrada es suya. La
frase de llegada le da los tres hechos que resuelven ese momento: es autónoma, ya se le dijo
antes, y los pasos están ahí.

Va **pretraducida**, como `AVISO`, por lo de siempre: es justo la frase que se cae si se deja
redactar. Ver §3.b — el 27/08/2026 el modelo, sin ella y sin teléfonos, se inventó que alguien
iría a recibir a la huésped.

Con ella viaja `y_luego`, que le dice al modelo que cierre ofreciendo el teléfono de `contacto`.
La ayuda va **al final y como salida**: si va delante, todos llaman y el autoservicio no sirve
de nada.

Lo elige `esElDiaDeLlegada()`, **por fecha y no por hora** —a las nueve de la mañana el huésped
ya está de camino—. Es el mismo método que decide `escalar_en_silencio`: un solo hecho con dos
consecuencias, y por eso se llama por el hecho y no por una de ellas.

#### 🔑 «¿Cuál es el código de la caja?» sin hablar de ningún huésped

La caja de las llaves es del **establecimiento**, no de la reserva. Un operador en la puerta
preguntando el código no tiene por qué nombrar a un huésped para que el sistema le diga un dato
que es de la casa — y hasta ahora se le exigía: `No sé de qué reserva son los códigos`.

Con `reserva_id` omitido y actor **del equipo**, la skill devuelve sólo el código general:

```
establecimiento: Centro Cusco Inti
codigos:         { caja_de_las_llaves: "2499E" }
nota:            "Es el código general… Para el de la puerta de una casita concreta,
                  o para dárselo a un huésped, dime de quién es la reserva."
```

⚠️ **Lo que protege este camino es el ROL, no la ventana horaria.** No hay estancia contra la que
medir fechas, así que las tres condiciones de arriba no aplican: al huésped lo para el guardia
del contexto (`contextoId() === null && !esDelEquipo()`), que sigue devolviéndole
«esta conversación no está asociada a ninguna reserva». Verificado que sigue cerrado.

Por eso este camino da **sólo la caja general** y no el código de puerta: ése es de una casita
concreta, y para saber si toca darlo hay que mirar a quién se le da.

### 💰 La caja del DINERO no sale por ahí jamás

`codigoCajaSecundaria` es la recaudación, no las llaves. **No aparece en `consultar_codigos` ni
con el actor del equipo** —verificado— y sólo se toca con `cambiar_codigo_caja`, que exige
`ROLE_RESERVAS_WRITE`. Esa skill tampoco repite el código nuevo en su respuesta: cambiarlo y
dejarlo escrito en un chat que alguien reenvía es la mitad del problema.

Y no basta con que la skill no lo devuelva: **si el huésped pregunta**, el modelo tiene que saber
qué contestar. Su descripción se lo dice explícitamente —«la caja del dinero NO EXISTE para el
huésped: si pregunta por ella, o por "la otra caja", dile que es interna y no la busques por otro
lado»—, porque sin esa instrucción el modelo intentaría encontrarla en la guía o improvisar una
explicación.

### ⚠️ Cambiar el código de las llaves deja gente fuera

Va en la guía y en los mensajes **ya enviados**. El sistema sirve el valor nuevo desde el flush,
pero el WhatsApp que el huésped recibió hace tres días no se actualiza: vuelve a las 23:00 y no
puede coger las llaves.

Por eso la previsualización cuenta **quién está dentro ahora mismo**:

```
huéspedes usándola: 7
  · Casita 1 — César Hiroyasu (XK6FV4)
  · Casita 2 — Rutaba Eshita (9EBXHW)
  …
⚠️ Esos mensajes NO se actualizan solos: si no les avisas, vuelven de noche y no entran.
```

Ese número es lo que decide si se cambia ahora o mañana. Y tras aplicarlo, la skill sugiere
mandarles el nuevo con `enviar_mensaje_huesped` — no lo hace sola: son mensajes a personas reales
y los redacta quien decide.

Se cuenta **por día** y no por instante: a las 09:00 del día de salida el huésped sigue dentro y
aún necesita abrir la caja para devolver las llaves.

### 📶 El WiFi es otra skill: `consultar_wifi`

Porque **el dato no está en la guía**. Vive en `PmsUnidad::$wifiNetworks`, y el cuerpo del ítem
sólo trae un `{{ wifi_data }}`. La primera versión lo resolvía dentro de `consultar_guia` y
funcionaba, pero mezclaba una skill que LEE texto publicado con una que sirve una CREDENCIAL.

Separadas:

- La contraseña sale de un sitio, no de un texto que alguien puede reescribir en el editor.
- «¿Cuál es la clave del wifi?» es de lo más preguntado: merece respuesta directa, no un párrafo
  del que extraerla.
- La ventana se comprueba explícitamente y se ve en la respuesta.

```
Alojada hoy      → redes: OPENPERU SAPHI 1 (josusadi2212) — Forward · SAPHI 2 — Back · MOVIL — Exteriors
Llega en 3 días  → disponible: false, disponible_desde: 08/08/2026 14:00
```

`consultar_guia` ya no resuelve ese placeholder: lo sustituye por una remisión legible («los
datos del WiFi se piden con consultar_wifi») para que el modelo sepa que ahí va algo y a quién
pedírselo, en vez de leer `{{ wifi_data }}` en voz alta.

⚠️ Los motivos de bloqueo de `consultar_wifi` se escriben en la skill y **no** reutilizan
`PmsGuiaMensajes`: aquéllos son textos de interfaz —`[Disponible el 12/08]`, entre corchetes para
pintarse dentro de un párrafo— y aquí hace falta una instrucción para el modelo, que además le
prohíbe rellenar el hueco por su cuenta.

### 🔥 Los bloques del front hay que cerrarlos aquí

`PmsGuiaInterpolador` resuelve a propósito **sólo las claves de dato** y deja los bloques de
maquetación al navegador (§4). En un chat no hay navegador, así que la skill los cierra en
`resolverBloquesDelFront()`:

- **`{{ wifi_data }}`** → una remisión a `consultar_wifi` (ver arriba). **No es una errata del
  editor:** `RichContentEngine.ts` lo traduce a `{{ widget: wifi }}` y lo pinta con
  `guia.redesWifi`; aquí no se resuelve porque la credencial es de la otra skill.
- **`{{ img: … }}`, `{{ video: … }}`, `{{ map: … }}`, `{{ widget: … }}`** → se borran. Una URL de
  imagen no explica cómo va la ducha y ensucia la respuesta.
- **Cualquier clave suelta que nadie resolvió** → se borra. Para el interpolador es una errata
  que «tiene que verse en la revisión», pero al huésped no se le lee una errata.

🪞 **Si el front aprende un placeholder nuevo, esto se queda corto sin avisar.** Los dos lados
leen el mismo contenido con reglas propias; al tocar `COMPONENT_REGISTRY` hay que mirar aquí.

### 🔥 Se busca en TODOS los idiomas, se responde en UNO

El primer intento buscaba sólo en el idioma de la reserva, y falló en la primera prueba real: la
guía de una huésped con idioma `en` dice «Using the shower», así que un operador preguntando
«ducha» recibía «la guía no dice nada sobre eso» —teniéndolo—. Ahora la coincidencia se busca en
todas las traducciones y el cuerpo se devuelve en el idioma del huésped: mandarle al modelo cinco
versiones del mismo párrafo es pagar cinco veces por la misma frase.

Cuando no hay nada, la respuesta trae el índice y un aviso que **prohíbe improvisar**: el riesgo
de esta skill no es no encontrar, es que el modelo se invente cómo funciona un calefactor a gas.

---

## 7. Gotchas

- **`store.loading` no cubre el arranque de la vista.** Los stores de pax nacen con
  `loading: false` y `guia/reserva: null` — combinación idéntica a "no encontrada". Toda vista
  que haga trabajo previo a la petición (p. ej. `maestroStore.cargarConfiguracion()`) pinta la
  rama de error un instante antes del spinner, salvo que mantenga un flag local que arranque en
  `true` y baje en el `finally` de su `cargar()`. Así lo hacen `HuespedGuiaView` (`cargando`) y
  `PmsReservaView` (`isReady`); una vista nueva debe repetir el patrón.
- **Propiedades virtuales, no columnas.** `seccionesParaCliente`, `contenidoParaCliente`,
  `bloqueadoHasta`… no están mapeadas. Los providers escriben en entidades gestionadas por el
  EntityManager, pero no las ensucian: no hay `flush()` en el camino de lectura de la API. Mismo
  patrón que `CotizacionFile::$versionesParaCliente` (ver `docs/Cotizaciones.md`).

- **`getItemsApi()` / `getSeccionesApi()` no tienen grupo, y es a propósito.** Devuelven el árbol
  **sin filtrar**; son la entrada de `PmsGuiaArbolFiltro`. Lo mismo con `PmsGuiaItem::getTitulo()`
  y `getDescripcion()`, que devuelven el contenido crudo con los `{{ placeholders }}` sin
  resolver. **Ponerles un `#[Groups]` a cualquiera de los cuatro reabre el agujero**: el primero
  publicaría el árbol entero, el segundo enseñaría `{{ door_code }}` literal en pantalla.

- **`PmsGuiaItem` se comparte entre secciones y guías.** La visibilidad es del ítem, así que
  marcar uno como público lo publica en **todas** las unidades que reutilicen esa sección común.

- **Doctrine congela el changeset antes de `preUpdate`.** `PmsSlugListener` llama a
  `recomputeSingleEntityChangeSet()`; sin eso, un slug asignado en `preUpdate` no llegaría al
  `UPDATE`. Mismo motivo por el que lo hace `PmsEventoCalendarioIntegrityListener`.

- **El backfill de slugs no translitera acentos.** MySQL no trae una función para eso, así que
  `Casita Ñusta` queda como `casita-usta`. Son pocas filas y se corrige en el panel; las altas
  nuevas sí pasan por `AsciiSlugger`, que sí translitera.

- **El catálogo devuelve 404 si no hay nada publicado.** No sirve la cáscara con título y foto:
  solo serviría para que los buscadores indexaran una página vacía.

- **Un `<style scoped>` NO alcanza lo que entra por `v-html`.** Vue marca con el atributo de
  scope solo los elementos que compila él; el HTML inyectado no lo lleva, así que un selector
  scoped normal no lo toca nunca. Por eso los estilos de `guia-dato` (§4) usan `:deep()`. Aplica
  a cualquier estilo que se quiera dar al cuerpo de un ítem: falla en silencio, sin error de
  compilación ni aviso en consola.

- **`galeria` poblada NO significa "pinta una galería".** Es el repositorio de imágenes del
  ítem, y en los tipos `card` / `alert` el editor las inserta **en línea dentro del texto**, que
  luego expande `RichTextRenderer`. Pintar además la rejilla al pie —que es lo que hacía la
  vista— mostraba cada foto **dos veces**. Quien decide si existe una galería como bloque propio
  es el **tipo del ítem**: solo `album`. En `HuespedGuiaView` el criterio vive en un único
  helper, `tieneGaleria()`, usado por los tres sitios que pintaban fotos (rejilla del ítem único
  en el nivel 2, bloque «Galería» del nivel 3 y el contador «N fotos» de la lista). La
  **miniatura** de la fila del nivel 2 se queda en todos los tipos a propósito: ahí la imagen no
  anuncia una galería, es una pista visual de la fila.

### 🔥 Un `@return` mal formado degrada el schema y contamina el frontend

`PmsReserva::getEventosActivosGuia()` devuelve `array` a secas. El tipo nativo de PHP no puede
decir *de qué*, así que **lo único de lo que API Platform puede deducir el contenido es el
docblock**. El de este getter tenía un asterisco de más:

```php
 * * @return array<int, PmsEventoCalendario>   ← el tag se lee como TEXTO, no como tag
```

Consecuencias, en cadena y todas silenciosas:

1. El schema publicaba `eventosActivosGuia: string[]`.
2. Sin schema anidado, `pax/` no podía anclar nada: `PmsUnidad`, `PmsEventoEstado` y
   `PmsEventoCalendario` se escribieron **a mano**.
3. Esos tipos a mano envejecieron apuntando a `PmsUnidad-pax_evento.read`, un grupo de
   serialización **que ya no existe** en el backend. El error solo salió al regenerar
   `api.d.ts`, meses después.
4. Y mientras tanto mentían: declaraban `id: string` cuando el schema real dice
   `string | null`, y `cantidadAdultos: number` cuando llega opcional.

Arreglado el asterisco, API Platform emite `PmsEventoCalendario-pax_reserva.read` y
`PmsUnidad-pax_reserva.read`, y los tres tipos se anclan sin ningún override.

**Regla práctica:** si un tipo del backend «no tiene schema» y estás a punto de escribirlo a
mano en el front, **sospecha primero del docblock**. Comprobación:

```bash
php bin/console api:openapi:export | python3 -c "import json,sys; \
  print(json.load(sys.stdin)['components']['schemas']['PmsReserva-pax_reserva.read']['properties']['eventosActivosGuia'])"
```

Si sale `{'items': {'type': 'string'}}` en algo que devuelve objetos, el problema es el getter,
no el frontend.

---

## 8. Dónde tocar para cambiar X

| Necesito… | Archivo | Símbolo |
|---|---|---|
| **Cambiar quién ve qué** | `src/Pms/Guia/PmsGuiaAcceso.php` | `permite()` (la matriz, §3) |
| Que un getter que devuelve `array` salga tipado en el schema | la entidad | el `@return array<int, X>` del docblock — es la única fuente (§7) |
| Tipos de la vista del huésped en `pax/` | `pax/src/types/paxHuespedModel.ts` | anclados a `components['schemas'][...]`; a mano solo lo que el backend no describe |
| Cambiar las horas de anticipación | mismo archivo | `HORAS_ANTICIPACION` |
| Cambiar qué invalida una estancia | mismo archivo | `paraEvento()`, las tres comprobaciones |
| Que un ítem bloqueado se oculte en vez de anunciarse | mismo archivo | `debeAnunciarBloqueo()` |
| Cambiar el aviso al tocar el candado de un ítem | `pax/src/views/huesped/HuespedGuiaView.vue` | `mensajeBloqueoItem`, `avisarBloqueo()` |
| Añadir un nivel de visibilidad | `src/Pms/Enum/PmsGuiaVisibilidad.php` **y** `PmsGuiaAcceso::permite()` | el `match` de los dos + `exigeCondicionExtra()`, el CRUD, el tipo de `pax` y una migración de datos |
| Añadir un placeholder de datos | `src/Pms/Guia/PmsGuiaContexto.php` | `construir()`, `$valores` o `$sensibles` |
| Marcar un placeholder como sensible | `src/Pms/Guia/PmsGuiaInterpolador.php` | `CLAVES_SENSIBLES` |
| Cambiar los textos de bloqueo o añadir un idioma | `src/Pms/Guia/PmsGuiaMensajes.php` | las constantes |
| Añadir un bloque de maquetación (`{{ algo: valor }}`) | `pax/src/core/RichContentEngine.ts` | `COMPONENT_REGISTRY` + la chuleta del CRUD |
| Añadir un medio con ventana (`{{ algo_ventana: url }}`) | `src/Pms/Guia/PmsGuiaInterpolador.php` | `REGEX_MEDIA_VENTANA`, `resolverMediaVentana()` |
| Cambiar el marco de un medio bloqueado | `pax/src/components/RichText/MediaBloqueadaBlock.vue` | la plantilla |
| Actualizar la chuleta de placeholders del formulario | `src/Pms/Controller/Crud/PmsGuiaItemCrudController.php` | `ayudaPlaceholders()` |
| Cambiar cómo se ve un dato interpolado o un `[Disponible el …]` | `pax/src/components/RichText/RichTextRenderer.vue` | `:deep(.guia-dato)` (§4) |
| Cambiar el marcado que envuelve el dato | `src/Pms/Guia/PmsGuiaInterpolador.php` | `envolver()` — tocar también el CSS |
| Cambiar el podado del árbol | `src/Pms/Guia/PmsGuiaArbolFiltro.php` | `podar()`, `podarItems()` |
| Cambiar qué estancia abre el enlace corto | `src/Api/Provider/Pms/PmsGuiaHuespedProvider.php` | `elegirEvento()` |
| Que el agente responda la guía por chat | `src/Agent/Skill/Pms/ConsultarGuiaSkill.php` | `ejecutar()` — §6.2 |
| **Que el agente encuentre un tema por otras palabras** | el ítem, en el CRUD | «Cómo lo preguntan» (`agenteTerminos`) — no toca código |
| Que el agente dé el WiFi | `src/Agent/Skill/Pms/ConsultarWifiSkill.php` | `ejecutar()`; los motivos de bloqueo, en `motivo()` |
| Que el agente dé los códigos de entrada | `src/Agent/Skill/Pms/ConsultarCodigosSkill.php` | `ejecutar()` — caja de llaves + puerta, nunca la del dinero |
| Cambiar el código de una caja fuerte | `src/Agent/Skill/Pms/CambiarCodigoCajaSkill.php` | `huespedesUsandola()` decide el aviso; el campo vive en `PmsEstablecimiento` |
| Cambiar cuándo se pregunta por la casita en vez de deducirla | `src/Pms/Guia/PmsGuiaEstanciaResolver.php` | `resolver()` — lo usan las DOS skills |
| Cambiar cuánto texto de guía se le da al modelo | misma skill | `MAX_ITEMS`, `MAX_CARACTERES` |
| Enseñar al agente un placeholder de maquetación nuevo | misma skill | `resolverBloquesDelFront()` — **espejo** de `RichContentEngine.ts` |
| Cambiar qué exige el catálogo para existir | `src/Api/Provider/Pms/PmsUnidadCatalogoProvider.php` | `provide()`, los guards |
| Añadir un campo al payload del huésped | `src/Pms/Entity/PmsGuia.php` | propiedad virtual + `pax_guia:read` |
| Añadir un campo al catálogo | entidad correspondiente | grupo `pax_catalogo:read` |
| Cambiar cómo se generan los slugs | `src/Pms/EventListener/PmsSlugListener.php` | `generarUnico()`, `normalizar()` |
| **Ajustar el freno anti-enumeración** | `src/Pms/Guia/PmsGuiaThrottle.php` | `MAX_FALLOS`, `VENTANA` |
| Proteger también el localizador de cotizaciones | `src/Api/Provider/Cotizacion/CotizacionFilePublicProvider.php` | reutilizar `PmsGuiaThrottle` (§6.1) |
| Clasificar contenido desde el panel | `src/Pms/Controller/Crud/PmsGuiaItemCrudController.php` | `ChoiceField::new('visibilidad')` |
| **Cambiar el diseño de la guía del huésped** | `pax/src/views/huesped/HuespedGuiaView.vue` | `secciones`, `avisoAcceso`, `mensajeError` |
| Cambiar qué ítems pintan galería | mismo archivo | `tieneGaleria()` (§7) |
| Llamadas HTTP de la guía | `pax/src/stores/huesped/paxHuespedGuiaStore.ts` | `cargar()`, `limpiar()` |
| Cambiar tipos de la guía | `pax/src/types/paxHuespedGuiaModel.ts` | espejo de `pax_guia:read` |
| Cambiar a dónde lleva el botón «Ver guía» | `pax/src/views/huesped/PmsReservaView.vue` | `verGuiaEvento()` |
| Cambiar el diseño del escaparate | `pax/src/views/huesped/CatalogoUnidadView.vue` | `fotos`, `seccionesOrdenadas`, `whatsappUrl`, `hermanas` |
| Cambiar qué casitas hermanas se ofrecen | `src/Api/Provider/Pms/PmsUnidadCatalogoProvider.php` | `hermanasPublicadas()` |
| Añadir o reordenar un bloque del escaparate | `src/Pms/Enum/PmsCatalogoBloque.php` | los `case` — **el orden de declaración es el de la página**; sembrar además su `cat_bloque_*` |
| Cambiar cómo se agrupan los ítems del escaparate | `src/Api/Provider/Pms/PmsUnidadCatalogoProvider.php` | `construirBloques()` |
| Poner contenido solo en el escaparate | crear un `PmsGuiaItem` sin sección y relacionarlo | `PmsCatalogoHasItem` (§2.b) |
| Cambiar el formulario del escaparate | `src/Pms/Controller/Crud/PmsCatalogoCrudController.php` + `PmsCatalogoHasItemType` | `configureFields()` / `buildForm()` |
| Cambiar tipos del catálogo | `pax/src/types/paxCatalogoUnidadModel.ts` | espejo de `pax_catalogo:read` |
| Llamadas HTTP del catálogo | `pax/src/stores/huesped/paxCatalogoUnidadStore.ts` | `cargar()` |
| Retirar el circuito heredado | §6, lista de pendientes | — |


---

## Lo que el asistente cuenta: `agente_contenido`

Un ítem de guía puede decirle **una cosa a la pantalla y otra al chat**. Con
`PmsGuiaItem::$agenteContenido` escrito, `consultar_guia` devuelve ese texto **en lugar del
cuerpo**; la app del huésped no se entera y sigue pintando la descripción de siempre.

> 🪜 **Y una cosa distinta la segunda vez.** `agenteContenido` es sólo el primer peldaño: lo que
> se contesta a una pregunta blanca. Lo que se dice **cuando eso no resolvió** —«cierra y abre al
> máximo», «enciende una hornilla, comparten el gas»— va en `PmsGuiaItem::$agentePasos`, y sale
> sólo si el huésped vuelve sobre el tema. Así al que sólo pregunta cómo funciona la ducha no se
> le contesta con avisos de avería. El mecanismo completo —cómo se cuenta el peldaño, por qué un
> humano reinicia la cuenta, y el aviso que evita que el modelo NIEGUE lo que no se le dio— está
> en `docs/Mensajeria.md` §22. **Casi todos los ítems lo llevan vacío, y así debe ser.**

### Por qué hacen falta dos versiones

No es censura, es que **leer y que te lo digan no son lo mismo**. El ítem «Pago y depósito de
garantía» explica que en las reservas de Booking se pide un depósito de S/ 300 al check-in.
Escrito en la guía está bien: el huésped lo lee cuando toca, junto a la parte de que se devuelve
entero. Soltado por chat a alguien que sólo preguntó «¿el pago no es por Booking?» suena a peaje
sorpresa y espanta.

Y sirve al revés, que es el uso menos obvio: **contarle al agente lo que no cabe en la guía**.
Las preguntas recurrentes que no son contenido publicable —por qué el importe de la app del
canal no cuadra con el nuestro— tienen aquí su sitio. Sin él, el modelo improvisa una
explicación verosímil y distinta cada vez; se le vio soltar que «las plataformas muestran
importes sin desglosar impuestos», que no se lo había dicho nadie.

### Reglas

- **Sustituye, no acompaña.** Lo que no escribas aquí, el agente no lo sabrá de ese tema: no lo
  completa leyendo el cuerpo, porque no lo recibe.
- **Se busca en los DOS, se devuelve el override.** El barrido mira términos, título, cuerpo
  publicado **y** el override. Buscar sólo en lo publicado dejaba el campo medio inútil: lo que
  escribieras únicamente ahí —por qué el importe del canal no cuadra, por ejemplo— no se
  encontraba, y el agente contestaba «tu guía no dice nada de eso» teniéndolo delante. Y buscar
  sólo en el override estrecharía lo encontrable justo cuando el operador acaba de resumir el
  texto para el chat.
- **Texto plano, en español, sin HTML ni placeholders.** El chat no pinta HTML, y el modelo lo
  redacta en el idioma del huésped — mismo criterio que
  `MessageTemplate::$agenteUso`.

⚠️ El cuerpo se arma en **dos** sitios de `ConsultarGuiaSkill` —el detalle de un tema y el
barrido de la búsqueda—. Por eso existe `cuerpoParaElAgente()`: la primera versión parcheó sólo
uno y el override no salía al buscar por palabra, que es el camino más usado.

### 🚫 Nada de `{{ placeholders }}` en este campo

El override **no pasa por el interpolador**: sale tal cual. Un `{{ door_code }}` escrito aquí
llegaría literal al modelo, y resolverlo sería peor —congelaría una credencial que hoy sólo se
revela dentro de su ventana de acceso—.

El caso que mejor lo explica no es de seguridad sino de corrección: `{{ check_in }}` **cambia
por reserva**. Horneado en el override, todos los huéspedes recibirían la misma hora.

Por eso `Version20260810140000`, que sembró el campo con el cuerpo de los 28 ítems que no usan
placeholders, **dejó fuera a los cuatro que sí**: «Llaves (general)», «Wifi (general)»,
«Ubicación (general)» y «Early check in Late Check Out». Ésos siguen por el camino vivo, con su
interpolación y su ventana intactas. Si algún día un ítem con placeholder necesita voz propia
para el agente, lo que hay que escribir es la parte que NO depende de la reserva.

### 🔥 Omitir un hecho no es callarlo: es NEGARLO

Es lo más importante de este campo y va contra la intuición. **Quitar algo del override no hace
que el agente se lo calle: hace que lo niegue.**

Medido con el depósito de garantía de S/ 300, preguntando «¿hay que dejar algún depósito al
llegar?». Tres formas de quitarlo, tres mentiras:

| Cómo se quitó | Qué contestó el agente |
|---|---|
| Instrucción explícita: «NO menciones el depósito» | «**No, no es necesario** dejar ningún depósito de garantía» |
| Simple omisión, sin instrucción | «**No requerimos** un depósito de garantía adicional» |
| Omisión + quitar `deposito, garantia` de los términos | «**No se requiere** dejar un depósito de garantía» |

El motivo es que el modelo recibe el override como **el contenido del tema**, y el prompt le
prohíbe inventar: si el texto del tema de pagos no dice nada de depósitos, la conclusión
razonable es que no hay. El vacío no se lee como «no lo sé», se lee como «no existe».

Quitar la palabra de los términos tampoco salva: el ítem se sigue encontrando por «pago» y el
resultado es el mismo.

#### La regla, entonces

> **El override cambia CÓMO y CUÁNDO se dice algo. Nunca SI existe.**

Para que un hecho incómodo no se suelte de entrada, se deja escrito **con su instrucción de
cuándo decirlo**:

```
Deposito de garantia: SOLO si pregunta por el. No lo saques tu.
Si pregunta: si, se piden S/ 300 al hacer el check-in y se devuelven integros al salir,
tras comprobar que se entregan las llaves y no hay danos. No es un cobro, es un deposito.
```

Con eso, medido: a «¿el pago no es por Booking?» **no lo menciona**, y a «¿hay depósito?»
contesta la verdad entera, con la parte de que se devuelve.

#### Y no, las instrucciones no se le escapan al huésped

Era el miedo razonable —que contestara «no puedo hablarte del depósito»— y no pasó en ninguna
de las pruebas. El modelo trata esas líneas como indicaciones para él, no como texto que
repetir. Lo que sí hace es obedecerlas, incluida la de mentir por omisión: por eso la regla de
arriba.

### Las horas de entrada y salida viajan como campo, no dentro del texto

`consultar_guia` devuelve `hora_check_in` y `hora_check_out` en la raíz de la respuesta, además
de lo que diga el ítem que toque.

No es comodidad: es lo que **desbloquea el override de «Horarios de estancia»**. Ese ítem tenía
las horas sólo como `{{ check_in }}` / `{{ check_out }}` en su cuerpo, y como el override no
pasa por el interpolador, darle `agente_contenido` habría borrado unas horas que salen del
evento y **cambian por estancia**. Sacándolas fuera, el ítem puede tener su versión para el
agente sin perder nada — y de paso el agente sabe las horas aunque el tema no salga en la
búsqueda.

⚠️ Se enumeran **dos claves a mano**, no se vuelca `PmsGuiaContexto::$valores`. En ese array
también viven `door_code`, `safe_code` y `keybox_main` cuando la ventana está abierta, y un
volcado es la forma clásica de que un día salgan sin querer. Misma regla que en §11 de
`docs/Mensajeria.md`: lo que no se enumera, no viaja.

### 🇪🇸 Al agente se le da SIEMPRE el español

`consultar_guia` no lee la guía en el idioma del huésped: la lee en español y deja que el
modelo traduzca al redactar, que es lo que ya le pide el contexto de la conversación.

Es el mismo criterio que `agente_contenido`, `MessageTemplate::$agenteUso` y las notas de
`FinMedioCobro`. Aquí suma dos motivos propios:

- **El español es el original.** Los otros seis idiomas los escribió el traductor automático;
  servirlos al modelo es heredar cualquier traducción torcida en la respuesta al huésped.
- **El recorte deja de depender de quién pregunte.** Éste es el que no se ve venir.

#### ⚠️ El truncado que cambiaba con el idioma

`recortar()` corta por **caracteres**, y una traducción no ocupa lo mismo que su original.
Medido sobre los ítems que leen el cuerpo publicado:

| Ítem | Español | El idioma más largo |
|---|---|---|
| Llaves (general) | 1250 | 1372 (de) — **+10%** |
| Early check in / Late check out | 486 | 555 (it) — **+14%** |
| Wifi (general) | 222 | 261 (de) — **+18%** |

Con el idioma del huésped, un alemán perdía ~120 caracteres más que un peruano **del mismo
ítem**, y se quedaba sin el final de las instrucciones. Es de los fallos más difíciles de
perseguir, porque «a mí me funciona» es literalmente cierto: en español entraba.

🔎 De paso salió que **`Llaves (general)` se trunca hoy en todos los idiomas** (1250 contra un
tope de 900). Es el ítem de cómo sacar las llaves de la caja fuerte, y es uno de los que no
puede tener `agente_contenido` porque lleva `{{ keybox_main }}`.

#### Lo que NO cambió: la búsqueda

Sigue mirando los siete idiomas ({@see ConsultarGuiaSkill::coincideEnAlgunIdioma()}), y tiene
que seguir así: el huésped pregunta en el suyo. «hot water» y «dusche» encuentran «Uso de la
ducha» y reciben el contenido en español.

**Buscar y responder van por caminos distintos** — misma regla que con el override.

### El widget de medios de pago: `{{ medios_pago }}`

Pinta las cuentas de cobro dentro de un ítem, igual que `{{ wifi_data }}` pinta las redes.
Cadena completa: `PmsGuiaHuespedProvider::mediosPago()` filtra por procedencia →
`GuiaMedioPago[]` en `paxHuespedGuiaModel.ts` → `RichContentEngine` lo normaliza a
`{{ widget: medios_pago }}` → `MediosPagoWidget.vue`.

Dos decisiones que no se ven en el código:

- **No tiene ventana de acceso**, al revés que el WiFi. Quien todavía no ha entrado es justo el
  que necesita saber por dónde adelantar el pago. Lo que sí lleva es el filtro de procedencia,
  con el **mismo servicio** que usa el agente (`PmsProcedenciaHuesped`): si cada uno dedujera por
  su cuenta, la pantalla y el chat acabarían ofreciendo cuentas distintas al mismo huésped.
- **El marcador va sólo en el bloque español** (`Version20260810240000`). Es un marcador, no
  texto: lo que se pinta dentro son los datos del catálogo, ya traducidos. Meterlo en los siete
  idiomas sería repetirlo siete veces con el riesgo de que el traductor lo toque. ⚠️ La
  contrapartida es real: **quien lea la guía en otro idioma no verá el widget** hasta que se
  añada a su bloque desde el panel.

### Cómo se escribe: hechos primero, instrucciones pegadas

Ni copiar el cuerpo tal cual ni escribir un prompt. Las dos cosas, en este orden:

```
[HECHOS]  Todo lo que el huésped pueda preguntar o encontrarse, sin adornos.
          Si un hecho falta, el agente lo NIEGA — no se lo calla.

[CUÁNDO]  Pegado al hecho que gobierna, no como regla general al principio.
          «SOLO si pregunta.» · «No lo saques tú.» · «Si insiste, avisa al equipo.»
```

Ejemplo real, el ítem de pagos:

```
El pago no lo cobra Booking: se abona directamente a nosotros. En reservas de
Booking.com el pago total se hace al llegar. Tambien se puede adelantar antes por
Yape, Plin o transferencia.                                          ← hecho

Deposito de garantia: SOLO si pregunta por el. No lo saques tu.      ← cuándo
Si pregunta: si, se piden S/ 300 al hacer el check-in y se devuelven
integros al salir, tras comprobar que se entregan las llaves.        ← hecho
```

| Sí | No |
|---|---|
| Tutear al modelo, mayúsculas, «NO hagas X» | Borrar un hecho para que no lo diga |
| Resumir, quitar repeticiones y emoji decorativo | Dejar HTML o `{{ placeholders }}` |
| Contar lo que no es publicable (por qué el canal muestra otro importe) | Traducirlo: va en español y él traduce |
| Instrucciones sin hecho detrás («si insiste, escala») | Pasarse de 900 caracteres |

La prueba de que una ficha está bien escrita no es que suene bien: es preguntarle al agente
por lo que quitaste, con `app:agent:replay`, y ver si lo niega.

### El recorte: de 900 a 1800, y por qué

`ConsultarGuiaSkill::MAX_CARACTERES` corta el override igual que al cuerpo. Estuvo en **900**,
un número calibrado cuando lo único que llegaba era el cuerpo publicado **sin curar** —HTML de
hasta 2.500 caracteres escrito para una pantalla—, donde cortar era defenderse de una parrafada
que nadie había revisado.

Con `agente_contenido` el material cambió: lo escribió alguien PARA el agente, y entonces
**recortar es cortar una decisión**. Y cortaba donde más dolía: «Reglas (general)» se partía
justo antes de que **los huéspedes adicionales se pagan** — una regla con dinero detrás que el
agente no llegaba a leer.

Medido sobre los 40 ítems: media **792**, máximo **1.668**. Con 1800 no se corta ninguno, ni en
el cuerpo publicado ni en el campo del agente. Y es un **tope, no una reserva**: con esa media
no cambia lo que se paga en la llamada normal. Lo que multiplica el gasto es `MAX_ITEMS`, que
sigue en 4.

### Dónde tocar

| Necesitas… | Dónde |
|---|---|
| Que el asistente calle algo que sí va en la guía | Panel → ítem → «🗣️ Lo que dice el asistente» |
| Contarle al asistente algo que no es publicable | El mismo campo |
| Que el ítem se encuentre por más palabras | «🔎 Cómo lo preguntan», que es independiente |
| Que informe pero no conceda | «🔔 Requiere confirmar con una persona» |

---

## 9. Los cuatro temas que faltaban, y las reglas que ahora viven aquí

`Version20260811160000` añadió cuatro ítems y publicó un quinto tema que sólo veía el agente.
No fue una lluvia de ideas: se contaron los mensajes entrantes de 2026 por tema y se cruzaron
con los 42 ítems publicados.

| Tema | Menciones | Qué había |
|---|---|---|
| Tours | 62 | plantilla `menu_tours` + autoenvío (funciona) |
| Equipaje | 56 | sólo `agente_contenido` |
| Cancelación / estadía mínima | 27 | nada |
| Taxi / aeropuerto | 25 | nada |
| Estacionamiento | 20 | nada |
| Boleta / factura | 18 | nada |

### El método: la política se extrae del chat, no se inventa

Las respuestas manuales del equipo son la única fuente escrita de estas reglas. Cada afirmación
de los ítems nuevos sale de ahí, y varias son cita textual («no cobramos igv y damos boleta
electrónica a todos los huéspedes pero no factura»). Cuando se quiera documentar otro tema, el
camino es el mismo: buscar en `msg_message` las salientes sin `template_id` que hablen de él.

### Las reglas de negocio que quedan fijadas

- **Estadía mínima: dos noches.** Es la causa habitual de que `consultar_disponibilidad`
  devuelva vacío. El agente tiene instrucción de decirlo en vez de dejarlo en «no hay».
- **Cancelación directa: gratis hasta 30 días antes; después, penalidad de la primera noche.**
  El prepago de la primera noche es lo que activa la condición.
- **Boleta de venta electrónica sí, factura no, y no se cobra IGV.** El precio publicado es
  final. Para emitirla hacen falta la foto del documento y a nombre de quién va.
- **Estacionamiento: dos opciones, ninguna nuestra** — playa pública gratuita enfrente, cochera
  privada de pago a una cuadra. Se dice siempre que la gratuita no es vigilada.
- **No hay traslado propio.** El recojo en aeropuerto de las reservas de Booking **lo presta
  Booking**, no nosotros: el huésped les manda a *ellos* sus datos de vuelo.
- **Equipaje:** se guarda antes de la entrada y después de la salida, y también varios días
  (un trek) avisando con un día de antelación y diciendo cuántos bultos son.

⚠️ **El coste de guardar el equipaje sigue sin estar escrito en ninguna parte.** Se presta
siempre y nadie lo ha cobrado, pero nunca se dijo por escrito que sea gratis, así que el ítem
publicado **no lo promete** y el campo del agente conserva su «si pregunta el costo, avisa al
equipo». Si se decide que es gratis, se escribe en el panel — no lo deduzca nadie de aquí.

### ⚠️ La cancelación choca con las OTA, y por eso no es `publico`

El «30 días / primera noche» es la política **directa**. Booking y Airbnb imponen la suya por
plan tarifario, y en una misma reserva puede no coincidir. Por eso:

1. El ítem va en visibilidad `cliente`, no `publico`: lo ve quien ya tiene localizador.
2. Cierra remitiendo a «las condiciones que figuran en tu reserva».
3. El `agente_contenido` le prohíbe expresamente contradecir a la plataforma y le obliga a
   **escalar** cualquier cancelación en curso, reembolso o cambio de fechas ya pagado.

Es el mismo patrón de §«Temas que se explican pero no se conceden»: informar sin decidir.

### Dos trampas de las migraciones de contenido

**Los siete idiomas van escritos.** Una migración inserta con SQL crudo y **no dispara los
listeners de Doctrine**, así que el `#[AutoTranslate]` de `$titulo` y `$descripcion` nunca llega
a ejecutarse. Sembrar sólo el español deja los otros seis vacíos hasta que alguien reedite el
ítem en el panel — y nada avisa.

**Las secciones se buscan por `nombre_interno`, no por el título.** `Version20260810160000` las
localizó con `JSON_EXTRACT(titulo, '$[0].content')`, que da por hecho que el bloque español es
el primero del array. Nadie garantiza ese orden: basta que el traductor reescriba el JSON para
que `[0]` pase a ser el inglés y la migración no encuentre nada, **en silencio y sin error**.

### Encadenar posiciones se calcula en memoria

`addSql()` **difiere** la ejecución. Al colocar «Estacionamiento» detrás de «Traslados», ese
predecesor todavía no existe en la base, así que un `fetchOne()` devolvería `false` y lo mandaría
al final. `Version20260811160000::colocarEn()` lee el orden actual una vez, inserta en el array,
y reescribe todas las posiciones de la sección.

### Dónde tocar

| Necesitas… | Dónde |
|---|---|
| Cambiar la política de cancelación | Panel → ítem «Cancelación (general)» — y su `agente_contenido` |
| Fijar el precio de guardar equipaje | Ítem «Early check in Late Check Out», cuerpo y campo del agente |
| Cambiar el mínimo de noches del texto | «Cancelación (general)»; el dato real vive en Beds24 |
| Añadir un tema nuevo a las 7 casitas | Copiar el patrón de `Version20260811160000` |

---

## 10. `categoria`: el segundo eje de cada ítem

Desde 2026-08-12 cada `PmsGuiaItem` tiene **dos** campos que deciden si sale, y no son lo mismo:

| Campo | Pregunta | Forma |
|---|---|---|
| `visibilidad` (`PmsGuiaVisibilidad`) | ¿cuánta confianza hace falta? | escalera: público → cliente → confirmado → ventana |
| `categoria` (`CategoriaConocimiento`) | ¿de qué habla el ítem? | resta que se aplica encima |

### 10.1 Por qué hicieron falta los dos

A una interesada que llegó por Airbnb sin confirmar se le dio la dirección exacta del
alojamiento. **El sistema no falló:** `Ubicación (general)` está marcada `Publico`, así que
entregarla era lo correcto según el modelo de entonces. Lo que faltaba era poder decir que la
dirección, siendo pública en la web, no puede salir por el canal de una OTA antes de confirmar.

No se podía resolver subiéndola de escalón: eso la habría escondido también en el catálogo
público, que es justo donde debe estar. Ni bajando el escalón del interesado: a esa misma
persona hay que contestarle cuántas habitaciones hay y cuántas camas, o se pierde la reserva —
que fue lo que pasó.

De ahí que sea un eje **ortogonal y sustractivo**: la escalera dice cuánto se abre, la categoría
dice qué no pasa por este canal.

### 10.2 Los valores

- **`General`** — el default, «siempre se puede contar»: distribución, camas, equipamiento,
  normas, check-in. La inmensa mayoría del contenido.
- **`DireccionExacta`** — calle, número, referencias para llegar a la puerta.
- **`Contacto`** — teléfonos, correos, redes.
- **`CanalAlternativo`** — WhatsApp, web propia, invitar a reservar directo.
- **`Credenciales`** — códigos de puerta, caja, WiFi. Ya protegidos por la escalera; se etiquetan
  por claridad.

El default es «se puede contar» a propósito: al revés, un ítem sin clasificar dejaría mudo al
agente, y lo que se restringe es la excepción.

### 10.3 Dónde se aplica

`PmsGuiaArbolFiltro::podarItems()` descarta por categoría **antes** que nada, y los ítems
bloqueados **desaparecen sin candado** — al contrario que los de `visibilidad`, que se anuncian
con «esto lo verás cuando confirmes». Anunciar «hay una dirección que no puedo darte» es invitar
a hablar de ella.

`podar()` recibe `$categoriasBloqueadas` con default `[]`, así que la web y el catálogo
(`PmsGuiaHuespedProvider`) siguen exactamente igual: ahí no hay partner que restrinja nada. Sólo
`ConsultarGuiaSkill` pasa las categorías, sacadas de `ActorInterface::restriccion()`.

Ver `docs/Mensajeria.md` §19 para el eje de canal completo.

### 10.4 Pendiente

La migración `Version20260812250000` crea la columna con **todo en `general`**. Mientras nadie
clasifique, la resta no tapa nada. Como mínimo:

- `Ubicación (general)` → `DireccionExacta`
- `Horario solicitudes (general)` → `Contacto` — **ya no contiene los teléfonos** desde
  `Version20260827234500` (§3.b), sólo el puntero a `consultar_codigos`. Se clasifica igual: es
  alcanzable **hoy** por una consulta de OTA sin confirmar (nivel `cliente`, y `PmsGuiaAcceso`
  permite `Cliente` a cualquier huésped identificado), y el puntero no es dato sensible
- `Wifi`, `Llaves` y los `Puerta (casa N)` → `Credenciales`

Se clasifica desde el panel: ítem de guía → **«De qué habla»**.


## La sección «Servicios», y por qué faltaba

El huésped llega a la guía con cinco preguntas, y hasta agosto de 2026 sólo cuatro tenían dónde
mirar:

| Se pregunta | Sección |
|---|---|
| ¿Cómo entro? | Ingreso |
| ¿Cómo funciona esto? | Manual |
| **¿Qué puedo pedir?** | **Servicios** ← no existía |
| ¿Qué se espera de mí? | Reglas (dentro de Pagos) |
| ¿Cómo pago? | Pagos |

Sin esa sección, los servicios acabaron **repartidos donde cupieron**: la lavandería dentro de una
prohibición («NO usar agua del grifo para lavar ropa»), las toallas dentro de la descripción
comercial, y el calefactor en el Manual **y** en las siete descripciones a la vez.

⚠️ El bloque «Servicios y detalles» estaba **byte a byte idéntico** en las siete descripciones —
mismo MD5, 434 caracteres—. Siete copias que se separan en cuanto alguien corrige una, que es
exactamente lo que pasó con las duchas.

Hoy: `Ingreso · Descripción · Manual · Servicios · Wifi · Pagos y Reglamento`, y el Manual se queda
con lo que de verdad explica cómo funciona algo — ducha, cocina y televisor.

### Cómo se metió: migración para la estructura, comando para los textos

Es el patrón para cualquier reorganización de la guía, y viene de una restricción real: los ítems
se publican **en siete idiomas** y las traducciones las genera un listener en `prePersist`, que
una migración SQL se salta.

```
MIGRACIÓN (Version20260813220000)     COMANDO (app:pms:guia:tanda-servicios)
─────────────────────────────────     ──────────────────────────────────────
crea la sección                       escribe títulos y cuerpos → se traducen
crea las fichas VACÍAS                recorta el bloque de las 7 descripciones
crea los enlaces APAGADOS             enciende los enlaces
mueve lo que ya existía
```

⚠️ **Los enlaces nacen apagados**, y no es un detalle: `PmsGuiaSeccion::getItemsApi()` filtra por
enlace activo, así que entre la migración y el comando **nadie ve fichas a medio hacer**. Si el
comando falla, lo único que queda son filas invisibles.

⚠️ **Los UUID van escritos a mano en la migración**, no generados. Así la misma ficha tiene el
mismo id en local y en producción, y una migración futura puede apuntarle por id en vez de por
`nombre_interno` — que es frágil: en esta misma semana se renombraron ítems.

⚠️ **Colapsar antes de mover.** «Servicios» es una sección común compartida por las siete guías, y
Calefactor y Limpieza tenían un enlace por casita: reapuntarlos sin más produce siete filas
idénticas y choca con `uniq_seccion_item`.

### La cocina, y el «hornito»

Va por casita porque **la casa 5 es la excepción**: inducción de una sola hornilla, sin olla
arrocera, y por tanto **sin gas compartido con la ducha**. Las demás son de 4 hornillas a gas, y
las casas 2, 3, 4 y 7 comparten balón con la ducha — de ahí el paso 1 con el diagnóstico cruzado.

El contenido nombra el **microondas** de forma explícita. Sale de un mensaje real: *«he intentado
usarlo para cocinar huevos y no funciona»* y *«¿es posible usar el horno pequeño para cocinar?»*.
No hay horno: es el microondas, y quien no lo reconoce no lo va a deducir de una lista de
electrodomésticos.
