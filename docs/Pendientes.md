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

---

## 4. Los mensajes de reservas canceladas se quedan ahí, y ensucian

Verificado el 12/08/2026 al poblar los enlaces de conversación: **106 de las 310 conversaciones
de alojamiento cuelgan de una reserva CANCELADA** —un tercio—, y otras 2 apuntan a una reserva
borrada que ya no existe (`context_id` es un string sin integridad referencial).

Entre esas canceladas hay reservas duplicadas nacidas de un bug de guardado: siete filas donde
debía haber una con cuatro eventos.

Sus mensajes programados siguen en `msg_message` y en las colas. Hoy no se envían —el motor los
cancela al barrer— pero ocupan, ensucian los recuentos y obligan a que cada consulta se acuerde
de filtrarlos.

**Decisión tomada (dueño del producto): se borran, con un cron que los limpie.** No al vuelo ni
con lógica dentro del motor, que ya tiene bastante: un barrido periódico que quite los mensajes
de asuntos cancelados.

⚠️ **No se hace todavía, y es deliberado.** Primero tiene que estar comprobado que el motor por
asunto funciona bien; borrar en paralelo a un cambio del motor mezcla dos causas cuando algo
salga mal. Cuando se haga, decidir también qué pasa con el aviso de cancelación por asunto, que
hoy queda mudo en modo enlace (`docs/Mensajeria.md` §20.6).

## Mejorar redacción de Agua Caliente en la Guía / Conocimiento

**Observación:** Durante la auditoría del Agente de IA, se notó que la duda de los huéspedes sobre si el agua caliente "alcanza para 5 personas" se puede responder de forma mucho más directa y elegante mejorando el texto de la base de conocimientos.

**Acción requerida:** Actualizar los textos en `pms_guia_item` y `agent_conocimiento` para aclarar que los calentadores son de paso continuo. 

*Texto sugerido a incorporar:* 
> "Contamos con calentadores a gas instantáneos (no de acumulación / no son termas con límite de litros). Por lo tanto, mientras haya gas, tendrán agua caliente ilimitada y continua, sin importar si se bañan 5 o más personas seguidas."

Con esto, el LLM tendrá material contundente para dar una respuesta técnica y tranquilizadora sin tener que deducirlo solo por la 'capacidad de la casa'.

## El país de las reservas de Airbnb es el idioma, no el país

**Observación (19/08/2026).** Cruzando `country2` con el prefijo del teléfono del mismo payload
en `pms_beds24_webhook_audit`: **16 reservas de Airbnb marcadas `ES` tienen móvil peruano
(+51)**, una +52 y otra +57. **Ninguna tiene +34.** Booking.com, en cambio, cuadra siempre
(`ES`↔+34, `FR`↔+33, `IT`↔+39).

Airbnb manda el **idioma** del huésped en ese campo cuando colisiona con un código de país:
`es`→`ES`, `fr`→`FR`, `pt`→`PT`. Con `en` no colapsa y entonces sí llega el país bueno. Hoy hay
**27 reservas de Airbnb guardadas como España**. Detalle en `docs/PmsBeds24ReservasSync.md` §3.3.

Aparte, en 4 de 47 reservas de Airbnb el campo llega `null` y `resolvePais()` cae a
`MaestroPais::DEFAULT_PAIS` (= `'PE'`). En esas cuatro acertó —apellidos y teléfonos peruanos—,
pero deja la reserva indistinguible de una con país confirmado.

**Por qué importa.** `PmsProcedenciaHuesped::pagaDesdePeru()` decide los medios de cobro. Con
`pais = 'ES'` devuelve `false` y a un peruano se le ofrece tarjeta con recargo y Western Union,
y **no** Yape, Plin ni transferencia: al revés de la regla, y a escala. Su respaldo por teléfono
—escrito justo para esto— no se ejecuta nunca, porque sólo mira el teléfono cuando la reserva no
tiene país, y el pull siempre le pone uno.

**Lo que NO está afectado:** el mensaje de prepago. Airbnb está en
`PmsChannel::CANAL_PAGO_TOTAL`, así que `GenerarMensajePrepagoSkill` sale antes de mirar el país.
Los afectados son `consultar_medios_pago`, `consultar_cuenta` y la guía del huésped.

**✅ Resuelto para lo que entre a partir de ahora (19/08/2026).** `resolvePais()` pide el país
al prefijo del teléfono en Airbnb, antes de mirar `country2`; y `country2` dejó de empujarse a
las OTA. Ver `docs/PmsBeds24ReservasSync.md` §3.3 y §7.2.

**✅ Y las reservas ya guardadas tienen comando** (19/08/2026):

```bash
php bin/console app:pms:corregir-pais-ota --dry-run    # dice qué haría
php bin/console app:pms:corregir-pais-ota              # lo hace
```

`PmsCorregirPaisOtaCommand`. Toca **sólo** las reservas con la firma exacta del fallo
—`pais === strtoupper(idioma)`, que es lo que produce la colisión `es`→`ES`— y usa como
evidencia el prefijo internacional del teléfono. Si el teléfono no lo trae, la deja como está:
no se cambia un país por una suposición.

En seco sobre la base local: **24 reservas se corregirían** —no sólo a `PE`: también `AR`, `CL`,
`MX` y `CO`, que estaban todas marcadas España— y 3 se quedan sin tocar por falta de teléfono
deducible. Idempotencia comprobada con datos reales en transacción con rollback
(`var/probar-corregir-pais.php`): segunda pasada, 0 reservas.

⚠️ **Está pendiente de correr en producción.** Los números de arriba son de la base local.

**Lo que sigue sin decidirse:** el `DEFAULT_PAIS = 'PE'` cuando no hay ni teléfono ni
`country2` (23 reservas de Airbnb no tienen teléfono deducible). Sigue tapando el «no se sabe»
que `PmsProcedenciaHuesped::pagaDesdePeru()` está escrito para devolver, y su respaldo por
teléfono continúa sin ejecutarse nunca.

## Descomentar el enlace de pago en el mensaje de prepago

**Estado (19/08/2026): bloqueado por la pasarela, no por el código.**

`GenerarMensajePrepagoSkill` compone un mensaje que **anuncia** un «Enlace de pago seguro» en sus
dos ramas —la nacional como Opción 2, la extranjera como Opción 1— y **no lleva ninguna URL**:
`FINANZAS_ENLACES_PREPAGO=0`, la pasarela no está habilitada. El bloque que lo emitiría está
escrito y comentado en la propia skill, justo antes de componer el mensaje.

⚠️ **NO basta con quitar las barras.** La primera versión de esta nota decía que sí, y era falso.
Hacen falta CINCO cosas, y sólo las tres últimas son de criterio:

1. **Inyectar el servicio.** `PmsPrepagoEnlaceService` **no está** en el constructor de la skill
   ni importado. Sin eso, `$this->prepagoEnlaces` es propiedad indefinida y revienta en la
   primera ejecución. PHPStan no lo ve hoy porque está dentro de un comentario.
2. **Capturar `DomainException`.** `emitir()` lanza con el flag apagado, sin cuenta de cobro y
   sin prepago pendiente. `GenerarEnlacePrepagoSkill` lo envuelve en try/catch; aquí, sin eso, la
   skill dejaría de contestar en vez de redactar el mensaje sin enlace.
3. **`emitir()` CREA UN COBRO** (`FinEnlacePago` persistido y flusheado). Esta skill es hoy
   `NivelRiesgo::Lectura`, o sea que se ejecuta **sin que nadie confirme**. Emitir desde aquí la
   convierte en escritura: hay que subirle el nivel y pasar por la previsualización, como ya hace
   `generar_enlace_prepago`.
4. **O no emitir aquí**: que esta skill sólo LEA un enlace ya emitido y deje la emisión donde
   está. Es la opción que no toca el nivel de riesgo, y probablemente la buena — dos skills que
   crean cobros para lo mismo es justo lo que se quiere evitar.
5. **Mientras tanto el texto promete algo que no viaja.** El operador copia y pega un mensaje que
   le anuncia al huésped un enlace seguro, con el importe y el 5.5% ya calculados, y el enlace no
   existe. Si la espera va para largo, lo honesto es quitar esa línea del mensaje antes que
   dejarla puesta.

**Relacionado, y sin resolver:**

- `generar_mensaje_prepago` declara `Roles::HUESPED`, así que está en el catálogo del huésped
  (comprobado con `app:agent:permisos`). El texto que produce es de operador —«🏨 DETALLE DE
  RESERVA», emojis de ficha, «listo para copiar y pegar»— y encima le anuncia un enlace que él no
  puede pedir, porque `generar_enlace_prepago` exige `RESERVAS_WRITE`. Tampoco se ancla al
  contexto del actor: coge `entrada['reserva_id']` directo, sin el `reservaDelContexto($actor)`
  que sí usa `consultar_cuenta`.
- Asume **USD**: imprime `US$` en duro y multiplica por el tipo de cambio. Con una cuenta en PEN
  etiqueta soles como dólares y convierte dos veces. `ConsultarCuentaSkill` lee `getMoneda()`
  justo por esto.

**Lo que sí está resuelto:** el importe. Las cuatro skills de prepago salen de
`PmsPrepagoCalculador::pendiente()` —`generar_enlace_prepago` vía `PmsPrepagoEnlaceService`— así
que ya no pueden decir cifras distintas para la misma reserva.

### Lo que salió al revisarlo, y sigue abierto

- **El TOTAL del mensaje no es el de `consultar_cuenta`.** `desgloseCargos()` reimplementa el
  desglose en vez de usar `getDesglosePorTipo()`, que es la fuente declarada: descarta los cargos
  de importe ≤ 0, no filtra por `esCargo()`, ignora `activa` y no convierte moneda. Con la
  reserva **GASUNN** de la base local —tiene un «Descuento tipo de cambio» de −0.20— el mensaje
  imprimiría `US$ 66.17` y `consultar_cuenta` dice `65.97`, con el prepago calculado sobre 65.97.
  El mismo huésped, dos totales en la misma conversación. De la misma raíz: en una cuenta anulada
  (`activa=false`) el mensaje imprimiría todos los cargos, y encima redacta «para confirmar y
  garantizar tu reserva» sobre una reserva cancelada, porque no mira el estado.
- **Diagnóstico falso «sin prepago».** Si `pendiente()` y `calcular()` dan `null`, el mensaje
  afirma «La política del establecimiento está configurada como sin prepago». Pero `calcular()`
  también devuelve `null` con **base 0** (reserva nueva, cargos aún sin generar) y con **noches
  < 1**. El operador lee que ese establecimiento no pide adelanto, y no lo pide.
  `GenerarEnlacePrepagoSkill` enumera los motivos sin afirmar uno; aquí habría que hacer igual.
- **`emitirSimulado()` y `emitir()` pueden discrepar en la MONEDA.** El simulado reporta la de la
  cabecera; `emitir()` llama a `FinEnlacePagoService::crear()` **sin pasar moneda**, así que el
  resolver elige la de mayor saldo. Con cargos en dos monedas y sin pagos —el único caso en que
  `pendiente()` pasa— la previsualización dice «65.97 USD» y se emite por «65.97 PEN». Y
  `vigentePorImporte()` compara sólo el importe, **sin moneda**: un enlace vigente de 100 PEN se
  reutiliza para un prepago de 100 USD. Latente: 1 cabecera con moneda cruzada en local, ninguna
  con prepago pendiente.
- **Reserva de grupo:** el mensaje sale con «👤 Huésped: Pendiente Sync (Grupo)», los
  placeholders que siembra el pull. Y los conceptos de cargo se imprimen crudos: los
  `[ROOMNAME1]` de Beds24 sólo se limpian en `ConsultarCuentaSkill::concepto()`, no aquí.
- **El pisado con `generar_enlace_prepago` está latente**, no activo: con el flag a 0 la skill del
  enlace no entra en el catálogo (`SkillConmutableInterface`). El día que se encienda, ninguna de
  las dos descripciones cita a la otra, y ante «mándale el cobro del adelanto» el triaje puede
  elegir la de Lectura —que se ejecuta sin confirmación—, encadenarla a `enviar_mensaje_huesped` y
  darse por satisfecho: el huésped recibe la promesa de un enlace que no existe y se puenteó la
  previsualización del cobro. La dirección inversa sí es segura (`confirmado=false` frena).
