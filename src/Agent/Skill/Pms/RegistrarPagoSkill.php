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
use App\Pms\Service\Finance\PmsCuentaSimulador;
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
    /**
     * La moneda en la que habla el operador cuando no dice ninguna.
     *
     * Se opera en Cusco y se cobra en soles a diario, pero 304 de las 307 cuentas se llevan en
     * USD. Así que «me pagó 20» casi siempre son soles sobre una cuenta en dólares: caer a la
     * moneda de la cuenta registraría 20 USD en vez de 5.89, un 240% de más y a favor del
     * huésped. Por eso no hay defecto: se pregunta.
     */
    private const string MONEDA_LOCAL = 'PEN';

    public function __construct(
        private EntityManagerInterface $em,
        private MonedaResolver $monedas,
        private TipoCambioDelDia $tipoCambio,
        private PmsCuentaSimulador $simulador,
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
                . 'al operador cuál es. OJO CON LA MONEDA: se opera en Perú y casi todas las '
                . 'cuentas se llevan en dólares, así que «me pagó 20» suele ser 20 SOLES sobre '
                . 'una cuenta en USD. Nunca lo des por hecho: si no lo dicen, pregunta. Si la '
                . 'respuesta trae falta_datos, traslada su pregunta al operador y vuelve a '
                . 'llamarme con lo que conteste, sin inventar nada. Si trae advertencia, '
                . 'LÉESELA antes de pedir la confirmación. ENSEÑA SIEMPRE LA SIMULACIÓN: la respuesta trae un bloque «simulacion» con cargos, pagado y saldo ANTES y DESPUÉS. Muéstraselo al operador como una comparación, no lo resumas en una frase, y termina con la pregunta exacta de pregunta_aprobacion. No apliques nada hasta que responda que sí. Necesita el reserva_id.',
                $pctTarjeta
            ),
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva.'),
                SkillParameter::texto('importe', 'Importe del pago, sólo el número. Ej: "20" o "20.50".'),
                SkillParameter::texto('medio_pago', 'Cómo pagó: ' . implode(', ', array_map(
                    static fn (PmsMedioPago $m) => $m->value,
                    PmsMedioPago::cases()
                )) . '.'),
                SkillParameter::texto('moneda', 'Moneda del importe: PEN si el operador dice '
                    . 'soles, USD si dice dólares. NO la adivines: si sólo te dan un número, '
                    . 'omítela y la skill te dirá cuánto sale de cada forma para que preguntes.',
                    requerido: false),
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

        $monedaIndicada = strtoupper(trim((string) ($entrada['moneda'] ?? '')));
        $incluye = strtolower(trim((string) ($entrada['importe_incluye_comision'] ?? '')));

        // Los dos datos que un importe suelto no trae y que valen dinero. Se piden JUNTOS: dos
        // repreguntas seguidas al operador por el mismo pago son una de más.
        $faltan = [];

        // 💱 «20» no dice de qué. Sólo es inequívoco si la cuenta ya está en la moneda local.
        if ($monedaIndicada === '' && $monedaCuenta->getId() !== self::MONEDA_LOCAL) {
            $faltan[] = 'moneda';
        }

        // 💳 Con comisión, «20» tampoco dice si es lo del POS o lo que abona.
        if ($pct > 0.0 && !in_array($incluye, ['si', 'sí', 'no'], true)) {
            $faltan[] = 'importe_incluye_comision';
        }

        if ($faltan !== []) {
            return SkillResult::ok(array_filter([
                'reserva_id' => $reservaId,
                'huesped' => $huesped,
                'moneda_de_la_cuenta' => $monedaCuenta->getId(),
                'medio' => $medio->label(),
                'falta_datos' => $faltan,
                'si_son_' . strtolower(self::MONEDA_LOCAL) => in_array('moneda', $faltan, true)
                    ? $this->equivalencia($importe, self::MONEDA_LOCAL, $monedaCuenta->getId())
                    : null,
                'si_son_' . strtolower($monedaCuenta->getId()) => in_array('moneda', $faltan, true)
                    ? sprintf('%.2f %s', $importe, $monedaCuenta->getId())
                    : null,
                'si_incluye_comision' => in_array('importe_incluye_comision', $faltan, true)
                    ? $this->desglose($importe, $pct, true, $monedaIndicada ?: '(por aclarar)')
                    : null,
                'si_no_incluye_comision' => in_array('importe_incluye_comision', $faltan, true)
                    ? $this->desglose($importe, $pct, false, $monedaIndicada ?: '(por aclarar)')
                    : null,
                'pregunta' => $this->pregunta($faltan, $importe, $medio, $monedaCuenta->getId()),
            ], static fn ($v) => $v !== null));
        }

        $cobrado = $pct > 0.0 && $incluye !== 'no' ? $importe : $importe * (1 + $pct / 100);
        $neto = $pct > 0.0 && $incluye !== 'no' ? $importe / (1 + $pct / 100) : $importe;

        // Conversión: el pago se guarda en la moneda de la cuenta, con el tipo aplicado
        // registrado para poder auditarlo. Mismo criterio que RegistrarCargoSkill.
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
        $saldoDespues = round($saldoAntes - $neto, 2);

        // Un número raro no se señala solo: si queda a favor del huésped hay que DECIRLO, o el
        // modelo lo lee como un dato más y el operador confirma sin fijarse.
        $advertencia = null;

        if ($saldoDespues < 0.0) {
            $advertencia = $saldoAntes <= 0.0
                ? sprintf(
                    'ATENCIÓN: esta cuenta YA estaba saldada (saldo %.2f). Este pago la deja en '
                    . '%.2f %s a favor del huésped. Díselo al operador y pregúntale si falta '
                    . 'registrar algún cargo antes.',
                    $saldoAntes,
                    $saldoDespues,
                    $monedaCuenta->getId()
                )
                : sprintf(
                    'ATENCIÓN: el pago excede lo pendiente (%.2f %s). La cuenta queda en %.2f a '
                    . 'favor del huésped. Confírmalo con el operador antes de aplicarlo.',
                    $saldoAntes,
                    $monedaCuenta->getId(),
                    $saldoDespues
                );
        }

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
            'saldo_despues' => sprintf('%.2f %s', $saldoDespues, $monedaCuenta->getId()),
            'advertencia' => $advertencia,
        ];

        $resumen = array_filter($resumen, static fn ($v) => $v !== null);

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'registrado' => false,
                'motivo' => 'falta_confirmacion',
                // La foto entera de la cuenta, no sólo el saldo: el operador aprueba mejor
                // viendo en qué queda todo que leyendo una frase.
                'simulacion' => $this->simulador->simular($info, deltaPagos: $neto),
                'pregunta_aprobacion' => '¿Apruebas el cambio?',
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
     * Qué son esos N en la moneda de la cuenta, para que el operador vea la diferencia.
     */
    private function equivalencia(float $importe, string $origen, string $destino): string
    {
        $tipo = $this->tipoCambio->venta();

        if ($tipo === null) {
            return sprintf('%.2f %s (sin tipo de cambio para convertir)', $importe, $origen);
        }

        $convertido = $origen === 'PEN' ? $importe / (float) $tipo : $importe * (float) $tipo;

        return sprintf(
            '%.2f %s = %.2f %s (tipo %s)',
            $importe,
            $origen,
            round($convertido, 2),
            $destino,
            $tipo
        );
    }

    /**
     * Una sola frase que el modelo pueda trasladar tal cual, con las dos dudas juntas.
     *
     * @param list<string> $faltan
     */
    private function pregunta(array $faltan, float $importe, PmsMedioPago $medio, string $monedaCuenta): string
    {
        $partes = [];

        if (in_array('moneda', $faltan, true)) {
            $partes[] = sprintf(
                '¿los %.2f son soles o %s? La cuenta se lleva en %s',
                $importe,
                $monedaCuenta,
                $monedaCuenta
            );
        }

        if (in_array('importe_incluye_comision', $faltan, true)) {
            $partes[] = sprintf(
                'con %s hay %s%% de comisión, ¿el importe es lo que se pasó por el POS o lo que '
                . 'debe abonar a la deuda?',
                $medio->label(),
                $medio->comisionPorcentaje()
            );
        }

        return 'Pregúntale al operador: ' . implode('; y ', $partes)
            . '. Luego vuelve a llamarme con esos datos.';
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
