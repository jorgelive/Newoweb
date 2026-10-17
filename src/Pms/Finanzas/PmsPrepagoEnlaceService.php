<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Entity\User;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use Psr\Log\LoggerInterface;
use Throwable;
use Doctrine\ORM\EntityManagerInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsPoliticaPrepago;
use App\Pms\Enum\PmsQueSePide;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Emite el enlace con el que el huésped paga su PREPAGO.
 *
 * No hay maquinaria nueva de cobro debajo: `FinEnlacePagoService::crear()` ya acepta un
 * `montoNeto`, así que un enlace de prepago es un enlace normal cuyo importe es el del
 * prepago en vez del saldo. Lo que aporta esta clase son las tres reglas que lo rodean —el
 * interruptor, la reutilización y de dónde sale la cifra— y que no deben quedar repartidas
 * entre la skill del agente y quien venga después.
 *
 * Vive en `src/Pms/` como su hermano {@see PmsReservaOrigenCobroResolver}: el módulo que sabe
 * qué es un prepago es el PMS. Finanzas no se entera de que esto existe.
 *
 * ### 1. El interruptor
 *
 * `finanzas.enlaces_prepago_activos` (env `FINANZAS_ENLACES_PREPAGO`). Apagado hasta que Culqi
 * pase a producción: en modo test la pasarela **acepta el cobro y no mueve dinero**, y un
 * huésped que paga ahí se queda convencido de que ya está. El flag no comprueba las claves —no
 * puede, una `pk_test_` es sintácticamente igual de válida—, así que encenderlo es una
 * decisión, no un automatismo.
 *
 * ### 2. La reutilización, y por qué mira el importe
 *
 * Un enlace vigente y pagable para el MISMO importe se devuelve tal cual en vez de emitir otro.
 * Sin esto, dos preguntas seguidas al agente («mándame el link», «no me llegó») dejarían dos
 * enlaces vivos por el mismo dinero, y el huésped que pague los dos paga el prepago dos veces.
 *
 * Se compara por `montoNeto` y no por «este enlace era de prepago» porque **la fila no guarda
 * para qué se emitió**, y añadirle una columna de tipo sería meter vocabulario del PMS en una
 * entidad que es transversal a propósito (§2). La consecuencia se acepta: si el prepago cambia
 * —cambian los cargos, cambia la política— el importe deja de coincidir y se emite uno nuevo,
 * que es exactamente lo que hay que hacer. Y si el operador ya había emitido a mano un enlace
 * por ese mismo importe, se reaprovecha: es el mismo cobro.
 *
 * ### 3. La cifra sale del calculador, no de aquí
 *
 * {@see PmsPrepagoCalculador::pendiente()} es la misma llamada que alimenta el estado de cuenta
 * del huésped y el resumen del panel. Si esto calculara por su cuenta, el enlace podría pedir
 * un importe distinto del que el huésped tiene delante en la pantalla — y el que no cuadra
 * siempre es el que llega por WhatsApp.
 *
 * `pendiente()` devuelve `null` en cuanto hay un pago registrado, así que un huésped que ya
 * adelantó algo no puede recibir un segundo enlace de prepago aunque alguien lo pida.
 */
final readonly class PmsPrepagoEnlaceService
{
    /**
     * Minutos desde que se paga el ADELANTO por enlace hasta que se emite el del saldo.
     *
     * Lo pidió Jorge el 10/10/2026: tras el adelanto, el saldo se puede pagar ya — es obligatorio
     * el día del check-in, pero no hay por qué esperar a ese día para ofrecerlo. La media hora
     * separa el «hemos recibido tu pago» del aviso del saldo. Lo emite el barrido
     * `app:pms:prepago:saldo-tras-adelanto`; ver `adelantoPagadoHaceRato()`.
     */
    public const int MINUTOS_SALDO_TRAS_ADELANTO = 30;

    /**
     * El «prepago» de una reserva cuya política no pide adelanto, cuando una PERSONA pidió cobrar
     * el total (`cobroTotalPedido`, o la skill con `cobrar_total`). Ver `prepagoQueProcede()`.
     *
     * No lleva importe porque no se usa: pidiendo el total, la cifra es el saldo del resolver
     * (`loQueSePide()`), y de esto sólo se lee la política para la respuesta.
     */
    private const array TOTAL_SIN_POLITICA = [
        'monto' => '0.00',
        'claveI18n' => '',
        'politica' => PmsPoliticaPrepago::SIN_PREPAGO->value,
    ];

    public function __construct(
        private PmsPrepagoCalculador $calculador,
        // Para el turno (GET_LOCK) y para releer la cabecera ya bloqueada.
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private FinEnlacePagoService $enlaces,
        private FinEnlacePagoRepository $repositorio,
        // Para el saldo TOTAL: es quien sabe cuánto se debe y en qué moneda, y ya es la fuente
        // que usa `FinEnlacePagoService::crear()` cuando no se le dice el importe.
        private PmsReservaOrigenCobroResolver $origen,
        #[Autowire('%finanzas.enlaces_prepago_activos%')]
        private bool $activo,
    ) {}

    public function estaActivo(): bool
    {
        return $this->activo;
    }

    /**
     * Enlaces por los que este huésped puede pagar AHORA.
     *
     * Sirve a la app del pax, que sólo enseña lo que ya existe: emitir desde ahí sería un
     * write disparado por cualquiera que tenga el localizador. Se devuelven todos los
     * pagables, no sólo el del prepago — si el operador emitió uno por el saldo completo, el
     * huésped también tiene que verlo.
     *
     * @return list<FinEnlacePago>
     */
    public function pagables(PmsReserva $reserva): array
    {
        $id = $reserva->getId();

        if (!$this->activo || $id === null) {
            return [];
        }

        return array_values(array_filter(
            $this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $id),
            static fn (FinEnlacePago $e): bool => $e->estaVigente(),
        ));
    }

    /**
     * Lo que emitiría `emitir()`, sin emitir nada.
     *
     * Existe para la previsualización del agente, que tiene que enseñarle al operador el
     * importe y pedirle un sí ANTES de que el enlace exista. Comparte las reglas con
     * `emitir()` —mismo calculador, misma búsqueda de enlace vivo— en vez de reimplementarlas
     * en la skill: una previsualización que no coincide con lo que luego ocurre es peor que
     * no previsualizar.
     *
     * @return array{monto: string, moneda: string, politica: string, reutilizado: bool, esSaldo: bool}|null
     *         `null` cuando no hay nada que pedir, por el motivo que sea. `esSaldo` distingue
     *         el adelanto del saldo entero: quien lo enseñe tiene que decir cuál es, y no
     *         deducirlo. Ver `loQueSePide()`.
     */
    public function emitirSimulado(PmsReserva $reserva, bool $comoTotal = false): ?array
    {
        $info = $reserva->getInformacionFinanciera();
        $id = $reserva->getId();

        if (!$this->activo || $info === null || $id === null) {
            return null;
        }

        // Espejo de `emitir()`: la previsualización tiene que ver la misma puerta. Con
        // `$comoTotal` —el operador va a marcar «cobrar el total»— la puerta es la del total.
        $prepago = $comoTotal
            ? ($this->calculador->pendiente($info) ?? $this->calculador->calcular($info) ?? self::TOTAL_SIN_POLITICA)
            : $this->prepagoQueProcede($info, $id);

        if ($prepago === null) {
            return null;
        }

        // Adelanto, o el saldo entero desde el día de llegada. La previsualización tiene que
        // enseñar EXACTAMENTE lo que va a emitirse: mismo helper que `emitir()`.
        $pide = $this->loQueSePide($reserva, $info, $prepago, $comoTotal ? PmsQueSePide::TOTAL : null);

        if ($pide === null) {
            return null;
        }

        $existente = $this->vigentesPorImporte($id, $pide['monto'], $pide['moneda']);

        return [
            'monto' => $pide['monto'],
            'moneda' => $pide['moneda'] ?? '',
            'politica' => $prepago['politica'],
            'reutilizado' => $existente !== null,
            'esSaldo' => $pide['esSaldo'],
        ];
    }

    /**
     * El enlace con el que se paga el prepago de esta reserva, pedido por una PERSONA (la skill
     * del agente). Lo emite o devuelve el que ya existe.
     *
     * ### 🔥 Es el enlace del SISTEMA, aunque lo pida alguien (16/10/2026)
     *
     * Hasta hoy nacía con `creadoPor` = el operador y la caducidad por defecto de 7 días. En este
     * módulo «con autor» significa «manual», y un manual apaga todo lo automático: el saldo media
     * hora después del adelanto (`adelantoPagadoHaceRato()` y el barrido filtran
     * `creado_por_id IS NULL`), el relevo por el total el día de llegada, y la reemisión cuando
     * cambian los cargos (`hayManualVivo()`). Y caducaba a la semana sin que nadie lo viera: el
     * único adelanto emitido así en producción, el de JCD7FR, acabó `expirado`.
     *
     * Lo que se emite aquí es la cifra de la POLÍTICA —la misma que emitiría el automático—, no
     * una cantidad elegida por alguien; un enlace a medida se emite desde el panel. Así que nace
     * como los automáticos: sin autor y sin caducidad. Quién lo pidió queda en el log.
     *
     * ### 🔒 Con el turno del automático
     *
     * Con «cobrar el total» la skill guarda la marca y su `postFlush` ya emite por
     * `emitirPorCambioDeCargos()`; esto llega después y lo reutiliza. Sin el turno, los dos
     * caminos podían cruzarse con un webhook de Beds24 sobre la misma reserva.
     *
     * @return array{enlace: FinEnlacePago, enlaces: non-empty-list<FinEnlacePago>, url: string, monto: string, moneda: string, politica: string, reutilizado: bool, esSaldo: bool}
     *
     * @throws DomainException con un mensaje que se le puede leer al operador tal cual.
     */
    public function emitir(PmsReserva $reserva, ?User $pedidoPor = null): array
    {
        if (!$this->activo) {
            throw new DomainException(
                'Los enlaces de prepago están desactivados hasta que la pasarela pase a '
                . 'producción. Cóbralo por otro medio o emite el enlace a mano desde el panel.'
            );
        }

        $info = $reserva->getInformacionFinanciera();
        $id = $reserva->getId();

        if ($info === null || $id === null) {
            throw new DomainException('Esta reserva todavía no tiene cuenta financiera abierta.');
        }

        if (!$this->tomarElTurno($id)) {
            throw new DomainException('Se está emitiendo otro enlace para esta reserva. Inténtalo en unos segundos.');
        }

        try {
            // Con el turno, la cabecera recién leída: el otro camino ha podido cambiarla.
            $this->em->refresh($info);

            // Misma puerta que el camino automático. Ver `prepagoQueProcede()`.
            $prepago = $this->prepagoQueProcede($info, $id);

            if ($prepago === null) {
                throw new DomainException(
                    'Esta reserva no tiene nada que cobrar por enlace: o su establecimiento no pide '
                    . 'adelanto, o el canal cobró por nosotros, o ya hay un pago registrado y la '
                    . 'reserva todavía no ha llegado.'
                );
            }

            $pide = $this->loQueSePide($reserva, $info, $prepago);

            if ($pide === null) {
                throw new DomainException('Esta reserva ya no tiene saldo pendiente que cobrar.');
            }

            // Vale también un manual por ese importe: lo que se pide ya está en la calle.
            $existentes = $this->vigentesPorImporte($id, $pide['monto'], $pide['moneda']);

            if ($existentes !== null) {
                return $this->respuesta($existentes, $prepago, reutilizado: true, esSaldo: $pide['esSaldo']);
            }

            // ⚠️ Sin anular nada antes: `crearEnTramos()` anula lo vivo —manuales incluidos, que
            // aquí hay una persona decidiendo reemplazarlo— DESPUÉS de validar lo nuevo. Anulando
            // aquí, un enlace que no podía existir (tope de la pasarela, credenciales) se llevaba
            // por delante al que había y la reserva se quedaba sin ninguno.
            $enlaces = $this->enlaces->crearEnTramos(
                origenTipo: FinOrigenCobro::PMS_RESERVA,
                origenId: $id,
                montoNeto: $pide['monto'],
                // El recargo de tarjeta se traslada igual que en cualquier otro cobro: la
                // comisión de la pasarela no la absorbe la casa por ser un adelanto.
                conRecargo: true,
                // Ver la cabecera: el enlace de la política es del sistema.
                vigenciaDias: 0,
                concepto: $pide['concepto'],
                creadoPor: null,
                // Ver la nota de `emitirConTurno()`: el importe viene en la moneda de la cabecera
                // y hay que decirlo.
                moneda: $pide['moneda'],
            );

            $this->logger->info('[prepago] enlace de la política emitido a petición de una persona.', [
                'reserva' => (string) $id,
                'pedido_por' => $pedidoPor?->getUserIdentifier(),
                'enlaces' => \count($enlaces),
            ]);

            return $this->respuesta($enlaces, $prepago, reutilizado: false, esSaldo: $pide['esSaldo']);
        } finally {
            $this->soltarElTurno($id);
        }
    }

    /**
     * Emite el enlace del adelanto SOLO, en cuanto la reserva tiene importes de verdad.
     *
     * ### Por qué aquí y no al crear la reserva
     *
     * Porque al nacer la reserva su cabecera financiera está **vacía**: la auto-provisiona
     * `PmsInformacionFinancieraCoherenciaListener` con cero cargos, y con base cero el
     * calculador devuelve `null`. Los importes llegan después, por el webhook de invoiceItems
     * o por el cron de facturas. Este método se llama tras el RECÁLCULO, que es el momento en
     * que el total deja de ser cero.
     *
     * ### Todas las reglas ya estaban escritas
     *
     * No hay ni una condición de negocio nueva: `pendiente()` devuelve `null` para los canales
     * que ya cobraron (Airbnb, VRBO), para una base de cero —un inquiry, una cancelada—, para
     * un establecimiento sin política y en cuanto hay cualquier pago registrado. Aquí sólo se
     * pregunta.
     *
     * ### ⚠️ Sin caducidad, a propósito
     *
     * `vigenciaDias: 0`. Un enlace automático no lo mira nadie: si caducara a los 7 días
     * moriría en silencio y el huésped se quedaría sin poder pagar sin que nadie se entere.
     * El emitido a mano desde el panel conserva su vigencia por defecto, porque ahí hay una
     * persona detrás. El de la skill no: cobra la cifra de la política y nace como éste (ver
     * `emitir()`).
     *
     * ### 🔒 NUNCA lanza
     *
     * Se ejecuta dentro del `postFlush` que acaba de persistir los cargos. Esos cargos son la
     * verdad contable y ya llegaron; que no se pueda emitir un enlace no puede tumbarlos. Y no
     * hace falta cola de reintentos: cualquier movimiento posterior de esa reserva vuelve a
     * pasar por el mismo recálculo y lo intenta otra vez.
     *
     * @return FinEnlacePago|null El enlace emitido, o null si no procedía o falló.
     */
    public function emitirPorCambioDeCargos(PmsInformacionFinanciera $info): ?FinEnlacePago
    {
        if (!$this->activo) {
            return null;
        }

        try {
            $reserva = $info->getReserva();
            $id = $reserva?->getId();

            if ($reserva === null || $id === null) {
                return null;
            }

            // 🔒 EL TURNO. Comprobar → bloquear → volver a comprobar.
            //
            // Sin esto, dos workers concurrentes sobre la misma reserva —el del webhook de
            // Beds24 y el del cron de facturas— pasan los dos por `vigentesPorImporte()` sin
            // encontrar nada y emiten DOS enlaces vivos. Un doble cobro.
            //
            // Mismo patrón que `ProcessInboundIntentDispatchHandler::tomarElTurno()`, incluido
            // el prefijo con el nombre de la base: `GET_LOCK` no sabe de bases y su espacio de
            // nombres es el SERVIDOR entero, así que un clon de staging en la misma máquina
            // bloquearía a producción para el mismo UUID.
            if (!$this->tomarElTurno($id)) {
                return null;
            }

            try {
                // Re-leer YA con el turno tomado: entre la comprobación de arriba y esta línea
                // el otro worker ha podido emitirlo y soltar. Es el tercer paso de la regla.
                $this->em->refresh($info);

                return $this->emitirConTurno($info, $reserva, $id);
            } finally {
                $this->soltarElTurno($id);
            }
        } catch (Throwable $e) {
            $this->logger->error('[prepago] no se pudo emitir el enlace automático; los cargos NO se ven afectados.', [
                'reserva' => (string) $info->getReserva()?->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * El cuerpo de la emisión, ya con el turno tomado. Ver `emitirPorCambioDeCargos()`.
     *
     * Devuelve el enlace emitido, o el primero de sus tramos.
     */
    private function emitirConTurno(PmsInformacionFinanciera $info, PmsReserva $reserva, Uuid $id): ?FinEnlacePago
    {
            // ⚠️ Cabecera ANULADA (todas las estancias canceladas): aquí no se pide adelanto.
            //
            // Emitir un «Adelanto de reserva» sobre una reserva cancelada no tiene ningún
            // sentido, así que se corta aquí aunque quede base que calcular.
            //
            // ⚠️ **Se pregunta a la RESERVA, no a `activa`** (08/09/2026). Desde que la bandera
            // dejó de decidir dinero, «inactiva» ya no significa «cancelada»: puede quedar abajo
            // en una reserva que siguió adelante como directa, y entonces esto le negaba el enlace
            // de adelanto al huésped que sí va a venir.
            // Un manual que ya pide más de lo que se debe se anula: la deuda bajó después de
            // emitirlo —una transferencia, el depósito de la OTA, un cargo retirado—. Va antes de
            // todo porque vale también para una reserva cancelada.
            $this->anularManualesQueExcedenLaDeuda($info, $id);

            if ($reserva->isCancelada()) {
                $this->anularAutomaticosVigentes($id);

                return null;
            }

            // 🔒 Un enlace MANUAL vivo manda: el automático no emite nada a su lado (10/10/2026).
            //
            // «En ningún caso deben estar dos enlaces» (Jorge). Hasta hoy el automático buscaba
            // uno vivo POR SU IMPORTE: el «Total» que el operador emitía a mano no coincidía con
            // el adelanto, y en el siguiente movimiento le salía un adelanto al lado. Uno manual
            // es la decisión de una persona; se respeta hasta que se pague o se anule. Para
            // cobrar el total sin enlace manual está `cobroTotalPedido`.
            if ($this->hayManualVivo($id)) {
                return null;
            }

            // ⚠️ Sin adelanto pendiente, `prepagoQueProcede()` todavía puede abrir la puerta del
            // SALDO. `pendiente()` devuelve null por cuatro motivos y no son equivalentes: el
            // canal ya cobró, el establecimiento no pide adelanto, la base es cero — o **ya hay un
            // pago registrado**. Ese cuarto no significa «no hay nada que cobrar» sino que lo que
            // queda es el saldo, y se pide cuando `puedePedirseElSaldo()`.
            $prepago = $this->prepagoQueProcede($info, $id);

            if ($prepago === null) {
                // Pagó una parte de un cobro EN TRAMOS: las demás son el resto de ese mismo cobro,
                // no algo que haya dejado de proceder. Anulándolas, el huésped que pagó la parte 1
                // de 2 del adelanto se quedaba sin la 2 (16/10/2026).
                if ($this->quedanTramosDeUnCobroEmpezado($info, $id)) {
                    return null;
                }

                // 🔴 Ya no procede pedir nada —el canal cobró, no hay política, la base es cero, o
                // pagó y todavía no ha llegado—, así que el enlace vivo tiene que MORIR.
                //
                // Sin esto, y como los automáticos se emiten SIN caducidad, quedaría un enlace
                // pagable para siempre en el WhatsApp de alguien que ya pagó. La ausencia de
                // caducidad, correcta mientras el cobro procede, se vuelve una trampa en cuanto
                // deja de proceder.
                $this->anularAutomaticosVigentes($id);

                return null;
            }

            // Adelanto, o el saldo entero desde el día de llegada (ver `loQueSePide()`). Va
            // ANTES de la comprobación de enlace vivo porque es el importe lo que decide si
            // hay que emitir: el día que la reserva cruza a TOTAL, el adelanto vivo deja de
            // coincidir y se releva solo.
            $pide = $this->loQueSePide($reserva, $info, $prepago);

            if ($pide === null) {
                // Toca el total y no queda saldo: no hay nada que cobrar, así que tampoco nada
                // que ofrecer. Se retira lo vivo por el mismo motivo que arriba.
                $this->anularAutomaticosVigentes($id);

                return null;
            }

            $existentes = $this->vigentesPorImporte($id, $pide['monto'], $pide['moneda']);

            if ($existentes !== null) {
                // Ya hay uno vivo por ese importe: no se emite otro. Es lo que evita un enlace
                // nuevo en cada recálculo.
                //
                // ⚠️ Pero puede quedar OTRO automático vivo por un importe que ya no se pide —el
                // adelanto cuando la reserva cruzó a saldo—, y ése hay que retirarlo igual. Sin
                // esto, el huésped veía dos enlaces por cantidades distintas y podía pagar el
                // que no toca. Muerde justo en el caso que motivó todo: el enlace que el
                // OPERADOR emitió a mano por el saldo coincide en importe, se reutiliza, y la
                // función salía por aquí dejando vivo el adelanto viejo.
                //
                // `excepto` son los que se reutilizan (uno, o los tramos del mismo cobro): si son
                // los propios automáticos, no se suicidan.
                $this->anularAutomaticosVigentes($id, $existentes);

                return null;
            }

            // ⚠️ SIN anular antes, y es lo que cambió el 16/10/2026. Se anulaban aquí los
            // automáticos y DESPUÉS se creaba: si la creación fallaba —un saldo por encima del tope
            // de la pasarela, credenciales—, el `catch` de arriba lo dejaba en el log y la reserva
            // se quedaba sin NINGÚN enlace. `crearEnTramos()` anula lo vivo después de validar lo
            // nuevo, en la misma transacción. Y aquí no hay manuales que respetar: con uno vivo
            // se salió arriba, en `hayManualVivo()`.
            //
            // En TRAMOS si no cabe en el tope de la pasarela: «parte 1 de 2», «parte 2 de 2», los
            // dos vivos a la vez. Ver `FinEnlacePagoService::crearEnTramos()`.
            return $this->enlaces->crearEnTramos(
                origenTipo: FinOrigenCobro::PMS_RESERVA,
                origenId: $id,
                montoNeto: $pide['monto'],
                conRecargo: true,
                concepto: $pide['concepto'],
                // ⚠️ La moneda se DICE, no se deduce. Con ADELANTO el importe viene en la
                // moneda de la CABECERA (`base()` lo convierte) y `crear()` sin este parámetro
                // se lo preguntaría al resolver, que responde «la de mayor saldo»: en una
                // reserva con cargos en soles y cabecera en dólares el enlace habría cobrado
                // 46.42 PEN donde el cálculo decía 46.42 USD. Con TOTAL el importe ya viene del
                // resolver, así que la moneda es la suya y coinciden por construcción.
                moneda: $pide['moneda'],
                // Sin persona detrás: el enlace queda sin `creadoPorNombre`, que es la verdad.
                creadoPor: null,
                vigenciaDias: 0,
            )[0];
    }

    /**
     * La puerta común de los tres caminos —automático, skill y previsualización—: qué prepago
     * procede pedir, o `null`.
     *
     * Sin pagos, el de la política (`pendiente()`). Con algún pago, ese pago fue el adelanto y lo
     * que queda es el SALDO, pero sólo cuando `puedePedirseElSaldo()`. Estuvo copiada en tres
     * sitios con la condición del día de llegada escrita a mano en cada uno.
     *
     * ### El total que pide una persona no necesita política (16/10/2026)
     *
     * `calcular()` es null cuando no hay adelanto que calcular: un establecimiento `SIN_PREPAGO`,
     * un canal que cobra por nosotros (Airbnb, VRBO) o una reserva sin cargo de alojamiento. Eso
     * dice que no hay ADELANTO, no que no haya nada que cobrar: los extras de un huésped de Airbnb
     * —una cena, un traslado— son nuestros. Con `cobroTotalPedido` puesto, el operador había
     * pedido cobrar el total y la skill contestaba «el canal ya cobró»; marcar la casilla no hacía
     * nada. Ahora la marca basta, y la cifra es el saldo (`loQueSePide()`).
     *
     * ### Y los EXTRAS de un canal que cobra, desde el día de llegada (16/10/2026)
     *
     * Jorge: «que se emitan para los extras». Un huésped de Airbnb o VRBO con una cena, un traslado
     * o una noche extra recibe el enlace por esos extras el día de llegada, como cualquier otro
     * huésped recibe el del total. Ver `extrasDeCanalQueCobra()`. Un establecimiento `SIN_PREPAGO`
     * sigue necesitando la marca: no hay ninguno hoy, y la petición fue la de los extras.
     *
     * @return array{monto: string, claveI18n: string, politica: string}|null
     */
    private function prepagoQueProcede(PmsInformacionFinanciera $info, Uuid $id): ?array
    {
        if (($pendiente = $this->calculador->pendiente($info)) !== null) {
            return $pendiente;
        }

        if (!$this->puedePedirseElSaldo($info, $id)) {
            return null;
        }

        return $this->calculador->calcular($info)
            ?? ($info->isCobroTotalPedido() || $this->extrasDeCanalQueCobra($info) ? self::TOTAL_SIN_POLITICA : null);
    }

    /**
     * ¿Es una reserva de un canal que cobra por nosotros, ya en su día de llegada, cuyo saldo son
     * EXTRAS nuestros? Entonces se le emite el enlace por ellos (ver `prepagoQueProcede()`).
     *
     * El saldo ES lo de los extras porque el depósito espejo (`PmsPagoOtaAutomaticoService`) cancela
     * exactamente los cargos del canal: comprobado en producción el 16/10/2026 sobre las reservas de
     * Airbnb de los últimos 60 días —las que tenían saldo sin extras eran canceladas, que no cobran—.
     *
     * 🛡️ Salvo un cargo de BEDS24 que cuenta y no lleva la marca de espejo (`esAutomatico`): es el
     * flag mal puesto que ya se vio («Alojamiento 130.00» de Airbnb sin marcar, ver
     * `ConsultarCuentaSkill::cuentaDeCanalQueCobra()`), y con él el «extra» sería el alojamiento que
     * el huésped ya pagó a la plataforma. Ante la duda no se emite y se deja aviso. Los extras de
     * verdad los teclea el operador, sin `beds24ItemId`.
     */
    private function extrasDeCanalQueCobra(PmsInformacionFinanciera $info): bool
    {
        $reserva = $info->getReserva();
        $canal = $reserva?->getChannel()?->getId();

        if ($canal === null || !\in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true) || !$this->calculador->yaLlegoElDia($info)) {
            return false;
        }

        foreach ($info->getCargos() as $cargo) {
            if ($cargo->cuentaParaElSaldo() && !$cargo->isEsAutomatico() && $cargo->getBeds24ItemId() !== null) {
                $this->logger->warning('[prepago] cargo de Beds24 sin marca de espejo en un canal que cobra: no se emite el enlace de los extras.', [
                    'reserva' => (string) $reserva->getId(),
                    'cargo' => (string) $cargo->getId(),
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Con un pago ya hecho, ¿toca pedir el resto?
     *
     * - el día de llegada o después (28/08/2026);
     * - si se pide el total: marcado por el operador o de última hora (10/10/2026);
     * - o si el adelanto se pagó por enlace hace `MINUTOS_SALDO_TRAS_ADELANTO` (10/10/2026).
     *
     * Un pago registrado A MANO (transferencia, efectivo) no abre la tercera puerta: ahí hay una
     * persona delante, y la detección que se pidió es la del enlace, que sólo marca pagado el
     * servidor cuando la pasarela confirma el cargo.
     *
     * ⚠️ Y la tercera puerta espera a que el adelanto esté ENTERO: con la parte 1 de 2 pagada,
     * emitir el saldo anulaba la parte 2 y el aviso decía «tu adelanto ya está registrado» con la
     * mitad pagada. Es la misma regla que `CotizacionEnlaceService::adelantoCubierto()`.
     */
    private function puedePedirseElSaldo(PmsInformacionFinanciera $info, Uuid $id): bool
    {
        return $this->calculador->yaLlegoElDia($info)
            || $this->calculador->pideElTotal($info)
            || ($this->adelantoPagadoHaceRato($id) !== null && !$this->quedanTramosDeUnCobroEmpezado($info, $id));
    }

    /**
     * ¿Hay un cobro EN TRAMOS con alguna parte pagada por su enlace y otras todavía vivas?
     *
     * Las partes vivas son el resto de ese mismo cobro: no se anulan porque haya entrado un pago
     * (era el suyo) ni se reemplazan por el saldo mientras falten. Ver `crearEnTramos()`.
     *
     * ⚠️ Sólo mientras **no haya entrado otro pago** desde que se emitieron. Si el resto llegó por
     * transferencia, la parte viva ya cobraría dos veces lo mismo, y no hay forma de saber qué
     * cubrió ese pago sin que lo diga una persona: se cae a la regla de siempre, que la anula. Lo
     * encontró la prueba con datos reales: pagada la parte 2 por transferencia, seguía viva.
     */
    private function quedanTramosDeUnCobroEmpezado(PmsInformacionFinanciera $info, Uuid $id): bool
    {
        /** @var array<string, array{vivos: list<FinEnlacePago>, pagados: list<FinEnlacePago>}> $cobros */
        $cobros = [];

        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $id) as $enlace) {
            $tramo = FinEnlacePagoService::tramoDe($enlace);

            if ($tramo === null || $enlace->getCreadoPor() !== null) {
                continue;
            }

            $clave = FinEnlacePagoService::conceptoSinTramo($enlace) . '|' . $tramo[1];
            $cobros[$clave] ??= ['vivos' => [], 'pagados' => []];

            if ($enlace->estaVigente()) {
                $cobros[$clave]['vivos'][] = $enlace;
            } elseif ($enlace->getEstado() === FinEnlacePagoEstado::PAGADO) {
                $cobros[$clave]['pagados'][] = $enlace;
            }
        }

        foreach ($cobros as $cobro) {
            if ($cobro['vivos'] === [] || $cobro['pagados'] === []) {
                continue;
            }

            $partes = [...$cobro['vivos'], ...$cobro['pagados']];
            $suyos = array_map(static fn (FinEnlacePago $e): string => (string) $e->getId(), $partes);
            $desde = min(array_map(static fn (FinEnlacePago $e): int => $e->getCreatedAt()?->getTimestamp() ?? 0, $partes));

            foreach ($info->getPagos() as $pago) {
                $deUnaParte = \in_array((string) $pago->getEnlacePagoId(), $suyos, true);

                if (!$deUnaParte && ($pago->getCreatedAt()?->getTimestamp() ?? PHP_INT_MAX) >= $desde) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * El último enlace AUTOMÁTICO de la reserva pagado hace al menos media hora, o `null`.
     *
     * Automático = sin autor: el adelanto lo emite el sistema. Se mira `pagadoEn`, que escribe
     * `FinEnlacePagoService::confirmarPago()` sólo con el cargo confirmado por la pasarela — el
     * navegador no puede marcarlo. Un reembolsado ya no está pagado y no cuenta.
     */
    public function adelantoPagadoHaceRato(Uuid $reservaId): ?FinEnlacePago
    {
        $corte = new \DateTimeImmutable(sprintf('-%d minutes', self::MINUTOS_SALDO_TRAS_ADELANTO));

        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            $pagadoEn = $enlace->getPagadoEn();

            if ($enlace->getEstado() === FinEnlacePagoEstado::PAGADO
                && $enlace->getCreadoPor() === null
                && $pagadoEn !== null
                && $pagadoEn <= $corte
            ) {
                return $enlace;
            }
        }

        return null;
    }

    /**
     * Anula los enlaces MANUALES vivos que piden más de lo que hoy se debe en su moneda.
     *
     * La otra mitad de la regla de `FinEnlacePagoService::crear()` (10/10/2026): allí no se deja
     * crear uno por encima de la deuda; aquí se retira el que se quedó por encima porque la deuda
     * bajó después —se pagó por otro medio, o se retiró un cargo—. Sin esto, BGD96K quedó saldada
     * con un enlace de 83.31 que el huésped todavía podía pagar.
     *
     * Sólo los manuales: los automáticos los releva este mismo emisor con su regla.
     *
     * ⚠️ **Se compara la SUMA por moneda**, no cada enlace (16/10/2026). El panel parte en tramos
     * lo que no cabe en el tope de la pasarela: con US$ 3 200 en dos enlaces de 1 600 y una
     * transferencia de 1 600, cada tramo «cabía» en la deuda y quedaban vivos 3 200 para 1 600.
     * Vivos en una moneda sólo puede haber un cobro —uno solo, o sus tramos—, así que lo que
     * excede se anula entero.
     */
    private function anularManualesQueExcedenLaDeuda(PmsInformacionFinanciera $info, Uuid $reservaId): void
    {
        $porMoneda = PmsTotalesPorMoneda::de($info)->porMoneda;
        /** @var array<string, list<FinEnlacePago>> $vivos */
        $vivos = [];

        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if ($enlace->estaVigente() && $enlace->getCreadoPor() !== null) {
                $vivos[$enlace->getMonedaCodigo() ?? ''][] = $enlace;
            }
        }

        foreach ($vivos as $moneda => $enlaces) {
            $pide = array_sum(array_map(static fn (FinEnlacePago $e): float => (float) $e->getMontoNeto(), $enlaces));
            $debe = (float) ($porMoneda[$moneda]['saldo'] ?? 0);

            if ($pide <= $debe + 0.005) {
                continue;
            }

            $this->logger->info('[prepago] enlace manual por encima de la deuda: se anula.', [
                'reserva' => (string) $reservaId,
                'enlaces' => array_map(static fn (FinEnlacePago $e): string => (string) $e->getId(), $enlaces),
                'pide' => $pide,
                'debe' => $debe,
            ]);

            foreach ($enlaces as $enlace) {
                $this->enlaces->anular($enlace);
            }
        }
    }

    /** ¿Hay un enlace emitido por una PERSONA que todavía se pueda pagar? */
    private function hayManualVivo(Uuid $reservaId): bool
    {
        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if ($enlace->estaVigente() && $enlace->getCreadoPor() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Soy el único emitiendo el adelanto de esta reserva?
     *
     * ⚠️ El lock vive en la SESIÓN de MySQL, no en la transacción: **no se suelta al hacer
     * commit**, sólo con `RELEASE_LOCK` o al caerse la conexión. Por eso el `finally` de arriba
     * no es opcional — sin él, una excepción dejaría el turno retenido lo que viva el worker,
     * que con supervisor son días.
     *
     * Eso sí, soltar funciona **aunque Doctrine haya cerrado el EntityManager**: cerrar el EM no
     * cierra la conexión DBAL, que es quien tiene el lock.
     *
     * ⚠️ Y aquí, ante la duda, se RETIRA — al revés que en el turno del agente, donde no
     * contestar es peor que contestar dos veces. Un enlace de cobro es al contrario: emitir dos
     * es un doble cobro, y no emitir no se pierde, porque cualquier movimiento posterior de esa
     * reserva vuelve a pasar por el mismo `postFlush`.
     */
    private function tomarElTurno(Uuid $reservaId): bool
    {
        try {
            // 3 segundos: `crear()` es un persist y un flush, milisegundos. Esperar ese poco
            // casi siempre gana el turno, y es preferible a retirarse y dejar el enlace con el
            // importe viejo hasta el siguiente movimiento.
            /** @var int|string|null $resultado 1 = turno ganado, 0 = ocupado, NULL = error interno. */
            $resultado = $this->em->getConnection()
                ->fetchOne('SELECT GET_LOCK(?, 3)', [$this->nombreDelLock($reservaId)]);
        } catch (Throwable $e) {
            $this->logger->error('[prepago] no se pudo pedir el turno; me retiro.', [
                'reserva' => (string) $reservaId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // `GET_LOCK` devuelve NULL en error interno, SIN lanzar. Se trata igual: retirarse.
        if ($resultado === null || (int) $resultado !== 1) {
            $this->logger->info('[prepago] el turno lo tiene otro worker; me retiro.', [
                'reserva' => (string) $reservaId,
            ]);

            return false;
        }

        return true;
    }

    private function soltarElTurno(Uuid $reservaId): void
    {
        try {
            $this->em->getConnection()
                ->executeStatement('SELECT RELEASE_LOCK(?)', [$this->nombreDelLock($reservaId)]);
        } catch (Throwable $e) {
            // Que no se pueda soltar no puede tumbar nada: MySQL lo libera al cerrar la sesión.
            // Pero se anota: si esto sale a menudo, hay algo raro con la conexión.
            $this->logger->warning('[prepago] no se pudo soltar el turno.', [
                'reserva' => (string) $reservaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * El nombre lleva la BASE DE DATOS delante, por lo mismo que el turno del agente:
     * `GET_LOCK` no sabe de bases y su espacio de nombres es el SERVIDOR entero. Con un clon en
     * la misma máquina, un worker de pruebas bloquearía al de producción para la misma reserva
     * — y el síntoma sería un retiro silencioso, de los más difíciles de atribuir.
     */
    private function nombreDelLock(Uuid $reservaId): string
    {
        return substr('prepago-' . $this->em->getConnection()->getDatabase() . '-' . $reservaId, 0, 64);
    }

    /**
     * Anula sólo los enlaces vivos que emitió el SISTEMA (sin autor).
     *
     * Se usa cuando el adelanto deja de proceder. Acotado a los automáticos a propósito: uno
     * emitido a mano es la decisión de una persona —puede estar cobrando otra cosa, o queriendo
     * cobrar igual— y retirárselo sin avisar sería peor que dejarlo.
     */
    /**
     * @param list<FinEnlacePago> $excepto Los que se están reutilizando.
     */
    private function anularAutomaticosVigentes(Uuid $reservaId, array $excepto = []): void
    {
        $respetar = array_map(static fn (FinEnlacePago $e): string => (string) $e->getId(), $excepto);

        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if (\in_array((string) $enlace->getId(), $respetar, true)) {
                continue;
            }

            if ($enlace->estaVigente() && $enlace->getCreadoPor() === null) {
                $this->enlaces->anular($enlace);
            }
        }
    }

    /**
     * El cobro vivo por ese mismo importe, si lo hay: un enlace, o los TRAMOS que lo suman.
     *
     * `estaVigente()` y no `estado === PENDIENTE`: un FALLIDO sigue siendo pagable —que una
     * tarjeta rebote no invalida el enlace, el cliente reintenta con otra en la misma URL— y
     * emitir uno nuevo por cada rechazo llenaría la reserva de enlaces muertos.
     *
     * ⚠️ **Los tramos se comparan por su SUMA** (16/10/2026). Un saldo partido en dos por el tope
     * de la pasarela no coincide con ningún enlace suelto, y sin esto cada recálculo emitía los
     * tramos otra vez. Pagada la parte 1, lo que se pide es lo que vale la 2, y coincide sola.
     *
     * @return non-empty-list<FinEnlacePago>|null
     */
    private function vigentesPorImporte(Uuid $reservaId, string $monto, ?string $moneda): ?array
    {
        // ⚠️ **La MONEDA también, desde el 06/09/2026.** Comparaba sólo el número, así que un
        // enlace vivo de 100 PEN se daba por bueno para un cobro de 100 USD: se reutilizaba y el
        // huésped pagaba **cuatro veces menos** sin que fallara nada.
        //
        // Sin moneda declarada no se reutiliza nada: preferimos emitir de más a cobrar en la
        // divisa equivocada.
        if ($moneda === null) {
            return null;
        }

        $tramos = [];
        $suma = 0.0;

        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if (!$enlace->estaVigente() || $enlace->getMonedaCodigo() !== $moneda) {
                continue;
            }

            if (FinEnlacePagoService::tramoDe($enlace) === null) {
                if (abs((float) $enlace->getMontoNeto() - (float) $monto) < 0.005) {
                    return [$enlace];
                }

                continue;
            }

            $tramos[] = $enlace;
            $suma += (float) $enlace->getMontoNeto();
        }

        return $tramos !== [] && abs($suma - (float) $monto) < 0.005 ? $tramos : null;
    }

    /**
     * Lo que el aviso del saldo (`saldo_pendiente`) puede anunciar: el cobro vivo del SISTEMA,
     * sumando sus tramos, o `null` si no hay nada que anunciar.
     *
     * Tampoco si lo vivo son las partes que quedan de un cobro empezado: serían el resto del
     * ADELANTO, y el aviso lo presentaría como el saldo.
     *
     * @return array{moneda: string, neto: string}|null
     */
    public function saldoVivoDelSistema(PmsReserva $reserva): ?array
    {
        $info = $reserva->getInformacionFinanciera();
        $id = $reserva->getId();

        if ($info === null || $id === null || $this->quedanTramosDeUnCobroEmpezado($info, $id)) {
            return null;
        }

        $moneda = null;
        $suma = 0.0;

        foreach ($this->pagables($reserva) as $enlace) {
            if ($enlace->getCreadoPor() !== null) {
                continue;
            }

            $moneda ??= $enlace->getMonedaCodigo() ?? '';

            if ($enlace->getMonedaCodigo() === $moneda) {
                $suma += (float) $enlace->getMontoNeto();
            }
        }

        return $moneda === null ? null : ['moneda' => $moneda, 'neto' => number_format($suma, 2, '.', '')];
    }

    /**
     * `enlace` es el primero; con tramos, `enlaces` los trae todos y `monto` es lo que suman.
     *
     * @param non-empty-list<FinEnlacePago> $enlaces
     * @param array{monto: string, claveI18n: string, politica: string} $prepago
     *
     * @return array{enlace: FinEnlacePago, enlaces: non-empty-list<FinEnlacePago>, url: string, monto: string, moneda: string, politica: string, reutilizado: bool, esSaldo: bool}
     */
    private function respuesta(array $enlaces, array $prepago, bool $reutilizado, bool $esSaldo): array
    {
        $enlace = $enlaces[0];

        return [
            'enlace' => $enlace,
            'enlaces' => $enlaces,
            'url' => $this->enlaces->urlPublica($enlace),
            'monto' => number_format(array_sum(array_map(static fn (FinEnlacePago $e): float => (float) $e->getMontoNeto(), $enlaces)), 2, '.', ''),
            'moneda' => $enlace->getMonedaCodigo() ?? '',
            'politica' => $prepago['politica'],
            'reutilizado' => $reutilizado,
            'esSaldo' => $esSaldo,
        ];
    }

    /**
     * Qué importe y con qué nombre se emite: el ADELANTO, o el TOTAL desde el día de llegada.
     *
     * ── La regla no es nueva; lo nuevo es que este camino la lea ──────────────────
     * `PmsPrepagoCalculador::queSePide()` la decidió el 28/08/2026 y gobernaba el MENSAJE que
     * se le redacta al huésped. El emisor de enlaces preguntaba por `pendiente()`, que no mira
     * fechas, así que desde el día de llegada el texto decía «paga el total» y el enlace que
     * acompañaba a ese texto se titulaba «Adelanto de reserva» por una fracción.
     *
     * Se vio en producción sobre dos reservas (PQK8EG y 4P559S): en las dos el operador emitió
     * a mano el enlace por el total —el mismo día de la llegada, que es cuando la regla lo
     * pide— y el camino automático le puso enfrente un adelanto por la primera noche.
     *
     * ── Lo usan los TRES caminos, y eso no es opcional ───────────────────────────
     * `emitirSimulado()` (la previsualización del agente), `emitir()` (la skill, con una
     * persona confirmando) y `emitirConTurno()` (el automático). Una previsualización que no
     * coincide con lo que luego ocurre es peor que no previsualizar, así que el importe sale
     * del mismo sitio para los tres.
     *
     * ⚠️ **El público NO cambia: sólo el importe.** Quien llega aquí ya pasó por
     * `pendiente() !== null`, o sea que tiene política de prepago, su canal no cobró por
     * nosotros, hay base y **no hay ni un pago registrado**. Un establecimiento sin política de
     * adelanto sigue sin recibir enlaces automáticos aunque el mensaje le pida el total el día
     * de la llegada; y una reserva con un pago a cuenta sigue sin recibirlos, que es la regla
     * de siempre. Ampliar eso sería otra decisión, y no es ésta.
     *
     * ⚠️ El TOTAL sale del resolver de origen —saldo de la moneda que más se debe—, no del
     * calculador: el adelanto es una petición mono-moneda que `base()` convierte, pero el saldo
     * **no se convierte nunca** (§12.2b). Con deuda en dos divisas se cobra la mayor, que es lo
     * que responde `crear()` cuando nadie le dice la moneda.
     *
     * @param array{monto: string, claveI18n: string, politica: string} $prepago
     *
     * @return array{monto: string, moneda: ?string, concepto: string, esSaldo: bool}|null `null`
     *         cuando toca el total y no queda saldo que cobrar: no hay enlace que emitir.
     */
    private function loQueSePide(PmsReserva $reserva, PmsInformacionFinanciera $info, array $prepago, ?PmsQueSePide $forzar = null): ?array
    {
        if (($forzar ?? $this->calculador->queSePide($info)) === PmsQueSePide::ADELANTO) {
            // La moneda se DICE: el importe viene en la de la cabecera. Ver `emitir()`.
            $moneda = $info->getMoneda()?->getId();
            $esTodo = $this->esElSaldoEntero($info, $moneda, $prepago['monto']);

            return [
                'monto' => $prepago['monto'],
                'moneda' => $moneda,
                // ⚠️ Si el «adelanto» ya es el saldo entero, se llama SALDO desde el primer
                // día. Pasa en las estancias de UNA noche: `primera_noche_total` reparte la
                // base entre las noches y cobra una, así que con una sola noche la fracción es
                // el total. Titularlo «Adelanto de reserva» sería mentirle al extracto de la
                // tarjeta por un cobro que es el pago completo.
                //
                // Se decide al EMITIR y no al cruzar el día de llegada a propósito: relevar el
                // enlace entonces mataría uno que el huésped ya tiene en su WhatsApp y le
                // mandaría otra URL sólo por cambiar un rótulo.
                // Sin pagos —es la rama del adelanto—, el saldo entero es el PAGO TOTAL.
                'concepto' => $esTodo ? $this->conceptoTotal($reserva) : $this->concepto($reserva),
                // Lo que se está pidiendo, para que quien lo enseñe no tenga que deducirlo del
                // concepto. Sin esto la skill del agente preguntaba «¿emito el enlace de
                // ADELANTO de 188.88?» sobre un cobro que era el saldo entero: el importe
                // correcto con el rótulo equivocado, que es de lo que nadie sospecha.
                'esSaldo' => $esTodo,
            ];
        }

        $id = $reserva->getId();
        $origen = $id === null ? null : $this->origen->resolver($id);

        // Sin saldo no hay nada que cobrar. No debería pasar —quien llega aquí tiene cargos y
        // ningún pago— pero el saldo lo calcula otra pieza y emitir un enlace de 0.00 es peor
        // que no emitir.
        if ($origen === null || (float) $origen->saldoPendiente <= 0.005) {
            return null;
        }

        return [
            'monto' => $origen->saldoPendiente,
            'moneda' => $origen->moneda,
            // «Pago total» si todavía no ha pagado nada —total pedido, última hora, una sola
            // noche—; «Saldo» si ya pagó algo. Es el mismo rótulo que pone la guía del huésped
            // («Total a pagar» / «Saldo por pagar»), y lo lee en el extracto de su tarjeta.
            'concepto' => $this->calculador->hayPagos($info) ? $this->conceptoSaldo($reserva) : $this->conceptoTotal($reserva),
            'esSaldo' => true,
        ];
    }

    /**
     * ¿El importe que se va a pedir es ya todo lo que se debe en esa moneda?
     *
     * Se compara contra los totales de la cabecera y no contra el resolver: el adelanto está en
     * la moneda de la cabecera y `PmsTotalesPorMoneda` ya está calculado sobre el `$info` que
     * tenemos delante — preguntar al resolver sería una consulta más para saber lo mismo.
     */
    private function esElSaldoEntero(PmsInformacionFinanciera $info, ?string $moneda, string $monto): bool
    {
        if ($moneda === null) {
            return false;
        }

        $saldo = PmsTotalesPorMoneda::de($info)->porMoneda[$moneda]['saldo'] ?? null;

        return $saldo !== null && abs((float) $saldo - (float) $monto) < 0.005;
    }

    /**
     * El concepto de un cobro por el SALDO, que no es el del adelanto.
     *
     * Lo lee el huésped en el extracto de su tarjeta: llamar «Adelanto de reserva» a un cobro
     * del total es contradecir al mensaje que se le acaba de mandar. Espejo de `concepto()`,
     * que sigue siendo el del adelanto.
     */
    public function conceptoSaldo(PmsReserva $reserva): string
    {
        return substr(sprintf(
            'Saldo de reserva %s — %s',
            $reserva->getLocalizador(),
            $reserva->getUnidadesAggregate() ?: $reserva->getNombreHabitacion(),
        ), 0, 255);
    }

    /** El concepto de un cobro por TODO, cuando todavía no se ha pagado nada. Ver `loQueSePide()`. */
    public function conceptoTotal(PmsReserva $reserva): string
    {
        return substr(sprintf(
            'Pago total de reserva %s — %s',
            $reserva->getLocalizador(),
            $reserva->getUnidadesAggregate() ?: $reserva->getNombreHabitacion(),
        ), 0, 255);
    }

    /**
     * Lo que el huésped lee en la página de pago. Dice que es un adelanto, no el total.
     *
     * Pública porque la usan los DOS caminos que emiten un enlace de adelanto: la skill del
     * agente por `emitir()`, y el atajo del panel, que la recibe prellenada en el formulario.
     * Si cada uno redactara la suya, el mismo cobro tendría dos nombres en el extracto del
     * huésped según quién lo emitiera.
     */
    public function concepto(PmsReserva $reserva): string
    {
        return substr(sprintf(
            'Adelanto de reserva %s — %s',
            $reserva->getLocalizador(),
            $reserva->getUnidadesAggregate() ?: $reserva->getNombreHabitacion(),
        ), 0, 255);
    }
}
