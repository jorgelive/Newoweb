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
use App\Pms\Entity\PmsPeticion;
use App\Pms\Service\Agent\PmsFrentes;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use App\Agent\Skill\EntradaDeSkill;

/**
 * Da por puesta una petición: alguien la comprobó.
 *
 * ── Por qué se marca desde el chat y no sólo desde el panel ─────────────────
 * Porque quien las cumple **está en la casita, no delante del ordenador**. La señora que prepara
 * el departamento trabaja por WhatsApp con el agente: ahí es donde ve `listar_entradas_salidas`
 * y ahí es donde tiene que poder decir «listo». Obligarla a entrar al panel para tachar una
 * línea es garantizar que no se tache nunca y que la lista se llene de cosas ya hechas — que es
 * como muere una lista.
 *
 * ── Por estancia, no por id ─────────────────────────────────────────────────
 * No se le pide un uuid a nadie por WhatsApp. Se marca por `evento_id`, que es lo que la lista
 * de entradas ya devuelve en cada fila, y por defecto **todas las pendientes de esa estancia**:
 * el gesto real es «ya dejé puesto lo de la Casita 2», no «cerré la petición 3».
 *
 * Con `peticion` se puede marcar una sola, buscándola por texto, para cuando se deja algo a
 * medias — «la plancha sí, el secador no lo tenía».
 *
 * ── `Interna` ───────────────────────────────────────────────────────────────
 * No toca la reserva, ni el dinero, ni nada que el huésped vea como suyo: pone una fecha y un
 * nombre en un apunte interno. Lo peor que puede pasar es que alguien dé por hecho lo que no
 * hizo, y eso se ve en la lista del día siguiente con nombre y hora al lado.
 */
final readonly class MarcarPeticionSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function nombre(): string
    {
        return 'marcar_peticion';
    }

    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Da por puesta lo que el huésped había pedido para una estancia: la '
                . 'plancha ya está, el secador ya está. Se usa cuando quien prepara la casita '
                . 'dice que lo dejó puesto. '
                . 'Por defecto marca TODAS las pendientes de esa estancia, que es lo que '
                . 'significa «ya está lista la Casita 2». Si sólo dejó una cosa, pásale también '
                . '«peticion» con unas palabras de la que sí dejó. '
                . 'El «evento_id» sale de listar_entradas_salidas: NO se lo pidas a nadie.',
            parametros: [
                SkillParameter::texto('evento_id', 'La estancia, tal como la devuelve '
                    . 'listar_entradas_salidas en «evento_id».'),
                SkillParameter::texto('peticion', 'Unas palabras de UNA petición concreta, si '
                    . 'sólo se dejó puesta esa. Omítelo para dar por hechas todas.',
                    requerido: false),
            ],
        );
    }

    /** Quien prepara la casita y quien atiende: los dos que comprueban. */
    public function rolesRequeridos(): array
    {
        return [Roles::LIMPIEZA, Roles::CUSTOMER_SUPPORT];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Interna;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $e = new EntradaDeSkill($entrada);
        $eventoId = trim($e->texto('evento_id'));

        if ($eventoId === '') {
            return SkillResult::error(
                'Dime de qué estancia: el «evento_id» que devuelve listar_entradas_salidas.'
            );
        }

        if (!Uuid::isValid($eventoId)) {
            return SkillResult::error(
                'Ese «evento_id» no es un identificador válido. Sácalo de '
                . 'listar_entradas_salidas, no lo escribas de memoria.'
            );
        }

        // ⚠️ El uuid va como OBJETO, no como cadena. La columna es binaria, y comparar contra
        // ella con el texto del uuid devuelve CERO FILAS sin error ninguno: la consulta corre,
        // no encuentra nada, y el agente contesta «no hay peticiones pendientes» sobre una
        // estancia que sí las tiene. Es de los fallos que no se ven hasta que alguien nota que
        // la plancha nunca se dejó.
        /** @var list<PmsPeticion> $pendientes */
        $pendientes = $this->em->getRepository(PmsPeticion::class)
            ->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.evento) = :evento')
            ->andWhere('p.efectuadaAt IS NULL')
            ->setParameter('evento', Uuid::fromString($eventoId), 'uuid')
            ->getQuery()
            ->getResult();

        if ($pendientes === []) {
            return SkillResult::ok([
                'marcadas' => 0,
                'aviso' => 'Esa estancia no tiene peticiones pendientes. Puede que ya las marcara '
                    . 'alguien: no lo vuelvas a intentar ni lo tomes por un error.',
            ]);
        }

        $buscado = trim($e->texto('peticion'));

        if ($buscado !== '') {
            $pendientes = array_values(array_filter(
                $pendientes,
                static fn (PmsPeticion $p): bool => str_contains(
                    mb_strtolower($p->getTexto()),
                    mb_strtolower($buscado)
                )
            ));

            if ($pendientes === []) {
                return SkillResult::error(sprintf(
                    'Ninguna petición pendiente de esa estancia dice «%s». Llama sin «peticion» '
                    . 'para verlas todas en la lista de entradas.',
                    $buscado
                ));
            }
        }

        $quien = $this->quienEs($actor);
        $ahora = new DateTimeImmutable();
        $textos = [];

        foreach ($pendientes as $peticion) {
            $peticion->setEfectuadaAt($ahora)->setEfectuadaPor($quien);
            $textos[] = $peticion->getTexto();
        }

        $this->em->flush();

        return SkillResult::ok([
            'marcadas' => count($textos),
            'peticiones' => $textos,
            'aviso' => 'Queda anotado con su nombre y la hora. Dale las gracias y no le pidas '
                . 'que confirme otra vez.',
        ]);
    }

    /**
     * Quién marcó, para que la lista diga quién lo comprobó.
     *
     * ⚠️ **Se vuelve a buscar por id en vez de usar el objeto del actor.** El actor puede traer
     * un `User` que este EntityManager no conoce —lo construye quien abre la conversación, y en
     * la consola es directamente sintético—, y asignarlo revienta el flush con «a new entity was
     * found through the relationship». Buscarlo devuelve el gestionado o `null`.
     *
     * `null` es aceptable: es preferible una marca sin nombre a no poder marcar, y la fecha ya
     * deja rastro de cuándo se comprobó.
     */
    private function quienEs(ActorInterface $actor): ?User
    {
        $id = $actor->usuario()?->getId();

        return $id === null ? null : $this->em->find(User::class, $id);
    }
}
