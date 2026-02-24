<?php

declare(strict_types=1);

namespace App\Panel\Controller;

use App\Entity\User;

// Maestros
use App\Entity\Maestro\MaestroDocumentoTipo;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroTipocambio;

// PMS Entities
use App\Pax\Entity\UiI18n;
use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\Beds24Endpoint;
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
use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsReservaHuesped;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Entity\PmsEstablecimientoVirtual;

// 🔥 NUEVAS ENTIDADES DE MENSAJERÍA
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageTemplate;
use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageAttachment;
use App\Message\Entity\GupshupConfig;
use App\Message\Entity\GupshupEndpoint;
use App\Message\Entity\WhatsappGupshupSendQueue;
use App\Message\Entity\Beds24SendQueue;

use App\Security\Roles;

// EasyAdmin Imports
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
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

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('https://unpkg.com/tippy.js@6/dist/tippy.css')
            ->addCssFile('https://unpkg.com/tippy.js@6/animations/scale.css')
            ->addJsFile('https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/timegrid@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/list@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/premium-common@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/scrollgrid@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/timeline@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/resource@6.1.10/index.global.min.js')
            ->addJsFile('https://cdn.jsdelivr.net/npm/@fullcalendar/resource-timeline@6.1.10/index.global.min.js')
            ;
    }

    public function configureCrud(): Crud
    {
        return parent::configureCrud()
            ->overrideTemplate('layout', 'panel/layout.html.twig')
            ->addFormTheme('panel/form/translation_entry.html.twig')
            ->addFormTheme('panel/field/gallery_helper.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Panel de Gestión')
            ->setFaviconPath('app/images/favicon.png')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        // --- SECCIÓN 1: OPERATIVA DIARIA ---
        yield MenuItem::section('Reservas y Operaciones');

        yield MenuItem::subMenu('Gestión PMS', 'fa fa-calendar-check')
            ->setSubItems([
                MenuItem::linkToCrud('Reservas', 'fa fa-bed', PmsReserva::class),
                MenuItem::linkToCrud('Namelist (Huéspedes)', 'fa fa-users-viewfinder', PmsReservaHuesped::class),
                MenuItem::linkToCrud('Eventos Calendario', 'fa fa-calendar', PmsEventoCalendario::class),
                MenuItem::linkToCrud('Tarifas Rango', 'fa fa-tags', PmsTarifaRango::class),
                MenuItem::linkToCrud('Beds24 Links', 'fa fa-link', PmsEventoBeds24Link::class),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        // 🔥 NUEVA SUBSECCIÓN: MENSAJERÍA OMNICANAL
        yield MenuItem::subMenu('Mensajería', 'fa fa-comments')
            ->setSubItems([
                MenuItem::linkToCrud('Conversaciones Chat', 'fa fa-comment-dots', MessageConversation::class),
                MenuItem::linkToCrud('Historial de Mensajes', 'fa fa-history', Message::class),
                MenuItem::linkToCrud('Archivos Adjuntos', 'fa fa-paperclip', MessageAttachment::class),
                MenuItem::linkToCrud('Reglas de Envío', 'fa fa-robot', MessageRule::class),
            ])
            ->setPermission(Roles::MENSAJES_SHOW);

        // --- SECCIÓN 2: CONTENIDOS PARA EL HUÉSPED ---
        yield MenuItem::section('Experiencia del Huésped');

        yield MenuItem::subMenu('Guía Digital', 'fa fa-map-signs')
            ->setSubItems([
                MenuItem::linkToCrud('Guías por Unidad', 'fa fa-book', PmsGuia::class),
                MenuItem::linkToCrud('Secciones (Bloques)', 'fa fa-puzzle-piece', PmsGuiaSeccion::class),
                MenuItem::linkToCrud('Ítems de Contenido', 'fa fa-info-circle', PmsGuiaItem::class),
                MenuItem::linkToCrud('Imagenes de Ítems', 'fa fa-image', PmsGuiaItemGaleria::class),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        // --- SECCIÓN 3: CONFIGURACIÓN Y MAESTROS ---
        yield MenuItem::section('Configuración');

        yield MenuItem::subMenu('Maestros Globales', 'fa fa-globe-americas')
            ->setSubItems([
                MenuItem::linkToCrud('Idiomas', 'fa fa-language', MaestroIdioma::class),
                MenuItem::linkToCrud('Países', 'fa fa-flag', MaestroPais::class),
                MenuItem::linkToCrud('Tipos Documento', 'fa fa-id-card', MaestroDocumentoTipo::class),
                MenuItem::linkToCrud('Monedas', 'fa fa-coins', MaestroMoneda::class),
                MenuItem::linkToCrud('Tipos de Cambio', 'fa fa-chart-line', MaestroTipocambio::class),
                MenuItem::linkToCrud('Traducciones UI', 'fa fa-language', UiI18n::class)
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        yield MenuItem::subMenu('Maestros PMS', 'fa fa-hotel')
            ->setSubItems([
                MenuItem::linkToCrud('Establecimientos', 'fa fa-building', PmsEstablecimiento::class),
                MenuItem::linkToCrud('Unidades', 'fa fa-door-open', PmsUnidad::class),
                MenuItem::linkToCrud('Establecimientos Virtuales', 'fa fa-tags', PmsEstablecimientoVirtual::class),
                MenuItem::linkToCrud('Canales', 'fa fa-exchange-alt', PmsChannel::class),
                MenuItem::linkToCrud('Actividades (Tareas)', 'fa fa-clipboard-list', PmsEventAssignmentActivity::class),
                MenuItem::linkToCrud('Estados de Evento', 'fa fa-tag', PmsEventoEstado::class),
                MenuItem::linkToCrud('Estados de Pago', 'fa fa-credit-card', PmsEventoEstadoPago::class),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        // 🔥 NUEVA SUBSECCIÓN: MAESTROS DE MENSAJERÍA
        yield MenuItem::subMenu('Maestros Mensajería', 'fa fa-comment-medical')
            ->setSubItems([
                MenuItem::linkToCrud('Canales de Envío', 'fa fa-tower-broadcast', MessageChannel::class),
                MenuItem::linkToCrud('Plantillas de Mensaje', 'fa fa-file-invoice', MessageTemplate::class),
                MenuItem::linkToCrud('Configuraciones Gupshup', 'fa fa-key', GupshupConfig::class),
                MenuItem::linkToCrud('Endpoints Gupshup', 'fa fa-link', GupshupEndpoint::class),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        // --- SECCIÓN 4: INFRAESTRUCTURA Y SISTEMA ---
        yield MenuItem::section('Sistema');

        // 🔥 ACTUALIZADO: SUBMENÚ DE QUEUES INTEGRADO
        yield MenuItem::subMenu('Jobs / Queues', 'fa fa-server')
            ->setSubItems([
                MenuItem::linkToCrud('Cola Pull Reservas', 'fa fa-tasks', PmsBookingsPullQueue::class),
                MenuItem::linkToCrud('Cola Push Reservas', 'fa fa-list', PmsBookingsPushQueue::class),
                MenuItem::linkToCrud('Cola Push Tarifas', 'fa fa-layer-group', PmsRatesPushQueue::class),
                MenuItem::linkToCrud('Cola Salida WhatsApp', 'fa fa-whatsapp', WhatsappGupshupSendQueue::class),
                MenuItem::linkToCrud('Cola Salida Beds24', 'fa fa-cloud-upload-alt', Beds24SendQueue::class),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::subMenu('Config Beds24', 'fa fa-cogs')
            ->setSubItems([
                MenuItem::linkToCrud('Maps Beds24', 'fa fa-link', PmsUnidadBeds24Map::class),
                MenuItem::linkToCrud('Endpoints', 'fa fa-network-wired', Beds24Endpoint::class),
                MenuItem::linkToCrud('Configs Beds24', 'fa fa-key', Beds24Config::class),
                MenuItem::linkToCrud('Estado de Crons', 'fa fa-robot', PmsCronCursor::class),
                MenuItem::linkToCrud('Webhook Audit', 'fa fa-bug', PmsBeds24WebhookAudit::class),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkToCrud('Usuarios', 'fa fa-users-gear', User::class)
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkToLogout('Salir', 'fa fa-sign-out');
    }
}