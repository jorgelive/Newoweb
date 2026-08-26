<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Entity\User;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use Psr\Log\LoggerInterface;
use Throwable;
use Doctrine\ORM\EntityManagerInterface;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
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
    public function __construct(
        private PmsPrepagoCalculador $calculador,
        // Para el turno (GET_LOCK) y para releer la cabecera ya bloqueada.
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private FinEnlacePagoService $enlaces,
        private FinEnlacePagoRepository $repositorio,
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
     * @return array{monto: string, moneda: string, politica: string, reutilizado: bool}|null
     *         `null` cuando no hay prepago que pedir, por el motivo que sea.
     */
    public function emitirSimulado(PmsReserva $reserva): ?array
    {
        $info = $reserva->getInformacionFinanciera();
        $id = $reserva->getId();

        if (!$this->activo || $info === null || $id === null) {
            return null;
        }

        $prepago = $this->calculador->pendiente($info);

        if ($prepago === null) {
            return null;
        }

        $existente = $this->vigentePorImporte($id, $prepago['monto']);

        return [
            'monto' => $prepago['monto'],
            // La moneda de la cabecera: es en la que está el importe que devuelve el
            // calculador, y en la que se emitirá el enlace.
            'moneda' => $info->getMoneda()?->getId() ?? '',
            'politica' => $prepago['politica'],
            'reutilizado' => $existente !== null,
        ];
    }

    /**
     * El enlace con el que se paga el prepago de esta reserva.
     *
     * @return array{enlace: FinEnlacePago, url: string, monto: string, moneda: string, politica: string, reutilizado: bool}
     *
     * @throws DomainException con un mensaje que se le puede leer al operador tal cual.
     */
    public function emitir(PmsReserva $reserva, ?User $creadoPor = null): array
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

        $prepago = $this->calculador->pendiente($info);

        if ($prepago === null) {
            throw new DomainException(
                'Esta reserva no tiene prepago pendiente: o ya hay un pago registrado, o su '
                . 'establecimiento no pide adelanto, o el canal cobró por nosotros.'
            );
        }

        $existente = $this->vigentePorImporte($id, $prepago['monto']);

        if ($existente !== null) {
            return $this->respuesta($existente, $prepago, reutilizado: true);
        }

        // ⚠️ El importe CAMBIÓ: se anula el enlace vivo por la cantidad vieja antes de emitir.
        //
        // Sin esto el huésped acaba con DOS enlaces vivos por importes distintos —el de antes
        // del cargo extra y el de después— y puede pagar el que no toca. Con emisión manual
        // casi no pasa, porque hay una persona mirando; en cuanto la emisión es automática
        // (ver `emitirPorCambioDeCargos()`) pasa solo.
        //
        // Se anula, no se borra: el enlace que se mandó existió y su rastro es parte de lo que
        // se le dijo a esa persona.
        $this->anularVigentes($id);

        $enlace = $this->enlaces->crear(
            origenTipo: FinOrigenCobro::PMS_RESERVA,
            origenId: $id,
            montoNeto: $prepago['monto'],
            // El recargo de tarjeta se traslada igual que en cualquier otro cobro: la
            // comisión de la pasarela no la absorbe la casa por ser un adelanto.
            conRecargo: true,
            concepto: $this->concepto($reserva),
            // Ver la nota de `emitirPorCambioDeCargos()`: el importe viene en la moneda de la
            // cabecera y hay que decirlo. El fallo era el mismo aquí, sólo que con una persona
            // delante que podía notarlo.
            moneda: $info->getMoneda()?->getId(),
            creadoPor: $creadoPor,
        );

        return $this->respuesta($enlace, $prepago, reutilizado: false);
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
     * El emitido a mano conserva su vigencia por defecto, porque ahí hay una persona detrás.
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
            // Beds24 y el del cron de facturas— pasan los dos por `vigentePorImporte()` sin
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
     */
    private function emitirConTurno(PmsInformacionFinanciera $info, PmsReserva $reserva, Uuid $id): ?FinEnlacePago
    {
            // ⚠️ Cabecera ANULADA (todas las estancias canceladas): aquí no se pide adelanto.
            //
            // No basta con `pendiente()`: con la cabecera inactiva sus cargos dejan de sumar
            // PERO la PENALIZACIÓN sigue contando (§12.7), así que la base no es cero y el
            // calculador devolvería una fracción de la penalidad. Emitir un «Adelanto de
            // reserva» sobre una reserva cancelada no tiene ningún sentido.
            if (!$info->isActiva()) {
                $this->anularAutomaticosVigentes($id);

                return null;
            }

            $prepago = $this->calculador->pendiente($info);

            if ($prepago === null) {
                // 🔴 Ya no procede pedir adelanto —lo pagó por transferencia, se canceló sin
                // penalización, cambió la política—, así que el enlace vivo tiene que MORIR.
                //
                // Sin esto, y como los automáticos se emiten SIN caducidad, quedaría un enlace
                // pagable para siempre en el WhatsApp de alguien que ya pagó. La ausencia de
                // caducidad, que es lo correcto mientras el cobro procede, se vuelve una trampa
                // en cuanto deja de proceder.
                $this->anularAutomaticosVigentes($id);

                return null;
            }

            // Ya hay uno vivo por ese importe: nada que hacer. Es lo que evita emitir un
            // enlace nuevo en cada recálculo.
            if ($this->vigentePorImporte($id, $prepago['monto']) !== null) {
                return null;
            }

            $this->anularVigentes($id);

            return $this->enlaces->crear(
                origenTipo: FinOrigenCobro::PMS_RESERVA,
                origenId: $id,
                montoNeto: $prepago['monto'],
                conRecargo: true,
                concepto: $this->concepto($reserva),
                // ⚠️ La moneda se DICE, no se deduce. `pendiente()` devuelve el importe en la
                // moneda de la CABECERA (`base()` lo convierte), pero `crear()` sin este
                // parámetro se lo pregunta al resolver, que responde «la de mayor saldo». En
                // una reserva con cargos en soles y cabecera en dólares son monedas distintas:
                // el enlace habría cobrado 46.42 PEN donde el cálculo decía 46.42 USD.
                moneda: $info->getMoneda()?->getId(),
                // Sin persona detrás: el enlace queda sin `creadoPorNombre`, que es la verdad.
                creadoPor: null,
                vigenciaDias: 0,
            );
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
    private function anularAutomaticosVigentes(Uuid $reservaId): void
    {
        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if ($enlace->estaVigente() && $enlace->getCreadoPor() === null) {
                $this->enlaces->anular($enlace);
            }
        }
    }

    /**
     * Anula los enlaces de prepago vivos de esa reserva.
     *
     * Se llama justo antes de emitir uno por un importe distinto, para que no queden dos
     * pagables a la vez. Un enlace ya PAGADO no está vigente, así que no entra aquí.
     */
    private function anularVigentes(Uuid $reservaId): void
    {
        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if ($enlace->estaVigente()) {
                $this->enlaces->anular($enlace);
            }
        }
    }

    /**
     * Enlace vivo por ese mismo importe, si lo hay.
     *
     * `estaVigente()` y no `estado === PENDIENTE`: un FALLIDO sigue siendo pagable —que una
     * tarjeta rebote no invalida el enlace, el cliente reintenta con otra en la misma URL— y
     * emitir uno nuevo por cada rechazo llenaría la reserva de enlaces muertos.
     */
    private function vigentePorImporte(Uuid $reservaId, string $monto): ?FinEnlacePago
    {
        foreach ($this->repositorio->porOrigen(FinOrigenCobro::PMS_RESERVA, $reservaId) as $enlace) {
            if ($enlace->estaVigente() && (float) $enlace->getMontoNeto() === (float) $monto) {
                return $enlace;
            }
        }

        return null;
    }

    /**
     * @param array{monto: string, claveI18n: string, politica: string} $prepago
     *
     * @return array{enlace: FinEnlacePago, url: string, monto: string, moneda: string, politica: string, reutilizado: bool}
     */
    private function respuesta(FinEnlacePago $enlace, array $prepago, bool $reutilizado): array
    {
        return [
            'enlace' => $enlace,
            'url' => $this->enlaces->urlPublica($enlace),
            'monto' => $enlace->getMontoNeto(),
            'moneda' => $enlace->getMonedaCodigo() ?? '',
            'politica' => $prepago['politica'],
            'reutilizado' => $reutilizado,
        ];
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
