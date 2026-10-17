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

> Verificado el 11/08/2026 y revisado entero el 17/10/2026. Los números salen de producción.

---

## Vender sin pasar a mano por una cotización — 16/10/2026

Tres piezas que salieron al montar «Cómo reservar» (`docs/Cotizaciones.md` §6.a) y que Jorge
decidió dejar para después:

1. **Enlace de Culqi desde la cotización: HECHO el 16/10/2026**, con los avisos por WhatsApp
   (`docs/Cotizaciones.md` §6.a). `saldo_pendiente_viaje` está **aprobada por Meta en los
   7 idiomas** (comprobado el 17/10/2026 con `msg:meta:push saldo_pendiente_viaje --ver`).
   - Los tramos en alojamiento quedaron hechos el 16/10/2026 (`docs/FinanzasEnlacesPago.md` §11 bis).
     Los extras de Airbnb/VRBO reciben su enlace solos desde el día de llegada (decidido el mismo día).
2. **Clonar un tour del catálogo a un expediente con los pasajeros reales: HECHO** (07/10/2026).
   `ClonarAExpedienteModal` desde el catálogo pide fecha de inicio y número de pasajeros, y
   `CloneCotizacionProcessor` rechaza llevar un tour a un expediente sin fecha. Comprobado el
   17/10/2026.
3. **Las cuentas de la empresa.** Todas las de `fin_medio_cobro` están a nombre de Susan Acuña
   Romero (Western Union, de Jorge), y los tours los vende Openperu Travel Group SAC. Cuando haya
   cuentas de la SAC, se cambian en el panel de Finanzas; nada del código las nombra.

---

## Del chat de Clémence Mougin (8YXYNK) — 16/10/2026

Analizado entero contra el original en francés. Tres fallos se arreglaron el mismo día (líneas
que Google se comía, la estancia mínima que el agente ignoraba, «c'est bien noté» sonando a
reservado: `docs/Mensajeria.md`, `docs/Autotraduccion.md` §9). Los dos que quedaban, el 17/10:

1. ~~**El idioma del hilo salta a inglés con un mensaje corto.**~~ **Arreglado el 17/10/2026**:
   `DetectorDeIdiomaLocal`, ver `docs/Mensajeria.md` «Cómo se decide el idioma del hilo».

2. ~~**La hora de salida se apunta en la estancia equivocada cuando la reserva se alargó.**~~
   **Arreglado el 17/10/2026**: `CadenaDeEstancia`, ver `docs/Mensajeria.md` «La regla salió de aquí».

Y de la misma tanda de trabajo, menores:

- **Fotos de experiencias tras revocar la autorización.** Retirar un álbum ya borra las miniaturas
  de Liip, pero el original sigue respondiendo en `public/carga/front/experiencias/` (protegido sólo
  por el token del nombre). Cerrarlo es servirlas por controlador con `X-Accel-Redirect`. Decisión
  de Jorge, ver `docs/WebPublica.md` §2.1.a. Hoy hay 0 álbumes: es el momento barato.
- ~~**Cotizador: `cantidad = 0` da `NaN`.**~~ Arreglado el 17/10/2026 (`porCabeza()`).

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
- **Entrantes duplicados**: dos mensajes con el mismo id externo de WhatsApp, el último el
  30/09/2026 (medido el 17/10/2026 agrupando por `external_ids`). Raro, pero sin investigar.

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

### 🔍 Y un caso que no encaja del todo: HRD4ZD (06/09/2026)

Salió al revisar `tools/pruebas/probar-regeneracion.php`. La reserva tiene **todas sus estancias
canceladas**, y hasta ahí es el caso de arriba. Lo que no encaja es la cantidad: **trece**
mensajes `cancelled` de la regla «Check Out», **todos con el mismo `scheduled_at`**
(`2026-10-02 12:00`), más uno `failed` a la misma hora.

Churn de sincronizaciones repetidas explicaría dos o tres, no trece con el segundo idéntico.
Huele a un bucle que cancela y recrea, y si lo es, no es exclusivo de las canceladas: sería un
mensaje nuevo por cada pasada del motor sobre cualquier conversación que cumpla la condición.

**Medido el 17/10/2026: era la punta de algo, y ya no pasa.** Agrupando `msg_message` por
`(conversation_id, rule_id, scheduled_at)` salieron 23 grupos repetidos, el peor con **211** copias
(Vanessa Lordello, tres reglas). Todos se crearon entre el 24/08 y el 17/09, con restos hasta el
27/09; desde entonces, ninguno. Los mensajes de regla por semana bajaron de ~350 a ~30 en la semana
del 21/09, que es cuando se movió el veto de canal al momento del envío.

**Lo que queda es la basura**: ~1 500 filas `cancelled` que entran solas en el barrido de arriba.
Antes de borrarlas, si se quiere saber la causa exacta, el rastro está en esas filas.

## Un país por defecto no se distingue de uno confirmado

**Residuo de la corrección del país de Airbnb (19/08/2026), que ya está hecha y por eso se
retiró de aquí:** `resolvePais()` cae a `MaestroPais::DEFAULT_PAIS` (`'PE'`) cuando no hay ni
teléfono con prefijo ni `country2` — 4 de 47 reservas de Airbnb medidas aquel día.

En esas acertó, pero la reserva queda **indistinguible de una con país confirmado**, y
`PmsProcedenciaHuesped::pagaDesdePeru()` devuelve `true` sobre una suposición nuestra. Es el
mismo pecado que esa clase evita con el teléfono: ahí un `51` puesto por el saneador se trata
como `null` a propósito, y aquí un `PE` puesto por defecto se trata como dato.

**La salida no es cambiar el defecto** —una reserva necesita país para guardarse— sino poder
distinguirlos: una marca de «país supuesto» que `pagaDesdePeru()` lea como `null` en vez de
como `true`, y entonces se le enseñan todos los medios y elige el huésped.

Sin prisa: el defecto acierta casi siempre y el coste de equivocarse es enseñar un medio de más.

## ✅ CERRADO (06/09/2026): la skill del mensaje de prepago se borró

**Lo que había:** `GenerarMensajePrepagoSkill` componía un mensaje que **anunciaba** un «Enlace de
pago seguro» y no llevaba ninguna URL; el bloque que la habría puesto estaba escrito y comentado
dentro de la propia skill. La nota decía «bloqueado por la pasarela, no por el código», con
`FINANZAS_ENLACES_PREPAGO=0`.

**Ese bloqueo había caducado y nadie volvió a mirar la nota:** el flag está en **1** en
producción. Y con él encendido, la colisión que esta misma sección daba por *latente* pasó a estar
**activa** — `generar_enlace_prepago_pago_total` es `SkillConmutableInterface` y con el flag a 1 entra en el
catálogo.

**No se descomentó nada: se borró la skill entera.** Las cinco cosas que hacían falta para
encender aquel bloque dejaron de importar cuando quedó claro que el trabajo ya lo hacía mejor otro
camino:

| Lo que el operador pide | Quién lo resuelve |
|---|---|
| «¿cuánto debe?» | `consultar_cuenta` — línea a línea, en su moneda |
| «¿qué medios tenemos?» | `consultar_medios_pago` — catálogo `FinMedioCobro` con `ofrecibles()` |
| «envíale su información de pago» | `enviar_plantilla` + `pago_texto` |
| «dámelo para copiarlo» | `enviarme_plantilla` |
| «emite el enlace» | `generar_enlace_prepago_pago_total`, con previsualización |

`pago_texto` hidrata `{{ bloque_pago }}` y `{{ medios_de_pago }}` desde `PmsRedactorDeCobro` —siete
idiomas, números de cuenta reales— y lleva `{{ account_url }}` a la ficha, que es donde el huésped
ve el enlace vigente. El enlace **no viaja en el texto a propósito**: así no puede quedar un
importe escrito contradiciendo lo que el enlace cobra.

⚠️ **El motivo de fondo fue el TRIAJE, no la duplicación.** Era `NivelRiesgo::Lectura`, luego
enrutable directa (`CatalogoDelTriaje::enrutablesDirectas()`); sus sustitutas son escrituras y no
pueden serlo, por diseño. Ante «mándale el detalle de pago», la única que el triaje podía señalar
sola era la peor — y devolvía un texto bien formado, así que ganaba en silencio.

**Lo que se pierde:** nada. El desglose cargo a cargo ya lo da `consultar_cuenta`, y mejor: aquél
imprimía todo en `US$` aunque la cuenta estuviera en soles.

Los defectos que esta sección enumeraba —diagnóstico falso «sin prepago», `US$` en duro,
`Roles::HUESPED`, los `[ROOMNAME1]` crudos, el pisado con `generar_enlace_prepago_pago_total`— se van con
ella. Los dos que NO eran suyos siguen abiertos y viven ahora en `docs/FinanzasEnlacesPago.md`:
`emitirSimulado()` y `emitir()` comparten ya el mismo `loQueSePide()`, y `vigentePorImporte()`
**sigue comparando sólo el importe, sin moneda**.

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

- **Nombre cruzado en el histórico.** El de esta reserva ya está corregido (`Susanna` /
  `Pasquali`, comprobado el 13/10/2026), pero `RevisarOrdenDelNombreDispatch` sólo actúa cuando el
  nombre entra o cambia: **no cubre el histórico**. La herramienta ya existe —`pms:nombre:revisar
  --limite N --aplicar` recorre las N últimas reservas con el mismo revisor del worker—; falta
  correrla sobre el histórico y mirar el resultado.

---

## ✅ CERRADO (13/10/2026): el comprobante de 170,30 de la reserva 88591163

Se anotó el 19/08/2026 como «no registrado» porque el adjunto de Booking no se podía abrir. **Se
registró ese mismo día a las 21:45** —161,42 + 5,5 % de recargo = 170,30, el neto como manda
`PmsReservaOrigenCobroResolver`— y el 05/09 la huésped pagó en efectivo los 161,44 restantes. La
cuenta cuadra: **392,15 de cargos, 392,15 pagados**. El pendiente se quedó abierto dos meses sólo
porque nadie volvió a esta nota.

Los otros dos puntos que colgaban de aquí no eran trabajo: los 91 adjuntos restantes **son
nuestros** (`host`), no comprobantes del huésped, y el aviso de cobros sin registrar vive en la
sección de abajo, que es donde está la causa de raíz.

---

## El enlace de pago hecho fuera del sistema — 19/08/2026

> **Medido el 17/10/2026, últimos 45 días:** de 35 pagos con tarjeta, 29 llevan un enlace nuestro
> y **6 no** (puede ser TPV o tarjeta virtual de OTA, no necesariamente un enlace externo). Mejor
> que en agosto, pero sigue dependiendo de que alguien registre esos 6 a mano.

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
| ~~**Full Day Valle Vip** (la pool)~~ | **Hecho**: ya no sale en `app:travel:proponer-puntos --dry-run` (comprobado el 17/10/2026). |
| Full Day MAPI: CUZ OLLA MAPI OLLA CUZ (bimodal) | **No.** Tren + bus + ingreso + guía: ningún servicio abarca el día, y cada componente saca sus puntos de su propio segmento. |
| Skylodge · Starlodge · Two Day Camino inca · Two Day MAPI bimodal · Two Day Vertical Sky | **Pendiente de montar**, no de decidir. Ver abajo. |

### 3. El componente por día de los paquetes de varios días

> **Revisado el 17/10/2026** (detalle en `docs/Travel.md`, «Plantillas de varios días»). De las
> seis que lista el comando: **Camino Inca hecho** (día 1, `PROMOCIONES_POR_DIA`); las dos de
> **Two Day MAPI** se quedan sin principal a propósito; quedan **Skylodge, Starlodge y Vertical
> Sky**. Y dos cosas de dato encontradas al revisarlas:
> - ~~🔴 **Vertical Sky, día 2:** el segmento «Circuito Completo de Aventura» **no tiene ningún
>   componente**.~~ **Hecho el 17/10/2026**: «Experiencia Circuito Vertical Sky» a 0,00, principal
>   del día 2 (08:00–11:30). Ver `docs/Travel.md` «Plantillas de varios días».
> - 🔴 **Vertical Sky, el precio:** las actividades van a 0 y el importe debería estar en el
>   alojamiento, pero la cápsula usa el genérico «Alojamiento en Ollantaytambo» (tarifas de hotel a
>   0,00 o de cabaña a 200 PEN). **La plantilla se cotiza sin el precio de Vertical Sky.** Hace
>   falta un alojamiento propio con sus tarifas: pendiente del tarifario del proveedor.
> - ~~⚠️ **Alojamiento de la cápsula:** Starlodge usaba «Alojamiento en Ollantaytambo».~~
>   **Corregido el 17/10/2026** con el tarifario 2026 de Natura Vive, junto con tres importes, la
>   edad de Skylodge y las tarifas predeterminadas (`docs/Travel.md`, «Plantillas de varios días»).
>   Vertical Sky sigue con el genérico: es otro proveedor y falta su tarifario.

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

## El JSON financiero pesa 10× lo que dice, y el store son 4 500 líneas — 23/08/2026

> **Vigente, y creció** (medido el 17/10/2026): 24 filas, **2,4 MB** la clasificación interna y
> **2,1 MB** la del cliente; la fila más pesada, **269 KB** (era 166 KB).

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

> **Vigente** (17/10/2026): cuatro siguen mal partidos en la base —«Piero Gael Ladron de» +
> «Guevara Iwaki.», «Ray Santiago Diaz» + «La Torre», «Elena Paula Acosta Pinto» + «De Wilson»,
> «Wendy Jennifer Sanchez» + «De Olarte»— y `PadronFormato::partirNombre()` no ha cambiado. El
> grupo ya terminó: lo que importa es la regla para el próximo padrón.

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

---

## 🅿️ Izipay: PARADA hasta que se implemente — 28/08/2026

**Decisión: no se toca el camino de Izipay.** Ni para arreglarle los dos huecos de abajo. El
conector se queda entero y compilando —borrarlo obligaría a rehacerlo (`docs/FinanzasEnlacesPago.md`
§11)— pero no se le añaden guardas ni se refactoriza mientras nadie lo ejecute.

Lo que la dejó parada no es técnico: Izipay **exige S/200 000 de venta acumulada** para habilitar
enlaces de pago, y para vender eso hacen falta los enlaces. `FINANZAS_PASARELA_POR_DEFECTO=culqi`.

### Los dos huecos, verificados contra el código el 28/08/2026

Salieron de la pregunta «¿`anular()` propaga hasta la pasarela?». La respuesta corta es **no**, y
con Culqi da igual porque no hay nada que propagar: `crear()` no llama a nadie y el cargo lo crea
nuestro servidor con `cobrarConToken()`, que `estaVigente()` ya bloquea (410 en
`FinPagoPublicoController`, líneas de `/configuracion` y `/culqi/cobrar`).

| Hueco | Qué pasaría | Por qué hoy no ocurre |
|---|---|---|
| `FinEnlacePagoService::anular()` no revoca el `formToken` en Lyra | Un token emitido antes del anular sigue vivo unos minutos: quien ya tuviera la página abierta completa el pago | No se emite ningún enlace por Izipay |
| `FinEnlacePagoService::confirmarPago()` sólo corta si el estado es `PAGADO`, no si es `ANULADO` | Un IPN sobre un enlace anulado lo marcaría `PAGADO` y crearía el `PmsPagoFinanciero` | Sólo alcanzable por el IPN de Izipay; el webhook de Culqi necesita un cargo que no existe |
| `IzipayClient::reembolsar()` lanza a propósito: no sabe devolver | Un cobro emitido por Izipay no se puede devolver desde el sistema | No hay ningún cobro de Izipay. El stub está declarado y documentado, no olvidado |

### Cuando se retome, la dirección ya está decidida

El segundo hueco **no se cierra rechazando el pago**. Si el dinero se movió en la pasarela, el
cliente tiene el cargo en su tarjeta lo llamemos como lo llamemos: registrarlo es peor de leer y
mucho mejor que esconderlo. Lo que falta es **dejar rastro de la contradicción** —un enlace
`anulado` que acabó cobrado— no negarla.


---

## ✅ CERRADO (10/09/2026): el calendario etiquetaba soles como dólares

Se anotó y se arregló el mismo día. El símbolo salía de la moneda de la ficha y el importe de la
de los cargos: una reserva enseñaba `US$130` sobre S/ 130.00, con el saldo al lado en dólares de
verdad. Y el tooltip nunca leyó el desglose por moneda que el backend le mandaba «para el
tooltip», así que la pastilla decía `≈US$438` y él `US$437.99`.

El detalle y el porqué de que el símbolo tenga que salir de la misma rama que el número están en
`docs/Calendar_architecture.md`, sección «Dos monedas en la misma barra».

⚠️ **Al cerrarlo se anotó aquí un segundo riesgo que resultó FALSO**: que sin tipo de cambio la
pastilla saldría verde sobre una deuda real. `cuadra()` comprueba `hayMonedaSinConvertir()` antes
de mirar el cuadre y contesta que no cuadra — comprobado con datos reales en transacción. Queda
dicho en el doc, porque la hipótesis es plausible y alguien la volverá a tener.

---

## ✅ CERRADO (10/09/2026): el maestro de tipo de cambio llevaba 15 días parado

`maestro_tipocambio` termina el **26/08/2026**. Hoy es el 10/09. Verificado en producción con
`php bin/console app:test-tipocambio 2026-09-10`:

```
WARNING [app] Consulta mensual SUNAT vacía. Intentando diaria.
Fecha Solicitada    2026-09-10
Fecha del Dato      2026-08-26      ← Fallback
```

**No falla nada, y por eso lleva quince días así.** `TipocambioManager::findLastAvailableInDb()`
tiene doble respaldo y sirve la última cotización que haya; la nota de *Fallback* sólo la ve quien
ejecuta ese comando de prueba.

### Lo que sí se está estropeando

`PmsTipoCambioSnapshotListener` sella cada cargo, cada cobro y cada ficha con `venta()` — que hoy
devuelve **3.350, la del 26/08**. El docblock del campo dice que es «cuánto valía el dólar el día
en que ese dinero se movió»: eso hoy no es cierto para nada creado desde el 26/08.

No es una pérdida de dinero —el error de una tasa de hace dos semanas es de milésimas— pero es
justo el dato que existe para poder **reconstruir una cuenta a posteriori** y decirle a un huésped
a cuánto se le cambió.

### La causa: el proveedor se mudó

`apis.net.pe` migró a **decolecta.com** y reestructuró las rutas. La vieja devuelve 404 **con
token y sin él**, así que no era la cuenta ni la cuota.

`TipocambioManager` ya apunta al destino nuevo:

| | antes | ahora |
|---|---|---|
| URL | `api.apis.net.pe/v1/tipo-cambio-sunat` | `api.decolecta.com/v1/tipo-cambio/sunat` |
| día | `?fecha=YYYY-MM-DD` | `?date=YYYY-MM-DD` |
| mes | `?month=09` | `?month=9` (entero, sin cero) |
| campos | `{fecha, compra, venta}` | `{date, buy_price, sell_price, base_currency}` |

⚠️ **Los nombres de los campos son la mitad peligrosa.** Cambiar sólo la URL habría dejado
`parseResponse()` descartando todas las filas en su `isset()` y devolviendo vacío: **el mismo
síntoma exacto que el proveedor caído, con la API funcionando**. Lo cazaron los tests de
`TipocambioManagerTest` antes de llegar a producción.

### Resuelto el mismo día

Llave nueva de decolecta instalada en los dos `.env.local` (y `composer dump-env prod` en el
servidor, sin lo cual no habría servido de nada: producción arranca de `.env.local.php`). El
maestro pasó de **116 filas / 26-08** a **131 filas / 10-09**, con el hueco del 27 al 31 de agosto
tapado — `persistMonthData()` sólo rellena el mes de la fecha que se le pide, así que agosto
necesitó su propia llamada.

Y el cron que nunca existió: `app:pms:tipo-cambio:sincronizar`, 08:40 de Lima, que **falla y avisa**
si el maestro se queda atrás. El detalle en `docs/PmsBeds24ReservasSync.md` §12.18.

**Re-sellar lo ya escrito: descartado el 13/10/2026.** La diferencia es de milésimas y no compensa;
lo sellado con tasa vieja se queda así (`docs/PmsBeds24ReservasSync.md` §12.18). El 09/10/2026 el
maestro volvió a pararse —cuota del plan gratis de decolecta agotada— y desde el 13/10 el aviso va
por WhatsApp a soporte técnico con la causa. Mismo sitio del doc.

### Lo único que queda

⚠️ `pms:finanzas:completar-tipo-cambio` **sólo repasa cargos y pagos**, no fichas
(`findBy(['tipoCambio' => null])` sobre `PmsCargoFinanciero` y `PmsPagoFinanciero`). Hoy hay 3
cargos y 6 pagos sin sellar y 0 fichas, pero una ficha que naciera sin tasa no la recupera nadie:
su listener sólo corre en `prePersist`.

---

## Un canal deshabilitado deja mensajes muertos, y desbloquear no los revive — 10/09/2026

> **El hueco sigue en el código, sin casos nuevos**: desde el 10/09 no ha muerto ningún mensaje por
> el veto manual (medido el 17/10/2026). `setWhatsappDisabled(false)` sigue sin reencolar.

**El caso:** conversación de David Espejo (Giuliana), Casita 3, 09→15 sept, reserva **directa**.

| cuándo | qué pasó |
|---|---|
| 07/09 22:56 | Se programan la guía de llegada (08/09 08:00) y el aviso de check-out (10/09 12:00) |
| 07/09 22:56 | WhatsApp estaba deshabilitado a mano ⇒ las dos fallan al encolar |
| 10/09 15:50 | El operador desmarca la casilla desde el panel |
| ahora | Los dos mensajes siguen en `failed`. **Nadie los reintenta.** |

El huésped **está alojado ahora mismo** y no recibió ni la guía de llegada ni el aviso de salida.

### Lo que se ve y lo que es

El panel enseña «Motivo: **Bloqueado a mano desde el panel**» junto a una casilla
**desmarcada** — porque el texto es la foto del 07/09 guardada en `metadata.dispatch_errors`, y
el estado actual es otro. El operador no tiene forma de saber que está leyendo historia.

Comprobado: `msg_conversation.whatsapp_disabled = 0`, ninguna `msg_identidad` con `bloqueado = 1`.

**Alcance:** 3 mensajes en 2 conversaciones (desde el 13/08/2026). Poco volumen, consecuencia
alta: son avisos con fecha, y cuando se descubren ya no sirven.

### Qué hace falta

`MessageConversation::setWhatsappDisabled(false)` ya levanta el veto de las identidades — le falta
el otro lado: **reencolar lo que murió por ese veto**. Y decidir el borde: un aviso de check-out
de hace tres días no se manda, se descarta. Reintentar a ciegas es peor que no reintentar.

---

## Reparto de fallos: medido otra vez el 13/10/2026, y la cuenta vieja ya no vale

La cuenta del 10/09 (302 fallos de regla programada contra 3 del agente) se tomó antes de mover el
veto de canal al momento del envío. Repetida sobre lo creado **del 10/09 al 13/10/2026**:

| origen | cuántos | qué eran |
|---|---|---|
| Regla programada | **7** | 1 sin motivo; 6 son **avisos internos al equipo** (cobro, fusión) fuera de ventana, del 26/09 y el 02/10, antes de que sus plantillas estuvieran aprobadas. Hoy las nueve `aviso_*` lo están, en 7 idiomas |
| Agente (`generado_por = ia`) | **2** | ver abajo |

**Los 302 eran el veto de canal muriendo antes de tiempo, y con eso arreglado desaparecieron.**
Los automáticos ya no justifican un resumen diario: caben en la guardia técnica tal cual.

### Lo que sí queda, y son dos casos de huésped

- **Humberto Pereda (08/10, 23:46).** La respuesta del agente falló por Beds24 con
  `[Code 0] Error desconocido al enviar mensaje`. Lo atendió una persona dos minutos después.
- **Camille Boisfer (12/10, 19:50) — ✅ causa encontrada y arreglada el 13/10/2026.** Al unir su
  hilo de Booking con el de WhatsApp quedaron dos teléfonos vivos sin principal, y siguió de
  salida el que nunca nos escribió: sin ventana, 131047. La unión propone ahora el número con la
  ventana abierta y deja elegirlo (`FusionadorDeHilos::telefonoPropuesto()`, `docs/Mensajeria.md`
  «Fusionar dejó de ser sólo un comando»). Y la causa de fondo —la ventana era del hilo y Meta la
  cuenta por número— se cerró el mismo día: la ventana es de cada identidad y el chat deja elegir
  a qué número sale (`docs/Mensajeria.md` §5, «La ventana de WhatsApp es de cada NÚMERO»).

En los dos, el agente le dijo al huésped «ya he avisado al equipo» en un mensaje que **no le
llegó**. Que lo atendiera una persona después fue suerte, no diseño.

---

## Ventana por número: dos restos que la revisión dejó fuera — 13/10/2026

Salieron de la segunda revisión de Fable del cambio «la ventana de WhatsApp es de cada número»
(`docs/Mensajeria.md` §5). Ninguno bloqueaba el despliegue.

- ~~**Los botones contestan a la salida, no al que pulsó.**~~ **Arreglado el 17/10/2026**:
  `MessageConversation::telefonoParaResponderA()`, que usan el agente, `SendTemplateActionHandler`
  (todos los botones) y `EnviarmePlantillaSkill`. Ver `docs/Mensajeria.md` §5.
- ~~**El hilo `staff` de un operador no vincula su móvil nuevo.**~~ **Arreglado el 17/10/2026**:
  `conversacionStaff()` vincula también en el camino del hilo existente. Ver `docs/Mensajeria.md`,
  «Las conversaciones `staff`».
