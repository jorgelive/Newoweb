<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Contract\ConversationMilestoneInterface;
use App\Message\Contract\ConversacionEnlaceInterface;
use DateTimeImmutable;
use Exception;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Los ASUNTOS que cuelgan de un hilo: sus reservas, sus expedientes de viaje.
 *
 * ── Por qué hace falta en el panel ──────────────────────────────────────────
 * Desde que los hilos se fusionan por persona, una conversación puede llevar varias reservas
 * —16 hilos ya lo hacen— y en cuanto Travel entre, reservas y viajes a la vez. El chat no tiene
 * forma de decir a cuál va un mensaje, y de ahí cuelgan dos cosas que hoy no funcionan:
 *
 * 1. El corte de canales por asunto (`MessageDispatcher::acotarAlAsunto()`) no se aplica: sin
 *    asunto usa la unión del hilo, que no acota nada.
 * 2. Un mensaje del viaje puede salir marcado Beds24 y aterrizar en el hilo de la reserva.
 *
 * ── Qué se devuelve, y qué no ───────────────────────────────────────────────
 * La etiqueta la redacta el DOMINIO ({@see ConversacionEnlaceInterface::getEtiqueta()}), que es
 * quien sabe qué se puede enseñar: la casita y las fechas sí, el localizador y el saldo no. El
 * núcleo la transporta sin leerla.
 *
 * `esTitular` viaja porque el panel tiene que poder distinguir el hilo que ATIENDE el asunto del
 * de un acompañante — al segundo se le contesta, pero no se le programa nada.
 */
#[AsController]
final class AsuntosDeConversacionController extends AbstractController
{
    public function __construct(
        private readonly EnlacesDeConversacion $enlaces,
        private readonly EntityManagerInterface $em,
    ) {}

    /** GET — los asuntos del hilo. */
    public function listar(MessageConversation $data): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_SHOW, null, 'Acceso denegado a las conversaciones.');

        return new JsonResponse(['asuntos' => $this->comoLista($data)]);
    }

    /**
     * Los asuntos ORDENADOS por relevancia: el que está en curso, luego el más próximo a
     * llegar, y al final los pasados de más reciente a más antiguo.
     *
     * ── Por qué ordena el backend y no el panel ─────────────────────────────
     * Porque es el mismo criterio que ya usa `PmsReservaRepository::findVivasByTelefono()`
     * cuando entra un WhatsApp de alguien con varias reservas vivas. Dos sitios decidiendo «cuál
     * de sus reservas es la de ahora» con criterios distintos es la deriva que este repo tiene
     * documentada; y el panel sólo tiene que tomar el primero.
     *
     * ⚠️ **`esTitular` NO sirve para esto.** Significa «este hilo atiende este asunto», no «éste
     * es el asunto principal»: en un hilo fusionado los tres asuntos son titulares del suyo, así
     * que ordenar por ahí devolvía el orden de creación del enlace — un defecto arbitrario que
     * acertaba por casualidad.
     *
     * `correoExclusivo` es el alias que emitió la plataforma para ESTE asunto, o `null` si el
     * correo del asunto es el personal del huésped. El panel lo usa para dos cosas: no ofrecer
     * marcarlo como principal —{@see \App\Message\Service\Conversacion\EditorDeIdentidades::marcarPrincipal()},
     * que es quien de verdad lo impide— y saber que el botón de correo depende del asunto elegido.
     *
     * @return list<array{negocio: string, contextType: string, contextId: string, etiqueta: string, esTitular: bool, origen: string|null, correoExclusivo: string|null}>
     */
    private function comoLista(MessageConversation $conversacion): array
    {
        $enlaces = $this->enlaces->de($conversacion);
        $hoy = new DateTimeImmutable('today');

        usort($enlaces, static function (ConversacionEnlaceInterface $a, ConversacionEnlaceInterface $b) use ($hoy): int {
            return self::relevancia($a, $hoy) <=> self::relevancia($b, $hoy);
        });

        return array_map(
            static fn (ConversacionEnlaceInterface $e): array => [
                'negocio'     => $e->getNegocio(),
                'contextType' => $e->getContextType(),
                'contextId'   => $e->getContextId(),
                'etiqueta'    => $e->getEtiqueta(),
                'esTitular'   => $e->esTitular(),
                'origen'      => $e->getOrigen(),
                'correoExclusivo' => $e->correoEsExclusivo() ? $e->correoDeContacto() : null,
            ],
            $enlaces
        );
    }

    /**
     * Menor es más relevante. Tres tramos, y el orden dentro de cada uno es el natural.
     *
     * Un asunto sin fechas —un expediente de viaje que aún no tiene itinerario— cae entre los
     * futuros y los pasados: no es urgente, pero tampoco está terminado.
     *
     * @return array{int, int} [tramo, desempate]
     */
    private static function relevancia(ConversacionEnlaceInterface $enlace, DateTimeImmutable $hoy): array
    {
        $hitos = $enlace->getMilestones();
        $inicio = self::fecha($hitos[ConversationMilestoneInterface::START] ?? null);
        $fin = self::fecha($hitos[ConversationMilestoneInterface::END] ?? null);

        // 0 · En curso: ya empezó y no ha terminado. Es de lo que se está hablando ahora.
        if ($inicio !== null && $inicio <= $hoy && ($fin === null || $fin >= $hoy)) {
            return [0, 0];
        }

        // 1 · Por llegar: la más próxima primero.
        if ($inicio !== null && $inicio > $hoy) {
            return [1, (int) $inicio->format('Ymd')];
        }

        // 2 · Sin fechas.
        if ($inicio === null) {
            return [2, 0];
        }

        // 3 · Pasados: el más reciente primero, de ahí el signo.
        return [3, -(int) $inicio->format('Ymd')];
    }

    private static function fecha(?string $valor): ?DateTimeImmutable
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor);
        } catch (Exception) {
            // Un hito con formato roto no puede tumbar el listado entero: cuenta como sin fecha.
            return null;
        }
    }

    /**
     * PATCH — mueve el papel de TITULAR de un asunto a este hilo.
     *
     * Es la decisión de «a quién de los dos se le programan los envíos» cuando una reserva la
     * atienden dos personas desde números distintos. Al otro se le sigue contestando; lo que no
     * recibe es la agenda automática.
     */
    public function cambiarTitular(MessageConversation $data, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(Roles::MENSAJES_WRITE, null, 'No tienes permiso para editar la conversación.');

        /** @var array<string, mixed> $cuerpo */
        $cuerpo = json_decode($request->getContent() ?: '{}', true) ?: [];
        $tipo = trim((string) ($cuerpo['contextType'] ?? ''));
        $id   = trim((string) ($cuerpo['contextId'] ?? ''));

        if ($tipo === '' || $id === '') {
            return new JsonResponse(['error' => 'Falta el asunto.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->enlaces->cambiarTitular($data, $tipo, $id)) {
            // No se inventa el enlace: un titular sin enlace sería un asunto atendido desde una
            // conversación que no lo tiene colgado.
            return new JsonResponse(['error' => 'Ese asunto no cuelga de esta conversación.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->flush();

        return new JsonResponse(['asuntos' => $this->comoLista($data)]);
    }
}
