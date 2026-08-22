<?php

declare(strict_types=1);

namespace App\Api\Controller\Operacion;

use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Service\OperacionPuntosDelServicio;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lo que el CATÁLOGO dice sobre dónde recoge y deja cada servicio de un expediente.
 *
 * Devuelve el **derivado**, sin aplicar el override del operador — que el panel ya tiene en la
 * propia fila. Es lo que se pinta como marcador de posición del campo editable: enseña qué
 * saldría si lo vaciara, y así se entiende que vacío no significa «sin punto» sino «lo que diga
 * el catálogo».
 *
 * Endpoint aparte y no un campo de `OperacionServicio` por lo mismo que en cotizaciones: es una
 * lectura derivada que se refresca sola al corregir un segmento, y como campo habría acabado
 * también en el `PUT` de vuelta.
 */
#[Route('/operacion/user/puntos', name: 'operacion_user_puntos')]
#[IsGranted(Roles::OPERACIONES_SHOW)]
class OperacionPuntosController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperacionPuntosDelServicio $puntos,
    ) {}

    /**
     * Por LISTA DE IDS y no por expediente: el cuadro de tráfico carga servicios filtrados por
     * fecha, lugar o tipo, que pueden ser de varios expedientes a la vez. Pedirlos por expediente
     * obligaría a una petición por cada uno y a traer servicios que no están en pantalla.
     */
    #[Route('', name: '_get', methods: ['GET'])]
    public function puntos(Request $request): JsonResponse
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter(
            (array) $request->query->all('id'),
            static fn (mixed $v): bool => is_string($v) && $v !== ''
        ));

        if ($ids === []) {
            return $this->json(['servicios' => []]);
        }

        // Un tope explícito: el cuadro pinta como mucho una página, y sin límite una petición
        // manipulada resolvería la cadena de alojamiento de medio catálogo.
        $ids = array_slice($ids, 0, 500);

        /** @var list<OperacionServicio> $servicios */
        $servicios = $this->em->getRepository(OperacionServicio::class)->findBy(['id' => $ids]);
        $salida = [];

        foreach ($servicios as $servicio) {
            $id = $servicio->getId()?->toRfc4122();

            if ($id === null) {
                continue;
            }

            $derivado = $this->puntos->para($servicio, conOverride: false);

            $salida[$id] = [
                'aplica' => $derivado->aplica,
                'tieneEntrega' => $derivado->tieneEntrega,
                'recojo' => $derivado->recojo,
                'entrega' => $derivado->entrega,
                'avisos' => $derivado->avisos,
            ];
        }

        return $this->json(['servicios' => $salida]);
    }
}
