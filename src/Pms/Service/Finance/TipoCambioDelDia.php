<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Service\TipocambioManager;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Devuelve el tipo de cambio USD→PEN del día para snapshotear en los registros financieros.
 *
 * Decisión de negocio: se guarda la **venta** SUNAT (la punta usada para convertir soles
 * recibidos a USD). Reutiliza App\Service\TipocambioManager (cachea en BD y cae a SUNAT /
 * último disponible). Nunca lanza: si no hay tasa, devuelve null y el registro queda sin TC,
 * para no romper la persistencia financiera.
 */
final class TipoCambioDelDia
{
    public function __construct(
        private readonly TipocambioManager $manager,
        private readonly LoggerInterface   $logger
    ) {}

    /**
     * @param DateTimeInterface|null $fecha Día del snapshot (por defecto, hoy en zona operativa Lima).
     * @return string|null Tipo de cambio venta como decimal string, o null si no se pudo obtener.
     */
    public function venta(?DateTimeInterface $fecha = null): ?string
    {
        try {
            $dia = $fecha !== null
                ? new DateTime($fecha->format('Y-m-d'), new DateTimeZone('America/Lima'))
                : new DateTime('now', new DateTimeZone('America/Lima'));

            $tc = $this->manager->getTipodecambio($dia);

            return $tc?->getVenta();
        } catch (Throwable $e) {
            $this->logger->warning('No se pudo obtener el tipo de cambio del día: ' . $e->getMessage());
            return null;
        }
    }
}
