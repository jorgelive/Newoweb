<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Publico;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Service\Culqi\CulqiRechazoException;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\Culqi\CulqiClient;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Finanzas\Service\FinPasarelaRegistry;
use DomainException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * La misma página sirve las dos pasarelas: `ver()` dice cuál toca y `configuracionPago()`
 * de cada cliente devuelve lo que su librería JS necesita.
 */
#[Route('/finanzas/pago', name: 'finanzas_pago_publico_')]
final class FinPagoPublicoController extends AbstractController
{
    public function __construct(
        private readonly FinEnlacePagoRepository $repository,
        private readonly FinPasarelaRegistry $pasarelas,
        private readonly CulqiClient $culqi,
        private readonly FinEnlacePagoService $servicio,
        private readonly LoggerInterface $logger,
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
            // Con qué librería se monta el formulario. La SPA hace un switch por esto.
            'pasarela' => $enlace->getPasarela()->value,
        ]);
    }

    /**
     * Abre el intento de cobro y devuelve lo que necesita el navegador.
     *
     * Es POST y no GET porque en Izipay **tiene efecto**: pide un formToken de un solo uso
     * que caduca en minutos. En Culqi es inocuo, pero se mantiene el mismo verbo para que
     * la página no tenga que saber cuál es cuál.
     */
    #[Route('/{token}/configuracion', name: 'configuracion', methods: ['POST'])]
    public function configuracion(string $token): JsonResponse
    {
        $enlace = $this->repository->porToken($token);

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'no_encontrado'], 404);
        }

        if (!$enlace->estaVigente()) {
            return $this->json(['error' => 'no_vigente', 'estado' => $enlace->getEstado()->value], 410);
        }

        try {
            $config = $this->pasarelas->para($enlace->getPasarela())->configuracionPago($enlace);
        } catch (DomainException | RuntimeException $e) {
            return $this->json(['error' => 'pasarela', 'mensaje' => $e->getMessage()], 502);
        }

        return $this->json(['pasarela' => $enlace->getPasarela()->value, 'config' => $config]);
    }

    /**
     * CULQI — cierra el cobro con el token que devolvió el Checkout del navegador.
     *
     * Este endpoint es el camino principal de confirmación de Culqi, y es seguro pese a ser
     * público: el `tokenTarjeta` no autoriza nada por sí solo, el cargo lo crea **nuestro
     * servidor** con la clave secreta, y el importe sale del ENLACE —no del navegador—, así
     * que nadie puede pagar menos de lo que debe manipulando el JS.
     *
     * Si el cargo sale bien se confirma aquí mismo, sin esperar al webhook. El webhook queda
     * como red de seguridad para el caso de que el cliente pierda la conexión justo aquí, y
     * no duplica nada porque `confirmarPago()` es idempotente.
     */
    #[Route('/{token}/culqi/cobrar', name: 'culqi_cobrar', methods: ['POST'])]
    public function culqiCobrar(string $token, Request $request): JsonResponse
    {
        $enlace = $this->repository->porToken($token);

        if (!$enlace instanceof FinEnlacePago) {
            return $this->json(['error' => 'no_encontrado'], 404);
        }

        if ($enlace->getPasarela() !== FinPasarela::CULQI) {
            return $this->json(['error' => 'pasarela_incorrecta'], 409);
        }

        if (!$enlace->estaVigente()) {
            return $this->json(['error' => 'no_vigente', 'estado' => $enlace->getEstado()->value], 410);
        }

        /** @var array<string, mixed> $datos */
        $datos = json_decode((string) $request->getContent(), true) ?: [];
        $tokenTarjeta = $datos['token'] ?? null;

        if (!is_string($tokenTarjeta) || $tokenTarjeta === '') {
            return $this->json(['error' => 'token_requerido'], 400);
        }

        // Los cinco parámetros del reto, si el navegador ya lo pasó. Ausentes en el primer
        // intento; ver `CulqiClient::cobrarConToken()`.
        $autenticacion = is_array($datos['autenticacion3DS'] ?? null) ? $datos['autenticacion3DS'] : null;

        try {
            $cargo = $this->culqi->cobrarConToken($enlace, $tokenTarjeta, $autenticacion);
        } catch (CulqiRechazoException $e) {
            // 🔥 **Un rechazo puede ser una INSTRUCCIÓN, no un no.** «Se solicita autenticación
            // 3DS» significa: autentica al titular y repite el mismo cobro. Tratarlo como
            // definitivo es lo que dejó sin poder pagar a toda tarjeta extranjera entre el 26/08
            // y el 05/09 —cinco denegaciones seguidas de España y Australia— mientras las
            // peruanas, que no disparan el reto, pasaban sin enterarse de nada.
            //
            // ⚠️ **No se registra fallo** en este caso: no ha fallado nada todavía. Marcarlo
            // ensuciaría el historial del enlace con un intento por cada autenticación.
            if ($e->pideAutenticacion3DS() && $autenticacion === null) {
                $this->logger->info('[culqi] el cobro exige 3DS; se devuelve al navegador', [
                    'enlace' => (string) $enlace->getId(),
                    'code' => $e->codigo(),
                ]);

                return $this->json([
                    'error' => 'requiere_3ds',
                    'mensaje' => $e->motivoDelComercio() ?? 'Tu banco pide autenticación.',
                ], 409);
            }

            // Rechazo del banco: NO es un error nuestro. Se registra el intento y el cliente
            // puede reintentar con otra tarjeta en el mismo enlace (FALLIDO no es final).
            //
            // ⚠️ Se guarda el CÓDIGO además del mensaje: si mañana Culqi pide 3DS con otro
            // código, aquí es donde se va a ver — la lista de `pideAutenticacion3DS()` sólo
            // reconoce lo que hemos medido, a propósito.
            $this->servicio->registrarFallo($enlace, [
                'error' => $e->getMessage(),
                'code' => $e->codigo(),
                'motivo' => $e->motivoDelComercio(),
            ]);

            return $this->json(['error' => 'rechazado', 'mensaje' => $e->getMessage()], 402);
        } catch (RuntimeException $e) {
            // Cualquier otro fallo hablando con Culqi (red, configuración): no es del banco.
            $this->servicio->registrarFallo($enlace, ['error' => $e->getMessage()]);

            return $this->json(['error' => 'rechazado', 'mensaje' => $e->getMessage()], 402);
        }

        if (!$this->culqi->cargoPagaElEnlace($cargo, $enlace)) {
            $this->logger->error('[culqi] cargo creado que no cuadra con el enlace', [
                'enlace' => (string) $enlace->getId(),
            ]);

            return $this->json(['error' => 'cargo_no_valido'], 422);
        }

        $this->servicio->confirmarPago($enlace, $this->culqi->comoRespuestaNormalizada($cargo));

        return $this->json(['ok' => true, 'estado' => $enlace->getEstado()->value]);
    }
}
