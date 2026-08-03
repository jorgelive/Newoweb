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
3. [Reglas del snapshot](#3-reglas-del-snapshot)
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
        ├─ CONFIRMADO ─► BibliaSnapshotService            │
        │                  ::generarParaCotizacion() ─────┘  1 fila por Cotcomponente
        │
        ├─ CANCELADO ───► propagarEstadoOperacion(CANCELADO)
        │
        └─ PENDIENTE / ENVIADO / ARCHIVADO
                        └► propagarEstadoOperacion(PENDIENTE)

           OperacionResincronizarCommand ──► el mismo BibliaSnapshotService
           (borra y regenera; ver §3)
```

### Por qué `onFlush` y no `postUpdate`

`onFlush` es la única fase en la que se pueden **persistir otras entidades dentro de la misma
transacción** sin disparar un flush anidado (que provocaría recursión infinita del listener). El
precio: hay que instruir a Doctrine a mano con `UnitOfWork::computeChangeSet()` después de cada
`persist()` o `set*()`. Si se olvida, el cambio se pierde **en silencio**.

### Por qué la generación vive en un servicio

`BibliaSnapshotService` está fuera del listener porque la generación tiene dos entradas legítimas:
el flujo automático al confirmar y la re-sincronización manual del comando. Tenerlo duplicado era
garantía de que las dos copias se desincronizaran.

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
viejo hasta que alguien corra el comando de §3.4.

### Idempotencia

`findOneBy(['cotizacionComponente' => $cotcomponente])` corta la creación si ya existe fila para
ese componente. Consecuencia deseada: re-confirmar no duplica. Consecuencia no deseada:
**re-confirmar tampoco repara** una fila mal generada ni rellena campos añadidos después.

### Exclusiones silenciosas

Un componente **no** llega a La Biblia, sin error ni aviso en ninguna UI, si:

| Condición | Dónde |
|---|---|
| La cotización es de catálogo (`getCatalogo() !== null`) | `generarParaCotizacion()` — tours de exhibición con fechas nominales, sin expediente real |
| Todas sus tarifas tienen rol `ALTERNATIVA` | `resolverTarifaPrimaria()` — las alternativas son venta opcional, no operación |
| No hay tarifa, o la tarifa no tiene moneda | `generarParaCotizacion()` |
| No se puede resolver una fecha | `resolverFechaServicio()` |

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

### <a id="34"></a>3.4 Re-sincronizar

```bash
php bin/console operacion:resincronizar <uuid-cotizacion>
php bin/console operacion:resincronizar --todas [--dry-run] [--force]
```

Borra el snapshot anterior y lo regenera. Es la **única** forma de aplicar cambios de reglas a
cotizaciones ya confirmadas, porque la idempotencia bloquea la regeneración normal.

Los servicios ya vinculados a una Orden de Servicio se conservan salvo `--force`: borrarlos
rompería la trazabilidad de lo que ya se le pidió al proveedor.

El comando hace un `flush()` intermedio entre el borrado y la regeneración. Es obligatorio: el
guard de idempotencia consulta la base de datos, y sin vaciar los `remove` pendientes vería las
filas viejas y no crearía nada.

### Campos financieros al nacer

`costoCotizado` = `tarifa.montoCosto`; `montoVenta` y `costoRealOperativo` arrancan en `'0.00'`;
`monedaCotizada` = `monedaReal` = moneda de la tarifa. El delta cotizado ↔ real es el margen
operativo, y hoy nada lo rellena automáticamente.

---

## 4. Los tres estados y por qué son tres

| Enum | Campo | Responde a |
|---|---|---|
| `EstadoReservaEnum` | `OperacionServicio::$estadoReserva` | ¿El proveedor ya me confirmó? `sin-solicitar` → `solicitado` → `confirmado` → `reconfirmado`, más `pendiente-pago`. |
| `EstadoOperacionEnum` | `OperacionServicio::$estadoOperacion` | ¿El servicio ya ocurrió? `pendiente` → `en-proceso` → `completado` / `cancelado`. |
| `EstadoOrdenServicioEnum` | `OperacionOrdenServicio::$estadoOs` | ¿En qué punto está el papeleo? `borrador` → `emitida` → `confirmada` → `completada` / `cancelada`. |

Son ortogonales a propósito: un servicio puede estar `confirmado` con el proveedor y aún
`pendiente` de ejecutarse.

A estos se suman dos campos **de sólo lectura** heredados de la cotización —
`estadoComponente` (`ComponenteEstadoEnum`) y `modoComponente` (`ComponenteModoEnum`) — que
describen cómo estaba el componente al confirmarse, no cómo va la operación.

### Asimetría del listener

**Confirmar crea filas; ningún otro estado las borra.** `propagarEstadoOperacion()` sólo escribe
`estadoOperacion`:

- `CANCELADO` → marca las filas como canceladas, **no las elimina**.
- Volver a `PENDIENTE` / `ENVIADO` / `ARCHIVADO` → devuelve `estadoOperacion` a `pendiente`, pero
  las filas creadas **siguen existiendo**. Des-confirmar no deshace la generación.

Es deliberado: una vez que se pidió al proveedor, el rastro no se borra. Implica que una
cotización confirmada por error deja filas que hay que limpiar con `operacion:resincronizar`.

### Espejos PHP ↔ TypeScript

| PHP | TypeScript | Regla |
|---|---|---|
| `EstadoReservaEnum` | `ESTADO_RESERVA_CONFIG` | añadir un case obliga a añadir la entrada |
| `EstadoOperacionEnum` | `ESTADO_OPERACION_CONFIG` | ídem |
| `EstadoOrdenServicioEnum` | `ESTADO_OS_CONFIG` | ídem |
| `ComponenteTipoEnum` | `TIPO_COMPONENTE_CONFIG` | ídem; la **prioridad NO se replica** |
| `ComponenteModoEnum` | `MODO_COMPONENTE_CONFIG` | ídem |
| `ComponenteEstadoEnum` | `ESTADO_COMPONENTE_CONFIG` | ídem |

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

La vista sólo deja generar una OS si los servicios seleccionados comparten **expediente,
proveedor y moneda**, y ninguno pertenece ya a otra OS (`conflictoSeleccion` en
`OperacionView.vue`). Una OS es una solicitud a un proveedor sobre un expediente; mezclarlos
produciría un documento que nadie puede firmar.

Notas:

- `numeroOs` es `unique` y **no tiene generador**: hoy lo propone la vista
  (`OS-YYYYMMDD-NNN`) y lo confirma el operador. Si choca, el POST falla.
- `OperacionServicio.ordenServicio` es `onDelete: 'SET NULL'` — borrar una OS desasocia sus
  servicios. Pero la colección tiene `orphanRemoval: true`, así que quitarlos **por la colección
  de la OS sí los borra**. Usa siempre el `PATCH` del servicio.
- `OperacionMensaje` guarda `cuerpoHtml` ya renderizado. Es bitácora inmutable para resolver
  disputas con el proveedor, no un borrador editable.

---

## 6. API: endpoints, grupos y filtros

`routePrefix: '/ops'`, todo bajo `/platform/ops/`:

| Recurso | Endpoint | Grupo lectura |
|---|---|---|
| `OperacionServicio` | `operacion_servicios` | `operacion:item:read` |
| `OperacionOrdenServicio` | `operacion_orden_servicios` | `operacion:read` |
| `OperacionMensaje` | `operacion_mensajes` | `operacion:mensaje:read` |

Roles: `ROLE_OPERACIONES_SHOW` (GET), `_WRITE` (POST/PUT/PATCH), `_DELETE`
(`src/Security/Roles.php`).

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
| `SearchFilter` exact | `file`, `cotizacionServicio.cotizacion`, `ordenServicio`, `estadoReserva`, `estadoOperacion`, `proveedorMaestroId`, `tipoComponente`, `modoComponente`, `estadoComponente` |
| `OrderFilter` | `order[fechaServicio]`, `order[horaRecojoReal]`, `order[tipoComponente]`, `order[descripcionServicio]` |

`fechaServicio` usa `DateFilter` y no `SearchFilter`: `exact` sólo permite un día suelto y el
tráfico se planifica por rango. `cotizacionServicio.cotizacion` es un filtro **anidado** que
navega la asociación; no hace falta relación directa a `Cotizacion`.

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

**Filtros:** rango de fechas (con presets Hoy / Mañana / 7 días), expediente por autocompletado,
cotización (versiones del expediente elegido), chips de tipo de componente, y estados de reserva y
operación. `construirParamsBiblia()` en `operacionModel.ts` es el único sitio donde viven los
nombres de los parámetros de query.

**Agrupación:** por día. Dentro del día manda la hora; los servicios sin hora se empujan al final
ordenados por `prioridadOperativa` (guiado/transporte antes que tickets), porque un cuadro de
tráfico se lee de arriba abajo y lo que no tiene hora estorba en medio.

**Edición en línea:** hora, proveedor, estado de reserva y estado de operación se editan en la
propia fila (`PATCH` por campo). No tocan la cotización: registran la realidad operativa.

**Generar OS:** selección múltiple → valida expediente/proveedor/moneda → modal → crea la cabecera
y asocia los servicios. Conecta las dos pestañas, que antes estaban desconectadas.

**Bitácora:** el botón «Mensajes» de una OS abre el hilo y permite registrar un envío nuevo.

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

**Qué componentes deberían generar fila.** Hoy entra todo. `ComponenteTipoEnum` ya distingue lo
despachable (`transporte`, `guiado`, `pool`, `privada`, `tren`, `vuelo`, tickets) de lo que es
sobre todo costo (`alojamiento`, `alimentacion_*`, `extras`), y `prioridad()` existe justamente
para "manifiestos y reportes operativos". Los candidatos a filtrar en `BibliaSnapshotService` son:

- por tipo, si se decide que alojamiento y alimentación no son tráfico;
- por `modoComponente` (`no_incluido`, `reemplazado`), que el editor ya descarta en
  `cotizacionEditorStore.ts`;
- por `estadoComponente` (`cancelado`).

Mientras no se decida, los tres campos viajan en el snapshot y la vista los muestra y filtra.
Cuando se decida: tocar `generarParaCotizacion()` y correr `operacion:resincronizar --todas`.

**Sin generador de `numeroOs`.** La vista propone un número y el operador lo confirma; dos
usuarios simultáneos pueden chocar contra el índice `unique`.

**`montoVenta` y `costoRealOperativo` no se rellenan.** El margen operativo por servicio existe
como columnas pero nadie las escribe todavía.

---

## 10. Dónde tocar para cambiar X

| Necesito… | Archivo | Símbolo |
|---|---|---|
| Cambiar qué dispara la generación | `src/Operacion/EventListener/CotizacionConfirmadaEventListener.php` | `onFlush()` (el `match` de estados) |
| Cambiar qué se copia al snapshot | `src/Operacion/Service/BibliaSnapshotService.php` | `generarParaCotizacion()` |
| **Filtrar qué componentes entran** (§9) | mismo archivo | `generarParaCotizacion()`, guard del bucle |
| Cambiar el texto de la fila | mismo archivo | `resolverDescripcion()` |
| Cambiar qué tarifa manda cuando hay varias | mismo archivo | `resolverTarifaPrimaria()` |
| Cambiar cómo se ubica la fila en el calendario | mismo archivo | `resolverFechaServicio()`, `resolverHoraRecojo()` |
| Aplicar cambios de reglas a lo ya confirmado | `src/Operacion/Command/OperacionResincronizarCommand.php` | `operacion:resincronizar` |
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
| Colores/labels/iconos | `util/src/types/operacionModel.ts` | `ESTADO_*_CONFIG`, `TIPO_COMPONENTE_CONFIG` |
