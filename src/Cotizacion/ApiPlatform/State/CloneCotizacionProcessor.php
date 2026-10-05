<?php
// src/Cotizacion/ApiPlatform/State/CloneCotizacionProcessor.php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Dto\CuerpoDeClonacion;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Dto\Lee;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Clona una cotización: en su mismo expediente, o **en otro y con otras fechas**.
 *
 * ## Por qué los dos casos viven en el mismo endpoint
 *
 * Porque son el mismo acto —copiar un viaje ya armado— y sólo cambia a dónde va. El cuerpo manda:
 * vacío clona en el mismo padre, exactamente como antes (la UI sigue mandando `{}` desde
 * `fileStore.ts`), y con `file`/`fechaInicio` lo manda a otro sitio y lo mueve de fecha.
 *
 * ## Qué resuelve
 *
 * Un viaje de promoción de un colegio es el viaje del colegio siguiente con otras fechas: mismos
 * 18 servicios, mismo orden, mismos proveedores. Rearmarlo a mano son horas y el error típico no
 * es olvidar un servicio, es **desordenar los días** — y un itinerario con los días barajados se
 * lee perfectamente plausible.
 *
 * ⚠️ Por eso el desplazamiento es por DELTA y no por reasignación, y la regla vive en
 * {@see Cotizacion::desplazarA()}: todos los días se mueven lo mismo, así que la separación entre
 * servicios se conserva. Aquí sólo se decide el ancla.
 *
 * ## Lo que la copia NO se lleva
 *
 * El estado (nace `PENDIENTE`: una copia no está aprobada de nada) y las operaciones. La Biblia y
 * las órdenes cuelgan de la cotización original y armar la operación de la copia es una decisión
 * aparte, con su botón — ver `docs/Operacion.md` §2.bis.
 *
 * @implements ProcessorInterface<Cotizacion, Cotizacion|null>
 */
final class CloneCotizacionProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Cotizacion
    {
        if (!$data instanceof Cotizacion) {
            throw new DomainException('No se encontró la cotización a clonar.');
        }

        $cuerpo = CuerpoDeClonacion::fromArray($this->cuerpoDe($context));

        // $data es la Cotizacion leída por el provider (gracias a read: true)
        $clon = $data->duplicar();

        $destino = $this->resolverDestino($cuerpo->fileId);

        if ($destino !== null) {
            // A otro expediente: el catálogo del original no viaja, o la copia colgaría de dos
            // sitios. `setFile()` manda y el catálogo se suelta.
            $clon->setCatalogo(null);
            $clon->setFile($destino);
        }

        // El padre puede ser un expediente o un catálogo de tours
        $padre = $clon->getFile() ?? $clon->getCatalogo();
        if ($padre) {
            $ultimaVersion = 0;
            foreach ($padre->getCotizaciones() as $c) {
                if ($c->getPropuesta() > $ultimaVersion) {
                    $ultimaVersion = $c->getPropuesta();
                }
            }
            $clon->setPropuesta($ultimaVersion + 1);
        } else {
            $clon->setPropuesta($data->getPropuesta() + 1);
        }

        if ($cuerpo->fechaInicio === false) {
            throw new DomainException('La fecha de inicio tiene que venir como AAAA-MM-DD.');
        }

        if ($cuerpo->fechaInicio !== null && $clon->desplazarA($cuerpo->fechaInicio) === null) {
            // Ni un servicio con fecha: desplazar no significa nada y callarlo haría creer que el
            // viaje se movió. Es el caso de una plantilla sin fechas puestas todavía.
            throw new DomainException('Esta cotización no tiene ningún servicio con fecha: no hay nada que desplazar.');
        }

        // Los pax, DESPUÉS de las fechas: no dependen entre sí, pero así el orden del código es
        // el mismo que el de la frase que lo pide («a otro expediente, en otra fecha, para N»).
        if ($cuerpo->numPax !== null) {
            $clon->ajustarPax($cuerpo->numPax);
        }

        $clon->setEstado(CotizacionEstadoEnum::PENDIENTE);

        $this->entityManager->persist($clon);
        $this->entityManager->flush();

        return $clon;
    }

    /**
     * El cuerpo del POST, que esta operación **no deserializa** (`deserialize: false`).
     *
     * Un cuerpo ausente, vacío o ilegible es `[]` —«clona como siempre»— y no un error: es lo que
     * manda la UI y lo que mandaría cualquier cliente viejo.
     *
     * @param array<string, mixed> $context
     *
     * @return array<mixed>
     */
    private function cuerpoDe(array $context): array
    {
        $request = $context['request'] ?? null;

        if (!$request instanceof Request) {
            return [];
        }

        $crudo = $request->getContent();

        if (trim($crudo) === '') {
            return [];
        }

        return Lee::mapa(json_decode($crudo, true));
    }

    /** El expediente destino, o null si no se pidió mover la copia. */
    private function resolverDestino(?string $fileId): ?CotizacionFile
    {
        if ($fileId === null) {
            return null;
        }

        if (!Uuid::isValid($fileId)) {
            throw new DomainException('El expediente destino no es un identificador válido.');
        }

        $destino = $this->entityManager->find(CotizacionFile::class, Uuid::fromString($fileId));

        if (!$destino instanceof CotizacionFile) {
            throw new DomainException('El expediente destino no existe.');
        }

        return $destino;
    }
}
