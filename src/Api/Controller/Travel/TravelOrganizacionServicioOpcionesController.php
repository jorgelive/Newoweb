<?php

declare(strict_types=1);

namespace App\Api\Controller\Travel;

use App\Security\Roles;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Los servicios de UNA organización, para el desplegable dependiente del panel.
 *
 * ## Por qué no es un filtro de API Platform
 *
 * Porque no puede serlo. `SearchFilter` **no filtra por UUID en este proyecto**: los ids son
 * `binary(16)` y la librería enlaza el valor sin declarar su tipo —`setParameter($p, $values[0])`
 * en `Filter/SearchFilter.php`—, así que compara texto contra binario y no casa jamás.
 * Comprobado contra producción el 20/08/2026 sobre esta misma colección:
 *
 * ```
 * ?nombre=Grand            → 1 resultado    (filtro de texto: funciona)
 * ?id=<uuid>               → 0 resultados   (filtro de uuid: no)
 * ?organizacion=<uuid>     → 0 resultados
 * ?organizacion=<IRI>      → 0 resultados
 * ```
 *
 * ⚠️ Y un filtro de UUID declarado es **peor que ninguno**: sin declarar, API Platform ignora el
 * parámetro y devuelve la colección entera —mal, pero visible—; declarado, se aplica y devuelve
 * cero, que es como el selector de prestadores del editor se quedó en blanco durante unas horas.
 *
 * Aquí el uuid se resuelve en PHP, que es donde sí se sabe convertirlo.
 *
 * ## El contrato de salida
 *
 * `[{id, nombre}]`, que es justo lo que lee
 * `assets/controllers/panel/dependent-select-ajax_controller.js`. Ni ficha, ni imágenes, ni
 * traducciones: un desplegable no necesita nada más y traerlo encarece cada tecla.
 *
 * ⚠️ **El host de la API cae en `PUBLIC_ACCESS`** —ver el aviso de
 * {@see \App\Api\Controller\Tipo\PmsEnumAjaxController}—, así que lo único que protege esto es
 * el `#[IsGranted]` de abajo. No lo quites.
 */
#[Route('/platform/travel/organizacion-servicios-opciones', name: 'travel_organizacion_servicio_opciones', methods: ['GET'])]
#[IsGranted(Roles::MAESTROS_SHOW)]
final class TravelOrganizacionServicioOpcionesController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $bruto = trim((string) $request->query->get('organizacion', ''));

        // Sin organización se devuelve vacío, NO el catálogo entero: este endpoint alimenta un
        // «servicios de ESA empresa», y contestar con los de todas es exactamente el fallo que
        // vino a arreglar.
        if ($bruto === '' || !Uuid::isValid($bruto)) {
            return $this->json([]);
        }

        /** @var list<array{id: string, nombre: string|null}> $filas */
        $filas = $this->em->createQueryBuilder()
            ->select('s.id AS id', 's.nombre AS nombre')
            ->from(TravelOrganizacionServicio::class, 's')
            ->andWhere('s.organizacion = :organizacion')
            ->setParameter('organizacion', Uuid::fromString($bruto), 'uuid')
            ->orderBy('s.nombre', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $this->json(array_map(
            static fn (array $f): array => ['id' => (string) $f['id'], 'nombre' => $f['nombre'] ?? ''],
            $filas
        ));
    }
}
