<?php

namespace App\Pms\Factory;

use App\Pms\Entity\PmsTarifaRango;
use App\Entity\MaestroMoneda;
use App\Repository\MaestroMonedaRepository;

/**
 * Factory para la creación de entidades {@see PmsTarifaRango}.
 *
 * Responsabilidad:
 * - Centraliza la creación de rangos de tarifa con valores por defecto coherentes.
 * - Evita duplicar lógica de inicialización en controladores, forms o servicios.
 * - Garantiza que toda nueva tarifa tenga una moneda válida (USD por defecto).
 *
 * Decisión de diseño:
 * - La moneda USD se obtiene una sola vez y se cachea en memoria del servicio
 *   para evitar consultas repetidas al repositorio (Doctrine ya cachea,
 *   pero esto evita llamadas innecesarias).
 * - NO persiste la entidad: solo la construye (factory puro).
 *
 * Este factory es especialmente útil cuando:
 * - Se crean tarifas desde formularios.
 * - Se generan rangos automáticamente (pricing engine, sincronizaciones).
 * - Se quiere mantener consistencia de defaults a nivel de dominio.
 */
class PmsTarifaRangoFactory
{
    /**
     * Cache interno de la moneda USD.
     *
     * Se resuelve una sola vez desde base de datos y se reutiliza
     * para todas las creaciones posteriores dentro del mismo request.
     */
    private ?MaestroMoneda $monedaUsd = null;

    public function __construct(
        private MaestroMonedaRepository $maestroMonedaRepository
    ) {
    }

    /**
     * Crea una nueva instancia de {@see PmsTarifaRango} con valores por defecto.
     *
     * Defaults aplicados:
     * - Moneda: USD (si existe en maestro_moneda).
     *
     * Nota:
     * - No asigna fechas, precio ni unidad: eso pertenece al contexto de uso.
     * - No persiste la entidad (responsabilidad del caller).
     *
     * @return PmsTarifaRango Entidad nueva lista para ser configurada.
     */
    public function create(): PmsTarifaRango
    {
        $entity = new PmsTarifaRango();

        $usd = $this->getUsdMoneda();
        if ($usd !== null) {
            $entity->setMoneda($usd);
        }

        // Posibles defaults futuros de dominio:
        // $entity->setActivo(true);
        // $entity->setPeso(0);
        // $entity->setImportante(false);

        return $entity;
    }

    /**
     * Obtiene la entidad {@see MaestroMoneda} correspondiente a USD.
     *
     * - La primera llamada consulta al repositorio.
     * - Las siguientes reutilizan el valor cacheado.
     *
     * @return MaestroMoneda|null Moneda USD o null si no existe en base de datos.
     */
    private function getUsdMoneda(): ?MaestroMoneda
    {
        if ($this->monedaUsd === null) {
            $this->monedaUsd = $this->maestroMonedaRepository
                ->findOneBy(['codigo' => 'USD']);
        }

        return $this->monedaUsd;
    }
}