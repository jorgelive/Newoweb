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

## 7. Cómo se prueba

El proyecto no tiene tests: el comando **es** la prueba.

```bash
php bin/console app:pms:disponibilidad 2026-08-05 2026-08-07          # rango ocupado
php bin/console app:pms:disponibilidad 2026-08-07 2026-08-08          # el día que sale
php bin/console app:pms:disponibilidad 2026-08-07 2026-08-08 --pax=8  # filtro de capacidad
```

Las dos primeras son la prueba de la regla de solape: una casita que sale el 7 **no** debe
aparecer en el primer rango y **sí** en el segundo.

## 8. Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Que un estado nuevo bloquee (o deje de bloquear) la venta | `PmsEventoEstado` | `IMPIDEN_VENTA` — §4, **no** toques `OCUPAN_UNIDAD` |
| Cambiar la regla de solape | `PmsDisponibilidadService` | `unidadesOcupadas()` — §3 |
| Filtrar por más criterios (tipo de casita, servicios…) | `PmsDisponibilidadService` | `buscar()` |
| Cambiar qué datos ve el agente al consultar | `PmsUnidadDisponibleDto` | `toArray()` — las claves son parte del prompt |
| Subir el techo de noches | `PmsDisponibilidadService` | `MAX_NOCHES` |
| Comprobar disponibilidad a mano | — | `php bin/console app:pms:disponibilidad <desde> <hasta>` |
