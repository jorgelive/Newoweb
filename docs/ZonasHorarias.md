# Zonas horarias — hora de pared, y de quién

Cómo se guarda, se manda y se enseña una fecha en este proyecto. Es transversal: toca PMS,
mensajería, cotizaciones, domótica y las dos apps de front, y hasta el 08/09/2026 la respuesta
estaba repartida entre tres documentos y dos `php.ini` que no están en el repositorio.

**Alcance:** la convención y de dónde sale la zona en cada caso. El detalle de cada módulo sigue en
su doc: `PmsBeds24ReservasSync.md` §12.16 (lo que manda Beds24), `NodeEnElStack.md` §9.bis (el
front), `Domotica.md` §13.2 y §14.11 (Tuya).

---

## Índice

1. [El estándar](#1-el-estándar)
2. [Las dos clases de fecha](#2-las-dos-clases-de-fecha)
3. [De dónde sale la zona](#3-de-dónde-sale-la-zona)
4. [El reloj de la aplicación](#4-el-reloj-de-la-aplicación)
5. [Fronteras: lo que llega de fuera](#5-fronteras-lo-que-llega-de-fuera)
6. [MySQL corre en UTC](#6-mysql-corre-en-utc)
7. [Lo que sigue pendiente](#7-lo-que-sigue-pendiente)
8. [Dónde tocar para cambiar X](#8-dónde-tocar-para-cambiar-x)

---

## 1. El estándar

**Se guarda hora de pared del establecimiento.** Una columna `datetime` contiene `2026-08-31
14:00:00` y eso significa las 14:00 **en el alojamiento**, sin huso dentro.

No es la única opción posible —guardar UTC es defendible— pero es la que ya siguen las doscientas
tablas del proyecto, y una sola columna en otro criterio obliga a que cada consulta que la cruce
sepa compensarla. Eso ya pasó con `fecha_reserva_canal` y costó una migración de 412 filas.

⚠️ **El precio: el instante no se puede recuperar sin saber el huso.** Doctrine devuelve las fechas
etiquetadas con el huso por defecto, sean cuales sean los dígitos que se guardaron. Cuando hay que
comparar de verdad —¿esto ya pasó?— hay que volver a leerlas en la zona que les corresponde. Es lo
que hace `PmsGuiaAcceso::paraEvento()`.

Perú no cambia de hora, así que el punto débil habitual de guardar hora local —la hora que se repite
y la que no existe— aquí no muerde.

## 2. Las dos clases de fecha

Es la distinción de la que cuelga todo. No tenerla escrita es lo que produjo el fallo de `pax`.

| | Qué es | Cómo se serializa | Cómo se enseña |
|---|---|---|---|
| **Hecho de pared** | check-in 14:00, fechas de estancia, «válida hasta» | naive, sin huso | **sin convertir**, ni a la zona del visitante ni a la de la casa |
| **Instante** | pago recibido, enlace que caduca, códigos que se liberan | ISO **con desplazamiento** (`DATE_ATOM`) | se convierte; el desplazamiento viaja dentro |

«Check-in a las 14:00» no es un momento en el tiempo: es un hecho sobre una casa. El huésped que
mira vuelos desde Madrid necesita leer **14:00**, que es cuando puede entrar.

⚠️ **Por eso `pax` no necesita conocer la zona del establecimiento.** En la primera categoría no se
convierte; en la segunda el desplazamiento ya viaja en la cadena. Si se quiere **etiquetar**
—«14:00, hora de Cusco»— eso es texto de UI, no una zona horaria.

## 3. De dónde sale la zona

En cascada, y cada peldaño existe por un motivo:

```
  PmsEstablecimiento::zonaHoraria()      ← LA BUENA. Campo `timezone` del alojamiento
          │  (vacío o inválido)
          ▼
  date_default_timezone_get()            ← el respaldo, que ya es una decisión (ver §4)
```

Quién sabe llegar al establecimiento:

| Desde | Camino |
|---|---|
| `PmsEventoCalendario::zonaHoraria()` | `pmsUnidad → establecimiento` |
| `DomoticaDispositivo::zonaHoraria()` | `unidad → establecimiento` |
| `PmsReserva` | `getEstablecimiento()` directo |

⚠️ **El respaldo no es una alternativa legítima: es la señal de que falta un dato.** Sólo lo usan de
verdad los aparatos sin unidad —el corredor, el tanque— que no cuelgan de ningún alojamiento.

## 4. El reloj de la aplicación

⚠️ **Hasta el 08/09/2026, `date_default_timezone_get()` significaba «lo que diga el servidor».**

La zona salía de `date.timezone` del `php.ini`: MAMP en el portátil de quien desarrolla,
`/etc/php/8.4/{fpm,cli}/php.ini` en producción. **Ni una línea del repositorio la fijaba.** Las dos
decían `America/Lima`, así que nunca dio problemas — y por eso nadie lo miró.

**El riesgo era total y silencioso:** un contenedor nuevo, un runner de CI o una reinstalación de
PHP arrancan en **UTC**. A partir de ese instante cada fecha escrita entra cinco horas movida y se
mezcla con las que ya había, en todas las tablas a la vez, sin un solo error en ninguna parte. Es el
fallo de `fecha_reserva_canal` multiplicado por el esquema entero.

Ahora lo fija `App\Kernel::boot()`, y `boot()` corre en **todas** las entradas: web, consola,
workers y sondas.

⚠️ **El valor vive en `Kernel::ZONA_POR_DEFECTO`, no en el `.env`**, y esto se comprobó en el
servidor: producción tiene `.env.local.php` —el entorno compilado por `composer dump-env`— y con él
Symfony **deja de leer `.env`**. `APP_ZONA_HORARIA` sirve como anulación en desarrollo, pero en
producción no llega, y el `post-merge` no ejecuta `dump-env`. La constante sí se despliega siempre. Comprobado arrancando PHP en UTC a la fuerza:

```
servidor arranca en:  UTC
tras Kernel::boot():  America/Lima
una fecha nueva:      21:54:00 -05
```

**Eso es lo que hace legítimo el respaldo de §3.** `date_default_timezone_get()` ya no significa «lo
que diga el sistema» sino «lo que decidimos», y la decisión está en el repositorio, versionada y
greppable.

⚠️ `APP_ZONA_HORARIA` **no es la zona de un establecimiento.** Es el respaldo de la operación. La de
un alojamiento sale de `PmsEstablecimiento::zonaHoraria()` y manda siempre que exista.

## 5. Fronteras: lo que llega de fuera

**La regla: una fecha que llega de fuera declara su huso en la frontera; la hora de pared la pone
quien conoce el establecimiento.** Son dos pasos, en sitios distintos a propósito — el primero es
transporte, el segundo es dominio.

| Proveedor | Qué manda | Dónde se declara |
|---|---|---|
| Beds24 — **reservas** | `"2025-07-30T14:30:00"`, sin huso, **en UTC** | `Beds24BookingDto::toDateTimeOrNull()` |
| Beds24 — **facturas** (`createTime`) | igual: sin huso, **en UTC** | `Beds24InvoiceItemDto::toInstanteUtcOrNull()` |
| Beds24 — **facturas** (`invoiceDate`) | un **día**, no un instante | `Beds24InvoiceItemDto::toDiaOrNull()` — no se convierte |
| Beds24 — **mensajes** | el mismo formato, pero **en hora local** | `Beds24MessageDto` — se parsea como local |
| Tuya | epoch en milisegundos (instante absoluto) | `TuyaClient` lo devuelve en UTC; normaliza la entidad |
| WhatsApp / Meta | epoch | `setTimestamp()`, que conserva el huso de la app |

⚠️ **Beds24 usa DOS convenciones distintas y no lo documenta.** Las reservas vienen en UTC; los
mensajes, en hora local de la cuenta. Comprobado con datos el 08/09/2026: los mensajes recientes
tienen su fecha a 0-1 minuto de nuestro propio reloj, y si vinieran en UTC estarían 300 minutos por
delante. **No «arregles» los mensajes igual que las reservas**: los desplazarías cinco horas al
futuro y los descolocarías frente a los de WhatsApp en el mismo hilo. Una revisión automática lo
señaló como fallo pendiente; los datos dijeron lo contrario.

⚠️ **Y dentro del mismo DTO de facturas conviven las dos categorías.** `createTime` es un instante
en UTC; `invoiceDate` es un día y la columna que lo recibe es `date`. Convertir el segundo lo
movería **al día anterior** —una factura fechada un día antes de lo que dice Beds24 es un descuadre
contable que nadie relacionaría con husos horarios—, así que se parsean con métodos distintos y el
persister sólo convierte uno.

### 5.1 El que se escapó a la primera pasada — 08/09/2026

`Beds24InvoiceItemDto` tenía el **mismo parseo sin huso** que se corrigió en las reservas y se quedó
fuera de aquella revisión. Esta vez la tabla era la del dinero: `fecha_creacion_beds24` de
`PmsCargoFinanciero`.

Cómo se distinguió lo que había que tocar de lo que no, que es la parte que importa:

| | `beds24_item_id` | Desfase contra `created_at` | Filas |
|---|---|---|---|
| Vienen de Beds24 | **no nulo** | −5 h | 177 |
| Los creamos nosotros | **nulo** | 0 | 34 |

El filtro va por **origen**, no por el desfase: el origen es un hecho comprobable, mientras que el
desfase sólo se ve en los que entraron por webhook casi al instante. Desplazar los 34 propios los
habría roto sin forma de distinguirlos después. Lo corrige `Version20260909060000`.

⚠️ **Y la zona se busca por la RESERVA, no por la estancia** — tanto en el código como en la
migración. `evento_id` es nulable: un cargo se crea aunque no se resuelva la estancia, y el propio
persister declara ese camino válido («no saber en qué estado está no es razón para tirar un importe
que puede ser real»). Con la zona sacada del evento, esos cargos guardaban el instante en UTC **y
nadie los corregía después**: la reimputación posterior asigna el evento pero no vuelve a tocar la
fecha, y un `JOIN` por `evento_id` tampoco los alcanzaba.

Por la reserva no hay hueco, y eso es comprobable: `upsertCargos()` **lanza** si no encuentra la
reserva, y `pms_reserva.establecimiento_id` es `NOT NULL`. Medido: 177 cargos de Beds24, **cero** sin
reserva. Así que `hidratar()` recibe una `DateTimeZone` —no una entidad que pueda venir nula— y el
camino del respaldo silencioso deja de existir.

⚠️ Las cifras de la tabla son de la base de **desarrollo**. En producción el reparto es distinto —unos 130 cargos de Beds24, 81 sin `updated_at`— y las migraciones no se han ejecutado allí todavía.

### 5.2 El guardia de las migraciones, y las dos versiones equivocadas

Merece contarse entero porque las dos primeras parecían correctas al leerlas.

`TimestampTrait::setTimestampsOnPersist()` **sólo rellena `createdAt`**: una fila recién insertada
tiene `updated_at = NULL`. Eso hace que `updated_at` sea incapaz de distinguir dos casos opuestos,
porque en los dos vale NULL:

- una fila **vieja que nadie tocó nunca** → hay que desplazarla;
- una fila **nueva que acaba de insertar el código nuevo** → no hay que tocarla.

| Intento | Qué hacía | Resultado |
|---|---|---|
| `updated_at < :corte` | NULL no cumple la condición → excluidas | **Migración a medias**: 72 cargos y 1 reserva sin convertir. Detectable |
| `(updated_at IS NULL OR updated_at < :corte)` | ahora las NULL entran… **incluidas las recién insertadas** | **Corrupción silenciosa** de lo que el código nuevo ya había escrito bien |
| `created_at < :corte AND (updated_at IS NULL OR updated_at < :corte)` | `created_at` siempre está puesto, así que sí distingue | ✅ |

Y una segunda red para el caso de ejecutar la migración mucho después del despliegue, cuando el
código nuevo lleve horas escribiendo bien: se salta lo que **ya parece convertido** —una fecha a
menos de dos horas de su propio `created_at` es hora de pared; en UTC estaría a unas cinco—.

⚠️ **La red atrapa el 97 %, no el 100 %.** Medido: protege 398 de 412 eventos; las 14 restantes son
cargas históricas donde la reserva se hizo semanas antes de importarla, así que la distancia con
`created_at` no dice nada. Por eso `migrate` debe correr **inmediatamente después del `pull`**.

**Las dos redes fallan hacia el mismo lado a propósito: quedarse corto se arregla, pasarse no.** Una
fila sin convertir se detecta comparándola con su `created_at`; una desplazada de más es
indistinguible de una correcta.

## 6. MySQL corre en UTC

⚠️ **El servidor tiene dos relojes.** Medido en producción:

```
MySQL   @@system_time_zone = UTC     NOW() → 01:29:39
PHP     America/Lima                        20:29:41
```

**Todo `NOW()`, `CURDATE()` y `CURRENT_DATE()` en SQL crudo va cinco horas por delante de lo que
guardan las columnas.** No es teórico: hay comandos que incluyen o excluyen gente por la franja de
las 19:00 a medianoche, y `VigilanteDeColas` —el candado que vigila las colas de Beds24— tiene una
ventana real de 19 h en vez de 24, y con `--horas` ≤ 5 no contaría nada nunca.

**La regla: en SQL crudo, el «ahora» se calcula en PHP y se liga como parámetro.** Es lo que hace
`Version20260909040000` para su corte, y por lo que no usa `NOW()`.

### 6.1 Lo que se saldó el 08/09/2026

| Dónde | Qué pasaba | Cada cuánto |
|---|---|---|
| `VigilanteDeColas::revisar()` | Ventana real de **19 h en vez de 24**; con `--horas` ≤ 5 no contaba **nada** | cada hora, en el candado de las colas |
| `RebuildConversationContextCommand` | Corre a las 03:00 de Lima = 08:00 en MySQL: los mensajes programados en esa franja contaban como **ya enviados**, `lastMessageAt` saltaba a un mensaje futuro y la conversación se marcaba leída | cada noche |
| `CambiarCodigoCajaSkill` | Desde las 19:00, la lista de «quién tiene el código viejo» incluía a los que **llegan mañana** | cada tarde |

Los tres son el mismo arreglo: el «ahora» se construye con `new DateTimeImmutable()` y se liga como
parámetro. Ninguno necesitó tocar la consulta más allá de sustituir la función de reloj.

### 6.2 Y su gemelo en el navegador: `toISOString()` no es «hoy»

`new Date().toISOString().slice(0, 10)` devuelve el día **en UTC**. Desde las 19:00 de Lima, es el
de mañana. Estaba en cuatro sitios de `util` y cada uno hacía algo distinto de mal:

- el **tipo de cambio** se consultaba para el día siguiente;
- el **filtro de pasajeros por estado documental** marcaba vencido lo que vence hoy — y era un
  quinto sitio, en el mismo archivo que uno de los otros cuatro: la ficha del pasajero quedó
  corregida y la lista no, así que el mismo pasaporte salía «vigente» en una y «vencido» en la otra;
- la **fecha base** de una cotización nueva nacía en mañana;
- un **documento que vence hoy** se marcaba vencido;
- y el `getFechaLimpia()` sin valor devolvía mañana como día por defecto.

Todos pasan por `hoyNaive()` (`dominio/fecha`), que lee los componentes locales. ⚠️ Es el «hoy» de
**quien mira**, no el del establecimiento: para el equipo, que trabaja en la zona de la operación,
son el mismo. Si algún día hay operadores en otro huso y hace falta el día del alojamiento, tiene
que venir del servidor — el navegador no puede saberlo.

⚠️ **Ojo al corregir esto en otros sitios: no todo `toISOString()` está mal.** Las decenas que hay
en el editor de cotizaciones operan sobre fechas **ancladas al riel de UTC** con `parseNaiveAsUTC`,
y ahí leer en UTC es exactamente lo correcto. El patrón defectuoso es concreto: `new Date()` —el
ahora local— seguido de `toISOString()`.

## 7. Lo que sigue pendiente

| Qué | Dónde | Gravedad |
|---|---|---|
| ~~`NOW()`/`CURDATE()` contra columnas de pared~~ | `VigilanteDeColas`, `RebuildConversationContextCommand`, `CambiarCodigoCajaSkill` | **Hecho** (§6.1) |
| ~~`toISOString()` como «hoy»~~ | editor de cotizaciones en `util` | **Hecho** (§6.2) |
| `CURRENT_DATE()` al ordenar por cercanía | `PmsReservaBuscarController` | Baja — sólo cambia el orden tras las 19:00 |
| ~~`toISOString()` al arrastrar en el calendario~~ | `util/src/views/Reservas/ReservasView.vue` | **Hecho.** Afectaba a `onEventDrop` **y** `onEventResize`, y no era «media»: la estancia quedaba en 19:00/15:00 y `PmsGuiaAcceso` entregaba los **códigos de puerta 5 h tarde** |
| `MomentoDeHito::ZONA` fijo a `America/Lima` | `src/Contract/` | Latente — ver abajo |
| `MomentoDeFrente`, skills del agente, tipo de cambio | zonas escritas a mano | Latente |
| ~20 zonas escritas a mano | skills del agente, tipo de cambio, `pax` | Latente |
| Sin ningún test de `PmsGuiaAcceso` | decide la entrega de códigos de puerta | Media |
| **El chat enseña siempre hora de Lima** | `util/src/views/ChatView.vue` — `formatTime()` y `formatFullDate()` | Media — molesta al operador que viaja |

⚠️ **`MomentoDeHito` no puede ir a buscar el establecimiento él solo**, y no es un descuido: vive en
`src/Contract/`, el núcleo compartido, donde la regla de `CLAUDE.md` prohíbe el conocimiento de un
dominio; y es un objeto de valor con constructor privado, así que no hay dónde inyectar nada.

El camino, si se hace, es el contrato: `ConversacionEnlaceInterface::zonaHoraria()`, que implementan
`PmsConversacionEnlace` (`reserva → establecimiento`) y `CotizacionConversacionEnlace`. Y la zona
debe entrar **una sola vez**, en `MapaDeHitos`, no en cada llamada a `MomentoDeHito::de()` —
compensar en cada consumidor es el patrón que ya falló en `PmsReservaMessageContext`.

## 8. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Cambiar el reloj de la aplicación | `src/Kernel.php` | `ZONA_POR_DEFECTO`. `APP_ZONA_HORARIA` sólo anula en desarrollo: en producción `.env.local.php` tapa el `.env` |
| Cambiar la zona de un alojamiento | Panel de establecimientos | `PmsEstablecimiento::$timezone` |
| Añadir una integración que manda fechas | El DTO de esa integración | Declarar el huso al parsear; convertir donde se conozca el establecimiento |
| Enseñar una fecha de pared en el front | `dominio/fecha/index.ts` | `fmtNaive()` / `fmtNaiveDia()` — **nunca** `new Date(cadenaNaive)` |
| Comparar fechas en SQL crudo | — | Calcular el «ahora» en PHP y ligarlo; `NOW()` está en UTC |
| Comprobar que las fechas aguantan otras zonas | `dominio/` | `npm test` — corre las fechas en cinco husos |

---

## 9. El chat: un instante servido como hora de pared

La hora de un mensaje **es un instante**, no un hecho de pared: «llegó a las 15:04» tiene sentido
relativo a quien lo lee. Hoy está en la categoría equivocada y se nota al viajar — el operador ve
hora de Cusco aunque esté en Madrid.

La causa concreta está en `ChatView.vue`, en `formatTime()`: extrae los dígitos de la cadena y los
pinta tal cual, sin convertir nunca.

```js
const timePart = iso.split('T')[1];
const [h, m] = timePart.split(':');
date.setHours(Number(h), Number(m));
```

### Por qué no basta con «quitar eso»

`DateTimeNormalizer` tiene prioridad 100 y **quita el huso a todas las fechas de la API**. Tocarlo
arreglaría el chat y rompería las fechas de estancia, que deben seguir naive.

**La salida es no pelearse con él:** exponer en `Message` una propiedad que ya sea un `string` en
`DATE_ATOM` —las cadenas pasan intactas por el normalizador—, dejarla sólo en el grupo de lectura
del chat, y que `createdAt` siga naive para quien ya dependa de él. Ningún otro endpoint cambia.

El `DateTimeImmutable` que devuelve Doctrine ya viene con la zona de la aplicación, así que
`format(DATE_ATOM)` da el desplazamiento correcto sin trabajo extra.

### La decisión de producto que hay que tomar antes

Con el huésped escribiendo a las 23:00 de Cusco y el operador en Madrid:

| Qué enseñar | A favor | En contra |
|---|---|---|
| **Hora del que mira** (06:00 del día siguiente) | «hace 10 minutos» cuadra con su reloj | **mueve las cabeceras de día** del chat, y deja de coincidir con lo que ve el equipo en Cusco |
| **Hora de la operación** (23:00 siempre) | todos hablan de la misma hora | «hace 10 minutos» sigue sin cuadrar |
| **Tiempo relativo** para lo reciente y absoluto para lo viejo | evita elegir en el 90 % de los casos | hay que decidir el corte |

La tercera es la recomendada: es lo que hace WhatsApp, y por eso nadie se plantea nunca en qué huso
está un chat.
