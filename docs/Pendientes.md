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

- ✅ **RESUELTO (19/08/2026): el TOTAL del mensaje ya es el de `consultar_cuenta`.**
  `desgloseCargos()` reimplementaba las reglas del desglose y se saltaba cuatro: no filtraba
  `esCargo()`, ignoraba `activa`, no convertía moneda y **descartaba los importes ≤ 0**. Ese
  último se vio con la reserva `GASUNN` —un «Descuento tipo de cambio» de −0.20—: el mensaje
  decía `66.17` y la cuenta `65.97`. Y era peor que una discrepancia entre skills: el prepago
  del **mismo mensaje** ya salía del desglose canónico, así que un solo texto llevaba dos
  aritméticas. Ahora llama a `getDesglosePorTipo()`, que es la fuente declarada, y la igualdad
  es por construcción. Exposición medida en producción antes del arreglo: **1 reserva, 0.20**.
  Los otros tres siguen siendo latentes y son más caros que ése; ver `var/probar-desglose-prepago.php`.

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

## El plan de pagos pactado no existe como dato, y el sistema afirma lo contrario

**Estado: sin resolver. Es de modelo, no de cálculo.**

### El caso, con fechas (reserva `5Y6AGN`, Susanna Pasquali, Booking)

| Cuándo | Qué pasó |
|---|---|
| 20/06 | Entra la reserva: 9 noches, 29/08–07/09, **623.68** |
| 20/06 | Se le manda **por chat** el plan: `69.29` + `242.55 (14 de agosto)` + `311.84 a la llegada` |
| 20/06 | Se registra el pago de **69.29** (a mano, tarjeta, 5.5%) |
| 14/07 | El canal acorta la estancia: 6 noches, 01–07/09, **392.15**. Los cargos se actualizan solos ✓ |
| 19/08 | La huésped pregunta por «the second rate of 242.55». Nadie sabe de dónde sale |

### Por qué falla, y no es que falte un pago

Los importes guardados **son correctos** (392.15 de cargos, 69.29 pagado, 322.86 de saldo, y el
total cuadra con `pms_evento_calendario.monto`). No falta ningún cobro: ella dijo que no lo había
pagado, preguntó cómo hacerlo y quedó en efectivo.

Lo que falta es **el plan pactado**. No hay entidad que lo guarde: existen `FinEnlacePago`,
`PmsPoliticaPrepago` y `PmsPrepagoCalculador`, pero ninguno modela «las cuotas que se acordaron
con este huésped, con su importe y su fecha». El plan vivió sólo como texto en un chat.

⚠️ **Y el sistema no se queda callado: afirma lo contrario.**
`PmsPrepagoCalculador::pendiente()` devuelve `null` en cuanto hay un pago registrado, así que
desde el 20/06 la ficha decía «no hay prepago pendiente» mientras había 242.55 comprometidos por
escrito. El operador que abre la ficha no puede saber de dónde sale la cifra que le citan; de ahí
su «hubo una confusión en la respuesta» — la confusión se la dio la pantalla.

Cuando el 14/07 cambió el total, los cargos se recalcularon y **el plan no**, porque no era un
dato. Quedó una promesa viva contradiciendo la cuenta, invisible salvo leyendo un chat de dos
meses antes.

> **El principio:** el trabajo del operador es mirar valores y calcular, no leer conversaciones e
> interpretar. Todo compromiso que sólo viva en prosa obliga a lo segundo — y tarde o temprano
> alguien interpreta mal. Es la misma lección del país deducido y del total del prepago.

### Qué hace falta

Un **plan de pagos como dato de la reserva**: cuotas con importe, fecha y estado
(pactada / pagada / anulada). Con eso:

1. El operador ve las cuotas en la ficha, sin leer nada.
2. El mensaje al huésped **se genera desde el plan**, no al revés — que es como está hoy y por
   eso se descuadra.
3. Al cambiar el total de la reserva, el plan se marca desfasado y salta, igual que salta el
   descuadre de la cabecera. En este caso habría avisado el **14 de julio**, un mes antes de que
   la huésped preguntara.
4. El agente lee cuotas en vez de interpretar conversaciones.

**Decisiones abiertas antes de construirlo:**

- Relación con `PmsPoliticaPrepago`: la política **deriva** un prepago; el plan **registra** lo
  pactado. ¿El plan nace de la política y luego se puede editar, o son cosas separadas?
- Qué pasa con un plan cuyo total ya no cuadra: ¿se anula entero, se reajusta la última cuota, o
  sólo se marca y decide una persona? (Con dinero de por medio, lo tercero parece lo sano.)
- Si `pendiente()` debe pasar a mirar el plan en vez de «¿hay algún pago?».

### Suelto pero relacionado

- El cargo de alojamiento de esta reserva se llama `[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`:
  placeholders de Beds24 sin resolver. `ConsultarCuentaSkill::concepto()` los limpia;
  `GenerarMensajePrepagoSkill` **no**, así que ahí saldrían crudos al huésped.
- El nombre de esta reserva está cruzado (`nombre_cliente = "Pasquali"`,
  `apellido_cliente = "Susanna"`), y la conversación se llama «Pasquali Susanna». Cualquier
  plantilla con `{{ nombre }}` la saluda por el apellido. `RevisarOrdenDelNombreDispatch` sólo
  actúa cuando el nombre entra o cambia: **no cubre el histórico**. Falta un comando que lo
  barra, como se hizo con `app:pms:corregir-pais-ota`.

---

## El comprobante que nadie pudo abrir — 19/08/2026

**Es el mismo agujero de arriba, visto desde el otro lado.** No falta un plan de pagos: falta
que llegue el dato del pago.

La huésped de la reserva **88591163** (Pasquali, 01–07/09/2026) mandó por Booking.com el
comprobante de un cargo. El comprobante existe, es legible y es inequívoco:

| Dato | Valor |
|---|---|
| Monto | **170,30 USD** |
| Fecha | 19/08/2026 10:44 |
| Medio | Mastercard `516499…5726` |
| Autorización | X49320 |
| Referencia | «Sussane set» |

**Y no está registrado.** Lo que hay en su cuenta hoy:

```
cargos    suplemento de limpieza                     15.00
          [ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]   327.96
          cargo por servicio                         49.19
                                                   ───────
                                                    392.15

pagos     20/06/2026  tarjeta de crédito             69.29
                                                   ───────
saldo que muestra el sistema                        322.86
saldo real, con el comprobante                      152.56   ← 170.30 de diferencia
```

**Por qué nadie lo anotó:** el adjunto llegó como un enlace que no se podía abrir. El `href`
venía sin host y el panel lo resolvía contra su propio dominio → 404. Eso ya está arreglado
(`EnlaceDeBeds24` + `Version20260819235500`, 92 mensajes corregidos), así que a partir de ahora
el enlace **se abre**. Ver `docs/Mensajeria.md` §23.

Pero el arreglo tiene un techo: el operador puede abrirlo porque tiene sesión en beds24.com; el
servidor **no puede descargarlo** —está probado que ninguna credencial de la API sirve—. Así que
el comprobante nunca estará en nuestra base ni lo verá el agente.

**Lo que queda pendiente, por orden:**

1. **Registrar los 170,30 de esta reserva.** Es un dato, no una interpretación: está el número de
   autorización.
2. **Revisar los otros 91 mensajes con adjunto.** Ninguno se pudo abrir en su momento, así que
   cualquiera podría llevar dentro otro comprobante sin anotar. Ahora los enlaces funcionan.
3. **Decidir si se avisa.** Un adjunto que el sistema no puede leer y que puede ser dinero
   debería levantar la mano solo, en vez de esperar a que alguien mire el hilo. Es exactamente el
   caso de «ver valores, no interpretar conversaciones»: hoy el valor está en una imagen que el
   sistema no ve.

---

## El enlace de pago hecho fuera del sistema — 19/08/2026

Investigando el adjunto de la reserva 88591163 salió la causa de raíz, y no es la que parecía.

**La máquina para esto ya existe y funciona.** `PmsReservaOrigenCobroResolver::registrarCobro()`
—que dispara el webhook de la pasarela— registra el pago solo, y lo hace *bien*:

| Lo que hace | Con qué |
|---|---|
| Registra el **neto**, no lo que cobró la tarjeta | `setMonto($enlace->getMontoNeto())` |
| Guarda el recargo aparte, sin mezclarlo | `setComisionPorcentaje($enlace->getRecargoPorcentaje())` |
| Deja la referencia de la transacción | `setReferencia($enlace->getTransaccionUuid())` |
| Usa la fecha real del cobro | `setFechaPago($enlace->getPagadoEn())` |

Es exactamente lo que hubo que hacer a mano el 19/08. **No se disparó porque el enlace no era
nuestro:** ni `8kwer06x` ni `oxf9ihjq` existen en `fin_enlace_pago`. Se crearon a mano en el panel
de Izipay, así que no hubo webhook, no hubo registro, y el rastro quedó en una captura de pantalla
subida al hilo de Booking.

**El patrón:** los 92 adjuntos de `getattach.php` son `host` —los subimos nosotros, en 70
conversaciones, desde marzo—. Ninguno viene del huésped. O sea que no es que lleguen comprobantes
que no podemos leer: es que **se cobra fuera del sistema y luego se documenta con una foto**. La
foto no suma en ninguna cuenta.

⚠️ **Pero el proceso manual funciona casi siempre, y conviene decirlo.** Revisadas otras cuatro
reservas de la misma tanda, el enlace tampoco era nuestro (`rbacwxeh` no está en
`fin_enlace_pago`) **y aun así el pago sí se registró a mano**:

| Reserva | Total | Pagado | |
|---|---|---|---|
| Jessa Shillig `90761098` | 260.30 | 86.76 | ✅ |
| Scott Shillig `90761245` | 246.15 | 82.05 | ✅ |
| Sarah Beament `86341124` | 330.24 | 330.52 | ✅ (0.28 de más) |
| Gabriela Avila `85698685` | 0.00 | — | anulada, coherente |

Así que lo de la reserva 88591163 fue **un fallo puntual, no la norma**. Lo que sigue en pie es
que el registro depende de que una persona se acuerde, y esa vez no se acordó. Eso baja la
urgencia de los tres puntos de abajo, no los anula: un proceso que funciona «casi siempre» sobre
dinero acaba fallando, y falla sin avisar.

**Qué hacer, por valor:**

1. **Que los enlaces de cobro salgan siempre del panel.** Es la única de las tres que elimina el
   trabajo manual en vez de vigilarlo. Falta averiguar por qué hoy no se usa: ¿no cubre algún
   caso, no se conoce, o es más incómodo que el panel de Izipay?
2. **Conciliar contra Izipay.** Para lo cobrado por fuera, traer las transacciones de la pasarela
   y casarlas con reservas. Pesca también lo que se cobre a mano en el futuro.
3. **Avisar del descuadre.** Un enlace enviado por chat sin pago registrado a los N días debería
   levantar la mano. Es la red, no la solución.

⚠️ Lo que **no** resuelve esto es bajar el adjunto de Beds24: el archivo es nuestro, la foto es
del cobro que ya hicimos, y recuperarla no registra ningún pago. Ver `docs/Mensajeria.md` §23.

---

## Ampliar Graph: de sólo enviar a leer y vigilar — 19/08/2026

Decisión tomada: el correo deja de ser una salida y pasa a ser también **una entrada**. Sobre la
mesa hay al menos tres usos, y conviene tratarlos como un mismo proyecto porque comparten la
aplicación de Graph y el modelo de permisos:

1. **Leer el código de segundo paso de Beds24**, para poder mantener una sesión desde el servidor
   y bajar los adjuntos (ver `docs/Mensajeria.md` §23).
2. **Monitorizar confirmaciones de proveedores** — que la organización que presta el servicio haya
   confirmado, sin que alguien tenga que mirar el buzón.
3. Lo que vaya saliendo: el usuario anticipa «muchas cosas sobre correo».

### Lo que hay que resolver antes de la primera línea de código

**El permiso.** Hoy la aplicación tiene `Mail.Send` **y nada más**, a propósito. Leer exige añadir
lectura de buzón, y ahí la pregunta no es «¿qué permiso pido?» sino «¿sobre qué buzones?».

⚠️ **El mecanismo que lo hace seguro ya está montado y hay que reutilizarlo**: la
`ApplicationAccessPolicy` de `docs/CorreoSaliente.md` es lo que convierte un permiso de tenant en
uno acotado a buzones concretos. Es la diferencia entre darle al servidor la llave de un buzón y
darle la del correo de la empresa. Sin esa política, `Mail.Read` es exactamente el escenario
contra el que ese doc advierte.

**Un buzón por trabajo, no uno para todo.** El de los códigos de Beds24 no debería recibir nada
más: si además le llegan confirmaciones de proveedores, el alcance del permiso deja de poder
razonarse por buzón y hay que razonarlo por mensaje, que es donde empiezan los errores.

**Y `docs/CorreoSaliente.md` hay que actualizarlo en la misma sesión en que se amplíe.** Hoy dice
«`Mail.Send` y nada más» en tres sitios y argumenta explícitamente en contra de ampliar. En cuanto
se amplíe, ese doc está mintiendo — y un doc que miente cuesta más que no tenerlo. Lo que tiene
que quedar escrito no es sólo el permiso nuevo, sino **por qué dejó de valer el argumento viejo**.

### Sobre el caso 1, para que no se compre de más

Bajar los adjuntos de Beds24 **no recupera ningún pago**: los 92 son documentos que subimos
nosotros. Es un archivo, no una fuente de dinero. Si el correo se amplía, que sea por los casos
2 y 3, y que el 1 vaya de propina.

### Sobre el caso 2

Encaja en `src/Travel/`, con las organizaciones y sus componentes ya modelados: quien presta el
servicio es `TravelComponente::getPrestador()`, y a quién se le manda el encargo, `getComprador()`
cuando existe. Falta decidir qué es «confirmado» —¿un correo de vuelta, un estado, una fecha
límite?— antes de tocar código.

## Origen y fin de servicio: lo que queda — 22/08/2026

El mecanismo está montado y desplegado (ver `docs/Travel.md` §11 quater y `docs/Cotizaciones.md`
§6.e). Esto es lo que **no** está.

### 1. ~~Los puntos todavía no salen en la orden de servicio~~ — HECHO 22/08/2026

**Resuelto de punta a punta.** Ver `docs/Operacion.md` §12: las tres capas (override del operador,
catálogo, cadena de alojamiento), congelado al emitir, y los campos editables en el cuadro de
tráfico. Lo de abajo queda como registro de lo que faltaba.

<details><summary>Lo que decía</summary>


Es el destinatario original de todo esto. `TravelPuntosDelServicio` y `CotizacionPuntosDelServicio`
ya los calculan, y el editor de cotizaciones ya los pinta, pero **`OperacionOrdenDocumento` no los
escribe**, así que al proveedor le sigue llegando la orden sin «dónde recojo / dónde dejo».

Y falta la mitad que convierte el modo en un sitio: **resolver `ALOJAMIENTO` contra la cadena de
alojamiento del expediente**. Está verificado que la cadena funciona —15 noches sin huecos en el
itinerario de Nune, 8/8 traslados correctos— pero no está enchufada.

⚠️ **Una noche sin alojamiento tiene que fallar en voz alta.** En un trek de varios días el
pasajero duerme en campamento, no en hotel: ahí la cadena tiene un hueco legítimo. Un resolvedor
descuidado coge el hotel de la noche anterior y manda al proveedor a Cusco — plausible y falso,
que es la familia de fallo más cara de este código. Para los campamentos, la respuesta correcta es
declararlos como `TravelPunto` (Wayllabamba, Pacaymayo, Wiñay Wayna) y usar modo `FIJO`, no
`ALOJAMIENTO`.

</details>

### 2. Plantillas sin servicio principal

`app:travel:proponer-puntos` las lista en su sección «para revisar» cada vez que se ejecuta.

| Plantilla | ¿Es un olvido? |
|---|---|
| **Full Day Valle Vip** (la pool) | **Sí.** Le toca `Pool Super Valle`, igual que a la Tradicional `Pool Valle Sagrado`. Es la única de las cuatro del Valle sin marca. Una línea en `TravelPromoverServicioPrincipalCommand::PROMOCIONES`. |
| Full Day MAPI: CUZ OLLA MAPI OLLA CUZ (bimodal) | **No.** Tren + bus + ingreso + guía: ningún servicio abarca el día, y cada componente saca sus puntos de su propio segmento. |
| Skylodge · Starlodge · Two Day Camino inca · Two Day MAPI bimodal · Two Day Vertical Sky | **Pendiente de montar**, no de decidir. Ver abajo. |

### 3. El componente por día de los paquetes de varios días

Las cinco plantillas de varios días no tienen principal **por día**, y el mecanismo ya lo admite:
la unicidad es `(plantilla, día)`, así que un Camino Inca de 4 días acepta cuatro marcados.

El patrón acordado: crear un componente por día —«Segundo día Camino Inca»—, **aunque sea de costo
0**, sólo para que aporte hora de inicio y de fin, y promoverlo.

⚠️ **La Categoría Operativa de ese componente decide si sirve para algo.** Con `extras` o `ticket`,
`ComponenteTipoEnum::puntosDeServicio()` devuelve `NINGUNO` y la promoción **no aporta ningún punto
de recojo, sin dar error**.

### 4. Plantilla de Meta con botón de URL para la orden fuera de ventana

Aplazado explícitamente. La página pública y el PDF ya existen
(`/orden/{token}` y `/orden/{token}.pdf`); falta la plantilla aprobada para mandar el enlace
cuando la ventana de 24 h está cerrada.

---

## Las traducciones manuales cuestan más de lo que resuelven — 22/08/2026

Dicho por el operador: *«pensé que iba a usar traducciones manuales, pero ahora me da más problemas
de los que soluciona»*.

### El problema

`AutoTranslationService` sólo pisa una traducción existente si `sobreescribirTraduccion` está
activo:

```php
if (!$overwrite && !$isContentEmpty) continue;
```

Protege lo escrito a mano, sí — **al precio de que cualquier cambio del original deje los otros
seis idiomas mintiendo, en silencio y para siempre**. No lo detecta nadie, porque quien revisa lee
español. Casi ocurre al renombrar «Valle sagrado tradicional privado» → «Valle Vip privada»: el
español habría dicho *Valle Vip* y los demás habrían seguido diciendo *Sacred Valley*, *Vale
Sagrado*, *Heilige Tal*, en un producto que cuesta un 40 % más.

Hoy la única defensa es acordarse de activar el flag **antes** de tocar el campo, y verificar
después que la regeneración de verdad ocurrió — una llamada de red que falla no lanza nada y deja
el campo sólo en español.

### Las dos salidas, y ninguna es un rato

- **Invertir el defecto:** regenerar siempre, salvo que el campo esté marcado como manual. El
  riesgo se da la vuelta: se pierde una redacción cuidada en vez de publicar una mentira.
- **Marcar la manualidad POR IDIOMA** en vez de por entidad. Es probablemente lo que se quería al
  poner el flag: «el inglés lo escribí yo, el resto que se traduzca». Hoy es todo o nada.

**Antes de elegir, medir:** cuántos campos tienen de verdad traducción manual y cuántos la tienen
sólo porque nadie regeneró nunca. Sin ese número la decisión es una corazonada, y las dos opciones
tocan todos los campos con `#[AutoTranslate]`.

---

## El JSON financiero pesa 10× lo que dice, y el store son 4 500 líneas — 23/08/2026

Medido en producción, no estimado. Es el trabajo previo de mover el cálculo financiero a un
servicio (`docs/NodeEnElStack.md`), y está aquí para no volver a medirlo.

### Lo que pesa, y quién lo lee

`cotizacion_cotizacion`, 10 filas, **1 MB** — y el 99,6 % son dos columnas:

| columna | media | máx | quién la lee |
|---|---|---|---|
| `clasificacion_financiera` | 53 KB | 166 KB | **nadie** |
| `clasificacion_financiera_cliente` | 49 KB | 156 KB | `CotizacionPublicNormalizer` y pax |
| `titulo`, `resumen`, el resto | 0,2 KB | | |

La interna aparece **cuatro veces** en todo el repo: tres declaraciones de tipo y
`payload.clasificacionFinanciera = fin` al guardar. Ni PHP, ni `util/`, ni `pax/` la leen. Lo que
la pantalla enseña —la barra de totales, el panel Resumen— sale de `resumenFinanciero`, que es un
`computed` que **recalcula desde el árbol** en cada carga.

⚠️ Aun así está en `cotizacion:read`, así que **viaja al navegador en cada apertura del editor**.

### Dónde está el peso dentro de la fila (la de 166 KB)

```
clasesPasajeros[0].detalle   75 KB   42 líneas × 26 campos
inclusiones                  78 KB   17 × 4,6 KB
   └─ el 60 % del total (100 KB) son bloques i18n: 262 nodos × 7 idiomas
   └─ 27,7 KB son cadenas LITERALMENTE repetidas
        x13  «Camino Inca corto a Machupicchu de 2 dias» + sus 6 traducciones
        x13  su UUID
```

Cada línea de `detalle` lleva `servicioNombre`, `componenteNombre` y `tarifaTitulo` **completos con
sus siete idiomas**, y trece de esas líneas son del mismo servicio.

### El síntoma que ya duele, y no es el disco

```
sort_buffer_size del servidor   256 KB
una fila, las dos columnas      hasta 322 KB
```

**Con diez filas ya revienta.** Pasó dos veces el mismo día: contando los históricos de una
cotización (`getTotalHistoricos()`, resuelto poniendo la colección `EXTRA_LAZY`) y en un
`ORDER BY LENGTH()` de diagnóstico. Cualquier consulta que ordene esta tabla arrastrando estas
columnas falla en producción con «Out of sort memory», un error que no dice nada del diseño.

### Qué hacer, y en qué orden

1. **Deduplicar los textos.** Una tabla de textos dentro del propio JSON y las líneas
   referenciando por id: ~28 KB de 166 (17 %) **sin tocar ni un idioma ni el contrato con pax**.
2. **Quitarle los idiomas a la interna.** Nadie le enseña esa clasificación a un cliente. Ahí no
   corre el riesgo que sí tiene la del cliente —si falta el idioma es una pantalla interna, no la
   propuesta—. Otro ~30 %.
3. **La interna, cuando el cálculo sea un servicio: fuera.** Quien la necesite la pide.

⚠️ **La del cliente NO se borra, y el motivo no es que no se pueda calcular.** El árbol está
congelado (`*Snapshot`) y `tipoCambio` también, así que recalcular da lo mismo **mientras la
función no cambie** — y va a cambiar. El día que se arregle un redondeo, una propuesta enviada por
$5 899,65 se re-renderiza en $5 899,70 la próxima vez que el cliente abra su enlace. Derivable y
seguro de derivar no son lo mismo: **el árbol está congelado, el código no.** Esa columna es el
registro de lo que se le dijo, no una caché.

### Y el store: 4 502 líneas, 100 miembros expuestos

`util/src/stores/cotizacion/cotizacionEditorStore.ts`. Los cien miembros expuestos son la señal,
no la longitud: un store que expone cien cosas no tiene frontera, así que ninguna capa es capa.

**El corte no hay que inventarlo**, lo marca la extracción del cálculo. El criterio es *¿lo
necesita el agente, que no tiene navegador?*:

| A `services/` (sin Vue) | Se queda en el store (estado de pantalla) |
|---|---|
| el cálculo y sus reglas | `abrirNivel`, `historialNavegacion` |
| `construirInclusiones` + advertencias | `isDirty`, borradores, foco |
| `expurgarParaCliente` | `componenteActivo`, `tarifaActiva` |
| `resolverPrestador` / `resolverComprador` | los `fetch*` de catálogos |

La columna izquierda es toda la lógica que ya tiene espejo en PHP o va a tenerlo. No es
casualidad: **lo que hay que abstraer es exactamente lo que estaría escrito dos veces.**

⚠️ La trampa no es la carpeta —`util/src/services/` ya existe y funciona: seis archivos, cada uno
una capacidad, ninguno sabe de Vue—. La trampa es la **superficie**: un `services/cotizacion.ts`
con sesenta funciones es el mismo problema con otro nombre. Prueba barata: **si el archivo compila
sin importar nada de Vue, está bien cortado.**

### El orden que no cambia

**Fixtures antes que nada** (`docs/NodeEnElStack.md` §5): cotizaciones reales de las difíciles
—rangos de edad, opcionales, multimoneda—, guardar la salida de hoy y exigir que lo extraído dé
exactamente lo mismo. Son ~527 líneas que nunca se han revisado con calculadora. Sirve para las
dos cosas —mover el cálculo y ordenar el store—, así que se paga una vez.

---

## Padrón de grupos grandes: documentos por persona, subgrupos y acceso — 23/08/2026

Decidido con el padrón real de **Punta Cana 2026** (Colegio San José La Salle, 133 personas)
delante. Nada empezado. Se anota para no rehacer el análisis.

### Lo que el padrón real ya denuncia

Medido sobre el archivo, no estimado:

```
133 personas · 100 menores de 18 a la fecha del viaje · 28 adultos · 5 sin fecha
 11 DNI YA VENCIDOS hoy (23/08/2026), el más antiguo del 22/06
  8 DNI vencen DURANTE el viaje (28/08 – 24/09)
 22 personas sin fecha de vencimiento de DNI cargada — no se puede ni comprobar
  0 pasaportes vencidos
```

⚠️ La hoja *Resumen* del padrón comprueba **pasaportes** y dice «vence antes del retorno: 0».
Nadie comprobó el **DNI**, que es con lo que se embarca el tramo nacional Cusco–Lima. Once
personas hoy no embarcan, y una es Coordinador.

**Eso es lo que justifica la funcionalidad**: no es un listado más bonito, es que un vencimiento
sin campo se revisa a mano y a mano se olvida uno de los dos documentos.

### No son dos documentos, son tres

DNI y pasaporte parecen bastar, pero **100 de 133 son menores** y salir del Perú siendo menor
exige **autorización de viaje notarial**: tercer documento, por cabeza, con su vigencia y su
escaneo, y es el que para en migraciones y no en el mostrador.

Por eso van como **filas y no como columnas**: cuatro columnas se vuelven ocho, la mitad nulas
para los adultos, y el día del carné de extranjería o la visa toca migración.

### El modelo acordado

**Documentos** — `CotizacionFiledocumento` ya tiene `vencimiento`, tipo y subida de archivo. Le
falta el dueño, y con **dos claves nulables** queda todo el alcance resuelto:

```
pasajero  (nullable) → DNI, pasaporte, autorización notarial, boarding pass
grupo     (nullable) → namelist de Arajet JA2CWN: lo ve su grupo entero
ninguno              → global del expediente (lo de hoy, intacto)
```

`ArchivoTipoEnum` gana `DNI`, `PASAPORTE`, `AUTORIZACION_MENOR` (hoy sólo conoce boleto, factura,
reserva, otros).

⚠️ `CotizacionFilepasajero` **ya tiene** `tipodocumento` y `numerodocumento`. Con los documentos
como filas queda el DNI en dos sitios, y aquí *la copia muerta gana porque es la que se lee*. Hay
que decidir: vaciarlos y exponer `documentoPrincipal()`, o mantenerlos por listener con la regla
escrita de quién manda. Preferido lo primero.

**Subgrupos** — una sola tabla y la pertenencia en many-to-many. **No es un árbol**: en el padrón
real salón y grupo se cruzan (5 salones, 10 grupos, 43 combinaciones; 9 de los 10 grupos aparecen
en más de un salón, el grupo 1 en los cinco).

```
Grupo
  file                ⚠️ cuelga del EXPEDIENTE: el «Grupo 5» de Punta Cana no es otro
  tipo    enum        salon | grupo | habitacion | reserva_aerea
  clave   'B' · '5' · 'HA13' · 'JA2CWN'
  nombre  opcional    si está vacío se rotula tipo + clave
  ÚNICO (file, tipo, clave)
  pasajero ↔ grupo    many-to-many, y la fila de pertenencia lleva `esJefe`
```

El **tipo va como enum** —son pocos y el código sí distingue al menos uno: una reserva aérea lleva
PNR y documentos—; **la clave nunca**: de cuatro ejes, dos tienen valores que los pone alguien de
fuera (66 habitaciones del hotel, 20 PNR de aerolíneas). La **unicidad** hace el trabajo que se le
pediría a un enum de valores: al reimportar el Excel corregido —y se va a reimportar varias veces
antes del viaje— un `firstOrCreate` por `(file, tipo, clave)` no duplica nada.

**Jefe de grupo** es un rol de la **pertenencia**, no de la persona: alguien lidera el grupo 5 y es
miembro raso del salón B.

### El acceso: buscar por documento, no un enlace por persona

Se reparte **un** enlace, el de la cotización, con `/search`: una ventanita pide tipo de documento
y número, y muestra lo suyo. Si es jefe de grupo, lo suyo **y lo de su grupo**.

Resuelve mejor que un token por miembro el problema que sí es real: el padrón lleva un reemplazo en
trámite (Alma Angelina → María del Carmen), y un enlace por posición apuntaría a otra persona el
día que alguien se dé de baja. Aquí la persona se identifica sola y no hay nada que repartir.

⚠️ **Es un FILTRO, no un control de acceso, y así se decidió a propósito.** Cualquiera con el
enlace y un DNI del grupo ve los datos de esa persona.

Eso es aceptable **por lo que hay detrás, y sólo mientras siga siendo eso**. La regla que lo
mantiene barato:

> **Tras el filtro va lo que el pasajero necesita LLEVARSE. Lo que sirve para COMPROBAR se queda
> del lado del operador**, en `util/`, que ya tiene sesión y roles.

| Se queda en `util/` | Puede salir por el `/search` |
|---|---|
| escaneo de DNI y pasaporte | itinerario, grupo, habitación |
| autorización notarial de menor | códigos de reserva, hora de encuentro |
| | boarding pass |

El escaneo del pasaporte existe para que **nosotros** verifiquemos la vigencia; el pasajero ya
tiene su pasaporte y no necesita descargárselo. La autorización notarial es un **insumo** que
entrega la familia, no un entregable. Con esa separación el enlace público nunca tiene detrás nada
que justifique un SMS o un token por persona — que para 133 familias de un colegio es coste
operativo real: números mal cargados, padres que no lo reciben, y soporte.

Si algún día hace falta fricción sin infraestructura: la búsqueda ya pide tipo **y** número; añadir
la fecha de nacimiento como tercer campo es gratis. No es seguridad —quien tenga el padrón los
tiene todos— pero corta el probar números a ver qué sale, que es el uso realista.

⚠️ Lo que **no** se puede hacer es meter ahí los escaneos «porque es cómodo» y dejar la nota. Ese
es el día en que esto sí necesita un segundo factor.

### El orden

1. **Documento con dueño + los tres tipos + la consulta de vencimientos.** Paga solo: los 11 DNI
   vencidos dejan de descubrirse leyendo un Excel.
2. Los subgrupos y la importación del padrón.
3. El `/search` y el jefe de grupo.

---

## Rellenar una plantilla oficial sin destruirla — 23/08/2026

Las plantillas del **Ministerio de Cultura** (carga de visitantes a Llaqta) se rechazan como «no
oficiales» después de pasar por PhpSpreadsheet, y de paso pierden los colores. No es un fallo que
arregle actualizar la librería: es lo que hace un *round-trip*.

Al abrir el `.xlsx` del Ministerio como zip aparece lo que se pierde:

```
customXml/item1.xml       18 KB   DataMashup — Power Query embebido
customXml/itemProps1.xml          su datastoreItem {2162FCA5-…}
xl/connections.xml                las conexiones de datos
xl/tables/table1.xml              la tabla real (ListObject)
xl/printerSettings/*.bin
xl/theme/theme1.xml               los colores cuelgan de aquí (theme="4" tint=…)
hojas: Hoja1 + «Tablas» oculta con estudianteLista, listaSexo, paisesLista,
       procedenciaLista, tarifasLista, tdocLista
```

**PhpSpreadsheet no modela `customXml/`, ni las conexiones, ni los ListObject**: al cargar y
volver a guardar, reconstruye el libro desde su modelo interno y esas partes no vuelven. El
`DataMashup` es lo que el Ministerio busca para reconocer su propia plantilla, y el tema que
emite el escritor no es el mismo del que cuelgan los índices de color de los estilos. De ahí
salen exactamente los dos síntomas: «no oficial» y colores cambiados.

**La vía que sí funciona: no cargar el libro.** Un `.xlsx` es un zip; se copia entrada por
entrada y se sustituye **sólo `xl/worksheets/sheet1.xml`**, con los valores como `inlineStr`
para no tocar tampoco `sharedStrings.xml`. Todo lo demás llega byte a byte como salió del
Ministerio, así que no hay nada que se pueda perder — ni ahora ni cuando cambien la plantilla.

⚠️ **No afecta al padrón.** Ahí la plantilla se genera desde cero y el importador sólo lee, que
son los dos usos en los que un round-trip no destruye nada. Esto es para plantillas ajenas.

Alternativa si algún día hay que rellenar plantillas con fórmulas vivas: LibreOffice headless.
Es un proceso más que mantener, así que sólo si el zip no basta.

---

## El precio al cliente se calcula desde el costo, y en un grupo grande eso no aguanta — 23/08/2026

Hoy no existe precio de venta: sale de `CotizacionCottarifa::$montoCosto` × (1 + margen), donde
el margen es `Cotizacion::$comision` o el `comisionOverrideSnapshot` de la línea
(`cotizacionEditorStore.ts` L693-707, L866). **El precio al cliente es una función del costo.**

Eso funciona mientras el costo no se mueva después de aceptar. En Punta Cana 2026 se movió dos
veces, y las dos son el caso normal de un grupo grande:

- se cotizó el vuelo entero en **Arajet** y hubo que partir el grupo en dos aerolíneas;
- **JetSmart** subió el precio a última hora y la mayoría acabó en **Sky**.

Con el precio derivado, cada una de esas correcciones **mueve el precio de una familia que ya
aceptó**. Y son correcciones inevitables: cuadrar las órdenes es exactamente mover prestadores y
partir un servicio en varias tarifas.

**Lo que ya está resuelto en el modelo:** el prestador vive en la **tarifa**
(`CotizacionCottarifa::$prestadorMaestroId`), no en el componente, así que un mismo componente
admite ya varias tarifas con prestadores distintos — Arajet y Sky bajo «Vuelo internacional» —
y las órdenes salen por prestador. No hace falta partir el componente ni duplicar la línea que
lee el cliente.

**Lo que falta es un `montoVenta` fijado**, nullable, que cuando está gane sobre `costo × margen`.
Con él el margen pasa a ser **derivado** (`venta − Σ costos`), que es justo el uso interno que se
quiere: se ve cuánto se perdió con el cambio de aerolínea sin que el cliente se entere de nada.

⚠️ El eje de esta decisión **no es el modo del expediente**: un precio cerrado lo quiere también
una cotización de dos personas. Si se ata a `FileModoEnum::GRUPO` habrá que desatarlo después.

Ver también el panel de inclusiones específicas por participante, que ya privilegia el precio
fijado a mano frente al calculado.

### El clasificador no puede repartir lo que no sabe quién compró

Medido sobre el padrón real de Punta Cana 2026 (133 personas, 10 servicios):

```
105 con el paquete completo   96 alumnos + 9 coordinadores
 13 sin «Comidas Lima»        todos acompañantes y supervisores
  7 invitados                 paquete mínimo, cada uno distinto
  2 «No participa»            nada
  3 alumnos sin vuelos        van por su cuenta
```

12 combinaciones, pero **una cubre el 79%** y la segunda es una resta por rol. Lo irregular de
verdad son **10 personas**.

**Lo que pasa hoy.** `resumenFinanciero` reparte por *atributos de la tarifa* —rango de edad y
procedencia—: elige la «partición canónica» (el componente cuyas tarifas por pax suman
exactamente `numPax`) y asigna los demás componentes sobre esas clases. Con `numPax = 133` y
ningún componente que llegue a 133, salta en **los diez a la vez**:

> «Comidas Lima» no cubre a todos los pasajeros: quedan sin tarifa 27 … **Se está cobrando de
> menos.**

Es un falso positivo. El aviso se puso para cazar el `numPax` subido sin reescalar tarifas; aquí
dispara por lo contrario. Y «arreglarlo» poniendo 133 en cada tarifa cobra de más a 12 personas.

**Por qué no se arregla dentro del clasificador.** Son dos modelos del mismo hecho: el
clasificador reparte por atributo de tarifa, el padrón por **pertenencia** (`+Coco Bongo`). Una
tarifa no sabe **quién**. Representar 12 combinaciones daría 12 clases de 1–3 personas — cifras
que ya no describen a nadie, el mismo motivo por el que se quitó el «precio total del viaje».

**La forma que encaja:** el clasificador se queda con el **paquete base** (para lo que está
hecho); la resta por rol y las 10 excepciones salen del **padrón**, al panel de inclusiones
específicas. Precio de cada familia = precio fijado ± lo suyo — sin `montoVenta`, quitarle
«Comidas Lima» a un acompañante le recalcula el paquete entero.

⚠️ Y el aviso de cobertura, en modo grupo, debe compararse contra **cuánta gente tiene ese
`+servicio` en el padrón**, no contra `numPax`. Así vuelve a ser útil: avisa si la tarifa dice
120 y el padrón dice 122.

### El precio calculado ya viaja al cliente, línea por línea

`expurgarParaCliente()` (`util/src/types/cotizacionEditorModel.ts`) quita `montoCosto` pero
**conserva `ventaSoles`/`ventaDolares` en cada línea del `detalle`**, por clase, más
`totalVentaBruta`. En el JSON que recibe `pax` está «Coco Bongo: 45 USD» desglosado.

Así que **no basta con no mostrarlo**: es una decisión de la vista y el dato llega igual — el
mismo fallo que ya pasó con `montoCotizado`. Y cuadrar una orden no mueve un número, mueve
**todos** los del desglose, contra los que el cliente puede comparar lo que vio al aceptar.

**El catálogo ya tiene la interfaz** (`preciosDesde`: perfil + moneda + valor, traducible), pero
con el orden invertido: ahí el precio fijado es una etiqueta encima de una verdad calculada, y
por eso se ve inútil — el cliente lee «Desde 890» y debajo la suma da 913.

Lo que falta:

- **`preciosDesde` deja de estar tras `modoCatalogo`**, igual que se hizo con `totalesOcultos` y
  por el mismo motivo.
- **El expurgador no emite `ventaSoles`/`ventaDolares` cuando hay precio fijado.** Es lo que
  convierte «no lo muestro» en «no se puede ver»; sin esto lo demás es cosmético.
- **El sugerido se queda, del lado interno.** Es lo único que dice si el precio fijado pierde
  dinero tras mover el grupo a Sky. Lo que sobra es enseñarlo *junto* al fijado.

⚠️ La propiedad que importa del sugerido **no es el redondeo, es que no se mueva solo**: un botón
que **rellena el campo una vez** (redondeando hacia arriba), no un valor vivo. Un valor vivo
vuelve a cambiar el día que cuadras la orden, que es el problema entero. Si el costo sube después,
el aviso es interno: «fijaste 890 y ahora cuesta 913».

---

## Seis apellidos compuestos mal partidos en el padrón de Punta Cana — 24/08/2026

`PadronFormato::partirNombre()` parte «Nombres y Apellidos» por **las dos últimas palabras**, que
es la convención peruana y acierta con la inmensa mayoría. Falla con los apellidos compuestos, y
en el padrón de Punta Cana 2026 hay **seis**:

```
ELENA PAULA ACOSTA PINTO DE WILSON   → nombres «Elena Paula Acosta Pinto» + apellidos «De Wilson»
                                        (debería ser «Elena Paula» + «Acosta Pinto de Wilson»)
PIERO GAEL LADRON DE GUEVARA IWAKI
RAY SANTIAGO DIAZ LA TORRE
WENDY JENNIFER SANCHEZ DE OLARTE
MARIA DEL CARMEN VELASQUEZ ZEGARRA
MERLY DEL CARMEN CARDENAS PAREDES
```

Concatenados **se leen bien** —«Elena Paula Acosta Pinto De Wilson»— así que en pantalla no se
nota y nadie lo va a reportar. Donde duele es al **filtrar o buscar por apellido**, y en cualquier
listado que ordene por él.

⚠️ **No se arregla en el código**: partir un nombre completo es una conjetura y ya se avisa al
importar. Se arregla **en el archivo**, poniendo nombre y apellido en columnas propias en esas
seis filas, y volviendo a cargar —el `Id` de la exportación hace que cada fila vuelva a su
persona, así que no se duplica nadie.

La capitalización sí está resuelta: `NombreSanitizer` baja el CAPS LOCK y respeta las partículas.

---

## `ContactoDeIdentidad`: la comprobación de `useAttrs()` es código muerto — 24/08/2026

El componente decide si ofrece el campo de la semilla así:

```ts
const editable = computed(() =>
    Boolean(attrs['onUpdate:telefono'] ?? attrs['onUpdate:correo'])   // ← SIEMPRE undefined
    || props.telefono !== undefined
    || props.correo !== undefined,
);
```

**La primera línea no vale nada.** `defineEmits` declara `update:telefono` y `update:correo`, y Vue
**extrae de `attrs` los `onUpdate:` de los emits declarados** para que no acaben en el DOM. Así que
`attrs['onUpdate:telefono']` es siempre `undefined` dentro de este componente.

Se escribió justo para cerrar un callejón sin salida —un expediente sin teléfono ni correo manda
los dos `undefined`, el componente pintaba sólo lectura y no había forma de escribir el primero— y
**nunca lo cerró**. Peor: dejó un comentario largo afirmando que estaba resuelto, así que el
siguiente que lo mire lo dará por bueno.

El síntoma se arregló el 24/08/2026 **desde quien llama**: `FileDetalle.vue` pasa ahora
`:telefono="file.telefono ?? ''"` con `@update:telefono`, igual que hacía `OrganizacionFormulario`
—donde nunca falló, precisamente por el `?? ''`—.

Lo que queda por hacer aquí:

1. **Borrar la rama muerta** y dejar el docblock diciendo la verdad: lo que decide la edición es
   que el padre pase un valor definido.
2. Si se quiere volver a detectar el listener, `getCurrentInstance()?.vnode.props` sí lo conserva
   —declarado o no—, pero es API interna: mejor un prop explícito.

⚠️ No se tocó ya porque el arreglo del síntoma no lo necesita y el componente lo usan tres
pantallas. Al tocarlo, comprobar las tres: `FileDetalle`, `OrganizacionFormulario` (con modelo) y
`OrganizacionesView` (SIN modelo, sólo lectura a propósito).

---

## Dos skills que se anuncian con la misma palabra: quién desempata — 24/08/2026

Salió al llamar `vuelos` a lo que se devuelve del padrón, precisamente para **no** decir
«itinerario» y chocar con la futura skill de itinerarios de `src/Travel/`. La pregunta de fondo
queda abierta: cuando dos herramientas comparten un término, ¿lo resuelve **el contexto** o **la
pregunta**?

### Lo que ya hay, y hasta dónde llega

`SkillRegistry::paraActor()` **ya desempata por contexto**: lo que un actor no puede usar ni se le
menciona al modelo. Un huésped no ve la skill del padrón, así que para él «itinerario» no es
ambiguo — sólo hay una cosa que puede ser.

⚠️ **Pero el caso que duele se lo salta.** `esDelDominioDe()` no se aplica a quien
`esDelEquipo()`, y es a propósito: quien atiende alojamiento y tours por el mismo WhatsApp interno
necesita las dos cajas a la vez. Y es justo el operador quien va a tener **el padrón y los
itinerarios de Travel delante al mismo tiempo**. Para él, el filtro por contexto no desempata
nada: le llegan las dos definiciones.

### Las tres salidas, en orden de coste

1. **Que no compartan la palabra.** Es lo que se hizo, cuesta cero en ejecución y no falla nunca.
   Mientras se pueda nombrar distinto, gana.
2. **Que la que se equivoca devuelva un puntero**, no una respuesta plausible. Es el patrón que
   esta skill ya usa para los nombres de eje: nunca lista vacía, siempre las opciones reales. Si
   al padrón le preguntan por un itinerario que no guarda, lo correcto es que conteste qué **sí**
   tiene y nombre la otra herramienta — no que devuelva los vuelos como si fueran eso.
3. **Desempatar por la pregunta** — clasificar la intención antes de elegir. Es lo más caro y lo
   menos fiable: es volver a meter un intérprete de lenguaje natural donde ya se decidió no
   tenerlo (ver `docs/Agent.md` §5.4, «por qué los filtros son parámetros y no una frase»). Sólo
   tendría sentido si un día hay tantas skills que la lista no cabe en el prompt.

### Lo que hay que decidir cuando se escriba la skill de itinerarios

Si acaba llamándose `consultar_itinerario` y el operador tiene las dos, comprobar **con una
pregunta ambigua real** («el itinerario de Santiago») a cuál llama el modelo. Si se equivoca, la
salida es la (2): que la equivocada lo diga. No hace falta nada más hasta verlo fallar.
