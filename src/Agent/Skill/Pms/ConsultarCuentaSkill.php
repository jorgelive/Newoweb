<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsPoliticaPrepago;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Finanzas\PmsProcedenciaHuesped;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
final readonly class ConsultarCuentaSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsPrepagoCalculador $prepagoCalculador,
        private PmsProcedenciaHuesped $procedencia,
        private LoggerInterface $logger,
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
                . 'totales, el saldo pendiente, cuánto sale pagarlo con tarjeta y, si procede, '
                . 'el adelanto que hay que pedir para asegurar la reserva (prepago_pendiente). '
                . 'Para «¿cuánto es la primera noche?» o «¿cuánto es el adelanto?» usa '
                . '«adelanto_de_la_politica»: dice lo que pide la política AUNQUE YA ESTÉ '
                . 'PAGADO, y su «ya_cubierto» te dice si toca pedirlo o sólo informar del '
                . 'importe. No respondas eso con el total del alojamiento. '
                . 'Si el huésped dice que en la app del canal (Booking, Airbnb…) le sale OTRO '
                . 'importe, dale la cifra de aquí y mira TAMBIÉN consultar_guia en el tema de '
                . 'pagos: la explicación de por qué no cuadran está escrita ahí y no te la '
                . 'inventes. '
                . 'Cuando un cargo trae explicacion_para_huesped, ésa es la explicación buena '
                . 'para dársela al huésped; el campo concepto viene del canal y puede ser un '
                . 'código sin sentido para él. Úsala cuando '
                . 'pregunten de qué se compone una cuenta, por qué un importe es el que es, qué '
                . 'se ha cobrado, cómo pagó alguien, cuánto hay que adelantar o cómo puede '
                . 'pagar lo que queda. Si sólo '
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

    /**
     * Del negocio de ALOJAMIENTO: habla de reservas, estancias, casitas o su dinero.
     *
     * Recorta el catálogo, no los permisos — ver {@see SkillDominioInterface}.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
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
        $idioma = $reserva->getIdioma()?->getId();
        $totales = PmsTotalesPorMoneda::de($info);

        // 🪞 CANALES QUE COBRAN POR NOSOTROS: la misma regla que ya aplica la guía del huésped.
        //
        // En Airbnb y VRBO ({@see PmsChannel::CANAL_PAGO_TOTAL}) el importe que guardamos es lo
        // que la OTA nos remite, NO lo que el huésped pagó —que lleva encima la comisión de
        // servicio de la plataforma—. `PmsReservaPaxProvider::cifras()` lo excluye desde
        // siempre, así que en su pantalla el huésped ve la barra al 100 % y ni una sola cifra.
        //
        // Esta skill no heredaba esa política y le recitaba total, pagado y saldo. Dos
        // superficies con los mismos datos y criterios opuestos: la web se lo oculta a
        // propósito y el chat se lo cantaba. Y no es «un dato de más» — si nuestro saldo no
        // cuadra con lo que él pagó a la OTA, parece que le reclamamos dinero.
        //
        // Ver docs/Mensajeria.md §19.10.
        $espejo = in_array(
            $reserva->getChannel()?->getId(),
            PmsChannel::CANAL_PAGO_TOTAL,
            true
        );

        if ($espejo) {
            return $this->cuentaDeCanalQueCobra($reservaId, $reserva, $info, $moneda, $idioma);
        }

        $pais = $reserva->getPais();

        // ⚠️ De DÓNDE PAGA no se deduce aquí. Lo contesta `PmsProcedenciaHuesped`, que es
        // quien ya se lo contesta a `consultar_medios_pago` y a la guía del huésped —y los
        // tres TIENEN que decir lo mismo: si la pantalla le enseña una cuenta del BCP y el
        // asistente le dice que no hay cuentas para él, el huésped no sabe a quién creer—.
        //
        // Un `$pais?->getId() === 'PE'` propio sería una cuarta respuesta a la misma pregunta,
        // y encima binaria: perdería el `null` de «no se sabe», que existe a propósito para
        // no darle por peruano a quien venía de fuera y esconderle el Western Union.
        $desdePeru = $this->procedencia->pagaDesdePeru($reserva);

        return SkillResult::ok(array_filter([
            'reserva_id' => $reservaId,
            'huesped' => $this->huesped($reserva),
            'pais' => $pais?->getId(),
            'pais_nombre' => $pais?->getNombre(),
            // Mismo nombre y misma semántica que en `consultar_medios_pago`: `null` = no se
            // pudo deducir, y entonces la clave no viaja (el `array_filter` de abajo descarta
            // los `null` y SÓLO los `null`, que es lo que deja pasar el `false` de «paga desde
            // fuera» — el único caso en que la regla de cobro cambia de verdad).
            'huesped_paga_desde_peru' => $desdePeru,
            'tiene_cuenta' => true,
            // 🔀 A DÓNDE IR SI DISCUTE LA CIFRA. Que el huésped diga «en la app veo otro
            // importe» es la objeción más frecuente de este tema, y la respuesta —que el canal
            // muestra su tarifa, que puede no incluir limpieza ni cargo por servicio, y que
            // nunca refleja lo que ya nos pagó directamente— está escrita en la guía, no aquí.
            //
            // Va pegado al dato y no confiado al prompt: sin esto el modelo improvisa una
            // explicación verosímil distinta cada vez, que es justo lo que no puede pasar
            // hablando de dinero. Mismo patrón que `debes_escalar` en la guía.
            'si_discute_el_importe' => $reserva->getChannel() === null ? null
                : 'Si dice que en la app del canal ve OTRA cifra, NO improvises la explicación: '
                    . 'pídeme el tema de pagos con consultar_guia poniendo «ya_lo_intento», y te '
                    . 'daré lo que hay que contarle.',
            // 💱 UNA ENTRADA POR MONEDA, sin convertir nada.
            //
            // Antes iba un `total_cargos` / `saldo_pendiente` escalar con todo convertido a la
            // moneda de la ficha, y ahí el modelo tenía que recitar un número que **el huésped
            // no había pagado nunca**: quien abonó S/ 223.70 por Yape no reconoce «debes
            // US$ 65.97». Con el desglose, el modelo puede decir exactamente lo que pasó.
            'moneda_de_cotizacion' => $moneda,
            'por_moneda' => $this->porMoneda($totales),
            // El saldo es lo que se mira primero, pero un «0.00» significa cosas distintas
            // según si hay cargos: sin cargos no es que esté pagada, es que no se ha cobrado.
            'esta_saldada' => $totales->hayCargos() && $totales->cuadra(),
            // El CUADRE, sólo cuando hay dos monedas en juego. Es lo que contesta «te pago en
            // soles lo que falta, ¿cuánto es?» sin que el desglose deje al modelo sumando de
            // cabeza — que es exactamente lo que no puede hacer con dinero.
            'cuadre' => $this->cuadre($totales),
            'cargos' => $this->cargos($info, $idioma),
            'pagos' => $this->pagos($info),
            // El recargo se calcula sobre el CUADRE, no sobre una moneda suelta: es lo que se
            // le va a cobrar si lo cierra todo de una vez con la tarjeta.
            'pago_con_tarjeta' => $this->conRecargoTarjeta($totales->cuadre, $totales->monedaCuadre),
            // Solo viaja si hay algo que pedir; `array_filter` lo quita cuando es null. El
            // saldo total y el prepago responden a preguntas distintas —«cuánto debes» y
            // «cuánto hay que adelantar ahora»— y confundirlos es cobrar de más.
            'prepago_pendiente' => $this->prepago($info, $moneda),
            // Cuánto PIDE la política, aunque ya esté pagado. Responde «¿cuánto es la primera
            // noche?», que `prepago_pendiente` deja sin contestar en cuanto hay un pago.
            'adelanto_de_la_politica' => $this->adelantoDeLaPolitica($info, $moneda),
            // El idioma del huésped viaja con los datos para que el modelo sepa en qué
            // lengua dirigirse a él si hay que redactarle algo. La skill NO traduce: devuelve
            // datos y quien redacta es el modelo, que es lo que mejor hace.
            'idioma_huesped' => $idioma,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Lo que se debe y lo que se ha cobrado, moneda a moneda.
     *
     * Se le da al modelo **con el saldo ya restado** para que no tenga que hacer aritmética: un
     * modelo resta bien casi siempre, y «casi siempre» no vale hablando de dinero.
     *
     * @return list<array{moneda: string, cargos: string, pagado: string, saldo: string}>
     */
    private function porMoneda(PmsTotalesPorMoneda $totales): array
    {
        $salida = [];

        foreach ($totales->porMoneda as $moneda => $cifras) {
            $salida[] = [
                'moneda' => $moneda,
                'cargos' => $cifras['cargos'],
                'pagado' => $cifras['pagos'],
                'saldo' => $cifras['saldo'],
            ];
        }

        return $salida;
    }

    /**
     * El balance de las dos monedas en una sola cifra, o `null` si sólo hay una.
     *
     * Con una moneda el desglose ya lo dice todo y esto sería repetirlo. Con dos, en cambio, el
     * modelo necesita una cifra para contestar «¿cuánto falta?» — y necesita saber que es
     * **aproximada**, o se la recitará al huésped como si fuera exacta.
     *
     * `saldo_a_favor` va aparte de `esta_saldada` a propósito: un sobrepago está pagado, y aun
     * así hay dinero del huésped en nuestra caja. Son dos hechos y los dos hay que poder decirlos.
     *
     * @return array<string, mixed>|null
     */
    private function cuadre(PmsTotalesPorMoneda $totales): ?array
    {
        if (!$totales->esMixta()) {
            return null;
        }

        return array_filter([
            'importe' => $totales->cuadre,
            'moneda' => $totales->monedaCuadre,
            'tipo_cambio' => $totales->tipoCambio,
            'cuadra' => $totales->cuadra(),
            'saldo_a_favor_del_huesped' => $totales->haySaldoAFavor() ?: null,
            'nota' => 'Esta reserva tiene movimientos en dos monedas. El desglose de `por_moneda` '
                . 'es lo exacto; este importe es el equivalente al cambio de la reserva, para '
                . 'poder cerrarlo todo en una sola. Si se lo dices al huésped, dile que es '
                . 'aproximado y en qué moneda.',
        ], static fn ($v) => $v !== null);
    }

    /**
     * El adelanto que todavía hay que pedir, o `null` si no procede.
     *
     * La cifra y la regla las pone {@see PmsPrepagoCalculador::pendiente()} —la misma que
     * alimenta el estado de cuenta del huésped—, así que el agente no puede decir un importe
     * distinto del que el huésped tiene delante en su pantalla. Eso es todo el motivo de que
     * esto no se calcule aquí.
     *
     * ⚠️ La `claveI18n` del calculador NO se pasa al modelo. Es una clave del diccionario de
     * `pax`, que se resuelve en el navegador del huésped: aquí sería un identificador
     * (`res_prepago_mitad_total`) que el modelo acabaría leyendo en voz alta o traduciendo a
     * ojo. Se manda `PmsPoliticaPrepago::etiqueta()`, que es español legible, y el modelo lo
     * redacta en el idioma que toque. Es el mismo criterio que ya aplica `medio` en los pagos.
     *
     * @return array<string, mixed>|null
     */
    private function prepago(PmsInformacionFinanciera $info, string $moneda): ?array
    {
        $prepago = $this->prepagoCalculador->pendiente($info);

        if ($prepago === null) {
            return null;
        }

        $politica = PmsPoliticaPrepago::tryFrom($prepago['politica']);

        return array_filter([
            'monto' => $prepago['monto'],
            'moneda' => $moneda,
            'politica' => $politica?->etiqueta(),
            // El saldo de SU moneda, no el convertido: si el prepago se pide en dólares, «lo
            // que queda» tiene que ser en dólares o el modelo le canta al huésped dos cifras
            // que no casan entre sí.
            'nota' => 'Es el adelanto para asegurar la reserva, no el total: quedan '
                . sprintf(
                    '%s %s',
                    PmsTotalesPorMoneda::de($info)->porMoneda[$moneda]['saldo'] ?? '0.00',
                    $moneda,
                )
                . ' de saldo. Todavía no se ha cobrado nada de esta reserva.',
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Los extras agrupados por su propia moneda.
     *
     * `$hayExtras` viene de fuera porque la sospecha de alojamiento mal marcado ya puso el total
     * a cero, y ese caso NO puede acabar dando cifras: es el único que no puede mentir.
     *
     * @param list<array<string, mixed>> $cargos
     *
     * @return list<array{moneda: string, total: string}>
     */
    private function extrasPorMoneda(array $cargos, bool $hayExtras): array
    {
        if (!$hayExtras) {
            return [];
        }

        $porMoneda = [];

        foreach ($cargos as $fila) {
            $moneda = (string) ($fila['moneda'] ?? 'USD');
            $porMoneda[$moneda] = ($porMoneda[$moneda] ?? 0.0) + (float) ($fila['importe'] ?? 0);
        }

        ksort($porMoneda);

        $salida = [];
        foreach ($porMoneda as $moneda => $total) {
            $salida[] = ['moneda' => $moneda, 'total' => number_format($total, 2, '.', '')];
        }

        return $salida;
    }

    /**
     * Cuánto PIDE la política, se haya pagado ya o no.
     *
     * Es `calcular()`, no `pendiente()`, y la diferencia importa: en cuanto hay un pago
     * registrado `pendiente()` devuelve `null` —correctamente, porque ese pago ES el prepago—
     * y el agente se quedaba **sin saber cuánto vale una noche**.
     *
     * Se vio en la reserva V4JE5Q: la huésped preguntó «me piden el pago de la primera noche,
     * ¿cuánto es?» habiendo pagado ya los 30, y el agente contestó «150.00», que es el
     * alojamiento entero, antes de escalar. La cifra buena estaba calculada —la pinta el estado
     * de cuenta del huésped— pero no llegaba hasta aquí.
     *
     * Va en una clave aparte de `prepago_pendiente` a propósito: son dos preguntas distintas
     * («cuánto vale» y «cuánto falta») y fundirlas es cobrar de más. `ya_cubierto` dice cuál de
     * las dos aplica sin que el modelo tenga que restar.
     *
     * @return array<string, mixed>|null
     */
    private function adelantoDeLaPolitica(PmsInformacionFinanciera $info, string $moneda): ?array
    {
        $prepago = $this->prepagoCalculador->calcular($info);

        if ($prepago === null) {
            return null;
        }

        $politica = PmsPoliticaPrepago::tryFrom($prepago['politica']);
        // «¿Hay algún pago?», no «cuánto». Se pregunta al VO para no depender de un escalar en
        // retirada; la respuesta es la misma.
        $cubierto = array_filter(
            PmsTotalesPorMoneda::de($info)->porMoneda,
            static fn (array $c): bool => (float) $c['pagos'] > 0.0,
        ) !== [];

        return array_filter([
            'monto' => $prepago['monto'],
            'moneda' => $moneda,
            'politica' => $politica?->etiqueta(),
            'ya_cubierto' => $cubierto,
            'nota' => $cubierto
                ? 'Esto es lo que pide la política de adelanto, y YA ESTÁ CUBIERTO con lo que '
                    . 'tiene pagado. Sirve para responder «¿cuánto es la primera noche?»; no se '
                    . 'lo vuelvas a pedir.'
                : 'Es lo que pide la política de adelanto y todavía no se ha pagado nada.',
        ], static fn ($v) => $v !== null && $v !== '');
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

    /**
     * La cuenta de un huésped cuyo canal ya le cobró todo.
     *
     * Sólo se le enseñan los EXTRAS: lo que consumió aquí y nos debe a nosotros —una cena, un
     * traslado, una noche extra—, que sí reconoce y sí puede pagar. Lo demás es contabilidad
     * interna del canal y se excluye por el flag `esAutomatico`, sin adivinar por importe ni
     * por subtipo. Es la contraparte exacta de `PmsReservaPaxProvider::pagosVisibles()`.
     *
     * Si no queda nada, no se devuelve un cero: se devuelve que su pago está cerrado con la
     * plataforma. Un «saldo 0.00» invita al modelo a decir «no debes nada» y de ahí a hablar
     * de importes que no debe mencionar; una frase sin cifras cierra la puerta.
     */
    private function cuentaDeCanalQueCobra(
        string $reservaId,
        PmsReserva $reserva,
        PmsInformacionFinanciera $info,
        string $moneda,
        ?string $idioma
    ): SkillResult {
        $cargos = array_values(array_filter(
            $this->cargos($info, $idioma, excluirEspejoCanal: true),
            static fn (array $fila): bool => $fila !== []
        ));

        $pagos = $this->pagos($info, excluirEspejoCanal: true);

        $totalExtras = array_sum(array_map(
            static fn (array $fila): float => (float) ($fila['importe'] ?? 0),
            $cargos
        ));

        $base = [
            'reserva_id' => $reservaId,
            'huesped' => $this->huesped($reserva),
            'tiene_cuenta' => true,
            'canal_ya_cobro' => true,
            // Aquí la objeción es aún más probable: no se le da ninguna cifra, así que la que
            // él tiene delante es la de la plataforma y no hay con qué compararla.
            'si_discute_el_importe' => 'Si dice que en la app del canal ve OTRA cifra o que pagó '
                . 'un importe distinto, NO improvises: pídeme el tema de pagos con consultar_guia '
                . 'poniendo «ya_lo_intento».',
            'idioma_huesped' => $idioma,
        ];

        // 🛡️ INVARIANTE: en un canal que ya cobró, el ALOJAMIENTO nunca es nuestro.
        //
        // Si aparece aquí, el flag `esAutomatico` de ese cargo está mal puesto — pasa en
        // producción: hay una reserva de Airbnb con «Alojamiento 130.00» y «Suplemento de
        // limpieza 15.00» sin marcar. Y este método no puede limitarse a repetir el dato,
        // porque lo AFIRMA: dice que lo que devuelve son extras «que se pagan a nosotros».
        // Con el flag mal, eso es reclamarle a un huésped 145.00 que ya le pagó a Airbnb.
        //
        // Así que ante la contradicción no se elige un lado: se cae al caso sin cifras, que
        // es el único que no puede mentir, y se deja constancia para que alguien arregle el
        // dato. La web tiene el mismo fallo y sí le enseña el importe; corregirlo allí es
        // otra tarea, pero el agente no va a ser quien lo diga en voz alta.
        $sospechosos = array_filter(
            $cargos,
            static fn (array $fila): bool => ($fila['tipo'] ?? null) === PmsTipoCargo::ALOJAMIENTO->value
        );

        if ($sospechosos !== []) {
            $this->logger->warning(
                'consultar_cuenta: alojamiento sin marcar como espejo en un canal que ya cobró. '
                . 'Revisar `es_automatico` de esos cargos.',
                ['reserva' => $reservaId, 'cargos' => count($sospechosos)]
            );

            $totalExtras = 0.0;
        }

        // Mismo corte que `PmsReservaPaxProvider::cifras()`: manda el TOTAL, no si la lista
        // viene vacía. Unos extras que suman cero —anulados, o un cargo de cortesía— no son
        // algo que enseñar, y comprobar la lista además del total habría hecho que las dos
        // superficies dijeran cosas distintas en ese caso.
        if ($totalExtras <= 0.0) {
            return SkillResult::ok(array_filter($base + [
                'mensaje' => 'Su pago está cerrado con la plataforma donde reservó: aquí no '
                    . 'tiene nada pendiente. NO le des importes, ni totales, ni saldo: las '
                    . 'cifras que guardamos son lo que la plataforma nos remite, no lo que él '
                    . 'pagó, y no van a cuadrar con su recibo. Si insiste con cifras, dile que '
                    . 'las consulte en la propia plataforma.',
            ], static fn ($v) => $v !== null));
        }

        return SkillResult::ok(array_filter($base + [
            'moneda_de_cotizacion' => $moneda,
            'cargos' => $cargos,
            'pagos' => $pagos,
            // ⚠️ POR MONEDA, y no una suma. Era `number_format($totalExtras)`, que sumaba los
            // importes sin mirar en qué moneda estaban: un extra de S/ 50 y otro de US$ 12 daban
            // «62.00» etiquetado con la moneda de la ficha. Al huésped se le acaba diciendo una
            // cifra que no existe, y en el único bloque de esta skill que SÍ le puede dar
            // importes — los extras son lo que se nos paga a nosotros.
            'extras_por_moneda' => $this->extrasPorMoneda($cargos, $totalExtras > 0.0),
            'mensaje' => 'El alojamiento ya lo cobró la plataforma donde reservó. Lo que ves '
                . 'aquí son SÓLO los extras consumidos aquí, que se pagan a nosotros. No '
                . 'menciones el total de la reserva ni el saldo global: esas cifras son '
                . 'internas y no cuadran con lo que él pagó.',
        ], static fn ($v) => $v !== null));
    }

    /**
     * @param bool $excluirEspejoCanal Deja fuera la contabilidad espejo del canal que cobra
     *        por nosotros. Ver {@see self::cuentaDeCanalQueCobra()}.
     */
    /**
     * @param string|null $idioma Idioma del huésped, para elegir la explicación del cargo.
     *
     * @return list<array<string, mixed>>
     */
    private function cargos(PmsInformacionFinanciera $info, ?string $idioma, bool $excluirEspejoCanal = false): array
    {
        $filas = [];

        foreach ($info->getCargos() as $cargo) {
            /** @var PmsCargoFinanciero $cargo */

            // `esAutomatico` en un cargo NO significa «lo generó el sistema»: los cargos de
            // reservas directas también se generan solos y llevan el flag en false a
            // propósito, porque el huésped nos los paga a nosotros y tiene que verlos. Lo que
            // marca es «esto es contabilidad interna del canal». Ver PmsCargoFinanciero.
            if ($excluirEspejoCanal && $cargo->isEsAutomatico()) {
                continue;
            }

            $filas[] = array_filter([
                'concepto' => $this->concepto($cargo),
                // La explicación REDACTADA PARA EL HUÉSPED, cuando el operador la escribió.
                // `concepto` viene del canal —códigos, nombres de tarifa sin normalizar, a
                // veces placeholders— y sirve para cuadrar, no para explicar; ésta se puede
                // repetir tal cual. La mayoría de los cargos no la tienen y se entienden por
                // su tipo, así que `array_filter` la quita y no gasta tokens.
                // Ver docs/FinanzasEnlacesPago.md §8.
                'explicacion_para_huesped' => $cargo->descripcionClienteEn($idioma ?? 'es'),
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

    /**
     * @param bool $excluirEspejoCanal Deja fuera el pago que la OTA se apunta a sí misma.
     *        Mismo criterio que `PmsReservaPaxProvider::pagosVisibles()`.
     *
     * @return list<array<string, mixed>>
     */
    private function pagos(PmsInformacionFinanciera $info, bool $excluirEspejoCanal = false): array
    {
        $filas = [];

        foreach ($info->getPagos() as $pago) {
            /** @var PmsPagoFinanciero $pago */
            if ($excluirEspejoCanal && $pago->isEsAutomatico()) {
                continue;
            }

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
