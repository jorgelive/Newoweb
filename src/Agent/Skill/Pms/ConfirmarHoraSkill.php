<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\EntradaDeSkill;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoConRespaldo;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Service\Reserva\HoraDeLaEstancia;
use App\Pms\Service\Reserva\PeticionDeHora;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * El huésped dice a qué hora llega o a qué hora se va: se apunta, y el equipo se entera.
 *
 * ── El hueco que cierra ─────────────────────────────────────────────────────
 * «Llegamos a las 16:00», «salimos a las 9». El agente contestaba y la hora no iba a ninguna
 * parte: sólo el equipo podía apuntarla (`aplicar_cambio_horario`). Y no es un dato menor: de esa
 * hora cuelgan la guía de llegada, el aviso de check-out, la despedida, la lista de limpieza y lo
 * que el propio agente sabe de en qué momento está el huésped.
 *
 * ── Qué hace, según la hora ─────────────────────────────────────────────────
 * - **Dentro del horario** (llega a la hora del check-in o después; se va a la del check-out o
 *   antes) **o dentro de un horario extra ya pactado**: se apunta y queda CONFIRMADA
 *   (`HoraDeLaEstancia::registrar()`, la misma que usa el equipo).
 * - **Llega de madrugada, pasada la medianoche de su día de entrada**: es una llegada TARDÍA, no un
 *   horario extra — la casita es suya desde el check-in y la entrada es autónoma. La hora no cabe
 *   en el día de entrada, así que no se escribe: queda como petición, la llegada como confirmada,
 *   y el equipo avisado.
 * - **Fuera del horario y sin nada pactado** (entrada temprana, salida tardía): NO se toca la
 *   estancia. Bloquear la noche de al lado lo decide el equipo
 *   (`aplicar_cambio_horario`, que le pregunta). Se deja una petición pegada a la estancia y el
 *   equipo recibe el aviso con la noche de al lado libre u ocupada.
 *
 * ── El equipo se entera SIEMPRE ─────────────────────────────────────────────
 * También de una confirmación dentro del horario, y de una que coincide con la hora por defecto:
 * una salida a las 10:00 es la de todos, y sólo el aviso dice que esta vez la ha confirmado el
 * huésped (Jorge, 01/10/2026). Por WhatsApp, con push del panel si no llega.
 *
 * ── `Interna` ───────────────────────────────────────────────────────────────
 * Como `anotar_peticion`: el chat del huésped abre las skills en sólo lectura, y es él quien dice
 * la hora. Escribe sólo en SU estancia y sólo la hora: ni el día, ni la casilla, ni la cuenta.
 */
final readonly class ConfirmarHoraSkill implements SkillInterface, SkillDominioInterface
{
    /**
     * Para la confirmación: «🕐 {{huesped}} confirma…». Pendiente de aprobar el texto con Jorge;
     * mientras no exista, fuera de la ventana de 24 h sale por push (`AvisoConRespaldo`).
     */
    public const string PLANTILLA_CONFIRMADA = 'aviso_hora_confirmada_interno';

    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaEstanciaResolver $estancias,
        private HoraDeLaEstancia $horas,
        private PmsDisponibilidadService $disponibilidad,
        private AvisoConRespaldo $avisos,
        private PeticionDeHora $peticiones,
    ) {}

    public function nombre(): string
    {
        return 'confirmar_hora';
    }

    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Apunta a qué hora LLEGA o a qué hora SE VA el huésped, cuando él te lo dice '
                . '(«llegamos a las 16:00», «salimos a las 9», «sí, a las 10 nos vamos»). ÚSALA SIEMPRE '
                . 'que el huésped confirme o cambie su hora, también si coincide con la de siempre: '
                . 'el equipo necesita saber que la confirmó. Si cabe en el horario la apunto y aviso '
                . 'al equipo; si es antes del check-in o después del check-out, NO la apunto: dejo '
                . 'la petición y aviso al equipo, que decide. En ese caso NO le prometas nada: dile '
                . 'el horario, que lo consultas con el equipo y lo que diga la política. '
                . 'CÓMO LEER LA HORA: un número suelto es la hora de 24 h tal cual —«a las 6» son '
                . 'las 06:00—; sólo es de la tarde si lo dice («6 de la tarde», «6 pm»). Ante la duda '
                . 'NO adivines: pregúntale si es de la mañana o de la tarde. Si llega de madrugada '
                . 'DESPUÉS de la medianoche de su día de entrada (el vuelo aterriza a las 2 del día '
                . 'siguiente), pásame despues_de_medianoche=true: es una llegada tardía, no un '
                . 'horario extra. Si llega de madrugada el MISMO día de entrada, antes del check-in, '
                . 'eso sí es una entrada temprana: sin despues_de_medianoche.',
            parametros: [
                SkillParameter::texto('extremo', '"llegada" o "salida".'),
                SkillParameter::texto('hora', 'HH:MM de 24 horas: "16:00", "09:30".'),
                SkillParameter::booleano('despues_de_medianoche', 'true si llega pasada la medianoche '
                    . 'del día de entrada (de madrugada del día siguiente).'),
                SkillParameter::texto('casita', 'Sólo si su reserva tiene VARIAS casitas y ya te dijo '
                    . 'de cuál habla.', requerido: false),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::MENSAJES_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Interna;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $e = new EntradaDeSkill($entrada);
        $extremo = strtolower(trim($e->texto('extremo')));
        $hora = HoraDeLaEstancia::normalizar($e->texto('hora'));
        $madrugada = $e->booleano('despues_de_medianoche');

        if (!in_array($extremo, ['llegada', 'salida'], true)) {
            return SkillResult::error('«extremo» tiene que ser "llegada" o "salida".');
        }

        if ($hora === null) {
            return SkillResult::error('La hora va en HH:MM de 24 horas, por ejemplo "16:00".');
        }

        $reservaId = $actor->contextoId();
        if ($actor->contextoTipo() !== 'pms_reserva' || $reservaId === null) {
            return SkillResult::error('Esta conversación no cuelga de ninguna reserva: no hay estancia donde apuntarlo. '
                . 'Avisa al equipo con escalar_al_equipo.');
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if (!$reserva instanceof PmsReserva) {
            return SkillResult::error('No encuentro esa reserva.');
        }

        $eleccion = $this->estancias->resolver($reserva->getEventosActivosGuia(), trim($e->texto('casita')));
        $evento = $eleccion['evento'];

        if ($evento === null) {
            $nombres = array_values(array_filter(array_map(
                static fn ($c): ?string => $c->getPmsUnidad()?->getNombre(),
                $eleccion['candidatas']
            )));

            return SkillResult::ok([
                'apuntada' => false,
                'casitas' => $nombres,
                'pregunta' => $nombres === []
                    ? 'Esta reserva no tiene ninguna estancia activa.'
                    : 'Esta reserva tiene varias casitas. Pregúntale de cuál habla y vuelve a llamarme con «casita».',
            ]);
        }

        $esSalida = $extremo === 'salida';
        $borde = $esSalida ? $evento->getFin() : $evento->getInicio();
        $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');

        if ($borde === null || $borde->format('Y-m-d') < $hoy) {
            return SkillResult::error(sprintf('Su %s ya pasó: no hay hora que apuntar.', $esSalida ? 'salida' : 'llegada'));
        }

        $limite = $this->horas->limite($evento, $esSalida);
        $fuera = $this->horas->excede($evento, $hora, $esSalida);
        $pactado = $esSalida ? $evento->isSalidaTardia() : $evento->isEntradaTemprana();

        if ($madrugada && !$esSalida) {
            return $this->llegadaDeMadrugada($evento, $actor, $hora);
        }

        if (!$fuera || $pactado) {
            return $this->apuntar($evento, $actor, $hora, $esSalida, $limite, $pactado);
        }

        return $this->pedirAlEquipo($evento, $actor, $hora, $esSalida, $limite);
    }

    /** Dentro del horario, o dentro del horario extra ya pactado: se apunta y se avisa. */
    private function apuntar(
        PmsEventoCalendario $evento,
        ActorInterface $actor,
        string $hora,
        bool $esSalida,
        string $limite,
        bool $pactado,
    ): SkillResult {
        $antes = ($esSalida ? $evento->getFin() : $evento->getInicio())?->format('H:i');

        $this->horas->registrar($evento, $hora, $esSalida);
        $this->em->flush();

        $nota = match (true) {
            // Tenía horario extra y ahora cabe en el normal: la noche bloqueada quizá ya sobra.
            $pactado && !$this->horas->excede($evento, $hora, $esSalida) => sprintf(
                ' Tenía %s pactada y esta hora cabe en el horario normal: la noche %s sigue bloqueada; desmárcala si ya no hace falta.',
                $esSalida ? 'salida tardía' : 'entrada temprana',
                $esSalida ? 'de su salida' : 'anterior'
            ),
            $pactado => sprintf(' Dentro de la %s ya pactada.', $esSalida ? 'salida tardía' : 'entrada temprana'),
            default => '',
        };

        $texto = sprintf(
            "🕐 %s confirma que %s el %s a las %s%s.%s",
            $this->quien($evento),
            $esSalida ? 'sale' : 'llega',
            ($esSalida ? $evento->getFin() : $evento->getInicio())?->format('d/m'),
            $hora,
            $antes !== null && $antes !== $hora ? sprintf(' (antes: %s)', $antes) : '',
            $nota,
        );

        $this->avisar($evento, $actor, $texto, ConfirmarHoraSkill::PLANTILLA_CONFIRMADA, [
            'huesped' => $this->quien($evento),
            'accion' => $esSalida ? 'sale' : 'llega',
            'fecha' => ($esSalida ? $evento->getFin() : $evento->getInicio())?->format('d/m') ?? '',
            'hora' => $hora,
        ]);

        return SkillResult::ok([
            'apuntada' => true,
            'hora' => $hora,
            'horario_del_alojamiento' => $limite,
            'aviso' => 'Queda apuntada y el equipo está avisado. Díselo con naturalidad: que queda '
                . 'anotado. No le cuentes que se avisó a nadie ni le hables de horarios internos.',
        ]);
    }

    /** Fuera del horario y sin nada pactado: no se toca la estancia; petición + aviso para decidir. */
    private function pedirAlEquipo(
        PmsEventoCalendario $evento,
        ActorInterface $actor,
        string $hora,
        bool $esSalida,
        string $limite,
    ): SkillResult {
        $fecha = ($esSalida ? $evento->getFin() : $evento->getInicio())?->format('d/m') ?? '';
        $pedido = sprintf('Pide %s el %s a las %s (%s %s)', $esSalida ? 'salir' : 'entrar', $fecha, $hora,
            $esSalida ? 'check-out' : 'check-in', $limite);

        $this->peticiones->dejar($evento, $pedido, $esSalida, $actor->conversacionId());
        $this->em->flush();

        $noche = $this->nocheDeAlLado($evento, $esSalida);
        $texto = sprintf(
            "🕐 %s: %s.%s\n\nNo se ha apuntado: decide el equipo (aplicar_cambio_horario pregunta si se bloquea la noche).",
            $this->quien($evento),
            $pedido,
            $noche !== null ? ' ' . $noche : ''
        );

        // Fuera de ventana va con la plantilla del escalado, que ya está aprobada y dice lo que
        // pasa: el huésped espera una respuesta del equipo.
        $this->avisar($evento, $actor, $texto, EscalarAlEquipoSkill::PLANTILLA_AVISO, [
            'huesped' => $this->quien($evento),
            'motivo' => $pedido . ($noche !== null ? '. ' . $noche : ''),
            'chat_path' => 'chat?id=' . ($actor->conversacionId() ?? ''),
        ]);

        return SkillResult::ok([
            'apuntada' => false,
            'motivo' => $esSalida ? 'salida_tardia' : 'entrada_temprana',
            'horario_del_alojamiento' => $limite,
            'aviso' => sprintf(
                'NO se ha apuntado y NO le confirmes nada. Dile que el %s es a las %s, que lo consultas '
                . 'con el equipo y le dices algo. Si te pregunta qué puede hacer mientras, mira el '
                . 'conocimiento (guardar equipaje, entrada autónoma). La petición queda pegada a su '
                . 'estancia y el equipo YA está avisado: no hace falta escalar_al_equipo.',
                $esSalida ? 'check-out' : 'check-in',
                $limite
            ),
        ]);
    }

    /**
     * Llega pasada la medianoche de su día de entrada: tarde, no temprano.
     *
     * La hora no cabe en `inicio` sin moverle el día —y el día no se toca—, así que se deja como
     * petición, la llegada queda CONFIRMADA y el equipo avisado: es la noche de la llave y la luz
     * del pasadizo, no una decisión.
     */
    private function llegadaDeMadrugada(PmsEventoCalendario $evento, ActorInterface $actor, string $hora): SkillResult
    {
        $inicio = $evento->getInicio();
        $dia = $inicio !== null ? DateTimeImmutable::createFromInterface($inicio)->modify('+1 day')->format('d/m') : '';
        $pedido = sprintf('Llega de madrugada: %s del %s (su entrada es el %s)', $hora, $dia, $inicio?->format('d/m') ?? '');

        $this->peticiones->dejar($evento, $pedido, false, $actor->conversacionId());
        $evento->setLlegadaConfirmadaAt(new DateTimeImmutable());
        $this->em->flush();

        $this->avisar($evento, $actor, sprintf('🕐 %s: %s.', $this->quien($evento), $pedido), ConfirmarHoraSkill::PLANTILLA_CONFIRMADA, [
            'huesped' => $this->quien($evento),
            'accion' => 'llega de madrugada',
            'fecha' => $dia,
            'hora' => $hora,
        ]);

        return SkillResult::ok([
            'apuntada' => true,
            'llegada_de_madrugada' => true,
            'aviso' => 'Queda anotado. Puede llegar a esa hora: la casita es suya desde el check-in '
                . 'y la entrada es autónoma (mira el conocimiento «Recepción y entrada autónoma»). '
                . 'No le hables de horarios extra ni de costes.',
        ]);
    }

    /** «La noche anterior está libre» / «…es de X»: lo que necesita el equipo para decidir. */
    private function nocheDeAlLado(PmsEventoCalendario $evento, bool $esSalida): ?string
    {
        try {
            $margen = $this->disponibilidad->margenesDe($evento)[$esSalida ? 'despues' : 'antes'];
        } catch (Throwable) {
            return null;
        }

        return sprintf(
            'La noche %s (%s) %s.',
            $esSalida ? 'de su salida' : 'anterior',
            (new DateTimeImmutable($margen['fecha']))->format('d/m'),
            $margen['libre'] ? 'está libre' : 'ya es de ' . $margen['ocupa']
        );
    }

    /**
     * @param array<string, string> $variables De la plantilla de respaldo; una línea cada una.
     */
    private function avisar(PmsEventoCalendario $evento, ActorInterface $actor, string $texto, string $plantilla, array $variables): void
    {
        try {
            $this->avisos->notificar(new AvisoAlEquipo(
                rol: Roles::CUSTOMER_SUPPORT,
                texto: $texto,
                plantillaCodigo: $plantilla,
                variables: array_map(static fn (string $v): string => trim((string) preg_replace('/\s+/', ' ', $v)), $variables),
                metadata: [
                    'aviso_hora_huesped' => true,
                    'evento' => (string) $evento->getId(),
                    'conversacion' => $actor->conversacionId(),
                ],
            ), titulo: '🕐 Hora de un huésped', url: '/chat' . ($actor->conversacionId() !== null ? '?id=' . $actor->conversacionId() : ''));
        } catch (Throwable) {
            // La hora ya está apuntada (o la petición, dejada): que no llegue el aviso no puede
            // deshacer eso ni romperle la respuesta al huésped.
        }
    }

    /** «Anna Müller (Casita 1, UV5XPW)». */
    private function quien(PmsEventoCalendario $evento): string
    {
        $reserva = $evento->getReserva();
        $nombre = trim((string) $reserva?->getNombreCliente() . ' ' . (string) $reserva?->getApellidoCliente());

        return sprintf(
            '%s (%s%s)',
            $nombre !== '' ? $nombre : ($evento->getTituloCache() ?? 'Un huésped'),
            $evento->getPmsUnidad()?->getNombre() ?? 'sin casita',
            $reserva?->getLocalizador() !== null ? ', ' . $reserva->getLocalizador() : ''
        );
    }
}
