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
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La cuenta de una reserva: cargos y pagos, línea a línea.
 *
 * 🔗 Cuarto eslabón sobre `reserva_id`. **No duplica los totales**: `buscar_reserva` y
 * `consultar_mi_reserva` ya devuelven `total_amount`, `paid_amount` y `balance`, y para «¿qué
 * debe Carlos?» con eso basta. Ésta es para cuando la pregunta es «¿de qué se compone?» —
 * cuadrar una cuenta, explicarle un importe al huésped, revisar qué se cobró de más.
 *
 * Devolver siempre el detalle en las otras skills habría hinchado cada respuesta con veinte
 * líneas que el 90% de las veces no se miran, y eso se paga en tokens en cada consulta.
 *
 * ### 🔒 La usa también el huésped, y ahí el contexto MANDA
 *
 * Cuánto debe es de las dos o tres cosas que un huésped pregunta de verdad, y «¿por qué me
 * cobráis eso?» sólo se contesta con el desglose. Pero el huésped no tiene `buscar_reserva`:
 * no puede llegar a un `reserva_id` legítimamente, y el que apareciera en su turno sería un
 * id que alguien le sopló o que el modelo se inventó.
 *
 * Por eso, **cuando la conversación tiene contexto de reserva, el parámetro se IGNORA** y se
 * usa `ActorInterface::contextoId()`. No se valida el parámetro contra el contexto: se
 * descarta. Comparar deja la puerta abierta a que un cambio futuro invierta la condición;
 * descartar no tiene forma de fallar hacia el lado malo.
 *
 * ### Qué NO devuelve, y por qué
 *
 * Las **notas** de los pagos quedan fuera: son apuntes internos («el huésped discutió el
 * cargo», «pendiente de revisar con Susan»), y esta skill puede acabar alimentando una
 * respuesta que el operador copia y pega al huésped.
 */
final readonly class ConsultarCuentaSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function nombre(): string
    {
        return 'consultar_cuenta';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Devuelve el DETALLE de la cuenta de una reserva: cada cargo y cada '
                . 'pago por separado, con su concepto, importe, fecha y medio de pago, más los '
                . 'totales, el saldo pendiente y cuánto sale pagarlo con tarjeta. Úsala cuando '
                . 'pregunten de qué se compone una cuenta, por qué un importe es el que es, qué '
                . 'se ha cobrado, cómo pagó alguien o cómo puede pagar lo que queda. Si sólo '
                . 'quieren la cifra del saldo, consultar_mi_reserva (o buscar_reserva) ya la '
                . 'trae y es más barata. Hablando con un huésped NO pases reserva_id: se usa '
                . 'siempre la reserva de esta conversación, la suya.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva, tal cual lo '
                    . 'devolvió buscar_reserva. Sólo desde el panel: en el chat con un huésped '
                    . 'se ignora.', requerido: false),
            ],
        );
    }

    /** El huésped la tiene por serlo —acotada a SU reserva—; el equipo, por ver reservas. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $reservaId = $this->reservaDelContexto($actor)
            ?? trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'El reserva_id no es válido. Identifica primero la reserva con buscar_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        $info = $this->em->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        if ($info === null) {
            return SkillResult::ok([
                'reserva_id' => $reservaId,
                'huesped' => $this->huesped($reserva),
                'tiene_cuenta' => false,
                'mensaje' => 'Esta reserva todavía no tiene cuenta financiera abierta.',
            ]);
        }

        $moneda = $info->getMoneda()?->getId() ?? '';

        return SkillResult::ok([
            'reserva_id' => $reservaId,
            'huesped' => $this->huesped($reserva),
            'tiene_cuenta' => true,
            'moneda' => $moneda,
            'total_cargos' => $info->getTotalCargos(),
            'total_pagado' => $info->getTotalPagos(),
            'saldo_pendiente' => $info->getSaldo(),
            // El saldo es lo que se mira primero, pero un «0.00» significa cosas distintas
            // según si hay cargos: sin cargos no es que esté pagada, es que no se ha cobrado.
            'esta_saldada' => (float) $info->getTotalCargos() > 0.0
                && (float) $info->getSaldo() <= 0.0,
            'cargos' => $this->cargos($info),
            'pagos' => $this->pagos($info),
            'pago_con_tarjeta' => $this->conRecargoTarjeta($info->getSaldo(), $moneda),
            // El idioma del huésped viaja con los datos para que el modelo sepa en qué
            // lengua dirigirse a él si hay que redactarle algo. La skill NO traduce: devuelve
            // datos y quien redacta es el modelo, que es lo que mejor hace.
            'idioma_huesped' => $reserva->getIdioma()?->getId(),
        ]);
    }

    /**
     * 🔒 La reserva que impone la conversación, si la hay.
     *
     * Devolver algo aquí significa «no se admite otra»: el `reserva_id` del modelo ni se mira.
     * El equipo desde el panel no tiene contexto de chat, así que para ellos devuelve `null` y
     * el parámetro manda como siempre.
     */
    private function reservaDelContexto(ActorInterface $actor): ?string
    {
        return $actor->contextoTipo() === 'pms_reserva' ? $actor->contextoId() : null;
    }

    /** @return list<array<string, mixed>> */
    private function cargos(PmsInformacionFinanciera $info): array
    {
        $filas = [];

        foreach ($info->getCargos() as $cargo) {
            /** @var PmsCargoFinanciero $cargo */
            $filas[] = array_filter([
                'concepto' => $this->concepto($cargo),
                'tipo' => $cargo->getTipoCargo()?->value,
                'importe' => $cargo->getTotalLinea(),
                'moneda' => $cargo->getMoneda()?->getId(),
                'tipo_cambio' => $cargo->getTipoCambio(),
                // Distingue lo que puso el sistema (espejo de una OTA, cargo automático) de
                // lo que tecleó una persona: al cuadrar una cuenta es lo primero que se busca.
                'automatico' => $cargo->isEsAutomatico(),
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return $filas;
    }

    /** @return list<array<string, mixed>> */
    private function pagos(PmsInformacionFinanciera $info): array
    {
        $filas = [];

        foreach ($info->getPagos() as $pago) {
            /** @var PmsPagoFinanciero $pago */
            $filas[] = array_filter([
                'importe' => $pago->getMonto(),
                'moneda' => $pago->getMoneda()?->getId(),
                // `label()` y no el value crudo: el enum se declara «la ÚNICA fuente de
                // verdad» de la etiqueta, y «western_union» dicho por el agente suena a
                // identificador, no a Western Union.
                'medio' => $pago->getMedioPago()->label(),
                'fecha' => $pago->getFechaPago()?->format('Y-m-d'),
                'referencia' => $pago->getReferencia(),
                'tipo_cambio' => $pago->getTipoCambio(),
                'automatico' => $pago->isEsAutomatico(),
                // `notas` NO se expone: son apuntes internos y esto puede acabar copiado
                // y pegado a un huésped.
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return $filas;
    }

    /**
     * Cuánto sale pagar el saldo con tarjeta.
     *
     * Es la pregunta que el huésped hace en cuanto ve el saldo, y la vista de `pax` ya se lo
     * muestra — si el agente no lo sabe, dará una cifra distinta a la que el huésped tiene
     * delante. El porcentaje sale de `PmsMedioPago`, que es donde vive: codificar «5.5» aquí
     * lo dejaría desactualizado el día que cambie.
     *
     * @return array<string, mixed>|null `null` si no hay nada pendiente.
     */
    private function conRecargoTarjeta(string $saldo, string $moneda): ?array
    {
        $pendiente = (float) $saldo;

        if ($pendiente <= 0.0) {
            return null;
        }

        $porcentaje = (float) PmsMedioPago::TARJETA_CREDITO->comisionPorcentaje();

        return [
            'total' => sprintf('%.2f', round($pendiente * (1 + $porcentaje / 100), 2)),
            'moneda' => $moneda,
            'recargo_porcentaje' => $porcentaje,
            'nota' => sprintf(
                'Sólo si paga con tarjeta: incluye el %s%% de comisión. Por transferencia, '
                . 'Western Union o efectivo son %s %s.',
                rtrim(rtrim(sprintf('%.1f', $porcentaje), '0'), '.'),
                sprintf('%.2f', $pendiente),
                $moneda
            ),
        ];
    }

    /**
     * 🔥 Los cargos importados de Beds24 pueden traer los placeholders del canal SIN
     * sustituir: `[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`. En el panel se ve raro pero se
     * entiende; puesto delante de un modelo, se lo lee al operador tal cual —o peor, se lo
     * copia al huésped.
     *
     * Cuando el concepto es sólo placeholders se cae al nombre del tipo de cargo, que es
     * información verdadera y legible. No se intenta resolverlos: los datos para hacerlo
     * (noche de entrada, de salida) ya viajan aparte en la respuesta.
     */
    private function concepto(PmsCargoFinanciero $cargo): string
    {
        $descripcion = trim((string) $cargo->getDescripcion());

        // Sin los `[...]`, ¿queda algo con letras? Si no, era pura plantilla.
        $sinPlaceholders = trim(preg_replace('/\[[A-Z0-9_]+\]/', '', $descripcion) ?? '');
        $sinPlaceholders = trim($sinPlaceholders, " -–—\t\n");

        if ($descripcion !== '' && $sinPlaceholders === '') {
            return match ($cargo->getTipoCargo()?->value) {
                'alojamiento' => 'Alojamiento',
                'limpieza' => 'Limpieza',
                'servicio' => 'Servicio',
                'penalizacion' => 'Penalización',
                default => 'Cargo',
            };
        }

        return $descripcion !== '' ? $descripcion : 'Cargo';
    }

    private function huesped(PmsReserva $reserva): string
    {
        return trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente());
    }
}
