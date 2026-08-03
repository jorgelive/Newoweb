<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Operacion\Entity\OperacionServicio;
use App\Travel\Enum\TarifaRolEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Construye las filas de La Biblia a partir de una cotización confirmada.
 *
 * Vive fuera del listener porque la generación tiene dos entradas legítimas: el flujo
 * automático al confirmar (CotizacionConfirmadaEventListener) y la re-sincronización
 * manual (OperacionResincronizarCommand) cuando el snapshot quedó viejo o incompleto.
 * Duplicar estas reglas en los dos sitios era garantía de que se desincronizaran.
 */
class BibliaSnapshotService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Genera los OperacionServicio que falten para una cotización.
     *
     * No hace flush: el listener necesita persistir dentro del flush en curso y el comando
     * cierra la transacción por su cuenta.
     *
     * @return OperacionServicio[] Las entidades nuevas, ya persistidas pero sin flush.
     */
    public function generarParaCotizacion(Cotizacion $cotizacion): array
    {
        // Tours de catálogo: producto de exhibición con fechas nominales, sin expediente
        // real — nunca deben generar operación en La Biblia.
        if ($cotizacion->getCatalogo() !== null) {
            return [];
        }

        $file        = $cotizacion->getFile();
        $cantidadPax = $cotizacion->getNumPax();
        $osRepo      = $this->em->getRepository(OperacionServicio::class);
        $creados     = [];

        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            foreach ($cotservicio->getCotcomponentes() as $cotcomponente) {
                // Idempotencia: saltar si ya existe un OperacionServicio para este componente
                if ($osRepo->findOneBy(['cotizacionComponente' => $cotcomponente]) !== null) {
                    continue;
                }

                $tarifa = $this->resolverTarifaPrimaria($cotcomponente);
                if ($tarifa === null || $tarifa->getMoneda() === null) {
                    continue; // Sin tarifa o sin moneda asignada: no se puede colocar
                }

                $fechaServicio = $this->resolverFechaServicio($cotcomponente, $cotservicio);
                if ($fechaServicio === null) {
                    continue; // Sin fecha: no se puede ubicar en La Biblia
                }

                $moneda = $tarifa->getMoneda();

                $ops = new OperacionServicio();
                $ops->setFile($file);
                $ops->setCotizacionServicio($cotservicio);
                $ops->setCotizacionComponente($cotcomponente);
                $ops->setCotizacionTarifa($tarifa);
                $ops->setFechaServicio($fechaServicio);
                $ops->setHoraRecojoReal($this->resolverHoraRecojo($cotcomponente));
                $ops->setProveedorMaestroId($tarifa->getProveedorMaestroId());
                $ops->setProveedorNombreManual($tarifa->getProveedorNombreSnapshot());
                $ops->setDescripcionServicio($this->resolverDescripcion($tarifa, $cotcomponente));
                $ops->setContextoServicio($this->textoEspanol($cotservicio->getNombreSnapshot()));
                // Clasificación del componente: hoy no filtra nada — entra todo a La Biblia —
                // pero viaja en el snapshot para que la vista pueda agrupar, priorizar y
                // filtrar, y para poder definir después qué tipos son realmente despachables.
                $ops->setTipoComponente($cotcomponente->getTipo());
                $ops->setModoComponente($cotcomponente->getModo()->value);
                $ops->setEstadoComponente($cotcomponente->getEstado()->value);
                $ops->setCantidadPax($cantidadPax);
                $ops->setCostoCotizado($tarifa->getMontoCosto());
                $ops->setMonedaCotizada($moneda);
                $ops->setMontoVenta('0.00');
                $ops->setCostoRealOperativo('0.00');
                $ops->setMonedaReal($moneda);

                $this->em->persist($ops);
                $creados[] = $ops;
            }
        }

        return $creados;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reglas de resolución
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Elige la tarifa que se opera cuando el componente tiene varias.
     *
     * Sólo estandar y operativo entran a La Biblia; alternativa es venta opcional y no
     * se despacha. Entre las candidatas gana el menor grupoTarifa.
     */
    public function resolverTarifaPrimaria(CotizacionCotcomponente $componente): ?CotizacionCottarifa
    {
        $tarifas = array_filter(
            $componente->getCottarifas()->toArray(),
            static fn (CotizacionCottarifa $t): bool =>
                TarifaRolEnum::tryFrom($t->getRolSnapshot() ?? '') !== TarifaRolEnum::ALTERNATIVA
        );

        if (empty($tarifas)) {
            return null;
        }

        usort($tarifas, static fn (CotizacionCottarifa $a, CotizacionCottarifa $b): int =>
            ($a->getGrupoTarifa() ?? PHP_INT_MAX) <=> ($b->getGrupoTarifa() ?? PHP_INT_MAX)
        );

        return array_values($tarifas)[0];
    }

    public function resolverFechaServicio(
        CotizacionCotcomponente $componente,
        CotizacionCotservicio $cotservicio,
    ): ?DateTimeImmutable {
        $inicio = $componente->getFechaHoraInicio();
        if ($inicio !== null) {
            // Normalizar a solo fecha (medianoche UTC) para el campo date_immutable
            return new DateTimeImmutable($inicio->format('Y-m-d'));
        }

        // Fallback: fecha base del servicio padre
        return $cotservicio->getFechaInicioAbsoluta();
    }

    public function resolverHoraRecojo(CotizacionCotcomponente $componente): ?string
    {
        $inicio = $componente->getFechaHoraInicio();
        if ($inicio === null || $componente->isSinHorario()) {
            return null;
        }

        return $inicio->format('H:i');
    }

    public function resolverDescripcion(
        CotizacionCottarifa $tarifa,
        CotizacionCotcomponente $componente,
    ): string {
        // Prioridad 1: nombre interno de la tarifa (campo operativo)
        $descripcion = trim($tarifa->getNombreInternoSnapshot() ?? '');
        if ($descripcion !== '') {
            return $descripcion;
        }

        // Prioridad 2: nombre del componente en español (snapshot i18n)
        return $this->textoEspanol($componente->getNombreSnapshot()) ?? 'Servicio sin nombre';
    }

    /**
     * Extrae el contenido en español de un snapshot i18n ([{language, content}, ...]).
     *
     * @param array<int, array{language?: string, content?: string}> $snapshot
     */
    public function textoEspanol(array $snapshot): ?string
    {
        foreach ($snapshot as $item) {
            if (($item['language'] ?? '') !== 'es') {
                continue;
            }

            $texto = trim(strip_tags($item['content'] ?? ''));
            if ($texto !== '') {
                return $texto;
            }
        }

        return null;
    }
}
