<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Entity\AgentConocimiento;
use App\Agent\Entity\AgentConocimientoCategoria;
use App\Agent\Service\ValidadorDeConocimiento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La primera siembra del conocimiento genérico, sacada de lo que la gente pregunta de verdad.
 *
 * ### De dónde salen estas fichas
 *
 * De contar los 2.124 mensajes entrantes reales. Por frecuencia: equipaje (58), pagos (47),
 * horarios de entrada (36), cómo llegar (31), calefacción (28), agua caliente (22),
 * estacionamiento (12), lavandería (6).
 *
 * ### ⚠️ Cinco duplican la guía A PROPÓSITO, y por eso van declaradas
 *
 * Estos temas ya están en la guía y allí se contestan **mejor**: por casita, con las horas y los
 * códigos de esa estancia resueltos. Pero la guía **exige una reserva**, y quien pregunta «¿hay
 * estacionamiento?» o «¿siempre hay agua caliente?» normalmente **todavía no ha reservado** —
 * hoy se lleva una repregunta de qué casita le interesa en vez de una respuesta que existe desde
 * hace meses.
 *
 * Por eso cada ficha va acotada a `prospecto` e `interesado`: **eso es la declaración de versión
 * pública**. El huésped sigue recibiendo la de su casita; el que aún decide, recibe ésta.
 *
 * Sin esa acotación serían un error: el huésped se llevaría la genérica en lugar de la suya.
 *
 * ### Dos van SIN acotar, y es la otra mitad de la regla
 *
 * `Tipo de cambio` y `Frazadas adicionales` no duplican ningún tema de la guía, así que van para
 * todos los perfiles. Acotarlas a prospecto las dejaría mudas justo para quien más las necesita:
 * a las mantas las pide el huésped ya alojado, de noche y con frío.
 *
 * ⚠️ El tipo de cambio guarda **el criterio, no la cifra**: «venta de SUNAT del día» no caduca
 * nunca; un «3.75» empieza a mentir mañana y nadie se entera.
 *
 * Es idempotente por `nombreInterno`: relanzarlo no duplica nada.
 */
#[AsCommand(
    name: 'app:agent:sembrar-conocimiento',
    description: 'Crea los temas y la primera tanda de respuestas del conocimiento genérico.'
)]
final class AgentSembrarConocimientoCommand extends Command
{
    /** id => [nombre, pista, orden] */
    private const array TEMAS = [
        'llegada' => ['Llegada y equipaje', 'horarios de entrada y salida, dejar maletas, cómo llegar', 10],
        'pagos' => ['Pagos y comprobantes', 'formas de pago, moneda, tipo de cambio, boletas', 20],
        'la-casa' => ['Cómo es la casa', 'agua caliente, calefacción, cocina, wifi, espacios', 30],
        'servicios' => ['Servicios y alrededores', 'lavandería, estacionamiento, limpieza extra', 40],
        'reservar' => ['Reservar y disponibilidad', 'capacidad, mínimo de noches, mascotas, cómo reservar', 50],
    ];

    /**
     * La declaración de «versión pública»: **todos menos el huésped**.
     *
     * ⚠️ El que sobra es el huésped, y sólo él: para él existe la ficha de la guía, que es mejor
     * —por casita, con sus horas y sus códigos resueltos—. Todos los demás la necesitan.
     *
     * La primera versión ponía sólo `prospecto` e `interesado`, y dejaba al EQUIPO viendo menos
     * que un desconocido: probando desde el WhatsApp interno, «¿hay cochera?» se contestó con un
     * «No tenemos cochera propia» —el agente no tenía la ficha y tiró de memoria— en vez de
     * ofrecer las dos opciones que existen. Quien prueba el bot es justamente el equipo.
     *
     * @var list<string>
     */
    private const array PUBLICO = ['prospecto', 'interesado', 'personal', 'colaborador'];

    /** Sin acotar: la contesta a cualquiera, porque no pisa nada de la guía. @var list<string> */
    private const array TODOS = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidadorDeConocimiento $validador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué crearía.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $temas = $this->sembrarTemas($io, $seco);

        if ($seco) {
            $io->section('Respuestas');
        }

        $creadas = 0;

        foreach ($this->respuestas() as $ficha) {
            $nombre = $ficha['nombre'];

            if ($this->em->getRepository(AgentConocimiento::class)->findOneBy(['nombreInterno' => $nombre]) !== null) {
                $io->text(sprintf('· %s ya existe.', $nombre));

                continue;
            }

            $item = (new AgentConocimiento())
                ->setNombreInterno($nombre)
                ->setEtiquetas($ficha['etiquetas'])
                ->setContenido($ficha['contenido'])
                ->setDominios(['hotelero'])
                // 🔓 LA DECLARACIÓN, cuando la ficha duplica un tema de la guía: acotarla a
                // prospecto e interesado es lo que evita que el huésped reciba la versión
                // genérica en lugar de la de su casita. Las que NO duplican nada van sin acotar,
                // porque ahí el huésped también las necesita —y de hecho suele ser él quien
                // pregunta—.
                ->setPerfiles($ficha['perfiles']);

            if (isset($temas[$ficha['tema']])) {
                $item->setCategoria($temas[$ficha['tema']]);
            }

            $duplica = $this->validador->temasQueYaLoCubren($item);

            $io->text(sprintf(
                '+ %-24s [%s] %s',
                $nombre,
                $ficha['tema'],
                match (true) {
                    $duplica === [] => 'sin equivalente en la guía · para todos',
                    $ficha['perfiles'] === self::TODOS => 'roza «' . implode('», «', array_map(
                        static fn ($t): string => $t->etiqueta,
                        $duplica
                    )) . '» · para todos, a propósito',
                    default => 'versión pública de «' . implode('», «', array_map(
                        static fn ($t): string => $t->etiqueta,
                        $duplica
                    )) . '»',
                }
            ));

            ++$creadas;

            if (!$seco) {
                $this->em->persist($item);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf('%d respuesta(s) %s.', $creadas, $seco ? 'se crearían' : 'creadas'));

        $io->note(
            'De aquí en adelante, las fichas nuevas se cargan desde el panel: al guardar te avisa '
            . 'si la guía ya lo contesta. Esto es sólo la primera tanda.'
        );

        return Command::SUCCESS;
    }

    /** @return array<string, AgentConocimientoCategoria> */
    private function sembrarTemas(SymfonyStyle $io, bool $seco): array
    {
        $io->section('Temas');
        $temas = [];

        foreach (self::TEMAS as $id => [$nombre, $pista, $orden]) {
            $existente = $this->em->getRepository(AgentConocimientoCategoria::class)->find($id);

            if ($existente !== null) {
                $io->text(sprintf('· %s ya existe.', $id));
                $temas[$id] = $existente;

                continue;
            }

            $io->text(sprintf('+ %-12s %s', $id, $nombre));

            $categoria = (new AgentConocimientoCategoria())
                ->setId($id)
                ->setNombre($nombre)
                ->setPista($pista)
                ->setOrden($orden);

            $temas[$id] = $categoria;

            if (!$seco) {
                $this->em->persist($categoria);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        return $temas;
    }

    /**
     * Las fichas. Todo el contenido sale de lo que ya dice la guía — no se inventa nada nuevo.
     *
     * @return list<array{tema: string, nombre: string, etiquetas: string, contenido: string,
     *                     perfiles: list<string>}>
     */
    private function respuestas(): array
    {
        return [
            // ── Las dos que NO duplican la guía: van sin acotar ──────────────────────────
            // El tipo de cambio y las frazadas no están en ningún ítem de guía, y quien pregunta
            // suele ser el huésped ya alojado —de noche y con frío, en el caso de las mantas—.
            // Acotarlas a prospecto las dejaría mudas justo para quien las necesita.
            [
                'tema' => 'reservar',
                'nombre' => 'Mascotas',
                'etiquetas' => 'mascotas, mascota, perro, perros, gato, gatos, puedo llevar mi '
                    . 'perro, viajo con mi mascota, pet friendly, pets, dog, animales',
                'contenido' => 'Lo sentimos, no aceptamos mascotas.'
                    . "\n\n"
                    . '⚠️ LA DISCULPA VA SIEMPRE, también en otros idiomas: «We are sorry, we do '
                    . 'not accept pets». Sin ella la frase es un portazo, y ya pasó — en inglés '
                    . 'salió un «We do not accept pets» a secas.'
                    . "\n\n"
                    . 'Sin dejar lugar a dudas, eso sí: no prometas consultarlo ni des a entender '
                    . 'que depende del caso, porque no hay excepción que ofrecer. Y no expliques el '
                    . 'motivo: no le interesa a quien pregunta y suena a excusa. Si todavía no ha '
                    . 'reservado, es mejor que lo sepa ahora que al llegar con el animal a la '
                    . 'puerta.',
                // Va acotada como las demás duplicadas: el huésped ya lo tiene en «Reglas», y
                // quien de verdad necesita esta respuesta es el que aún está decidiendo si
                // reserva — y ése no llega a la guía.
                'perfiles' => self::PUBLICO,
            ],
            [
                'tema' => 'pagos',
                'nombre' => 'Tipo de cambio',
                'etiquetas' => 'tipo de cambio, en dolares, dolares o soles, en que moneda pago, '
                    . 'cuanto es en soles, cuanto en dolares, exchange rate, pay in dollars, moneda',
                'contenido' => 'Se cobra en soles. Si prefiere pagar en dólares también se acepta, '
                    . 'y la conversión se hace con el tipo de cambio VENTA de SUNAT del día.'
                    . "\n\n"
                    . 'NO des una cifra: cambia cada día, y la que corresponda se la confirma el '
                    . 'equipo al momento de cobrar. Decir el criterio ya responde la pregunta.',
                'perfiles' => self::TODOS,
            ],
            [
                'tema' => 'la-casa',
                'nombre' => 'Frazadas adicionales',
                'etiquetas' => 'frazadas, frazada, mantas, manta, cobijas, mas abrigo, tengo frio, '
                    . 'hace frio de noche, blankets, extra blanket, abrigo',
                'contenido' => 'Sí, hay frazadas adicionales y no cuestan nada. Lo único es que '
                    . 'hay que pedirlas CON ANTICIPACIÓN: no se pueden llevar en el momento, así '
                    . 'que conviene avisar por el chat con tiempo y se las dejamos preparadas.'
                    . "\n\n"
                    . 'Si lo que quiere es calor AHORA, eso es el calefactor: se enciende en '
                    . 'remoto y está caliente en minutos. Las mantas son para quien lo prevé; el '
                    . 'calefactor, para quien ya tiene frío. No son la misma respuesta.',
                'perfiles' => self::TODOS,
            ],

            [
                'tema' => 'llegada',
                'nombre' => 'Guardar equipaje (público)',
                'etiquetas' => 'dejar maletas, guardar equipaje, almacen, deposito de maletas, '
                    . 'antes del check in, despues del check out, luggage, store bags, dejar las cosas',
                'contenido' => 'Sí, tenemos almacén para el equipaje. Se pueden dejar las maletas '
                    . 'si llega antes de la hora de entrada, o después del check-out si sigue en '
                    . 'la ciudad. También se pueden guardar varios días —por ejemplo mientras hace '
                    . 'un trek—: en ese caso conviene avisar con un día de antelación y decir '
                    . 'cuántos bultos son.',
                'perfiles' => self::PUBLICO,
            ],
            [
                'tema' => 'llegada',
                'nombre' => 'Cómo llegar desde el aeropuerto (público)',
                'etiquetas' => 'como llego, desde el aeropuerto, taxi, uber, recojo, transporte, '
                    . 'traslado, airport, how to get there, movilidad',
                'contenido' => 'Uber funciona en Cusco y hay taxis dentro del aeropuerto; basta '
                    . 'con dar la dirección. El coche llega hasta la puerta: la calle es plana y '
                    . 'ancha, sin escaleras ni un último tramo a pie, lo cual va bien con equipaje '
                    . 'pesado o con personas mayores. No ofrecemos traslado propio.',
                'perfiles' => self::PUBLICO,
            ],
            [
                'tema' => 'la-casa',
                'nombre' => 'Agua caliente (público)',
                'etiquetas' => 'hay agua caliente, siempre hay agua caliente, agua caliente todo '
                    . 'el dia, 24 horas, presion del agua, ducha caliente, hot water, hot shower',
                'contenido' => 'Sí. Cada casita tiene su propio calentador a gas, así que hay agua '
                    . 'caliente las 24 horas y no depende de horarios ni de un sistema compartido '
                    . 'con otros huéspedes.',
                'perfiles' => self::PUBLICO,
            ],
            [
                'tema' => 'servicios',
                'nombre' => 'Estacionamiento (público)',
                'etiquetas' => 'estacionamiento, cochera, parqueo, parking, donde dejo el auto, '
                    . 'donde dejo el carro, hay parqueo, vehiculo, garaje',
                'contenido' => 'Justo frente a la casa hay un estacionamiento público. No es '
                    . 'vigilado, pero mucha gente deja ahí su vehículo durante la noche: al '
                    . 'costado hay una grifería que trabaja las 24 horas.'
                    . "\n\n"
                    . 'A una cuadra hay además una cochera privada. Se puede contratar el mismo '
                    . 'día de la llegada, y si quiere coordinar la disponibilidad con el '
                    . 'propietario, este es su número: +51 984 631 997.'
                    . "\n\n"
                    . 'Ofrece las dos con confianza: es de lo que más preguntan los que llegan en '
                    . 'coche. Ninguna de las dos es nuestra, así que el precio y el sitio los '
                    . 'confirma la cochera, no el equipo.',
                'perfiles' => self::PUBLICO,
            ],
            [
                'tema' => 'servicios',
                'nombre' => 'Lavandería (público)',
                'etiquetas' => 'lavanderia, lavar ropa, donde lavo, laundry, lavado, lavar',
                'contenido' => 'Hay lavanderías económicas a media cuadra, frente a la gasolinera. '
                    . 'Además nuestro personal de limpieza ofrece servicio de lavandería a 1 dólar '
                    . 'el kilo: se coordina por el chat.',
                'perfiles' => self::PUBLICO,
            ],
        ];
    }
}
