<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Finance\MonedaResolver;
use App\Pms\Service\Finance\PmsCuentaSimulador;
use App\Pms\Service\Finance\TipoCambioDelDia;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Añade un cargo a la cuenta de una reserva. **Escribe.**
 *
 * Está separada de {@see AplicarCambioHorarioSkill} a propósito: marcar una salida tardía y
 * cobrarla son dos decisiones que el negocio toma por separado —hay late check-outs de
 * cortesía—, así que el modelo encadena las dos skills sólo cuando el operador lo pide.
 *
 * ### 💱 La conversión de moneda
 *
 * El operador dice «20 soles», pero la cuenta puede llevarse en dólares. El cargo se guarda
 * SIEMPRE en la moneda de la cabecera financiera, convirtiendo con el tipo de cambio del día
 * ({@see TipoCambioDelDia}) y **dejando registrado el tipo aplicado** en el propio cargo, que
 * es lo que permite auditar después por qué salió ese importe.
 *
 * Sin tipo de cambio disponible NO se inventa uno: se devuelve el error y que lo meta una
 * persona. Un cargo con una cifra inventada es peor que un cargo que falta.
 */
final readonly class RegistrarCargoSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MonedaResolver $monedas,
        private TipoCambioDelDia $tipoCambio,
        private PmsCuentaSimulador $simulador,
    ) {}

    public function nombre(): string
    {
        return 'registrar_cargo';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Añade un cargo a la cuenta de una reserva (late check-out, limpieza '
                . 'extra, penalización, consumo…). MODIFICA DATOS: antes de llamarla con '
                . 'confirmado=true, dile al usuario el importe, la moneda y el concepto, y '
                . 'espera su sí. Con confirmado=false devuelve la previsualización —incluida '
                . 'la conversión de moneda— sin tocar nada. ENSEÑA SIEMPRE LA SIMULACIÓN: la respuesta trae un bloque «simulacion» con cargos, pagado y saldo ANTES y DESPUÉS. Muéstraselo al operador como una comparación, no lo resumas en una frase, y termina con la pregunta exacta de pregunta_aprobacion. No apliques nada hasta que responda que sí. Si el importe viene en otra moneda '
                . 'que la de la cuenta, se convierte automáticamente: no conviertas tú.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva.'),
                SkillParameter::texto('concepto', 'Descripción del cargo, como la verá el huésped.'),
                SkillParameter::texto('importe', 'Importe, sólo el número. Ej: "20" o "20.50".'),
                SkillParameter::texto('moneda', 'Moneda del importe indicado (PEN, USD…). Si se '
                    . 'omite, se asume la de la cuenta.', requerido: false),
                SkillParameter::texto('tipo', 'Tipo de cargo: alojamiento, limpieza, servicio, '
                    . 'penalizacion u otro. Por defecto "servicio".', requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la confirmación explícita '
                    . 'del usuario. false para previsualizar.'),
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
        $concepto = trim((string) ($entrada['concepto'] ?? ''));
        $importe = (float) str_replace(',', '.', (string) ($entrada['importe'] ?? '0'));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error('El reserva_id no es válido.');
        }

        if ($concepto === '') {
            return SkillResult::error('Indica el concepto del cargo.');
        }

        if ($importe <= 0) {
            return SkillResult::error('El importe debe ser mayor que cero.');
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        $info = $this->em->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        if ($info === null) {
            return SkillResult::error(
                'Esta reserva no tiene cuenta financiera abierta. Créala desde el panel antes de cargar nada.'
            );
        }

        $monedaCuenta = $info->getMoneda();
        if ($monedaCuenta === null) {
            return SkillResult::error('La cuenta de esta reserva no tiene moneda definida.');
        }

        $monedaIndicada = strtoupper(trim((string) ($entrada['moneda'] ?? '')));
        $monedaOrigen = $monedaIndicada !== ''
            ? $this->monedas->resolve($monedaIndicada)
            : $monedaCuenta;

        // 💱 Conversión: el cargo se guarda en la moneda de la cuenta, pase lo que pase.
        $tipoAplicado = null;
        $importeFinal = $importe;

        if ($monedaOrigen->getId() !== $monedaCuenta->getId()) {
            $tipoAplicado = $this->tipoCambio->venta();

            if ($tipoAplicado === null) {
                return SkillResult::error(sprintf(
                    'No hay tipo de cambio disponible para pasar de %s a %s. Registra el cargo '
                    . 'desde el panel indicando el tipo a mano.',
                    $monedaOrigen->getId(),
                    $monedaCuenta->getId()
                ));
            }

            // PEN → USD divide; USD → PEN multiplica. El tipo del día es soles por dólar.
            $importeFinal = $monedaOrigen->getId() === 'PEN'
                ? $importe / (float) $tipoAplicado
                : $importe * (float) $tipoAplicado;
        }

        $importeFinal = round($importeFinal, 2);

        $resumen = [
            'reserva_id' => $reservaId,
            'huesped' => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'concepto' => $concepto,
            'importe_indicado' => sprintf('%.2f %s', $importe, $monedaOrigen->getId()),
            'importe_en_cuenta' => sprintf('%.2f %s', $importeFinal, $monedaCuenta->getId()),
            'tipo_cambio_aplicado' => $tipoAplicado,
        ];

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                // Misma foto que en registrar_pago, por el mismo simulador: un cargo y un pago
                // mueven la misma cuenta y el operador debe verla igual en los dos casos.
                'simulacion' => $this->simulador->simular($info, deltaCargos: $importeFinal),
                'pregunta_aprobacion' => '¿Apruebas el cambio?',
                'previsualizacion' => $tipoAplicado !== null
                    ? sprintf(
                        'Se cargarán %s (equivalen a %s al tipo de cambio %s). Confírmalo para aplicarlo.',
                        $resumen['importe_indicado'],
                        $resumen['importe_en_cuenta'],
                        $tipoAplicado
                    )
                    : sprintf('Se cargarán %s. Confírmalo para aplicarlo.', $resumen['importe_en_cuenta']),
            ]);
        }

        $tipo = PmsTipoCargo::tryFrom(strtolower(trim((string) ($entrada['tipo'] ?? 'servicio'))))
            ?? PmsTipoCargo::SERVICIO;

        $cargo = new PmsCargoFinanciero();
        $cargo->setTipoCargo($tipo);
        $cargo->setDescripcion($concepto);
        $cargo->setMonto((string) $importeFinal);
        $cargo->setTotalLinea((string) $importeFinal);
        $cargo->setMoneda($monedaCuenta);

        // Se guarda el tipo aplicado aunque no haya habido conversión: en una auditoría, un
        // campo vacío y un campo a 1 no cuentan la misma historia.
        if ($tipoAplicado !== null) {
            $cargo->setTipoCambio($tipoAplicado);
        }

        $info->addCargo($cargo);
        $this->em->persist($cargo);
        $this->em->flush();

        return SkillResult::ok($resumen + [
            'aplicado' => true,
            'mensaje' => sprintf('Cargado %s por «%s».', $resumen['importe_en_cuenta'], $concepto),
        ]);
    }
}
