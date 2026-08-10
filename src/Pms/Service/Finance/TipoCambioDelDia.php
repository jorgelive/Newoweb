<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Entity\Maestro\MaestroTipocambio;
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
        return $this->referencia($fecha)?->getVenta();
    }

    /**
     * El registro entero, para quien además de la tasa necesita saber **de qué día es**.
     *
     * Lo pide {@see \App\Agent\Skill\Pms\ConsultarTipoCambioSkill}: si SUNAT todavía no ha
     * publicado la de hoy, `TipocambioManager` cae a la última disponible, y decirle a un
     * huésped «el tipo de cambio de hoy» citando el del viernes es contarle algo que no le
     * consta. Con la fecha delante, el modelo puede decir de cuándo es.
     *
     * `venta()` se apoya en éste para que la zona horaria y el fallback vivan en un solo sitio.
     */
    public function referencia(?DateTimeInterface $fecha = null): ?MaestroTipocambio
    {
        try {
            $dia = $fecha !== null
                ? new DateTime($fecha->format('Y-m-d'), new DateTimeZone('America/Lima'))
                : new DateTime('now', new DateTimeZone('America/Lima'));

            return $this->manager->getTipodecambio($dia);
        } catch (Throwable $e) {
            $this->logger->warning('No se pudo obtener el tipo de cambio del día: ' . $e->getMessage());
            return null;
        }
    }
}
