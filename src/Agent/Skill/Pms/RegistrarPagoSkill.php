<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Service\Finance\MonedaResolver;
use App\Pms\Service\Finance\TipoCambioDelDia;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Registra un pago recibido del huésped. **Escribe, y toca dinero.**
 *
 * Hermana de {@see RegistrarCargoSkill}: aquélla apunta lo que el huésped DEBE, ésta lo que ha
 * ENTREGADO. Separadas porque son dos hechos distintos que ocurren en momentos distintos.
 *
 * ### 💳 La comisión de tarjeta: se pregunta, no se supone
 *
 * `pms_pago_financiero.monto` guarda el **neto** —lo que abona la deuda—, y la comisión queda
 * aparte en `comisionPorcentaje`. Lo que pasó por el POS es `neto × (1 + pct/100)`.
 *
 * Así que «pagó 20 soles con tarjeta» es ambiguo y la diferencia es dinero: o abona 18.96 y se
 * cobraron 20, o abona 20 y se cobraron 21.10. La skill **no elige**: cuando el medio lleva
 * comisión y no se ha dicho cuál de los dos es, devuelve `falta_dato` con las dos cifras ya
 * calculadas para que el operador señale. Con los medios al 0% no pregunta, porque coinciden.
 *
 * ### 🪞 Espejo del panel — hay que tocar los dos
 *
 * Las fórmulas son las mismas que las del formulario de `util`:
 * `util/src/types/pmsFinanzasModel.ts` → `totalConComision()` y `netoDesdeTotal()`. Si cambia el
 * redondeo o la fórmula en un lado, **hay que cambiarlo en el otro** o el agente y el panel
 * guardarán importes distintos para el mismo pago. Ver docs/Mensajeria.md §11.
 *
 * ### El porcentaje no se escribe aquí
 *
 * Sale de `PmsMedioPago::comisionPorcentaje()`, incluso en el texto que lee el modelo: la
 * descripción se compone en `definicion()` interpolando el valor real. Teclear «5.5» en el
 * prompt habría creado una segunda verdad que nadie recuerda actualizar el día que cambie.
 */
final readonly class RegistrarPagoSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MonedaResolver $monedas,
        private TipoCambioDelDia $tipoCambio,
    ) {}

    public function nombre(): string
    {
        return 'registrar_pago';
    }

    public function definicion(): SkillDefinition
    {
        $pctTarjeta = PmsMedioPago::TARJETA_CREDITO->comisionPorcentaje();

        return new SkillDefinition(
            descripcion: sprintf(
                'Registra un pago que el huésped ha entregado (efectivo, Yape, tarjeta, '
                . 'transferencia…). MODIFICA DATOS Y TOCA DINERO: llama primero con '
                . 'confirmado=false, enseña al operador el huésped, el importe, el medio y el '
                . 'saldo que quedará, y espera su sí antes de confirmado=true. CONFIRMA SIEMPRE '
                . 'DE QUIÉN ES: di el nombre del huésped y la casita en voz alta antes de '
                . 'registrar nada, porque un pago en la cuenta equivocada descuadra dos '
                . 'reservas. Si te dicen «el pasajero de la 2» sin nombre, usa '
                . 'consultar_ocupacion con la fecha de hoy para saber quién es y confirma el '
                . 'nombre con el operador. Pide SIEMPRE el medio de pago si no te lo han dicho: '
                . 'no lo supongas. La tarjeta de crédito lleva %s%%%% de comisión, que se aplica '
                . 'sola: no la calcules tú. Si el medio lleva comisión y no está claro si el '
                . 'importe la incluye, la skill te devolverá las dos cifras para que preguntes '
                . 'al operador cuál es. Necesita el reserva_id.',
                $pctTarjeta
            ),
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva.'),
                SkillParameter::texto('importe', 'Importe del pago, sólo el número. Ej: "20" o "20.50".'),
                SkillParameter::texto('medio_pago', 'Cómo pagó: ' . implode(', ', array_map(
                    static fn (PmsMedioPago $m) => $m->value,
                    PmsMedioPago::cases()
                )) . '.'),
                SkillParameter::texto('moneda', 'Moneda del importe (PEN, USD…). Si se omite, la '
                    . 'de la cuenta.', requerido: false),
                SkillParameter::texto('importe_incluye_comision', 'Sólo para medios con '
                    . 'comisión. "si" = el importe es lo que se pasó por el POS; "no" = es lo '
                    . 'que debe abonar a la deuda. Omítelo para que la skill te dé las dos '
                    . 'cifras y puedas preguntar.', requerido: false),
                SkillParameter::texto('referencia', 'Nº de operación, voucher o referencia, si '
                    . 'la hay.', requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la confirmación explícita '
                    . 'del operador. false para previsualizar.'),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));
        $importe = (float) str_replace(',', '.', (string) ($entrada['importe'] ?? '0'));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error('El reserva_id no es válido.');
        }

        if ($importe <= 0) {
            return SkillResult::error('El importe debe ser mayor que cero.');
        }

        $medio = PmsMedioPago::tryFrom(strtolower(trim((string) ($entrada['medio_pago'] ?? ''))));
        if ($medio === null) {
            return SkillResult::error(sprintf(
                'Falta el medio de pago o no se reconoce. Pregunta al operador cómo pagó. '
                . 'Opciones: %s.',
                implode(', ', array_map(static fn (PmsMedioPago $m) => $m->value, PmsMedioPago::cases()))
            ));
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        $info = $this->em->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        if ($info === null) {
            return SkillResult::error(
                'Esta reserva no tiene cuenta financiera abierta. Ábrela desde el panel antes de '
                . 'registrar un pago.'
            );
        }

        $monedaCuenta = $info->getMoneda();
        if ($monedaCuenta === null) {
            return SkillResult::error('La cuenta de esta reserva no tiene moneda definida.');
        }

        $huesped = trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente());
        $pct = (float) $medio->comisionPorcentaje();

        // 💳 Con comisión, «20» no basta: hay que saber si los 20 son lo cobrado o lo que abona.
        // Se devuelven las dos cifras en vez de elegir una, que aquí es dinero.
        $incluye = strtolower(trim((string) ($entrada['importe_incluye_comision'] ?? '')));

        if ($pct > 0.0 && !in_array($incluye, ['si', 'sí', 'no'], true)) {
            return SkillResult::ok([
                'reserva_id' => $reservaId,
                'huesped' => $huesped,
                'medio' => $medio->label(),
                'comision_porcentaje' => $medio->comisionPorcentaje(),
                'falta_dato' => 'importe_incluye_comision',
                'si_incluye_comision' => $this->desglose($importe, $pct, true, $monedaCuenta->getId()),
                'si_no_incluye_comision' => $this->desglose($importe, $pct, false, $monedaCuenta->getId()),
                'pregunta' => sprintf(
                    'Con %s hay %s%% de comisión. ¿Los %.2f son lo que se pasó por el POS, o lo '
                    . 'que tiene que abonar a la deuda? Pregúntaselo al operador y vuelve a '
                    . 'llamarme con importe_incluye_comision.',
                    $medio->label(),
                    $medio->comisionPorcentaje(),
                    $importe
                ),
            ]);
        }

        $cobrado = $pct > 0.0 && $incluye !== 'no' ? $importe : $importe * (1 + $pct / 100);
        $neto = $pct > 0.0 && $incluye !== 'no' ? $importe / (1 + $pct / 100) : $importe;

        // Conversión de moneda: mismo criterio que RegistrarCargoSkill — el pago se guarda en
        // la moneda de la cuenta, con el tipo aplicado registrado para poder auditarlo.
        $monedaIndicada = strtoupper(trim((string) ($entrada['moneda'] ?? '')));
        $monedaOrigen = $monedaIndicada !== ''
            ? $this->monedas->resolve($monedaIndicada)
            : $monedaCuenta;

        $tipoAplicado = null;

        if ($monedaOrigen->getId() !== $monedaCuenta->getId()) {
            $tipoAplicado = $this->tipoCambio->venta();

            if ($tipoAplicado === null) {
                return SkillResult::error(sprintf(
                    'No hay tipo de cambio disponible para pasar de %s a %s. Registra el pago '
                    . 'desde el panel indicando el tipo a mano.',
                    $monedaOrigen->getId(),
                    $monedaCuenta->getId()
                ));
            }

            $factor = $monedaOrigen->getId() === 'PEN'
                ? 1 / (float) $tipoAplicado
                : (float) $tipoAplicado;

            $cobrado *= $factor;
            $neto *= $factor;
        }

        $cobrado = round($cobrado, 2);
        $neto = round($neto, 2);

        $saldoAntes = (float) $info->getSaldo();

        $resumen = [
            'reserva_id' => $reservaId,
            'huesped' => $huesped,
            'localizador' => $reserva->getLocalizador(),
            'medio' => $medio->label(),
            'cobrado_al_huesped' => sprintf('%.2f %s', $cobrado, $monedaCuenta->getId()),
            'comision_porcentaje' => $medio->comisionPorcentaje(),
            'abona_a_la_deuda' => sprintf('%.2f %s', $neto, $monedaCuenta->getId()),
            'tipo_cambio_aplicado' => $tipoAplicado,
            'saldo_antes' => sprintf('%.2f %s', $saldoAntes, $monedaCuenta->getId()),
            'saldo_despues' => sprintf('%.2f %s', round($saldoAntes - $neto, 2), $monedaCuenta->getId()),
        ];

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'registrado' => false,
                'motivo' => 'falta_confirmacion',
                'previsualizacion' => sprintf(
                    'Se registrará un pago de %s por %s a nombre de %s (%s). Abona %s y el '
                    . 'saldo quedará en %s. CONFIRMA CON EL OPERADOR QUE ES ESE HUÉSPED antes '
                    . 'de aplicarlo.',
                    $resumen['cobrado_al_huesped'],
                    $medio->label(),
                    $huesped !== '' ? $huesped : 'sin nombre',
                    $reserva->getLocalizador() ?? 'sin localizador',
                    $resumen['abona_a_la_deuda'],
                    $resumen['saldo_despues']
                ),
            ]);
        }

        $pago = new PmsPagoFinanciero();
        $pago->setMonto(sprintf('%.2f', $neto));
        $pago->setMoneda($monedaCuenta);
        $pago->setMedioPago($medio);
        $pago->setComisionPorcentaje($medio->comisionPorcentaje());
        $pago->setFechaPago(new DateTimeImmutable());
        $pago->setEsAutomatico(false);

        $referencia = trim((string) ($entrada['referencia'] ?? ''));
        if ($referencia !== '') {
            $pago->setReferencia($referencia);
        }

        if ($tipoAplicado !== null) {
            $pago->setTipoCambio($tipoAplicado);
        }

        $info->addPago($pago);
        $this->em->persist($pago);
        $this->em->flush();

        return SkillResult::ok($resumen + [
            'registrado' => true,
            'pago_id' => (string) $pago->getId(),
            // El saldo se relee tras el flush: lo recalcula el listener de coherencia, así que
            // devolver la resta de antes sería contar lo que creemos, no lo que quedó.
            'saldo_real' => sprintf('%.2f %s', (float) $info->getSaldo(), $monedaCuenta->getId()),
            'mensaje' => sprintf('Registrado el pago de %s de %s.', $resumen['cobrado_al_huesped'], $huesped),
        ]);
    }

    /**
     * Las dos lecturas posibles de un importe con comisión.
     *
     * 🪞 Espejo de `totalConComision()` y `netoDesdeTotal()` de
     * `util/src/types/pmsFinanzasModel.ts`. Si cambia una fórmula, cambian las dos.
     *
     * @return array<string, string>
     */
    private function desglose(float $importe, float $pct, bool $incluye, ?string $moneda): array
    {
        $cobrado = $incluye ? $importe : $importe * (1 + $pct / 100);
        $neto    = $incluye ? $importe / (1 + $pct / 100) : $importe;

        return [
            'cobrado_al_huesped' => sprintf('%.2f %s', round($cobrado, 2), $moneda),
            'comision'           => sprintf('%.2f %s', round($cobrado - $neto, 2), $moneda),
            'abona_a_la_deuda'   => sprintf('%.2f %s', round($neto, 2), $moneda),
        ];
    }
}
