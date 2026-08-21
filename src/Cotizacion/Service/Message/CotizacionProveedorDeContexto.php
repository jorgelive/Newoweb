<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\ProveedorDeContextoInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El contexto de un expediente de viaje, a petición.
 *
 * El mismo que arma `CotizacionFileConversacionListener` al guardar. Aquí sirve para el caso que
 * el listener no cubre: el expediente que se guardó **sin** teléfono ni correo —así que no nació
 * hilo— y al que después se le añadieron los datos por otro camino.
 */
final readonly class CotizacionProveedorDeContexto implements ProveedorDeContextoInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function supports(string $contextType): bool
    {
        return $contextType === CotizacionConversacionEnlace::CONTEXT_TYPE;
    }

    public function para(string $contextId): ?MessageContextInterface
    {
        $file = $this->em->getRepository(CotizacionFile::class)->find($contextId);

        return $file !== null ? new CotizacionFileMessageContext($file) : null;
    }
}
