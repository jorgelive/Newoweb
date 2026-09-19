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
 * ### La novena llegó después, y por otra puerta (18/09/2026)
 *
 * «Recepción y entrada autónoma» no salió de contar preguntas sino de una respuesta mala: el
 * agente ofreció pagar «en efectivo en recepción», y no hay recepción. Las ocho primeras
 * contestan lo que se pregunta mucho; ésta inaugura la otra mitad del oficio de esta tabla,
 * **decir lo que el sitio ES** — lo que no depende de la casita y por eso no cabe en ninguna
 * guía. Entra por aquí y no por el panel porque es contenido que conviene versionado, igual
 * que las notas de `fin:medios:notas`.
 *
 * Es idempotente por `nombreInterno`: relanzarlo no duplica nada.
 */
#[AsCommand(
    name: 'app:agent:sembrar-conocimiento',
    description: 'Crea los temas y la primera tanda de respuestas del conocimiento genérico.',
    hidden: true,
)]
final class AgentSembrarConocimientoCommand extends Command
{
    /** id => [nombre, pista, orden] */
    private const array TEMAS = [
        'llegada' => ['Llegada y equipaje', 'horarios de entrada y salida, entrada autónoma, quién recibe, dejar maletas, cómo llegar', 10],
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
        $actualizadas = 0;

        foreach ($this->respuestas() as $ficha) {
            $nombre = $ficha['nombre'];
            $existente = $this->em->getRepository(AgentConocimiento::class)
                ->findOneBy(['nombreInterno' => $nombre]);

            if ($existente !== null) {
                $cambios = $this->ponerAlDia($existente, $ficha, $temas, $seco);

                $io->text($cambios === []
                    ? sprintf('· %s ya está igual.', $nombre)
                    : sprintf('~ %s · %s', $nombre, implode(', ', $cambios)));

                $actualizadas += $cambios === [] ? 0 : 1;

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

        $io->success(sprintf(
            '%d %s y %d %s.',
            $creadas,
            $seco ? 'se crearía(n)' : 'creada(s)',
            $actualizadas,
            $seco ? 'se pondría(n) al día' : 'puesta(s) al día'
        ));

        $io->note(
            'Las fichas de ESTE comando son suyas: si las editas en el panel, la próxima pasada '
            . 'te las devuelve al texto de aquí. Las que no están aquí se cargan desde el panel, '
            . 'que al guardar avisa si la guía ya lo contesta.'
        );

        return Command::SUCCESS;
    }

    /**
     * Alinea una ficha que ya existe con lo que dice este archivo, campo a campo.
     *
     * ── Por qué dejó de bastar con «ya existe» ──────────────────────────────────
     * La primera versión saltaba las existentes, y eso convertía el comando en un sembrador de
     * una sola vez: el docblock presumía de «contenido versionado» cuando en realidad, desde la
     * segunda pasada, el archivo y la base podían decir cosas distintas sin que nadie lo notara.
     * Pasó a los dos días: «Recepción y entrada autónoma» nació en la categoría `la-casa` y hubo
     * que moverla a `llegada` —ahí es donde el modelo busca «¿quién me abre si llego tarde?»— y
     * no había forma de hacerlo desde aquí.
     *
     * Ahora es idempotente **por contenido**, como `fin:medios:notas`: se comparan los cinco
     * campos que este archivo gobierna y sólo se tocan los que difieren.
     *
     * ⚠️ La otra cara: estas fichas son de aquí. Editarlas en el panel funciona hasta la próxima
     * pasada, que las devuelve a este texto. Lo que se edite en el panel hay que traerlo al
     * archivo — es el precio de tenerlas en git, y el aviso sale por pantalla al terminar.
     *
     * @param array{tema: string, nombre: string, etiquetas: string, contenido: string,
     *              perfiles: list<string>} $ficha
     * @param array<string, AgentConocimientoCategoria> $temas
     *
     * @return list<string> Qué cambió, para poder decirlo. Vacío si ya estaba igual.
     */
    private function ponerAlDia(
        AgentConocimiento $item,
        array $ficha,
        array $temas,
        bool $seco
    ): array {
        $cambios = [];

        $tema = $temas[$ficha['tema']] ?? null;

        if ($tema !== null && $item->getCategoria()?->getId() !== $tema->getId()) {
            $cambios[] = sprintf('tema %s → %s', $item->getCategoria()?->getId() ?? '—', $tema->getId());

            if (!$seco) {
                $item->setCategoria($tema);
            }
        }

        if ($item->getEtiquetas() !== $ficha['etiquetas']) {
            $cambios[] = 'etiquetas';

            if (!$seco) {
                $item->setEtiquetas($ficha['etiquetas']);
            }
        }

        if ($item->getContenido() !== $ficha['contenido']) {
            $cambios[] = 'contenido';

            if (!$seco) {
                $item->setContenido($ficha['contenido']);
            }
        }

        if ($item->getPerfiles() !== $ficha['perfiles']) {
            $cambios[] = 'perfiles';

            if (!$seco) {
                $item->setPerfiles($ficha['perfiles']);
            }
        }

        if ($item->getDominios() !== ['hotelero']) {
            $cambios[] = 'dominios';

            if (!$seco) {
                $item->setDominios(['hotelero']);
            }
        }

        return $cambios;
    }

    /** @return array<string, AgentConocimientoCategoria> */
    private function sembrarTemas(SymfonyStyle $io, bool $seco): array
    {
        $io->section('Temas');
        $temas = [];

        foreach (self::TEMAS as $id => [$nombre, $pista, $orden]) {
            $existente = $this->em->getRepository(AgentConocimientoCategoria::class)->find($id);

            if ($existente !== null) {
                // ⚠️ La PISTA no es decoración: es la única línea por la que el modelo decide a
                // qué tema entrar en la fase 1, y saltarla aquí dejaba el enrutado congelado en
                // lo que se sembró el primer día. Se pone al día como todo lo demás.
                $desfase = $existente->getNombre() !== $nombre || $existente->getPista() !== $pista;

                $io->text($desfase
                    ? sprintf('~ %-12s %s (%s)', $id, $nombre, $pista)
                    : sprintf('· %s ya está igual.', $id));

                if ($desfase && !$seco) {
                    $existente->setNombre($nombre)->setPista($pista);
                }

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
                // ⚠️ Copiado del PANEL, no al revés. El 13/08 a las 15:35 se editó ahí —quitando
                // la frase de apertura y detallando que el calefactor va por app— y el archivo se
                // quedó con la versión vieja. Como el comando ya reconcilia, la próxima pasada
                // habría revertido esa edición sin que nadie lo pidiera: se trae aquí para que
                // las dos digan lo mismo. Es la regla de esta tabla desde hoy — lo que se toque
                // en el panel hay que devolverlo al archivo.
                'contenido' => 'Tenemos disponibles frazadas adicionales. Se pueden solicitar '
                    . 'CON ANTICIPACIÓN: no se pueden llevar en el momento, así que conviene '
                    . 'avisar por el chat con tiempo y se las dejamos preparadas.'
                    . "\n\n"
                    // 🔗 El calefactor NO se explica aquí, se enlaza. Su precio (20 soles por
                    // periodo), sus horarios y cómo se enciende viven en su ficha de guía y
                    // cambian ahí; copiarlos sería el mismo fallo que tener la misma cifra en dos
                    // sitios. El marcador lo resuelve `PmsEnlacesDeFicha` y el modelo recibe el
                    // título del tema, que además puede pedir con consultar_guia.
                    . 'También hay calefactores en las habitaciones. {{ ficha: calefactor }} '
                    . 'Cuéntale lo que diga esa ficha; no des de memoria ni el precio ni los '
                    . 'horarios. Las mantas son para quien lo prevé; el calefactor, para quien ya '
                    . 'tiene frío: no son la misma respuesta.',
                // Vuelve a verla TODO EL MUNDO. Se acotó desde el panel el 13/08 quitando al
                // huésped, y sus propias etiquetas dicen que ése es su público: «tengo frio»,
                // «hace frio de noche» las escribe quien ya está dentro de la casita, de noche.
                // No hay ficha de frazadas en la guía que le conteste; esto es lo único que hay.
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
                    . 'cuántas piezas de equipaje son.'
                    . "\n\n"
                    // ⚠️ Quién lo recibe hay que decirlo desde que el agente sabe que no hay
                    // personal en el sitio: sin esto, a «¿y quién me guarda las maletas?» le toca
                    // elegir entre inventarse a alguien o negar un servicio que sí existe.
                    . 'QUIÉN LO RECIBE, que es lo que suelen preguntar después: el día de la '
                    . 'salida el personal de limpieza llega a la hora del check-out, o a la que se '
                    . 'haya coordinado, y él recibe el equipaje y lo mueve al almacén si hace '
                    . 'falta. Pero NO hay que esperar a nadie: basta con dejarlo y mandarnos una '
                    . 'foto por WhatsApp, que se pide siempre. Quien sale de madrugada hace eso '
                    . 'mismo y ya está.',
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
                // Copiado del PANEL (editado el 13/08 a las 14:56, después del último commit que
                // tocó esta cadena): «gasolinera» en vez de «grifería» y el cierre reescrito.
                'contenido' => "Justo frente a la casa hay un estacionamiento publico. No es vigilado, pero mucha gente\n"
                    . "deja ahi su vehiculo durante la noche: al costado hay una gasolinera que trabaja las 24\n"
                    . "horas.\n\n"
                    . "A una cuadra hay ademas una cochera privada. Se puede contratar el mismo dia de la\n"
                    . "llegada, y si quiere coordinar la disponibilidad con el propietario, este es su numero:\n"
                    . "+51 984 631 997.\n\n"
                    . "Ofrece las dos con confianza: es de lo que mas preguntan los que llegan en coche.\n"
                    . "El precio y el espacio de la cochera privada los confirman ellos no\n"
                    . "el equipo.",
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

            // ── La novena, y es de otra especie ──────────────────────────────────────────
            // Las ocho de arriba salieron de CONTAR preguntas. Ésta sale de una respuesta mala:
            // el 18/09/2026, en un replay, el agente ofreció pagar «en efectivo en recepción».
            // No hay recepción, y la frase salió de parafrasear nuestra propia nota de cobro
            // («al hacer el check-in»): al modelo, un check-in le suena a mostrador mientras
            // nadie le diga cómo es este sitio.
            //
            // Va SIN acotar y es deliberado: no duplica ninguna ficha de guía (se buscó
            // «recepción» en los 62 ítems y no aparece en ninguno), y el huésped confirmado es
            // justo quien pregunta «llego a las 2 a.m., ¿quién me abre?». Es además la primera
            // de una familia que faltaba entera: LO QUE EL SITIO ES, que no depende de la
            // casita y por eso no cabe en ninguna guía.
            //
            // El hecho está también en el prompt —PmsInstruccionesDominio::COMO_ES_EL_SITIO— y no
            // sobra: allí evita que se lo invente hablando de otra cosa —el fallo fue contestando
            // sobre PAGOS—, y aquí está la respuesta larga, editable en el panel sin desplegar.
            [
                // 📍 En «llegada» y no en «la-casa», aunque hable del edificio: la fase 1 enruta
                // por la PISTA del tema, y «llego de madrugada, ¿quién me abre?» cae en «Llegada
                // y equipaje (horarios de entrada y salida…)», nunca en «Cómo es la casa (agua
                // caliente, calefacción, cocina…)». El tema lo elige la pregunta, no la materia.
                'tema' => 'llegada',
                'nombre' => 'Recepción y entrada autónoma',
                // Las etiquetas no son sólo para el modelo: `candidatosPara()` las mira ANTES de
                // escalar a una persona, y «quién me abre» a medianoche es escalado caro.
                'etiquetas' => 'recepcion, hay recepcion, recepcion 24 horas, hay alguien, quien '
                    . 'me recibe, me espera alguien, me abre alguien, nadie me abre, conserje, '
                    . 'portero, entrada autonoma, self check in, check in autonomo, llego de '
                    . 'madrugada, llego muy tarde, llego de noche, reception, front desk',
                'contenido' => 'No tenemos recepción ni personal en el edificio: son apartamentos '
                    . 'independientes y la entrada es autónoma, a cualquier hora del día o de la '
                    . 'noche. Las llaves están en una caja fuerte digital del pasadizo y abre el '
                    . 'propio huésped; el código y los pasos van en su guía.'
                    . "\n\n"
                    . 'Dilo como una ventaja, que lo es: no hay que coordinar con nadie ni llegar '
                    . 'a una hora concreta para que le abran. Un vuelo de madrugada no es un '
                    . 'problema.'
                    . "\n\n"
                    . 'NO des aquí el código ni las horas: el código lo da consultar_codigos '
                    . '—que además comprueba si a este huésped ya le toca— y los horarios están '
                    . 'en su guía. Si algo no sale como debería, se escribe por el chat.',
                'perfiles' => self::TODOS,
            ],
        ];
    }
}
