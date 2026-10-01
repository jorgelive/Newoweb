# Plan — Horario extra sin eventos hermanos

> **Estado (01/10/2026):** fases 0 a 5 hechas y desplegadas; la 4 probada contra Beds24 real.
> Ya no hay eventos hermanos: la noche extra sale de la casilla y su `black` es un link de la
> estancia. Pendiente: la aprobación de Meta de `aviso_choque_ota_interno`, una segunda plantilla
> para el caso en que el canal mueve a la DUEÑA de la noche extra (texto por acordar), y la
> limpieza de la fase 6.

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

### Fase 2 — La noche extra se deriva (código listo, apagado hasta la fase 4) ✅ 01/10/2026
- `PmsEventoCalendario::nocheExtra($rol)` (y `nochesExtra()`): un `NocheExtra` con `desde`/`hasta`
  a medianoche, o `null`. **Una sola fuente**, la usan todos los de abajo. Activa si la casilla
  está marcada y la estancia está en `IMPIDEN_VENTA` — la misma lista que la disponibilidad, así
  que un inquiry (`abierto`) tampoco bloquea su noche, no sólo una cancelada.
- `PmsEventoBeds24Link::nocheQueBloquea()`: la noche que ESE link tiene que tener cerrada ahora —
  la de la estancia, si el link está en un mapa activo de la casita de la estancia; si no, `null`
  y su `black` se cancela. La leen el push y la cola: una sola regla.
- Servicio `NochesExtraDeEstancia` (sustituye a `PmsExtensionEstanciaService`): un link extra por
  mapa activo y por noche activa, repartidos por establecimiento virtual como los espejos (mover
  de casita conserva el `bookId`). **No borra ningún link**: con la noche apagada el link se
  queda y su `black` sale `cancelled`; volver a marcar la revive con el mismo `bookId`.
- Cola (`Beds24BookingsPushQueueCreator`): un link extra apagado que nunca llegó a Beds24 no se
  encola, y lo que tuviera pendiente se cancela. El snapshot de los extra lleva `rol` y `noche`
  (marcar la casilla no cambia nada más del evento); los de estancia no cambian de hash.
- Payload de un link extra (`BookingsPushMappingStrategy::buildExtraPayload()`): fechas
  calculadas, `status = black`, `numAdult = 0`, `firstName = «Entrada temprana · <huésped>»`,
  `custom2 = EXTRA`, sin `masterId`, `price`, `apiReference`, `channel` ni contacto — es nuestra,
  sea la estancia de quien sea. Apagada: sólo `{id, status: cancelled}`.
  *(Cambio sobre el plan: **sin `custom3`**. Un link extra no escribe nada en el pull y uno huérfano
  ya se reconoce por `custom2`; `ESTADO:extra_entrada` habría sido un estado que no existe.)*
- Cron de push: ventana ensanchada un día por cada lado.
- Pruebas: `NocheExtraTest`, `NochesExtraDeEstanciaTest` y tres casos en
  `BookingsPushMappingStrategyTest` (OTA con sus fechas, desmarcada, en otra casita).

### Fase 3 — La ocupación se calcula de la casilla (con la fase 4) ✅ 01/10/2026
- `PmsDisponibilidadService::ocupacion()` / `unidadesOcupadas()`: el rango efectivo,
  `DATE(inicio) - INTERVAL entrada_temprana DAY` … `DATE(fin) + INTERVAL salida_tardia DAY`, sólo
  para estados vivos. `PmsOcupacionDto` gana `esNocheExtra` para que los mensajes digan «la entrada
  temprana de X» y no «X».
- `PmsEventoCalendarioSolapeListener`: compara rangos efectivos; mover una estancia con horario
  extra se valida con su rango completo — y entonces **se puede mover** (fase 4 quita el congelado).
- `PmsEspacioEstancia`, `margenesDe()`, agente: desde la casilla del vecino.
- Calendario: la franja rayada sale de las casillas de la propia estancia (sin consulta aparte).
- Disponibilidad del agente y del buscador: igual, por `ocupacion()`.

### Fase 4 — El corte (UN despliegue, con la fase 3) ✅ 01/10/2026
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

### Lo que se hizo en las fases 3 y 4

- `ocupacion()` / `unidadesOcupadas()` con el rango efectivo (`DATE_SUB(DATE(inicio), INTERVAL
  entrada_temprana DAY)`…); `PmsOcupacionDto::$nocheExtra`, `quienOcupa()`, `nochesOcupadas()`.
  §8.c y §8.d de `PmsDisponibilidad.md`.
- `PmsEventoCalendarioSolapeListener` reescrito: valida la estancia y sus noches extra en cada
  cambio de fechas, casita, estado o casilla. Ya no mira `eventoOrigen`.
- `PmsEspacioEstancia` cuenta las noches extra de los vecinos y gana `libre_la_noche_que_se_va`;
  el agente lo dice en `ConsultarMiReservaSkill` y `PmsInstruccionesDominio`.
- Calendario: `horariosExtra()` sale de las casillas.
- Enganche: `PmsEventoCalendarioFactory` llama a `NochesExtraDeEstancia` después de los links de
  estancia (al crear, al mover de casita, en el pull); `PmsInformacionFinancieraCoherenciaListener`
  al cambiar una casilla o el estado, sin depender ya de que la reserva tenga finanzas.
- Fuera: `PmsExtensionEstanciaService`, `assertFechasMoviblesConHorarioExtra()`, el congelado del
  drawer (`diaBloqueadoPara()` y su aviso). Textos del agente (`EvaluarCambioHorarioSkill`,
  `AplicarCambioHorarioSkill`) sin «evento de extensión».
- Migración `Version20261001180000`: los links de las 2 extensiones vivas pasan a su estancia con
  su rol y su `bookId` (M4E23R `extra_salida`, KXET9H `extra_entrada`; las dos ya pasadas); colas
  pendientes de las demás canceladas; los 10 eventos `extension` borrados con sus links.
- De paso: `PmsEventoCalendario::isSynced()` comparaba con `canceled` y la cola escribe
  `cancelled`; una cola cancelada contaba como pendiente.

### La prueba contra Beds24 real (01/10/2026)

Con `tools/pruebas/prueba-horario-extra-beds24.php`, una estancia sin reserva («PRUEBA horario
extra», evento `01a0f830-6a17-76be-8b83-0fabeee554ad`), leyendo Beds24 por API después de cada
paso:

| Paso | Beds24 |
|---|---|
| Crear en Casita 1, 02–05/02/2027, con entrada temprana | 4 reservas: principal `94029154` y espejo `94029155` `confirmed` 02–05/02; las dos `black` `94029156`/`94029157` el 01/02, «Entrada temprana · PRUEBA horario extra», `custom2 = EXTRA`, sin `masterId` |
| Mover un día (03–06/02) | Las cuatro se mueven con su id; las `black` al 02/02 |
| Mover a Casita 2 | Las cuatro cambian de habitación con su id (633677 / 633711) |
| Desmarcar | Las dos `black` pasan a `cancelled` |
| Volver a marcar | Las MISMAS dos `black` vuelven a `black` |
| Otra estancia (o un bloqueo) sobre la víspera | Frenado: «Casita 2 ya está ocupada del 02/02 al 03/02 por la entrada temprana de PRUEBA horario extra» |
| Cancelar la estancia | Las cuatro `cancelled` |

Ningún evento ni reserva nació de rebote por webhook o pull. La estancia de prueba queda
cancelada en el PMS y en Beds24.

### Fase 5 — lo que se hizo ✅ 01/10/2026

- La regla: `PmsEventoCalendario::nocheExtraPisadaPor($otra)` — otra estancia viva
  (`OCUPAN_UNIDAD`), de otra reserva, en la misma casita, con alguna de sus noches (o las de su
  propio horario extra) sobre la noche extra de ésta. La usan el aviso y el calendario.
- `PmsChoqueNocheExtraListener`: en el pull (y el webhook), cada estancia creada o con fechas,
  casita, estado o casillas cambiados se manda a revisar, asíncrono
  (`RevisarChoqueDeNocheExtraDispatch`).
- `ChoquesDeNocheExtra::de()` busca los choques en los dos sentidos: (1) el canal movió una
  reserva sobre la noche extra de otra; (2) el canal movió a la DUEÑA y su noche extra cayó sobre
  otra.
- `RevisarChoqueDeNocheExtraDispatchHandler`: WhatsApp a `ROLE_CUSTOMER_SUPPORT` (plantilla
  `aviso_choque_ota_interno` fuera de la ventana, sólo para el caso 1 — su texto dice «{{canal}}
  acaba de mover ahí otra reserva», que en el caso 2 sería falso), push del panel si no llegó a
  nadie, una vez al día por choque.
- Calendario: `choquesDeNochesExtra()` marca en rojo las dos barras (⚠ con el motivo) y la franja.
- Ensayado sobre la copia de producción en contexto de pull: los dos casos se detectan y se
  despachan 3 revisiones (dos altas y un cambio de fechas), ninguna por los cambios hechos desde
  el PMS.

### Fase 5 — OTA que cambia fechas o habitación (el plan)
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

1. ~~Casita y fecha de prueba~~ → **Casita 1, del 02 al 05/02/2027** (Jorge, 01/10/2026). Libre
   del 30/01 al 08/02, así que caben la víspera (01/02) y la noche de salida (05/02).
2. ~~Nombre de la `black` en Beds24~~ → **«Entrada temprana · <huésped>»** / «Salida tardía ·
   <huésped>» (Jorge, 01/10/2026; hoy la del hermano dice el localizador).
3. ~~A quién avisa la fase 5~~ → **WhatsApp a `ROLE_CUSTOMER_SUPPORT`** (hoy Susan y Jorge) con la
   plantilla `aviso_choque_ota_interno`, push del panel de respaldo, y la franja en rojo en el
   calendario mientras dure el choque (Jorge, 01/10/2026). Texto aprobado: «⚠️ *{{casita}}*: la
   noche del {{fecha}} estaba reservada para el horario extra de {{huesped}}, pero {{canal}} acaba
   de mover ahí otra reserva ({{otra}}). Hay que reubicar a una de las dos.»

## 6. Dónde tocar (cuando esté hecho)

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Cambiar qué noche ocupa un horario extra | `PmsEventoCalendario` | `nocheExtra()` |
| Cambiar cuándo una `black` extra deja de bloquear | `PmsEventoBeds24Link` | `nocheQueBloquea()` |
| Cambiar cuántos links extra tiene una estancia y cómo se mueven | `NochesExtraDeEstancia` | `sincronizar()` |
| Cambiar qué se manda a Beds24 por esa noche | `BookingsPushMappingStrategy` | `buildExtraPayload()` |
| Cambiar a quién se avisa si una OTA la pisa (fase 5) | `RevisarChoqueDeNocheExtraDispatchHandler` | rol `CUSTOMER_SUPPORT`; plantilla de `MessageCrearAvisoChoqueOtaCommand` |
| Cambiar qué cuenta como pisar una noche extra | `PmsEventoCalendario` | `nocheExtraPisadaPor()` — aviso y calendario |
| Cambiar cuándo se revisa (qué cambios del canal) | `PmsChoqueNocheExtraListener` | `CAMPOS` |
| Cambiar cómo cuenta la ocupación | `PmsDisponibilidadService` | `ocupacion()` / `unidadesOcupadas()` |
