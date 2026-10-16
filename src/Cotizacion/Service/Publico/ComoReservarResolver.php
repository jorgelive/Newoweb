<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Publico;

use App\Cotizacion\Dto\ComoReservar;
use App\Cotizacion\Dto\CuentaDelExpediente;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Enum\FileEstadoEnum;
use App\Finanzas\Enum\FinAudienciaCobro;
use App\Finanzas\Enum\FinMedioCobroTipo;
use App\Finanzas\Repository\FinMedioCobroRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Arma el bloque «Cómo reservar» de una propuesta para la vista del cliente.
 *
 * - **El adelanto** es el porcentaje de la propuesta ({@see Cotizacion::porcentajeDeAdelanto()}).
 * - **Los medios** salen del catálogo de Finanzas (`FinMedioCobro`), el mismo que usa el
 *   alojamiento: al peruano, Yape, Plin y transferencias; al de fuera, tarjeta y Western Union.
 *   Quién es de Perú lo dice el país del expediente y, sin él, el teléfono
 *   ({@see FinAudienciaCobro::desdePeru()}).
 * - **Las devoluciones** no se redactan aquí: se enlaza la política publicada en la web.
 *
 * Sólo en una propuesta que todavía se está ofreciendo ({@see self::seOfrece()}). Una confirmada
 * ya está reservada, y decirle «reserva con el 50 %» a quien ya compró invita a pagar dos veces.
 */
final readonly class ComoReservarResolver
{
    /** Las páginas de la política, por idioma. La web sólo tiene español e inglés. */
    private const string PAGINA_DEVOLUCIONES = 'politica-de-cambios-y-devoluciones';

    public function __construct(
        private FinMedioCobroRepository $mediosCobro,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * ¿Se le dice a esta propuesta cómo reservarla?
     *
     * Las tres cosas, y la regla es UNA para el detalle y la portada:
     *
     * - **La propuesta** está pendiente o enviada: lo que aún se ofrece. Confirmada, operativa u
     *   operada ya se reservaron; cerrada, cancelada e histórica no se venden.
     * - **El expediente** sigue abierto: cerrado (no hubo venta) o archivado (el viaje terminó) no
     *   ofrecen nada, aunque quede una propuesta publicada.
     * - **No hay otra ya vendida**. Confirmar la v2 no despublica la v1, y la v1 seguía diciendo
     *   «reserva con el 50 %» a quien ya había comprado la v2 (revisión del 16/10/2026).
     */
    public static function seOfrece(CotizacionFile $file, Cotizacion $cotizacion): bool
    {
        return self::seOfreceEnEstado($file, $cotizacion->getEstado());
    }

    /** Lo mismo, para quien lee el estado de una consulta escalar (la portada). */
    public static function seOfreceEnEstado(CotizacionFile $file, CotizacionEstadoEnum $estado): bool
    {
        return \in_array($estado, [CotizacionEstadoEnum::PENDIENTE, CotizacionEstadoEnum::ENVIADO], true)
            && $file->getEstado() === FileEstadoEnum::ABIERTO
            && CuentaDelExpediente::vendida($file) === null;
    }

    /**
     * Lo ya pagado a cuenta de una propuesta, tal como lo puede ver el cliente.
     *
     * 🔒 **En un grupo, nada.** El enlace lo tienen todas las familias, y la suma de lo pagado sería
     * el dinero de todas — a la vista de cualquiera y diciéndole «adelanto recibido» a quien no
     * pagó. Lo de cada familia, cuando haya cuenta por persona identificada.
     *
     * 🔒 **Y con el precio oculto, tampoco.** Una propuesta `SIN_PRECIO` no dice cuánto cuesta el
     * viaje; decir cuánto se ha pagado de ella es el mismo dinero por otra puerta. La portada ya
     * lo hacía y el detalle no (revisión del 16/10/2026): el bloque «Cómo reservar» cuelga de
     * `CotizacionFile`, así que `CotizacionPublicNormalizer::redactarMontos()` —que sólo mira la
     * `Cotizacion`— no lo tapaba. La regla vive aquí para que no haya dos.
     *
     * @return array{usd: float, porMoneda: array<string, float>}
     */
    public static function pagadoVisible(CotizacionFile $file, int $propuesta, bool $precioOculto): array
    {
        return $file->getModo()->ocultaTotalDeGrupo() || $precioOculto
            ? ['usd' => 0.0, 'porMoneda' => []]
            : CuentaDelExpediente::pagadoDePropuesta($file, $propuesta);
    }

    /** El día del primer servicio, o `null` si la propuesta no tiene fechas. */
    public static function primerServicio(Cotizacion $cotizacion): ?DateTimeImmutable
    {
        $primero = null;

        foreach ($cotizacion->getCotservicios() as $servicio) {
            $inicio = $servicio->getFechaInicioAbsoluta();

            if ($inicio !== null && ($primero === null || $inicio < $primero)) {
                $primero = $inicio;
            }
        }

        return $primero?->setTime(0, 0);
    }

    public function para(CotizacionFile $file, Cotizacion $cotizacion, DateTimeImmutable $hoy): ?ComoReservar
    {
        if (!self::seOfrece($file, $cotizacion)) {
            return null;
        }

        $inicio = self::primerServicio($cotizacion);
        $desdePeru = FinAudienciaCobro::desdePeru($file->getPais()?->getId(), $file->getTelefono());

        // Los días retiran lo que ya no da tiempo: Western Union pide dos. Se cuentan por FECHA,
        // igual que en la guía del huésped, para que un medio no aparezca y desaparezca a lo
        // largo del mismo día.
        $dias = $inicio !== null
            ? max(0, (int) $hoy->setTime(0, 0)->diff($inicio)->format('%r%a'))
            : null;

        $medios = [];
        foreach ($this->mediosCobro->ofrecibles($desdePeru, $dias) as $medio) {
            // ⚠️ El efectivo NO: es un medio de alojamiento —su nota dice «al hacer el check-in»— y
            // un adelanto de tour se paga a distancia. Salía en propuestas cuyo primer servicio
            // ya era hoy (su ventana es el mismo día) con un texto que no le tocaba a un tour.
            if ($medio->getTipo() === FinMedioCobroTipo::EFECTIVO) {
                continue;
            }
            $medios[] = $medio->fichaParaCliente();
        }

        return new ComoReservar(
            porcentajeAdelanto: $cotizacion->porcentajeDeAdelanto(),
            fechaPrimerServicio: $inicio?->format('Y-m-d'),
            desdePeru: $desdePeru,
            conTarjeta: $desdePeru !== true,
            medios: $medios,
            politicaDevoluciones: [
                'es' => $this->politica('es'),
                'en' => $this->politica('en'),
            ],
            pagadoUsd: ($pagado = self::pagadoVisible($file, $cotizacion->getPropuesta(), $cotizacion->isPrecioOculto()))['usd'],
            pagadoPorMoneda: $pagado['porMoneda'],
        );
    }

    private function politica(string $idioma): string
    {
        return $this->urls->generate(
            'front_legal',
            ['pagina' => self::PAGINA_DEVOLUCIONES, '_locale' => $idioma],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
