<?php

namespace App\Panel\Controller;

use App\Entity\User;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsCronCursor;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventAssignmentActivity;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsReservaHuesped;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


class DashboardController extends AbstractDashboardController
{

    #[Route('/', name: 'panel_dashboard')]
    public function index(): Response
    {
        return $this->render('panel/dashboard.html.twig');
    }

    public function configureCrud(): Crud
    {
        return parent::configureCrud()
            ->overrideTemplate('layout', 'panel/layout.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Panel de Gestión')
            ->setFaviconPath('app/images/favicon.png');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Reservas PMS');

        // Gestión de Reservas (Oficina)
        yield MenuItem::subMenu('Gestión Reservas', 'fa fa-calendar-check')
            ->setSubItems([
                MenuItem::linkToCrud('Reservas', 'fa fa-bed', PmsReserva::class),
                MenuItem::linkToCrud('Namelist (Huéspedes)', 'fa fa-users-viewfinder', PmsReservaHuesped::class),
                MenuItem::linkToCrud('Eventos', 'fa fa-calendar', PmsEventoCalendario::class),
                MenuItem::linkToCrud('Beds24 Links', 'fa fa-link', PmsEventoBeds24Link::class),
                MenuItem::linkToCrud('Tarifas Rango', 'fa fa-tags', PmsTarifaRango::class),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        // Módulo Independiente de Mensajería
        yield MenuItem::subMenu('Mensajería', 'fa fa-comments')
            ->setSubItems([
                // Aquí irán tus CRUDS de mensajes cuando los tengas
            ])
            ->setPermission(Roles::MENSAJES_SHOW);

        // Maestros (Configuración)
        yield MenuItem::subMenu('Maestros', 'fa fa-database')
            ->setSubItems([
                MenuItem::linkToCrud('Actividades (Tareas)', 'fa fa-clipboard-list', PmsEventAssignmentActivity::class),
                MenuItem::linkToCrud('Unidades', 'fa fa-door-open', PmsUnidad::class),
                MenuItem::linkToCrud('Establecimientos', 'fa fa-building', PmsEstablecimiento::class),
                MenuItem::linkToCrud('Canales', 'fa fa-plug', PmsChannel::class),
                MenuItem::linkToCrud('Estados de evento', 'fa fa-tag', PmsEventoEstado::class),
                MenuItem::linkToCrud('Estados de pago', 'fa fa-credit-card', PmsEventoEstadoPago::class),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        // Infraestructura Técnica
        yield MenuItem::section('Infraestructura');

        yield MenuItem::subMenu('Jobs / Queues', 'fa fa-server')
            ->setSubItems([
                MenuItem::linkToCrud('Cola Pull Reservas', 'fa fa-tasks', PmsBookingsPullQueue::class),
                MenuItem::linkToCrud('Cola Push Reservas', 'fa fa-list', PmsBookingsPushQueue::class),
                MenuItem::linkToCrud('Cola Push Tarifas', 'fa fa-layer-group', PmsRatesPushQueue::class),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::subMenu('Config Beds24', 'fa fa-cogs')
            ->setSubItems([
                MenuItem::linkToCrud('Maps Beds24', 'fa fa-link', PmsUnidadBeds24Map::class),
                MenuItem::linkToCrud('Endpoints', 'fa fa-network-wired', PmsBeds24Endpoint::class),
                MenuItem::linkToCrud('Configs Beds24', 'fa fa-key', Beds24Config::class),
                MenuItem::linkToCrud('Estado de Crons', 'fa fa-robot', PmsCronCursor::class),
                MenuItem::linkToCrud('Webhook Audit', 'fa fa-bug', PmsBeds24WebhookAudit::class),
            ])
            ->setPermission(Roles::ADMIN);

        // --- SECCIÓN SISTEMA ---
        yield MenuItem::section('Sistema');

        yield MenuItem::linkToCrud('Usuarios', 'fa fa-users-gear', User::class)
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkToLogout('Salir', 'fa fa-sign-out');
    }
}