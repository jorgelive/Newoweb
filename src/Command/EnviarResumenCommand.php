<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Entity\ReservaReserva;
use Symfony\Component\HttpClient\HttpClient;



#[AsCommand(name: 'app:enviar-resumen')]
class EnviarResumenCommand extends Command
{
    protected static $defaultName = 'app:enviar-resumen';

    protected static $defaultDescription = 'Envia resumen a correo electrónico.';

    private EntityManagerInterface $entityManager;


    public function __construct(EntityManagerInterface $entityManager, TransportInterface $mailer, ParameterBagInterface $params)
    {
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->params = $params;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Este comando envia un resúmen por correo electrónico.');
        //$this->addArgument('username', InputArgument::REQUIRED, 'The username of the user.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hoy = new \DateTime('now');
        $manana = new \DateTime('tomorrow');
        $pasado = new \DateTime('tomorrow + 1day');

        $output->writeln([
            sprintf('%s: Iniciando envio de mensajes...', $hoy->format('Y-m-d H:i')),
            '============'
        ]);

        $componenteAlertas = $this->entityManager->getRepository("App\Entity\ViewCotizacionCotcomponenteAlerta")->findAll();
        if(count($componenteAlertas) > 0){

            foreach($componenteAlertas as $id => $componenteAlerta){
                $alertas[$id]['idEstado'] = $componenteAlerta->getEstadocotcomponente()->getNombre();
                $alertas[$id]['nombreEstado'] = $componenteAlerta->getEstadocotcomponente()->getNombre();
                $alertas[$id]['fechaHoraInicio'] = $componenteAlerta->getFechahorainicio();
                $alertas[$id]['nombreFile'] = $componenteAlerta->getCotservicio()->getCotizacion()->getFile()->getNombre() . ' x' . $componenteAlerta->getCotservicio()->getCotizacion()->getNumeropasajeros();
                $alertas[$id]['idServicio'] = $componenteAlerta->getCotservicio()->getId();
                $alertas[$id]['nombreServicio'] = $componenteAlerta->getCotservicio()->getServicio()->getNombre();
                $alertas[$id]['nombreComponente'] = $componenteAlerta->getComponente()->getNombre();
                $alertas[$id]['fechaAlerta'] = $componenteAlerta->getFechaalerta();
            }
        }else{
            $alertas = [];
        }

        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('rr')
            ->from('App\Entity\ReservaReserva', 'rr')
            ->innerJoin('rr.estado', 'e')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->andX(
                        $qb->expr()->gte('DATE(rr.fechahorainicio)', ':hoy'),
                        $qb->expr()->lt('DATE(rr.fechahorainicio)', ':pasado'),
                        $qb->expr()->eq('rr.estado', '2') //confimado
                    ),
                    $qb->expr()->andX(
                        $qb->expr()->gte('DATE(rr.fechahorafin)', ':hoy'),
                        $qb->expr()->lt('DATE(rr.fechahorafin)', ':pasado'),
                        $qb->expr()->eq('rr.estado', '2') //confimado
                    )
                )

            )
            ->setParameter('hoy', $hoy->format('Y-m-d'))
            ->setParameter('pasado', $pasado->format('Y-m-d'));

        $reservas = $qb->getQuery()->getResult();

        //Para Ordenar
        $reservasOrdenadas = ['ingresosHoy' => [], 'salidasHoy' => [], 'ingresosManana' => [], 'salidasManana' => [] ];
        $existeReservas = false;
        foreach ($reservas as $reserva){
            if($reserva->getFechahorainicio()->format('Y-m-d') == $hoy->format('Y-m-d')){
                $reservasOrdenadas['ingresosHoy']['nombre'] = 'Ingresando hoy';
                $reservasOrdenadas['ingresosHoy']['reservas'][] = $reserva;
                $existeReservas = true;
            }
            if($reserva->getFechahorainicio()->format('Y-m-d') == $manana->format('Y-m-d')){
                $reservasOrdenadas['ingresosManana']['nombre'] = 'Ingresando mañana';
                $reservasOrdenadas['ingresosManana']['reservas'][] = $reserva;
                $existeReservas = true;
            }
            if($reserva->getFechahorafin()->format('Y-m-d') == $hoy->format('Y-m-d')){
                $reservasOrdenadas['salidasHoy']['nombre'] = 'Saliendo hoy';
                $reservasOrdenadas['salidasHoy']['reservas'][] = $reserva;
                $existeReservas = true;
            }
            if($reserva->getFechahorafin()->format('Y-m-d') == $manana->format('Y-m-d')){
                $reservasOrdenadas['salidasManana']['nombre'] = 'Saliendo mañana';
                $reservasOrdenadas['salidasManana']['reservas'][] = $reserva;
                $existeReservas = true;
            }
        }
        if(!$existeReservas){$reservasOrdenadas = [];}
        unset($reservas);
        unset($reserva);
        unset($qb);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('cs')
            ->from('App\Entity\CotizacionCotservicio', 'cs')
            ->innerJoin('cs.cotizacion', 'c')
            ->innerJoin('c.estadocotizacion', 'e')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('e.id', 3), //confirmado
                    $qb->expr()->gte('DATE(cs.fechahorainicio)', ':hoy'),
                    $qb->expr()->lt('DATE(cs.fechahorainicio)', ':pasado'),
                )
            )
            ->orderBy('cs.fechahorainicio', 'ASC')

            ->setParameter('hoy', $hoy->format('Y-m-d'))
            ->setParameter('pasado', $pasado->format('Y-m-d'));

        $servicios = $qb->getQuery()->getResult();

        $existeServicios = true;
        $serviciosOrdenados = ['serviciosHoy' => [], 'serviciosManana' => []];
        foreach ($servicios as $servicio) {
            if($servicio->getFechahorainicio()->format('Y-m-d') == $hoy->format('Y-m-d')){
                $serviciosOrdenados['serviciosHoy']['nombre'] = 'Servicios para hoy';
                $serviciosOrdenados['serviciosHoy']['servicios'][] = $servicio;
                $existeServicios = true;
            }
            if($servicio->getFechahorainicio()->format('Y-m-d') == $manana->format('Y-m-d')){
                $serviciosOrdenados['serviciosManana']['nombre'] = 'Servicios para mañana';
                $serviciosOrdenados['serviciosManana']['servicios'][] = $servicio;
                $existeServicios = true;
            }

        }
        if(!$existeServicios){$serviciosOrdenados = [];}
        unset($servicios);
        unset($servicio);
        unset($qb);

        $email = (new TemplatedEmail())
            ->from(new Address($this->params->get('mailer_sender_email'), $this->params->get('mailer_sender_name')))

            ->subject(sprintf('OpenPeru - Resumen del %s', $hoy->format('Y-m-d')))
            ->htmlTemplate('emails/command_enviar_resumen.html.twig')
            ->context([
                'fechaHoraActual' => new \DateTime('now'),
                'alertas' => $alertas,
                'reservasordenadas' => $reservasOrdenadas,
                'serviciosordenados' => $serviciosOrdenados
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
                    'Se ha completado el proceso con error!',
                    ''
                ]
            );
            return Command::FAILURE;
        }

        $output->writeln([
            'Se ha completado el proceso exitosamente!',
            ''
            ]
        );

        return Command::SUCCESS;

    }
}
