<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Webhook;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Entity\FinPasarelaWebhookAudit;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Finanzas\Service\Izipay\IzipayClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * IPN de Izipay: la ÚNICA fuente de verdad de que un cobro ocurrió.
 *
 * ## Por qué no se confía en el navegador
 *
 * Cuando el cliente termina de pagar, la pasarela también avisa al navegador. Ese aviso no
 * se usa para marcar nada: viaja por una máquina que no controlamos y un cliente que cierra
 * la pestaña antes de tiempo no lo dispara nunca. El IPN es servidor-a-servidor, se
 * reintenta si no respondemos, y va firmado. Marcar como pagado desde el retorno del
 * navegador es la forma clásica de regalar estancias.
 *
 * ## Contrato de respuesta
 *
 * Se responde **200 siempre que el mensaje se haya podido procesar o descartar con
 * criterio**, incluso si lo ignoramos: un no-200 hace que Izipay reintente en bucle. El 400
 * queda sólo para la firma inválida, que sí queremos que se note en su panel.
 */
#[Route('/finanzas/webhooks', name: 'finanzas_webhook_')]
final class IzipayWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinEnlacePagoRepository $repository,
        private readonly FinEnlacePagoService $servicio,
        private readonly IzipayClient $izipay,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/izipay', name: 'izipay', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // El POST llega como formulario (`application/x-www-form-urlencoded`), no como JSON:
        // los campos son `kr-answer`, `kr-hash`, `kr-hash-key`… Leerlo con getContent() y
        // json_decode devuelve null y parece "webhook vacío".
        $post = $request->request->all();

        // Auditoría ANTES de validar: los rechazados por firma son los que más interesa ver.
        $audit = new FinPasarelaWebhookAudit();
        $audit
            ->setRemoteIp($request->getClientIp())
            ->setPayloadRaw((string) $request->getContent());

        $this->em->persist($audit);
        $this->em->flush();

        if (!$this->izipay->validarFirma($post)) {
            $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_ERROR, 'firma_invalida');
            $this->logger->warning('[finanzas] IPN de Izipay con firma inválida', ['ip' => $request->getClientIp()]);

            return $this->json(['ok' => false, 'error' => 'firma_invalida'], 400);
        }

        try {
            $respuesta = $this->izipay->decodificarRespuesta((string) $post['kr-answer']);
            $enlace = $this->localizarEnlace($respuesta);

            if (!$enlace instanceof FinEnlacePago) {
                $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_IGNORADO, 'enlace_no_encontrado');

                // 200: el mensaje es válido, simplemente no es nuestro (o el enlace se borró).
                // Devolver error haría que Izipay lo reintentase eternamente.
                return $this->json(['ok' => true, 'ignorado' => 'enlace_no_encontrado']);
            }

            $audit->setEnlaceId($enlace->getId());

            if ($this->izipay->esPagoExitoso($respuesta)) {
                $this->servicio->confirmarPago($enlace, $respuesta);
            } else {
                $this->servicio->registrarFallo($enlace, $respuesta);
            }

            $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_PROCESADO);

            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->cerrarAudit($audit, FinPasarelaWebhookAudit::ESTADO_ERROR, $e->getMessage());
            $this->logger->error('[finanzas] error procesando IPN de Izipay', ['error' => $e->getMessage()]);

            // 500 a propósito: aquí SÍ queremos el reintento de Izipay, porque el fallo es
            // nuestro (base de datos caída, bug) y el cobro sí ocurrió.
            return $this->json(['ok' => false, 'error' => 'error_interno'], 500);
        }
    }

    /**
     * Encuentra el enlace del que habla la respuesta.
     *
     * Primero por `metadata.enlaceId`, que es lo que mandamos en `CreatePayment` y viaja
     * intacto; el `orderId` es el plan B por si el cobro se lanzó desde el Backoffice de
     * Izipay sin pasar por nosotros y no lleva metadata.
     *
     * @param array<string, mixed> $respuesta
     */
    private function localizarEnlace(array $respuesta): ?FinEnlacePago
    {
        $enlaceId = $respuesta['transactions'][0]['metadata']['enlaceId']
            ?? $respuesta['metadata']['enlaceId']
            ?? null;

        if (is_string($enlaceId) && Uuid::isValid($enlaceId)) {
            $enlace = $this->repository->find(Uuid::fromString($enlaceId));

            if ($enlace instanceof FinEnlacePago) {
                return $enlace;
            }
        }

        $ordenId = $respuesta['orderDetails']['orderId'] ?? $respuesta['orderId'] ?? null;

        return is_string($ordenId) ? $this->repository->porOrdenId($ordenId) : null;
    }

    private function cerrarAudit(FinPasarelaWebhookAudit $audit, string $estado, ?string $error = null): void
    {
        $audit->setEstado($estado)->setErrorMensaje($error);

        try {
            $this->em->flush();
        } catch (Throwable $e) {
            // Que no se pueda cerrar la auditoría no puede tumbar la respuesta al webhook.
            $this->logger->error('[finanzas] no se pudo cerrar la auditoría del IPN', ['error' => $e->getMessage()]);
        }
    }
}
