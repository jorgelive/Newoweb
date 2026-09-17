<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Publico;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use App\Cotizacion\Documento\Discrepancia;
use App\Cotizacion\Documento\QueLePedimosAlPasajero;
use App\Cotizacion\Documento\ValidadorDeDocumento;
use App\Cotizacion\Documento\ValidadorDeEticket;
use App\Cotizacion\Documento\ValidadorDeManifiesto;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Psr\Log\LoggerInterface;
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
        private readonly ValidadorDeDocumento $documento,
        private readonly ValidadorDeManifiesto $manifiesto,
        private readonly ValidadorDeEticket $eticket,
        private readonly LoggerInterface $logger,
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

        // 🔥 **«Qué se pide» BLOQUEA, no sólo esconde.** Hasta ahora esta guarda miraba únicamente
        // si el tipo lo sube el pasajero, así que quitar el pasaporte de la lista hacía desaparecer
        // la casilla en la app —`documentosPedidos()` filtra el catálogo— pero **el endpoint lo
        // seguía aceptando**: una petición repetida, un botón de atrás, una pestaña vieja con la
        // lista de antes, y entraba igual.
        //
        // Y hay un momento concreto en que esa diferencia importa: a dos días de volar se cierran
        // los documentos de identidad —ya están revisados y el manifiesto cuadra— y se deja abierto
        // sólo el trámite migratorio. Un pasaporte que entre entonces vuelve a poner en duda un
        // veredicto que costó revisar, y nadie se entera hasta que el chip cambia solo.
        //
        // ⚠️ Esto acota **al pasajero**, no al operador: por `util` se sigue pudiendo subir
        // cualquier cosa, que es lo que permite arreglar un caso raro sin reabrir la lista para los
        // 134.
        if (!in_array($tipo->value, $file->getDocumentosPedidos(), true)) {
            return new JsonResponse([
                'error' => sprintf('Ahora mismo no se pide %s. Si te hace falta, escríbenos.', $tipo->getLabel()),
            ], Response::HTTP_CONFLICT);
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

        // 🔥 **Lo ya VERIFICADO no se reemplaza desde aquí.**
        //
        // Subir por esta vía **borra el anterior** —«la segunda foto es la buena»—, y ahí estaba el
        // agujero: quien tuviera el enlace podía sustituir un pasaporte ya validado por otra cosa y
        // **el original desaparecía**. El control lo acabaría marcando —el documento nuevo se lee y
        // se coteja igual— pero la prueba que se había verificado ya no está, y eso no se deshace.
        //
        // ⚠️ **Y no se bloquea por el archivo, se bloquea por el VEREDICTO**, que es lo que dice si
        // alguien ya lo dio por bueno: para un DNI o un pasaporte vive en la identificación que
        // respalda ({@see ArchivoTipoEnum::respaldaA()}/{@see ArchivoTipoEnum::verificaA()}); para
        // el E-Ticket, en el propio archivo. Mirar sólo el archivo dejaría fuera justo los
        // documentos de identidad, que son los que importan.
        //
        // ⚠️ Esto acota **al pasajero**. Por `util` se sigue pudiendo reemplazar cualquier cosa: si
        // un documento verificado hay que cambiarlo de verdad, lo hace alguien que sabe qué está
        // pisando, y queda su rastro.
        if ($pasajero->tieneVerificado($tipo)) {
            return new JsonResponse([
                'error' => sprintf(
                    'Tu %s ya está revisado y no se puede cambiar desde aquí. Si hay que corregirlo, escríbenos.',
                    $tipo->getLabel(),
                ),
            ], Response::HTTP_CONFLICT);
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

        return new JsonResponse(['ok' => true, 'tipo' => $tipo->value, ...$this->revisarAlSubir($file, $pasajero, $archivo, $tipo)]);
    }

    /**
     * Lee y controla el documento **en el momento de subirlo**, con el pasajero todavía delante.
     *
     * 🔥 **Antes el control corría días después, desde `util`.** El pasajero subía una foto
     * cortada, la app le decía «recibido», y alguien del equipo tenía que perseguirle por WhatsApp
     * para pedirle otra — a veces con el vuelo encima. Con el móvil todavía en la mano, repetir la
     * foto son diez segundos. Qué se le pide y qué no lo decide {@see QueLePedimosAlPasajero}.
     *
     * 🔑 **No cuesta una lectura de más: la adelanta.** La lectura se guarda en el archivo y la
     * tanda de `util` la reutiliza gratis, así que lo que antes se pagaba al pulsar «Validar
     * manifiesto» ahora se paga aquí. El veredicto del equipo también queda puesto en el acto.
     *
     * ⚠️ **Nunca tumba la subida.** El documento YA está guardado cuando esto empieza. Si la IA está
     * caída o tarda de más, se registra y el pasajero ve «recibido» como siempre: un fallo nuestro
     * no puede convertirse en «tu documento no sirve».
     *
     * ⚠️ **Y por lo mismo, `null` en la lectura NO se le cuenta.** `lecturaDe()` devuelve `null`
     * sólo cuando falla el proveedor o el disco; un documento que el modelo leyó y no sirve vuelve
     * CON datos. Decirle «no conseguimos leer tu pasaporte» porque Google devolvió un 503 sería
     * culparle de nuestra caída. Se olvida la lectura fallida para que la tanda la reintente.
     *
     * @return array{revisado: bool, pideOtro: list<string>}
     */
    private function revisarAlSubir(
        CotizacionFile $file,
        CotizacionFilepasajero $pasajero,
        CotizacionFilearchivo $archivo,
        ArchivoTipoEnum $tipo,
    ): array {
        $sinRevisar = ['revisado' => false, 'pideOtro' => []];

        try {
            if (in_array($tipo, [ArchivoTipoEnum::PASAPORTE, ArchivoTipoEnum::DNI_ANVERSO, ArchivoTipoEnum::DNI_REVERSO], true)) {
                $leido = $this->documento->lecturaDe($archivo);

                if ($leido === null) {
                    return $this->fallaNuestra($archivo, $sinRevisar);
                }

                // El veredicto del equipo, puesto ya: quien abra `util` lo ve sin pulsar nada.
                // ⚠️ Sólo el de ESTE documento: `validarPasajero()` leía los demás escaneos de la
                // persona y convertía una subida en tres lecturas. Ver `validarDocumentoDe()`.
                $numero = $tipo->respaldaA() ?? $tipo->verificaA();
                if ($numero !== null) {
                    $this->manifiesto->validarDocumentoDe($pasajero, $numero);
                }

                return ['revisado' => true, 'pideOtro' => QueLePedimosAlPasajero::delDocumento($tipo, $leido)];
            }

            if ($tipo === ArchivoTipoEnum::ETICKET) {
                $pais = $file->getPaisDeControl();

                if ($pais === null) {
                    return $sinRevisar;
                }

                $cotejo = $this->eticket->validar($archivo, $pais);
                $leido = $this->eticket->lecturaDe($archivo);   // ya leído arriba: gratis

                if ($leido === null) {
                    return $this->fallaNuestra($archivo, $sinRevisar);
                }

                if ($cotejo !== null) {
                    $archivo->rejuzgar(
                        $cotejo->estado,
                        array_map(static fn (Discrepancia $d): array => $d->aJson(), $cotejo->discrepancias),
                        $cotejo->notas,
                    );
                    $this->em->flush();
                }

                return ['revisado' => true, 'pideOtro' => QueLePedimosAlPasajero::delEticket($leido)];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo revisar un documento al subirlo desde pax', [
                'archivo' => (string) $archivo->getId(),
                'tipo' => $tipo->value,
                'error' => $e->getMessage(),
            ]);
        }

        return $sinRevisar;
    }

    /**
     * La IA o el disco fallaron: no es culpa del pasajero y no se le dice nada.
     *
     * ⚠️ Se **olvida** la lectura fallida en vez de dejarla registrada: `registrarLectura(null)`
     * deja `leidoEn` puesto, que la tanda interpreta como «ya se intentó y no tiene arreglo», y ese
     * documento no se volvería a mirar sin `--reintentar`.
     *
     * @param array{revisado: bool, pideOtro: list<string>} $sinRevisar
     *
     * @return array{revisado: bool, pideOtro: list<string>}
     */
    private function fallaNuestra(CotizacionFilearchivo $archivo, array $sinRevisar): array
    {
        $this->logger->warning('La lectura falló al subir desde pax; queda para la tanda', [
            'archivo' => (string) $archivo->getId(),
            'error' => $archivo->getLecturaError(),
        ]);

        $archivo->olvidarLectura();
        $this->em->flush();

        return $sinRevisar;
    }

}
