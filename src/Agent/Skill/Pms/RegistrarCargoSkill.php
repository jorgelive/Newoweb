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
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
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
 *
 * ⚠️ El tipo se sella **aunque las monedas coincidan** y no haya nada que convertir. Es el
 * mismo criterio que el `tcSiempre` del panel (`ReservaFinanzasPanel.vue`): un cargo sin TC
 * deja de sumar en cuanto se cambia la moneda base de la cuenta (aporta 0, §12.2) y hay que
 * repararlo a mano uno por uno.
 *
 * ### 🏠 A qué casita se carga
 *
 * Un cargo manual no tiene `beds24BookingId` del que colgarse —esa pista sólo la traen los
 * invoiceItems del canal (§11.6)—, así que su estancia la fija la FK `evento`. Con una sola
 * casita se elige sola; con varias la skill **no adivina**: devuelve `falta_datos` con la lista
 * y que el operador señale, igual que hace el `<select>` del panel. Un cargo sin estancia se
 * guarda igual, pero acaba en «Cargos de la reserva», repartido contra todas las casitas.
 *
 * ### 🚫 Cuentas anuladas
 *
 * Sobre una cuenta con `activa = false` el recálculo sólo suma los cargos de tipo
 * `penalizacion` (§12.7). Cualquier otro tipo se guarda pero **no mueve el saldo**, así que la
 * skill lo detecta antes de confirmar, simula delta 0 y devuelve `advertencia` para que el
 * modelo se lo lea al operador. Sin eso la previsualización prometía un total que el listener
 * de coherencia descartaba justo después.
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
                . 'que la de la cuenta, se convierte automáticamente: no conviertas tú. Si la '
                . 'respuesta trae advertencia, LÉESELA al operador antes de pedirle la '
                . 'confirmación: avisa de que el cargo no va a mover el saldo y por qué. Si '
                . 'trae falta_datos con las estancias, es que la reserva tiene varias casitas: '
                . 'traslada su pregunta al operador para que diga a cuál se carga, y vuelve a '
                . 'llamarme con el evento_id que elija. No elijas tú una casita.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva.'),
                SkillParameter::texto('concepto', 'Descripción del cargo, como la verá el huésped.'),
                SkillParameter::texto('importe', 'Importe, sólo el número. Ej: "20" o "20.50".'),
                SkillParameter::texto('moneda', 'Moneda del importe indicado (PEN, USD…). Si se '
                    . 'omite, se asume la de la cuenta.', requerido: false),
                SkillParameter::texto('tipo', 'Tipo de cargo: alojamiento, limpieza, servicio, '
                    . 'penalizacion u otro. Por defecto "servicio".', requerido: false),
                SkillParameter::texto('evento_id', 'Estancia (casita) a la que se imputa el '
                    . 'cargo, tal cual lo devuelve buscar_estancias_de_reserva. Omítelo: si la '
                    . 'reserva tiene una sola casita se elige sola, y si tiene varias la skill '
                    . 'te devolverá la lista para que preguntes a cuál.', requerido: false),
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

        // ⚠️ Concepto e importe NO cortan aquí con un error, aunque falten. Cortaban, y era lo
        // que partía la repregunta en dos vueltas: el operador daba el concepto y sólo entonces
        // la skill llegaba a ver que la reserva tiene tres casitas y le preguntaba a cuál. Se
        // acumulan con el resto y se pregunta todo junto más abajo.
        $faltanBasicos = [];

        if ($concepto === '') {
            $faltanBasicos[] = 'concepto';
        }

        if ($importe <= 0) {
            $faltanBasicos[] = 'importe';
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
        //
        // El TC se consulta SIEMPRE, coincidan o no las monedas: es el mismo criterio que el
        // `tcSiempre` del panel (util/src/components/reservas/ReservaFinanzasPanel.vue). Un
        // cargo guardado sin TC deja de sumar el día que se cambie la moneda base de la cuenta
        // —aporta 0 (§12.2)— y hay que repararlo a mano. Sellarlo de más nunca deforma un
        // total, porque `PmsInformacionFinanciera::aMonedaBase()` lo ignora cuando la moneda
        // del cargo ya es la de la cabecera.
        $tipoDelDia = $this->tipoCambio->venta();
        $huboConversion = $monedaOrigen->getId() !== $monedaCuenta->getId();
        $importeFinal = $importe;

        if ($huboConversion) {
            if ($tipoDelDia === null) {
                return SkillResult::error(sprintf(
                    'No hay tipo de cambio disponible para pasar de %s a %s. Registra el cargo '
                    . 'desde el panel indicando el tipo a mano.',
                    $monedaOrigen->getId(),
                    $monedaCuenta->getId()
                ));
            }

            // PEN → USD divide; USD → PEN multiplica. El tipo del día es soles por dólar.
            $importeFinal = $monedaOrigen->getId() === 'PEN'
                ? $importe / (float) $tipoDelDia
                : $importe * (float) $tipoDelDia;
        }

        $importeFinal = round($importeFinal, 2);

        // Lo que se le reporta al modelo es el tipo que se USÓ para convertir: decirle que se
        // «aplicó» un tipo cuando las monedas coincidían le hace explicarle al operador una
        // conversión que no ocurrió. El que se sella en el registro es $tipoDelDia.
        $tipoAplicado = $huboConversion ? $tipoDelDia : null;

        // El tipo se resuelve ANTES de la previsualización porque de él depende que el cargo
        // llegue a sumar en una cuenta anulada (ver la advertencia de abajo).
        $tipo = PmsTipoCargo::tryFrom(strtolower(trim((string) ($entrada['tipo'] ?? 'servicio'))))
            ?? PmsTipoCargo::SERVICIO;

        // 🏠 ¿A qué casita se imputa? Un cargo manual no tiene `beds24BookingId` del que
        // colgarse (§11.6 de PmsBeds24ReservasSync), así que la estancia la fija la FK `evento`
        // — y si no se rellena, el panel lo pinta en «Cargos de la reserva», repartido contra
        // toda la reserva en vez de contra la casita que lo generó.
        $estancias = $this->estanciasImputables($reserva);
        $eventoIndicado = trim((string) ($entrada['evento_id'] ?? ''));
        $evento = null;

        if ($eventoIndicado !== '') {
            foreach ($estancias as $candidata) {
                if ((string) $candidata->getId() === $eventoIndicado) {
                    $evento = $candidata;
                    break;
                }
            }

            // Se comprueba contra las estancias DE ESTA reserva, no con un find() suelto: un
            // evento_id de otra reserva imputaría el cargo a un huésped que no lo debe.
            if ($evento === null) {
                return SkillResult::error(
                    'Ese evento_id no es una estancia de esta reserva. Pide la lista con '
                    . 'buscar_estancias_de_reserva y vuelve a llamarme con uno de los suyos.'
                );
            }
        } elseif (count($estancias) === 1) {
            // Con una sola casita no hay nada que preguntar. Mismo criterio que el panel
            // (`abrirNuevoCargo()` en ReservaFinanzasPanel.vue preselecciona igual).
            $evento = $estancias[0];
        }

        // Todo lo que falta, JUNTO: el concepto y el importe que no dijeron, más la casita
        // cuando la reserva tiene varias. Preguntarlos en vueltas distintas es hacerle al
        // operador tres viajes por un cargo de veinte dólares.
        $faltan = $faltanBasicos;
        $variasCasitas = $evento === null && count($estancias) > 1;

        if ($variasCasitas) {
            $faltan[] = 'evento_id';
        }

        if ($faltan !== []) {
            $preguntas = [];

            if (in_array('concepto', $faltan, true)) {
                $preguntas[] = '¿por qué concepto es el cargo? (limpieza extra, lavandería, '
                    . 'tour, consumo…)';
            }

            if (in_array('importe', $faltan, true)) {
                $preguntas[] = '¿de cuánto es?';
            }

            if ($variasCasitas) {
                $preguntas[] = sprintf(
                    'esta reserva tiene %d casitas, ¿a cuál se le carga?',
                    count($estancias)
                );
            }

            return SkillResult::ok(array_filter([
                'reserva_id' => $reservaId,
                'huesped' => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
                'concepto' => $concepto !== '' ? $concepto : null,
                'importe_en_cuenta' => $importe > 0
                    ? sprintf('%.2f %s', $importeFinal, $monedaCuenta->getId())
                    : null,
                'falta_datos' => $faltan,
                'estancias' => $variasCasitas
                    ? array_map(static fn (PmsEventoCalendario $e): array => [
                        'evento_id' => (string) $e->getId(),
                        'casita' => $e->getPmsUnidad()?->getNombre() ?? '—',
                        'entrada' => $e->getInicio()?->format('Y-m-d'),
                        'salida' => $e->getFin()?->format('Y-m-d'),
                    ], $estancias)
                    : null,
                'pregunta' => sprintf(
                    'Pregúntale al operador, todo de una vez: %s. Luego vuelve a llamarme con '
                    . 'esos datos.',
                    implode('; y ', $preguntas)
                ),
            ], static fn ($v) => $v !== null));
        }

        // 🚫 Cuenta ANULADA: el recálculo sólo suma la penalización (§12.7, el
        // `AND (i2.activa = 1 OR c.tipo_cargo = 'penalizacion')` de
        // PmsInformacionFinancieraRecalculoService). Sin este aviso la skill guardaba el cargo,
        // respondía «Cargado 20.00 USD» y el saldo no se movía: la previsualización prometía
        // un total que el listener descartaba acto seguido.
        $advertencia = null;

        if (!$info->isActiva() && $tipo !== PmsTipoCargo::PENALIZACION) {
            $advertencia = sprintf(
                'ATENCIÓN: esta cuenta está ANULADA (reserva cancelada). En una cuenta anulada '
                . 'sólo suma el tipo «penalizacion», así que este cargo de tipo «%s» se guardará '
                . 'pero NO cambiará el saldo. Si lo que quieres es cobrar la cancelación, '
                . 'vuelve a llamarme con tipo="penalizacion". Díselo al operador antes de '
                . 'pedirle la confirmación.',
                $tipo->value
            );
        }

        $resumen = array_filter([
            'reserva_id' => $reservaId,
            'huesped' => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'concepto' => $concepto,
            'tipo' => $tipo->value,
            'importe_indicado' => sprintf('%.2f %s', $importe, $monedaOrigen->getId()),
            'importe_en_cuenta' => sprintf('%.2f %s', $importeFinal, $monedaCuenta->getId()),
            'tipo_cambio_aplicado' => $tipoAplicado,
            'advertencia' => $advertencia,
        ], static fn ($v) => $v !== null);

        // Lo que el cargo moverá DE VERDAD el total: cero si la cuenta anulada lo va a
        // descartar. Simular el importe entero sería prometer un saldo que no va a existir,
        // que es justo lo que una previsualización tiene que impedir.
        $deltaEfectivo = $advertencia === null ? $importeFinal : 0.0;

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                // Misma foto que en registrar_pago, por el mismo simulador: un cargo y un pago
                // mueven la misma cuenta y el operador debe verla igual en los dos casos.
                'simulacion' => $this->simulador->simular($info, deltaCargos: $deltaEfectivo),
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

        $cargo = new PmsCargoFinanciero();
        $cargo->setTipoCargo($tipo);
        $cargo->setEvento($evento);
        $cargo->setDescripcion($concepto);
        // `%.2f` y no un cast: `(string) 50.0` da "50", y la columna es decimal(10,2). El
        // pago hermano ya lo hacía así.
        $cargo->setMonto(sprintf('%.2f', $importeFinal));
        $cargo->setTotalLinea(sprintf('%.2f', $importeFinal));
        $cargo->setMoneda($monedaCuenta);

        // Se sella el tipo del día aunque no haya habido conversión: en una auditoría un campo
        // vacío y un campo con el tipo no cuentan la misma historia, y sobre todo es lo que
        // mantiene vivo el cargo si mañana se cambia la moneda base de la cuenta (ver arriba).
        if ($tipoDelDia !== null) {
            $cargo->setTipoCambio($tipoDelDia);
        }

        $info->addCargo($cargo);
        $this->em->persist($cargo);
        $this->em->flush();

        return SkillResult::ok($resumen + [
            'aplicado' => true,
            // Se relee tras el flush, igual que en registrar_pago: lo recalcula el listener de
            // coherencia, así que devolver la suma que preveíamos sería contar lo que creemos
            // y no lo que quedó —justo lo que ocultaba el cargo descartado de una cuenta anulada.
            'saldo_real' => sprintf('%.2f %s', (float) $info->getSaldo(), $monedaCuenta->getId()),
            'mensaje' => sprintf('Cargado %s por «%s».', $resumen['importe_en_cuenta'], $concepto),
        ]);
    }

    /**
     * Estancias a las que tiene sentido imputar un cargo.
     *
     * Mismo criterio que {@see BuscarEventoEstanciaSkill}, y a propósito: es la lista que el
     * operador ya ha visto si preguntó por las casitas, y ofrecerle aquí una distinta (con
     * canceladas, o con las noches fantasma de un horario extra) le haría elegir sobre algo
     * que no reconoce. Ojo: NO coincide con `PmsInformacionFinanciera::getEstancias()`, que no
     * filtra nada porque su trabajo es traducir claves para agrupar lo ya guardado.
     *
     * @return list<PmsEventoCalendario>
     */
    private function estanciasImputables(PmsReserva $reserva): array
    {
        $estancias = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            // Las extensiones son la noche que bloquea un horario extra (§7.1.b), no una
            // estancia: su cargo ya lo lleva la estancia que las generó.
            if ($evento->getEventoOrigen() !== null) {
                continue;
            }

            if ($evento->getEstado()?->getId() === PmsEventoEstado::CODIGO_CANCELADA) {
                continue;
            }

            $estancias[] = $evento;
        }

        return $estancias;
    }
}
