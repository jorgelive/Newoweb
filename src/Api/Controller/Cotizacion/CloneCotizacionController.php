<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class CloneCotizacionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Ejecuta el proceso de clonación profunda de una Cotización.
     *
     * ¿Por qué existe?: Actúa como endpoint personalizado de API Platform para aislar la
     * pesada lógica de duplicación (Deep Clone) en el servidor.
     *
     * Efectos secundarios:
     * - Calcula automáticamente la siguiente versión correlativa basada en el File.
     * - Retorna el estado a 'Pendiente'.
     * - Persiste todo el grafo en una sola transacción segura.
     *
     * @param Cotizacion $data La cotización original resuelta por API Platform.
     * @return Cotizacion El clon recién persistido.
     */
    public function __invoke(Cotizacion $data): Cotizacion
    {
        // 1. Clonación mágica que dispara recursivamente todos los __clone() en las entidades hijas
        $clon = clone $data;

        // 2. Lógica de negocio: Incrementar versión buscando la máxima del File actual
        $filePadre = $data->getFile();
        if ($filePadre) {
            $ultimaVersion = 0;
            foreach ($filePadre->getCotizaciones() as $c) {
                if ($c->getVersion() > $ultimaVersion) {
                    $ultimaVersion = $c->getVersion();
                }
            }
            $clon->setVersion($ultimaVersion + 1);
        } else {
            // Fallback (solo por si es huérfana)
            $clon->setVersion($data->getVersion() + 1);
        }

        // 3. Forzar el estado a pendiente
        $clon->setEstado(CotizacionEstadoEnum::PENDIENTE);

        // 4. Persistir y confirmar transacción
        $this->entityManager->persist($clon);
        $this->entityManager->flush();

        return $clon;
    }
}