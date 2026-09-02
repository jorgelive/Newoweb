# Plan — la propuesta operativa, el acceso por documento y el filtrado por subgrupo

Cómo se representa **lo que de verdad va a pasar en el viaje** cuando deja de parecerse a lo que se
vendió, sin duplicar el expediente y sin que el cliente pierda los precios que aprobó.

**Alcance:** plan de ejecución con fases, criterios de «hecho» y lo que se deja fuera a propósito.
El modelo de propuestas está en `docs/Cotizaciones.md` §6.j.0 y §6.j.

**Estado:** redactado el 02/09/2026. Sin empezar.

---

## Índice

1. [El caso, tal como ocurre](#1-el-caso-tal-como-ocurre)
2. [La forma que sale del modelo que ya existe](#2-la-forma-que-sale-del-modelo-que-ya-existe)
3. [El criterio de orden](#3-el-criterio-de-orden)
4. [Fase A — la propuesta operativa](#fase-a--la-propuesta-operativa)
5. [Fase B — el acceso por documento](#fase-b--el-acceso-por-documento)
6. [Fase C — el filtrado por subgrupo](#fase-c--el-filtrado-por-subgrupo)
7. [Lo que NO entra, y cómo entraría](#7-lo-que-no-entra-y-cómo-entraría)
8. [Dónde choca con el plan del cálculo compartido](#8-dónde-choca-con-el-plan-del-cálculo-compartido)
9. [Decisiones abiertas](#9-decisiones-abiertas)
10. [Cómo saber que va bien](#10-cómo-saber-que-va-bien)

---

## 1. El caso, tal como ocurre

Se cotiza un grupo: todo junto, un vuelo, N pax, «todo bonito». Después la realidad se separa:

- El grupo se parte en **vuelos distintos**, nacional e internacional.
- Algunos **se integran ya en Punta Cana**.
- El servicio «Vuelo» acaba con **dos componentes de vuelo**.
- Las cantidades por componente **dejan de sumar el total del grupo**.

Y sobre eso hay que emitir las órdenes. Pero el cliente tiene que seguir viendo **los precios que
aprobó**, aunque el itinerario ya sea otro.

## 2. La forma que sale del modelo que ya existe

⚠️ **No es un documento nuevo.** La operativa es **hermana del histórico**: misma propuesta,
distinguida por estado, colgando de la aprobada por el mismo `derivadaDe` que ya existe. Ninguna
consume número de propuesta.

```
Expediente
 └── PROPUESTA (v1, v2…)      alternativas o COMPLEMENTOS · el cliente elige una o varias
       ├── HISTORICO ×N        derivadaDe → la viva      no pública · foto del pasado
       ├── la aprobada         CONFIRMADO/ENVIADO        pública    · financiero cliente ORIGEN
       └── OPERATIVA           derivadaDe → la aprobada  pública    · financiero cliente HEREDADO
                                                                    · interno recalculado → órdenes
```

**Las dos superficies financieras ya existen y encajan solas:**

| | Qué lleva | En la operativa |
|---|---|---|
| `clasificacionFinanciera` | costos y márgenes · **interno** | **se mueve** — de ahí salen las órdenes |
| `clasificacionFinancieraCliente` | sin costos ni márgenes · **el cliente** | **se hereda y NO se regenera** |

Y el eje de subgrupos ya soporta el multitramo **sin tocar código**: `reserva_aerea` con subeje
libre (`#Vuelo Nacional`, `#Vuelo Internacional`, `#Vuelo Punta Cana`).

## 3. El criterio de orden

**Primero lo que no falla ruidosamente.**

Añadir el estado es ruidoso casi en todas partes —tres `match` exhaustivos en el enum y la unión
generada de los `api.d.ts` hacen saltar el compilador—, así que esos sitios se cazan solos. Lo que
va primero es lo que **hace otra cosa en silencio**:

| Riesgo | Qué pasa hoy | Ruido |
|---|---|---|
| El padrón abierto | cualquiera con el enlace ve los documentos de todos | 🔇 ninguno: funciona «bien» |
| El `match` del listener | un estado nuevo cae en `default => null` y deja operación activa | 🔇 su propio doc lo predice |
| Dos públicas por propuesta | el provider devuelve la que MySQL quiera | 🔇 ya mordió una vez |
| El snapshot del cliente | **guardar lo reescribe** | 🔇 le cambia los precios al cliente |

## Fase A — la propuesta operativa

| | Acción | Hecho cuando |
|---|---|---|
| A.1 | ⚠️ **El `match` de `CotizacionConfirmadaEventListener`**, con el caso explícito | Un estado nuevo no puede pasar en silencio |
| A.2 | ⚠️ **Desempate explícito del provider público** dentro de una propuesta | Con aprobada + operativa, sirve la que se decida — no la que ordene MySQL |
| A.3 | `OPERATIVA` en `CotizacionEstadoEnum` + `esPublico()`, `esHistorico()`, `badgeColor()` | Compila |
| A.4 | Processor «crear operativa»: clona la aprobada, pone `derivadaDe` y **hereda** `clasificacionFinancieraCliente` | Existe el botón y crea la fila |
| A.5 | ⚠️ **Candado en el SERVIDOR**: una operativa rechaza un guardado que cambie `clasificacionFinancieraCliente` | Un `curl` con otro snapshot devuelve 422 |
| A.6 | Front: mapa de estados, botón de crear, y que el editor diga dónde está | El operador ve «Propuesta 2 · operativa» |

⚠️ **A.5 no puede vivir en el front.** El editor regenera ese snapshot **en cada guardado**
(`payload.clasificacionFinancieraCliente = expurgarParaCliente(fin)`), sin preguntar y sin avisar.
Una convención —«acuérdate de no guardar»— le cambiaría los precios al cliente el primer día. Es el
mismo patrón que la fase 6 de `PlanProcesamientoCompartido.md`: **PHP comprueba y rechaza.**

⚠️ **A.2 ya mordió antes.** `(file_id, propuesta)` no es único, y el provider hacía `findOneBy` y
comprobaba el estado después: MySQL le entregaba la foto congelada y respondía 404 con un enlace
válido. Ahora filtra en la consulta — pero con **dos filas públicas** eso ya no desempata.

## Fase B — el acceso por documento

⚠️ **B.1 no es una función nueva: es cerrar algo que hoy está abierto.** `PaxFilePortadaView` pinta
el padrón entero **con los números de documento de todos**, para cualquiera que tenga el enlace. Y
`exigeIdentificacion` existe, devuelve `true` en modo grupo… y **no lo consume nadie**: sólo aparece
en el `api.d.ts` generado. La puerta está declarada y sin construir.

| | Acción | Hecho cuando |
|---|---|---|
| B.1 | La portada deja de listar a todos los pasajeros con sus documentos | Un enlace sin identificar no expone a nadie |
| B.2 | Endpoint: documento + fecha de nacimiento → **token acotado a ese pasajero** | Devuelve token o 401, sin decir cuál de los dos falló |
| B.3 | El provider público filtra por ese token | Un token de A no ve los datos de B |
| B.4 | Límite de intentos | Un bucle no enumera el padrón |

⚠️ **B.4 no es paranoia:** la llave son documento y fecha de nacimiento, datos que circulan por
WhatsApp en un grupo de colegio. Sin límite, el padrón es enumerable por quien tenga el enlace.

⚠️ **El 401 no distingue** «ese documento no está» de «la fecha no coincide». Distinguirlos
convierte el formulario en un comprobador de quién viaja.

## Fase C — el filtrado por subgrupo

| | Acción | Hecho cuando |
|---|---|---|
| C.1 | `CotizacionCotcomponente.grupo` → `?CotizacionFileGrupo`, **null = aplica a todos** | Migración |
| C.2 | `CotizacionPasajeroGrupo.codigo` → el localizador aéreo **de esa persona en ese vuelo** | Migración |
| C.3 | Editor: asignar subgrupo a un componente | Se puede decir «este vuelo es de los del subeje Nacional» |
| C.4 | Chequeo de coherencia: **unión(subgrupos) ⊇ pasajeros** | Avisa de huecos (alguien sin vuelo) y de solapes |
| C.5 | Guía: el pasajero identificado ve sólo lo suyo, con su código | |
| C.6 | Órdenes: salen con la gente del subgrupo | |

⚠️ **`null` = sin acotar, no «sin clasificar»**, igual que en skills y en la guía del huésped. Va
contra la intuición y es deliberado: un olvido deja el componente **de más** —inofensivo— en vez de
invisible, y lo segundo no se descubre nunca.

⚠️ **C.4 cambia la invariante, y ése es el punto.** Lo correcto **no** es

```
suma(cantidades) == numPax        ← falso en cuanto hay dos vuelos
unión(subgrupos)  ⊇ pasajeros     ← esto sí se puede comprobar
```

Que las cantidades no cuadren deja de ser una anomalía a tolerar: pasa a ser la forma normal, y lo
que se vigila es otra cosa.

⚠️ **El código de vuelo va en el PIVOTE**, no en el pasajero ni en el vuelo: una persona tiene un
código por vuelo, y un vuelo tiene un código por persona. Es un dato de la intersección, que es
justo lo que `CotizacionPasajeroGrupo` representa.

## 7. Lo que NO entra, y cómo entraría

**Los que se integran a mitad de viaje.** Descartado a conciencia el 02/09/2026.

El motivo no es que sea raro: es que **con subgrupos habría que describir una ausencia**. Para que
a esas personas no les salgan los hoteles y tours que no hacen, habría que etiquetar **todos** esos
componentes — N anotaciones que crecen con el viaje, no con el caso.

Compáralo con los vuelos, donde el mismo diseño es barato: el servicio se parte en dos componentes
y cada uno lleva su subgrupo. **Dos anotaciones.** La diferencia es que ahí describes lo que pasa.

> **La regla que sale de esto: si una función te obliga a anotar todo, el dato está en la entidad
> equivocada.**

**Si el caso vuelve, vuelve barato** y sin rehacer nada:

```
CotizacionFilepasajero
  + participaDesde / participaHasta      (null = todo el viaje)

el pasajero ve un componente si:
    (no tiene subgrupo  O  la persona está en él)
  Y (el día del componente cae dentro de su tramo)
```

Un campo, en una persona, y ningún componente se entera. Es aditivo: una columna y un `AND` más.

## 8. Dónde choca con el plan del cálculo compartido

**Un componente acotado a 12 de 40 pax tiene que valorarse por 12.** Y el cálculo financiero vive
**sólo en el navegador**: el servidor no lo alcanza.

Consecuencia práctica: **C se puede construir entero salvo el precio.** Los vuelos, los horarios,
el namelist y las órdenes funcionan sin ello; la valoración de un componente acotado espera a la
fase 5 de `docs/PlanProcesamientoCompartido.md`.

No es un bloqueo, pero conviene no descubrirlo a mitad.

## 9. Decisiones abiertas

Son de producto, no técnicas:

| | La pregunta |
|---|---|
| 1 | **Qué ve quien NO se identifica**: ¿nada, la portada sin padrón, o el itinerario común sin datos personales? |
| 2 | **Si dos propuestas aprobadas son complementarias** (Lima + Bolivia), ¿la guía las fusiona por fecha o las enseña sueltas? |
| 3 | **Cuando existe operativa**, ¿gana siempre sobre la aprobada en la vista del cliente, o se elige a mano? |

La 2 es la más grande: el pasajero vive **un viaje**, no dos documentos — pero eso hay que quererlo,
no deducirlo.

## 10. Cómo saber que va bien

1. **Nadie ve un documento de identidad que no sea suyo.** Es lo primero y lo único urgente.
2. **Añadir un estado no puede pasar en silencio por ningún `match`.**
3. **Guardar una operativa no cambia lo que el cliente ya aprobó** — comprobado con un `curl`, no
   con buena voluntad.
4. **El chequeo avisa de huecos y solapes**, y nadie vuelve a mirar si las cantidades suman.
5. **Ningún componente lleva subgrupo «para que no salga»** — si aparece esa necesidad, el dato
   está en la entidad equivocada (§7).

---

## Dónde tocar para cambiar X

| Necesidad | Archivo |
|---|---|
| Qué estados ve el cliente | `CotizacionEstadoEnum::esPublico()` |
| Qué pasa al cambiar de estado | `CotizacionConfirmadaEventListener` — el `match`, **explícito siempre** |
| Qué propuesta sirve pax | `CotizacionFilePublicProvider` |
| Que el cliente conserve sus precios | el candado de A.5, en el servidor |
| A quién aplica un componente | `CotizacionCotcomponente::$grupo` (C.1) |
| El código de vuelo de una persona | `CotizacionPasajeroGrupo::$codigo` (C.2) |
| Los ejes de agrupación | `GrupoTipoEnum` — el subeje es texto libre, no hace falta un `case` por tramo |
