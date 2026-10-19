<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Una lista de pasajeros en el formato que pide un tercero: PeruRail, el Ministerio de Cultura…
 *
 * Añadir otra —Consettur, cuya plantilla también se conserva— es una clase que implemente esto: el
 * controlador las encuentra solas por `formato()`.
 */
#[AutoconfigureTag('app.lista_para_proveedor')]
interface ListaParaProveedorInterface
{
    /** El nombre en la URL: `perurail`, `cultura`. */
    public function formato(): string;

    /** @param list<CotizacionFilepasajero> $pasajeros en el orden en que deben salir */
    public function generar(CotizacionFile $file, array $pasajeros): ArchivoDeLista;
}
