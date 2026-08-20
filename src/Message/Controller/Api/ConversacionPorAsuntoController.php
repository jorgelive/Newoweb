<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

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
 */
#[AsController]
final class ConversacionPorAsuntoController extends AbstractController
{
    public function __construct(
        private readonly EnlacesDeConversacion $enlaces
    ) {}

    public function __invoke(Request $request): ?MessageConversation
    {
        // El permiso se comprueba aquí además de en la operación: es la misma cautela que
        // documenta `UnreadSummaryController`, y aquí se devuelven nombres de huéspedes.
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        $tipo = trim((string) $request->query->get('contextType', ''));
        $id   = trim((string) $request->query->get('contextId', ''));

        if ($tipo === '' || $id === '') {
            return null;
        }

        return $this->enlaces->hiloTitularDe($tipo, $id);
    }
}
