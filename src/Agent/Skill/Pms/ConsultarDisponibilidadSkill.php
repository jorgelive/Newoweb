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
use App\Pms\Service\Finance\TipoCambioDelDia;
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
        private TipoCambioDelDia $tipoCambio,
        private EntityManagerInterface $em,
    ) {}

    /** Moneda en la que ya no hay nada que convertir. */
    private const string MONEDA_SOLES = 'PEN';

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
                . 'para revisar las tarifas base configuradas, consultar_tarifas_base. '
                . '🧾 AL COTIZAR, LEE «desglose» TAL CUAL: ya trae la cuenta hecha '
                . '(tarifa × noches + extras + limpieza → TOTAL). No rehagas tú la '
                . 'multiplicación ni montes tu propia suma. Si viene '
                . '«total_referencial_soles», dilo también: quien pregunta desde Perú piensa '
                . 'en soles. '
                . '⚠️ Si en vez de «precio» recibes «precio_desde», NO lo presentes como '
                . 'total ni como precio cerrado: es un mínimo al que le falta el suplemento '
                . 'por persona. Di «desde X» y PREGUNTA cuántas personas son; con ese dato '
                . 'vuelve a llamarme con «pax» y entonces sí tendrás el total. '
                . '🛏️ SI PREGUNTAN POR COMODIDAD, ESPACIO, PRIVACIDAD O CAMAS —«quiero algo '
                . 'más cómodo», «vamos en familia», «somos dos parejas»— usa «habitaciones», '
                . '«camas» y «banos_privados». CAPACIDAD NO ES COMODIDAD: en dos casitas de '
                . 'capacidad 8 caben los mismos, pero la de 3 habitaciones con baños privados '
                . 'es la cómoda. Recomienda comparándolas, no listes todo. Si quieren el '
                . 'detalle completo de una (equipamiento, fotos, ubicación), pídelo con '
                . 'consultar_guia indicando esa casita. '
                . '«servicio_en_otas» es sólo para comparar: quien te escribe está reservando '
                . 'DIRECTO y no paga ese porcentaje. Úsalo como argumento de venta —reservando '
                . 'directo se lo ahorra—, nunca lo sumes al total.',
            parametros: [
                SkillParameter::texto('desde', 'Fecha de entrada en formato YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Fecha de salida en formato YYYY-MM-DD. '
                    . 'Es el día en que la casita queda libre: del 12 al 15 son 3 noches.'),
                SkillParameter::entero('pax', 'Número de personas, si lo indican.'),
            ],
        );
    }

    /**
     * El equipo y el PROSPECTO — no el huésped alojado.
     *
     * Puede sonar al revés y no lo es. Un huésped con reserva que pregunta «¿qué más tenéis
     * libre?» está pidiendo que le vendan algo, y eso lo cierra una persona. Un prospecto es
     * alguien que TODAVÍA no es cliente: contestarle qué hay libre y a cuánto es el trabajo,
     * no una excepción. Y la respuesta no nombra a ningún huésped —eso es
     * `consultar_ocupacion`, que sigue siendo sólo del equipo—.
     */
    public function rolesRequeridos(): array
    {
        // El prospecto entra aquí: qué hay libre y a qué precio es justo lo que un
        // desconocido puede saber, y la respuesta no nombra a ningún huésped.
        return [Roles::PROSPECTO, Roles::RESERVAS_SHOW];
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

            // 🛒 «No hay» es una respuesta que cierra una venta, y casi nunca es verdad.
            //
            // El filtro de `pax` pide UNA casita que quepa al grupo entero, así que un grupo de
            // 20 devolvía la lista vacía y el agente contestaba «no hay capacidad» — teniendo
            // tres casitas libres que sumaban exactamente 20. El dato era correcto y la
            // respuesta, inútil.
            //
            // Así que cuando el filtro deja la lista en cero se vuelve a preguntar SIN él: si
            // hay sitio repartido, eso es lo que hay que ofrecer. No caber en una habitación no
            // es lo mismo que no caber.
            $repartido = false;

            if ($libres === [] && $pax !== null && $pax > 0) {
                $libres = $this->disponibilidad->buscar($desde, $hasta);
                $repartido = $libres !== [];
            }
        } catch (Throwable $e) {
            return SkillResult::error($e->getMessage());
        }

        $noches = (int) $desde->diff($hasta)->days;

        // En un reparto NO se sabe cuántos van en cada casita, así que el suplemento por
        // persona no se puede calcular: pasar los 20 a cada una cobraría el grupo entero siete
        // veces. Se cotiza como si no supiéramos el pax —que es la verdad— y cada casita sale
        // con `precio_desde` hasta que el cliente diga cómo se reparten.
        $paxPorCasita = $repartido ? null : $pax;

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
            'reparto' => $repartido ? $this->reparto($libres, $pax) : null,
            'casitas' => array_map(
                fn ($u) => $this->conPrecio($u, $desde, $hasta, $noches, $paxPorCasita),
                $libres
            ),
        ], static fn ($v) => $v !== null));
    }

    /**
     * El mensaje para cuando el grupo no cabe en una casita pero sí en varias.
     *
     * Dice tres cosas y las tres hacen falta: que juntos no caben, cuánta gente suman las
     * libres, y qué preguntar para poder cerrar. Sin la última se queda en un dato y lo que
     * hay que hacer es vender.
     *
     * @param list<object> $libres
     */
    private function reparto(array $libres, ?int $pax): string
    {
        $capacidad = 0;

        foreach ($libres as $u) {
            $capacidad += (int) ($u->capacidad ?? 0);
        }

        $alcanza = $pax !== null && $capacidad >= $pax;

        return sprintf(
            'NO hay una sola casita para %d persona(s), pero SÍ hay sitio repartido: estas %d '
            . 'están libres y suman %d plaza(s). %s Ofrécelas como una propuesta conjunta —di '
            . 'cuáles son y para cuántos es cada una— y pregunta cómo se quieren repartir; con '
            . 'ese dato vuelve a llamarme con «pax» por casita para dar el total exacto. NO '
            . 'contestes que no hay disponibilidad.',
            (int) $pax,
            count($libres),
            $capacidad,
            $alcanza
                ? 'Da para el grupo entero.'
                : sprintf('Aun así faltan %d plaza(s), así que dilo con franqueza y ofrece '
                    . 'lo que hay: puede que parte del grupo cambie de fechas.', $pax - $capacidad)
        );
    }

    /**
     * La cuenta escrita como se la dirías a un cliente: tarifa × noches, los extras sumados
     * uno a uno, y el total.
     *
     * Existe para que el modelo **no calcule**. Antes recibía piezas sueltas y tenía que
     * componer «45 por noche, 3 noches, más 15 de limpieza»; multiplicar y sumar es
     * exactamente lo que un modelo hace mal, y aquí cada error es dinero mal cotizado.
     *
     * Con tarifas distintas por noche no se inventa un promedio —sería un precio que no se
     * cobra ninguna noche— y se dice el subtotal del alojamiento sin desglosarlo: el detalle
     * noche a noche es de `consultar_tarifas`.
     *
     * @param array{total: ?float, alojamiento: ?float, suplemento_pax: float, pax_adicionales: int, limpieza: float, precios_por_noche: list<float>} $resumen
     */
    private function desglose(array $resumen, int $noches, string $moneda): ?string
    {
        if ($resumen['total'] === null || $resumen['alojamiento'] === null) {
            return null;
        }

        $partes = [count($resumen['precios_por_noche']) === 1
            ? sprintf(
                '%.2f %s × %d noche(s) = %.2f',
                $resumen['precios_por_noche'][0],
                $moneda,
                $noches,
                $resumen['alojamiento']
            )
            : sprintf('alojamiento %d noche(s) = %.2f %s', $noches, $resumen['alojamiento'], $moneda),
        ];

        if ($resumen['suplemento_pax'] > 0.0) {
            $partes[] = sprintf(
                '%d persona(s) adicional(es) = %.2f',
                $resumen['pax_adicionales'],
                $resumen['suplemento_pax']
            );
        }

        if ($resumen['limpieza'] > 0.0) {
            $partes[] = sprintf('limpieza %.2f', $resumen['limpieza']);
        }

        return sprintf('%s → TOTAL %.2f %s', implode(' + ', $partes), $resumen['total'], $moneda);
    }

    /**
     * El total en soles, al cambio del día. `null` si ya está en soles o si no hay cambio
     * publicado —que pasa: el maestro se alimenta de SUNAT y hay días sin publicar—.
     *
     * Referencial y dicho con esas palabras: se cobra en la moneda de la tarifa, y entre la
     * cotización y el pago el cambio se mueve. Prometer un importe exacto en soles sería
     * comprometer un número que no controlamos.
     */
    private function enSoles(float $total, string $moneda): ?string
    {
        if ($moneda === self::MONEDA_SOLES || $moneda === '') {
            return null;
        }

        $cambio = $this->tipoCambio->venta();

        if ($cambio === null || (float) $cambio <= 0.0) {
            return null;
        }

        return sprintf(
            'referencial S/ %.2f (al cambio de %s del día; se cobra en %s)',
            $total * (float) $cambio,
            $cambio,
            $moneda
        );
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

        // ⚠️ `precio` vs `precio_desde`: la diferencia NO es cosmética.
        //
        // Sin `pax` el total va corto —le falta el suplemento— y ya se comprobó que avisarlo
        // en prosa no basta: el `aviso_pax` estaba escrito, el modelo no lo repitió y cotizó
        // tres casitas 144 USD por debajo presentando el número como cerrado. Un aviso depende
        // de que alguien lo lea; el NOMBRE del campo no. A un `precio_desde` no se le puede
        // llamar total sin mentir en la misma frase.
        //
        // Sólo cuando la unidad puede cobrar más: la Casita 5 no cobra persona extra, así que
        // su precio sin `pax` ya es exacto y llamarlo «desde» sería sembrar una duda falsa.
        $incierto = $pax === null && $unidad->cobraPaxAdicional();
        $claveP = $incierto ? 'precio_desde' : 'precio';

        // El orden importa: el precio primero porque es lo que hay que decir. El suplemento va
        // desglosado —no escondido dentro del total— para que el operador pueda explicar de
        // dónde sale la diferencia en vez de soltar una cifra mayor sin motivo aparente.
        return array_filter([
            $claveP => sprintf('%.2f %s', $resumen['total'], $moneda),
            // 🧾 La cuenta HECHA, para leerla tal cual al cliente. Es lo que hace un vendedor:
            // no suelta un total, enseña de dónde sale. Y al venir armada desde aquí, el modelo
            // no tiene que multiplicar noches por tarifa — que es justo donde se equivocaría.
            'desglose' => $this->desglose($resumen, $noches, $moneda),
            // 💱 El mismo total en soles. Quien pregunta desde Perú piensa en soles, y hacerle
            // la conversión es parte de vender. Va marcado como referencial: el cobro se hace
            // en la moneda de la tarifa y el cambio se mueve.
            'total_referencial_soles' => $this->enSoles($resumen['total'], $moneda),
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
