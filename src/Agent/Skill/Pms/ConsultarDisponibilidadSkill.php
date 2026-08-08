<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Pms\Service\Tarifa\PmsTarifaCalculadora;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Qué casitas están libres entre dos fechas, y a cuánto salen.
 *
 * Fachada delgada sobre `PmsDisponibilidadService`: la lógica de solape y qué estados ocupan
 * una noche viven allí (docs/PmsDisponibilidad.md), no aquí.
 *
 * ### 💰 Primero el precio real, la tarifa base sólo como referencia
 *
 * Devolvía únicamente `tarifa_base`, y el modelo la presentaba como «tarifa base orientativa:
 * 70.00 USD/noche» aunque para esas fechas hubiera una tarifa cargada de 65.00 — el operador
 * cotizaba con un número que no era el que se iba a cobrar.
 *
 * Ahora cada casita trae el **total real del tramo**, resuelto por
 * {@see PmsTarifaCalculadora} con el mismo motor que cobra. La base se queda, pero detrás y
 * dicha como lo que es: el suelo cuando no hay tarifa puesta.
 */
final readonly class ConsultarDisponibilidadSkill implements SkillInterface
{
    public function __construct(
        private PmsDisponibilidadService $disponibilidad,
        private PmsTarifaCalculadora $tarifas,
        private EntityManagerInterface $em,
    ) {}

    public function nombre(): string
    {
        return 'consultar_disponibilidad';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Consulta qué casitas están libres en un rango de fechas, con su '
                . 'PRECIO REAL para esas noches. Úsala siempre que pregunten por '
                . 'disponibilidad, casitas libres, huecos, si se puede alojar a alguien en '
                . 'unas fechas, o cuánto costaría alojarse. Nunca respondas de memoria sobre '
                . 'disponibilidad: llama siempre a esta skill. AL DAR PRECIOS usa «precio», que '
                . 'es el total de esas noches con las tarifas que de verdad están cargadas, y '
                . '«precio_por_noche» si te piden el desglose. NO menciones ninguna tarifa base '
                . 'ni hables de «precio orientativo»: lo que devuelvo ya es el precio de venta. '
                . 'Si una casita trae «noches_sin_tarifa», esas noches se están vendiendo a la '
                . 'tarifa base por no tener uno cargado: dilo en una frase, porque suele ser un '
                . 'olvido. Para el detalle noche a noche de UNA casita usa consultar_tarifas, y '
                . 'para revisar las tarifas base configuradas, consultar_tarifas_base.',
            parametros: [
                SkillParameter::texto('desde', 'Fecha de entrada en formato YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Fecha de salida en formato YYYY-MM-DD. '
                    . 'Es el día en que la casita queda libre: del 12 al 15 son 3 noches.'),
                SkillParameter::entero('pax', 'Número de personas, si lo indican.'),
            ],
        );
    }

    /**
     * Sólo el equipo. Un huésped preguntando por disponibilidad general es una venta, y eso
     * pasa por una persona: implicaría precio, y `tarifa_base` NO es el precio de venta.
     */
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
        try {
            $desde = new DateTimeImmutable((string) ($entrada['desde'] ?? ''));
            $hasta = new DateTimeImmutable((string) ($entrada['hasta'] ?? ''));

            $libres = $this->disponibilidad->buscar(
                $desde,
                $hasta,
                isset($entrada['pax']) ? (int) $entrada['pax'] : null,
            );
        } catch (Throwable $e) {
            return SkillResult::error($e->getMessage());
        }

        $noches = (int) $desde->diff($hasta)->days;

        return SkillResult::ok([
            'total' => count($libres),
            'noches' => $noches,
            'casitas' => array_map(
                fn ($u) => $this->conPrecio($u, $desde, $hasta, $noches),
                $libres
            ),
        ]);
    }

    /**
     * La casita libre, con lo que costaría de verdad ese tramo.
     *
     * El DTO de disponibilidad sólo lleva la tarifa base, así que el precio se resuelve aquí
     * recuperando la unidad. Son tantas consultas como casitas libres —siete, en el peor caso
     * de este alojamiento—, y a cambio el operador cotiza con el número que se va a cobrar en
     * vez de con el suelo de la casita.
     *
     * @return array<string, mixed>
     */
    private function conPrecio(
        object $dto,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        int $noches
    ): array {
        // 🚫 La tarifa base NO viaja en la respuesta. La llevaba, y el modelo la repetía junto
        // al precio real como «tarifa base ref: 70.00» — dos números para lo mismo, y el que
        // no se cobra el más llamativo. Quien necesite revisarlas tiene `consultar_tarifas_base`.
        $fila = $dto->toArray();
        unset($fila['tarifa_base'], $fila['moneda']);

        $unidad = $this->em->getRepository(PmsUnidad::class)->find($dto->id);

        if ($unidad === null) {
            return $fila;
        }

        $resumen = $this->tarifas->resumen($unidad, $desde, $hasta);

        if ($resumen['total'] === null) {
            return $fila + [
                'precio' => null,
                'sin_tarifa' => 'Esta casita no tiene tarifa cargada ni tarifa base activa para '
                    . 'esas fechas: no se puede cotizar sin ponerle precio antes.',
            ];
        }

        // El orden importa: `precio` primero porque es lo que hay que decir, y `tarifa_base`
        // se queda al final del array —donde ya venía— como la referencia que es.
        return [
            'precio' => sprintf('%.2f %s', $resumen['total'], $resumen['moneda'] ?? ''),
            'precio_por_noche' => $noches > 0
                ? sprintf('%.2f %s', $resumen['total'] / $noches, $resumen['moneda'] ?? '')
                : null,
            'estancia_minima' => $resumen['min_stay'] > 0 ? $resumen['min_stay'] : null,
            'noches_sin_tarifa' => $resumen['noches_sin_tarifa'] > 0
                ? $resumen['noches_sin_tarifa']
                : null,
        ] + $fila;
    }
}
