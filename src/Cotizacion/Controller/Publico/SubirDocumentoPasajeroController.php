<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Publico;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El pasajero sube la foto de su documento desde su propio móvil.
 *
 * ── Por qué lo sube él ──────────────────────────────────────────────────────
 * Perseguir 133 pasaportes por WhatsApp, uno a uno, y luego renombrarlos y subirlos, es el trabajo
 * que esto quita. Y el que tiene el documento delante es él.
 *
 * ── Quién puede ────────────────────────────────────────────────────────────
 * Sólo quien se identificó con **documento y fecha de nacimiento**
 * ({@see IdentidadDelPasajero}), que ya trae su propio freno a los intentos. Y sólo puede subir
 * **lo suyo**: el pasajero sale de la sesión, no de la petición — si viniera en el cuerpo,
 * bastaría con cambiarlo para subirle algo a otro.
 *
 * ── Lo que NO hace ──────────────────────────────────────────────────────────
 * ⚠️ **No devuelve el fichero nunca.** Sube y se acaba: {@see ArchivoTipoEnum::esDevolvibleAlPasajero()}
 * lo deja fuera, y por eso la app enseña la previsualización **antes** de enviar — que es el
 * momento en que se ve si salió movida. Después ya no hay nada que mirar.
 *
 * ⚠️ **Reemplaza en vez de acumular.** Si ya había una foto de ese mismo tipo, se borra: la
 * segunda es la buena, y guardar las dos deja al operador eligiendo entre una borrosa y otra.
 */
#[AsController]
final class SubirDocumentoPasajeroController
{
    /** Un pasaporte fotografiado con un móvil moderno cabe de sobra. */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    private const array TIPOS_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdentidadDelPasajero $identidad,
    ) {
    }

    #[Route(
        '/file/{localizador}/mis-documentos',
        name: 'cotizacion_subir_documento_pasajero',
        methods: ['POST'],
    )]
    public function __invoke(string $localizador, Request $request): JsonResponse
    {
        $file = $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            return new JsonResponse(['error' => 'Ese expediente no existe.'], Response::HTTP_NOT_FOUND);
        }

        $pasajero = $this->identidad->pasajeroIdentificado($file);

        if ($pasajero === null) {
            return new JsonResponse(
                ['error' => 'Identifícate con tu documento y tu fecha de nacimiento para poder subir.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $tipo = ArchivoTipoEnum::tryFrom((string) $request->request->get('tipo'));

        if ($tipo === null || !$tipo->loSubeElPasajero()) {
            return new JsonResponse(['error' => 'Ese tipo de documento no se sube desde aquí.'], Response::HTTP_BAD_REQUEST);
        }

        $subido = $request->files->get('documento');

        if (!$subido instanceof UploadedFile || !$subido->isValid()) {
            return new JsonResponse(['error' => 'No llegó la foto. Inténtalo otra vez.'], Response::HTTP_BAD_REQUEST);
        }

        if ($subido->getSize() > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'La foto pesa demasiado. Prueba con menos calidad.'], Response::HTTP_BAD_REQUEST);
        }

        // ⚠️ El tipo se pregunta al FICHERO, no a lo que diga el navegador: `getClientMimeType()`
        // lo manda quien sube y se puede escribir a mano.
        if (!in_array((string) $subido->getMimeType(), self::TIPOS_MIME, true)) {
            return new JsonResponse(['error' => 'Sube una foto o un PDF.'], Response::HTTP_BAD_REQUEST);
        }

        // La segunda foto es la buena: se reemplaza en vez de acumular.
        foreach ($file->getFilearchivos() as $previo) {
            if ($previo->getTipoArchivo() === $tipo && $previo->getPasajero()?->getId()?->equals($pasajero->getId() ?? $previo->getId()) === true) {
                $this->em->remove($previo);
            }
        }

        $archivo = new CotizacionFilearchivo();
        $archivo->setFile($file);
        $archivo->setPasajero($pasajero);
        $archivo->setTipoArchivo($tipo);
        $archivo->setNombre([['language' => 'es', 'content' => $tipo->getLabel()]]);
        $archivo->setImageFile($subido);

        $this->em->persist($archivo);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'tipo' => $tipo->value]);
    }
}
