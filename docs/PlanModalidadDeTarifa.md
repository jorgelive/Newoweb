# Plan — `esGrupal` pasa a ser un enum de tres casos

> **Estado (05/10/2026): FASE 1 HECHA.** `TarifaCalculoEnum` y su espejo `dominio/cotizacion/
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

### Fase 2 — La columna, conviviendo con el booleano

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

### Fase 3 — Los consumidores de PHP, de uno en uno

Cinco sitios reales (`calcularCostoCotizado`, `getDesgloseCosto`, `ajustarPax`,
`CoherenciaCatalogoChecker`, el CRUD). Cada uno pasa a preguntar al predicado que le toca.

El cambio de comportamiento aquí es **cero**, porque no hay operativas en ninguna cotización: los
predicados sobre individual y grupal dan exactamente lo de antes. Es el momento de confirmarlo con
una sonda que recalcule los costos de las cotizaciones vivas **antes y después** y exija que no se
mueva ni un céntimo.

### Fase 4 — El clasificador

La fase cara, y la que de verdad cambia algo: las líneas 1125 y 1131 pasan de `esGrupal` a
`seProrratea()`, y ahí es donde la operativa empieza a prorratearse.

**Antes de tocar: snapshots de `clasificacionFinanciera` de las cotizaciones vivas.** Es el
material que `dominio/` ya sabe probar (`__snapshots__`), y un snapshot que cambie se lee y se
decide, no se actualiza con `-u`.

### Fase 5 — Sacar `operativo` del rol

`TarifaRolEnum` se queda con `estandar` y `alternativa`. Hay que revisar sus tres métodos:
`esVisibleParaCliente()` y `comisionEditablePorDefecto()` pierden su única rama falsa y pasan a la
modalidad; `sumaRamaPrincipal()` se queda sólo con `estandar`.

Migración de datos: `UPDATE travel_tarifa SET rol = 'estandar' WHERE rol = 'operativo'` — las 22,
que ya tendrán `modalidad = 'operativa'` desde la fase 2.

Y de paso: quitar `alternativa` del formulario del maestro, donde nunca significó nada.

### Fase 6 — Borrar el booleano

`costoPorGrupo`, `esGrupal`, la proyección provisional del editor
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
