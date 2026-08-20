<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Calendar\Service\CalendarResourceCatalog;
use App\Pms\Service\Message\TelefonoDeContacto;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use Doctrine\DBAL\ArrayParameterType;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Variante de PmsEventosRawCalendarProvider para consumidores API/SPA (Vue).
 *
 * Diferencias respecto al provider legacy (EasyAdmin):
 * - No genera urledit/urlshow (no depende de rutas de EasyAdmin ni del router de Symfony).
 * - No depende de runtime_returnTo / current_page.
 * - No hace chequeos de ROLE_* para decidir si expone un link (eso es responsabilidad
 *   de la API REST real que consuma el id, no del calendario).
 * - Expone "context" + ids crudos en extendedProps para que el frontend arme su propia
 *   navegación (reserva vs bloqueo directo).
 */
final class PmsEventosSpaCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly CalendarResourceCatalog $resourceCatalog,
        private readonly TelefonoDeContacto $telefonos,
    ) {}

    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'pms_eventos_spa');
    }

    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $eventos = $this->fetchEventos($from, $to, $config);
        $finanzas = $this->fetchFinanzas($eventos);
        $conversaciones = $this->fetchConversaciones($eventos);
        $out = [];

        foreach ($eventos as $evento) {
            if (!$evento instanceof PmsEventoCalendario) continue;

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();
            $unidad = $evento->getPmsUnidad();

            if (!$inicio || !$fin || !$unidad) continue;

            $reserva = $evento->getReserva();
            $estado = $evento->getEstado();
            $estadoPago = $evento->getEstadoPago();

            $out[] = new CalendarEventDto(
                id: $evento->getId() ?? spl_object_id($evento),
                title: $this->buildTitle($evento, $reserva),
                start: $inicio,
                end: $fin,
                resourceId: $unidad->getId(),
                backgroundColor: $this->resolveColor($estado, $estadoPago),
                tooltip: $this->buildTooltip($evento, $reserva),
                extendedProps: $this->buildContext(
                    $evento,
                    $reserva,
                    $finanzas[(string) $reserva?->getId()] ?? null,
                    $conversaciones[(string) $reserva?->getId()] ?? null,
                ),
            );
        }

        return $out;
    }

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $eventos = $this->fetchEventos($from, $to, $config);
        $seen = [];
        $out = [];

        foreach ($eventos as $evento) {
            $unidad = $evento->getPmsUnidad();
            $id = $unidad?->getId();
            if ($id === null) continue;

            $idStr = (string) $id;
            if (isset($seen[$idStr])) continue;

            $seen[$idStr] = true;
            $out[] = new CalendarResourceDto(id: $idStr, title: (string) $unidad);
        }

        // Las unidades sin eventos en el rango desaparecían de la grilla: el
        // catálogo las repone (ver resources.showAll en el YAML) y se encarga
        // del orden natural + índice `orden`.
        return $this->resourceCatalog->merge($out, $config, PmsUnidad::class);
    }

    /**
     * @param array<string, mixed> $config La configuración del calendario, tal como llega del YAML.
     * @return list<\App\Pms\Entity\PmsEventoCalendario>
     */
    private function fetchEventos(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $em = $this->managerRegistry->getManagerForClass(PmsEventoCalendario::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new HttpException(500, 'EntityManager no disponible.');
        }

        $filters = (array) ($config['filters'] ?? []);

        $qb = $em->createQueryBuilder()
            ->select('e, u, r, es, ep')
            ->from(PmsEventoCalendario::class, 'e')
            ->leftJoin('e.pmsUnidad', 'u')
            ->leftJoin('e.reserva', 'r')
            ->leftJoin('e.estado', 'es')
            ->leftJoin('e.estadoPago', 'ep')
            ->andWhere('e.inicio < :to AND e.fin > :from')
            // Las EXTENSIONES no se pintan NUNCA, en ningún calendario: son la
            // noche que bloquea un horario extra, no una estancia. Se filtra por
            // `eventoOrigen` y no por estado, porque al retirarlas pasan a
            // `cancelada` y con un filtro de estado reaparecían en «Todas».
            ->andWhere('e.eventoOrigen IS NULL')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $this->applyIdFilter($qb, 'es', 'estado', $filters);
        $this->applyIdFilter($qb, 'ep', 'estadoPago', $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyIdFilter(QueryBuilder $qb, string $alias, string $key, array $filters): void
    {
        $val = $filters[$key] ?? null;
        if (empty($val)) return;

        if (is_array($val) && (isset($val['in']) || isset($val['not_in']))) {
            if (!empty($val['in'])) {
                $qb->andWhere("$alias.id IN (:$key" . "_in)")->setParameter($key . '_in', (array)$val['in']);
            }
            if (!empty($val['not_in'])) {
                $qb->andWhere("$alias.id NOT IN (:$key" . "_nin)")->setParameter($key . '_nin', (array)$val['not_in']);
            }
            return;
        }

        $qb->andWhere("$alias.id IN (:$key" . "_val)")->setParameter($key . '_val', (array)$val);
    }

    private function buildTitle(PmsEventoCalendario $evento, ?PmsReserva $reserva): string
    {
        $cliente = $reserva?->getNombreApellido();
        if (!$cliente) {
            return sprintf('Evento (%s)', $evento->getEstado()?->getNombre() ?? 'Sin Estado');
        }

        $channel = strtoupper((string)($evento?->getChannel()?->getId()[0] ?? 'X'));
        $pax = $evento->getCantidadAdultos() + $evento->getCantidadNinos();

        return sprintf('%s x%d | %s | %s', $channel, $pax, $cliente, (string)$evento->getPmsUnidad());
    }

    /**
     * @return list<string> Las líneas del tooltip, ya redactadas.
     */
    private function buildTooltip(PmsEventoCalendario $evento, ?PmsReserva $reserva): array
    {
        $lines = [
            (string) $evento->getPmsUnidad(),
            'Pax: ' . $reserva?->getNombreApellido(),
            'Estado: ' . ($evento->getEstado()?->getNombre() ?? '-'),
            'Pago: ' . ($evento->getEstadoPago()?->getNombre() ?? '-')
        ];

        if ($evento?->getReferenciaCanal()) {
            $lines[] = 'Ref: ' . $evento->getReferenciaCanal();
        }

        return $lines;
    }

    private function resolveColor(?PmsEventoEstado $estado, ?PmsEventoEstadoPago $estadoPago): ?string
    {
        if ($estadoPago?->isColorOverride()) return $estadoPago->getColor();
        return $estado?->getColor() ?? null;
    }

    /**
     * Cabeceras financieras de todas las reservas del rango, en UNA consulta.
     *
     * La relación va de PmsInformacionFinanciera hacia la reserva (JoinColumn
     * unique del lado de las finanzas), así que el evento no puede navegar hasta
     * ella: hay que buscarla. Y hay que hacerlo EN LOTE — un `findOneBy` por
     * evento serían ~200 consultas en una vista de mes, que es justo el tipo de
     * N+1 que hace inusable un calendario.
     *
     * **Gotcha, y de los caros**: esto NO puede ser un `IN (:reservas)` en DQL.
     * `reserva_id` es `BINARY(16)`, y pasar entidades u objetos `Uuid` por
     * `setParameter()` sin tipo de parámetro los serializa mal: la consulta no
     * falla, devuelve CERO filas, y el calendario se pinta sin cifras sin un
     * solo error en el log. Es la misma trampa que documenta
     * `TourTarjetaResolver::binarios()`. `findBy()` con las ENTIDADES sí acierta
     * porque el persister de Doctrine conoce el tipo de la columna destino del
     * mapeo: es la versión en lote del `findOneBy(['reserva' => …])` que ya usa
     * PmsReservaPaxProvider.
     *
     * El precio es no poder hacer eager load de la moneda; sale a una consulta
     * por moneda DISTINTA (el identity map deduplica), no por fila.
     *
     * @param array<int, mixed> $eventos
     *
     * @return array<string, PmsInformacionFinanciera> indexado por id de reserva
     */
    /**
     * La conversación de cada reserva, en UNA consulta.
     *
     * Mismo patrón que `fetchFinanzas()` y por el mismo motivo: este proveedor sirve el
     * calendario entero, y preguntar por la conversación evento a evento serían decenas de
     * consultas por carga. Aquí es una sola con un `IN`.
     *
     * `context_id` es un varchar con el UUID en texto —no una FK—, así que se compara contra
     * la forma de cadena del id, no contra el binario.
     *
     * @param array<int, mixed> $eventos
     * @return array<string, string> reservaId (texto) → conversacionId (texto)
     */
    private function fetchConversaciones(array $eventos): array
    {
        $ids = [];
        foreach ($eventos as $evento) {
            if ($evento instanceof PmsEventoCalendario && $evento->getReserva()?->getId() !== null) {
                $ids[] = (string) $evento->getReserva()->getId();
            }
        }

        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return [];
        }

        $em = $this->managerRegistry->getManagerForClass(PmsEventoCalendario::class);
        if (!$em instanceof EntityManagerInterface) {
            return [];
        }

        try {
            $filas = $em->getConnection()->executeQuery(
                'SELECT context_id AS reserva, BIN_TO_UUID(id) AS conversacion
                   FROM msg_conversation
                  WHERE context_type = :tipo AND context_id IN (:ids)',
                ['tipo' => 'pms_reserva', 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            // El calendario tiene que pintarse aunque la mensajería falle: sin conversación
            // el front simplemente no ofrece el botón de chat.
            return [];
        }

        $porReserva = [];
        foreach ($filas as $fila) {
            $porReserva[(string) $fila['reserva']] = (string) $fila['conversacion'];
        }

        return $porReserva;
    }

    /**
     * @param list<\App\Pms\Entity\PmsEventoCalendario> $eventos
     * @return array<string, mixed>
     */
    private function fetchFinanzas(array $eventos): array
    {
        $reservas = [];
        foreach ($eventos as $evento) {
            if ($evento instanceof PmsEventoCalendario && $evento->getReserva()?->getId() !== null) {
                $reservas[(string) $evento->getReserva()->getId()] = $evento->getReserva();
            }
        }

        if ([] === $reservas) {
            return [];
        }

        $em = $this->managerRegistry->getManagerForClass(PmsInformacionFinanciera::class);
        if (!$em instanceof EntityManagerInterface) {
            return [];
        }

        $filas = $em->getRepository(PmsInformacionFinanciera::class)
            ->findBy(['reserva' => array_values($reservas)]);

        $porReserva = [];
        foreach ($filas as $fila) {
            if ($fila instanceof PmsInformacionFinanciera && $fila->getReserva()?->getId() !== null) {
                $porReserva[(string) $fila->getReserva()->getId()] = $fila;
            }
        }

        return $porReserva;
    }

    /**
     * La cifra de la pastilla: la de su moneda si hay una, la convertida si hay dos.
     *
     * Con una sola moneda **no se convierte nada** y la barra se lee exactamente igual que antes
     * del rediseño, que es el 99 % de los casos.
     */
    private function cifraDeBarra(?PmsTotalesPorMoneda $totales, string $campo): ?string
    {
        if ($totales === null) {
            return null;
        }

        if (!$totales->esMixta()) {
            // Copia local: `reset()` recibe el array POR REFERENCIA y `porMoneda` es readonly.
            $filas = $totales->porMoneda;
            $unica = reset($filas);

            return $unica === false ? null : $unica[$campo];
        }

        // Con dos monedas, el total se lleva a la de cuadre igual que el saldo. Sin tipo de
        // cambio no se inventa: se devuelve null y la barra se queda sin cifra, que es más
        // honesto que sumar peras con manzanas.
        $tc = (float) ($totales->tipoCambio ?? 0);

        if ($tc <= 0.0) {
            return null;
        }

        $suma = 0.0;
        foreach ($totales->porMoneda as $moneda => $cifras) {
            $valor = (float) $cifras[$campo];
            $suma += match (true) {
                $moneda === $totales->monedaCuadre => $valor,
                $moneda === 'USD' && $totales->monedaCuadre === 'PEN' => $valor * $tc,
                $moneda === 'PEN' && $totales->monedaCuadre === 'USD' => $valor / $tc,
                default => $valor,
            };
        }

        return number_format($suma, 2, '.', '');
    }

    /**
     * El desglose exacto por moneda, para el tooltip.
     *
     * La pastilla da una cifra para leer de un vistazo; esto es lo que de verdad se debe, sin
     * convertir. Los dos viajan juntos a propósito: lo aproximado va etiquetado y **la verdad
     * está siempre a un hover**.
     *
     * @return list<array{moneda: string, cargos: string, saldo: string}>|null
     */
    private function detalleDeMonedas(?PmsTotalesPorMoneda $totales): ?array
    {
        if ($totales === null || !$totales->esMixta()) {
            return null;
        }

        $salida = [];
        foreach ($totales->porMoneda as $moneda => $cifras) {
            $salida[] = [
                'moneda' => $moneda,
                'cargos' => $cifras['cargos'],
                'saldo' => $cifras['saldo'],
            ];
        }

        return $salida;
    }

    /**
     * Identificación cruda para que el frontend arme su propia navegación,
     * sin acoplarse a rutas de EasyAdmin.
     *
     * Además de los ids, viajan los DATOS SUELTOS que pinta la barra y el
     * tooltip (canal, cliente, pax, estados, referencia, y las cifras de la
     * cabecera financiera). El `title` y el `tooltip` de arriba siguen
     * existiendo como texto plano —son el contrato del DTO y el respaldo si
     * algo falla—, pero el frontend prefiere esto: con el canal como
     * identificador puede pintar el icono de Airbnb o Booking en vez de la
     * inicial «A»/«B», y no tiene que re-parsear la cadena
     * «A x8 | Nombre | Casita» para saber qué es cada trozo.
     *
     * @return array<string,mixed>
     */
    private function buildContext(
        PmsEventoCalendario $evento,
        ?PmsReserva $reserva,
        ?PmsInformacionFinanciera $finanzas,
        ?string $conversacionId = null,
    ): array {
        // Se calcula desde las colecciones y no leyendo `pms_finanzas_total_moneda`: el
        // calendario carga las cabeceras en lote con sus hijos, así que aquí ya están en memoria
        // y una consulta más por reserva sería el N+1 que ese lote existe para evitar.
        $totales = $finanzas !== null ? PmsTotalesPorMoneda::de($finanzas) : null;

        // Sin cargos no hay cifras que pintar: un bloqueo o una reserva recién
        // creada mandan null y la barra simplemente no muestra la línea de dinero.
        $hayCifras = $totales !== null && $totales->hayCargos();

        return [
            'context' => $reserva ? 'reserva' : 'bloqueo',
            'eventoId' => (string) $evento->getId(),
            'reservaId' => $reserva?->getId() ? (string) $reserva->getId() : null,
            'isOta' => $evento->isOta(),

            'canalId' => $evento->getChannel()?->getId(),
            'cliente' => $reserva?->getNombreApellido(),
            'unidad' => $evento->getPmsUnidad()?->getNombre(),
            'pax' => $evento->getCantidadAdultos() + $evento->getCantidadNinos(),

            // Para contactar desde el panel de hoy sin abrir la ficha. El teléfono va en
            // dígitos —así lo guarda `PmsReservaIntegrityListener`— y sirve tal cual para
            // wa.me. `null` en un bloqueo, o cuando no hay número ni conversación abierta:
            // el front omite el botón en vez de pintar un enlace muerto.
            'telefono' => $this->telefonos->para($reserva),
            'conversacionId' => $conversacionId,
            'estado' => $evento->getEstado()?->getNombre(),
            // Icono y color del estado, tal como los tiene el maestro. Viajan como DATO y no
            // como una tabla en el front: un estado nuevo que alguien dé de alta se pinta
            // solo, y se ve idéntico aquí, en el CRUD del panel y donde haga falta mañana.
            //
            // El color va aparte del `backgroundColor` de la barra a propósito: aquél puede
            // venir pisado por el estado de PAGO (ver resolveColor()), y este icono tiene que
            // seguir diciendo el estado de la RESERVA aunque la barra esté tintada por otro
            // motivo.
            'estadoIcono' => $evento->getEstado()?->getIcono(),
            'estadoColor' => $evento->getEstado()?->getColor(),
            'estadoPago' => $evento->getEstadoPago()?->getNombre(),

            // El motivo escrito a mano («Pintado», «Fumigación»…). Es lo ÚNICO propio de un
            // evento sin reserva: su `title` es un «Evento (Bloqueo)» sintético que repite lo
            // que el color y el icono ya dicen. La barra lo pinta en la segunda fila, donde una
            // estancia lleva sus cifras. En una reserva va igualmente, pero ahí no se pinta:
            // esa fila la ocupan pax, noches y saldo.
            'descripcion' => $evento->getDescripcion(),
            'referenciaCanal' => $evento->getReferenciaCanal(),
            'noches' => $evento->getNoches(),
            // Horario extra: la barra los marca con un icono. La noche que bloquean
            // NO se pinta aquí — es un evento `extension` aparte, que este mismo
            // calendario filtra por YAML (ver PmsExtensionEstanciaService).
            'entradaTemprana' => $evento->isEntradaTemprana(),
            'salidaTardia' => $evento->isSalidaTardia(),

            // Cifras de la RESERVA, no de la estancia: una reserva de dos casitas
            // repite el mismo total en sus dos barras. Es intencionado — el saldo
            // se cobra una vez— pero conviene saberlo al leer el calendario.
            //
            // ── UNA sola cifra, siempre ─────────────────────────────────────────
            // Con una moneda (313 de 317 reservas) es la suya y se lee igual que siempre. Con
            // dos se convierten a la moneda de la ficha con su tipo de cambio de cuadre y se
            // pinta un total y un saldo.
            //
            // La alternativa —«la de mayor valor con un +»— se descartó: en GASUNN la pastilla
            // diría «$66» y se callaría S/ 223.70, y una pastilla de calendario existe para
            // responder «cuánto es esto» de un vistazo. Decisión del dueño del producto.
            //
            // `convertido` avisa de que la cifra pasó por una tasa, para que la barra la marque
            // con `≈` y el tooltip enseñe el detalle exacto de cada moneda.
            'simbolo' => $hayCifras ? $finanzas->getMoneda()?->getSimbolo() : null,
            'total' => $hayCifras ? $this->cifraDeBarra($totales, 'cargos') : null,
            'saldo' => $hayCifras ? $totales->cuadre : null,
            'convertido' => $hayCifras && $totales->esMixta(),
            // Lo que hace que la pastilla no se ponga roja por diez céntimos de redondeo del
            // cambio: el mismo criterio que el panel y que la decisión de estado de pago.
            'cuadra' => $totales === null || $totales->cuadra(),
            // El detalle exacto, para el tooltip. La cifra de arriba es para leer de un vistazo;
            // ésta es la verdad, y va sin convertir.
            'totales' => $hayCifras ? $this->detalleDeMonedas($totales) : null,
        ];
    }
}
