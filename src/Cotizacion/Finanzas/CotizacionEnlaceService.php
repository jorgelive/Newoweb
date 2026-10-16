<?php

declare(strict_types=1);

namespace App\Cotizacion\Finanzas;

use App\Cotizacion\Dominio\CalcularAdelanto;
use App\Cotizacion\Dto\CuentaDelExpediente;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\Publico\ComoReservarResolver;
use App\Dominio\EjecutorDeDominio;
use App\Dto\Lee;
use App\Entity\User;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Los enlaces de pago de una cotización: el del adelanto, que se emite solo al publicarla, y los
 * que el cliente puede pagar ahora (16/10/2026).
 *
 * El gemelo de `PmsPrepagoEnlaceService`, con sus reglas:
 *
 * - **El interruptor es el mismo**, `finanzas.enlaces_prepago_activos`: dice si la pasarela cobra
 *   de verdad. Apagado, en modo de pruebas Culqi acepta el cobro sin mover dinero y el cliente se
 *   quedaría convencido de que pagó.
 * - **Se reutiliza** un enlace vivo por el mismo importe y moneda en vez de emitir otro: dos
 *   publicaciones seguidas no dejan dos enlaces por el mismo dinero.
 * - **La cifra no se calcula aquí**: el adelanto es `montoDeAdelanto()` de `dominio/`, la misma
 *   función que enseña «Cómo reservar» ({@see CalcularAdelanto}).
 * - **El automático no caduca** (`vigenciaDias: 0`): no lo mira nadie, y caducado moriría en
 *   silencio con el cliente sin poder pagar.
 * - **Con recargo**: la comisión de la tarjeta la paga el cliente, como en alojamiento (Jorge).
 * - **En dólares**: es la moneda de los totales. Desde el panel se puede emitir en soles.
 */
final readonly class CotizacionEnlaceService
{
    public function __construct(
        private FinEnlacePagoService $enlaces,
        private FinEnlacePagoRepository $repositorio,
        private EjecutorDeDominio $dominio,
        private LoggerInterface $logger,
        #[Autowire('%finanzas.enlaces_prepago_activos%')]
        private bool $activo,
    ) {
    }

    /**
     * Los enlaces que el cliente puede pagar AHORA, del más reciente al más antiguo.
     *
     * Para pax, que sólo enseña lo que ya existe: emitir desde ahí sería una escritura disparada
     * por cualquiera con el localizador.
     *
     * @return list<FinEnlacePago>
     */
    public function pagables(Cotizacion $cotizacion): array
    {
        $id = $cotizacion->getId();

        if (!$this->activo || $id === null) {
            return [];
        }

        return array_values(array_filter(
            $this->repositorio->porOrigen(FinOrigenCobro::COTIZACION, $id),
            static fn (FinEnlacePago $e): bool => $e->estaVigente(),
        ));
    }

    /**
     * Emite —o reutiliza— el enlace del adelanto de una propuesta que se está ofreciendo.
     *
     * No procede (y devuelve `null`) si: el interruptor está apagado, la propuesta ya no se ofrece
     * (`ComoReservarResolver::seOfrece()`), no pide adelanto, no tiene total, o ya hay algo pagado
     * —lo que se pague después es el saldo, y ése lo emite `emitirSaldo()`—.
     *
     * 🔒 **Nunca lanza.** Se llama desde el `postFlush` que publica la cotización: la publicación ya
     * está hecha y que no salga el enlace no puede tumbarla. Queda en el log, y el operador lo
     * emite a mano desde el panel.
     */
    public function emitirAdelanto(Cotizacion $cotizacion, ?User $creadoPor = null): ?FinEnlacePago
    {
        try {
            $file = $cotizacion->getFile();
            $id = $cotizacion->getId();

            if (!$this->activo || $file === null || $id === null || !ComoReservarResolver::seOfrece($file, $cotizacion)) {
                return null;
            }

            $porcentaje = $cotizacion->porcentajeDeAdelanto();
            $total = round((float) $cotizacion->origenFinancieroParaCliente()->getTotalVenta(), 2);
            $pagado = CuentaDelExpediente::pagadoDePropuesta($file, $cotizacion->getPropuesta())['usd'];

            if ($porcentaje <= 0 || $total <= 0 || $pagado > 0) {
                return null;
            }

            $monto = number_format($this->adelanto($total, $porcentaje), 2, '.', '');

            return $this->vigentePorImporte($cotizacion, $monto, 'USD') ?? $this->enlaces->crear(
                origenTipo: FinOrigenCobro::COTIZACION,
                origenId: $id,
                montoNeto: $monto,
                conRecargo: true,
                vigenciaDias: 0,
                concepto: sprintf('Adelanto para reservar (%s %%) · %s', rtrim(rtrim(number_format($porcentaje, 2, '.', ''), '0'), '.'), $file->getNombreGrupo() ?: $file->getLocalizador()),
                creadoPor: $creadoPor,
                moneda: 'USD',
            );
        } catch (Throwable $e) {
            $this->logger->error('[cotizacion] no se pudo emitir el enlace del adelanto', [
                'cotizacion' => (string) $cotizacion->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Emite —o reutiliza— el enlace del SALDO de una propuesta: lo que falta tras el adelanto.
     *
     * Lo emite solo el barrido `app:cotizacion:saldo-tras-adelanto` media hora después de pagarse
     * el adelanto con tarjeta, como en alojamiento. Sin saldo, `null`. 🔒 Nunca lanza.
     */
    public function emitirSaldo(Cotizacion $cotizacion): ?FinEnlacePago
    {
        try {
            $file = $cotizacion->getFile();
            $id = $cotizacion->getId();

            if (!$this->activo || $file === null || $id === null) {
                return null;
            }

            $saldo = CotizacionOrigenCobroResolver::saldoUsd($file, $cotizacion);

            if ($saldo <= 0) {
                return null;
            }

            $monto = number_format($saldo, 2, '.', '');

            return $this->vigentePorImporte($cotizacion, $monto, 'USD') ?? $this->enlaces->crear(
                origenTipo: FinOrigenCobro::COTIZACION,
                origenId: $id,
                montoNeto: $monto,
                conRecargo: true,
                vigenciaDias: 0,
                concepto: sprintf('Saldo del viaje · %s', $file->getNombreGrupo() ?: $file->getLocalizador()),
                moneda: 'USD',
            );
        } catch (Throwable $e) {
            $this->logger->error('[cotizacion] no se pudo emitir el enlace del saldo', [
                'cotizacion' => (string) $cotizacion->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** El adelanto con la función de `dominio/`, la misma que enseña «Cómo reservar». */
    private function adelanto(float $total, float $porcentaje): float
    {
        $salida = Lee::objeto($this->dominio->ejecutarUna(new CalcularAdelanto(), ['total' => $total, 'porcentaje' => $porcentaje]));
        $adelanto = Lee::decimal($salida['adelanto'] ?? null);

        if ($adelanto === null) {
            throw new \UnexpectedValueException('El cálculo del adelanto no devolvió un importe.');
        }

        return $adelanto;
    }

    private function vigentePorImporte(Cotizacion $cotizacion, string $monto, string $moneda): ?FinEnlacePago
    {
        foreach ($this->pagables($cotizacion) as $enlace) {
            if ($enlace->getMontoNeto() === $monto && $enlace->getMonedaCodigo() === $moneda) {
                return $enlace;
            }
        }

        return null;
    }
}
