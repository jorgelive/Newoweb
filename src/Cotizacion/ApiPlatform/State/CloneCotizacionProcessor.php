<?php
// src/Cotizacion/ApiPlatform/State/CloneCotizacionProcessor.php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Dto\CuerpoDeClonacion;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Dto\Lee;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Clona una cotización: en su mismo padre, **en otro expediente y con otras fechas**, o **en un
 * catálogo de tours** como propuesta genérica (07/10/2026).
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
 * aparte, con su botón — ver `docs/Operacion.md` §2.bis. Tampoco `publicado`, la fecha de
 * creación ni los ids internos del original: eso lo resuelve `Cotizacion::duplicar()` para toda
 * copia.
 *
 * ## Cambiar de padre
 *
 * Las reglas de cada dirección viven en la entidad (`reubicarEnExpediente()`,
 * `reubicarEnCatalogo()`); aquí sólo se decide el destino y la fecha:
 *
 * | Destino | Fecha | Si no llega |
 * |---|---|---|
 * | mismo padre (`{}`) | opcional | no se mueve |
 * | otro expediente | opcional | no se mueve — salvo que venga de un catálogo: 422 |
 * | catálogo | opcional | la base nominal (`CotizacionCatalogo::FECHA_BASE_NOMINAL`) |
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

        if ($cuerpo->fileId !== null && $cuerpo->catalogoId !== null) {
            throw new DomainException('Elige un destino para la copia: un expediente o un catálogo, no los dos.');
        }

        if ($cuerpo->fechaInicio === false) {
            throw new DomainException('La fecha de inicio tiene que venir como AAAA-MM-DD.');
        }

        $fechaInicio = $cuerpo->fechaInicio;
        // La base nominal no la pidió nadie: si el original no tiene fechas, no hay nada que mover
        // y no es un error (sí lo es si el operador pidió una fecha concreta).
        $fechaPedida = $fechaInicio !== null;

        $destino = $this->resolverDestino($cuerpo->fileId);
        $catalogo = $this->resolverCatalogo($cuerpo->catalogoId);

        if ($destino !== null) {
            // Un tour de catálogo vive en 2030 («Día N»): llevarlo a un expediente sin decir a qué
            // día dejaría un viaje real con fechas de mentira, que se leen perfectamente plausibles.
            if ($data->getCatalogo() !== null && $fechaInicio === null) {
                throw new DomainException('Un tour de catálogo tiene fechas nominales: para pasarlo a un expediente indica la fecha de inicio.');
            }
            $clon->reubicarEnExpediente($destino);
        }

        if ($catalogo !== null) {
            $orden = 0;
            foreach ($catalogo->getCotizaciones() as $c) {
                $orden = max($orden, $c->getOrden() + 1);
            }
            $clon->reubicarEnCatalogo($catalogo, $orden);
            $fechaInicio ??= new \DateTimeImmutable(CotizacionCatalogo::FECHA_BASE_NOMINAL);
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

        if ($fechaInicio !== null && $clon->desplazarA($fechaInicio) === null && $fechaPedida) {
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

    /** El catálogo destino, o null si la copia no va a un catálogo. */
    private function resolverCatalogo(?string $catalogoId): ?CotizacionCatalogo
    {
        if ($catalogoId === null) {
            return null;
        }

        if (!Uuid::isValid($catalogoId)) {
            throw new DomainException('El catálogo destino no es un identificador válido.');
        }

        $catalogo = $this->entityManager->find(CotizacionCatalogo::class, Uuid::fromString($catalogoId));

        if (!$catalogo instanceof CotizacionCatalogo) {
            throw new DomainException('El catálogo destino no existe.');
        }

        return $catalogo;
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
