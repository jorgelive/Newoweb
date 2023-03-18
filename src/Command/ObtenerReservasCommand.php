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

        $ahora = new \DateTime('now');
        $output->writeln([
            sprintf('%s: Iniciando proceso...', $ahora->format('Y-m-d H:i')),
            '============'
        ]);

        $nexos = $this->entityManager->getRepository("App\Entity\ReservaUnitnexo")->findAll();

        foreach($nexos as $nexo){
            if(!$nexo->isDeshabilitado()){
                $ical = new ICal(false, array(
                    'defaultSpan'                 => 2,     // Default value
                    'defaultTimeZone'             => 'UTC-5',
                    'defaultWeekStart'            => 'MO',  // Default value
                ));

                $qb = $this->entityManager
                    ->createQueryBuilder('rr')
                    ->select('rr')
                    ->from('App\Entity\ReservaReserva', 'rr')
                    //->where('rr.nexo = :nexo')                    
                    ->where('rr.chanel = :chanel')
                    ->andWhere('rr.unit = :unit')
                    ->andWhere('DATE(rr.fechahorainicio) >= :fechahorainicio')
                    //->setParameter('nexo', $nexo->getId())
                    ->setParameter('chanel', $nexo->getChanel()->getId())
                    ->setParameter('unit', $nexo->getUnit()->getId())
                    ->setParameter('fechahorainicio', $ahora->format('Y-m-d'));

                $currentReservas = $qb->getQuery()->getResult();
                try {
                    $ical->initUrl($nexo->getEnlace());
                } catch (\Exception $e) {
                    $output->writeln(sprintf('Excepción capturada: %s',  $e->getMessage()));
                    return Command::FAILURE;
                }

                $canal = $nexo->getChanel()->getId(); //2: Airbnb 3:Booking
                $unidad = $nexo->getUnit();
                $establecimiento = $nexo->getUnit()->getEstablecimiento();

                //guarda los uids del bucle actual para despues comparar con las reservas existentes y cancelar las que ya no esten
                $uidsArray = [];

                foreach($ical->events() as $event){
                    $temp = [];
                    $insertar = false;

                    //todo buscar en la coleccion de existentes
                    $existente = $this->entityManager->getRepository("App\Entity\ReservaReserva")->findOneBy(['uid' => $event->uid]);

                    $uidsArray[] = $event->uid;

                    if(!is_null($existente)){
                        if($existente->isManual() === true){
                            continue;
                        }

                        if($existente->getFechahorainicio()->format('Ymd') != $event->dtstart){
                            $currentStartTime = $existente->getFechahorainicio()->format('H:i');
                            $existente->setFechahorainicio(new \DateTime($event->dtstart . ' ' . $currentStartTime));
                        }
                        if($existente->getFechahorafin()->format('Ymd') != $event->dtend){
                            $currentEndTime = $existente->getFechahorafin()->format('H:i');
                            $existente->setFechahorafin(new \DateTime($event->dtend . ' ' . $currentEndTime));
                        }
                        //solo actualizamos horas y salimos
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
                    }elseif($canal == 3){
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', 1);
                        $temp['nombre'] = ucwords(strtolower(str_replace('CLOSED - ', '', $event->summary))) . '- Completar';
                        $temp['enlace'] = '';
                        $insertar = true;
                    }

                    if($insertar === true){
                        $reserva = new ReservaReserva();
                        $reserva->setChanel($nexo->getChanel());
                        $reserva->setNexo($nexo);
                        $reserva->setUnit($unidad);
                        $reserva->setEstado($temp['estado']);
                        $reserva->setManual(false);
                        $reserva->setNombre($temp['nombre']);
                        $reserva->setEnlace($temp['enlace']);
                        $reserva->setUid($event->uid);
                        $reserva->setFechahorainicio($temp['fechahorainicio']);
                        $reserva->setFechahorafin($temp['fechahorafin']);
                        $output->writeln('Agregando: '. $event->uid);
                        $this->entityManager->persist($reserva);
                    }
                }

                foreach($currentReservas as &$currentReserva){
                    if(empty($currentReserva->getNexo()){
                        $currentReserva->setNexo($nexo);
                    }
                    if($currentReserva->isManual()){
                        continue;
                    }
                    if(!in_array($currentReserva->getUid(), $uidsArray)){
                        if($currentReserva->getEstado()->getId() != 3){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', 3));
                            $output->writeln(sprintf('Cancelando la reserva de %s: %s' , $currentReserva->getChanel()->getNombre(), $currentReserva->getNombre()));
                        }
                    }elseif($currentReserva->getEstado()->getId() == 3){
                        //reponemos si la desaparición fue temporal
                        $output->writeln(sprintf('Reactivando la reserva de %s: %s' , $currentReserva->getChanel()->getNombre(), $currentReserva->getNombre()));
                        if($currentReserva->getChanel()->getId() == 2){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', 2));
                        }elseif($currentReserva->getChanel()->getId() == 3){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', 1));
                        }
                    }
                }

            }
        }

        $this->entityManager->flush();

        $output->writeln([
            'Se ha completado el proceso!',
            ''
            ]
        );

        return Command::SUCCESS;

        // return Command::INVALID
    }
}
