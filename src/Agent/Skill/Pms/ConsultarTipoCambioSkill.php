<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Finance\TipoCambioDelDia;
use App\Security\Roles;

/**
 * El tipo de cambio USD→PEN que usa la casa, para quien va a pagar en soles.
 *
 * ### Por qué es una skill y no un dato de la guía
 *
 * Porque **caduca**. Los medios de cobro ({@see ConsultarMediosPagoSkill}) se escriben una vez
 * y valen un año; el tipo de cambio cambia a diario. Escrito a mano en un ítem de guía,
 * envejece en silencio y el error no se ve hasta que alguien paga de menos —o de más—.
 *
 * En la reserva V4JE5Q el agente dijo 3.80 cuando el real era 3.391: 114 soles de adelanto en
 * vez de ~101. No fue un fallo de redacción, fue que no había de dónde leerlo. Ver
 * docs/Mensajeria.md §16.
 *
 * ### De dónde sale la cifra
 *
 * De {@see TipoCambioDelDia}, que es **la misma fuente que se snapshotea en cada cargo y cada
 * pago** (`PmsCargoFinanciero::$tipoCambio`). Es todo el motivo de que no se calcule aquí: lo
 * que el agente le dice al huésped y lo que luego aparece en su cuenta tienen que ser el mismo
 * número. Se usa la punta **venta** de SUNAT, que es la decisión de negocio ya tomada para
 * convertir soles recibidos a dólares.
 *
 * Fachada delgada, como manda docs/Mensajeria.md §11: la caché, la llamada a SUNAT y la caída
 * a la última tasa disponible viven en el servicio.
 */
final readonly class ConsultarTipoCambioSkill implements SkillInterface
{
    public function __construct(
        private TipoCambioDelDia $tipoCambio,
    ) {}

    public function nombre(): string
    {
        return 'consultar_tipo_cambio';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'El tipo de cambio de DÓLARES a SOLES que maneja la casa. Úsala '
                . 'siempre que pregunten «¿a cuánto está el dólar?», «¿qué tipo de cambio '
                . 'manejan?», «¿cuánto sería en soles?» o quieran pagar en soles algo que está '
                . 'en dólares. 🔥 NUNCA digas un tipo de cambio de memoria ni lo saques de un '
                . 'cargo antiguo: el de los cargos es el del día en que se cobraron y hoy es '
                . 'otro. Si le pasas «monto_usd» te devuelve además la conversión ya hecha en '
                . '«equivalente_pen» — úsalo en vez de multiplicar tú, que es donde se cuelan '
                . 'los errores. Devuelve «tasa», «fecha» (de qué día es la tasa), «moneda_origen», '
                . '«moneda_destino» y «fuente». Si la fecha no es la de hoy, dilo al darla. Y si '
                . 'viene un error, NO te inventes una cifra: dile que se lo confirmas en un '
                . 'momento y llama a escalar_al_equipo.',
            parametros: [
                SkillParameter::numero('monto_usd', 'Importe en dólares que quieres convertir a '
                    . 'soles. Opcional: sin él sólo se devuelve la tasa.', requerido: false),
            ],
            // No queda decisión de negocio: se lee una cifra y se redacta. El trabajo que hay
            // después de elegir esta skill es formatear un número.
            siguientePaso: PotenciaRequerida::Baja,
        );
    }

    /** El huésped la necesita para pagar; el equipo, para cotizar en soles. */
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
        $referencia = $this->tipoCambio->referencia();
        $tasa = $referencia?->getVenta();

        // Error de negocio, no excepción: el modelo puede reformular y escalar. Que SUNAT no
        // conteste no es un fallo de infraestructura nuestro. Ver docs/Mensajeria.md §11.
        if ($referencia === null || $tasa === null || (float) $tasa <= 0.0) {
            return SkillResult::error(
                'Ahora mismo no se puede consultar el tipo de cambio. No lo estimes: dile al '
                . 'huésped que se lo confirmas enseguida y avisa al equipo.'
            );
        }

        $salida = [
            'tasa' => $tasa,
            'moneda_origen' => 'USD',
            'moneda_destino' => 'PEN',
            'fecha' => $referencia->getFecha()?->format('Y-m-d'),
            'fuente' => 'Tipo de cambio venta SUNAT, el mismo que se usa al registrar los pagos.',
        ];

        $monto = $entrada['monto_usd'] ?? null;

        if (is_numeric($monto) && (float) $monto > 0.0) {
            $salida['monto_usd'] = number_format((float) $monto, 2, '.', '');
            // Se redondea a 2 decimales porque es lo que el huésped va a teclear en el Yape.
            $salida['equivalente_pen'] = number_format((float) $monto * (float) $tasa, 2, '.', '');
        }

        return SkillResult::ok($salida);
    }
}
