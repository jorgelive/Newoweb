<?php

declare(strict_types=1);

namespace App\Finanzas\Service\Aviso;

use App\Finanzas\Entity\FinEnlacePago;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoAlEquipoService;
use App\Security\Roles;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Throwable;
use App\Service\Config\Parametro;

/**
 * Avisa al equipo por WhatsApp de que un enlace de pago se ha cobrado.
 *
 * ### Por qué hace falta
 *
 * Hasta ahora un cobro no avisaba a nadie: se enteraba quien mirase el panel de la reserva —el
 * saldo baja solo— o la caja. Mientras cobraba un operador desde el panel daba igual, porque ya
 * estaba delante. Deja de dar igual con los **enlaces de prepago**: el huésped paga por su
 * cuenta, a cualquier hora, y nadie se entera hasta que alguien abre la reserva.
 *
 * ### Qué NO hace, a propósito
 *
 * **No decide a quién ni por dónde**: eso es de {@see AvisoAlEquipoService}. Aquí sólo se
 * redacta, que es lo único que sabe Finanzas.
 *
 * **No deduplica.** No hace falta: cada cobro es un hecho único e irrepetible, al contrario que
 * el escalado —donde el mismo huésped insistiendo tres veces son tres avisos por lo mismo, y por
 * eso allí hay enfriamiento—. Si algún día un cobro pudiera avisar dos veces, el sitio de
 * arreglarlo es aquí, no el servicio.
 *
 * ⚠️ **No interpreta el ORIGEN del cobro.** `origenTipo` se imprime por su etiqueta y se
 * transporta; este servicio no sabe qué es una reserva de alojamiento ni una cotización, igual
 * que el resto de Finanzas (ver la regla de dominios en CLAUDE.md).
 */
final readonly class FinAvisoDeCobro
{
    /**
     * La plantilla para cuando la ventana de 24 h está cerrada, que es lo normal: un operador de
     * guardia no le escribe al número del negocio todos los días.
     *
     * Si no existe o Meta aún no la aprobó, el aviso sale igual **dentro** de ventana y fuera se
     * queda en `no_avisados`. Nunca tumba el cobro: ver `notificar()`.
     */
    private const string PLANTILLA = 'aviso_cobro_interno';

    public function __construct(
        private AvisoAlEquipoService $avisos,
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
    ) {}

    /**
     * 🔒 NUNCA lanza, y esa es su regla principal.
     *
     * Se llama después de que el cobro esté cerrado y persistido. Un fallo avisando —Meta caída,
     * plantilla sin aprobar, un móvil mal escrito— no puede volverse contra un pago que el
     * cliente ya hizo y que ya está registrado. Se traga el error y lo deja en el log.
     */
    public function notificar(FinEnlacePago $enlace): void
    {
        try {
            $resultado = $this->avisos->notificar(new AvisoAlEquipo(
                rol: Roles::CUSTOMER_SUPPORT,
                texto: $this->redactar($enlace),
                plantillaCodigo: self::PLANTILLA,
                variables: $this->variables($enlace),
                metadata: [
                    'aviso_cobro' => true,
                    'enlace_pago' => (string) $enlace->getId(),
                ],
            ));

            if ($resultado->sinDestinatarios) {
                $this->logger->warning('[finanzas] cobro sin avisar: nadie con el rol y móvil.', [
                    'enlace' => (string) $enlace->getId(),
                ]);

                return;
            }

            if ($resultado->noAvisados !== []) {
                $this->logger->error('[finanzas] el aviso de cobro no llegó a todos.', [
                    'enlace' => (string) $enlace->getId(),
                    'no_avisados' => $resultado->noAvisados,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('[finanzas] falló el aviso de cobro; el pago NO se ve afectado.', [
                'enlace' => (string) $enlace->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * El aviso dentro de la ventana de 24 h: multilínea, con todo lo que evita abrir el panel.
     *
     * Lleva el medio de pago porque es lo primero que se pregunta al cuadrar la caja, y el
     * origen porque un cobro suelto y el adelanto de una reserva se atienden distinto.
     */
    private function redactar(FinEnlacePago $enlace): string
    {
        $lineas = [
            sprintf('💰 *%s* ha pagado %s', $this->cliente($enlace), $this->importe($enlace)),
            '',
            $enlace->getConcepto(),
        ];

        $origen = $enlace->getOrigenTipo()?->etiqueta();
        $referencia = $enlace->getOrigenReferencia();

        if ($origen !== null) {
            $lineas[] = $referencia !== null && $referencia !== ''
                ? sprintf('%s · %s', $origen, $referencia)
                : $origen;
        }

        $medio = $enlace->getMedioDetalle();

        if ($medio !== null && $medio !== '') {
            $lineas[] = $medio;
        }

        $lineas[] = '';
        $lineas[] = '👉 ' . $this->urlFinanzas();

        return implode("\n", $lineas);
    }

    /**
     * Lo que hidrata la plantilla, en el formato que Meta acepta.
     *
     * ⚠️ Una sola línea por valor y **ninguno vacío**: `WhatsappMetaSendMappingStrategy` lanza
     * excepción si una variable llega en blanco, así que todos tienen respaldo.
     *
     * @return array<string, string>
     */
    private function variables(FinEnlacePago $enlace): array
    {
        $origen = $enlace->getOrigenTipo()?->etiqueta();
        $referencia = $enlace->getOrigenReferencia();

        $detalle = $enlace->getConcepto();

        if ($origen !== null) {
            $detalle .= $referencia !== null && $referencia !== ''
                ? sprintf(' (%s · %s)', $origen, $referencia)
                : sprintf(' (%s)', $origen);
        }

        return [
            'cliente' => $this->unaLinea($this->cliente($enlace)),
            'importe' => $this->importe($enlace),
            'concepto' => $this->unaLinea($detalle),
            // Relativo: el botón de Meta ya lleva el dominio y sólo admite el sufijo. Mismo
            // criterio que `chat_path` en el aviso de escalado.
            'finanzas_path' => 'finanzas',
            'finanzas_url' => $this->urlFinanzas(),
        ];
    }

    private function cliente(FinEnlacePago $enlace): string
    {
        $nombre = trim((string) $enlace->getClienteNombre());

        return $nombre !== '' ? $nombre : 'Un cliente';
    }

    private function importe(FinEnlacePago $enlace): string
    {
        return sprintf('%s %s', $enlace->getMonedaCodigo() ?? '', $enlace->getMontoTotal());
    }

    private function urlFinanzas(): string
    {
        return rtrim(Parametro::texto($this->params->get('util_host_url'), 'util_host_url'), '/') . '/finanzas';
    }

    /** Sin saltos ni dobles espacios, y nunca vacío: los dos los prohíbe Meta. */
    private function unaLinea(string $valor): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $valor));

        return $limpio !== '' ? $limpio : '—';
    }
}
