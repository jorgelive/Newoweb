<?php
// src/Cotizacion/ApiPlatform/State/CloneCotizacionProcessor.php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<Cotizacion, Cotizacion|null> */
final class CloneCotizacionProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /**

     * @param array<string, mixed> $uriVariables

     * @param array<string, mixed> $context

     */

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Cotizacion
    {
        // $data es la Cotizacion leída por el provider (gracias a read: true)
        $clon = $data->duplicar();

        // El padre puede ser un expediente o un catálogo de tours
        $padre = $data->getFile() ?? $data->getCatalogo();
        if ($padre) {
            $ultimaVersion = 0;
            foreach ($padre->getCotizaciones() as $c) {
                if ($c->getPropuesta() > $ultimaVersion) {
                    $ultimaVersion = $c->getPropuesta();
                }
            }
            $clon->setPropuesta($ultimaVersion + 1);
        } else {
            $clon->setPropuesta($data->getPropuesta() + 1);
        }

        $clon->setEstado(CotizacionEstadoEnum::PENDIENTE);

        $this->entityManager->persist($clon);
        $this->entityManager->flush();

        return $clon;
    }
}