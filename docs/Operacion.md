# Módulo de Operaciones — La Biblia y Órdenes de Servicio

Cómo una cotización confirmada se convierte en logística despachable, qué garantiza y qué **no**
garantiza esa conversión, y dónde se toca cada pieza.

Alcance: `src/Operacion/` (entidades, enums, servicio, listener, comando), los endpoints
`/platform/ops/*`, `util/src/stores/operacion/operacionStore.ts`,
`util/src/types/operacionModel.ts` y `util/src/views/Operacion/OperacionView.vue`.

---

## Índice

1. [Vocabulario](#1-vocabulario)
2. [Flujo: de cotización confirmada a La Biblia](#2-flujo-de-cotización-confirmada-a-la-biblia)
3. [Reglas del snapshot](#3-reglas-del-snapshot) — incluye [3.3 comprable vs. referencia](#33), [3.5 reconciliación](#35), [3.4 consola](#34), [3.7 el file de la fila](#37), [3.8 congelado vs. vivo](#38)
2.bis [Confirmar ya NO arma la operación](#2bis-confirmar-ya-no-arma-la-operación)
3.bis [Por qué La Biblia aparece vacía](#3bis-por-qué-la-biblia-aparece-vacía)
4. [Los tres estados y por qué son tres](#4-los-tres-estados-y-por-qué-son-tres)
5. [Órdenes de Servicio y bitácora](#5-órdenes-de-servicio-y-bitácora)
6. [API: endpoints, grupos y filtros](#6-api-endpoints-grupos-y-filtros)
7. [La vista de tráfico](#7-la-vista-de-tráfico)
8. [Gotchas](#8-gotchas)
9. [Pendiente de decidir](#9-pendiente-de-decidir)
10. [Dónde tocar para cambiar X](#10-dónde-tocar-para-cambiar-x)
13. [Lo que se le dice al proveedor](#13-lo-que-se-le-dice-al-proveedor-22082026)

---

## 1. Vocabulario

| Término | Significado |
|---|---|
| **La Biblia** | El cuadro de tráfico: qué se opera cada día, a qué hora, con quién. Una fila = un `OperacionServicio`. |
| **OperacionServicio** | Unidad despachable. Es un *snapshot* de un `CotizacionCotcomponente`. ⚠️ **Nace cuando el operador arma la operación**, no al confirmar (§2.bis). |
| **Orden de Servicio (OS)** | Cabecera que agrupa varios `OperacionServicio` del **mismo proveedor y expediente** para solicitar formalmente y liquidar. |
| **Expediente / File** | `CotizacionFile`: el grupo/pasajero. Tiene N cotizaciones (versiones); sólo la confirmada genera operación. |

---

## 2. Flujo: de cotización confirmada a La Biblia

`CotizacionEditorView.vue` y `OperacionView.vue` **no se conocen entre sí**: no hay imports ni
navegación. El único vínculo es un listener de Doctrine.

```
CotizacionEditorView.vue                          OperacionView.vue  (/operacion)
  <select v-model="store.cotizacion.estado">        pestaña "La Biblia"
        │  → "confirmado"                                 ▲
        │                                                 │ GET /platform/ops/operacion_servicios
        ▼  guardarCotizacion()                            │   ?fechaServicio[after]=…&[before]=…
   PATCH /cotizaciones/{id}                               │
        │                                                 │
        ▼  EntityManager::flush()                         │
  ┌──────────────────────────────────────────────┐        │
  │ CotizacionConfirmadaEventListener::onFlush() │        │
  └──────────────────────────────────────────────┘        │
        │                                                 │
        ├─ CONFIRMADO · OPERATIVA ─► reactiva las filas canceladas (→ pendiente)
        │                              ⚠️ NO genera ninguna (ver §2.bis)
        │
        └─ CUALQUIER OTRO ─► propagarEstadoOperacion(CANCELADO)
           (cancelado / pendiente / enviado / archivado)

           POST .../operacion ──► GenerarOperacionProcessor ──┘  1 fila por Cotcomponente
                  ↑  una DECISIÓN del operador, con su botón

           POST .../operacion/plan ──► BibliaReconciliacionService::planificar()
                  ↓  revisión humana campo a campo (panel / consola)
           POST .../operacion/aplicar ──► sólo lo aprobado, si la firma vive (§3.5)
```

### Por qué `onFlush` y no `postUpdate`

`onFlush` es la única fase en la que se pueden **persistir otras entidades dentro de la misma
transacción** sin disparar un flush anidado (que provocaría recursión infinita del listener). El
precio: hay que instruir a Doctrine a mano con `UnitOfWork::computeChangeSet()` después de cada
`persist()` o `set*()`. Si se olvida, el cambio se pierde **en silencio**.

### Por qué la generación vive en un servicio

`BibliaSnapshotService` está fuera del listener porque sus reglas tienen tres consumidores que no
pueden divergir: el flujo automático al confirmar, la reconciliación (§3.5) y el comando de
consola. Tenerlas duplicadas era garantía de que las copias se desincronizaran.

### Granularidad: componente, no servicio

Una fila de La Biblia es un **`CotizacionCotcomponente`**, no un `Cotservicio` ni una cotización.
Un "Día 3 – City Tour" con transporte, guía y almuerzo produce **tres** filas independientes,
porque cada una puede tener proveedor, hora y estado de reserva distintos.

---

## 2.bis Confirmar ya NO arma la operación (02/09/2026)

Hasta esta fecha, pasar una cotización a CONFIRMADO generaba de golpe todas sus filas de La
Biblia. Ya no. **Armar la operación es una acción del operador**, con su botón:

```
POST /client/cotizacion/{id}/operacion   →   GenerarOperacionProcessor
```

### Por qué se separó

Son dos actos distintos que ocurren en momentos distintos. **Confirmar es comercial** —el cliente
aceptó— y puede pasar semanas antes de que la operación esté lista para armarse. Encadenadas, el
cuadro nacía con lo que hubiera ese día y había que corregirlo después, con el agravante conocido:
`generarParaCotizacion()` es idempotente, así que **re-confirmar no repara** una fila mal generada
(§3). El momento equivocado se quedaba pegado.

Separadas, el operador arma cuando tiene con qué.

### Lo que SÍ sigue siendo automático: reactivar

`CotizacionConfirmadaEventListener::reactivar()` conserva la mitad que no crea nada: si una
cotización se cancela por error y se vuelve a confirmar, sus filas vuelven de `cancelado` solas.

⚠️ Quitar eso **no** era lo que se pidió, y habría sido caro: sin ello las filas se quedan
canceladas para siempre, porque la reconciliación tampoco lo arregla —`estadoOperacion` está en la
lista de campos que jamás toca, es del operador—.

```
lo que existía   →  vuelve solo
lo que no existe →  no nace solo
```

### Dónde está el botón, y por qué ahí

En la fila **donde vive la operación**: la operativa si la hay, si no la confirmada. Es el mismo
sitio que el botón del plan de reconciliación, y a propósito: uno crea el cuadro y el otro lo
revisa.

⚠️ **Es idempotente y ésa es su forma de uso**, no un accidente: pulsarlo tras añadir servicios
completa lo que falta sin tocar lo demás. Y **no revive lo cancelado** — una fila que el operador
apagó se queda apagada, o cada uso del botón desharía decisiones suyas.

### Cómo se ve en el expediente dónde vive la operación

Desde que hay dos formas de que una confirmada esté vacía, **el estado ya no lo dice**:

```
confirmada sin filas  →  nadie ha pulsado el botón       (§2.bis)
confirmada sin filas  →  su operativa se las llevó       (Cotizaciones §6.j.3)
```

Se ven igual y significan lo contrario. Por eso el detalle del expediente lo pinta con el **dato**:

| Se ve | Qué significa |
|---|---|
| Badge oscuro **«OPERACIÓN · N»** + fondo teñido | Ahí viven N filas activas |
| Badge ámbar **«Sin operación»** | Es donde debería vivir y está vacía — una llamada a armarla |
| Nada | No es donde vive: se está vendiendo, es un histórico, o ya traspasó |

⚠️ **Cuenta las ACTIVAS.** Una confirmada que traspasó conserva sus 47 filas en `cancelado`;
contarlas diría que la operación sigue ahí.

⚠️ **El dato llega en UNA consulta**, desde `CotizacionFileItemProvider`, y por eso no es un getter
de la entidad: no hay relación inversa de `cotservicio` a `OperacionServicio`, así que un getter
haría una consulta **por propuesta** — N+1 con aspecto de campo, del que nadie sospecha porque
parece un atributo.

### ⚠️ Consecuencia para lo que ya existe

Las confirmadas anteriores conservan sus filas: no se borra nada. Lo que cambia es que una
confirmada **nueva** nace con el cuadro vacío hasta que alguien lo arme. Si alguien reporta «La
Biblia está vacía», la respuesta ya no es sólo §3.bis: puede ser que nadie haya pulsado el botón.

## 2.ter Quiénes van en una orden (03/09/2026)

```
Orden «Vuelo Cusco → Lima»
  Alejandra Valdivia Berrios   YMFLHB
  Alex Montes Ugarte           YMFLHB
```

Sale de los **subgrupos del componente** (`CotizacionCotcomponente::$grupos`), en el `Get` de la
orden.

### 🔥 Se DERIVA, no se congela — y es la excepción a la regla del snapshot

La Biblia es un snapshot **de valores** —precios, fechas— porque eso se acordó en un momento y no
debe moverse solo (§3). **Quién viaja no es un valor acordado**: cambia hasta el día de salida.

Una orden congelada se manda al proveedor con la lista de hace tres semanas: **con gente que ya no
va y sin quien se apuntó después**. Y obligaría a regenerar órdenes cada vez que entra alguien al
padrón, que es exactamente lo que §3 dice que la idempotencia impide.

Los subgrupos son del **expediente**, así que están siempre al día y sobreviven a abrir la
operativa.

### ⚠️ Vacío significa TODO EL GRUPO

Un componente sin subgrupos aplica a todos, y devolver ahí las 133 fichas convertiría la orden de
un hotel en un listado inútil. Se devuelve **vacío**, y quien pinta la orden dice «todo el grupo» —
que es la información, no la lista.

### ⚠️ Sin repetir, y ordenado por nombre

Un componente puede llevar varios subgrupos —el vuelo JA7018 son 7 PNRs— y una persona estar en
dos. Se cuenta por **pasajero**, no por pertenencia. Y se ordena por nombre: una orden se lee
pasando lista, y el orden de la base no ayuda a eso.

### ⚠️ Grupo de serialización PROPIO, sólo en el `Get`

`normalizationContext` es **de recurso**: poner los pasajeros en `operacion:item:read` los pondría
también en la colección, y el cuadro de La Biblia son cientos de filas recorriendo cada una los
subgrupos de su componente. N+1 sobre la pantalla que más se abre. Van en
`operacion:pasajeros:read`, declarado sólo en la operación de detalle.

## 3. Reglas del snapshot

`BibliaSnapshotService::generarParaCotizacion()` copia valores; **no** crea una vista en vivo.

### Es un snapshot, no un espejo

Editar la cotización después de confirmar **no actualiza** La Biblia. No hay re-sincronización
automática. Si cambia una hora o un proveedor en el editor, la fila operativa conserva el valor
viejo hasta que alguien lo reconcilie a mano (§3.5).

### Idempotencia

`findOneBy(['cotizacionComponente' => $cotcomponente])` corta la creación si ya existe fila para
ese componente. Consecuencia deseada: re-confirmar no duplica. Consecuencia no deseada:
**re-confirmar tampoco repara** una fila mal generada ni rellena campos añadidos después.

### <a id="33"></a>3.3 Filas comprables y filas de referencia

**No todo lo que se opera se compra.** Un hotel que el pasajero reservó por su cuenta no se le
compra a nadie —no lleva tarifa, no tiene costo, no va en ninguna Orden de Servicio— y aun así
es el dato más importante del día para el transportista: sin él no sabe dónde recoger. Lo mismo
con un vuelo `no_incluido`: marca la hora a la que el guía deja de tener al grupo.

Por eso hay dos clases de fila, y las distingue `OperacionServicio::isSoloReferencia()`:

| | Fila **comprable** | Fila de **referencia** |
|---|---|---|
| Condición | tiene tarifa y `modo ≠ no_incluido` | **sin tarifa** o `modo = no_incluido` |
| `cotizacionTarifa`, `monedaCotizada` | pobladas | **`null`** (por eso las columnas son nullable) |
| `costoCotizado` | de la tarifa | `'0.00'` — es referencia, no una compra de importe desconocido |
| Orden de Servicio | sí | **no** — `setOrdenServicio()` lanza `DomainException` → 422 |
| En la vista | checkbox de selección | icono de ojo + badge «Referencia», **sin atenuar** |

La regla vive en la entidad y **no se reimplementa en TypeScript**: viaja calculada en el campo
`soloReferencia` del grupo `operacion:item:read`, igual que `prioridadOperativa`. Es un cálculo y
no una columna a propósito — se deriva de datos que ya están en la fila, y duplicarlo abriría la
puerta a que las dos versiones se contradigan.

⚠️ La guarda de `setOrdenServicio()` es la fuente de verdad; el frontend sólo evita que el
operador llegue al modal para recibir un 422. Doctrine hidrata por reflexión, así que cargar
filas existentes nunca la dispara — sólo un `PATCH` que asigne la OS.

⚠️ **No se atenúan.** `cancelado` y `reemplazado` van con `opacity-55`; una fila de referencia
no, porque atenuarla diría lo contrario de lo que se quiere: el hotel del pasajero es justo lo
que hay que mirar para programar el recojo.

### Exclusiones silenciosas

Un componente **no** llega a La Biblia, sin error ni aviso en ninguna UI, si:

| Condición | Dónde |
|---|---|
| La cotización es de catálogo (`getCatalogo() !== null`) | `generarParaCotizacion()` — tours de exhibición con fechas nominales, sin expediente real |
| Tiene tarifas y **todas** son rol `ALTERNATIVA` | `generarParaCotizacion()` — venta opcional que nadie compró; programarla sería operar algo que quizá no se vendió |
| No se puede resolver una fecha | `resolverFechaServicio()` |

🔥 **No tener tarifa ya NO excluye.** Excluía, y era un descarte accidental: la regla decía «sin
tarifa» cuando la intención era «no se compra». Coincidían porque en la práctica todo lo que no
lleva tarifa es `no_incluido`… salvo el día en que a alguien se le olvide tarifar un guiado, que
desaparecía del cuadro de tráfico sin un solo aviso. Hoy entra como referencia y se ve.

Por eso «sin tarifa» y «sólo alternativas» se distinguen aunque `resolverTarifaPrimaria()`
devuelva `null` en los dos casos: el guard mira `getCottarifas()->isEmpty()`.

**No se excluye nada más.** Entran alojamientos, desayunos, cortesías, `no_incluido`,
`reemplazado` y componentes cancelados en la cotización. Es deliberado por ahora: primero se ve
todo lo que llega y después se decide qué dejar de generar (§9). Para que esa decisión sea
posible, el snapshot copia `tipoComponente`, `modoComponente` y `estadoComponente`, y la vista los
muestra y los deja filtrar.

### Helpers de resolución

| Helper | Regla |
|---|---|
| `resolverTarifaPrimaria()` | Descarta rol `ALTERNATIVA`, ordena por `grupoTarifa` ascendente (`null` al final) y toma la primera. |
| `resolverFechaServicio()` | `componente.fechaHoraInicio` normalizada a medianoche; si es `null`, cae a `cotservicio.fechaInicioAbsoluta`. |
| `resolverHoraRecojo()` | `H:i` del componente; **`null` si `isSinHorario()`** — por eso hay filas con `--:--`. |
| `resolverDescripcion()` | **Cinco prioridades**, de lo más específico a lo más genérico: 0) `prestador.servicioNombre` —qué le encargas, «Habitación suite superior»— → 1) `tarifa.nombreParaProveedorSnapshot` —cómo lo llama ÉL— → 2) `componente.nombreInternoSnapshot` —sólo lo tienen los manuales; ver `docs/Cotizaciones.md` §6.h— → 3) `tarifa.nombreInternoSnapshot` → 4) snapshot i18n `es` del componente → `'Servicio sin nombre'`. ⚠️ Esta tabla documentó tres durante un tiempo, cuando el código ya tenía cuatro. |
| `textoEspanol()` | Extrae el `content` en `es` de un snapshot i18n; se usa también para `contextoServicio`. |

⚠️ La prioridad 1 de la descripción es el **nombre interno de la tarifa** (`"Buffet regular"`,
`"Americana Royal Class"`), pensado para negociar con el proveedor. Por sí solo no identifica el
servicio: por eso la fila lleva además `contextoServicio` (el nombre del día del itinerario),
`tipoComponente` y el expediente.

### <a id="317"></a>3.17 Modal de expediente y «atrás» que cierra el modal (2026-08-17)

Clic en el nombre del expediente (o del cliente) → modal con dos paneles colapsables —namelist
y documentos cargados— y un botón que salta al editor de la **cotización de la que cuelga ese
servicio** (`getCotizacionId()` la resuelve). El detalle se pide bajo demanda (`file:item:read`),
no en cada fila del cuadro.

⚠️ **El botón «atrás» del móvil cierra el modal, no sale de la vista.** Cada modal empuja una
entrada de history al abrirse; `popstate` la consume cerrándolo. Cerrar con la X hace lo mismo
por código (`history.back()`), así la entrada nunca queda colgando —un «atrás» que no hace
nada—. Aplica a los seis modales de la pantalla (expediente, pagos, bitácora, edición, alta,
mensajes), no sólo al nuevo. Sin esto, volver con un modal abierto mandaba al menú y perdía
dónde estabas.

### <a id="319"></a>3.19 La Orden es un documento: contenido propio y acciones (2026-08-20)

#### El contenido

Hasta ahora la Orden **no tenía contenido propio**: sus ítems eran las filas vivas de La Biblia
enlazadas por `orden_servicio_id`. Eso dejaba una contradicción sin salida:

```
liberas las filas  →  la Orden anulada queda VACÍA
las dejas atadas   →  no se pueden volver a pedir en otra Orden
```

`OperacionOrdenServicioItem` congela lo que el documento pidió —descripción, fecha, hora, pax,
cantidad, importe con su moneda, prestador y servicio por NOMBRE— más `operacionServicioId` como
**soft-link** hacia atrás. Anular = **soltar el vínculo vivo y conservar el congelado**.

⚠️ **Se congela al EMITIR, no al crear.** Un `borrador` todavía se compone y debe seguir siendo
una vista viva; al emitirlo pasa a ser un documento, y un documento dice lo que decía el día que
se mandó.

⚠️ **Lo cancelado no vuelve a entrar, y no hizo falta marcarlo.** Al soltarse la fila queda
disponible, pero `OperacionServicio::motivoNoComprable()` le impide entrar en la siguiente si el
componente está cancelado o reemplazado. La guarda ya existía: reutilizarla es un mecanismo menos.

`reemplazaA` encadena las reemisiones — «OS-014 anulada → OS-021» es la pregunta que se hace de
verdad cuando un proveedor reclama.

#### La suciedad

Después de emitir, La Biblia sigue moviéndose: **el cambio de fecha se hace en la cotización**,
para que el programa que ve el cliente quede al día, y de ahí baja por el snapshot. Entonces el
documento y la realidad se separan.

`getDivergencias()` compara cada línea congelada con su fila viva y `isSucia()` lo resume. La
Orden **no se corrige sola** —un documento mandado no cambia solo—: se marca y una persona
decide. En pantalla sale el detalle («se pidió para el 01/09 y ahora es el 04/09») y un botón
que anula para reemitir.

Dos afinados que evitan avisos falsos: un `borrador` nunca está sucio, y un `costoNegociado` en
cero **no** cuenta como cambio —mientras nadie pacte, lo vivo es lo cotizado—.

#### Las acciones

Crear una orden eran *N* `PATCH` para atar cada fila más un `POST` para la cabecera, orquestados
desde el navegador. Si la pestaña se caía en medio quedaban filas atadas a una orden que no llegó
a existir, y las reglas vivían la mitad en `conflictoSeleccion` del front — dos pestañas abiertas
armaban lo que la vista impedía.

```
POST /platform/ops/orden-servicios/emitir        valida · crea · enlaza · congela · devuelve
POST /platform/ops/orden-servicios/{id}/estado   valida la transición · ejecuta · devuelve
```

Mismo patrón que `/cotizacions/{id}/operacion/aplicar`, que ya existía en el módulo.

⚠️ **`estadoOs` salió de `operacion:write`.** Con dos puertas a la misma transición las reglas se
escapan por la que no mira nadie: un `PATCH` genérico no sabe distinguir «emitir» —que congela—
de «corregir el número». Lo cazó el typecheck del front al quitarlo, que es la señal de que la
puerta quedó cerrada de verdad.

Las validaciones viven ahora en `OperacionOrdenEmision::validar()`: un solo expediente, un solo
comprador **por id** y todo comprable. Se comprueban **antes de tocar nada**, así el error llega
entero y no a mitad de una orden ya medio construida.

#### Por qué NO un microservicio Node/Nuxt

Se evaluó y se descartó con la regla que ya está escrita en `CLAUDE.md`: **Node calcula, PHP
persiste**. Emitir una orden **escribe** —cabecera, ítems, vínculos, estado—, y la lógica ya vive
en PHP. No hay conexión persistente, ni regla que ya exista en TypeScript, ni falta de cliente:
los tres motivos que lo justificarían están ausentes. La única pieza que encajaría algún día es
la maquetación de un PDF, que calcula y no persiste.

#### Dos severidades: lo que obliga a reemitir y lo que sólo hay que aplicar

No todo lo que se mueve tiene la misma consecuencia, y meterlo todo en «sucia» convierte el
aviso en ruido que nadie mira.

**Mayor → reemitir** (`getDivergencias()`, aviso ámbar). Si el proveedor tuviera esta orden
delante, estaría leyendo algo que ya no es cierto:

| Condición | |
|---|---|
| **Cambió el comprador** | el peor de todos: la orden **ya se mandó a la empresa equivocada** |
| La fila ya no está en la orden | |
| Cambió la fecha | se toca en la cotización, para que el programa del cliente quede al día |
| Cambiaron los pax | |
| Cambió el importe | |
| Cambió la hora **ya confirmada** | modificación: hay que avisar al cliente y al proveedor |

**Menor → aplicar** (`getCambiosMenores()`, aviso azul, botón «Actualizar y avisar»):

| Condición | |
|---|---|
| Aparece la hora de recojo **por primera vez** | el proveedor la **confirmó** |

⚠️ **La hora tiene dos lados, y confundirlos rompe el flujo normal.** Cuando le pides un
servicio a un proveedor, **la hora te la dice él al confirmar**: que aparezca es el final
correcto del proceso, no un descuido. Tratarlo como cambio sucio obligaría a reemitir **cada
orden que sale bien**.

Lo que distingue una cosa de la otra es `OperacionOrdenServicioItem::$horaRecojoConfirmada`,
congelado al emitir:

```
estaba NULA  y ahora hay hora   →  confirmó      →  menor
estaba PUESTA y ahora es otra   →  modificó      →  mayor
```

Y el aviso que sale **no es el mismo**: al cliente se le confirma la hora para su programa y al
proveedor se le acusa recibo. Mandar un «cambio de horario» donde hubo una confirmación siembra
dudas sobre un servicio que va bien.

`POST /platform/ops/orden-servicios/{id}/aplicar-menores` copia el dato al documento y **la
orden sigue emitida**. De ahí cuelgan las acciones; hoy quedan en el log y el envío va aparte y
en asíncrono, porque una caída del correo no puede deshacer una actualización ya aplicada.

⚠️ Y el **botón es otro**, no el de reemitir. Confirmar una hora y rehacer un documento son dos
gestos con consecuencias muy distintas; darles el mismo botón invita a usar el destructivo por
costumbre.

#### La reemisión: la sucesora nace con la anulación, no después

⚠️ **Anular a secas pierde trabajo.** Si el botón sólo anulara, las filas quedarían sueltas en
La Biblia y bastaría con que el operador cerrara la pestaña: al día siguiente nadie recuerda qué
siete filas iban juntas ni a quién se le pedían.

Por eso «Reemitir» es **una sola llamada** a `/orden-servicios/emitir` con `reemplazaAId` y
`soloBorrador: true`. El servidor, en la misma transacción:

```
1. anula la anterior      →  suelta sus filas · CONSERVA sus líneas congeladas
2. valida                 →  ahora las filas están libres y pasan
3. crea la sucesora       →  las vuelve a enlazar · deja escrito `reemplazaA`
```

En ningún momento hay filas huérfanas.

**Y nace en borrador, no emitida.** Reemitir no es mandar otro papel a ciegas: es rehacerlo para
revisarlo. Mientras es borrador sigue siendo una vista viva, así que refleja lo que La Biblia
diga **ahora** — que es justo lo que había cambiado. El operador lo comprueba y emite.

#### Qué está probado, y un fallo que salió al probarlo

45 tests unitarios, sin contenedor ni base:

| Archivo | Cubre |
|---|---|
| `BibliaReconciliacionTest` | el **sincronizador**: crear, sin cambios, actualizar, huérfano, y las dos ramas del conflicto |
| `OperacionOrdenEmisionTest` | las reglas de `validar()` y la máquina de estados |
| `OperacionOrdenDivergenciaTest` | congelar al emitir, la suciedad y la anulación |

El sincronizador se prueba **simulando su única consulta**: `planificar()` sólo toca la base
para traer las filas de los cotservicios, así que con un stub del repositorio queda toda la
lógica de comparación al descubierto. Hasta ahora no tenía ninguna prueba.

⚠️ **`createStub` y no `createMock`.** `phpunit.dist.xml` lleva `failOnNotice="true"`, y un mock
sin expectativas dispara un aviso que tumba la suite — con razón: anuncia una comprobación que
no existe.

**El fallo que encontraron:** en `EmitirOrdenProcessor`, `validar()` corría **antes** de anular
la orden anterior. Como `validar()` rechaza un servicio que ya esté en otra orden y al reemitir
las filas siguen atadas a la que se sustituye, reemitir en una sola llamada fallaba **siempre**
con «ya está en la orden OS-014» — la orden que uno mismo acababa de pedir que se anulara. El
orden de esas dos líneas no era cosmético.

#### Lo que queda pendiente

El aviso al proveedor al emitir. Va **después del flush y en asíncrono**: si fuera dentro, una
caída del correo tumbaría una orden que ya está bien emitida. El sitio es
`CambiarEstadoOrdenProcessor`.

### <a id="318"></a>3.18 Los tres papeles: cotizado ↔ override, y el fin de los conflictos (2026-08-20)

**Es la respuesta al problema que quedó abierto: la sincronización entre La Biblia y el
componente de la cotización.** No se resolvió — se hizo desaparecer.

#### El problema

Había **una sola columna** para dos cosas distintas. `BibliaSnapshotService` escribía
`prestadorNombre` desde la cotización, y el operador la sobrescribía a mano en el cuadro de
tráfico. Con dos autores sobre la misma celda, cada reconciliación tenía que **adivinar quién
había sido** —comparando contra `snapshotOrigen`— y sacar un conflicto para que decidiera una
persona.

Y encima el operador editaba **texto libre**: `compradorMaestroId` seguía apuntando a la empresa
anterior. La Orden de Servicio **agrupa por comprador**, así que un nombre tecleado no agrupaba
con la ficha del catálogo aunque se leyera igual.

#### La forma

Dos columnas por papel: la que dice lo cotizado y la que lo **anula**.

| Lo que dijo la cotización | Lo que anula operaciones |
|---|---|
| `prestadorMaestroId` / `prestadorNombre` | `prestadorOverrideMaestroId` / `prestadorOverrideNombre` |
| `prestadorServicioMaestroId` / `…Nombre` | `prestadorServicioOverrideMaestroId` / `…OverrideNombre` |
| `compradorMaestroId` / `compradorNombre` | `compradorOverrideMaestroId` / `compradorOverrideNombre` |

```
la cotización escribe SÓLO la de la izquierda   →  nunca pisa al operador
el operador escribe SÓLO la de la derecha       →  nunca lo pisa la cotización
lo que vale = override ?? cotizado
```

No hay nada que adivinar, así que **no hay conflicto**. Lo que vale lo dan
`getPrestadorEfectivoNombre()`, `getPrestadorServicioEfectivoNombre()`,
`getCompradorEfectivoMaestroId()` y compañía.

⚠️ **Los campos cotizados perdieron `operacion:write`.** Si la API siguiera aceptándolos habría
otra vez dos autores sobre la misma celda y el conflicto volvería por la puerta de atrás.

⚠️ **Todo lo que actúe sobre estos papeles lee el EFECTIVO.** Leer `getCompradorNombre()` a secas
es leer lo que dijo la cotización e ignorar lo que hizo el equipo — justo lo que estos campos
vienen a impedir. La Orden agrupa por `compradorEfectivoMaestroId`, por **id** y no por texto.

#### El rescate de lo ya editado

Las ediciones antiguas vivían en la columna cotizada, y allí las habría pisado la siguiente
reconciliación. Se reconocen sin ambigüedad —`snapshotOrigen` guarda la foto de lo que puso la
cotización, así que **valor ≠ foto ⟹ lo cambió una persona**— y `Version20260820180000` las mueve
a la columna override y devuelve la cotizada a su valor de origen.

#### Cuál de los cuatro llega a la Orden

De las cuatro columnas del comprador **sólo un valor viaja**, y la Orden lo guarda en un campo
que se llama igual que el cotizado — cuidado con eso al leer:

```
OperacionServicio                          OperacionOrdenServicio
  comprador_override_maestro_id  ─┐
  comprador_override_nombre       ├─ el que gane ──►  comprador_maestro_id
  comprador_maestro_id           ─┤                   comprador_nombre
  comprador_nombre               ─┘
```

**La prioridad es `override ?? cotizado`**, resuelta por `getCompradorEfectivoMaestroId()`. Del
prestador y su servicio **no viaja nada**: la Orden es un documento de compra y va dirigida a
quien ejecuta el encargo, no a quien opera.

Tres momentos, y los tres leen el efectivo:

1. **Agrupar** — `conflictoSeleccion` exige que las filas seleccionadas compartan
   `compradorEfectivoMaestroId`. Por **id**, no por texto: «Futurismo» tecleado y «Futurismo
   Jonathan» del catálogo serían dos órdenes.
2. **Proponer** — el modal se rellena con el efectivo de la primera fila.
3. **Decidir** — el operador puede cambiar el destinatario en el modal antes de crear. Esa
   elección se guarda en la Orden y **no vuelve a mirar al servicio**: la Orden es un documento
   emitido, no una vista.

⚠️ Una vez creada, cambiar el override en La Biblia **no reescribe la Orden**. Es deliberado —un
documento ya mandado no cambia solo— pero significa que corregir el destinatario después exige
editar la Orden, no la fila.

#### «Real» se retiró del vocabulario (20/08/2026)

`real` describía tres cosas distintas y ninguna con precisión. `Version20260820220000`:

| Antes | Ahora | Por qué |
|---|---|---|
| `prestador_real_*`, `comprador_real_*` | `*_override_*` | no es «lo real», es **lo que anula** a lo cotizado. Vacío = sin cambio |
| `hora_recojo_real` | `hora_recojo` | no hay otra hora de recojo con la que confundirla; el par es con `hora_componente`, la vendida |
| `costo_real_operativo` | `costo_negociado` | dice de dónde sale la cifra: de negociar, no de una verdad superior |
| `moneda_real` | `moneda_negociada` | va con el costo; dejarla en «real» la habría dejado huérfana |

⚠️ **La migración va a mano, con `CHANGE` y no con `ADD` + `DROP`.** Doctrine propone lo segundo
para cinco de las columnas —`doctrine:schema:update --dump-sql` lo enseña— y eso **borra el
contenido**: crea la nueva vacía y tira la vieja con lo que hubiera dentro. Misma trampa que en
`Version20260819230000`.

#### El contacto: fuera las copias congeladas

`prestadorTelefono` y `prestadorDireccion` eran una copia por fila y **nunca se llenaron**:
0 de 42 en producción. El snapshot las ponía a `null` y la pantalla no tenía dónde escribirlas,
así que sólo eran una segunda fuente de verdad esperando a discrepar del catálogo.

Lo que las delató fue la **asimetría**: el comprador —a quien de verdad se le manda la Orden—
nunca las tuvo, porque su contacto siempre se resolvió vivo desde `TravelOrganizacion` por su id.
Dos mecanismos distintos para el mismo dato, y el congelado era el del papel que **no** recibe
la Orden.

Ahora los dos salen del mismo sitio: `contactoDeOrganizacion(maestroId)` en el store, con
`contactoDePrestador()` y `contactoDeComprador()` encima. **Por el id EFECTIVO**: si operaciones
cambió el prestador, el teléfono que hace falta es el de la organización nueva. Corregir un número en
la ficha lo corrige en todas las filas — justo lo que una copia por fila impedía
(`Version20260820200000`).

#### En la pantalla

El input va contra un `<datalist>` del catálogo, uno solo para todo el cuadro: con un selector
por fila el DOM crecería a 99 opciones × cientos de filas. Si lo escrito no casa con ninguna
organización (`resolverOrganizacion()`) **se revierte y se avisa**, en vez de guardar un nombre suelto que rompería la agrupación
de la Orden.

Cuando operaciones cambió algo, el campo se pinta en color y debajo aparece «Cotizado: …» como
informativo. Si coinciden no se pinta nada: repetirlo en cada fila convertiría el dato en ruido.

Vaciar el campo **no** significa «no hay nadie», significa «vuelve a lo cotizado» — por eso
`primeroConTexto()` trata la cadena vacía como ausente.

### <a id="315"></a>3.15 La hora de recojo y la vendida son campos distintos (2026-08-17)

`horaRecojoReal` y `horaComponente` eran el mismo campo, así que fijar el recojo pisaba la hora
con la que se vendió y se perdía la referencia. Separados:

| Campo | Qué es | Quién lo gobierna |
|---|---|---|
| `horaComponente` | La hora **como se vendió** al cliente | La cotización (snapshot, reconciliación) |
| `horaRecojoReal` | La hora de recojo real que fija el operador | El operador — la reconciliación **no la toca** |

La fila muestra la de recojo; si no hay, el placeholder es la vendida (fallback). Y cuando
difieren, debajo sale «vend. HH:MM». Migración: `horaComponente` se rellena con lo que hoy
tiene `horaRecojoReal`, que es de donde salía; nada se pierde.

### <a id="316"></a>3.16 El servicio del prestador encabeza el voucher (2026-08-17)

`descripcionServicio` (lo que ve el proveedor) ahora prioriza el **servicio del prestador**:
«habitación suite superior», no «Hotel 4 estrellas». Sale del `ProveedorServicio` del
componente. La cadena completa:

```
servicio del prestador → nombreParaProveedor → nombre interno de la tarifa → nombre del componente
```

Se guarda además aparte como `prestadorServicioNombre`, para tenerlo disponible al componer el
voucher sin re-resolver la cadena. Nulo si el componente no especifica servicio de prestador.

### <a id="314"></a>3.14 Bitácora de cambios de estado (2026-08-17)

Cada cambio de `estadoReservaProveedor` o `estadoOperacion` deja una línea en
`operacion_estado_bitacora`. Responde dos preguntas distintas:

- **«¿cuánto lleva así?»** — el campo `estadoReservaProveedorDesde` en el propio servicio, que
  el listener actualiza en cada cambio. Directo, sin join, porque se pinta en cada fila del
  cuadro («hace 3 h» bajo el estado).
- **«¿por dónde pasó y quién?»** — la bitácora completa, a un clic en el reloj. Se pide bajo
  demanda (`fetchBitacoraEstado`), nunca embebida: es una serie temporal que sólo se mira de
  una fila a la vez.

Lo escribe `EstadoBitacoraListener` en `onFlush`, no a mano: así el cambio y su rastro entran
en la misma transacción, y ningún estado se mueve sin quedar registrado. El usuario se resuelve
del token; si no hay sesión (comando, reconciliación) queda sin firma, pero el hecho se anota
igual.

⚠️ **La historia empieza aquí.** Los cambios anteriores al despliegue no existen —no se pueden
inventar— así que las filas viejas no tienen «desde» hasta su próximo cambio.

⚠️ Un `UPDATE` en SQL se salta el listener y no deja rastro. Es la misma razón por la que las
correcciones de estado van por el ORM, nunca por consola de MySQL.

### <a id="313"></a>3.13 «Pagado» era mentira, y faltaban las noches (2026-08-17)

Dos arreglos de lectura sobre la fila:

- El editable se rotulaba **«Pagado»** en la tarjeta móvil. No se ha pagado nada — es el costo
  **negociado**, y en una orden en borrador aún puede que no exista pago. Renombrado a
  «Negociado».
- La fila decía «Hotel 4 estrellas» sin las **noches**. `cantidadComponente` (snapshot de
  `CotizacionCotcomponente::$cantidad`) ya se multiplicaba en el costo (§3.9) pero no se
  guardaba aparte, así que no se podía mostrar. Ahora sale junto al pax: «4 noches · 2 pax».
  Un `1` no se pinta —«1 noche» para un traslado es ruido— y la palabra depende del tipo:
  `alojamiento` → noches, el resto → uds.

Se rellena por reconciliación como el resto de campos de la cotización; nace en 1.

### <a id="312"></a>3.12 El editor de costo es UN componente, con estado local (2026-08-17)

El importe replicado en todas las filas de una orden —escribes en una y aparece el mismo
número en las demás— era el síntoma. La causa: el editor vivía **tres veces** (columna de
escritorio, tarjeta móvil, detalle de orden) compartiendo dos `Record` globales indexados por
id de servicio. Cuando la orden empezó a traer los servicios embebidos con `operacion:read`,
que NO lleva el id del recurso, todas las filas cayeron en la clave vacía `''`: un solo
borrador para todas.

Dos arreglos, y hacían falta los dos:

1. **`OperacionServicio::getServicioId()`** expone el id en `operacion:read`. Sin él, el
   embebido no se puede distinguir ni guardar (el PATCH no tiene destino). El front lo unifica
   con `idDe()`: La Biblia trae `id`, la orden `servicioId`.
2. **`EditorCostoNegociado.vue`**, con estado LOCAL por instancia. Cada fila conoce sólo lo
   suyo, así que la colisión de claves es imposible por construcción. El padre no gestiona
   borradores: recibe un `@guardar` con el payload validado y hace el PATCH.

El editor es **click-to-edit**: en reposo se ve sólo el valor (subrayado punteado, «registrar»
si está vacío); un toque abre select + input + guardar/cancelar, con autofocus. Es lo que pidió
el operador —«que el campo sólo aparezca al editar»— y en un móvil quita el ruido de N
formularios abiertos a la vez.

⚠️ La regla que sale de aquí: **un editor que se repite en tres sitios es un componente, no
tres bloques con helpers compartidos.** El estado global por id fue exactamente lo que
convirtió un id ausente en corrupción cruzada entre filas.

### <a id="311"></a>3.11 Los importes se guardan con BOTÓN, no al salir del campo (2026-08-17)

Estuvieron con `@change`, que dispara al perder el foco. En un móvil eso es invisible: escribes
el número, el teclado tapa media pantalla, y no hay nada que diga que pasó algo — ni un gesto
evidente para provocarlo, porque «tocar fuera» no lo asocia nadie con guardar.

El síntoma reportado fue **«no guarda nada»**, y era exacto desde el punto de vista de quien lo
usa. De hecho no guardaba: comprobado en el access log, cero PATCH mientras se editaba, porque
el campo nunca perdía el foco.

Cómo funciona ahora:

- Lo escrito vive en un **borrador aparte** (`borradorCosto`, `borradorMoneda`), por id de fila.
- El campo se pone **ámbar** y aparece un **botón naranja** en cuanto hay algo distinto que
  enviar. Sin cambios, no hay botón: nada se envía por accidente.
- `Enter` guarda, para no obligar a apuntar a un botón de 28px.
- **Importe y moneda van juntos en un solo PATCH.** Se negocia «250 soles», no «soles» y luego
  «250»; dos peticiones seguidas dejaban guardado un estado intermedio que nadie pidió.
- ⚠️ **El borrador se limpia sólo si se guardó.** Si falla, lo escrito sigue en pantalla y el
  botón encendido para reintentar — perder el número por un fallo de red sería lo peor que
  podría hacer esta pantalla.

Vale para los tres sitios donde se edita: la columna de escritorio de La Biblia, su tarjeta
móvil (§3.10) y el detalle de la orden.

### <a id="310"></a>3.10 Los puntos de corte esconden columnas enteras (2026-08-17)

El cuadro es una tabla y cada columna se retira a un ancho distinto:

| Columna | Aparece desde |
|---|---|
| Hora, Servicio, Reserva | siempre |
| Pax | `sm` (640px) |
| Prestador | `md` (768px) |
| Expediente | `lg` (1024px) |
| **Costo** | **`xl` (1280px)** |

⚠️ **Un campo escondido detrás de un punto de corte no es «menos visible»: no existe.** El
costo real y su moneda son editables desde el primer día y **en un teléfono no se habían
podido tocar nunca** — la columna que los contiene arranca en 1280px. El único síntoma era
que el operador no los encontraba.

Por eso lo que se retira tiene que **reaparecer en la tarjeta móvil**, no simplemente
desaparecer: el prestador ya lo hacía (`md:hidden`), y ahora también el costo negociado
(`xl:hidden`). Si añades una columna, decide dónde vive en móvil antes de ponerle el
`hidden`.

### <a id="39bis"></a>3.9 bis Qué nombre lleva cada pantalla (2026-08-17)

Una fila tiene **dos nombres** y la pantalla decide cuál manda. No es estética:

Una fila tiene **TRES** nombres, y cada pantalla decide cuál manda. No es estética:

| Campo | Qué es | Para qué sirve |
|---|---|---|
| `descripcionServicio` | Cómo conoce el PROVEEDOR el servicio. Resuelto con respaldo: `nombreParaProveedor` → nombre interno → nombre del componente | Pedirle el servicio |
| `tarifaNombre` | El nombre interno de la tarifa, **sin resolver nada** | Buscarla en el tarifario |
| `contextoServicio` | El día del itinerario | Ubicar la fila en la jornada |

**Manda `descripcionServicio` en la Orden** y `contextoServicio` en La Biblia. En La Biblia
estás ubicando un servicio dentro de tu día; en la Orden se lo estás describiendo a quien te lo
vende, y ahí un código interno que él no reconoce es pedirle que adivine — la orden es su
documento, no el nuestro. Los otros quedan debajo, más pequeños. Ninguno se esconde.

⚠️ **`tarifaNombre` existe porque `descripcionServicio` COLAPSA dos nombres en uno.** En cuanto
la tarifa tiene `nombreParaProveedor`, el nombre interno desaparece de la pantalla — y los dos
hacen falta a la vez: «Del Origen Al Presente de Lima» es lo que él entiende, y «Pool City Lima
CT002 (Base 1-4)» es lo que buscas en el tarifario cuando te pregunta por qué le pagas eso.

Las dos pantallas lo esconden **sólo si coincide** con `descripcionServicio`, que es el caso de
una tarifa sin nombre para el proveedor: ahí la misma línea dos veces no informa de nada.

Se rellena por reconciliación, no por migración: `operacion:resincronizar` lo propone como
cualquier otro campo de la cotización. Hasta entonces queda nulo y la pantalla enseña lo que
enseñaba antes.

### <a id="39"></a>3.9 El costo de una fila: SIEMPRE los dos factores (2026-08-17)

```
costoCotizado = Σ (monto × cantidad_de_la_tarifa × cantidad_del_componente)
                  sobre las tarifas que no son ALTERNATIVA
```

Es la fórmula del cotizador (`cotizacionEditorStore`), no una propia. Si cambia allí, cambia
aquí.

⚠️ **No es que unos multipliquen por noches y otros por pax.** Es una sola fórmula con tres
términos, siempre. Que en los datos de hoy uno de los dos multiplicadores valga casi siempre 1
es casualidad del expediente:

| Servicio | monto | × tarifa | × componente | = |
|---|---|---|---|---|
| Hotel 4 estrellas | 115.00 | 1 | 4 noches | 460.00 |
| Tacama | 260.00 | 2 pax | 1 | 520.00 |
| Hotel 3 noches, 2 habitaciones | 100.00 | 2 | 3 | **600.00** |

Y ésa es la razón de que el fallo anterior —`tarifaPrimaria->getMontoCosto()` a secas—
aguantara sin que nadie lo viera: **acertaba siempre que los dos factores fuesen 1**, que es el
caso mayoritario. Parecía funcionar la mayor parte del tiempo. Cuando no, guardaba un número
menor y bien formado, y ése es el que suma la Orden de Servicio.

Medido al corregirlo: 25 filas cortas en producción, entre 12 y 1.375 soles cada una.

### <a id="38"></a>3.8 Qué se congela y qué se resuelve vivo (2026-08-17)

No todo lo que la fila enseña sale del snapshot, y la línea que los separa es **si el dato
envejece bien**:

| Dato | Dónde vive | Por qué |
|---|---|---|
| Nombre del prestador y del comprador | Congelado en la fila | Es la identidad con la que se vendió y con la que se firma la OS. Además **es editable en el cuadro**, y esa corrección es lo que permite agrupar dos filas del mismo proveedor escrito distinto. |
| Descripción, contexto, pax, importes | Congelado | Cambiar el catálogo no puede reescribir lo ya vendido. |
| **Teléfono y dirección del prestador** | **Vivo, contra el maestro** | Cuando el conductor no aparece sirve el número de HOY, no el del día que se cotizó. Un teléfono caducado con apariencia de bueno es peor que no tener ninguno. |

Por eso `BibliaSnapshotService::construirValores()` escribe `prestadorTelefono` y
`prestadorDireccion` a `null`, y quien los rellena es el front.

#### El hidratador en lote

`operacionStore.resolverContactoDeProveedores()` junta los `prestadorMaestroId` y
`compradorMaestroId` distintos y los pide de una vez con `?id[]=` a
`/platform/travel/proveedores` — el mismo mecanismo que las etiquetas de lugar (§3.3.b), que
atiende `UuidBatchIdExtension`. Los dos papeles van en la **misma** petición porque desde la
unificación ambos son `Proveedor`. Medido en producción: 12 proveedores distintos en 42 filas,
así que fila a fila serían decenas de peticiones para repetir doce respuestas.

⚠️ **Se llama DENTRO de `fetchServicios()`, antes del `finally`**, en paralelo con el de
lugares. Es lo que evita el efecto que se quería evitar: si se llamara después, la tabla se
pintaría sin teléfonos y se rellenaría medio segundo más tarde. Como todo el cuadro entra por
`fetchServicios()`, vale igual para cada filtro y cada recarga — **cualquier carga nueva de
filas tiene que pasar por ahí o saldrá incompleta**.

#### La degradación: el maestro manda, lo guardado es la red

`contactoDePrestador()` devuelve el dato del catálogo y, si no hay, el congelado en la fila.
Ese orden sostiene a los proveedores de un solo uso: si el proveedor se dio de baja, se borró
o nunca estuvo en el catálogo, queda lo que la fila tenga. Al revés —guardado primero— un
número viejo taparía para siempre al corregido en el maestro.

Si el catálogo no responde, el cuadro se abre igual: la fila conserva nombre, hora y pax, que
es lo que la ubica.

### <a id="36"></a>3.6 Las FK: por qué CASCADE y no RESTRICT

Las tres referencias de `OperacionServicio` al árbol de la cotización eran `RESTRICT`, y eso
hacía **imposible** el flujo que el módulo entero promete.

El editor guarda con un **PUT del árbol completo**: lo que ya no viene en el payload se orfaniza
y Doctrine lo borra. Así que en una cotización ya confirmada, quitar un día, borrar un componente
o —lo más común de todo— **reemplazar una tarifa** terminaba en violación de clave ajena: un 500
en mitad del guardado, sin ningún mensaje que explicara qué pasaba. No se notaba sólo porque La
Biblia estaba vacía; el día que se confirmara la primera cotización, empezaba.

| Referencia | Regla | Por qué |
|---|---|---|
| `file`, `cotizacionServicio`, `cotizacionComponente` | **CASCADE** | si desaparecen de la cotización, la fila se queda sin origen y no significa nada |
| `cotizacionTarifa` | **SET NULL** | cambiar de tarifa es rutina de negociación: la fila debe sobrevivir para que la reconciliación le ponga la nueva |
| `ordenServicio` | **SET NULL** | borrar una OS desasocia, no destruye (§5) |

⚠️ Con CASCADE se pierde lo que el operador hubiera escrito en esa fila. Es aceptable —alguien
decidió que ese servicio ya no va— y sigue siendo mucho mejor que un 500. Lo que **no** puede
pasar es que se pierda por cambiar una tarifa, y por eso la tarifa es la excepción.

⚠️ Consecuencia para el reconciliador: el caso `huerfano` **no** cubre «borraron el componente»
—esas filas desaparecen solas con el CASCADE— sino «el componente sigue existiendo pero dejó de
producir valores» (le quitaron la fecha, se quedó sólo con alternativas).

### <a id="37"></a>3.7 El file de la fila: por qué no se lee del objeto en memoria

`OperacionServicio::$file` es **NOT NULL**, y sale de `Cotizacion::getFile()`. Si ese file llega
nulo, no se produce una fila incompleta: **muere el flush entero**, con un
`SQLSTATE[23000]: Column 'file_id' cannot be null` lanzado desde el fondo de Doctrine, sin una
sola línea de `src/` en la traza y sin mencionar ni la cotización ni el expediente. El
14/08/2026 le costó a un operador el guardado completo de una cotización
(`PUT /platform/sales/cotizacions/38e83a6c…`), y en el log no había nada que dijera de qué
cotización se trataba.

La causa está en el mismo PUT del árbol completo de §3.6: si el payload llega sin `file` —o con
`file: null`— la cotización se desengancha de su expediente. La columna de `cotizacion_cotizacion`
**sí** es nullable, así que Doctrine lo aceptaba sin rechistar; el único motivo por el que esto
dio la cara es que La Biblia copia ese file a una columna que no lo es. **Sin la generación de
operaciones de por medio, el desenganche habría sido totalmente silencioso.**

Hay dos guardas, y hacen falta las dos:

| Dónde | Qué hace | Por qué ahí |
|---|---|---|
| `Cotizacion::setFile()` | ignora el `null` que desengancharía un expediente ya asignado | ataca la raíz: ningún camino de escritura puede dejar la cotización huérfana. Reasignar a **otro** file sí se permite — eso es deliberado |
| `BibliaSnapshotService::resolverFile()` | si el objeto no trae file, lo lee **de la base**; si tampoco hay, lanza `DomainException` con el número de cotización | corre dentro de `onFlush`, con la cotización a medio escribir: lo que el objeto diga del padre puede ser lo que trajo el payload y no lo guardado |

El fallback es una **consulta escalar**, no un `find()`: `find()` pasaría por el UnitOfWork que
estamos atravesando y devolvería el mismo objeto en memoria que ya sabemos sospechoso. El
expediente de una cotización no cambia por confirmarla, así que ante la duda manda la fila
persistida.

`BibliaReconciliacionService` usa el mismo resolutor al crear filas: son dos consumidores de la
misma regla y no pueden divergir.

### <a id="35"></a>3.5 Reconciliación: plan → revisión → aplicar

**Nada se escribe sin que una persona haya visto el diff.**

Hubo un botón que borraba La Biblia y la regeneraba de un golpe. Era correcto mientras
las filas no contenían nada propio; dejó de serlo en cuanto pasaron a guardar hora pactada
por teléfono, prestador, teléfono del recojo y —cuando se rellene— costo real. Un merge
automático, por conservador que sea, es tan peligroso como un borrado: el daño sólo tarda
más en aparecer. Por eso son **dos operaciones**:

```
POST /platform/sales/cotizacions/{id}/operacion/plan      → diff firmado. NO escribe.
        ↓  una persona revisa, marca campo a campo, confirma
POST /platform/sales/cotizacions/{id}/operacion/aplicar   → sólo lo aprobado
```

#### La pregunta que lo hace posible

Si la fila y la cotización difieren, **¿quién de los dos se movió?** Sin respuesta no se
puede decidir nada en automático. La responde `OperacionServicio::$snapshotOrigen`, la
foto escalar de lo que escribió el snapshot la última vez:

```
actual == origen  →  el operador no lo tocó  →  la cotización manda
actual != origen  →  el operador lo editó    →  CONFLICTO, decide una persona
```

Filas anteriores a esa columna tienen `{}`: **todo se trata como conflicto**. Es lo
conservador — si no se sabe quién se movió, no se decide solo.

⚠️ `aplicarValores()` reescribe `snapshotOrigen` **entero**, incluso con los campos que se
rechazaron. Guardar lo propuesto significa «esto ya te lo pregunté»; conservar lo viejo
haría que el siguiente plan volviera a proponer lo mismo y el operador tuviera que
rechazarlo una y otra vez.

⚠️ **`valoresActuales()` tiene que devolver TODOS los campos de `ETIQUETAS`.** El diff compara
lo propuesto contra lo que devuelve `valoresActuales()`; si un campo está en `ETIQUETAS` (y por
tanto lo produce `calcularValores()`) pero **falta** en `valoresActuales()`, se lee como `null`,
nunca coincide con lo propuesto y **el plan lo marca como cambio en conflicto para siempre** —
aplicarlo no converge, porque la siguiente comparación vuelve a leer `null`. Pasó con
`tarifaNombre` y `cantidadComponente` (2026-08-18): se añadieron al snapshot y a `ETIQUETAS`
pero no a `valoresActuales()`, y el sincronizador proponía 42 filas / 82 campos en cada corrida
aunque la BD ya coincidía. Al tocar cualquiera de las tres listas (`ETIQUETAS`, `calcularValores()`,
`valoresActuales()`), cuadrar las otras dos.

#### Las clases de cambio y su casilla por defecto

El estado inicial de cada casilla **es** la política de seguridad:

| Cambio | Por defecto | Por qué |
|---|---|---|
| `crear` | ✅ marcado | no destruye nada |
| `actualizar` sin conflicto y fuera de OS | ✅ marcado | la cotización es la fuente |
| Campo **en conflicto** | ⬜ desmarcado | gana quien habló con el proveedor |
| Cualquier cambio en fila **con OS** | ⬜ desmarcado (nada premarcado) | altera lo que ya se pidió |
| `huerfano` | ⬜ desmarcado | borrar se confirma siempre a mano |
| `huerfano` en OS o con costo real | 🔒 **bloqueado**, sin casilla | rompería trazabilidad o borraría dinero |

Si «actualizar» viniera marcado en un conflicto, esto sería una trampa con aspecto de
asistente.

#### Aprobación por CAMPO, no por fila

Si la cotización movió la fecha y alguien ya había fijado la hora, aprobar «la fila» no
significa nada: ¿acepta la fecha y conserva su hora, o se lleva todo por delante? El body
de `aplicar` es un mapa `idDelCambio → campos`. **Lo que no aparece, no se aplica**: no
hay «aplicar todo» implícito.

Los identificadores internos viajan pegados al campo que los explica
(`expandirTecnicos()`): la moneda con el costo, la tarifa con el proveedor. Sin eso se
podría aprobar un costo nuevo dejando la moneda vieja.

#### La firma: por qué la aprobación no es decorativa

Entre ver el plan y aprobarlo pueden pasar minutos, y en ese hueco otro operador puede
cambiar una hora. `firmar()` hashea el estado leído —filas existentes **y** valores
calculados de la cotización— y `aplicar()` lo revalida. Si no coincide, **422**: *«la
operación cambió mientras revisabas»*. Sin esto se estarían aplicando decisiones tomadas
sobre una realidad que ya no existe.

#### Los identificadores internos no se listan, pero SÍ se comparan

`proveedorMaestroId`, `prestadorMaestroId` y `cotizacionTarifaId` son UUID: enseñar su
valor no le dice nada a nadie. Pero durante un tiempo se **descartaban** en
`compararCampos()`, y eso abría un punto ciego permanente: sustituir una tarifa por otra
del mismo importe, o corregir la divisa manteniendo la cifra, dejaba el plan diciendo
«sin cambios» mientras la fila seguía apuntando a la tarifa vieja **para siempre** — con
la agrupación de OS por moneda equivocada y sin que nada pudiera detectarlo nunca.

Ahora se comparan igual, y salen como línea **sólo cuando cambian sin que cambie su campo
visible** (`TECNICO_ACOMPANA_A`), con texto legible en vez del UUID. Si el campo visible
también cambió, viajan pegados a él en `expandirTecnicos()` y no se repiten.

`monedaCotizadaId` dejó de ser técnico: sus valores (`USD`, `PEN`) son legibles y sale
como línea propia. Sigue viajando con el costo, porque aceptar un importe nuevo dejando
la moneda vieja convierte 150 soles en 150 dólares sin que nadie lo note.

#### Un componente, una fila — también en la base

`UNIQUE (cotizacion_componente_id)`. La idempotencia del snapshot lo garantizaba con un
`findOneBy()`, que es una **comprobación y no una restricción**: dos `aplicar`
concurrentes con la misma firma pasan los dos y crean la fila los dos, porque entre leer
y escribir no hay lock. Con filas duplicadas se le pide y se le paga dos veces al
proveedor.

#### Lo que la reconciliación NUNCA toca

`estadoReservaProveedor`, `estadoOperacion`, `costoRealOperativo`, `montoVenta`, `monedaReal` y
`ordenServicio`. No salen de la cotización: son del operador. Esa lista corta es media
razón de ser del módulo.

#### Dónde vive cada regla

| Pieza | Responsabilidad |
|---|---|
| `BibliaSnapshotService::calcularValores()` | QUÉ dice la cotización hoy (escalares) |
| `BibliaSnapshotService::aplicarValores()` | cómo se vuelca sobre una fila, con `$soloCampos` |
| `BibliaReconciliacionService::planificar()` | qué cambia, de quién es y quién lo autoriza |
| `BibliaReconciliacionService::aplicar()` | ejecuta lo aprobado si la firma sigue viva |

El generador y el reconciliador comparten `calcularValores()` a propósito: si el segundo
reimplementara las reglas, propondría cambios que el primero nunca habría hecho.

### <a id="34"></a>3.4 Desde la consola

```bash
php bin/console operacion:resincronizar <uuid-cotizacion>
php bin/console operacion:resincronizar --todas [--dry-run] [--force]
```

Usa el mismo `BibliaReconciliacionService` que el panel (§3.5), así que la consola y la
pantalla no pueden proponer cosas distintas. Muestra el plan y **pregunta antes de
aplicar**.

| | Qué aplica |
|---|---|
| sin flags | sólo lo aprobado por defecto: crear, y actualizar lo que nadie tocó |
| `--force` | además los conflictos y los sobrantes. **Nunca lo bloqueado** |
| `--dry-run` | nada: imprime el plan y sale |

En consola no hay aprobación campo a campo — se aprueban los campos enteros del cambio.
Quien necesite ese detalle usa el panel.

⚠️ **El default de la pregunta depende del lote.** Crear y actualizar es reversible y no
pierde nada, así que va a «sí» por defecto y `--no-interaction` sirve para un cron. Si el
lote incluye **borrados**, el default es «no»: eso se teclea, aunque se haya pedido con
`--force`.

### Espejo PHP ↔ TypeScript de la referencia

| PHP | TypeScript | Regla |
|---|---|---|
| `OperacionServicio::isSoloReferencia()` | campo `soloReferencia` de `api.d.ts` | **no se reimplementa**: viaja calculado. Si cambias la condición, sólo se toca el PHP y se regenera `api.d.ts` (§8) |

### Campos financieros al nacer

`costoCotizado` = `tarifa.montoCosto`, o `'0.00'` en una fila de referencia (§3.3); `montoVenta` y
`costoRealOperativo` arrancan en `'0.00'`; `monedaCotizada` = `monedaReal` = moneda de la tarifa,
o `null` si no hay tarifa. El delta cotizado ↔ real es el margen operativo, y lo rellena el
operador desde la columna «Costo» del cuadro (§7.3): nada lo calcula automáticamente, porque
sólo quien pagó sabe cuánto se pagó.

---

## 3.bis Por qué La Biblia aparece vacía

Diagnóstico verificado en agosto de 2026, con el panel vacío en local **y en producción**. Las
causas, en orden de frecuencia:

**1. No hay ninguna cotización en `confirmado`.** Era el caso real: cero filas en
`operacion_servicio` porque no existía una sola cotización confirmada en toda la base. El
listener y el snapshot funcionaban; simplemente nunca se les llamó. Comprobación:

```bash
php bin/console doctrine:query:sql --force-fetch \
  "SELECT estado, COUNT(*) c FROM cotizacion_cotizacion GROUP BY estado"
```

Ojo con el guard del editor: `guardarCotizacion()` (`cotizacionEditorStore.ts`) **aborta el
guardado** si el estado está en `['enviado','confirmado','operado']` y el resumen financiero no
es `publicable` — y `publicable` exige **cero advertencias**, no sólo cero conflictos. Un aviso
informativo basta para que «Confirmado» no llegue a guardarse nunca.

**2. El rango de fechas.** Los servicios de un expediente caen semanas después de venderlo. El
panel arrancaba filtrando por un solo día (`hoy`) y salía vacío casi siempre; hoy arranca con
7 días. El preset «Hoy» sigue a un clic.

**3. Se confirmó y después se editó.** La generación se dispara en la **transición** a
`confirmado`, y sólo una vez. Añadir un día, corregir una hora o poner la tarifa que faltaba
después no llega nunca a Operaciones. Para eso está §3.4.

**4. Confirmar en el POST no genera nada.** El listener sólo mira
`getScheduledEntityUpdates()`. Una cotización creada directamente con `estado = confirmado`
—o clonada a un estado confirmado— no produce filas. `CloneCotizacionProcessor` pone
`PENDIENTE`, así que hoy no se da en la práctica; si algún día se da, el botón de §3.4 lo cubre.

---

## 4. Los tres estados y por qué son tres

| Enum | Campo | Responde a |
|---|---|---|
| `EstadoReservaProveedorEnum` | `OperacionServicio::$estadoReservaProveedor` | ¿El proveedor ya me confirmó? `sin-solicitar` → `solicitado` → `confirmado` → `reconfirmado`, más `pendiente-pago`. |
| `EstadoOperacionEnum` | `OperacionServicio::$estadoOperacion` | ¿El servicio ya ocurrió? `pendiente` → `en-proceso` → `completado` / `cancelado`. |
| `EstadoOrdenServicioEnum` | `OperacionOrdenServicio::$estadoOs` | ¿En qué punto está el papeleo? `borrador` → `emitida` → `confirmada` → `completada` / `cancelada`. |

### 4.1 «Reserva» aquí es al PROVEEDOR — y por eso el campo se renombró

El primero se llamaba `EstadoReservaProveedorEnum` sobre un campo `estadoReservaProveedor`, y era una trampa
tendida: en este sistema **«reserva» significa `PmsReserva`**, la del huésped. Esta otra es la
que le hago YO al proveedor cuando le compro el servicio. Quien leyera `estadoReservaProveedor =
confirmado` en una fila de La Biblia tenía todo el derecho a entender lo contrario de lo que
dice. Renombrado a `estadoReservaProveedor` en `Version20260812280000`; los valores no
cambiaron.

⚠️ **Al pasajero se le contesta con éste, nunca con el de la orden.** El agente conversacional
va a leer estos estados cuando alguien pregunte «¿ya está confirmado mi tour?»:

| Pregunta del pasajero | Estado que la responde |
|---|---|
| ¿Tengo la plaza? ¿Está confirmado? | `estadoReservaProveedor` |
| ¿Ya lo hice / ya pasó? | `estadoOperacion` |
| — (nada: es papeleo interno) | `estadoOs` de la orden |

Contestar «sí, confirmado» leyendo `estadoOs` no da ningún error: da una respuesta
tranquilizadora y falsa sobre una plaza que quizá el proveedor no ha confirmado. Y `totalOs` es
el **coste al proveedor**: no viaja jamás a un DTO de cliente — se recorta en la capa de datos,
por perfil, no confiando en que el prompt lo prohíba.

Son ortogonales a propósito: un servicio puede estar `confirmado` con el proveedor y aún
`pendiente` de ejecutarse.

A estos se suman dos campos **de sólo lectura** heredados de la cotización —
`estadoComponente` (`ComponenteEstadoEnum`) y `modoComponente` (`ComponenteModoEnum`) — que
describen cómo estaba el componente al confirmarse, no cómo va la operación.

### Asimetría del listener

**Ningún estado borra filas: sólo cambian `estadoOperacion`.** Una vez que se pidió al
proveedor, el rastro no se borra.

| Transición | Efecto |
|---|---|
| → `CONFIRMADO` | reactiva las canceladas (`→ pendiente`) **y** genera las que falten |
| → cualquier otro | `→ cancelado` |

🔥 **Salir de confirmado cancela, no deja en pendiente.** Antes des-confirmar dejaba las filas en
`pendiente`: con aspecto de activas, sin atenuar y dentro de los filtros habituales. Como
renegociar y confirmar la **versión siguiente** del mismo expediente es rutina, el cuadro
acababa mostrando los mismos días **dos veces** — y la reconciliación no lo arreglaba, porque
trabaja por cotización y nunca ve las filas de la otra versión. Riesgo de pedir y pagar dos veces
lo mismo.

🔥 **Confirmar reactiva.** Sin eso, cancelar por error una cotización y volver a confirmarla al
minuto dejaba sus filas en `cancelado` **para siempre**: la idempotencia impide regenerarlas y la
reconciliación no toca `estadoOperacion` porque es del operador.

⚠️ **`completado` es intocable.** Un servicio que se operó el martes se operó, y que el viernes
se archive la cotización no lo deshace. `propagarEstadoOperacion()` lo salta en ambos sentidos:
pisarlo borraría el registro de lo que de verdad ocurrió, que es justo lo que ese campo guarda.

### Espejos PHP ↔ TypeScript

| PHP | TypeScript | Regla |
|---|---|---|
| `EstadoReservaProveedorEnum` | `ESTADO_RESERVA_PROVEEDOR_CONFIG` | añadir un case obliga a añadir la entrada |
| `EstadoOperacionEnum` | `ESTADO_OPERACION_CONFIG` | ídem |
| `EstadoOrdenServicioEnum` | `ESTADO_OS_CONFIG` | ídem |
| `ComponenteTipoEnum` | `TIPO_COMPONENTE_CONFIG` | ídem; la **prioridad NO se replica** |
| `ComponenteModoEnum` | `MODO_COMPONENTE_CONFIG` | ídem |
| `ComponenteEstadoEnum` | `ESTADO_COMPONENTE_CONFIG` | ídem — sólo `activo`/`cancelado`. **No** es el estado de reserva: ver `docs/Cotizaciones.md` §3.b |

Los tres primeros están tipados contra `api.d.ts`, así que `vue-tsc` falla si falta una entrada —
pero sólo después de **regenerar `api.d.ts`** (§8). Los tres últimos están tipados como
`Record<string, …>` porque el origen es un `string` suelto: ahí no hay red, se comprueba a ojo.

`ComponenteTipoEnum::prioridad()` no se duplica en TS a propósito: viaja calculada desde el
backend en `prioridadOperativa`, que es la única fuente de verdad del orden de despacho.

---

## 5. Órdenes de Servicio y bitácora

```
N OperacionServicio ──(ordenServicio, ManyToOne)──► 1 OperacionOrdenServicio
                                                          │
                                                          └──► N OperacionMensaje (bitácora)
```

`operacionStore.crearOrdenServicio()` hace dos pasos: `POST` de la cabecera y luego un `PATCH` por
cada servicio para asignarle el IRI de la OS. **No es transaccional**: si falla a mitad, queda una
OS con sólo parte de los servicios asociados.

La vista sólo deja generar una OS si los servicios seleccionados comparten **expediente y
comprador**, ninguno pertenece ya a otra OS y **todos son comprables**
(`conflictoSeleccion` en `OperacionView.vue`).

⚠️ Esa comprobación vive en el navegador y por tanto **no es la garantía**. La garantía
está en `setOrdenServicio()`, que compara el expediente de la fila con el de
la cabecera de la OS. Sin ella, dos pestañas abiertas o cualquier consumidor de la API
distinto de la vista podían armar una orden con filas de dos expedientes: un documento 
que nadie puede firmar. (Las monedas mezcladas ya no bloquean, pues cada fila mantiene la suya).

**Comprable** (`OperacionServicio::esComprable()`) es más estrecho que «no es referencia»:
excluye además lo `cancelado` en la cotización y lo `reemplazado`. Los dos conservan tarifa, así
que pasaban todas las demás comprobaciones — la vista los atenúa, pero **atenuar no impide
marcar la casilla**, y el resultado era pedirle y pagarle a un proveedor un servicio que nadie
va a usar.

⚠️ La guarda es doble y las dos hacen falta: `setOrdenServicio()` vigila la **entrada**, y
`verificarCoherenciaConOrdenServicio()` (`prePersist`/`preUpdate`) vigila que una fila que **ya
está** en una OS no deje de ser comprable. Sin la segunda, aprobar en el panel un
`modoComponente → no_incluido`, o que la tarifa desapareciera del árbol, convertía la fila en
referencia **dentro** de una orden ya emitida: la OS acababa conteniendo un importe que no se
debe (hoy ese importe está en el ítem, ver §5.4). Una OS es una solicitud a un proveedor sobre un
expediente; mezclarlos produciría un documento que nadie puede firmar, y meterle una fila que
nadie compra (§3.3) produciría un importe que no se debe.

### <a id="54"></a>5.4 El dinero vive en los ÍTEMS, no en la cabecera (2026-08-17)

`OperacionOrdenServicio` tenía `totalOs` y `monedaOs` obligatorios, y de ahí colgaba una
regla: **una orden no podía mezclar monedas**, porque «deja un total que no suma». La guarda
estaba en tres sitios —el front, `setOrdenServicio()` y la coherencia—.

El problema no era mezclar: era que el importe estaba en el sitio equivocado. Un proveedor
cobra unos servicios en soles y otros en dólares, y partir eso en dos órdenes parte una gestión
que en la vida real es una sola.

| Dónde | Campo | Qué es |
|---|---|---|
| **Ítem** | `costoCotizado` + `monedaCotizada` | Lo que dijo el cotizador. **Referencial**, sólo lectura |
| **Ítem** | `costoRealOperativo` + `monedaReal` | Lo que se negoció. **Editable, moneda incluida** |
| Cabecera | `totalOs` + `monedaOs` | Apunte manual de conciliación. **Opcional**, no se pide al crear |

La moneda negociada es editable **a propósito**: se puede cotizar en dólares y cerrar en soles
con el mismo proveedor, y heredarla del cotizador obligaba a que coincidieran. Cambiarla no
toca el cotizado — son dos hechos distintos, y machacar el primero borraría la referencia con
la que se concilia.

⚠️ **La pantalla suma POR MONEDA y no convierte.** Es el criterio de la casa: al proveedor no
se le manda un total, así que no hay ninguna razón para inventar un tipo de cambio. El modal de
creación enseña el referencial desglosado por moneda y no tiene campo de total.

Lo que se retiró: el bloqueo por monedas distintas en `setOrdenServicio()` y en
`conflictoSeleccion`. Lo que **no** se retiró: expediente único, comprador único, nada de
referencias ni cancelados, y nada que ya esté en otra orden.

#### Dos ranuras, no una cadena: el componente y el itinerario (2026-08-27)

La ficha y la orden enseñan **dos nombres a la vez**, y son datos distintos:

```
GRANDE   nombreComponente    QUÉ hay que hacer   «Transporte Aeropuerto Cusco - Hotel Cusco»
al lado  descripcionServicio la VARIANTE          «Auto»  (tarifa: auto / van / bus)
pequeño  contextoServicio    DÓNDE encaja         «Transporte en Cusco» / «Full Day HUAYNA…»
```

⚠️ **El nombre grande es el OPERATIVO del maestro (`TravelComponente::$nombre`), no el público.**
Son tres nombres distintos y es fácil coger el que no es:

| | ejemplo | para qué |
|---|---|---|
| operativo (maestro) | «Transporte Aeropuerto Cusco - Hotel Cusco» | **el que va en grande**: con el que despachamos |
| público (`tituloSnapshot`) | «Transporte» | prosa de cliente, y **a veces genérico** |
| del segmento | «Transporte desde el Aeropuerto de Cusco al hotel en Cusco» | el itinerario, va en pequeño |

El público parece buen candidato porque en muchos componentes es descriptivo, pero en éste es
«Transporte» a secas — y con él la ficha decía «Transporte» sin más. La regla es el operativo.

#### ⚠️ El nombre viaja congelado, y hay que refrescarlo a mano (29/08/2026)

`resolverNombreComponente()` lee el snapshot de la COTIZACIÓN, no el maestro. La cadena entera
es de copias:

```
TravelComponente.nombreInterno            ← el catálogo, vivo
        ↓ (uuid, sí se reapunta)
CotizacionCotcomponente.nombreInternoSnapshot   ← CONGELADO al cotizar
        ↓
OperacionServicio.nombreComponente        ← copia el snapshot, NO el maestro
        ↓
OperacionOrdenServicioItem                ← congelado al emitir
```

Por eso, tras renombrar 25 componentes en el refactor de transporte, La Biblia seguía diciendo
«Transporte Cusco - Ollanta»: **no estaba desfasada respecto a su fuente**, estaba copiando
fielmente una cotización que sí lo estaba. Buscar el fallo en Operación habría sido buscar en el
sitio equivocado.

Se refresca con `app:cotizacion:refrescar-nombres-maestros` y después
`operacion:resincronizar --todas`, que sí trae `nombreComponente` entre sus campos reconciliables.

**Qué se refresca y qué no**, que es lo que hace segura la operación:

| | |
|---|---|
| `nombreInternoSnapshot` | **sí** — es operativo, y un nombre que ya no existe obliga a buscar a ciegas |
| `tituloSnapshot` | **no** — es prosa de cliente, se edita a mano por cotización, y refrescarlo destruiría esas ediciones y cambiaría lo que un cliente ya leyó |
| importes | **no** — el precio congelado es lo que se vendió |
| órdenes emitidas | **no** — un documento dice lo que dijo |

#### Quién va en grande: lo decide el TIPO (29/08/2026)

```
GRANDE   el segmento SI es transporte, y el componente en todo lo demás
debajo   el otro de los dos
al lado  la variante de tarifa
pequeño  el día del itinerario
```

`ComponenteTipoEnum::mandaElSegmento()` devuelve exactamente `nombraUnaRuta()` —**transporte, tren y vuelo**—, y no es una
preferencia de diseño: `travel_componente` **no tiene columnas de origen ni de destino** —las
tiene el segmento— así que un componente de transporte *no puede* decir a dónde va hoy. Desde el
refactor ni lo intenta: `Transporte Cusco ↔ Ollanta (ida o vuelta)` sirve a tres segmentos a
propósito.

En todo lo demás el componente nombra lo comprado y **la variante lo termina de distinguir**:

```
ticket    Ingreso a Catedral          + Adulto Extranjero
guiado    Guiado Machu Picchu         + Privado Circuito 3     ← el circuito va en la variante
pool      Pool Paracas y Huacachina   + Cultur (Base 1, 2 pax)
```

Ahí el segmento es prosa de **itinerario, escrita para el cliente**: «La Catedral: Encuentro e
Inmersión Colonial» no le dice al Arzobispado qué se le compró.

⚠️ **`tipoComponente` se congela en la línea de la orden.** Leerlo del maestro al pintar haría que
un documento emitido se leyera distinto el día que el catálogo cambiara de opinión. Nulo en las
emitidas antes de esa fecha → manda el componente, que es como se leían entonces.

##### El orden del día llega a las cuatro superficies (29/08/2026)

El `orden` manual del servicio no servía de nada si sólo lo veían el editor y la guía. Ahora la
misma cascada gobierna La Biblia, la orden, el mensaje y la impresión.

**En La Biblia**, `OperacionServicio::getOrdenItinerario()`:

```
antes    día × 1.000            + orden del segmento
ahora    día × 1.000.000  +  posición del servicio × 1.000  +  orden del segmento
```

⚠️ **Tenía el mismo defecto que la guía**: el orden del segmento es un número de ordenar DENTRO de
un servicio, y se usaba para comparar ENTRE servicios. Cada plantilla empieza por su segmento 1,
así que dos servicios del mismo día valían ambos `día×1000 + 1` y el cuadro los **intercalaba**.

La posición del servicio es su `orden` manual, o el orden narrativo de su tipo. Sigue **calculado
y no en columna**, por lo que ya decía su docblock: una copia abriría la puerta a que el cuadro y
la cotización se contradigan al reordenar.

**En la orden, el mensaje y el PDF**, `OperacionOrdenServicio::getItemsOrdenados()`:

```
1  la fecha
2  con hora antes que sin hora        ← ⚠️ esto estaba al revés
3  la hora
4  el itinerario congelado
```

⚠️ **El paso 2 era un fallo abierto.** Se comparaba `(string) getHora()`, y una hora nula es la
cadena vacía, que ordena **antes** que `04:00`. Al proveedor le encabezaba la jornada justo lo que
no tiene hora fijada. El cuadro y la guía ya lo tenían bien; la orden no.

`ordenItinerario` se **congela** en la línea al emitir —una orden se lee igual dentro de un año
aunque el itinerario se haya reordenado— y es nulo en las emitidas antes, que desempatan como
entonces.

##### El secundario es de La Biblia, no de la orden (29/08/2026)

Al proveedor le va **UNO** de los dos nombres, el que gane por tipo. El que pierde se queda en la
pantalla interna.

```
La Biblia   Transporte desde el Aeropuerto de Lima al hotel en Lima
              Transporte Aeropuerto Lima ↔ Miraflores (ida o vuelta)   ← el operador lo usa para buscar
              Auto a Miraflores Noche

la orden    Transporte desde el Aeropuerto de Lima al hotel en Lima · Auto a Miraflores Noche · Transporte en Lima
```

Al operador le sirve saber de qué componente del catálogo salió la fila — es como la busca. Al
proveedor **no le añade nada**, y desde la fusión por sentido es literalmente **menos preciso**:
debajo del encargo ponía la misma ruta sin el sentido.

##### ⚠️ La lista de órdenes era una CUARTA copia de la regla

`OperacionView.vue` reimplementaba la línea con `nombreComponente || descripcion`, ignorando el
tipo. Resultado: **la orden que se enviaba y la que se listaba decían cosas distintas** — en un
traslado la lista enseñaba el componente en grande y el mensaje el segmento.

Se arregló exponiendo los textos ya resueltos (`tituloParaProveedor`, `varianteParaProveedor`,
`diaParaProveedor`) en los grupos `operacion:read`, para que el front **pinte** en vez de decidir.
Cuatro superficies, una regla, un solo sitio donde equivocarse.

##### El peldaño 1, que estaba vacío en 859 de 863 tarifas (29/08/2026)

`nombreParaPrestador` es el texto con el que el proveedor reconoce lo que se le encarga, y era el
gran hueco: sin él la orden caía al peldaño 3 —el nombre interno de la tarifa— y le llegaba «Auto
a Miraflores Noche», que es **jerga nuestra**: una etiqueta para elegir en un desplegable, no para
que alguien reconozca un servicio.

Rellenado en 207 tarifas de rutas bidireccionales:

```
*Transporte desde el Aeropuerto de Lima al hotel en Lima*   ← el segmento: hacia dónde va HOY
Traslado Aeropuerto Lima ↔ Miraflores · Auto Noche          ← su tarifa, como él la cobra
Transporte en Lima
```

⚠️ **Bidireccional también para él, y no es una concesión: es cómo tarifa.** Cobra lo mismo por
llevar al aeropuerto que por traer de él. Repetirle la dirección aquí sería decirla dos veces
—ya está arriba, en grande— y quitarle la ruta entera le dejaría sólo «Auto Noche».

⚠️ **Sólo los componentes con `↔`.** Uno que aún nombra un sentido —«Transporte Ollanta - Cusco»—
no se toca: ahí la ruta con dirección sigue siendo correcta, y meterle un «↔» sería afirmar algo
que su tarifa no dice.

Y `RefrescarNombresMaestrosCommand` propaga ahora **los dos** nombres. Refrescar sólo el interno
sería actualizar justo el que el proveedor no ve.

##### ⚠️ `descripcion` no es «la variante de tarifa»

Es **el nombre para el proveedor**, con su propia cascada en `BibliaSnapshotService::resolverDescripcion()`:

```
0  servicio del prestador          «Habitación suite superior»
1  nombreParaProveedorSnapshot     cómo llama ÉL a ese servicio
2  nombre interno del componente   sólo los manuales lo tienen
3  nombre interno de la tarifa     «Auto a Miraflores Noche»
4  título público en español
```

Por eso en un traslado sale «Auto a Miraflores Noche»: nadie llenó los tres primeros peldaños y
cayó al nombre de la tarifa. **No es un campo de relleno: es el que está escrito para que el
proveedor lo reconozca**, y llenar el peldaño 1 mejora la orden más que cualquier regla de display.

##### Una sola regla para las cuatro ranuras: callarse si repites

`OperacionOrdenServicioItem::calladoSiRepite()`. Cada texto se calla si repite **alguno de los que
ya salieron antes** en la línea: el secundario mira al título, la variante al título y al
secundario, el día a los tres.

Sustituye a cuatro comparaciones sueltas que no coincidían entre sí ni con su espejo en `util`. La
peor: la variante se callaba comparando contra el **componente**, que desde que manda el segmento
ya no es lo que hay arriba. Fallaba **en los dos sentidos y en superficies distintas**:

| Caso | PHP (WhatsApp, PDF, web) | `util` (La Biblia) |
|---|---|---|
| variante = segmento | **imprimía el mismo texto dos veces** | se callaba ✓ |
| variante = componente | se callaba ✓ | **lo enseñaba junto al secundario, que era ese mismo texto** |

Es lo que pasa cuando dos espejos comparan contra cosas **parecidas pero no iguales**. Una regla
acumulativa no tiene ese problema: no hay contra qué equivocarse.

⚠️ **El día también subió a la entidad** (`getDiaParaProveedor()`). Antes el documento y el twig
repetían la comparación cada uno por su cuenta y sólo miraban al título, así que un día igual al
componente salía duplicado en el PDF. Una regla en un sitio, tres superficies que la consumen.

⚠️ **Y el último peldaño del título ya no puede quedar vacío.** Caía a `descripcion`, que admite
cadena vacía: con todo en blanco la línea salía como un `**` sin nada dentro. Ahora cae al tipo,
genérico pero nunca mentiroso — el mismo criterio que ya tenía el espejo de `util`.

Verificado sobre las 14 líneas emitidas reales: ninguna repite un texto.

##### Y una tercera pregunta: ¿el segmento pinta algo?

`ComponenteTipoEnum::ocultaElSegmento()` — **true en las excursiones** (`pool` y `privada`). Su
componente es el ANCLA de una plantilla de varios segmentos: se cuelga del primero porque algo
tiene que sostener la tarifa, pero cubre el día entero.

```
Pool Paracas y Huacachina
  Traslado Costero de Lima a Paracas    ← parecía que sólo se contrató el traslado
  Full Day Paracas y Huacachina
```

El proveedor lleva el día completo —traslado, almuerzo, Huacachina, retorno— y esa segunda línea
sugería que no. **No es que estorbara: mentía por omisión**, que es peor que sobrar.

⚠️ Va en un método aparte de `mandaElSegmento()` a propósito. Un `pool` responde «no» a las dos,
pero por motivos que no se parecen: **no manda** porque el componente ya nombra lo comprado, y
**no se muestra** porque el segmento es sólo un capítulo de lo comprado. Fundirlas habría
escondido esa diferencia.

##### Estas reglas NO aplican a la guía del huésped

Y no por decisión, sino porque `pax` **recibe otros campos**: ningún `nombreInternoSnapshot`
lleva el grupo `pax_cotizacion:read` —ni el del segmento, ni el del componente, ni el del
servicio—. El huésped ve `tituloSnapshot`, traducido.

Allí la respuesta además es la contraria: el segmento **es el relato del día** y va en grande
siempre. Al huésped sí le interesa leer «Traslado Costero de Lima a Paracas» como el primer
momento de su jornada; al proveedor no, porque le encoge lo que se le contrató. **Son dos
audiencias con dos preguntas distintas, y por eso comparten datos pero no reglas.**

##### Dos caminos que se descartaron, y por qué

**Contar a cuántos segmentos sirve cada componente.** Da la misma respuesta con los datos de hoy,
pero es una **observación, no una declaración**: en cuanto alguien enganche un segundo segmento a
un componente, dos filas idénticas empezarían a pintarse distinto sin que nada visible haya
cambiado. El tipo se declara al crear el componente y no se mueve solo.

**Poner el segmento arriba SIEMPRE.** Se simuló sobre las **47 filas reales** antes de decidir, y
habría encabezado un `Pool Valle Sagrado` y un `Boleto Turístico Parcial` con «Recojo e inicio de
excursión (Servicio Grupal)» —el ancla, idéntica en todas las excursiones y que ni siquiera habla
de esa fila—. Un retroceso en 21 de 47.

La simulación es el método, no una anécdota: **una regla de display se prueba rindiéndola sobre
los datos reales**, porque el caso que la rompe nunca es el que se tenía en la cabeza al
escribirla.

#### La secuencia para propagar un renombrado, y por qué ese orden

```
1. app:cotizacion:refrescar-nombres-maestros     la cotización recoge el nombre nuevo
2. operacion:resincronizar --todas --force       La Biblia lo copia de la cotización
3. app:operacion:refrescar-ordenes-emitidas      las órdenes lo copian de La Biblia
```

Al revés no funciona: cada paso **copia del anterior**, así que empezar por el final propaga los
nombres viejos.

⚠️ **El `--force` del paso 2 no es un atajo: es un falso positivo sistemático.** La
reconciliación decide «¿quién se movió?» comparando contra `OperacionServicio::$snapshotOrigen`,
y `nombreComponente` **no existía** cuando se escribieron esas filas — 46 de 47 lo tienen ausente.
Sin poder probar que lo escribió ella, asume conservadoramente que lo editó una persona y pide
aprobación. Se verificó que no era cierto antes de forzar: **cero filas** en las que
`descripcionServicio` difiriera de lo que el snapshot escribió.

**Compruébalo igual la próxima vez.** El día que alguien SÍ haya editado a mano, `--force` se lo
lleva por delante y el aviso habrá sido correcto.

⚠️⚠️ **El paso 3 reescribe documentos emitidos, y normalmente NO se hace.** Fue legítimo el
29/08/2026 por una condición concreta: **ninguna de esas órdenes se había enviado**. Estaban
emitidas en el sistema pero no habían salido a ningún proveedor, así que no había nadie con una
versión distinta en la mano. Esa condición no se cumple sola: si una sola orden salió, lo
correcto es **anular y reemitir**, no reescribir.

Un vacío en La Biblia **no borra** lo que la orden ya dice: perder texto por un recálculo
incompleto es peor que quedarse con un nombre viejo.

#### Tres ranuras: el segmento entra en la orden (2026-08-29)

Hasta aquí eran dos, y **al proveedor sólo le llegaban dos**. `nombreSegmento` se calculaba en
`BibliaSnapshotService::calcularValores()` y se congelaba en `OperacionServicio`, pero
`OperacionOrdenEmision::emitir()` **no lo copiaba a la línea**: se quedaba en la pantalla interna.

```
GRANDE   nombreComponente   QUÉ hay que hacer   «Transporte Cusco - Ollanta»
debajo   nombreSegmento     el MOMENTO          «Traslado a la estación de Ollantaytambo»   ← nuevo
al lado  descripcion        la VARIANTE         «Auto»
pequeño  contextoServicio   el DÍA              «Full Day MAPI: CUZ OLLA MAPI OLLA CUZ»
```

**Por qué importa más de lo que parece.** `travel_componente` **no tiene columnas de origen ni de
destino**: quien las guarda es el segmento (`inicioPunto` / `finPunto`, y a veces como *modo* —
«acaba en el alojamiento del pasajero», que se resuelve por cotización). Todo lo que el nombre
del componente dice de la ruta es **prosa duplicada** de un dato que ya vive en otro sitio.

Mientras el segmento no viajaba con la orden, esa prosa era obligatoria: era lo único que le
decía al proveedor a dónde ir. De ahí salió un componente por destino, cada uno con su juego de
tarifas repetido — 94 componentes de transporte y 336 tarifas para 39 líneas de cotización
reales. Y la duplicación divergía en silencio: `Cusco - Ollanta` y `Ollanta - Cusco` tenían el
mismo «Auto» a 150, uno con capacidad 3 y otro con 4.

Con el segmento en la línea, el componente puede volver a ser lo que es —el vehículo, el
proveedor y el precio— y dejar la ruta donde ya estaba.

**Símbolos:** `OperacionOrdenServicioItem::$nombreSegmento` y `getMomentoParaProveedor()` (se
calla si repite el encargo o la variante, misma guarda que `getVarianteParaProveedor()`);
`OperacionOrdenEmision::emitir()`; `OperacionOrdenDocumento::linea()`;
`templates/operacion/orden_publica.html.twig`. Espejo en el front: `momentoDeFila()` y
`diaDeFila()` en `util/src/views/Operacion/OperacionView.vue` — **antes eran un solo hueco con
`segmento || contextoServicio`**, y fundidos escondían justo el dato que distingue tres segmentos
que comparten componente. Si cambia la regla, se tocan los dos lados.

⚠️ **Las órdenes ya emitidas se rellenaron a mano**, con
`app:operacion:rellenar-nombre-segmento`. Es una excepción con fecha a la regla de que un
documento emitido no se reescribe: el campo no existía y lo añadido **no contradice** lo enviado,
sólo lo completa. El comando escribe únicamente donde está a nulo —nunca pisa un valor— y deja
como huérfanas las líneas cuya fila de La Biblia ya no existe.

⚠️ **Ninguno es respaldo del otro.** Que el itinerario pudiera subir a la ranura grande cuando
faltaba el componente es el fallo entero: un traslado de Ollantaytambo a Cusco se anunciaba como
«Full Day HUAYNA: MAPI OLLA CUZ», y Gabriel Aime —que sólo hace ese tramo— aparecía encargado de
la excursión completa. **No deja un hueco: deja otro nombre**, y se lee perfectamente plausible.
La única pista era que salía duplicado, arriba y abajo, en la misma ficha.

El último recurso del título es el **tipo** (`Transporte`, `Guiado`): genérico, pero nunca miente.

##### Por qué está denormalizado

El nombre vivía **sólo en el catálogo maestro** y lo resolvía el navegador en vivo, por
`componenteMaestroId`, en el mismo lote que las etiquetas de lugar. Ese lote tiene un `catch` que
lo vacía entero, con este razonamiento escrito al lado: *«los badges son decoración»*. Y lo son —
pero el nombre del componente viajaba de polizón entre ellos, y no es decoración: es la identidad
de la fila. Cualquier fallo de esa petición borraba el nombre de todo el cuadro.

Peor en la Orden: el snapshot **no llevaba el nombre en absoluto**, así que el documento que se le
manda al proveedor sólo tenía la variante de tarifa. Le llegaron órdenes que decían «Auto» y
«Hotel 4 estrellas por grupo» como encargo completo.

Ahora se congela en `OperacionServicio::$nombreComponente`, y el orden de
`BibliaSnapshotService::resolverNombreComponente()` es:

```
1. nombreInternoSnapshot   el operativo: copiado del maestro al añadirlo, o escrito a mano
2. tituloSnapshot (es)     el título público, último recurso
```

⚠️ **El maestro ya no se consulta aquí.** Desde el 27/08/2026 el operativo se copia al snapshot al
añadir el componente —como ya hacían servicio y tarifa—, así que la ruta del nombre es una sola:
maestro → snapshot → La Biblia → Orden. La deriva del catálogo la denuncia la reconciliación en vez
de aplicarse a escondidas. El test lo fija con un EntityManager que estalla si alguien lo usa.

⚠️ **Vaciar el campo en el editor lo DEVUELVE al del catálogo, no lo deja sin nombre.** Como el
resolutor ya no consulta el maestro, un campo vacío caería al título público —«Transporte» a
secas—; por eso el editor repone el del catálogo al salir del campo
(`reponerNombreDelCatalogoSiQuedoVacio`). Sin eso, la pantalla prometía una herencia que el backend
había dejado de hacer.

⚠️ **Pero los dos campos «snapshot» no son la misma cosa.** `nombreInternoSnapshot` lo **escribe
una persona**: es una decisión, y por eso gana. `tituloSnapshot` es una **copia del maestro** hecha
el día que se cotizó: no es decisión de nadie, es una foto que envejece. Si ganara, un componente
cuyo maestro se renombró enseñaría el nombre viejo para siempre — el caso del vuelo, cuya copia
dice «Ticket aereo» mientras el maestro ya dice «Vuelo Lima Cusco». Por eso la copia va la última y se copia a la línea al emitir. El maestro queda como
refuerzo para las filas anteriores al campo, y la deriva la denuncia la reconciliación, que ya lo
tiene en `ETIQUETAS`.

##### ⚠️ Los dos nombres SIEMPRE, y por qué no hay un flag

Algunos componentes de catálogo se llaman en genérico a propósito: **«Ticket aereo»**, **«Contacto
con el cliente»**. Son plantillas reutilizables, y lo que dice *cuál* es esta vez lo lleva el
segmento: «Vuelo desde la ciudad de Lima a la ciudad de Cusco». Con el itinerario al pie y en gris,
la ficha del ticket aéreo no decía qué vuelo era en ninguna parte legible. Por eso el itinerario
subió pegado al título y dejó de ir atenuado.

**Alternativa descartada: un flag en el maestro** que declarase el componente como «de nombre
genérico» y dejara mandar al nombre de la plantilla en la ranura grande. Se descartó el 27/08/2026,
y el motivo es del modelo de datos, no de gusto:

> Se puede crear un segmento llamado «Vuelo Cusco a Lima» —es un tramo del itinerario—, pero **no
> se puede crear un segmento «Walking Sticks en Vinicunca»**: los bastones son un extra colgado de
> una excursión, no una etapa del viaje.

O sea: **no existe un campo único capaz de cargar el «qué» y el «dónde» para todos los
componentes.** En el vuelo el segmento nombra la fila; en los bastones el segmento es la excursión
a la que van pegados y el componente sigue siendo el que dice qué son. Sustituir uno por otro
funciona en el primer caso y pierde información en el segundo — y el segundo no se nota, que es lo
caro.

De ahí la regla, que es la misma de todo este apartado y ahora por una razón más: **se ven los dos,
siempre.** Uno grande y otro pequeño, sin desempatar. Lo único que se calla es la repetición
literal (`itinerarioDeFila()` en `OperacionView.vue`), que es el caso de los traslados sueltos,
donde el día se llama igual que el servicio.

⚠️ Y de paso: **«sin lugar» tampoco vale como señal de «nombre genérico».** De 226 componentes hay
10 sin lugar, y entre ellos están «Ticket aereo» (genérico) pero también «Box Lunch» y «Walking
Sticks», que se nombran perfectamente solos. Al revés, «Transporte Aeropuerto Cusco - Hotel Cusco»
sí tiene lugar y es específico.

##### Previsualizar no falla por lo que impide mandar (27/08/2026)

`OperacionOrdenEnvio::previsualizar()` abría el hilo del proveedor **sólo para saber los canales**,
y abrirlo exige teléfono o correo. Con un proveedor sin contacto —Sonesta Miraflores— eso reventaba
y se llevaba por delante el documento entero: donde debía estar el texto salía **«Internal Server
Error»**, y ni el motivo ni los botones apagados que el panel ya sabe pintar.

Ahora se degrada: el documento se enseña completo, todos los canales apagados con motivo
`sin_datos_o_vetado`, y el porqué redactado en `motivoSinCanal` —en ámbar, no en rojo: no es un
error, es algo que falta y se arregla en la ficha del proveedor—.

`enviar()` sigue negándose, que es donde corresponde.

⚠️ **La regla, que vale más allá de este caso: mirar no puede romperse por algo que sólo impide
actuar.** Una previsualización que falla deja al operador sin la información con la que arreglarlo.

⚠️ Y el 500 de origen: `AperturaDeHilo` lanza `RuntimeException`, que no está en el mapa de
`api_platform.yaml`. Se traduce a `DomainException` en la frontera —ya mapeada a 422— y **no** se
mapea `RuntimeException` en la configuración: es la excepción genérica de PHP, la lanza medio
mundo, y convertirla en «error del usuario» escondería como 422 fallos que sí son nuestros.

#### Qué se le contrata exactamente (27/08/2026)

La línea de la orden lleva ahora un cuarto dato, después de la variante:

```
Alojamiento en Cusco  ·  Hotel 4 estrellas por grupo  ·  Habitación premium
   ↑ componente             ↑ variante de tarifa          ↑ servicio del prestador
```

Es el dato con el que el hotelero busca la reserva. Se calla cuando repetiría lo ya dicho: si el
componente tiene servicio de prestador, `resolverDescripcion()` ya lo usa como descripción, así que
variante y servicio coinciden.

⚠️ **El nombre venía vacío aunque la habitación estuviera asignada.** `prestadorServicioMaestroId`
estaba puesto y `prestadorServicioNombreSnapshot` no, y la Orden lee el **snapshot** —mientras que
`pax` resuelve **en vivo** contra el catálogo—. Por eso el huésped veía la habitación y el
proveedor no. Corregido con `Version20260828040000`, que rellena la cadena entera: cotización → La
Biblia → líneas ya congeladas, sólo donde está vacío.

##### Las tres superficies leen lo mismo

`OperacionOrdenServicioItem::getTituloParaProveedor()` y `getVarianteParaProveedor()` las usan la
página pública, el PDF y el texto de WhatsApp. Una sola fuente: es lo que evita arreglar dos y
dejar la tercera diciendo «Auto». La variante se calla si repetiría el título —en los componentes
manuales suelen coincidir—, y el itinerario también.

##### `resolverDescripcion()` NO se tocó

Sigue resolviendo **cómo llama el proveedor a lo que le compras**, y su prioridad 1 —el
`nombreParaProveedor` de la tarifa— es correcta para lo que hace. El fallo no era ésa: era pedirle
que respondiera además a *«qué es esto»*, que es otra pregunta. En los componentes de catálogo ese
campo trae la variante («Auto», «Adulto extranjero», «Extranjero») porque nombra la línea de
precio, no el servicio.

Backfill: `app:operacion:backfill-nombre-componente` (idempotente, con `--dry-run` y
`--rehacer`). **Calcula con el mismo resolutor, no con SQL propio**: la primera versión
repetía la regla en SQL y se equivocó de nombre — cogía el público. Una regla escrita dos
veces se arregla una vez y se queda mal en la otra.

#### La suma se calcula en el servidor, no en la pantalla

`OperacionOrdenServicio::getTotalesPorMoneda()` devuelve una línea por moneda con dos
columnas: `cotizado` (referencial) y `real` (negociado). Vive en el backend porque en el
listado los ítems viajan como IRI —la pantalla no tiene los importes— y engordarlo con cada
servicio entero sería pagar el payload de todos para pintar dos números.

Dos detalles que parecen caprichos y no lo son:

- **Un importe negociado se acumula bajo SU moneda**, que puede no ser la del cotizado: se
  cotiza en dólares y se cierra en soles. Sumarlo bajo la del cotizador lo pondría en la
  columna equivocada.
- **Mientras nadie negocie, `real` vale lo cotizado.** Un cero ahí se leería como «pactado en
  cero», que es lo contrario de «todavía sin pactar».
- **Sin filas vivas, suma las líneas congeladas.** Una orden anulada soltó sus filas (§5.4.a),
  así que el bucle normal no recorre nada: sin este respaldo diría «PEN 0.00» de algo que sí
  costó dinero. La condición mira `operacionServicios->isEmpty()`, **no si el acumulado quedó
  vacío**: los pagos también escriben en ese array, y con `$acumulado === []` bastaba **un
  adelanto abonado** para que el respaldo no entrara — la orden volvía al 0.00 y además
  enseñaba un saldo negativo, «te deben 50», porque restaba el pago de una nada. Comprobado:
  sin pago, 84.00; con un pago de 50, 0.00.

⚠️ **En una orden cancelada los importes se pintan TACHADOS**, no en cero ni ocultos. El dinero
que se pactó forma parte de lo que pasó —es lo que se le dijo al proveedor antes de anular— y
esconderlo deja el histórico contando una versión que no ocurrió. Es la misma regla de «no se
borra: se marca» aplicada a los números.

⚠️ **No hay ningún medio de envío de la orden.** `OperacionMensaje` es una **bitácora**: `tipo`,
`cuerpoHtml` y quién lo escribió. Se anota lo que se le dijo al proveedor; no manda correo ni
WhatsApp. Si algún día se automatiza, el contacto sale del maestro (§3.8), no de la fila.

#### 5.4.b Editar una orden (2026-08-17)

Una orden se creaba y ya no se podía tocar: ni corregir el número, ni cambiar el
destinatario, ni moverla de estado. Lo único a mano era la bitácora, que no edita nada.

El panel (`abrirEdicion()` en `OperacionView`) edita por **PATCH**, no PUT: se mandan sólo los
campos tocados, porque con PUT un campo ausente se lee como «ponlo a null».

| Se edita | No se edita |
|---|---|
| `numeroOs`, destinatario (`compradorMaestroId` + `compradorNombre`), `estadoOs` | El importe |

El importe no está en la cabecera a propósito —vive en cada ítem con su moneda—, pero **sí se
edita en el detalle**: al desplegar la orden, cada servicio trae su importe y su moneda
negociados en campos editables. Antes había que volver a La Biblia, encontrar la fila y
tocarla allí, que es ir y venir entre dos pantallas justo cuando estás cerrando el precio con
el proveedor y tienes la orden delante.

Lo que se edita ahí es `costoRealOperativo` y `monedaReal`, que son **campos del operador**: la
reconciliación no los pisa jamás. El cotizado queda debajo como referencia y no se toca — es
con lo que se concilia.

Al guardar se recargan dos cosas: el detalle y el listado. `totalesPorMoneda` lo calcula el
servidor, así que sin recargar el listado la cabecera se quedaría con la suma vieja.

#### Los servicios vienen EMBEBIDOS, no se piden aparte

La primera versión los pedía con `?ordenServicio=<iri>`. El endpoint devolvía **200 y una
colección vacía**, así que el detalle decía «0 servicio(s)» con tres dentro — y con el filtro
declarado en el `SearchFilter` y el uuid correcto en la petición, verificado en el access log.

En vez de perseguir el filtro se llevaron los ocho campos que el detalle necesita al grupo
`operacion:read`, que es el que usa la orden al serializar su colección. Sale mejor por tres
lados: una petición menos, ninguna dependencia de un filtro, y el detalle abre instantáneo
porque el dato ya está en memoria.

El payload aguanta: ~8 campos por servicio, y el listado de órdenes es corto por naturaleza
—una orden por proveedor y expediente—. No es el caso de La Biblia, que sí puede traer cientos
de filas.

⚠️ Ojo al añadir campos a `operacion:read` en `OperacionServicio`: ese grupo ya no es sólo «lo
que se lista», es **lo que viaja dentro de cada orden**. `ordenServicio` NO está ahí a
propósito — metería un ciclo servicio → orden → servicios.

Notas:

- `numeroOs` es `unique` y **no tiene generador**: hoy lo propone la vista
  (`OS-YYYYMMDD-NNN`) y lo confirma el operador. Si choca, el POST falla.
- 🔥 **Borrar una OS desasocia sus servicios; NO los borra.** Y durante un tiempo hizo lo
  contrario: la colección tenía `cascade: ['remove']` + `orphanRemoval: true`, así que borrar
  una OS equivocada para rehacerla se llevaba por delante las filas del cuadro con la hora
  pactada por teléfono, el prestador y el costo real. Una OS es un documento de compra que
  **agrupa** filas; las filas existen antes y después de ella. Hoy la colección no cascadea
  nada y el `onDelete: 'SET NULL'` del lado propietario es el único efecto.
- `OperacionMensaje` guarda `cuerpoHtml` ya renderizado. Es bitácora inmutable para resolver
  disputas con el proveedor, no un borrador editable.

### Con qué nombre se le pide (2026-08-16)

`BibliaSnapshotService::resolverDescripcion()` describía el servicio con **tu código
interno** («Pool City Lima CT002 (Base 1-4)»). La OS es un documento que se le manda al
proveedor, así que ahora prefiere `nombreParaProveedor` («Del Origen Al Presente de Lima»)
y sólo baja al nombre interno si falta.

El campo existía y estaba **escrito pero sin conectar**: se llenaba en el editor, viajaba al
snapshot y no lo leía nadie. Y se autorrellenaba con el nombre interno, lo que lo dejaba al
100% e indistinguible; ese relleno se quitó, así que vacío ahora significa «el proveedor la
llama igual que nosotros».

### A quién va dirigida: el COMPRADOR (2026-08-16)

🔥 **`proveedorNombreManual` era el comprador con el nombre equivocado.** Se documentaba como
«a quién le mando la solicitud y le pago», que es literalmente la definición del comprador.
Y que fuera **manual** era la prueba: existía un campo a mano porque el dato automático —el
proveedor de la tarifa— respondía a otra pregunta. `Version20260816220000` lo renombra en
`operacion_servicio` y `operacion_orden_servicio`, **conservando el valor**: lo que había
escrito ya era el comprador.

Consecuencia práctica: **la OS se agrupa por comprador, no por proveedor.** Dos servicios de
proveedores distintos que compra Futurismo caben ahora en la misma orden; antes se partían
en dos sin motivo.

La OS se dirigía a quien pone el precio. Suele valer porque le
compras directo, pero no siempre: le encargas a **Futurismo** que compre las entradas a
Paracas, o que contrate el **Hotel Estelar** porque consigue mejor precio. Ahí el prestador
es el hotel y el comprador es Futurismo — y eso no tenía dónde escribirse.

`OperacionServicio::$compradorMaestroId` / `$compradorNombre` congelan a quién se le
encargó, propagados por `BibliaSnapshotService` desde
`CotizacionCotcomponente::resolverComprador()`. **Siempre es un `Proveedor`**, también
cuando el comprador es una parte interna de la empresa: ahí también está modelada como
proveedor, así que no hay un segundo catálogo que consultar.

⚠️ **Mientras el campo esté vacío, la cascada cae al proveedor y la OS se dirige a quien se
dirigía siempre.** El cambio no altera ninguna fila existente: hay que encargar la compra
explícitamente para que cambie el destinatario.

Por qué siempre es un `Proveedor` —y por qué se descartó modelar personas o un soft-link
polimórfico— está en `docs/Cotizaciones.md` §6.c.

---

## 5.5 Pagos a cuenta al proveedor (2026-08-17)

`OperacionPago`: los abonos que se le van haciendo al proveedor por una Orden de Servicio.

| | |
|---|---|
| Campos | monto, moneda, fecha, **medio de pago**, observaciones (`notas`), y quién lo registró (nombre, sellado en `prePersist`) |
| Saldo | **NO se guarda**: se calcula `negociado − Σ pagos` por moneda en `getTotalesPorMoneda()` |
| Moneda | la del pago; no se convierte, así que cada pago resta del saldo de SU moneda |
| API | GetCollection (filtrable por orden) + Post + Delete. **Sin PUT/PATCH**: un pago mal metido se borra y se rehace; editar el monto invita a cuadrar caja cambiando la historia |

### El medio de pago (21/08/2026)

`OperacionMedioPago`: efectivo, transferencia bancaria, depósito en cuenta, Plin/Yape, tarjeta,
otro. La etiqueta y el icono viven **sólo en PHP** y el panel los pide a
`OperacionEnumAjaxController` (`GET /tipo/user/enum/operacion/medios-pago`), en vez de duplicar
el diccionario en TypeScript — donde se desincroniza en silencio y el selector se queda corto sin
que nadie eche de menos la opción que no sabía que existía.

⚠️ **No se reutiliza `PmsMedioPago`.** Aquél contesta la pregunta contraria —cómo nos pagó el
huésped a NOSOTROS— y arrastra cosas que aquí no significan nada: el recargo del 5,5 % de la
tarjeta existe porque se lo cobramos al cliente, y `seCobraEnMano()` decide si preguntar por el
cobrador, que pagando a un proveedor no aplica. Al revés, aquí hace falta `deposito`, que como
forma de cobrar no existe. Importarlo habría metido conocimiento del PMS en Operación por
ahorrarse seis líneas, y el día que el alojamiento añadiera un medio aparecería en este selector
sin que nadie lo pidiera.

⚠️ **La columna es `NOT NULL`, y eso hubo que comprobarlo.** Un campo así se añade normalmente
nullable para no reprobar a las filas viejas; aquí no hizo falta porque `operacion_pago` estaba
**vacía** (3 órdenes, 0 pagos, medido en producción antes de escribir la migración). Sin filas
que respetar, un `NULL` sólo habría arrastrado por toda la aplicación una rama de «medio no
consta» que nunca se daría.

### ⚠️⚠️ Registrar un pago NUNCA funcionó, y no se notó (21/08/2026)

`OperacionPago` nació el 17/08 **sin constructor y sin `#[ORM\HasLifecycleCallbacks]`**. Los dos
traits que usa necesitan ese gancho:

| Falta | Qué pasa |
|---|---|
| `__construct()` con `initializeId()` | `IdTrait` declara la clave con `GeneratedValue(strategy: 'NONE')` — el UUID lo pone la aplicación. Sin él, `persist()` lanza `EntityMissingAssignedId` |
| `#[ORM\HasLifecycleCallbacks]` | El `#[PrePersist]` de `TimestampTrait` no corre y el INSERT muere con «Column 'created_at' cannot be null» |

Encadenados: al arreglar el primero salió el segundo.

**Por qué pasó desapercibido cuatro días.** Porque nada lo dice: PHPStan está limpio,
`lint:container` está limpio, `schema:validate` está limpio y los tests unitarios no llaman a
`persist()`. En el panel el error se lo comía `crearPago()`, que devolvía un booleano y pintaba
«No se pudo registrar el pago» — un mensaje que suena a problema de red, no a que la entidad no
se pueda guardar. Y la tabla vacía no llamaba la atención: una función nueva sin uso todavía.

**Qué lo cazó:** ejecutar un `flush()` de verdad contra datos reales
(`var/probar-pago-orden-servicio.php`). Es exactamente lo que avisa `CLAUDE.md` — lo que no
cubren los tests unitarios se verifica con datos reales, en transacción con `rollback`.

⚠️ **Al añadir una entidad que use `IdTrait` o `TimestampTrait`, comprueba las dos cosas.** Un
barrido del 21/08 encontró otras tres sin constructor: `DomoticaSuscripcion`, `DomoticaLectura` y
`DomoticaDispositivo` (ver `docs/Domotica.md`).

### ⚠️ La moneda se comprueba en el BACKEND, no sólo en el selector

`OperacionPago::validarMonedaDeLaOrden()`. La regla estaba **sólo en el panel** —el `<select>` se
armaba con las monedas de la orden— y una regla que sólo vive en el front no es una regla:
cualquier POST directo metía un abono en una divisa ajena, y como el saldo se agrupa por moneda,
ese pago aparecía como una fila nueva con `pagado` y sin `real`. O sea **dinero declarado como
salido contra una deuda que no existe**, sin un solo error.

Las monedas admitidas salen de `OperacionOrdenServicio::monedasDeLosServicios()` —lo cotizado, lo
negociado y `monedaOs` si está puesta—, **no de `getTotalesPorMoneda()`**. Aquél incluye las
monedas de los pagos ya hechos, para que ninguno se esconda; validar contra él haría que el
primer abono equivocado legitimara al segundo.

Si la orden todavía no tiene ninguna moneda —costos sin cargar— se deja pasar: negarlo bloquearía
el adelanto de una orden recién emitida, que es justo cuando más se adelanta.

Auditado antes de instalarla: de los pagos que había en producción, **ninguno** habría sido
rechazado.

### `saldada`: un hecho sobre el dinero, no un estado de la orden

`OperacionOrdenServicio::isSaldada()` — derivado, no persistido: `true` cuando no queda saldo en
**ninguna** moneda.

⚠️ **No es un caso de `EstadoOrdenServicioEnum`, y no por pereza.** Ese enum contesta *en qué
punto está el papeleo de la orden de compra* —borrador, emitida, confirmada, completada,
cancelada—. Meter «pagada» ahí obligaría a que una orden CONFIRMADA dejara de estarlo al
pagarla: se perdería información real para ganar un dato que ya se puede calcular. Son dos ejes
independientes —una orden puede estar completada y sin pagar, o pagada por adelantado y aún sin
confirmar—, y este módulo ya documenta que sus tres estados contestan preguntas distintas (ver
`EstadoReservaProveedorEnum`).

Se pinta como una insignia junto al destinatario. Con varias monedas es lo único que contesta de
un vistazo: las líneas de importes dicen «pagado» por moneda, no si la orden entera está saldada.

⚠️ Una orden **sin importes** no está saldada: está sin cargar. Con todo a cero el saldo también
da cero, y decir «saldada» ahí afirma algo sobre un dinero que nadie ha escrito.

### `addPago()`: el lado inverso no se poblaba

No existía, así que un abono recién registrado no aparecía en `$orden->getPagos()` hasta releer de
la base — y con el saldo calculado al vuelo sobre esa colección, cualquier cosa que mirara la
orden **dentro de la misma petición** lo veía sin él. El panel lo tapaba recargando la orden
después de guardar: una vuelta de red para arreglar algo que pasaba en memoria.

El saldo aparece en la cabecera de la orden («saldo X» o «pagado») y, con el detalle, en el
modal de pagos: hoja inferior en móvil, tarjeta centrada en ancho. El modal recarga sólo su
orden al añadir o borrar (`refrescarOrden`), no la lista entera.

El medio **no se preselecciona**: cómo se pagó es un hecho, y un valor por defecto lo convierte
en «lo que estaba puesto al abrir». Sí se conservan moneda y medio entre altas seguidas, porque
quien registra tres abonos los hace casi siempre igual.

⚠️ Y el error del servidor **se pinta tal cual**. `crearPago()` devolvía un booleano y el panel
decía «No se pudo registrar el pago»; el backend sabe qué falló —«esta orden se maneja en USD: no
se le puede registrar un pago en PEN»— y taparlo obligaba a adivinar delante de un formulario que
no dice qué campo está mal. Ahora devuelve el motivo (`mensajeDeErrorApi`).

Verificación con datos reales: `var/probar-pago-orden-servicio.php`, en transacción con
`rollback`. ⚠️ **En local no hay ni una orden de servicio**, así que esa sonda sólo dice algo
ejecutada contra producción (`APP_ENV=prod`).

⚠️ Nota de rendimiento que salió aquí: `actualizarServicio` ya **no** enciende el `isLoading`
global. Lo hacía, y editar un número disparaba el spinner de pantalla completa —parecía que
todo se recargaba por cambiar un importe—. La fila se reemplaza en su sitio y el editor da su
propio ✓; ningún PATCH de un campo justifica un spinner global.

## 5.6 El ciclo de una orden: emitir, anular, reemitir, borrar (21/08/2026)

```
                    ┌── emitir ──► EMITIDA ──► CONFIRMADA ──► COMPLETADA
   BORRADOR ────────┤                │             │              │
      │             └── (se puede borrar)          │              │
      │                              └─────────────┴──────────────┴──► CANCELADA
      └── ELIMINAR: se va la orden, los servicios VUELVEN AL POOL
```

| Acción | Cómo se ejecuta | Qué hace con los servicios |
|---|---|---|
| **Emitir** | `POST /ops/orden-servicios/emitir` (`EmitirOrdenProcessor`), o salir de borrador por `POST /{id}/estado` | Los congela en `items`: copias, no referencias |
| **Avanzar** | `POST /{id}/estado` | Nada |
| **Anular** | `POST /{id}/estado` con `cancelada` → `OperacionOrdenEmision::anular()` | **Los devuelve al pool** (`setOrdenServicio(null)`) |
| **Reemitir** | `anularParaReemitir()` en el panel: anula y emite la sucesora con `reemplazaA` | Del pool a la nueva orden |
| **Eliminar** | `DELETE /ops/operacion_orden_servicios/{id}` | **Los devuelve al pool** — lo hace la FK, no el código |

### ⚠️ Sólo se borra un BORRADOR

`OperacionOrdenBorradoListener`. El `Delete` de la API **no tenía ninguna comprobación**: bastaba
el permiso para borrar una orden ya emitida, la que el proveedor tiene en la mano.

Y contradecía de frente a `OperacionOrdenEmision::validarTransicion()`, que se niega a devolver
una emitida a borrador con estas palabras: «el proveedor ya la tiene. Anúlala y emite otra». Si ni
siquiera se puede retroceder de estado, borrarla entera —número de OS, líneas congeladas,
bitácora, pagos— no podía estar permitido. Era la puerta grande al lado de la puerta cerrada.

> La orden OS-20260815-335 está emitida: no se borra. Una orden que ya salió al proveedor deja
> rastro. Anúlala —eso también devuelve sus servicios al pool— y, si hace falta, emite otra.

**Anular consigue lo mismo sin perder el rastro**, y por eso el mensaje manda ahí: quien quería
soltar los servicios los suelta igual.

### Los servicios vuelven al pool solos

`operacion_servicio.orden_servicio_id` es **`ON DELETE SET NULL`**: al borrar el borrador se
sueltan sin que ningún código los toque, y quedan libres para entrar en otra orden. Los pagos y
la bitácora sí caen en **cascada** — son de la orden, no del servicio. Es la distinción que
importa: un servicio **preexiste** a la orden y le sobrevive; un pago, no.

### En el panel

Botón **Eliminar** en la tarjeta, **sólo con estado borrador**, con confirmación en dos toques
que dice la consecuencia («los servicios vuelven al pool») en vez de un «¿seguro?» pelado. El
motivo del rechazo se pinta **pegado a su orden**: con varias en pantalla, un aviso global no
diría de cuál habla.

⚠️ **Al borrar hay que tocar DOS colecciones, y son distintas.** La tarjeta se quedaba en
pantalla después de borrar porque la vista refrescaba La Biblia (`fetchServicios`) creyendo que
eso bastaba — pero las órdenes viven en `ordenesServicio` y los servicios en `servicios`:

| Qué cambió | Quién lo refleja |
|---|---|
| La orden ya no existe | `eliminarOrdenServicio()` la saca de `ordenesServicio` **en el store** |
| Sus servicios volvieron al pool | la vista relee La Biblia con `cargarBiblia()` |

La primera va en el **store** por lo mismo que `aplicarCambiosMenores()` y `cambiarEstadoOrden()`
ya sincronizan ahí: si cada vista tuviera que acordarse, la segunda no se acuerda. Y se hace en
memoria en vez de refetchear porque la respuesta ya se sabe — la orden que se acaba de borrar no
está.

### ⚠️ Crear y emitir dejaron de ser el mismo gesto (21/08/2026)

El alta mandaba `soloBorrador: false` **fijo**, con el motivo escrito al lado: «el borrador
existe para componer, y aquí ya está compuesta». Era cierto **mientras no hubiera forma de
emitir después** —el estado vivía en un desplegable enterrado en el modal de edición—, así que
crear y emitir tenían que ser el mismo gesto.

Con el botón «Emitir» en la tarjeta esa razón desapareció, y lo que quedaba era sólo lo malo:
componer una orden obligaba a **mandársela al proveedor en el acto**, sin poder repasar el
destinatario, el número ni los importes negociados. El síntoma se notaba al revés — «creo una
orden y ya nace emitida, así que el botón Emitir no me sale nunca».

El modal ofrece ahora dos caminos, y **el que sale al proveedor no es el destacado**:

| Botón | Qué hace |
|---|---|
| **Crear borrador** | La deja compuesta y sin salir. Se repasa y se emite después desde su tarjeta |
| **Crear y emitir** | Lo de antes: crea y congela el contenido de una vez |

La API ya lo soportaba (`EmitirOrdenInput::$soloBorrador`); lo que no lo ofrecía era el panel.

### El estado se VE en el formulario y se MUEVE con botones

Se movía con un `<select>` dentro del modal de edición, y ese control **no distingue entre
corregir un número de OS y mandarle un documento a un proveedor**: las dos cosas salían del
mismo gesto, sin confirmación y a un desliz de distancia. Además ofrecía los cinco destinos
aunque el backend fuera a rechazar la mitad.

Ahora el formulario **sólo enseña** el estado, y cada transición es un botón en la tarjeta con
confirmación en dos toques que dice su consecuencia:

| Estado | Botones |
|---|---|
| `borrador` | **Emitir** («se congela el contenido y ya no vuelve a borrador») · **Eliminar** |
| `emitida` | **Confirmar** · **Reemitir** · **Anular** («no vuelve atrás, y sus servicios regresan al pool») |
| `confirmada` | **Completar** · **Reemitir** · **Anular** |
| `completada` | **Anular** |
| `cancelada` | ninguno — es terminal |

**Reemitir sale del rincón.** Vivía sólo dentro del aviso de divergencias con La Biblia, así que
no se podía reemitir por ningún otro motivo —un precio repactado, un error en el documento— sin
pasar por el desplegable.

⚠️ `accionesDe()` es un **espejo de `OperacionOrdenEmision::validarTransicion()`**, que es quien
manda. Lo fija `tests/Operacion/Service/TransicionesDeOrdenTest.php`: si el panel ofreciera una
transición que el backend rechaza, el operador confirmaría y se comería un 422; si el backend
admitiera una nueva y el panel no la añadiera, el botón sencillamente no existiría.

## 5.6 bis El plan de sincronización enseñaba UUIDs (21/08/2026)

La línea «Servicio del prestador (catálogo)» salía como
`019f68bd-4f13-7750-bb37-2cafacd3489b`. Dos fallos encadenados:

1. **`prestadorServicioMaestroId` no estaba en `CAMPOS_TECNICOS`**, así que nunca pasaba por
   `describirTecnico()` — que ya sabía tratarlo.
2. Y `describirTecnico()` devolvía **ocho caracteres del UUID** («prestador 019f68bd»), que no
   dicen más que el UUID entero.

Ahora los identificadores del catálogo se resuelven a **nombre** contra `TravelOrganizacion` y
`TravelOrganizacionServicio`, con caché por petición —un plan de 40 filas repite el mismo
proveedor decenas de veces—. El id queda de respaldo: si la ficha se borró del maestro, el
nombre ya no existe y enseñar el identificador sigue siendo mejor que un hueco.

`cotizacionTarifaId` se queda sin nombre a propósito: una tarifa no tiene una etiqueta corta que
valga: la explica su importe, que ya sale en la línea de al lado.

⚠️ Quien aprueba un cambio del catálogo necesita saber **de qué empresa a qué empresa**. Con un
UUID no puede decidir, así que aprueba a ciegas o no aprueba nada.

## 5.7 Enviarle la orden al proveedor (21/08/2026)

`OperacionOrdenDocumento` + `OperacionOrdenEnvio` + `EnviarOrdenController`.

```
GET  /platform/ops/orden-servicios/{id}/documento   → qué se mandaría y por qué canales
POST /platform/ops/orden-servicios/{id}/enviar      → {"canal": "email"}
```

### ⚠️ Enviar NO es parte de emitir

Son dos decisiones y **fallan distinto**: emitir congela el contenido —un hecho interno—;
enviar se lo pone delante a alguien de fuera y eso no se retira. En el mismo botón, un fallo de
red dejaría la orden emitida y al proveedor sin enterarse, o al revés.

Separados, **«reenviar» no necesita código propio**: es el mismo botón otra vez. Y eso importa
porque reenviar es lo normal — el proveedor perdió el correo, cambió de contacto, o hubo un
cambio menor que no justifica reemitir.

Disponible en `emitida`, `confirmada` y `completada`. En borrador no: no hay ítems congelados y
el documento saldría vacío.

### El documento no lleva importes

Es la regla que ya estaba escrita en `$totalOs` —«al proveedor no se le manda un total»—,
extendida a las líneas. Este documento es una **solicitud de servicio**: a quién recoger, dónde,
a qué hora y cuántos son. El dinero está pactado por otro canal, y meterlo abre una negociación
justo cuando lo que hace falta es que la plaza exista. Lo que se paga va aparte, en
`OperacionPago`.

Sale de los **ítems congelados**, no de los servicios vivos: componerlo de éstos haría que
reenviarlo un mes después mandara algo distinto de lo que el proveedor tiene en la mano — y ése
es justo el escenario en que se reenvía.

Ejemplo real:

```
Orden de Servicio OS-20260817-938

· 31/08/2026  ·  22:00  ·  Auto a Miraflores Noche  ·  2 pax
· 02/09/2026  ·  04:00  ·  Tacama (Base 2 Pax)  ·  2 pax
· 04/09/2026  ·  08:30  ·  Auto  ·  2 pax

Por favor confirmar recepción y disponibilidad.
```

⚠️ La **hora de recojo sólo se dice cuando difiere** de la del servicio. En los datos reales
coinciden casi siempre, y repetir el mismo dato dos veces por línea enseña a no leerlo — que es
lo contrario de lo que hace falta el día que sí sean distintas.

### Va por el hilo del proveedor, no por un mailer suelto

El destinatario es una `TravelOrganizacion`, que desde el 21/08/2026 tiene conversación como
cualquier asunto. Así el envío hereda lo que ya está resuelto: cola con reintentos, destino
resuelto **por identidad** —no por el campo del catálogo, que es sólo la semilla—, veto de
números muertos e historial en el chat. Un mailer suelto habría duplicado esas cuatro cosas, y
se habría notado la primera vez que un proveedor cambiara de correo.

El canal **se elige en la previsualización**, entre los que ese proveedor tenga disponibles. Los
no disponibles se enseñan con su motivo en vez de esconderse: «no aparece WhatsApp» obliga a
adivinar; «sin datos» no.

### El enlace público, y por qué existe (21/08/2026)

```
GET /orden/{token}       → la página que ve el proveedor (host de PAX)
GET /orden/{token}.pdf   → el mismo documento, descargable
```

⚠️ **Nace de una restricción de Meta, no de un capricho.** Fuera de la ventana de 24 h WhatsApp
sólo admite **plantillas aprobadas**, y una orden de servicio **no cabe en una**: las plantillas
tienen variables sueltas, no filas — y una orden tiene tantas líneas como servicios. La salida
estándar es que la plantilla lleve un **botón con URL** y el detalle viva al otro lado. El
mecanismo de botón-URL con `{{1}}` ya existía en `WhatsappMetaTemplatePushService`.

| Pieza | Decisión |
|---|---|
| Llave | `token_publico`, 32 caracteres de azar. Ni `numeroOs` —`OS-20260817-938`, adivinable— ni el `id`, que es un **UUID v7** con la marca de tiempo delante |
| Cuándo se sella | Al **emitir**. Un borrador no tiene documento, y una llave sin documento invita a repartir un enlace vacío. Idempotente: reemitir no invalida el enlace ya repartido |
| Dónde vive | Host de **PAX**, el público. El proveedor no tiene cuenta |
| PDF | **Al vuelo** con dompdf, sin fichero guardado |
| Anulada | **404**. El proveedor no debe quedarse abierta la versión retirada |

⚠️ **Es público: quien tenga el enlace lo lee.** Por eso el documento no lleva importes, ni
expediente, ni cliente — sólo qué operar. Un enlace se reenvía, y hay que asumir que acaba fuera.

⚠️ **Generado al vuelo tiene una contrapartida y hay que saberla:** si después se aplican
«cambios menores», quien reabra el enlace verá la versión NUEVA, no la que recibió. Es lo
aceptado a cambio de no mantener un almacén de documentos.

⚠️ **El CSS de la plantilla es pobre a propósito** —sin flex, sin grid, sin variables—: dompdf
entiende CSS 2.1 y poco más, y una hoja moderna se ve bien en el navegador y descuadrada en el
PDF. Que es el peor de los dos resultados, porque sólo se nota cuando ya lo mandaste.

### Fuera de la ventana de 24 h no se manda por WhatsApp

`OperacionOrdenEnvio::exigirVentanaAbierta()` se niega con el motivo en vez de intentarlo: un
envío que Meta rechaza deja al proveedor sin enterarse y al operador creyendo que salió. El panel
además lo avisa **antes** de elegir canal, no después de leer todo el documento.

**Pendiente:** dar de alta la plantilla con botón-URL y que Meta la apruebe. Hasta entonces,
fuera de ventana la salida es el correo.

### ⚠️ Un controlador nuevo no tiene rutas hasta declarar su directorio

`config/routes.yaml` lista el directorio de cada módulo, y `src/Operacion/Controller/Api/` no
estaba. Se descubre con un 404 en una ruta que `debug:router` no lista — no con un error, que es
peor: parece un fallo del front.

## 6. API: endpoints, grupos y filtros

`routePrefix: '/ops'`, todo bajo `/platform/ops/`:

| Recurso | Endpoint | Grupo lectura |
|---|---|---|
| `OperacionServicio` | `operacion_servicios` | `operacion:item:read` |
| `OperacionOrdenServicio` | `operacion_orden_servicios` | `operacion:read` |
| `OperacionMensaje` | `operacion_mensajes` | `operacion:mensaje:read` |

Roles: `ROLE_OPERACIONES_SHOW` (GET), `_WRITE` (POST/PUT/PATCH), `_DELETE`
(`src/Security/Roles.php`).

**Fuera de `/ops`:** la regeneración manual cuelga del recurso `Cotizacion`, no de este módulo,
porque su `{id}` es el de la cotización:

| Operación | Endpoint | Salida |
|---|---|---|
| Calcular el diff (§3.5) | `POST /platform/sales/cotizacions/{id}/operacion/plan` | `PlanReconciliacion` |
| Aplicar lo aprobado (§3.5) | `POST /platform/sales/cotizacions/{id}/operacion/aplicar` | `ResultadoAplicacion` |

Las dos usan el grupo `operacion:plan:read`; `aplicar` recibe `AplicarPlanInput`
(`operacion:plan:write`). **`plan` no escribe nada** y es POST y no GET a propósito: el
cálculo recorre el árbol entero y ninguna caché HTTP debe servirlo viejo — un plan obsoleto
es justo lo que la firma existe para impedir.

Se declara con `read: true` (el provider trae la Cotizacion), `deserialize: false` (no hay
cuerpo) y `output:` al DTO. El procesador vive en `src/Operacion/`, no en `src/Cotizacion/`: la
regla es de operaciones aunque la URL sea de ventas.

### 🔥 Gotcha mayor: el directorio tiene que estar en `mapping.paths`

`config/packages/api_platform.yaml` → `mapping.paths` **debe incluir `src/Operacion/Entity`**.
`AttributeFilterPass` recorre sólo esos directorios para compilar los `#[ApiFilter]` a servicios.
Mientras faltó, los filtros existían en el código y **no se aplicaban**: la colección devolvía
todo, cualquier `?fechaServicio=…` se descartaba en silencio y el OpenAPI no exponía un solo
parámetro. Curiosidad: los *recursos* sí se descubrían igualmente, así que los endpoints
funcionaban y nada delataba el problema.

Si añades un módulo nuevo con `#[ApiFilter]`, añade su directorio aquí o repetirás el fallo.

**Cómo comprobarlo:**

```bash
php bin/console debug:container | grep annotated_.*filter    # debe listar el módulo
php bin/console api:openapi:export | grep -A5 operacion_servicios
```

### Orden por defecto

`#[ApiResource(order: ['fechaServicio' => 'ASC', 'horaRecojoReal' => 'ASC'])]`. Sin él la
colección llega en orden arbitrario de la BD y el cuadro de tráfico es ilegible.

### Filtros disponibles

| Filtro | Parámetro |
|---|---|
| `DateFilter` sobre `fechaServicio` | `fechaServicio[after]`, `[before]` (inclusivos), `[strictly_after]`, `[strictly_before]` |
| `SearchFilter` exact | `file`, `cotizacionServicio.cotizacion`, `ordenServicio`, `estadoReservaProveedor`, `estadoOperacion`, `proveedorMaestroId`, `tipoComponente`, `modoComponente`, `estadoComponente` |
| `OrderFilter` | `order[fechaServicio]`, `order[horaRecojoReal]`, `order[tipoComponente]`, `order[descripcionServicio]` |

`fechaServicio` usa `DateFilter` y no `SearchFilter`: `exact` sólo permite un día suelto y el
tráfico se planifica por rango. `cotizacionServicio.cotizacion` es un filtro **anidado** que
navega la asociación; no hace falta relación directa a `Cotizacion`.


### El filtro por lugar: el único que cruza a Travel

`?lugar[]=<uuid>` (varios = OR) filtra el cuadro por centro turístico. Lo resuelve
`src/Operacion/Filter/OperacionServicioLugarExtension.php`, y es la única consulta del módulo
que mira a Travel. **No es un JOIN**, porque no puede serlo: Operación no tiene ninguna
relación con Travel, sólo el soft-link escalar `CotizacionCotcomponente::$componenteMaestroId`
(`VARCHAR(36)`, sin FK). Son tres pasos:

1. **Validar** cada UUID. Si llegó el parámetro y no queda ninguno válido →
   `andWhere('1 = 0')`, **nunca un `return`**. Ignorar un filtro en silencio es exactamente
   cómo se acaban emitiendo órdenes de servicio para la ciudad equivocada.
2. **Resolver lugar → ids de componente maestro** con una consulta a Travel.
3. **Aplicar** un `IN (...)` sobre el soft-link.

#### Y una segunda fuente: los componentes MANUALES (25/08/2026)

Un componente tecleado a mano no tiene maestro al que preguntarle, así que sus ubicaciones viven
en su propia fila: `CotizacionCotcomponente::$lugaresManuales`, una lista JSON de uuids (ver
`docs/Cotizaciones.md` §«Las ubicaciones de un componente manual»). El filtro pregunta a las dos
y las junta con **OR**:

```sql
componente_maestro_id IN (:ids del catálogo)      -- los de catálogo
OR JSON_CONTAINS(lugares_manuales, '"<uuid>"')    -- una por uuid pedido
```

**Una condición `JSON_CONTAINS` por uuid, no una con la lista entera**: con la lista preguntaría
por los que los tienen **todos**, y aquí varios lugares se combinan en OR. El candidato viaja
como JSON (`json_encode()`), no como texto pelado — `JSON_CONTAINS(x, 'abc')` es un error de
sintaxis JSON y las comillas son parte del valor.

`JSON_CONTAINS` y `JSON_LENGTH` están registradas como funciones DQL en
`config/packages/doctrine.yaml` (`beberlei/doctrineextensions`): Doctrine no entiende columnas
JSON de fábrica. Es la alternativa barata a montar una tabla de relación hacia el catálogo, que
es justo lo que este módulo no quiere tener.

⚠️ **No hay que desempatar entre las dos fuentes.** Son excluyentes por construcción: la entidad
vacía las manuales al vincular un maestro, y no deja escribirlas mientras haya uno.

⚠️ **La conversión de identificadores es donde falla.** `travel_componente.id` es `BINARY(16)`
y `componente_maestro_id` es `VARCHAR(36)` en minúsculas —tal como lo escribe `extractIdStr()`
del store—. Según cómo vuelva la consulta, el id llega como objeto `Uuid`, como cadena o como
**los 16 bytes crudos**; el helper contempla las tres formas y normaliza todo con
`toRfc4122()`. La primera versión devolvía cero resultados por saltarse el caso binario.

El índice `idx_cotcomponente_maestro` sobre `cotizacion_cotcomponente (componente_maestro_id)`
no es opcional: es la columna contra la que se lanza el `IN`, y sin él cada clic en un chip
provoca un full scan.

**El chip «Sin etiqueta»** (`lugar[]=__sin_lugar__`) recoge lo que no cae en ningún lugar, y
desde el 25/08/2026 son dos casos y no uno:

- soft-link **puesto** pero fuera de la lista de los etiquetados (maestro sin etiquetar), y
- soft-link **`NULL` Y `JSON_LENGTH(lugares_manuales) = 0`** — un manual sin ubicación puesta.

⚠️ La segunda condición es la que cambió. Antes bastaba con no tener maestro, así que **todos**
los manuales caían aquí; ahora el que tiene ubicación sale en su lugar y no en este chip.

Es la diferencia entre un filtro fiable y uno que esconde cosas: sin este chip, la suma de todos
los chips no da el total y nadie sabe qué falta.

**No se cachea la resolución lugar → componentes.** La caché es justo lo que rompería el
etiquetado vivo (`docs/Travel.md` §8): renombrar o reetiquetar tiene que verse en el
siguiente clic.

**Registro: ninguno.** `services_operacion.yaml` ya carga `../../src/Operacion/` con
autoconfigure y API Platform etiqueta sola la interfaz.

### Qué viaja embebido y qué no

`file` sí trae `id`, `nombreGrupo` y `pasajeroPrincipal`: `CotizacionFile` los publica en el grupo
`operacion:item:read` justamente para que La Biblia sepa de quién es cada fila. `getId()` se
redeclara sobre `IdTrait` sólo para eso.

`cotizacionServicio`, `cotizacionComponente` y `cotizacionTarifa` siguen siendo **stubs**
(identificador + timestamps): su información útil está denormalizada en el snapshot
(`contextoServicio`, `tipoComponente`, `modoComponente`, `estadoComponente`). Si necesitas un dato
nuevo de la cotización en La Biblia, la vía correcta es **añadirlo al snapshot**, no abrir grupos
de serialización sobre el árbol de cotizaciones.

---

## 7. La vista de tráfico

`util/src/views/Operacion/OperacionView.vue`, ruta `/operacion`.

**Filtros:** rango de fechas —arranca en **hoy → +6 días**, no en un solo día (§3.bis)—, atajos
Hoy / Mañana, expediente por autocompletado, versión de la cotización (del expediente elegido) y
cuatro ejes plegables: lugar, organización, tipo y estado. `construirParamsBiblia()` en
`operacionModel.ts` es el único sitio donde viven los nombres de los parámetros de query.

### 7.0 La barra de filtros: cuatro ejes tras una fila de rótulos (25/08/2026)

La barra ocupa **tres líneas fijas** y nada más:

```
┌──────────────────────────────────────────────┐
│ DESDE [ 18/08/2026 ]   HASTA [ 10/09/2026 ]  │  rango
├──────────────────────────────────────────────┤
│ [Hoy] [Mañana]  [ Expediente…            ]   │  atajos + búsqueda
├──────────────────────────────────────────────┤
│ 📍Lugar ▾  🚚Organización① ▾  Tipo ▾  Estado ▾   40  │  ejes
└──────────────────────────────────────────────┘
        ↓ sólo el eje abierto pinta sus opciones
   [ Cusco ] [ Lima ] [ Puno ] [ ? Sin etiqueta ]
```

**Mobile first, y la restricción manda.** Cada eje tenía antes su propia franja desplegada: trece
chips de lugar, los de organización y catorce de tipo. En un teléfono de 360 px la barra se comía
la pantalla entera y **el primer servicio caía bajo el pliegue** — que es justo lo que se viene a
mirar. Ahora sólo hay un eje abierto a la vez y al abrir uno se cierra el anterior
(`grupoAbierto`, `alternarGrupo()`).

**El rótulo ES el interruptor y lleva contador.** Es lo que hace seguro arrancar con todos
cerrados: un filtro activo escondido *y sin contador* es la forma de leer un cuadro recortado
creyéndolo entero — el fallo que ya costó las filas de tipo `contacto`. `conteoPorGrupo` es quien
lo calcula, y tiene que cubrir los cuatro ejes.

⚠️ **LUGAR y ORGANIZACIÓN se esconden cuando no tienen nada que ofrecer** (`gruposVisibles`). Un
rótulo que abre a una lista vacía se lee como avería —«el filtro no carga»— cuando lo que pasa es
que no hay de qué filtrar.

⚠️ **Pero LUGAR sale igual si hay lugares FILTRADOS, aunque el catálogo esté vacío.** Los lugares
elegidos se restauran de `localStorage` antes de que cargue el catálogo y viajan **al servidor**;
si `fetchLugares()` falla —su `catch` se traga el error y deja la lista vacía—, esconder el rótulo
dejaba un filtro activo **sin contador y sin chips que quitar**: cuadro recortado que se lee como
«no hay nada». Es el mismo fallo que costó las filas de tipo `contacto`, reintroducido por la
puerta de atrás. ORGANIZACIÓN no lo necesita porque su `watch` de poda suelta lo que desaparece, y
TIPO descarta el filtro guardado si el catálogo cambió. Y si el eje abierto desaparece, un `watch` lo cierra: si no, quedaba un
bloque de opciones colgando sin rótulo con el que cerrarlo, y había que recargar para salir.

**Qué desapareció y por qué:**

| Antes | Ahora | Motivo |
|---|---|---|
| Botón «Filtros» + panel plegable | — | Sólo escondía tipo y estado un toque más adentro, y no son menos de diario que lugar u organización |
| Preset «7 días» | — | Devolvía al rango de arranque, o sea a ninguna parte: la vista ya nace con la semana puesta |
| Botón «Actualizar» en cada barra | Uno en la cabecera (`refrescar()`) | Estaba duplicado en las dos pestañas y en La Biblia se comía el ancho donde ahora vive el expediente |
| Chips de tipo y estados repartidos | Ejes «Tipo» y «Estado» | Los tres cortes de estado —OS, reserva, operación— se leen juntos y nada decía que fueran la misma pregunta |

**El expediente se queda en la barra fija**, en el hueco que dejaron «7 días» y «Actualizar». Es
lo único que consulta SIN rango de fechas: esconderlo tras un desplegable era esconder la salida.
La **versión de la cotización** cuelga de él y sólo sale cuando hay expediente elegido — suelta en
un panel de filtros no se entendía de qué era versión.

Los chips de lugar llevan color distinto al de los de tipo porque son dos taxonomías distintas y
no deben leerse como la misma.

Los badges de lugar por fila se resuelven **en lote**: se juntan los `componenteMaestroId`
distintos de las filas cargadas y se hace **una sola** llamada a
`/platform/travel/componentes?id[]=…&pagination=false`. Los `lugares` llegan como IRIs y se
traducen a nombre contra el vocabulario que ya se cargó para los chips — una consulta por carga
del cuadro, no una por fila.

`lugaresDeServicio()` mira **dos fuentes y no las mezcla**: con maestro, las del catálogo recién
resueltas; sin él, los uuids de `lugaresManuales` traducidos contra ese mismo vocabulario.
`nombreComponenteDeServicio()` sigue exactamente el mismo patrón con el **nombre**: maestro en
vivo, y `nombreInternoSnapshot` como respaldo para los manuales (§«El componente identifica la
fila»).

⚠️ **El vocabulario se carga aunque no haya ni un componente de catálogo.** El corte por «no hay
maestros que resolver» dejaba en blanco un cuadro entero de filas manuales que sí tenían
ubicación: sin vocabulario no hay con qué traducir sus uuids a nombre.

### De tabla a REJILLA DE FICHAS (25/08/2026)

La Biblia era **una tabla** con seis de sus nueve columnas marcadas `hidden md:table-cell`. En un
teléfono —que es donde se usa el cuadro de tráfico— eso no se convertía en ficha: se quedaba una
tabla a la que le faltaban columnas, con el servicio, el expediente, el prestador y el costo
apelotonados en la única celda que sobrevivía. Ni tabla ni ficha.

Ahora es **una ficha por servicio**, en rejilla de 1 / 2 / 3 columnas según el ancho. La misma
maqueta en todos los tamaños: no hay dos renders que mantener.

#### Qué se edita en la ficha y qué no

| | Dónde |
|---|---|
| Los dos **estados** (reserva y operación) | En la propia ficha. Es lo que se mueve a diario |
| La **hora** de recojo | En la propia ficha — **si el componente admite hora** |
| Puntos, notas, prestador, servicio contratado, comprador, costo | En el formulario, a un toque de la plumita |

La regla es «lo que se toca a diario se toca sin abrir nada». Todo lo demás vive en el formulario,
porque un campo editable en cada ficha multiplicado por cuarenta fichas es lo que convertía el
cuadro en un formulario gigante.

⚠️ **La hora sale del FLAG, no de una copia de la regla.** `admiteHora()` mira
`CotizacionCotcomponente::$sinHorario` (publicado en `operacion:item:read`), que sale de
`ComponenteTipoEnum::sinHorario()`. Un ingreso al Koricancha es un `ticket_variable`: no tiene hora
que fijar, y La Biblia ofrecía el campo igualmente — una hora escrita ahí no significa nada y
alguien acaba mandándosela al proveedor. Ahora ese input **no existe** para esos tipos; en su sitio
se lee «sin hora». Si el flag no llegara, se admite: esconder un campo que la gente usa es peor que
enseñar uno de más, y lo segundo se ve.

#### La EMPRESA va encima de todo

Arriba de la ficha, por encima del nombre del componente, va el **prestador efectivo** —y el
comprador cuando no es el mismo—. Destacado pero más pequeño que el componente: manda en el
vistazo, no en la jerarquía. Lo que la fila *es* sigue siendo el componente; lo que se *busca* al
recorrer el día es a quién se le pide, porque la orden agrupa por comprador.

Sin prestador sale «Sin asignar» en ámbar, que es el mismo color y el mismo criterio que su chip
de filtro. El comprador sólo aparece si difiere del prestador o falta (`mostrarComprador()`):
repetir el mismo nombre dos veces en cada ficha convierte el dato en ruido.

#### Tres gestos, y cada uno donde toca

| Gesto | Qué hace |
|---|---|
| Tocar el **cuerpo** de la ficha | La marca para la orden |
| Botón 👁 | Abre el **detalle** |
| Botón ✎ | Abre el **formulario** |

Marcar es lo del barrido —se recorre el día marcando lo de un proveedor— y por eso se lleva la
superficie grande. Ver y editar son operaciones sobre UNA fila y tienen cada una su botón, del
mismo tamaño y juntos. Los dos van con `@click.stop`, igual que la hora y los dos desplegables de
estado: sin eso, tocar un desplegable marcaría además la ficha.

La ficha abre el **detalle en lectura** y el formulario queda a un botón. Recorrer cuarenta
servicios es lo que más se hace en un día de tráfico, y en un formulario los datos están
repartidos entre campos que hay que interpretar. Es el mismo reparto que el namelist del expediente
(`docs/Cotizaciones.md`), y por el mismo motivo.

Las flechas de la cabecera saltan a la ficha de al lado **sin apilar capas**: es la misma capa
cambiando de contenido. Sin eso, recorrer diez servicios dejaba diez entradas de historial detrás.

#### El gesto atrás devuelve, no expulsa

Detalle y formulario son **capas en el historial** (`useCapasEnHistorial`, ver
`docs/UI_Componentes_Compartidos.md` §3.k): `?capa=servicio.servicio-edicion`. Atrás lleva del
formulario al detalle, y del detalle a la rejilla. Guardar hace lo mismo —vuelve al detalle, no
cierra—: lo normal es mirar lo que acabas de cambiar.

⚠️ Al enganchar la ficha hubo que **migrar los seis modales de la vista a la vez**. Tenían su
propio mecanismo con `history.pushState` a mano, que es justo el que el composable reemplaza, y dos
contabilidades sobre el mismo historial se pisan.

⚠️ **Al guardar se vuelve a apuntar la ficha por id.** `cargarBiblia()` reemplaza el array entero,
así que la ficha quedaba apuntando a un objeto muerto y el detalle volvía enseñando los datos de
antes de guardar.

#### Prestador y proveedor: el error se dice donde se está mirando

En la tabla, escribir un nombre que no estaba en el catálogo **revertía el input en silencio** y
sacaba un aviso en una franja flotante. En el formulario, ese nombre corta el guardado y el motivo
sale junto al botón de guardar. Sigue valiendo que **vacío = «vuelve a lo cotizado»**, que no es lo
mismo que «no hay nadie».

El costo negociado se empotra con su propio editor y **guarda al confirmarlo**, aparte del botón de
abajo: tiene su propia confirmación y duplicarlo en el borrador daría dos formas de guardar el
mismo importe.

### El eje ORGANIZACIÓN (25/08/2026)

Se llamaba «Empresa» y pasó a **Organización** el mismo día: es el nombre que ya usa el dominio
(`TravelOrganizacion`) y el que lleva el catálogo contra el que se validan prestador y comprador
(`resolverOrganizacion()`). Dos nombres para la misma cosa obligan a traducir mentalmente en cada
pantalla. El renombrado alcanzó a los identificadores del front —`organizacionesSeleccionadas`,
`organizacionesDelCuadro`, `coincideOrganizacion()`, `SIN_ORGANIZACION`—; no toca la API, porque
este filtro no viaja al servidor.

Chips en el eje, con el mismo patrón que los de lugar —el rótulo es el interruptor y lleva
contador—, pero con tres diferencias que importan:

- **Filtra lo ya cargado**, en el navegador, como el conmutador «sin OS / en OS». No viaja al
  servidor. Es lo que pidió Jorge y es suficiente: el cuadro trae 200 filas como mucho.
- **Los chips salen de las filas**, no del catálogo de proveedores. El catálogo es largo y la
  mitad no aparece en estas fechas; así cada chip que se ve trae al menos una fila.
- **Mira los dos papeles y los combina con OR.** Una fila tiene prestador y comprador, y no
  siempre son la misma (§3.3.b): quien busca «Futurismo Jonathan» quiere sus filas, le
  toque el papel que le toque. Y mira el **efectivo**, no lo cotizado — filtrar por lo cotizado
  escondería justo las filas que Operaciones acaba de reasignar.

**El chip «Sin asignar»** trae las filas sin ninguna de las dos: son las que hay que resolver
antes de emitir nada, y no tienen nombre por el que buscarlas.

#### Quién puede recibir una orden, y por qué se marca (25/08/2026)

Los dos papeles caen en el mismo saco de chips, y ahí se perdía la diferencia que más importa:
**la Orden de Servicio agrupa por comprador**, así que una organización que en este cuadro sólo
aparece como *prestador* no va a recibir ninguna — su orden se la lleva quien le compra. Con la
lista en puro orden alfabético las dos clases se leían igual, y se elegía un prestador esperando
poder emitirle.

Ahora `organizacionesDelCuadro` devuelve `{ id, nombre, recibeOrdenes }` y:

- **Van primero las que reciben órdenes**, y dentro de cada bloque, alfabético.
- **Llevan marca**: el mismo `fa-file-invoice` naranja (`#E07845`) de «Generar OS» y de la pestaña
  de Órdenes. Si el signo quiere decir «esto acaba en una orden», tiene que ser el mismo en toda
  la pantalla.
- Las demás quedan en gris más claro: siguen filtrando igual, pero se leen como referencia.

⚠️ **`recibeOrdenes` exige además que la fila sea COMPRABLE** (`esComprable()`, espejo de
`OperacionServicio::esComprable()`). Si todo lo que esa organización compra en este rango es
referencia o está cancelado, la orden no llegaría a existir y la marca prometería algo que no se
cumple.

⚠️ **`esComprable()` va declarado ARRIBA en el `<script setup>`**, antes de
`organizacionesDelCuadro`, y no junto a `conflictoSeleccion()`, que es su otro consumidor. El
`watch` sobre ese computed lo evalúa **durante el `setup`**, así que con la declaración más abajo
la vista revienta con un `ReferenceError` — y ni `vue-tsc` ni ESLint lo ven: para TypeScript una
función usada antes de declararse dentro de un closure es legal.

La leyenda de la marca sólo sale cuando conviven las dos clases (`hayMezclaDePapeles`). En un
teléfono no hay `title` que leer, así que sin ella el icono naranja sería un signo sin explicar;
y con todas iguales explicaría una distinción que no se ve.

⚠️ **Las selecciones se sueltan solas cuando la organización desaparece del cuadro.** Al cambiar
el rango, una organización elegida puede no estar en la carga nueva: su chip dejaría de pintarse pero
seguiría filtrando, y el cuadro se quedaría vacío sin que nada dijera por qué. Se falla ABIERTO,
igual que con el filtro de tipos.

⚠️ Y por lo mismo, un cuadro vacío **por los filtros de la vista** ya no es un hueco en blanco:
tiene su propio bloque, dice cuántas filas hay cargadas y ofrece quitar el filtro que las esconde.
Con `filtroOs` esto ya podía pasar y no se avisaba.

Por eso el filtro **no se guarda en `localStorage`**, al revés que los demás: sus valores dependen
de lo que esté cargado, y restaurar una selección que ya no existe es la misma trampa.

### El componente identifica la fila, no la tarifa (25/08/2026)

Una tarifa —«Adulto Extranjero», «Americana Royal Class»— se repite en media docena de filas del
mismo día y **no dice qué se compró**. Lo que lo dice es el componente: si eso es un ticket o un
guiado. La regla vale en **todas** las vistas que listan servicios operativos, y se aplicaba sólo
en la fila del cuadro:

| Dónde | Antes | Ahora |
|---|---|---|
| Fila de La Biblia | componente + tarifa | igual |
| Modal «Generar Orden de Servicio» | servicio + tarifa | **componente + icono de tipo**, tarifa debajo |
| Detalle de una orden abierta | nombre para el proveedor + tarifa | **componente + icono**, y debajo lo demás |
| Bitácora de estados | tarifa | **componente** · tarifa |
| Ficha móvil | ya llevaba componente | igual |

⚠️ **El nombre del componente puede venir `null`, y el respaldo NO es la tarifa: es la etiqueta
del tipo.** `nombreComponenteDeServicio()` resuelve contra el lote de maestros armado con las
**filas del cuadro**; en el detalle de una orden los servicios vienen con la orden, así que ese
lote puede no tenerlos. `tipoComponente` viaja denormalizado en el snapshot, está siempre, y
responde justamente la pregunta cara —ticket o guiado— sin depender del catálogo. Por eso el
icono de tipo se pinta **siempre**, aunque el nombre falte.

⚠️ Y para los componentes **manuales** el nombre sale de `nombreInternoSnapshot`, que se publicó
en `operacion:item:read` el 25/08/2026 justo para esto: no tienen maestro al que preguntarle y se
quedaban rotulados con la tarifa.

**Agrupación:** por día. Dentro del día manda la hora; los servicios sin hora se empujan al final
ordenados por `prioridadOperativa` (guiado/transporte antes que tickets), porque un cuadro de
tráfico se lee de arriba abajo y lo que no tiene hora estorba en medio.

**Edición en línea:** hora, prestador, proveedor comercial, estado de reserva y estado de
operación se editan en la propia fila (`PATCH` por campo). No tocan la cotización: registran la
realidad operativa.

### 7.1 El botón «Revisar cambios de operación»

Las dos apps siguen sin conocerse (§2): el vínculo nuevo es un endpoint, no un import.

| Dónde | Qué se ve | Condición |
|---|---|---|
| `CotizacionEditorView.vue`, junto al selector de estado | Botón ancho «Revisar cambios de operación» | `estado === 'confirmado'` y no es catálogo |
| `FileDetalle.vue`, en la fila de acciones de cada versión | Icono de diff | `cot.estado === 'confirmado'` |

Está en los dos sitios a propósito: el editor es donde se confirma, y el detalle del expediente
es donde se ve **qué versión** está confirmada entre todas. Los dos abren el mismo componente,
`util/src/components/operacion/PlanOperacionModal.vue`, que llama a
`fileStore.planificarOperacion()` y `fileStore.aplicarPlanOperacion()`.

Ya no hace falta advertir de nada antes de abrir: el panel **enseña** lo que va a cambiar, campo
a campo, y no aplica nada que no se marque (§3.5). El único `confirm()` que queda es el de los
borrados.

La condición del frontend es comodidad, no seguridad: el procesador vuelve a validar el estado
y responde 422. Y se envía **lo que hay en la base de datos**, no lo que se ve en pantalla: si
el editor tiene cambios sin guardar, hay que guardar primero. El texto de ayuda bajo el botón
lo dice.

**Permisos:** la operación acepta `ROLE_RESERVAS_WRITE` **o** `ROLE_OPERACIONES_WRITE`. Quien
vende es quien confirma, y exigirle sólo el rol de operaciones habría dejado el botón inservible
para el perfil que más lo va a usar.

### 7.2 La columna «Prestador»

Es la que responde a *dónde recojo y quién opera*, así que manda sobre el proveedor comercial
(§3.3.b). La celda tiene tres niveles:

| Nivel | Qué | Cuándo |
|---|---|---|
| Principal, editable | `prestadorNombre` | siempre |
| Teléfono (enlace `tel:`) y dirección | `prestadorTelefono`, `prestadorDireccion` | si el snapshot los trae |
| «Compra», editable y atenuado | `compradorNombre` | salvo que sea **redundante** (no vacío e idéntico al prestador) o la fila sea referencia |

Tres decisiones que conviene no deshacer:

- **El comercial se oculta sólo cuando es redundante**, no cuando está vacío. La primera versión
  de `mostrarComercial()` exigía además que no estuviera vacío, y como los dos campos salen de la
  misma tarifa, cuando esa tarifa no tiene proveedor **los dos nacen nulos**: el input no se
  pintaba nunca y el campo quedaba fuera del alcance del operador. Justo el caso mayoritario
  —34 de 42 filas en una cotización real— y justo el que hace falta para agrupar una OS.
  **Vacío no es redundante, es pendiente.**
- **Pero sigue siendo editable cuando aparece.** `conflictoSeleccion` agrupa la OS por
  `compradorNombre`: sin poder corregirlo aquí, dos filas del mismo comprador escrito de
  dos maneras no se podrían juntar nunca en una misma Orden de Servicio.
- **En una fila de referencia no se muestra**, porque no se compra: ver §3.3.

El teléfono se repite en la línea compacta de móvil, donde la columna está oculta. Es
deliberado: es justo el dato que se consulta a pie de calle.

**Generar OS:** selección múltiple → valida expediente/proveedor/moneda → modal → crea la cabecera
y asocia los servicios. Conecta las dos pestañas, que antes estaban desconectadas.

**Filas de referencia (§3.3):** en vez de checkbox llevan un icono de ojo y un badge
«Referencia», y el campo de proveedor cambia el placeholder de «Por asignar» a «Referencia» —
no hay nada que asignar. Se ven a plena opacidad: son el dato del recojo.

**Bitácora:** el botón «Mensajes» de una OS abre el hilo y permite registrar un envío nuevo.

⚠️ **Aviso de truncado.** La colección se pide con `itemsPerPage: 200` y la vista **no
pagina**. Un rango amplio con más servicios recortaba el cuadro en silencio: el operador
leía el día creyendo que estaba entero. Ahora el store guarda `totalServicios`
(`hydra:totalItems`) y la cabecera pinta «N sin mostrar» cuando falta algo.

**Estado vacío:** enumera las dos causas reales (rango corto, cotización sin confirmar) porque
ninguna se ve desde esta pantalla. Sin ese texto, «no hay filas» se lee como «el panel está roto».


### 7.3 La columna «Costo»: cotizado contra real

Sólo desde `xl:`. Arriba el `costoCotizado` en gris (lo que decía la cotización), debajo el
**`costoRealOperativo` editable** (lo que el proveedor acabó cobrando), y el delta en rojo si
costó de más, en verde si de menos. Ese delta **es** el margen operativo por servicio.

Dos decisiones deliberadas:

- **La moneda no se edita en la celda.** `monedaReal` nace igual a `monedaCotizada` y ahí se
  queda. Cambiarla desde un input de tabla, sin conversión ni tipo de cambio, mezclaría divisas
  en la misma columna y las sumas dejarían de significar nada — el mismo error que cometí al
  analizar una cotización real sumando USD y PEN en una sola cifra. Si hay que cambiarla, es un
  caso raro que merece su propio flujo.
- **Las filas de referencia no ofrecen el campo** (§3.3): no se compran, así que no hay costo
  real que registrar, y dar el input invitaría a inventar una cifra.

⚠️ `costoRealOperativo` es del operador y **la reconciliación no lo toca nunca** (§3.5). Es el
único sitio donde vive lo que se pagó de verdad: si un merge automático lo pisara, ese dato no se
podría recuperar de ninguna otra parte.

---

## 8. Gotchas

### Qué se puede editar en cada estado (26/08/2026)

| | Borrador | Emitida | Confirmada | Completada | Cancelada |
|---|---|---|---|---|---|
| Nº de OS | 🔒 | 🔒 | 🔒 | 🔒 | 🔒 |
| Destinatario | ✏️ | 🔒 | 🔒 | 🔒 | 🔒 |
| Quitar o añadir líneas | ✏️ | 🔒 | 🔒 | 🔒 | 🔒 |
| Confirmar hora de recojo | 🔒 | ✏️ | ✏️ | 🔒 | 🔒 |
| Qué extremos ve el proveedor | ✏️ | ✏️ | ✏️ | 🔒 | 🔒 |
| Pagos | ✏️ | ✏️ | ✏️ | ✏️ | 🔒 |
| Bitácora | ✏️ | ✏️ | ✏️ | ✏️ | ✏️ |

**El porqué de cada línea, que es lo que no se ve leyendo la tabla:**

- **El número NUNCA se edita, ni en borrador.** Es la referencia con la que el proveedor contesta
  —«confirmo la OS-…»— y con la que archiva en su lado. Cambiarlo deja al otro hablando de un
  número que ya no existe, y ni el chat ni el correo que él tiene se enteran.
- **El destinatario sólo mientras se compone.** Cambiarlo después de emitir **no es editar, es otra
  orden**: quien la recibió seguiría creyendo que es suya. Para eso está reemitir, que anula la
  primera y avisa.
- **Las líneas, igual.** Quitar una de una orden emitida le cambia el encargo por detrás.
- **La hora confirmada NO es una modificación**, es completar el documento con lo que el proveedor
  respondió — por eso se aplica sin reemitir (`aplicar-menores`). En **completada** se cierra: el
  servicio ya se operó y confirmar su hora a toro pasado no completa nada, reescribe historia.
- **Las rutas visibles son presentación, no pacto**: ocultar un renglón dice menos, no dice algo
  falso.
- **A un proveedor se le puede pagar tarde**, así que los pagos siguen abiertos en completada. En
  anulada no: no se le paga lo que se le retiró.
- **La bitácora siempre.** Es el registro de lo que pasó, y lo que pasó no deja de pasar.

**Dónde vive la regla:**

- **Las guardas de verdad**, en los setters de la entidad (`setNumeroOs`,
  `exigirCabeceraEditable()`) y en `OperacionServicio::setOrdenServicio()`. ⚠️ Tienen que estar ahí
  y no en el formulario: el `PATCH` está abierto a cualquier consumidor de la API —otra pestaña, un
  script—, y hasta hoy **no lo sabía nadie**: se podía cambiar el destinatario de una orden emitida.
- **El mapa para la pantalla**, en `getEdicionPermitida()`, expuesto en los grupos de lectura. La
  vista lo lee y no reescribe la regla; si el campo no viene, **falla bloqueando**.

⚠️ **Las guardas bloquean CAMBIOS, no asignaciones.** Los setters se llaman con el valor que ya
está cada vez que se denormaliza un `PATCH`, así que guardar una orden emitida sin tocar nada
habría reventado con un 422 que no describía nada — y un guardado que falla sin haber cambiado nada
enseña a desconfiar del botón, no de la regla.

⚠️ **`anular()` marca el estado ANTES de soltar las filas.** Sacar una fila sólo se permite en
borrador o en anulada; soltándolas antes de marcarla, la orden seguiría emitida y la guarda
tumbaría la propia anulación.

⚠️ **El mapa y la guarda no coinciden en anulada, a propósito.** El mapa dice que no se tocan las
líneas —no es un gesto que se le ofrezca a nadie— mientras la guarda sí lo permite, porque es lo
que `anular()` necesita para vaciarla. El mapa describe lo que la pantalla puede ofrecer; la
guarda, lo que el dominio tolera.

**En la pantalla, lo bloqueado se ENSEÑA con su motivo**, no se esconde: un campo que desaparece se
lee como un fallo, uno deshabilitado que dice por qué enseña la regla. Y el diálogo lista además lo
que sí se puede hacer **y dónde** —las líneas en La Biblia, la hora en la ficha, los pagos en su
botón—, porque sin eso «no puedo cambiarlo aquí» se confunde con «no se puede cambiar» y alguien
acaba anulando una orden para hacer algo que sí podía. Cuando no queda nada editable, **el botón
«Guardar» desaparece**: el diálogo es entonces una ficha de consulta, y así se comporta.

**«Órdenes vigentes» enseñaba las canceladas.** El rótulo mentía y, peor, una anulada se leía
igual de vigente que las demás a la velocidad a la que se repasa una lista. Una orden anulada **no
se borra** —es el rastro de lo que se le mandó a alguien—, así que la respuesta no es quitarla sino
no enseñarla salvo que se pida.

Ahora hay un chip por estado con su contador, y **las canceladas empiezan apagadas**. El rótulo
dice lo que se está viendo: «Órdenes vigentes» o «Órdenes» a secas si se encienden.

⚠️ Se guardan los estados **visibles**, no los ocultos: el día que se añada un estado al enum,
aparece solo. Con una lista de excluidos, un estado nuevo se colaría sin que nadie lo decidiera.

⚠️ **Se avisa siempre de cuántas quedan escondidas**, y el vacío por filtro es **distinto** del
vacío de verdad: «no hay ninguna» se arregla generando una, «las hay pero el filtro las esconde»
se arregla tocando un chip. Confundirlos manda a alguien a crear una orden que ya tiene. Es el
mismo principio que el bloque de La Biblia para los filtros de la vista.

**Para los grupos de WhatsApp se copia y se pega, y no es un apaño.** El modal de envío lleva un
botón **Copiar** que se lleva el cuerpo **más el enlace**, con el formato de WhatsApp ya puesto.

⚠️ **La pregunta que hay que hacerse no es «¿puedo mandar a un grupo?» sino «¿puede mi número
entrar en el grupo?». Y la respuesta es NO: la dirección de la Groups API está invertida.**

Repasada la lista entera de endpoints el 26/08/2026, la API sólo opera sobre grupos que **ella
misma creó**:

| Lo que existe | Lo que NO existe |
|---|---|
| `POST /<PHONE_ID>/groups` — crear grupo y generar su enlace | ningún `join` ni `accept_invite` |
| `GET/POST /<GROUP_ID>/invite_link` | entrar en un grupo ajeno, ni con el enlace en la mano |
| `GET/POST/DELETE /<GROUP_ID>/join_requests` — aprobar quién entra | |
| `DELETE /<GROUP_ID>/participants` — **sacar** gente | ninguno para **meter** gente |
| `GET /<PHONE_ID>/groups` — listar los **propios** | ver grupos que no creó |

Y un segundo candado: **los grupos no están disponibles para números de la app de WhatsApp
Business**, que suele ser justo el número que ya está metido en los grupos con proveedores. Un
número no puede estar a la vez en la app y en la Cloud API: migrarlo lo saca de la app.

Los otros límites, por si algún día se evalúa: **máximo 8 participantes**, requiere **Official
Business Account**, y **cada mensaje se factura por destinatario** —un grupo de seis cuesta como
seis mensajes—.

**Conclusión: los grupos que ya existen no se migran, se sustituirían.** Tendría que crearlos el
número de API e invitar a cada proveedor a entrar. Para lo que ya está montado, el camino es pegar
a mano — y lo que hay que cuidar es que el texto copiado sea **idéntico** al que manda la API:
`textoParaWhatsapp` es espejo de `OperacionOrdenEnvio::enviar()`, que compone
`cuerpo + "\n\n" + enlace`. Si allá cambia la forma de pegarlos, aquí también — o el proveedor del
grupo recibe una versión y el del chat otra.

⚠️ **No se le añaden marcas al copiar.** El cuerpo ya viene con `*negrita*` y emoji desde PHP; un
segundo juego encima del primero es lo que convierte el mensaje en una ristra de asteriscos.

⚠️ El copiado va en **tres intentos**: `navigator.clipboard` → `execCommand('copy')` → y si las dos
fallan, se selecciona el bloque para copiarlo con el teclado. La API moderna se niega en más casos
de los que uno espera —sin foco en el documento, sin gesto del usuario, sin contexto seguro— y
falla **lanzando**, no devolviendo `false`. Un botón que a veces copia es peor que no tenerlo: el
operador cree que lo lleva y pega lo que hubiera antes, que puede ser el mensaje de otro proveedor.

**La información operativa —el vuelo— no llegaba al proveedor.** «Delta LATAM LA-2695 Aterriza
22:00» vive en `notasPrestador`, y es el dato con el que un chófer decide a qué hora sale de casa.
Se veía en La Biblia y en la página pública, pero faltaba en **los dos sitios que importan**:

- **El mensaje** (`OperacionOrdenDocumento`) no lo incluía en absoluto. O sea que por WhatsApp o
  correo —que es por donde el proveedor lo recibe de verdad— se le pedía recoger a alguien en un
  aeropuerto **sin decirle el vuelo**.
- **El panel del borrador** tampoco: se veía en el cuadro y desaparecía justo en la pantalla desde
  la que se manda, así que no había forma de repasar antes de emitir lo que él va a recibir.

Y detrás del segundo, otra vez el mismo agujero: `getNotasPrestadorEfectivas()` estaba sólo en
`operacion:item:read`. Ver más abajo la regla del grupo `operacion:read`.

⚠️ En un **borrador** las notas se leen EN VIVO (`…Efectivas`); al emitir se congelan en el ítem
(`notasPrestador`). Son dos campos distintos a propósito: lo que el proveedor tiene en la mano es
lo congelado, y si cambia, la orden lo denuncia y hay que reemitir.

**El mensaje abre con un saludo y cierra presentando el enlace.** Un texto que empieza con
«*Orden de Servicio OS-…*» y termina con una URL suelta se lee como un volcado de sistema: al otro
lado hay una persona y esto es una petición de trabajo, no un ticket. Una URL sin presentar se lee
como firma automática y no se pulsa.

```
Estimado equipo de Futurismo:

*Orden de Servicio OS-20260825-159*
…
Por favor confirmar recepción y disponibilidad.

Puede consultar la orden de servicio en el siguiente enlace:
https://pax.openperu.pe/orden/…
```

Se saluda con la **razón social** del catálogo —es como se llama la empresa en lo que se firma y se
factura— con cascada a `compradorNombre` si no la tiene, y a «nuestro proveedor» si el destinatario
no está en el catálogo. Se lee **en vivo**: si la empresa cambió de razón social, se la saluda como
se llama hoy. Lo congelado es el contenido de la orden, que es lo pactado; el saludo no lo es.

⚠️ **El enlace se compone DENTRO del cuerpo, y eso mató un espejo.** Antes lo pegaba
`OperacionOrdenEnvio::enviar()` **y** lo repetía por su cuenta el botón «Copiar» del front: dos
sitios armando el mismo texto, o sea que el proveedor de un grupo podía recibir una versión y el
del chat otra. Ahora `OperacionOrdenDocumento::para($orden, $enlace)` lo compone una vez; `enviar()`
usa `cuerpo` tal cual y el front copia `cuerpo` tal cual. **Si hay que añadirle algo al mensaje, va
en PHP.**

⚠️ Un **borrador no tiene enlace** —la llave se sella al emitir—, así que el bloque no sale y el
texto termina limpio en «Por favor confirmar…». Nada de rótulos colgando sin URL.

**El formato del documento es distinto en cada medio, y no por capricho.** La misma orden sale por
tres sitios y cada uno aguanta cosas distintas:

| Superficie | Qué admite | Qué se usa |
|---|---|---|
| Mensaje al proveedor (WhatsApp / correo) | Texto plano | `*negrita*` de WhatsApp en el encabezado de día, y **emoji** 🕐 📍 |
| Página pública | HTML de navegador | Color, negrita, espaciado |
| PDF | **Dompdf**, que es CSS 2.1 | Color, negrita, espaciado — **nada más** |

⚠️ **En el PDF no van ni emoji ni iconos web.** Dompdf no lleva fuente de color, así que un emoji
sale como un cuadro vacío, y Font Awesome no existe salvo que se embeba la fuente. La «marca» de
cada día es una **barra de color a la izquierda**, que es sólo un `border-left`: se ve igual en el
navegador y en el PDF. Tampoco hay flex ni grid — el documento es una `<table>` a propósito.

**Las dos van agrupadas por DÍA**, con el día encabezando sus líneas en vez de repetir la fecha en
cada renglón. El proveedor cuadra su semana por jornadas —«el miércoles tengo tres»—, y con la
fecha repetida hay que leer los cinco renglones para saber cuántos días son. En la tabla eso
además libera una columna entera, que en el PDF hace falta para la dirección del recojo.

⚠️ La etiqueta del día (`Mié 2 sep`) la compone **`OperacionOrdenServicioItem::getEtiquetaDia()`**,
y las tres superficies leen de ahí. Con el mapa de nombres copiado en Twig, corregir «mié»
arreglaría una y dejaría las otras como estaban. Los nombres van a mano y no con
`IntlDateFormatter`, por lo mismo que ya decidió `PmsFrentes`.

**Las líneas de una orden van ordenadas por FECHA, y eso se declara en el mapeo.** Una orden se
compone marcando filas del cuadro a saltos —primero el traslado que uno recuerda, luego el del día
anterior—, así que el orden natural de la colección es el de marcado. Salía `31/08, 02/09, 04/09,
02/09, 02/09`: nadie puede seguir un itinerario que va y viene, y es la lista con la que el
proveedor organiza su semana.

`#[ORM\OrderBy]` en **las dos** colecciones de `OperacionOrdenServicio`:

| Colección | Orden | Quién la consume |
|---|---|---|
| `$operacionServicios` (viva) | `fechaServicio`, `horaRecojo` | El panel de un borrador — y `OperacionOrdenEmision::emitir()`, que construye los ítems recorriéndola, así que el documento **nace** ordenado |
| `$items` (congelada) | `fechaServicio`, `hora` | Las órdenes ya emitidas y `OperacionOrdenDocumento`, que arma el texto que se manda al proveedor |

⚠️ Se ordena **en el mapeo y no al pintar**: hay tres superficies —panel, documento y PDF— y
ordenar en una sola las deja discrepando entre sí. Y ordenar la colección viva no basta: el
documento del proveedor lee la congelada.

⚠️ **El mapeo tampoco basta por sí solo, y ése fue el fallo de verdad.** `#[ORM\OrderBy]` sólo
actúa al CARGAR la colección de la base; si los ítems se acaban de crear en la misma petición
—previsualizar justo después de emitir— la colección está en memoria y sale en orden de inserción.
Por eso el orden vive en `getItemsOrdenados()`, y las tres superficies leen de ahí.

Era `private` y sólo lo usaba `getRutasVisibles()`. El resultado era peor que un desorden: **las
rutas se decidían sobre la lista ordenada y las líneas se imprimían sobre la cruda**. Además de
las fechas a saltos, la regla de «el recojo se enseña una vez al día salvo que cambie» se aplicaba
a unas líneas y se comía el «Recoge en…» de otras — en el documento que ve el proveedor. Dos
listas distintas para el mismo documento no pueden coincidir.

Las órdenes ya emitidas también salen ordenadas, y no rompe nada: el orden es de lectura, no está
congelado en el contenido, así que no reescribe nada de lo pactado.

**Un campo sin el grupo `operacion:read` desaparece del ANIDADO, y el front cae al respaldo sin
decir nada.** El panel de una Orden de Servicio pintaba todas sus líneas como «Sin tipo» con el
icono de interrogación. La causa: `OperacionServicio::$tipoComponente` sólo estaba en
`operacion:item:read`, y los servicios **anidados dentro de una orden** se serializan con
`operacion:read`. Llegaba `undefined`, y `getTipoComponenteConfig()` tiene un respaldo para
valores desconocidos, así que no había error ni hueco — sólo un cuadro diciendo que no sabía qué
era cada cosa.

Lo que lo hacía difícil de ver: **en La Biblia se veía bien**, porque esa colección usa
`operacion:item:read`. El mismo campo, la misma entidad y el mismo componente de UI, bien en una
pantalla y mal en la otra.

⚠️ La regla al añadir o arreglar un campo que el front lea **dentro de una orden**: comprobar que
esté en `operacion:read`, no sólo en `operacion:item:read`. Y regenerar `api.d.ts` — que tampoco
avisa, porque un campo que falta se lee como `undefined`, no como error de tipos.

**Una acción que cambia DOS registros tiene que sincronizar los dos.** Reemitir anula la orden
anterior y crea la sucesora **en la misma transacción**, pero `emitirOrdenServicio()` sólo hacía
`unshift` de la sucesora: la copia local de la anulada seguía diciendo «confirmada». El operador
veía como vigente la orden que acababa de anular, y sólo al volver a entrar aparecía cancelada
—que fue como se detectó—. Un estado que **se inventa el navegador** es de lo peor que puede pasar
en esta pantalla: mirando eso se decide si hay que llamar al proveedor.

No hace falta pedir nada: la respuesta trae la anterior ya anulada en `reemplazaA`, porque es la
misma entidad gestionada, serializada después del `flush`. La regla general: **si el servidor tocó
más de un registro, la respuesta dice cómo quedaron todos — úsala, o vuelve a preguntar; lo que no
vale es dejar el estado viejo en pantalla.**

**Los iconos de Font Awesome necesitan el prefijo de familia.** Las configs guardan
`icon: 'fa-check'`, así que la plantilla tiene que escribir `class="fas"` además del icono. Si se
pasa sólo `cfg.icon`, el `<i>` no renderiza nada; combinado con un label oculto por breakpoint
(`hidden sm:inline`) el resultado es una píldora vacía sin ningún error en consola.

**No uses inputs nativos de fecha.** Regla de `docs/UI_Componentes_Compartidos.md`: todo campo de
fecha usa `FechaHoraPicker`. Emite `"YYYY-MM-DDTHH:mm"`; la API quiere `"YYYY-MM-DD"`, y el
recorte se hace con `slice(0, 10)`, **nunca con `Date`**, para no aplicarle zona horaria a una
fecha de servicio. La hora de recojo se edita con un input de texto validado contra `HH:MM` por el
mismo motivo: `<input type="time">` cambia a AM/PM según el idioma del sistema.

**Multivalor en axios: no pongas `paramsSerializer`.** Las claves ya llevan los corchetes
(`tipoComponente[]`) y el serializador por defecto las repite tal cual. Con `indexes: null` axios
**borra los corchetes**, PHP se queda sólo con el último valor y el filtro pasa de N tipos a uno,
sin ningún síntoma visible.

**Comparar entidades con id `Uuid` en DQL no funciona.** `WHERE cs.cotizacion = :cotizacion`
devuelve **0 filas en silencio**: el id es binario y la comparación no lo convierte. Usa
`findBy(['cotizacionServicio' => $cotservicios])`, que pasa por el persister y aplica los tipos.
Este fallo tenía roto `propagarEstadoOperacion()`: cancelar una cotización no propagaba nada.

**El lector de anotaciones de Doctrine sigue activo.** Escribir una arroba seguida de una palabra
(por ejemplo el identificador de JSON-LD) dentro de un docblock de entidad revienta el arranque
con `annotation "@…" was never imported`. En los docblocks de entidades, esa arroba se describe con
palabras.

**`api.d.ts` se regenera a mano.** No hay script en `package.json`:

```bash
php bin/console api:openapi:export > /tmp/openapi.json
cd util && npx openapi-typescript /tmp/openapi.json -o src/types/api.d.ts
```

Estaba desactualizado respecto al backend, así que regenerarlo puede sacar a la luz referencias a
schemas que ya no existen en otros módulos. Es señal de deuda, no de que el cambio esté mal.

---

## 9. Pendiente de decidir

**Qué componentes deberían generar fila.** Hoy entra todo, y una parte del debate ya está
resuelta: `no_incluido` **sí entra**, como referencia (§3.3). No es cuestión de ruido — es lo que
el transportista necesita para el recojo. Filtrarlo por modo, como hace el editor en
`cotizacionEditorStore.ts`, sería replicar una decisión comercial en un contexto operativo donde
significa lo contrario.

Queda por decidir:

- **por tipo**, si alojamiento y alimentación son tráfico o sólo costo. `ComponenteTipoEnum` ya
  distingue lo despachable (`transporte`, `guiado`, `pool`, `privada`, `tren`, `vuelo`, tickets)
  de lo demás, y `prioridad()` existe justamente para "manifiestos y reportes operativos";
- **`reemplazado`**: un componente sustituido no se opera, y desde que la tarifa dejó de ser
  requisito (§3.3) los que no la tenían empezaron a generar fila. Hoy aparecen atenuados y con
  badge, así que se ven como lo que son, pero son candidatos claros a dejar de generarse;
- **`estadoComponente = cancelado`**, por el mismo motivo.

Mientras no se decida, los tres campos viajan en el snapshot y la vista los muestra y filtra.
Cuando se decida: tocar `generarParaCotizacion()` y correr `operacion:resincronizar --todas`.

**Sin generador de `numeroOs`.** La vista propone un número y el operador lo confirma; dos
usuarios simultáneos pueden chocar contra el índice `unique`.

**`montoVenta` no se rellena.** `costoRealOperativo` ya se edita en el cuadro (§7.3), pero su
pareja de venta sigue sin escribirse: el margen por servicio se puede calcular contra el costo
cotizado, no contra la venta real.

---

## 10. Dónde tocar para cambiar X

| Necesito… | Archivo | Símbolo |
|---|---|---|
| **Armar el cuadro de operación** | `src/Cotizacion/ApiPlatform/State/GenerarOperacionProcessor.php` | `process()` — botón «Armar la operación». ⚠️ **NO** lo hace confirmar (§2.bis) |
| Que re-confirmar devuelva las filas canceladas | `src/Operacion/EventListener/CotizacionConfirmadaEventListener.php` | `reactivar()` — reactiva, no genera |
| **Añadir un medio de pago a proveedor** | `src/Operacion/Enum/OperacionMedioPago.php` | un `case` + su `label()` e `icono()`; el panel lo recoge solo |
| Cambiar en qué monedas se puede pagar una orden | `src/Operacion/Entity/OperacionOrdenServicio.php` | `monedasDeLosServicios()` — lo comprueba `OperacionPago::validarMonedaDeLaOrden()` |
| Cambiar cuándo una orden cuenta como saldada | `src/Operacion/Entity/OperacionOrdenServicio.php` | `isSaldada()` — derivado, **no** es un estado de `estadoOs` |
| Cambiar cómo se filtra por centro turístico | `src/Operacion/Filter/OperacionServicioLugarExtension.php` | `applyToCollection()` |
| Cambiar qué recoge el chip «Sin etiqueta» | `src/Operacion/Filter/OperacionServicioLugarExtension.php` | `SIN_LUGAR` |
| Cambiar de dónde salen las ubicaciones de un componente manual | `src/Cotizacion/Entity/CotizacionCotcomponente.php` | `$lugaresManuales` — y el filtro de arriba, que las cruza |
| Cambiar cómo se rotula un servicio en cualquier lista | `util/src/stores/operacion/operacionStore.ts` | `nombreComponenteDeServicio()` — el respaldo es el tipo, nunca la tarifa |
| Cambiar qué se edita sin abrir la ficha | `util/src/views/Operacion/OperacionView.vue` | la `<article>` de la rejilla; lo demás va al formulario |
| Cambiar cómo filtra el chip de organización | `util/src/views/Operacion/OperacionView.vue` | `coincideOrganizacion()` — los dos papeles en OR, sobre el efectivo |
| Cambiar a qué órdenes se puede agregar | `util/src/views/Operacion/OperacionView.vue` | `ordenesParaAgregar` — y `fetchBorradoresDeFile()` en el store, que es quien las trae |
| Cambiar cuándo se refrescan los borradores de «Agregar a OS» | `util/src/stores/operacion/operacionStore.ts` | `fileDeBorradores = null` en emitir / agregar / cambiar estado / eliminar |
| Filtrar la colección de órdenes por algo nuevo | `src/Operacion/Entity/OperacionOrdenServicio.php` | `SearchFilter` si es texto, `UuidRelacionFilter` si es relación — y regenerar `api.d.ts` |
| **Filtrar por una relación en cualquier recurso** | `src/Api/Filter/UuidRelacionFilter.php` | declararla ahí, **nunca** en `SearchFilter`: con uuid binario devuelve cero en silencio |
| Cambiar qué organización se marca como capaz de recibir órdenes | `util/src/views/Operacion/OperacionView.vue` | `organizacionesDelCuadro` (`recibeOrdenes`) — y `esComprable()`, espejo del PHP |
| Cambiar qué se puede meter en una Orden de Servicio | `src/Operacion/Entity/OperacionServicio.php` **y** `util/src/views/Operacion/OperacionView.vue` | `esComprable()` (espejo, se tocan los dos) |
| **Añadir o quitar un eje de filtro** (§7.0) | `util/src/views/Operacion/OperacionView.vue` | `GrupoFiltro` + `gruposVisibles` + `conteoPorGrupo` — **los tres**: sin contador, el eje filtra en silencio |
| Cambiar cuándo se esconde un eje | mismo archivo | `gruposVisibles` — y el `watch` que cierra el eje que desaparece |
| Cambiar qué vacía «Limpiar» | mismo archivo | `limpiarFiltros()` — incluidos los que filtran en local (`filtroOs`, organización) |
| Cambiar los atajos de fecha | mismo archivo | `aplicarPreset()` — el rango de arranque está aparte, en los `ref` de `desde`/`hasta` |
| Cambiar qué componentes llevan hora | `src/Travel/Enum/ComponenteTipoEnum.php` | `sinHorario()` — el front lo consulta por el flag, no lo replica |
| Cambiar qué dispara la generación | `src/Operacion/EventListener/CotizacionConfirmadaEventListener.php` | `onFlush()` (el `match` de estados) |
| Cambiar qué se copia al snapshot | `src/Operacion/Service/BibliaSnapshotService.php` | `generarParaCotizacion()` |
| **De dónde sale el expediente de la fila** (§3.7) | mismo archivo | `resolverFile()` — y la guarda de raíz en `Cotizacion::setFile()` |
| **Filtrar qué componentes entran** (§9) | mismo archivo | `generarParaCotizacion()`, guard del bucle |
| **Cambiar qué fila es "sólo referencia"** (§3.3) | `src/Operacion/Entity/OperacionServicio.php` | `isSoloReferencia()` — y regenerar `api.d.ts` |
| **Cambiar el teléfono/dirección que ve el tráfico** (§3.8) | `util/src/stores/operacion/operacionStore.ts` | `resolverContactoDeProveedores()` / `contactoDePrestador()` — **no** el snapshot: llega nulo a propósito |
| Cambiar de dónde sale el prestador (§3.3.b) | `src/Cotizacion/Entity/CotizacionCotcomponente.php` **y** `util/src/stores/cotizacion/cotizacionEditorStore.ts` | `resolverPrestador()` (espejo, se tocan los dos) |
| Qué puede entrar en una Orden de Servicio | mismo archivo | `esComprable()` / `motivoNoComprable()`, y las DOS guardas: `setOrdenServicio()` + `verificarCoherenciaConOrdenServicio()` |
| Qué pasa al salir o entrar en `confirmado` (§4) | `src/Operacion/EventListener/CotizacionConfirmadaEventListener.php` | `confirmar()`, `propagarEstadoOperacion()` |
| Qué impide borrar una fila sobrante | `src/Operacion/Service/BibliaReconciliacionService.php` | `motivoBloqueoBorrado()` |
| Comportamiento al borrar de la cotización (§3.6) | `src/Operacion/Entity/OperacionServicio.php` | los `onDelete` de los `JoinColumn` |
| Cambiar el texto de la fila | mismo archivo | `resolverDescripcion()` |
| Cambiar qué tarifa manda cuando hay varias | mismo archivo | `resolverTarifaPrimaria()` |
| Cambiar cómo se ubica la fila en el calendario | mismo archivo | `resolverFechaServicio()`, `resolverHoraRecojo()` |
| **Cambiar el borrado+regeneración** (las dos entradas) | `src/Operacion/Service/BibliaSnapshotService.php` | `resincronizarCotizacion()` |
| Aplicar cambios de reglas a lo ya confirmado (consola) | `src/Operacion/Command/OperacionResincronizarCommand.php` | `operacion:resincronizar` |
| Cambiar las guardas del plan (estado, catálogo) o su mensaje | `src/Operacion/ApiPlatform/State/PlanificarOperacionProcessor.php` | `process()` |
| Añadir un dato al plan o al resultado | `src/Operacion/ApiPlatform/Dto/` **y** `util/src/types/operacionModel.ts` | `PlanReconciliacion` / `CambioPropuesto` / `CampoPropuesto` / `ResultadoAplicacion` (espejos) |
| Cambiar el panel de revisión (diff, casillas, avisos) | `util/src/components/operacion/PlanOperacionModal.vue` | `marcadosPorDefecto()`, `alternarCampo()`, `aplicar()` |
| Cambiar dónde aparece el botón | `util/src/views/Cotizaciones/CotizacionEditorView.vue`, `util/src/views/Cotizaciones/FileDetalle.vue` | `abrirPlanOperacion()` |
| Cambiar la llamada HTTP del panel | `util/src/stores/cotizacion/fileStore.ts` | `planificarOperacion()`, `aplicarPlanOperacion()` |
| **Cambiar qué se propone y quién lo autoriza** (§3.5) | `src/Operacion/Service/BibliaReconciliacionService.php` | `planificar()`, `aplicar()`, `compararCampos()` |
| Cambiar el marcado por defecto de una casilla | mismo archivo **y** `PlanOperacionModal.vue` | `aprobadoPorDefecto` / `marcadosPorDefecto()` (espejo) |
| Añadir un campo gobernado por la cotización | `BibliaSnapshotService::calcularValores()` + `aplicarValores()` + `ETIQUETAS` del reconciliador | los tres, o el campo no se ve en el diff |
| Cambiar qué campos son intocables | `BibliaReconciliacionService` | la lista del docblock de `ETIQUETAS` |
| Cambiar el rango de fechas de arranque del panel | `util/src/views/Operacion/OperacionView.vue` | refs `desde` / `hasta` |
| Que cancelar borre filas en vez de marcarlas | `CotizacionConfirmadaEventListener.php` | `propagarEstadoOperacion()` |
| Añadir un campo operativo (chofer, placa, nota) | `src/Operacion/Entity/OperacionServicio.php` | nueva propiedad + grupos + migración |
| Añadir un dato de la cotización a La Biblia | `BibliaSnapshotService.php` + entidad | denormalizar en el snapshot, no abrir grupos |
| Añadir o cambiar filtros de la colección | `src/Operacion/Entity/OperacionServicio.php` | bloque `#[ApiFilter]` |
| **Que un módulo nuevo tenga filtros** | `config/packages/api_platform.yaml` | `mapping.paths` (§6) |
| Cambiar el orden por defecto | `src/Operacion/Entity/OperacionServicio.php` | `order:` del `#[ApiResource]` |
| Cambiar prioridad de despacho | `src/Travel/Enum/ComponenteTipoEnum.php` | `prioridad()` |
| Añadir un estado | `src/Operacion/Enum/*.php` **y** `util/src/types/operacionModel.ts` | enum + `Record` (espejo, §4) |
| Cambiar permisos | `src/Security/Roles.php` | `OPERACIONES_SHOW` / `_WRITE` / `_DELETE` |
| Nombres de los parámetros de query | `util/src/types/operacionModel.ts` | `construirParamsBiblia()` |
| Llamadas HTTP del módulo | `util/src/stores/operacion/operacionStore.ts` | `fetchServicios()`, `buscarExpedientes()`, `fetchCotizacionesDeExpediente()`, `actualizarServicio()`, `crearOrdenServicio()`, `fetchMensajesPorOrden()`, `registrarMensaje()` |
| Filtros, agrupación y edición en línea | `util/src/views/Operacion/OperacionView.vue` | `filtrosActivos`, `serviciosPorDia`, `guardarCampo()`, `confirmarOs()` |
| Qué muestra la columna Prestador (§7.2) | mismo archivo | `editarPrestador()`, `mostrarComercial()`, `telHref()` |
| Cómo se registra el costo real (§7.3) | mismo archivo | `editarCostoReal()`, `deltaOperativo()`, `PATRON_IMPORTE` |
| Colores/labels/iconos | `util/src/types/operacionModel.ts` | `ESTADO_*_CONFIG`, `TIPO_COMPONENTE_CONFIG` |

---

## 11. Los mini-hitos: cada servicio es un momento que se puede anunciar

`OperacionHitosDeViaje::para()` convierte los `OperacionServicio` de un expediente en una lista
de hitos con fecha y hora — la **misma forma** que resolvió la estancia partida en alojamiento
(`docs/Mensajeria.md` §20.7).

### Por qué un viaje lo necesita más que una estancia

Una estancia tiene principio y fin, y lo de en medio es la excepción. **Un viaje es todo días de
en medio:**

```
  Cusco–Puno–Arequipa, 8 días
    día 1  traslado del aeropuerto
    día 2  city tour con guía
    día 4  bus a Puno
    día 6  Islas Uros
    día 8  traslado de salida

  con start/end:  «tu viaje empieza el 1 y acaba el 8»
```

Al pasajero no le hace falta saber cuándo empieza su viaje: le hace falta saber **a qué hora le
recogen mañana**. Cada servicio es un hito, tipo `SERVICE`; el primero lleva además `START` y el
último `END`, para que las reglas que hoy cuelgan de esos dos —la bienvenida, la despedida—
sigan encontrando dónde engancharse sin reescribirlas.

### Decisiones

| Qué | Por qué |
|---|---|
| Recibe los **servicios**, no el expediente | `OperacionServicio → CotizacionFile` no tiene lado inverso; añadirlo obligaría a arrastrar decenas de filas para leer una fecha. Quien llama los consulta acotados |
| La hora sale de `horaRecojoReal` | Es el dato que de verdad se manda. Es texto libre que teclea el operador: si no se puede leer, **se deja el día a secas** — un mensaje a una hora rara se recupera, uno el día equivocado no |
| Fuera los cancelados y los que no tienen fecha | Una fila de La Biblia a medio cuadrar no puede reventar el cálculo de las demás |

⚠️ **De aquí no sale ni el coste al proveedor (`totalOs`) ni el estado del papeleo (`estadoOs`).**
El detalle de un hito es lo que se le puede leer al pasajero. El recorte va en la fuente, no
confiando en que un prompt lo prohíba — eso ya falló esta semana con los teléfonos del personal
de limpieza. Y si algún día hay que decirle «tu tour está confirmado», el estado que responde a
eso es `estadoReservaProveedor` (§4.1), nunca el de la orden de compra.

### Lo que falta

La derivación está hecha y probada (8 tests con objetos en memoria) y **no la consume nadie**.
Para que salgan mensajes hace falta el enlace de conversación del lado de cotizaciones, que a su
vez espera a que `CotizacionFile` tenga búsqueda por teléfono. Ver `docs/Mensajeria.md` §20.5.

## 12. Dónde recojo y dónde dejo — de punta a punta (22/08/2026)

Es la primera pregunta de todo proveedor al recibir una Orden, y hasta ahora se contestaba a mano.
El origen del dato es el catálogo (`docs/Travel.md` §11 quater); esto es el último tramo.

### Las tres capas, y por qué son tres

```
1. override del operador   OperacionServicio::$puntoRecojo / $puntoEntrega   ← si lo hay, MANDA
2. lo que dice el catálogo CotizacionPuntosDelServicio::paraComponente()     ← punto fijo o «su alojamiento»
3. cuál alojamiento        CadenaDeAlojamiento                               ← con las fechas del expediente
```

Cada capa sabe algo que la de abajo no puede saber: el catálogo no sabe en qué hotel duerme este
grupo, y el expediente no sabe que a **este** cliente lo recogemos en la puerta de atrás porque
llega en bus. Lo junta `OperacionPuntosDelServicio::para()`.

### La cadena de alojamiento

Convierte «el alojamiento del pasajero» en «Hotel Terra — Calle Unión 184, Cusco». Las estancias
son los componentes de tipo `alojamiento` del expediente, con su rango de fechas; el hotel es su
**prestador** y la dirección, la de la ficha de esa organización.

```
dondeDurmio(D)    la noche que TERMINA el día D    →  desde <  D <= hasta
dondeDormira(D)   la noche que EMPIEZA el día D    →  desde <= D <  hasta
```

⚠️ **Son dos preguntas y no una.** Con una estancia 04→06 y un servicio el día 6: durmió ahí pero
ya no dormirá. Un traslado de ese día sale del hotel viejo y llega al nuevo — que es exactamente
lo que hay que decirle al conductor. Con una sola pregunta —«en qué hotel está el día 6»— la
respuesta sería correcta la mitad de las veces y la otra mitad mandaría al conductor al sitio
equivocado, sin que nada lo delatara.

⚠️ **Una noche SIN alojamiento devuelve `null`, nunca el hotel anterior.** En un trek se duerme en
campamento. El último hotel conocido es la respuesta plausible que manda al proveedor a cuatro
horas de donde está la gente. Los campamentos se declaran como `TravelPunto` con modo fijo.

⚠️ **Las estancias se DEDUPLICAN.** Un mismo alojamiento aparece varias veces en la cotización
—una fila por habitación o categoría de pasajero— y todas dicen lo mismo.

`CadenaDeAlojamiento` es un objeto **puro** y su constructor vive aparte
(`CadenaDeAlojamientoBuilder`): así la regla de qué noche cubre qué estancia se prueba sin base de
datos, y es justo la que no puede fallar.

### El override del operador: un campo APARTE

`OperacionServicio::$puntoRecojo` guarda **sólo** lo que el operador escribe. Vacío significa «usa
el catálogo».

⚠️ **No se guarda copia del derivado**, por lo mismo que `isSoloReferencia()` es un cálculo y no
una columna: la copia muerta gana siempre porque es la que se lee, y corregir un segmento del
maestro tiene que arreglar todos los viajes a la vez.

⚠️ **Es un campo distinto del derivado, igual que `horaRecojo` lo es de `horaComponente`.** Esa
separación está pagada: cuando las dos horas eran el mismo campo, fijar el recojo pisaba la hora
vendida y se perdía la referencia. Aquí escribir «puerta de atrás» borraría que el catálogo decía
«el hotel del pasajero».

Una cadena en blanco vuelve a `null` en el setter: guardada como `''` seguiría contando como
override y taparía el derivado con un valor que no dice nada.

### En la orden, CONGELADO

`OperacionOrdenServicioItem::$puntoRecojoConfirmado` / `$puntoEntregaConfirmado`, resueltos del
todo al emitir (`OperacionOrdenEmision::emitir()`).

⚠️ **No se leen en vivo, y ésa es la razón de que existan las dos columnas.** El documento se
construye desde los datos congelados del ítem; con un punto vivo, el proveedor abriría el enlace
público la semana siguiente y vería **un sitio distinto del que se le mandó**. Un documento
emitido dice lo que decía al emitirse; si el catálogo cambia, se reemite y se avisa — que es lo
que ya hace el importe.

La redacción («Recoge en X → deja en Y») vive en `OperacionOrdenServicioItem::rutaParaLaOrden()`
porque la pintan **dos** superficies —el mensaje al proveedor y la página pública con su PDF— y
son el mismo documento visto de dos formas. Si los dos extremos coinciden se dice una vez; un
punto ausente no se rellena con un guion, porque un guion invita a suponer que es el hotel.

### En el cuadro de tráfico

Dos campos bajo la descripción del servicio, sólo cuando el servicio recoge a alguien. Vacíos
muestran el derivado como **marcador de posición** —igual que la hora de recojo muestra la
vendida—, así que de un vistazo se ve qué filas se tocaron a mano (texto negro) y cuáles siguen el
maestro (gris). Vaciar el campo devuelve el control al catálogo.

El derivado llega por `GET /operacion/user/puntos?id[]=…`
(`OperacionPuntosController`), **por lista de ids y no por expediente**: el cuadro filtra por
fecha, lugar o tipo y mezcla expedientes. Endpoint aparte y no un campo de la fila por lo mismo
que en cotizaciones: es una lectura derivada que se refresca sola al corregir un segmento.

Los **avisos** se pintan en ámbar bajo los campos y dicen por qué falta algo y dónde se arregla.
Sin ellos, un campo gris y vacío no distingue «no aplica» de «nadie lo declaró».

### 🐛 Un filtro guardado esconde los tipos que aún no existían

Los filtros del cuadro de tráfico se persisten en `localStorage['biblia:filtros']`. Al añadir
`ComponenteTipoEnum::CONTACTO`, los servicios de ese tipo **desaparecieron del cuadro**: el filtro
guardado llevaba los 13 tipos de entonces y el nuevo no estaba en la lista.

Sin error, sin hueco y sin nada que lo delatara — **nueve filas en la base, ocho en pantalla**. Se
tarda en notar porque un filtro que oculta de más se parece mucho a «no hay nada ese día».

El arreglo no es borrar el storage, que volvería a pasar con el siguiente tipo: se guarda junto al
filtro **la foto del catálogo** (`tiposCatalogo`), y al restaurar, si no coincide con el actual, el
filtro de tipos **se descarta**.

⚠️ **Se falla ABIERTO**, y es deliberado: perder el filtro una vez es una molestia; perder una fila
es una orden que no se emite.

⚠️ **Sólo para `tipos`.** Es un enum del código y cambia una vez al año. Los lugares salen de la
base y se crean a menudo: reiniciar su filtro en cada alta sería peor que el problema.

### 🐛 Las horas de recojo que copió una migración (limpiado 22/08/2026)

`Version20260817220000` partió `hora_recojo_real` en dos: copió su valor a `hora_componente`
—correcto, de ahí salía— y **dejó el original intacto** en lo que después pasó a llamarse
`hora_recojo`.

Quedaron 17 filas con las dos horas idénticas y **ninguna con horas distintas**. Cero en diecisiete
no es casualidad: nadie había pactado nunca una hora de recojo diferente.

Y el campo significa «el operador fijó esta hora», así que mentía en dos sitios:

- la fila muestra «vend. HH:MM» sólo cuando difieren — con la copia puesta, nunca;
- **al emitir, `horaRecojoConfirmada` se rellena con ésta**, y nula significa «el proveedor aún no
  confirmó». Esas filas salían a la orden como si hubiera confirmado una hora que nadie le preguntó.

Se limpia con `app:operacion:limpiar-hora-recojo-copiada`, que sólo toca lo **idéntico**. La fila
no cambia de aspecto: sigue enseñando la misma hora porque ya cae al `fallback`. Lo único que
cambia es que deja de afirmar algo que no ocurrió.

⚠️ Si alguien pactó una hora que casualmente coincidía con la vendida, esto se la lleva. Es la
única pérdida posible y se asume a sabiendas: ese dato ya era indistinguible de la copia.

### El orden del día: itinerario o reloj (22/08/2026)

Un interruptor en la barra, junto a los chips de lugar. **Por itinerario es el defecto.**

```
Itinerario   OperacionServicio::getOrdenItinerario()  = día × 1.000.000 + posición del servicio × 1.000 + orden del segmento
Por hora     horaRecojo ?? horaComponente             = la MISMA que pinta la fila
```

⚠️ **Ordenar sólo por reloj parte el relato.** El alojamiento y la comida no tienen hora, así que
caían al final del día lejos del servicio al que pertenecen. Por itinerario quedan donde el cliente
los va a vivir, que es como se lee un viaje.

⚠️ **Y el modo reloj estaba mal.** Ordenaba por `horaRecojo` a secas, así que todo el que no
tuviera un recojo pactado —la mayoría— se iba al fondo del día **aunque su hora estuviera ahí
delante**: el cuadro enseñaba «10:00» y lo colocaba después de las 08:30. Ahora ordena por la misma
hora que pinta.

`getOrdenItinerario()` **se calcula, no se guarda**: duplicarlo en una columna abriría la puerta a
que el cuadro y la cotización se contradigan al reordenar un segmento.

🚧 Coste: serializar una página carga el cotcomponente y su cotsegmento de cada fila — con 200 por
página, 400 lecturas perezosas. Si pesa, se resuelve con un `fetch join` en la extensión de la
colección, **no guardando una copia**.

### 🐛 `gen:api` no limpiaba la caché

Una propiedad serializada nueva **no aparecía en `api.d.ts`**, y sin ningún aviso: `api:openapi:export`
lee los metadatos cacheados. Pasó con `ordenItinerario` — el getter estaba, el grupo estaba, y el
tipo generado seguía sin conocerlo.

Es de la peor familia: un paso de generación que se salta lo nuevo en silencio, y el front acaba
declarando a mano lo que debía venir generado. El script ahora hace `cache:clear --quiet` antes de
exportar.

### El recojo, visible en la tarjeta de la orden ANTES de emitir

Los puntos se congelan al emitir, así que una orden en **borrador no tiene ítems** y no había de
dónde leerlos — y la tarjeta tampoco los pintaba. Resultado: el sitio de recojo sólo se podía ver
**después** de mandar el documento, que es justo cuando corregirlo ya cuesta anular y reemitir.

Ahora `GET /operacion/user/puntos` devuelve, además del derivado, el **efectivo**: override del
operador incluido y hotel ya resuelto. Es exactamente lo que la emisión va a congelar, y es lo que
se pinta en la tarjeta — en ámbar si falta algo.

⚠️ **La redacción está escrita dos veces**, en `OperacionOrdenServicioItem::rutaParaLaOrden()` y en
`rutaDe()` del panel. No hay forma de compartirla: aquí se pinta lo que **todavía no está
congelado**. Ambas lo dicen en su comentario; si cambia una, cambia la otra.

⚠️ Y `cargarPuntosDe()` **mezcla en vez de reemplazar**: los servicios de una orden pueden no estar
en el cuadro —otro rango, otro filtro—, y reemplazando, abrir una orden borraba los puntos de la
lista de detrás.

### 🐛 El botón que nunca aparecía: comparar un objeto con un IRI

«Agregar a OS» se ofrecía sólo si había borradores compatibles, y **no salía nunca** — con la orden
compatible delante. La condición comparaba `servicio.file` con `orden.file`, y **la API devuelve esa
misma relación como objeto en uno y como IRI en el otro** según el grupo de serialización. Un `===`
entre las dos formas es siempre `false`.

Y una condición que siempre es falsa **se ve exactamente igual que «no hay nada que ofrecer»**: sin
error, sin hueco, sin nada.

Se normaliza con `idDeRecurso()`, que acepta las tres formas —objeto con `id`, IRI y uuid pelado—.
La regla: **antes de comparar dos relaciones de la API, extrae el id de las dos**.

#### 🐛 …y volvía a no aparecer, por DOS motivos más (25/08/2026)

Arreglada la comparación, el botón seguía sin salir. Al depurarlo con datos reales aparecieron dos
causas independientes, y ninguna da error.

**Causa 2 — `idDeRecurso()` no cubría la forma que llega de verdad.** La sección de arriba dice
que la función acepta «objeto con `id`, IRI y uuid pelado». Falta una cuarta: **un objeto cuyo
único identificador es `@id`**. Y es justo la que llega:

| Recurso | Claves de su `file` |
|---|---|
| `OperacionServicio` | `@id`, `@type`, `nombreGrupo`, `pasajeroPrincipal`, **`id`**, … |
| `OperacionOrdenServicio` | `@id`, `@type`, `createdAt`, `updatedAt` — **sin `id`** |

La orden serializa su `file` sin el grupo que publica `id`, así que `idDeRecurso(orden.file)`
devolvía `null` y la comparación era `null === '019ec…'`: **falsa siempre**, otra vez. La regla de
arriba seguía siendo buena; lo que estaba mal era creer que la función ya cubría todos los casos.

**Causa 3 — `SearchFilter` no sabe filtrar por una relación, y lo hace en silencio.**
Se intentó pedir los borradores del expediente al servidor. Devolvía cero. Medido en DQL:

```
COUNT sin WHERE                          → 6
WHERE o.file = :f   (string)             → 0
WHERE o.file = :f   (objeto Uuid)        → 0
WHERE o.file = :f   con el tipo 'uuid'   → 6   ← toda la diferencia
```

`SearchFilter::addWhereByStrategy()` ata el valor con `setParameter($p, $v)` **sin declarar su
tipo**, así que compara la cadena de 36 caracteres contra la columna `binary(16)` del uuid. No casa
nunca: **200 con la colección vacía**, sin excepción y sin nada en el log.

⚠️ **No era exclusivo de las órdenes: afectaba a TODOS los filtros de relación.** El más caro:
`operacion_servicios?file=<uuid>` —el filtro por expediente de La Biblia— devolvía **0** con 42
filas de ese mismo expediente cargadas. O sea que **elegir un expediente dejaba el cuadro vacío**.
El problema ya estaba diagnosticado en `TravelOrganizacionServicio`, donde se esquivó con un
controlador a medida; van tres veces.

**Arreglado de raíz con `App\Api\Filter\UuidRelacionFilter`** (25/08/2026), que hace lo único
que faltaba: `setParameter($p, $uuid, 'uuid')`. Acepta uuid pelado o IRI, admite listas
(`?file[]=…`) y navega propiedades anidadas. Se declara **como mapa**, no como lista:
`isPropertyEnabled()` hace `array_key_exists()`, así que con `['file']` la clave sería `0` y el
filtro se ignoraría entero — devolviendo la colección **sin filtrar**, que es el fallo al revés y
peor.

⚠️ **Un valor ilegible corta con `1 = 0`, no se ignora** — y basta **uno** en una lista para
cortar entera. Ignorarlo devuelve la colección completa, y quien pidió «lo de este expediente» se
llevaría lo de todos creyendo que es lo suyo. Enseñar de menos se nota; enseñar de más, no. La
primera versión descartaba el valor malo y seguía con los buenos: con un solo valor cortaba y con
uno bueno y uno malo colaba, que es lo peor de los dos mundos porque nadie sabe qué le tocó.

⚠️ **La forma multivalor va con un parámetro por valor unidos con OR, NO con un `IN`.** La primera
versión ataba la lista con el tipo `'uuid[]'`, **que no existe**: DBAL sólo expande los
`ArrayParameterType::*` y cualquier otro nombre acaba en `Type::getType('uuid[]')`, que lanza.
Con UN elemento la lista colapsaba a la rama de uno y pasaba; con DOS reventaba con
`Unknown column type "uuid[]" requested` — un **500**. Y peor que un fallo cualquiera, porque
`getDescription()` publica `?file[]` en el esquema y en `api.d.ts`: se ofrecía un contrato que sólo
funcionaba con un valor. Probar la forma de lista con un solo elemento **no prueba nada**.

Propiedades migradas de `SearchFilter` a `UuidRelacionFilter`:

| Entidad | Propiedades |
|---|---|
| `OperacionServicio` | `ordenServicio`, `file`, `cotizacionServicio.cotizacion` |
| `OperacionOrdenServicio` | `file` (recuperado; `estadoOs` se queda en `SearchFilter`) |
| `OperacionMensaje` | `ordenServicio` |
| `OperacionEstadoBitacora` | `operacionServicio` (`campo` se queda) |
| `OperacionPago` | `ordenServicio` |
| `PmsTarifaRango` | `unidad` (`moneda` se queda: su id es texto, `'PEN'`) |

**La regla, corta: relación → `UuidRelacionFilter`; columna de texto → `SearchFilter`.**

Queda pendiente retirar los apaños de Travel (`TravelOrganizacionServicioPorOrganizacionExtension`
y su controlador de opciones), que ahora podrían ser una línea de `#[ApiFilter]`.

**Cómo quedó:**

- `fetchBorradoresDeFile(fileId)` filtra **en el servidor** por `estadoOs` y por `file`, con
  `itemsPerPage` explícito: con la paginación por defecto (30) un borrador en la página 2 vuelve a
  ser un botón que no sale.
- Se piden al marcar la primera fila, no al arrancar. El `watch` vigila **dos** fuentes:
  `fileDeLaSeleccion` y `fileDeBorradores`. Con sólo la primera quedaba un hueco: se emite un
  borrador desde la pestaña de Órdenes, se vuelve a La Biblia con lo mismo marcado, la selección no
  cambió y **nada recargaba** — el botón seguía ofreciendo una orden ya emitida.
- `invalidarBorradores()` **vacía la lista**, no sólo marca el expediente como sucio. Dejar el
  contenido viejo esperando un refetch que quizá no llegue es lo que producía ese mismo síntoma.
- ⚠️ `ordenesParaAgregar` **vuelve a comprobar el expediente en el navegador**, aunque el servidor
  ya lo filtre. No es redundancia: entre marcar una fila del expediente A y otra del B salen dos
  peticiones sin cancelar la primera, y si la de A llega la última la lista es de A con la
  selección en B. Filtrando sólo por comprador —el mismo proveedor está en dos expedientes muy a
  menudo— el botón ofrecía agregar filas de B a una orden de A. `idDeRecurso()` con la rama `@id`
  es lo que lo hace posible (causa 2).

⚠️ **La invalidación vive en el store, no en la vista.** Emitir, agregar, cambiar de estado o
borrar ponen `fileDeBorradores = null`. Si dependiera de que cada vista se acordase de refrescar,
la segunda vista no se acuerda — es el mismo motivo por el que `ordenesServicio` ya se sincroniza
ahí.

**Verificado contra el backend de pruebas** (25/08/2026):

- Se genera una orden con una fila de Americana, se marca otra fila de Americana y aparece
  «Agregar a OS (1)» —el (1) es correcto: de los 5 borradores del expediente sólo casa el de
  Americana—; al confirmarlo la orden pasa de 1 a 2 líneas.
- El filtro por expediente de La Biblia pasa de **0 a 42** filas (todas las del expediente).
- Ejercitando el filtro directamente: por uuid → 2, por IRI → 2, lista de **dos** → 2, lista con
  un repetido → 2, uuid inexistente → 0, valor ilegible → **0 y no 42**, lista mixta
  (bueno + basura) → **0**, lista vacía → 0, sin parámetro → 42. El anidado
  `cotizacionServicio.cotizacion` → 42 con la cotización real y 0 con una inexistente.

### Crear y emitir son dos decisiones, y sólo una es reversible

- **«Crear»** —a secas, en verde— es la acción segura y la del 90 % de los casos. Se llamaba «Crear
  borrador» y sonaba a paso intermedio que hay que rematar; lo que crea es una orden, que además se
  puede emitir después desde su tarjeta.
- **«Crear y emitir»** pide **dos pulsaciones**: la segunda confirma. Emitir congela el contenido y
  no vuelve atrás —para cambiar algo hay que anular y reemitir— y el botón está justo al lado del
  seguro. Un diálogo aparte sería más ceremonioso; el botón que se convierte en «¿Seguro? Toca otra
  vez» corta el error sin añadir una ventana más, y en el teléfono se agradece.

⚠️ La confirmación **se olvida sola a los 4 segundos**: una que se queda armada indefinidamente es
la misma pulsación accidental, sólo que más tarde.

### El cuadro de tráfico en móvil (22/08/2026)

Nueve columnas no caben en 360 px. En vez de comprimirlas —que es como se llega a una fila que no
se puede leer ni tocar sin equivocarse— por debajo de `md` se reducen a **tres**:

```
☑   06:00        Transporte Cusco - Ollanta
    [Pendiente]  Van (paquete externo)
    [OS]         Futurismo · 2 pax
     ↑ estados    ← toca en cualquier sitio → ficha a pantalla completa
```

- **Expediente, pax, prestador, costo, reserva y operación** llevan `hidden md:table-cell`.
- **La celda de la hora se parte**: el reloj arriba y los estados debajo, como pastillas. Es hueco
  muerto y es donde la vista ya está mirando.
- **Tocar la fila abre la ficha.** El selector y los inputs en línea llevan `@click.stop`: marcar
  una casilla no puede abrir un formulario.
- **En escritorio no cambia nada.** La tabla ancha funciona y la ficha ni se monta (`md:hidden`).

#### ⚠️ En la ficha se edita sobre un BORRADOR, al revés que en la tabla

En la tabla cada campo se manda al perder el foco. En un teléfono **no hay sitio para poner el
aviso de guardado al lado del campo**, así que el operador no sabía si su cambio había entrado —
`guardando` se calculaba y no se pintaba en ningún sitio.

Con el borrador, «Guardar cambios» **es** la respuesta a esa pregunta. Y sólo se manda lo que
cambió: enviar el resto reescribiría campos que nadie tocó.

Los botones van **abajo y fijos**: es donde llega el pulgar y donde no los tapa el teclado.

⚠️ **El costo negociado se empotra tal cual**, con su propio commit, en vez de meterlo en el
borrador: dos formas de guardar el mismo importe acabarían discrepando.

### Agregar servicios a una orden que se está componiendo (22/08/2026)

`POST /platform/ops/orden-servicios/{id}/agregar` → `AgregarAOrdenProcessor`.

Componer una orden no siempre ocurre de una sentada: se marcan los servicios del martes y al
revisar el miércoles aparecen dos más del mismo proveedor. Sin esto había que **anular y rehacer**,
que además cambia el número de la orden.

Con el filtro «sin orden» del cuadro es un barrido: se seleccionan los que quedan sueltos, se
suman a la orden que corresponda, y desaparecen de la lista.

⚠️ **SÓLO sobre borradores.** Una orden emitida es un documento que el proveedor ya tiene:
añadirle una línea por detrás la cambiaría sin que él se entere. Para eso está reemitir —anular y
crear la sucesora—, que deja el rastro.

**Reusa `OperacionOrdenEmision::validar()`** en vez de reescribir las comprobaciones (comprable, no
atado a otra orden, un expediente, un comprador), y encima añade lo que sólo aplica aquí: que el
expediente y el comprador de lo que entra **coincidan con los de la orden**, que ya están
decididos. Sin eso, una orden dirigida a PeruRail acabaría con una línea de Consettur dentro.

En el cuadro, el botón sale sólo si hay borradores compatibles, y el modal enseña **número y
comprador** de cada uno: sin el comprador delante, elegir entre «OS-014» y «OS-015» es adivinar, y
equivocarse manda el encargo al proveedor que no era.

### La visibilidad de los puntos: presentación, no pacto (22/08/2026)

#### El diagnóstico que lo desbloqueó todo

El operador temía que conservar una orden anulada obligara a «snapshots de snapshots». **No hay
tal**: existe exactamente **un** nivel de congelación (`items`, al emitir) y la sucesión de
documentos ya la resuelve la cadena `reemplazaA`. Nunca se copia un ítem desde otro ítem.

Lo que creaba la ilusión era un bug del panel: **sólo leía el enlace vivo, nunca `items`**. Una
orden anulada aparecía vacía —suelta sus servicios por diseño— aunque su documento estuviera entero
en la base. Y una **emitida** enseñaba lo que La Biblia dice *ahora*, no lo que el proveedor tiene
en la mano: la misma mentira que `getDivergencias()` existe para denunciar, pintada como si fuera
el documento.

**Ahora: borrador → vista viva; de emitida en adelante → los ítems.**

#### Tres clases de cambio, no dos

La regla nunca fue «el ítem no se toca», sino **«el pacto no se toca»**. El módulo ya lo practicaba:

| Clase | Qué toca | Mecanismo |
|---|---|---|
| **Pacto** | importes, fechas, pax, qué servicios, comprador | anular + reemitir (`reemplazaA`) |
| **Completado** | lo que era **nulo** y ahora se sabe | `aplicarCambiosMenores()` — **ya escribía en ítems emitidos** |
| **Presentación** | qué renglones se imprimen | `getRutasVisibles()` — **ya ocultaba puntos verdaderos** |

Ocultar un renglón **no afirma nada falso: dice menos**. Cambiar el TEXTO de un punto sí es pacto.
Ésa es la frontera, y es la que permite editar la presentación sin reemitir.

#### `VisibilidadPuntoEnum`, dos por línea

```
AUTO      manda la regla de cadenas (defecto)
SIEMPRE   se imprime aunque esté en medio
OCULTO    NO se imprime aunque sea el extremo
```

Uno para el recojo y otro para la entrega, porque **la granularidad del mecanismo ya es por lado**:
el primero de una cadena enseña su recojo y el último su entrega.

Viven **en los dos sitios con papeles distintos, no con precedencia**: el ítem manda sobre ESTE
documento; la fila viva es la semilla del siguiente. Nunca se leen a la vez.

⚠️ **La puerta escribe los DOS.** Si sólo tocara el ítem, **reemitir resucitaría el renglón que el
operador acaba de ocultar** — en silencio, semanas después, en un documento que nadie va a comparar
con el anterior.

⚠️ **`getRutasVisibles()` NO se materializa.** Todos sus insumos ya están congelados —fecha, hora,
prestador, puntos y ahora los enums—, así que es determinista y el documento se reproduce igual
meses después. Materializarla sería guardar copia de un derivado, que es lo que `isSoloReferencia()`
y `getOrdenItinerario()` se niegan a hacer.

#### 🐛 El desplegable que quedaba debajo del teclado

`SearchableSelect` **ya volcaba hacia arriba** cuando no cabía… pero decidía con
`window.innerHeight`, y **eso no encoge cuando sale el teclado** (en iOS nunca; en Android depende
del modo). La cuenta decía que había sitio de sobra abajo justo cuando el teclado acababa de
taparlo: la lista se abría hacia abajo, quedaba detrás del teclado, y como el campo ya estaba en el
límite tampoco había scroll con el que alcanzarla.

**No es que se viera mal: es que no se podía elegir.**

Ahora mide con `visualViewport` —altura y `offsetTop`— y escucha sus eventos, porque **el teclado no
dispara `resize` de forma fiable**: sin eso la lista se colocaba bien al abrirse y se quedaba mal en
cuanto subía el teclado.

Vale para sus cuatro usos a la vez, que es la ventaja de que fuera un componente.

#### La pastilla dice el RESULTADO, no el ajuste

`getVisibilidadEfectiva()` devuelve, por ítem y por lado, si de verdad se imprime. La pastilla pone
**«Auto (visible)»** o **«Auto (oculto)»**.

⚠️ «Auto» a secas no informaba: el operador no puede saber si esa línea sale sin reconstruir la
cadena de cabeza. Como el sistema **ya lo ha calculado**, decirlo es gratis — y es la diferencia
entre un ajuste y una respuesta. El color va también por el resultado: un `auto` que acaba oculto
se ve como oculto.

La resolución por lado vive en `resolverLados()` y la usan **las dos** —el texto que se imprime y
el informe de las pastillas—. Escrita dos veces, la pastilla acabaría diciendo «visible» de un
renglón que el documento se calla.

Y el listado va **agrupado por día**: la regla de cadenas trabaja por jornada, así que ver las
líneas sueltas obliga a reconstruir qué va con qué.

#### 🚧 Un servicio que está en La Biblia y NO debe llegar al proveedor

Caso real: un Camino Inca de 4 días necesita **un componente por día** para llevar hora de inicio y
fin (ver `docs/Travel.md`), pero para el proveedor **es un solo servicio** y esas cuatro líneas
sobran en su orden.

**No hace falta nada nuevo.** El mecanismo ya existe: un componente **sin tarifa** —o en modo
`no_incluido`— es «sólo referencia» (`isSoloReferencia()`), y `motivoNoComprable()` lo deja fuera de
cualquier Orden de Servicio. Vive en el cuadro de tráfico con sus horas, marca el servicio principal
del día, resuelve los puntos… y **nunca llega al documento**.

Lo que sí va a la orden es el pool real con su precio: una línea, que es como el proveedor lo vende.

⚠️ Es el reverso exacto del contacto con el pasajero, donde la tarifa de 0 era **obligatoria**
precisamente para que sí llegara. La regla es la misma leída en los dos sentidos: **sin tarifa =
existe para nosotros, no para el proveedor**.

#### La puerta: `POST /ops/orden-servicios/{id}/rutas`

`AjustarRutasProcessor`. Sólo sobre **emitida** o **confirmada**: en borrador se edita la fila viva
como cualquier override, y una anulada es terminal —retocar la presentación de un documento que ya
no vale sólo sirve para dudar de si sigue vigente—.

⚠️ **Puerta dedicada, no un PATCH al ítem.** El ítem no es `ApiResource` y no debe serlo: abrirlo a
PATCH genérico expondría toda la línea congelada —importes incluidos— a una escritura sin reglas.
Misma doctrina que el cambio de estado.

Valida con **lista blanca**: el ítem tiene que ser de esa orden. Sin eso, editar la presentación de
un documento ajeno desde su hermana es una puerta que nadie vuelve a encontrar.

#### Se relaja el «nunca quita», y con qué a cambio

El control anterior era un booleano que sólo podía **añadir** líneas, con el argumento de que
quitar el principio de una cadena deja al proveedor sin saber dónde ir. El argumento sigue siendo
bueno; lo que cambió es el control: ahora se decide **por lado, sobre el listado de la orden**,
donde se ve la cadena entera. Y bloquearlo empujaba al atajo destructivo —vaciar el texto del
punto— que además pierde el dato.

⚠️ A cambio, `getAvisosDeRutas()`: si una cadena queda sin decir dónde se recoge o dónde se deja, el
panel lo pinta en ámbar. **Aviso, no bloqueo** — el mismo criterio de «se falla abierto» del filtro
de tipos: el sistema hace visible la consecuencia; la decisión es de la persona.

#### El recálculo, acotado

`getCambiosMenores()` gana los puntos, con la **misma asimetría que la hora**: sólo rellena lo que
estaba **nulo**, nunca pisa lo que ya tenía valor. Recalcular el texto de un punto ya impreso *es*
reemitir, y eso ya tiene su botón. El contrato del recálculo es **«rellena lo vacío y denuncia lo
demás»**.

Y compara sólo contra el override del operador, no contra el catálogo: volver a derivarlo necesita
el expediente y la cadena de alojamiento, y esto se pinta por fila.

#### `getDivergencias()` no se toca

La visibilidad le es **ortogonal**: compara textos congelados contra overrides vivos, y ocultar un
renglón no toca ningún texto. Una divergencia de visibilidad no debe existir —la puerta escribe
ambos— y, si existiera, no obliga a reemitir.

### Una cadena del mismo prestador dice dónde EMPIEZA y dónde ACABA

`OperacionOrdenServicio::rutasVisibles()`.

⚠️ **Lo de en medio es logística del proveedor.** Si el mismo opera el recojo en el hotel, el
traslado a la estación y el de ahí a Machu Picchu, decirle «hotel → estación · estación → estación
· estación → Machu Picchu» no le informa de nada: se lo está contando a quien lo va a conducir. Lo
que necesita saber es dónde empieza y dónde termina.

```
06:00  Transporte   Futurismo   Recoge en Hotel Terra          ← principio de la cadena
06:40  Tren         Futurismo   (nada)                         ← en medio: suyo
09:00  Transporte   Futurismo   Deja en Machu Picchu Pueblo    ← final de la cadena
15:00  Guiado       Consettur   Recoge en … → deja en …        ← otra cadena, entera
```

**Corta la cadena** cambiar de prestador o cambiar de día. Un día nuevo empieza cadena aunque lo
opere el mismo: se consulta por jornada, y quien opera el miércoles puede no haber leído el martes.

**No la corta** un ítem sin puntos: un ticket de ingreso entre tres traslados del mismo proveedor
no convierte eso en dos cadenas. Tampoco entra en ella, porque no aporta ni principio ni final.

⚠️ **La primera versión se callaba sólo lo REPETIDO, y no cubría el caso**: en una cadena los tres
puntos son distintos, así que salían los tres. Repetir la logística interna del proveedor en cada
línea es cómo se le enseña a no leer ese renglón — y entonces no lo lee el día que sí le estás
diciendo algo.

#### El escape: `puntosSiempreVisibles`

Una casilla por servicio —en la ficha— para que **esa** línea enseñe sus puntos aunque esté en
medio. Es para cuando la suposición no vale: un tramo subcontratado, o un punto intermedio que el
proveedor sí necesita por escrito. Se congela en el ítem al emitir, como todo lo demás.

⚠️ **Sólo puede AÑADIR líneas, nunca quitarlas.** Un interruptor que también sirviera para ocultar
el principio o el final de una cadena dejaría al proveedor sin saber dónde recoge — y eso no es una
preferencia de formato, es una orden que no se puede cumplir.

Vive en la orden y no en cada superficie porque lo pintan **dos** —el mensaje al proveedor y la
página pública con su PDF— y una regla de «cuándo callarse» escrita dos veces se convierte en dos
documentos distintos para el mismo servicio. Cubierto por `OperacionOrdenRutasTest`.

### Verificado con datos reales

Sobre un expediente de 42 servicios en La Biblia:

```
31/08  Auto a Miraflores Noche   Aeropuerto de Lima          → Sonesta Miraflores — Calle Alcanfores…
04/09  Auto                      Aeropuerto de Cusco         → Hotel Terra - Cusco — Calle Unión 184
06/09  Van                       Hotel Terra - Cusco — …     → Estación de Ollantaytambo
08/09  PR Observatory Adulto     Estación de Machu Picchu    → Estación de Ollantaytambo
09/09  Americana Royal Class     Hotel Terra - Cusco — …     → Plaza de Armas de Cusco
10/09  Americana Royal Class     Hotel Terra - Cusco — …     → Tambo del Inka - Urubamba — Av. Ferrocarril
```

Lo que queda sin resolver son segmentos del catálogo sin puntos declarados, y **cada uno sale con
su aviso**, no en blanco.

### Lo que salió de la revisión externa (22/08/2026)

Se pasó el módulo entero por una revisión crítica. Lo corregido y lo que queda:

#### Corregido

| Hallazgo | Por qué era grave |
|---|---|
| La cadena de alojamiento incluía componentes **cancelados o reemplazados** | Aquí no se borra: cambiar el hotel A por el B deja la fila de A viva y marcada, así que la cadena tenía **las dos estancias sobre la misma noche** y el desempate lo decidía el orden de inserción. La orden podía salir con el hotel viejo — nombre y dirección reales, fechas que cuadran, imposible de ver leyéndola. |
| El resolvedor de cotización contaba los cancelados | Un componente muerto podía quedarse la **cabecera** del servicio y meter su hueco en los avisos. |
| Dos hoteles la misma noche se resolvían **en silencio** | Un grupo repartido es legítimo y aquí no consta a quién va cada uno. Ahora se coge el primero **y se avisa**. |
| `getDivergencias()` no miraba los puntos | El docblock prometía «si cambia, se reemite y se avisa» y no había nada. |
| El `v-if` del cuadro escondía un override existente | Con el endpoint del derivado caído, el dato del operador seguía mandando en la emisión y él no lo veía ni podía vaciarlo. |
| Aviso engañoso en tipos sin extremos | Un ticket con override recibía «ponlo en el SEGMENTO del maestro»: mandaba a arreglar un hueco inexistente, y un aviso así enseña a ignorar los demás. |
| Caché de cadenas sin `ResetInterface` | Bajo un worker de Messenger, la cadena del primer expediente sobreviviría a los demás. Fallo intermitente, dependiente del orden de la cola, con hoteles reales pero equivocados. |
| Ids malformados → 500 | Ensucian el log y esconden los fallos de verdad. |
| **Los dos resolvedores divergían** con `dia = null` en plantilla multi-día | Travel tomaba los extremos de la plantilla entera y Cotización los del día del segmento. Dos respuestas distintas a la misma pregunta. Alineados en la segunda, que es la que está bien definida siempre. |

⚠️ **La corrección de los cancelados tiene una trampa que casi se cuela:** `no_incluido` **SÍ está
vivo**. Es el hotel que el pasajero reservó por su cuenta — no se le compra a nadie, pero es donde
hay que recogerlo. Filtrar «por modo» a secas lo habría borrado y dejado al transportista sin
dirección. Por eso el predicado vive en `CotizacionCotcomponente::estaVivo()`, con los **mismos dos
casos** que `OperacionServicio::esComprable()`, y tiene test propio.

#### Pendiente, anotado y no tapado

- **La divergencia sólo vigila el override del operador**, no el catálogo. Volver a derivar el
  punto necesita el expediente y varias consultas, y `getDivergencias()` se pinta por fila. Caza el
  caso real —«lo corregí después de emitir»— y no caza que alguien cambie el segmento del maestro.
- **N+1 en `OperacionPuntosController`**: hasta 500 servicios × (una consulta de maestros + los
  perezosos de la cotización + la cadena). El resto del código presume «en UNA consulta»; este
  camino la pierde multiplicada. Con una página del cuadro no se nota; con el tope de 500, sí.
- **`ComponenteTipoEnum::esSalto()` no tiene consumidor.** Su docblock decía que vuelos y trenes
  parten el día; ningún resolvedor lo hace. Corregido el texto para que no describa código que no
  existe.
- **`TravelPuntosDelServicio` no tiene consumidor en producción ni test propio.** La regla está
  escrita dos veces a propósito —contenedores distintos— pero eso sólo se sostiene si algo denuncia
  la divergencia, y la divergencia del `dia = null` es la prueba de que nadie la denunciaba. O se le
  da su consumidor (el informe del comando podría usarlo en vez de reimplementar `extremosDe()`) o
  tests espejo de los de Cotización.
- **`cerrarAbarcadores` escribe sobre segmentos COMPARTIDOS.** El `finModo` del último segmento del
  día se fija según compartido/privado del abarcador, y hay 33 segmentos compartidos: si dos
  plantillas de tipo distinto comparten el final, la primera procesada decide por ambas. Ya se
  ejecutó; es auditoría pendiente, no un fix.
| Cambiar qué extremos ve el proveedor en una orden emitida | `src/Operacion/ApiPlatform/State/AjustarRutasProcessor.php` | `POST /{id}/rutas` — **escribe el ítem Y la fila viva** |
| Ajustar la regla de qué renglón se imprime | `src/Operacion/Entity/OperacionOrdenServicio.php` | `getRutasVisibles()`, `cadenasDe()`, `getAvisosDeRutas()` |
| Conservar lo vendido antes de modificar la cotización | `src/Cotizacion/ApiPlatform/State/GuardarHistoricoProcessor.php` | clona hacia ATRÁS: las órdenes no pierden su ancla — ver `docs/Cotizaciones.md` §6.j |
| Cambiar qué se le dice al proveedor en una línea | `src/Operacion/Entity/OperacionServicio.php` | `notasPrestador` (override) / `getNotasPrestadorEfectivas()` — §13 |
| Que un detalle de la cotización llegue a la orden | editor de cotización | marcar la audiencia `prestador` en el componente — §13 |

---

## 13. Lo que se le dice al proveedor (22/08/2026)

El cotizador anota cosas sobre un componente —frecuencias del tren, número de vuelo, dirección
del hotel— y parte de eso es exactamente lo que el proveedor necesita para hacer su trabajo.
Hasta ahora no salía del expediente: la orden le llegaba con qué, cuándo, cuántos y dónde, pero
sin **qué tiene que saber**.

### La cadena, y por qué cada eslabón es distinto

```
CotizacionCotcomponente.detallesOperativos     el dato, con sus banderas de audiencia
   │  textosPara(PRESTADOR, 'es')              ← sólo lo marcado para él, en español
   ▼
OperacionServicio.notasPrestador               NULL = «usa lo del componente»
   │  getNotasPrestadorEfectivas()             ← override si lo hay, si no el derivado
   ▼
OperacionOrdenServicioItem.notasPrestador       CONGELADO al emitir
```

Es la misma pareja que ya forman `puntoRecojo` (nullable, vacío = catálogo) y
`puntoRecojoConfirmado` (congelado), y por los mismos dos motivos:

- **El override es un campo APARTE, no una copia del derivado.** Guardar aquí lo derivado abriría
  la puerta a que las dos se contradigan, y la copia muerta gana siempre porque es la que se lee.
  Corregir el detalle en la cotización tiene que arreglar todos los viajes a la vez.
- **El ítem es copia y no enlace.** Leerlo en vivo haría que el proveedor abriera su enlace la
  semana siguiente y viera instrucciones **distintas** de las que se le mandaron. Un documento
  emitido dice lo que decía al emitirse.

⚠️ **Vaciar el campo devuelve al derivado, no significa «no le digas nada».** `[]` y `null` se
guardan igual (`setNotasPrestador()`): una lista vacía persistida seguiría contando como override
y taparía los detalles del componente para siempre con algo que no dice nada.

### Después de emitir: completar sí, cambiar no

Misma asimetría que la hora y los puntos, y por la misma razón:

| Situación | Clase | Dónde sale |
|---|---|---|
| El ítem no tenía notas y ahora hay | **completado** | `getCambiosMenores()` → «ya hay instrucciones para el proveedor» |
| El ítem tenía notas y ahora dicen otra cosa | **pacto** | `getDivergencias()` → «cambió lo que se le pide al proveedor» → reemitir |

`aplicarCambiosMenores()` **sólo rellena lo vacío, nunca pisa**: cambiar un texto ya impreso es
cambiarle el encargo, y eso tiene su propio botón.

### En el documento del proveedor

`templates/operacion/orden_publica.html.twig` —el mismo archivo para la web y para el PDF— imprime
las notas del ítem bajo la descripción, junto a la ruta.

⚠️ Van **sin** la clase `sub` que llevan el recojo, el «opera» y la ruta: eso son matices de la
línea y esto es el encargo. Que se lea igual que ellos sería enseñar a saltárselo.

⚠️ Y salen del **ítem congelado**, no de la fila viva: el proveedor abre su enlace la semana
siguiente y ve lo que se le mandó, no lo que la cotización diga ahora.

### En el cuadro de tráfico

`OperacionView.vue` pinta un `textarea` —una nota por línea— con el derivado de **placeholder**,
así que se ve qué se va a imprimir sin abrir la cotización. En la tarjeta de la orden salen las
del ítem, en sólo lectura, porque ahí lo que importa es lo que el proveedor tiene en la mano.

⚠️ **El campo aparece sólo si ya hay algo que decir** —del componente o escrito a mano—, igual
que los puntos. Lo que no existe se escribe en la cotización, que es donde vive el dato: este
campo es para **ajustar**, no para redactar de cero. Si algún día hace falta redactar aquí, es
quitar esa condición; ponerlo ahora invitaría a llenar la Biblia de texto que la cotización no
conoce y que se perdería al reemitir.

### Quién sale a `prestador`

Por defecto, nadie: es el único cambio de esta familia que no se puede deshacer, porque un texto
de más se ve cuando el proveedor ya lo leyó.

El 22/08/2026 se corrió la conversión con `--todas` tras mirar el contenido real: de los
diecisiete bloques, quince son número de vuelo, frecuencia de tren y dirección de hotel. **Quitar
la marca a dos sale más barato que ponérsela a quince**, y entre marcarla y que un proveedor lo
lea sigue estando la emisión de la orden, que la ve una persona.

A partir de ahí es una decisión por componente, en el editor de la cotización.
