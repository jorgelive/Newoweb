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
9. [Añadir un módulo nuevo que cobre](#9-añadir-un-módulo-nuevo-que-cobre)
10. [Dónde tocar para cambiar X](#10-dónde-tocar-para-cambiar-x)

---

## 1. Piezas y dónde vive cada una

```
src/Finanzas/                          ← no importa NADA de Pms/, Travel/, Cotizacion/
├── Contract/FinOrigenCobroResolverInterface   el puente; lleva #[AutoconfigureTag]
├── Dto/FinOrigenCobroDto                      "cuánto se debe y a quién", sin entidades
├── Entity/FinEnlacePago                       la unidad de cobro
├── Entity/FinPasarelaWebhookAudit             traza cruda de cada IPN
├── Enum/{FinOrigenCobro, FinEnlacePagoEstado, FinPasarela}
├── Service/FinOrigenCobroRegistry             despacha origen → resolver
├── Service/FinEnlacePagoService               emite y cierra enlaces (único sitio que cambia estado)
├── Service/Izipay/IzipayClient                REST + validación de firma
└── Controller/
    ├── Api/FinEnlacePagoApiController         SPA util (con #[IsGranted])
    ├── Publico/FinPagoPublicoController       SPA pax (sin sesión, credencial = token)
    └── Webhook/IzipayWebhookController        IPN de Izipay

src/Pms/Finanzas/PmsReservaOrigenCobroResolver ← el PMS implementa el contrato de Finanzas

util/  ReservaEnlacesPagoSection.vue, stores/finanzas/enlacesPagoStore.ts
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
| `FinEnlacePagoApiController::serializar()` | `util/src/types/finEnlacePagoModel.ts` |
| `FinPagoPublicoController::ver()` / `formToken()` | `pax/src/types/paxPagoModel.ts` |
| Librería KR de Izipay (sin paquete npm) | `pax/src/types/izipayKrypton.d.ts` |

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

## 9. Añadir un módulo nuevo que cobre

Todo el diseño está pensado para que esto sean **dos pasos**:

1. Añadir el `case` a `FinOrigenCobro` (si no está ya).
2. Crear `src/<Modulo>/Finanzas/<X>OrigenCobroResolver.php` implementando
   `FinOrigenCobroResolverInterface`:
   - `soporta()` → el case.
   - `resolver()` → un `FinOrigenCobroDto` con saldo, moneda, cliente y referencia.
     **Devuelve `null` si el documento ya no existe**, nunca lanza.
   - `registrarCobro()` → crea el asiento del módulo y devuelve su UUID. Sin `flush()`: la
     transacción la cierra `FinEnlacePagoService::confirmarPago()`.

No hay que tocar la entidad, ni la migración, ni el registry, ni el YAML de Finanzas. En el
frontend, `ReservaEnlacesPagoSection.vue` se monta con otro `origen-tipo` y ya.

El resolver del PMS (`PmsReservaOrigenCobroResolver`) es el modelo a copiar.

---

## 10. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Cambiar el % de recargo de tarjeta | `config/services/services_finanzas.yaml` **y** `src/Pms/Enum/PmsMedioPago.php` | `finanzas.recargo_tarjeta_porcentaje` / `comisionPorcentaje()` |
| Cambiar la vigencia por defecto | `src/Finanzas/Service/FinEnlacePagoService.php` | `VIGENCIA_DIAS_DEFECTO` |
| Cambiar el formato del `orderId` | `src/Finanzas/Service/FinEnlacePagoService.php` | `generarOrdenId()` |
| Cambiar la URL pública del enlace | `src/Finanzas/Service/FinEnlacePagoService.php` | `urlPublica()` (+ ruta en `pax/src/router/index.ts`) |
| Añadir datos al `CreatePayment` | `src/Finanzas/Service/Izipay/IzipayClient.php` | `crearFormToken()` |
| Cambiar qué respuesta cuenta como cobro | `src/Finanzas/Service/Izipay/IzipayClient.php` | `esPagoExitoso()` |
| Guardar otro campo de la transacción | `src/Finanzas/Service/FinEnlacePagoService.php` | `confirmarPago()` |
| Cambiar cómo se imputa el pago en el PMS | `src/Pms/Finanzas/PmsReservaOrigenCobroResolver.php` | `registrarCobro()` |
| Cambiar el texto que ve el cliente | `src/Pms/Finanzas/PmsReservaOrigenCobroResolver.php` | `describir()` |
| Cambiar el JSON que consume `util` | `src/Finanzas/Controller/Api/FinEnlacePagoApiController.php` | `serializar()` (+ espejo TS, §7) |
| Cambiar el JSON que consume `pax` | `src/Finanzas/Controller/Publico/FinPagoPublicoController.php` | `ver()` (+ espejo TS, §7) |
| Cambiar el tema visual del formulario | `pax/src/views/pago/PaxPagoView.vue` | `cargarLibreria()` (assets `neon`) |
| Cambiar el sondeo de confirmación | `pax/src/views/pago/PaxPagoView.vue` | `sondearConfirmacion()` |
| Mover o rediseñar el botón del operador | `util/src/components/reservas/ReservaEnlacesPagoSection.vue` | — |
| Añadir un módulo que cobre | §9 | `FinOrigenCobroResolverInterface` |
| Depurar "no se confirmó un cobro" | tabla `fin_pasarela_webhook_audit` | `payload_raw`, `estado`, `error_mensaje` |
