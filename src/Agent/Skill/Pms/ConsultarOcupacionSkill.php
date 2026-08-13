<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Dto\PmsOcupacionDto;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Quién está alojado en una casita entre dos fechas. El reverso de {@see ConsultarDisponibilidadSkill}.
 *
 * Nace de un hueco real: con sólo disponibilidad, «¿quién está en la casita 1 del 10 al 12?» no
 * tenía respuesta. `listar_entradas_salidas` se le parece pero contesta otra cosa — los
 * movimientos de esos días, no quién duerme dentro. Un huésped que entró el 9 y se va el 18 no
 * aparece en ninguna de las dos, y es justo el que ocupa la casita.
 *
 * ### Se apoya en el servicio, no reimplementa el solape
 *
 * Llama a `PmsDisponibilidadService::ocupacion()`, que comparte `IMPIDEN_VENTA` y la comparación
 * por `DATE()` con la disponibilidad. Las dos skills cuadran por construcción: lo que una da por
 * libre, la otra no lo da por ocupado.
 *
 * ### 🔤 Resuelve el nombre, no exige el id
 *
 * El operador dice «la casita 1» y el modelo no tiene el UUID. Obligar a pasar un id habría
 * forzado una llamada previa a otra skill que ni siquiera existe: no hay ninguna que liste el
 * parque. Así que acepta el nombre y admite «1» a secas, que es como se pregunta de verdad.
 *
 * Ver docs/PmsDisponibilidad.md.
 */
final readonly class ConsultarOcupacionSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private PmsDisponibilidadService $disponibilidad,
        private EntityManagerInterface $em,
    ) {}

    public function nombre(): string
    {
        return 'consultar_ocupacion';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Dice QUIÉN está alojado en una casita, o en todas, entre dos fechas. '
                . 'Úsala para «quién está en la casita 1 del 10 al 12», «quién duerme hoy en la '
                . '3», «qué casitas están ocupadas esta semana y por quién». Devuelve de cada '
                . 'estancia el huésped, la casita, las fechas y horas de entrada y salida, el '
                . 'estado, el localizador, y reserva_id y evento_id para encadenar con '
                . 'consultar_cuenta, localizar_conversacion o aplicar_cambio_horario. Es el '
                . 'reverso de consultar_disponibilidad: aquélla dice qué está libre, ésta quién '
                . 'está dentro. NO la confundas con listar_entradas_salidas, que sólo da quién '
                . 'llega o se va esos días: alguien que entró antes y sigue alojado aparece '
                . 'aquí y allí no. Incluye bloqueos y cierres de mantenimiento, que ocupan la '
                . 'casita pero no son personas: los distingue el campo es_estancia en false.',
            parametros: [
                SkillParameter::texto('desde', 'Primera noche a mirar, en formato YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Día en que termina el rango, en formato '
                    . 'YYYY-MM-DD. No se mira esa noche: del 10 al 12 son las noches del 10 y '
                    . 'el 11. Para una sola noche, pon el día siguiente.'),
                SkillParameter::texto('casita', 'Nombre de la casita, como «Casita 1» o sólo '
                    . '«1». Si se omite, devuelve la ocupación de todo el parque.',
                    requerido: false),
            ],
        );
    }

    /**
     * Del negocio de ALOJAMIENTO: habla de reservas, estancias, casitas o su dinero.
     *
     * Recorta el catálogo, no los permisos — ver {@see SkillDominioInterface}.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    /** Ver quién está alojado es leer reservas ajenas: sólo el equipo. */
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
        $desde = $this->fecha($entrada['desde'] ?? null);
        $hasta = $this->fecha($entrada['hasta'] ?? null);

        if ($desde === null || $hasta === null) {
            return SkillResult::error('Indica las fechas «desde» y «hasta» en formato YYYY-MM-DD.');
        }

        $casita = trim((string) ($entrada['casita'] ?? ''));
        $unidad = null;

        if ($casita !== '') {
            $encontradas = $this->resolverCasita($casita);

            if ($encontradas === []) {
                return SkillResult::error(sprintf(
                    'No hay ninguna casita que se llame «%s».',
                    $casita
                ));
            }

            // Con varias coincidencias NO se elige: mismo criterio que buscar_reserva.
            if (count($encontradas) > 1) {
                return SkillResult::error(sprintf(
                    '«%s» encaja con varias casitas: %s. Pregunta al usuario cuál.',
                    $casita,
                    implode(', ', array_map(static fn (PmsUnidad $u) => $u->getNombre() ?? '—', $encontradas))
                ));
            }

            $unidad = $encontradas[0];
        }

        try {
            $ocupacion = $this->disponibilidad->ocupacion(
                $desde,
                $hasta,
                $unidad !== null ? (string) $unidad->getId() : null
            );
        } catch (InvalidArgumentException $e) {
            return SkillResult::error($e->getMessage());
        }

        if ($ocupacion === []) {
            return SkillResult::ok([
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
                'casita_consultada' => $unidad?->getNombre(),
                'total' => 0,
                'estancias' => [],
                'mensaje' => $unidad !== null
                    ? sprintf('%s está libre esas noches: no hay nadie alojado.', $unidad->getNombre())
                    : 'No hay nadie alojado en esas fechas.',
            ]);
        }

        return SkillResult::ok(array_filter([
            'desde' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
            'casita_consultada' => $unidad?->getNombre(),
            'total' => count($ocupacion),
            'estancias' => array_map(
                static fn (PmsOcupacionDto $o) => $o->toArray(),
                $ocupacion
            ),
        ], static fn ($v) => $v !== null));
    }

    /**
     * «Casita 1», «casita 1» o «1» resuelven a la misma unidad.
     *
     * @return list<PmsUnidad>
     */
    private function resolverCasita(string $busqueda): array
    {
        $qb = $this->em->getRepository(PmsUnidad::class)
            ->createQueryBuilder('u')
            ->where('u.activo = true')
            ->orderBy('u.nombre', 'ASC');

        // Un número suelto se ancla al final del nombre para que «1» no traiga la 11 ni la 21.
        if (preg_match('/^\d+$/', $busqueda) === 1) {
            $qb->andWhere('u.nombre LIKE :sufijo')->setParameter('sufijo', '% ' . $busqueda);
        } else {
            $qb->andWhere('LOWER(u.nombre) LIKE :like')
               ->setParameter('like', '%' . mb_strtolower($busqueda) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    private function fecha(mixed $valor): ?DateTimeImmutable
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return $fecha !== false ? $fecha : null;
    }
}
