<?php
namespace App\Command;

use App\Entity\ReservaEstado;
use App\Entity\ReservaChannel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use ICal\ICal;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\ReservaReserva;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;


#[AsCommand(name: 'app:obtener-reservas', description: 'Obtiene las reservas de Airbnb.')]
class ObtenerReservasCommand extends Command
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager, TransportInterface $mailer, ParameterBagInterface $params)
    {
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->params = $params;

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

        //Obtenemos todos los nexos
        $nexos = $this->entityManager->getRepository("App\Entity\ReservaUnitnexo")->findAll();

        $duplicadosBooking = [];

        foreach($nexos as $nexo){
            if(!$nexo->isDeshabilitado()){
                $ical = new ICal(false, array(
                    'defaultSpan'                 => 2,     // Default value
                    'defaultTimeZone'             => 'UTC-5',
                    'defaultWeekStart'            => 'MO',  // Default value
                ));

                //Obtenemos las reservas actuales
                $qb = $this->entityManager
                    ->createQueryBuilder()
                    ->select('rr')
                    ->from('App\Entity\ReservaReserva', 'rr')
                    ->where('rr.unitnexo = :unitnexo')                    
                    ->andWhere('rr.channel = :channel')
                    ->andWhere('rr.unit = :unit')
                    ->andWhere('DATE(rr.fechahorainicio) >= :fechahorainicio')
                    ->setParameter('unitnexo', $nexo->getId())
                    ->setParameter('channel', $nexo->getChannel()->getId())
                    ->setParameter('unit', $nexo->getUnit()->getId())
                    ->setParameter('fechahorainicio', $ahora->format('Y-m-d'));

                $currentReservas = $qb->getQuery()->getResult();

                //obtenemos el resultado iCal
                try {
                    $ical->initUrl($nexo->getEnlace());
                } catch (\Exception $e) {
                    $output->writeln(sprintf('Excepción capturada: %s',  $e->getMessage()));
                    //return Command::FAILURE;
                    continue;
                }

                $canal = $nexo->getChannel()->getId(); //2: Airbnb 3:Booking
                $unidad = $nexo->getUnit();
                $establecimiento = $nexo->getUnit()->getEstablecimiento();

                //Guarda los uids del bucle actual para después comparar con las reservas existentes y cancelar las que ya no estén
                $uidsArray = [];

                foreach($ical->events() as $event) {

                    $temp = [];
                    $insertar = false;

                    //Puede haber más de una reserva con el mismo uid (gracias booking!) por lo que reemplazamos el findOneBy por findBy
                    $existentes = $this->entityManager->getRepository("App\Entity\ReservaReserva")->findBy(['uid' => $event->uid]);

                    if(in_array($event->uid, $uidsArray) && $nexo->getChannel()->getId() == ReservaChannel::DB_VALOR_BOOKING){

                        if(count($existentes) > 0) {
                            //si ya existe lo pongo manual la no envío la alerta, como es una array hago bucle, solo debería haber un valor
                            foreach ($existentes as $existente):

                                if ($existente->isManual() === false) {
                                    $duplicadostemp['uid'] = $event->uid;
                                    $duplicadostemp['inicio'] = new \DateTime($event->dtstart . ' 00:00:00');
                                    $duplicadostemp['unidad'] = $nexo->getUnit();
                                    $duplicadosBooking[] = $duplicadostemp;
                                }
                            endforeach;
                        }else{
                            //si no existe sería raro pero por si las moscas
                            $duplicadostemp['uid'] = $event->uid;
                            $duplicadostemp['inicio'] = new \DateTime($event->dtstart . ' 00:00:00');
                            $duplicadostemp['unidad'] = $nexo->getUnit();
                            $duplicadosBooking[] = $duplicadostemp;
                        }

                    }else{
                        $uidsArray[] = $event->uid;
                    }

                    if(count($existentes) > 0){
                        foreach ($existentes as $existente):

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
                        endforeach;

                        //Solo actualizamos fechas y horas, salimos del bucle porque la reserva se encuentra presente
                        continue;
                    }

                    $temp['fechahorainicio'] = new \DateTime($event->dtstart . ' ' . $establecimiento->getCheckin());
                    $temp['fechahorafin'] = new \DateTime($event->dtend . ' ' . $establecimiento->getCheckout());

                    if($event->summary == 'Airbnb (Not available)'){
                        $insertar = false;
                    }elseif($canal == ReservaChannel::DB_VALOR_AIRBNB){
                        $insertar = true;
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_PAGO_TOTAL);
                        $temp['nombre'] = 'Completar Airbnb';
                        if($num_found = preg_match_all('~[a-z]+://\S+~', $event->description, $out))
                        {
                            $temp['enlace'] = $out[0][0];
                        }
                    }elseif($canal == ReservaChannel::DB_VALOR_BOOKING){
                        $insertar = true;
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO);  //ya no Pendiente (1)
                        $temp['nombre'] = str_replace('CLOSED - Not available', '', $event->summary) . 'Completar Booking';
                        $temp['enlace'] = '';
                    }elseif($canal == ReservaChannel::DB_VALOR_VRBO){
                        $insertar = true;
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_PAGO_TOTAL);
                        $temp['nombre'] = str_replace('Reserved -', '', $event->summary) . 'Completar VRBO';
                        $temp['enlace'] = '';
                    }else{
                        $insertar = true;
                        $temp['estado'] = $this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO);
                        $temp['nombre'] = $event->summary;
                        $temp['enlace'] = '';
                    }

                    if($insertar){
                        $reserva = new ReservaReserva();
                        $reserva->setChannel($nexo->getChannel());
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
                            $output->writeln(sprintf('Cancelando la reserva de %s: %s' , $currentReserva->getChannel()->getNombre(), $currentReserva->getNombre()));
                        }
                    }elseif($currentReserva->getEstado()->getId() == ReservaEstado::DB_VALOR_CANCELADO){
                        //reponemos si la desaparición fue temporal
                        $output->writeln(sprintf('Reactivando la reserva de %s: %s' , $currentReserva->getChannel()->getNombre(), $currentReserva->getNombre()));

                        if($currentReserva->getChannel()->getId() == ReservaChannel::DB_VALOR_BOOKING){
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO)); //Ya no pendiente (1)
                            $currentReserva->setNombre('Reactivado - ' . $currentReserva->getNombre());
                            $currentReserva->setEnlace(null);
                            $currentReserva->setTelefono(null);
                            $currentReserva->setNota(null);
                            $currentReserva->setCantidadadultos(1);
                            $currentReserva->setCantidadninos(0);
                            $currentReserva->setCreado($ahora);
                            $currentReserva->setModificado($ahora);
                        }else{
                            $currentReserva->setEstado($this->entityManager->getReference('App\Entity\ReservaEstado', ReservaEstado::DB_VALOR_CONFIRMADO));
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

        if(!empty($duplicadosBooking)
            //solamente desde 15:20 a 15:30
            && (int)$ahora->format('H') == 15
            && (int)$ahora->format('i') > 30
            && (int)$ahora->format('i') < 40
        ){
            $email = (new TemplatedEmail())
                ->from(new Address($this->params->get('mailer_sender_email'), $this->params->get('mailer_sender_name')))
                ->subject('Alerta: Reserva de booking con el mismo UID')
                ->htmlTemplate('emails/command_obtener_reservas_booking_duplicados.html.twig')
                ->context([
                    'fechaHoraActual' => $ahora,
                    'duplicados' => $duplicadosBooking
                ]);

            $receivers = explode(',', $this->params->get('mailer_alert_receivers'));

            foreach ($receivers as $key => $receiver){
                if ($key === array_key_first($receivers)) {
                    $email->to(new Address($receiver));
                }else{
                    $email->addTo(new Address($receiver));
                }
            }

            try {
                $this->mailer->send($email);
                //$dummy = 1;
            }catch (TransportExceptionInterface $e) {
                $output->writeln([
                        'No se ha podido enviar el email!',
                        ''
                    ]
                );
                return Command::FAILURE;
            }
        }
        return Command::SUCCESS;
    }
}
