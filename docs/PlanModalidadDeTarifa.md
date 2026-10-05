# Plan — `esGrupal` pasa a ser un enum de tres casos

> **Estado (05/10/2026): FASES 1 a 5 HECHAS, revisadas y corregidas; queda la 6b.** `operativo` ya
> no es un rol y `calculo` manda; el booleano sobrevive como copia derivada. Una revisión posterior
> de los cálculos financieros encontró **seis fallos**, cinco introducidos por la fase 5 — todos
> corregidos y anotados abajo. Sólo falta el borrado físico del booleano (6b), que **no cambia
> comportamiento**.
>
> **Estado anterior (05/10/2026): FASES 1 a 4 HECHAS.** El clasificador ya reparte las tres modalidades.
> **Y destapó que la operativa no era sólo un redondeo: caía en la clase «⚠️ CONFLICTO» y bloqueaba
> publicar la cotización.** Quedan la 5 (sacar `operativo` del rol) y la 6 (borrar el booleano).
>
> **Estado anterior (05/10/2026): FASES 1, 2 y 3 HECHAS.** El enum, las dos columnas derivadas y los
> consumidores de PHP preguntando al predicado. **Verificado contra producción: 318 costos y 85
> desgloses idénticos byte a byte, suma clavada en 482 136,92.** Siguiente: fase 4, el clasificador
> —la única que sí cambia números.
>
> **Estado anterior (05/10/2026): FASES 1 y 2 HECHAS.** El enum existe y las dos columnas también,
> **derivadas y sin mandar**: `calculo` en `travel_tarifa` y `calculo_snapshot` en
> `cotizacion_cottarifa`, al día desde los setters viejos y sin setter propio. Siguiente: fase 3,
> los cinco consumidores de PHP.
>
> **Estado anterior (05/10/2026): FASE 1 HECHA.** `TarifaCalculoEnum` y su espejo `dominio/cotizacion/
> calculoTarifa.ts` existen, con los tres predicados y 12 tests por los dos lados. **Nada los usa
> todavía**, que es justo lo que hace la fase desplegable sola. Siguiente: fase 2, la columna.
>
> ⚠️ **El enum NO se llama `TarifaModalidadEnum`**, aunque el panel diga «Modalidad de Cálculo»:
> ese nombre ya estaba cogido por `privado`/`compartido`. Ver §5 fase 1.
>
> **Estado anterior (05/10/2026): DISEÑADO, sin empezar.** Hoy existe una proyección de tres opciones en el
> editor (`modalidadDeTarifa()` / `cambiarModalidadTarifa()`), que escribe los dos campos viejos.
> Funciona y desbloquea el caso de los liberados, pero el modelo de datos sigue siendo un booleano
> más un rol. Esto es el plan para cerrarlo de verdad.

---

## 1. Qué se quiere

Sustituir el booleano `esGrupal` / `costoPorGrupo` por un enum de tres valores, y **sacar
`operativo` del enum de rol**, donde hoy convive con `estandar` y `alternativa` sin ser de la misma
familia.

```
                    multiplica        prorratea        lo ve
                    por cantidad      entre pax        el cliente
individual               sí               no              sí
grupal                   no (×1)          sí              sí
operativa                sí               sí              NO
```

`rol` se queda con dos valores —`estandar` y `alternativa`— que es lo único que de verdad describe:
si esta línea compite con otra de cara al cliente.

---

## 2. ⚠️ El hallazgo que decide todo el plan

**El booleano de hoy gobierna DOS comportamientos distintos, y el enum los separa.** Ahora mismo
`esGrupal` decide a la vez:

| | Dónde |
|---|---|
| `× 1` en vez de `× cantidad` | `BibliaSnapshotService::calcularCostoCotizado()`, `OperacionServicio`, `cotizacionEditorStore:1020` |
| `/ numPax` para la cifra por persona | `cotizacionEditorStore:1125` y `:1131` (`ventaPPde` / `costoPPde`) |

Para `individual` y `grupal` las dos van juntas, y por eso un booleano bastaba. **`operativa` las
rompe: multiplica por cantidad Y se prorratea.**

🔑 **Por eso esto NO es un buscar-y-reemplazar.** Cambiar `esGrupal` por `modalidad === 'grupal'`
en los diez sitios dejaría la operativa sin prorratear, y el error saldría como una cifra por
persona baja — plausible, revisada por nadie. Cada sitio hay que leerlo y decidir **cuál de las dos
preguntas** estaba haciendo.

La forma de no equivocarse es no responderlas a mano: el enum expone tres predicados y los sitios
llaman al que corresponde.

```php
multiplicaPorCantidad()   individual ✓   grupal ✗   operativa ✓
seProrratea()             individual ✗   grupal ✓   operativa ✓
visibleParaCliente()      individual ✓   grupal ✓   operativa ✗
```

---

## 3. Lo que dicen los datos (medido el 05/10/2026)

| Combinación actual | Maestro | Snapshots |
|---|---|---|
| `estandar` + individual | 553 | la mayoría |
| `estandar` + grupal | 277 | 65, **todas con `cantidad = 1`** |
| `operativo` + grupal | 22 | **0** |
| `operativo` + individual | 0 | 0 |
| `alternativa` (cualquiera) | 0 | 3, individuales |

Tres consecuencias:

1. **La migración de datos es trivial y lossless.** Las 22 operativas son grupales con
   `cantidad = 1`; leerlas como «× cantidad» da el mismo número.
2. **Ninguna cotización tiene operativas**, así que ningún documento vendido cambia de importe.
3. `alternativa` **nunca** está en el maestro: el enum de rol ofrece en el panel un valor que ahí
   no significa nada. Se puede quitar del formulario del maestro en el mismo viaje.

---

## 4. Inventario de consumidores

### PHP (9 archivos)

| Archivo | Qué hace con el campo |
|---|---|
| `Travel/Entity/TravelTarifa.php` | declara `costoPorGrupo` y `rol`; `__toString()` |
| `Cotizacion/Entity/CotizacionCottarifa.php` | declara `esGrupal` y `rolSnapshot` |
| `Operacion/Service/BibliaSnapshotService.php` | `calcularCostoCotizado()` y `resolverTarifaPrimaria()` (filtra `alternativa`) |
| `Operacion/Entity/OperacionServicio.php` | `getDesgloseCosto()` |
| `Cotizacion/Entity/Cotizacion.php` | `ajustarPax()` — salta las grupales |
| `Cotizacion/Service/CoherenciaCatalogoChecker.php` | `tarifas-no-cubren-pax` (ya salta grupal y operativo) |
| `Travel/Controller/Crud/TravelTarifaCrudController.php` | el formulario del panel |
| `Travel/Command/CrearTarifaBusPorPersonaCommand.php`, `CrearSeguroDeViajeCommand.php` | cargadores archivados |
| `Travel/Enum/TarifaRolEnum.php` | los tres métodos de rol |

### TypeScript (8 archivos)

| Archivo | Usos |
|---|---|
| `util/.../cotizacionEditorStore.ts` | **44 de `esGrupal` + 15 del rol** — aquí está el clasificador |
| `util/.../CotizacionEditorView.vue` | 12 + 3 |
| `util/src/types/cotizacionEditorModel.ts` | 6 + 2 (`esOpcionalParaElCliente`) |
| `util/.../ResumenClasificacion.vue` | 3 + 2 — la etiqueta «Prorrateado / Unitario» |
| `pax/src/types/paxCotizacionModel.ts` | 2 `esGrupal: boolean` anclados **a mano** |
| `pax/.../PaxCotizacionGuiaView.vue` | 1 |

### El clasificador, punto por punto

Es la parte cara. Diez decisiones en `cotizacionEditorStore.ts`:

> ⚠️ Esta tabla se escribió **antes** de leer el código con cuidado y tiene mal los sitios: 1125 y
> 1131 son el camino de los upgrades, no el reparto principal. Se deja como está porque el error es
> parte de lo aprendido — la lista buena está arriba.

| Línea | Hoy | Debe preguntar |
|---|---|---|
| 1020 | `montoBase * (esGrupal ? 1 : tCant) * cCant` | `multiplicaPorCantidad()` |
| 1033 | `cupos = esGrupal ? numPaxGlobal : tCant` | `multiplicaPorCantidad()` |
| 1034 | `if (!esGrupal && … rol==='estandar') paxEstandar += tCant` | ⚠️ **ninguno de los dos** — es cobertura de pax, y una operativa no cubre a nadie |
| 1102, 1108 | `l.esGrupal \|\| l.base.rol !== 'estandar'` | líneas maestras del por-pax |
| 1125, 1131 | `esGrupal ? usd / numPax : usd` | **`seProrratea()`** ← el que cambia de verdad |
| 1158-1170 | cobertura de las alternativas | `multiplicaPorCantidad()` |
| 1325, 1340 | agrupación de la ficha | presentación |

⚠️ **Y una trampa aparte:** `resolverGrupal()` (líneas 613 y 849) cae al **maestro** cuando el
snapshot no trae el campo. Si se quita `costoPorGrupo` del maestro sin más, ese respaldo devuelve
`false` en silencio y las grupales viejas empiezan a multiplicarse por pax. El maestro necesita el
enum **antes** de que el snapshot deje de tener el booleano.

---

## 5. Fases

Cada una se despliega sola y deja el sistema funcionando.

### Fase 1 — El enum y sus tres predicados. Sin cambiar nada. ✅ HECHA

`App\Travel\Enum\TarifaCalculoEnum` y su espejo `dominio/cotizacion/calculoTarifa.ts`, con los
tres predicados de §2 y sus tests a los dos lados (6 + 6). **Nada lo usa todavía**, que es lo que
hace la fase desplegable sola.

⚠️ **El nombre NO es `TarifaModalidadEnum`, y conviene saber por qué.** Ese archivo ya existía —es
`privado`/`compartido`, el nivel de exclusividad del servicio— y el primer intento lo **sobrescribió
entero**: el enum se escribió con `cat >` sobre una ruta que nadie comprobó. Lo destapó PHPStan en
la misma pasada (`TarifaModalidadEnum::PRIVADO` dejó de existir, 11 errores) y se restauró desde
git sin que llegara a ningún commit.

La trampa es el vocabulario: el panel llama **«Modalidad»** a `privado`/`compartido` y **«Modalidad
de Cálculo»** a esto. Dos cosas con el mismo nombre de pila invitan exactamente a ese error, así que
aquí se llaman distinto y cada uno lleva el aviso de no confundirse con el otro.

El espejo de TS tiene además un test que ningún predicado por separado daría: **que las dos primeras
preguntas no sean la misma**. Si alguien las volviera a colapsar en un booleano, se cae.

### Fase 2 — La columna, conviviendo con el booleano ✅ HECHA

`Version20261005120000`. Las dos columnas nulables, rellenadas con la traducción de §3, y la copia
mantenida al día por `sincronizarCalculo()`, que corre desde **los dos** setters viejos.

⚠️ **Sin setter público, a propósito.** Un tercer campo escribible junto a los dos que mandan
acabaría diciendo otra cosa, y el que perdería sería el nuevo porque es el que nadie lee todavía.
Se vuelve escribible en la fase 6. Como efecto, API Platform lo publica `readonly` — se ve en
`api.d.ts`, y el maestro sale ya como unión cerrada `"individual" | "grupal" | "operativa"`.

Y `getCalculo()` **nunca devuelve null**: si la columna no está escrita, la deriva. Un null
obligaría a cada consumidor de la fase 3 a decidir qué hacer, que es como se reparten las reglas
por el código.

Lo que fija el test (`CalculoDerivadoTest`) no es la traducción —es un `match` de tres líneas— sino
que se rehaga **desde los dos setters y en cualquier orden**. Si uno se olvidara, el campo se
quedaría con lo anterior; y como en esta fase nadie lo lee, el error viviría tranquilo hasta la
fase 3, donde ya sería un precio mal calculado.

#### Fase 2 — notas de la redacción original

Migración: `travel_tarifa.modalidad` y `cotizacion_cottarifa.modalidad_snapshot`, **nulables**, y
relleno desde lo que ya hay:

```sql
operativa   WHERE rol = 'operativo'
grupal      WHERE costo_por_grupo = 1 AND rol <> 'operativo'
individual  en el resto
```

El booleano sigue siendo el que manda. Las entidades ganan getter y setter, y **los dos setters
escriben los dos campos** mientras dure la convivencia: un dato a medias es peor que el viejo.

⚠️ Al añadir campo mapeado: `rm -rf var/cache/dev` antes de sondear nada, y regenerar `api.d.ts`.

### Fase 3 — Los consumidores de PHP, de uno en uno ✅ HECHA

| Sitio | Pregunta ahora |
|---|---|
| `calcularCostoCotizado()` | `multiplicaPorCantidad()` |
| `getDesgloseCotizado()` | `multiplicaPorCantidad()` |
| `ajustarPax()` | `multiplicaPorCantidad()` **y** `visibleParaCliente()` |
| `tarifas-no-cubren-pax` | `calculo = 'individual'` — los dos de una vez |

🔑 **`ajustarPax()` es el que enseña por qué no era un reemplazo mecánico.** Su pregunta no es «¿es
grupal?» sino «¿su cantidad significa pasajeros?». Una grupal es un precio cerrado y una operativa
cuenta **unidades** —cinco vuelos liberados— que no son cinco pax: las dos se quedan fuera, por
razones distintas. Con un enum comparado contra `'grupal'` la operativa habría seguido al grupo y
cinco vuelos se habrían convertido en sesenta.

Y el chequeo de coherencia **se simplifica**: donde miraba `es_grupal` y excluía dos roles, ahora
pregunta una sola cosa.

#### El puente: `getCalculoDeTarifa()`

La columna guarda **texto** a propósito —un snapshot conserva el vocabulario del día en que se
vendió— pero las reglas no se escriben comparando cadenas. Un valor que el enum no conozca cae a
`INDIVIDUAL`, el único respaldo que no cambia ningún número por sí solo.

#### La verificación, que es el punto de la fase

Una sonda recorrió **las 318 componentes y las 85 filas de La Biblia de producción** antes y
después del despliegue:

```
COSTOS QUE CAMBIARON:     0
DESGLOSES QUE CAMBIARON:  0
suma antes 482 136,92  ·  suma después 482 136,92
```

Era lo esperado —no hay operativas en ninguna cotización, así que los predicados sobre individual y
grupal dan lo de antes— pero **esperarlo no es comprobarlo**, y la fase 4 va a cambiar números de
verdad: conviene llegar sabiendo que hasta aquí no se movió nada.

#### Fase 3 — notas de la redacción original

Cinco sitios reales (`calcularCostoCotizado`, `getDesgloseCosto`, `ajustarPax`,
`CoherenciaCatalogoChecker`, el CRUD). Cada uno pasa a preguntar al predicado que le toca.

El cambio de comportamiento aquí es **cero**, porque no hay operativas en ninguna cotización: los
predicados sobre individual y grupal dan exactamente lo de antes. Es el momento de confirmarlo con
una sonda que recalcule los costos de las cotizaciones vivas **antes y después** y exija que no se
mueva ni un céntimo.

### Fase 4 — El clasificador ✅ HECHA

⚠️ **El plan tenía mal el sitio.** §4 decía que lo que cambiaba eran las líneas 1125/1131
(`ventaPPde` / `costoPPde`), y ésas son el camino de los **upgrades**. El prorrateo de verdad está
en el reparto por clase de pasajero:

```js
if (l.esGrupal) { clases.forEach(c => registrar(c, l, c.cantidad)); }  // entre TODOS
else            { asignar(l, l.cantidad); }                            // por cantidad
```

Cinco sitios, y cada uno pregunta lo suyo:

| Sitio | Pregunta |
|---|---|
| `costoTotal` | `multiplicaPorCantidad()` |
| **`cupos`** | **`seProrratea()`** — es «entre cuántos se divide», no «cómo se multiplica» |
| `paxEstandar` | `calculo === 'individual'` |
| el reparto por clase | `seProrratea()` |
| `tuvoLineasPorPax` | `calculo === 'individual'` |

#### 🔥 Lo que destapó el test

Antes de esto, una operativa de cantidad 5 en un grupo de 10 **ya cubierto** producía:

```
Cualquier Nacionalidad         10 pax   10 000
⚠️ CONFLICTO: Cualquier Nac.    5 pax      400
```

Los cinco vuelos se leían como **cinco pasajeros más que nadie cubre**, y la clase de conflicto
**bloquea publicar** (`publicable = false`). No era una cifra mal redondeada: era **no poder vender
el viaje por haber cotizado bien los liberados**. Eso explica, mejor que ninguna otra cosa, por qué
el rol operativo llevaba 22 fichas en el catálogo y cero usos.

#### El respaldo tiene que mirar el ROL

`comoCalculo(t.calculoSnapshot ?? …)` cae, cuando el snapshot no trae el campo, a la misma
derivación que `CotizacionCottarifa::calculoDerivado()` — **incluido el rol**. Si cayera sólo al
booleano, una operativa recién marcada se repartiría mal hasta recargar la página: la clase de
diferencia que nadie atribuye al código.

#### El test, y por qué el primero no servía

`util/src/stores/cotizacion/calculoDeTarifa.test.ts`. La primera versión medía el total del bucket
y **pasaba antes del cambio** — o sea no probaba nada: el bucket ya multiplicaba bien. Lo que la
fase mueve es **a cuántos pasajeros se reparte cada línea**, y eso sólo se ve en el detalle por
clase.

Los de `individual` y `grupal` pasaban antes y siguen pasando: son el 100 % de los datos de hoy y
no pueden moverse.

#### Fase 4 — notas de la redacción original

La fase cara, y la que de verdad cambia algo: las líneas 1125 y 1131 pasan de `esGrupal` a
`seProrratea()`, y ahí es donde la operativa empieza a prorratearse.

**Antes de tocar: snapshots de `clasificacionFinanciera` de las cotizaciones vivas.** Es el
material que `dominio/` ya sabe probar (`__snapshots__`), y un snapshot que cambie se lee y se
decide, no se actualiza con `-u`.

### Fase 5 — Sacar `operativo` del rol ✅ HECHA (con la 6a dentro)

⚠️ **La 5 y la 6 estaban entrelazadas y este plan no lo vio.** Sacar `operativo` del rol obliga a
que `calculo` sea escribible, que era trabajo de la 6. Se invirtió la derivación —`calculo` manda,
`esGrupal` es la copia— y el borrado físico quedó aparte como **6b**.

#### 🔥 Lo que el tipo generado destapó

Al regenerar `api.d.ts`, `rol` se estrechó a `"estandar" | "alternativa"` y `vue-tsc` señaló **ocho
sitios** que seguían comparando contra `'operativo'`. Uno era el filtro que esconde las operativas
del cliente (`expurgarParaCliente`): mirando el rol, el guía y los liberados habrían **empezado a
publicarse en la propuesta del huésped** sin que nada fallara.

#### 🔥 Y un fallo que sólo salió corriendo el test

La capacidad de cada clase de pasajero sale de `maestroLineas`, que filtraba `!esGrupal`. Una
operativa no es grupal, así que entraba — y su `cupos` es `numPax`:

```
10 pax reales + una operativa de cupos 10  →  clase de 20 pax
                                              10 800 en vez de 10 400
                                              y «no cubre a todos los pasajeros»
```

Las líneas que definen **cuántos pasajeros hay** son sólo las `individual`.

#### 🔥 La revisión posterior: seis fallos más

Terminadas las fases, un agente revisor leyó **todos** los cálculos financieros. El peor no era de
patrón:

```
el editor manda   calculoSnapshot      ← el nombre real del campo
el setter era     setCalculo()         → la clave escribible era `calculo`
api.d.ts decía    readonly calculoSnapshot
```

**Una operativa marcada en el editor NO se guardaba.** Volvía como `individual`, su cantidad se
leía como pasajeros, aparecía en la propuesta y disparaba «⚠️ CONFLICTO», que bloquea publicar. Y
**el costo total no cambiaba** —× cantidad en los dos casos—, así que nada lo delataba.

⚠️ **La lección: el serializer empareja el setter con la propiedad POR EL NOMBRE.** Un setter
«bonito» sobre un campo con sufijo crea una propiedad virtual y deja la real en sólo lectura, sin
que nada falle. El `readonly` en `api.d.ts` lo estaba diciendo y se leyó como intencional.

Los otros cinco son el mismo patrón: donde el código apartaba las operativas filtrando **por rol**,
el rol dejó de distinguirlas.

| Sitio | Qué se escapaba |
|---|---|
| `construirInclusiones()` | su título salía en «qué incluye» del huésped |
| bloque de upgrades | promediaba su precio en la base → **delta de upgrade falso**, y su nombre interno podía ser el «espejo» tachado |
| `esOpcionalParaElCliente()` | un opcional con un liberado dejaba de serlo y pasaba a publicarse |
| `selloDeComponente()` en `pax` | filtro muerto: nombre interno de compra como sello del itinerario |
| `mapearATarifaSnapshot()` | no copiaba el cálculo: una operativa del catálogo se persistía como **grupal** |

La revisión confirmó correctos la aritmética central del clasificador, `expurgarParaCliente()`
sobre el detalle, los tres consumidores de PHP, el chequeo de coherencia y `src/Finanzas/` entero.

#### Lo demás de la fase

- `setRolSnapshot('operativo')` **traduce en vez de rechazar**: un cliente desactualizado no es un
  error, y rechazarlo rompería el guardado entero por un campo.
- `setCostoPorGrupo(false)` **no pisa una operativa**: también es «no grupal».
- El botón «Operativa» del conmutador de rol, puesto esa misma mañana, **se quitó**: vive en
  «Modalidad de Cálculo».

#### Redacción original de la fase 5

`TarifaRolEnum` se queda con `estandar` y `alternativa`. Hay que revisar sus tres métodos:
`esVisibleParaCliente()` y `comisionEditablePorDefecto()` pierden su única rama falsa y pasan a la
modalidad; `sumaRamaPrincipal()` se queda sólo con `estandar`.

Migración de datos: `UPDATE travel_tarifa SET rol = 'estandar' WHERE rol = 'operativo'` — las 22,
que ya tendrán `modalidad = 'operativa'` desde la fase 2.

Y de paso: quitar `alternativa` del formulario del maestro, donde nunca significó nada.

### La regla de quién puede cambiar de modalidad (05/10/2026)

| | Individual | Grupal | Operativa |
|---|---|---|---|
| Tarifa **suelta** | libre | libre | libre |
| Tarifa **del catálogo** | la que diga el maestro | ídem | **libre** |
| Del catálogo y **el maestro es operativa** | — | — | fija |

**Individual y grupal describen cómo cotiza el PROVEEDOR**, y eso lo fija el tarifario: cambiarlo
dentro de una cotización sería contradecir al catálogo. **Operativa no dice cómo cotiza el
proveedor sino cómo lo asumes tú** —ese gasto se reparte y no se enseña—, y eso es una decisión de
esa venta: por eso queda libre aunque la tarifa venga del catálogo. Es justo el caso de los
liberados, que no se van a dar de alta en el maestro.

Si el maestro ya es operativa, **queda fija**: no hay a dónde volver, la tarifa lo es por
definición.

⚠️ **Y la vuelta devuelve a la modalidad DEL CATÁLOGO, no a individual.** La primera versión caía
siempre a individual, así que una grupal del tarifario marcada operativa y devuelta pasaba de
`× 1` a `× cantidad` **en silencio**. Hoy no mordería —las 277 grupales del maestro tienen
`cantidad = 1`— pero es la misma trampa de poner sin poder quitar bien que ya apareció dos veces
en este plan. Lo fija `modalidadesDisponibles()` y su test.

### Fase 6b — Borrar el booleano (PENDIENTE)

`esGrupal` / `costoPorGrupo` siguen como copia derivada, y quitarlos **no cambia comportamiento**:
el modelo ya es correcto. Lo que falta tiene dos aristas:

- `esGrupal` **viaja al cliente** (`LineaDetalleClaseCliente`), así que quitarlo cambia el contrato
  público y los tipos anclados a mano de `pax`.
- ~40 lecturas en `util` que aún lo usan para etiquetas y agrupaciones.

Redacción original: `costoPorGrupo`, `esGrupal`, la proyección provisional del editor
(`modalidadDeTarifa` / `cambiarModalidadTarifa`) y los `esGrupal` anclados a mano en `pax`.
Regenerar `api.d.ts` y comprobar que `pax` compila: su typecheck es la única comprobación
automática de esa frontera.

---

## 6. Riesgos, y cuál vigila cada uno

| Riesgo | Qué lo caza |
|---|---|
| Un sitio pregunta el predicado equivocado | la sonda de la fase 3 (costos antes/después) y los snapshots de la 4 |
| El respaldo al maestro de `resolverGrupal()` | orden de las fases: el maestro tiene el enum desde la 2 |
| `pax` se queda con el campo viejo | su `typecheck`, y por eso se borra al final |
| Una cotización vendida cambia de importe | hoy no hay operativas en ninguna; si aparece alguna entre medias, la fase 4 la vería |
| El baseline de PHPStan | `PhpstanBaselineTest` fija familias y total; **no se añaden entradas** |

⚠️ **Lo que NO se hace: la validación «operativa ⇒ grupal».** Se propuso y habría bloqueado el caso
que originó todo —cinco vuelos liberados son una operativa **unitaria** con `cantidad = 5`—. Las 22
del catálogo son grupales porque describen lo que había, no lo que puede haber.

---

## 7. Dónde tocar para cambiar X

| Necesito… | Archivo | Símbolo |
|---|---|---|
| Añadir un cuarto caso | `src/Travel/Enum/TarifaCalculoEnum.php` + `dominio/cotizacion/calculoTarifa.ts` | el enum y sus tres predicados — se tocan LOS DOS |
| No confundir los dos «modalidad» | `src/Travel/Enum/TarifaModalidadEnum.php` | ése es `privado`/`compartido`, nada que ver |
| Entender por qué no es un reemplazo | este documento | §2 |
| Saber qué consumidores quedan | este documento | §4 |
| La proyección provisional de hoy | `util/src/stores/cotizacion/cotizacionEditorStore.ts` | `modalidadDeTarifa()` — se borra en la fase 6 |
