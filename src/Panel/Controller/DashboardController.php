<?php

declare(strict_types=1);

namespace App\Panel\Controller;

use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

// 🔥 IMPORTACIONES DE CONTROLADORES CRUD (Reemplazan a las Entidades)
use App\Agent\Controller\Crud\AutoResponderRuleCrudController;
use App\Exchange\Controller\Crud\Beds24ConfigCrudController;
use App\Exchange\Controller\Crud\CronCursorCrudController;
use App\Exchange\Controller\Crud\ExchangeEndpointCrudController;
use App\Exchange\Controller\Crud\MetaConfigCrudController;
use App\Message\Controller\Crud\Beds24ReceiveQueueCrudController;
use App\Message\Controller\Crud\Beds24SendQueueCrudController;
use App\Message\Controller\Crud\MessageAttachmentCrudController;
use App\Message\Controller\Crud\MessageChannelCrudController;
use App\Message\Controller\Crud\MessageConversationCrudController;
use App\Message\Controller\Crud\MessageCrudController;
use App\Message\Controller\Crud\MessageRuleCrudController;
use App\Message\Controller\Crud\MessageTemplateCrudController;
use App\Message\Controller\Crud\MetaWebhookAuditCrudController;
use App\Message\Controller\Crud\WhatsappMetaSendQueueCrudController;
use App\Panel\Controller\Crud\MaestroDocumentoTipoCrudController;
use App\Panel\Controller\Crud\MaestroIdiomaCrudController;
use App\Panel\Controller\Crud\MaestroMonedaCrudController;
use App\Panel\Controller\Crud\MaestroPaisCrudController;
use App\Panel\Controller\Crud\MaestroTipocambioCrudController;
use App\Panel\Controller\Crud\MessengerMessageCrudController;
use App\Panel\Controller\Crud\PushSubscriptionCrudController;
use App\Panel\Controller\Crud\UserCrudController;
use App\Pax\Controller\Crud\UiI18nCrudController;
use App\Pms\Controller\Crud\PmsBeds24WebhookAuditCrudController;
use App\Pms\Controller\Crud\PmsBookingsPullQueueCrudController;
use App\Pms\Controller\Crud\PmsBookingsPushQueueCrudController;
use App\Pms\Controller\Crud\PmsChannelCrudController;
use App\Pms\Controller\Crud\PmsEstablecimientoCrudController;
use App\Pms\Controller\Crud\PmsEstablecimientoVirtualCrudController;
use App\Pms\Controller\Crud\PmsEventAssignmentActivityCrudController;
use App\Pms\Controller\Crud\PmsEventoBeds24LinkCrudController;
use App\Pms\Controller\Crud\PmsEventoCalendarioCrudController;
use App\Pms\Controller\Crud\PmsEventoEstadoCrudController;
use App\Pms\Controller\Crud\PmsEventoEstadoPagoCrudController;
use App\Pms\Controller\Crud\PmsGuiaCrudController;
use App\Pms\Controller\Crud\PmsGuiaItemCrudController;
use App\Pms\Controller\Crud\PmsGuiaItemGaleriaCrudController;
use App\Pms\Controller\Crud\PmsGuiaSeccionCrudController;
use App\Pms\Controller\Crud\PmsRatesPushQueueCrudController;
use App\Pms\Controller\Crud\PmsReservaCrudController;
use App\Pms\Controller\Crud\PmsReservaHuespedCrudController;
use App\Pms\Controller\Crud\PmsTarifaRangoCrudController;
use App\Pms\Controller\Crud\PmsUnidadBeds24MapCrudController;
use App\Pms\Controller\Crud\PmsUnidadCrudController;

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
            ->addFormTheme('panel/form/whatsapp_meta_button_entry.html.twig')
            ->addFormTheme('panel/form/whatsapp_meta_body_entry.html.twig')
            ->addFormTheme('panel/form/whatsapp_meta_header_entry.html.twig')
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
                MenuItem::linkTo(PmsReservaCrudController::class, 'Reservas', 'fa fa-bed'),
                MenuItem::linkTo(PmsReservaHuespedCrudController::class, 'Namelist (Huéspedes)', 'fa fa-users-viewfinder'),
                MenuItem::linkTo(PmsEventoCalendarioCrudController::class, 'Eventos Calendario', 'fa fa-calendar-day'),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        yield MenuItem::subMenu('Precios', 'fa fa-chart-line')
            ->setSubItems([
                MenuItem::linkTo(PmsTarifaRangoCrudController::class, 'Tarifas', 'fa fa-tags'),
            ])
            ->setPermission(Roles::RESERVAS_WRITE);

        // =========================================================================
        // SECCIÓN 2: EXPERIENCIA DEL HUÉSPED
        // =========================================================================
        yield MenuItem::section('Experiencia del Huésped');

        yield MenuItem::subMenu('Guía Digital', 'fa fa-map-signs')
            ->setSubItems([
                MenuItem::linkTo(PmsGuiaCrudController::class, 'Guías por Unidad', 'fa fa-book'),
                MenuItem::linkTo(PmsGuiaSeccionCrudController::class, 'Secciones (Bloques)', 'fa fa-puzzle-piece'),
                MenuItem::linkTo(PmsGuiaItemCrudController::class, 'Ítems de Contenido', 'fa fa-info-circle'),
                MenuItem::linkTo(PmsGuiaItemGaleriaCrudController::class, 'Galería de Imágenes', 'fa fa-images'),
            ])
            ->setPermission(Roles::RESERVAS_SHOW);

        // =========================================================================
        // SECCIÓN 3: CONFIGURACIÓN DEL NEGOCIO (MAESTROS)
        // =========================================================================
        yield MenuItem::section('Configuración');

        yield MenuItem::subMenu('Maestros PMS', 'fa fa-hotel')
            ->setSubItems([
                MenuItem::linkTo(PmsEstablecimientoCrudController::class, 'Establecimientos', 'fa fa-building'),
                MenuItem::linkTo(PmsUnidadCrudController::class, 'Unidades', 'fa fa-door-open'),
                MenuItem::linkTo(PmsEstablecimientoVirtualCrudController::class, 'Establecimientos Virtuales', 'fa fa-building-flag'),
                MenuItem::linkTo(PmsChannelCrudController::class, 'Canales de Venta', 'fa fa-shopping-cart'),
                MenuItem::linkTo(PmsEventAssignmentActivityCrudController::class, 'Tareas / Actividades', 'fa fa-clipboard-list'),
                MenuItem::linkTo(PmsEventoEstadoCrudController::class, 'Estados de Evento', 'fa fa-tag'),
                MenuItem::linkTo(PmsEventoEstadoPagoCrudController::class, 'Estados de Pago', 'fa fa-credit-card'),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        yield MenuItem::subMenu('Bot & IA (AutoResponder)', 'fa fa-brain')
            ->setSubItems([
                MenuItem::linkTo(AutoResponderRuleCrudController::class, 'Reglas Deterministas', 'fa fa-bolt'),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        yield MenuItem::subMenu('Maestros Comunicación', 'fa fa-bullhorn')
            ->setSubItems([
                MenuItem::linkTo(MessageChannelCrudController::class, 'Canales de Envío', 'fa fa-tower-broadcast'),
                MenuItem::linkTo(MessageTemplateCrudController::class, 'Plantillas (Templates)', 'fa fa-file-invoice'),
                MenuItem::linkTo(MessageRuleCrudController::class, 'Reglas de Automatización', 'fa fa-robot'),
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        yield MenuItem::subMenu('Maestros Globales', 'fa fa-globe-americas')
            ->setSubItems([
                MenuItem::linkTo(MaestroIdiomaCrudController::class, 'Idiomas', 'fa fa-language'),
                MenuItem::linkTo(MaestroPaisCrudController::class, 'Países', 'fa fa-flag'),
                MenuItem::linkTo(MaestroDocumentoTipoCrudController::class, 'Tipos Documento', 'fa fa-id-card'),
                MenuItem::linkTo(MaestroMonedaCrudController::class, 'Monedas', 'fa fa-coins'),
                MenuItem::linkTo(MaestroTipocambioCrudController::class, 'Tipos de Cambio', 'fa fa-money-bill-transfer'),
                MenuItem::linkTo(UiI18nCrudController::class, 'Traducciones UI', 'fa fa-spell-check')
            ])
            ->setPermission(Roles::MAESTROS_SHOW);

        // =========================================================================
        // SECCIÓN 4: INTEGRACIONES EXTERNAS (EXCHANGE)
        // =========================================================================
        yield MenuItem::section('Integraciones API');

        yield MenuItem::subMenu('Credenciales y Endpoints', 'fa fa-network-wired')
            ->setSubItems([
                MenuItem::linkTo(ExchangeEndpointCrudController::class, 'Endpoints (Hub)', 'fa fa-link'),
                MenuItem::linkTo(Beds24ConfigCrudController::class, 'Credenciales Beds24', 'fa fa-key'),
                MenuItem::linkTo(MetaConfigCrudController::class, 'Credenciales Meta', 'fab fa-whatsapp'),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::subMenu('Mapeos Técnicos', 'fa fa-sitemap')
            ->setSubItems([
                MenuItem::linkTo(PmsUnidadBeds24MapCrudController::class, 'Mapas Beds24 (Unidades)', 'fa fa-map-location-dot'),
                MenuItem::linkTo(PmsEventoBeds24LinkCrudController::class, 'Vínculos de Eventos', 'fa fa-link-slash'),
            ])
            ->setPermission(Roles::ADMIN);

        // =========================================================================
        // SECCIÓN 5: SISTEMA Y MONITOREO (ADMINISTRADORES)
        // =========================================================================
        yield MenuItem::section('Sistema');

        yield MenuItem::subMenu('Colas de Trabajo (Jobs)', 'fa fa-server')
            ->setSubItems([
                MenuItem::linkTo(PmsBookingsPullQueueCrudController::class, 'Pull Reservas', 'fa fa-download'),
                MenuItem::linkTo(PmsBookingsPushQueueCrudController::class, 'Push Reservas', 'fa fa-upload'),
                MenuItem::linkTo(PmsRatesPushQueueCrudController::class, 'Push Tarifas', 'fa fa-tags'),
                MenuItem::linkTo(WhatsappMetaSendQueueCrudController::class, 'Salida WhatsApp', 'fa fa-paper-plane'),
                MenuItem::linkTo(Beds24SendQueueCrudController::class, 'Salida Beds24', 'fa fa-cloud-upload-alt'),
                MenuItem::linkTo(Beds24ReceiveQueueCrudController::class, 'Entrada Beds24', 'fa fa-cloud-download-alt'),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::subMenu('Auditoría Técnica', 'fa fa-bug')
            ->setSubItems([
                MenuItem::linkTo(MessageConversationCrudController::class, 'Conversaciones (Mensajes)', 'fa fa-comment-dots'),
                MenuItem::linkTo(MessageCrudController::class, 'Historial de Mensajes', 'fa fa-history'),
                MenuItem::linkTo(MessageAttachmentCrudController::class, 'Archivos Adjuntos', 'fa fa-paperclip'),
                MenuItem::linkTo(MessengerMessageCrudController::class, 'Colas del Sistema (Messenger)', 'fas fa-network-wired'),
                MenuItem::linkTo(PushSubscriptionCrudController::class, 'Suscripciones Push (Dispositivos)', 'fas fa-mobile-alt'),
                MenuItem::linkTo(PmsBeds24WebhookAuditCrudController::class, 'Beds24 Webhook Audit', 'fa fa-stethoscope'),
                MenuItem::linkTo(MetaWebhookAuditCrudController::class, 'Meta Whatsapp Webhook Audit', 'fa fa-stethoscope'),
                MenuItem::linkTo(CronCursorCrudController::class, 'Estado de Crons', 'fa fa-clock'),
            ])
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkTo(UserCrudController::class, 'Usuarios del Sistema', 'fa fa-users-gear')
            ->setPermission(Roles::ADMIN);

        yield MenuItem::linkToLogout('Cerrar Sesión', 'fa fa-sign-out-alt');
    }
}