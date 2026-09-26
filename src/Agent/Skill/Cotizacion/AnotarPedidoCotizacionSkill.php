<?php

declare(strict_types=1);

namespace App\Agent\Skill\Cotizacion;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Cotizacion\Entity\CotizacionPedido;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use App\Agent\Skill\EntradaDeSkill;

/**
 * Apunta un tour, una excursión o una cotización que el cliente pidió, para que el área de
 * Cotizaciones lo trabaje.
 *
 * ── El fallo que cierra ─────────────────────────────────────────────────────
 * La hermana de {@see \App\Agent\Skill\Pms\AnotarPeticionSkill}, para Travel. Un huésped de hotel
 * pidió tres tours con fechas y personas; el agente no podía resolverlo solo —hay que armar
 * itinerario, precio y disponibilidad— y tuvo que intervenir una persona a mano. El pedido quedó
 * escrito en el chat y en ninguna otra parte: nada lo marcaba como pendiente de trabajar, y nada
 * avisaba cuando alguien por fin lo trabajaba.
 *
 * ── Por qué no bastaba `escalar_al_equipo` ──────────────────────────────────
 * Escalar es «esto no lo sé resolver, míralo AHORA»: manda un WhatsApp y marca el hilo. Un tour
 * pedido con una semana de antelación no es urgente, es trabajo que espera su turno; el WhatsApp
 * se lee una vez y no queda como tarea para cuando Cotizaciones tenga un hueco.
 *
 * ── Nace en la CONVERSACIÓN, se cierra con el EXPEDIENTE ────────────────────
 * Al pedirse, casi nunca hay ya un expediente de viaje —es lo que hay que crear—. Por eso el
 * pedido no pide localizador ni nada de Travel: se cuelga de la conversación y queda pendiente
 * hasta que alguien abre el expediente, momento en el que se cierra solo
 * ({@see \App\Cotizacion\Service\Message\CotizacionSincronizadorDeEnlace}). No hay «marcar_pedido»
 * equivalente a `marcar_peticion`: aquí lo que lo cierra es el trabajo real, no un aviso de que se
 * hizo.
 *
 * ── `Interna` ────────────────────────────────────────────────────────────────
 * No promete precio, no reserva nada y no decide: sólo dice que alguien lo pidió. Es él mismo
 * quien puede llamarla —está pidiendo él—, y el daño de una de más es que alguien lea una línea
 * que no hacía falta.
 */
final readonly class AnotarPedidoCotizacionSkill implements SkillInterface, SkillDominioInterface
{
    /** Lo que cabe en una línea de la lista de pendientes de Cotizaciones. */
    private const int MAX_TEXTO = 180;

    public function __construct(private EntityManagerInterface $em) {}

    public function nombre(): string
    {
        return 'anotar_pedido_cotizacion';
    }

    public function dominios(): array
    {
        return ['turistico'];
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Anota un tour, una excursión o cualquier cosa de viaje que el cliente '
                . 'pidió y que hay que COTIZAR: precio, itinerario o disponibilidad que no puedes '
                . 'resolver tú. Queda pendiente para que el área de Cotizaciones lo trabaje y se '
                . 'cierra solo en cuanto alguien le abre su expediente. '
                . '⚠️ ÚSALA SIEMPRE QUE DIGAS QUE LO VAS A COTIZAR O QUE ALGUIEN LE ESCRIBIRÁ CON '
                . 'LA COTIZACIÓN: si no la llamas, ese pedido no queda en ninguna parte y nadie '
                . 'sabe que hay que trabajarlo. '
                . 'NO es para lo urgente —eso es «escalar_al_equipo»—, y no sustituye a '
                . 'responderle: si algo de lo que pide ya tiene precio y disponibilidad a mano '
                . '(consultar_tarifas, consultar_disponibilidad, buscar_tarifas), cuéntaselo, y '
                . 'ADEMÁS anótalo si hace falta armar el resto.',
            parametros: [
                SkillParameter::texto('pedido', 'Qué pide, en una línea y con sus palabras: '
                    . '«Valle Sagrado lunes 5 para 4, Montaña 7 Colores miércoles 7 para 4, '
                    . 'Quelccaya jueves 8 para 4, niño de 9 años». Lo va a leer quien cotiza, no '
                    . 'el cliente.'),
            ],
        );
    }

    /** El propio cliente incluido, que es quien pide. Ver el docblock: es `Interna` por esto. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::PROSPECTO, Roles::MENSAJES_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Interna;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $e = new EntradaDeSkill($entrada);
        $texto = trim($e->texto('pedido'));

        if ($texto === '') {
            return SkillResult::error(
                'Dime en «pedido» qué pide: es lo que va a leer quien lo cotice.'
            );
        }

        $conversacionId = $actor->conversacionId();

        if ($conversacionId === null) {
            return SkillResult::error(
                'Esta conversación todavía no está guardada, así que no hay dónde anotar el '
                . 'pedido. Si es urgente, usa escalar_al_equipo.'
            );
        }

        $pedido = new CotizacionPedido($conversacionId, mb_substr($texto, 0, self::MAX_TEXTO));

        $this->em->persist($pedido);
        $this->em->flush();

        return SkillResult::ok([
            'anotado' => true,
            'pedido' => $pedido->getTexto(),
            // Qué puede prometer el modelo y qué no: queda pendiente de que alguien lo cotice,
            // no «ya tiene el precio». La diferencia la nota el cliente que espera un número.
            'aviso' => 'Queda anotado para que el área de Cotizaciones lo trabaje. Dile que en '
                . 'breve le llega su cotización, sin prometerle un plazo exacto ni un precio que '
                . 'no tienes.',
        ]);
    }
}
