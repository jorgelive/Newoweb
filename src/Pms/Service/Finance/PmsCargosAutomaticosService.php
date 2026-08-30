<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Tarifa\PmsTarifaCalculadora;
use App\Entity\Maestro\MaestroMoneda;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lo que el tarifario dice de una estancia DIRECTA, y los cargos que sí nacen solos.
 *
 * Una reserva OTA recibe sus importes de Beds24 (§11); una directa no recibe nada.
 *
 * ── Una estancia directa nueva NACE SIN CARGOS ──────────────────────────────
 * Hasta el 15/08/2026 este servicio estrenaba tres cargos con importe —alojamiento del
 * tarifario, suplemento por persona y limpieza—; después, una sola línea de LIMPIEZA en 0.00.
 * Desde el 25/08/2026 no se crea ninguna.
 *
 * El motivo es el mismo que retiró los importes: **el precio de una venta directa no lo pone el
 * tarifario, lo pone quien vende**. La línea en cero tampoco resolvía eso —era un hueco que
 * había que rellenar o borrar—, y encima llegaba etiquetada como LIMPIEZA a una estancia cuyo
 * primer cargo casi nunca es la limpieza.
 *
 * ── El tarifario no se pierde: se enseña ────────────────────────────────────
 * Lo que antes se cobraba a ciegas se ofrece como referencia en {@see self::costoTeorico()},
 * que el panel financiero pinta en un tooltip en la cabecera de la estancia. **No depende de
 * que exista ningún cargo**: se calcula del tarifario y de la ficha de la casita, y viaja
 * siempre. Quien vende ve lo que «debería» costar —noches, personas de más y limpieza,
 * desglosado— y decide. La diferencia entre sugerir y cobrar es toda la diferencia.
 *
 * ── Lo que SÍ sigue naciendo solo ───────────────────────────────────────────
 * El horario extra ({@see self::sincronizarExtras()}), porque ahí el cargo no es un precio que
 * nadie ha acordado sino la consecuencia de una noche bloqueada; y los importes que una PERSONA
 * ha aprobado ({@see self::escribirAprobados()}), que es la vía de las skills del agente.
 *
 * SERVICIO sigue sin generarse: en las reservas directas se exonera.
 *
 * Los cargos que este servicio escribe quedan MANUALES (sin `beds24ItemId`), así que el operador
 * los corrige o los borra sin pelearse con la sincronización.
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
     * Escribe importes que una PERSONA ha aprobado.
     *
     * Es la excepción a que una estancia directa nazca sin cargos: aquí el sistema sí pone
     * precio, porque el operador ha visto el desglose y ha dicho que sí. Lo usan las skills del
     * agente (`crear_reserva`, `crear_estancia`), que son las únicas vías con un paso de
     * aprobación explícito.
     *
     * Vive aquí y no en cada skill para que haya UNA forma de escribir cargos de una estancia
     * directa. Con una copia por skill, la primera corrección se quedaría en una de las dos.
     *
     * Retira antes las líneas en cero de esta estancia — y **sólo si siguen en cero**: con
     * importe es dinero que alguien valoró, y eso no se pisa. Ya no las crea nadie, pero las
     * reservas anteriores al 25/08/2026 arrastran la suya y escribir encima dejaría el hueco
     * viejo debajo del importe nuevo. NO hace flush: lo hace quien llama.
     *
     * @param list<array{concepto: string, importe: string, origen?: string, tipo: PmsTipoCargo}> $lineas
     *
     * @return list<string> Qué se escribió, para contárselo al operador.
     */
    public function escribirAprobados(
        PmsEventoCalendario $evento,
        PmsInformacionFinanciera $info,
        array $lineas,
    ): array {
        foreach ($info->getCargos() as $cargo) {
            $enCero = (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0') === 0.0;

            if ($cargo->getEvento() === $evento && $enCero) {
                $info->removeCargo($cargo);
                $this->em->remove($cargo);
            }
        }

        $moneda = $info->getMoneda() ?? $this->monedaResolver->resolve(null);
        $hechos = [];

        foreach ($lineas as $linea) {
            $origen = $linea['origen'] ?? 'aprobado por el operador';

            // Una línea en 0.00 es informativa —«limpieza PERDONADA»— y no un cargo: escribirla
            // llenaría la cuenta de ceros que no significan nada.
            if ((float) $linea['importe'] === 0.0) {
                $hechos[] = sprintf('%s: no se cobra (%s).', $linea['concepto'], $origen);
                continue;
            }

            $this->crearCargo(
                info: $info,
                evento: $evento,
                tipo: $linea['tipo'],
                descripcion: $linea['concepto'],
                importe: $linea['importe'],
                moneda: $moneda,
            );

            $hechos[] = sprintf('%s: %s (%s).', $linea['concepto'], $linea['importe'], $origen);
        }

        return $hechos;
    }

    /**
     * Lo que ESTA estancia costaría según el tarifario y la ficha de la casita.
     *
     * Es una referencia para quien vende, no un importe a cobrar: sale desglosado para poder
     * discutirlo —«tres noches a 24, dos personas de más, la limpieza»— en vez de un total
     * redondo que no se sabe de dónde viene. Lo pinta el panel financiero en un tooltip en la
     * cabecera de la estancia, y **no cuelga de ningún cargo**: se calcula aquí, viaja siempre
     * y se ve antes de que nadie haya tecleado el primer importe, que es cuando sirve.
     *
     * Devuelve `null` cuando no hay nada que estimar (sin casita, sin fechas, o una estancia que
     * no es una venta). Y **`alojamiento` puede venir a `null` con el resto relleno**: si al
     * tarifario le falta alguna noche se prefiere no enseñar un alojamiento corto —que se leería
     * como el precio de la estancia entera— a enseñar uno falso.
     *
     * `porNoche` sólo se rellena si TODAS las noches valen lo mismo. Con temporada alta de por
     * medio no hay un «precio por noche» que enseñar, y escribir la media invitaría a
     * multiplicarla por las noches y a no cuadrar con el total.
     *
     * @return array{
     *     moneda: ?string,
     *     alojamiento: array{noches: int, porNoche: ?string, importe: string}|null,
     *     paxAdicional: array{personas: int, noches: int, porPersonaNoche: string, importe: string}|null,
     *     limpieza: array{importe: string, esPorcentaje: bool}|null,
     *     total: string
     * }|null
     */
    public function costoTeorico(PmsEventoCalendario $evento): ?array
    {
        if (!$this->aplica($evento)) {
            return null;
        }

        $unidad = $evento->getPmsUnidad();
        $noches = $this->nochesDe($evento);

        if ($unidad === null || $noches < 1) {
            return null;
        }

        $diarios = $this->preciosDeNoches($unidad, $evento->getInicio(), $evento->getFin());
        $alojamiento = null;
        $moneda = null;

        if ($diarios !== null) {
            $precios = array_map(static fn (array $d): float => $d['price'], $diarios);
            $moneda = $diarios[0]['currency'] ?? null;
            $importe = array_sum($precios);

            $alojamiento = [
                'noches' => count($precios),
                // `array_unique` sobre los precios: una sola entrada = todas las noches iguales.
                'porNoche' => count(array_unique($precios, SORT_NUMERIC)) === 1
                    ? number_format($precios[0], 2, '.', '')
                    : null,
                'importe' => number_format($importe, 2, '.', ''),
            ];
        }

        $baseAlojamiento = $alojamiento === null ? 0.0 : (float) $alojamiento['importe'];

        // Las personas por encima de las que cubre la tarifa. La regla la decide la unidad; aquí
        // sólo se descompone para poder enseñarla.
        $paxExtra = max(0, $this->paxDe($evento) - (int) $unidad->getPaxIncluidos());
        $suplemento = $unidad->suplementoPorPax($this->paxDe($evento), $noches);
        $paxAdicional = $paxExtra > 0 && $suplemento > 0.0 ? [
            'personas' => $paxExtra,
            'noches' => $noches,
            'porPersonaNoche' => $unidad->getPrecioPaxAdicional(),
            'importe' => number_format($suplemento, 2, '.', ''),
        ] : null;

        // La base del porcentaje es alojamiento + suplemento, tal como la define
        // `PmsUnidad::costoLimpieza()`. Con el alojamiento incalculable esa base es incompleta,
        // así que una limpieza a porcentaje saldría corta: se omite en vez de mentir.
        $limpiezaImporte = $unidad->costoLimpieza($noches, $baseAlojamiento + $suplemento);
        $esPorcentaje = $unidad->limpiezaEsPorcentaje();
        $limpieza = $limpiezaImporte > 0.0 && !($esPorcentaje && $alojamiento === null) ? [
            'importe' => number_format($limpiezaImporte, 2, '.', ''),
            'esPorcentaje' => $esPorcentaje,
        ] : null;

        $total = $baseAlojamiento + $suplemento + (float) ($limpieza['importe'] ?? 0);

        return [
            'moneda' => $moneda,
            'alojamiento' => $alojamiento,
            'paxAdicional' => $paxAdicional,
            'limpieza' => $limpieza,
            'total' => number_format($total, 2, '.', ''),
        ];
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

    /**
     * El mismo cálculo, sin necesidad de un evento.
     *
     * Lo usa `CrearReservaSkill` para PREVISUALIZAR cuánto saldría del tarifario antes de crear
     * nada, que es lo que permite al operador decidir si prefiere poner un precio cerrado.
     *
     * Delega en `preciosDeNoches()`, la misma lectura que descompone `costoTeorico()`: lo que
     * enseña la skill y lo que enseña el tooltip del panel salen del mismo sitio.
     */
    public function estimarAlojamiento(
        ?\App\Pms\Entity\PmsUnidad $unidad,
        ?\DateTimeInterface $inicio,
        ?\DateTimeInterface $fin
    ): ?float {
        $diarios = $this->preciosDeNoches($unidad, $inicio, $fin);

        if ($diarios === null) {
            return null;
        }

        $total = 0.0;
        foreach ($diarios as $dia) {
            $total += $dia['price'];
        }

        return $total;
    }

    /**
     * El precio de CADA noche de la estancia, o `null` si no se puede precisar entera.
     *
     * Es la pieza común de las dos lecturas del tarifario —el total que se cobraba y el desglose
     * que ahora se enseña—, y está separada justo para que no haya dos: una fórmula que suma y
     * otra que descompone acabarían dando cifras distintas el día que una de las dos cambie.
     *
     * @return list<array{price: float, currency?: string|null, minStay?: int|null, sourceId?: string}>|null
     */
    private function preciosDeNoches(
        ?\App\Pms\Entity\PmsUnidad $unidad,
        ?\DateTimeInterface $inicio,
        ?\DateTimeInterface $fin
    ): ?array {
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
            // una estancia más corta de la real. Se prefiere no enseñar nada a quedarse corto.
            // A DÍA, no a instante: la estancia va de las 14:00 a las 10:00, así que un
            // diff() crudo de dos noches devolvería "1 día y 20 horas" → 1. El flattener
            // trunca a medianoche (§12.5.5), y aquí hay que contar igual.
            $nochesEsperadas = (int) $this->aDia($inicio)->diff($this->aDia($fin))->days;
            if (count($preciosDiarios) < $nochesEsperadas) {
                $this->logger->info('Tarifario incompleto para la estancia: no se puede estimar el alojamiento.', [
                    'unidad' => (string) $unidad->getId(),
                    'nochesEsperadas' => $nochesEsperadas,
                    'nochesConPrecio' => count($preciosDiarios),
                ]);

                return null;
            }

            return array_values($preciosDiarios);
        } catch (Throwable $e) {
            // Nunca romper el guardado de la reserva por el tarifario: se avisa y se sigue sin
            // estimación, que el operador puede teclear a mano.
            $this->logger->error('Fallo leyendo el tarifario de la estancia.', ['exception' => $e]);

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
        MaestroMoneda $moneda,
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
