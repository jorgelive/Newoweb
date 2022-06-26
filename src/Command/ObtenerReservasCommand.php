<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use ICal\ICal;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\ReservaReserva;

use Symfony\Component\HttpClient\HttpClient;
use BenjaminFavre\OAuthHttpClient\OAuthHttpClient;
use BenjaminFavre\OAuthHttpClient\GrantType\ClientCredentialsGrantType;



#[AsCommand(name: 'app:obtener-reservas')]
class ObtenerReservasCommand extends Command
{
    protected static $defaultName = 'app:obtener-reservas';

    protected static $defaultDescription = 'Obtiene las reservas de Airbnb.';

    private $entityManager;


    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Este comando guarda la información obtenida en la base de datos.');
        //$this->addArgument('username', InputArgument::REQUIRED, 'The username of the user.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln([
            'Procesando...',
            '============',
            '',
        ]);

        $nexos = $this->entityManager->getRepository("App:ReservaUnitnexo")->findAll();

        foreach ($nexos as $nexo){
            if(!$nexo->isDeshabilitado()){
                $ical = new ICal(false, array(
                    'defaultSpan'                 => 2,     // Default value
                    'defaultTimeZone'             => 'UTC-5',
                    'defaultWeekStart'            => 'MO',  // Default value
                ));

                $ical->initUrl($nexo->getEnlace()); //cs3

                $canal = $nexo->getChanel()->getId(); //2: Airbnb 3:Booking
                $unidad = $nexo->getUnit();
                $establecimiento = $nexo->getUnit()->getEstablecimiento();
                foreach($ical->events() as $event){
                    $temp = [];
                    $insertar = false;
                    $existente = $this->entityManager->getRepository("App:ReservaReserva")->findOneBy(['uid' => $event->uid]);

                    if(!is_null($existente)){
                        if($existente->getFechahorainicio()->format('Ymd') != $event->dtstart){
                            $currentStartTime = $existente->getFechahorainicio()->format('H:i');
                            $existente->setFechahorainicio(new \DateTime($event->dtstart . ' ' . $currentStartTime));
                        }
                        if($existente->getFechahorafin()->format('Ymd') != $event->dtend){
                            $currentEndTime = $existente->getFechahorafin()->format('H:i');
                            $existente->setFechahorafin(new \DateTime($event->dtend . ' ' . $currentEndTime));
                        }
                        continue;
                    }

                    $temp['fechahorainicio'] = new \DateTime($event->dtstart . ' ' . $establecimiento->getCheckin());
                    $temp['fechahorafin'] = new \DateTime($event->dtend . ' ' . $establecimiento->getCheckout());

                    if($canal == 2 && $event->summary != 'Airbnb (Not available)'){

                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', 2);
                        $temp['nombre'] = 'Completar';
                        if($num_found = preg_match_all('~[a-z]+://\S+~', $event->description, $out))
                        {
                            $temp['enlace'] = $out[0][0];
                        }
                        $insertar = true;
                    }elseif ($canal == 3){
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', 1);
                        $temp['nombre'] = ucfirst(strtolower(str_replace('CLOSED - ', '', $event->summary)));
                        $temp['enlace'] = '';
                        $insertar = true;
                    }

                    if ($insertar === true){
                        $reserva = new ReservaReserva();
                        $reserva->setChanel($nexo->getChanel());
                        $reserva->setUnit($unidad);
                        $reserva->setEstado($temp['estado']);
                        $reserva->setNombre($temp['nombre']);
                        $reserva->setEnlace($temp['enlace']);
                        $reserva->setUid($event->uid);
                        $reserva->setFechahorainicio($temp['fechahorainicio']);
                        $reserva->setFechahorafin($temp['fechahorafin']);
                        $output->writeln('Agregando: '. $event->uid);
                        $this->entityManager->persist($reserva);
                    }
                }

            }
        }

        $this->entityManager->flush();

        //$output->writeln('Username: '.$input->getArgument('username'));

        $output->writeln('Se ha completado el proceso!');

        return Command::SUCCESS;

        // return Command::FAILURE;

        // return Command::INVALID
    }
}