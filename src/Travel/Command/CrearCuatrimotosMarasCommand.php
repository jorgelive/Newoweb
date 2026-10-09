<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Entity\TravelItemDiccionario;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\ItemModoEnum;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las cuatrimotos de la meseta de Maras: un servicio nuevo, con una plantilla por ruta.
 *
 * ── Por qué NO entra en `MARAS_MORAY` ───────────────────────────────────────
 *
 * «Chinchero, Maras y Moray» es el tour arqueológico de bus y guía. Esto es otro producto: otro
 * vehículo, otro proveedor, otros horarios y —en dos de las tres rutas— otros sitios. Los
 * bloques de un cotservicio se pintan agrupados bajo el nombre de su servicio maestro, así que
 * meterlo dentro pondría «Chinchero, Maras y Moray» de encabezado sobre un recorrido que no
 * pisa Chinchero. Es el mismo motivo por el que Isla Saona no vive dentro de `ACT_RESORT`.
 *
 * ── El patrón: ancla, como toda excursión ───────────────────────────────────
 *
 * Nadie compra «la Laguna de Huaypo». Se contrata el programa entero, así que un solo
 * componente lo representa —anclado en el segmento de recojo— y los demás segmentos van sin
 * componente: sólo cuentan. Ver `CrearEscalaMirafloresCommand`, que es el mismo molde.
 *
 * ⚠️ **Compartido y privado son componentes DISTINTOS, no una modalidad de la tarifa.** No es
 * criterio de estilo: `ComponenteTipoEnum::esCompartido()` sólo es true para `EXCURSION_POOL` y
 * es lo que decide dónde se deja al pasajero —el privado al hotel, el compartido al centro—.
 * Un componente único con dos modalidades no podría afirmar las dos cosas a la vez. La
 * modalidad de la tarifa se pone igualmente, porque describe lo que se vende.
 *
 * ⚠️ **La cuatrimoto individual y la doble SÍ son tarifas** del mismo componente, como las
 * clases de tren o las flotas de transporte.
 *
 * ── Los montos son POR PASAJERO ─────────────────────────────────────────────
 *
 * El tarifario del proveedor cobra la cuatrimoto doble por VEHÍCULO (dos personas): S/ 130 el
 * compartido y S/ 280 el privado. El catálogo guarda por pasajero, así que aquí van divididos
 * entre dos — 65 y 140. Se comprueba con su propia web pública, que vende la simple a 30 USD y
 * la doble a 40: si fuera por persona, la doble costaría más que dos simples.
 *
 * ⚠️ Los montos del PRIVADO van **sin IGV**, tal como los factura el proveedor. Decisión
 * tomada el 08/10/2026: el catálogo dice lo que cuesta comprarlo, no lo que se cobra.
 *
 * ── Lo que este comando NO carga, y por qué ─────────────────────────────────
 *
 * Queda fuera **«Dos lagunas (Huaypo y Piuray)»**, y no por falta de datos: su web la publica
 * con texto completo, pero el tarifario 2026-2027 no la cotiza. La web está desactualizada
 * —confirmado con el proveedor el 08/10/2026—, así que lo que falta en el tarifario nuevo no es
 * un hueco: es un producto retirado.
 *
 * Por el mismo motivo, al revés, Checoq y el Full Day SÍ entran aunque no estén en la web: son
 * posteriores a la última actualización del sitio, no productos fantasma.
 *
 * Añadir una ruta es una entrada más en `self::RUTAS` y sus segmentos en `self::SEGMENTOS`.
 *
 * ── El orden, porque hay dos comandos antes ─────────────────────────────────
 *
 *   1. app:travel:asignar-prestadores-cuatrimotos
 *      Mueve «John» del nombre de la tarifa al campo prestador y crea Top Andean Travel.
 *
 *   2. app:travel:renombrar-componente 'Pool Cuatrimoto' 'Pool Cuatrimoto Moray y Salineras'
 *      El genérico huérfano ERA esta ruta; sólo le faltaba decirlo. Renombrarlo en vez de
 *      crear otro conserva sus tarifas —las de dos proveedores— y no deja un duplicado que
 *      ninguna restricción de la base impediría.
 *
 *   3. este comando, que lo encuentra ya nombrado y le cuelga lo que le falta: segmentos,
 *      pivotes, pools y plantilla.
 *
 * Si se corre sin el paso 2, no falla: crea un componente nuevo con las tarifas de la
 * constante y el huérfano se queda donde estaba. Funciona, pero deja dos fichas de la misma
 * ruta y la del proveedor sin usar.
 *
 * Idempotente por clave natural —código de servicio, slug de segmento, slug de plantilla—. La
 * idempotencia de cada ruta va por la PLANTILLA y no por el componente, justo para que el
 * componente que ya existía se pueda reutilizar. Por comando y no por migración: `titulo` y
 * `contenido` llevan `#[AutoTranslate]`, y un INSERT en SQL dejaría las fichas sólo en español.
 */
#[AsCommand(
    name: 'app:travel:crear-cuatrimotos-maras',
    description: 'Crea el servicio «Cuatrimotos en Maras» con sus rutas, tarifas y plantillas.',
    hidden: true,
)]
final class CrearCuatrimotosMarasCommand extends Command
{
    private const SERVICIO_CODIGO = 'ATV_MARAS';
    private const SERVICIO_NOMBRE = 'Cuatrimotos en Maras';
    private const MONEDA = 'PEN';

    private const PROVEEDOR = 'Top Andean Travel';

    /**
     * El «qué incluye» que lee el pasajero, tomado del diccionario de ítems.
     *
     * ⚠️ **No es decoración: sin ítems el componente entra en la cotización sin decir qué se
     * compró.** Y es la norma, no un extra — 45 de los 52 componentes de tipo `pool` los
     * tienen. La lista de abajo es la del catálogo para un tour de cuatrimotos, copiada de
     * «Cuatrimotos en Piuray Ocotuan», que es el hermano más completo.
     *
     * El orden importa: se enseña tal cual.
     */
    private const ITEMS_BASE = [
        'Guia Profesional',
        'Transporte',
        'Cuatrimotos',
        'Casco',
        'Armadura completa',
        'Guantes',
        'Poncho de lluvia',
    ];

    /**
     * Términos que este comando añade al diccionario si no están.
     *
     * Sólo uno: los otros nueve ya existían. El diccionario es vocabulario CONTROLADO y
     * compartido por todo el catálogo, así que inventar un término es la última opción —
     * primero se busca el que ya dice lo mismo.
     *
     * @var array<string, string>
     */
    private const ITEMS_NUEVOS = [
        'Poncho de lluvia' => 'Poncho de lluvia',
    ];

    /**
     * Las entradas. Existen ya como componentes con su cuadro de tarifas por procedencia.
     *
     * ⚠️ **`Ingreso a Maras` no está aquí, y no es un olvido.** Cuelga GLOBALMENTE del segmento
     * de las Salineras —`itinerarioContexto` y `dia` a null, el cubo «siempre que se use el
     * segmento»—, así que toda ruta que reutilice ese segmento lo hereda sin escribir una fila.
     * Añadirlo otra vez lo duplicaría en la cotización.
     *
     * El BTPV sí hace falta: en `MARAS_MORAY` cuelga de sus anclas y no del segmento de Moray,
     * de modo que reutilizar Moray no lo arrastra.
     */
    private const ENTRADAS = [
        'btpv' => ['nombre' => 'Boleto Turistico Parcial Valle - BTPV', 'crear' => null],
        'maras' => ['nombre' => 'Ingreso a Maras', 'crear' => null],

        // El único que no existía. No es una entrada a la laguna —no se cobra por verla— sino
        // el muelle privado donde paran a descansar al bajarse de las cuatrimotos. De ahí que
        // tenga un importe y no un cuadro: el boleto y Maras cambian según de dónde venga el
        // pasajero; un muelle cobra lo mismo a todos.
        'huaypo' => [
            'nombre' => 'Muelle privado en la Laguna de Huaypo',
            'crear' => [
                'titulo' => 'Muelle en la Laguna de Huaypo',
                'monto' => '5.00',
            ],
        ],
    ];

    /**
     * Segmentos que YA EXISTEN y se reutilizan. Editarlos afecta también a `MARAS_MORAY`.
     *
     * Son los mismos sitios: las Salineras son las mismas se llegue en bus o en cuatrimoto. Lo
     * que cambia —el vehículo, la inducción, el casco— vive en el segmento ancla, que sí es
     * propio. Duplicar el texto dejaría dos fichas de las Salineras y las correcciones se
     * aplicarían a una sola.
     */
    private const REUTILIZADOS = [
        'moray' => 'VIS-VALLE_VIP/MARAS_MORAY-MORAY',
        'salineras' => 'VIS-VALLE_VIP/MARAS_MORAY-MARAS',
        'retorno_centro' => 'RET_EXC-CENTRO-CUS',
    ];

    /**
     * Los segmentos nuevos, por slug.
     *
     * @var array<string, array{nombre: string, titulo: string, contenido: string,
     *                          inicio: PuntoModoEnum, fin: PuntoModoEnum}>
     */
    private const SEGMENTOS = [
        'SAL_EXC-ATV_MARAS-POOL' => [
            'nombre' => 'Recojo e inicio de la aventura en cuatrimotos',
            'titulo' => 'Recojo e inicio de la aventura',
            'contenido' => 'Les recogemos en su hotel y salimos en nuestro transporte hacia el Valle '
                . 'Sagrado. El viaje hasta la base, en la comunidad de Cjhua, ronda los cincuenta '
                . 'minutos y ya es parte del paseo.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'SAL_EXC-ATV_MARAS-PRIV' => [
            'nombre' => 'Recojo en el hotel (cuatrimotos, servicio privado)',
            'titulo' => 'Recojo en el hotel (servicio privado)',
            'contenido' => 'Pasamos por su hotel a la hora acordada. El vehículo, el guía y las '
                . 'cuatrimotos son sólo para su grupo, así que el horario y el ritmo de las paradas se '
                . 'ajustan a ustedes.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'EXC-ATV_MARAS-INDUCCION' => [
            'nombre' => 'Inducción y entrega de equipo (cuatrimotos)',
            'titulo' => 'Inducción y entrega de equipo',
            'contenido' => 'En la base explicamos el manejo de la cuatrimoto y las normas del recorrido, '
                . 'y entregamos el equipo: casco, guantes y poncho de lluvia, más la armadura de '
                . 'rodilleras, coderas y chaleco, y el poncho de lluvia según la temporada. Después hay '
                . 'unos minutos de práctica en terreno llano, hasta que todos se sienten cómodos. Las '
                . 'máquinas son Honda TRX 250. El recorrido es de dificultad moderada, con subidas y '
                . 'bajadas, y va por carretera no asfaltada: es momento de empolvarse.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-ATV_MARAS-HUAYPO' => [
            'nombre' => 'Laguna de Huaypo',
            'titulo' => 'Laguna de Huaypo',
            'contenido' => 'Una laguna abierta en pleno Valle Sagrado, de la que se cuentan tantas '
                . 'historias como leyendas. Es la primera parada del recorrido y el tramo donde se coge '
                . 'confianza con la máquina: carretera sin asfaltar, polvo y campo abierto. Al bajarse '
                . 'de las cuatrimotos se descansa en el muelle, al borde del agua.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-ATV_MARAS-PUEBLO' => [
            'nombre' => 'Pueblo de Maras y Cordillera de Urubamba',
            'titulo' => 'Pueblo de Maras',
            'contenido' => 'El camino cruza el pueblo de Maras, de casas de adobe y balcones de madera, '
                . 'y se abre a la Cordillera de Urubamba. Desde aquí se distinguen el Verónica —5,682 '
                . 'metros, el «Waqaywillka» de los incas— y el Chicón, 5,530 metros, guardián del Valle '
                . 'Sagrado.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-ATV_MARAS-ZIPLINE' => [
            'nombre' => 'Circuito de tirolina',
            'titulo' => 'Circuito de tirolina',
            'contenido' => 'Cuatro cables de distinta longitud, entre los 300 y los 600 metros. Se '
                . 'cruzan en orden: el primero es suave, para coger confianza; el segundo es el más '
                . 'veloz; el tercero, el más extenso, da tiempo a soltarse y hacer posturas; el cuarto '
                . 'es el de mayor alcance, con las mejores vistas del cañón, el Valle Sagrado y los '
                . 'nevados. Hora y media, con instructor y equipo de seguridad.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'TRANS-ATV_MARAS-BASE' => [
            'nombre' => 'Traslado de la tirolina a la base de cuatrimotos',
            'titulo' => 'Traslado a la base de cuatrimotos',
            'contenido' => 'Un cuarto de hora hasta la comunidad de Cjhua, donde esperan las '
                . 'cuatrimotos y empieza la segunda mitad del día.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-ATV_MARAS-TIOBAMBA' => [
            'nombre' => 'Templo de Tiobamba',
            'titulo' => 'Templo de Tiobamba',
            'contenido' => 'Una iglesia colonial de adobe a dos kilómetros de Maras, levantada entre '
                . 'los siglos XVI y XVII, con fachada de piedra, capilla abierta y un balcón de columnas '
                . 'de madera. Dentro conserva lienzos de la escuela cusqueña y la imagen de la Virgen '
                . 'Asunta. Está sola en medio del campo, que es buena parte de su encanto.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-ATV_MARAS-CHECOQ' => [
            'nombre' => 'Graneros andinos de Checoq',
            'titulo' => 'Graneros andinos de Checoq',
            'contenido' => 'Las colcas de Checoq: los graneros que los incas usaban para almacenar '
                . 'alimentos, parecidos a los que se ven sobre Ollantaytambo. Están colgados de la '
                . 'ladera para que el viento y la altura conservaran el grano, y desde ahí se abre el '
                . 'valle.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ALM-ATV_MARAS-PICNIC' => [
            'nombre' => 'Almuerzo picnic en la base (full day)',
            'titulo' => 'Almuerzo picnic',
            'contenido' => 'De vuelta en el punto de partida paramos a comer al aire libre antes de '
                . 'bajar a las Salineras. Va incluido en el programa.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'RET_EXC-ATV_MARAS-HOTEL' => [
            'nombre' => 'Retorno al hotel (cuatrimotos, servicio privado)',
            'titulo' => 'Retorno al hotel',
            'contenido' => 'Dejamos las cuatrimotos en la base y volvemos a Cusco en nuestra movilidad, '
                . 'hasta la puerta de su hotel.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
    ];

    /**
     * Una entrada por ruta × modalidad. Cada una es un componente, sus tarifas y una plantilla.
     *
     * `segmentos` va en el orden en que ocurre; el primero es el ANCLA y es el único que carga
     * componente. Las claves de `self::REUTILIZADOS` se resuelven contra lo que ya existe.
     *
     * `entradas` ata un ticket a un segmento DENTRO de esta plantilla. Va por contexto y no
     * global porque el ancla se comparte entre rutas y una de ellas no pasa por Moray: una fila
     * global le colaría el boleto a quien no lo necesita.
     *
     * @var list<array{componente: string, tipo: ComponenteTipoEnum, modalidad: TarifaModalidadEnum,
     *                 plantilla: string, plantillaSlug: string, titulo: string, hora: string,
     *                 minPax: int|null, segmentos: list<string>, entradas: list<array{clave: string, segmento: string}>,
     *                 items: list<string>,
     *                 tarifas: list<array{nombre: string, monto: string}>}>
     */
    private const RUTAS = [
        [
            'componente' => 'Pool Cuatrimoto Moray y Salineras',
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'modalidad' => TarifaModalidadEnum::COMPARTIDO,
            'plantilla' => 'Medio Día Cuatrimotos Moray y Salineras (Pool)',
            'plantillaSlug' => 'HD-ATV_MARAS-MORAY-POOL',
            'titulo' => 'Cuatrimotos por Moray y las Salineras de Maras',
            'hora' => '06:30',
            'minPax' => null,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-POOL',
                'EXC-ATV_MARAS-INDUCCION',
                '@moray',
                '@salineras',
                '@retorno_centro',
            ],
            'entradas' => [['clave' => 'btpv', 'segmento' => '@moray']],
            'items' => [],
            'tarifas' => [
                ['nombre' => 'ATV individual', 'monto' => '90.00'],
                ['nombre' => 'ATV doble (compartida) · por pasajero', 'monto' => '65.00'],
            ],
        ],
        [
            'componente' => 'Privado Cuatrimoto Moray y Salineras',
            'tipo' => ComponenteTipoEnum::EXCURSION_PRIVADA,
            'modalidad' => TarifaModalidadEnum::PRIVADO,
            'plantilla' => 'Medio Día Cuatrimotos Moray y Salineras (Privado)',
            'plantillaSlug' => 'HD-ATV_MARAS-MORAY-PRIV',
            'titulo' => 'Cuatrimotos por Moray y las Salineras (servicio privado)',
            'hora' => '06:30',
            'minPax' => 2,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-PRIV',
                'EXC-ATV_MARAS-INDUCCION',
                '@moray',
                '@salineras',
                'RET_EXC-ATV_MARAS-HOTEL',
            ],
            'entradas' => [['clave' => 'btpv', 'segmento' => '@moray']],
            'items' => [],
            'tarifas' => [
                ['nombre' => 'ATV individual', 'monto' => '230.00'],
                ['nombre' => 'ATV doble (compartida) · por pasajero', 'monto' => '140.00'],
            ],
        ],
        [
            'componente' => 'Pool Cuatrimoto Laguna Huaypo y Salineras',
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'modalidad' => TarifaModalidadEnum::COMPARTIDO,
            'plantilla' => 'Medio Día Cuatrimotos Laguna Huaypo y Salineras (Pool)',
            'plantillaSlug' => 'HD-ATV_MARAS-HUAYPO-POOL',
            'titulo' => 'Cuatrimotos por la Laguna de Huaypo y las Salineras',
            'hora' => '06:30',
            'minPax' => null,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-POOL',
                'EXC-ATV_MARAS-INDUCCION',
                'VIS-ATV_MARAS-HUAYPO',
                'VIS-ATV_MARAS-PUEBLO',
                '@salineras',
                '@retorno_centro',
            ],
            'entradas' => [['clave' => 'huaypo', 'segmento' => 'VIS-ATV_MARAS-HUAYPO']],
            'items' => [],
            'tarifas' => [
                ['nombre' => 'ATV individual', 'monto' => '90.00'],
                ['nombre' => 'ATV doble (compartida) · por pasajero', 'monto' => '65.00'],
            ],
        ],
        [
            'componente' => 'Privado Cuatrimoto Laguna Huaypo y Salineras',
            'tipo' => ComponenteTipoEnum::EXCURSION_PRIVADA,
            'modalidad' => TarifaModalidadEnum::PRIVADO,
            'plantilla' => 'Medio Día Cuatrimotos Laguna Huaypo y Salineras (Privado)',
            'plantillaSlug' => 'HD-ATV_MARAS-HUAYPO-PRIV',
            'titulo' => 'Cuatrimotos por la Laguna de Huaypo y las Salineras (servicio privado)',
            'hora' => '06:30',
            'minPax' => 2,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-PRIV',
                'EXC-ATV_MARAS-INDUCCION',
                'VIS-ATV_MARAS-HUAYPO',
                'VIS-ATV_MARAS-PUEBLO',
                '@salineras',
                'RET_EXC-ATV_MARAS-HOTEL',
            ],
            'entradas' => [['clave' => 'huaypo', 'segmento' => 'VIS-ATV_MARAS-HUAYPO']],
            'items' => [],
            'tarifas' => [
                ['nombre' => 'ATV individual', 'monto' => '230.00'],
                ['nombre' => 'ATV doble (compartida) · por pasajero', 'monto' => '140.00'],
            ],
        ],
        [
            'componente' => 'Pool Cuatrimoto y Zip Line',
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'modalidad' => TarifaModalidadEnum::COMPARTIDO,
            'plantilla' => 'Día Completo Cuatrimotos y Zip Line',
            'plantillaSlug' => 'FD-ATV_MARAS-ZIPLINE-POOL',
            'titulo' => 'Zip Line y cuatrimotos por Moray y las Salineras',
            'hora' => '06:30',
            'minPax' => null,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-POOL',
                'ACT-ATV_MARAS-ZIPLINE',
                'TRANS-ATV_MARAS-BASE',
                'EXC-ATV_MARAS-INDUCCION',
                '@moray',
                '@salineras',
                '@retorno_centro',
            ],
            'entradas' => [['clave' => 'btpv', 'segmento' => '@moray']],
            'items' => ['Recorrido en Zipline', 'Instructores Especializados', 'Arnés de seguridad'],
            'tarifas' => [
                ['nombre' => 'ATV individual + Zip Line', 'monto' => '170.00'],
                ['nombre' => 'ATV doble + Zip Line · por pasajero', 'monto' => '145.00'],
            ],
        ],
        [
            'componente' => 'Privado Cuatrimoto Checoq y Tiobamba',
            'tipo' => ComponenteTipoEnum::EXCURSION_PRIVADA,
            'modalidad' => TarifaModalidadEnum::PRIVADO,
            'plantilla' => 'Medio Día Cuatrimotos Checoq y Tiobamba (Privado)',
            'plantillaSlug' => 'HD-ATV_MARAS-CHECOQ-PRIV',
            'titulo' => 'Cuatrimotos por Checoq y el templo de Tiobamba (servicio privado)',
            // 8:00, no 6:30: es el único medio día que arranca más tarde, y «previa coordinación».
            'hora' => '08:00',
            'minPax' => 2,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-PRIV',
                'EXC-ATV_MARAS-INDUCCION',
                'VIS-ATV_MARAS-TIOBAMBA',
                'VIS-ATV_MARAS-CHECOQ',
                'RET_EXC-ATV_MARAS-HOTEL',
            ],
            // No pasa por Moray ni por las Salineras: es la única ruta sin ninguna entrada.
            'entradas' => [],
            'items' => [],
            'tarifas' => [
                ['nombre' => 'ATV individual', 'monto' => '230.00'],
                ['nombre' => 'ATV doble (compartida) · por pasajero', 'monto' => '140.00'],
            ],
        ],
        [
            'componente' => 'Privado Cuatrimoto Full Day Maras',
            'tipo' => ComponenteTipoEnum::EXCURSION_PRIVADA,
            'modalidad' => TarifaModalidadEnum::PRIVADO,
            'plantilla' => 'Día Completo Cuatrimotos Moray, Checoq, Tiobamba y Salineras (Privado)',
            'plantillaSlug' => 'FD-ATV_MARAS-COMPLETO-PRIV',
            'titulo' => 'Cuatrimotos por Moray, Checoq, Tiobamba y las Salineras (servicio privado)',
            // El tarifario lo daba por «horario flexible»; el itinerario de la misma fecha sí lo
            // concreta: recojo a las 9:00 y hasta las 16:00, previa coordinación.
            'hora' => '09:00',
            'minPax' => 2,
            'segmentos' => [
                'SAL_EXC-ATV_MARAS-PRIV',
                'EXC-ATV_MARAS-INDUCCION',
                '@moray',
                'VIS-ATV_MARAS-CHECOQ',
                'VIS-ATV_MARAS-TIOBAMBA',
                'ALM-ATV_MARAS-PICNIC',
                '@salineras',
                'RET_EXC-ATV_MARAS-HOTEL',
            ],
            'entradas' => [['clave' => 'btpv', 'segmento' => '@moray']],
            'items' => ['Almuerzo Picnic'],
            'tarifas' => [
                ['nombre' => 'ATV individual', 'monto' => '330.00'],
                ['nombre' => 'ATV doble (compartida) · por pasajero', 'monto' => '220.00'],
            ],
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $moneda = $this->em->getRepository(MaestroMoneda::class)->find(self::MONEDA);
        if ($moneda === null) {
            $io->error(sprintf('No existe la moneda %s.', self::MONEDA));

            return Command::FAILURE;
        }

        $reutilizados = [];
        foreach (self::REUTILIZADOS as $clave => $slug) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);
            if ($segmento === null) {
                $io->error(sprintf('Falta el segmento reutilizado «%s».', $slug));

                return Command::FAILURE;
            }
            $reutilizados[$clave] = $segmento;
        }

        $io->title(self::SERVICIO_NOMBRE);

        $servicio = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_CODIGO]);

        if ($servicio === null) {
            $io->text(sprintf('Servicio · %s %s (%s)', $simula ? 'crearía' : 'creado', self::SERVICIO_NOMBRE, self::SERVICIO_CODIGO));

            if (!$simula) {
                $servicio = (new TravelServicio())
                    ->setCodigo(self::SERVICIO_CODIGO)
                    ->setNombreInterno(self::SERVICIO_NOMBRE)
                    ->setTitulo([['language' => 'es', 'content' => self::SERVICIO_NOMBRE]]);
                $this->em->persist($servicio);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Servicio · ya existe (%s)', self::SERVICIO_CODIGO));
        }

        // El proveedor nace OCULTO al cliente, como Solarena: se le asigna para OPERAR —a quién
        // se le pide, con qué teléfono— sin publicarlo. Y como está oculto no aporta ni foto ni
        // nombre, así que no necesita servicio de prestador: no habría dónde enseñarlo.
        $proveedor = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::PROVEEDOR]);

        if ($proveedor === null) {
            $io->text(sprintf('Proveedor · %s %s (oculto al cliente)', $simula ? 'crearía' : 'creado', self::PROVEEDOR));

            if (!$simula) {
                $proveedor = (new TravelOrganizacion())
                    ->setNombreComercial(self::PROVEEDOR)
                    ->setTitulo([['language' => 'es', 'content' => self::PROVEEDOR]])
                    ->setDescripcion([['language' => 'es', 'content' => 'Operador de cuatrimotos y '
                        . 'tirolina en la meseta de Maras, con base en la comunidad de Cjhua.']])
                    ->setVisibleParaCliente(false);
                $this->em->persist($proveedor);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Proveedor · ya existe (%s)', self::PROVEEDOR));
        }

        $io->section('Entradas');
        $entradas = [];

        foreach (self::ENTRADAS as $clave => $def) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['nombre']]);

            if ($componente !== null) {
                $io->text(sprintf('  ya existe · %s', $def['nombre']));
                $entradas[$clave] = $componente;
                continue;
            }

            if ($def['crear'] === null) {
                $io->error(sprintf('Falta el componente de entrada «%s» y este comando no lo crea.', $def['nombre']));

                return Command::FAILURE;
            }

            $io->text(sprintf('  %s · %-40s S/ %s', $simula ? 'crearía' : 'creado ', $def['nombre'], $def['crear']['monto']));

            if ($simula) {
                continue;
            }

            $componente = (new TravelComponente())
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['crear']['titulo']]])
                ->setTipo(ComponenteTipoEnum::TICKET_HORARIO_VAR);
            $this->em->persist($componente);

            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($def['nombre'] . ' · Ingreso');
            $tarifa->setTitulo([['language' => 'es', 'content' => 'Ingreso']]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto($def['crear']['monto']);
            $this->em->persist($tarifa);

            $this->em->flush();
            $entradas[$clave] = $componente;
        }

        $io->section('Segmentos');
        $segmentos = $reutilizados;
        $nuevos = 0;

        foreach (self::REUTILIZADOS as $clave => $slug) {
            $io->text(sprintf('  reutiliza · %-38s %s', $slug, $segmentos[$clave]->getNombreInterno() ?? ''));
        }

        foreach (self::SEGMENTOS as $slug => $def) {
            $existente = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);

            if ($existente !== null) {
                $segmentos[$slug] = $existente;
                $io->text(sprintf('  ya existe · %s', $slug));
                continue;
            }

            ++$nuevos;
            $io->text(sprintf('  %s · %-38s %s', $simula ? 'crearía' : 'creado ', $slug, $def['nombre']));

            if ($simula) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($slug)
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]])
                ->setInicioModo($def['inicio'])
                ->setFinModo($def['fin']);

            $this->em->persist($segmento);
            $segmentos[$slug] = $segmento;
        }

        // Los pools se llenan con TODOS los segmentos que usa el servicio, reutilizados incluidos:
        // el M2M es lo que los ofrece al editor. Su PK es la pareja, así que repetir no duplica.
        if (!$simula && $servicio !== null) {
            foreach ($segmentos as $segmento) {
                $servicio->addSegmento($segmento);
            }

            // Las entradas al pool de COMPONENTES. Sin esto el servicio se ve completo y al
            // cotizar no ofrece el boleto: es el hueco más fácil de dejar, porque addSegmento()
            // y addComponente() son dos llamadas y sólo una salta a la vista.
            foreach ($entradas as $entrada) {
                $servicio->addComponente($entrada);
            }

            $this->em->flush();
        }

        $io->section('Rutas');
        $rutas = 0;

        foreach (self::RUTAS as $def) {
            // ⚠️ La idempotencia va por la PLANTILLA, no por el componente, y la diferencia
            // importa: `Pool Cuatrimoto Moray y Salineras` ya existía —era el genérico huérfano,
            // con las tarifas de dos proveedores y ningún segmento—. Cortar por el componente
            // habría saltado la ruta entera y lo habría dejado igual de inútil que estaba.
            $itinerario = $this->em->getRepository(TravelItinerario::class)
                ->findOneBy(['slug' => $def['plantillaSlug']]);

            if ($itinerario !== null) {
                $io->text(sprintf('  ya existe · %s', $def['plantilla']));
                continue;
            }

            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['componente']]);
            $reutilizaComponente = $componente !== null;

            ++$rutas;
            $io->text(sprintf(
                '  %s · %-44s %s · %d segmentos · %s',
                $simula ? 'crearía' : 'creada ',
                $def['componente'],
                $def['modalidad']->value,
                count($def['segmentos']),
                $reutilizaComponente
                    ? 'componente y tarifas REUTILIZADOS'
                    : implode(' / ', array_map(
                        static fn (array $t): string => $t['nombre'] . ' S/ ' . $t['monto'],
                        $def['tarifas'],
                    )),
            ));

            if ($simula) {
                continue;
            }

            if ($componente === null) {
                // ⚠️ SIN título público, y es deliberado. En un `pool` o una `privada` el título
                // del componente GANA al del segmento en la guía
                // (`traducir(c.tituloSnapshot) || delSegmento`), así que ponerlo sustituye la
                // narrativa del bloque —«Recojo e inicio de la aventura»— por el nombre del
                // producto, que el pasajero ya está viendo en la cabecera del servicio. Qué
                // incluye lo cuentan los ítems, no esto. Ver §4 quater del doc de carga.
                $componente = (new TravelComponente())
                    ->setNombreInterno($def['componente'])
                    ->setTipo($def['tipo']);
                $this->em->persist($componente);
            }

            // El pool es la pareja (servicio, componente) como PK: añadirlo dos veces no duplica.
            $servicio->addComponente($componente);

            $predeterminada = null;

            // ⚠️ Un componente que YA existía trae sus tarifas, y crearle las nuestras encima las
            // duplicaría sin que nada lo impida —no hay clave natural en `travel_tarifa`—. Las
            // suyas son las buenas: las puso quien negoció el precio. Ver
            // `app:travel:limpiar-tarifas-repetidas`, que existe precisamente por esto.
            if ($reutilizaComponente) {
                foreach ($componente->getTarifas() as $tarifa) {
                    $io->text(sprintf('      tarifa suya · %s S/ %s', $tarifa->getNombreInterno(), $tarifa->getMonto()));
                    $predeterminada ??= $tarifa;
                }
            } else {
                foreach ($def['tarifas'] as $t) {
                    // ⚠️ Los setters de TravelTarifa NO se encadenan todos: aquí, una por línea.
                    $tarifa = new TravelTarifa();
                    $tarifa->setComponente($componente);
                    $tarifa->setNombreInterno($def['componente'] . ' · ' . $t['nombre']);
                    $tarifa->setTitulo([['language' => 'es', 'content' => $t['nombre']]]);
                    $tarifa->setMoneda($moneda);
                    $tarifa->setMonto($t['monto']);
                    $tarifa->setModalidad($def['modalidad']);
                    $tarifa->setPrestador($proveedor);
                    $tarifa->setComprador($proveedor);

                    if ($def['minPax'] !== null) {
                        $tarifa->setCapacidadMinima($def['minPax']);
                    }

                    $this->em->persist($tarifa);
                    $predeterminada ??= $tarifa;
                }
            }

            $ancla = $this->resolver($segmentos, $def['segmentos'][0]);

            // La relación GLOBAL del ancla va sin hora: la hora de salida de una excursión es de
            // cada plantilla, y puesta aquí se aplicaría a cualquier tour que use el segmento.
            // Esta es la que se lleva la tarifa por defecto.
            $yaGlobal = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                'segmento' => $ancla,
                'componente' => $componente,
                'itinerarioContexto' => null,
                'dia' => null,
            ]);

            if ($yaGlobal === null) {
                $this->em->persist(
                    (new TravelSegmentoComponente())
                        ->setSegmento($ancla)
                        ->setComponente($componente)
                        ->setTarifaPredeterminada($predeterminada)
                        ->setModo(ComponenteModoEnum::INCLUIDO)
                        ->setOrden(1),
                );
            }

            $itinerario = (new TravelItinerario())
                ->setServicio($servicio)
                ->setSlug($def['plantillaSlug'])
                ->setNombreInterno($def['plantilla'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setDuracionDias(1);
            $this->em->persist($itinerario);

            foreach ($def['segmentos'] as $pos => $ref) {
                $this->em->persist(
                    (new TravelItinerarioSegmentoRel())
                        ->setItinerario($itinerario)
                        ->setSegmento($this->resolver($segmentos, $ref))
                        ->setDia(1)
                        ->setOrden($pos + 1),
                );
            }

            // Las entradas: atadas a ESTA plantilla y colgadas del segmento del sitio que las
            // pide. Sin hora —un boleto no ocurre a una hora— y sin tarifa por defecto, porque
            // cuál toca depende de la procedencia del pasajero y eso no lo sabe el catálogo.
            foreach ($def['entradas'] as $entrada) {
                $this->em->persist(
                    (new TravelSegmentoComponente())
                        ->setSegmento($this->resolver($segmentos, $entrada['segmento']))
                        ->setComponente($entradas[$entrada['clave']])
                        ->setItinerarioContexto($itinerario)
                        ->setModo(ComponenteModoEnum::INCLUIDO)
                        ->setOrden(1),
                );
            }

            // Y la SEGUNDA relación del ancla, atada a la plantilla: la única que puede promover
            // la hora a horario de toda la excursión. `validarPromocionRequierePlantilla()`
            // prohíbe hacerlo sin plantilla, y por eso la plantilla se crea antes que esta fila.
            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($ancla)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($predeterminada)
                    ->setItinerarioContexto($itinerario)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setDia(1)
                    ->setOrden(1)
                    ->setHora(new \DateTimeImmutable($def['hora']))
                    ->setHoraServicioCompleto(true),
            );
        }

        if (!$simula) {
            $this->em->flush();
        }

        // ── Los títulos públicos que sobran ─────────────────────────────────────────────
        //
        // Pasada propia por lo mismo que los ítems: se comprueba por una clave distinta de la
        // plantilla. `app:travel:renombrar-componente` escribe el título público salvo que se
        // le pase `--solo-interno`, así que el componente reutilizado acabó anunciando al
        // cliente su propio nombre interno, «Pool Cuatrimoto Moray y Salineras».
        $io->section('Títulos públicos');
        $vaciados = 0;

        foreach (self::RUTAS as $def) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['componente']]);

            if ($componente === null || $componente->getTitulo() === []) {
                continue;
            }

            ++$vaciados;
            $io->text(sprintf(
                '  %s · %-44s quita «%s»',
                $simula ? 'haría  ' : 'hecho  ',
                $def['componente'],
                $componente->getTitulo()[0]['content'] ?? '',
            ));

            if (!$simula) {
                $componente->setSobreescribirTraduccion(true);
                $componente->setTitulo([]);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        if ($vaciados === 0) {
            $io->text('  todos sin título público, que es lo correcto.');
        }

        // ── Los ítems, en una pasada aparte ─────────────────────────────────────────────
        //
        // ⚠️ Van FUERA del bucle de rutas a propósito. La idempotencia de una ruta la decide su
        // plantilla, así que en la segunda pasada el bucle de arriba no entra — y si los ítems
        // vivieran dentro, un componente que se creó sin ellos no los recibiría nunca. Aquí se
        // comprueban uno a uno contra la pareja (componente, término), que es lo que de verdad
        // los identifica.
        $io->section('Ítems del «qué incluye»');
        $puestos = 0;

        foreach (self::RUTAS as $def) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['componente']]);

            if ($componente === null) {
                continue;
            }

            $faltan = [];
            $orden = 0;

            foreach ([...self::ITEMS_BASE, ...$def['items']] as $nombre) {
                ++$orden;
                $termino = $this->termino($nombre, $simula);

                if ($termino === null) {
                    $faltan[] = $nombre . ' (crearía el término)';
                    continue;
                }

                $ya = $this->em->getRepository(TravelComponenteItem::class)
                    ->findOneBy(['componente' => $componente, 'diccionario' => $termino]);

                if ($ya !== null) {
                    continue;
                }

                $faltan[] = $nombre;
                ++$puestos;

                if ($simula) {
                    continue;
                }

                $this->em->persist(
                    (new TravelComponenteItem())
                        ->setComponente($componente)
                        ->setDiccionario($termino)
                        ->setModo(ItemModoEnum::INCLUIDO)
                        ->setOrden($orden),
                );
            }

            $io->text(sprintf(
                '  %-44s %s',
                $def['componente'],
                $faltan === [] ? 'completo' : ($simula ? 'añadiría: ' : 'añadidos: ') . implode(' · ', $faltan),
            ));
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d segmento(s), %d ruta(s), %d ítem(s) y %d título(s) retirado(s).',
            $simula ? 'Se crearían' : 'Creados',
            $nuevos,
            $rutas,
            $puestos,
            $vaciados,
        ));

        $io->note([
            'Las entradas van al pool y se cobran aparte: el Boleto Turístico Parcial en las tres',
            'rutas que pasan por Moray, y «Ingreso a Maras» sin tocar nada, porque ya cuelga del',
            'segmento de las Salineras que se reutiliza.',
            '',
            'Los montos del privado van SIN IGV, como los factura el proveedor.',
        ]);

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * El término del diccionario, creándolo sólo si está declarado en `self::ITEMS_NUEVOS`.
     *
     * Devuelve null en ensayo cuando habría que crearlo: así el informe lo dice sin escribir.
     */
    private function termino(string $nombre, bool $simula): ?TravelItemDiccionario
    {
        $termino = $this->em->getRepository(TravelItemDiccionario::class)
            ->findOneBy(['nombreInterno' => $nombre]);

        if ($termino !== null) {
            return $termino;
        }

        if (!isset(self::ITEMS_NUEVOS[$nombre]) || $simula) {
            return null;
        }

        $termino = (new TravelItemDiccionario())
            ->setNombreInterno($nombre)
            ->setTitulo([['language' => 'es', 'content' => self::ITEMS_NUEVOS[$nombre]]]);

        $this->em->persist($termino);
        $this->em->flush();

        return $termino;
    }

    /**
     * Resuelve una referencia de `segmentos`: `@clave` apunta a los reutilizados, el resto a slug.
     *
     * @param array<string, TravelSegmento> $segmentos
     */
    private function resolver(array $segmentos, string $ref): TravelSegmento
    {
        $clave = str_starts_with($ref, '@') ? substr($ref, 1) : $ref;

        return $segmentos[$clave] ?? throw new \LogicException(sprintf('Segmento no resuelto: %s', $ref));
    }
}
