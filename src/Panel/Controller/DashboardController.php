<?php

declare(strict_types=1);

namespace App\Panel\Controller;

use App\Entity\Maestro\MaestroDocumentoTipo;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroTipocambio;
use App\Entity\User;
use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\GupshupConfig;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageAttachment;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageTemplate;
use App\Message\Entity\WhatsappGupshupSendQueue;
use App\Pax\Entity\UiI18n;
use App\Pms\Entity\PmsBeds24WebhookAudit;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsCronCursor;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEstablecimientoVirtual;
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
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

// Maestros

// PMS Entities

// 🔥 NUEVAS ENTIDADES DE MENSAJERÍA

// EasyAdmin Imports

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

        // =========================================================================
        // SECCIÓN 1: OPERATIVA DIARIA (FRONT DESK & REVENUE)
        // =========================================================================
        yield MenuItem::section('Operativa Diaria');

        yield MenuItem::subMenu('Front Desk', 'fa fa-concierge-bell')
            ->setSubItems([
                MenuItem::linkToCrud('Reservas', 'fa fa-bed', PmsReserva::class),
                MenuItem::linkToCrud('Namelist (Huéspedes)', 'fa fa-users-viewfinder', PmsReservaHuesped::class),
                MenuItem::linkToCrud('Eventos Calendario', 'fa fa-calendar-day', PmsEventoCalendario::class),
                MenuItem::linkToCrud('Tareas / Actividades', 'fa fa-clipboard-list', PmsEventAssignmentActivity::class),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        yield MenuItem::subMenu('Bandeja de Mensajes', 'fa fa-comments')
            ->setSubItems([
                MenuItem::linkToCrud('Conversaciones', 'fa fa-comment-dots', MessageConversation::class),
                MenuItem::linkToCrud('Historial General', 'fa fa-history', Message::class),
                MenuItem::linkToCrud('Archivos Adjuntos', 'fa fa-paperclip', MessageAttachment::class),
            ])
            ->setPermission(Roles::MENSAJES_SHOW);

        yield MenuItem::subMenu('Revenue & Precios', 'fa fa-chart-line')
            ->setSubItems([
                MenuItem::linkToCrud('Tarifas Rango', 'fa fa-tags', PmsTarifaRango::class),
            ])
            ->setPermission(Roles::RESERVAS_WRITE);

        // =========================================================================
        // SECCIÓN 2: EXPERIENCIA DEL HUÉSPED
        // =========================================================================
        yield MenuItem::section('Experiencia del Huésped');

        yield MenuItem::subMenu('Guía Digital', 'fa fa-map-signs')
            ->setSubItems([
                MenuItem::linkToCrud('Guías por Unidad', 'fa fa-book', PmsGuia::class),
                MenuItem::linkToCrud('Secciones (Bloques)', 'fa fa-puzzle-piece', PmsGuiaSeccion::class),
                MenuItem::linkToCrud('Ítems de Contenido', 'fa fa-info-circle', PmsGuiaItem::class),
                MenuItem::linkToCrud('Galería de Imágenes', 'fa fa-images', PmsGuiaItemGaleria::class),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        // =========================================================================
        // SECCIÓN 3: CONFIGURACIÓN DEL NEGOCIO (MAESTROS)
        // =========================================================================
        yield MenuItem::section('Configuración');

        yield MenuItem::subMenu('Maestros PMS', 'fa fa-hotel')
            ->setSubItems([
                MenuItem::linkToCrud('Establecimientos', 'fa fa-building', PmsEstablecimiento::class),
                MenuItem::linkToCrud('Unidades', 'fa fa-door-open', PmsUnidad::class),
                MenuItem::linkToCrud('Establecimientos Virtuales', 'fa fa-building-flag', PmsEstablecimientoVirtual::class),
                MenuItem::linkToCrud('Canales de Venta', 'fa fa-shopping-cart', PmsChannel::class),
                MenuItem::linkToCrud('Estados de Evento', 'fa fa-tag', PmsEventoEstado::class),
                MenuItem::linkToCrud('Estados de Pago', 'fa fa-credit-card', PmsEventoEstadoPago::class),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        yield MenuItem::subMenu('Maestros Comunicación', 'fa fa-bullhorn')
            ->setSubItems([
                MenuItem::linkToCrud('Canales de Envío', 'fa fa-tower-broadcast', MessageChannel::class),
                MenuItem::linkToCrud('Plantillas (Templates)', 'fa fa-file-invoice', MessageTemplate::class),
                MenuItem::linkToCrud('Reglas de Automatización', 'fa fa-robot', MessageRule::class),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        yield MenuItem::subMenu('Maestros Globales', 'fa fa-globe-americas')
            ->setSubItems([
                MenuItem::linkToCrud('Idiomas', 'fa fa-language', MaestroIdioma::class),
                MenuItem::linkToCrud('Países', 'fa fa-flag', MaestroPais::class),
                MenuItem::linkToCrud('Tipos Documento', 'fa fa-id-card', MaestroDocumentoTipo::class),
                MenuItem::linkToCrud('Monedas', 'fa fa-coins', MaestroMoneda::class),
                MenuItem::linkToCrud('Tipos de Cambio', 'fa fa-money-bill-transfer', MaestroTipocambio::class),
                MenuItem::linkToCrud('Traducciones UI', 'fa fa-spell-check', UiI18n::class)
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        // =========================================================================
        // SECCIÓN 4: INTEGRACIONES EXTERNAS (EXCHANGE)
        // =========================================================================
        yield MenuItem::section('Integraciones API');

        yield MenuItem::subMenu('Credenciales y Endpoints', 'fa fa-network-wired')
            ->setSubItems([
                MenuItem::linkToCrud('Endpoints (Hub)', 'fa fa-link', ExchangeEndpoint::class),
                MenuItem::linkToCrud('Credenciales Beds24', 'fa fa-key', Beds24Config::class),
                MenuItem::linkToCrud('Credenciales Gupshup', 'fa fa-whatsapp', GupshupConfig::class),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::subMenu('Mapeos Técnicos', 'fa fa-sitemap')
            ->setSubItems([
                MenuItem::linkToCrud('Mapas Beds24 (Unidades)', 'fa fa-map-location-dot', PmsUnidadBeds24Map::class),
                MenuItem::linkToCrud('Vínculos de Eventos', 'fa fa-link-slash', PmsEventoBeds24Link::class),
            ])
            ->setPermission(Roles::ADMIN);

        // =========================================================================
        // SECCIÓN 5: SISTEMA Y MONITOREO (ADMINISTRADORES)
        // =========================================================================
        yield MenuItem::section('Sistema');

        yield MenuItem::subMenu('Colas de Trabajo (Jobs)', 'fa fa-server')
            ->setSubItems([
                MenuItem::linkToCrud('Pull Reservas', 'fa fa-download', PmsBookingsPullQueue::class),
                MenuItem::linkToCrud('Push Reservas', 'fa fa-upload', PmsBookingsPushQueue::class),
                MenuItem::linkToCrud('Push Tarifas', 'fa fa-tags', PmsRatesPushQueue::class),
                MenuItem::linkToCrud('Salida WhatsApp', 'fa fa-paper-plane', WhatsappGupshupSendQueue::class),
                MenuItem::linkToCrud('Salida Beds24', 'fa fa-cloud-upload-alt', Beds24SendQueue::class),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::subMenu('Auditoría Técnica', 'fa fa-bug')
            ->setSubItems([
                MenuItem::linkToCrud('Webhook Audit (Beds24)', 'fa fa-stethoscope', PmsBeds24WebhookAudit::class),
                MenuItem::linkToCrud('Estado de Crons', 'fa fa-clock', PmsCronCursor::class),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkToCrud('Usuarios del Sistema', 'fa fa-users-gear', User::class)
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkToLogout('Cerrar Sesión', 'fa fa-sign-out-alt');
    }
}