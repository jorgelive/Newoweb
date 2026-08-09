<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Webhook;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Entity\FinPasarelaWebhookAudit;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\Culqi\CulqiClient;
use App\Finanzas\Service\FinEnlacePagoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Webhook de Culqi. **Red de seguridad, no camino principal.**
 *
 * El cobro normal ya se confirma en `FinPagoPublicoController::culqiCobrar()`, que es una
 * llamada nuestra autenticada. Esto sólo cubre el hueco de que el cliente pierda la conexión
 * justo después de pagar y antes de que su navegador nos avise. No duplica nada porque
 * `FinEnlacePagoService::confirmarPago()` es idempotente.
 *
 * ## Aquí NO se cree nada del cuerpo
 *
 * Culqi **no publica firma, secreto compartido ni lista de IPs** para sus webhooks
 * (comprobado en su doc de webhooks y en la referencia de API — si algún día la añaden, se
 * suma como segunda barrera). Un endpoint público sin firma lo puede llamar cualquiera:
 * creerse el JSON entrante permitiría saldar reservas con un `curl`.
 *
 * Así que del payload sólo se saca un **id de cargo**, y a partir de ahí manda
 * `CulqiClient::verificarCargo()`, que pregunta a Culqi con nuestra clave secreta. Y ni
 * siquiera basta con que el cargo exista: `cargoPagaElEnlace()` comprueba importe y moneda,
 * porque el id lo puso quien mandó el webhook y podría ser el de un cargo real de S/1.
 *
 * Contrato de respuesta: **200 salvo error nuestro**. Un no-200 hace que Culqi reintente,
 * y un mensaje que no es nuestro o que no cuadra no mejora reintentándolo.
 */
#[Route('/finanzas/webhooks', name: 'finanzas_webhook_')]
final class CulqiWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinEnlacePagoRepository $repository,
        private readonly FinEnlacePagoService $servicio,
        private readonly CulqiClient $culqi,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/culqi', name: 'culqi', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $crudo = (string) $request->getContent();

        // Auditoría antes de nada: es la única prueba de qué llegó y de quién.
        $audit = new FinPasarelaWebhookAudit();
        $audit
            ->setPasarela(FinPasarela::CULQI)
            ->setRemoteIp($request->getClientIp())
            ->setPayloadRaw($crudo);

        $this->em->persist($audit);
        $this->em->flush();

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($crudo, true, 512, JSON_THROW_ON_ERROR);

            $idCargo = $this->idDeCargo($payload);
            $enlace = $this->localizarEnlace($payload);

            if ($idCargo === null || !$enlace instanceof FinEnlacePago) {
                $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_IGNORADO, 'sin_cargo_o_enlace');

                return $this->json(['ok' => true, 'ignorado' => 'sin_cargo_o_enlace']);
            }

            $audit->setEnlaceId($enlace->getId());

            // Si ya lo cerró el camino principal, no se vuelve a preguntar a Culqi.
            if ($enlace->getEstado() === FinEnlacePagoEstado::PAGADO) {
                $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_IGNORADO, 'ya_pagado');

                return $this->json(['ok' => true, 'ignorado' => 'ya_pagado']);
            }

            $cargo = $this->culqi->verificarCargo($idCargo);

            if ($cargo === null || !$this->culqi->cargoPagaElEnlace($cargo, $enlace)) {
                $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_IGNORADO, 'cargo_no_verificado');

                // 200 a propósito: el aviso no es de fiar o no cuadra, y reintentarlo daría
                // el mismo resultado. Queda en la auditoría para poder mirarlo.
                return $this->json(['ok' => true, 'ignorado' => 'cargo_no_verificado']);
            }

            $this->servicio->confirmarPago($enlace, $this->culqi->comoRespuestaNormalizada($cargo));
            $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_PROCESADO);

            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_ERROR, $e->getMessage());
            $this->logger->error('[finanzas] error procesando webhook de Culqi', ['error' => $e->getMessage()]);

            // 500: aquí SÍ queremos el reintento, porque el fallo es nuestro y el cobro
            // pudo ser real.
            return $this->json(['ok' => false, 'error' => 'error_interno'], 500);
        }
    }

    /**
     * Id del cargo dentro del aviso.
     *
     * Culqi manda el objeto en `data` para los eventos de cargo; se aceptan también las
     * formas planas por si el evento llega con otra envoltura. Sólo se toma el ID: todo lo
     * demás del cuerpo se ignora por diseño.
     *
     * @param array<string, mixed> $payload
     */
    private function idDeCargo(array $payload): ?string
    {
        $candidatos = [
            $payload['data']['id'] ?? null,
            $payload['object']['id'] ?? null,
            $payload['id'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            // Los cargos de Culqi son `chr_...`; descartar el resto evita ir a preguntar
            // por el id del propio evento y llevarse un 404.
            if (is_string($candidato) && str_starts_with($candidato, 'chr_')) {
                return $candidato;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function localizarEnlace(array $payload): ?FinEnlacePago
    {
        $enlaceId = $payload['data']['metadata']['enlaceId']
            ?? $payload['metadata']['enlaceId']
            ?? null;

        if (is_string($enlaceId) && Uuid::isValid($enlaceId)) {
            $enlace = $this->repository->find(Uuid::fromString($enlaceId));

            if ($enlace instanceof FinEnlacePago) {
                return $enlace;
            }
        }

        $ordenId = $payload['data']['metadata']['ordenId'] ?? null;

        return is_string($ordenId) ? $this->repository->porOrdenId($ordenId) : null;
    }

    private function cerrarAudit(FinPasarelaWebhookAudit $audit, string $estado, ?string $error = null): void
    {
        $audit->setEstado($estado)->setErrorMensaje($error);

        try {
            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->error('[finanzas] no se pudo cerrar la auditoría del webhook', ['error' => $e->getMessage()]);
        }
    }
}
