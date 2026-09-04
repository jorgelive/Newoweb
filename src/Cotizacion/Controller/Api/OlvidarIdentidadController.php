<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Api;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «No soy yo»: cierra la identificación de este expediente.
 *
 * El enlace de un viaje de grupo se abre en dispositivos compartidos —el móvil de la familia, el
 * ordenador del colegio—, y sin esto la primera persona que entra deja su nombre, su localizador
 * de vuelo y su habitación puestos para quien venga detrás.
 *
 * ⚠️ **Responde 200 aunque no hubiera nadie identificado.** Es idempotente por naturaleza: quien
 * llama quiere quedar sin identidad, y ya estarlo es el resultado que pedía. Un 404 o un 400 sólo
 * daría al front un caso que tratar sin ninguna consecuencia distinta.
 *
 * ⚠️ El freno de intentos **no se toca**: ver {@see IdentidadDelPasajero::olvidar()}. Si saliera
 * con él, cerrar sesión sería la forma de saltárselo.
 *
 * ── Vive en `Controller/Api/` y eso ES la configuración ────────────────────
 * `config/routes.yaml` ata cada carpeta a un host. `pax` llama por `apiClient`, o sea a
 * `api.openperu.pe`, así que un controlador suyo va aquí aunque su pantalla sea pública. Es la
 * trampa que ya se pagó con {@see IdentificarPasajeroController}.
 */
final class OlvidarIdentidadController extends AbstractController
{
    #[Route(
        path: '/platform/sales/client/cotizacion/{localizador}/identificar',
        name: 'pax_cotizacion_olvidar',
        methods: ['DELETE'],
    )]
    public function __invoke(
        string $localizador,
        EntityManagerInterface $em,
        IdentidadDelPasajero $identidad,
    ): JsonResponse {
        $file = $em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            return new JsonResponse(['mensaje' => 'Expediente no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $identidad->olvidar($file);

        return new JsonResponse(['identificado' => false]);
    }
}
