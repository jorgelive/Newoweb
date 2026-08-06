<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Message\Service\MessageDataResolverRegistry;
use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Symfony\Component\String\UnicodeString;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Busca la reserva de un huésped por nombre o localizador.
 *
 * Es la hermana de {@see ConsultarMiReservaSkill} para el equipo, y **son dos skills
 * distintas a propósito**, no una con permisos distintos: aquella saca la reserva del
 * contexto (por eso un huésped no puede apuntar a otra), y ésta acepta a quién buscar.
 * Fundirlas obligaría a comprobar en ejecución si el parámetro está permitido — justo el
 * `if` que se olvida al añadir la siguiente.
 *
 * NUNCA elige entre varias coincidencias: las devuelve todas para que el modelo pregunte
 * cuál. Con dos Carlos González, adivinar es peor que preguntar — y cuando la skill sea de
 * escritura, adivinar significará mover la reserva equivocada.
 *
 * ### ⚠️ Lo que devuelve y no está en la `descripcion` no existe
 *
 * La salida es el volcado de `getMessageVariables()`: 23 claves. El modelo sólo ve la
 * descripción cuando decide **a quién llamar** —los datos llegan después, y sólo si acertó—,
 * así que un campo no anunciado es un campo inalcanzable. `guide_url` estuvo devolviéndose
 * aquí sin figurar en la descripción mientras {@see ConsultarMiReservaSkill} sí lo anunciaba:
 * el huésped podía pedir su guía y el operador no, con el dato idéntico en las dos salidas.
 *
 * Al añadir una clave a `getMessageVariables()` hay que decidir si se anuncia. La descripción
 * es el contrato; lo demás es carga que se paga en tokens y nadie pide.
 */
final readonly class BuscarReservaSkill implements SkillInterface
{
    /** Coincidencias devueltas. Más que esto no ayuda: se le pide al usuario que acote. */
    private const int MAX_RESULTADOS = 8;

    public function __construct(
        private EntityManagerInterface $em,
        private MessageDataResolverRegistry $resolvers,
    ) {}

    public function nombre(): string
    {
        return 'buscar_reserva';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Busca la reserva de un huésped por su nombre, apellido o '
                . 'localizador, y devuelve sus datos: fechas de entrada y salida, casita, '
                . 'noches, huéspedes, localizador, canal por el que reservó, país, total, '
                . 'pagado, saldo pendiente y el desglose en alojamiento, limpieza y servicio. '
                . 'Devuelve también los ENLACES que se le pueden pasar al huésped: guide_url '
                . 'es su guía personal (llegada, wifi, instrucciones) y tours_catalog_url el '
                . 'catálogo de tours; son públicos y están pensados para compartirse, así que '
                . 'úsalos tal cual cuando pidan «el enlace de la guía de X» sin buscar otra '
                . 'skill. Úsala siempre que pregunten por un huésped concreto o por una '
                . 'reserva concreta. Aguanta nombres mal escritos, sin acentos y con el orden '
                . 'cambiado. Si la respuesta trae coincidencia_aproximada, NO des por hecho que '
                . 'acertaste: lee su aviso, di el nombre completo tal como está guardado con su '
                . 'localizador y haz que el operador lo confirme antes de enviar o tocar nada. '
                . 'Si devuelve varias coincidencias, pregunta al usuario cuál es antes de '
                . 'continuar: nunca elijas tú, y para que pueda elegir enséñale de cada una el '
                . 'ESTADO, el localizador y su lista de «estancias» (casita, entra y sale de '
                . 'cada tramo): dos reservas del mismo huésped pueden tener las mismas fechas y '
                . 'diferenciarse sólo en eso. Una reserva puede ocupar VARIAS casitas a la vez, '
                . 'cambiar de casita a mitad, o repetir casita en dos tramos con un hueco: di '
                . 'los tramos, no sólo la primera entrada y la última salida. Si el estado es '
                . '«cancelada», DILO LO PRIMERO y no envíes ni apuntes nada sin preguntar.',
            parametros: [
                SkillParameter::texto('busqueda', 'Nombre, apellido o localizador del huésped.'),
            ],
        );
    }

    /** Sólo el equipo: consultar la reserva de OTRA persona. El huésped tiene la suya. */
    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $busqueda = trim((string) ($entrada['busqueda'] ?? ''));

        if (mb_strlen($busqueda) < 3) {
            return SkillResult::error('Indica al menos 3 caracteres para buscar.');
        }

        ['reservas' => $reservas, 'parcial' => $aproximada] = $this->porTokens($busqueda);

        // Fase 2: nadie encaja literalmente. Antes de rendirse, se busca por parecido — el
        // operador escribe «Landeau» donde pone «Landemaine» y eso no es un caso raro.
        if ($reservas === []) {
            $reservas = $this->porParecido($busqueda);
            $aproximada = $reservas !== [];
        }

        if ($reservas === []) {
            return SkillResult::ok(['total' => 0, 'reservas' => []]);
        }

        $hayMas = count($reservas) > self::MAX_RESULTADOS;
        $reservas = array_slice($reservas, 0, self::MAX_RESULTADOS);

        $resolver = $this->resolvers->getResolver('pms_reserva');

        $salida = [];
        foreach ($reservas as $reserva) {
            $datos = $resolver?->getMessageVariables((string) $reserva->getId()) ?? [];

            $fila = array_filter(
                $datos,
                static fn ($valor) => is_scalar($valor) && (string) $valor !== ''
            );

            // 🔗 El eslabón de la cadena: con esto el modelo puede llevar la reserva elegida
            // a la siguiente skill. Ver docs/Mensajeria.md §11.
            $fila['reserva_id'] = (string) $reserva->getId();

            // Lo que hace falta para ELEGIR entre varias, y que `getMessageVariables()` no
            // trae porque nació para rellenar plantillas de una reserva ya elegida.
            $fila += $this->paraDistinguir($reserva);

            $salida[] = $fila;
        }

        return SkillResult::ok(array_filter([
            'total' => count($salida),
            'hay_mas' => $hayMas,
            // Que la coincidencia sea aproximada NO puede quedarse implícito: detrás de esta
            // skill vienen las de escritura, y cobrarle a quien se parece al que dijeron es
            // peor que no encontrar a nadie.
            'coincidencia_aproximada' => $aproximada ?: null,
            'aviso' => $aproximada
                ? 'NINGUNA coincide del todo con lo que buscabas: éstas se PARECEN. Los nombres '
                    . 'guardados llevan acentos y grafías que no siempre se teclean igual. Di el '
                    . 'nombre completo TAL COMO ESTÁ GUARDADO, con su localizador, y pregunta al '
                    . 'operador si es esa persona ANTES de enviar nada ni tocar nada. Si hay '
                    . 'varias, enséñalas todas: no elijas tú.'
                : null,
            'reservas' => $salida,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Fase 1: cada palabra tiene que aparecer en algún sitio, en cualquier orden.
     *
     * El `LIKE` sobre la cadena entera fallaba con «Landemaine Aurélie» y —peor— con dos
     * espacios entre nombre y apellido, que es un tropiezo de teclado, no una búsqueda distinta.
     * Partiendo en palabras, el orden y los espacios dejan de importar.
     *
     * Los acentos NO se tocan: las columnas son `utf8mb4_unicode_ci`, así que «aurelie» ya
     * casa con «Aurélie» en la propia base. Normalizarlos aquí sería trabajo duplicado.
     *
     * Devuelve además si hubo que conformarse con coincidencias PARCIALES —sólo algunas de
     * las palabras—, porque eso el que pregunta tiene que saberlo: “thea landau” encuentra a
     * Théa Landeau y a Théa Signor, y elegir por él sería mandarle la guía a la otra.
     *
     * @return array{reservas: list<PmsReserva>, parcial: bool}
     */
    private function porTokens(string $busqueda): array
    {
        $qb = $this->em->getRepository(PmsReserva::class)->createQueryBuilder('r');

        // El localizador es un identificador exacto: si coincide, manda sobre cualquier nombre.
        $qb->where('r.localizador = :exacto')->setParameter('exacto', $busqueda);

        $tokens = array_values(array_filter(
            preg_split('/\s+/u', trim($busqueda)) ?: [],
            static fn (string $t) => $t !== ''
        ));

        foreach ($tokens as $i => $token) {
            $qb->orWhere(sprintf(
                '(LOWER(r.nombreCliente) LIKE :t%1$d OR LOWER(r.apellidoCliente) LIKE :t%1$d)',
                $i
            ))->setParameter('t' . $i, '%' . mb_strtolower($token) . '%');
        }

        // Con varias palabras, un OR traería a todos los «Aurélie» del mundo. Se exige que
        // TODAS aparezcan, y eso se filtra después: son 307 filas, no hace falta más máquina.
        $candidatas = $qb->orderBy('r.fechaLlegada', 'DESC')->getQuery()->getResult();

        if (count($tokens) < 2) {
            return [
                'reservas' => array_slice($candidatas, 0, self::MAX_RESULTADOS + 1),
                'parcial' => false,
            ];
        }

        $todas = array_values(array_filter(
            $candidatas,
            function (PmsReserva $r) use ($tokens): bool {
                $nombre = $this->normalizar($r->getNombreCliente() . ' ' . $r->getApellidoCliente());

                foreach ($tokens as $token) {
                    if (!str_contains($nombre, $this->normalizar($token))) {
                        return false;
                    }
                }

                return true;
            }
        ));

        // Si exigir todas las palabras deja la lista vacía, vale más devolver las parciales
        // que un «no existe»: el operador reconoce a su huésped en una lista corta. Pero se
        // marca como parcial, porque «encontré a alguien que encaja a medias» y «encontré a
        // tu huésped» no son la misma respuesta.
        $parcial = $todas === [];
        $resultado = $parcial ? $candidatas : $todas;

        return [
            'reservas' => array_slice($resultado, 0, self::MAX_RESULTADOS + 1),
            'parcial' => $parcial,
        ];
    }

    /**
     * Fase 2: por parecido, cuando el nombre viene mal escrito.
     *
     * `Landeau` por `Landemaine`, `aurely` por `aurelie`: errores de un par de letras que el
     * `LIKE` no perdona. Con 271 nombres distintos, comparar en PHP contra todos es más rápido
     * que discutirlo — y evita meter una extensión de MySQL o un índice full-text por esto.
     *
     * Se compara también el nombre invertido, así «Landeau Thea» encuentra a «Théa Landeau»
     * aunque además esté mal escrito.
     *
     * @return list<PmsReserva>
     */
    private function porParecido(string $busqueda): array
    {
        $aguja = $this->normalizar($busqueda);

        if ($aguja === '') {
            return [];
        }

        // Tolerancia proporcional: en un nombre largo se perdonan más letras que en uno de
        // cinco, pero con techo, o «Ana» empezaría a parecerse a cualquiera.
        $tolerancia = min(5, max(2, (int) floor(mb_strlen($aguja) * 0.25)));

        $puntuadas = [];

        foreach ($this->em->getRepository(PmsReserva::class)->findAll() as $reserva) {
            $nombre = $this->normalizar($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente());
            $invertido = $this->normalizar($reserva->getApellidoCliente() . ' ' . $reserva->getNombreCliente());

            if ($nombre === '') {
                continue;
            }

            $distancia = min(levenshtein($aguja, $nombre), levenshtein($aguja, $invertido));

            if ($distancia <= $tolerancia) {
                $puntuadas[] = ['reserva' => $reserva, 'distancia' => $distancia];
            }
        }

        // Del más parecido al menos, y a igual parecido la llegada más reciente primero.
        usort($puntuadas, static function (array $a, array $b): int {
            if ($a['distancia'] !== $b['distancia']) {
                return $a['distancia'] <=> $b['distancia'];
            }

            return ($b['reserva']->getFechaLlegada()?->getTimestamp() ?? 0)
                <=> ($a['reserva']->getFechaLlegada()?->getTimestamp() ?? 0);
        });

        return array_slice(array_column($puntuadas, 'reserva'), 0, self::MAX_RESULTADOS + 1);
    }

    /** Minúsculas, sin acentos y con los espacios colapsados, para comparar en PHP. */
    private function normalizar(string $texto): string
    {
        $ascii = (new UnicodeString($texto))->ascii()->lower()->toString();

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }

    /**
     * Lo que distingue una reserva de otra cuando hay varias del mismo huésped.
     *
     * Nace de un caso real: Dheeraj Palakurthy tiene **dos** reservas, WXFM34 y GMJ4UY, con las
     * mismas fechas y las mismas casitas. En la lista salían como dos filas idénticas y era
     * imposible elegir. La diferencia es que **una está entera cancelada** — y el estado no se
     * devolvía.
     *
     * `checkin_date`/`checkout_date` son el envolvente de la reserva y colapsan el detalle: una
     * reserva de grupo con cuatro casitas, o una que cambia de casita a mitad, o Karina Barrio
     * con la misma casita en dos tramos separados por un hueco, se ven todas igual. Por eso va
     * el desglose por estancia.
     *
     * @return array<string, mixed>
     */
    private function paraDistinguir(PmsReserva $reserva): array
    {
        $estancias = [];
        $estados = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            $estado = $evento->getEstado()?->getId() ?? '';

            // Bloqueos y extensiones no son tramos del huésped: son el efecto de una entrada
            // temprana o una salida tardía. Mostrarlos aquí sería ruido al elegir.
            if (in_array($estado, ['bloqueo', 'extension'], true)) {
                continue;
            }

            $estados[] = $estado;

            $estancias[] = array_filter([
                'casita' => $evento->getPmsUnidad()?->getNombre(),
                'entra' => $evento->getInicio()?->format('Y-m-d'),
                'sale' => $evento->getFin()?->format('Y-m-d'),
                'estado' => $estado,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        usort($estancias, static fn (array $a, array $b) => ($a['entra'] ?? '') <=> ($b['entra'] ?? ''));

        $vivos = array_values(array_filter($estados, static fn (string $e) => $e !== 'cancelada'));

        // La regla vive en la entidad: ver PmsReserva::estaCancelada().
        $cancelada = $reserva->estaCancelada();

        return array_filter([
            'estancias' => $estancias !== [] ? $estancias : null,
            // Una reserva cancelada manda sobre todo lo demás: es lo primero que hay que decir
            // y basta para descartarla.
            'estado' => $estados === [] ? null : ($cancelada ? 'cancelada' : implode('/', array_unique($vivos))),
            'aviso_cancelada' => $cancelada
                ? 'Esta reserva está CANCELADA. No le envíes nada ni le apuntes cargos sin '
                    . 'preguntar antes al operador.'
                : null,
        ], static fn ($v) => $v !== null);
    }
}
