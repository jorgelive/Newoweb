# Plan — Horario extra sin eventos hermanos

> **Estado (01/10/2026):** fases 0 y 1 hechas. Fase 1 desplegada sin efecto visible (columna
> `rol`, único nuevo, hidratador/torneo/pull ya la respetan — §2.1 de `PmsBeds24ReservasSync.md`).
> Siguiente: fase 2, que pide las decisiones del §5.

## 1. Qué cambia y por qué

Hoy una entrada temprana o una salida tardía se guarda **dos veces**: la casilla en la estancia y
un **evento hermano** (`estado = extension`, `evento_origen_id` → la estancia) que ocupa la noche y
viaja a Beds24 como `black`. Todo lo demás consiste en mantener los dos alineados, y en tres días
(27–30/09/2026) salieron cinco fallos con esa misma raíz:

| Fallo | Causa común |
|---|---|
| La noche no se veía en el calendario | El hermano hay que esconderlo en cada vista |
| Se podía crear una reserva encima | El candado no miraba al hermano |
| El agente veía la noche extra de José como «otro huésped» | El hermano parecía otra estancia |
| El pull le cambiaba el estado (`extension` → `bloqueo`) | `black` son dos estados |
| No sigue a la estancia al moverla (y en OTA no hay forma de impedirlo) | Sólo se recoloca al tocar las casillas |

**El diseño nuevo:** la casilla es el ÚNICO dato. La noche extra se **deriva** de ella:

- **Ocupación** (disponibilidad, solapes, agente, calendario): con entrada temprana la estancia
  ocupa también la víspera; con salida tardía, la noche del día de salida.
- **Bloqueo en Beds24**: una reserva `black` **propia y directa**, enganchada a la PROPIA estancia
  como un enlace más (`PmsEventoBeds24Link`), igual que hoy el espejo. Sus fechas se calculan de la
  estancia en cada push.

```
Estancia (casilla entrada temprana = sí)
 ├─ link PRINCIPAL  → reserva del canal / directa   (fechas: las de la estancia; OTA: no se tocan)
 ├─ link ESPEJO     → «(M) …»                       (fechas: las de la estancia)
 ├─ link EXTRA_ENTRADA (principal)  → black, víspera  (fechas: calculadas, SIEMPRE nuestras)
 └─ link EXTRA_ENTRADA (espejo)     → black, víspera  (ídem, en la otra propiedad virtual)
```

**La reserva de la OTA no se toca nunca.** Lo que se mueve es la `black` nuestra, que es directa:
el mismo motivo por el que hoy el hermano nace con canal directo e `isOta = false`.

## 2. Lo que encontró el mapa del código (y condiciona el plan)

1. **`uniq_pms_evento_beds24_evento_map (evento_id, unidad_beds24_map_id)`**: un evento sólo admite
   UN link por mapa. El link extra choca → hace falta una columna de **rol** en el link y que el
   único sea `(evento, mapa, rol)`. *(Fase 0: ese único estaba declarado pero NUNCA existió en la
   base — Doctrine ignora los índices anidados en `#[ORM\Table]`, §2.2 de
   `PmsBeds24ReservasSync.md`. La fase 1 lo crea, ya con el rol.)*
2. **El push agrupa por (evento, mapa)** (`Beds24BookingsPushQueueListener::resolveTasks()`): un
   ganador por grupo y CANCEL al resto. Hay que meter el rol en la clave.
3. **`PmsEventoCalendarioFactory::internalHydrate()` se comería los links extra**: los que no
   reutiliza los borra (orphanRemoval → DELETE en Beds24). Tiene que ignorar todo lo que no sea rol
   `estancia`.
4. **El payload toma las fechas del evento** (`buildUpsertPayload()`): el link extra necesita las
   suyas.
5. **El pull ya trata como inerte un link no principal** con evento existente (sólo `lastSeenAt`):
   encaja. Falta que un `black` huérfano con `custom2 = EXTRA` no cree nada (como hoy `MIRROR`).
6. **El cron de push** (`Beds24BookingsPushJob`) elige eventos por `[inicio, fin]`: la víspera de
   una entrada temprana queda fuera de la ventana → ensancharla un día por cada lado.
7. **`PmsEspacioEstancia`** puede elegir hoy una extensión de entrada temprana como «estancia
   principal» y encadenar la de salida tardía como un día más de estancia. Desaparece con el
   rediseño.
8. Doc desfasado: §7.1.b dice que el rollup filtra por `estado_id != 'extension'`; el código filtra
   por `evento_origen_id IS NULL`.

## 3. Fases

Cada fase se despliega sola salvo donde se dice. La regla: **nunca dos mecanismos bloqueando la
misma noche a la vez** (el hermano viejo y el link nuevo) — Beds24 aceptaría las dos `black` sin
quejarse y el corte de una dejaría la otra viva.

### Fase 0 — Preparación (sin cambios en producción) ✅ 01/10/2026
- Base local refrescada desde un volcado de producción: la local es de agosto, y la migración de
  la fase 4 se ensaya sobre datos reales.
- Inventario de extensiones vivas y sus `beds24BookId` (hoy: 2 vivas, 8 canceladas; 10 en total,
  todas de reservas directas). Vivas: M4E23R (casita 4, 05/08/2026, `90962710`/`90962711`) y
  UV5XPW (casita 1, 27/09/2026, `93826892`/`93826893`), cada una con principal y espejo.
- Copia local de producción: base `openperu_prod_20261001` (sin filas de `msg_meta_webhook_audit`
  ni `messenger_messages`). 940 links, 470 eventos con link, 0 pares `(evento, mapa)` repetidos.
- **Decisión de Jorge:** una casita y una fecha futura libre para las pruebas contra Beds24 real.

### Fase 1 — El link sabe qué es (desplegable sola, sin efecto visible) ✅ 01/10/2026
- Migración `Version20261001120000`: `pms_evento_beds24_link.rol` (`estancia` | `extra_entrada` |
  `extra_salida`), relleno `estancia`, único NUEVO `(evento_id, unidad_beds24_map_id, rol)`.
- `internalHydrate()` sólo toca links `estancia`.
- `Beds24BookingsPushQueueListener::resolveTasks()`: clave `(evento, mapa, rol)`.
- `isSynced()`, `getSyncStatus()`, `getMotivoNoBorrable()`: revisar que un link extra no los
  confunda.
  *(Revisado: lo cuentan como uno más, y está bien — la noche extra es parte de la
  sincronización de la estancia.)* Los que buscan «el principal» (facturas, mensajes, URL de
  Beds24) no ven a los extra porque un extra **nunca** es principal: lo impide la entidad.
- Pull: un huérfano con `custom2 = EXTRA` se ignora, como `MIRROR`. **Y un link extra enlazado no
  mueve la estancia de casita** (encontrado al hacerla): tras un cambio de habitación de la OTA, el
  pull de la `black` aún en la casita vieja la habría devuelto allí.
- El push de un link extra se niega hasta la fase 2 (`buildUpsertPayload()`): con el payload de
  hoy saldría con las fechas de la estancia.
- Pruebas: `LinkConRolTest`, `TorneoDeLinksTest`, `HidratadorIgnoraLinksExtraTest` y dos casos más
  en `BookingPullPersisterMarcadorTest`.

### Fase 2 — La noche extra se deriva (código listo, apagado hasta la fase 4)
- `PmsEventoCalendario::nocheExtraEntrada()` / `nocheExtraSalida()`: el rango de cada noche, o
  `null` (casilla apagada o estancia cancelada). **Una sola fuente**, la usan todos los de abajo.
- Servicio `NochesExtraDeEstancia` (sustituye a `PmsExtensionEstanciaService`): asegura un link
  extra por mapa y por noche activa; al apagarse, el link pasa a `cancelled` (nunca se borra:
  misma regla que hoy).
- Payload de un link extra (`BookingsPushMappingStrategy`): fechas calculadas, `status = black`,
  `firstName = «Entrada temprana · <nombre>»`, `custom2 = EXTRA`, `custom3 = ESTADO:extra_entrada`,
  sin `masterId`, `price`, `apiReference` ni `channel` — es nuestra, sea la estancia de quien sea.
- Hash del snapshot de la cola: incluye el rol y las fechas calculadas.
- Cron de push: ventana ensanchada un día por cada lado.
- Pruebas unitarias: fechas derivadas (con hora real de entrada/salida), payload por rol.

### Fase 3 — La ocupación se calcula de la casilla (con la fase 4)
- `PmsDisponibilidadService::ocupacion()` / `unidadesOcupadas()`: el rango efectivo,
  `DATE(inicio) - INTERVAL entrada_temprana DAY` … `DATE(fin) + INTERVAL salida_tardia DAY`, sólo
  para estados vivos. `PmsOcupacionDto` gana `esNocheExtra` para que los mensajes digan «la entrada
  temprana de X» y no «X».
- `PmsEventoCalendarioSolapeListener`: compara rangos efectivos; mover una estancia con horario
  extra se valida con su rango completo — y entonces **se puede mover** (fase 4 quita el congelado).
- `PmsEspacioEstancia`, `margenesDe()`, agente: desde la casilla del vecino.
- Calendario: la franja rayada sale de las casillas de la propia estancia (sin consulta aparte).
- Disponibilidad del agente y del buscador: igual, por `ocupacion()`.

### Fase 4 — El corte (UN despliegue, con la fase 3)
- Migración de datos, en SQL y sin listeners (para que no salga ningún DELETE a Beds24):
  1. Por cada extensión VIVA, sus links pasan a la estancia origen con su rol, **conservando el
     `beds24BookId`**: la misma `black` de Beds24 sigue siendo la que bloquea. Cero altas y cero
     bajas en el canal.
  2. Las extensiones canceladas y sus links se borran de la base (en Beds24 ya están canceladas).
  3. Se borran los eventos hermanos.
- Fuera: `PmsExtensionEstanciaService`, el paso 5 de `PmsInformacionFinancieraCoherenciaListener`
  (se queda el cargo en 0.00, que no depende del hermano), `assertFechasMoviblesConHorarioExtra()`
  y el congelado del drawer (`diaBloqueadoPara()`), `horariosExtra()` del proveedor.
- El estado `extension` deja de usarse (la fila se queda hasta la fase 6).
- Verificación en producción: las dos noches vivas siguen `black` en Beds24 con su mismo `bookId`;
  la casita de prueba: marcar, mover de día, mover de casita, desmarcar, cancelar — cada paso
  comprobado en Beds24.

### Fase 5 — OTA que cambia fechas o habitación
- Ya funciona por construcción: el pull actualiza la estancia, el listener de push encola todos sus
  links y el extra sale con las fechas nuevas.
- Lo que falta: si la noche nueva choca con otra estancia, **avisar al equipo** (no se puede
  rechazar: el canal ya lo cambió). Aviso técnico interno con casita, noche y quién ocupa.

### Fase 6 — Limpieza
- Quitar los filtros que ya no filtran nada: `eventoOrigen IS NULL` (calendarios, CRUD, buscador,
  rollup, pagos, `listar_entradas_salidas`, `CambiarCodigoCaja`…) y `not_in: [extension]` del YAML
  (con sus tests de configuración).
- Migración: borrar `evento_origen_id` y la fila `extension` de `pms_evento_estado`.
- `custom3` se queda: sigue siendo útil para cualquier estado que comparta código en Beds24.
- Textos del agente (`EvaluarCambioHorario`, `AplicarCambioHorario`) que prometen «un evento de
  extensión».
- Docs: §7.1.b de `PmsBeds24ReservasSync.md` reescrito, `PmsDisponibilidad.md`,
  `Calendar_architecture.md`.

## 4. Riesgos y cómo se cubren

| Riesgo | Cobertura |
|---|---|
| Dos `black` para la misma noche durante la transición | Fases 3+4 en un solo despliegue; la migración REUTILIZA el `bookId` |
| Un DELETE accidental en Beds24 al mover links | La migración va por SQL, sin listeners; el hidratador ignora roles extra desde la fase 1 |
| El torneo del push cancela el link extra | Clave con rol desde la fase 1, con prueba unitaria |
| Que el pull cree eventos de las `black` extra | `custom2 = EXTRA` se ignora, y un link existente no principal ya es inerte |
| El agente o la disponibilidad cuenten distinto que el calendario | Todos pasan por `ocupacion()` y por `nocheExtra*()` de la entidad |

## 5. Lo que hace falta decidir

1. **Casita y fecha de prueba** contra Beds24 real (fase 0).
2. **Nombre de la `black` en Beds24**: «Entrada temprana · <huésped>» (hoy la del hermano dice
   «Entrada temprana · <localizador>»).
3. **A quién avisa la fase 5** cuando una OTA mueve una reserva con horario extra sobre otra.

## 6. Dónde tocar (cuando esté hecho)

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Cambiar qué noche ocupa un horario extra | `PmsEventoCalendario` | `nocheExtraEntrada()` / `nocheExtraSalida()` |
| Cambiar qué se manda a Beds24 por esa noche | `BookingsPushMappingStrategy` | payload del rol `extra_*` |
| Cambiar cómo cuenta la ocupación | `PmsDisponibilidadService` | `ocupacion()` / `unidadesOcupadas()` |
