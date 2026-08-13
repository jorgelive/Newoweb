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
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Marca la salida tardía o la entrada temprana de una estancia. **Escribe.**
 *
 * 🔗 Último eslabón de la cadena, y el primero que toca datos. Escribe **un booleano** — pero
 * ese booleano dispara tres cosas más al hacer flush, y las tres se enumeran en
 * {@see self::consecuencias()} para que la previsualización no mienta por omisión.
 *
 * ### ⚠️ No uses RegistrarCargoSkill después de ésta
 *
 * `PmsCargosAutomaticosService::sincronizarExtras()` abre sola la línea «Salida tardía (noche
 * bloqueada) · Casita N» con importe **0.00**, a la espera de que el operador la valore: cuánto
 * vale salir más tarde se negocia caso por caso y sugerir un precio sería peor que no poner
 * ninguno. Añadir un cargo con `registrar_cargo` dejaría **dos conceptos iguales** en la cuenta
 * y el operador no sabría cuál es el bueno. El importe se pone en la línea que ya existe.
 *
 * ### Por qué marca un flag y no mueve `fin`
 *
 * `PmsExtensionEstanciaService` reacciona al flag creando un evento de extensión que bloquea
 * la noche y viaja al canal como `black`. Mover `fin` fue el primer intento del proyecto y se
 * descartó por dos motivos (§7.1.b de PmsBeds24ReservasSync): no servía para las OTA —cuyas
 * fechas nunca se envían— y no protegía del propio PMS, que seguía vendiendo la noche.
 *
 * Por eso esto SÍ funciona en reservas de OTA, mientras que mover fechas está prohibido.
 *
 * ### 🔑 Bloquear la noche es OPCIONAL, y a veces imposible
 *
 * Marcar la casilla retira de la venta la noche anterior a la entrada —o la posterior a la
 * salida— para tener margen de limpieza. Eso lo decide el operador, no se deduce de la hora:
 *
 * - **Noche libre** → se PREGUNTA si bloquearla. Retirar de la venta una noche vendible no
 *   puede ser el efecto secundario de apuntar una hora.
 * - **Noche ya vendida** → NO se pregunta nada, porque no se puede bloquear: la extensión
 *   quedaría superpuesta a una estancia real. Se registra la hora y se avisa de que la
 *   limpieza tendrá menos margen esa mañana. Si hace falta refuerzo, eso lo ve el operador.
 *
 * Con la noche vendida tampoco se abre la línea de cargo, porque cuelga de la misma casilla
 * ({@see \App\Pms\Service\Finance\PmsCargosAutomaticosService::sincronizarExtras()}). Lo que
 * se le quiera cobrar por ese horario se añade a mano en el panel.
 *
 * ### ⚠️ La confirmación depende del modelo, no del código
 *
 * Con `confirmado: false` la skill devuelve la previsualización y no escribe. Pero **nada
 * impide llamarla con `true` a la primera**: `confirmado` es un parámetro más y el servidor no
 * recuerda si hubo previsualización. Ver §11.1 de docs/Mensajeria.md.
 */
final readonly class AplicarCambioHorarioSkill implements SkillInterface, SkillDominioInterface
{
    /** Hora de referencia si el establecimiento no la tiene configurada. */
    private const string CHECK_IN_POR_DEFECTO = '14:00';
    private const string CHECK_OUT_POR_DEFECTO = '10:00';

    public function __construct(
        private EntityManagerInterface $em,
        private PmsDisponibilidadService $disponibilidad,
    ) {}

    public function nombre(): string
    {
        return 'aplicar_cambio_horario';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Marca la salida tardía o la entrada temprana de una estancia. Hace '
                . 'SÓLO esas dos cosas: NO mueve fechas, no cambia de casita, no alarga la '
                . 'estancia un día más. Si te piden mover un día, di que hay que hacerlo en el '
                . 'calendario del panel y no llames a esta skill. MODIFICA DATOS: antes de '
                . 'llamarla con confirmado=true, dile al usuario exactamente qué vas a hacer '
                . '(huésped, casita, qué se marca) y espera su sí. Si la llamas con '
                . 'confirmado=false te devuelve la previsualización sin tocar nada. Necesita '
                . 'el evento_id de buscar_estancias_de_reserva, y conviene comprobar antes con '
                . 'evaluar_cambio_horario que está permitido. LOCALIZA PRIMERO A QUIÉN: si te '
                . 'dicen «el de la casita 2», usa consultar_ocupacion con la fecha de hoy para '
                . 'saber quién es y con qué evento_id, y confirma el nombre con el operador. '
                . 'ENSEÑA LA PREVISUALIZACIÓN ENTERA: la respuesta trae «que_va_a_pasar» con '
                . 'TODAS las consecuencias —el bloqueo que sale a los portales, la línea de '
                . 'cargo que se abre—. Léeselas una a una al operador, no las resumas, y '
                . 'termina con la pregunta de pregunta_aprobacion. PÁSAME LA HORA si te la '
                . 'dicen («sale a las 14:00»): si cabe dentro del horario del alojamiento sólo '
                . 'la registro y no bloqueo nada; si lo excede, además marco el horario extra. '
                . 'BLOQUEAR LA NOCHE ES OPCIONAL: si está libre, la pregunta_aprobacion te '
                . 'preguntará si además quieres retirarla de la venta para tener margen de '
                . 'limpieza; léesela tal cual y si el operador dice que sólo apuntes la hora, '
                . 'no vuelvas a llamarme con confirmado=true. Y si la respuesta trae '
                . '«no_se_puede_bloquear», esa noche YA ESTÁ VENDIDA: NO ofrezcas bloquear nada '
                . 'ni hables de solapes ni de conflictos, porque no va a ocurrir ninguno. '
                . 'Limítate a decir que se registra la hora y que la limpieza tendrá menos '
                . 'margen esa mañana.',
            parametros: [
                SkillParameter::texto('evento_id', 'Identificador de la estancia.'),
                SkillParameter::texto('cambio', '"salida_tardia" o "entrada_temprana".'),
                SkillParameter::texto('hora', 'La hora acordada, en formato HH:MM de 24 horas '
                    . '("06:00", "18:00"). Si cabe dentro del horario normal del alojamiento '
                    . 'sólo se registra; si lo excede, además se marca y se bloquea la noche. '
                    . 'Sin hora, se marca sin registrar ninguna. CÓMO LEER LA HORA QUE TE DIGAN: '
                    . 'un número suelto es la hora del reloj de 24 h tal cual — «a las 6» es '
                    . '06:00 y «a las 18» es 18:00. Sólo pasa a la tarde si te lo dicen con '
                    . 'palabras: «6 de la tarde», «6 pm» o «18» son 18:00. Ante la duda NO '
                    . 'adivines: pregúntale al operador si son las 6 de la mañana o las 6 de la '
                    . 'tarde, porque equivocarse son doce horas de diferencia en una salida.',
                    requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO después de que el usuario '
                    . 'haya confirmado explícitamente. false para previsualizar.'),
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
        $eventoId = trim((string) ($entrada['evento_id'] ?? ''));
        $cambio = strtolower(trim((string) ($entrada['cambio'] ?? '')));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($eventoId)) {
            return SkillResult::error('El evento_id no es válido.');
        }

        if (!in_array($cambio, ['salida_tardia', 'entrada_temprana'], true)) {
            return SkillResult::error('El cambio debe ser "salida_tardia" o "entrada_temprana".');
        }

        $evento = $this->em->getRepository(PmsEventoCalendario::class)->find($eventoId);
        if ($evento === null) {
            return SkillResult::error('No existe ninguna estancia con ese identificador.');
        }

        $esSalida = $cambio === 'salida_tardia';
        $yaMarcado = $esSalida ? $evento->isSalidaTardia() : $evento->isEntradaTemprana();

        // 🕒 La hora acordada, si la hay. Se compara con el horario del establecimiento para
        // saber si esto es un horario extra de verdad o sólo un dato que apuntar.
        $horaPedida = trim((string) ($entrada['hora'] ?? ''));

        if ($horaPedida !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaPedida)) {
            return SkillResult::error('La hora debe ir en formato HH:MM, por ejemplo "14:00".');
        }

        $limite = $this->horarioDelEstablecimiento($evento, $esSalida);
        $excede = $horaPedida !== '' && $this->excedeElHorario($horaPedida, $limite, $esSalida);

        // Sin hora se conserva el comportamiento de siempre: marcar y bloquear. Con hora, sólo
        // se bloquea si de verdad se sale del horario — que es la diferencia entre «salgo a las
        // 9:30» (un dato) y «salgo a las 14:00» (una noche que deja de venderse).
        $bloquea = $horaPedida === '' || $excede;

        $resumen = [
            'evento_id' => $eventoId,
            'huesped' => trim(
                ($evento->getReserva()?->getNombreCliente() ?? '') . ' ' .
                ($evento->getReserva()?->getApellidoCliente() ?? '')
            ),
            'casita' => $evento->getPmsUnidad()?->getNombre() ?? '—',
            'localizador' => $evento->getReserva()?->getLocalizador(),
            'cambio' => $cambio,
            'es_ota' => $evento->isOta(),
            'canal' => $evento->getReserva()?->getChannel()?->getNombre(),
            'entrada_actual' => $evento->getInicio()?->format('Y-m-d H:i'),
            'salida_actual' => $evento->getFin()?->format('Y-m-d H:i'),
        ];

        if ($horaPedida !== '') {
            $resumen += [
                'hora_pedida' => $horaPedida,
                'horario_del_alojamiento' => $limite,
                'dentro_del_horario' => !$excede,
                'solo_registra_hora' => !$bloquea,
            ];
        }

        // 🗓️ Cómo está la noche que el bloqueo ocuparía. Se mira SIEMPRE, se vaya a bloquear
        // o no: si está ocupada cambia lo que se puede hacer, y si está libre es la que se
        // ofrece bloquear.
        $alerta = $this->alertaDeOcupacion($evento, $esSalida, $bloquea, $horaPedida, $limite);

        if ($alerta !== null) {
            $resumen['noche'] = $alerta;
        }

        // 🔑 Ocupada = NO se puede bloquear, y no hay nada que preguntar. La noche ya está
        // vendida a otra reserva; crear encima una extensión sería un evento superpuesto a una
        // estancia real. Se registra la hora, se avisa de que la limpieza tendrá menos margen
        // y se acabó — si hay que contratar refuerzo esa mañana, lo decide el operador.
        // ⚠️ NO se pisa `$bloquea`: son dos cosas distintas y confundirlas hace mentir al
        // mensaje final. `$bloquea` sigue significando «esta hora es horario extra»; lo que
        // cambia es que no se pueda bloquear. Al fusionarlas, registrar una entrada a las 08:00
        // sobre una noche vendida se anunciaba como «cabe dentro del horario (14:00)» — y las
        // 08:00 son entrada temprana de manual.
        $nocheOcupada = $bloquea && $alerta !== null && !$alerta['libre'];
        $vaABloquear = $bloquea && !$nocheOcupada;

        if ($nocheOcupada) {
            $resumen['no_se_puede_bloquear'] = sprintf(
                'La noche %s (%s) ya está vendida%s, así que NO se bloquea: sólo se registra la '
                . 'hora. Dile al operador que el equipo de limpieza tendrá menos margen esa '
                . 'mañana, y no le ofrezcas bloquear nada.',
                $esSalida ? 'posterior a la salida' : 'anterior a la entrada',
                $alerta['fecha'],
                $alerta['ocupa'] !== null ? ' a ' . $alerta['ocupa'] : ''
            );
        }

        if ($yaMarcado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'ya_estaba_marcado',
                'mensaje' => 'Esta estancia ya tenía ese horario marcado. No se ha cambiado nada.',
            ]);
        }

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                // Las consecuencias, enumeradas. El bloqueo que sale al canal y la línea de
                // cargo a cero las provocan servicios de más abajo al hacer flush, no esta
                // skill: si no se nombran aquí, el operador aprueba una cosa y ocurren varias.
                'que_va_a_pasar' => $this->consecuencias($evento, $esSalida, $vaABloquear, $horaPedida),
                // 🔑 Con la noche LIBRE, bloquearla es opcional y la decide el operador, así que
                // la pregunta lo dice: retirar de la venta una noche vendible no es el efecto
                // secundario de apuntar una hora. Con la noche ocupada no se pregunta nada —no
                // se puede bloquear— y basta con aprobar el registro de la hora.
                'pregunta_aprobacion' => $vaABloquear
                    ? sprintf(
                        '¿Bloqueo también la noche %s (%s) para tener margen de limpieza? Se '
                        . 'retira de la venta en todos los portales. Si prefieres dejarla '
                        . 'vendible, dímelo y sólo apunto la hora.',
                        $esSalida ? 'posterior a la salida' : 'anterior a la entrada',
                        $alerta['fecha'] ?? ''
                    )
                    : '¿Apruebas el cambio?',
                // ⚠️ Tiene que describir lo que DE VERDAD va a pasar. Decía siempre «quedará
                // marcada con salida tardía», y el modelo lo repetía tal cual: registrar una
                // salida a las 00:00 —diez horas ANTES del check-out— se anunciaba al operador
                // como «voy a registrar la salida tardía». Ninguna casilla se marca cuando la
                // hora cabe dentro del horario.
                'previsualizacion' => match (true) {
                    $vaABloquear => sprintf(
                        'La estancia de %s en %s (%s) quedará marcada con %s. Enséñale al '
                        . 'operador la lista de «que_va_a_pasar» entera antes de pedirle el sí.',
                        $resumen['huesped'] !== '' ? $resumen['huesped'] : 'el huésped',
                        $resumen['casita'],
                        $resumen['localizador'] ?? 'sin localizador',
                        $esSalida ? 'salida tardía' : 'entrada temprana'
                    ),
                    $nocheOcupada => sprintf(
                        'Se apuntará que %s en %s (%s) %s a las %s. Esa noche ya está vendida, '
                        . 'así que no se bloquea nada: sólo queda registrada la hora. Menciona '
                        . 'que la limpieza tendrá menos margen esa mañana.',
                        $resumen['huesped'] !== '' ? $resumen['huesped'] : 'el huésped',
                        $resumen['casita'],
                        $resumen['localizador'] ?? 'sin localizador',
                        $esSalida ? 'sale' : 'entra',
                        $horaPedida
                    ),
                    default => sprintf(
                        'Se apuntará que %s en %s (%s) %s a las %s. Cabe dentro del horario '
                        . 'normal (%s), así que NO es %s: no se marca ninguna casilla, no se '
                        . 'bloquea ninguna noche y no se abre ningún cargo. No se lo cuentes al '
                        . 'operador como un horario extra.',
                        $resumen['huesped'] !== '' ? $resumen['huesped'] : 'el huésped',
                        $resumen['casita'],
                        $resumen['localizador'] ?? 'sin localizador',
                        $esSalida ? 'sale' : 'entra',
                        $horaPedida,
                        $limite,
                        $esSalida ? 'una salida tardía' : 'una entrada temprana'
                    ),
                },
            ]);
        }

        // Al hacer flush pasan tres cosas que esta skill NO orquesta, sólo dispara al marcar:
        // PmsExtensionEstanciaService crea el evento de extensión, el push lo manda al canal
        // como bloqueo, y PmsCargosAutomaticosService abre la línea de cargo a 0.00.
        // Están enumeradas en consecuencias() para que la previsualización no mienta por
        // omisión.
        // La hora se registra SIEMPRE que se haya dicho, exceda o no: es el dato que el
        // operador necesita en la lista de salidas del día.
        if ($horaPedida !== '') {
            $this->registrarHora($evento, $horaPedida, $esSalida);
        }

        // El flag SÓLO si de verdad se sale del horario. Marcarlo cuando la hora cabe dentro
        // bloquearía una noche vendible por nada.
        if ($vaABloquear) {
            if ($esSalida) {
                $evento->setSalidaTardia(true);
            } else {
                $evento->setEntradaTemprana(true);
            }
        }

        $this->em->flush();

        return SkillResult::ok($resumen + array_filter([
            'aplicado' => true,
            'que_ha_pasado' => $this->consecuencias($evento, $esSalida, $vaABloquear, $horaPedida),
            // El mensaje tiene que decir lo que PASÓ, no lo que suele pasar: con una hora
            // dentro del horario no se marca nada ni se crea extensión, y anunciarlo sería
            // hacerle creer al operador que bloqueó una noche que sigue vendible.
            'mensaje' => match (true) {
                $vaABloquear => sprintf(
                    '%sMarcada la %s. Se ha creado la extensión que bloquea esa noche.',
                    $horaPedida !== '' ? sprintf('Registrada la hora %s. ', $horaPedida) : '',
                    $esSalida ? 'salida tardía' : 'entrada temprana'
                ),
                // Sí era horario extra, pero la noche estaba vendida: se apuntó la hora y nada
                // más. Decir aquí «cabe dentro del horario» —como hacía al fusionar las dos
                // variables— era falso: las 08:00 con check-in a las 14:00 son entrada temprana.
                $nocheOcupada => sprintf(
                    'Registrada la hora de %s a las %s. Esa noche ya está vendida, así que no se '
                    . 'ha bloqueado nada: sólo queda apuntado. El equipo de limpieza tendrá menos '
                    . 'margen esa mañana.',
                    $esSalida ? 'salida' : 'entrada',
                    $horaPedida
                ),
                default => sprintf(
                    'Registrada la hora de %s a las %s. Cabe dentro del horario del alojamiento '
                    . '(%s), así que NO se ha marcado horario extra ni bloqueado ninguna noche.',
                    $esSalida ? 'salida' : 'entrada',
                    $horaPedida,
                    $limite
                ),
            },
            // NO se sugiere registrar_cargo: PmsCargosAutomaticosService ya ha creado la línea
            // «Salida tardía (noche bloqueada) · Casita N» a 0.00. Añadir otra con
            // registrar_cargo dejaría dos conceptos iguales en la cuenta, y el operador no
            // sabría cuál es el bueno. Valorar la que existe se hace en el panel.
            'siguiente_paso_sugerido' => !$vaABloquear ? null : 'Ya se ha creado la línea del cargo con importe 0.00 '
                . 'para que la valore el operador. NO uses registrar_cargo para esto: '
                . 'duplicaría el concepto. El importe se le pone a esa línea desde el panel '
                . 'financiero de la reserva.',
        ], static fn ($v) => $v !== null));
    }

    /**
     * Todo lo que se dispara al marcar, con nombre y apellidos.
     *
     * La skill escribe **un booleano**; lo demás lo hacen listeners y servicios al hacer flush.
     * Enumerarlo aquí es lo que separa «¿apruebas marcar la salida tardía?» de «¿apruebas que
     * la casita deje de venderse esa noche en todos los portales?» — que es la misma acción y
     * la segunda es la que el operador necesita oír.
     *
     * @return list<string>
     */
    private function consecuencias(
        PmsEventoCalendario $evento,
        bool $esSalida,
        bool $bloquea,
        string $hora = ''
    ): array {
        $casita = $evento->getPmsUnidad()?->getNombre() ?? 'la casita';
        $noche = $esSalida ? 'la noche del día de salida' : 'la noche anterior a la llegada';
        $concepto = $esSalida ? 'Salida tardía (noche bloqueada)' : 'Entrada temprana (noche bloqueada)';

        if ($hora !== '') {
            $lista = [sprintf(
                'Se registra la hora de %s a las %s en la estancia.',
                $esSalida ? 'salida' : 'entrada',
                $hora
            )];
        } else {
            $lista = [sprintf(
                'Se marca la %s en la estancia. Las horas de entrada y salida NO cambian: '
                . 'esta acción no fija una hora concreta.',
                $esSalida ? 'salida tardía' : 'entrada temprana'
            )];
        }

        // Dentro del horario normal no hay noche que bloquear ni cargo que abrir: sólo queda
        // el dato. Enumerar las otras tres consecuencias aquí sería asustar por nada.
        if (!$bloquea) {
            $lista[] = 'NO se bloquea ninguna noche ni se abre ningún cargo: la hora cabe '
                . 'dentro del horario normal del alojamiento.';

            return $lista;
        }

        $lista[] = sprintf(
            'Se marca la %s en la estancia.',
            $esSalida ? 'salida tardía' : 'entrada temprana'
        );
        $lista[] = sprintf(
            'Se crea un evento de extensión que ocupa %s en %s, así que %s deja de estar '
            . 'disponible esa noche.',
            $noche,
            $casita,
            $casita
        );

        $canal = $evento->getReserva()?->getChannel()?->getNombre();
        $lista[] = sprintf(
            'La extensión viaja al canal como bloqueo, así que %s deja de venderse esa noche '
            . 'en TODOS los portales%s. Deshacerlo exige desmarcarlo y esperar otro push.',
            $casita,
            $canal !== null ? ' (esta reserva vino de ' . $canal . ')' : ''
        );

        $lista[] = sprintf(
            'Se abre en la cuenta una línea «%s · %s» con importe 0.00. NO cobra nada por sí '
            . 'sola: el importe se lo pone el operador desde el panel financiero.',
            $concepto,
            $casita
        );

        return $lista;
    }

    /**
     * El horario normal del alojamiento para esta estancia: `HH:MM`.
     *
     * Es el hito contra el que se decide si una hora es «horario extra» o simplemente un dato.
     * Sale del establecimiento —no está hardcodeado— porque es una decisión de negocio que
     * puede cambiar sin tocar código.
     */
    private function horarioDelEstablecimiento(PmsEventoCalendario $evento, bool $esSalida): string
    {
        $est = $evento->getPmsUnidad()?->getEstablecimiento();
        $hora = $esSalida ? $est?->getHoraCheckOut() : $est?->getHoraCheckIn();

        return $hora?->format('H:i')
            ?? ($esSalida ? self::CHECK_OUT_POR_DEFECTO : self::CHECK_IN_POR_DEFECTO);
    }

    /**
     * ¿La hora acordada se sale del horario normal?
     *
     * Asimétrico a propósito: salir DESPUÉS del check-out ocupa la casita más tiempo, y entrar
     * ANTES del check-in la ocupa antes. Salir a las 09:00 o entrar a las 18:00 no molestan a
     * nadie — son datos útiles para el equipo de limpieza, no horarios extra.
     *
     * Se comparan cadenas `HH:MM`, que en formato 24 h ordenan igual que el reloj.
     */
    private function excedeElHorario(string $hora, string $limite, bool $esSalida): bool
    {
        return $esSalida ? $hora > $limite : $hora < $limite;
    }

    /**
     * Escribe la hora conservando el DÍA.
     *
     * ⚠️ Nunca se toca la fecha, sólo la hora de pared (§12.5.5 del doc de sync). Mover el día
     * de una estancia es otra operación —prohibida en OTA— y se hace en el calendario del panel.
     * Cambiar sólo la hora es seguro incluso en reservas de OTA porque el push al canal manda
     * `Y-m-d` (`BookingsPushMappingStrategy`): el portal no ve las horas.
     */
    private function registrarHora(PmsEventoCalendario $evento, string $hora, bool $esSalida): void
    {
        $actual = $esSalida ? $evento->getFin() : $evento->getInicio();

        if ($actual === null) {
            return;
        }

        $nueva = DateTimeImmutable::createFromInterface($actual)
            ->setTime((int) substr($hora, 0, 2), (int) substr($hora, 3, 2));

        $esSalida ? $evento->setFin($nueva) : $evento->setInicio($nueva);
    }

    /**
     * Cómo está la noche anterior a la entrada —o la posterior a la salida— y qué implica.
     *
     * Se devuelve SIEMPRE, porque decide dos cosas distintas: si está LIBRE es la que se
     * ofrece bloquear, y si está VENDIDA es lo que impide bloquear nada y deja al equipo de
     * limpieza con menos margen esa mañana.
     *
     * ⚠️ **Informa, no veta.** Nunca dice «no puedes hacer esto»: el horario se registra igual
     * y lo que cambia es cuánto margen queda. Contratar refuerzo de limpieza esa mañana es una
     * decisión del operador, y para tomarla necesita el dato — que es justo lo que faltaba.
     *
     * @return array{fecha: string, libre: bool, ocupa: ?string, aviso: string}|null
     */
    private function alertaDeOcupacion(
        PmsEventoCalendario $evento,
        bool $esSalida,
        bool $bloquea,
        string $hora = '',
        string $limite = ''
    ): ?array {
        try {
            $margenes = $this->disponibilidad->margenesDe($evento);
        } catch (\Throwable) {
            // Sin margen calculable se sigue: la alerta es un extra, no un requisito.
            return null;
        }

        $margen = $esSalida ? $margenes['despues'] : $margenes['antes'];
        $casita = $evento->getPmsUnidad()?->getNombre() ?? 'la casita';
        $cual = $esSalida ? 'del día de salida' : 'anterior a la entrada';

        // ¿La hora acordada deja MÁS tiempo de limpieza que el horario normal? Salir antes del
        // check-out lo alarga; entrar después del check-in, también. Es lo contrario de lo que
        // asumía el aviso genérico.
        $daMasMargen = $hora !== '' && $limite !== '' && $hora !== $limite
            && ($esSalida ? $hora < $limite : $hora > $limite);

        // Nada de «⛔ CONFLICTO»: un solape no llega a ocurrir nunca, porque con la noche
        // ocupada ya no se bloquea. Lo único que le queda por saber al operador es cuánto
        // margen de limpieza tendrá esa mañana.
        $aviso = match (true) {
            $margen['libre'] => sprintf(
                '✅ La noche %s (%s) está LIBRE en %s: se puede bloquear si se quiere margen de '
                . 'limpieza, pero es opcional.',
                $cual,
                $margen['fecha'],
                $casita
            ),
            // Hacia QUÉ lado cambia el margen depende de si la hora adelanta o retrasa. Decir
            // siempre «es más corto» era mentir en la mitad de los casos: quien sale a las
            // 00:00 con check-out a las 10:00 deja DIEZ HORAS MÁS, no menos.
            $daMasMargen => sprintf(
                '✅ La noche %s (%s) está vendida%s, pero %s a las %s deja MÁS margen de limpieza '
                . 'que el horario normal (%s), no menos.',
                $cual,
                $margen['fecha'],
                $margen['ocupa'] !== null ? ' a ' . $margen['ocupa'] : '',
                $esSalida ? 'salir' : 'entrar',
                $hora,
                $limite
            ),
            default => sprintf(
                '⚠️ La noche %s (%s) ya está vendida%s, así que esa noche no se puede bloquear. '
                . 'El equipo de limpieza tendrá menos margen esa mañana: dilo, y que el operador '
                . 'decida si necesita refuerzo.',
                $cual,
                $margen['fecha'],
                $margen['ocupa'] !== null ? ' a ' . $margen['ocupa'] : ''
            ),
        };

        return [
            'fecha' => $margen['fecha'],
            'libre' => $margen['libre'],
            'ocupa' => $margen['ocupa'],
            'aviso' => $aviso,
        ];
    }
}
