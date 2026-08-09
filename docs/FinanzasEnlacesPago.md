# Finanzas — Enlaces de pago por pasarela (Izipay)

Módulo `src/Finanzas/`. Emite enlaces de cobro que se envían al cliente, los cobra por
pasarela y devuelve el dinero al módulo que lo generó (hoy el PMS; mañana tours).

Nace **transversal** a propósito: es el arranque del sistema de administración/contabilidad,
no una función del PMS. Por eso no conoce ninguna entidad de negocio y todo lo que sabe del
documento que cobra se lo cuenta un *resolver* que vive en el módulo dueño.

**Estado:** operativo end-to-end para reservas del PMS. El origen `tour_reserva` está
declarado en el enum pero **sin resolver**: emitir un cobro con ese origen falla a propósito.

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
12. [Despliegue: por qué no basta con `git pull`](#12-despliegue-por-qué-no-basta-con-git-pull)
13. [Dónde tocar para cambiar X](#13-dónde-tocar-para-cambiar-x)

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
        IPN ok ────────┤────────► PAGADO    (terminal, irreversible)
        IPN ko ────────┤────────► FALLIDO ──┐
                       │            ▲       │ el cliente reintenta con otra
                       │            └───────┘ tarjeta en la MISMA url
   operador anula ─────┤────────► ANULADO   (terminal)
   pasa expiraEn ──────┴────────► EXPIRADO  (terminal)
```

- **`FALLIDO` no es final.** Que una tarjeta rebote no invalida el enlace.
  `FinEnlacePagoEstado::esFinal()` lo deja fuera, y `estaVigente()` sigue dejando pagar.
- **`PAGADO` es irreversible desde el sistema.** No se "des-paga" borrando el enlace: se
  devuelve el dinero en el Backoffice de Izipay y se elimina después el pago que generó.
  `FinEnlacePagoService::anular()` se niega explícitamente sobre un enlace pagado.
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
