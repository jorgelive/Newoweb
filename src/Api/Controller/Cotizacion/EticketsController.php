<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Documento\CotejoDeEticket;
use App\Cotizacion\Documento\Discrepancia;
use App\Cotizacion\Documento\ValidadorDeEticket;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\PaisDeControlEnum;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
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
 * El botón de «controlar los E-Ticket», en tandas cortas y con respuesta ligera.
 *
 * ── 🔥 Por qué NO devuelve el expediente ────────────────────────────────────
 * El botón de identidad ({@see \App\Cotizacion\ApiPlatform\State\ValidarManifiestoProcessor})
 * devuelve el expediente entero «para que el front repinte sin una segunda vuelta». Con 134
 * pasajeros y ~600 archivos eso son **~2,1 MB por pulsación** —medido: 10,8 KB por pasajero, de los
 * que 9,9 KB son las pertenencias, que embeben el grupo entero 1 712 veces—. Aquí se devuelven sólo
 * los veredictos: **~40 KB**, y el front parchea en sitio.
 *
 * No es una idea nueva: {@see DocumentosSueltosController::revalidar()} ya se construyó exactamente
 * por esto, y su cabecera cuenta el síntoma —«pulsabas, no pasaba nada, y la validación aparecía
 * cuando terminaba de recargarse toda la página»—.
 *
 * ── 🔥 Y por qué en TANDAS ──────────────────────────────────────────────────
 * Leer un documento con el modelo tarda **~10 s** —medido sobre las 97 lecturas reales: entre 5 y 10
 * por minuto— y un expediente tiene ~100. Una sola llamada que las intente todas tarda un cuarto de
 * hora y nadie espera eso delante de un botón.
 *
 * Así que cada llamada hace dos cosas muy distintas:
 *
 * ```
 *   re-juzgar TODO lo ya leído   → gratis, PHP puro, milisegundos
 *   leer como mucho `limite`     → ~3,5 s cada una, y se para
 * ```
 *
 * y devuelve `pendientesDeLeer` para que el cliente vuelva a llamar. Cada petición **persiste lo
 * suyo**, así que es reanudable: si se corta, lo pagado se queda pagado.
 *
 * ⚠️ **Re-juzgar todo en cada pasada no es un capricho de rendimiento: es lo que mantiene los
 * veredictos vivos.** Un E-Ticket se cotejó contra unos vuelos y un pasaporte, y nada invalida su
 * veredicto cuando alguien cambia un vuelo o sube un escaneo nuevo. Un listener por cada fuente
 * sería otro sitio del que nadie se acuerda; re-juzgar cuesta milisegundos y no se olvida.
 *
 * ── Por qué no una cola ─────────────────────────────────────────────────────
 * ⚠️ Messenger + Mercure es la arquitectura correcta para trabajos de minutos, y las piezas están.
 * Pero añade una entidad de tarea con estado, un canal de entrega y **una dependencia del worker**
 * —que hay que reiniciar en cada despliegue; un worker parado es un botón que no hace nada y no
 * dice por qué— para un control que se corre un puñado de veces por expediente. Si algún día son
 * mil documentos, el bucle de lectura se muda a un handler y **este contrato no cambia**.
 */
final class EticketsController extends AbstractController
{
    /**
     * Cuántas lecturas caben en una petición que alguien esté mirando.
     *
     * 🔥 **Eran 15, calculadas sobre una estimación mía de 3,5 s por lectura que resultó falsa.**
     * Medido sobre las 97 lecturas reales: entre **5 y 10 por minuto**, o sea ~10 s cada una. Las
     * tandas tardaban **entre dos y cuatro minutos** —comprobado en los tiempos del log— y al
     * usuario le saltaba el error del cliente antes de que volviera la primera.
     *
     * ⚠️ **Lo que importa no es el total, es cada cuánto se ve avanzar.** El trabajo tarda lo que
     * tarda; lo que se elige aquí es el tamaño del trozo tras el cual la pantalla se mueve y el
     * cliente puede reintentar sin perder nada. Cinco son ~50 s: suficientemente corto para que se
     * note y para que un corte de red cueste poco.
     *
     * ⚠️ Y no se puede subir «porque el servidor aguanta»: aguanta —nginx corta a 300 s y el
     * `max_execution_time` de PHP no cuenta las esperas de red—, pero quien está delante, no.
     */
    private const LIMITE_POR_TANDA = 5;

    #[Route(
        '/cotizacion/user/manifiesto/{id}/etickets/validar',
        name: 'cotizacion_etickets_validar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para validar documentos.')]
    public function validar(
        string $id,
        Request $request,
        EntityManagerInterface $em,
        ValidadorDeEticket $validador,
    ): Response {
        $file = $em->getRepository(CotizacionFile::class)->find(Uuid::fromString($id));

        if ($file === null) {
            return new JsonResponse(['error' => 'No encontré ese expediente.'], Response::HTTP_NOT_FOUND);
        }

        $pais = $this->paisDe($file);

        if ($pais === null) {
            return new JsonResponse(
                ['error' => 'Este expediente no pide ningún trámite migratorio.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $limite = max(1, min(self::LIMITE_POR_TANDA, $request->query->getInt('limite', self::LIMITE_POR_TANDA)));

        $archivos = [];
        $conteo = [];
        $leidos = 0;
        $pendientes = 0;

        foreach ($file->getFilearchivos() as $archivo) {
            if ($archivo->getTipoArchivo() !== ArchivoTipoEnum::ETICKET || $archivo->getPasajero() === null) {
                continue;
            }

            $sinLeer = $archivo->getDatosLeidos() === null && !$archivo->seIntentoLeer();

            // Lo ya leído se re-juzga siempre; lo que no, sólo mientras quede cupo. Y lo que se
            // queda fuera se CUENTA, que es lo que permite al cliente saber si tiene que volver.
            if ($sinLeer && $leidos >= $limite) {
                ++$pendientes;
                continue;
            }

            if ($sinLeer) {
                ++$leidos;
            }

            $cotejo = $validador->validar($archivo, $pais);

            if ($cotejo === null) {
                continue;
            }

            $nuevas = array_map(static fn (Discrepancia $d): array => $d->aJson(), $cotejo->discrepancias);

            // 🔑 **Un sello humano sobrevive al re-juicio, pero sólo mientras el desajuste sea EL
            // MISMO.** Re-juzgar en cada pasada es lo que mantiene vivos los veredictos, y aplicado
            // a ciegas borraría el «lo he mirado y está bien» en el clic siguiente: el botón de
            // aceptar no serviría de nada. Pero conservarlo pase lo que pase es peor —taparía un
            // problema nuevo con la revisión de uno viejo—.
            //
            // Lo que alguien aceptó fue **este** desacuerdo. Si cambia, se reabre solo.
            if ($archivo->getEstadoValidacion() === ValidacionIdentificacionEnum::CONFIRMADO
                && $nuevas == $archivo->getDiscrepancias()) {
                $conteo[ValidacionIdentificacionEnum::CONFIRMADO->value]
                    = ($conteo[ValidacionIdentificacionEnum::CONFIRMADO->value] ?? 0) + 1;
                $archivos[] = self::veredictoDe($archivo);
                continue;
            }

            $archivo->registrarValidacion($cotejo->estado, $nuevas, $cotejo->notas);

            $conteo[$cotejo->estado->value] = ($conteo[$cotejo->estado->value] ?? 0) + 1;
            $archivos[] = self::veredictoDe($archivo);
        }

        // Las LECTURAS ya las persistió el validador una a una —son dinero gastado y no pueden
        // depender de llegar vivo al final—; esto guarda los veredictos, que son baratos de rehacer.
        $em->flush();

        return new JsonResponse([
            'pendientesDeLeer' => $pendientes,
            'leidosAhora' => $leidos,
            'conteo' => $conteo,
            'archivos' => $archivos,
        ]);
    }

    /**
     * «Lo he mirado y está bien»: cierra a mano un trámite observado.
     *
     * 🔥 **Sin esto, un observado se queda en ámbar para siempre.** El control puede tener razón en
     * lo que señala y aun así no haber nada que corregir: el trámite trae un segundo nombre que al
     * manifiesto le falta, o la referencia era el manifiesto y el equivocado era él. La única salida
     * era volver a subir el mismo PDF para que se releyera, que no arregla nada y cuesta una lectura.
     *
     * Es el espejo de `DocumentosSueltosController::confirmar()`, que existe por lo mismo.
     *
     * ⚠️ **Se anota quién y cuándo en las notas, no se borra el motivo.** Un observado cerrado sin
     * rastro es indistinguible de uno que nunca saltó, y la siguiente persona que lo mire no sabría
     * si se revisó o si el control nunca llegó. La discrepancia se queda escrita: lo que cambia es
     * que alguien se hizo responsable.
     *
     * ⚠️ Y lo cierra **hasta que cambie algo**: el control re-juzga en cada pasada, así que si el
     * trámite se vuelve a leer o cambian los vuelos, el veredicto se recalcula y el sello se pierde.
     * Es lo correcto — lo que se aceptó fue este desajuste, no todos los futuros.
     */
    #[Route(
        '/cotizacion/user/etickets/{id}/aceptar',
        name: 'cotizacion_eticket_aceptar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para validar documentos.')]
    public function aceptar(string $id, EntityManagerInterface $em): Response
    {
        $archivo = $em->getRepository(CotizacionFilearchivo::class)->find(Uuid::fromString($id));

        if ($archivo === null || $archivo->getTipoArchivo() !== ArchivoTipoEnum::ETICKET) {
            return new JsonResponse(['error' => 'No encontré ese E-Ticket.'], Response::HTTP_NOT_FOUND);
        }

        $quien = $this->getUser()?->getUserIdentifier() ?? 'alguien';

        $archivo->registrarValidacion(
            ValidacionIdentificacionEnum::CONFIRMADO,
            $archivo->getDiscrepancias(),
            [...$archivo->getNotasValidacion(), sprintf('revisado y aceptado por %s', $quien)],
        );

        $em->flush();

        return new JsonResponse(['archivos' => [self::veredictoDe($archivo)]]);
    }

    /**
     * Qué trámite pide este expediente. **No viene del cliente.**
     *
     * ⚠️ Dejar que lo mande el front sería dejarle elegir contra qué país se coteja, que es una
     * decisión del expediente: lo dice `documentosPedidos`. Hoy la relación es una y directa; el día
     * que haya dos trámites, esto crece aquí y el front no se entera.
     */
    private function paisDe(CotizacionFile $file): ?PaisDeControlEnum
    {
        return in_array(ArchivoTipoEnum::ETICKET->value, $file->getDocumentosPedidos(), true)
            ? PaisDeControlEnum::REPUBLICA_DOMINICANA
            : null;
    }

    /**
     * Lo justo para repintar una tarjeta: ~300 bytes contra los 10,8 KB que pesa un pasajero entero.
     *
     * ⚠️ Va `lecturaError` aunque no sea un veredicto: es la diferencia entre «no ha mandado nada» y
     * «mandó algo que no se puede abrir», y sin él la segunda se lee como la primera.
     *
     * @return array<string, mixed>
     */
    private static function veredictoDe(CotizacionFilearchivo $archivo): array
    {
        return [
            'id' => (string) $archivo->getId(),
            'pasajeroId' => (string) $archivo->getPasajero()?->getId(),
            'tipoArchivo' => $archivo->getTipoArchivo()?->value,
            'estadoValidacion' => $archivo->getEstadoValidacion()->value,
            'discrepancias' => $archivo->getDiscrepancias(),
            'notasValidacion' => $archivo->getNotasValidacion(),
            'validadoEn' => $archivo->getValidadoEn()?->format(DATE_ATOM),
            'leidoEn' => $archivo->getLeidoEn()?->format(DATE_ATOM),
            'lecturaError' => $archivo->getLecturaError(),
        ];
    }
}
