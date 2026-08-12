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

## 1. Nunca se marca la entrega ni la lectura en la cola de WhatsApp

`msg_whatsapp_meta_send_queue` tiene `delivered_at` y `read_at`, y están **vacías en las 2368
filas**. Cero.

El webhook de Meta sí recibe los acuses —el estado `read` aparece en los mensajes— pero los
escribe en `msg_message.metadata`, no en la cola. Así que esas dos columnas son hoy peso muerto:
o se llenan, o se quitan. Dejarlas como están invita a que alguien construya un informe encima y
le salga todo a cero.

---

## 2. No existe registro de la salida real ni del fin de la limpieza

Lo documenta ya el docblock de `PmsEspacioEstancia`, y aquí queda porque **bloquea una regla que
se quiere**: conceder entrada temprana cuando el huésped anterior se fue con margen.

`PmsEventoCalendario` guarda el fin **previsto** y un booleano `salidaTardia`, pero nadie apunta
a qué hora se fue de verdad. `pms_evento_limpieza` dice **quién** limpia —se asigna desde el
drawer de Reservas—, pero ni cuándo empezó ni cuándo terminó.

(La tabla vieja `pms_event_assignment`, evento↔usuario↔actividad, ya no interviene en esto: no
la lee ninguna consulta de limpieza, sólo la escribe un formulario embebido de EasyAdmin. Antes
de construir nada encima, decidir si se retira.)

Por eso hoy el agente sólo puede **descartar** («hoy sale alguien, ni lo plantees») y **matizar**
(«está libre, pero la limpieza puede seguir»), nunca conceder. La regla de «salió con 5 horas de
antelación» no se puede implementar hasta que ese dato exista.

---

## 3. Sueltos

- **El CRUD de medios de cobro va con `MAESTROS_WRITE`.** Son datos financieros —cuentas
  bancarias, números de Yape— y comparten permiso con los maestros corrientes. Merece un rol
  propio.
- **La reserva XDGCYT tiene 14 huéspedes por unidad.** Es un error de captura, no de código: el
  push a Beds24 manda bien la cifra por evento. Hace falta el reparto real (las notas dicen
  2/6/6) y decidir qué pasa con la Casita 1, que quedó con 1 adulto.
- **Mensaje entrante duplicado a las 23:12:59** en `msg_message`. Detectado y nunca investigado.
