<?php

declare(strict_types=1);

// 🔥 CAMBIO DE NAMESPACE
namespace App\Pms\Service\Tarifa\Generator;

use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Tarifa\Dto\GeneradorTarifaMasivaDto;
use Doctrine\ORM\EntityManagerInterface;

final class GeneradorTarifaMasivaService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function procesar(GeneradorTarifaMasivaDto $dto): int
    {
        // ... (Lógica idéntica a la anterior) ...
        // 1. Buscar unidades activas con tarifa base
        // 2. Calcular precio con porcentaje
        // 3. Persistir rango

        // Simplemente copio la lógica central para referencia:
        $unidades = $this->em->getRepository(PmsUnidad::class)->findBy([
            'activo' => true,
            'tarifaBaseActiva' => true
        ]);

        $count = 0;
        foreach ($unidades as $unidad) {
            $moneda = $unidad->getTarifaBaseMoneda();
            if (!$moneda) continue;

            $base = (float) $unidad->getTarifaBasePrecio();
            $factor = 1 + ($dto->porcentaje / 100);

            // SIN decimales: un +7% sobre 265 no vale 283.55 — el precio de venta se
            // publica redondo en los canales y nadie tarifica al céntimo. La leyenda
            // del drawer usa el mismo redondeo (ver TarifaMasivaDrawer.vue).
            $precioCalculado = round($base * $factor);

            $rango = new PmsTarifaRango();
            $rango->setUnidad($unidad);
            $rango->setMoneda($moneda);
            $rango->setFechaInicio($dto->fechaInicio);
            $rango->setFechaFin($dto->fechaFin);
            $rango->setPrecio((string) $precioCalculado);
            $rango->setMinStay($dto->minStay);
            $rango->setPrioridad($dto->prioridad);
            $rango->setImportante($dto->importante);
            $rango->setActivo(true);

            $this->em->persist($rango);
            $count++;
        }

        $this->em->flush();
        return $count;
    }
}