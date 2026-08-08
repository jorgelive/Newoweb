<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Publico;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\Izipay\IzipayClient;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints PÚBLICOS que alimentan la página de pago de `pax` (`/pago/{token}`).
 *
 * No hay sesión ni login: la credencial es el token de la URL, 32 bytes aleatorios. Por eso
 * aquí se aplican tres reglas que no son negociables:
 *
 * 1. **Se responde por token, nunca por id.** Aceptar el UUID abriría la puerta a
 *    enumerar enlaces vecinos (el v7 lleva marca de tiempo).
 * 2. **Se devuelve lo mínimo**: importe, moneda y concepto. Ni el origen, ni quién lo
 *    emitió, ni el email del cliente — el que abre esta URL puede ser cualquiera a quien
 *    se la hayan reenviado.
 * 3. **Un enlace no vigente responde 410**, no 404: el cliente que llega tarde merece un
 *    "este enlace caducó" y no un error genérico.
 */
#[Route('/finanzas/pago', name: 'finanzas_pago_publico_')]
final class FinPagoPublicoController extends AbstractController
{
    public function __construct(
        private readonly FinEnlacePagoRepository $repository,
        private readonly IzipayClient $izipay,
        #[Autowire('%finanzas.izipay.static_url%')]
        private readonly string $staticUrl,
    ) {}

    /** Datos para pintar la página antes de montar el formulario de la pasarela. */
    #[Route('/{token}', name: 'ver', methods: ['GET'])]
    public function ver(string $token): JsonResponse
    {
        $enlace = $this->repository->porToken($token);

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'no_encontrado'], 404);
        }

        return $this->json([
            'concepto' => $enlace->getConcepto(),
            'referencia' => $enlace->getOrigenReferencia(),
            'moneda' => $enlace->getMonedaCodigo(),
            'monedaSimbolo' => $enlace->getMonedaSimbolo(),
            'montoNeto' => $enlace->getMontoNeto(),
            'montoRecargo' => $enlace->getMontoRecargo(),
            'montoTotal' => $enlace->getMontoTotal(),
            'recargoPorcentaje' => $enlace->getRecargoPorcentaje(),
            'estado' => $enlace->getEstado()->value,
            'vigente' => $enlace->estaVigente(),
            'expiraEn' => $enlace->getExpiraEn()?->format(DATE_ATOM),
            'pagadoEn' => $enlace->getPagadoEn()?->format(DATE_ATOM),
            // La clave pública y el host estático son públicos por diseño: la librería JS
            // los necesita en el navegador. La que nunca sale del servidor es `password`.
            'publicKey' => $this->izipay->clavePublica(),
            'staticUrl' => $this->staticUrl,
        ]);
    }

    /**
     * Abre un intento de cobro y devuelve el `formToken`.
     *
     * Se pide en cada carga de la página y no se cachea: el formToken es de un solo uso y
     * caduca en minutos (ver `IzipayClient::crearFormToken()`).
     */
    #[Route('/{token}/form-token', name: 'form_token', methods: ['POST'])]
    public function formToken(string $token): JsonResponse
    {
        $enlace = $this->repository->porToken($token);

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'no_encontrado'], 404);
        }

        if (!$enlace->estaVigente()) {
            return $this->json([
                'error' => 'no_vigente',
                'estado' => $enlace->getEstado()->value,
            ], 410);
        }

        try {
            $formToken = $this->izipay->crearFormToken($enlace);
        } catch (RuntimeException $e) {
            return $this->json(['error' => 'pasarela', 'mensaje' => $e->getMessage()], 502);
        }

        return $this->json(['formToken' => $formToken, 'publicKey' => $this->izipay->clavePublica()]);
    }
}
