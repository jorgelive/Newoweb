<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Documento\Candidato;
use App\Cotizacion\Documento\GiradorDeEscaneo;
use App\Cotizacion\Documento\ResolutorDeDocumentoSuelto;
use App\Cotizacion\Documento\ValidadorDeManifiesto;
use App\Cotizacion\Documento\ValidadorDeDocumento;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * El panel de resolución de la bóveda: qué documentos no son de nadie y a quién podrían ser.
 *
 * 🔑 **Dos rutas y no una, porque leer y escribir son actos distintos.** El plan se puede pedir
 * las veces que haga falta y no toca nada; resolver escribe, y encima puede **crear una persona**
 * en el manifiesto. Juntarlos en un endpoint que «hace lo que corresponda» es como se acaba
 * creando gente por recargar una pantalla.
 */
final class DocumentosSueltosController extends AbstractController
{
    /**
     * Los documentos sin dueño y sus candidatos. **No escribe nada.**
     *
     * ⚠️ Lee la lectura cacheada del archivo, así que abrir el panel no cuesta llamadas a la IA.
     * Los que nunca se han leído salen sin candidatos y con `leido: false`: primero se pasa la
     * tanda, después se resuelve.
     */
    #[Route(
        '/cotizacion/user/documentos-sueltos/{id}',
        name: 'cotizacion_documentos_sueltos',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para ver los documentos.')]
    public function plan(string $id, EntityManagerInterface $em, ValidadorDeDocumento $validador): Response
    {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));
        if ($file === null) {
            return new JsonResponse(['error' => 'No encontré el expediente.'], Response::HTTP_NOT_FOUND);
        }

        /** @var list<CotizacionFilearchivo> $sueltos */
        $sueltos = $em->getRepository(CotizacionFilearchivo::class)->findBy(['file' => $file, 'pasajero' => null]);

        $filas = [];
        foreach ($sueltos as $archivo) {
            if ($archivo->getTipoArchivo()?->esValidable() !== true) {
                continue;   // un boleto suelto no tiene dueño que adivinar
            }

            $leido = $archivo->getDatosLeidos() !== null ? $validador->lecturaDe($archivo) : null;

            $filas[] = [
                'id' => (string) $archivo->getId(),
                'nombre' => (string) $archivo,
                'tipo' => $archivo->getTipoArchivo()->value,
                'url' => $archivo->getImageUrl(),
                'leido' => $leido !== null,
                'documento' => $leido === null ? null : [
                    'numero' => $leido->numero,
                    'nombre' => trim(($leido->nombres ?? '') . ' ' . ($leido->apellidos ?? '')),
                    'nacimiento' => $leido->nacimiento?->format('Y-m-d'),
                    'vencimiento' => $leido->vencimiento?->format('Y-m-d'),
                    'nacionalidad' => $leido->nacionalidadIso2,
                    'mrz' => $leido->verificadoPorMrz(),
                    'rotacion' => $leido->rotacion,
                ],
                'candidatos' => $leido === null ? [] : array_map(
                    static fn (Candidato $c): array => [
                        'id' => (string) $c->pasajero->getId(),
                        'nombre' => trim(($c->pasajero->getNombre() ?? '') . ' ' . ($c->pasajero->getApellido() ?? '')),
                        'por' => $c->por,
                        'motivo' => $c->motivo,
                        'seguro' => $c->esSeguro(),
                    ],
                    $validador->candidatosPara($archivo, $leido),
                ),
            ];
        }

        return new JsonResponse(['documentos' => $filas]);
    }

    /**
     * Reprocesa a UNA persona: vuelve a cotejar sus documentos con lo que hay guardado ahora.
     *
     * ⚠️ **No relee el documento** —la lectura está cacheada—, así que cuesta cero. Es justo lo que
     * hace falta tras corregir un dato del manifiesto: comprobar si el aviso se fue. Sin esto había
     * que relanzar la tanda del expediente entero, 135 personas para verificar una.
     */
    #[Route(
        '/cotizacion/user/manifiesto/pasajero/{id}/revalidar',
        name: 'cotizacion_pasajero_revalidar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para validar documentos.')]
    public function revalidar(string $id, EntityManagerInterface $em, ValidadorDeManifiesto $validador): Response
    {
        $pasajero = $em->getRepository(CotizacionFilepasajero::class)->find(Uuid::fromString($id));
        if ($pasajero === null) {
            return new JsonResponse(['error' => 'No encontré a esa persona.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['conteo' => $validador->validarPasajero($pasajero)]);
    }

    /**
     * Gira el escaneo, **reescribiendo el fichero**.
     *
     * ⚠️ No guarda un ángulo para aplicarlo al mostrar: eso es el fallo del EXIF que ya costó caro
     * —el huésped veía su pasaporte derecho y al operador le llegaba tumbado—. Ver
     * {@see GiradorDeEscaneo}.
     */
    #[Route(
        '/cotizacion/user/documentos-sueltos/{id}/girar',
        name: 'cotizacion_documento_girar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para editar documentos.')]
    public function girar(
        string $id,
        Request $peticion,
        EntityManagerInterface $em,
        GiradorDeEscaneo $girador,
    ): Response {
        $archivo = $em->getRepository(CotizacionFilearchivo::class)->find(Uuid::fromString($id));
        if ($archivo === null) {
            return new JsonResponse(['error' => 'No encontré el documento.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array{grados?: int|string} $cuerpo */
        $cuerpo = $peticion->toArray();

        try {
            $girador->girar($archivo, (int) ($cuerpo['grados'] ?? 0));
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        // Se devuelve lo justo para que la pantalla se actualice **sin recargar el expediente**:
        // la marca de tiempo rompe la caché de la imagen y la orientación nueva quita el aviso.
        // Antes se recargaba entero y cada giro costaba ~20 s, de los que 16 eran esa recarga.
        return new JsonResponse([
            'girado' => true,
            'actualizado' => $archivo->getUpdatedAt()?->format('U'),
            'bordeSuperior' => $archivo->getDatosLeidos()['bordeSuperior'] ?? 'arriba',
        ]);
    }

    /**
     * Resuelve UNO: lo vincula a alguien, o le crea la ficha.
     *
     * ⚠️ **De uno en uno a propósito.** Un «resolver todos» aplicaría también las corazonadas por
     * nombre, que son las que se equivocan en las familias — y deshacer eso son tantas decisiones
     * como se tomaron de golpe.
     */
    #[Route(
        '/cotizacion/user/documentos-sueltos/{id}/resolver',
        name: 'cotizacion_documentos_sueltos_resolver',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para asignar documentos.')]
    public function resolver(
        string $id,
        Request $peticion,
        EntityManagerInterface $em,
        ResolutorDeDocumentoSuelto $resolutor,
    ): Response {
        $archivo = $em->getRepository(CotizacionFilearchivo::class)->find(Uuid::fromString($id));
        if ($archivo === null) {
            return new JsonResponse(['error' => 'No encontré el documento.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array{accion?: string, pasajeroId?: string} $cuerpo */
        $cuerpo = $peticion->toArray();
        $accion = (string) ($cuerpo['accion'] ?? '');

        try {
            if ($accion === 'crear') {
                $pasajero = $resolutor->crear($archivo);

                return new JsonResponse([
                    'creado' => true,
                    'pasajero' => ['id' => (string) $pasajero->getId(), 'nombre' => trim(($pasajero->getNombre() ?? '') . ' ' . ($pasajero->getApellido() ?? ''))],
                ]);
            }

            if ($accion === 'vincular') {
                $pasajero = $em->getRepository(CotizacionFilepasajero::class)->find(Uuid::fromString((string) ($cuerpo['pasajeroId'] ?? '')));
                if ($pasajero === null) {
                    return new JsonResponse(['error' => 'Esa persona no existe.'], Response::HTTP_BAD_REQUEST);
                }

                $resolutor->vincular($archivo, $pasajero);

                return new JsonResponse(['vinculado' => true]);
            }
        } catch (Throwable $e) {
            // El mensaje de estos servicios está escrito para leerse —«ese archivo ya tiene
            // dueño: recarga la lista»— así que se devuelve tal cual en vez de un 500 mudo.
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['error' => 'Acción desconocida.'], Response::HTTP_BAD_REQUEST);
    }
}
