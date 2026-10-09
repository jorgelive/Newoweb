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
 * Las excursiones del lago Titicaca, de Qhapaq Adventures.
 *
 * Mismo molde que `CrearCuatrimotosMarasCommand`: patrón ancla, idempotencia por PLANTILLA,
 * ítems en pasada aparte y componentes reutilizados con sus propias tarifas. Lo que cambia aquí
 * está abajo.
 *
 * ── Siete productos, siete componentes ──────────────────────────────────────
 *
 * El clásico, el folclórico y el VIP recorren el mismo lago y **no son el mismo producto**: el
 * folclórico mete show al embarcar y en el almuerzo, el VIP cambia los 538 escalones por una
 * bajada en triciclo y añade los Sikuris. Si el itinerario difiere, son componentes distintos
 * con su propio ancla — no tres tarifas de uno solo, que dejaría al proveedor sin saber cuál
 * mandar al leer la Orden de Servicio.
 *
 * Lo que SÍ se comparte son las paradas que son las mismas: los Uros, la caminata de Taquile,
 * la plaza, el almuerzo y el retorno. Es el mismo reparto que Moray y las Salineras entre las
 * rutas de cuatrimotos.
 *
 * ── La logística NO vive aquí ───────────────────────────────────────────────
 *
 * Los traslados de Puno, el bus nocturno desde Cusco y el alojamiento son servicios
 * independientes (`TRF_PUN`, `TRF_CUZ`, `ALO`) y se arman por cotización. El motivo es del
 * negocio: quien llega en avión a Juliaca no puede empalmar —no hay vuelo de mañana— pero el día
 * que lo haya, la excursión no se toca. Ver `app:travel:crear-conexion-puno`.
 *
 * ── La jerarquía de fuentes, otra vez ───────────────────────────────────────
 *
 * Tres documentos y no coinciden. Manda el **tarifario de agencias 2026** —es lo que factura— en
 * precios y horarios; los itinerarios oficiales mandan en el recorrido. Donde chocan:
 *
 *   medio día      el itinerario dice 15:30; el tarifario, 15:00        → 15:00
 *   folclórico     el itinerario recoge a las 07:00; el tarifario 08:00 → 08:00
 *   2D con Taquile el itinerario llega a Puno 16:00; el tarifario 15:00 → 15:00
 *   2D con Taquile el almuerzo del día 2 es «opcional» en el itinerario
 *                  e incluido en el tarifario                           → incluido
 *
 * ⚠️ El Full Day a Amantaní (S/70) no tiene itinerario de Qhapaq. Su recorrido se tomó de una
 * ficha pública de OTRO operador, y **sólo el recorrido**: sus horas retornan a Puno a las 17:30
 * y las de Qhapaq a las 15:00, tres horas y media de diferencia que deciden si el pasajero
 * alcanza el bus de vuelta.
 *
 * ⚠️ El recorrido de la Opción 07 (Luquina) también viene de un tercero, Puno Tours, y por el
 * mismo motivo: Qhapaq la vende y no publica su itinerario. Las horas de aquella ficha retornan
 * a Puno a las 15:45 y las de Qhapaq a las 15:00.
 */
#[AsCommand(
    name: 'app:travel:crear-titicaca',
    description: 'Crea el servicio «Lago Titicaca» con las excursiones de Qhapaq Adventures.',
    hidden: true,
)]
final class CrearTiticacaCommand extends Command
{
    private const SERVICIO_CODIGO = 'TITICACA';
    private const SERVICIO_NOMBRE = 'Lago Titicaca';
    private const MONEDA = 'PEN';
    private const PROVEEDOR = 'Qhapaq Adventures';

    /**
     * Términos del diccionario que no existían. Los otros nueve que hacen falta, sí.
     *
     * @var array<string, string>
     */
    private const ITEMS_NUEVOS = [
        'Lancha rápida' => 'Lancha rápida',
        'Show folclórico' => 'Show folclórico',
        'Show de Sikuris' => 'Show de Sikuris',
        'Hospedaje en casa de familia' => 'Hospedaje en casa de familia',
        'Bajada en triciclo' => 'Bajada en triciclo',
        'Fotos del recorrido' => 'Fotos del recorrido',
        'Hospedaje con baño privado' => 'Hospedaje con baño privado y agua caliente',
    ];

    /**
     * Los segmentos nuevos, por slug. Los comparten varias rutas salvo las anclas.
     *
     * @var array<string, array{nombre: string, titulo: string, contenido: string,
     *                          inicio: PuntoModoEnum, fin: PuntoModoEnum}>
     */
    private const SEGMENTOS = [
        // ── Anclas, una por producto ────────────────────────────────────────
        'SAL_EXC-TITICACA-UROS_HD' => [
            'nombre' => 'Uros medio día · recojo e inicio',
            'titulo' => 'Islas de los Uros en medio día',
            'contenido' => 'Les recogemos en su hotel o en la estación de bus y les llevamos al puerto '
                . 'de Puno, donde embarcamos hacia las islas flotantes. Son unas tres horas en total.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'SAL_EXC-TITICACA-CLASICO' => [
            'nombre' => 'Uros y Taquile · recojo e inicio (clásico)',
            'titulo' => 'Uros y Taquile en lancha',
            'contenido' => 'Les recogemos en su hotel o en la estación de bus y embarcamos en el puerto '
                . 'de Puno con destino a las islas flotantes, para seguir después hacia Taquile.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'SAL_EXC-TITICACA-FOLCLORICO' => [
            'nombre' => 'Uros y Taquile folclórico · recojo e inicio',
            'titulo' => 'Uros y Taquile con folclore',
            'contenido' => 'Les recogemos en su hotel o en la estación de bus. Antes de zarpar hay una '
                . 'presentación cultural en el puerto, y de ahí salimos en lancha rápida hacia las islas.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'SAL_EXC-TITICACA-VIP' => [
            'nombre' => 'Uros y Taquile VIP · recojo e inicio',
            'titulo' => 'Uros y Taquile VIP',
            'contenido' => 'Traslado privado desde su hotel hasta el puerto de Puno y embarque en lancha '
                . 'rápida. El grupo es reducido y el servicio, personalizado.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'SAL_EXC-TITICACA-AMANTANI_FD' => [
            'nombre' => 'Uros y Amantaní en el día · recojo e inicio',
            'titulo' => 'Uros y Amantaní en un día',
            'contenido' => 'Les recogemos en su hotel o en la estación de bus para el check-in en el '
                . 'muelle de Puno, y de ahí salimos en bote hacia las islas flotantes y Amantaní.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'SAL_EXC-TITICACA-2D_AMANTANI' => [
            'nombre' => 'Uros y Amantaní 2D1N · recojo e inicio',
            'titulo' => 'Uros y Amantaní, dos días',
            'contenido' => 'Les recogemos en su hotel y salimos del puerto de Puno hacia las islas '
                . 'flotantes. La noche se pasa en Amantaní, en casa de una familia de la isla.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'SAL_EXC-TITICACA-2D_TAQUILE' => [
            'nombre' => 'Uros, Amantaní y Taquile 2D1N · recojo e inicio',
            'titulo' => 'Uros, Amantaní y Taquile, dos días',
            'contenido' => 'Les recogemos en su hotel y salimos del puerto de Puno hacia las islas '
                . 'flotantes. Se duerme en Amantaní, en casa de una familia, y el segundo día se visita '
                . 'Taquile antes de volver.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],

        // ── Paradas compartidas ─────────────────────────────────────────────
        'VIS-TITICACA-UROS' => [
            'nombre' => 'Islas flotantes de los Uros',
            'titulo' => 'Islas flotantes de los Uros',
            'contenido' => 'Islas hechas de totora que se renuevan capa sobre capa y flotan ancladas al '
                . 'fondo del lago. Se visitan dos, y el guía explica durante unos veinte minutos cómo se '
                . 'construyen y cómo se vive en ellas. Después queda tiempo para fotos, para comprar '
                . 'artesanía o para dar un paseo en balsa de totora, que va aparte.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-TITICACA-TAQUILE' => [
            'nombre' => 'Isla de Taquile y caminata al pueblo',
            'titulo' => 'Isla de Taquile',
            'contenido' => 'Se desembarca en el muelle norte y se sube caminando hasta el pueblo. Por el '
                . 'camino el guía cuenta la historia de la isla y su tradición textil, declarada '
                . 'Patrimonio de la Humanidad: aquí tejen los hombres, y lo que uno lleva puesto dice si '
                . 'está casado o soltero.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-TITICACA-TAQUILE_PLAZA' => [
            'nombre' => 'Plaza principal y mercado artesanal de Taquile',
            'titulo' => 'Plaza y mercado de Taquile',
            'contenido' => 'La plaza del pueblo y su salón artesanal, donde se vende el tejido que hace '
                . 'la propia comunidad y que se reparte por turnos entre las familias.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ALM-TITICACA-TAQUILE' => [
            'nombre' => 'Almuerzo típico en Taquile',
            'titulo' => 'Almuerzo en Taquile',
            'contenido' => 'Almuerzo en un restaurante de la isla, con lo que se cultiva y se pesca allí '
                . 'mismo: trucha del lago, quinua y papa de la zona.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'RET_EXC-TITICACA-PUNO' => [
            'nombre' => 'Retorno al puerto de Puno',
            'titulo' => 'Retorno a Puno',
            'contenido' => 'Se baja al muelle principal y se navega de vuelta a Puno, donde espera el '
                . 'transporte que les deja en el hotel.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'VIS-TITICACA-AMANTANI' => [
            'nombre' => 'Llegada a Amantaní y casas de familia',
            'titulo' => 'Isla de Amantaní',
            'contenido' => 'En el muelle esperan las familias que acogen a los viajeros. El guía reparte '
                . 'los grupos —de dos a cinco personas por casa— y cada quien se va con la suya. '
                . 'Amantaní es quechua y vive de la agricultura: las casas son sencillas y los servicios, '
                . 'básicos. No hay agua potable ni electricidad.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ALM-TITICACA-AMANTANI' => [
            'nombre' => 'Almuerzo en casa de familia (Amantaní)',
            'titulo' => 'Almuerzo en casa de familia',
            'contenido' => 'Se come en casa, con la familia que les acoge y lo que ellos mismos cultivan.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-TITICACA-AMANTANI_CIMA' => [
            'nombre' => 'Caminata a la cima de Amantaní',
            'titulo' => 'Caminata a la cima',
            'contenido' => 'Subida al punto más alto de la isla por la tarde, cuando el lago se queda '
                . 'quieto y se ve la orilla boliviana al otro lado.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'CEN-TITICACA-AMANTANI' => [
            'nombre' => 'Cena en casa de familia (Amantaní)',
            'titulo' => 'Cena en casa de familia',
            'contenido' => 'Cena en casa, otra vez con la familia.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-PENA' => [
            'nombre' => 'Peña con los pobladores de Amantaní',
            'titulo' => 'Peña en el local comunal',
            'contenido' => 'Después de cenar, los anfitriones prestan la ropa típica de la isla y '
                . 'acompañan a los viajeros al local comunal, donde hay música en vivo, bebida y una '
                . 'fogata. Es voluntario, y es lo que más se recuerda del viaje.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'DES-TITICACA-AMANTANI' => [
            'nombre' => 'Desayuno en casa de familia (Amantaní)',
            'titulo' => 'Desayuno en casa de familia',
            'contenido' => 'Desayuno en casa antes de despedirse de la familia.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],

        // ── Lo propio de cada producto ──────────────────────────────────────
        'ACT-TITICACA-SHOW_EMBARQUE' => [
            'nombre' => 'Show cultural antes de embarcar',
            'titulo' => 'Show cultural en el puerto',
            'contenido' => 'Presentación de danzas en el puerto, antes de zarpar hacia las islas.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-SHOW_ALMUERZO' => [
            'nombre' => 'Show folclórico en el almuerzo (Taquile)',
            'titulo' => 'Danzas folclóricas',
            'contenido' => 'Durante el almuerzo, o en la plaza principal, hay una presentación de danzas '
                . 'de la zona.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-SIKURIS' => [
            'nombre' => 'Show de Sikuris en la plaza de Taquile',
            'titulo' => 'Sikuris en la plaza',
            'contenido' => 'Presentación de sikuris en la plaza principal: zampoñas tocadas en pareja, '
                . 'donde cada músico sopla la mitad de la melodía y sólo suena entera entre dos.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-TRICICLO' => [
            'nombre' => 'Bajada en triciclo al muelle (Taquile)',
            'titulo' => 'Bajada en triciclo',
            'contenido' => 'En lugar de bajar los 538 escalones hasta el muelle, el descenso se hace en '
                . 'triciclo, que en la isla es el transporte de siempre y no hace ruido.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-DANZA_AMANTANI' => [
            'nombre' => 'Danza típica de Amantaní',
            'titulo' => 'Danza típica de Amantaní',
            'contenido' => 'Los pobladores reciben al grupo con la danza de la isla y se puede bailar '
                . 'con ellos.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ALM-TITICACA-AMANTANI_CAMPESTRE' => [
            'nombre' => 'Almuerzo campestre en Amantaní',
            'titulo' => 'Almuerzo campestre',
            'contenido' => 'Almuerzo al aire libre en la isla antes de bajar al muelle.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'SAL_EXC-TITICACA-2D_LUQUINA' => [
            'nombre' => 'Uros, Taquile y Luquina 2D1N · recojo e inicio',
            'titulo' => 'Uros, Taquile y Luquina, dos días',
            'contenido' => 'Les recogemos en su hotel y salimos en lancha rápida del puerto de Puno '
                . 'hacia las islas flotantes. El primer día se visitan los Uros y Taquile, y se duerme '
                . 'en Luquina, en la península, en casa de una familia.',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
        ],
        'VIS-TITICACA-LUQUINA' => [
            'nombre' => 'Llegada a Luquina y casa de familia',
            'titulo' => 'Luquina Chico',
            'contenido' => 'Luquina no es una isla sino una península, y se llega por agua desde '
                . 'Taquile. La familia anfitriona recibe al grupo en el muelle; después se instalan en '
                . 'la casa y el guía cuenta las costumbres del sitio y para qué sirve cada prenda de la '
                . 'vestimenta típica.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'VIS-TITICACA-LUQUINA_MIRADOR' => [
            'nombre' => 'Mirador de Luquina al atardecer',
            'titulo' => 'Atardecer en el mirador',
            'contenido' => 'Caminata hasta el mirador de la península para ver caer el sol sobre el '
                . 'lago. Es el momento del día en que el agua se queda quieta del todo.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'CEN-TITICACA-LUQUINA' => [
            'nombre' => 'Cena familiar en Luquina',
            'titulo' => 'Cena con la familia',
            'contenido' => 'Cena en casa, con la familia que les acoge.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'DES-TITICACA-LUQUINA' => [
            'nombre' => 'Desayuno en Luquina',
            'titulo' => 'Desayuno con la familia',
            'contenido' => 'Desayuno en casa antes de empezar el segundo día.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-LUQUINA_LIBRE' => [
            'nombre' => 'Kayak o paseo en bote en Luquina',
            'titulo' => 'Kayak o paseo en bote',
            'contenido' => 'Rato libre a la orilla. Se puede salir en kayak o dar un paseo en bote '
                . 'local; las dos cosas se pagan aparte, allí mismo.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ALM-TITICACA-LUQUINA' => [
            'nombre' => 'Almuerzo típico en Luquina',
            'titulo' => 'Almuerzo típico',
            'contenido' => 'Último almuerzo con la familia antes de volver a Puno.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
        'ACT-TITICACA-VIVENCIA' => [
            'nombre' => 'Vivencia con la familia en Amantaní',
            'titulo' => 'Vivencia con la familia',
            'contenido' => 'La mañana se pasa con la familia en lo suyo: el campo y los animales. No es '
                . 'una demostración montada para visitantes, es el trabajo del día.',
            'inicio' => PuntoModoEnum::SIN_DEFINIR,
            'fin' => PuntoModoEnum::SIN_DEFINIR,
        ],
    ];

    /**
     * Un producto por fila. `segmentos` admite `slug|2` para el día 2.
     *
     * `crear` a false reutiliza el componente que ya está, con SUS tarifas, y no escribe las de
     * aquí: `travel_tarifa` no tiene clave natural y duplicarlas no lo impide nadie.
     *
     * @var list<array{componente: string, crear: bool, tipo: ComponenteTipoEnum, plantilla: string,
     *                 plantillaSlug: string, titulo: string, hora: string, dias: int,
     *                 segmentos: list<string>, items: list<string>,
     *                 tarifas: list<array{nombre: string, monto: string}>}>
     */
    private const RUTAS = [
        [
            'componente' => 'Pool Uros Medio Día',
            'crear' => true,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => 'Medio Día Islas de los Uros',
            'plantillaSlug' => 'HD-TITICACA-UROS',
            'titulo' => 'Islas de los Uros en medio día',
            'hora' => '09:00',
            'dias' => 1,
            'segmentos' => ['SAL_EXC-TITICACA-UROS_HD', 'VIS-TITICACA-UROS', 'RET_EXC-TITICACA-PUNO'],
            'items' => ['Transporte', 'Lancha', 'Guia Profesional', 'Tickets de ingreso'],
            'tarifas' => [['nombre' => 'Compartido', 'monto' => '25.00']],
        ],
        [
            'componente' => 'Pool Uros Taquile con almuerzo',
            'crear' => false,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => 'Full Day Uros y Taquile (Clásico)',
            'plantillaSlug' => 'FD-TITICACA-TAQUILE-CLASICO',
            'titulo' => 'Uros y Taquile en lancha',
            'hora' => '07:00',
            'dias' => 1,
            'segmentos' => [
                'SAL_EXC-TITICACA-CLASICO', 'VIS-TITICACA-UROS', 'VIS-TITICACA-TAQUILE',
                'VIS-TITICACA-TAQUILE_PLAZA', 'ALM-TITICACA-TAQUILE', 'RET_EXC-TITICACA-PUNO',
            ],
            'items' => ['Transporte', 'Lancha', 'Guia Profesional', 'Tickets de ingreso', 'Almuerzo'],
            'tarifas' => [['nombre' => 'Compartido', 'monto' => '80.00']],
        ],
        [
            'componente' => 'Pool Uros Taquile Folclórico',
            'crear' => true,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => 'Full Day Uros y Taquile (Folclórico)',
            'plantillaSlug' => 'FD-TITICACA-TAQUILE-FOLCLORICO',
            'titulo' => 'Uros y Taquile con folclore',
            'hora' => '08:00',
            'dias' => 1,
            'segmentos' => [
                'SAL_EXC-TITICACA-FOLCLORICO', 'ACT-TITICACA-SHOW_EMBARQUE', 'VIS-TITICACA-UROS',
                'VIS-TITICACA-TAQUILE', 'VIS-TITICACA-TAQUILE_PLAZA', 'ALM-TITICACA-TAQUILE',
                'ACT-TITICACA-SHOW_ALMUERZO', 'RET_EXC-TITICACA-PUNO',
            ],
            'items' => ['Transporte', 'Lancha rápida', 'Guia Profesional', 'Tickets de ingreso',
                'Almuerzo', 'Show folclórico'],
            'tarifas' => [['nombre' => 'Compartido', 'monto' => '100.00']],
        ],
        [
            'componente' => 'Pool Uros Taquile VIP',
            'crear' => true,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => 'Full Day Uros y Taquile (VIP)',
            'plantillaSlug' => 'FD-TITICACA-TAQUILE-VIP',
            'titulo' => 'Uros y Taquile VIP',
            'hora' => '08:00',
            'dias' => 1,
            'segmentos' => [
                'SAL_EXC-TITICACA-VIP', 'VIS-TITICACA-UROS', 'VIS-TITICACA-TAQUILE',
                'VIS-TITICACA-TAQUILE_PLAZA', 'ACT-TITICACA-SIKURIS', 'ALM-TITICACA-TAQUILE',
                'ACT-TITICACA-TRICICLO', 'RET_EXC-TITICACA-PUNO',
            ],
            'items' => ['Transporte', 'Lancha rápida', 'Guia Profesional', 'Tickets de ingreso',
                'Almuerzo', 'Show de Sikuris', 'Bajada en triciclo', 'Fotos del recorrido'],
            'tarifas' => [['nombre' => 'Compartido exclusivo', 'monto' => '120.00']],
        ],
        [
            'componente' => 'Pool Uros Amantaní Full Day',
            'crear' => true,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => 'Full Day Uros y Amantaní',
            'plantillaSlug' => 'FD-TITICACA-AMANTANI',
            'titulo' => 'Uros y Amantaní en un día',
            'hora' => '08:00',
            'dias' => 1,
            'segmentos' => [
                'SAL_EXC-TITICACA-AMANTANI_FD', 'VIS-TITICACA-UROS', 'VIS-TITICACA-AMANTANI',
                'ACT-TITICACA-DANZA_AMANTANI', 'ALM-TITICACA-AMANTANI_CAMPESTRE',
                'RET_EXC-TITICACA-PUNO',
            ],
            'items' => ['Transporte', 'Lancha', 'Guia Profesional', 'Tickets de ingreso', 'Almuerzo'],
            'tarifas' => [['nombre' => 'Compartido', 'monto' => '70.00']],
        ],
        [
            'componente' => 'Pool Uros Amantaní 2D1N',
            'crear' => true,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => '2D/1N Uros y Amantaní',
            'plantillaSlug' => '2D-TITICACA-AMANTANI',
            'titulo' => 'Uros y Amantaní, dos días',
            'hora' => '08:00',
            'dias' => 2,
            'segmentos' => [
                'SAL_EXC-TITICACA-2D_AMANTANI', 'VIS-TITICACA-UROS', 'VIS-TITICACA-AMANTANI',
                'ALM-TITICACA-AMANTANI', 'VIS-TITICACA-AMANTANI_CIMA', 'CEN-TITICACA-AMANTANI',
                'ACT-TITICACA-PENA',
                'DES-TITICACA-AMANTANI|2', 'ACT-TITICACA-VIVENCIA|2', 'RET_EXC-TITICACA-PUNO|2',
            ],
            'items' => ['Transporte', 'Lancha', 'Guia Profesional', 'Tickets de ingreso',
                'Hospedaje en casa de familia', 'Almuerzo (Dia 1)', 'Cena (Dia 1)', 'Desayuno (Dia 2)'],
            'tarifas' => [['nombre' => 'Compartido', 'monto' => '140.00']],
        ],
        [
            'componente' => 'Pool Uros Amantani Taquile con almuerzo',
            'crear' => false,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => '2D/1N Uros, Amantaní y Taquile',
            'plantillaSlug' => '2D-TITICACA-AMANTANI-TAQUILE',
            'titulo' => 'Uros, Amantaní y Taquile, dos días',
            'hora' => '08:00',
            'dias' => 2,
            'segmentos' => [
                'SAL_EXC-TITICACA-2D_TAQUILE', 'VIS-TITICACA-UROS', 'VIS-TITICACA-AMANTANI',
                'ALM-TITICACA-AMANTANI', 'VIS-TITICACA-AMANTANI_CIMA', 'CEN-TITICACA-AMANTANI',
                'ACT-TITICACA-PENA',
                'DES-TITICACA-AMANTANI|2', 'VIS-TITICACA-TAQUILE|2', 'VIS-TITICACA-TAQUILE_PLAZA|2',
                'ALM-TITICACA-TAQUILE|2', 'RET_EXC-TITICACA-PUNO|2',
            ],
            'items' => ['Transporte', 'Lancha', 'Guia Profesional', 'Tickets de ingreso',
                'Hospedaje en casa de familia', 'Almuerzo (Dia 1)', 'Cena (Dia 1)',
                'Desayuno (Dia 2)', 'Almuerzo (Dia 2)'],
            'tarifas' => [['nombre' => 'Compartido', 'monto' => '160.00']],
        ],
        [
            // ⚠️ Luquina NO es una variante de la de Amantaní: Taquile va el día 1 y la noche se
            // pasa en la península, no en la isla. Lo que Qhapaq cobra de más —y lo que vende— es
            // la casa con baño privado y agua caliente, que en Amantaní no hay.
            'componente' => 'Pool Uros Taquile Luquina 2D1N',
            'crear' => true,
            'tipo' => ComponenteTipoEnum::EXCURSION_POOL,
            'plantilla' => '2D/1N Uros, Taquile y Luquina',
            'plantillaSlug' => '2D-TITICACA-LUQUINA',
            'titulo' => 'Uros, Taquile y Luquina, dos días',
            'hora' => '08:00',
            'dias' => 2,
            'segmentos' => [
                'SAL_EXC-TITICACA-2D_LUQUINA', 'VIS-TITICACA-UROS', 'VIS-TITICACA-TAQUILE',
                'VIS-TITICACA-TAQUILE_PLAZA', 'ALM-TITICACA-TAQUILE', 'VIS-TITICACA-LUQUINA',
                'VIS-TITICACA-LUQUINA_MIRADOR', 'CEN-TITICACA-LUQUINA',
                'DES-TITICACA-LUQUINA|2', 'ACT-TITICACA-VIVENCIA|2', 'ACT-TITICACA-LUQUINA_LIBRE|2',
                'ALM-TITICACA-LUQUINA|2', 'RET_EXC-TITICACA-PUNO|2',
            ],
            'items' => ['Transporte', 'Lancha rápida', 'Guia Profesional', 'Tickets de ingreso',
                'Hospedaje con baño privado', 'Almuerzo (Dia 1)', 'Cena (Dia 1)',
                'Desayuno (Dia 2)', 'Almuerzo (Dia 2)'],
            'tarifas' => [['nombre' => 'Compartido exclusivo', 'monto' => '200.00']],
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

        $proveedor = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::PROVEEDOR]);

        if ($proveedor === null) {
            $io->text(sprintf('Proveedor · %s %s (oculto al cliente)', $simula ? 'crearía' : 'creado', self::PROVEEDOR));

            if (!$simula) {
                $proveedor = (new TravelOrganizacion())
                    ->setNombreComercial(self::PROVEEDOR)
                    ->setTitulo([['language' => 'es', 'content' => self::PROVEEDOR]])
                    ->setDescripcion([['language' => 'es', 'content' => 'Operador de excursiones en el '
                        . 'lago Titicaca, con base en Puno.']])
                    ->setVisibleParaCliente(false);
                $this->em->persist($proveedor);
                $this->em->flush();
            }
        } else {
            $io->text(sprintf('Proveedor · ya existe (%s)', self::PROVEEDOR));
        }

        $io->section('Segmentos');
        $segmentos = [];
        $nuevos = 0;

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

        if (!$simula && $servicio !== null) {
            foreach ($segmentos as $segmento) {
                $servicio->addSegmento($segmento);
            }
            $this->em->flush();
        }

        $io->section('Productos');
        $rutas = 0;

        foreach (self::RUTAS as $def) {
            $itinerario = $this->em->getRepository(TravelItinerario::class)
                ->findOneBy(['slug' => $def['plantillaSlug']]);

            if ($itinerario !== null) {
                $io->text(sprintf('  ya existe · %s', $def['plantilla']));
                continue;
            }

            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['componente']]);

            if ($componente === null && !$def['crear']) {
                $io->error(sprintf('Falta el componente «%s», y este comando no lo crea.', $def['componente']));

                return Command::FAILURE;
            }

            ++$rutas;
            $io->text(sprintf(
                '  %s · %-42s %d segmentos · %s',
                $simula ? 'crearía' : 'creado ',
                $def['componente'],
                count($def['segmentos']),
                $componente !== null
                    ? 'componente y tarifas REUTILIZADOS'
                    : implode(' / ', array_map(
                        static fn (array $t): string => $t['nombre'] . ' S/ ' . $t['monto'],
                        $def['tarifas'],
                    )),
            ));

            if ($simula) {
                continue;
            }

            $reutiliza = $componente !== null;

            if ($componente === null) {
                // Sin título público: en un `pool` gana al del segmento y lo que el pasajero
                // necesita saber ya lo dicen el segmento y los ítems. Ver §4 quater del doc.
                $componente = (new TravelComponente())
                    ->setNombreInterno($def['componente'])
                    ->setTipo($def['tipo']);
                $this->em->persist($componente);
            }

            $servicio->addComponente($componente);
            $predeterminada = null;

            if ($reutiliza) {
                // Las suyas se enseñan, pero quien manda es el tarifario: la pasada «Tarifas
                // 2026» de más abajo las pone al día.
                foreach ($componente->getTarifas() as $tarifa) {
                    $io->text(sprintf('      tarifa suya · %s S/ %s', $tarifa->getNombreInterno(), $tarifa->getMonto()));
                    $predeterminada ??= $tarifa;
                }
            } else {
                foreach ($def['tarifas'] as $t) {
                    $tarifa = new TravelTarifa();
                    $tarifa->setComponente($componente);
                    $tarifa->setNombreInterno($def['componente'] . ' · ' . $t['nombre']);
                    $tarifa->setTitulo([['language' => 'es', 'content' => $t['nombre']]]);
                    $tarifa->setMoneda($moneda);
                    $tarifa->setMonto($t['monto']);
                    $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
                    $tarifa->setPrestador($proveedor);
                    $tarifa->setComprador($proveedor);
                    $this->em->persist($tarifa);
                    $predeterminada ??= $tarifa;
                }
            }

            [$anclaSlug] = $this->partir($def['segmentos'][0]);
            $ancla = $segmentos[$anclaSlug];

            $yaGlobal = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                'segmento' => $ancla, 'componente' => $componente,
                'itinerarioContexto' => null, 'dia' => null,
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
                ->setDuracionDias($def['dias']);
            $this->em->persist($itinerario);

            $orden = [1 => 0, 2 => 0];

            foreach ($def['segmentos'] as $ref) {
                [$slug, $dia] = $this->partir($ref);
                ++$orden[$dia];

                $this->em->persist(
                    (new TravelItinerarioSegmentoRel())
                        ->setItinerario($itinerario)
                        ->setSegmento($segmentos[$slug])
                        ->setDia($dia)
                        ->setOrden($orden[$dia]),
                );
            }

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

        // ── Las tarifas del tarifario 2026 ──────────────────────────────────────────────
        //
        // ⚠️ Cambia la regla que traía este comando. Al cargar se respetaban las tarifas del
        // componente que ya existía, porque no había autoridad para contradecirlas. Ahora sí:
        // el tarifario de agencias de Qhapaq es lo que factura, y lo que había era de fecha
        // desconocida — un «Qhapac Adventures Rapida» de S/90 que no existe en 2026 y un full
        // day a S/30, por debajo del medio día de tres horas.
        //
        // ⚠️ **Sólo se tocan las tarifas de Qhapaq.** Las de otros proveedores se dejan y se
        // informan: no tengo su tarifario de 2026 y borrarlas sería tirar un dato que nadie
        // puede reponer.
        $io->section('Tarifas 2026');
        $tocadas = 0;

        foreach (self::RUTAS as $def) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $def['componente']]);

            if ($componente === null) {
                continue;
            }

            $esperadas = [];
            // ⚠️ Las adoptadas se anotan por id de objeto: tras renombrarlas ya no están en
            // `$esperadas`, y el bucle de abajo las tomaría por tarifas de Qhapaq fuera del
            // tarifario y las borraría — justo la que se acaba de poner al día.
            $adoptadas = [];

            foreach ($def['tarifas'] as $t) {
                $esperadas[$def['componente'] . ' · ' . $t['nombre']] = $t['monto'];
            }

            // Primero, adoptar por IMPORTE: si Qhapaq ya tiene una tarifa con ese monto es la
            // misma con otro nombre, y renombrarla conserva su id. Borrarla y crear otra igual
            // sería churn gratis, y rompería cualquier cosa que la citara.
            foreach ($componente->getTarifas() as $tarifa) {
                $nombre = (string) $tarifa->getNombreInterno();

                if (isset($esperadas[$nombre]) || stripos($nombre, 'qhapa') === false) {
                    continue;
                }

                $destino = array_search($tarifa->getMonto(), $esperadas, true);

                if ($destino === false) {
                    continue;
                }

                ++$tocadas;
                $io->text(sprintf('  %s · renombra «%s» → «%s», mismo importe', $simula ? 'haría ' : 'hecho ', $nombre, $destino));

                if (!$simula) {
                    $tarifa->setNombreInterno($destino);
                    $tarifa->setTitulo([['language' => 'es', 'content' => 'Compartido']]);
                    $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
                    $tarifa->setPrestador($proveedor);
                    $tarifa->setComprador($proveedor);
                }

                $adoptadas[spl_object_id($tarifa)] = true;
                unset($esperadas[$destino]);
            }

            foreach ($componente->getTarifas() as $tarifa) {
                if (isset($adoptadas[spl_object_id($tarifa)])) {
                    continue;
                }

                $nombre = (string) $tarifa->getNombreInterno();

                if (isset($esperadas[$nombre])) {
                    if ($tarifa->getMonto() !== $esperadas[$nombre]) {
                        ++$tocadas;
                        $io->text(sprintf('  %s · %s  S/ %s → S/ %s', $simula ? 'haría ' : 'hecho ', $nombre, $tarifa->getMonto(), $esperadas[$nombre]));

                        if (!$simula) {
                            $tarifa->setMonto($esperadas[$nombre]);
                        }
                    }

                    unset($esperadas[$nombre]);
                    continue;
                }

                // Una tarifa de Qhapaq que el tarifario 2026 ya no tiene: sobra.
                if (stripos($nombre, 'qhapa') !== false) {
                    ++$tocadas;
                    $io->text(sprintf('  %s · retira «%s» S/ %s, no está en el tarifario 2026', $simula ? 'haría ' : 'hecho ', $nombre, $tarifa->getMonto()));

                    if (!$simula) {
                        $this->em->remove($tarifa);
                    }

                    continue;
                }

                $io->text(sprintf('  respeta · «%s» S/ %s, de otro proveedor', $nombre, $tarifa->getMonto()));
            }

            foreach ($esperadas as $nombre => $monto) {
                ++$tocadas;
                $io->text(sprintf('  %s · añade «%s» S/ %s', $simula ? 'haría ' : 'hecho ', $nombre, $monto));

                if ($simula) {
                    continue;
                }

                $tarifa = new TravelTarifa();
                $tarifa->setComponente($componente);
                $tarifa->setNombreInterno($nombre);
                $tarifa->setTitulo([['language' => 'es', 'content' => 'Compartido']]);
                $tarifa->setMoneda($moneda);
                $tarifa->setMonto($monto);
                $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
                $tarifa->setPrestador($proveedor);
                $tarifa->setComprador($proveedor);
                $this->em->persist($tarifa);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        // Los ítems, en pasada aparte: la idempotencia de un producto la decide su plantilla, y
        // un componente que ya existía no recibiría los suyos nunca desde el bucle de arriba.
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

            foreach ($def['items'] as $nombre) {
                ++$orden;
                $termino = $this->termino($nombre, $simula);

                if ($termino === null) {
                    $faltan[] = $nombre . ' (crearía el término)';
                    continue;
                }

                if ($this->em->getRepository(TravelComponenteItem::class)
                    ->findOneBy(['componente' => $componente, 'diccionario' => $termino]) !== null) {
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
                '  %-42s %s',
                $def['componente'],
                $faltan === [] ? 'completo' : ($simula ? 'añadiría: ' : 'añadidos: ') . implode(' · ', $faltan),
            ));
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d segmento(s), %d producto(s), %d ítem(s) y %d tarifa(s).',
            $simula ? 'Se crearían' : 'Creados',
            $nuevos,
            $rutas,
            $puestos,
            $tocadas,
        ));

        $io->note([
            'Los ocho productos del tarifario 2026 quedan cargados.',
            '',
            'Los traslados y el bus desde Cusco van aparte: app:travel:crear-conexion-puno.',
        ]);

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * `slug` o `slug|2` → [slug, día].
     *
     * @return array{0: string, 1: int}
     */
    private function partir(string $ref): array
    {
        $partes = explode('|', $ref);

        return [$partes[0], isset($partes[1]) ? (int) $partes[1] : 1];
    }

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
}
