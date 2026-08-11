# Pendientes

Cosas **detectadas y verificadas** que no se han arreglado, con lo que hace falta saber para
retomarlas sin volver a investigar desde cero.

No es una lista de deseos ni un backlog de producto. Aquí sólo entra lo que cumple tres cosas:

1. Se comprobó contra datos o código reales, y la comprobación está escrita.
2. Nadie lo está haciendo ahora mismo.
3. Si se olvida, vuelve a costar el mismo rato de investigación.

Cuando algo de aquí se resuelva, **se borra de este archivo** y se documenta donde le toque
según la tabla de `CLAUDE.md`. Un pendiente resuelto que sigue listado es peor que no tenerlo:
la siguiente persona lo investiga otra vez.

> Verificado el 11/08/2026. Los números salen de producción.

---

## 1. Los duplicados `*_META` vuelven en cada sincronización

**Estado:** decidido que se arregla, sin decidir cómo. Bloqueado por prioridades, no por dudas.

### Qué pasa

`WhatsappMetaTemplateSyncService::processTemplateRecord()` empareja lo que devuelve Meta con lo
local **por `meta_template_name`, nunca por `code`**. Si ninguna plantilla local reclama el
nombre que llega, crea una fila con el código generado `strtoupper($metaName) . '_META'`.

Hoy hay una así: `WELCOME_BOOKING_META`, creada el **05/04/2026** y nunca vuelta a tocar. En
Meta existen dos plantillas de bienvenida de Booking y la local sólo reclamaba una:

| `code` local | `meta_template_name` | Envíos 2026 |
|---|---|---|
| `welcome_booking` | `welcome_booking_command` | 210 |
| `WELCOME_BOOKING_META` | `welcome_booking` | 0 |

Las dos están **APPROVED en los siete idiomas**, y la que se usa envía bien: 30 mensajes desde
julio, todos en `sent` o `read`, ninguno fallido. **No corre prisa.**

### Por qué no basta con borrarlo en el panel

El servicio es **create-or-update puro**: no tiene una sola línea de borrado ni de desactivación
—comprobado con `grep` de `remove(`, `delete` y `deactiv` sobre el archivo—. Mientras la
plantilla exista en Meta y nada local reclame su nombre, la siguiente sincronización la recrea.

El cron de `www-data` corre `app:whatsapp:sync-templates` **a diario a las 03:15**, así que
reaparece esa misma noche.

### La idea a explorar: dejar cosas fuera del sincronizador

Ya existe un precedente exacto en `WhatsappMetaTemplateSyncService::sync()`, donde se filtra a
mano una plantilla de Meta por nombre:

```php
if (in_array($status, ['APPROVED', 'PENDING', 'REJECTED']) && ($templateData['name'] ?? '') !== 'hello_world') {
```

Ese `!== 'hello_world'` es el punto donde encajaría una lista de exclusión de verdad. Las
preguntas abiertas, que son de diseño y no de código:

- ¿La exclusión vive en configuración, en una columna de `msg_template`, o en una tabla propia?
  Una columna no sirve para excluir algo que **todavía no existe en local** — que es justo el
  caso.
- ¿Se excluye por nombre de Meta, o se marca la fila local como «no la sincronices»? Son dos
  necesidades distintas y puede que hagan falta las dos.
- ¿Qué pasa con lo ya creado? Una exclusión nueva no borra el `WELCOME_BOOKING_META` que ya
  está.

### ⚠️ Lo que NO hay que hacer: repuntar el nombre sin más

Es la trampa cara. No falla al guardar; falla al enviar, días después.

El sincronizador **preserva `resolver_key`** al actualizar un botón existente —está puesto a
propósito— pero **sí pisa `type` y `content`**. Como las dos plantillas tienen diseños de botón
distintos, repuntar `meta_template_name` deja un híbrido imposible:

| Botón | Ahora | Llega de Meta | Quedaría |
|---|---|---|---|
| 0 «Ver Tours y Traslados» | `quick_reply` + `CMD_OBTENER_TOURS` | `url` → pax | `url` **+ `CMD_OBTENER_TOURS`** |
| 1 «Guía del Alojamiento» | `quick_reply` + `CMD_OBTENER_GUIA` | `url` → pax | `url` **+ `CMD_OBTENER_GUIA`** |
| 2 «Obtener Guia» | *(no existe)* | `quick_reply` | `quick_reply` **+ `null`** |

Y en `WhatsappMetaSendMappingStrategy`, al enviar:

- La rama `url` hace `$variables[$resolverKey]`. Con un `CMD_*` —que es un payload de comando,
  no una variable de URL— sale **cadena vacía**: botón sin enlace.
- La rama `quick_reply` con `resolver_key` vacío **lanza `RuntimeException`** y tumba el envío
  entero, no sólo el botón.

Traducido: la bienvenida dejaría de salir para **todos** los huéspedes de Booking, y la noticia
llegaría por una reseña.

Si algún día se adopta la plantilla nueva, hay que rehacer los `resolver_key` **en la misma
sesión**, antes de las 03:15. Las claves en uso son `guide_path`, `tours_catalog_path` y
`chat_path` (ver `welcome_airbnb`, que ya usa las dos primeras).

### El detalle que delata que la de Meta está a medio hacer

Su botón 2 —`quick_reply` «Obtener Guia»— **duplica al botón 1**, que ya lleva a la guía. Quien
la construyó en la consola no la terminó. Si se adopta, ese botón sobra.

El análisis completo del mecanismo está en `docs/Mensajeria.md` §18.

---

## 2. El widget de medios de pago sólo se ve en español

`Version20260810240000` insertó `{{ medios_pago }}` **sólo en el bloque `es`** de «Pago
(general)», a propósito: el placeholder es un marcador y meterlo siete veces se arriesgaba a que
el traductor automático lo rompiera en alguno.

Verificado hoy: el ítem tiene **7 bloques de idioma y 1 sola aparición** del marcador.

Consecuencia real: **un huésped que lea la guía en inglés, francés o portugués no ve las cuentas
bancarias.** Se arregla escribiéndolo en el panel, donde se ve el resultado al guardar. No hace
falta migración.

---

## 3. Nunca se marca la entrega ni la lectura en la cola de WhatsApp

`msg_whatsapp_meta_send_queue` tiene `delivered_at` y `read_at`, y están **vacías en las 2368
filas**. Cero.

El webhook de Meta sí recibe los acuses —el estado `read` aparece en los mensajes— pero los
escribe en `msg_message.metadata`, no en la cola. Así que esas dos columnas son hoy peso muerto:
o se llenan, o se quitan. Dejarlas como están invita a que alguien construya un informe encima y
le salga todo a cero.

---

## 4. No existe registro de la salida real ni del fin de la limpieza

Lo documenta ya el docblock de `PmsEspacioEstancia`, y aquí queda porque **bloquea una regla que
se quiere**: conceder entrada temprana cuando el huésped anterior se fue con margen.

`PmsEventoCalendario` guarda el fin **previsto** y un booleano `salidaTardia`, pero nadie apunta
a qué hora se fue de verdad. `pms_event_assignment` dice quién tiene asignada la limpieza, sin
fecha ni estado.

Por eso hoy el agente sólo puede **descartar** («hoy sale alguien, ni lo plantees») y **matizar**
(«está libre, pero la limpieza puede seguir»), nunca conceder. La regla de «salió con 5 horas de
antelación» no se puede implementar hasta que ese dato exista.

---

## 5. Sueltos

- **El CRUD de medios de cobro va con `MAESTROS_WRITE`.** Son datos financieros —cuentas
  bancarias, números de Yape— y comparten permiso con los maestros corrientes. Merece un rol
  propio.
- **La reserva XDGCYT tiene 14 huéspedes por unidad.** Es un error de captura, no de código: el
  push a Beds24 manda bien la cifra por evento. Hace falta el reparto real (las notas dicen
  2/6/6) y decidir qué pasa con la Casita 1, que quedó con 1 adulto.
- **Mensaje entrante duplicado a las 23:12:59** en `msg_message`. Detectado y nunca investigado.
