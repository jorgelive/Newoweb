<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EditorDeIdentidades;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

/**
 * El editor de identificadores de una persona: añadir, retirar, marcar principal, vetar.
 *
 * ── Por qué acciones y no un PATCH de la colección ──────────────────────────
 * Porque cada una tiene reglas propias que hay que poder rechazar con un motivo entendible:
 * añadir un valor que ya es de otro hilo no es un error de validación, es «esto es una fusión y
 * la decide una persona». Un PATCH de colección respondería 500 por violación de unicidad al
 * operador que estaba haciendo justo lo correcto.
 *
 * Las reglas viven en {@see EditorDeIdentidades}: el panel es un cliente más, y el comando de
 * fusión —y algún día una skill— van a querer las mismas.
 *
 * ⚠️ **Retirar no borra.** Además de que el historial de la persona quedaría partido, la fila
 * es lo que impide que el dominio resucite el número en el siguiente recálculo.
 */
#[AsController]
final class IdentidadesDeConversacionController extends AbstractController
{
    public function __construct(
        private readonly EditorDeIdentidades $editor,
        private readonly EntityManagerInterface $em,
    ) {}

    /** POST /conversations/{id}/identidades — `{"tipo": "telefono", "valor": "+51 984 …"}` */
    public function anadir(MessageConversation $data, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'No tienes permiso para editar la conversación.');

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent() ?: '{}', true) ?: [];
        $tipo = IdentidadTipo::tryFrom((string) ($cuerpo['tipo'] ?? ''));

        if ($tipo === null) {
            return $this->error('Tipo de identificador desconocido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $identidad = $this->editor->anadir($data, $tipo, (string) ($cuerpo['valor'] ?? ''));
        } catch (RuntimeException $e) {
            // 409 y no 400: no es que el dato esté mal escrito, es que choca con otro hilo y
            // eso lo resuelve una persona decidiendo si son la misma.
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        $this->em->flush();

        return new JsonResponse(['id' => (string) $identidad->getId(), 'valor' => $identidad->getValor()], Response::HTTP_CREATED);
    }

    /**
     * PATCH /conversations/{id}/identidades/{identidad}
     *
     * Campos opcionales e independientes: `principal`, `bloqueado`, `retirada`. Se aplican en
     * ese orden porque retirar quita la marca de principal, y al revés no.
     */
    public function actualizar(MessageConversation $data, string $identidad, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'No tienes permiso para editar la conversación.');

        if (!Uuid::isValid($identidad)) {
            return $this->error('Identificador inválido.', Response::HTTP_BAD_REQUEST);
        }

        $fila = $this->em->getRepository(MessageIdentidad::class)->find(Uuid::fromString($identidad));

        // Que exista no basta: tiene que ser de ESTA conversación. Sin esta comprobación, el id
        // de la URL permitiría editar el identificador de cualquier otra persona.
        if ($fila === null || $fila->getConversacion() !== $data) {
            return $this->error('Ese identificador no es de esta conversación.', Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent() ?: '{}', true) ?: [];

        try {
            if (array_key_exists('principal', $cuerpo) && $cuerpo['principal'] === true) {
                $this->editor->marcarPrincipal($fila);
            }

            if (array_key_exists('bloqueado', $cuerpo)) {
                $this->editor->bloquear($fila, (bool) $cuerpo['bloqueado']);
            }

            if (array_key_exists('retirada', $cuerpo) && $cuerpo['retirada'] === true) {
                $this->editor->retirar($fila, new DateTimeImmutable());
            }
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        $this->em->flush();

        return new JsonResponse([
            'id' => (string) $fila->getId(),
            'principal' => $fila->isPrincipal(),
            'bloqueado' => $fila->isBloqueado(),
            'retirada' => !$fila->estaViva(),
            'hiloBloqueado' => $data->isWhatsappDisabled(),
        ]);
    }

    private function error(string $mensaje, int $estado): JsonResponse
    {
        return new JsonResponse(['error' => $mensaje], $estado);
    }
}
