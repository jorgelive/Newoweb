# Teléfonos: normalización y presentación

Cómo se limpia, se guarda y se muestra un número de teléfono en todo el proyecto.
Alcance: `src/Service/Phone/PhoneSanitizer.php` (única fuente de verdad al **guardar**),
los listeners y persisters que lo invocan, y `util/src/utils/telefono.ts` (única fuente de
verdad al **mostrar**).

Los contactos entran por vías muy distintas —tecleados a mano en el drawer de reservas,
empujados por Beds24 desde cualquier OTA, o extraídos del webhook de WhatsApp— y cada
origen escribe el número a su manera. Este documento fija qué se guarda y qué no.

## 1. Formato canónico en BD

**E.164 sin el `+`: solo dígitos, con código de país delante.** Ejemplo: `51984123456`.

Se guarda sin el `+` porque es lo que esperan `wa.me` / `api.whatsapp.com` y porque las
búsquedas por teléfono (`PmsReservaRepository::findVivasByTelefono()`,
`UserRepository::findByTelefono()`) comparan por igualdad exacta contra ese formato.

⚠️ **El precio de no guardar el `+`**: un valor almacenado es ambiguo, no se sabe si sus
primeros dígitos son código de país o parte del número nacional. De ahí sale el gotcha de
§4. Si algún día se migra a E.164 *con* `+`, hay que tocar a la vez la columna, los dos
repositorios de búsqueda, `PmsReservaWhatsappLinkController` y `PmsReservaVcardController`.

## 2. Quién normaliza, y cuándo

Nadie escribe un teléfono en BD sin pasar por `PhoneSanitizer::cleanPhoneNumber()`:

```
Drawer de reservas (util) ──► API Platform ──► PmsReserva
                                                   │
Beds24 pull ──► BookingPullPersister ──────────────┤
                                                   ▼
WhatsApp webhook ──► WhatsappMetaReceivePersister  PmsReservaIntegrityListener
                                                   (prePersist / preUpdate)
                                                   │
                                                   ▼
                                       PhoneSanitizer::cleanPhoneNumber($raw, $isoPais)
```

- `PmsReservaIntegrityListener` cubre el alta y la edición de reservas. En `preUpdate` **no
  basta con llamar al setter**: el changeset de Doctrine ya está calculado, hay que usar
  `PreUpdateEventArgs::setNewValue()` (y, por higiene, actualizar también la entidad en
  memoria).
- `UserIntegrityListener` hace lo propio con `User`.
- El `$defaultCountryIso` sale del país de la reserva; **si la reserva no tiene país, se
  asume `PE`**. Solo se usa para interpretar números escritos en formato nacional: si el
  número ya trae prefijo internacional, manda el prefijo.

## 3. La regla de limpieza

`cleanPhoneNumber()` hace, en este orden:

1. Intenta `libphonenumber` con el país por defecto. Si el número es **válido**
   (`isValidNumber()`), devuelve su E.164 sin el `+`. Fin.
2. Si no valida, aplica el **único prefijo manual que existe**: país `PE` + 9 dígitos
   empezando por `9` (el móvil peruano tal y como lo teclea la gente) → `51` delante.
3. Cualquier otra cosa se guarda con los dígitos que llegaron, sin tocar.

**Regla de oro: nunca se inventa un prefijo.** Un dato crudo se puede corregir a ojo; uno
con un código de país falso ya no se distingue de uno bueno.

`isValidNumber()` y **no** `isPossibleNumber()`: el segundo solo comprueba que la longitud
sea plausible, y como Perú admite fijos de 6 dígitos daba por bueno cualquier número
incompleto. Ese era el origen del gotcha siguiente.

## 4. Gotcha: el `51` que se duplicaba en cada guardado

Síntoma: una reserva peruana mostraba **`+51 51 940418`** en el drawer.

Qué pasaba, con `isPossibleNumber()`:

```
usuario teclea «940418» (número incompleto, 6 dígitos)
   │  isPossibleNumber() → true (Perú admite fijos de 6 dígitos)
   ▼
BD: 51940418          ← se le pegó el +51 y se guardó sin el '+'
   │  siguiente guardado: se relee como número NACIONAL peruano
   ▼
BD: 5151940418        ← y otro 51
```

Y al mostrarlo, `51940418` se lee como nacional peruano = indicativo `51` (Puno) + `940418`,
que `libphonenumber-js` da por válido → se pinta `+51 51 940418`.

Dos detalles que costaron entender:

- **La validación no coincide entre PHP y el front.** `util/` usa la metadata *min* de
  `libphonenumber-js`, más laxa que la metadata completa de PHP: hay números que el back
  rechaza y el front formatea tan campante. No se puede razonar sobre uno mirando el otro.
- **El crecimiento solo se veía al reguardar**, no al escribir, así que el número se
  corrompía en ediciones que no tocaban el teléfono.

Con la regla de §3 el ciclo se corta: `940418` se queda en `940418`, y un número que ya
trae el `51` tiene 11 dígitos, así que nunca entra por el paso 2.

**Datos históricos**: los registros corrompidos antes del arreglo no se auto-reparan, solo
dejan de crecer. Se detectan con
`SELECT id, telefono FROM pms_reserva WHERE telefono LIKE '5151%' OR LENGTH(telefono) < 10`
y hay que reescribirlos a mano: el número original es irrecuperable.

## 5. Presentación (`util/`)

`util/src/utils/telefono.ts` es la única fuente de verdad para **mostrar**; no normaliza lo
que se persiste. Interpreta probando dos lecturas: primero nacional del país por defecto
(`PE`), y si falla, internacional al que le falta el `+` —caso de las OTAs, que entregan
`5561998210624` sin prefijo—. Si ninguna da un número válido devuelve el valor crudo: ante
texto libre o números incompletos, mostrar el dato tal cual siempre es mejor que inventar.

`formatearTelefono()` para pintar, `telefonoParaWhatsapp()` para el enlace (devuelve `null`
si no hay número utilizable, para poder ocultar la acción en vez de abrir un chat roto).

### El teléfono en el drawer de reserva: se ENSEÑA en los dos modos (28/08/2026)

`ReservaTelefonoIdentidad.vue` pinta el número resuelto, la marca «sin verificar», el botón
**Editar** (que lleva al editor de identidades en el chat) y el de **vCard**. Se monta en el
modo «Ver» **y** en el de «Editar» del `ReservaEditDrawer`.

**Un componente y no dos copias del marcado**, porque es literalmente la misma información:
el teléfono no es un dato de la reserva sino de la **persona**, así que no cambia entre modos.

⚠️ **Por qué hizo falta arreglarlo.** Al mover el teléfono a las identidades se quitó su campo
editable del formulario —correcto: editarlo ahí no cambiaría a dónde salen los mensajes, y
dejaría dos datos contradiciéndose— pero **con el campo se fue también el número y el botón de
vCard**, y en su lugar quedó una nota que decía dónde mirarlo. Eso fue quitar de más: enseñar
y descargar son **consulta**, no edición, y quien está editando una reserva sigue necesitando
saber a qué número se le escribe.

La regla que sale de ahí: **al retirar un campo editable, comprobar qué se llevó por delante
en modo lectura.** Lo que desaparece sin dar error es lo que nadie echa de menos hasta que
hace falta.

El teléfono **sigue sin resolverse en el front**: el componente lo recibe ya resuelto de
`TelefonoDeContacto` (backend), que es quien sabe cuál de las identidades vale y si está
vetada o retirada. Copiar esa regla en TypeScript sería reintroducir el espejo que se quitó.

### La vCard: una por módulo, con SUS datos (28/08/2026)

Hay dos, y no comparten código a propósito:

| Módulo | Controlador | Nombre en la agenda |
|---|---|---|
| Reservas | `PmsReservaVcardController` | `2026/05/12 B x2 (Casita 3) Juan Pérez` |
| Expedientes | `CotizacionFileVcardController` | `2KVBMX · Nune & Todd` |

La mecánica sí es idéntica —descarga directa con `Content-Disposition`, un `<a href>` basta—
pero **el contenido no se copia**: una reserva es una estancia y se busca por fecha, casita y
canal; un expediente **no tiene fechas propias** (las tienen sus cotizaciones, y hay varias
versiones), así que ordenarlo por fecha sería inventarse un dato. Lo que lo identifica es su
localizador. La nota de cada uno lleva lo que enseña su propia ficha, en el mismo orden.

⚠️ **Las dos convierten a E164** (`+51967007752`) y no reutilizan el formato de pantalla: la
columna se guarda sin `+` y `CotizacionFile::getTelefono()` devuelve INTERNATIONAL con
espacios, que es para leer, no para una agenda.

⚠️ **Un controlador nuevo NO tiene rutas hasta declarar su directorio en `config/routes.yaml`.**
`src/Cotizacion/Controller/Api/` no estaba y hubo que añadirlo. No da error: da un 404 en una
ruta que `debug:router` no lista, que parece un fallo del front. El propio `routes.yaml` lo
avisa en el bloque de Operación — y aun así volvió a pasar.

## 6. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Cambiar qué se considera limpiable / qué prefijo se añade | `src/Service/Phone/PhoneSanitizer.php` | `cleanPhoneNumber()` |
| Cambiar el país asumido al guardar una reserva sin país | `src/Pms/EventListener/PmsReservaIntegrityListener.php` | `prePersist()` / `preUpdate()` |
| Cambiar el país asumido al **mostrar** | `util/src/utils/telefono.ts` | `PAIS_POR_DEFECTO` |
| Cambiar cómo se ve un teléfono en el drawer / expedientes | `util/src/utils/telefono.ts` | `formatearTelefono()` |
| Cambiar el número con el que se abre WhatsApp | `util/src/utils/telefono.ts` | `telefonoParaWhatsapp()` |
| Tocar el teléfono / vCard del drawer (los dos modos) | `util/src/components/reservas/ReservaTelefonoIdentidad.vue` | un solo componente: no lo dupliques por modo |
| Cambiar el teléfono que sale en la vCard | `src/Pms/Controller/Api/PmsReservaVcardController.php` | `__invoke()` |
| Cambiar la vCard del EXPEDIENTE | `src/Cotizacion/Controller/Api/CotizacionFileVcardController.php` | `__invoke()` — nombre de agenda y nota |
| Cambiar cómo se buscan reservas por teléfono | `src/Pms/Repository/PmsReservaRepository.php` | `findVivasByTelefono()` |
| Normalizar teléfonos de un canal nuevo | el persister del canal | inyectar `PhoneSanitizer` |
