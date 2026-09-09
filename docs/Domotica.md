# Domótica — aparatos inteligentes, consumo y estado

El parque de enchufes y switches inteligentes de las casitas, colgado de la nube de Tuya: qué hay,
en qué estado está, y —para los que llevan contómetro— cuánta electricidad gasta cada huésped, con
una bitácora horaria que se le puede enseñar.

**La medición es UNA parte del módulo, no el módulo.** Nació para cobrar la calefacción por kW·h,
pero lo que se registra aquí son aparatos: los hay que sólo conmutan, y el accionamiento
(encender/apagar desde el sistema) es el siguiente paso previsto. Ver §3.

**Alcance:** `src/Domotica/` (dominio) y el cliente de Tuya en `src/Exchange/Service/Client/`
(pendiente). Fuera de alcance: el cobro en sí, que cuando llegue pasará por `src/Finanzas/`
como cualquier otro importe.

**Estado (08/09/2026):** están las entidades, los repositorios, la vigilancia, la configuración
y **las dos migraciones, aplicadas en producción**. El cron de vigilancia lleva semanas corriendo
allí cada hora. **No están todavía** el cliente de Tuya, el muestreo, el accionamiento ni las
vistas — y por eso las tres tablas están **vacías en producción**: no hay ni un aparato dado de
alta, así que el vigilante barre el vacío y siempre reporta `0 aviso(s)`. Ver §8.

---

## Índice

1. [Por qué existe](#1-por-qué-existe)
2. [El modelo: proyección y bitácora](#2-el-modelo-proyección-y-bitácora)
3. [Dos capacidades independientes](#3-dos-capacidades-independientes)
4. [Las dos columnas](#4-las-dos-columnas)
5. [El reinicio](#5-el-reinicio)
6. [El crédito diario](#6-el-crédito-diario)
7. [El contrato de Tuya](#7-el-contrato-de-tuya)
8. [Lo que falta](#8-lo-que-falta)
9. [Dónde tocar para cambiar X](#9-dónde-tocar-para-cambiar-x)
10. [La vigilancia](#10-la-vigilancia)
11. [Pendiente de comprobar antes de diseñar el vivo](#11-pendiente-de-comprobar-antes-de-diseñar-el-vivo)
12. [El monitor de consumo: `pax` y `util`](#12-el-monitor-de-consumo-pax-y-util)
13. [El parque real y el mecanismo de sincronización](#13-el-parque-real-y-el-mecanismo-de-sincronización)
14. [Lo que se construyó el 08/09/2026](#14-lo-que-se-construyó-el-08092026)

---

## 1. Por qué existe

El alquiler de calefactores generaba fricción constante: el huésped cree que la calefacción es
cara, nosotros sabemos que no lo es, y ninguno de los dos puede demostrarlo. Y hay abuso real
—gente con la estufa encendida a pleno sol— que el alquiler plano no penaliza.

El cambio de modelo es dejar de alquilar el aparato y **cobrar el kW·h a tarifa de Electro Sur
Este**, con una cortesía diaria por aparato y el consumo **visible en tiempo real** en la
reserva del huésped.

Lo que lo hace funcionar no es el cobro, es la transparencia: poder decirle *«cuando entraste a
las 14:00 el contador marcaba 546 y tu consumo era 0; a las 15:00 marcaba 549 y llevas 3»*, con
el historial completo detrás. Un número sin historial se discute; un extracto no.

## 2. El modelo: proyección y bitácora

```
  DomoticaDispositivo      el enchufe físico, atado a una PmsUnidad
          │
          │  ManyToOne
          ▼
  DomoticaSuscripcion      dispositivo ↔ evento de calendario
          │               consumoTotal · consumoCliente   ← ESTADO ACTUAL (proyección)
          │
          │  ManyToOne
          ▼
  DomoticaLectura          una fila por hora
                          consumoTotal · consumoCliente   ← HISTÓRICO (fuente de verdad)
```

**La regla de la que cuelga todo lo demás: la suscripción es una proyección de la bitácora,
nunca al revés.** Se puede recalcular entera recorriendo `DomoticaLectura`; existe sólo para no
tener que recorrerla cada vez que se abre la app. Si las dos discrepan, la buena es la bitácora.

Eso es lo que hace defendible cobrar por consumo: cada sol facturado se rastrea hasta una
lectura con su hora.

La suscripción cuelga del **evento de calendario, no de la reserva**. Una reserva puede partirse
en varios eventos, y quien está durmiendo ahí —y encendiendo el calefactor— pertenece a uno
concreto.

**Dependencia dura a `App\Pms`.** `DomoticaDispositivo` apunta a `PmsUnidad` y
`DomoticaSuscripcion` a `PmsEventoCalendario` con FK de verdad, saliéndose de la pauta de
Finanzas —que evita la FK con `origenTipo`/`origenId`—. El motivo es que allí había un segundo
consumidor previsible (tours, ventas sueltas) y aquí no: un enchufe está clavado en un
dormitorio de una casita concreta, y quien sabe qué es un dormitorio es el PMS.

## 3. Dos capacidades independientes

`DomoticaDispositivo` no es «un contómetro»: es un aparato con dos capacidades que se declaran por
separado y ninguna es obligatoria.

| Campo | Qué habilita | De dónde sale |
|---|---|---|
| `mideConsumo` | Muestreo, suscripción, bitácora y facturación | `add_ele` en la especificación |
| `conmutable` | Accionar desde el sistema — **pendiente**; hoy el estado sólo se lee | `switch_1` en la especificación |

⚠️ **Las dos son DERIVADAS, no se teclean.** El aparato reporta lo que sabe hacer en
`/v1.0/devices/{id}/specifications`; el alta y el `--resync` las rellenan. Nacen en `false`
(`Version20260815020000`) porque hasta esa consulta la respuesta honesta es «no sé», y un `true`
sin comprobar mete a un detector de gas en el muestreo y en las alertas cada tres horas.

Aquí la asimetría se **invierte** respecto a la regla de la guía —donde una lista vacía es «sin
acotar» porque el ítem de más es inofensivo—: un `false` equivocado sólo retrasa el muestreo hasta
que alguien sincronice. Fallar callado, no a gritos.

No confundir con `activo`, que es la otra mitad: `mideConsumo` es lo que el aparato **puede**;
`activo` es si **queremos** usarlo. Un enchufe con contómetro que no se quiere facturar se
desactiva, no se marca como que no mide.

**El modelo verificado** (`product_id: labfkgzm2ikdcpdp`, Aubess Smart Switch/EM) expone
`add_ele` (kW·h, escala 3), `cur_power` (W, escala 1), `cur_current`, `cur_voltage` y `switch_1`:
mide y conmuta. Comprar por `product_id` — «Aubess Smart Switch» sin el `/EM` es otro producto y
no mide. Y dos DP que salen gratis: `fault` (`ov_cr`, `ov_pwr`, `ls_cr`…) sirve de diagnóstico
anticipado, y `relay_status` (0/1/2) decide qué pasa tras un corte de luz — hay que fijarlo a
propósito, o un apagón enciende calefactores en casitas vacías.

`encendido` + `estadoTomadoEn` valen **por sí solos, sin contómetro**: saber que un aparato quedó
encendido en una casita vacía ya es accionable, y es la otra mitad de la fricción que originó el
módulo.

⚠️ `DomoticaDispositivoRepository::mudos()` y `lecturaVencida()` filtran por `mideConsumo`. Un
aparato sin contómetro **no está mudo**: no da lectura porque no la tiene, y avisar de eso cada
tres horas quemaría el canal.

## 4. Las dos columnas

`consumoTotal` y `consumoCliente` están en la suscripción **y** en cada fila de la bitácora, y
no miden lo mismo:

| | Qué es | Se reinicia |
|---|---|---|
| `consumoTotal` | Lo que marca el aparato. Verificable contra la app de Tuya | **Nunca** |
| `consumoCliente` | Lo que se le imputa a quien está alojado | Al entrar y a solicitud |

Tener las dos es lo que permite decir *«el aparato marca 551 y tú llevas 5,5»* sin que ninguno
de los dos números tenga que mentir.

⚠️ `consumoCliente` **no es** `consumoTotal - lecturaInicial`. Los reinicios rompen esa resta a
propósito: se acumula sumando incrementos, que es lo que sobrevive a que se ponga a cero a mitad
de estancia.

`DomoticaLectura::$incremento` guarda los kW·h del tramo aunque se puedan deducir restando filas
consecutivas. Es redundancia deliberada: es lo que devuelve Tuya (`add_ele` es un incremento por
hora, no un acumulado) y guardarlo tal cual deja comprobar si un salto raro venía de la nube o lo
introdujimos nosotros al acumular.

## 5. El reinicio

Pasa de verdad: el personal de limpieza deja el calefactor encendido y el huésped entra con
consumo que no es suyo.

**Un reinicio no rebobina la tabla: escribe una fila más.** Mismo `consumoTotal`,
`consumoCliente` a cero, marcada con `DomoticaMotivoLectura::Reinicio` y su nota. El salto en la
cuenta queda explicado dentro del propio historial.

Si se hiciera con un `UPDATE`, el salto no tendría explicación visible y el huésped tendría que
fiarse de nosotros — justo lo que este módulo existe para evitar. Es la regla de «no se borra: se
marca» de `CLAUDE.md`, aquí llevada al extremo porque hay dinero de por medio.

`DomoticaMotivoLectura::reiniciaConsumoCliente()` es quien decide qué motivos ponen a cero;
`exigeNota()` marca los que decide una persona y por tanto piden explicación escrita.

## 6. El crédito diario

Cortesía **por día y por aparato**, en soles. Quien usa el calefactor con cabeza no paga nada y
ni se entera de que existe un contador; lo que se cobra es el exceso.

`DomoticaSuscripcion::diasDeCredito()` cuenta **días empezados, no completos**: quien entra a las
14:00 tiene su crédito del primer día entero desde el minuto uno. Prorratearlo por haber llegado
tarde sería exactamente la clase de detalle que arruina la sensación de trato justo que busca
todo esto.

El crédito no gastado **no se acumula ni se devuelve**: es una cortesía diaria, no un saldo.
`importeACobrarSoles()` nunca devuelve negativo.

`tarifaSolesKwh` y `creditoDiarioSoles` se **congelan en la suscripción** al abrirla. Los
parámetros de `services_domotica.yaml` son sólo la semilla. El precio del kW·h lleva años quieto,
pero el día que se mueva no puede recalcular hacia atrás lo que ya se le dijo a un huésped que
iba a pagar — mismo criterio que `FinEnlacePago` con sus importes.

**Sin decidir:** el multiplicador del crédito (por huésped / por habitación / por aparato
alquilado). Que es *por día* está cerrado.

## 7. El contrato de Tuya

Verificado a mano contra la nube con `tools/inspeccion/probar-tuya.php`, que **sí está en git**:
las credenciales no viven dentro, se le pasan por variables de entorno para que no queden en el
historial del shell.

```
GET /v1.0/iot-03/energy/electricity/devices/statistics-trend
    ?energy_action=consume&statistics_type=hour
    &start_time=YYYYMMDDHH&end_time=YYYYMMDDHH&device_id=…
```

Trampas que ya costaron tiempo:

- **Los parámetros van ordenados alfabéticamente en la cadena a firmar.** La URL se llama en el
  orden que sea, pero la firma se calcula sobre la versión ordenada. Con un parámetro no se nota;
  con dos, Tuya responde `sign invalid` sin decir por qué.
- **Hay que forzar HTTP/1.1.** El endpoint cierra mal los streams HTTP/2 y curl aborta con
  `PROTOCOL_ERROR` antes de leer el cuerpo.
- `energy_type` es `electricity`, no `electric`. `energy_action=consume` es **obligatorio**.
- **Máximo 24 horas por llamada** en granularidad horaria.
- `add_ele` viene con `scale: 3` — el entero se divide entre 1000 para leer kW·h.
- Requiere la suscripción **Power Management** activa en el proyecto de Tuya.

Se muestrea **cada hora** porque la API devuelve cubos horarios: pedir más a menudo gasta cuota
sin traer dato nuevo. El vivo (`cur_power`, potencia instantánea en vatios) se pide **bajo
demanda**, cuando alguien tiene la vista abierta — es lo que convence de verdad al que dice que
no lo usa.

**Un aparato mudo no se nota solo.** La cuenta simplemente deja de subir, que es justo lo que el
huésped esperaría ver, y el descubrimiento llegaría el día de cobrar. Por eso
`DomoticaDispositivo::lecturaVencida()` y `DomoticaDispositivoRepository::mudos()`: el cron es
horario y con más de dos vencidas hay algo roto —enchufe desconectado, wifi de la casita caído,
cuota de Tuya agotada— y hay que enterarse antes.

## 8. Lo que falta

| Pieza | Dónde irá | Nota |
|---|---|---|
| ~~Cliente Tuya~~ | `src/Exchange/Service/Client/TuyaClient.php` | **Hecho** (08/09/2026). Vive con los otros clientes pero **no** implementa `ExchangeClientInterface`: §14.1 |
| ~~Alta desde Tuya~~ | `app:domotica:sincronizar-dispositivos` | **Hecho.** 28 aparatos de alta, capacidades derivadas |
| ~~Servicio de muestreo~~ | `app:domotica:muestrear` | **Hecho.** Dos llamadas para el parque + una por aparato que mide |
| Columna `enLinea` + hora **por dato** | `DomoticaDispositivo` | **Va antes que el muestreo.** Un solo `estadoTomadoEn` no vale: en un mismo aparato `switch_1` es de hace un minuto y `cur_power` de hace nueve días. §13.2 |
| Escala de `statistics-trend` | — | **Sin confirmar**: los cubos medidos salieron a cero. Antes de facturar un sol hay que verla con consumo real. §14.3 |
| ~~Migración~~ | `Version20260815005759` + `Version20260815020000` | **Hecho** y aplicado en producción |
| ~~Renombrar `Energia` a `Domotica`~~ | todo `src/Domotica/` | **Hecho** antes de migrar, que era la condición |
| ~~Alerta de mudos~~ | `DomoticaVigilarCommand` + `VigilanteDeDispositivos` | **Hecho**, con su cron en producción. Ver §10 |
| Accionamiento on/off | `src/Domotica/Service/` | `conmutable` ya está modelado; falta el `POST` a Tuya y quién puede pulsarlo |
| Caché del vivo | `src/Domotica/Service/` | Colapsa N espectadores en una llamada a Tuya. **Va antes que cualquier vista**: §12.3 |
| Monitor general | `util/` | Todos los aparatos de un vistazo, leyendo la **tabla**, no la nube. §12.4 |
| Alta y asignación | Panel / comando | Las capacidades las rellena el `--resync`, no un formulario (§3) |
| Vista del huésped | `pax/` | Contador, su consumo, el vivo y el extracto de `DomoticaLectura::comoLinea()`. §12.5 |
| Exposición por API | `#[ApiResource]` | El directorio ya está en `api_platform.yaml`. `security` **decidido en §12.5**: por localizador, resolviendo los aparatos en el servidor y filtrando por suscripción |

## 9. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Cambiar la tarifa del kW·h | `.env` / `.env.local` | `DOMOTICA_TARIFA_SOLES_KWH` — sólo afecta a suscripciones nuevas |
| Cambiar la cortesía diaria | `.env` / `.env.local` | `DOMOTICA_CREDITO_DIARIO_SOLES` — ídem |
| Cambiar cómo se cuentan los días de crédito | `src/Domotica/Entity/DomoticaSuscripcion.php` | `diasDeCredito()` |
| Cambiar qué se cobra de verdad | `src/Domotica/Entity/DomoticaSuscripcion.php` | `importeACobrarSoles()` |
| Añadir un motivo de lectura | `src/Domotica/Enum/DomoticaMotivoLectura.php` | + `reiniciaConsumoCliente()` y `exigeNota()` |
| Cambiar cada cuántos fallos se avisa | `config/services/services_domotica.yaml` | `domotica.fallos_para_alerta` (3) — módulo, avisa en el 3, 6, 9… |
| Cambiar el barrido por reloj | `config/services/services_domotica.yaml` | `domotica.horas_para_alerta` (3) |
| Cambiar quién recibe la alerta | `src/Domotica/Service/VigilanteDeDispositivos.php` | `destinatarios()` — hoy `Roles::OPERACIONES_SHOW` |
| Cambiar el texto del extracto del huésped | `src/Domotica/Entity/DomoticaLectura.php` | `comoLinea()` — vive en la entidad para que app y asistente digan lo mismo |
| Enchufar un aparato nuevo | Panel (pendiente) | Clave natural: `DomoticaDispositivo::$tuyaDeviceId` |
| Credenciales de Tuya | `.env.local` | `TUYA_CLIENT_ID`, `TUYA_SECRET`, `TUYA_HOST` |
| Cambiar cada cuánto se refresca el vivo | Caché del servidor, no el front | El TTL manda: el intervalo del navegador no puede bajar la cuota de Tuya. §12.3 |
| Cambiar qué ve el huésped de su consumo | `src/Domotica/Entity/DomoticaLectura.php` | `comoLinea()` — y el filtro **por suscripción**, nunca por aparato. §12.5 |
| Añadir un aparato nuevo de Tuya | — | `app:domotica:sincronizar-dispositivos`, y luego asignarle unidad y activarlo |
| Cambiar cada cuánto se muestrea | crontab de `www-data` | `app:domotica:muestrear`, minuto 5. El coste es 2 llamadas + 1 por aparato que mida |
| Confirmar la escala de la facturación | `src/Exchange/Service/Client/TuyaClient.php` | `consumoHorarioCrudo()` — hoy NO divide, a propósito. §14.3 |
| Añadir un dato al monitor general | `src/Domotica/Entity/DomoticaDispositivo.php` | Columnas de estado (`potenciaVatios`, `encendido`, `lecturaTomadaEn`): el monitor lee la tabla. §12.4 |


---

## 10. La vigilancia

Un enchufe mudo **no se nota solo**: la cuenta del huésped deja de subir, que es justo lo que él
esperaría ver si no estuviera gastando. No hay error ni queja, y el descubrimiento llegaría el día
de cobrar — cuando ya no se puede reconstruir lo que no se midió, y la discusión está perdida.

Hay dos avisos, y son distintos a propósito:

| | Quién lo dispara | Qué mira | Qué caso cubre |
|---|---|---|---|
| **Por fallos** | el muestreo, vía `VigilanteDeDispositivos::anotarFallo()` | `fallosConsecutivos` | Aparato desenchufado, wifi caído, cuota agotada |
| **Por reloj** | `app:domotica:vigilar` | `lecturaTomadaEn` | **Que se muera el muestreo entero** |

El segundo no es redundante. Si el cron deja de correr, nadie llama a `anotarFallo()`, el contador
se queda congelado en cero y el sistema se calla justo cuando más falta hace que hable: todos los
aparatos mudos a la vez y ni un aviso. El reloj es la única señal que sobrevive a que falle el
proceso que debía vigilar.

**Se avisa cada N fallos, no cada fallo.** `DomoticaDispositivo::debeAlertar()` usa un módulo: salta
en el 3, el 6, el 9… Un enchufe roto un fin de semana generaría sesenta notificaciones idénticas, y
para la número diez ya nadie las abre — el canal queda quemado para cuando importe. Y el primero
llega al fallo 3, no al 1: un muestreo perdido por un wifi con hipo se recupera solo a la hora
siguiente.

`registrarLectura()` pone el contador a cero en cuanto llega dato bueno. Sin ese cero, un aparato
recuperado arrastraría el contador y volvería a avisar al siguiente tropiezo suelto.

```cron
5  * * * *   php bin/console app:domotica:muestrear
35 * * * *   php bin/console app:domotica:vigilar
```

Desfasados media hora a propósito: juntos, el barrido leería el estado de antes de que el muestreo
lo actualice y avisaría de aparatos que están bien.

Vive en el crontab de **`www-data`** en el servidor, no en el repositorio — igual que el resto de
los cron de producción.

⚠️ **Estuvo instalado DOS VECES** (dos bloques idénticos al minuto 35, de dos ediciones distintas
del crontab), y se descubrió auditando el módulo el 08/09/2026, no por sus efectos. No los tuvo
porque no hay ni un aparato de alta: dos procesos leyendo cero aparatos dan cero avisos, y el log
sólo repetía «0 aviso(s)» el doble de veces.

Con el primer aparato dado de alta habría sido otra cosa. `mudosSinMuestrear()` **no es idempotente
entre procesos concurrentes**: lee, decide y escribe `ultimaAlertaEn` sin candado, así que dos
copias arrancando en el mismo minuto se leen mutuamente el estado de antes y avisan las dos. Toda la
economía del módulo —avisar en el fallo 3, el 6, el 9 y no en cada uno— se sostiene sobre que haya
**un** proceso.

De ahí la comprobación al instalar cualquier entrada nueva, que cuesta un segundo:

```bash
sudo -u www-data crontab -l | grep -c app:domotica
```

**Por qué no hay tabla de programación ni cola.** Se evaluó extender `src/Exchange/` con
programación horaria y se descartó: la cola de Exchange existe porque el trabajo de Beds24 es
dirigido por eventos y de volumen impredecible. Aquí es por reloj y acotado — y sobre todo, **la
cola ya la tiene Tuya**: su API devuelve cubos horarios de las últimas 24 h, así que un muestreo
perdido se recupera pidiendo el rango otra vez, y `uniq_domotica_lectura_hora` hace que reescribir
sea un no-op. Una tabla de pendientes sería una segunda copia de un estado que ya guarda el remoto.
Si algún día hacen falta programaciones de verdad, el camino es `symfony/scheduler`, no una tabla.

---

## 11. Pendiente de comprobar antes de diseñar el vivo

El consumo en tiempo real —para el panel del colaborador y la guía del huésped— **no se ha
diseñado todavía a propósito**. Hay dos incógnitas que deciden la arquitectura, y contestarlas
cuesta quince minutos en la consola de Tuya. Construir antes sería apostar.

### 1. ¿Tuya empuja por HTTP, o sólo por cola?

En la configuración de suscripción de mensajes del proyecto, mirar qué métodos de entrega ofrece
para eventos de **estado de dispositivo**.

| Si… | El diseño es… |
|---|---|
| Hay push a un endpoint HTTPS | **Un webhook en Symfony.** Se acabó: sin Pulsar, sin sidecar, sin proceso persistente. Mismo patrón que Beds24 — recibir, auditar, encolar, contestar rápido |
| Sólo hay cola (Pulsar) | Consumidor persistente. Y ojo: el cliente de Pulsar para Node es un *binding nativo* que necesita libpulsar en el servidor, no un `npm install` |

⚠️ Esta comprobación va **primero**, porque puede eliminar la mitad del trabajo.

### 2. ¿El enchufe reporta `cur_power` por su cuenta?

Ninguna arquitectura arregla esto. Muchos enchufes Tuya emiten el `switch_1` al instante pero la
potencia sólo en cambios grandes, cada varios minutos, o nunca.

Cómo verlo, sin montar nada: en el depurador de dispositivo del panel de Tuya está el registro de
DP emitidos. Enchufar una **carga real** —un calefactor, no un cargador de móvil—, dejarlo diez
minutos y anotar qué aparece y cada cuánto.

- **Si empuja la potencia con buena cadencia** → push como vía principal, y el muestreo horario se
  queda como reconciliación.
- **Si no la empuja** → para el «ahora mismo está gastando» hay que consultar igual, y el push sólo
  sirve para el on/off. Entonces la caché de lectura por demanda vuelve a ser la respuesta buena.

### ⚠️ Al 08/09/2026: la 2 y la 3 están contestadas

`statistics-trend` responde para los tres aparatos con contómetro, así que **Power Management está
activo** — la incógnita 3 deja de bloquear.

**La 2 tiene una respuesta peor de lo esperado: el enchufe NO emite `cur_power` con cadencia.** Los
sellos por DP de §13.2 lo dejan sin discusión — el último `cur_power` del aparato en línea era de
**nueve días antes**, mientras `switch_1` y `add_ele` reportaban al minuto. El DP existe y se puede
consultar, pero el aparato sólo lo manda **cuando cambia**, así que muestrearlo cada ocho segundos
devuelve sesenta veces el mismo número viejo.

Consecuencia de diseño, y es la que §12.1 ya anticipó: **el «ahora mismo» que se le enseña al
huésped no puede ser `cur_power` a secas.** O se acompaña de su `time` —«850 W, hace un momento» vs.
«sin datos recientes»— o se construye sobre el incremento del contador, que sí llega. Prometer un
vatímetro en vivo sobre un DP que se emite por excepción es prometer un número que a veces tendrá
nueve días.

La 1 sigue abierta, y §13.4 explica por qué ya no bloquea: se arranca por consulta en lote.

### 3. Cuota y suscripción

Mirar el consumo de API y **si Power Management está en periodo de prueba**. Si caduca sin avisar,
`statistics-trend` empieza a fallar y la facturación se queda sin datos — escenario que el
vigilante de §10 ya cubre, porque no distingue causas.

Volumen de referencia con ~15 aparatos: el cron horario son ~360 llamadas/día (irrelevante). El
vivo escala con **cuánto tiempo hay alguien mirando**, no con el número de aparatos: un aparato
observado 24 h son ~10.800 llamadas/día a intervalo de 8 s.

---

## 12. El monitor de consumo: `pax` y `util`

Dos audiencias, un solo camino de datos, y preguntas opuestas. El huésped pregunta **«¿cuánto
llevo yo?»** sobre un aparato; el equipo pregunta **«¿está todo vivo?»** sobre los quince. Ninguno
de los dos habla con Tuya.

### 12.1 Dos relojes que no se mezclan NUNCA

| | De dónde sale | ¿Se guarda? | ¿Se factura? |
|---|---|---|---|
| **El contador** | `add_ele`, cubo horario | Sí: una fila de `DomoticaLectura` por hora | **Sí.** Es la verdad |
| **El vivo** | `cur_power`, vatios instantáneos | No por muestra; sólo el último valor en `DomoticaDispositivo::$potenciaVatios` | **Nunca** |

⚠️ **La trampa en la que no hay que caer: integrar vatios para cobrar.** Es tentador —se tiene una
muestra cada 30 s, se multiplica por el intervalo y sale una curva preciosa— y rompe lo único que
sostiene el módulo: que el número se pueda **verificar contra la app de Tuya**. Una integral de
muestras nunca da exactamente lo que marca el contador del aparato, y en cuanto el huésped compare
las dos cifras la discusión está perdida, con razón. El vivo es para **convencer** —«ahora mismo
estás gastando 850 W»— y para explicar por qué sube la cuenta. Lo que se cobra sale siempre de la
bitácora horaria.

Corolario práctico: si `cur_power` no está disponible (§11 puede acabar diciendo que el enchufe no
lo emite), el monitor **degrada a la última hora cerrada** y sigue siendo útil. Si faltara el cubo
horario, no hay módulo.

### 12.2 De la reserva al aparato

El huésped conoce su reserva; el aparato conoce su unidad. El camino entre los dos ya está
modelado entero, y tiene dos pliegues que la vista no puede ignorar:

```
  PmsReserva
      │  eventosCalendario  (⚠️ saltando los CANCELADOS)
      ▼
  PmsEventoCalendario ───────────► DomoticaSuscripcion   (una por evento y aparato)
      │  getPmsUnidad()                    consumoCliente · tarifa · crédito, congelados
      ▼
  PmsUnidad ◄──── unidad ──── DomoticaDispositivo
                                        │
                                        ▼
                                 DomoticaLectura   (la bitácora: el extracto)
```

**Pliegue 1: una reserva puede tener varios eventos.** Es la razón por la que §2 cuelga la
suscripción del evento y no de la reserva. Para la vista significa que lo que `pax` recibe **es una
lista, no un escalar**: quien parte su estancia en dos unidades tiene dos aparatos y dos contadores,
y sumarlos en un solo número le impide entender de dónde sale nada.

**Pliegue 2: los eventos cancelados no cuentan.** Mismo criterio que ya aplica `PmsReserva` al
contar noches. Un evento cancelado con suscripción abierta enseñaría —y acabaría cobrando— una
estancia que no ocurrió.

### 12.3 El front NUNCA habla con Tuya

Es la regla de la que cuelga todo el diseño del vivo, y no es preferencia de estilo: es aritmética
de cuota.

§11 ya midió el caso de un aparato observado 24 h a intervalo de 8 s: **~10.800 llamadas/día**. Esa
cuenta se hizo pensando en un panel interno. Con lo que ahora se quiere, se multiplica por dos
lados a la vez:

- **`pax`** lo abre **el huésped**, en su móvil, tantos como haya alojados a la vez, y por el tiempo
  que le dé la gana dejar la pestaña abierta. No hay forma de acotar eso desde fuera.
- **el monitor de `util`** pregunta por **todos los aparatos de golpe**: un refresco ingenuo son
  quince llamadas, no una.

La respuesta es una sola y resuelve los dos: **una caché por aparato en el servidor**, con su TTL,
que colapsa N espectadores en **una** llamada a Tuya. La frecuencia con la que refresca el front y
la frecuencia con la que se consulta la nube dejan de ser el mismo número — que es justo lo que hace
falta, porque una la decide el usuario y la otra la decide la cuota.

Tres reglas que van con ella:

- **El endpoint del vivo devuelve dato cacheado y dice cuándo se tomó.** Enseñar la hora del dato
  es lo que permite que la vista muestre «hace 40 s» en vez de fingir que es instantáneo.
- **La pestaña oculta no pregunta.** `document.visibilityState`: un móvil en el bolsillo toda la
  noche con la pestaña abierta no puede seguir muestreando. Sin esto, el peor caso no es el huésped
  mirando, es el huésped **no** mirando.
- **El intervalo del front tiene suelo, y el servidor no se fía de él.** El TTL de la caché manda
  aunque llegue una petición por segundo.

### 12.4 `util`: el monitor general lee la TABLA, no la nube

El monitor general **no es** quince peticiones al vivo. `DomoticaDispositivo` ya carga las columnas
que necesita —`encendido`, `estadoTomadoEn`, `potenciaVatios`, `lecturaTomadaEn`,
`fallosConsecutivos`—: están ahí precisamente para que el estado de todo el parque se pinte con
**una** consulta a la base, sin tocar Tuya.

Lo que el muestreo horario deja escrito es lo que el monitor enseña. Y como cada fila lleva **cuándo
se tomó**, la vista puede apagar visualmente lo que está rancio en vez de enseñar un número viejo
como si fuera de ahora — que es la misma señal que mira `lecturaVencida()`, sólo que aquí se ve en
lugar de notificarse. El monitor y el vigilante de §10 no son dos mecanismos: son la misma verdad
mirada de dos formas, una que hay que ir a ver y otra que viene a buscarte.

El vivo, en `util`, se pide **de uno en uno y a petición**: abrir la ficha de un aparato concreto.
Quince en vivo a la vez no responde a ninguna pregunta real — para «¿está todo bien?» basta el
estado guardado.

### 12.5 `pax`: lo que ve el huésped, y sobre todo lo que NO

El acceso es el que ya usa toda la app del huésped: **el localizador en la URL**, sin sesión, igual
que `pax_get_reserva`. Eso decide la forma del endpoint:

⚠️ **El cliente no manda nunca un identificador de aparato.** Se pide por localizador y el servidor
resuelve qué aparatos le tocan recorriendo el camino de §12.2. Un endpoint que aceptara
`tuyaDeviceId` o el UUID del dispositivo dejaría que cualquiera con un localizador válido leyera el
consumo de **otra** casita — y los localizadores circulan por correo.

⚠️ **Y se filtra por SUSCRIPCIÓN, no por aparato.** Las filas de `DomoticaLectura` de un enchufe
abarcan a todos los que pasaron por esa habitación. Enseñar «las lecturas del aparato» en vez de
«las lecturas de mi suscripción» le entrega al huésped actual **el patrón de ocupación del
anterior**: a qué hora encendía la estufa, qué noches no durmió allí. Es una fuga de privacidad
disfrazada de transparencia, y el filtro que la evita es una cláusula, así que es exactamente la
clase de cosa que se omite sin querer.

Lo que sí ve, que es el §1 del documento hecho pantalla:

- **el contador del aparato**, que puede contrastar con la app de Tuya si desconfía;
- **su consumo**, que arranca de cero al entrar;
- **el vivo**, con su marca de hora;
- **el extracto**, fila a fila, con `DomoticaLectura::comoLinea()` — que vive en la entidad para que
  la app y el asistente cuenten lo mismo, y donde los reinicios aparecen **explicados** en vez de
  como un salto inexplicable (§5).

### 12.6 Qué se guarda y qué se calcula al leer

«Los cálculos van en las tablas» tiene un límite, y conviene tenerlo escrito porque la frontera no
es obvia:

| | Dónde | Por qué |
|---|---|---|
| **kW·h** (`consumoTotal`, `consumoCliente`, `incremento`) | **Columnas.** En cada `DomoticaLectura` y proyectados en `DomoticaSuscripcion` | Es lo que llegó de la nube a una hora concreta. No se puede recalcular más tarde: si no se guardó, se perdió |
| **Soles** (`importeACobrarSoles()`, `creditoAcumuladoSoles()`) | **Se calculan al leer**, en la entidad | La tarifa y el crédito **ya están congelados** en la fila (§6), así que el cálculo es determinista y da siempre lo mismo |

⚠️ **Guardar los soles en una columna sería un segundo autor sobre el dinero.** El día que se
corrija una lectura hacia atrás —un reinicio a solicitud, un tramo repetido— la columna de importe
seguiría diciendo lo de antes, y habría dos cifras discrepando sin que ninguna se pueda declarar
mala. Se guarda lo que **se observó**; se calcula lo que **se deduce**.

Y por lo mismo, **el front no recalcula dinero**: recibe los soles ya calculados. Un espejo del
cálculo en TypeScript sería una tercera copia de la regla, y la regla de `CLAUDE.md` es que ninguna
viva dos veces. (Lo de `dominio/` es para lo contrario: cálculo que **ya** vivía en TS y lo necesitan
los dos lados. Aquí nace en PHP y de PHP no tiene por qué salir.)

### 12.7 Qué de esto depende todavía de §11

Poco, y a propósito. Que Tuya empuje por webhook o haya que ir a buscarlo **sólo cambia quién
rellena la caché de §12.3**: por dentro, un webhook escribe `potenciaVatios` cuando llega el evento,
y un consultor lo escribe cuando vence el TTL. Todo lo demás —el camino de §12.2, el filtro por
suscripción, la tabla como fuente del monitor general, la frontera de §12.6— es igual en los dos
mundos.

Ésa es la razón de poner la caché en medio antes de saber la respuesta: es la costura que deja
cambiar de mecanismo sin tocar ninguna vista.

---

## 13. El parque real y el mecanismo de sincronización

Medido contra la nube el **08/09/2026** con `tools/inspeccion/listar-tuya.php`. Hasta esa mañana
el módulo entero estaba diseñado sobre un aparato de muestra y una estimación de «unos quince».

### 13.1 Lo que hay de verdad

**28 aparatos**, no quince. Y el reparto importa más que el número:

| | Cuántos | Qué significa |
|---|---|---|
| **Miden** (`add_ele`) | **3** | Son los únicos que pueden facturar: dos Aubess `labfkgzm2ikdcpdp` y un deshumidificador `hthgmqorwcz7i4v2` |
| **Sólo conmutan** (`switch_1`) | 21 | Enchufes y switches de departamento. Aquí el módulo sirve para «quedó encendido en una casita vacía», no para cobrar |
| **Ni una cosa ni la otra** | 4 | Tres **detectores de gas** y un sensor de nivel de tanque |

Esos detectores de gas son literalmente el ejemplo que `Version20260815020000` puso por escrito
para justificar que las capacidades nacieran en `false`. Estaban ahí de verdad. Un alta con las dos
casillas marcadas por defecto los habría metido en el muestreo y en las alertas cada tres horas.

⚠️ **Y el dato incómodo: 17 de los 28 estaban desconectados**, incluidos **dos de los tres que miden**. El parque no está sano hoy. Un
monitor sobre esto enseñaría sobre todo «no se sabe», así que **poner los aparatos en línea es
trabajo previo, no posterior**, a cualquier vista.

### 13.2 ⚠️ `/status` NO dice si el dato es de ahora

Es la trampa más peligrosa que apareció, porque el valor falso es **plausible**:

```
E 2do triple   →  online: false, última señal hace 58 horas
                  /status devuelve:  switch_1 = true,  cur_power = 19998  (≈ 2.000 W)
```

Ese aparato lleva casi tres días sin dar señales y la API sigue sirviendo, tan campante, la última
lectura conocida: un calefactor de 2 kW encendido. **No hay ninguna marca de frescura en la
respuesta** — el campo `t` que trae es el reloj del servidor de Tuya al contestar, no la hora del
dato.

Enseñarle eso al huésped en `pax` («ahora mismo estás gastando 2.000 W») sería peor que no enseñar
nada: es un número creíble, atribuido al presente, sobre un aparato que quizá ni está enchufado.

⚠️ **Y `update_time` de `/v1.0/devices` NO sirve para esto** — se probó y no es lo que parece. Con
el interruptor accionado a las 18:11:59 y el contador reportando a las 18:14:19, ese campo seguía
marcando **16:03:04**: describe cuándo se actualizó el *registro* del aparato, no cuándo habló. De
`/v1.0/devices` sólo es fiable **`online`**, que sí acertó con el aparato de 58 horas.

**La frescura de verdad está por DP, y la da un endpoint distinto:**

```
GET /v2.0/cloud/thing/{device_id}/shadow/properties
    → properties[]: { code, value, time }   ← `time` es de CADA dato, en milisegundos
```

Medido el 08/09/2026 sobre «E 6to Matrimonial J», con el enchufe recién encendido a mano:

| DP | Valor | `time` |
|---|---|---|
| `switch_1` | `true` | 18:11:59 — el accionamiento, tres minutos antes |
| `add_ele` | 1 | 18:14:19 — al minuto |
| `cur_power` | 0 | **30/08 14:25 — nueve días antes** |
| `cur_current` | 0 | ídem |

Tres datos del mismo aparato, en la misma respuesta, con nueve días de diferencia entre ellos. Un
`estadoTomadoEn` único para todo el dispositivo **no puede describir eso**: la hora buena depende de
qué campo se mire.

⚠️ No admite lote: `/v2.0/cloud/thing/properties?device_ids=…` responde `No space permission`. Son
tantas llamadas como aparatos, lo cual es asumible para los **3** que miden y desaconseja pedirlo
para los 28.

⚠️ Y eso destapa un hueco en la entidad: `DomoticaDispositivo::registrarEstado()` recibe el momento
de quien la llama. Si el muestreo le pasa `new DateTimeImmutable()` —lo natural— guardaría **su
propio reloj**, y el aparato de 58 horas aparecería en el monitor como recién leído. Hace falta
pasarle el `update_time` de Tuya y guardar además el `online`; es una columna y un argumento, y hay
que hacerlo **antes** de escribir el muestreo, no después.

### 13.3 Todo el parque en DOS llamadas

Los dos endpoints admiten lote, y se comprobó con los 28 de golpe:

```
GET /v1.0/devices/status?device_ids=a,b,c…    → result: { «id»: [ {code, value}, … ] }
GET /v1.0/devices?device_ids=a,b,c…           → result.devices[]: online, update_time, name, model
```

Con 28 ids en una sola llamada: `success: true`, 28 devueltos. **El estado del parque entero cuesta
dos llamadas por ciclo, no 56.** Eso cambia la economía del monitor general de §12.4 por completo.

⚠️ **Al firmar en lote, la coma NO se escapa.** `http_build_query()` convierte `device_ids=a,b` en
`a%2Cb`, y como Tuya firma la URL literal, la respuesta es `sign invalid` — el mismo mensaje mudo de
§7, por otra causa. Con un aparato no se nota, porque no hay coma. Se firma la ruta tal cual.

### 13.4 El mecanismo: hay DOS, y sólo uno estaba sin decidir

La confusión venía de tratarlo como una sola pregunta. Son dos sincronizaciones distintas, con
periodos y fuentes distintos:

| | Qué trae | Cada cuánto | Estado |
|---|---|---|---|
| **La facturación** | `statistics-trend`, cubos horarios → `DomoticaLectura` | Cada hora | **Decidido desde §7 y verificado el 08/09/2026**: responde 24 cubos para los 3 aparatos con contómetro. Power Management está activo |
| **El estado y el vivo** | `/devices/status` + `/devices` en lote (los 28, `online`) y `shadow/properties` para los 3 que miden (valor **con su hora**) → columnas de `DomoticaDispositivo` | Minutos | **Era esto lo que faltaba decidir** |

**Decisión: consulta por lote, no push.** Los motivos, ahora con números detrás:

- **Está verificado hoy y el push no.** Todo lo de arriba se midió contra la nube real; que Tuya
  ofrezca webhook HTTP sigue sin comprobarse (§11.1), y si sólo ofreciera Pulsar el coste sube a un
  proceso persistente con un binding nativo en el servidor.
- **El lote lo hace barato.** Dos llamadas por ciclo para los 28. A un ciclo de 5 minutos son ~576
  llamadas/día para el parque entero, frente a las ~10.800 que costaba **un** aparato observado a 8
  segundos. El problema de cuota que asustaba en §11 lo resuelve el lote, no el push.
- **El push no arregla lo que de verdad está roto.** 17 aparatos desconectados no empiezan a hablar
  porque cambie el transporte.
- **Y la caché de §12.3 lo deja reversible.** El push, si llega, escribe en la misma caché: por
  dentro cambia quién la rellena y nada más. Ninguna vista se entera.

Lo que **no** se hace: consultar a Tuya desde la petición del huésped. El ciclo escribe en la tabla
y en la caché; `pax` y `util` leen de ahí. Es lo que impide que la cuota dependa de cuánta gente
tenga una pestaña abierta.

### 13.5 El módulo va DENTRO del aparato, y eso cambia qué significa «encendido»

Los que miden no son enchufes intermedios: el `Aubess Smart Switch/EM` está **montado dentro del
calefactor**. El aparato y el módulo son una sola cosa.

De ahí sale una asimetría que hay que tener presente en todo lo que se construya encima:

```
  relé del módulo (switch_1)  ──►  interruptor propio de la estufa  ──►  resistencia
       lo vemos y lo mandamos          NO lo vemos ni lo mandamos          consume
```

**`switch_1` dice que el aparato tiene corriente, no que esté calentando.** Entre el relé y la
resistencia queda el mando de la propia estufa, que es un corte físico invisible para el sistema.
Se comprobó en vivo el 08/09/2026: relé cerrado a las 18:11:59, 226 V presentes, y `cur_power` sin
moverse de 0 porque el interruptor del aparato estaba bajado.

Tres consecuencias, y ninguna es cosmética:

- ⚠️ **`encendido: true` con consumo cero es un estado NORMAL, no una avería.** El vigilante de §10
  no debe tratarlo como fallo: no hay nada roto en un calefactor con corriente y el mando apagado.
  Lo que sí es sospechoso es el contrario —consumo sin relé cerrado—, que no debería poder pasar.
- ⚠️ **A `pax` no se le dice «tu calefactor está encendido».** Sería falso la mitad de las veces y
  el huésped lo desmentiría mirando el aparato, que es la peor forma de perder credibilidad en una
  pantalla cuyo único argumento es la transparencia. Lo honesto se dice sobre el **consumo**, que sí
  se mide: «no está gastando» / «está gastando 850 W».
- ⚠️ **Cuando llegue el accionamiento, apagar es fiable y encender no.** Abrir el relé corta la
  corriente y la estufa se apaga, seguro. Cerrarlo **no** garantiza calor: si el mando del aparato
  está bajado, no pasa nada. Un botón de «encender» que a veces no hace nada visible es peor que no
  tenerlo, así que tendrá que confirmarse por consumo —encender, esperar, mirar si tira— y decirlo
  cuando no ocurra.

---

## 14. Lo que se construyó el 08/09/2026

Tres piezas, y con ellas el módulo deja de ser un modelo sin datos: hay 28 aparatos en la tabla y un
ciclo que los mira.

### 14.1 `TuyaClient`, y por qué NO implementa `ExchangeClientInterface`

Vive en `src/Exchange/Service/Client/` como decía §8 —firmar HMAC y pedir un token es
infraestructura, no conocimiento del dominio— pero **no implementa el contrato de Exchange**, y eso
fue una decisión, no un olvido.

`ExchangeClientInterface::send(MappingResult)` está hecho para la maquinaria de **colas**: una tarea
encolada, su `ChannelConfig`, la auditoría del envío. Tuya es una API de **lectura movida por
reloj**, sin cola — que es exactamente lo que §10 decidió al descartar montar Domótica sobre
Exchange. Cumplir la interfaz obligaría a fabricar un `MappingResult` que nadie usa: **un contrato
cumplido de mentira estorba más que uno no cumplido**, porque el día que alguien recorra los
`app.exchange.client` se encontrará dentro algo que no es un cliente de envío.

Expone cinco consultas: listar, especificación, estado en lote, quién está en línea y propiedades
con hora. Las cuatro trampas de firma están resueltas dentro y comentadas — incluida la de la coma,
que se descubrió escribiéndolo.

### 14.2 Los dos comandos

| | Qué hace | Cuándo se corre |
|---|---|---|
| `app:domotica:sincronizar-dispositivos` | Da de alta por `tuyaDeviceId` y **refresca las capacidades preguntándoselas al aparato** | A mano, al añadir aparatos |
| `app:domotica:asignar-unidad` | Ata un aparato a su casita, o lista lo que falta por atar | A mano, tras cada alta |
| `app:domotica:muestrear` | El ciclo: estado de todos + potencia y contador de los que miden | Cron, minuto 5 |

⚠️ **La asignación a casita NO se infiere del nombre, y es deliberado.** Los nombres de Tuya llevan
ordinales —«E 6to Matrimonial J», «7mo departamento»— que se parecen muchísimo a un número de
casita. Parecerse no basta cuando el error se paga en dinero ajeno: una unidad equivocada le enseña
a un huésped el consumo de otra casa **y** le emite una factura que no es suya, las dos cosas en
silencio. Las pone una persona, de una en una, y el comando enseña lo que va a hacer antes de
hacerlo.

Es además la asignación de la que cuelga todo lo que ve el huésped: sin unidad, un aparato existe
pero es **invisible** para `pax`, porque el camino de §12.2 pasa por ahí.

Dos decisiones del alta que conviene no deshacer:

- **Las altas nacen `activo = false`.** Que un aparato exista en Tuya no significa que queramos
  mirarlo: `activo` es «queremos», `mideConsumo` es «puede» (§3). Alguien tiene que asignarle unidad
  y encenderlo.
- **La sincronización refresca capacidades pero NO pisa lo que decide una persona**: unidad, activo,
  notas y el nombre se quedan como estén. El nombre se copia sólo al dar de alta, porque después
  puede haberse cambiado aquí a propósito.

El muestreo reparte el trabajo según lo que cada aparato sabe hacer: los que sólo conmutan salen del
lote y **no gastan una llamada propia** —preguntar la hora exacta de un interruptor no compensa—;
los que miden pasan además por `shadow/properties`, que es donde está la hora de cada dato.

Primera ejecución real: **24 aparatos en 5 llamadas**, y los tres que miden salieron marcados
`SIN DATO RECIENTE` —incluido el que está en línea—, que es la respuesta correcta y la que
justifica toda la maquinaria de §13.2.

### 14.3 ⚠️ Lo que todavía NO se puede hacer: cobrar

`TuyaClient::consumoHorarioCrudo()` devuelve **el entero tal como llega y no divide nada**, a
propósito. La escala de `statistics-trend` está **sin confirmar**: todos los cubos medidos salieron
a cero porque no había consumo, así que no hay forma de saber si el valor viene en kW·h, en Wh o con
escala 3 como `add_ele`.

Dividir «por analogía con `add_ele`» sería exactamente el tipo de suposición que este documento
persigue: no daría error, daría **facturas mil veces mayores o menores** sin que nada chirriara. Se
confirma con una carga real conectada y una hora de espera, y entonces se cambia en un solo sitio.

### 14.4 El fallo que llevaba un mes escondido: sin constructor no hay id

Al persistir el primer aparato, `persist()` murió con «entity has no ID». `IdTrait` declara
`#[ORM\GeneratedValue(strategy: 'NONE')]`: Doctrine **no inventa identificadores**, los pone la
entidad en su constructor llamando a `initializeId()` — el patrón de todo el repositorio.

**Las tres entidades de Domótica no tenían constructor.** Ninguna de las tres se podía persistir,
desde agosto. No lo cazó nada: ni PHPStan, ni los tests, ni `doctrine:schema:validate` —el mapeo es
correcto, el problema aparece en tiempo de ejecución— y sobre todo **no lo cazó nadie porque nada
las había instanciado nunca**. Un módulo entero escrito, migrado y desplegado sin que una sola línea
hiciera `new` de sus entidades.

Es el argumento de `tools/README.md` en estado puro: lo que no se ejecuta contra datos reales no
está probado, por muy verde que salga todo lo demás.

### 14.5 El mapeo a casitas, y los cinco que quedan fuera

Hecho el 08/09/2026. El criterio es el ordinal del nombre de Tuya: **`N-ésimo` → `Casita N`**. Hay
siete ordinales para siete casitas y los nombres los dicen («E 6to Matrimonial J», «7mo
departamento»).

| Casita | Aparatos | ¿Mide alguno? |
|---|---|---|
| 1 | 3 | no |
| 2 | 2 | **sí** — `E 2do triple` |
| 3 | 4 | no |
| 4 | 2 | no |
| 5 | 2 | no |
| 6 | 5 | **sí** — `E 6to Matrimonial J` |
| 7 | 5 | no |

Vive en `app:domotica:cargar-mapeo-casitas`, **no en veintitrés órdenes sueltas**. La razón es que
repetirlo a mano en producción sería volver a decidirlo —otra persona, otro día, otro criterio—, y
un aparato mal atado no da error: le enseña a un huésped el consumo de otra casa y le emite una
factura ajena, en silencio las dos cosas. El cargador va por `tuyaDeviceId` y no por nombre, porque
el nombre se puede cambiar desde la app del móvil y entonces dejaría de encontrar aparatos sin
decirlo.

⚠️ **No pisa un aparato ya atado a OTRA casita: avisa y lo deja.** Puede que alguien lo moviera de
verdad, y el cargador no tiene forma de saber quién tiene razón.

⚠️ **Cinco se quedan sin casita a propósito**: `Nivel Tanque`, `Corredor`, `Lampara Cocina`,
`#sta Mónica` y `Deshumidificador`. Sus nombres no llevan ordinal, así que colocarlos sería
adivinar. Sin unidad son **invisibles para `pax`** —lo correcto mientras no se sepa de quién son— y
siguen visibles en el monitor interno. El del deshumidificador escuece: es **uno de los tres
contómetros del parque**, parado hasta que alguien diga en qué casa está.

### 14.6 ⚠️ Sólo dos casitas pueden facturar por consumo

Es la consecuencia incómoda del inventario, y conviene tenerla delante antes de prometer una tarifa
por kW·h a nadie.

De los once aparatos que parecen estufas —los que empiezan por `E`—, **sólo dos tienen contómetro**:
el de la Casita 6 y el de la Casita 2. El tercer aparato que mide es el deshumidificador, que no
calienta nada.

En las otras cinco casitas el módulo sirve para lo que sirve —ver el on/off, saber que quedó
encendida en una casa vacía—, que ya era la mitad del motivo original (§1). Pero **cobrar el
consumo ahí es imposible hoy**: hace falta cambiar el módulo por el `labfkgzm2ikdcpdp`, que es el
que mide.

### 14.7 Lo que hay que correr al desplegar

Nada de lo anterior existe en producción todavía: las tres tablas siguen vacías allí. El orden
importa, porque cada uno depende del anterior:

```bash
php bin/console doctrine:migrations:migrate --no-interaction   # potencia_tomada_en, en_linea
php bin/console app:domotica:sincronizar-dispositivos          # los 28, con sus capacidades
php bin/console app:domotica:cargar-mapeo-casitas              # los 23 a su casita
php bin/console app:domotica:asignar-unidad                    # comprobar qué quedó sin atar
```

Y la línea de cron que falta, junto a la de vigilancia que ya está:

```cron
5 * * * * /usr/bin/php /var/www/openperu.pe/bin/console app:domotica:muestrear --env=prod >> /var/www/openperu.pe/var/log/domotica_muestrear.log 2>&1
```

⚠️ Antes de añadirla, comprobar que no queda duplicada — es el fallo que ya ocurrió con la de
vigilancia (§10):

```bash
sudo -u www-data crontab -l | grep -c app:domotica
```

⚠️ **Las altas nacen `activo = false`.** Tras sincronizar hay que activarlas, o el muestreo no
mirará ninguna y el ciclo dirá que no hay nada que hacer — un verde que no significa nada.

### 14.8 Atar no es publicar: `visibleParaHuesped`

Los 23 aparatos atados incluyen detectores de gas, la lámpara de la cocina y el switch del corredor.
Están en la casita porque físicamente están ahí, y eso es correcto — pero la pantalla del cliente no
es el inventario de la casa.

**Son tres preguntas independientes, y hacen falta las tres:**

| Campo | Qué pregunta | Quién lo contesta |
|---|---|---|
| `mideConsumo` / `conmutable` | qué **puede** el aparato | el aparato, en `/specifications` |
| `activo` | si lo **queremos** muestrear | operaciones |
| `visibleParaHuesped` | si el **cliente** lo ve | quien atiende |

Nace en `false`, misma asimetría que las capacidades: uno de menos hace que alguien pregunte por qué
no lo ve; uno de más le enseña a un huésped un detector de gas o el consumo de una zona común, y eso
no se descubre preguntando. Encendido hoy para los **11 calefactores** atados a casita.

`DomoticaDispositivo::sePuedeEnseñarAlHuesped()` junta las dos condiciones —visible **y** con
unidad— en la entidad, para que `pax`, el panel y el asistente no las comprueben cada uno a su
manera, que es como acaban discrepando.

### 14.9 Qué se guarda de verdad: con contómetro y sin él

`tools/inspeccion/ver-registro-domotica.php` lo enseña escribiendo filas reales y haciendo rollback.

**Con contómetro** — una fila por hora en `domotica_lectura`:

```
  cubo         consumo_total  consumo_cliente  incremento   motivo
  ─────────────────────────────────────────────────────────────────
  2026090814   546.000        0.000            0.000        poll
  2026090815   549.100        3.100            3.100        poll
  2026090816   551.400        5.400            2.300        poll
```

Y eso es lo que lee el huésped, fila a fila, por `comoLinea()`:

```
  14:00 · contador 546.000 · tu consumo 0.000 kW·h
  15:00 · contador 549.100 · tu consumo 3.100 kW·h
  16:00 · contador 551.400 · tu consumo 5.400 kW·h

  5.400 kW·h × S/ 0.7900 = S/ 4.27 · crédito 1 día × S/ 10.00 → A COBRAR S/ 0.00
```

Las dos columnas de §4 haciendo su trabajo: el contador del aparato —contrastable contra la app de
Tuya— y lo imputado al huésped, que arranca de cero al entrar.

**⚠️ Sin contómetro NO HAY HISTORIAL. Ninguno.**

```
  filas en domotica_lectura:  0
  suscripciones:              0

  encendido        false        ← el siguiente muestreo lo pisa
  estadoTomadoEn   18:49:36
  enLinea          true
  potenciaVatios   NULL
```

Todo lo que se sabe de un aparato que sólo conmuta es **el ahora**, en su propia fila. Si estuvo
encendido seis horas, no queda constancia: el muestreo siguiente sobrescribe `encendido` y lo
anterior desaparece. `domotica_lectura` no sirve para guardarlo —sus tres columnas de consumo son
`NOT NULL` y un interruptor no consume nada que contar—, así que hoy son **nueve de los once
calefactores** de los que no se puede decir absolutamente nada del pasado.

### 14.10 `domotica_cambio_estado`: el historial que faltaba

Añadida el 08/09/2026 para tapar el agujero de arriba. Una fila por **transición**, no por ciclo:

```
  nombre              encendido   ocurrido_en           hora_exacta
  ───────────────────────────────────────────────────────────────────
  E 1ro Adelante      0           2026-09-08 19:28:40   1
  E 5to Habitación    1           2026-09-08 19:00:07   1
  Lampara Cocina      1           2026-09-08 18:58:47   1
```

**Sólo se escribe cuando cambia.** Guardar cada ciclo daría 288 filas diarias por aparato diciendo
lo mismo, y la pregunta que se le hace a esta tabla —«¿cuánto llevaba encendida?»— se contesta con
dos filas, no con doscientas. `DomoticaCambioEstadoRepository::minutosEncendido()` la responde, y
tiene en cuenta el caso que importa: un aparato **encendido antes** de la ventana no tiene fila
dentro de ella, así que se mira con qué estado entró — sin eso, una estufa encendida desde ayer
contaría cero.

**Cómo sale la hora exacta sin arruinar el coste.** El lote de estado no trae marcas de tiempo, así
que se usa para **detectar** el cambio y sólo entonces se le pregunta al aparato su hora
(`shadow/properties`). Los cambios son raros —una estufa se acciona un puñado de veces al día—, así
que el ciclo sigue costando dos llamadas casi siempre. Pedírsela a los 24 en cada ciclo por si acaso
multiplicaría el gasto por doce para el mismo dato.

⚠️ Cuando el aparato no da su hora —desconectado, o el DP no viene— se guarda la del muestreo y la
fila queda marcada `horaExacta = false`; `comoLinea()` lo enseña con un «≈». Con ciclo de cinco
minutos el error máximo son cinco minutos, que no cambia ninguna decisión. Lo que sí la cambiaría es
creer que es exacta cuando no lo es.

⚠️ **Esto NO se factura.** Horas encendido × vatios nominales es la misma trampa que integrar
`cur_power` (§12.1): da una cifra plausible que no cuadra con ningún contador, y en cuanto el
huésped la discuta no hay con qué defenderla. Sirve para tres cosas — decirle al huésped «lleva 3 h
encendida», ver que quedó ardiendo en una casa vacía **y desde cuándo**, y decidir en qué casitas
compensa comprar contómetro en vez de comprar por corazonada.

### 14.11 ⚠️ El huso horario: cinco horas que daban «reciente» para siempre

Lo cazó `tools/inspeccion/ver-registro-domotica.php` al decir «ningún cambio» sobre una tabla que
tenía dos filas.

`new DateTimeImmutable('@…')` devuelve **siempre UTC**, y Doctrine guarda la hora de pared tal cual
venga. Las horas que llegaban de Tuya entraban por ahí; las del muestreo eran `new
DateTimeImmutable()`, o sea `America/Lima`. **La misma columna acabó con dos husos y cinco horas de
desfase.**

Y el fallo no era simétrico, que es lo que lo hacía peligroso: como Lima va cinco horas por detrás,
una hora de Tuya quedaba en el **futuro** respecto al reloj local, así que
`potenciaEsReciente()` la daba por fresca **siempre**. Es decir: el módulo que existe para no
enseñar un número viejo como si fuera de ahora estaba haciendo exactamente eso, y en el único sitio
donde se comprueba.

**El estándar del proyecto es la HORA DE PARED** (decidido el 08/09/2026), y la hora de pared de un
aparato es **la del establecimiento donde cuelga** — no la del servidor que lo consulta.

Por eso la conversión **no vive en `TuyaClient`**: un cliente HTTP no puede saber de
establecimientos sin cargar medio dominio dentro. El cliente devuelve el instante en UTC,
honestamente etiquetado, y quien normaliza es `DomoticaDispositivo`, que sí sabe llegar al suyo por
`unidad → establecimiento → timezone`. Lo hacen sus propios `registrar*()`, para que no dependa de
que nadie se acuerde.

⚠️ **Y `date_default_timezone_get()` NO vale como respuesta.** Es Lima por configuración de PHP, no
porque nadie lo haya decidido para ese aparato: hoy coinciden —un solo establecimiento, en
`America/Lima`— y por eso el atajo no duele. El día que haya uno en otro huso, todo lo que se le
enseñe al huésped y todo lo que se le cobre estaría movido, y el código de arriba seguiría
pareciendo correcto. `PmsEstablecimiento::$timezone` ya existía; `PmsGuiaAcceso` lleva anotada esta
misma deuda desde antes —«*existe pero todavía no participa*»—, y domótica es el primer sitio donde
sí participa.

Queda como respaldo sólo para los aparatos **sin unidad** —el corredor, el tanque—, que no cuelgan
de ningún establecimiento. No es una preferencia de domótica: es
lo que guardan las otras doscientas tablas, y una sola columna en otro huso obliga a que cada
consulta que la cruce sepa compensarla. Perú no cambia de hora, así que el punto débil habitual de
esta elección —la hora que se repite y la que no existe— aquí no muerde.

⚠️ El precio de guardar hora de pared es que **el instante no se puede recuperar sin saber el
huso**: Doctrine devuelve las fechas con el huso por defecto pegado, sea cual sea el que se escribió.
Por eso `potenciaEsReciente()` compara con un «ahora» pedido en la zona del aparato y no en la del
servidor — comparar una hora de pared de Nairobi contra el reloj de Lima da una antigüedad
inventada.

Traducido a código, y vale para cualquier integración futura:

| Lo que llega | Qué hacer |
|---|---|
| Epoch (segundos o ms) | Dos formas válidas, y la elección depende de **quién normaliza**: `'@…'` devuelve el instante en UTC —honesto, para que lo convierta después quien conozca el establecimiento, como hace `TuyaClient`— y `new DateTimeImmutable()->setTimestamp($s)` lo deja ya en el huso de la app, que vale cuando no hay establecimiento de por medio. Lo que **nunca** vale es guardar un `'@…'` sin convertir |
| Cadena CON huso (`…Z`, `+00:00`) | Parsear y `->setTimezone()` al de la app |
| Cadena SIN huso | Averiguar en qué huso la manda el proveedor antes de tocar nada: parsearla a secas la etiqueta como local, y si venía en UTC quedan dígitos ajenos con etiqueta propia |
