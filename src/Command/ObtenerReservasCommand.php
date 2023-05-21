<?php
namespace App\Command;

use App\Entity\ReservaEstado;
use Proxies\__CG__\App\Entity\ReservaChanel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use ICal\ICal;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\ReservaReserva;
use Symfony\Component\HttpClient\HttpClient;



#[AsCommand(name: 'app:obtener-reservas', description: 'Obtiene las reservas de Airbnb.')]
class ObtenerReservasCommand extends Command
{
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
                    ->createQueryBuilder()
                    ->select('rr')
                    ->from('App\Entity\ReservaReserva', 'rr')
                    ->where('rr.unitnexo = :unitnexo')                    
                    ->andWhere('rr.chanel = :chanel')
                    ->andWhere('rr.unit = :unit')
                    ->andWhere('DATE(rr.fechahorainicio) >= :fechahorainicio')
                    ->setParameter('unitnexo', $nexo->getId())
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

                //Guarda los uids del bucle actual para después comparar con las reservas existentes y cancelar las que ya no estén
                $uidsArray = [];

                foreach($ical->events() as $event){
                    $temp = [];
                    $insertar = false;

                    //Puede haber mas de una reserva con el mismo uid por lo que reemplazamos el findOneBy por findBy
                    $existentes = $this->entityManager->getRepository("App\Entity\ReservaReserva")->findBy(['uid' => $event->uid]);

                    $uidsArray[] = $event->uid;

                    if(count($existentes) > 0){
                        foreach ($existentes as $existente) {

                            if ($existente->isManual() === true) {
                                //si es manual no hacemos nada
                                continue;
                            }

                            if ($existente->getFechahorainicio()->format('Ymd') != $event->dtstart) {
                                $currentStartTime = $existente->getFechahorainicio()->format('H:i');
                                $existente->setFechahorainicio(new \DateTime($event->dtstart . ' ' . $currentStartTime));
                            }
                            if ($existente->getFechahorafin()->format('Ymd') != $event->dtend) {
                                $currentEndTime = $existente->getFechahorafin()->format('H:i');
                                $existente->setFechahorafin(new \DateTime($event->dtend . ' ' . $currentEndTime));
                            }
                        }

                        //Solo actualizamos fechas y horas, salimos del bucle ya que la reserva se encuentra presente
                        continue;
                    }

                    $temp['fechahorainicio'] = new \DateTime($event->dtstart . ' ' . $establecimiento->getCheckin());
                    $temp['fechahorafin'] = new \DateTime($event->dtend . ' ' . $establecimiento->getCheckout());

                    if($canal == ReservaChanel::DB_VALOR_AIRBNB && $event->summary != 'Airbnb (Not available)'){

                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO);
                        $temp['nombre'] = 'Completar Airbnb';
                        if($num_found = preg_match_all('~[a-z]+://\S+~', $event->description, $out))
                        {
                            $temp['enlace'] = $out[0][0];
                        }
                        $insertar = true;
                    }elseif($canal == ReservaChanel::DB_VALOR_BOOKING){
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO);  //ya no Pendiente (1)
                        $temp['nombre'] = str_replace('CLOSED - Not available', '', $event->summary) . 'Completar Booking';
                        $temp['enlace'] = '';
                        $insertar = true;
                    }

                    if($insertar === true){
                        $reserva = new ReservaReserva();
                        $reserva->setChanel($nexo->getChanel());
                        $reserva->setUnitnexo($nexo);
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
                    //Fix para colocar el id de nexo posteriormente
                    if(in_array($currentReserva->getUid(), $uidsArray) && empty($currentReserva->getUnitnexo())){
                        $currentReserva->setUnitnexo($nexo);
                    }
                    if($currentReserva->isManual()){
                        continue;
                    }

                    if(!in_array($currentReserva->getUid(), $uidsArray)){
                        //cancelamos la reserva si ya no esta presente
                        if($currentReserva->getEstado()->getId() != 3){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CANCELADO));
                            $output->writeln(sprintf('Cancelando la reserva de %s: %s' , $currentReserva->getChanel()->getNombre(), $currentReserva->getNombre()));
                        }
                    }elseif($currentReserva->getEstado()->getId() == ReservaEstado::DB_VALOR_CANCELADO){
                        //reponemos si la desaparición fue temporal
                        $output->writeln(sprintf('Reactivando la reserva de %s: %s' , $currentReserva->getChanel()->getNombre(), $currentReserva->getNombre()));
                        if($currentReserva->getChanel()->getId() == ReservaChanel::DB_VALOR_AIRBNB){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO));
                        }elseif($currentReserva->getChanel()->getId() == ReservaChanel::DB_VALOR_BOOKING){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO)); //Ya no pendiente (1)
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

    }
}
