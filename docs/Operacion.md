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
3.bis [Por qué La Biblia aparece vacía](#3bis-por-qué-la-biblia-aparece-vacía)
4. [Los tres estados y por qué son tres](#4-los-tres-estados-y-por-qué-son-tres)
5. [Órdenes de Servicio y bitácora](#5-órdenes-de-servicio-y-bitácora)
6. [API: endpoints, grupos y filtros](#6-api-endpoints-grupos-y-filtros)
7. [La vista de tráfico](#7-la-vista-de-tráfico)
8. [Gotchas](#8-gotchas)
9. [Pendiente de decidir](#9-pendiente-de-decidir)
10. [Dónde tocar para cambiar X](#10-dónde-tocar-para-cambiar-x)

---

## 1. Vocabulario

| Término | Significado |
|---|---|
| **La Biblia** | El cuadro de tráfico: qué se opera cada día, a qué hora, con quién. Una fila = un `OperacionServicio`. |
| **OperacionServicio** | Unidad despachable. Nace como *snapshot* de un `CotizacionCotcomponente` al confirmar la cotización. |
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
        ├─ CONFIRMADO ─► reactiva las filas canceladas (→ pendiente)
        │                  + generarParaCotizacion() ────┘  1 fila por Cotcomponente
        │
        └─ CUALQUIER OTRO ─► propagarEstadoOperacion(CANCELADO)
           (cancelado / pendiente / enviado / archivado)

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
| `resolverDescripcion()` | 1) `tarifa.nombreInternoSnapshot` → 2) snapshot i18n `es` del componente → 3) `'Servicio sin nombre'`. |
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
cambió el prestador, el teléfono que hace falta es el de la empresa nueva. Corregir un número en
la ficha lo corrige en todas las filas — justo lo que una copia por fila impedía
(`Version20260820200000`).

#### En la pantalla

El input va contra un `<datalist>` del catálogo, uno solo para todo el cuadro: con un selector
por fila el DOM crecería a 99 opciones × cientos de filas. Si lo escrito no casa con ninguna
empresa **se revierte y se avisa**, en vez de guardar un nombre suelto que rompería la agrupación
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

⚠️ **La conversión de identificadores es donde falla.** `travel_componente.id` es `BINARY(16)`
y `componente_maestro_id` es `VARCHAR(36)` en minúsculas —tal como lo escribe `extractIdStr()`
del store—. Según cómo vuelva la consulta, el id llega como objeto `Uuid`, como cadena o como
**los 16 bytes crudos**; el helper contempla las tres formas y normaliza todo con
`toRfc4122()`. La primera versión devolvía cero resultados por saltarse el caso binario.

El índice `idx_cotcomponente_maestro` sobre `cotizacion_cotcomponente (componente_maestro_id)`
no es opcional: es la columna contra la que se lanza el `IN`, y sin él cada clic en un chip
provoca un full scan.

**El chip «Sin etiqueta»** (`lugar[]=__sin_lugar__`) añade en OR los componentes cuyo
soft-link es `NULL` o **no está en la lista de los etiquetados**. Recoge lo tecleado a mano y
los soft-links rotos, y es la diferencia entre un filtro fiable y uno que esconde cosas: sin
él, la suma de todos los chips no da el total y nadie sabe qué falta.

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

**Filtros:** rango de fechas —arranca en **hoy → +6 días**, no en un solo día (§3.bis)— con
presets Hoy / Mañana / 7 días, expediente por autocompletado,
cotización (versiones del expediente elegido), chips de tipo de componente, y estados de reserva y
operación. `construirParamsBiblia()` en `operacionModel.ts` es el único sitio donde viven los
nombres de los parámetros de query.

**Los chips de lugar van en fila propia, siempre visible**, y eso no es cosmético: el objetivo
declarado era apretar «Lima» y ver de un clic todo lo que se opera desde ahí. Metidos en el
panel plegable de filtros avanzados —donde viven los chips de tipo— habría que abrirlo antes de
cada clic, que es exactamente el paso que se quería quitar. Llevan color distinto al de los
chips de tipo porque son dos taxonomías distintas y no deben leerse como la misma.

Los badges de lugar por fila se resuelven **en lote**: se juntan los `componenteMaestroId`
distintos de las filas cargadas y se hace **una sola** llamada a
`/platform/travel/componentes?id[]=…&pagination=false`. Los `lugares` llegan como IRIs y se
traducen a nombre contra el vocabulario que ya se cargó para los chips — una consulta por carga
del cuadro, no una por fila.

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
| **Añadir un medio de pago a proveedor** | `src/Operacion/Enum/OperacionMedioPago.php` | un `case` + su `label()` e `icono()`; el panel lo recoge solo |
| Cambiar en qué monedas se puede pagar una orden | `src/Operacion/Entity/OperacionOrdenServicio.php` | `monedasDeLosServicios()` — lo comprueba `OperacionPago::validarMonedaDeLaOrden()` |
| Cambiar cuándo una orden cuenta como saldada | `src/Operacion/Entity/OperacionOrdenServicio.php` | `isSaldada()` — derivado, **no** es un estado de `estadoOs` |
| Cambiar cómo se filtra por centro turístico | `src/Operacion/Filter/OperacionServicioLugarExtension.php` | `applyToCollection()` |
| Cambiar qué recoge el chip «Sin etiqueta» | `src/Operacion/Filter/OperacionServicioLugarExtension.php` | `SIN_LUGAR` |
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
