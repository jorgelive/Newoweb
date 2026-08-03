<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Entity\Maestro\MaestroMoneda;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resuelve un código de moneda entrante contra el maestro `MaestroMoneda`.
 * Regla de negocio: si no llega moneda (o el código no existe), el default es dólar (USD).
 */
final class MonedaResolver
{
    /** @var array<string, MaestroMoneda> Cache de instancia para evitar N+1 en lotes. */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function resolve(?string $codigo): MaestroMoneda
    {
        $id = strtoupper(trim((string) $codigo));
        if ($id === '') {
            $id = MaestroMoneda::DB_ID_USD;
        }

        // ⚠️ La caché se descarta si la entidad quedó DESLIGADA del EntityManager. Pasa en
        // cuanto alguien llama a `em->clear()` (procesos largos: workers de messenger, cron,
        // persisters por lotes): la instancia cacheada sigue viva en PHP pero el EM ya no la
        // conoce, y asignarla a un cargo revienta con "A new entity was found through the
        // relationship ... that was not configured to cascade persist".
        if (isset($this->cache[$id]) && $this->em->contains($this->cache[$id])) {
            return $this->cache[$id];
        }

        $moneda = $this->em->find(MaestroMoneda::class, $id)
            ?? $this->em->getReference(MaestroMoneda::class, MaestroMoneda::DB_ID_USD);

        return $this->cache[$id] = $moneda;
    }
}
