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
                . 'reserva concreta. Si devuelve varias coincidencias, pregunta al usuario '
                . 'cuál es antes de continuar: nunca elijas tú.',
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

        $reservas = $this->em->getRepository(PmsReserva::class)
            ->createQueryBuilder('r')
            ->where('r.localizador = :exacto')
            ->orWhere('LOWER(r.nombreCliente) LIKE :like')
            ->orWhere('LOWER(r.apellidoCliente) LIKE :like')
            ->orWhere('LOWER(CONCAT(r.nombreCliente, \' \', r.apellidoCliente)) LIKE :like')
            ->setParameter('exacto', $busqueda)
            ->setParameter('like', '%' . mb_strtolower($busqueda) . '%')
            // Las más recientes primero: al preguntar por alguien se busca su estancia actual
            // o la próxima, casi nunca una de hace tres años.
            ->orderBy('r.fechaLlegada', 'DESC')
            ->setMaxResults(self::MAX_RESULTADOS + 1)
            ->getQuery()
            ->getResult();

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

            $salida[] = $fila;
        }

        return SkillResult::ok([
            'total' => count($salida),
            'hay_mas' => $hayMas,
            'reservas' => $salida,
        ]);
    }
}
