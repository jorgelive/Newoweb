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
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
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

        $conteo = $validador->validarPasajero($pasajero);

        return new JsonResponse(['conteo' => $conteo, 'identificaciones' => self::veredictosDe($pasajero)]);
    }

    /**
     * «El documento tiene razón»: copia al manifiesto el valor que dice el escaneo.
     *
     * 🔥 **Porque casi siempre la tiene.** El manifiesto se tecleó a mano y el escaneo lo leyó una
     * máquina de un documento real —con MRZ, además, verificada con dígitos de control—. Ante
     * «vencimiento: doc 2036-08-11 · guardado 2026-07-10», el dedazo está casi siempre en el lado
     * guardado, y hasta ahora la única salida era **copiar la fecha a mano** desde una pastilla,
     * reescribirla en el formato del formulario y guardar. Tres pasos para aceptar lo que el sistema
     * ya sabía.
     *
     * ⚠️ **El valor NO viene del cliente, se relee del documento.** El cuerpo dice qué CAMPO se
     * acepta, no qué valor: si viniera el valor, este endpoint sería «escribe lo que quieras en el
     * manifiesto» con un nombre tranquilizador.
     *
     * ⚠️ **Sólo los campos que son de la identificación.** El nombre y el nacimiento son del
     * pasajero, no de su documento, y escribirlos desde aquí metería a este endpoint a decidir sobre
     * una entidad que no es la suya.
     *
     * ⚠️ Y **revalida después**, para que el veredicto lo escriba el mismo camino que todos los
     * demás. Marcar la ficha como copiada del escaneo es lo que impide que luego se coteje consigo
     * misma y salga «validada» por haberse creído a sí misma.
     */
    #[Route(
        '/cotizacion/user/identificaciones/{id}/usar-del-documento',
        name: 'cotizacion_identificacion_usar_del_documento',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para editar documentos.')]
    public function usarDelDocumento(
        string $id,
        Request $request,
        EntityManagerInterface $em,
        ValidadorDeDocumento $lector,
        ValidadorDeManifiesto $validador,
    ): Response {
        $identificacion = $em->getRepository(CotizacionPasajeroIdentificacion::class)->find(Uuid::fromString($id));

        if ($identificacion === null) {
            return new JsonResponse(['error' => 'No encontré ese documento.'], Response::HTTP_NOT_FOUND);
        }

        $cuerpo = json_decode((string) $request->getContent(), true);
        $campo = is_array($cuerpo) && is_string($cuerpo['campo'] ?? null) ? $cuerpo['campo'] : '';

        if (!in_array($campo, ['número', 'vencimiento'], true)) {
            return new JsonResponse(
                ['error' => 'Sólo se puede aceptar del documento el número y el vencimiento.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $escaneo = $identificacion->getValidadoCon();
        $leido = $escaneo !== null ? $lector->lecturaDe($escaneo) : null;

        if ($leido === null) {
            return new JsonResponse(
                ['error' => 'No hay una lectura del escaneo con la que rellenar esto.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($campo === 'número') {
            if ($leido->numero === null || trim($leido->numero) === '') {
                return new JsonResponse(['error' => 'El escaneo no trae número.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $identificacion->setNumero(trim($leido->numero));
        }

        if ($campo === 'vencimiento') {
            if ($leido->vencimiento === null) {
                return new JsonResponse(['error' => 'El escaneo no trae vencimiento.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $identificacion->setVencimiento($leido->vencimiento);
        }

        // 🔥 **Sólo el NÚMERO marca la ficha como copiada del escaneo.**
        //
        // La bandera significa una cosa muy concreta —«el número y el nombre salieron de esta
        // foto»— y tiene una consecuencia: `Cotejo` deja de poder cotejarla, porque compararla
        // contra el escaneo sería compararla consigo misma, y cae a «hace falta que alguien la
        // confirme».
        //
        // Marcarla al copiar el **vencimiento** era falso y se veía: tras corregir una fecha, la
        // ficha decía «se creó copiando el escaneo» —que no es lo que pasó— y pedía una
        // confirmación que no hacía falta. El número y el nombre seguían siendo los del manifiesto,
        // tecleados a mano, así que el cotejo seguía siendo una comprobación independiente. El
        // vencimiento no entra en el cotejo.
        if ($campo === 'número') {
            $identificacion->marcarCopiadaDelEscaneo();
        }

        $pasajero = $identificacion->getPasajero();

        if ($pasajero !== null) {
            $validador->validarPasajero($pasajero);
        }

        $em->flush();

        return new JsonResponse([
            'identificaciones' => $pasajero !== null ? self::veredictosDe($pasajero) : [],
        ]);
    }

    /**
     * Los veredictos de esa persona, para que la pantalla los pinte **sin recargar el expediente**.
     *
     * 🔥 **Reprocesar a una persona costaba traerse las 132.** El endpoint sólo devolvía un conteo,
     * así que el front no tenía con qué repintar y recargaba el expediente entero: 717 KB de media
     * y hasta 4,3 MB, con picos de 8 s de servidor — para cambiar una pastilla. Se veía: pulsabas,
     * no pasaba nada, y la validación aparecía cuando terminaba de recargarse toda la página.
     *
     * ⚠️ Se devuelven **todas** las de la persona, no sólo la tocada: validar a alguien puede
     * crear identificaciones nuevas ({@see ValidadorDeManifiesto::adoptarEscaneosSinFicha()}), y
     * devolviendo sólo una, ésas no aparecerían hasta la siguiente recarga.
     *
     * @return list<array<string, mixed>>
     */
    private static function veredictosDe(CotizacionFilepasajero $pasajero): array
    {
        $filas = [];
        foreach ($pasajero->getIdentificaciones() as $i) {
            $filas[] = [
                'id' => (string) $i->getId(),
                'tipo' => $i->getTipo()?->value,
                'numero' => $i->getNumero(),
                'vencimiento' => $i->getVencimiento()?->format('Y-m-d'),
                'estadoValidacion' => $i->getEstadoValidacion()->value,
                'discrepancias' => $i->getDiscrepancias(),
                'notasValidacion' => $i->getNotasValidacion(),
                'copiadaDelEscaneo' => $i->isCopiadaDelEscaneo(),
                'confirmadaEn' => $i->getConfirmadaEn()?->format(DATE_ATOM),
                'confirmadaPor' => $i->getConfirmadaPor(),
                // ⚠️ **Hay algo que mirar**, que es distinto de que la ficha venga del escaneo. Sin
                // esto, la pantalla no podía ofrecer «lo he mirado» a un pasaporte cuya MRZ no
                // cuadra —no hay discrepancia, hay una banda ilegible— y ese documento se quedaba
                // observado para siempre. Firmar lo que NO se puede mirar sí sería malo; por eso se
                // manda el hecho y lo decide el servidor, no una deducción del front.
                // ⚠️ **De la ENTIDAD, no recalculados aquí.** Es la misma regla que aplica el
                // guarda de `confirmar()` y la que viaja en el payload del expediente: con la
                // condición escrita en tres sitios, el día que cambie uno el botón ofrecería algo
                // que el endpoint rechaza.
                'tieneEscaneo' => $i->getTieneEscaneo(),
                'sePuedeConfirmar' => $i->getSePuedeConfirmar(),
            ];
        }

        return $filas;
    }

    /**
     * «Lo he mirado y está bien»: el respaldo humano de una ficha que salió del propio escaneo.
     *
     * 🔥 **Es la mitad que faltaba de una regla que sí era correcta.** Un número copiado del
     * escaneo no puede cotejarse contra ese escaneo, así que quedaba `observado` pidiendo «que
     * alguien la confirme» — y no había dónde. El único modo de apagarlo era cambiar el número a
     * otro, guardar, volver a poner el bueno y guardar: dos escrituras falsas de peaje, tras las
     * cuales el sistema validaba exactamente lo que se negaba a aceptar antes.
     *
     * ⚠️ **Confirma UNA identificación, no la persona.** Alguien puede tener el pasaporte mirado y
     * el DNI no; confirmar «a la persona» sellaría de paso documentos que nadie ha abierto.
     *
     * ⚠️ Y **revalida después**, para que el sello lo escriba el mismo camino que todos los demás.
     * Escribirlo aquí a mano sería un segundo sitio donde se decide un veredicto.
     */
    #[Route(
        '/cotizacion/user/manifiesto/identificacion/{id}/confirmar',
        name: 'cotizacion_identificacion_confirmar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para validar documentos.')]
    public function confirmar(
        string $id,
        EntityManagerInterface $em,
        ValidadorDeManifiesto $validador,
    ): Response {
        $identificacion = $em->getRepository(CotizacionPasajeroIdentificacion::class)->find(Uuid::fromString($id));
        if ($identificacion === null) {
            return new JsonResponse(['error' => 'No encontré ese documento.'], Response::HTTP_NOT_FOUND);
        }

        $pasajero = $identificacion->getPasajero();
        if ($pasajero === null) {
            return new JsonResponse(['error' => 'Ese documento no cuelga de nadie.'], Response::HTTP_CONFLICT);
        }

        // 🔥 **Un documento vencido no se puede dar por bueno mirándolo, y esto faltaba.** El botón
        // salía encima de un DNI caducado y, al pulsarlo, `Cotejo` lo dejaba igual —la nota de
        // vencido bloquea el sello— pero **el «confirmada por X» sí se escribía**: una firma humana
        // guardada sobre un documento que en el mostrador no vale, y una pastilla que no cambiaba.
        //
        // El resto de avisos los levanta alguien que abre el escaneo y comprueba los datos. El
        // vencimiento, no: por mucho que se mire, sigue vencido.
        if ($identificacion->estaVencida()) {
            return new JsonResponse(
                ['error' => 'Está vencido: eso no se arregla mirándolo. Hace falta el documento nuevo.'],
                Response::HTTP_CONFLICT,
            );
        }

        $identificacion->confirmarAMano($this->getUser()?->getUserIdentifier() ?? 'desconocido');
        $em->flush();

        $conteo = $validador->validarPasajero($pasajero);

        return new JsonResponse(['conteo' => $conteo, 'identificaciones' => self::veredictosDe($pasajero)]);
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
            'rotacionPendiente' => $archivo->getRotacionPendiente(),
        ]);
    }

    /**
     * Lee UN documento suelto y devuelve lo que dice, con sus candidatos.
     *
     * 🔥 **Sin esto, un documento sin dueño era un callejón sin salida.** La tanda de validación
     * recorre `pasajeros → identificaciones → su escaneo`, así que **nunca toca un archivo sin
     * dueño**; y el panel sólo ofrece acciones sobre lo ya leído. Resultado: subías una foto sin
     * asignar, el panel la listaba diciendo «pasa antes Validar contra los escaneos» — y esa tanda
     * no iba a leerla jamás. La instrucción no sólo no ayudaba: mandaba a un sitio equivocado.
     *
     * ⚠️ **De uno en uno y a petición**, no al abrir el panel. Leer cuesta ~$0,0016 y 3,5 s; con
     * cincuenta sueltos, abrir el panel dispararía cincuenta llamadas y tres minutos de espera sin
     * que nadie lo haya pedido.
     */
    #[Route(
        '/cotizacion/user/documentos-sueltos/{id}/leer',
        name: 'cotizacion_documento_suelto_leer',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para leer documentos.')]
    public function leer(
        string $id,
        EntityManagerInterface $em,
        ValidadorDeDocumento $validador,
    ): Response {
        $archivo = $em->getRepository(CotizacionFilearchivo::class)->find(Uuid::fromString($id));
        if ($archivo === null) {
            return new JsonResponse(['error' => 'No encontré el documento.'], Response::HTTP_NOT_FOUND);
        }

        $leido = $validador->lecturaDe($archivo);
        // `lecturaDe()` deja la lectura puesta en la entidad pero NO guarda: quien orquesta decide
        // cuándo. Aquí es ahora, o la llamada a la IA se pagaría otra vez en la siguiente vuelta.
        $em->flush();

        if ($leido === null) {
            return new JsonResponse(['error' => $archivo->getLecturaError() ?? 'no se pudo leer'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['leido' => true]);
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
