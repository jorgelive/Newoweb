<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Entity\User;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
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

        $enlace = $this->enlaces->crear(
            origenTipo: FinOrigenCobro::PMS_RESERVA,
            origenId: $id,
            montoNeto: $prepago['monto'],
            // El recargo de tarjeta se traslada igual que en cualquier otro cobro: la
            // comisión de la pasarela no la absorbe la casa por ser un adelanto.
            conRecargo: true,
            concepto: $this->concepto($reserva),
            creadoPor: $creadoPor,
        );

        return $this->respuesta($enlace, $prepago, reutilizado: false);
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
