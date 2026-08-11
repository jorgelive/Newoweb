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
                . '«precio_por_noche» si te piden el desglose. ⚠️ NO NOMBRES LA TARIFA BASE, NI '
                . 'AUNQUE LA TENGAS DE UNA CONSULTA ANTERIOR DE ESTA MISMA CONVERSACIÓN: no la '
                . 'devuelvo a propósito. Poner «tarifa base: 75.00» al lado de un total de '
                . '224.00 son dos precios para lo mismo, y el que no se cobra es el que se '
                . 'queda mirando el operador. Tampoco hables de «precio orientativo»: lo que '
                . 'devuelvo YA es el precio de venta. Si de verdad preguntan por la base, para '
                . 'eso está consultar_tarifas_base. '
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

            $pax = isset($entrada['pax']) ? (int) $entrada['pax'] : null;

            $libres = $this->disponibilidad->buscar($desde, $hasta, $pax);
        } catch (Throwable $e) {
            return SkillResult::error($e->getMessage());
        }

        $noches = (int) $desde->diff($hasta)->days;

        return SkillResult::ok(array_filter([
            'total' => count($libres),
            'noches' => $noches,
            'pax' => $pax,
            // Sin saber cuántos van, el precio no puede incluir el suplemento por persona: se
            // avisa en vez de dar un total que se quedará corto al concretar el grupo.
            'aviso_pax' => $pax === null
                ? 'No te han dicho cuántas personas van, así que estos precios NO incluyen el '
                    . 'suplemento por persona adicional. Si el grupo pasa de lo que cubre la '
                    . 'tarifa, el precio sube: pregunta cuántos son antes de cerrar nada.'
                : null,
            'casitas' => array_map(
                fn ($u) => $this->conPrecio($u, $desde, $hasta, $noches, $pax),
                $libres
            ),
        ], static fn ($v) => $v !== null));
    }

    /**
     * Cuánto costaría lo mismo por una OTA, ya sumado. `null` si la unidad no marca ningún
     * canal con servicio.
     *
     * **No entra en el total** de la cotización: aquí se cotiza directo, y por directo no se
     * cobra servicio. Es un dato para comparar, y por eso el texto empieza diciendo que no
     * está incluido — si el modelo sólo lee media frase, que la media que lea sea ésa.
     *
     * @param array{total: ?float, alojamiento: ?float, suplemento_pax: float, porcentaje_servicio: float, servicio_canales: list<string>} $resumen
     */
    private function referenciaServicio(array $resumen, string $moneda): ?string
    {
        $porcentaje = $resumen['porcentaje_servicio'];

        if ($porcentaje <= 0.0 || $resumen['servicio_canales'] === [] || $resumen['total'] === null) {
            return null;
        }

        // La base excluye la limpieza — regla de `PmsUnidad::servicioSobre()`, que es quien la
        // decide; esto la reconstruye para poder enseñarla, no para redefinirla.
        $servicio = ($resumen['alojamiento'] + $resumen['suplemento_pax']) * $porcentaje / 100.0;

        return sprintf(
            'NO incluido en el total de arriba. Por %s el huésped vería %.2f %s: son %.2f de '
            . 'servicio (%s%% sobre alojamiento + suplemento, la limpieza no entra en la base) '
            . 'que cobra la OTA y que aquí no se cobra.',
            implode(' y ', $resumen['servicio_canales']),
            $resumen['total'] + $servicio,
            $moneda,
            $servicio,
            rtrim(rtrim(sprintf('%.2f', $porcentaje), '0'), '.'),
        );
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
        int $noches,
        ?int $pax
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

        $resumen = $this->tarifas->resumen($unidad, $desde, $hasta, $pax);

        if ($resumen['total'] === null) {
            return $fila + [
                'precio' => null,
                'sin_tarifa' => 'Esta casita no tiene tarifa cargada ni tarifa base activa para '
                    . 'esas fechas: no se puede cotizar sin ponerle precio antes.',
            ];
        }

        $moneda = $resumen['moneda'] ?? '';

        // El orden importa: `precio` primero porque es lo que hay que decir. El suplemento va
        // desglosado —no escondido dentro del total— para que el operador pueda explicar de
        // dónde sale la diferencia en vez de soltar una cifra mayor sin motivo aparente.
        return array_filter([
            'precio' => sprintf('%.2f %s', $resumen['total'], $moneda),
            // Un promedio aquí sería un precio inventado: con 65 tres noches y 45 dos, la
            // media da 57.00, que no se cobra ninguna noche. Se dice el precio si es uno solo,
            // y el recorrido si varía —el desglose completo lo da consultar_tarifas—.
            'precio_por_noche' => match (count($resumen['precios_por_noche'])) {
                0 => null,
                1 => sprintf('%.2f %s', $resumen['precios_por_noche'][0], $moneda),
                default => sprintf(
                    'de %.2f a %.2f %s (varía por noche)',
                    $resumen['precios_por_noche'][0],
                    end($resumen['precios_por_noche']),
                    $moneda
                ),
            },
            'suplemento_por_pax' => $resumen['suplemento_pax'] > 0.0
                ? sprintf(
                    '%.2f %s (%d persona(s) por encima de las %d que cubre la tarifa, %s por '
                    . 'persona y noche)',
                    $resumen['suplemento_pax'],
                    $moneda,
                    $resumen['pax_adicionales'],
                    $unidad->getPaxIncluidos(),
                    $unidad->getPrecioPaxAdicional()
                )
                : null,
            // Desglosada y no escondida en el total, igual que el suplemento: es un concepto
            // que el huésped pregunta («¿y eso qué es?») y que hay que poder nombrar.
            'limpieza' => $resumen['limpieza'] > 0.0
                ? sprintf('%.2f %s (por estancia, no por noche)', $resumen['limpieza'], $moneda)
                : null,
            // 📌 REFERENCIA, no cobro. Va fuera del total a propósito: esta cotización es
            // directa, y el servicio lo aplicaría la OTA por su cuenta. Sirve para responder
            // «por Booking le saldría más» sin tener que cotizar dos veces.
            //
            // El total ya sumado y NO «un 16% sobre 189»: pedirle la multiplicación al modelo
            // es pedirle que se equivoque con dinero. Aquí llega hecha.
            'servicio_en_otas' => $this->referenciaServicio($resumen, $moneda),
            'estancia_minima' => $resumen['min_stay'] > 0 ? $resumen['min_stay'] : null,
            'noches_sin_tarifa' => $resumen['noches_sin_tarifa'] > 0
                ? $resumen['noches_sin_tarifa']
                : null,
        ], static fn ($v) => $v !== null) + $fila;
    }
}
