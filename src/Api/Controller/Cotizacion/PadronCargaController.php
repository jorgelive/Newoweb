<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Padron\PadronImportador;
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
 * Carga un padrón en un expediente.
 *
 * ## Dos pasos, y el primero no escribe
 *
 * `?ensayo=1` devuelve **exactamente** lo que va a pasar sin dejar rastro: el importador escribe
 * dentro de una transacción y la deshace, así que las restricciones de base se comprueban igual.
 * Un ensayo más permisivo que la carga no sirve de nada — es lo que dejó pasar un padrón que
 * reventó al aplicar.
 *
 * ⚠️ La respuesta trae siempre el **nombre del expediente**. Cargar 133 personas en el que no toca
 * es un error caro y silencioso, y la pantalla tiene que poder decir en cuál va a escribir antes
 * de que alguien confirme.
 */
final class PadronCargaController extends AbstractController
{
    #[Route(
        '/cotizacion/user/padron/cargar/{id}',
        name: 'cotizacion_padron_cargar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para cargar padrones.')]
    public function __invoke(
        string $id,
        Request $request,
        EntityManagerInterface $em,
        PadronImportador $importador,
    ): JsonResponse {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return $this->json(['error' => 'No encontré el expediente.'], Response::HTTP_NOT_FOUND);
        }

        $subido = $request->files->get('padron');

        if ($subido === null) {
            return $this->json(['error' => 'Falta el archivo.'], Response::HTTP_BAD_REQUEST);
        }

        $extension = mb_strtolower($subido->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->json(
                ['error' => sprintf('«%s» no es una hoja de cálculo. Sube el .xlsx.', $subido->getClientOriginalName())],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // A un temporal propio: el de PHP se borra al acabar la petición y PhpSpreadsheet
        // necesita el archivo abierto mientras lo lee.
        $temporal = sys_get_temp_dir().'/padron_'.bin2hex(random_bytes(8)).'.'.$extension;
        $subido->move(dirname($temporal), basename($temporal));

        try {
            $seco = $request->query->getBoolean('ensayo', true);
            $resultado = $importador->importar($file, $temporal, $seco);
        } finally {
            @unlink($temporal);
        }

        return $this->json([
            'expediente' => $file->getNombreGrupo(),
            'ensayo' => $seco,
            ...$resultado->comoArray(),
        ], $resultado->tieneErrores() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
