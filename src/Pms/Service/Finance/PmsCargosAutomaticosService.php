<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Tarifa\PmsTarifaCalculadora;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Genera los cargos de una estancia DIRECTA a partir del tarifario.
 *
 * Una reserva OTA recibe sus importes de Beds24 (§11); una directa no recibe nada, y hasta
 * ahora había que teclearlos. Este servicio los arma solos al crear la estancia:
 *
 *   · ALOJAMIENTO — suma del precio de CADA DÍA del tarifario, no una tarifa plana. Se usa el
 *     mismo motor que pinta el calendario de tarifas (TarifaPricingEngine), así que respeta
 *     temporadas, prioridades y solapamientos exactamente igual que lo que ve el operador.
 *   · LIMPIEZA — lo que diga la unidad (`PmsUnidad::costoLimpieza()`): importe fijo o
 *     porcentaje, según su flag. En 0 no se genera el cargo.
 *   · SERVICIO — **no se genera**: en las reservas directas se exonera.
 *
 * Todo queda como cargo MANUAL (sin `beds24ItemId`), así que el operador puede corregirlo o
 * borrarlo sin pelearse con la sincronización.
 */
final class PmsCargosAutomaticosService
{
    /**
     * Descripciones canónicas de los cargos de horario extra. Son la MARCA por la
     * que se reconocen después para retirarlos: no se tocan sin migrar los cargos
     * existentes (ver sincronizarExtras()).
     */
    public const string DESC_SALIDA_TARDIA = 'Salida tardía (noche bloqueada)';
    public const string DESC_ENTRADA_TEMPRANA = 'Entrada temprana (noche bloqueada)';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsTarifaCalculadora $tarifas,
        private readonly MonedaResolver $monedaResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * ¿Esta estancia debe estrenar cargos automáticos?
     *
     * Sólo las DIRECTAS: una OTA los recibe del canal y duplicarlos falsearía el saldo. Los
     * bloqueos tampoco — no son una venta.
     */
    public function aplica(PmsEventoCalendario $evento): bool
    {
        $canal = $evento->getChannel()?->getId();
        if ($canal !== null && $canal !== PmsChannel::CODIGO_DIRECTO) {
            return false;
        }

        // Ni los bloqueos ni las EXTENSIONES: no son ventas. La extensión, además,
        // ya tiene su propio cargo en la estancia que la generó (sincronizarExtras()),
        // y si entrara aquí estrenaría alojamiento y limpieza por una noche fantasma.
        // `esExtension()` y no el estado: una extensión retirada queda en
        // `cancelada` y tampoco debe estrenar cargos si alguien la revive.
        if ($evento->getEstado()?->getId() === PmsEventoEstado::CODIGO_BLOQUEO || $evento->esExtension()) {
            return false;
        }

        return $evento->getPmsUnidad() !== null
            && $evento->getInicio() !== null
            && $evento->getFin() !== null;
    }

    /**
     * Crea los cargos de la estancia. NO hace flush: lo hace quien llama.
     *
     * Es idempotente por estancia: si ya tiene cargos imputados, no añade nada. Así un
     * segundo guardado del drawer no duplica el alojamiento.
     */
    public function generarParaEvento(PmsEventoCalendario $evento, PmsInformacionFinanciera $info): void
    {
        if (!$this->aplica($evento) || $this->yaTieneCargos($evento, $info)) {
            return;
        }

        $moneda = $info->getMoneda() ?? $this->monedaResolver->resolve(null);

        // Es el IMPORTE del alojamiento, no un número de noches. Se llamaba `$noches`, que es
        // exactamente el nombre que hace que alguien lo multiplique por una tarifa.
        $alojamiento = $this->calcularAlojamiento($evento);

        if ($alojamiento !== null && $alojamiento > 0) {
            $this->crearCargo(
                info: $info,
                evento: $evento,
                tipo: PmsTipoCargo::ALOJAMIENTO,
                descripcion: 'Alojamiento',
                importe: number_format($alojamiento, 2, '.', ''),
                moneda: $moneda,
            );
        }

        // 👥 Las personas que no caben en la tarifa. Cargo APARTE y no sumado al alojamiento:
        // el huésped tiene derecho a ver por qué paga más que la tarifa anunciada, y metido
        // dentro del alojamiento falsearía además el precio por noche de cualquier informe.
        $suplemento = $this->calcularSuplementoPax($evento);

        if ($suplemento !== null && $suplemento > 0) {
            $unidad = $evento->getPmsUnidad();

            $this->crearCargo(
                info: $info,
                evento: $evento,
                tipo: PmsTipoCargo::ALOJAMIENTO,
                descripcion: sprintf(
                    'Persona adicional (%d × %s por noche)',
                    max(0, $this->paxDe($evento) - (int) $unidad?->getPaxIncluidos()),
                    $unidad?->getPrecioPaxAdicional() ?? '0.00'
                ),
                importe: number_format($suplemento, 2, '.', ''),
                moneda: $moneda,
            );
        }

        // 🧹 LA LIMPIEZA SALE DE LA UNIDAD, no de una constante.
        //
        // Era `TARIFA_LIMPIEZA = '15.00'`, fija e incondicional, y coincidía con lo cotizado
        // sólo porque todas las casitas tenían 15.00 puestos. En cuanto una pasara a
        // porcentaje, o a otro importe, o a cero, se cotizaba una cosa y se cobraba otra —y
        // el cargo se creaba hasta en las que no cobran limpieza—.
        //
        // ⚠️ `$alojamiento` es el IMPORTE del alojamiento; la variable de arriba se llamaba
        // `$noches` y contenía esto mismo, que es una trampa para quien lea rápido. Se le pasa
        // junto con el suplemento porque la base del porcentaje es alojamiento + suplemento
        // (regla en `PmsUnidad::costoLimpieza()`, que es quien la decide).
        $limpieza = $evento->getPmsUnidad()?->costoLimpieza(
            $this->nochesDe($evento),
            ($alojamiento ?? 0.0) + ($suplemento ?? 0.0)
        ) ?? 0.0;

        if ($limpieza > 0.0) {
            $this->crearCargo(
                info: $info,
                evento: $evento,
                tipo: PmsTipoCargo::LIMPIEZA,
                descripcion: 'Suplemento de limpieza',
                importe: number_format($limpieza, 2, '.', ''),
                moneda: $moneda,
            );
        }

        // SERVICIO: intencionadamente ausente — se exonera en las reservas directas.
    }

    /**
     * Pone al día los cargos de HORARIO EXTRA de una estancia: entrada temprana y
     * salida tardía.
     *
     * Las dos bloquean una noche que ya no se puede vender —la víspera y la del
     * día de salida—: de eso se encarga `PmsExtensionEstanciaService`, creando un
     * evento hermano. Aquí va solo el dinero, y las dos se cobran igual: **el cargo
     * se crea con importe CERO y lo valora el operador**. Cuánto vale entrar antes o salir después se
     * negocia caso por caso; sugerir un precio sería peor que no poner ninguno,
     * porque se acabaría cobrando el que el sistema inventó.
     *
     * Van como SERVICIO y no como ALOJAMIENTO a propósito: no son noches dormidas,
     * y mezclarlas con el alojamiento falsearía las noches vendidas y el ADR — que
     * es justo lo que se quería arreglar al dejar de crear estancias de una noche.
     *
     * Es idempotente y reversible: llamarlo dos veces no duplica nada, y al
     * desmarcar la casilla retira el cargo. NO hace flush.
     */
    public function sincronizarExtras(PmsEventoCalendario $evento, PmsInformacionFinanciera $info): void
    {
        $this->sincronizarExtra($evento, $info, $evento->isEntradaTemprana(), self::DESC_ENTRADA_TEMPRANA);
        $this->sincronizarExtra($evento, $info, $evento->isSalidaTardia(), self::DESC_SALIDA_TARDIA);
    }

    /** Crea o retira UN cargo de horario extra según su casilla. */
    private function sincronizarExtra(
        PmsEventoCalendario $evento,
        PmsInformacionFinanciera $info,
        bool $activo,
        string $descripcion,
    ): void {
        $existente = $this->buscarCargoExtra($evento, $info, $descripcion);

        if (!$activo) {
            // Sólo se retira si sigue en CERO, o sea si nadie llegó a valorarlo.
            //
            // Un cargo con importe es dinero facturado —y probablemente cobrado—:
            // borrarlo al desmarcar la casilla dejaba el pago huérfano y la reserva
            // con saldo negativo, sin rastro de por qué. Si el horario extra se
            // anula de verdad, el operador borra el cargo a mano, que es una
            // decisión sobre dinero y le toca a él.
            if ($existente !== null && (float) ($existente->getTotalLinea() ?? $existente->getMonto() ?? '0') === 0.0) {
                $info->removeCargo($existente);
                $this->em->remove($existente);
            }

            return;
        }

        // Ya está: no se toca. Si el operador le puso importe, es el suyo el que manda.
        if ($existente !== null) {
            return;
        }

        // La casita va en la descripción porque una reserva puede tener DOS
        // estancias con salida tardía: sin ella, el panel financiero mostraría dos
        // líneas idénticas y el operador no sabría cuál está valorando. El cargo
        // sigue apuntando a su evento, que es lo que manda; esto es para leerlo.
        $casita = $evento->getPmsUnidad()?->getNombre();

        $this->crearCargo(
            info: $info,
            evento: $evento,
            tipo: PmsTipoCargo::SERVICIO,
            descripcion: $descripcion . ($casita ? ' · ' . $casita : ''),
            importe: '0.00',
            moneda: $info->getMoneda() ?? $this->monedaResolver->resolve(null),
        );
    }

    /**
     * El cargo de horario extra de esta estancia, si existe.
     *
     * Se reconoce por tipo + descripción canónica: `PmsCargoFinanciero` no tiene
     * un campo de "origen", y su `esAutomatico` significa otra cosa (ver su
     * docblock). Los cargos que vienen de Beds24 (`beds24ItemId`) quedan fuera:
     * esos los manda el canal y no se tocan.
     */
    private function buscarCargoExtra(
        PmsEventoCalendario $evento,
        PmsInformacionFinanciera $info,
        string $descripcion,
    ): ?PmsCargoFinanciero {
        foreach ($info->getCargos() as $cargo) {
            // Por PREFIJO: la descripción lleva la casita detrás (ver crearCargo).
            // Así los cargos creados antes de añadirla se siguen reconociendo.
            if ($cargo->getEvento() === $evento
                && $cargo->getTipoCargo() === PmsTipoCargo::SERVICIO
                && str_starts_with((string) $cargo->getDescripcion(), $descripcion)
                && $cargo->getBeds24ItemId() === null
            ) {
                return $cargo;
            }
        }

        return null;
    }

    /** ¿Ya se le generaron cargos a esta estancia? */
    private function yaTieneCargos(PmsEventoCalendario $evento, PmsInformacionFinanciera $info): bool
    {
        foreach ($info->getCargos() as $cargo) {
            if ($cargo->getEvento() === $evento) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suma el precio de cada noche del tarifario.
     *
     * El intervalo es [llegada, salida) — la noche de salida no se cobra —, que es justo el
     * contrato del flattener (`to` exclusivo).
     *
     * Devuelve null (y no se crea el cargo) si NO se puede precisar todas las noches: es
     * preferible que el operador teclee el importe a cobrarle de menos sin que nadie se entere.
     */
    private function calcularAlojamiento(PmsEventoCalendario $evento): ?float
    {
        return $this->estimarAlojamiento(
            $evento->getPmsUnidad(),
            $evento->getInicio(),
            $evento->getFin()
        );
    }

    /**
     * El mismo cálculo, sin necesidad de un evento.
     *
     * Lo usa `CrearReservaSkill` para PREVISUALIZAR cuánto saldría del tarifario antes de crear
     * nada, que es lo que permite al operador decidir si prefiere poner un precio cerrado. Es
     * público y delega aquí `calcularAlojamiento()` para que no haya dos fórmulas: la que se
     * enseña y la que se cobra tienen que ser la misma.
     */
    public function estimarAlojamiento(
        ?\App\Pms\Entity\PmsUnidad $unidad,
        ?\DateTimeInterface $inicio,
        ?\DateTimeInterface $fin
    ): ?float {
        if (!$inicio || !$fin || !$unidad || $fin <= $inicio) {
            return null;
        }

        try {
            // 🐛 Esto ERA una consulta propia con `createQueryBuilder()->setParameter('unidad',
            // $unidad)`, y el id es un UUID BINARY(16): en DQL ese parámetro se serializa mal y
            // la consulta devolvía CERO rangos **sin fallar**. Consecuencia: todas las
            // estancias se cobraban a la tarifa base, ignorando el tarifario. Medido en la
            // Casita 1 del 10 al 19 de agosto — 630.00 cobrados contra 565.00 reales.
            //
            // Ahora lo resuelve `PmsTarifaCalculadora`, que ya tiene el `findBy()` correcto y
            // es la misma pieza que usan `consultar_tarifas` y `consultar_disponibilidad`. Una
            // fórmula, no tres.
            $preciosDiarios = $this->tarifas->preciosPorNoche($unidad, $inicio, $fin);

            // El flattener OMITE los días que no logra precisar; si falta alguno, el total sería
            // una estancia más corta de la real. Se prefiere no cobrar nada a cobrar de menos.
            // A DÍA, no a instante: la estancia va de las 14:00 a las 10:00, así que un
            // diff() crudo de dos noches devolvería "1 día y 20 horas" → 1. El flattener
            // trunca a medianoche (§12.5.5), y aquí hay que contar igual.
            $nochesEsperadas = (int) $this->aDia($inicio)->diff($this->aDia($fin))->days;
            if (count($preciosDiarios) < $nochesEsperadas) {
                $this->logger->info('Tarifario incompleto para la estancia: no se genera cargo de alojamiento.', [
                    'unidad' => (string) $unidad->getId(),
                    'nochesEsperadas' => $nochesEsperadas,
                    'nochesConPrecio' => count($preciosDiarios),
                ]);
                return null;
            }

            $total = 0.0;
            foreach ($preciosDiarios as $dia) {
                $total += (float) ($dia['price'] ?? 0);
            }

            return $total;
        } catch (Throwable $e) {
            // Nunca romper el guardado de la reserva por el tarifario: se avisa y se sigue
            // sin cargo de alojamiento, que el operador puede añadir a mano.
            $this->logger->error('Fallo calculando el alojamiento desde el tarifario.', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Cuántas personas duermen en la estancia.
     *
     * Adultos + niños: para el suplemento cuentan igual, porque lo que se cobra es ocupar una
     * plaza. Si algún día los niños dejan de contar, se cambia AQUÍ y en
     * {@see \App\Pms\Entity\PmsUnidad::suplementoPorPax()} — no en cada sitio que sume pax.
     */
    private function paxDe(PmsEventoCalendario $evento): int
    {
        return (int) $evento->getCantidadAdultos() + (int) $evento->getCantidadNinos();
    }

    /**
     * Lo que suman las personas por encima de las que cubre la tarifa.
     *
     * La regla vive en la unidad ({@see \App\Pms\Entity\PmsUnidad::suplementoPorPax()}); aquí
     * sólo se le da el pax y las noches. `null` cuando no hay estancia calculable, y `0.0`
     * cuando la casita no cobra suplemento o el grupo cabe: no cobrar de más es el fallo seguro.
     */
    private function calcularSuplementoPax(PmsEventoCalendario $evento): ?float
    {
        $unidad = $evento->getPmsUnidad();
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        if ($unidad === null || $inicio === null || $fin === null || $fin <= $inicio) {
            return null;
        }

        // A DÍA, igual que el alojamiento: la estancia va de las 14:00 a las 10:00 y un diff()
        // crudo de dos noches devolvería «1 día y 20 horas» → 1 (§12.5.5).
        $noches = (int) $this->aDia($inicio)->diff($this->aDia($fin))->days;

        return $unidad->suplementoPorPax($this->paxDe($evento), $noches);
    }

    /** Trunca a medianoche conservando la fecha de pared (mismo criterio que el flattener). */
    /**
     * Las noches de la estancia, contadas A DÍA.
     *
     * Mismo criterio que el alojamiento y el suplemento: la estancia va de las 14:00 a las
     * 10:00 y un `diff()` crudo de dos noches devuelve «1 día y 20 horas» → 1 (§12.5.5).
     */
    private function nochesDe(PmsEventoCalendario $evento): int
    {
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        if ($inicio === null || $fin === null || $fin <= $inicio) {
            return 0;
        }

        return (int) $this->aDia($inicio)->diff($this->aDia($fin))->days;
    }

    private function aDia(DateTimeInterface $dt): DateTimeImmutable
    {
        return new DateTimeImmutable($dt->format('Y-m-d') . ' 00:00:00');
    }

    private function crearCargo(
        PmsInformacionFinanciera $info,
        PmsEventoCalendario $evento,
        PmsTipoCargo $tipo,
        string $descripcion,
        string $importe,
        object $moneda,
    ): void {
        // Sin beds24ItemId => cargo manual: editable y borrable por el operador (§11.5).
        $cargo = new PmsCargoFinanciero();
        $cargo->setTipoCargo($tipo);
        $cargo->setDescripcion($descripcion);
        $cargo->setTotalLinea($importe);
        $cargo->setMonto($importe);
        $cargo->setMoneda($moneda);
        $cargo->setEvento($evento);

        $info->addCargo($cargo);
        $this->em->persist($cargo);
    }
}
