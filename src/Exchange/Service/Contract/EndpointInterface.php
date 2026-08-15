<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use Symfony\Component\Uid\Uuid;

interface EndpointInterface
{
    /**
     * El identificador del endpoint.
     *
     * Lo aporta `IdTrait` en la única implementación, pero el contrato no lo declaraba y los
     * proveedores de cola lo llaman para comprobar homogeneidad de lote — o sea que ya era parte
     * del contrato de hecho, sólo que sin escribir.
     */
    public function getId(): ?Uuid;

    public function getEndpoint(): ?string; // ej: '/inventory/calendar'
    public function getMetodo(): ?string;   // ej: 'POST'
    public function getAccion(): ?string;   // ej: 'SEND_MESSAGE'
}