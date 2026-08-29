<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Vuelos\VuelosImportador;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Carga vuelos en un expediente desde un JSON pegado a mano.
 *
 * Mismo trato que el padrón: `?ensayo=1` **escribe y deshace**, así que lo que enseña es
 * exactamente lo que va a pasar —incluidas las restricciones de base—. La lógica no vive aquí
 * sino en {@see VuelosImportador}, que es también lo que usa el comando.
 *
 * ⚠️ El texto llega **pegado**, no subido: quien lo usa acaba de copiarlo de un correo de la
 * aerolínea. Por eso el JSON mal formado se contesta con el mensaje del parser y la posición,
 * que es lo único accionable cuando falta una coma en la línea 40.
 */
final class VuelosCargaController extends AbstractController
{
    #[Route(
        '/cotizacion/user/vuelos/cargar/{id}',
        name: 'cotizacion_vuelos_cargar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para cargar vuelos.')]
    public function __invoke(
        string $id,
        Request $request,
        EntityManagerInterface $em,
        VuelosImportador $importador,
    ): JsonResponse {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return $this->json(['error' => 'No encontré el expediente.'], Response::HTTP_NOT_FOUND);
        }

        $cuerpo = json_decode($request->getContent(), true);
        $texto = is_array($cuerpo) ? (string) ($cuerpo['json'] ?? '') : '';

        if (trim($texto) === '') {
            return $this->json(['error' => 'No pegaste nada.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $reservas = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->json(
                ['error' => 'El texto no es JSON válido: ' . $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!is_array($reservas) || !array_is_list($reservas)) {
            return $this->json(
                ['error' => 'Se espera una LISTA de reservas, cada una con su «pnr» y sus «vuelos».'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var list<array<string, mixed>> $reservas */
        $resultado = $importador->importar($file, $reservas, !$request->query->getBoolean('ensayo', true));

        return $this->json([
            'expediente' => $file->getLocalizador(),
            'grupo' => $file->getNombreGrupo(),
            'cambios' => $resultado->cambios,
            'avisos' => $resultado->avisos,
            'problemas' => $resultado->problemas,
            'hayCambios' => $resultado->hayAlgoQueHacer(),
        ]);
    }
}
