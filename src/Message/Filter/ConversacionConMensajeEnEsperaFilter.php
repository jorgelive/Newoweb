<?php

declare(strict_types=1);

namespace App\Message\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Message\Entity\Message;
use Doctrine\ORM\QueryBuilder;

/**
 * `?enEspera=1`: sólo los hilos donde hay algo esperando a que el cliente abra la ventana de
 * WhatsApp ({@see Message::STATUS_EN_ESPERA}).
 *
 * Existe porque un mensaje en espera sólo se ve entrando en su chat: sin esto, para saber a quién
 * se le debe una respuesta que no ha salido había que acordarse de en qué hilos se dejó.
 *
 * Se resuelve en el servidor con un `EXISTS`, no filtrando la página en el navegador: la bandeja
 * está paginada, y un filtro de cliente sólo encontraría lo que ya estuviera cargado.
 */
final class ConversacionConMensajeEnEsperaFilter extends AbstractFilter
{
    private const string PARAMETRO = 'enEspera';

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($property !== self::PARAMETRO || !in_array($value, ['1', 'true', 1, true], true)) {
            return;
        }

        $hilo = $queryBuilder->getRootAliases()[0];
        $mensaje = $queryNameGenerator->generateJoinAlias('espera');
        $estado = $queryNameGenerator->generateParameterName('estado');

        $queryBuilder
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s %s WHERE %s.conversation = %s AND %s.status = :%s)',
                Message::class,
                $mensaje,
                $mensaje,
                $hilo,
                $mensaje,
                $estado
            ))
            ->setParameter($estado, Message::STATUS_EN_ESPERA);
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETRO => [
                'property' => null,
                'type' => 'bool',
                'required' => false,
                'description' => 'Sólo los hilos con mensajes esperando a que el cliente abra la ventana de WhatsApp.',
            ],
        ];
    }
}
