# Plan — Domótica desde la red local: una Raspberry que empuja a producción

Cómo se sustituye la nube de Tuya por un servicio en la red de las casitas que lee los aparatos
**en local** y **empuja** sus lecturas al servidor de producción. Y, ya que la Raspberry está ahí,
cómo se aprovecha para ordenar la red (DHCP, firewall, VPN).

**Estado (13/10/2026): plan, nada construido.** Decidido en conversación con el dueño del producto
el 13/10/2026. El muestreo contra la nube está pausado desde ese día (ver el aviso de estado al
principio de `docs/Domotica.md`).

**Alcance:** el hardware, la red, el contrato entre el servicio local y producción, y las fases. El
modelo de datos (aparatos, lecturas, suscripciones, cobro) **no cambia**: es el de
`docs/Domotica.md`, y ahí sigue documentado.

---

## Índice

1. [Por qué](#1-por-qué)
2. [La forma](#2-la-forma)
3. [Hardware](#3-hardware)
4. [La red: doble NAT, con la Raspberry en la DMZ](#4-la-red-doble-nat-con-la-raspberry-en-la-dmz)
5. [El contrato del push](#5-el-contrato-del-push)
6. [El latido, y por qué sustituye al vigilante](#6-el-latido-y-por-qué-sustituye-al-vigilante)
7. [Fases](#7-fases)
8. [Alternativa descartada por ahora: Omada en mesh](#8-alternativa-descartada-por-ahora-omada-en-mesh)
9. [Preguntas abiertas](#9-preguntas-abiertas)
10. [Dónde tocar para cambiar X](#10-dónde-tocar-para-cambiar-x)

---

## 1. Por qué

- **La suscripción IoT Core de Tuya venció** (error visible desde el 11/10/2026) y no se renueva a
  propósito. Depender de una suscripción de nube para leer enchufes que están en nuestra propia red
  es pagar por un rodeo.
- **El corte real fue antes y nadie se enteró**: la última lectura de los contómetros es del
  12/09/2026 y el vigilante avisó una sola vez (14/09) y calló un mes. Un diseño por **push** hace
  del silencio la señal (§6), que es justo lo que faltó.
- **La red de las casitas ya es inestable** con muchos equipos: el módem del operador gestiona mal
  la LAN. La misma Raspberry puede hacerse cargo de eso (§4).

## 2. La forma

```
   RED DE LAS CASITAS                                        PRODUCCIÓN (openperu.pe)
 ┌───────────────────────────────────────────┐
 │ enchufes Tuya ──(protocolo local)──┐      │
 │                                     ▼      │   HTTPS, sólo SALIENTE
 │                    Raspberry: servicio local ──────────────────────▶  POST /…/domotica/push
 │                    (easytuya + cola en disco)│   lote firmado (HMAC)   │
 │                                              │                         ▼
 └───────────────────────────────────────────┘                  recibe → audita → encola → 202
                                                                          │
                                                                          ▼
                                                            Messenger: persiste lecturas en
                                                            DomoticaDispositivo / lecturas
```

La regla de `CLAUDE.md` («Node y PHP: cada lógica en un solo sitio») decide el reparto:

- **El servicio local va TONTO.** Lee los aparatos y manda lo que leyó, crudo, con su hora. No sabe
  qué es una casita, una suscripción ni una tarifa. Da igual que sea Python o Node.
- **PHP persiste y calcula.** Validar, deduplicar, convertir a consumo y cobrar sigue en
  `src/Domotica/`. El servicio local **nunca escribe en la base**.
- **El push sólo necesita conexiones salientes** desde la red local, y producción nunca le habla
  a la Raspberry. ⚠️ Eso es del push: **la red sí abre la Pi a internet** desde que se pone en la
  DMZ del módem (fase 5, §4). La seguridad de esa puerta la pone el firewall de la Pi, no el
  diseño del push — ver §4, «La Pi en la DMZ queda EXPUESTA».

## 3. Hardware

**Raspberry Pi 5 (4 GB), obligatoria.** No la Pi 4: si algún día corre el controlador de Omada
(§8), éste trae MongoDB, y MongoDB 5.0+ exige ARMv8.2, que la Pi 4 (Cortex-A72, ARMv8.0) no tiene:
se cae al arrancar con «illegal instruction». La Pi 5 (Cortex-A76) sí lo tiene. Tampoco la Zero 2 W:
no tiene puerto de red con cable y no sirve de router.

**Sistema: Ubuntu Server 24.04 LTS** (imagen oficial de Canonical para Pi 5) o Raspberry Pi OS de
64 bits. Ubuntu es lo que documenta TP-Link para su controlador.

| Pieza | Por qué | Aprox. (lista, US$; en Perú más) |
|---|---|---|
| Pi 5 4 GB | Sobra para ~30 aparatos + DHCP + VPN (+ controlador Omada en Docker) | ~60 |
| **SSD por USB** (no SD) | Lo que muere en una Pi es la tarjeta SD por desgaste de escritura | ~25–40 |
| **Fuente oficial** | Con un cargador cualquiera la Pi sufre bajo voltaje y corrompe datos | ~12 |
| Caja con ventilador | No ponerla cerrada junto al módem | ~10–15 |
| Adaptador USB 3.0 → Gigabit | Segunda interfaz con cable, para hacer de router (§4) | ~15 |
| Protector de picos | Tormentas de la temporada de lluvias | — |

**Sin SAI.** La energía en Cusco es buena (un corte al año); con SSD un apagado brusco ocasional
rara vez daña nada. Se compensa escribiendo poco: logs en memoria (`log2ram`) y la cola de
lecturas vaciándose a producción cada pocos minutos.

**Una copia del disco configurado**, a mano. Si algo falla, se cambia en minutos.

## 4. La red: doble NAT, con la Raspberry en la DMZ

El módem del operador (ex Movistar) **no se puede quitar**: es el único equipo aprobado para la
línea. Y como módem no es malo; lo que gestiona mal es la LAN cuando hay muchos equipos. Así que se
queda haciendo sólo de módem:

```
Internet ─ módem del operador ─[cable]─ Raspberry ─[cable]─ switch / APs ─ casitas, huéspedes, enchufes
           192.168.1.x                  WAN: 192.168.1.<fija>
           DHCP encendido (sólo         LAN: 192.168.10.x
           le da IP a la Pi)            dnsmasq, nftables, WireGuard, servicio de domótica
           DMZ → la Pi
           Wi-Fi APAGADO
```

- **Modo puente (bridge) descartado**: en la fibra del operador pide parámetros (PPPoE, VLAN) que
  no se dan. El doble NAT no estorba a nada de lo que se usa aquí — navegar, WhatsApp, enchufes,
  VPN con la Pi en la DMZ, el push a producción.
- **IP fija para la Raspberry en el módem** (reserva DHCP o estática en la Pi): la DMZ apunta a una
  dirección, y si la Pi cambia de IP la DMZ apunta a nada.
- **Subred DISTINTA detrás de la Pi** (`192.168.10.x` frente a `192.168.1.x` del módem). Con la
  misma, el tráfico no sabe por dónde salir.
- **IP fija (reserva) para cada enchufe** en el `dnsmasq` de la Pi: el control local de Tuya habla
  con el aparato por su IP.
- **Los enchufes en una red aislada** (VLAN o subred aparte) que sólo vea la Raspberry, no los
  equipos de los huéspedes. Los aparatos IoT baratos son lo menos seguro de una red.

### 🔒 La Pi en la DMZ queda EXPUESTA: firewall que niega todo lo que entra

La DMZ reenvía a la Pi **todo** lo que llega de internet: SSH, `dnsmasq`, el servicio de domótica,
el controlador de Omada si llega a instalarse. Sin firewall, la fase 5 deja la Pi entera a la
vista. Lo vio la revisión de Fable (13/10/2026): el plan decía «no se abre ningún puerto» y a la
vez metía la Pi en la DMZ.

La regla, en `nftables`, **antes** de poner la Pi en la DMZ:

- **Por la interfaz del módem (WAN): se niega todo lo entrante**, salvo el puerto UDP de
  WireGuard (fase 6) y las respuestas a conexiones que la Pi abrió (`ct state established,related`).
- **SSH sólo desde la LAN o por la VPN**, nunca desde la WAN, y con clave, sin contraseña.
- **`dnsmasq` sólo escucha en las interfaces de dentro**, nunca en la WAN.
- **La red aislada de los enchufes** puede hablar con la Pi y con nada más; los huéspedes no la
  ven.
- Comprobarlo **desde fuera** tras activar la DMZ (un escaneo de puertos a la IP pública desde
  otra red): lo único que debe responder es WireGuard.

### 🛟 El plan B, y por qué el DHCP del módem se deja encendido

Si la Raspberry falla, **se pasa el cable del switch de la Pi al módem y se vuelve a encender su
Wi-Fi**. Dos minutos y hay internet en las casitas —con la inestabilidad de siempre— mientras se
arregla la Pi. Funciona sólo porque el DHCP del módem **nunca se apagó**: si se apaga, el plan B
pasa a ser reconfigurar un módem a ciegas con huéspedes esperando.

⚠️ **El riesgo de juntar router y domótica en la Pi** es que su muerte ya no tumba sólo la domótica:
tumba internet. Por eso el orden de las fases (§7) valida primero la domótica con la Pi como un
equipo más, y sólo después la pone de router.

## 5. El contrato del push

**Una sola puerta**, en producción: `POST /platform/domotica/push` (nombre provisional). Mismo
patrón que el webhook de Beds24: **recibir, auditar, encolar, contestar rápido**. Nada se procesa
dentro de la petición.

### Cabeceras

| Cabecera | Qué |
|---|---|
| `X-Domotica-Tiempo` | Unix timestamp del envío |
| `X-Domotica-Firma` | `hex(HMAC-SHA256(secreto, tiempo + "." + cuerpo))` |

Se rechaza con 401 si la firma no cuadra o si `tiempo` se aparta más de **5 minutos** del reloj del
servidor. La ventana limita reenviar un lote capturado; dentro de esos 5 minutos lo que impide
duplicar es la idempotencia por `loteId` (más abajo).

- **La firma se compara con `hash_equals()`**, nunca con `===`: comparar texto corta en el primer
  carácter distinto, y el tiempo de respuesta filtra cuánto de la firma era correcto.
- ⚠️ **La Pi no tiene reloj propio** (sin RTC): tras un corte arranca con la hora mal hasta que NTP
  sincroniza, y mientras tanto **todos sus lotes dan 401**. No se pierde nada —la cola en disco
  los guarda y se reenvían— pero el servicio local debe **esperar a tener hora sincronizada**
  (`timedatectl` → `System clock synchronized: yes`) antes de vaciar la cola, y registrar los 401
  como lo que son. El secreto es una variable de entorno
**obligatoria**: `%env(resolve:DOMOTICA_PUSH_SECRETO)%` **y** en el `.env.local` del servidor el
mismo día del despliegue — ver `CLAUDE.md`, «Una variable de entorno NUEVA no basta con ponerla en
`.env`».

### Cuerpo

```json
{
  "loteId": "0192…-uuid-v7",
  "emisor": "casitas-cusco",
  "tipo": "lecturas",
  "eventos": [
    {
      "tuyaDeviceId": "eb41e3dce849ba7c2btoss",
      "tomadoEn": "2026-10-20T14:05:02-05:00",
      "dps": { "1": true, "17": 1234, "19": 850 }
    }
  ]
}
```

- **`tuyaDeviceId`** es el mismo identificador que ya guarda `DomoticaDispositivo`: el modelo no
  cambia, sólo de dónde llega el dato. Un id que no exista en la tabla se cuenta como descartado y
  se registra — **no se crea el aparato solo**.
- **`tomadoEn` con su desfase explícito**, y es la hora de la LECTURA, no la del envío: una lectura
  que llega tarde por un corte no puede contar como de ahora. Al guardar se pasa a hora de pared
  del establecimiento, que es el estándar (`docs/ZonasHorarias.md` §1 y §5).
- **`dps` crudos**, sin interpretar. Qué DP es el contador (`add_ele`), cuál la potencia
  (`cur_power`) y cuál el interruptor lo decide PHP, como hoy (`docs/Domotica.md` §13.2).
- ⚠️ **El consumo se toma del CONTADOR, no de la potencia.** Está medido: el enchufe sólo manda
  `cur_power` cuando cambia, y llegó a tener nueve días (`docs/Domotica.md` §11.2).

### Respuesta

`202 {"loteId": "…", "aceptados": 12, "descartados": 0, "duplicado": false}`

**Idempotencia por `loteId`**: un lote repetido contesta `202` con `"duplicado": true` y no hace
nada. Es lo que permite al servicio local **reintentar sin miedo** tras un corte de internet: si no
recibió la respuesta, reenvía, y producción no duplica consumo.

### El servicio local

- Lee los aparatos (cadencia según lo que permita easytuya, §9) y **apila en disco** lo leído.
- Vacía la cola cada **pocos minutos** en lotes. Si producción no contesta, reintenta con espera
  creciente y **no pierde nada**: la cola sobrevive a un reinicio.
- Es un servicio de `systemd` que arranca solo.

## 6. El latido, y por qué sustituye al vigilante

Cada **5 minutos** el servicio local manda un lote `"tipo": "latido"` (sin eventos, o con el
recuento de aparatos que ve). Producción apunta la hora del último.

**Si pasan 15 minutos sin latido, se avisa** por WhatsApp a soporte técnico
(`AvisoAlEquipo` con `Roles::TECH_SUPPORT` y la plantilla `aviso_tecnico_generico_interno`, como el
tipo de cambio — ver `docs/PmsBeds24ReservasSync.md` §12.18), con el push de respaldo. Un aviso al
empezar la caída, no uno cada cinco minutos.

⚠️ **El silencio no se detecta solo: hace falta una tarea en producción que mire el reloj.** Que
no llegue nada no dispara ningún código. Va un comando por cron cada 5 minutos —con el envoltorio
`tools/cron/con-candado` como todas las tareas, ver `CLAUDE.md` «Despliegue»— que compara la hora
del último latido con ahora y avisa una vez por caída.

**Por qué es mejor que lo de hoy**: con el muestreo por consulta, un fallo se reconocía contando
errores, y el vigilante dejó de contar (calló un mes con el parque entero mudo). Con push, el
silencio **es** la avería, y cubre todas a la vez: Raspberry muerta, internet de las casitas caído,
servicio local colgado, secreto cambiado.

Y aparte, por aparato: un enchufe del que no llegan eventos en N horas mientras el latido sí llega
es un enchufe desenchufado o fuera del Wi-Fi. Eso es lo que `VigilanteDeDispositivos` intentaba —
**revisar por qué calló antes de reutilizarlo** (aviso de estado en `docs/Domotica.md`).

## 7. Fases

El orden va de menos a más riesgo: cada fase se puede quedar donde está si la siguiente se tuerce.

| Fase | Qué | Cuando está hecha… | Si falla, se cae… |
|---|---|---|---|
| **0. Comprobaciones** | Las de §9: local keys, qué hace easytuya, inventario con IP | Se sabe que el control local funciona con NUESTROS aparatos | nada |
| **1. Pi como equipo más** | Pi con SSD en la LAN del módem, IP fija, easytuya leyendo los aparatos | La Pi lee todo el parque en local | nada |
| **2. Puerta en producción** | Endpoint, firma, tabla de lotes (idempotencia), auditoría, latido y su aviso | Un `curl` firmado entra; uno sin firma no; un lote repetido no duplica | nada (no hay emisor aún) |
| **3. Servicio local** | Cola en disco, envío por lotes, reintentos, latido | Las lecturas llegan a `DomoticaDispositivo` como antes llegaban de la nube | la domótica |
| **4. Retirar la nube** | Quitar `app:domotica:muestrear` del crontab (ya comentado), `TuyaClient` a archivo | No queda ninguna llamada a la nube de Tuya | — |
| **5. Pi como router** | Doble NAT, DMZ, `dnsmasq`, red aislada para enchufes, APs detrás | La LAN la reparte la Pi; el módem sólo ve a la Pi | **internet de las casitas** → plan B (§4) |
| **6. VPN** | WireGuard en la Pi | Se entra a la red de las casitas desde fuera | la VPN |

La fase 5 va **después** de que la domótica lleve unas semanas estable: así, si algo falla, se sabe
cuál de las dos cosas fue.

## 8. Alternativa descartada por ahora: Omada en mesh

Una red de puntos de acceso **TP-Link Omada en mesh** con su controlador resolvería mejor la
cobertura y la gestión de muchos equipos que cualquier AP suelto. **Descartada por coste**, no por
técnica.

No compite con este plan, lo complementa: los APs Omada irían **detrás** de la Raspberry, en el
lugar de «switch / APs» del diagrama de §4. Si un día se compran, nada de lo de arriba cambia.

### Dónde correría el controlador (verificado el 13/10/2026)

**TP-Link NO publica el controlador para ARM.** Sólo hay `linux_x64` (`.deb` y `.tar.gz`) y su guía
de instalación es para Ubuntu en PC. En una Pi hay dos caminos, los dos de la comunidad:

| Camino | Veredicto |
|---|---|
| El `.deb` de PC con el Java parcheado a mano | ❌ **No.** Funciona a ratos y se rompe al actualizar el sistema (hay quien tuvo que reinstalar dependencias tras un update de Debian). Sin vuelta atrás limpia |
| **Docker, imagen `mbentley/omada-controller`** (publica arm64) | ✅ **Éste, en la Pi 5.** Java y MongoDB van dentro y en versiones que casan; actualizar la Pi no los toca; volver a la versión anterior es cambiar la etiqueta; y queda aislado de la domótica, `dnsmasq` y WireGuard |

**El OC200** (el controlador de TP-Link en aparato) sigue siendo la salida oficial y más barata que
la Pi — pero la Pi hace falta igual por la domótica, y el OC200 no la sustituye. Se compra sólo si
el controlador en Docker diera problemas.

⚠️ **Que muera el controlador no tumba el wifi**: los APs Omada siguen dando servicio sin él. Lo que
se pierde mientras tanto es configurar, ver estadísticas y montar el mesh.

## 9. Preguntas abiertas

1. **Las local keys.** El control local de Tuya necesita la clave local de cada aparato, y
   normalmente se sacan una vez de la API de la nube. **Con la suscripción vencida, ¿se pueden
   sacar todavía? ¿Están guardadas en algún sitio?** Sin ellas no hay control local: es lo
   PRIMERO que hay que comprobar.
2. **Qué hace easytuya exactamente**: ¿el aparato le avisa al cambiar, o hay que preguntarle cada
   cierto tiempo? Decide la cadencia de lectura del servicio local.
3. **Si los enchufes siguen funcionando aislados de internet** una vez en la red aislada (§4):
   algunos firmwares se comportan raro sin su nube. Probar con uno antes de aislar todos.
4. **Qué pasa con la app de Tuya en el móvil** del personal cuando la nube deje de estar en el
   circuito, si alguien la usa para encender o apagar.

## 10. Dónde tocar para cambiar X

Casi todo está **por crear**; la tabla dice dónde irá, para que se cree ahí.

| Necesidad | Archivo | Símbolo |
|---|---|---|
| El endpoint que recibe el push | `src/Domotica/Controller/` *(por crear)* | — |
| Verificar la firma y la ventana de 5 min | `src/Domotica/` *(por crear)* | — |
| El secreto compartido | `.env.local` del servidor **y** de la Pi | `DOMOTICA_PUSH_SECRETO` |
| Leer el cuerpo del lote | `src/Domotica/Dto/` *(por crear)* | un `fromArray()` con `App\Dto\Lee` (`docs/TiposDeFrontera.md`) |
| Qué DP es contador, potencia o interruptor | lo que hoy usa el muestreo | `docs/Domotica.md` §13.2 |
| Cuándo se avisa por falta de latido | `src/Domotica/Command/` *(por crear)* + su línea en `tools/cron/www-data.crontab` con `con-candado` | 15 min; aviso con `AvisoAlEquipoService`, rol `TECH_SUPPORT`, plantilla `aviso_tecnico_generico_interno` |
| Qué entra a la Pi desde internet | `nftables` en la Pi, **no el repo** | default-deny en la WAN salvo WireGuard (§4) |
| El crontab del muestreo contra la nube | `tools/cron/www-data.crontab` | línea `app:domotica:muestrear`, comentada desde el 13/10/2026 |
| La red de las casitas (DHCP, reservas, firewall) | la Raspberry, **no el repo** | `dnsmasq`, `nftables`, WireGuard |
