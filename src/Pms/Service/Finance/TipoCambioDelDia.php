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
    /**
     * El registro ya resuelto, por día. Se rellena a la primera y no vuelve a preguntarse.
     *
     * ── Por qué hace falta ──────────────────────────────────────────────────
     * `getTipodecambio()` consulta a SUNAT cuando no tiene la tasa del día, y esta clase no
     * cacheaba nada: **cada llamada era una consulta**. Con un solo consumidor por petición
     * no se notaba, pero al componer una lista —20 reservas × su importe y su equivalencia
     * con tarjeta— salían decenas de consultas para pedir el MISMO número, el de hoy. Se ve
     * en el log: «Consulta mensual SUNAT vacía. Intentando diaria», repetido.
     *
     * La memoria es **por instancia**, o sea por petición. No es una caché con caducidad: la
     * tasa del día no cambia dentro de una petición, y persistirla más allá sería inventarse
     * una política de invalidación para un dato que ya vive en `MaestroTipocambio`.
     *
     * ⚠️ Se guarda también el fallo (`false`): si SUNAT no responde, reintentarlo cincuenta
     * veces en la misma petición no lo va a arreglar y multiplica la latencia.
     *
     * @var array<string, MaestroTipocambio|false>
     */
    private array $memoria = [];

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

            $clave = $dia->format('Y-m-d');

            if (array_key_exists($clave, $this->memoria)) {
                return $this->memoria[$clave] ?: null;
            }

            return $this->memoria[$clave] = ($this->manager->getTipodecambio($dia) ?? false) ?: null;
        } catch (Throwable $e) {
            $this->logger->warning('No se pudo obtener el tipo de cambio del día: ' . $e->getMessage());
            return null;
        }
    }
}
