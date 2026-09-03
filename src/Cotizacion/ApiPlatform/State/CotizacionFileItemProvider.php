<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * El expediente del editor, más el dato que no se puede deducir: **dónde vive la operación**.
 *
 * ── Por qué hace falta decirlo ──────────────────────────────────────────────
 * Antes se sabía mirando el estado: la confirmada tenía la operación y punto. Dos cambios del
 * 02/09/2026 rompieron esa deducción, cada uno por su lado:
 *
 * - **Confirmar ya no arma la operación** (`GenerarOperacionProcessor`), así que una confirmada
 *   puede estar vacía sencillamente porque nadie ha pulsado el botón.
 * - **La operativa se lleva las filas** (`AbrirOperativaProcessor`), así que una confirmada puede
 *   estar vacía porque ya las traspasó.
 *
 * Las dos se ven igual desde fuera —una confirmada sin operación— y significan cosas opuestas. Sin
 * este dato, el operador no tiene forma de distinguirlas más que abriendo La Biblia.
 *
 * ── UNA consulta para todo el expediente ────────────────────────────────────
 * ⚠️ Y ésa es la razón de que esto viva en un provider y no en un getter de la entidad. No hay
 * relación inversa de `cotservicio` a `OperacionServicio` —es unidireccional a propósito—, así que
 * un getter que contara por su cuenta dispararía **una consulta por propuesta**: N+1 con aspecto
 * de campo, del que nadie sospecha porque parece un atributo.
 *
 * Aquí es un `GROUP BY` sobre las propuestas del expediente, y son 2–5.
 *
 * ⚠️ SQL crudo y no DQL: es un `COUNT` sobre un `JOIN`, no hay nada que hidratar, y pasar ids UUID
 * por DQL es donde este módulo ya se ha tropezado tres veces.
 *
 * @implements ProviderInterface<CotizacionFile>
 */
final readonly class CotizacionFileItemProvider implements ProviderInterface
{
    public function __construct(
        /** @var ProviderInterface<CotizacionFile> */
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $decorado,
        private Connection $conn,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionFile
    {
        $file = $this->decorado->provide($operation, $uriVariables, $context);

        if (!$file instanceof CotizacionFile || $file->getId() === null) {
            return $file instanceof CotizacionFile ? $file : null;
        }

        /** @var array<string, int> $porCotizacion */
        $porCotizacion = $this->conn->fetchAllKeyValue(
            "SELECT LOWER(HEX(cs.cotizacion_id)) AS cot, COUNT(*) AS n
               FROM operacion_servicio os
               JOIN cotizacion_cotservicio cs ON cs.id = os.cotizacion_servicio_id
              WHERE cs.cotizacion_id IN (
                    SELECT c.id FROM cotizacion_cotizacion c WHERE c.file_id = UNHEX(REPLACE(?, '-', ''))
                )
                AND os.estado_operacion != 'cancelado'
              GROUP BY cs.cotizacion_id",
            [(string) $file->getId()],
        );

        foreach ($file->getCotizaciones() as $cotizacion) {
            if (!$cotizacion instanceof Cotizacion || $cotizacion->getId() === null) {
                continue;
            }

            $clave = str_replace('-', '', strtolower($cotizacion->getId()->toRfc4122()));

            // ⚠️ 0 explícito, no `null`. `null` significa «no se preguntó» y el front lo usa para
            // no pintar nada; una propuesta sin filas SÍ es una respuesta, y es la que hace falta
            // para distinguir «vacía» de «no consultada».
            $cotizacion->setFilasOperacionActivas((int) ($porCotizacion[$clave] ?? 0));
        }

        return $file;
    }
}
