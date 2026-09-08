<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Api;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\CargaMasivaDeArchivos;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Sube un ZIP de boarding passes y los reparte entre las personas y los vuelos del expediente.
 *
 * ── Dos pasos, y el primero no escribe ──────────────────────────────────────
 * `POST …/plan` devuelve fila por fila qué haría —quién, qué vuelo, qué problema— y **no guarda
 * nada**. `POST …/aplicar` guarda lo que casa. Con mil ficheros, aplicar a ciegas es pedir un
 * desastre callado: un renombrado torcido metería el boarding pass de uno en la ficha de otro y
 * nadie lo sabría hasta el gate.
 *
 * ⚠️ **El ZIP se sube una sola vez.** El plan devuelve la carpeta temporal donde quedaron los
 * ficheros extraídos, y «aplicar» trabaja sobre ella: volver a subir 300 MB para confirmar sería
 * castigar al operador por revisar.
 */
#[AsController]
final class CargaMasivaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CargaMasivaDeArchivos $carga,
    ) {
    }

    #[Route(
        '/platform/sales/cotizacion_files/{id}/archivos-zip/plan',
        name: 'cotizacion_carga_masiva_plan',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para subir documentos.')]
    public function plan(string $id, Request $request): JsonResponse
    {
        $file = $this->expediente($id);
        $zip = $request->files->get('zip');

        if ($file === null || $zip === null) {
            return $this->json(['error' => 'Falta el expediente o el ZIP.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $plan = $this->carga->planificar($file, $zip->getPathname());
        } catch (RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'carpeta' => $this->carpetaDe($plan),
            'filas' => array_map(
                static fn (array $f): array => [
                    'fichero' => $f['fichero'],
                    'pasajero' => $f['pasajero'] === null ? null : trim(sprintf(
                        '%s %s',
                        (string) $f['pasajero']->getNombre(),
                        (string) $f['pasajero']->getApellido(),
                    )),
                    'pasajeroId' => $f['pasajero']?->getId()?->toRfc4122(),
                    'vuelo' => $f['vuelo'] === null ? null : sprintf(
                        '%s · %s → %s',
                        (string) $f['vuelo']->getNumero(),
                        (string) $f['vuelo']->getOrigen(),
                        (string) $f['vuelo']->getDestino(),
                    ),
                    'vueloId' => $f['vuelo']?->getId()?->toRfc4122(),
                    'problema' => $f['problema'],
                    'reemplaza' => $f['reemplaza'],
                    'ruta' => $f['ruta'] === null ? null : basename($f['ruta']),
                ],
                $plan,
            ),
        ]);
    }

    /**
     * Guarda lo que casa.
     *
     * ⚠️ Se recalcula el plan a partir de la carpeta ya extraída **y no se confía en lo que manda
     * el navegador**: si el cliente pudiera decir «este fichero es de esta persona», bastaría con
     * editar la petición para colgarle a alguien el boarding pass de otro.
     */
    #[Route(
        '/platform/sales/cotizacion_files/{id}/archivos-zip/aplicar',
        name: 'cotizacion_carga_masiva_aplicar',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para subir documentos.')]
    public function aplicar(string $id, Request $request): JsonResponse
    {
        $file = $this->expediente($id);
        $carpeta = (string) $request->request->get('carpeta', '');

        if ($file === null || !preg_match('/^zip-[0-9a-f]{12}$/', $carpeta)) {
            return $this->json(['error' => 'Falta el expediente o la carga no existe.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $creados = $this->carga->aplicarDesdeCarpeta($file, $carpeta);
        } catch (RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($creados as $archivo) {
            $this->em->persist($archivo);
        }

        $this->em->flush();

        // 🔥 DESPUÉS del flush, nunca antes: Vich no mueve el fichero, lo COPIA —sólo mueve los
        // `UploadedFile`—, y esa copia ocurre al guardar. Y hay que borrarla justamente por eso:
        // como copia, cada ZIP aplicado dejaba el extracto entero duplicado, para siempre.
        $this->carga->limpiar($carpeta);

        return $this->json(['creados' => count($creados)]);
    }

    /**
     * El operador miró el reparto y no le gustó.
     *
     * ⚠️ Sin esto, «Descartar» sólo limpiaba la pantalla y el extracto se quedaba en el disco. Con
     * ZIP de cientos de megas y tres intentos hasta acertar con el renombrado, eso es lo que llena
     * el disco — y un disco lleno aquí ya tumbó producción una vez.
     */
    #[Route(
        '/platform/sales/cotizacion_files/{id}/archivos-zip/descartar',
        name: 'cotizacion_carga_masiva_descartar',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para subir documentos.')]
    public function descartar(string $id, Request $request): JsonResponse
    {
        $this->carga->limpiar((string) $request->request->get('carpeta', ''));

        return $this->json(['ok' => true]);
    }

    private function expediente(string $id): ?CotizacionFile
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));
    }

    /** @param list<array{ruta: ?string}> $plan */
    private function carpetaDe(array $plan): ?string
    {
        foreach ($plan as $fila) {
            if ($fila['ruta'] !== null) {
                return basename(dirname($fila['ruta']));
            }
        }

        return null;
    }
}
