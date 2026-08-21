<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\AperturaDeHilo;
use App\Security\Roles;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * «Escríbele a éste»: abre el hilo de un asunto que todavía no lo tiene.
 *
 * ── Por qué es un POST y no un parámetro del GET ────────────────────────────
 * Porque **crea**. `GET /conversations/por-asunto` responde `204` cuando el asunto no tiene
 * hilo, y añadirle un `?crear=1` metería un efecto de lado en un verbo que no lo admite: lo
 * dispararía cualquier recarga, cualquier precarga del navegador y cualquier reintento
 * automático. Un hilo se abre porque alguien lo decide.
 *
 * ── Idempotente igual ───────────────────────────────────────────────────────
 * Si el asunto ya tiene hilo, devuelve **ese**. Por dentro es `upsertFromContext()`, que
 * resuelve por enlace titular, por identidad y por la llave legada antes de crear nada. Así que
 * el panel puede llamarlo sin comprobar antes, y dos operadores pulsando a la vez no abren dos.
 *
 * Devuelve `201` cuando el hilo acaba de nacer y `200` cuando ya estaba, para que el panel pueda
 * decir «se abrió la conversación» en vez de «aquí la tienes» — la diferencia importa cuando el
 * operador creía que no existía.
 *
 * ── Los errores llevan el motivo escrito ────────────────────────────────────
 * `409` con el texto tal cual: «no hay ni teléfono ni correo», «ese asunto ya no existe»,
 * «todavía no se pueden abrir conversaciones de X». El panel lo enseña sin traducir, porque el
 * dominio sabe decirlo mejor que un código.
 */
#[AsController]
final class AbrirConversacionController extends AbstractController
{
    public function __construct(private readonly AperturaDeHilo $apertura)
    {
    }

    public function __invoke(Request $request): Response
    {
        // Abrir un hilo es escribir, no mirar: no basta con MENSAJES_SHOW.
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'No tienes permiso para abrir conversaciones.');

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent() ?: '{}', true) ?: [];

        $tipo = trim((string) ($cuerpo['contextType'] ?? ''));
        $id = trim((string) ($cuerpo['contextId'] ?? ''));

        if ($tipo === '' || $id === '') {
            return $this->json(['error' => 'Falta el asunto: `contextType` y `contextId`.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $hilo = $this->apertura->abrir($tipo, $id);
        } catch (RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(
            $hilo,
            $this->recienNacido($hilo) ? Response::HTTP_CREATED : Response::HTTP_OK,
            [],
            ['groups' => ['conversation:read']]
        );
    }

    /**
     * ¿Acaba de nacer, o ya estaba?
     *
     * Se mira la marca de tiempo y no un booleano devuelto por el servicio a propósito: la
     * apertura es idempotente y no tiene por qué llevar la cuenta de quién la creó. Dos segundos
     * de margen cubren el flush y la serialización sin llegar a confundir un hilo de ayer.
     */
    private function recienNacido(MessageConversation $hilo): bool
    {
        $creado = $hilo->getCreatedAt();

        return $creado !== null && (time() - $creado->getTimestamp()) <= 2;
    }
}
