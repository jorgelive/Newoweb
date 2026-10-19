<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Enum\DocumentoTipoEnum;

/**
 * Con qué documento va cada pasajero en una lista de entradas o de tren.
 *
 * El peruano con su DNI —es con el que entra y con el que le dan tarifa nacional—; el resto con el
 * pasaporte, que es con el que viaja. Sin el preferido, el que tenga, en el orden de abajo. El RUC
 * no: es de empresas, no de personas (`DocumentoTipoEnum::esDocumentoDeViaje()`).
 */
final class DocumentoParaLista
{
    public static function de(CotizacionFilepasajero $pasajero): ?CotizacionPasajeroIdentificacion
    {
        $esPeruano = $pasajero->getPais()?->getId() === 'PE';
        $orden = $esPeruano
            ? [DocumentoTipoEnum::DNI, DocumentoTipoEnum::PASAPORTE, DocumentoTipoEnum::CE, DocumentoTipoEnum::CI]
            : [DocumentoTipoEnum::PASAPORTE, DocumentoTipoEnum::CE, DocumentoTipoEnum::DNI, DocumentoTipoEnum::CI];

        foreach ($orden as $tipo) {
            foreach ($pasajero->getIdentificaciones() as $identificacion) {
                if ($identificacion->getTipo() === $tipo && trim((string) $identificacion->getNumero()) !== '') {
                    return $identificacion;
                }
            }
        }

        return null;
    }
}
