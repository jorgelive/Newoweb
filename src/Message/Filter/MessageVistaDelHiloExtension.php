<?php

declare(strict_types=1);

namespace App\Message\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Message\Entity\Message;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;

/**
 * Qué mensajes le tocan a cada pestaña del chat, decidido en el servidor.
 *
 * ── El fallo que cierra ─────────────────────────────────────────────────────
 * Las tres pestañas —historial, programados, cancelados— se alimentaban de UNA lista paginada de
 * 30 que el front repartía en cliente. Eso rompía dos cosas a la vez:
 *
 * 1. **El historial se vaciaba.** Un hilo con muchos programados llenaba la página 1 de fechas de
 *    2027 y no llegaba ni un mensaje enviado. Medido el 17/09/2026: 29 cancelados y 1 programado
 *    en la primera página, «Historial» en blanco.
 * 2. **Los contadores mentían.** Contaban lo descargado, no lo que hay: «Programados (1)» con
 *    seis en la base, porque los otros cinco estaban en la página 3.
 *
 * Con una consulta por pestaña, cada una pagina por la clave con la que se pinta y ninguna puede
 * invadir a las demás. Los contadores salen del total que devuelve API Platform.
 *
 * ── Por qué una extensión y no filtros en la URL ────────────────────────────
 * Se consideró exponer `DateFilter`/`SearchFilter` y que el front armara las tres consultas. Dos
 * motivos para no hacerlo: `SearchFilter` **no sabe negar** —no hay forma de pedir
 * `status != cancelled`— y, sobre todo, dejaría la condición en manos de quien llama. Una
 * petición que olvide un parámetro devuelve la lista mal y nadie se entera. Aquí la operación ya
 * determina lo que trae: pedirla «a pelo» no puede dar un resultado incorrecto.
 *
 * ── La frontera es la FECHA, y se comprobó ──────────────────────────────────
 * Lo ya ocurrido se separa de lo programado por `ocurrio_at`, sin mirar el estado. Antes de
 * hacerlo se verificó en producción que no hubiera enviados con fecha futura —serían datos
 * corruptos, no un caso a contemplar—: de 1.157 filas con fecha futura, 1.100 canceladas y 57
 * encoladas. Cero `sent`, `delivered` o `read`.
 */
final class MessageVistaDelHiloExtension implements QueryCollectionExtensionInterface
{
    /** Los nombres que llevan las operaciones del subrecurso en `Message`. */
    public const string HISTORIAL = 'hilo_historial';
    public const string PROGRAMADOS = 'hilo_programados';
    public const string CANCELADOS = 'hilo_cancelados';

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if (Message::class !== $resourceClass || $operation === null) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        // `ocurrio_at` puede ser nulo en una fila escrita fuera del ORM. Se trata como su fecha
        // de creación —que es lo que hace el getter— para que no desaparezca de ninguna pestaña:
        // un mensaje que no se ve es peor que uno mal colocado.
        $efectiva = sprintf('COALESCE(%s.ocurrioAt, %s.createdAt)', $alias, $alias);

        match ($operation->getName()) {
            self::HISTORIAL => $this->historial($queryBuilder, $alias, $efectiva),
            self::PROGRAMADOS => $this->programados($queryBuilder, $alias, $efectiva),
            self::CANCELADOS => $queryBuilder->andWhere(sprintf('%s.status = :cancelado', $alias))
                ->setParameter('cancelado', Message::STATUS_CANCELLED),
            default => null,
        };
    }

    /** Lo que ya pasó: ni futuro ni cancelado. Es la pestaña que se abre por defecto. */
    private function historial(QueryBuilder $qb, string $alias, string $efectiva): void
    {
        $qb->andWhere(sprintf('%s <= :ahora', $efectiva))
            ->andWhere(sprintf('%s.status != :cancelado', $alias))
            ->setParameter('ahora', new DateTimeImmutable())
            ->setParameter('cancelado', Message::STATUS_CANCELLED);
    }

    /** Lo que está por salir. Cancelado no es programado aunque tenga fecha futura. */
    private function programados(QueryBuilder $qb, string $alias, string $efectiva): void
    {
        $qb->andWhere(sprintf('%s > :ahora', $efectiva))
            ->andWhere(sprintf('%s.status != :cancelado', $alias))
            ->setParameter('ahora', new DateTimeImmutable())
            ->setParameter('cancelado', Message::STATUS_CANCELLED);
    }
}
