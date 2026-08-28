<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Repository\FinMedioCobroRepository;
use App\Finanzas\Service\FinEnlacePagoService;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsMotivoSinCobro;
use App\Pms\Enum\PmsQueSePide;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use App\Pms\Service\Finance\TipoCambioDelDia;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Compone {@see PmsSituacionDeCobro}: la única fuente sobre qué se le pide a quién.
 *
 * ── Es un COMPOSITOR, no un cálculo nuevo ───────────────────────────────────
 * No reimplementa nada. El importe del adelanto lo da `PmsPrepagoCalculador`, los saldos
 * `PmsTotalesPorMoneda`, los medios `FinMedioCobroRepository::ofrecibles()` y el enlace vivo
 * `PmsPrepagoEnlaceService::pagables()`. Todas esas piezas ya eran únicas y correctas; lo que
 * estaba repetido —y derivaba— era juntarlas.
 *
 * ── El pipeline, y por qué en ESTE orden ────────────────────────────────────
 *
 * ```
 * 0 · ACTOR        no es rama: decide QUÉ objeto se construye (proyección)
 * 1 · COHERENCIA   ⛔ corta: si hay cargos que no suman, no se dan cifras
 * 2 · VIGENCIA     ⛔ corta: cancelada, sólo la penalización
 * 3 · CANAL        FILTRA los cargos reclamables; NO corta
 * 4 · PAGOS        por moneda, sin convertir
 * 5 · POLÍTICA     qué se pide: adelanto antes del check-in, total desde ese día
 * 6 · CÓMO PAGAR   medios ofrecibles + enlace vivo
 * ```
 *
 * ⚠️ **Canal no puede ir primero.** «Airbnb → no hay nada que pedir» es falso: en un canal
 * espejo sí hay extras nuestros —una cena, un traslado— que sí se reclaman, y eso ya está
 * resuelto en `ConsultarCuentaSkill::cuentaDeCanalQueCobra()`. El canal decide **qué cargos
 * son reclamables**, no **si se reclama**. Ponerlo de puerta reintroduce ese bug.
 *
 * ⚠️ **Actor no es una rama, es la proyección.** Dos objetos distintos y no uno con un mapa
 * de visibilidad: un objeto único con metadatos de acceso acaba serializado entero por
 * alguien. Ya pasó con `BuscarReservaSkill` volcando 23 claves.
 *
 * @see docs/Mensajeria.md
 */
final readonly class PmsSituacionDeCobroResolver
{
    public function __construct(
        private PmsPrepagoCalculador $prepago,
        private PmsProcedenciaHuesped $procedencia,
        private PmsPrepagoEnlaceService $enlaces,
        // Sólo para componer la URL pública: `FinEnlacePago` no la guarda, la deriva
        // `urlPublica()` del token. Es su fuente única — ver docs/FinanzasEnlacesPago.md §13.
        private FinEnlacePagoService $enlacesFinanzas,
        private FinMedioCobroRepository $catalogo,
        private TipoCambioDelDia $tipoCambio,
        // El MISMO parámetro que usa `FinEnlacePagoService`, no un literal: el porcentaje ya
        // estaba escrito en tres sitios y éste habría sido el cuarto. Ver docs/FinanzasEnlacesPago.md §7.
        #[Autowire('%finanzas.recargo_tarjeta_porcentaje%')]
        private string $recargoTarjetaPorcentaje,
    ) {
    }

    /** Lo que se le puede decir al HUÉSPED: sin comisión interna ni coste teórico. */
    public function paraHuesped(PmsReserva $reserva): PmsSituacionDeCobro
    {
        return $this->componer($reserva, paraHuesped: true);
    }

    /** Lo que ve el EQUIPO. Misma decisión, más campos. */
    public function paraEquipo(PmsReserva $reserva): PmsSituacionDeCobro
    {
        return $this->componer($reserva, paraHuesped: false);
    }

    private function componer(PmsReserva $reserva, bool $paraHuesped): PmsSituacionDeCobro
    {
        $info = $reserva->getInformacionFinanciera();

        if ($info === null) {
            return $this->nada(PmsMotivoSinCobro::SIN_CARGOS, $paraHuesped);
        }

        $totales = PmsTotalesPorMoneda::de($info);

        // ── 1 · COHERENCIA ──────────────────────────────────────────────────
        // Un cargo sin tipo de cambio no suma, así que cualquier cifra que diéramos estaría
        // incompleta. Se dice que no se puede, en vez de dar un total que miente. Los dos
        // fallos más caros de agosto de 2026 fueron de datos, no de código.
        if ($totales->hayMonedaSinConvertir()) {
            return $this->nada(PmsMotivoSinCobro::DATOS_INCOMPLETOS, $paraHuesped);
        }

        // ── 2 · VIGENCIA ────────────────────────────────────────────────────
        // Cancelada: los cargos de la estancia se conservan pero no suman; sólo cuenta la
        // penalización (§12.7). Si no queda saldo, no hay nada que reclamar.
        //
        // ⚠️ Va ANTES que `hayCargos()`, y el orden importa aunque el huésped vea lo mismo
        // —en los dos casos no hay nada que pagar—. Al revés, una cancelada sin cargos salía
        // como SIN_CARGOS y **tapaba que estaba cancelada**: en una auditoría de 250 reservas,
        // siete canceladas se contaron como «reservas vivas a las que les falta el precio», que
        // es una conclusión falsa sobre datos ciertos. El motivo es para explicar, y explicar
        // de menos es explicar mal.
        if ($info->isActiva() === false && !$totales->quedaAlgoPorCobrar()) {
            return $this->nada(PmsMotivoSinCobro::CANCELADA, $paraHuesped);
        }

        // Viva y sin un solo cargo: a nadie se le ha puesto precio todavía. Esto sí es un
        // hueco que alguien tiene que rellenar, y por eso no debe confundirse con lo de arriba.
        if (!$totales->hayCargos()) {
            return $this->nada(PmsMotivoSinCobro::SIN_CARGOS, $paraHuesped);
        }

        // ── 3 · CANAL ───────────────────────────────────────────────────────
        // Filtra, no corta: en un canal que cobra por nosotros quedan los extras nuestros.
        // `quedaAlgoPorCobrar()` ya los ve, porque el depósito espejo cancela sus cargos.
        if ($this->canalCobraPorNosotros($reserva) && !$totales->quedaAlgoPorCobrar()) {
            return $this->nada(PmsMotivoSinCobro::COBRA_EL_CANAL, $paraHuesped);
        }

        // ── 4 · PAGOS ───────────────────────────────────────────────────────
        //
        // ⚠️ El cruce de monedas va ANTES que todo lo demás de esta etapa, y lo encontró
        // ejecutar el read-model contra producción: GASUNN tiene cargos en USD y un Yape de
        // S/ 223.70 **sin imputar**. Sumando cada moneda por su lado, `quedaAlgoPorCobrar()`
        // dice que sí —los dólares siguen sin pagar— y `haySaldoAFavor()` dice que no
        // —el cuadre convertido sale a cero—. O sea que sin esta guarda se le pedía el total
        // a alguien que ya había pagado.
        //
        // No es un caso degradado: es lo que pasa cada vez que alguien paga en soles una
        // deuda en dólares y nadie ha pulsado «imputar». Lo arregla un humano en un clic
        // (§12.2b); hasta entonces, callar es la única respuesta honesta.
        if ($totales->hayCruceDeMonedas()) {
            return $this->nada(PmsMotivoSinCobro::CRUCE_DE_MONEDAS, $paraHuesped);
        }

        if ($totales->haySaldoAFavor()) {
            return $this->nada(PmsMotivoSinCobro::SALDO_A_FAVOR, $paraHuesped);
        }

        if (!$totales->quedaAlgoPorCobrar()) {
            return $this->nada(PmsMotivoSinCobro::SALDADA, $paraHuesped);
        }

        // ── 5 · POLÍTICA ────────────────────────────────────────────────────
        $queSePide = $this->queSePide($reserva, $info);
        $importes = $queSePide === PmsQueSePide::ADELANTO
            ? $this->importeDelAdelanto($info)
            : $this->importesPendientes($totales, $reserva);

        if ($importes === []) {
            return $this->nada(PmsMotivoSinCobro::SALDADA, $paraHuesped);
        }

        // ── 6 · CÓMO PAGAR ──────────────────────────────────────────────────
        return new PmsSituacionDeCobro(
            queSePide: $queSePide,
            motivo: null,
            importes: $importes,
            medios: $this->medios($reserva, $importes[0]),
            enlacePago: $this->enlaceVivo($reserva),
            paraHuesped: $paraHuesped,
        );
    }

    /**
     * Adelanto o total, y el corte es **el día de check-in incluido**.
     *
     * ⚠️ Regla de negocio decidida el 28/08/2026, y **nueva**: `PmsPrepagoCalculador` nunca
     * ha mirado fechas — sólo sabe si la política pide adelanto y si ya hay algún pago. Desde
     * la mañana del día de llegada se pide el total: un adelanto pierde sentido cuando el
     * huésped ya está entrando, y pedirlo invita a que pague dos veces.
     */
    private function queSePide(PmsReserva $reserva, PmsInformacionFinanciera $info): PmsQueSePide
    {
        $llegada = $reserva->getFechaLlegada();

        // Sin fecha no se puede decidir por tiempo: manda la política, que es lo que hacía
        // el código antes de esta regla.
        $yaLlegoElDia = $llegada !== null
            && (new DateTimeImmutable($llegada->format('Y-m-d'))) <= new DateTimeImmutable('today');

        if ($yaLlegoElDia) {
            return PmsQueSePide::TOTAL;
        }

        // `pendiente()` devuelve null en cuanto hay CUALQUIER pago: ese pago era el adelanto.
        return $this->prepago->pendiente($info) !== null
            ? PmsQueSePide::ADELANTO
            : PmsQueSePide::TOTAL;
    }

    /**
     * El adelanto, que es ESCALAR y va en la moneda de la cabecera.
     *
     * Asimetría deliberada con el saldo: el adelanto es una petición mono-moneda —«adelanta
     * US$ 32»— y `PmsPrepagoCalculador::base()` sí convierte para calcularlo. El saldo, en
     * cambio, es por moneda y no se convierte nunca (§12.2b). Son dos cosas distintas y por
     * eso tienen esquemas distintos.
     *
     * @return list<PmsImporteMoneda>
     */
    private function importeDelAdelanto(PmsInformacionFinanciera $info): array
    {
        $pendiente = $this->prepago->pendiente($info);

        if ($pendiente === null) {
            return [];
        }

        $moneda = $info->getMoneda();

        return [new PmsImporteMoneda(
            moneda: $moneda?->getId() ?? 'USD',
            simbolo: $moneda?->getSimbolo(),
            importe: $pendiente['monto'],
            enSoles: $this->enSoles($pendiente['monto'], $moneda?->getId() ?? 'USD', $info->getReserva()),
        )];
    }

    /**
     * Lo que se debe, **una entrada por moneda y sin convertir**.
     *
     * @return list<PmsImporteMoneda>
     */
    private function importesPendientes(PmsTotalesPorMoneda $totales, PmsReserva $reserva): array
    {
        $salida = [];

        foreach ($totales->porMoneda as $moneda => $cifras) {
            if ((float) $cifras['saldo'] <= 0.0) {
                continue;
            }

            $salida[] = new PmsImporteMoneda(
                moneda: $moneda,
                simbolo: null,
                importe: $cifras['saldo'],
                enSoles: $this->enSoles($cifras['saldo'], $moneda, $reserva),
            );
        }

        return $salida;
    }

    /**
     * Los medios que se le pueden ofrecer, **con lo que entrega por cada uno**.
     *
     * ── El join que faltaba ─────────────────────────────────────────────────
     * El catálogo sabe QUÉ medios valen; el importe sale de otro sitio. Cruzarlos aquí es lo
     * que evita que el huésped tenga que sumar el 5.5 % de cabeza — el efectivo lleva el
     * neto, la tarjeta lo lleva ya dentro.
     *
     * ⚠️ `ofrecibles()` recibe la procedencia TERNARIA y los días que faltan: con `null` no
     * se filtra por audiencia (se enseñan todos y elige el huésped) y con los días fuera de
     * plazo desaparece Western Union sola. Nada de eso se decide aquí.
     *
     * @return list<PmsMedioDeCobro>
     */
    private function medios(PmsReserva $reserva, PmsImporteMoneda $importe): array
    {
        $desdePeru = $this->procedencia->pagaDesdePeru($reserva);
        $dias = $this->diasHastaLlegada($reserva);

        // Agrupado por TIPO y no una entrada por fila del catálogo. `ofrecibles()` devuelve
        // una fila por CUENTA —tres bancos son tres filas de «transferencia»— y sin agrupar
        // el resumen de una reserva real listaba doce opciones. El huésped elige primero la
        // forma («te transfiero») y después la cuenta; las cuentas son del detalle.
        $porTipo = [];

        foreach ($this->catalogo->ofrecibles($desdePeru, $dias) as $medio) {
            $porTipo[$medio->getTipo()->value][] = $medio;
        }

        $salida = [];

        foreach ($porTipo as $codigo => $fichas) {
            $salida[] = new PmsMedioDeCobro(
                codigo: (string) $codigo,
                etiqueta: $fichas[0]->getTipo()->label(),
                importe: $importe->importe,
                enSoles: $importe->enSoles,
                recargoPorcentaje: '0.00',
                fichas: $fichas,
            );
        }

        // La tarjeta no está en `FinMedioCobro` —no tiene titular ni número que teclear— y es
        // el único medio con recargo. Va al final: es la opción cara, y abrir por ella empuja
        // a pagar de más a quien podía transferir.
        $salida[] = $this->medioTarjeta($importe, $reserva);

        return $salida;
    }

    /** La tarjeta, con el recargo YA DENTRO del importe. */
    private function medioTarjeta(PmsImporteMoneda $importe, PmsReserva $reserva): PmsMedioDeCobro
    {
        $pct = $this->recargoTarjetaPorcentaje;
        $conRecargo = number_format((float) $importe->importe * (1 + (float) $pct / 100), 2, '.', '');

        return new PmsMedioDeCobro(
            codigo: PmsMedioPago::TARJETA_CREDITO->value,
            etiqueta: PmsMedioPago::TARJETA_CREDITO->label(),
            importe: $conRecargo,
            enSoles: $this->enSoles($conRecargo, $importe->moneda, $reserva),
            recargoPorcentaje: $pct,
            fichas: [],
        );
    }

    /**
     * La equivalencia en soles, **sólo si sabemos que paga desde Perú**.
     *
     * ⚠️ `pagaDesdePeru()` es ternaria y su `null` significa «no se sabe» —el saneador de
     * teléfonos antepone `51` a cualquier móvil de 9 dígitos—. Con `null` NO se pone
     * equivalencia: una conversión a alguien que no paga en soles confunde más que ayuda.
     *
     * Es presentación, no contabilidad: no toca el saldo y va del TC del día, igual que el
     * «son unos S/ 340» del formulario de cargo.
     */
    private function enSoles(string $importe, string $moneda, ?PmsReserva $reserva): ?string
    {
        if ($moneda === 'PEN' || $reserva === null) {
            return null;
        }

        if ($this->procedencia->pagaDesdePeru($reserva) !== true) {
            return null;
        }

        // `TipoCambioDelDia` memoriza por fecha desde el 28/08/2026, así que llamarlo por
        // cada importe y cada medio no multiplica las consultas.
        $tc = $this->tipoCambio->venta();

        if ($tc === null || (float) $tc <= 0.0) {
            return null;
        }

        return number_format((float) $importe * (float) $tc, 2, '.', '');
    }

    /** Días hasta la llegada, o `null` si no se sabe. Lo consume `llegaATiempo()`. */
    private function diasHastaLlegada(PmsReserva $reserva): ?int
    {
        $llegada = $reserva->getFechaLlegada();

        if ($llegada === null) {
            return null;
        }

        $hoy = new DateTimeImmutable('today');
        $dia = new DateTimeImmutable($llegada->format('Y-m-d'));

        return (int) $hoy->diff($dia)->format('%r%a');
    }

    /** El enlace que el huésped todavía puede pagar, si lo hay. */
    private function enlaceVivo(PmsReserva $reserva): ?string
    {
        foreach ($this->enlaces->pagables($reserva) as $enlace) {
            return $this->enlacesFinanzas->urlPublica($enlace);
        }

        return null;
    }

    /**
     * ⚠️ Hoy sale de la CONSTANTE `PmsChannel::CANAL_PAGO_TOTAL`, no de una columna.
     *
     * Se aísla en este método para que el día que sea `PmsChannel::cobraPorNosotros()` haya
     * un solo sitio que tocar aquí. Mientras siga siendo constante, un canal nuevo que cobre
     * por nosotros exige desplegar código.
     */
    private function canalCobraPorNosotros(PmsReserva $reserva): bool
    {
        $canal = $reserva->getChannel()?->getId();

        return $canal !== null && in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true);
    }

    private function nada(PmsMotivoSinCobro $motivo, bool $paraHuesped): PmsSituacionDeCobro
    {
        return new PmsSituacionDeCobro(
            queSePide: PmsQueSePide::NADA,
            motivo: $motivo,
            importes: [],
            medios: [],
            enlacePago: null,
            paraHuesped: $paraHuesped,
        );
    }
}
