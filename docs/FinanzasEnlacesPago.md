# Finanzas — Enlaces de pago por pasarela (Culqi operativa · Izipay PARADA)

Módulo `src/Finanzas/`. Emite enlaces de cobro que se envían al cliente, los cobra por
pasarela y devuelve el dinero al módulo que lo generó (hoy el PMS; mañana tours).

Nace **transversal** a propósito: es el arranque del sistema de administración/contabilidad,
no una función del PMS. Por eso no conoce ninguna entidad de negocio y todo lo que sabe del
documento que cobra se lo cuenta un *resolver* que vive en el módulo dueño.

**Estado:** operativo end-to-end para reservas del PMS **por Culqi**. El origen `tour_reserva`
está declarado en el enum pero **sin resolver**: emitir un cobro con ese origen falla a
propósito.

## 🅿️ IZIPAY ESTÁ PARADA (stalled) — 28/08/2026

**No se toca el camino de Izipay hasta que esté implementada de verdad.** Su conector se
queda entero y compilando (§11: borrarlo obligaría a rehacerlo), pero **no se le arreglan
huecos, no se le añaden guardas y no se refactoriza**. Cualquier hallazgo sobre Izipay se
apunta aquí abajo y se queda quieto: pulir un camino que nadie ejecuta es pagar hoy por una
decisión que no se ha tomado.

`FINANZAS_PASARELA_POR_DEFECTO=culqi`, y Izipay exige S/200 000 de venta acumulada para
habilitar enlaces — el muro que la dejó parada.

**Congelado hasta que se habilite:**

| Hueco | Qué pasa | Por qué no urge |
|---|---|---|
| `anular()` no revoca el `formToken` en Lyra | Un token emitido antes del anular sigue vivo unos minutos: el cliente que ya tenía la página abierta puede completar el pago | No hay tokens de Izipay porque no se emite por Izipay |
| `confirmarPago()` no comprueba `ANULADO`, sólo `PAGADO` | Un IPN sobre un enlace anulado lo marcaría `PAGADO` y crearía el `PmsPagoFinanciero` | Sólo es alcanzable por el IPN de Izipay: en Culqi el cargo lo crea nuestro servidor y `estaVigente()` ya lo bloquea (§5) |

⚠️ Ese segundo hueco, **si algún día se cierra, no se cierra rechazando el pago**. Si el
dinero se movió de verdad en la pasarela, el cliente tiene el cargo en su tarjeta lo llamemos
como lo llamemos: registrarlo es peor de leer y mucho mejor que esconderlo. Lo que faltaría
es dejar rastro de la contradicción (un enlace `anulado` que acabó cobrado), no negarla.

---

## Índice

1. [Piezas y dónde vive cada una](#1-piezas-y-dónde-vive-cada-una)
2. [La relación soft: por qué no hay foreign key](#2-la-relación-soft-por-qué-no-hay-foreign-key)
3. [Flujo completo de un cobro](#3-flujo-completo-de-un-cobro)
4. [El protocolo de Izipay y sus tres trampas](#4-el-protocolo-de-izipay-y-sus-tres-trampas)
5. [Estados del enlace y sus asimetrías](#5-estados-del-enlace-y-sus-asimetrías)
6. [Importes: neto, recargo y total](#6-importes-neto-recargo-y-total)
7. [Espejos PHP ↔ TypeScript](#7-espejos-php--typescript)
8. [Configuración y credenciales](#8-configuración-y-credenciales)
9. [El módulo en `util`: Cobros y Caja](#9-el-módulo-en-util-cobros-y-caja)
10. [Añadir un módulo nuevo que cobre](#10-añadir-un-módulo-nuevo-que-cobre)
11. [Dos pasarelas en paralelo: Izipay y Culqi](#11-dos-pasarelas-en-paralelo-izipay-y-culqi)
11 bis. [Enlaces de PREPAGO](#11-bis-enlaces-de-prepago)
11 quater. [Devoluciones: deshacer un cobro que ya pasó](#11-quater-devoluciones-deshacer-un-cobro-que-ya-paso)
12. [Despliegue: por qué no basta con `git pull`](#12-despliegue-por-qué-no-basta-con-git-pull)
13. [Dónde tocar para cambiar X](#13-dónde-tocar-para-cambiar-x)
14. [El catálogo de medios de cobro (`FinMedioCobro`)](#14-el-catálogo-de-medios-de-cobro-finmediocobro)
    - [⚠️ Gotcha: `opciones()` de un enum devuelve CASOS](#144--gotcha-opciones-de-un-enum-devuelve-casos-no-value)
    - [⚠️ `#[AutoTranslate]` necesita DOS cosas más](#145--una-entidad-con-autotranslate-necesita-dos-cosas-más)

---

## 1. Piezas y dónde vive cada una

```
src/Finanzas/                          ← no importa NADA de Pms/, Travel/, Cotizacion/
├── Contract/
│   ├── FinOrigenCobroResolverInterface        puente 1: "¿cuánto debe?" → sirve para COBRAR
│   ├── FinMovimientoProviderInterface         puente 2: "¿qué entró?"   → sirve para MIRAR
│   └── FinPasarelaClientInterface             puente 3: quién procesa el cobro (§11)
├── Dto/FinOrigenCobroDto                      "cuánto se debe y a quién", sin entidades
├── Dto/{FinMovimientoDto, FinMovimientoFiltro} un cobro recibido, y sus criterios
├── Entity/FinEnlacePago                       la unidad de cobro
├── Entity/FinPasarelaWebhookAudit             traza cruda de cada IPN
├── Enum/{FinOrigenCobro, FinEnlacePagoEstado, FinPasarela}
├── Service/FinOrigenCobroRegistry             despacha origen → resolver
├── Service/FinMovimientoRegistry              fusiona la caja de todos los módulos
├── Service/FinPasarelaRegistry                despacha pasarela → cliente, y elige la de defecto
├── Service/FinEnlacePagoService               emite y cierra enlaces (único sitio que cambia estado)
├── Service/FinEnlacePagoSerializer            forma JSON del enlace, compartida por 2 pantallas
├── Service/Izipay/IzipayClient                REST + validación de firma HMAC
├── Service/Culqi/CulqiClient                  REST + verificación por API (no hay firma, §11)
└── Controller/
    ├── Api/FinEnlacePagoApiController         panel de UNA reserva (con #[IsGranted])
    ├── Api/FinCajaApiController               vista global: pestañas Cobros y Caja
    ├── Publico/FinPagoPublicoController       SPA pax + cierre del cobro de Culqi
    ├── Webhook/IzipayWebhookController        IPN firmado de Izipay
    └── Webhook/CulqiWebhookController         aviso de Culqi: dispara, NO confirma

src/Pms/Finanzas/    ← el PMS implementa los DOS contratos de Finanzas
├── PmsReservaOrigenCobroResolver              cobrar una reserva
└── PmsPagoMovimientoProvider                  sus pagos, de cualquier medio, en la caja

util/  views/Finanzas/FinanzasView.vue, ReservaEnlacesPagoSection.vue,
       stores/finanzas/{enlacesPagoStore, cajaStore}.ts
pax/   views/pago/PaxPagoView.vue, types/izipayKrypton.d.ts
```

**La dirección de la dependencia es lo importante:** Finanzas declara la interfaz, los
módulos la implementan. Al revés (Finanzas importando `PmsReserva`) el módulo dejaría de ser
transversal el primer día.

---

## 2. La relación soft: por qué no hay foreign key

`FinEnlacePago` apunta a su documento con **dos columnas y ninguna FK**:

```
origen_tipo  VARCHAR(30)   'pms_reserva' | 'tour_reserva' | …
origen_id    BINARY(16)    el UUID del documento en su módulo
             + INDEX idx_fin_enlace_pago_origen (origen_tipo, origen_id)
```

La alternativa evaluada era una FK nullable por módulo (`pms_reserva_id`,
`tour_reserva_id`…) con un CHECK de "exactamente una". Se descartó porque el coste de
añadir el módulo N+1 crece a mano: columna, migración y edición de la entidad compartida —
la misma deuda que ya duele en mensajería con los canales.

**Lo que se pierde y por qué se acepta:**

- *No hay integridad referencial.* Puede quedar un enlace apuntando a una reserva borrada.
  Es **deseable**: un enlace pagado es un documento contable y tiene que sobrevivir al
  borrado de su origen. Por eso `FinOrigenCobroResolverInterface::resolver()` devuelve
  `null` en vez de lanzar, y la UI pinta "origen no disponible".
- *No hay JOIN en DQL.* Para saber de quién es un enlace hay que pasar por
  `FinOrigenCobroRegistry::resolver()`. El índice compuesto es lo que mantiene barata la
  consulta "enlaces de este documento".

> ⚠️ **Trampa de UUID binario.** `FinEnlacePagoRepository::porOrigen()` pasa el UUID con
> `setParameter(..., 'uuid')` **explícito**. Sin el tipo, Doctrine manda la forma canónica
> contra una columna `BINARY(16)` y la consulta devuelve **vacío sin error**. Es la misma
> trampa que documenta §12.6 de `docs/PmsBeds24ReservasSync.md` para los SearchFilter, y
> aquí muerde igual porque `origen_id` no es una relación sino una columna suelta.

---

## 3. Flujo completo de un cobro

> El diagrama de abajo es el de **Izipay**. Culqi confirma por otro camino (el navegador
> devuelve un token y lo cobra nuestro servidor): ver §11.

```
util (operador)                    backend                          Izipay / cliente
──────────────────────────────────────────────────────────────────────────────────────
Panel financiero
"Cobrar con tarjeta"
  monto (= saldo) ─────► POST /finanzas/enlaces-pago
                            FinEnlacePagoService::crear()
                              ├─ registry.resolver()   ← PmsReservaOrigenCobroResolver
                              │    lee saldo, moneda, cliente, localizador
                              ├─ congela neto + recargo + total
                              ├─ token = random_bytes(32)
                              └─ ordenId = LOCALIZADOR-xxxxxxxx
  ◄──── {url: pax/pago/<token>}

  copia la URL, la manda por WhatsApp/correo ──────────────────────►  cliente abre la URL

                         GET  /finanzas/pago/{token}      ◄──── PaxPagoView monta
                              (importes + publicKey + staticUrl)
                         POST /finanzas/pago/{token}/form-token
                              IzipayClient::crearFormToken()
                                └─ POST api.micuentaweb.pe
                                     /api-payment/V4/Charge/CreatePayment
                         ◄──── formToken (un solo uso, caduca en minutos)

                                                          formulario incrustado de Izipay
                                                          (la tarjeta NUNCA toca nuestro
                                                           servidor ni nuestro JS)
                                                                   │
                          ┌────────────────────────────────────────┤
                          │ navegador: KR.onSubmit                 │ servidor: IPN firmado
                          ▼                                        ▼
                 pinta "confirmando…"              POST /finanzas/webhooks/izipay
                 y SONDEA el backend                  1. audita en crudo (antes de validar)
                          │                           2. IzipayClient::validarFirma()
                          │                           3. localiza el enlace por metadata
                          │                           4. FinEnlacePagoService::confirmarPago()
                          │                                └─ registry.registrarCobro()
                          │                                     └─ crea PmsPagoFinanciero
                          └──────► ve estado='pagado' ◄───────────┘   (el listener del PMS
                                   → pantalla verde                    recalcula el saldo)
```

**La confirmación no la decide el navegador.** El aviso de `KR.onSubmit` sólo cambia lo que
se pinta; quien marca el cobro es el IPN firmado. Marcar como pagado desde el retorno del
navegador es la forma clásica de regalar estancias: ese aviso viaja por una máquina que no
controlamos, y un cliente que cierra la pestaña no lo dispara nunca.

Por eso `PaxPagoView.vue` sondea `GET /finanzas/pago/{token}` cada 2 s (20 intentos, ~40 s)
hasta ver `estado: 'pagado'`. Si el IPN tarda más, el pago no se pierde — pero al cliente no
se le deja girando: se le dice que lo revisamos.

---

## 4. El protocolo de Izipay y sus tres trampas

Izipay corre sobre la plataforma **Lyra**, de ahí que el host sea `api.micuentaweb.pe`.

### 4.1. Tres claves que no son intercambiables

| Clave | Dónde vive | Para qué |
|---|---|---|
| `password` | **Servidor** | Basic auth de la API **y** firma de los **webhooks (IPN)** |
| `publicKey` | Navegador | Inicializa la librería JS. Es pública por diseño |
| `hmacSha256Key` | **Servidor** | Firma del retorno al **navegador** (no del IPN) |

La trampa: **el IPN se firma con `password`, no con la clave HMAC.** Por eso
`IzipayClient::validarFirma()` lee el campo `kr-hash-key` del propio POST (`password` o
`sha256_hmac`) en vez de asumir una. Validar un IPN con la clave HMAC falla *siempre*, y la
tentación entonces es desactivar la validación "un rato" — que es exactamente cómo se acepta
un cobro falsificado.

### 4.2. El importe va en céntimos

`amount` es un entero en la unidad mínima de la moneda. Mandar `150.00` en vez de `15000`
cobra **un sol y medio** en lugar de ciento cincuenta, y la API lo acepta sin rechistar. La
conversión vive en `FinEnlacePago::montoTotalCentimos()`, no en el cliente HTTP, para que no
haya dos sitios donde equivocarse.

### 4.3. Responde 200 aunque rechace

La API contesta HTTP 200 incluso cuando falla; el veredicto está en el campo `status` del
cuerpo (`SUCCESS` o no). `IzipayClient::post()` comprueba eso y no el código HTTP.

### Otras dos que cuestan una tarde

- **El IPN llega como formulario**, no como JSON: los campos son `kr-answer`, `kr-hash`,
  `kr-hash-key` en `application/x-www-form-urlencoded`. Leerlo con `getContent()` +
  `json_decode` devuelve `null` y parece "webhook vacío".
- **Se firma el `kr-answer` crudo.** Decodificarlo y volver a serializarlo cambia el
  espaciado y el escapado de las barras, y el HMAC deja de cuadrar.

---

## 5. Estados del enlace y sus asimetrías

```
                  ┌──────────┐
   crear() ──────►│PENDIENTE │
                  └────┬─────┘
        IPN ok ────────┤────────► PAGADO ──────► REEMBOLSADO  (terminal)
                       │            (devuelto por la pasarela, §11 quater)
        IPN ko ────────┤────────► FALLIDO ──┐
                       │            ▲       │ el cliente reintenta con otra
                       │            └───────┘ tarjeta en la MISMA url
   operador anula ─────┤────────► ANULADO   (terminal)
   pasa expiraEn ──────┴────────► EXPIRADO  (terminal)
```

- **`FALLIDO` no es final.** Que una tarjeta rebote no invalida el enlace.
  `FinEnlacePagoEstado::esFinal()` lo deja fuera, y `estaVigente()` sigue dejando pagar.
- **`PAGADO` no se deshace: se avanza a `REEMBOLSADO`.** No hay vuelta a `PENDIENTE` y no se
  borra nada. El cobro ocurrió, y una devolución es **otro hecho** encima, no la cancelación
  del primero. `FinEnlacePagoService::anular()` se niega explícitamente sobre un enlace pagado
  —anular es para lo que aún no se cobró—, y para lo cobrado está `reembolsar()` (§11 quater),
  que llama a la pasarela, sella el estado y deja el cobro del módulo en cero con una nota.

  ⚠️ **Tampoco se deshace borrando el pago.** El cobro por pasarela está vetado en
  `PmsPagoFinanciero::getMotivoNoBorrable()` (§ *La relación pago ↔ enlace*): borrarlo dejaba
  el enlace diciendo `PAGADO` sobre una fila inexistente, o sea escondía la devolución en vez
  de registrarla. Hasta el 28/08/2026 el procedimiento era devolver en el Backoffice y
  eliminar el pago; ya no vale, y sólo queda el Backoffice para una pasarela que no sepa
  devolver desde aquí (hoy, Izipay).

- **`REEMBOLSADO` es final y llega SIEMPRE después del dinero.** Lo escribe `reembolsar()` una
  vez que la pasarela confirma, nunca antes: un estado que se adelanta es una devolución
  anotada que puede no haber ocurrido. Es final para `esFinal()`/`estaVigente()`, así que el
  enlace deja de ser pagable y desaparece de la app del huésped.

- **`ANULADO` es un estado NUESTRO: no se propaga a ninguna pasarela**, y con Culqi no hay
  nada que propagar. Emitir un enlace no llama a nadie —`crear()` escribe una fila y devuelve
  una URL de `pax`—, y en Culqi el cargo lo crea **nuestro servidor** con `cobrarConToken()`.
  Anular basta porque `ANULADO` es `esFinal()` → `estaVigente()` es `false`, y
  `FinPagoPublicoController` lo comprueba en las **dos** puertas, `/configuracion` y
  `/culqi/cobrar` (410 en ambas). Sin nuestro servidor no hay cargo, y sin cargo el webhook
  tampoco confirma: `verificarCargo()` no encuentra ninguno.
  Con **Izipay** la garantía sería más floja —`/configuracion` sí emite un `formToken` real
  que sobrevive al anular— pero eso está **parado**: ver el bloque 🅿️ del encabezado.
- **La expiración no la aplica ningún cron.** `estaVigente()` ya cierra la puerta al leer;
  `marcarCaducados()` se llama al listar y es sólo cosmética del panel.
- **`confirmarPago()` es idempotente.** Un IPN repetido —Izipay reintenta si no
  respondemos 200 a tiempo— sobre un enlace ya `PAGADO` se ignora. Sin esa guarda el
  reintento crearía un segundo `PmsPagoFinanciero` y la reserva quedaría con el doble de
  pagos; un duplicado que no se detecta hasta que alguien cuadra la caja.

**Contrato de respuesta del webhook:** 200 también cuando lo ignoramos (mensaje válido que
no es nuestro), porque un no-200 hace que Izipay reintente en bucle. 400 sólo para firma
inválida. 500 cuando el fallo es nuestro — ahí sí queremos el reintento, porque el cobro
ocurrió.

---

## 6. Importes: neto, recargo y total

Misma semántica que `PmsPagoFinanciero`, para que el resolver pueda trasladarlos tal cual:

| Campo | Qué es |
|---|---|
| `montoNeto` | Lo que **abona la deuda** de la reserva |
| `recargoPorcentaje` | 5.50 = 5.5%. Se guarda el porcentaje, no el importe |
| `montoTotal` | Lo que se **cobra a la tarjeta**: neto + recargo |

Dos decisiones que parecen redundancia y no lo son:

- **`montoTotal` se guarda** en vez de derivarse. Es la cifra que viajó a la pasarela: si
  mañana cambia la fórmula del recargo, la conciliación con Izipay tiene que cuadrar contra
  lo que se cobró *aquel día*, no contra un recálculo.
- **Los importes están congelados** al emitir; no son una lectura viva del saldo. Si el
  saldo cambia después, el enlace sigue cobrando lo que decía cuando se envió: el cliente ya
  tiene esa cifra en su correo. Para cobrar el saldo nuevo se anula y se emite otro.

Al confirmarse, `PmsReservaOrigenCobroResolver::registrarCobro()` crea el pago con el
**neto** y `comisionPorcentaje` = el recargo, marcado `esAutomatico` y **sin cobrador**
(nadie recibió ese dinero en mano). El saldo lo recalcula
`PmsInformacionFinancieraCoherenciaListener` al persistir, como con cualquier otro pago.

---

## 7. Espejos PHP ↔ TypeScript

Ninguno se genera solo. **Si tocas un lado, toca el otro.**

| PHP | TypeScript |
|---|---|
| `FinEnlacePagoSerializer::aArray()` | `util/src/types/finEnlacePagoModel.ts` |
| `FinCajaApiController::cobros()` | `util/src/types/finEnlacePagoModel.ts` (`FinCobrosRespuesta`) |
| `FinMovimientoDto::aArray()` y `FinCajaApiController::movimientos()` | `util/src/types/finMovimientoModel.ts` |
| `FinPagoPublicoController::ver()` / `configuracion()` | `pax/src/types/paxPagoModel.ts` |
| Librería KR de Izipay (sin paquete npm) | `pax/src/types/izipayKrypton.d.ts` |
| Checkout Custom de Culqi (sin paquete npm) | `pax/src/types/culqiCheckout.d.ts` |
| `PmsGuiaHuespedProvider::mediosPago()` (aplana `FinMedioCobro`) | `pax/src/types/paxHuespedGuiaModel.ts` (`GuiaMedioPago`) — §14 |

Y un espejo que **no es TypeScript**: el porcentaje de recargo vive en
`finanzas.recargo_tarjeta_porcentaje` (`config/services/services_finanzas.yaml`) **y** en
`PmsMedioPago::TARJETA_CREDITO->comisionPorcentaje()`. Está duplicado a propósito: Finanzas
no puede depender del PMS, es al revés. Si cambia el 5.5%, se cambia en los dos sitios.

---

## 8. Configuración y credenciales

Variables en `.env` (bloque `app/finanzas-izipay`), **valores reales en `.env.local`**:

```
IZIPAY_ENDPOINT=https://api.micuentaweb.pe
IZIPAY_STATIC_URL=https://static.micuentaweb.pe
IZIPAY_USERNAME=            # shopId numérico, igual en test y producción
IZIPAY_PASSWORD=            # ⚠️ firma los webhooks: quien lo tenga puede fabricar un cobro
IZIPAY_HMAC_SHA256_KEY=     # ⚠️ secreto
IZIPAY_PUBLIC_KEY=          # pública, va al navegador
```

Salen del **Backoffice Vendedor de Izipay → Configuración → Tienda → Claves de API REST**.
Hay un juego para TEST y otro para PRODUCCIÓN.

**Webhook a registrar en el Backoffice** (Reglas de notificación → URL de notificación al
final del pago), apuntando al host de API:

```
https://<API_HOST>/finanzas/webhooks/izipay
```

Sin ese registro todo funciona salvo lo único que importa: los cobros nunca se confirman y
los enlaces se quedan eternamente en `PENDIENTE`.

### Culqi (la pasarela operativa hoy — ver §11)

```
CULQI_ENDPOINT=https://api.culqi.com
CULQI_PUBLIC_KEY=            # pk_test_ / pk_live_. Pública: va al navegador
CULQI_SECRET_KEY=            # ⚠️ sk_test_ / sk_live_. SOLO en .env.local
FINANZAS_PASARELA_POR_DEFECTO=culqi   # izipay | culqi
```

CulqiPanel → Desarrollo → **API Keys**. Webhook en Desarrollo → **Webhooks**:

```
https://<API_HOST>/finanzas/webhooks/culqi
```

En Culqi el webhook es **red de seguridad, no camino principal**: aunque no se registre, los
cobros se confirman igual desde `culqiCobrar()`. Cubre el caso de que el cliente pierda la
conexión justo después de pagar.

`FINANZAS_PASARELA_POR_DEFECTO` **no apaga la otra pasarela**: si la indicada no tiene
credenciales, `FinPasarelaRegistry::porDefecto()` cae a la primera que sí las tenga, porque
con dos conectores en paralelo un `.env` a medio rellenar es lo normal y no poder cobrar por
una errata sería peor que cobrar por la otra.

**Registro del módulo** (el patrón de este repo, por si se replica):

| Qué | Dónde |
|---|---|
| Servicios y parámetros | `config/services/services_finanzas.yaml` + import en `config/services.yaml` |
| Mapping Doctrine | `config/packages/doctrine.yaml` → `Finanzas`, alias `finanzas` |
| Rutas (3 bloques, host api) | `config/routes.yaml` → `finanzas_{api,publico,webhook}_controllers` |
| Tablas | `fin_enlace_pago`, `fin_pasarela_webhook_audit` (migración `Version20260808034446`) |

> Los resolvers **no** se declaran en YAML: el `#[AutoconfigureTag]` está en la interfaz.

### Seguridad

El host de API cae en `PUBLIC_ACCESS` (`config/packages/security.yaml`). Eso es **lo que se
quiere** en `Publico/` y `Webhook/` —ni el huésped ni Izipay tienen sesión— pero significa
que `Api/` se protege **sólo** con sus `#[IsGranted]`. No los quites.

La credencial de la página pública es el **token** del enlace: 32 bytes de `random_bytes`,
no el UUID de la fila (el v7 lleva marca de tiempo y sería enumerable por cercanía). El
endpoint público devuelve el mínimo: importe, moneda y concepto — nada del origen, ni quién
lo emitió, ni el email del cliente. Quien abre esa URL puede ser cualquiera a quien se la
hayan reenviado.

---

## 9. El módulo en `util`: Cobros y Caja

Entrada por `/finanzas` (grupo **Administración** del portal). Dos pestañas, y no se
fusionan porque responden a preguntas distintas:

| | COBROS | CAJA |
|---|---|---|
| Pregunta | ¿Qué emití y en qué quedó? | ¿Cuánto entró y por dónde? |
| Filas | `FinEnlacePago` de todos los documentos | Pagos de cualquier medio, de todos los módulos |
| Incluye lo no pagado | **Sí** (son los que hay que perseguir) | No |
| Importe | `montoTotal` — con recargo, es la cifra que mueve la pasarela | **Neto** — el recargo se lo queda la pasarela, no llega a la cuenta |
| Filtro por fecha | De **creación** del enlace | De **pago** |
| Fuente | `FinEnlacePagoRepository::buscar()` | `FinMovimientoRegistry::buscar()` |

Un cobro emitido y sin pagar sale en la primera y no en la segunda; un pago en efectivo, al
revés. **Sumar las dos cifras no significa nada**, y por eso nunca se pintan juntas.

El filtro de cobros va por fecha de **creación** a propósito: filtrando por fecha de pago
desaparecerían justo los que nadie pagó, que son el motivo de mirar esta pantalla.

### Anular desde aquí, y por qué hacía falta (28/08/2026)

`anular` vivía **sólo** en `ReservaEnlacesPagoSection.vue`, o sea sólo en el panel de una
reserva. Para un cobro con origen eso era incómodo —«ir al origen» y anular allí— pero para
un **manual** era una puerta cerrada: nace sin `origenId`, así que **no aparece en el panel
de ninguna reserva**, y sólo se crea desde esta pantalla. Un manual emitido por error se
quedaba vigente y pagable hasta caducar; con `vigenciaDias = 0`, para siempre.

El backend nunca tuvo esa limitación: `POST /finanzas/enlaces-pago/{id}/anular` va por id y
no mira el origen. Era la vista la que no tenía el botón.

Ahora está en los **dos** sitios de la pestaña Cobros, los dos sólo sobre un cobro `vigente`:

| Dónde | Forma | Por qué así |
|---|---|---|
| Fila de la tabla | Icono 🚫 junto a «copiar», con `@click.stop` | Sin `.stop` la fila abriría además la ficha |
| Pie de la ficha | Botón de ancho completo, **debajo** de «Copiar», en tono neutro | Copiar es lo que se viene a hacer; anular casi nunca. Dos botones iguales en la misma línea se pulsan por proximidad |

⚠️ **La confirmación NOMBRA el cobro** —importe y concepto— en vez de preguntar «¿seguro?».
En una tabla de hasta 500 filas con los botones pegados, un «¿Anular este enlace?» a secas
no deja comprobar que se pulsó el de la fila que se quería: quien confirma no tiene delante
ningún dato del que va a anular. Con el importe dentro, un clic en la fila de al lado se ve
antes de aceptar.

`cajaStore.anularCobro()` **sustituye su fila** con el enlace que devuelve el backend, no
recarga: recargar traería la consulta entera con sus filtros para cambiar una etiqueta de
estado, y devolvería el scroll al principio con la ficha abierta encima. Si la ficha está
abierta sobre ese mismo cobro, se refresca también — si no, seguiría ofreciendo «Copiar
enlace de pago» de uno que acaba de morir.

⚠️ Es **gemelo de `enlacesPagoStore.anular()`**: dos listas distintas, cada store mantiene la
suya. Si cambia el endpoint o su respuesta, hay que tocar los dos.

### Cobro manual: el origen es OPCIONAL

`origenTipo` y `origenId` son ambos **nullable**, y son dos cosas separables:

| `origenTipo` | `origenId` | Qué es | ¿Imputa dinero en un módulo? |
|---|---|---|---|
| `pms_reserva` | uuid | Cobro contra un documento: el resolver lee su saldo | **Sí** |
| `pms_reserva` | `null` | Manual **etiquetado** en ese módulo | No |
| `null` | `null` | Manual suelto | No |

**Lo que define un cobro manual es la ausencia de `origenId`, no la de módulo.** Un manual
etiquetado como PMS sigue siendo manual: no hay reserva a la que imputarle el dinero.

Consecuencia: al cobrarse, un manual **no llama a ningún resolver** — el dinero queda
registrado sólo en Finanzas. No es un caso degradado, es el caso normal de una venta suelta.

El `modulo` del formulario es **sólo una etiqueta para filtrar**. Por eso se admite
`cotizacion` aunque ese módulo todavía no tenga resolver: etiquetar no requiere saber leer
saldos.

Se emite desde `/finanzas` → pestaña Cobros → botón **Cobro manual**, con endpoint propio
(`POST /finanzas/enlaces-pago/manual`) porque el contrato es distinto: sin saldo del que
deducir, importe, moneda y concepto pasan a ser obligatorios.

### El segundo contrato: `FinMovimientoProviderInterface`

La pestaña de caja necesita leer los pagos de cada módulo, y eso es otra pregunta que
"cuánto debe este documento". Por eso hay un contrato aparte y no un método más en
`FinOrigenCobroResolverInterface`: un módulo puede querer aparecer en la caja sin emitir
enlaces, y juntarlos obligaría a implementar métodos vacíos.

Mismo mecanismo de tags (`finanzas.movimiento_provider`, declarado en la interfaz). El del
PMS es `PmsPagoMovimientoProvider`, que traduce `PmsPagoFinanciero` y expone **todos** los
medios — efectivo, Yape, transferencia, tarjeta—, no sólo lo cobrado por pasarela.

### Dos límites conocidos, y por qué se aceptan

- **Los totales no se convierten entre monedas.** Se agrupan por moneda. Convertir obligaría
  a Finanzas a elegir un tipo de cambio, y el bueno es el del día de cada pago — que el
  módulo ya guardó en `tipoCambio`. Una cifra única con la cotización de hoy no cuadraría
  con ningún extracto. Es la misma decisión que ya toma el panel de la reserva.
- **No hay paginación: el tope de 500 filas es POR MÓDULO.** Cada provider devuelve hasta
  ese tope y `FinMovimientoRegistry` fusiona y recorta. Con un solo módulo el resultado es
  exacto; con varios, uno muy activo podría desplazar filas antiguas de otro. La respuesta
  trae `truncado: true` cuando se roza y la vista lo avisa. Paginar de verdad exige una
  tabla común de movimientos — eso es rehacer la contabilidad, no listar pagos; el día que
  estorbe, la salida es materializar los movimientos en Finanzas, no complicar el registry.

---

## 10. Añadir un módulo nuevo que cobre

Todo el diseño está pensado para que esto sean **dos pasos** (tres si además quieres que
sus pagos salgan en la caja):

1. Añadir el `case` a `FinOrigenCobro` (si no está ya).
2. Crear `src/<Modulo>/Finanzas/<X>OrigenCobroResolver.php` implementando
   `FinOrigenCobroResolverInterface`:
   - `soporta()` → el case.
   - `resolver()` → un `FinOrigenCobroDto` con saldo, moneda, cliente y referencia.
     **Devuelve `null` si el documento ya no existe**, nunca lanza.
   - `registrarCobro()` → crea el asiento del módulo y devuelve su UUID. Sin `flush()`: la
     transacción la cierra `FinEnlacePagoService::confirmarPago()`.

3. *(Opcional, para la pestaña de caja)* Crear
   `src/<Modulo>/Finanzas/<X>MovimientoProvider.php` implementando
   `FinMovimientoProviderInterface`: `buscar()` traduce sus pagos a `FinMovimientoDto` y
   `mediosDisponibles()` declara su vocabulario de medios. Aparece en `/finanzas` solo.

No hay que tocar la entidad, ni la migración, ni los registries, ni el YAML de Finanzas, ni
la vista del módulo. En el panel de reserva, `ReservaEnlacesPagoSection.vue` se monta con
otro `origen-tipo` y ya.

Los del PMS (`PmsReservaOrigenCobroResolver` y `PmsPagoMovimientoProvider`) son el modelo a
copiar.

---

## 11. Dos pasarelas en paralelo: Izipay y Culqi

**No se migra de una a otra: conviven.** Cada `FinEnlacePago` guarda en `pasarela` con cuál
se emitió, y esa columna existe desde la primera migración precisamente para esto.

### Por qué

Izipay **exige S/200 000 de venta acumulada** para habilitar enlaces de pago — un círculo
imposible: para vender eso necesitas los enlaces. Culqi no tiene muro de volumen ni coste de
afiliación, así que es la pasarela operativa. El conector de Izipay se queda **entero y
funcionando** para el día que cambien la política o nos habiliten; borrarlo obligaría a
rehacerlo desde cero.

🅿️ **Pero «se queda» no es «se mantiene»: Izipay está PARADA.** Se conserva compilando y no
se toca —ni para arreglarle huecos conocidos— hasta que se implemente. La lista de lo que
queda congelado con ella está en el bloque 🅿️ del encabezado de este documento.

### Los flujos NO se parecen, y por eso la interfaz es corta

```
IZIPAY (Lyra)                          CULQI
─────────────────────────────          ─────────────────────────────
servidor → formToken (1 uso)           servidor → nada (config estática)
   ↓                                      ↓
navegador monta el form                navegador captura tarjeta
   ↓                                      ↓
navegador ↔ pasarela                   navegador → nos devuelve un TOKEN
   ↓                                      ↓
IPN FIRMADO (HMAC) confirma            NUESTRO servidor crea el cargo
                                       (POST /v2/charges) ← confirma aquí
                                          ↓
                                       webhook = red de seguridad
```

`FinPasarelaClientInterface` sólo declara `pasarela()`, `estaConfigurado()` y
`configuracionPago()`. Forzar un método común de "cobrar" habría dado uno que Izipay
implementa vacío — la abstracción que parece limpia y luego hay que deshacer. Lo específico
vive en su cliente (`CulqiClient::cobrarConToken()`) y lo consume su propio controlador.

### 🪪 El apellido dejó de perderse por el camino (31/08/2026)

`PmsReserva` guarda `nombreCliente` y `apellidoCliente` **en dos columnas**, y
`PmsReservaOrigenCobroResolver` los pegaba con `getNombreApellido()` antes de crear el enlace. De
ahí para abajo sólo había un campo, así que a la pasarela le llegaba «Vanesa Acosta» en
`first_name` y nada en `last_name`.

**La solución no es partir mejor: es dejar de juntarlos.** Recuperar el apellido al final es
adivinar dónde empieza, y no hay heurística que acierte — `RGRABK` tiene nombre «Ramos Garcia» y
apellido «Mª Isabel».

Lo que se tocó, de punta a punta:

| Pieza | Qué |
|---|---|
| `Version20260831120000` | columna `fin_enlace_pago.cliente_apellido` |
| `FinEnlacePago` | propiedad, getters y `getClienteNombreCompleto()` para donde se enseña una línea |
| `FinOrigenCobroDto` | un campo más en el contrato de origen |
| `PmsReservaOrigenCobroResolver` | los lee separados en vez de pegarlos |
| `FinEnlacePagoService` | `construir()`, `crear()` y `crearManual()` |
| `FinEnlacePagoApiController` | `clienteApellido` en el cuerpo del manual |
| `CulqiClient` | `first_name` + **`last_name`** |
| `IzipayClient` | `billingDetails.lastName`, para que las dos pasarelas digan lo mismo |
| `FinanzasView.vue` | nombre, apellido, **teléfono** y referencia, los cuatro opcionales |

⚠️ **No se rellena hacia atrás.** Los 21 enlaces anteriores conservan el nombre completo en
`cliente_nombre` y sin apellido. Partirlo ahora sería la adivinanza que este cambio viene a
evitar, sobre datos que además ya se mandaron a la pasarela. Se leen igual: el panel concatena.

⚠️ **El teléfono faltaba en el formulario manual**, no en el backend: `crearManual()` ya lo
aceptaba y el DTO también. Sólo no había campo, así que un cobro manual nacía sin teléfono aunque
el operador lo tuviera delante.

⚠️ **Y en las reservas de OTA, nombre y apellido llegan como los manda el canal** — a veces
cambiados, como `RGRABK`. Separarlos hace que la pasarela enseñe exactamente lo que Beds24 nos
dio: para bien y para mal. Pegados, ese desorden no se veía.

### 🪪 Quién paga: `antifraud_details` (31/08/2026)

En el panel de Culqi **todas** las ventas salían a nombre de `first_last_name first_last_name` y
con el correo `pagos@openperu.pe`. Ninguna de las dos cosas las inventa Culqi:

- El **nombre** es su relleno por defecto porque no mandábamos ninguno.
- El **correo** es nuestro respaldo: `getClienteEmail() ?: 'pagos@openperu.pe'` en
  `cobrarConToken()`, que se dispara en cuanto la reserva no trae email — lo normal en directas.

Se confirmó **leyendo el cargo que Culqi nos devolvió** (`respuesta_pasarela.culqi.antifraud_details`
de un cobro real), no la documentación: su web es una SPA que no se deja leer. Los siete campos que
devuelve son `first_name`, `last_name`, `phone`, `address`, `address_city`, `country_code` y
`object`.

⚠️ **Se manda `phone_number` y vuelve `phone`.** El SDK oficial (`culqi/culqi-php`) documenta
`phone_number` al crear el cargo; el objeto que Culqi devuelve lo llama `phone`. Mandar `phone` no
da error: **se ignora en silencio** y el panel se queda igual de vacío. Los otros cinco se llaman
igual en las dos direcciones.

⚠️ **Va en el cargo, no en el Checkout.** El `client` del Checkout Custom sólo lleva `email`; el
nombre viaja en `antifraud_details` del `POST /charges`, que es servidor.

**El nombre va ENTERO en `first_name`.** `FinEnlacePago` guarda un solo campo —«Vanesa Acosta»— y
Culqi quiere dos. No se parte por el primer espacio: «Ramos Garcia Mª Isabel» daría un apellido
inventado, y como el panel los pinta concatenados, entero en el primero se lee igual sin adivinar.
Es lo que `IzipayClient` ya hace con `billingDetails.firstName`.

`address`, `address_city` y `country_code` **no se mandan**: no están en el enlace, y Finanzas no
puede preguntárselos al PMS —la dependencia va al revés—. El día que hagan falta van como columna.

Cobertura de lo que sí tenemos, sobre 21 enlaces: **nombre 20, teléfono 18, correo 16**.

### ⚠️ Culqi no firma sus webhooks — y eso cambia el diseño

La documentación de Culqi **no publica firma, secreto compartido ni lista de IPs** para los
webhooks (comprobado en su doc de webhooks y en la referencia de API). Con Izipay el IPN va
firmado con HMAC y por eso se puede creer; aquí no hay nada que validar.

Un endpoint público sin firma lo puede llamar cualquiera: **creerse el cuerpo permitiría
saldar reservas con un `curl`**. Así que `CulqiWebhookController` trata el aviso como un
simple disparador — saca un id de cargo y **nada más** — y la verdad la da
`CulqiClient::verificarCargo()`, que pregunta a Culqi con nuestra clave secreta.

Y no basta con que el cargo exista y esté pagado: `cargoPagaElEnlace()` comprueba **importe
y moneda** contra el enlace. Sin eso, alguien podría mandarnos el id de un cargo real suyo de
S/1 y saldar una reserva de S/2000 — el id viene de fuera, así que el importe se verifica
siempre.

Ese diseño es más robusto que la firma y **sigue valiendo si Culqi añade firma mañana**
(entonces se suma como segunda barrera, no la sustituye).

### Dónde está la confirmación de cada una

| | Camino principal | Red de seguridad |
|---|---|---|
| Izipay | IPN firmado (`IzipayWebhookController`) | — |
| Culqi | `FinPagoPublicoController::culqiCobrar()`, llamada nuestra autenticada | webhook + verificación por API |

En Culqi el endpoint público de cobro es seguro pese a no tener sesión: el token del
navegador no autoriza nada por sí solo, el cargo lo crea el servidor con la clave secreta, y
**el importe sale del enlace, no del navegador**. Manipular el JS no abarata el cobro.

Las dos rutas acaban en `FinEnlacePagoService::confirmarPago()`, que es idempotente — por eso
el webhook de Culqi puede llegar después del cobro ya cerrado sin duplicar el pago.

### Las llaves RSA de Culqi son OPCIONALES

En el CulqiPanel se marca **qué endpoints proteger**: es cifrado selectivo, no un requisito.
Si algún día se activa, es híbrido (AES-256-GCM para el payload, RSA-OAEP-SHA256 para clave e
IV) con cabecera `x-culqi-rsa-id` y un cuerpo de `encrypted_data` / `encrypted_key` /
`encrypted_iv`. **No está implementado y no hace falta**: triplica la superficie de fallo y
no aporta nada yendo por HTTPS.

### Qué ve el operador

El selector de pasarela **sólo aparece si hay más de una configurada** (`/finanzas/enlaces-pago/pasarelas`
devuelve únicamente las que tienen credenciales); con una sola, estorba. Por defecto va en
"Automática", que es lo que decide `FinPasarelaRegistry::porDefecto()`.

La pasarela se pinta en cada fila —tanto en el panel de la reserva como en el listado global
de Cobros— porque al conciliar, lo primero que hace falta es saber contra qué extracto se
cuadra ese cobro.

### Checkout Custom, y no Checkout v4

Culqi ofrece **dos librerías de navegador, ambas vigentes**. Usamos **Checkout Custom**
(`js.culqi.com/checkout-js`), no Checkout v4 (`checkout.culqi.com/js/v4`).

> Corrección a una creencia previa: **v4 NO está deprecado**. Se llegó a afirmar aquí a
> partir de un resumen de búsqueda; la documentación no dice tal cosa y las presenta como
> alternativas. Lo único cierto es que la URL de la doc del "custom" dejó de colgar de v4.

El motivo del cambio **no es la versión, es el estado global**:

| | Checkout v4 | Checkout Custom |
|---|---|---|
| Instancia | `window.Culqi`, singleton | `new CulqiCheckout(pk, config)` |
| Callback | `window.culqi`, buscado por nombre | `instancia.culqi = fn` |

En una SPA, el singleton obliga a asignar y borrar globales en cada montaje. Si se escapa
uno —y se escapa— al reentrar en la página se dispara el callback del componente anterior
**contra un enlace que ya no toca**: cobrarías el importe de otra reserva. Con la instancia
local, el objeto nace y muere con el componente y ese fallo deja de ser posible.

De paso, Checkout Custom permite personalizar estilos y ordenar los medios de pago.

#### ⚠️ El peaje: `shallowRef` + `markRaw`, nunca `ref`

Pasar a una instancia trae un fallo que el singleton no tenía, y **el mensaje engaña porque
parece de la librería**:

```
TypeError: Cannot read private member #e from an object whose class did not declare it
    at Proxy.validateConfig (checkout-js)
    at Proxy.open (checkout-js)
```

`ref()` envuelve el objeto en `reactive()`, que es un **`Proxy`**, y un Proxy **rompe los
campos privados de clase** (`#campo`). `CulqiCheckout` los usa por dentro, así que al pulsar
Pagar reventaba. La pista está en la traza: `Proxy.open`, no `CulqiCheckout.open`.

La regla, y vale para cualquier librería, no solo esta: **una instancia de clase de terceros
nunca va en un `ref`**. `shallowRef` para no proxiar el contenido, y `markRaw` encima para
que no pueda proxiarla nadie más aunque acabe en otro sitio reactivo.

Existe además un paquete oficial `culqi/vue-culqi-checkout`. Se descartó: envuelve lo mismo
en una dependencia más, y aquí la integración son treinta líneas.

### Qué NO es un cobro por pasarela: `esAutomatico`

El `PmsPagoFinanciero` que genera un cobro **no lleva `esAutomatico`**, aunque lo cree el
sistema. Esa bandera no significa "lo creó el sistema" sino **"el sistema lo REGENERA
solo"**: identifica el depósito espejo de las OTA de pago total, y de ella cuelgan dos
guardas —no borrable y no editable— pensadas para que el operador no pelee contra un
registro que va a reaparecer.

Un cobro por pasarela es un hecho consumado: no reaparece, y **tiene que poder borrarse**,
porque ése es el paso 2 de revertir un cobro (primero se devuelve el dinero en el Backoffice
de la pasarela). Marcarlo hacía que el borrado respondiera 500 con un mensaje sobre
"depósitos del canal" que no venía a cuento.

La trazabilidad no depende de esa bandera: el enlace apunta al pago con
`movimientoGeneradoId`, el pago guarda la referencia de la transacción y la nota dice por qué
pasarela entró.

### Una regla de negocio no es un error 500

Al intentar borrar el depósito automático de una OTA, la SPA mostraba **"Internal Server
Error"**. No era un fallo: era el listener de coherencia defendiendo una regla con un
`DomainException`… que API Platform trataba como caída del servidor porque **no había
`exception_to_status`**.

Dos consecuencias, y la segunda es peor que la primera:

- El operador leía un error genérico en vez del motivo, sin saber qué hacer.
- El log se llenaba de `CRITICAL` por situaciones normales, así que **un 500 dejaba de
  significar nada**: no se podía distinguir una regla de negocio de una caída de verdad.

Arreglado globalmente en `config/packages/api_platform.yaml`:

```yaml
exception_to_status:
    DomainException: 422
```

422 es lo correcto —la petición está bien formada, es el estado del dominio el que la
rechaza— y arregla de golpe **todas** las guardas del módulo, no sólo ésta. El mensaje viaja
en `hydra:description` y `extractApiErrorMessage()` ya lo pinta.

> Consecuencia: el texto de cualquier `DomainException` **lo va a leer el operador**. Ya
> estaban escritos así, pero conviene recordarlo al añadir uno nuevo.

### Y antes de eso: no ofrecer lo que se va a rechazar

Arreglar el código de estado no basta. El basurero seguía apareciendo en un pago que nunca
iba a poder borrarse — el sistema ofreciendo una acción y negándola después.

La regla pasa a vivir en la entidad (`PmsPagoFinanciero::getMotivoNoBorrable()`), que es
**fuente única**: el listener la usa para vetar y la SPA para decidir si pinta el basurero o
un candado con el motivo en el tooltip. Mismo patrón que
`PmsEventoCalendario::getMotivoNoBorrable()`.

Con la condición escondida dentro del listener, el frontend no tenía forma de conocerla.

### La relación pago ↔ enlace, y por qué los importes no coinciden

Un enlace de US$ 328.11 genera un pago de US$ 311.00. **No es un descuadre**: el enlace cobra
el total con recargo y el pago registra el **neto**, que es lo único que abona la reserva
(§6). El recargo se lo queda la pasarela.

Como eso confunde al verlo suelto, el panel marca esos pagos con una etiqueta
`Enlace · <pasarela>`. La relación se resuelve **en el frontend**, cruzando
`enlace.movimientoGeneradoId` con el id del pago: las dos listas ya están cargadas en el
mismo panel y así el PMS no necesita guardar una referencia a Finanzas sólo para pintar una
etiqueta.

> Si algún día hace falta esa marca **fuera del panel** —en la vista de caja, en el agente—
> habrá que persistirla, porque ahí no se dispone de los enlaces.

**Ese día llegó el 28/08/2026, y la marca ya se persiste:**
`pms_pago_financiero.enlace_pago_id` (soft, sin FK, como todo lo que cruza a Finanzas — §2).
La escribe `PmsReservaOrigenCobroResolver::registrarCobro()` al crear el pago.

Lo que la hizo falta no fue una etiqueta más, sino una **regla**: un cobro por pasarela no se
puede borrar (abajo), y `PmsPagoFinanciero::getMotivoNoBorrable()` corre en el backend, donde
no hay ninguna lista de enlaces cargada. Preguntárselo a Finanzas por repositorio metería una
consulta dentro de un getter de entidad y partiría en dos la fuente única que ese método es.

⚠️ La etiqueta del panel **sigue resolviéndose en el frontend** por cruce. No se migró a leer
el campo nuevo: allí el cruce funciona, es gratis y cambiarlo no arregla nada. Son dos
mecanismos para dos usos distintos y está bien que lo sean — pero si algún día el cruce del
panel falla, la respuesta es leer `enlacePagoId`, no arreglar el cruce.

#### Un cobro por pasarela NO se borra

`getMotivoNoBorrable()` lo veta en cuanto `enlacePagoId` no es null. El basurero desaparece y
en su sitio queda el candado con el motivo, igual que con el depósito del canal.

Borrarlo dejaba **dos afirmaciones contradictorias vivas a la vez**: el enlace seguía en
`PAGADO` con su fecha y su código de autorización, y la reserva volvía a deber un dinero que
el huésped sí pagó. Y rompía la trazabilidad en las dos direcciones —
`fin_enlace_pago.movimiento_generado_id` quedaba apuntando a una fila inexistente— y con ella
la etiqueta que era lo único que explicaba por qué el enlace y el pago no valen lo mismo.

La diferencia con el depósito del canal importa: **aquél se veta porque reaparecería solo;
éste se veta porque la pasarela no se entera**. Quien tiene la verdad de este dinero es el
extracto de Culqi, no una fila nuestra.

⚠️ **Efecto colateral: una reserva con un cobro por pasarela ya no se puede borrar.** Sus
pagos van en `cascade: ['remove']`, así que el borrado llega al veto. Es lo correcto —no se
borra la reserva de alguien que pagó— y **el aviso llega a tiempo y con su motivo**:
`PmsReserva::getMotivoNoBorrable()` recorre también los pagos, así que la SPA no ofrece el
botón, y `PmsReservaDeleteListener::preRemove()` rechaza con el texto dentro si se intenta
igual. Antes esto salía como un 500 pelado —el veto saltaba en `onFlush`— y con el depósito
automático de las OTA pasaba ya desde antes. El detalle está en `docs/PmsBeds24ReservasSync.md`.

### La respuesta de la pasarela NO se modela, y es una decisión

`fin_enlace_pago.respuesta_pasarela` es un `json` que guarda **lo que llegó, tal cual**. No
hay columnas para "código de autorización de Culqi", "outcome", "reference_code" ni nada
parecido, y no las va a haber.

El motivo es el propio diseño del módulo: **la interfaz es agnóstica de pasarela**. Un
`charge` de Culqi y un `kr-answer` de Lyra no comparten un solo campo. Modelar esa
estructura en columnas o en una entidad ataría Finanzas a la forma de una de las dos, y el
día que entre la tercera habría que elegir entre reescribir el modelo o meter un campo
suelto por proveedor — la misma deuda por canal que ya duele en mensajería.

Lo que sí se extrae a columnas propias es el puñado de datos que el **negocio** necesita y
que existen en cualquier pasarela: `transaccion_uuid`, `autorizacion_codigo`,
`medio_detalle`. Eso lo normaliza cada cliente en su
`comoRespuestaNormalizada()` — la traducción vive en el conector, no en el modelo.

Para auditar, `GET /finanzas/enlaces-pago/{id}/respuesta` devuelve el JSON crudo y la UI lo
pinta formateado **sin interpretar ningún campo**. Endpoint aparte del listado a propósito:
son varios KB por fila y se consultan una vez al año, cuando alguien reclama.

En TypeScript el tipo es `unknown`, no una interfaz: tiparlo sería el mismo error en el otro
lado. Ver `FinRespuestaPasarela` en `util/src/types/finEnlacePagoModel.ts`.

### ⚠️ La trampa de `order`: la misma palabra, dos significados

Costó una tarde. En **Izipay**, `orderId` es una referencia **libre**: le mandamos
`QW9ANY-94ce142e` y aparece tal cual en su Backoffice. En **Culqi**, `order` es el **id de
una orden creada antes vía `/v2/orders`** (formato `ord_...`), y es además lo que habilita
Yape y el pago en efectivo.

Pasarle nuestra cadena al Checkout de Culqi provoca el peor síntoma posible: **el botón de
pagar no hace nada**. `Culqi.settings()` la acepta sin protestar —sólo guarda— y es
`Culqi.open()` quien valida y aborta **en silencio**, sin excepción ni mensaje. Desde fuera
parece que el JS no cargó, cuando en realidad cargó perfectamente.

Por eso `CulqiClient::configuracionPago()` **no manda `order`**. Sin él, el Checkout ofrece
sólo tarjeta, que es exactamente lo que soportamos hoy. Nuestro `ordenId` sigue viajando en
el `metadata` del cargo, que es donde de verdad sirve para conciliar.

Consecuencia a tener presente: **Yape y PagoEfectivo no están disponibles** mientras no
implementemos `/v2/orders`. El texto bajo el botón dice "Tarjeta de crédito o débito" y no
debe prometer más.

### Comprobado contra la API real (cobro de test)

- **Éxito del cargo.** Un cobro autorizado devuelve `object: "charge"` **y**
  `outcome.type: "venta_exitosa"`. `cargoPagaElEnlace()` sigue decidiendo por
  `object === 'charge'` **a propósito**: es la condición necesaria y suficiente —Culqi sólo
  materializa el objeto cargo cuando autoriza, un rechazo devuelve un objeto de error—, y
  exigir además un `outcome.type` exacto arriesga rechazar un cobro legítimo si mañana
  aparece otra variante (3DS, por ejemplo). El valor se registra en el log para poder verlo.
- **Moneda.** La cuenta **acepta USD**, no sólo soles. Verificado con un cargo real de test
  de `72387 USD` → `venta_exitosa`. No hay que convertir importes.
- **Tarjetas de prueba que tokenizan** en esta cuenta: `4111 1111 1111 1111` (Visa) y
  `5111 1111 1111 1118` (Mastercard). Otras muy citadas —`4557 8818 8367 7553`,
  `4000 0000 0000 0101`, la Amex `3712 8882 2626 8310`— fallan con *"No se encuentra el Bin
  de la tarjeta"*: el BIN depende de la cuenta, así que si el modal dice "no pudimos validar
  tu tarjeta", **sospecha del número antes que del código**. Se reproduce en un segundo con
  un POST a `/v2/tokens` usando la clave pública.

### Sigue pendiente

**Si Culqi firma sus webhooks.** El panel ofrece un toggle "Activar autenticación" que su
documentación pública no explica. Merece preguntarlo a soporte: el diseño no depende de ello
—la verdad la da la consulta autenticada a la API— pero si la hay, se suma como segunda
barrera.

---

## 11 bis. Enlaces de PREPAGO

Cobrar el adelanto por pasarela. **No hay maquinaria de cobro nueva debajo**: es lo que ya
había, con el importe del prepago en vez del saldo.

```
consultar_cuenta ──► prepago_pendiente (sólo informa)
                                │
generar_enlace_prepago ─────────┤ PmsPrepagoEnlaceService::emitir()
   (skill, RESERVAS_WRITE)      │   ├─ PmsPrepagoCalculador::pendiente()  ← el importe
   confirmado=false → preview   │   └─ FinEnlacePagoService::crear(montoNeto: …)
   confirmado=true  → emite     │
                                ▼
                        FinEnlacePago (normal y corriente)
                                │
        el modelo redacta ──► enviar_mensaje_huesped ──► el huésped
                                │
                                ├──► app del pax: botón «Pagar ahora»
                                │     (PmsReservaPaxProvider::enlacesPagables())
                                ▼
                        /pago/{token} → Culqi → confirmarPago()
                                            └─ PmsPagoFinanciero (§3)
                                                 └─ el listener recalcula el saldo
```

### El interruptor: `FINANZAS_ENLACES_PREPAGO`

En **0** hasta que Culqi pase a producción. Con `pk_test_`/`sk_test_` la pasarela **acepta el
cobro y no mueve dinero**, y un huésped que "paga" ahí se va convencido de que ya está.

Apaga sólo el camino **automático** —la skill y el botón del pax—, no los cobros: el botón
«Cobrar con tarjeta» del panel sigue igual que siempre. Es la diferencia que importa: por el
panel pasa un operador que mira; por aquí el enlace sale solo.

⚠️ **El flag no comprueba las claves.** Una `pk_test_` es sintácticamente igual de válida que
una `pk_live_`, así que encenderlo es una decisión de una persona, no un automatismo. Antes de
ponerlo a 1: claves `_live_` en `.env.local` **y** `composer dump-env prod` (§12.1).

Con el flag apagado la skill **no existe en el catálogo** —`SkillConmutableInterface`, ver
`docs/Mensajeria.md` §11— y `enlacesPagables()` devuelve vacío, así que la app del pax no pinta
ningún botón. Comprobado en local en los dos sentidos.

### El enlace se emite SOLO cuando la reserva estrena importes

`PmsPrepagoEnlaceService::emitirPorCambioDeCargos()`, enganchado en el `postFlush` de
`PmsInformacionFinancieraCoherenciaListener`, justo detrás del estado de pago.

⚠️ **Y no al crear la reserva, aunque sea lo intuitivo.** La cabecera financiera nace **vacía**
—la auto-provisiona ese mismo listener—, y con base cero el calculador no pide nada. Los
importes llegan después, por el webhook de invoiceItems o por el cron de facturas: ese es el
momento en que el total deja de ser cero, y es donde se engancha.

**No hay ni una regla de negocio nueva.** Las decide `pendiente()`, como los otros cuatro
consumidores: `null` para los canales que ya cobraron (Airbnb, VRBO), `null` con base cero —un
inquiry (§11.2.b de PmsBeds24ReservasSync), una cancelada—, `null` sin política y `null` en
cuanto hay cualquier pago registrado.

#### Síncrono, y por qué

Emitir **no toca la red**: `crear()` es una consulta al calculador, un `persist` y un `flush`. A
la pasarela no se le habla hasta que el cliente abre la página
(`CulqiClient::configuracionPago()`). Sacarlo a Messenger no acortaría ninguna espera —el
disparador ya corre en un worker— y rompería la simetría con los dos pasos que tiene al lado en
ese mismo `postFlush`: el depósito de la OTA también **persiste** una entidad ahí.

> Si algún día crear un enlace exigiera pedirle un token a la pasarela —Izipay lo pide para su
> formulario—, entonces sí va a Messenger: eso ya es I/O externo en mitad de un flush.

🔒 **No lanza nunca.** Los cargos ya están persistidos y son la verdad contable; un enlace que no
sale no puede tumbarlos. Y no hace falta cola de reintentos: cualquier movimiento posterior de
esa reserva vuelve a pasar por el mismo recálculo.

#### Sin caducidad, y anulando el anterior

**`vigenciaDias: 0`.** Un enlace automático no lo mira nadie: si caducara a los 7 días moriría en
silencio y el huésped se quedaría sin poder pagar sin que nadie se entere. El emitido **a mano**
conserva su vigencia por defecto, porque ahí hay una persona detrás.

⚠️ **Y al emitir por un importe distinto se ANULA el vivo anterior** (`anularVigentes()`). Sin
eso, un cargo extra dejaba dos enlaces pagables por cantidades distintas y el huésped podía pagar
el que no toca. Con emisión manual casi no pasaba —hay alguien mirando—; automatizado pasa solo.
Se anula, no se borra: el enlace que se mandó existió.

⚠️ **Y la moneda se DICE, no se deduce.** `pendiente()` devuelve el importe en la moneda de la
**cabecera** (`base()` lo convierte), pero `crear()` sin el parámetro `moneda` se lo pregunta al
resolver, que responde «la de mayor saldo». En una reserva con cargos en soles y cabecera en
dólares son monedas distintas: el enlace habría cobrado **46.42 PEN donde el cálculo decía 46.42
USD**, cuatro veces menos. Pasa en los dos caminos —el automático y el manual— y en el manual
llevaba tiempo ahí, tapado porque había una persona delante.

⚠️ **Cuando el adelanto deja de proceder, el enlace se ANULA.** `pendiente()` a `null` —lo pagó
por transferencia, cambió la política— o cabecera inactiva: en los dos casos se retiran los
enlaces automáticos vivos. Sin esto, la ausencia de caducidad —correcta mientras el cobro
procede— se convierte en un enlace pagable **para siempre** en el WhatsApp de alguien que ya
pagó. Sólo los automáticos: uno emitido a mano es la decisión de una persona.

⚠️ **Una cancelada NO basta con `pendiente()`.** Con la cabecera inactiva sus cargos dejan de
sumar pero la **PENALIZACIÓN sigue contando** (§12.7), así que la base no es cero y el calculador
devolvería una fracción de la penalidad: se emitiría un «Adelanto de reserva» sobre una reserva
cancelada. Por eso hay una guarda explícita de `isActiva()`.

#### 🔒 El turno: por qué hay un `GET_LOCK` y no un índice único

`vigentePorImporte()` → `anularVigentes()` → `crear()` es leer-y-escribir, y el disparador corre
en **workers concurrentes** (el del webhook de Beds24 y el del cron de facturas). Sin bloqueo,
los dos pasan sin encontrar enlace y emiten **dos vivos**: un doble cobro.

Se resuelve con el mismo patrón que ya usaba `ProcessInboundIntentDispatchHandler::tomarElTurno()`
—comprobar → bloquear → **volver a comprobar**—, incluido el prefijo con el nombre de la base:
`GET_LOCK` no sabe de bases y su espacio de nombres es el **servidor entero**, así que un clon de
staging en la misma máquina bloquearía a producción para la misma reserva.

⚠️ **El lock vive en la SESIÓN, no en la transacción: no se suelta al hacer commit.** De ahí que
el `finally` no sea opcional — sin él, una excepción dejaría el turno retenido lo que viva el
worker, y con supervisor eso son días. Dos atenuantes que conviene conocer: cerrar el
EntityManager **no** cierra la conexión DBAL, así que soltar funciona igual; y si la conexión se
cae de verdad, MySQL libera el lock al desconectar.

⚠️ **Ante la duda, se RETIRA** — al revés que el turno del agente, donde no contestar es peor que
contestar dos veces. Aquí es al contrario: emitir dos enlaces es un doble cobro, y no emitir no
se pierde, porque cualquier movimiento posterior de esa reserva vuelve a pasar por el mismo
`postFlush`. Por eso un `GET_LOCK` que devuelve `NULL` (error interno, sin lanzar) también
significa retirarse.

**Timeout de 3 segundos.** `crear()` es un persist y un flush, milisegundos: esperar ese poco casi
siempre gana el turno, y es preferible a retirarse dejando el enlace con el importe viejo hasta
el siguiente movimiento.

Se descartaron: **`symfony/lock`** —no está instalado, y la decisión de no meterlo ya está escrita
en `ProcessInboundIntentDispatchHandler`; su `DoctrineDbalStore` usa `GET_LOCK` por debajo—;
**`SELECT … FOR UPDATE`** —en `postFlush` no hay transacción abierta ni fila que bloquear, el
enlace aún no existe—; y el **índice único sobre columna generada**, porque «vigente» depende de
`NOW()` (la caducidad) y no es expresable como columna determinista, y además rompería los
enlaces manuales que sí pueden convivir.

Verificado con `var/probar-prepago-automatico.php` (transacción con rollback): estrena un enlace
sin caducidad, sin autor y **en la moneda de la cabecera**; un movimiento que no cambia el importe
**no** emite otro; al cambiar el adelanto queda uno vivo con el anterior en `anulado`; al anular
la reserva no queda ninguno vivo; y **con el turno tomado desde otra conexión, el emisor se
retira en vez de emitir** —lo único que no se puede comprobar con una sola conexión, porque
`GET_LOCK` es reentrante para la sesión que ya lo tiene—.

### La reutilización, y por qué mira el importe

`emitir()` devuelve un enlace **vigente por el mismo importe** en vez de emitir otro. Sin eso,
«mándame el link» + «no me llegó» dejan dos enlaces vivos por el mismo dinero, y el huésped que
pague los dos paga el adelanto dos veces.

Se compara por `montoNeto` porque **la fila no guarda para qué se emitió**, y darle una columna
de tipo sería meter vocabulario del PMS en una entidad transversal a propósito (§2). Se acepta
lo que eso implica: si el prepago cambia, el importe deja de coincidir y se emite uno nuevo
—correcto—; y un enlace manual del operador por ese mismo importe se reaprovecha —también
correcto, es el mismo cobro—.

`estaVigente()` y no `estado === PENDIENTE`: un FALLIDO se reintenta con otra tarjeta en la
misma URL (§5), y emitir uno por cada rechazo llenaría la reserva de enlaces muertos.

### La skill genera, pero NO envía

El encargo decía «genera y envía». Envía `enviar_mensaje_huesped`, como todo lo demás. Las dos
razones ya estaban escritas en `docs/Mensajeria.md` §11:

1. **El texto lo compone el modelo.** Es la decisión que evitó un `enviar_estado_de_cuenta`.
2. **El envío ya tiene su puerta**: borrador → «¿lo mando?» → sale. Meter el envío dentro de la
   skill sacaría un enlace de cobro hacia un huésped real sin pasar por esa confirmación, y con
   el autorespondedor encendido, sin que lo viera nadie.

### Dónde vive la sección: acordeón propio, y sin `readOnly` (28/08/2026)

`ReservaEnlacesPagoSection.vue` estuvo colgada **al final del acordeón "Pagos"** del panel
financiero, con el argumento de que para el operador «es otra forma de que entre dinero». El
argumento es cierto y la ubicación era mala:

- Quedaba **debajo** de la lista de cobros, del bloque del depósito del canal y del formulario
  de alta. En un móvil eso es recorrer el acordeón entero para llegar a lo que se venía a hacer.
- Y no son la misma cosa. **"Pagos" es lo que YA entró** —contabilidad, cuadra con el saldo—;
  **un enlace es lo que se ha PEDIDO**, y puede no entrar nunca. Mezclarlos hacía leer un enlace
  pendiente como si fuera dinero.

Ahora es el **tercer acordeón**, hermano de Cargos y Pagos, con su propio color (violeta) y una
pastilla **«N por cobrar»** en la cabecera cuando hay enlaces vigentes. Esa pastilla es lo único
del listado que cambia la siguiente acción del operador: quien va a anotar un pago a mano
necesita saber que hay un enlace vivo **antes** de abrir nada.

> El cuerpo va con `v-show`, no con `v-if`, igual que los otros dos: el componente pide sus
> enlaces al montarse, y con `v-if` la cabecera no sabría cuántos hay hasta que alguien la
> abriera — que es justo lo que se quiere evitar.

⚠️ **El componente ya no acepta `readOnly`**, y la eliminación es deliberada. Lo tenía y
escondía emitir y anular cuando el drawer estaba en modo «Ver Estancia». Era un error de
encuadre: **el modo Ver protege los DATOS de la reserva** —fechas, unidad, titular, cargos—, y
un enlace de pago no es un dato de la reserva, es una **gestión de cobro** sobre ella. El caso
normal es exactamente ése: se abre la ficha para mirar mientras el huésped escribe pidiendo
pagar. Obligar a pulsar «Editar» —que además desbloquea todo lo demás— para mandar un enlace es
pedir más permiso del necesario para hacer menos daño.

Anular tampoco destruye nada: el enlace queda en estado `anulado` con su historia entera a la
vista (§5). Lo único irreversible es **cobrar**, y eso lo hace el cliente, no el operador.

Y no abre ningún agujero, porque **`readOnly` nunca fue un permiso**: lo pone
`ReservasView::abrirEdicion()` según cómo se entró a la ficha (mirar vs. editar), y quien
manda de verdad son los `#[IsGranted(Roles::RESERVAS_WRITE)]` de `FinEnlacePagoApiController`.
Un usuario sin ese rol seguía sin poder emitir con el botón visible, y sigue sin poder ahora.
Confundir un modo de interfaz con un control de acceso es lo que hacía la versión anterior.

El botón de anular pasó de un icono suelto a **«🚫 Anular» con su palabra**: un 🚫 pegado a
«Copiar» se leía como «no se puede copiar». Y la URL ocupa ahora su propio renglón, con las
acciones debajo — en un móvil los tres en línea dejaban el campo en dos centímetros, que es
precisamente el que hay que poder seleccionar a mano cuando el portapapeles está bloqueado.

### Los atajos del panel

`ReservaEnlacesPagoSection.vue` ofrece los dos cobros que se piden de verdad, sin teclear ni
calcular de cabeza. **Cuáles aparecen depende de si el adelanto sigue pendiente:**

| Estado | Atajos |
|---|---|
| Adelanto pendiente | **«Primera noche» + «Total»** |
| Adelanto ya cobrado | **«Saldo»**, y sólo ése |

Los dos primeros conviven porque los dos son legítimos: la política pide el adelanto, pero hay
huéspedes que prefieren dejarlo pagado entero de una vez. El segundo se llama «Total» sólo
mientras no se ha cobrado nada — después, lo que queda es el **saldo**, y llamarlo total
mentiría porque el total de la reserva incluye lo ya pagado.

Que «Primera noche» desaparezca no hay que programarlo: el panel recibe `prepagoPendiente` de
`pendiente()`, que devuelve `null` en cuanto hay un pago (§8). El backend además lo impediría.

⚠️ **El atajo NO emite: prellena el formulario y lo abre.** Vigencia, recargo y concepto siguen
a la vista y el operador confirma con «Generar enlace». Es un cobro; ahorrar el tecleo no es
razón para quitar la última mirada.

⚠️ **La etiqueta sale del enum, no de un `Record` en TypeScript.** `PmsPoliticaPrepago` expone
`etiquetaCorta()` («Primera noche», «50 %») para el botón y `etiqueta()` («Primera noche (solo
alojamiento)») para su `title`. Copiada en el front, el botón seguiría diciendo «Primera noche»
el día que alguien pase el establecimiento a `mitad_total` — en un botón que emite un cobro.

El **concepto** del atajo de adelanto lo redacta `PmsPrepagoEnlaceService::concepto()`, la misma
que usa la skill del agente: si cada camino redactara el suyo, el mismo cobro se llamaría de dos
maneras en el extracto del huésped según quién lo emitiera.

Los atajos **no están detrás de `FINANZAS_ENLACES_PREPAGO`**, y es deliberado: el flag apaga el
camino automático —el enlace que sale solo—, no al operador. Aquí hay alguien mirando, igual que
cuando teclea el importe a mano.

### La app del pax enseña, no emite

`PmsReservaPaxProvider` manda `enlacesPago` con los **vigentes**, y la vista pinta un botón por
cada uno. Nunca emite: esta vista se abre con el localizador, y crear un cobro desde ahí sería
un write que dispara cualquiera que tenga el enlace de la reserva.

Tres detalles que no son cosméticos:

- **Viaja el token.** Es la credencial de la página de pago, y aquí es correcto: el endpoint ya
  está acotado al localizador, la misma llave con la que el huésped ve su reserva entera. Quien
  lee esto ya podía ver el saldo; lo único que suma el token es poder **pagarlo**.
- **El botón enseña el TOTAL, no el neto**, y dice el recargo: es lo que aparecerá en el
  extracto de la tarjeta (§6).
- **El importe NO pasa por el conmutador a soles.** El enlace cobra lo que dice su fila, en su
  moneda. Un botón que pone «S/ 137.20» y carga US$ 40.50 es la reclamación garantizada.

En `soloProgreso` no se manda ninguno: esa reserva no enseña un solo importe a propósito (el
canal ya cobró) y un botón de pagar ahí es pedir el dinero dos veces.

### Conciliación: no había nada que decidir

La duda era si un enlace pagado debía crear un `PmsPagoFinanciero` o sólo marcarse. **No es una
elección**: `confirmarPago()` llama a `registrarCobro()` y no hay camino que confirme un enlace
sin generar el movimiento (§3). El saldo no puede descuadrar por aquí.

Verificado en local de punta a punta, simulando el cierre de la pasarela sobre un enlace de
adelanto de 110.00 PEN:

| Antes | Después |
|---|---|
| `total_pagos` 0.00, saldo 220.00 | `total_pagos` 110.00, saldo 110.00 |
| enlace PENDIENTE | enlace PAGADO, `movimientoGeneradoId` apuntando al pago |
| `prepago_pendiente` en `consultar_cuenta` | ya no aparece |
| `generar_enlace_prepago` emitía | responde «no tiene prepago pendiente» |

El pago se creó por el **neto** (110.00, no los 116.05 de la tarjeta) con `comisionPorcentaje`
5.50, que es la regla de §6 y la que hace que el saldo cuadre.

Que el prepago desaparezca solo no es un añadido: `pendiente()` devuelve `null` en cuanto hay
un pago registrado, así que el mismo hecho apaga la cifra en el pax, en el panel y en el agente
sin que nadie los sincronice.

---

### 11 bis.2 La ficha de un cobro

La tabla de **Cobros** contesta «qué pasó con mis enlaces». No contestaba «qué es exactamente
**este** cobro», y en un cobro **manual** eso dolía: el correo, el teléfono, la referencia libre
y las notas se tecleaban al crearlo, se guardaban… y no se volvían a ver nunca.

Ahora la fila abre una ficha lateral con cuatro bloques: el **documento de origen**, el
**cliente**, **el cobro** (módulo, pasarela, emisión, quién lo emitió, caducidad, notas) y **el
pago**, este último sólo si lo hubo — fecha, medio, código de autorización y `ordenId`, que son
los cuatro que se cotejan contra el extracto de la pasarela.

**Panel lateral y no fila desplegable**, porque en un móvil la tabla ya se corta: lo que queda
fuera de pantalla es precisamente Concepto, Documento e Importe.

#### Por qué el origen va en su propio endpoint

`GET /finanzas/caja/cobros/{id}` devuelve `{cobro, origen}`. El `origen` no viaja en el listado
y es deliberado: resolverlo es **preguntarle a su dominio**
(`FinOrigenCobroRegistry::resolver()`), o sea al menos una consulta por fila. En un listado de
hasta 500 serían 500 consultas para pintar algo que casi nunca se mira. Se paga una vez, y sólo
al abrir la ficha.

Qué trae según de dónde venga el cobro:

| Origen | `origen` | Por qué |
|---|---|---|
| **Manual** | `null` | No hay documento. Lo que se tecleó al crearlo ES todo lo que hay, y ya viaja en el propio enlace |
| **PMS / Cotizaciones** | descripción, referencia y **saldo pendiente HOY** | Es lo que no se puede deducir mirando el enlace |

⚠️ El **saldo pendiente es el de hoy**, no el del momento en que se emitió el enlace. Es el dato
por el que se abre esta ficha después de cobrar: saber si aquel cobro dejó el documento saldado.

Si el documento se borró o su módulo no lo reconoce, `origen` sale `null` y se responde **200
igualmente**: el cobro existió y hay que poder verlo. La ficha lo dice en vez de dejar el hueco.

---

### 11 bis.3 Qué medios se ofrecen: **a quién** y **cuándo**

El catálogo (`FinMedioCobroRepository::ofrecibles()`) filtra por dos ejes, y son dos porque
responden a preguntas distintas:

| Eje | Campo | Lo decide |
|---|---|---|
| **A quién** | `audiencia` (todos / peru / internacional) | `PmsProcedenciaHuesped::pagaDesdePeru()` |
| **Cuándo** | `diasMinimos` | Los días que faltan para la llegada |

`diasMinimos` existe por **Western Union**, que hoy vale **2**: se ofrece mientras falten dos
días o más y desaparece el día antes y el mismo día. Ofrecérselo a alguien que llega mañana es
darle una salida que no existe — y es el peor tipo de respuesta, la que parece útil y se
descubre inútil cuando ya no queda tiempo.

Resultado, medido sobre los medios reales:

```
internacional · faltan 5 días  →  western_union, efectivo
internacional · falta 1 día    →  efectivo              ← WU se retira solo
peruano       · el mismo día   →  yape, plin, transferencias, efectivo
```

⚠️ **`null` en cualquiera de los dos ejes = no se esconde nada.** Sin saber de dónde paga, o sin
fecha de llegada (una cotización a medio hacer), pasan todos: esconder una opción por no saber
es peor que ofrecerla de más.

⚠️ **Se compara por FECHA, no por hora.** «El día de la llegada» es un día entero: a las nueve
de la mañana el huésped ya está de camino. Con la hora, un medio aparecería y desaparecería a lo
largo del mismo día, que es imposible de explicar a nadie.

**Y el filtro vive en el catálogo, no en las skills**, porque lo consultan tres sitios —el
agente (`ConsultarMediosPagoSkill`), la app del huésped (`PmsGuiaHuespedProvider`) y las
cotizaciones—. Puesto abajo, los tres se corrigen a la vez; puesto arriba, habría tres reglas
que un día dirán cosas distintas.

---

### 11 ter. El aviso de cobro al equipo

Hasta ahora **un cobro no avisaba a nadie**: se enteraba quien mirase el panel de la reserva —el
saldo baja solo— o la caja. Mientras cobraba un operador desde el panel daba igual, porque ya
estaba delante. Deja de dar igual con los enlaces de prepago (§11 bis): el huésped paga por su
cuenta, a cualquier hora, y nadie se entera hasta que alguien abre la reserva.

`FinAvisoDeCobro` redacta el aviso y se lo pasa a `AvisoAlEquipoService`, el mismo que usa el
escalado del agente (ver `docs/Mensajeria.md`, *El mecanismo de avisar vive fuera*). Aquí sólo se
decide **qué se dice**; a quién y por dónde ya estaba resuelto.

**Se engancha en `FinEnlacePagoService::confirmarPago()`**, y ese sitio no es casual: por ese
embudo pasan los TRES caminos de cobro —el navegador del cliente y los webhooks de las dos
pasarelas—, así que enganchar ahí cubre todos sin repetir código. Y como la guarda de
idempotencia devuelve antes cuando el enlace ya estaba pagado, **un IPN repetido no vuelve a
hacer sonar los teléfonos**.

⚠️ **Va DESPUÉS del flush, y no lanza nunca.** Las dos cosas por el mismo motivo: el cliente ya
pagó. Avisar antes de persistir contaría un pago que aún podría no guardarse, y dejar que el
aviso propague una excepción convertiría un problema de mensajería —Meta caída, plantilla sin
aprobar, un móvil mal escrito— en un cobro reventado. `notificar()` se traga el error y lo deja
en el log.

| Dónde cae | Qué sale |
|---|---|
| **Dentro** de la ventana de 24 h | Texto libre, multilínea: cliente, importe, concepto, origen con su referencia, medio de pago y enlace a Finanzas |
| **Fuera** | Plantilla `aviso_cobro_interno` con `cliente`, `importe` y `concepto` (el origen entra DENTRO de `concepto`, para no pedirle a Meta un parámetro más) |

**No deduplica, y es deliberado.** Cada cobro es un hecho único, al contrario que el escalado
—donde el mismo huésped insistiendo tres veces son tres avisos por lo mismo, y por eso allí hay
enfriamiento—.

Verificado con `var/probar-aviso-cobro.php`, que compone el aviso de tres casos (reserva, venta
suelta sin origen, y sin nombre de cliente) y comprueba las dos reglas que Meta impone y que
revientan el envío: **ninguna variable vacía ni multilínea**. ⚠️ Ese guion **no envía nada** a
propósito: hacerlo haría sonar el móvil de toda la guardia (ver `docs/Mensajeria.md` §16.7).

---

## 11 quater. Devoluciones: deshacer un cobro que ya pasó (28/08/2026)

Un cobro por pasarela **no se borra** (§ *La relación pago ↔ enlace*). Lo que sí se puede es
devolver el dinero, y desde el 28/08/2026 se hace **desde el sistema**, no sólo en el
Backoffice de Culqi.

### Cuánto se devuelve: el NETO

Es la decisión que lo ordena todo, y sale de que **el recargo se cobra anunciado y aparte**:
el botón del huésped dice «Incluye 5.5% de comisión» antes de que pulse.

| | Importe |
|---|---|
| El huésped pagó a su tarjeta | `montoTotal` (neto + 5.5%) |
| A la reserva se le abonó | `montoNeto` |
| Culqi se quedó | el recargo |

Se devuelve **lo que el documento recibió**: el neto. El huésped asume el coste de haber
pagado con tarjeta, que aceptó al pulsar; la casa no pone nada; y el asiento devuelve
exactamente lo que entró.

⚠️ Y coincide con lo que hace la pasarela: la documentación de Culqi dice que **pasada la
fecha de la venta la devolución descuenta su comisión**, o sea que no la reintegra. Devolver
el total obligaría a poner esa diferencia de nuestro bolsillo, por una operación que además
ya no existe.

> Sin esa transparencia previa habría que discutir quién come la comisión. Con ella, no hay
> discusión — y por eso la nota del 5.5% en el botón del pax no es cosmética: es lo que hace
> barata esta decisión.

### El orden, que ES la garantía

```
1. La pasarela devuelve         ← si falla aquí, no se ha escrito NADA
2. El enlace pasa a REEMBOLSADO
3. El módulo dueño deshace su asiento
4. Un solo flush cierra 2 y 3
```

**Primero el dinero, después el registro.** Un estado que se adelanta a la pasarela es una
devolución anotada que puede no haber ocurrido: el saldo diría que le debemos algo a alguien
que sigue teniendo su dinero. Si Culqi rechaza, la ficha se queda exactamente como estaba.

**2 y 3 comparten flush** por lo contrario: un enlace reembolsado con el cobro todavía vivo
haría mentir al saldo en la otra dirección.

### `REEMBOLSADO`: del PAGADO se sale hacia adelante

Estado nuevo en `FinEnlacePagoEstado`, **final** para `esFinal()`/`estaVigente()`. No se
vuelve a `PENDIENTE` ni se borra nada: el cobro ocurrió, y lo que hubo después fue **otro
hecho**, no la cancelación del primero.

La respuesta cruda de Culqi se guarda **junto** a la del cobro, no encima:
`{cobro: …, devolucion: …}`. La del cobro es la que se cotejará contra el extracto el día que
alguien discuta la operación; perderla para guardar la nueva sería cambiar la prueba por el
recibo.

### Qué ve el PMS: el cobro a CERO con una nota

`PmsReservaOrigenCobroResolver::registrarDevolucion()` pone el `PmsPagoFinanciero` a `0.00`,
le anula la comisión y le **añade** una nota con fecha, importe, pasarela y el motivo que
escribió el operador.

Se eligió esto frente a un **contra-cargo de devolución**, que devolvía el mismo saldo:

- Un contra-cargo mete una línea **positiva** en el desglose del huésped —parece que se le
  cobra algo más— y hay que explicarla.
- El cobro a cero no aparece donde estorba, y el saldo sube solo.

⚠️ **No se borra la fila**: el cobro existió, y borrarlo dejaría el enlace apuntando a una
fila inexistente. Es la regla del módulo — no se borra, se marca — y además el veto de
`getMotivoNoBorrable()` lo impediría.

⚠️ **El importe se cambia por el ORM, nunca por SQL.** El listener de coherencia se engancha
al flush y es quien recalcula `total_pagos` y el saldo de la cabecera. Un `UPDATE` directo
dejaría la fila a cero y los totales diciendo lo de antes.

### Dónde vive la acción: SÓLO en `/finanzas`

El botón vive en el panel general (pestaña **Cobros**), en la fila y en la ficha, y **sólo
sobre un cobro `pagado`**. No convive nunca con copiar/anular, que son de los vigentes: por
eso la ficha tiene **dos pies distintos** y no uno con ramas — no comparten ni una acción.

Y **no** está en el panel de la reserva. Allí esto se ve —el enlace en «Reembolsado», el cobro
en cero con su nota— pero no se decide.

⚠️ **Se confirma con `prompt`, no con `confirm`, y el motivo es obligatorio.** Acaba en la nota
del cobro del PMS y es lo único que explica, meses después, por qué esa reserva tiene un pago
en cero. Un `confirm` no puede pedirlo y encadenar dos diálogos es peor; el `prompt` hace las
dos cosas de una — cancelar aborta, y el texto es el dato.

> El **backend sí acepta motivo vacío** (lo pone como «no indicado»): no se le cierra la puerta
> al agente ni a una llamada futura. La exigencia es de la pantalla, donde hay una persona
> delante y un reembolso sin motivo es un agujero que nadie va a poder rellenar después.

Ámbar y no rojo en los dos sitios: devolver no es deshacer un error ni una acción destructiva,
es una operación legítima que mueve dinero. El aviso de que se devuelve el **neto** va debajo
del botón además de en el diálogo, porque es la pregunta que el operador se hace **antes** de
pulsar.

⚠️ **El filtro de estados dejó de escribirse a mano.** Era una copia de `FinEnlacePagoEstado`
en TypeScript, y al añadir `reembolsado` el desplegable se quedó corto **sin que nada
fallara** — el estado existía, se guardaba, y no se podía filtrar por él. Ahora sale de
`estadosCobro`, que el backend manda con el listado desde el enum de PHP. Mismo criterio que
el catálogo de medios.

Devolver dinero es una operación de **caja**, no de recepción, y el sitio donde vive una
acción es parte de quién puede hacerla. Es la misma razón por la que emitir un enlace sí está
en la reserva: pedir un cobro es gestión del alojamiento; deshacerlo, no.

### `reason` de Culqi es un ENUM cerrado

`duplicado`, `fraudulento`, `solicitud_comprador`. **No es texto libre** — el README de la
librería PHP de Culqi pone una frase de ejemplo (`"bought an incorrect product"`) y engaña.

Se manda siempre `solicitud_comprador`: quien devuelve aquí es el operador atendiendo a un
cliente que lo pidió. Los otros dos son para casos que no pasan por este panel — un cobro
duplicado se resuelve en el Backoffice, y un fraude no lo declara una recepción.

El motivo **de verdad**, el que escribe el operador, viaja a la nota del asiento del módulo,
no a la pasarela.

### Por qué `reembolsar()` está en el contrato común

Se planteó como capacidad opcional (una interfaz aparte que sólo implementara Culqi) para que
Izipay —parada— no tuviera que escribir nada. **Se revirtió**: un contrato no se afloja por el
estado de una implementación. Declararlo opcional escondía el hueco de Izipay detrás de un
`instanceof` que nadie mira.

Así que `reembolsar()` vive en `FinPasarelaClientInterface` e `IzipayClient` lo implementa
**lanzando**, con un docblock que explica por qué está vacío. El hueco tiene nombre y sale en
el editor; el día que Izipay se habilite, la lista de lo que falta es la lista de métodos que
lanzan.

⚠️ La regla que sale de ahí, y que decide qué entra en esa interfaz corta: **se deja fuera lo
que a una pasarela no le APLICA; no lo que simplemente no está ESCRITO todavía.**
`cobrarConToken()` no está porque el flujo de Izipay no tiene ese paso —pedírselo sería
inventarle un concepto—; `reembolsar()` sí, porque devolver dinero lo hace cualquier pasarela
de tarjeta.

⚠️ Y un stub de dinero **lanza, nunca devuelve un array vacío**. Con `[]`, el servicio daría
la devolución por buena, marcaría el enlace y pondría el cobro a cero sobre dinero que sigue
en la cuenta del cliente. Nadie lo descubre hasta que el cliente reclama.

### ⚠️ El estado de pago de la estancia NO baja solo

`PmsEstadoPagoEventosService` es deliberadamente **de una sola dirección**: registrar un pago
confirma, quitarlo no des-confirma (§12.9). Tras una devolución el cobro queda en cero y el
recálculo corre, pero la estancia **conserva** su `pago-total` / `pago-parcial`.

Eso no es cosmético: esos estados están en `ESTADOS_PAGO_CONFIABLES` y son los que **abren los
códigos de acceso de la guía**. Un huésped al que se le devolvió el dinero conserva la entrada
hasta que alguien toque la estancia.

Si la devolución acompaña a una cancelación —el caso normal— el operador cancela la estancia y
el problema no existe. Si la reserva sigue viva, **hay que bajarle el estado de pago a mano**.

No se automatiza porque la asimetría es vieja y deliberada, y degradar en automático tiene sus
propios riesgos; pero este flujo la dispara sin avisar, así que queda dicho aquí y en la nota
del asiento.

### Lo que sigue sin resolverse

- **Devoluciones parciales.** Culqi las admite y el contrato también (el importe va como
  parámetro), pero el flujo devuelve el neto entero y pone el cobro a cero. Una parcial
  exigiría dejar el pago en `neto − devuelto`, y no hay caso todavía.
- **Un cobro MANUAL no imputa nada**: `registrarDevolucion()` no tiene módulo al que avisar,
  igual que `registrarCobro()`. El enlace queda `REEMBOLSADO` y ahí acaba, que es correcto —
  no había documento al que abonarle el dinero en primer lugar.

---

## 12. Despliegue: por qué no basta con `git pull`

Dos pasos que **no se hacen solos** y cuyos fallos no se parecen a su causa. Los dos
mordieron en el primer despliegue de este módulo.

### 1. Volcar el entorno

En producción manda `.env.local.php`, el archivo compilado por `composer dump-env prod`.
**No se regenera con `git pull`**: una variable nueva del `.env` del repo sencillamente no
existe para la aplicación.

```bash
composer dump-env prod && php bin/console cache:clear
```

Lo peligroso es el alcance del fallo. La excepción es
`EnvNotFoundException: Environment variable not found: "CULQI_ENDPOINT"`, y no revienta sólo
donde se usa esa variable: revienta en **cualquier punto que resuelva parámetros**. En el
primer despliegue tumbó `CalendarConfigResolver` y con él el **calendario entero**, que no
tiene nada que ver con cobros. Si tras desplegar falla algo aparentemente inconexo, mira
esto antes que nada.

### 2. Reconstruir las DOS apps

`public/app_pax/` y `public/app_util/` están en `.gitignore`: se construyen en el servidor.

```bash
cd pax  && npm run build
cd util && npm run build
```

Tocar un endpoint público **obliga a reconstruir la SPA que lo consume**. Al cambiar
`/form-token` por `/configuracion` se desplegó el backend nuevo contra un `pax` compilado
que seguía pidiendo el endpoint viejo; el cliente veía "No pudimos preparar el pago" y el
log de la aplicación estaba **limpio**, porque el error ocurría en el navegador.

La pista buena estuvo en el log de nginx: se veían los `GET` del navegador y **ningún POST**.
Cuando algo "no hace nada" en la página de pago, mirar qué peticiones llegan de verdad
distingue en un minuto entre un frontend viejo, una pasarela que rechaza y un backend caído.

---

## 13. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Tocar el botón de devolver (fila o ficha) | `util/src/views/Finanzas/FinanzasView.vue` | `devolverCobro()` — el motivo es obligatorio aquí, no en el backend |
| Añadir un estado de cobro al filtro | **nada en el front** | sale de `FinEnlacePagoEstado::opciones()`; el desplegable lo lee de `estadosCobro` |
| Cambiar cuánto se devuelve al reembolsar (§11 quater) | `src/Finanzas/Service/Culqi/CulqiClient.php` | `reembolsar()` — hoy `montoNetoCentimos()`, **no** el total |
| Cambiar el `reason` que se manda a Culqi | `src/Finanzas/Service/Culqi/CulqiClient.php` | `MOTIVO_POR_DEFECTO` — enum cerrado de tres valores |
| Cambiar qué hace el PMS al recibir una devolución | `src/Pms/Finanzas/PmsReservaOrigenCobroResolver.php` | `registrarDevolucion()` — pago a 0 + nota, por ORM |
| Añadir devoluciones a otro módulo que cobre | el resolver de ese módulo | implementar `registrarDevolucion()` del contrato |
| Cambiar el % de recargo de tarjeta | `config/services/services_finanzas.yaml` **y** `src/Pms/Enum/PmsMedioPago.php` | `finanzas.recargo_tarjeta_porcentaje` / `comisionPorcentaje()` |
| Cambiar la vigencia por defecto | `src/Finanzas/Service/FinEnlacePagoService.php` | `VIGENCIA_DIAS_DEFECTO` |
| Cambiar el formato del `orderId` | `src/Finanzas/Service/FinEnlacePagoService.php` | `generarOrdenId()` |
| Cambiar la URL pública del enlace | `src/Finanzas/Service/FinEnlacePagoService.php` | `urlPublica()` (+ ruta en `pax/src/router/index.ts`) |
| Añadir datos al `CreatePayment` (Izipay) | `src/Finanzas/Service/Izipay/IzipayClient.php` | `crearFormToken()` |
| Cambiar qué respuesta cuenta como cobro (Izipay) | `src/Finanzas/Service/Izipay/IzipayClient.php` | `esPagoExitoso()` |
| Añadir datos al cargo (Culqi) | `src/Finanzas/Service/Culqi/CulqiClient.php` | `cobrarConToken()` |
| Cambiar qué cargo se da por bueno (Culqi) | `src/Finanzas/Service/Culqi/CulqiClient.php` | `cargoPagaElEnlace()` |
| Cambiar la pasarela por defecto | `.env` / `.env.local` | `FINANZAS_PASARELA_POR_DEFECTO` |
| Tocar el selector de pasarela del operador | `util/src/components/reservas/ReservaEnlacesPagoSection.vue` | `eligePasarela` |
| Mover la sección de enlaces dentro del panel | `util/src/components/reservas/ReservaFinanzasPanel.vue` | acordeón `seccionAbierta === 'enlaces'` |
| Cambiar el texto de la confirmación al anular (vista global) | `util/src/views/Finanzas/FinanzasView.vue` | `anularCobro()` — nombra importe y concepto a propósito |
| Cambiar qué pasa con la fila al anular | `util/src/stores/finanzas/cajaStore.ts` | `anularCobro()` — **gemelo** de `enlacesPagoStore.anular()` |
| Cambiar quién puede emitir o anular un enlace | el backend (`#[IsGranted]` de `FinEnlacePagoApiController`) | **no** un `readOnly` en el front — ver §11 bis |
| Cambiar qué datos del cobro se ven sin desplegar | `util/src/components/reservas/ReservaEnlacesPagoSection.vue` | bloque `estado === 'pagado'` |
| Tocar la vista de auditoría de la respuesta | `util/src/components/reservas/ReservaEnlacesPagoSection.vue` | `alternarAuditoria()` |
| Extraer un campo nuevo de la respuesta a columna | el cliente de esa pasarela | `comoRespuestaNormalizada()` |
| Cambiar el formulario de una pasarela en `pax` | `pax/src/views/pago/Pago{Izipay,Culqi}Form.vue` | — |
| Añadir una TERCERA pasarela | §11 | `FinPasarelaClientInterface` + `FinPasarelaRegistry` |
| Guardar otro campo de la transacción | `src/Finanzas/Service/FinEnlacePagoService.php` | `confirmarPago()` |
| Cambiar cómo se imputa el pago en el PMS | `src/Pms/Finanzas/PmsReservaOrigenCobroResolver.php` | `registrarCobro()` |
| Cambiar el texto que ve el cliente | `src/Pms/Finanzas/PmsReservaOrigenCobroResolver.php` | `describir()` |
| Cambiar el JSON que consume `util` | `src/Finanzas/Controller/Api/FinEnlacePagoApiController.php` | `serializar()` (+ espejo TS, §7) |
| Cambiar el JSON que consume `pax` | `src/Finanzas/Controller/Publico/FinPagoPublicoController.php` | `ver()` (+ espejo TS, §7) |
| Cambiar el tema visual del formulario | `pax/src/views/pago/PaxPagoView.vue` | `cargarLibreria()` (assets `neon`) |
| Cambiar el sondeo de confirmación | `pax/src/views/pago/PaxPagoView.vue` | `sondearConfirmacion()` |
| Mover o rediseñar el botón del operador | `util/src/components/reservas/ReservaEnlacesPagoSection.vue` | — |
| Cambiar el tope de filas de la vista global | `src/Finanzas/Controller/Api/FinCajaApiController.php` | `LIMITE` |
| Cambiar qué se busca con el texto (cobros) | `src/Finanzas/Repository/FinEnlacePagoRepository.php` | `buscar()` |
| Cambiar qué se busca con el texto (caja) | `src/Pms/Repository/PmsPagoFinancieroRepository.php` | `buscarParaCaja()` |
| Cambiar las columnas de las tablas | `util/src/views/Finanzas/FinanzasView.vue` | — |
| Cambiar el rango de fechas por defecto | `util/src/views/Finanzas/FinanzasView.vue` | `filtros` (arranca en 30 días) |
| Que los pagos de un módulo salgan en la caja | §10, paso 3 | `FinMovimientoProviderInterface` |
| Añadir un módulo que cobre | §10 | `FinOrigenCobroResolverInterface` |
| Depurar "no se confirmó un cobro" | tabla `fin_pasarela_webhook_audit` | `payload_raw`, `estado`, `error_mensaje` |
| Cambiar CUÁNTO se pide de prepago | `src/Pms/Enum/PmsPoliticaPrepago.php` | `fraccion()`, `soloAlojamiento()` |
| Cambiar CUÁNDO deja de pedirse | `src/Pms/Service/Finance/PmsPrepagoCalculador.php` | `pendiente()` (§8) |
| Cambiar CUÁNDO se emite solo el enlace | `PmsInformacionFinancieraCoherenciaListener::postFlush()` | La llamada a `emitirPrepagos()`, al final de la cadena. La decisión de *si procede* sigue en `pendiente()` |
| Cambiar qué dice el aviso de cobro (§11 ter) | `src/Finanzas/Service/Aviso/FinAvisoDeCobro.php` | `redactar()` dentro de ventana, `variables()` fuera. Si añades una variable, tiene que llegar SIEMPRE con valor y en una línea |
| Añadir un dato a la ficha de un cobro (§11 bis.2) | `FinEnlacePagoSerializer::aArray()` **y** `util/src/types/finEnlacePagoModel.ts` | Son espejo: el serializador lo cita. Si el dato es del DOCUMENTO y no del enlace, va en `FinCajaApiController::origenDe()` |
| Cambiar qué sabe el módulo de su documento | el resolver del dominio (`*OrigenCobroResolver::resolver()`) | Devuelve el `FinOrigenCobroDto`. Añadir un campo ahí lo hace visible en la ficha de TODOS los módulos |
| Dejar de avisar de los cobros, o avisar de otra cosa | `FinEnlacePagoService::confirmarPago()` | La llamada a `avisoDeCobro->notificar()`, al final y fuera de la transacción |
| Cambiar cómo lo ve el huésped | `pax/src/views/huesped/PmsReservaView.vue` | bloque «Prepago pendiente» |
| Cambiar cómo lo ve el operador | `util/src/components/reservas/ReservaFinanzasPanel.vue` | fila «Prepago pendiente» del resumen |
| Cambiar qué sabe el agente del prepago | `src/Agent/Skill/Pms/ConsultarCuentaSkill.php` | `prepago()` |
| Encender/apagar los enlaces de prepago | `.env` → `FINANZAS_ENLACES_PREPAGO` | + `composer dump-env prod` (§12.1) |
| Cambiar cuándo se reaprovecha un enlace | `src/Pms/Finanzas/PmsPrepagoEnlaceService.php` | `vigentePorImporte()` (§11 bis) |
| Cambiar el concepto que lee el huésped al pagar | `src/Pms/Finanzas/PmsPrepagoEnlaceService.php` | `concepto()` |
| Cambiar el botón de pagar del pax | `pax/src/views/huesped/PmsReservaView.vue` | bloque «PAGAR ONLINE» |
| Cambiar los atajos de importe del panel | `util/src/components/reservas/ReservaEnlacesPagoSection.vue` | `presets` (§11 bis) |
| Cambiar cómo se llama una política en el botón | `src/Pms/Enum/PmsPoliticaPrepago.php` | `etiquetaCorta()` |

---

## 8. La descripción del cargo que ve el huésped

`pms_cargo_financiero` tiene **dos** descripciones y no son intercambiables:

| Campo | Quién la escribe | Quién la ve |
|---|---|---|
| `descripcion` | `Beds24InvoiceReceivePersister`, con lo que venga del canal | Solo el equipo |
| `descripcion_cliente` | El operador, en español | El huésped |

La primera trae códigos y nombres de tarifa sin normalizar: no es presentable. Sin la
segunda, un cargo de tipo «Otros» le llegaba al huésped como una cifra suelta —un −0.20 de
ajuste de cuadre que nadie sabe interpretar—.

`descripcion_cliente` es `I18nContent[]` con `#[AutoTranslate]`. **Es opcional a propósito**;
la mayoría de los cargos se explican con su tipo y obligar a redactar cada uno sería trabajo
inútil.

> 🐞 **Pero hoy NO se traduce, aunque el atributo esté puesto.** `PmsCargoFinanciero` no usa
> `AutoTranslateControlTrait`, y `AutoTranslationService::processEntity()` **sale por la puerta
> de atrás en su primera línea** cuando la entidad no tiene `getEjecutarTraduccion()`:
>
> ```php
> if (!$execute && method_exists($entity, 'getEjecutarTraduccion')) { … }
> if (!$execute) { return; }   // ← sin el trait, siempre sale por aquí
> ```
>
> Consecuencia: un huésped francés que pregunte «¿por qué me cobráis esto?» recibe la
> explicación en español, por el `?? 'es'` de `descripcionClienteEn()`, y nadie ve un error.
> Lo consume así tanto la pantalla como `consultar_cuenta`.
>
> **Arreglarlo son tres cosas y van juntas:** el trait en la entidad, la columna
> `sobreescribir_traduccion` (migración) y la casilla en su CRUD. Sueltas no sirven: sin el
> trait la propiedad no existe y el campo del formulario reventaría.
>
> No cuesta llamadas de más en la sincronización: `processEntity()` hace `continue` con los
> valores vacíos, y los cargos que llegan de Beds24 no traen descripción de cliente.

Se edita desde `util` (`ReservaFinanzasPanel.vue`) vía el `PATCH` de API Platform, mandando
el accesor plano `descripcionClienteEs`. El CRUD del panel es de **solo lectura** para esta
entidad (`disable(Action::NEW, Action::EDIT)`), así que allí solo se consulta.

La descripción del cliente se ve ahora **también en la fila del cargo** del panel
(`ReservaFinanzasPanel.vue`), entre comillas y en cursiva. Antes sólo se leía abriendo el
formulario de edición, así que de un vistazo no había forma de saber si un cargo llegaba
explicado o como una cifra suelta — que es justo lo que el campo viene a evitar.

Y la consume la skill `consultar_cuenta` del agente, como `explicacion_para_huesped`
(`docs/Mensajeria.md` §11).

### Quién pide el prepago: `calcular()` vs `pendiente()`

`PmsPrepagoCalculador` tiene **dos** entradas y confundirlas cobra de más:

| Método | Responde | Quién la llama |
|---|---|---|
| `calcular()` | «cuánto pide la política» | El estado de cuenta del huésped y `ConsultarCuentaSkill::adelantoDeLaPolitica()` |
| `pendiente()` | «cuánto queda por pedir» | Los tres consumidores |

> 🐞 **El agente se quedaba sin saber cuánto vale una noche.** `ConsultarCuentaSkill` sólo
> llamaba a `pendiente()`, que devuelve `null` en cuanto hay **cualquier** pago registrado. A
> «me piden el pago de la primera noche, ¿cuánto es?», con los 30 ya pagados, el agente
> contestó **150.00** —el alojamiento entero— antes de escalar. La cifra buena estaba calculada
> y la pintaba el estado de cuenta; simplemente no llegaba hasta la skill.
>
> Por eso ahora viaja también `adelanto_de_la_politica`, con `ya_cubierto` para que el modelo
> no tenga que restar. Son dos preguntas distintas —«cuánto vale» y «cuánto falta»— y fundirlas
> en una clave es cobrar de más o dejar sin respuesta.

**«En la app me sale otro monto» encadena con la guía.** La descripción de `consultar_cuenta`
manda al modelo a `consultar_guia` (tema de pagos) cuando el huésped compara con lo que ve en
Booking: la cifra la da la cuenta, pero **el porqué no cuadran es contenido editable** y vive en
`PmsGuiaItem::$agenteContenido` — ver `docs/PmsGuiaHuesped.md`. Sin ese enlace el modelo
improvisaba la explicación, que es la parte que nadie revisa.

`pendiente()` es `calcular()` más una regla: **si hay algún pago registrado, ese pago ES el
prepago** —es lo primero que se cobra— y volver a pedirlo es reclamarle al huésped algo que ya
hizo. La regla vive en el servicio y no en quien la pinta porque la comparten tres sitios:

| Consumidor | Dónde | Forma en que sale |
|---|---|---|
| Estado de cuenta del huésped | `PmsReservaPaxProvider::cifras()` | `prepago` con `claveI18n` (el front la resuelve) |
| Panel financiero | `PmsInformacionFinancieraPorReservaProvider::prepago()` | `prepagoPendiente` con `politicaEtiqueta` |
| Agente | `ConsultarCuentaSkill::prepago()` | `prepago_pendiente` con la etiqueta y una nota |

Se mira `getTotalPagos()` —el agregado de la cabecera, el mismo que el estado de cuenta enseña
como «pagado»— para que la regla y lo que ve el huésped no puedan discrepar.

#### 🔥 El calculador no sabe qué día es: los tres consumidores preguntan antes

`PmsPrepagoCalculador::pendiente()` **no tiene una sola referencia a fechas**. Sabe si queda algo
por adelantar, no si adelantar sigue teniendo sentido. La regla **«desde la mañana del día de
check-in se pide el TOTAL»** vive en `PmsSituacionDeCobroResolver::queSePide()`, y hasta el
30/08/2026 la conocía **sólo la tarjeta del huésped**.

O sea que a un huésped ya alojado, el panel le ofrecía al operador el botón de cobrar la primera
noche y el agente le ofrecía a él pagarla, **mientras su propia tarjeta le pedía el total**. Con
el enlace del pax en manos de quien pregunta por pagos, puede tener las dos cosas delante.

Medido antes de arreglarlo: **ocho reservas** ya llegadas, sin ningún pago y con el adelanto vivo.
La más antigua había llegado veinticinco días antes.

Los tres consumidores **preguntan la decisión** en vez de replicar la regla — dos
implementaciones de «qué se le pide a esta persona» son dos respuestas el día que una cambie, que
es justo lo que había pasado:

| Consumidor | Cómo pregunta |
|---|---|
| Tarjeta del huésped | Es el propio `PmsSituacionDeCobroResolver` |
| Panel financiero | `queSePide !== ADELANTO` → `prepagoPendiente` viaja `null` |
| Agente | ídem, y además manda `que_se_pide` resuelto para que el modelo no lo deduzca |

En el panel, que `prepagoPendiente` llegue `null` no hay que programarlo en ninguna vista: el
bloque y el atajo de «Primera noche» de los enlaces de pago van los dos tras un `v-if`, así que
desaparecen solos y queda «Saldo», que es lo correcto para quien lleva tres semanas alojado.

#### ⚠️ Y por eso `recargar()` tiene que volver por `por-reserva`

`finanzasStore.recargar()` —la que llaman los ocho `create`/`patch`/`delete` del panel— releía
por el **`GET` por id**, que no rellena `prepagoPendiente` ni `costosTeoricos`. Resultado: **cada
escritura los borraba del panel**.

Con el prepago costaba verlo, porque tras cobrarlo TIENE que desaparecer. Se notaba al registrar
un pago **parcial** o al tocar un cargo: el bloque se iba igual sin haber cobrado el adelanto.

Arreglado el 30/08/2026 recordando el `reservaId` con el que se cargó. No se reutiliza
`fetchPorReserva()` porque aquélla levanta `isLoading` y el panel pinta un esqueleto con esa
bandera: un parpadeo en cada guardado sería peor que el fallo que esto arregla.

⚠️ **La `claveI18n` sólo sirve en `pax`.** Es una clave del diccionario `pax_ui_i18n` que se
resuelve en el navegador del huésped. En el panel y en el agente se sustituye por
`PmsPoliticaPrepago::etiqueta()`: así la etiqueta mantiene una sola fuente (el enum) en vez de
acabar copiada en un `Record` de TypeScript o leída en voz alta por el modelo.

#### El prepago del panel no es una columna

`PmsInformacionFinanciera::$prepagoPendiente` es una propiedad **transitoria**: la inyecta el
provider de `por-reserva`, igual que `PmsReservaPaxProvider` hace con el resumen del huésped.
No puede vivir dentro de la entidad porque depende de la política del establecimiento virtual
y de las noches de la reserva, y ese cálculo es un servicio.

Dos consecuencias:

1. **Sólo la rellena `por-reserva`.** En un `GET` por id o en la colección llega `null`, y eso
   no significa «no hay prepago» sino «ahí nadie lo ha calculado».
2. **La forma se declara a mano** con `#[ApiProperty(openapiContext: …)]`. De un `?array` API
   Platform deduce `string[]`, y el tipo que `openapi-typescript` genera para `util` sale
   inservible (`.monto` no existe en un array de cadenas). Con el `openapiContext` el espejo
   TS se deriva del esquema como el resto y no hay que escribirlo a mano.

En el panel se pinta **en la moneda de la cabecera**, aunque el conmutador de vista dual esté
en la otra: el prepago es la cifra que se le pide al huésped, y convertirla al vuelo inventaría
una tercera cantidad que nadie le ha dicho.

### El estado de cuenta del huésped pasó a ser línea a línea

`PmsReservaPaxProvider` manda ahora `lineas` además de `cargos`. `cargos` sigue siendo el
desglose agrupado por tipo; `lineas` es el detalle, con la descripción de cada cargo.

⚠️ **`PmsInformacionFinanciera::getLineasCliente()` y `getDesglosePorTipo()` aplican las
MISMAS cuatro reglas** (anulación §12.7, `esCargo()`, `totalLinea ?? monto`, conversión a la
moneda de la cabecera). Tocar una en uno obliga a tocarla en el otro; por eso viven las dos
en la entidad y no en quien las pinta.

### El importe en soles es REFERENCIAL

`referenciaSoles()` manda **un solo** tipo de cambio —el del día, vía `TipoCambioDelDia`—
para toda la tarjeta, no el que cada cargo tiene congelado. Con los TC históricos las líneas
no sumarían el total convertido, y ese descuadre es justo la conversación que la tarjeta
quiere evitar.

Por eso **la pantalla tiene que decir que es referencial**: no es lo que se cobró ni lo que se
va a cobrar. El cobro real sigue siendo en la moneda de la cabecera. Si no hay TC del día, o
la cabecera ya está en soles, no se manda nada y el conmutador no se pinta.

#### Dónde vive el conmutador, y por qué el aviso está fuera del plegable

En `PmsReservaView.vue` el conmutador es un **segmentado** (`$` ⇄ `S/.`, la moneda activa en
color) y vive en la **cabecera de la tarjeta**, no dentro del desglose. El desglose arranca
plegado, y el badge de saldo —que sí se ve plegado— también cambia de moneda: el mando tiene
que estar accesible en los dos estados.

⚠️ Esa mudanza arrastra el aviso de «referencial»: **también tiene que estar fuera del
plegable**. Si el aviso se queda dentro, se puede pasar a soles sin desplegar nada y el badge
enseña una cifra en soles sin decir que no es la que se cobra —justo lo que esta sección
prohíbe—. Van juntos: si alguna vez se mueve uno, se mueve el otro.

Dos condiciones, no una: el conmutador se pinta con `hayReferencia && !soloProgreso`. El
`referenciaSoles()` del provider se añade al margen de que haya cifras (va en `$base`, no en
`cifras()`), así que en una reserva de Airbnb sin extras llega TC pero no hay nada que
convertir. Antes esto lo daba gratis el bloque plegable, que ya iba dentro de `!soloProgreso`.


---

## 14. El catálogo de medios de cobro (`FinMedioCobro`)

### La ventana de días: `dias_minimos` y `dias_maximos`

Dos columnas que juntas dicen **cuándo** se puede ofrecer un medio, y la mayoría no usa
ninguna de las dos:

| Medio | `min` | `max` | Por qué |
|---|---|---|---|
| Western Union | 2 | — | Tarda. Ofrecérselo a quien llega mañana es ofrecer algo inútil |
| Efectivo | — | **0** | No se paga en mano desde otro sitio |
| El resto | — | — | Sirven en cualquier momento |

⚠️ **El efectivo NO se resolvió con la audiencia, y la distinción importa.**
`FinAudienciaCobro` separa Perú de fuera, y la tentación era marcarlo «sólo Perú». Está mal:
**un peruano en Puno tampoco puede pagar efectivo en Cusco**. Lo que decide no es de dónde es
la persona sino **dónde está**, y eso lo aproxima el calendario, no el país.

Los días van **con signo** y son negativos una vez dentro de la estancia, así que `max 0`
deja pasar el día de la llegada y todos los siguientes — que es justo lo que se quiere para
el saldo que se paga en el alojamiento.

`llegaATiempo(null)` sigue dejando pasar todo: sin fechas —una cotización, un chat sin
reserva— esconder una opción por no saber es peor que ofrecerla de más.


Todo lo de arriba trata de cobrar **por pasarela**: una URL, una tarjeta, un webhook. Este
apartado es lo contrario y por eso vive aparte: **las vías por las que el cliente nos manda el
dinero él mismo** —un Yape, una transferencia, un giro— y de las que sólo tenemos que darle el
destino correcto.

### 14.1 Qué es y por qué está en `Finanzas`

Una tabla plana, `fin_medio_cobro`, con una fila por cuenta: tipo, titular, número, banco, CCI,
moneda, a quién se le ofrece y una nota traducida. Doce filas hoy.

Está aquí y no en `src/Pms/` porque **tiene dos consumidores y ninguno manda sobre el otro**:

- `ConsultarMediosPagoSkill` se lo lee a un huésped por el chat de su reserva.
- Las condiciones de pago de una cotización lo necesitarán igual, y ahí no hay reserva.

`src/Finanzas/` no importa de `App\Pms` en ningún archivo. Esta entidad mantiene la regla: las
flechas van Pms → Finanzas y Cotizacion → Finanzas. Si el catálogo hubiera vivido en el PMS, la
cotización habría tenido que arrastrar el módulo entero para pedir un número de cuenta.

### 14.2 Lo que NO guarda: el enlace de pago

Un `FinMedioCobro` es un destino estable que se teclea una vez. Un {@see FinEnlacePago} es un
cobro concreto, con importe, caducidad y estado. **No se mezclan**, y en particular no existe un
medio de cobro de tipo «tarjeta» con una URL dentro: esa URL la emite la pasarela por cada
cobro, y escribirla a mano en el catálogo sería congelar un enlace que caduca.

Mientras `FINANZAS_ENLACES_PREPAGO=0`, la skill responde `pago_con_tarjeta.disponible: false`
con la orden explícita de no ofrecer ninguna URL. Ver `docs/Mensajeria.md` §16.

### 14.2b Quién ve las cuentas, y dónde

Los datos de un `FinMedioCobro` —titular, banco, número, CCI— se publican en **dos** superficies
del huésped, y las dos son autenticadas sólo por el localizador de su reserva:

| Dónde | Quién lo compone | Forma |
|---|---|---|
| Guía del huésped, sección «cómo pagar» | `PmsGuiaHuespedProvider::mediosPago()` | el catálogo aplanado |
| Ficha de la reserva, tras la «i» de cada medio | `PmsReservaPaxProvider::conFichas()` | por grupo de importe |

**Son cuentas para RECIBIR dinero, no credenciales**, y ése es el criterio: publicarlas no
habilita a nadie a sacar nada. Lo que **no** sale de aquí es la audiencia ni la ventana de días
—`dias_minimos`, `dias_maximos`—, que describen *a quién le ofrecemos qué* y son reglas nuestras.
Por eso las dos superficies serializan **campo a campo y nunca la entidad**.

⚠️ **Una ficha sin ningún dato no se publica.** «Efectivo» existe en el catálogo para llevar su
ventana de días, pero no tiene nada que enseñar. Si se publicara, la app del pax le pintaría su
icono de información y abriría un cuadro en blanco. Ver `docs/PmsBeds24ReservasSync.md` §12.5.2.

### 14.2c La nota: lo que hay que HACER con ese número

Un número no dice cómo se usa, y en **Western Union esa diferencia cuesta dinero**: la empresa
ofrece *enviar a una cuenta bancaria* además del giro para recojo en tienda, y un envío hecho
por esa vía **no lo podemos cobrar**. Para el huésped las dos cosas son «mandar por Western
Union»; descubrirlo después es un giro perdido.

Por eso la nota es contenido de negocio versionado, no algo tecleado una vez en el panel: la
fija `fin:medios:notas` (`src/Finanzas/Command/FinNotasMedioCobroCommand.php`), idempotente por
el texto castellano.

⚠️ **Va por comando y no por SQL, y además necesita `setSobreescribirTraduccion(true)`.**
`$nota` lleva `#[AutoTranslate]`, y el modo por defecto del listener es «seguro»: sólo rellena
los idiomas VACÍOS. Al reescribir una nota **ya traducida**, sin el flag el castellano cambia y
los otros seis se quedan con el texto anterior — un huésped alemán leería la versión sin el
aviso, que es justo el caso que la nota existe para evitar.

⚠️ **Y el texto origen tiene que nombrar el papel entero, no señalar.** «Envíalo **a ese
nombre**» —con el nombre un renglón más arriba— se tradujo al italiano y al neerlandés como *el
remitente* («a nome di questo mittente», «op naam van de afzender»). Con eso el huésped pone su
propio nombre y el giro queda incobrable: el mismo fallo que la nota venía a evitar, sólo que
en dos idiomas y sin que nadie lo vea. Quedó «El destinatario del giro es el nombre indicado en
este recuadro». **Un deíctico no sobrevive a una traducción automática**, porque el traductor
no ve el recuadro.

El aviso va **después** de la instrucción positiva, no en su lugar: quien lee «no hagas X» sin
saber antes qué sí hacer se queda sin saberlo.

⚠️ **En la nota va sólo lo que es del MEDIO.** Cómo avisarnos después depende del canal de la
reserva —el chat de Booking no transporta imágenes— y este catálogo lo comparten el PMS y las
cotizaciones: no tiene por qué saber qué es Booking. Estuvo dentro de las notas de Yape y Plin,
y así **a un huésped de una reserva directa se le hablaba del chat de una plataforma por la que
no vino**. Vive ahora en `PmsChannel::CHAT_SIN_IMAGENES` y se publica como clave i18n aparte;
ver `docs/PmsBeds24ReservasSync.md` §12.5.2. Por eso la nota de Western Union pide el MTCN sin
decir por dónde: eso lo pone el aviso de canal, y dicho en los dos sitios acabarían en
desacuerdo.

### 14.3 La audiencia es dinero, no permisos

`FinAudienciaCobro` (`todos` / `peru` / `internacional`) existe porque ofrecer el medio
equivocado **le cuesta al cliente**: una transferencia internacional a una cuenta peruana se
lleva en comisiones buena parte de un adelanto, y mandar a un peruano a Western Union es
cobrarle un giro que no necesitaba. El filtro lo aplica `FinMedioCobroRepository::ofrecibles()`,
no el consumidor —y desde luego no el modelo de IA, que sólo ve un número.

⚠️ De dónde sale el «¿es de Perú?» **lo pone cada consumidor**, porque el dato no está en el
mismo sitio: en el PMS es `PmsReserva::$pais` con el prefijo del teléfono de suplente, y ojo con
ese prefijo (`PhoneSanitizer` antepone el 51 por defecto). En una cotización será el país del
cliente. Subir ese cálculo al repositorio obligaría a Finanzas a saber qué es una reserva.

### 14.4 ⚠️ Gotcha: `opciones()` de un enum devuelve CASOS, no `->value`

Los desplegables de enum del panel (`ChoiceField::setChoices(FinMedioCobroTipo::opciones())`)
tienen que recibir **el caso del enum**, no su valor de cadena. La entidad los guarda como
objetos (`enumType` en el mapping), así que con un array de strings `ChoiceType` intenta
convertir el valor actual a texto para casarlo con las opciones y muere con:

```
Object of class App\Finanzas\Enum\FinMedioCobroTipo could not be converted to string
```

Lo que hace que cueste encontrarlo es **dónde NO falla**: el listado se pinta bien y el
formulario de ALTA también, porque ahí no hay ningún valor previo que casar. Sólo revienta al
abrir **editar**, que es la pantalla a la que se llega la segunda vez. Ni `php -l` ni
`lint:container` lo ven: es un error de ejecución del componente Form.

Es la misma convención que ya seguía `PmsPoliticaPrepago::opciones()`; el enum nuevo se escribió
sin mirarla y por eso apareció. Al añadir un enum al panel, cópiala.

`php var/probar-medios-cobro.php` lo comprueba sin navegador —y de paso verifica que cada
propiedad de `configureFields()` se pueda leer y escribir en la entidad, que es el otro fallo
que sólo se ve al abrir la pantalla—.

### 14.5 ⚠️ Una entidad con `#[AutoTranslate]` necesita TRES cosas más

El atributo por sí solo no traduce nada. Hacen falta cuatro piezas y **ninguna avisa si falta**:

| Pieza | Dónde | Qué pasa si falta |
|---|---|---|
| `#[AutoTranslate]` en la propiedad | la entidad | Nada se traduce |
| `use AutoTranslateControlTrait` | la entidad | **Nada se traduce, en silencio.** `processEntity()` sale en su primera línea si no encuentra `getEjecutarTraduccion()` |
| `BooleanField::new('ejecutarTraduccion', 'Traducir Auto')` | su CRUD | No hay forma de **apagar** el traductor en un guardado suelto: corregir una errata del español paga una tanda de traducciones que nadie pidió |
| `BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')` | su CRUD | Se traduce lo vacío, pero **no hay forma de rehacer una traducción** tras corregir el español: el modo seguro respeta lo que ya existe |

Los dos interruptores van **en pareja** (`->onlyOnForms()->setColumns(6)`), juntos y justo antes
del campo que gobiernan. Es el patrón de `PmsGuiaItemCrudController` y de los diez CRUD de
Travel. No son intercambiables:

- **«Traducir Auto»** (`ejecutarTraduccion`) es un flag **virtual**: no es columna, no persiste,
  vale sólo para ese guardado. Apaga el proceso entero.
- **«Sobrescribir»** (`sobreescribirTraduccion`) **sí es columna**, y decide si se rehacen los
  idiomas que ya tienen texto. `AutoTranslationService` lo devuelve a `false` él solo en cuanto
  lo usa, para que no se quede pegado retraduciendo en cada edición.

El caso del trait es el que más caro sale, porque el atributo queda a la vista en la entidad y
todo parece bien puesto. Pasó dos veces en un mismo día: en `PmsEstablecimientoVirtual` (se
detectó a tiempo y el campo se dejó sin traducir a propósito) y en `PmsCargoFinanciero`, donde
sigue vivo — ver §8.

Auditar el parque entero es un comando:

```bash
for f in $(grep -rl "#\[AutoTranslate" --include="*.php" src/); do
  ent=$(basename $f .php)
  crud=$(grep -rl "return ${ent}::class" --include="*.php" src/ | head -1)
  [ -z "$crud" ] && continue
  for control in ejecutarTraduccion sobreescribirTraduccion; do
    grep -q "$control" "$crud" || echo "FALTA $control en $(basename $crud)"
  done
done
```

Ojo con los falsos positivos: los CRUD **de solo lectura** (`disable(Action::NEW, Action::EDIT)`,
como `CotizacionCrudController` y el de `PmsCargoFinanciero`) no tienen formulario donde poner
la casilla, y ahí la ausencia es correcta. La entidad se edita desde `util`.

### 14.6 Las notas del catálogo, en los siete idiomas

`Version20260810040000` dejó las notas de Yape, Plin, Western Union y efectivo escritas en los
siete idiomas activos (`maestro_idioma` con `prioridad > 0`: es, en, pt, fr, it, de, nl).

Se hizo a mano y no dejándoselo al traductor automático porque **el traductor sólo corre al
guardar desde el panel**, y esas cuatro filas no tienen motivo para tocarse. Hasta entonces un
huésped francés habría visto la nota en español: la del widget de la guía no pasa por ningún
modelo que la redacte, se pinta tal cual venga. La del chat sí, porque ahí el modelo traduce.

A partir de ahora se mantienen como cualquier otro texto: se corrige el español en el panel y
se marca «Retraducir la nota al guardar». Las ocho cuentas bancarias no llevan nota.

### 14.7 Dónde tocar para cambiar X

| Necesitas… | Archivo | Símbolo |
|---|---|---|
| Corregir una cuenta, un Yape o un titular | panel → Configuración → Cobros | «Medios de cobro». Una fila por cuenta; no se toca código |
| Que un medio deje de ofrecerse | panel | Casilla «Activo» — conserva el número |
| Añadir una clase de medio (cripto, otra billetera) | `FinMedioCobroTipo` | El `case` + `label()` + `exigeNumero()` |
| Cambiar a quién se le ofrece algo | `FinAudienciaCobro` | `aplicaA()` — es dinero, no permisos |
| Cambiar el orden en que se le enseñan al cliente | panel | Campo «Orden» |
| Añadir un campo (alias interbancario, etc.) | `FinMedioCobro` + su CRUD **y** `ConsultarMediosPagoSkill::medios()` | Los tres: lo que no se enumera en `medios()` no llega al modelo |
| Consumirlo desde un módulo nuevo | `FinMedioCobroRepository` | `ofrecibles(?bool $desdePeru)` — tú pones el `desdePeru` |
| Cambiar si el huésped cuenta como «de Perú» | `PmsProcedenciaHuesped` | `pagaDesdePeru()` — fuente única del chat y de la guía del huésped |
| Añadir un campo que vea el huésped en su guía | `PmsGuiaHuespedProvider::mediosPago()` **y** `paxHuespedGuiaModel.ts` | Espejo, §7. El front lo ignora en silencio si falta |
| Quién puede editar el catálogo | `FinMedioCobroCrudController` | `MAESTROS_WRITE` hoy; ver el aviso de su docblock |
| Comprobar el CRUD sin abrir el navegador | — | `php var/probar-medios-cobro.php` — §14.4 |

---

## Gotcha: `#[Groups]` en métodos y Symfony 7

Symfony 7 exige que todo método anotado con `#[Groups]` empiece por **`get`, `is`,
`has`, `can` o `set`**. Un nombre en español como `esManual()` deja de ser válido y el
error **no aparece al arrancar**: revienta al calentar la caché de `prod` o al serializar
la entidad en runtime.

```
Groups on "App\Finanzas\Entity\FinEnlacePago::esManual()" cannot be added.
Groups can only be added on methods beginning with "get", "is", "has", "can" or "set".
```

El método se renombró a **`getEsManual()`**, no a `isManual()`, y la elección importa: el
serializer quita el prefijo `get`, así que la propiedad sigue publicándose como
`esManual` — la clave que ya consumen `util/src/types/finEnlacePagoModel.ts` y
`FinanzasView.vue`. Con `isManual()` se habría serializado como `manual` y habría roto el
frontend sin ningún error en PHP.

**Al añadir un getter con `#[Groups]`:** si el nombre natural en español no empieza por
uno de esos prefijos, antepón `get` en vez de traducirlo. Y verifica siempre con
`php bin/console cache:warmup --env=prod`: `lint:container` **no** detecta esto, porque
los metadatos del serializer no se cargan al lintar el contenedor.

---

## Un enlace por moneda (16/08/2026)

Una pasarela cobra un enlace en **UNA divisa**, y desde la contabilidad por moneda
(`docs/PmsBeds24ReservasSync.md` §12.2b) un documento puede deber en dos. Cobrar «un total»
exigiría convertir — justo lo que ese rediseño vino a quitar.

`FinOrigenCobroResolverInterface::resolver()` gana un `?string $moneda = null` **opcional**, y con
él `FinOrigenCobroRegistry::resolver()`, `FinEnlacePagoService::crear()` y el `POST
/finanzas/enlaces-pago`. Con `null` se toma la moneda de **mayor saldo**, que es lo que devolvía
antes de que esto existiera: ningún llamante actual se rompe, y el gemelo que implemente el módulo
de tours puede ignorar el parámetro si sólo maneja una moneda.

En el panel, `ReservaEnlacesPagoSection` recibe `saldos` —una entrada por moneda— en vez de un
`saldo` escalar, y ofrece **un atajo por cada una que deba algo**. Con una sola moneda se lee
exactamente igual que antes; con dos, cada botón emite su propio enlace por lo que de verdad se
debe en esa moneda.

⚠️ **Cada atajo lleva el símbolo de SU moneda**, no el de cotización. Pintar los dos con el mismo
símbolo sería reproducir el error que todo esto vino a arreglar, y en un botón que va a cargar
dinero a una tarjeta.

La fontanería ya estaba: `FinEnlacePago` guarda su propia `moneda` y `crear()` acepta cualquier
`monedaId`. El único que forzaba una sola era el resolver.
