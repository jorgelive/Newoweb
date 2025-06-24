<?php

namespace App\Block;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Sonata\BlockBundle\Block\BlockContextInterface;
use Sonata\BlockBundle\Block\Service\AbstractBlockService;
use Sonata\BlockBundle\Form\Mapper;
use Sonata\BlockBundle\Model\BlockInterface;
use Sonata\Form\Validator\ErrorElement;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;


class TareasBlockService extends AbstractBlockService
{

    private EntityManagerInterface $entityManager;
    private TokenStorageInterface $tokenStorage;
    public function __construct(Environment $twig, EntityManagerInterface $entityManager, TokenStorageInterface $tokenStorage)
    {
        $this->entityManager = $entityManager;
        $this->tokenStorage = $tokenStorage;

        parent::__construct($twig);
    }
    public function configureSettings(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'url' => false,
            'title' => 'Tareas',
            'template' => 'block/tareas.html.twig',
            'class' => 'Tareas',
            'icon' => false,
            'translation_domain' => 'messages'
        ]);
    }

    public function execute(BlockContextInterface $blockContext, Response $response = null): Response
    {
        // merge settings
        $settings = $blockContext->getSettings();

        if(empty($this->tokenStorage->getToken())){
            return new Response('');
        }

        $hoy = new \DateTime('now');
        $manana = new \DateTime('tomorrow');
        $pasado = new \DateTime('tomorrow + 1day');
        $traspasado = new \DateTime('tomorrow + 2day');

        $componenteAlertas = $this->entityManager->getRepository("App\Entity\ViewCotizacionCotcomponenteAlerta")->findAll();
        if(count($componenteAlertas) > 0){
            foreach($componenteAlertas as $id => $componenteAlerta){
                $alertas[$id]['id'] = $componenteAlerta->getId();
                $alertas[$id]['idEstado'] = $componenteAlerta->getEstadocotcomponente()->getNombre();
                $alertas[$id]['nombreEstado'] = $componenteAlerta->getEstadocotcomponente()->getNombre();
                $alertas[$id]['fechaHoraInicio'] = $componenteAlerta->getFechahorainicio();
                $alertas[$id]['nombreFile'] = $componenteAlerta->getCotservicio()->getCotizacion()->getFile()->getNombre() . ' x' . $componenteAlerta->getCotservicio()->getCotizacion()->getNumeropasajeros();
                $alertas[$id]['idServicio'] = $componenteAlerta->getCotservicio()->getId();
                $alertas[$id]['idCotizacion'] = $componenteAlerta->getCotservicio()->getCotizacion()->getId();
                $alertas[$id]['codigoCotizacion'] = $componenteAlerta->getCotservicio()->getCotizacion()->getCodigo();
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
                        $qb->expr()->neq('rr.estado', '3') //cancelado
                    ),
                    $qb->expr()->andX(
                        $qb->expr()->gte('DATE(rr.fechahorafin)', ':hoy'),
                        $qb->expr()->lt('DATE(rr.fechahorafin)', ':pasado'),
                        $qb->expr()->neq('rr.estado', '3') //cancelado
                    )
                )

            )
            ->setParameter('hoy', $hoy->format('Y-m-d'))
            ->setParameter('pasado', $pasado->format('Y-m-d'));

        $reservas = $qb->getQuery()->getResult();

        //Para Ordenar
        $reservasOrdenadas['hoy']['nombre']='Hoy';
        $reservasOrdenadas['hoy']['clases']['ingresos'] = [];
        $reservasOrdenadas['hoy']['clases']['salidas'] = [];

        $reservasOrdenadas['manana']['nombre']='Mañana';
        $reservasOrdenadas['manana']['clases']['ingresos'] = [];
        $reservasOrdenadas['manana']['clases']['salidas'] = [];

        $existeReservas = false;
        foreach ($reservas as $reserva){
            if($reserva->getFechahorainicio()->format('Y-m-d') == $hoy->format('Y-m-d')){
                $reservasOrdenadas['hoy']['clases']['ingresos']['nombre'] = 'Ingresan';
                $reservasOrdenadas['hoy']['clases']['ingresos']['icono'] = 'ingreso';
                $reservasOrdenadas['hoy']['clases']['ingresos']['reservas'][] = $reserva;
                $existeReservas = true;
            }
            if($reserva->getFechahorainicio()->format('Y-m-d') == $manana->format('Y-m-d')){
                $reservasOrdenadas['manana']['clases']['ingresos']['nombre'] = 'Ingresan';
                $reservasOrdenadas['manana']['clases']['ingresos']['icono'] = 'ingreso';
                $reservasOrdenadas['manana']['clases']['ingresos']['reservas'][] = $reserva;
                $existeReservas = true;
            }
            if($reserva->getFechahorafin()->format('Y-m-d') == $hoy->format('Y-m-d')){
                $reservasOrdenadas['hoy']['clases']['salidas']['nombre'] = 'Salen';
                $reservasOrdenadas['hoy']['clases']['salidas']['icono'] = 'salida';
                $reservasOrdenadas['hoy']['clases']['salidas']['reservas'][] = $reserva;
                $existeReservas = true;
            }
            if($reserva->getFechahorafin()->format('Y-m-d') == $manana->format('Y-m-d')){
                $reservasOrdenadas['manana']['clases']['salidas']['nombre'] = 'Salen';
                $reservasOrdenadas['manana']['clases']['salidas']['icono'] = 'salida';
                $reservasOrdenadas['manana']['clases']['salidas']['reservas'][] = $reserva;
                $existeReservas = true;
            }
        }
        if(!$existeReservas){$reservasOrdenadas = [];}
        unset($reservas);
        unset($reserva);
        unset($qb);


        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('fd')
            ->from('App\Entity\CotizacionFiledocumento', 'fd')
            ->innerJoin('fd.file', 'f')
            ->innerJoin('f.cotizaciones', 'c')
            ->innerJoin('c.estadocotizacion', 'e')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('e.id', 3), //confirmado
                    //$qb->expr()->gte('DATE(fd.vencimiento)', ':hoy'), //que salgan todos
                    $qb->expr()->lt('DATE(fd.vencimiento)', ':traspasado')
                )
            )
            ->orderBy('fd.vencimiento', 'ASC')
            //->setParameter('hoy', $hoy->format('Y-m-d'))
            ->setParameter('traspasado', $traspasado->format('Y-m-d'));

        $vencimientos = $qb->getQuery()->getResult();

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
                    $qb->expr()->lt('DATE(cs.fechahorainicio)', ':pasado')
                )
            )
            ->orderBy('cs.fechahorainicio', 'ASC')

            ->setParameter('hoy', $hoy->format('Y-m-d'))
            ->setParameter('pasado', $pasado->format('Y-m-d'));

        $servicios = $qb->getQuery()->getResult();

        $existeServicios = false;
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

        return $this->renderResponse($blockContext->getTemplate(), [
            'fechaHoraActual' => new \DateTime('now'),
            'alertas' => $alertas,
            'vencimientos' => $vencimientos,
            'reservasordenadas' => $reservasOrdenadas,
            'serviciosordenados' => $serviciosOrdenados,
            'block'     => $blockContext->getBlock(),
            'settings'  => $settings
        ], $response);
    }

}