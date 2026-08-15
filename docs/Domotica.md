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

**Estado (14/08/2026):** están las entidades, los repositorios, la vigilancia y la configuración.
**No están todavía** la migración, el cliente de Tuya, el muestreo ni las vistas. Ver §7.

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

| Campo | Qué habilita |
|---|---|
| `mideConsumo` | Muestreo, suscripción, bitácora y facturación |
| `conmutable` | Accionar desde el sistema — **pendiente**; hoy el estado sólo se lee |

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

Verificado a mano contra la nube (`var/probar-tuya.php`, fuera de git por las credenciales):

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
| Migración | `migrations/` | `make:migration` — todavía no generada |
| Cliente Tuya | `src/Exchange/Service/Client/TuyaExchangeClient.php` | Junto a Beds24 y WhatsApp Meta, contra `ExchangeClientInterface` |
| Servicio de muestreo | `src/Domotica/Service/` | Toma la última lectura y le suma la diferencia de contadores |
| Cron horario | `src/Domotica/Command/` | Recorre `DomoticaSuscripcionRepository::vigentes()` |
| ~~Alerta de mudos~~ | `DomoticaVigilarCommand` + `VigilanteDeDispositivos` | **Hecho.** Ver abajo |
| Accionamiento on/off | `src/Domotica/Service/` | `conmutable` ya está modelado; falta el `POST` a Tuya y quién puede pulsarlo |
| Renombrar el módulo a `Domotica` | todo `src/Domotica/` | **Decidir antes de la migración**: después cuesta renombrar tablas en producción |
| Panel de dispositivos | `util/` | Alta, asignación a unidad, reinicio a solicitud |
| Vista del huésped | `pax/` | El extracto de `DomoticaLectura::comoLinea()` |
| Exposición por API | `#[ApiResource]` | El directorio ya está en `api_platform.yaml`; **falta decidir `security`** antes de exponer nada: el consumo de un huésped es suyo |

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
5  * * * *   php bin/console app:domotica:muestrear    # pendiente
35 * * * *   php bin/console app:domotica:vigilar
```

Desfasados media hora a propósito: juntos, el barrido leería el estado de antes de que el muestreo
lo actualice y avisaría de aparatos que están bien.

**Por qué no hay tabla de programación ni cola.** Se evaluó extender `src/Exchange/` con
programación horaria y se descartó: la cola de Exchange existe porque el trabajo de Beds24 es
dirigido por eventos y de volumen impredecible. Aquí es por reloj y acotado — y sobre todo, **la
cola ya la tiene Tuya**: su API devuelve cubos horarios de las últimas 24 h, así que un muestreo
perdido se recupera pidiendo el rango otra vez, y `uniq_domotica_lectura_hora` hace que reescribir
sea un no-op. Una tabla de pendientes sería una segunda copia de un estado que ya guarda el remoto.
Si algún día hacen falta programaciones de verdad, el camino es `symfony/scheduler`, no una tabla.
