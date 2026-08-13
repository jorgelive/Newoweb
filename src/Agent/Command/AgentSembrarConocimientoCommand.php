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
 * ### ⚠️ Todas duplican la guía A PROPÓSITO, y por eso van declaradas
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
 * ### Lo que NO se siembra
 *
 * Tipo de cambio y frazadas adicionales, que son de los más preguntados, **no están aquí porque
 * no sé el dato**: la regla del cambio y si las mantas extra tienen coste sólo las sabe el
 * negocio. Se cargan a mano desde el panel.
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
        'reservar' => ['Reservar y disponibilidad', 'capacidad, mínimo de noches, cómo reservar', 50],
    ];

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
                // 🔓 LA DECLARACIÓN. Ver el docblock de la clase: sin esto, el huésped recibiría
                // esta versión genérica en lugar de la de su casita.
                ->setPerfiles([
                    PerfilConversacion::Prospecto->value,
                    PerfilConversacion::Interesado->value,
                ]);

            if (isset($temas[$ficha['tema']])) {
                $item->setCategoria($temas[$ficha['tema']]);
            }

            $duplica = $this->validador->temasQueYaLoCubren($item);

            $io->text(sprintf(
                '+ %-24s [%s] %s',
                $nombre,
                $ficha['tema'],
                $duplica === []
                    ? 'sin equivalente en la guía'
                    : 'versión pública de «' . implode('», «', $duplica) . '»'
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
            'Faltan dos de las más preguntadas y necesitan un dato del negocio: el TIPO DE CAMBIO '
            . '(la regla, no la cifra) y las FRAZADAS ADICIONALES (si hay y si cuestan). '
            . 'Cárgalas desde el panel.'
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
     * @return list<array{tema: string, nombre: string, etiquetas: string, contenido: string}>
     */
    private function respuestas(): array
    {
        return [
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
            ],
            [
                'tema' => 'la-casa',
                'nombre' => 'Agua caliente (público)',
                'etiquetas' => 'hay agua caliente, siempre hay agua caliente, agua caliente todo '
                    . 'el dia, 24 horas, presion del agua, ducha caliente, hot water, hot shower',
                'contenido' => 'Sí. Cada casita tiene su propio calentador a gas, así que hay agua '
                    . 'caliente las 24 horas y no depende de horarios ni de un sistema compartido '
                    . 'con otros huéspedes.',
            ],
            [
                'tema' => 'servicios',
                'nombre' => 'Estacionamiento (público)',
                'etiquetas' => 'estacionamiento, cochera, parqueo, parking, donde dejo el auto, '
                    . 'donde dejo el carro, hay parqueo, vehiculo, garaje',
                'contenido' => 'Hay dos opciones, y ninguna es nuestra: justo al frente hay una '
                    . 'playa de estacionamiento pública y gratuita, y a una cuadra una cochera '
                    . 'privada de pago. La gratuita no es un área vigilada, conviene decirlo. No '
                    . 'podemos reservar sitio en ninguna de las dos.',
            ],
            [
                'tema' => 'servicios',
                'nombre' => 'Lavandería (público)',
                'etiquetas' => 'lavanderia, lavar ropa, donde lavo, laundry, lavado, lavar',
                'contenido' => 'Hay lavanderías económicas a media cuadra, frente a la gasolinera. '
                    . 'Además nuestro personal de limpieza ofrece servicio de lavandería a 1 dólar '
                    . 'el kilo: se coordina por el chat.',
            ],
        ];
    }
}
