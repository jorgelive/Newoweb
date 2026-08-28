<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * El hilo de un ASUNTO, resuelto por su enlace titular.
 *
 * ── Por qué no basta con filtrar la colección ───────────────────────────────
 * El front pedía `/conversations?contextType=pms_reserva&contextId=…`, o sea filtraba por la
 * CABECERA del hilo. Eso funcionaba cuando había una conversación por reserva, y dejó de
 * funcionar el día que los hilos se fusionaron por persona: la cabecera del superviviente
 * apunta a UNA de sus reservas, y los hilos absorbidos —archivados y vacíos— conservan la suya.
 *
 * Medido en producción el 20/08/2026: **26 reservas abrían un hilo archivado con 0 mensajes**
 * mientras su conversación real seguía viva al lado. Yael Shifman tiene 42 mensajes y desde
 * tres de sus reservas se veía la pantalla en blanco; una reserva de Lucho Gonez llevaba al
 * hilo de Susan Acuña, con 164.
 *
 * Y no daba error: devolvía una conversación válida, sólo que la equivocada.
 *
 * ── La resolución correcta ──────────────────────────────────────────────────
 * `asunto → enlace titular → hilo`, el mismo camino duradero que usa el backend
 * ({@see EnlacesDeConversacion::hiloTitularDe()}). Sobrevive a la fusión, a que la persona
 * cambie de número y a que tenga varias reservas en el mismo hilo.
 *
 * `204` cuando el asunto todavía no tiene hilo, que es una respuesta normal —una reserva
 * recién creada a la que nadie ha escrito— y el front la trata como «no ofrezcas el chat».
 *
 * ── ⚠️ Devuelve `Response`, NO la entidad (28/08/2026) ──────────────────────
 * Devolvía el `MessageConversation` y confiaba en que API Platform lo serializara. **No lo
 * hacía**: cada llamada moría con `ControllerDoesNotReturnResponseException` —«el controlador
 * debe devolver un Response y devolvió `Proxies\__CG__\…\MessageConversation`»— y el 500 se
 * comía el botón «Editar» de los identificadores, que es de donde sale este endpoint.
 *
 * El fallo era **invisible desde el front**: `chatStore.fetchConversacionPorContexto()` captura
 * la excepción y devuelve `null`, así que se leía como «esta reserva no tiene hilo» en vez de
 * como un error. Sólo el drawer, que no lo captura, lo enseñaba — y con un mensaje genérico.
 *
 * Los otros seis controladores de este módulo ya devolvían `JsonResponse`; éste era el único
 * que no, y el único que fallaba. Se normaliza a mano con los mismos grupos que declara el
 * recurso para que el cuerpo sea **idéntico** al que se esperaba: `chatStore` comprueba `@id`
 * y `reservasStore` lee `id`.
 */
#[AsController]
final class ConversacionPorAsuntoController extends AbstractController
{
    public function __construct(
        private readonly EnlacesDeConversacion $enlaces,
        private readonly NormalizerInterface $normalizador,
    ) {}

    public function __invoke(Request $request): Response
    {
        // El permiso se comprueba aquí además de en la operación: es la misma cautela que
        // documenta `UnreadSummaryController`, y aquí se devuelven nombres de huéspedes.
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        $tipo = trim((string) $request->query->get('contextType', ''));
        $id   = trim((string) $request->query->get('contextId', ''));

        if ($tipo === '' || $id === '') {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $conversacion = $this->enlaces->hiloTitularDe($tipo, $id);

        if ($conversacion === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // `jsonld` y el grupo `conversation:read`: los mismos que declara el `#[ApiResource]`,
        // para que la respuesta no dependa de quién la construye. Con otro formato se perdería
        // el `@id`, que es lo que `chatStore` usa para saber si vino una conversación.
        return new JsonResponse($this->normalizador->normalize(
            $conversacion,
            'jsonld',
            ['groups' => ['conversation:read']],
        ));
    }
}
