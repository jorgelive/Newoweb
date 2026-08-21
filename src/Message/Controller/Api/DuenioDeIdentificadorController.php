<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ¿De quién es ya este teléfono o este correo?
 *
 * ── Para qué ────────────────────────────────────────────────────────────────
 * Para que al CREAR una reserva el operador sepa lo que va a pasar **antes** de guardar. Hoy
 * pasa a ciegas, y son tres desenlaces distintos según a quién pertenezcan los datos:
 *
 * - Nadie los tiene → nace un hilo.
 * - El teléfono ya es de alguien → **la reserva se suma a su conversación**, con su historial.
 * - Teléfono de una persona y correo de otra → manda el teléfono y **el correo se descarta**.
 *
 * El tercero es el que obliga a avisar: el operador teclea un dato y ese dato no se guarda. Pero
 * el segundo también merece verse — es lo que confirma que la reserva va a caer en el hilo bueno.
 *
 * ⚠️ **Normaliza igual que la resolución de hilos.** Se pasa por `IdentidadTipo::normalizar()` y
 * no por una limpieza propia: si el aviso mirara un valor y el guardado otro, diría lo contrario
 * de lo que va a pasar — que es peor que no avisar.
 */
#[AsController]
#[Route('/platform/message/identidades', name: 'app_message_identidad_')]
final class DuenioDeIdentificadorController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/duenio', name: 'duenio', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        $tipo = IdentidadTipo::tryFrom(trim((string) $request->query->get('tipo', '')));
        $valor = trim((string) $request->query->get('valor', ''));

        if ($tipo === null || $valor === '') {
            return new JsonResponse(['duenio' => null]);
        }

        $normalizado = $tipo->normalizar($valor);

        if ($normalizado === '') {
            return new JsonResponse(['duenio' => null]);
        }

        $identidad = $this->em->getRepository(MessageIdentidad::class)
            ->findOneBy(['tipo' => $tipo, 'valor' => $normalizado]);

        $conversacion = $identidad?->getConversacion();

        if ($conversacion === null) {
            return new JsonResponse(['duenio' => null]);
        }

        return new JsonResponse([
            'duenio' => [
                'conversacionId' => (string) $conversacion->getId(),
                'nombre' => $conversacion->getGuestName(),
                // Una identidad retirada sigue resolviendo el historial pero ya no es salida: el
                // panel lo dice con otras palabras, porque la consecuencia es distinta.
                'retirada' => !$identidad->estaViva(),
            ],
        ]);
    }
}
