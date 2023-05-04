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
        $ahora = new \DateTime('now');
        $output->writeln([
            sprintf('%s: Iniciando envio de mensajes...', $ahora->format('Y-m-d H:i')),
            '============'
        ]);

        $componenteAlertas = $this->entityManager->getRepository("App\Entity\ViewCotizacionCotcomponenteAlerta")->findAll();
        if(count($componenteAlertas) > 0){

            foreach($componenteAlertas as $id => $componenteAlerta){
                $resumen[$id]['idEstado'] = $componenteAlerta->getEstadocotcomponente()->getNombre();
                $resumen[$id]['nombreEstado'] = $componenteAlerta->getEstadocotcomponente()->getNombre();
                $resumen[$id]['fechaHoraInicio'] = $componenteAlerta->getFechahorainicio();
                $resumen[$id]['nombreFile'] = $componenteAlerta->getCotservicio()->getCotizacion()->getFile()->getNombre() . ' x' . $componenteAlerta->getCotservicio()->getCotizacion()->getNumeropasajeros();
                $resumen[$id]['idServicio'] = $componenteAlerta->getCotservicio()->getId();
                $resumen[$id]['nombreServicio'] = $componenteAlerta->getCotservicio()->getServicio()->getNombre();
                $resumen[$id]['nombreComponente'] = $componenteAlerta->getComponente()->getNombre();
                $resumen[$id]['fechaAlerta'] = $componenteAlerta->getFechaalerta();
            }
        }else{
            $resumen = [];
        }

        $ahora = new \DateTime('now');

        $receivers = explode(',', $this->params->get('mailer_alert_receivers'));

        $email = (new TemplatedEmail())
            ->from(new Address($this->params->get('mailer_sender_email'), $this->params->get('mailer_sender_name')))

            ->subject(sprintf('OpenPeru - Resumen del %s', $ahora->format('Y-m-d')))
            ->htmlTemplate('emails/command_enviar_resumen.html.twig')
            ->context([
                'fechaHoraActual' => new \DateTime('now'),
                'resumen' => $resumen
            ]);

        foreach ($receivers as $key => $receiver){
            if ($key === array_key_first($receivers)) {
                $email->to(new Address($receiver));
            }else{
                $email->addTo(new Address($receiver));
            }
        }

        try {
            $this->mailer->send($email);
        }catch (TransportExceptionInterface $e) {
            $output->writeln([
                    'Se ha completado el proceso!',
                    ''
                ]
            );
            return Command::FAILURE;
        }

        $output->writeln([
            'Se ha completado el proceso!',
            ''
            ]
        );

        return Command::SUCCESS;

    }
}
