<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Message\Service\MessageDataResolverRegistry;
use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Busca la reserva de un huésped por nombre o localizador.
 *
 * Es la hermana de `ConsultarMiReservaTool` para el equipo, y **son dos herramientas
 * distintas a propósito**, no una con permisos distintos: aquella saca la reserva del
 * contexto de la conversación (por eso un huésped no puede apuntar a otra), y ésta acepta a
 * quién buscar. Fundir las dos obligaría a comprobar en tiempo de ejecución si el parámetro
 * está permitido — justo el `if` que se olvida.
 *
 * NUNCA elige por su cuenta entre varias coincidencias: las devuelve todas para que el
 * modelo pregunte cuál. Con dos Carlos González, adivinar es peor que preguntar — y cuando
 * la herramienta sea de escritura, adivinar significará mover la reserva equivocada.
 */
final readonly class BuscarReservaTool implements AgentToolInterface
{
    /** Coincidencias devueltas. Más que esto no ayuda al modelo: le pide que acote. */
    private const int MAX_RESULTADOS = 8;

    public function __construct(
        private EntityManagerInterface $em,
        private MessageDataResolverRegistry $resolvers,
    ) {}

    public function nombre(): string
    {
        return 'buscar_reserva';
    }

    public function definicion(): array
    {
        return [
            'description' => 'Busca la reserva de un huésped por su nombre, apellido o '
                . 'localizador, y devuelve sus datos: fechas de entrada y salida, casita, '
                . 'noches, huéspedes, total, pagado y saldo pendiente. Úsala siempre que '
                . 'pregunten por un huésped concreto o por una reserva concreta. Si devuelve '
                . 'varias coincidencias, pregunta al usuario cuál es antes de continuar: '
                . 'nunca elijas tú.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'busqueda' => [
                        'type' => 'string',
                        'description' => 'Nombre, apellido o localizador del huésped.',
                    ],
                ],
                'required' => ['busqueda'],
            ],
        ];
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

    public function ejecutar(array $entrada, AgentActor $actor): string
    {
        $busqueda = trim((string) ($entrada['busqueda'] ?? ''));

        if (mb_strlen($busqueda) < 3) {
            return $this->json(['error' => 'Indica al menos 3 caracteres para buscar.']);
        }

        $reservas = $this->em->getRepository(PmsReserva::class)
            ->createQueryBuilder('r')
            ->where('r.localizador = :exacto')
            ->orWhere('LOWER(r.nombreCliente) LIKE :like')
            ->orWhere('LOWER(r.apellidoCliente) LIKE :like')
            ->orWhere('LOWER(CONCAT(r.nombreCliente, \' \', r.apellidoCliente)) LIKE :like')
            ->setParameter('exacto', $busqueda)
            ->setParameter('like', '%' . mb_strtolower($busqueda) . '%')
            // Las más recientes primero: al preguntar por alguien se busca su estancia
            // actual o la próxima, casi nunca una de hace tres años.
            ->orderBy('r.fechaLlegada', 'DESC')
            ->setMaxResults(self::MAX_RESULTADOS + 1)
            ->getQuery()
            ->getResult();

        if ($reservas === []) {
            return $this->json(['total' => 0, 'reservas' => []]);
        }

        $hayMas = count($reservas) > self::MAX_RESULTADOS;
        $reservas = array_slice($reservas, 0, self::MAX_RESULTADOS);

        $resolver = $this->resolvers->getResolver('pms_reserva');

        $salida = [];
        foreach ($reservas as $reserva) {
            $datos = $resolver?->getMessageVariables((string) $reserva->getId()) ?? [];

            $salida[] = array_filter(
                $datos,
                static fn ($valor) => is_scalar($valor) && (string) $valor !== ''
            );
        }

        return $this->json([
            'total' => count($salida),
            'hay_mas' => $hayMas,
            'reservas' => $salida,
        ]);
    }

    private function json(array $datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '{"error":"respuesta no serializable"}';
    }
}
