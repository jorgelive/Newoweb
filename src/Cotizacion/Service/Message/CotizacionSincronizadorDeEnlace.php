<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\SincronizadorDeEnlaceInterface;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cuelga —y mantiene al día— el EXPEDIENTE como asunto de la conversación de esa persona.
 *
 * Es la pieza que faltaba para que Turismo existiera en la mensajería: la tabla y el proveedor
 * llevaban días en pie y **nadie los rellenaba**.
 *
 * ⚠️ El asunto de Travel es el **expediente**, no la cotización. Un expediente tiene varias
 * versiones —se propone, el cliente pide cambios, se emite la v2— y el cliente habla de *su
 * viaje*, no de la versión vigente: colgar la cotización obligaría a mover el enlace en cada
 * revisión y el hilo contaría una historia partida.
 */
final readonly class CotizacionSincronizadorDeEnlace implements SincronizadorDeEnlaceInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private CotizacionProveedorDeEnlaces $proveedor,
    ) {
    }

    public function supports(?string $contextType): bool
    {
        return $contextType === CotizacionConversacionEnlace::CONTEXT_TYPE;
    }

    public function sincronizar(MessageConversation $conversacion, MessageContextInterface $contexto): void
    {
        if (!Uuid::isValid($contexto->getContextId())) {
            return;
        }

        $file = $this->em->getRepository(CotizacionFile::class)->find(Uuid::fromString($contexto->getContextId()));

        if ($file === null) {
            return;
        }

        $enlace = $this->enlaceDe($conversacion, $file);

        if ($enlace === null) {
            $enlace = new CotizacionConversacionEnlace($conversacion, $file);
            $this->em->persist($enlace);
        }

        $enlace->setVinculo($contexto->getVinculo());
        $enlace->setOrigen($contexto->getOrigin());
        $enlace->setAgencia($contexto->getAgencyId());

        // Los hitos se copian aunque hoy lleguen vacíos: el día que Turismo tenga fechas
        // propias, esto ya está enchufado y el motor las verá sin tocar nada aquí.
        $enlace->setMilestones($contexto->getMilestones()->comoTextoPlano());
    }

    /**
     * El enlace de ESTE expediente en ESTE hilo, mirando también lo pendiente de insertar.
     *
     * Por lo mismo que el proveedor: en el alta, el enlace se crea y se consulta dentro de la
     * misma unidad de trabajo, y una consulta a secas no lo vería — se crearía un duplicado y
     * el índice único reventaría al hacer flush.
     */
    private function enlaceDe(MessageConversation $conversacion, CotizacionFile $file): ?CotizacionConversacionEnlace
    {
        foreach ($this->proveedor->paraConversacion($conversacion) as $enlace) {
            if ($enlace->getFile()?->getId()?->equals($file->getId()) === true) {
                return $enlace;
            }
        }

        return null;
    }
}
