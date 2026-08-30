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
final readonly class ConsultarDisponibilidadSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private PmsDisponibilidadService $disponibilidad,
        private PmsTarifaCalculadora $tarifas,
        private TipoCambioDelDia $tipoCambio,
        private EntityManagerInterface $em,
    ) {}

    /** La única moneda desde la que se sabe convertir: el maestro publica USD→PEN. */
    private const string MONEDA_DOLARES = 'USD';

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
                . '🧮 EN CUANTO TE DIGAN QUIÉN VA EN CADA CASITA, vuelve a llamarme con '
                . '«distribucion» («2:7, 5:2»). Te devuelvo el total exacto de cada una y el '
                . '«cotizacion»: un texto ya armado con la cuenta de CADA casita y el total. '
                . 'CÓPIALO TAL CUAL, entero, sin resumirlo a los totales y sin mover el '
                . 'suplemento ni la limpieza a una nota al pie: quien lo recibe tiene que ver '
                . 'de dónde sale cada cifra. ⛔ NO sumes casitas tú, NO calcules el '
                . 'suplemento por persona a partir del precio base, y NO conviertas importes a '
                . 'soles a mano: todo eso viene hecho. Si te falta un dato para pedirlo, '
                . 'pregúntalo antes; equivocarse en una cifra cuesta más que una repregunta. '
                . '🛏️ SI PREGUNTAN POR COMODIDAD, ESPACIO, PRIVACIDAD O CAMAS —«quiero algo '
                . 'más cómodo», «vamos en familia», «somos dos parejas»— usa «habitaciones», '
                . '«camas» y «banos». CAPACIDAD NO ES COMODIDAD: en dos casitas de '
                . 'capacidad 8 caben los mismos, pero la de 3 habitaciones y 2 baños '
                . 'es la cómoda. Recomienda comparándolas, no listes todo. '
                . '🚿 «banos» es el número TOTAL de baños de la casita y TODOS son privados: '
                . 'el apartamento es independiente y no se comparte con nadie. Si preguntan '
                . 'cuántos baños hay, contesta con ese número tal cual. Si el dato viene '
                . 'vacío, NO lo supongas ni des un mínimo: pídelo con consultar_guia. '
                . 'Si quieren el '
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
                SkillParameter::texto(
                    'distribucion',
                    'CÓMO SE REPARTEN entre varias casitas, cuando ya lo han dicho. Formato '
                    . '«casita:personas» separado por comas: «2:7, 5:2» o «Casita 7:10, '
                    . 'Casita 5:2». Úsalo SIEMPRE que te digan quién va en cada casita: '
                    . 'devuelve el total exacto de cada una Y el total conjunto ya sumado. '
                    . 'Con esto no tienes que sumar ni calcular suplementos por tu cuenta.',
                    requerido: false
                ),
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

    /**
     * El equipo, el prospecto **y el huésped alojado**.
     *
     * Qué hay libre y a cuánto es justo lo que un desconocido puede saber, y la respuesta no
     * nombra a ningún huésped —eso es `consultar_ocupacion`, que sigue siendo sólo del equipo—.
     * Si se le puede contestar a alguien que no es cliente, negárselo a quien ya está dentro no
     * se sostiene.
     *
     * ── Por qué antes el huésped NO entraba, y por qué se cambió ────────────────
     * Estaba excluido a propósito, con este argumento: «un huésped con reserva que pregunta
     * "¿qué más tenéis libre?" está pidiendo que le vendan algo, y eso lo cierra una persona».
     * La preocupación era buena; la consecuencia, no: **el huésped que quiere ampliar su
     * estadía es el caso de venta más fácil que existe —ya está dentro y quiere quedarse— y era
     * justo el único al que el agente no podía ni informar.** De las cuatro skills que admitían
     * al prospecto, tres admitían ya también al huésped; la única que no era la de vender.
     *
     * Lo que motivaba aquella exclusión sigue en pie y no depende de este método: esta skill es
     * {@see NivelRiesgo::Lectura} y **no reserva nada**. Enseña el hueco y el precio; ampliar la
     * estancia lo sigue cerrando una persona, por `escalar_al_equipo` o por el operador. Cambia
     * quién puede PREGUNTAR, no quién puede VENDER.
     */
    public function rolesRequeridos(): array
    {
        return [Roles::PROSPECTO, Roles::HUESPED, Roles::RESERVAS_SHOW];
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

            // 📅 NO SE COTIZA EL PASADO, y esto no es una comodidad: es una red.
            //
            // El modelo resuelve «del 8 al 10 de noviembre» eligiendo un año, y si se
            // equivoca cotiza tarifas de un noviembre que ya pasó sin que nada chirríe: el
            // precio sale de otra temporada, suena razonable y el cliente no tiene forma de
            // notarlo. Pasó en producción y costó rato ver que los números no eran inventados,
            // eran del año anterior.
            //
            // Se corta aquí y no con un aviso en el prompt porque un aviso depende de que
            // alguien lo lea. Vender una noche que ya pasó no existe como caso de uso: si de
            // verdad quieren mirar atrás, para eso está consultar_ocupacion.
            if ($hasta <= new DateTimeImmutable('today')) {
                return SkillResult::error(sprintf(
                    'Esas fechas ya pasaron (%s → %s). No cotizo el pasado: seguramente se '
                    . 'refieren a %d. Confirma el AÑO con quien pregunta y vuelve a llamarme.',
                    $desde->format('d/m/Y'),
                    $hasta->format('d/m/Y'),
                    (int) $desde->format('Y') + 1
                ));
            }

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

        // ── Camino del REPARTO YA DECIDIDO ──────────────────────────────────
        // Cuando ya han dicho quién va en cada casita, se cotiza exactamente eso y se
        // devuelve el total conjunto SUMADO AQUÍ. Nació de un fallo real: preguntando por la
        // 5 y la 2 para 9 personas, el modelo cogió el precio base de cada una y se puso a
        // añadir el suplemento por su cuenta — calculó 2 personas adicionales donde había 3 y
        // cotizó 248 USD en vez de 260. Los números de la skill eran correctos; lo que
        // fallaba era la aritmética que le tocaba hacer a él.
        //
        // Objeto y no array a propósito: la versión array de este contrato se rompió en
        // producción y tumbó la skill en el caso normal — el porqué, en {@see DistribucionLeida}.
        $leido = DistribucionLeida::deTexto((string) ($entrada['distribucion'] ?? ''));

        if ($leido->ilegibles !== []) {
            return SkillResult::error(sprintf(
                'No entendí este trozo de la distribución: «%s». El formato es casita:personas '
                . 'separado por comas, por ejemplo «2:7, 5:2». Corrígelo y vuelve a llamarme; '
                . 'no cotizo con la mitad del reparto.',
                implode('», «', $leido->ilegibles)
            ));
        }

        if ($leido->reparto !== []) {
            return $this->cotizarDistribucion($leido->reparto, $desde, $hasta, $noches);
        }

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
     * Cotiza un reparto ya decidido: cada casita con SU número de personas, y el total conjunto.
     *
     * 🔒 Sólo cotiza casitas LIBRES en ese rango. Se parte de `buscar()` sin filtro de pax y se
     * cruza por nombre: pedir el precio de una casita ocupada tiene que fallar, no devolver una
     * cifra que nadie va a poder vender.
     *
     * @param list<array{0: string, 1: int}> $reparto
     */
    private function cotizarDistribucion(
        array $reparto,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        int $noches
    ): SkillResult {
        $libres = $this->disponibilidad->buscar($desde, $hasta);

        $casitas = [];
        $noDisponibles = [];
        $totalUsd = 0.0;
        $totalPax = 0;
        $moneda = '';
        $completo = true;

        $vistas = [];

        foreach ($reparto as [$nombre, $pax]) {
            $dto = $this->emparejar($libres, $nombre);

            if ($dto === null) {
                $noDisponibles[] = $nombre;
                $completo = false;
                continue;
            }

            // 🚫 La misma casita dos veces («2:7, 2:3») cotizaba alojamiento y limpieza por
            // duplicado, calculaba el suplemento sobre 7 y sobre 3 por separado en vez de sobre
            // 10, y el texto decía «por las 2 casitas juntas». Parecía legítimo.
            if (isset($vistas[$dto->id])) {
                return SkillResult::error(sprintf(
                    'La casita «%s» aparece dos veces en la distribución. Si van varios grupos '
                    . 'a la misma casita, súmalos en una sola entrada.',
                    $nombre
                ));
            }

            $vistas[$dto->id] = true;

            // No se cotiza a más gente de la que cabe: un total para 20 personas en una casita
            // de 8 se puede leer como cerrable, y no lo es.
            if ($dto->capacidad !== null && $pax > $dto->capacidad) {
                $noDisponibles[] = sprintf(
                    '%s (caben %d, pedían %d)',
                    $dto->nombre,
                    $dto->capacidad,
                    $pax
                );
                $completo = false;
                continue;
            }

            $cotizado = $this->conPrecioYTotal($dto, $desde, $hasta, $noches, $pax);

            $casitas[] = ['pax' => $pax] + $cotizado['fila'];
            $totalPax += $pax;

            if ($cotizado['total'] === null) {
                $completo = false;
                continue;
            }

            // 💱 Sumar dos monedas distintas da peras con manzanas etiquetadas con la última
            // que se vio. Antes de que exista un caso real de tarifas en monedas distintas,
            // esto corta el total conjunto en vez de inventarse una conversión.
            if ($moneda !== '' && $cotizado['moneda'] !== $moneda) {
                $noDisponibles[] = sprintf(
                    '%s (tarifa en %s, no se puede sumar con %s sin tipo de cambio)',
                    $dto->nombre,
                    $cotizado['moneda'],
                    $moneda
                );
                $completo = false;
                continue;
            }

            // Redondeado a lo que se ENSEÑA: sumar los floats crudos hacía que el total
            // conjunto se desviara un céntimo de la suma de sus propias líneas, que es justo
            // lo que un cliente rehace a mano.
            $totalUsd += round($cotizado['total'], 2);
            $moneda = $cotizado['moneda'];
        }

        return SkillResult::ok(array_filter([
            'noches' => $noches,
            'pax_total' => $totalPax,
            'casitas' => $casitas,
            'no_disponibles' => $noDisponibles !== []
                ? sprintf(
                    'Estas NO se han podido cotizar (o no están libres, o no cabe la gente que '
                    . 'dijiste, o su tarifa está en otra moneda): %s. Dilo claramente en vez de '
                    . 'dar un total como si estuvieran incluidas.',
                    implode(', ', $noDisponibles)
                )
                : null,
            // 🧾 LA COTIZACIÓN ENTERA, LISTA PARA COPIAR. Es la razón de ser de este camino.
            //
            // No es sólo el total sumado: lleva DENTRO el desglose de cada casita. Con el
            // total en un campo y el desglose en otro, el modelo cogía el total y resumía el
            // resto en una nota al pie —«incluye 36.00 de suplemento»—, que es justo lo que no
            // se quería: quien recibe la cotización tiene que ver de dónde sale cada cifra.
            // Yendo en el MISMO campo, no puede dar uno sin el otro.
            //
            // Sólo se da si TODAS las casitas se pudieron cotizar: una cotización a la que le
            // falta una casita parece completa, y eso es peor que no darla.
            'cotizacion' => $completo && $casitas !== []
                ? $this->cotizacionCompleta($casitas, $totalUsd, $totalPax, $moneda)
                : null,
        ], static fn ($v) => $v !== null));
    }

    /** La casita libre que corresponde a «2», «casita 2» o «Casita 2». */
    private function emparejar(array $libres, string $nombre): ?object
    {
        $aguja = mb_strtolower(trim($nombre));

        foreach ($libres as $u) {
            $suyo = mb_strtolower((string) $u->nombre);

            // Un número suelto se ancla al FINAL para que «1» no traiga la 11 ni la 21, igual
            // que en `ConsultarOcupacionSkill::resolverCasita()`.
            if ($suyo === $aguja
                || (preg_match('/^\d+$/', $aguja) === 1 && str_ends_with($suyo, ' ' . $aguja))
                || (preg_match('/^\d+$/', $aguja) !== 1 && str_contains($suyo, $aguja))) {
                return $u;
            }
        }

        return null;
    }

    /**
     * La cotización completa en un solo texto: casita por casita con su cuenta, y el total.
     *
     * Se entrega ARMADA para que se lea tal cual. Todo lo que aquí se separa en líneas es algo
     * que el modelo, si se lo damos suelto, resume o se salta: el precio por noche, el
     * suplemento por persona y la limpieza acabaron en una nota al pie la primera vez.
     *
     * Sin markdown de tablas: esto sale por WhatsApp.
     *
     * @param list<array<string, mixed>> $casitas
     */
    private function cotizacionCompleta(array $casitas, float $total, int $pax, string $moneda): string
    {
        $lineas = [];

        foreach ($casitas as $c) {
            $lineas[] = sprintf(
                '%s · %d persona(s): %s',
                $c['nombre'] ?? 'Casita',
                (int) ($c['pax'] ?? 0),
                // El desglose ya trae «tarifa × noches + extras + limpieza → TOTAL».
                $c['desglose'] ?? ($c['precio'] ?? '')
            );
        }

        $soles = $this->enSoles($total, $moneda);

        $lineas[] = sprintf(
            'TOTAL%s: %.2f %s%s',
            count($casitas) > 1 ? sprintf(' por las %d casitas juntas (%d personas)', count($casitas), $pax) : '',
            $total,
            $moneda,
            $soles !== null ? ' · ' . $soles : ''
        );

        // Se dice en la propia cotización, no sólo en la descripción de la skill: quien
        // pregunta por aquí reserva DIRECTO y no paga el porcentaje de las OTA.
        $lineas[] = 'Precio DIRECTO: no incluye ni paga el porcentaje de servicio de las OTA.';

        return implode("\n", $lineas);
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
     * @param array{total: ?float, alojamiento: ?float, suplemento_pax: float, pax_adicionales: int, limpieza: float, limpieza_porcentaje: float, precios_por_noche: list<float>} $resumen
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

        // Con la limpieza en 0 —ni importe ni porcentaje— la línea NO se pinta. Un «limpieza
        // 0.00» en una cotización invita a preguntar por algo que no existe.
        if ($resumen['limpieza'] > 0.0) {
            $partes[] = $resumen['limpieza_porcentaje'] > 0.0
                ? sprintf(
                    'limpieza %s%% = %.2f',
                    $this->pct($resumen['limpieza_porcentaje']),
                    $resumen['limpieza']
                )
                : sprintf('limpieza %.2f', $resumen['limpieza']);
        }

        return sprintf('%s → TOTAL %.2f %s', implode(' + ', $partes), $resumen['total'], $moneda);
    }

    /** Un porcentaje sin ceros de relleno: 15.00 → «15», 12.50 → «12.5». */
    private function pct(float $valor): string
    {
        return rtrim(rtrim(sprintf('%.2f', $valor), '0'), '.');
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
        // `TipoCambioDelDia` es explícitamente USD→PEN: aplicárselo a una tarifa en euros
        // daría un número inventado con pinta de correcto.
        if ($moneda !== self::MONEDA_DOLARES) {
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
        return $this->conPrecioYTotal($dto, $desde, $hasta, $noches, $pax)['fila'];
    }

    /**
     * Lo mismo, pero devolviendo además el total EN NÚMERO y la moneda.
     *
     * Existe para que `cotizarDistribucion()` pueda sumar sin volver a consultar ni —peor—
     * reparsear los importes de las cadenas que ya se formatearon para el modelo.
     *
     * @return array{fila: array<string, mixed>, total: ?float, moneda: string}
     */
    private function conPrecioYTotal(
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
            return ['fila' => $fila, 'total' => null, 'moneda' => ''];
        }

        $resumen = $this->tarifas->resumen($unidad, $desde, $hasta, $pax);

        if ($resumen['total'] === null) {
            return [
                'fila' => $fila + [
                    'precio' => null,
                    'sin_tarifa' => 'Esta casita no tiene tarifa cargada ni tarifa base activa '
                        . 'para esas fechas: no se puede cotizar sin ponerle precio antes.',
                ],
                'total' => null,
                'moneda' => '',
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
        $filaFinal = array_filter([
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
                ? sprintf(
                    '%.2f %s (%s, por estancia y no por noche)',
                    $resumen['limpieza'],
                    $moneda,
                    $resumen['limpieza_porcentaje'] > 0.0
                        ? sprintf(
                            '%s%% sobre alojamiento + suplemento',
                            $this->pct($resumen['limpieza_porcentaje'])
                        )
                        : 'importe fijo'
                )
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

        return ['fila' => $filaFinal, 'total' => $resumen['total'], 'moneda' => $moneda];
    }
}
