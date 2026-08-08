<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsEventoEstado;
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Quién entra o sale en los próximos días.
 *
 * Es el ejemplo de **skill de cardinalidad abierta**: devolver muchas filas es el resultado
 * correcto, no un problema a refinar. Contrasta con {@see BuscarReservaSkill}, donde varias
 * coincidencias significan «pregunta cuál» porque detrás suele venir una acción.
 *
 * La distinción importa al encadenar: una skill de la que se espera UNA entidad para actuar
 * sobre ella no se comporta igual que un listado.
 *
 * Sirve además a limpieza: `ROLE_LIMPIEZA` la tiene aunque no vea el resto del PMS.
 *
 * ### 🔗 Cada fila trae su enlace a la ficha
 *
 * La misma URL que abre el panel de «salen hoy» al pinchar una fila:
 * `/reservas?evento=…&reserva=…` ({@see util/src/views/HomeView.vue} `verEnReservas()`). Sin
 * ella el operador leía tres nombres en el chat y tenía que ir a buscarlos al calendario a
 * mano — teniendo la skill los dos ids delante.
 *
 * ⚠️ Sólo para el equipo. La lista ya está detrás de `RESERVAS_SHOW`/`LIMPIEZA`/`MANTENIMIENTO`
 * y ningún huésped la alcanza, pero el enlace apunta al panel interno: si algún día se abre a
 * otro actor, esto se queda fuera.
 */
final readonly class ListarSalidasSkill implements SkillInterface
{
    private const string TZ = 'America/Lima';
    private const int MAX_DIAS = 30;

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%util_host_url%')]
        private string $urlPanel,
    ) {}

    public function nombre(): string
    {
        return 'listar_entradas_salidas';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Lista las estancias que ENTRAN o SALEN en los próximos días, con '
                . 'el huésped, la casita, la hora y el localizador. Úsala para preguntas como '
                . '«quién sale mañana», «qué llegadas hay esta semana» o «qué casitas hay que '
                . 'limpiar». Devuelve varias filas: eso es lo esperado, no hace falta acotar a '
                . 'una. Cada fila trae reserva_id y evento_id, así que puedes seguir desde '
                . 'aquí sin volver a buscar al huésped: pásalos a consultar_cuenta, '
                . 'localizar_conversacion, evaluar_cambio_horario o aplicar_cambio_horario. '
                . 'Para un día concreto usa «desde» con esa fecha y dias=1, y responde SÓLO con '
                . 'lo que devuelva: no des por salido de un día a quien figure en otro. '
                . 'CADA FILA TRAE HASTA TRES ENLACES, y se ponen como iconos al final de la '
                . 'línea, nunca como URL suelta ni envolviendo el nombre: «url» es su ficha y '
                . 'va como [📋](url); «url_whatsapp» abre WhatsApp con ese huésped y va como '
                . '[🟢](url_whatsapp); «url_chat» abre su conversación en el panel y va como '
                . '[💬](url_chat). Deja el nombre en negrita, sin enlazar. Si un campo viene '
                . 'vacío OMITE ese icono —sin teléfono no hay WhatsApp, sin conversación no hay '
                . 'chat— y no lo sustituyas por texto ni expliques que falta. Ejemplo de línea: '
                . '«• *Casita 3* — Desiree Egg · 10:00 · FZWF94 [📋](…) [🟢](…) [💬](…)».',
            parametros: [
                SkillParameter::texto(
                    'tipo',
                    'Qué listar: "salidas", "entradas" o "ambas".',
                ),
                SkillParameter::texto(
                    'desde',
                    'Primer día a mirar, en formato YYYY-MM-DD. Si se omite, hoy. Para «mañana» '
                    . 'pon la fecha de mañana con dias=1, NO hoy con dias=2: eso devolvería '
                    . 'también los de hoy y son los que confunden la respuesta.',
                    requerido: false,
                ),
                SkillParameter::entero(
                    'dias',
                    'Cuántos días mirar, contando el de «desde». Por defecto 1 (sólo ese día).',
                ),
            ],
        );
    }

    /** Limpieza también: es justo lo que necesita para organizar el día. */
    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW, Roles::LIMPIEZA, Roles::MANTENIMIENTO];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $tipo = strtolower(trim((string) ($entrada['tipo'] ?? 'ambas')));
        if (!in_array($tipo, ['salidas', 'entradas', 'ambas'], true)) {
            $tipo = 'ambas';
        }

        $dias = max(1, min((int) ($entrada['dias'] ?? 1), self::MAX_DIAS));

        // El día de partida es un parámetro y no siempre hoy: sin él, «quién sale mañana»
        // obliga a pedir dos días y descartar los de hoy por fuera. La fila de hoy se cuela
        // en la respuesta como distractor, y contestar «sale mañana» sobre alguien que se fue
        // hoy es un error que llega al huésped.
        $inicio = $this->fecha($entrada['desde'] ?? null)
            ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ));

        $desde = $inicio->format('Y-m-d');
        $hasta = $inicio->modify(sprintf('+%d days', $dias - 1))->format('Y-m-d');

        $filas = [];

        if ($tipo === 'salidas' || $tipo === 'ambas') {
            $filas = array_merge($filas, $this->consultar('fin', $desde, $hasta, 'sale'));
        }

        if ($tipo === 'entradas' || $tipo === 'ambas') {
            $filas = array_merge($filas, $this->consultar('inicio', $desde, $hasta, 'entra'));
        }

        usort($filas, static fn (array $a, array $b) => [$a['fecha'], $a['hora']] <=> [$b['fecha'], $b['hora']]);

        return SkillResult::ok([
            'desde' => $desde,
            'hasta' => $hasta,
            'total' => count($filas),
            'estancias' => $filas,
        ]);
    }

    /**
     * SQL nativo por el `DATE()`: `inicio` y `fin` llevan hora de check-in/check-out, y
     * comparar los instantes tal cual desplaza el día (docs/PmsDisponibilidad.md §3).
     *
     * @return list<array<string, mixed>>
     */
    private function consultar(string $columna, string $desde, string $hasta, string $movimiento): array
    {
        // El teléfono sale ya en dígitos (`PmsReservaIntegrityListener` lo normaliza al
        // guardar), así que vale tal cual para wa.me sin limpiarlo aquí. Y se respeta
        // `telefono2_es_principal`: escribir al número que el huésped NO usa es no escribir.
        $sql = <<<SQL
            SELECT
                BIN_TO_UUID(e.id)         AS evento_id,
                BIN_TO_UUID(e.reserva_id) AS reserva_id,
                DATE(e.$columna)          AS fecha,
                TIME(e.$columna)          AS hora,
                u.nombre                  AS casita,
                TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, ''))) AS huesped,
                r.localizador             AS localizador,
                e.is_ota                  AS es_ota,
                NULLIF(IF(r.telefono2_es_principal, r.telefono2, r.telefono), '') AS telefono,
                BIN_TO_UUID(c.id)         AS conversacion_id
            FROM pms_evento_calendario e
            LEFT JOIN pms_unidad u  ON u.id = e.pms_unidad_id
            LEFT JOIN pms_reserva r ON r.id = e.reserva_id
            LEFT JOIN msg_conversation c
                   ON c.context_type = 'pms_reserva'
                  AND c.context_id = BIN_TO_UUID(e.reserva_id)
            WHERE e.estado_id IN (:estados)
              AND e.evento_origen_id IS NULL
              AND DATE(e.$columna) BETWEEN :desde AND :hasta
            ORDER BY e.$columna
        SQL;

        $filas = $this->em->getConnection()->executeQuery(
            $sql,
            [
                // Las extensiones quedan fuera por `evento_origen_id IS NULL`: no son
                // estancias y nadie las espera en una lista de llegadas.
                'estados' => PmsEventoEstado::OCUPAN_UNIDAD,
                'desde' => $desde,
                'hasta' => $hasta,
            ],
            ['estados' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        return array_map(
            fn (array $f) => [
                'movimiento' => $movimiento,
                'fecha' => $f['fecha'],
                'hora' => substr((string) $f['hora'], 0, 5),
                'huesped' => $f['huesped'] !== '' ? $f['huesped'] : 'Sin nombre',
                'casita' => $f['casita'] ?? '—',
                'localizador' => $f['localizador'],
                'es_ota' => (bool) $f['es_ota'],
                'evento_id' => $f['evento_id'],
                'reserva_id' => $f['reserva_id'],
                'url' => $this->fichaDe($f['evento_id'], $f['reserva_id']),
                // Los dos caminos para hablar con esta persona. `null` cuando no se puede:
                // sin teléfono no hay WhatsApp, y sin conversación abierta no hay chat que
                // enlazar. El modelo omite el icono en vez de pintar un enlace muerto.
                'url_whatsapp' => $f['telefono'] !== null ? 'https://wa.me/' . $f['telefono'] : null,
                'url_chat' => $f['conversacion_id'] !== null
                    ? rtrim($this->urlPanel, '/') . '/chat/' . $f['conversacion_id']
                    : null,
            ],
            $filas
        );
    }

    /**
     * La ficha de la estancia en el panel, con el drawer ya desplegado.
     *
     * 🪞 Espejo de `verEnReservas()` en `util/src/views/HomeView.vue`: los dos ids viajan por la
     * query porque es lo único que necesita `abrirEdicion()` del drawer de Reservas. **Si allí
     * cambia la ruta o el nombre de los parámetros, aquí también** — o el enlace del asistente
     * llevará al calendario sin abrir nada.
     */
    private function fichaDe(?string $eventoId, ?string $reservaId): ?string
    {
        if ($eventoId === null) {
            return null;
        }

        return sprintf(
            '%s/reservas?evento=%s%s',
            rtrim($this->urlPanel, '/'),
            urlencode($eventoId),
            $reservaId !== null ? '&reserva=' . urlencode($reservaId) : ''
        );
    }

    /** Fecha estricta: un texto que no sea YYYY-MM-DD cae a `null` y se usa hoy. */
    private function fecha(mixed $valor): ?DateTimeImmutable
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto, new DateTimeZone(self::TZ));

        return $fecha !== false ? $fecha : null;
    }
}
