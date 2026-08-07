# Disponibilidad — qué casitas puedo vender

Cómo se responde «¿qué tengo libre del 12 al 15?», qué estados bloquean una noche y por qué
la regla de solape se compara por día y no por instante.

Alcance: `src/Pms/Service/Reserva/PmsDisponibilidadService.php`, `src/Pms/Dto/PmsUnidadDisponibleDto.php`,
`PmsEventoEstado::IMPIDEN_VENTA` y el comando `app:pms:disponibilidad`.

---

## 1. Para qué existe

Nació como la herramienta que consulta el agente interno del panel, pero **no sabe nada de IA**.
Es una consulta de negocio reutilizable: el drawer de reservas, las cotizaciones o un motor de
reserva público pueden llamarla igual. El agente es sólo su primer consumidor.

## 2. El rango es semiabierto, como una estancia

`buscar(desde, hasta)` devuelve las casitas libres **todas** las noches de `[desde, hasta)`.

```
        12      13      14      15
        ├───────┼───────┼───────┤
        │ noche │ noche │ noche │        buscar('2026-03-12', '2026-03-15')
        └───────┴───────┴───────┘        → 3 noches: 12, 13 y 14
                                          el día 15 la casita ya está libre
```

De ahí que una reserva que **se va** el 12 no estorbe a una consulta que **empieza** el 12: esa
noche la casita queda libre. Y una que **entra** el 15 tampoco estorba.

## 3. 🔑 El solape se compara por DÍA, no por instante

`inicio` y `fin` llevan la hora de check-in y check-out (hora de pared — §12.5.5 de
`PmsBeds24ReservasSync.md`). Comparar los instantes tal cual **sobre-ocupa**:

```
Reserva que sale el 12 a las 10:00      fin = 2026-03-12T10:00
Consulta que entra el 12                desde = 2026-03-12T00:00

  ¿fin > desde?  →  10:00 > 00:00  →  TRUE  →  ✗ la casita parece ocupada
  ¿DATE(fin) > desde?  →  12 > 12  →  FALSE →  ✓ la casita está libre
```

Por eso `unidadesOcupadas()` va en **SQL nativo con `DATE()`**: DQL no expone esa función y el
truco de comparar contra las 23:59 depende de que ningún check-out sea de madrugada.

La condición de solape es la estándar de rangos semiabiertos:

```sql
DATE(e.inicio) < :hasta   AND   DATE(e.fin) > :desde
```

## 4. Qué bloquea una noche: `IMPIDEN_VENTA`

⚠️ **No es `OCUPAN_UNIDAD`.** Las dos listas viven en `PmsEventoEstado`, se parecen, y
responden a preguntas distintas — por eso se declaran por separado y **no** se deriva una de
la otra:

| Estado | `OCUPAN_UNIDAD`<br>(¿pinto beige en tarifas?) | `IMPIDEN_VENTA`<br>(¿puedo alojar a alguien?) |
|---|:---:|:---:|
| `pendiente` | ✅ | ✅ |
| `confirmada` | ✅ | ✅ |
| `requerimiento` | ✅ | ✅ |
| **`bloqueo`** | ❌ no es venta que tarifar | ✅ **casita cerrada** |
| **`extension`** | ❌ invisible en el PMS | ✅ **noche inutilizada** (§7.1.b) |
| `cancelada` | ❌ | ❌ |
| `abierto` (inquiry) | ❌ | ❌ — es una consulta, no retiene la unidad |

Si algún día se decide que un inquiry de Airbnb sí retiene la casita, se añade a
`IMPIDEN_VENTA` — **no** a `OCUPAN_UNIDAD`, o se teñirá el calendario de tarifas sin querer.

## 5. 🔥 Gotcha: los UUID binarios en un `IN` de DQL

`pms_unidad.id` es `BINARY(16)`. Un `NOT IN` de DQL alimentado con **UUID canónicos** —o
incluso con objetos `Uuid`— **no casa con ninguna fila y no lanza error**: la consulta
devuelve todas las casitas como libres. En un servicio de disponibilidad ese fallo mudo
**sobrevende**.

Hay que pasar binarios crudos con el tipo explícito, el mismo patrón que
`Beds24SendQueueRepository::hydrateItems()`:

```php
->setParameter(
    'ocupadas',
    array_map(static fn (string $id) => Uuid::fromString($id)->toBinary(), $ocupadas),
    ArrayParameterType::BINARY
)
```

**Cómo se comprueba que funciona:** no basta con que la consulta no reviente — hay que ver que
una casita ocupada **desaparece** del listado. Ese fue exactamente el fallo durante el
desarrollo: devolvía las 7 casitas con 5 de ellas ocupadas.

## 6. Decisiones de producto

- **Capacidad sin declarar no descarta.** Con `pax`, una casita cuya `capacidad` es `NULL`
  **sí** se ofrece: es mejor que el operador la vea y decida, que esconder inventario por un
  campo sin rellenar.
- **Techo de 365 noches** (`MAX_NOCHES`) para que una fecha mal tecleada no barra años.
- **Sólo unidades activas** (`u.activo = true`).

## 7. La otra cara: quién está dentro (`ocupacion()`)

`buscar()` dice qué se puede vender; `ocupacion()` dice quién ocupa. **Son la misma pregunta por
sus dos caras, y viven en el mismo servicio a propósito**: comparten `IMPIDEN_VENTA` (§4) y el
solape por `DATE()` (§3). Si el cálculo se duplicara, bastaría con que uno contase `bloqueo` y el
otro no para que el operador viese *5 libres y 4 ocupadas de 7 casitas* sin saber qué creerse.
Cuadran por construcción, no por disciplina.

### El invariante que hay que mantener

> **libres + casitas distintas ocupadas = parque activo.**

Es la prueba de humo de cualquier cambio en el solape o en los estados. Con datos reales del
10 al 12 de agosto de 2026: `2 + 5 = 7`. Si un día no suma, el error está en §3 o en §4, no en
quien consulta.

### Bloqueos y extensiones se devuelven, marcados

Un `bloqueo` o una `extension` ocupan la casita pero no son personas. Se devuelven igual, con
`es_estancia: false`, porque esconderlos descuadraría la suma justo en los casos raros — que son
los que se consultan. Quien pinte la respuesta decide si los muestra; el servicio no miente.

### Por qué no vale `listar_entradas_salidas`

Se parecen y contestan cosas distintas. Aquélla da los **movimientos** de unos días; ésta, quién
**duerme dentro**. Catherine entró el 9 y se va el 18: no aparece en ningún listado de entradas y
salidas del 10 al 12, y sin embargo es quien está en la Casita 1. Confundirlas es el error
probable de un modelo pequeño, así que la descripción de `consultar_ocupacion` lo dice explícito.

## 8. Cómo se prueba

El proyecto no tiene tests: el comando **es** la prueba.

```bash
php bin/console app:pms:disponibilidad 2026-08-05 2026-08-07          # rango ocupado
php bin/console app:pms:disponibilidad 2026-08-07 2026-08-08          # el día que sale
php bin/console app:pms:disponibilidad 2026-08-07 2026-08-08 --pax=8  # filtro de capacidad

# Las dos caras, que deben sumar el parque activo:
php bin/console app:agent:skill consultar_disponibilidad '{"desde":"2026-08-10","hasta":"2026-08-12"}'
php bin/console app:agent:skill consultar_ocupacion      '{"desde":"2026-08-10","hasta":"2026-08-12"}'
php bin/console app:agent:skill consultar_ocupacion      '{"desde":"2026-08-10","hasta":"2026-08-12","casita":"1"}'
```

Las dos primeras son la prueba de la regla de solape: una casita que sale el 7 **no** debe
aparecer en el primer rango y **sí** en el segundo.

**`ocupacion()` es además la única forma de comprobar que las canceladas se ignoran.** En
disponibilidad no se ve: si una casita tiene una reserva cancelada y otra vigente, sale ocupada
igual y el filtro roto daría el mismo resultado. En la ocupación se listan los eventos uno a uno,
así que una cancelada mal filtrada **aparece con nombre y apellido**. Comprobado en Casita 2, que
tiene las dos y sólo devuelve la vigente.

## 8.b Los márgenes de una estancia: `margenesDe()`

La tercera pregunta que se le hace a este servicio, y la más operativa: **¿está libre la casita
justo antes de que entre y justo después de que salga?** Es lo que decide una entrada temprana o
una salida tardía, y lo que el operador miraba a mano en el calendario.

```php
$margenes = $disponibilidad->margenesDe($evento);
// ['antes' => ['fecha' => '2026-08-01', 'libre' => false, 'ocupa' => 'Yerzalif Onsueta'],
//  'despues' => ['fecha' => '2026-08-09', 'libre' => false, 'ocupa' => 'Catherine Cochet']]
```

La noche que importa para un late check-out es la **del día de salida**: es la que ocuparía la
extensión que crea el horario extra (§7.1.b de `PmsBeds24ReservasSync.md`). Si ya está vendida,
no hay nada que conceder.

Reutiliza `ocupacion()`, así que hereda `IMPIDEN_VENTA` y el solape por `DATE()` sin reescribir la
regla: un bloqueo por mantenimiento cuenta como ocupado, porque no es vendible aunque no haya
huésped.

⚠️ **Informa, no decide.** Libre significa que la conversación puede seguir —hay precio, limpieza
y criterio de por medio—; ocupada sí es concluyente.

⚠️ **Lo de la misma reserva no cuenta como ocupación ajena.** Se compara por `reservaId` y no por
id de evento: las extensiones son eventos aparte que cuelgan por `eventoOrigen`, y buscarlas una a
una sería una consulta extra por noche. De paso cubre el caso de una reserva con dos tramos en la
misma casita.

Lo consume `EscalarAlEquipoSkill`, que lo mete en el WhatsApp que recibe el operador — **nunca en
lo que ve el huésped** (§11 de `Mensajeria.md`).

## 9. Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Que un estado nuevo bloquee (o deje de bloquear) la venta | `PmsEventoEstado` | `IMPIDEN_VENTA` — §4, **no** toques `OCUPAN_UNIDAD`. Afecta a las dos caras a la vez |
| Cambiar la regla de solape | `PmsDisponibilidadService` | `unidadesOcupadas()` **y** `ocupacion()` — §3, las dos o el invariante de §7 deja de cumplirse |
| Filtrar por más criterios (tipo de casita, servicios…) | `PmsDisponibilidadService` | `buscar()` |
| Cambiar qué datos ve el agente al consultar disponibilidad | `PmsUnidadDisponibleDto` | `toArray()` — las claves son parte del prompt |
| Cambiar qué datos ve el agente al consultar ocupación | `PmsOcupacionDto` | `toArray()` — ídem |
| Cambiar cómo se resuelve «casita 1» a una unidad | `ConsultarOcupacionSkill` | `resolverCasita()` — §7 |
| Subir el techo de noches | `PmsDisponibilidadService` | `MAX_NOCHES` |
| Cambiar qué noche se mira para un late check-out / early check-in | `PmsDisponibilidadService` | `margenesDe()` — §8.b |
| Cambiar cómo se le presentan los márgenes al operador | `EscalarAlEquipoSkill` | `margenes()` — es texto de WhatsApp, no JSON |
| Comprobar disponibilidad a mano | — | `php bin/console app:pms:disponibilidad <desde> <hasta>` |
| Comprobar que las dos caras cuadran | — | Los tres comandos de §8 |
