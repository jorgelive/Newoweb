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
use App\Entity\User;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Service\Finance\MonedaResolver;
use App\Pms\Service\Finance\PmsCuentaSimulador;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
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
final readonly class RegistrarPagoSkill implements SkillInterface, SkillDominioInterface
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

    /**
     * Cómo dice el operador que cobró él mismo.
     *
     * El modelo traduce «me pagó a mí» / «lo cobré yo» a uno de estos, en vez de teclear el
     * nombre del operador —que no siempre sabe— o de dejarlo caer en el cobrador principal,
     * que sería atribuirle a recepción un dinero que recogió otra persona.
     */
    private const array ALIAS_YO = ['yo', 'mi', 'mí', 'me', 'a mi', 'a mí', 'yo mismo', 'yo misma'];

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
                . 'llamarme con lo que conteste, sin inventar nada. APUNTA QUIÉN COBRÓ: en '
                . 'efectivo, Yape, tarjeta o Western Union el dinero lo recibe una persona, y '
                . 'sin saber quién no se puede cuadrar la caja. Si dicen «le pagó a María», '
                . 'pásame cobrador="María"; si dicen «me pagó a mí» o «lo cobré yo», pásame '
                . 'cobrador="yo"; si no dicen nada, omítelo y se atribuye a recepción. DILE '
                . 'SIEMPRE AL OPERADOR a quién ha quedado atribuido: viene en cobrado_por, y '
                . 'si esa vez cobró otra persona tiene que poder corregirlo antes de aprobar. '
                . 'Si trae advertencia, '
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
                SkillParameter::texto('salda_deuda_en', 'A qué deuda se aplica el dinero cuando '
                    . 'el cobro entra en una moneda SIN cargos. NO lo mandes de entrada: la '
                    . 'skill te preguntará si hace falta, y entonces respondes con la moneda '
                    . '(«USD») o con «no» si ese dinero es otra cosa —una propina, un extra sin '
                    . 'cargar— y debe quedar como saldo a favor en la moneda en que entró.',
                    requerido: false),
                SkillParameter::texto('importe_incluye_comision', 'Sólo para medios con '
                    . 'comisión. "si" = el importe es lo que se pasó por el POS; "no" = es lo '
                    . 'que debe abonar a la deuda. Omítelo para que la skill te dé las dos '
                    . 'cifras y puedas preguntar.', requerido: false),
                SkillParameter::texto('cobrador', 'Quién RECIBIÓ el dinero. Si dicen un nombre '
                    . '(«se lo pagó a María»), pásalo tal cual: basta el nombre de pila. Si el '
                    . 'operador dice que lo cobró ÉL MISMO («me pagó a mí», «lo cobré yo»), '
                    . 'pon "yo" y se apuntará a su nombre. Si no dicen nada, omítelo: se '
                    . 'atribuye a quien cobra por defecto en recepción, y verás en la '
                    . 'previsualización a quién ha ido para poder corregirlo.', requerido: false),
                SkillParameter::texto('referencia', 'Nº de operación, voucher o referencia, si '
                    . 'la hay.', requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la confirmación explícita '
                    . 'del operador. false para previsualizar.'),
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

        // ⚠️ Un medio que falta NO corta aquí con un error. Lo hacía, y era lo que rompía la
        // repregunta única: el operador contestaba «yape», entonces la skill llegaba por fin a
        // mirar la moneda y le preguntaba OTRA vez. Tres vueltas para un pago. Ahora entra en
        // la misma bolsa que el resto, y sólo se saltan las comprobaciones que de verdad
        // dependen de él —la comisión y el cobrador, que salen del medio—.
        $medio = PmsMedioPago::tryFrom(strtolower(trim((string) ($entrada['medio_pago'] ?? ''))));

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
        $pct = $medio !== null ? (float) $medio->comisionPorcentaje() : 0.0;

        $monedaIndicada = strtoupper(trim((string) ($entrada['moneda'] ?? '')));
        $incluye = strtolower(trim((string) ($entrada['importe_incluye_comision'] ?? '')));

        // Todo lo que un importe suelto no trae y que vale dinero. Se pide JUNTO: cada
        // repregunta de más es una vuelta más al operador por el mismo pago.
        $faltan = [];

        // 💳 Cómo pagó. Va el primero porque de él dependen los dos siguientes.
        if ($medio === null) {
            $faltan[] = 'medio_pago';
        }

        // 💱 «20» no dice de qué. Sólo es inequívoco si la cuenta ya está en la moneda local.
        // No depende del medio, así que se pregunta en la misma tanda.
        if ($monedaIndicada === '' && $monedaCuenta->getId() !== self::MONEDA_LOCAL) {
            $faltan[] = 'moneda';
        }

        // 🎯 ¿A QUÉ DEUDA SE APLICA ESTE DINERO?
        //
        // Aparece cuando el cobro entra en una moneda donde NO hay ningún cargo: ese dinero no
        // puede saldar nada suyo. Es el caso real de GASUNN —cargos de Booking en dólares, cobro
        // por Yape en soles— y es la razón de que esta skill NO se simplificara al dejar de
        // convertir: sin la pregunta, el agente contestaría «saldo US$ 65.97 pendiente y
        // S/ 223.70 a favor del huésped», que es justo lo falso.
        //
        // Se pregunta, no se decide: a veces esos soles son de verdad otra cosa —una propina, un
        // extra que todavía no se ha cargado—, y darlos por aplicados sería cobrar algo que nadie
        // acordó.
        //
        // ⚠️ Va en ESTA tanda y no en un viaje aparte. Es la regla de la skill: si faltan tres
        // datos se piden los tres juntos. Sólo se puede plantear cuando la moneda del cobro ya se
        // conoce — si está en `$faltan`, la candidata sale null y la pregunta espera al siguiente
        // turno, que es cuando de verdad se puede formular.
        $monedaOrigen = $monedaIndicada !== ''
            ? $this->monedas->resolve($monedaIndicada)
            : $monedaCuenta;

        $totales = PmsTotalesPorMoneda::de($info);
        $conDeuda = array_keys(array_filter(
            $totales->porMoneda,
            static fn (array $c): bool => (float) $c['cargos'] > 0.0,
        ));

        // Sólo con UNA candidata: con deuda en dos monedas distintas de la del cobro, elegir por
        // el operador sería adivinar a cuál se aplica.
        $candidata = !in_array($monedaOrigen->getId(), $conDeuda, true) && count($conDeuda) === 1
            ? $conDeuda[0]
            : null;

        $imputacion = strtolower(trim((string) ($entrada['salda_deuda_en'] ?? '')));
        // Se consulta aquí y no más abajo porque la PREGUNTA ya necesita enseñar la equivalencia:
        // «¿va contra la deuda en USD? abonaría 65.97» se responde mucho mejor que «¿va contra la
        // deuda en USD?» a secas.
        $tipoDelDiaParaPreview = $this->tipoCambio->venta();

        if ($candidata !== null && $imputacion === '') {
            $faltan[] = 'salda_deuda_en';
        }

        // 💳 Con comisión, «20» tampoco dice si es lo del POS o lo que abona. Sólo se puede
        // saber si hay comisión cuando ya se sabe el medio: sin él no se pregunta todavía.
        if ($pct > 0.0 && !in_array($incluye, ['si', 'sí', 'no'], true)) {
            $faltan[] = 'importe_incluye_comision';
        }

        // 🧑 ¿Quién recibió el dinero? Sólo en los medios que cobra una persona: en una
        // transferencia no hay a quién apuntar. Se resuelve aquí para que un nombre que no
        // existe o que baila entre dos personas entre en la MISMA repregunta que el resto.
        $cobradorIndicado = trim((string) ($entrada['cobrador'] ?? ''));
        $cobrador = null;
        $candidatos = [];
        // Por qué falta, no sólo que falta: «no hay nadie que se llame María» y «hay dos
        // Marías» piden cosas distintas al operador. Inferirlo de si $candidatos viene lleno
        // no funciona —en el caso «no encontrado» se rellena con la lista completa para poder
        // ofrecerla—, y el resultado era decirle «María coincide con varias personas» seguido
        // de tres nombres que no son María.
        $porqueFaltaCobrador = null;

        // Sin medio no se sabe si lo cobró alguien en mano: una transferencia no tiene
        // cobrador. Se resuelve en la vuelta siguiente, cuando el medio ya venga.
        if ($medio !== null && $medio->seCobraEnMano()) {
            if (in_array(mb_strtolower($cobradorIndicado), self::ALIAS_YO, true)) {
                // «me pagó a mí»: lo cobró quien está manejando el chat. Es el único caso en
                // que el cobrador sale del actor y no de lo que se teclea.
                $yo = $actor->usuario();

                if ($yo === null) {
                    return SkillResult::error(
                        'No puedo saber quién eres para apuntarte el cobro. Dime el nombre de '
                        . 'la persona que recibió el dinero.'
                    );
                }

                // El operador tampoco se salta el filtro por ser quien escribe: manejar el
                // chat y estar autorizado a recibir dinero son cosas distintas. Se mira la
                // columna literal —igual que el resto— y NO `tieneRol()`, que a un
                // SUPER_ADMIN le abriría esta puerta como le abre las demás.
                //
                // ⚠️ NO es `SkillResult::error()`. Lo era, y el modelo lo trataba como un fallo
                // a reintentar: volvía a llamar SIN cobrador y el pago acababa en el cobrador
                // por defecto, sin que el operador se enterara de que su «me pagó» se había
                // descartado. Entra en la misma repregunta que un nombre desconocido o
                // ambiguo, que es lo que de verdad es: falta saber quién cobró.
                if (!in_array(Roles::COBRADOR, $yo->getRoles(), true)) {
                    $porqueFaltaCobrador = 'yo_sin_rol';
                    $candidatos = $this->cobradoresPosibles();
                } else {
                    $cobrador = $yo;
                }
            } elseif ($cobradorIndicado !== '') {
                $coincidencias = $this->cobradoresPosibles($cobradorIndicado);

                if (count($coincidencias) === 1) {
                    $cobrador = $coincidencias[0];
                } else {
                    // Ni 0 (no existe) ni 2+ (dos Marías) se resuelven adivinando: apuntar el
                    // efectivo a la persona equivocada descuadra la caja de las dos.
                    $porqueFaltaCobrador = $coincidencias === [] ? 'no_encontrado' : 'ambiguo';
                    $candidatos = $coincidencias !== [] ? $coincidencias : $this->cobradoresPosibles();
                }
            } else {
                // Nadie lo ha dicho: cae en recepción, que es quien cobra casi todo. Se
                // atribuye en silencio pero NO se aplica en silencio: `cobrado_por` va en la
                // previsualización, así que el operador lo ve antes de aprobar y lo corrige
                // si esa vez cobró otra persona.
                $cobrador = $this->cobradorPrincipal();

                // Sin nadie marcado como principal no hay defecto que valga: se pregunta.
                if ($cobrador === null) {
                    $porqueFaltaCobrador = 'sin_indicar';
                    $candidatos = $this->cobradoresPosibles();
                }
            }

            if ($porqueFaltaCobrador !== null) {
                $faltan[] = 'cobrador';
            }
        }

        if ($faltan !== []) {
            return SkillResult::ok(array_filter([
                'reserva_id' => $reservaId,
                'huesped' => $huesped,
                'moneda_de_la_cuenta' => $monedaCuenta->getId(),
                // `null` cuando el medio es justo lo que falta: `array_filter` lo quita y el
                // modelo no ve un campo vacío que pueda confundir con un valor.
                'medio' => $medio?->label(),
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
                'cobradores_posibles' => $candidatos !== []
                    ? array_map(static fn (User $u): array => [
                        'cobrador' => $u->getFullname() ?: (string) $u->getUserIdentifier(),
                    ], $candidatos)
                    : null,
                'pregunta' => $this->pregunta(
                    array_values($faltan),
                    $importe,
                    $medio,
                    $monedaCuenta->getId(),
                    $cobradorIndicado,
                    $candidatos,
                    $porqueFaltaCobrador,
                    $monedaOrigen->getId(),
                    $candidata,
                    $candidata !== null && $tipoDelDiaParaPreview !== null
                        ? sprintf(
                            '%.2f %s',
                            $monedaOrigen->getId() === 'PEN'
                                ? $importe / (float) $tipoDelDiaParaPreview
                                : $importe * (float) $tipoDelDiaParaPreview,
                            $candidata,
                        )
                        : null,
                ),
            ], static fn ($v) => $v !== null));
        }

        // Con `$faltan` vacío el medio existe por construcción —si no, estaría en la lista—,
        // pero eso es un razonamiento que hay que rehacer cada vez que se lee. Explícito aquí:
        // de aquí en adelante `$medio` se usa sin `?->` y así se ve por qué se puede.
        if ($medio === null) {
            return SkillResult::error('Falta el medio de pago. Pregunta al operador cómo pagó.');
        }

        $cobrado = $pct > 0.0 && $incluye !== 'no' ? $importe : $importe * (1 + $pct / 100);
        $neto = $pct > 0.0 && $incluye !== 'no' ? $importe / (1 + $pct / 100) : $importe;

        // 💱 EL COBRO SE GUARDA EN LA MONEDA EN QUE ENTRÓ. Ya no se convierte.
        //
        // Antes se pasaba a la moneda de la cuenta con el tipo del día. El importe resultante era
        // correcto y aun así **el huésped no lo reconocía**: quien abonó S/ 223.70 por Yape no
        // entiende «pagaste US$ 65.97». Desde el 16/08/2026 los importes se suman por moneda y no
        // se convierten (§12.2b), así que aquí sólo hay que registrar lo que pasó.
        // Ya resuelto arriba, para poder enseñar la equivalencia en la pregunta.
        $tipoDelDia = $tipoDelDiaParaPreview;

        $cobrado = round($cobrado, 2);
        $neto = round($neto, 2);

        $totales = PmsTotalesPorMoneda::de($info);

        $monedaSaldada = $candidata !== null && !in_array($imputacion, ['', 'no', 'nada', 'ninguna'], true)
            ? $this->monedas->resolve(strtoupper($imputacion))
            : null;

        // El saldo de la moneda en la que de verdad se va a aplicar el dinero.
        $monedaAfectada = ($monedaSaldada ?? $monedaOrigen)->getId();
        $saldoAntes = (float) ($totales->porMoneda[$monedaAfectada]['saldo'] ?? '0');

        $aplicado = $monedaSaldada === null
            ? $neto
            : ($monedaOrigen->getId() === 'PEN' ? $neto / (float) $tipoDelDia : $neto * (float) $tipoDelDia);

        $saldoDespues = round($saldoAntes - $aplicado, 2);

        // Un número raro no se señala solo: si queda a favor del huésped hay que DECIRLO, o el
        // modelo lo lee como un dato más y el operador confirma sin fijarse.
        $advertencia = null;

        // ⚠️ Un cobro IMPUTADO deja casi siempre un residuo: el cambio del mostrador no es el de
        // los cargos, y sobre 66 dólares eso son 45 céntimos. Avisar de «el pago excede lo
        // pendiente» por eso convierte la alarma en ruido, y una alarma que salta siempre deja de
        // leerse — que es justo lo que no puede pasar con la única señal de sobrepago que hay.
        //
        // Se usa la MISMA tolerancia que el cuadre del panel, por el mismo motivo: la holgura la
        // concede haber pasado por una tasa de cambio.
        $tolerancia = $monedaSaldada === null ? 0.0 : max(
            (float) PmsTotalesPorMoneda::UMBRAL_CUADRE_MINIMO,
            abs($aplicado) * (float) PmsTotalesPorMoneda::UMBRAL_CUADRE_PROPORCION,
        );

        if ($saldoDespues < 0.0 && abs($saldoDespues) <= $tolerancia) {
            $advertencia = sprintf(
                'El saldo queda en %.2f %s. Es el redondeo del cambio —se cobró en %s y la deuda '
                . 'estaba en %s—, no un sobrepago: dalo por saldado.',
                $saldoDespues,
                $monedaAfectada,
                $monedaOrigen->getId(),
                $monedaAfectada,
            );
        } elseif ($saldoDespues < 0.0) {
            $advertencia = $saldoAntes <= 0.0
                ? sprintf(
                    'ATENCIÓN: esta cuenta YA estaba saldada (saldo %.2f). Este pago la deja en '
                    . '%.2f %s a favor del huésped. Díselo al operador y pregúntale si falta '
                    . 'registrar algún cargo antes.',
                    $saldoAntes,
                    $saldoDespues,
                    $monedaAfectada
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
            'cobrado_por' => $cobrador?->getFullname(),
            // En la moneda en que ENTRÓ el dinero: es lo que el huésped reconoce de su recibo.
            'cobrado_al_huesped' => sprintf('%.2f %s', $cobrado, $monedaOrigen->getId()),
            'comision_porcentaje' => $medio->comisionPorcentaje(),
            'abona_a_la_deuda' => sprintf('%.2f %s', $aplicado, $monedaAfectada),
            // Sólo cuando el dinero cruzó de moneda de verdad. En un cobro que salda lo suyo no
            // hay ningún cambio aplicado, y decir uno invitaría a explicárselo al huésped.
            'tipo_cambio_aplicado' => $monedaSaldada !== null ? $tipoDelDia : null,
            'salda_deuda_en' => $monedaSaldada?->getId(),
            'saldo_antes' => sprintf('%.2f %s', $saldoAntes, $monedaAfectada),
            'saldo_despues' => sprintf('%.2f %s', $saldoDespues, $monedaAfectada),
            'advertencia' => $advertencia,
        ];

        $resumen = array_filter($resumen, static fn ($v) => $v !== null);

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'registrado' => false,
                'motivo' => 'falta_confirmacion',
                // La foto entera de la cuenta, no sólo el saldo: el operador aprueba mejor
                // viendo en qué queda todo que leyendo una frase.
                'simulacion' => $this->simulador->simular($info, deltaPagos: $aplicado, moneda: $monedaAfectada),
                'pregunta_aprobacion' => '¿Apruebas el cambio?',
                'previsualizacion' => sprintf(
                    'Se registrará un pago de %s por %s a nombre de %s (%s)%s. Abona %s y el '
                    . 'saldo quedará en %s. CONFIRMA CON EL OPERADOR QUE ES ESE HUÉSPED antes '
                    . 'de aplicarlo.',
                    $resumen['cobrado_al_huesped'],
                    $medio->label(),
                    $huesped !== '' ? $huesped : 'sin nombre',
                    $reserva->getLocalizador() ?? 'sin localizador',
                    $cobrador !== null ? ', cobrado por ' . $cobrador->getFullname() : '',
                    $resumen['abona_a_la_deuda'],
                    $resumen['saldo_despues']
                ),
            ]);
        }

        $pago = new PmsPagoFinanciero();
        $pago->setMonto(sprintf('%.2f', $neto));
        // En la moneda en que ENTRÓ el dinero, no en la de la cuenta.
        $pago->setMoneda($monedaOrigen);
        $pago->setMedioPago($medio);
        $pago->setComisionPorcentaje($medio->comisionPorcentaje());
        $pago->setFechaPago(new DateTimeImmutable());
        $pago->setEsAutomatico(false);
        // Null sólo en los medios que no cobra nadie (transferencia, PayPal): en los demás
        // el bloque de $faltan no deja llegar hasta aquí sin cobrador resuelto.
        $pago->setCobrador($cobrador);

        $referencia = trim((string) ($entrada['referencia'] ?? ''));
        if ($referencia !== '') {
            $pago->setReferencia($referencia);
        }

        // El tipo del día, haya o no habido conversión: es lo que mantiene vivo el pago si
        // mañana se cambia la moneda base de la cuenta (ver el bloque de conversión).
        if ($tipoDelDia !== null) {
            $pago->setTipoCambio($tipoDelDia);
        }

        if ($monedaSaldada !== null) {
            $pago->setMonedaSaldada($monedaSaldada);
        }

        $info->addPago($pago);
        $this->em->persist($pago);
        $this->em->flush();

        return SkillResult::ok($resumen + [
            'registrado' => true,
            'pago_id' => (string) $pago->getId(),
            // El saldo REAL de la moneda a la que fue el dinero, recalculado desde las
            // colecciones tras el flush.
            //
            // ⚠️ Era `$info->getSaldo()`, y el comentario decía «lo recalcula el listener de
            // coherencia» — **y no es cierto**: el rollup es SQL crudo y la entidad gestionada
            // conserva los escalares con los que se cargó. Devolvía el saldo de ANTES del pago,
            // y encima convertido a la moneda de la cabecera. El value object suma desde
            // `$info->getPagos()`, que ya incluye el que se acaba de añadir.
            'saldo_real' => sprintf(
                '%.2f %s',
                (float) (PmsTotalesPorMoneda::de($info)->porMoneda[$monedaAfectada]['saldo'] ?? '0'),
                $monedaAfectada,
            ),
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
     * @param list<string> $faltan Los campos que el modelo no rellenó.
     * @param list<User> $candidatos Los cobradores que encajan con lo que dijo el huésped.
     */
    private function pregunta(
        array $faltan,
        float $importe,
        ?PmsMedioPago $medio,
        string $monedaCuenta,
        string $cobradorIndicado = '',
        array $candidatos = [],
        ?string $porqueFaltaCobrador = null,
        string $monedaCobro = '',
        ?string $candidata = null,
        ?string $equivalente = null
    ): string {
        $partes = [];

        if (in_array('medio_pago', $faltan, true)) {
            $partes[] = sprintf(
                '¿cómo pagó? (%s)',
                implode(', ', array_map(
                    static fn (PmsMedioPago $m) => $m->label(),
                    PmsMedioPago::cases()
                ))
            );
        }

        if (in_array('moneda', $faltan, true)) {
            $partes[] = sprintf(
                '¿los %.2f son soles o %s? La cuenta se lleva en %s',
                $importe,
                $monedaCuenta,
                $monedaCuenta
            );
        }

        if (in_array('salda_deuda_en', $faltan, true) && $candidata !== null) {
            $partes[] = sprintf(
                'el cobro entra en %s y en esa moneda no hay ningún cargo, así que no puede '
                . 'saldar nada suyo: ¿va contra la deuda en %s?%s Si no —es una propina, o un '
                . 'extra que todavía no se ha cargado—, dilo y queda como saldo a favor en %s',
                $monedaCobro,
                $candidata,
                $equivalente !== null ? sprintf(' Al cambio de hoy abonaría %s.', $equivalente) : '',
                $monedaCobro,
            );
        }

        if ($medio !== null && in_array('importe_incluye_comision', $faltan, true)) {
            $partes[] = sprintf(
                'con %s hay %s%% de comisión, ¿el importe es lo que se pasó por el POS o lo que '
                . 'debe abonar a la deuda?',
                $medio->label(),
                $medio->comisionPorcentaje()
            );
        }

        if (in_array('cobrador', $faltan, true)) {
            $nombres = implode(', ', array_map(
                static fn (User $u): string => $u->getFullname() ?: (string) $u->getUserIdentifier(),
                $candidatos
            ));

            // Tres redacciones porque el operador tiene que entender qué pasó con LO QUE DIJO:
            // no es lo mismo no haber dado nombre que haber dado uno que no existe. El caso se
            // recibe hecho y NO se deduce de $candidatos: en «no encontrado» esa lista viene
            // con TODOS los cobradores (para poder ofrecerlos), así que mirarla decía
            // «María coincide con varias personas» seguido de tres nombres que no son María.
            $partes[] = match ($porqueFaltaCobrador) {
                'no_encontrado' => sprintf(
                    'no hay ningún cobrador que se llame «%s». ¿A quién se lo dieron? Puede '
                    . 'ser %s. Si la persona no está, hay que darla de alta con el rol de '
                    . 'cobrador antes de registrar el pago',
                    $cobradorIndicado,
                    $nombres !== '' ? $nombres : '(no hay nadie con el rol de cobrador)'
                ),
                'ambiguo' => sprintf(
                    '«%s» coincide con varias personas (%s). ¿Cuál de ellas lo cobró?',
                    $cobradorIndicado,
                    $nombres
                ),
                // Dijo «me pagó» pero su usuario no cobra. Se le dice POR QUÉ no se le puede
                // apuntar y se le pregunta quién fue: dejarlo caer callando en el cobrador
                // por defecto le haría creer que el dinero quedó a su nombre.
                'yo_sin_rol' => sprintf(
                    'tu usuario no tiene el rol de cobrador, así que el pago NO se te puede '
                    . 'apuntar a ti. ¿Quién recibió el dinero? Puede ser %s. Si de verdad lo '
                    . 'cobraste tú, hay que pedir que te asignen el rol de cobrador antes de '
                    . 'registrarlo',
                    $nombres !== '' ? $nombres : '(no hay nadie con el rol de cobrador)'
                ),
                default => sprintf(
                    '¿a quién le entregaron el dinero? Es un pago en %s, así que lo cobró '
                    . 'alguien. Opciones: %s',
                    $medio?->label() ?? 'mano',
                    $nombres !== '' ? $nombres : '(no hay nadie con el rol de cobrador)'
                ),
            };
        }

        return 'Pregúntale al operador: ' . implode('; y ', $partes)
            . '. Luego vuelve a llamarme con esos datos.';
    }

    /**
     * Personal que puede figurar como cobrador, filtrando por nombre si se da uno.
     *
     * El filtro de elegibilidad es `enabled = true` — el mismo que usa el desplegable del
     * panel (`PmsEnumAjaxController::getCobradores()`), y hay que mantenerlos a la par: si
     * aquí entrara alguien que allí no sale, el agente registraría pagos a nombre de alguien
     * que el operador no puede elegir a mano.
     *
     * La búsqueda es por coincidencia parcial sobre nombre y apellido porque el operador dice
     * «María», no «Maria Apaza».
     *
     * @return list<User>
     */
    /**
     * Quien cobra por defecto (recepción), o null si nadie está marcado.
     *
     * 🪞 Mismo criterio que el panel, que preselecciona a esta persona en el desplegable de
     * un pago nuevo. Si aquí y allí no coincidieran, el mismo pago quedaría a nombre de una
     * persona distinta según se registrara por chat o a mano.
     */
    private function cobradorPrincipal(): ?User
    {
        $principales = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.esCobradorPrincipal = true')
            ->andWhere('u.roles LIKE :rol')
            ->setParameter('rol', '%"' . Roles::COBRADOR . '"%')
            ->orderBy('u.firstname', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $principales[0] ?? null;
    }

    /** @return list<User> Los usuarios con rol de cobrador, por nombre. */
    private function cobradoresPosibles(string $busqueda = ''): array
    {
        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u')
            // Por la columna literal, igual que UserRepository::findByRole(): la jerarquía de
            // security.yaml no cuenta aquí, y es lo que se quiere (ver Roles::COBRADOR).
            // SIN filtrar por `enabled`: quien cobra en la casita no necesita entrar al panel.
            ->where('u.roles LIKE :rol')
            ->setParameter('rol', '%"' . Roles::COBRADOR . '"%')
            ->orderBy('u.firstname', 'ASC')
            ->addOrderBy('u.lastname', 'ASC');

        if ($busqueda !== '') {
            $qb->andWhere("LOWER(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) LIKE :q")
                ->setParameter('q', '%' . mb_strtolower($busqueda) . '%');
        }

        return array_values($qb->getQuery()->getResult());
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
