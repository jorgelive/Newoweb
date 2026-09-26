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
use App\Pms\Entity\PmsPeticion;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Pms\Service\Agent\PmsFrentes;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use App\Agent\Skill\EntradaDeSkill;

/**
 * Apunta lo que el huésped pide para su estancia, donde quien prepara la casita lo verá.
 *
 * ── El fallo que cierra ─────────────────────────────────────────────────────
 * «¿Pueden dejarme una plancha?», «¿hay secador?», «¿me suben una estufa?». El agente contestaba
 * —a veces escalando, a veces con un «tomamos nota»— y **la nota no existía**. 37 peticiones en
 * el histórico dependiendo de que una persona se acordara el día de la llegada. El «tomamos
 * nota» era, literalmente, mentira: no había dónde.
 *
 * ── Por qué no bastaba `escalar_al_equipo` ──────────────────────────────────
 * Porque son dos cosas distintas. Escalar es «esto no lo sé resolver, míralo AHORA»: manda un
 * WhatsApp y marca el hilo. Una petición es «el jueves, al preparar la Casita 4, deja plancha»;
 * el WhatsApp se lee a las tres de la tarde y para el jueves nadie vuelve a él. Lo que hacía
 * falta no era otro aviso, sino que el apunte **espere pegado a la estancia**.
 *
 * ── `Interna`, para que el huésped pueda usarla ─────────────────────────────
 * El chat del huésped abre las skills en modo sólo-lectura, así que una de `Escritura` no
 * existiría para él — y es él quien pide la plancha. {@see NivelRiesgo::Interna} es justo este
 * caso: escribe hacia dentro, no toca su reserva ni su cuenta. El daño de una petición de más es
 * que alguien lea una línea que no hacía falta.
 *
 * ── Qué NO hace ─────────────────────────────────────────────────────────────
 * No promete, no cobra y no decide. Los calefactores son alquiler y las frazadas gratis; esto
 * sólo anota lo que pidió. Si el huésped necesita saber el precio o la disponibilidad, eso es
 * una respuesta —la guía o el conocimiento— y ocurre aparte.
 */
final readonly class AnotarPeticionSkill implements SkillInterface, SkillDominioInterface
{
    /** Lo que cabe en una línea de una lista que se mira preparando una casita. */
    private const int MAX_TEXTO = 180;

    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaEstanciaResolver $estancias,
    ) {}

    public function nombre(): string
    {
        return 'anotar_peticion';
    }

    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Anota algo que el huésped pide que le DEJEN PUESTO en su casita: una '
                . 'plancha, un secador, una estufa, frazadas, almohadas. La petición queda '
                . 'pegada a su estancia y la ve quien prepara el departamento el día de su '
                . 'llegada. '
                . '⚠️ ÚSALA SIEMPRE QUE DIGAS QUE SE TOMA NOTA: si no la llamas, no hay nota en '
                . 'ninguna parte y nadie se va a enterar. '
                . 'NO es para lo que hay que resolver ahora —una avería, un cobro que no cuadra, '
                . 'alguien en la puerta—: eso es «escalar_al_equipo». Ésta es para lo que se '
                . 'prepara con tiempo. '
                . 'Tampoco sustituye a responderle: mira primero en su guía si eso existe y '
                . 'cuánto cuesta, cuéntaselo, y ADEMÁS anótalo.',
            parametros: [
                SkillParameter::texto('peticion', 'Qué pide, en una línea y con sus palabras: '
                    . '«plancha y tabla», «secador de pelo», «dos almohadas bajas». Lo va a leer '
                    . 'quien prepara la casita, no el huésped.'),
                SkillParameter::texto('casita', 'Sólo si su reserva tiene VARIAS casitas y ya te '
                    . 'dijo de cuál habla. Si no, omítelo.', requerido: false),
            ],
        );
    }

    /**
     * El huésped incluido, que es quien pide. Ver el docblock: es `Interna` por esto mismo.
     */
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
        $texto = trim($e->texto('peticion'));

        if ($texto === '') {
            return SkillResult::error(
                'Dime en «peticion» qué pide: es lo que va a leer quien prepare la casita.'
            );
        }

        $reservaId = $actor->contextoId();

        if ($actor->contextoTipo() !== 'pms_reserva' || $reservaId === null) {
            return SkillResult::error(
                'Esta conversación no cuelga de ninguna reserva, así que no hay estancia a la '
                . 'que pegar la petición. Avisa al equipo con escalar_al_equipo.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if (!$reserva instanceof PmsReserva) {
            return SkillResult::error('No encuentro esa reserva.');
        }

        // Misma resolución que la guía: con una casita se toma esa; con varias y sin decir cuál,
        // se pregunta. Anotar «deja plancha» en la casita equivocada es peor que no anotarlo,
        // porque además alguien la deja puesta para quien no la pidió.
        $eleccion = $this->estancias->resolver(
            $reserva->getEventosActivosGuia(),
            trim($e->texto('casita'))
        );

        $evento = $eleccion['evento'];

        if ($evento === null) {
            $nombres = array_values(array_filter(array_map(
                static fn ($e): ?string => $e->getPmsUnidad()?->getNombre(),
                $eleccion['candidatas']
            )));

            return SkillResult::ok([
                'anotada' => false,
                'casitas' => $nombres,
                'pregunta' => $nombres === []
                    ? 'Esta reserva no tiene ninguna estancia activa donde anotarlo.'
                    : 'Esta reserva tiene varias casitas y lo que se deja puesto es de una en '
                        . 'concreto. Pregúntale en cuál está y vuelve a llamarme con «casita».',
            ]);
        }

        $peticion = (new PmsPeticion())
            ->setEvento($evento)
            ->setTexto(mb_substr($texto, 0, self::MAX_TEXTO))
            ->setConversacionId($actor->conversacionId());

        $peticion->initializeId();

        $this->em->persist($peticion);
        $this->em->flush();

        return SkillResult::ok([
            'anotada' => true,
            'casita' => $evento->getPmsUnidad()?->getNombre(),
            'peticion' => $peticion->getTexto(),
            // Se le dice al modelo qué puede prometer y qué no: queda anotado para la llegada,
            // no «se lo llevamos ahora». La diferencia la nota el huésped que espera en la sala.
            'aviso' => 'Queda anotado en su estancia y lo verá quien prepare la casita el día de '
                . 'su llegada. Díselo así: que queda apuntado para su llegada. NO le prometas '
                . 'una hora ni que se lo suben ahora mismo.',
        ]);
    }
}
