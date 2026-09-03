<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Publico;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El formulario que abre la propuesta operativa de un expediente de grupo.
 *
 * Ver {@see IdentidadDelPasajero} para el porqué de pedir documento + fecha de nacimiento y de
 * guardar la identidad en la sesión.
 *
 * ⚠️ **Controlador plano y no una operación de API Platform.** Esto no lee ni escribe un recurso:
 * abre una puerta. Modelarlo como un `Post` sobre `CotizacionFile` obligaría a un DTO, un
 * processor y un grupo de escritura para algo que no cambia ni una fila.
 */
final class IdentificarPasajeroController extends AbstractController
{
    #[Route(
        path: '/platform/sales/client/cotizacion/{localizador}/identificar',
        name: 'pax_cotizacion_identificar',
        methods: ['POST'],
    )]
    public function __invoke(
        string $localizador,
        Request $request,
        EntityManagerInterface $em,
        IdentidadDelPasajero $identidad,
    ): JsonResponse {
        $file = $em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            return new JsonResponse(['mensaje' => 'Expediente no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$file->isExigeIdentificacion()) {
            // No hay puerta que abrir. Responder 200 y no 400: quien llame no tiene por qué saber
            // de antemano de qué modo es el expediente.
            return new JsonResponse(['identificado' => true, 'nombre' => null]);
        }

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode((string) $request->getContent(), true) ?: [];

        $pasajero = $identidad->identificar(
            $file,
            is_string($cuerpo['documento'] ?? null) ? $cuerpo['documento'] : '',
            is_string($cuerpo['fechaNacimiento'] ?? null) ? $cuerpo['fechaNacimiento'] : '',
        );

        if ($pasajero !== null) {
            return new JsonResponse([
                'identificado' => true,
                'nombre' => trim($pasajero->getNombre() . ' ' . $pasajero->getApellido()),
            ]);
        }

        if ($identidad->bloqueado($file)) {
            return new JsonResponse([
                'identificado' => false,
                'mensaje' => 'Demasiados intentos. Vuelve a probar en un rato, o escríbenos y te ayudamos.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // ⚠️ **Un solo mensaje para los dos fallos**, a propósito. Decir «ese documento no está en
        // el grupo» convierte el formulario en un buscador de documentos: se prueban números
        // hasta que uno deja de dar ese mensaje, y ya se sabe quién viaja sin haber entrado.
        return new JsonResponse([
            'identificado' => false,
            'mensaje' => 'No encontramos a nadie con esos datos. Revisa el documento y la fecha de nacimiento.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
