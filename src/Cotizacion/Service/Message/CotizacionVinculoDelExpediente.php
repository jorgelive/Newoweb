<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pone al día el vínculo comercial de las conversaciones de un expediente cuando cambia lo que lo
 * decide: un pago registrado o anulado.
 *
 * El enlace GUARDA el vínculo —lo lee el motor de reglas en caliente, ver
 * `CotizacionConversacionEnlace::$vinculo`— y sólo se recalculaba al crear o resincronizar la
 * conversación. Sin esto, el pasajero que acaba de pagar seguiría siendo «interesado» para el
 * agente hasta que volviera a escribir por otro canal.
 *
 * La regla no está aquí: es {@see CotizacionFile::vinculoComercial()}.
 */
final readonly class CotizacionVinculoDelExpediente
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Ajusta los enlaces del expediente. No hace `flush()`: va en la escritura de quien llama. */
    public function aplicar(CotizacionFile $file): void
    {
        $vinculo = $file->vinculoComercial();

        foreach ($this->em->getRepository(CotizacionConversacionEnlace::class)->findBy(['file' => $file]) as $enlace) {
            $enlace->setVinculo($vinculo);
        }
    }
}
