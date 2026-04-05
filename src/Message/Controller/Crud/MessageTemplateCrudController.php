<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageTemplate;
use App\Message\Form\Type\Beds24TemplateType;
use App\Message\Form\Type\EmailTemplateType;
use App\Message\Form\Type\WhatsappLinkTemplateType;
use App\Message\Form\Type\WhatsappMetaTemplateType;
use App\Message\Service\MessageSegmentationAggregator;
use App\Message\Service\Meta\Template\WhatsappMetaTemplatePushService;
use App\Message\Service\Meta\Template\WhatsappMetaTemplateSyncService;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MessageTemplateCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly MessageSegmentationAggregator $segmentationAggregator
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MessageTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $crud = parent::configureCrud($crud);

        return $crud
            ->setEntityLabelInSingular('Plantilla')
            ->setEntityLabelInPlural('Plantillas de Mensaje')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gestión de Plantillas')
            ->showEntityActionsInlined();
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addCssFile('panel/styles/message/message_template/flat-collection.css');
    }

    public function configureActions(Actions $actions): Actions
    {
        // 1. Botón global para sincronizar (PULL)
        $syncMetaAction = Action::new('syncMetaTemplates', 'Sincronizar Meta', 'fa fa-cloud-download-alt')
            ->linkToCrudAction('executeMetaSync')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-info');

        // 2. Botón individual para hacer PUSH a Meta (Bypass interfaz web)
        $pushMetaAction = Action::new('pushMetaTemplate', 'Push a Meta', 'fa fa-cloud-upload-alt')
            ->linkToCrudAction('executePushToMeta')
            ->setCssClass('btn btn-warning text-dark') // Diferenciado visualmente
            ->displayIf(static function (MessageTemplate $entity) {
                // Solo mostrar si tiene datos de Meta
                return !empty($entity->getWhatsappMetaTmpl());
            });

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $syncMetaAction)
            // Añadimos el nuevo botón de Push en lista, vista detalle y edición
            ->add(Crud::PAGE_INDEX, $pushMetaAction)
            ->add(Crud::PAGE_DETAIL, $pushMetaAction)
            ->add(Crud::PAGE_EDIT, $pushMetaAction);

        $actions = parent::configureActions($actions);

        $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setIcon('fa fa-plus')->setLabel('Crear Plantilla'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action->setIcon('fa fa-edit')->setLabel('Editar'))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $action) => $action->setIcon('fa fa-eye')->setLabel('Ver'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => $action->setIcon('fa fa-trash-alt')->setLabel('Eliminar'))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel('Guardar Plantilla'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel('Guardar Cambios'));

        return $actions
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)
            ->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE)
            ->setPermission('pushMetaTemplate', Roles::MENSAJES_WRITE); // Requiere permisos de escritura
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setMaxLength(40)
            ->onlyOnDetail();

        // --- PANEL 1: GENERAL ---
        yield FormField::addPanel('Información General')
            ->setIcon('fa fa-info-circle');

        yield TextField::new('code', 'Código Interno')
            ->setColumns(12)
            ->setHelp('Llave única para el sistema. <b>No usar espacios, tildes ni mayúsculas</b> (ej: <code>booking_confirmation</code>).');

        yield TextField::new('name', 'Nombre Comercial')
            ->setColumns(12)
            ->setHelp('Nombre descriptivo y amigable para el equipo.');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        // --- 🔥 PANEL 2: ALCANCE Y SEGREGACIÓN ---
        yield FormField::addPanel('Alcance y Segregación (Scope)')
            ->setIcon('fa fa-filter')
            ->setHelp('Define dónde se permite usar esta plantilla. Si dejas los filtros vacíos, será una plantilla <b>Global</b>.');

        yield ChoiceField::new('contextType', 'Módulo Exclusivo')
            ->setChoices([
                'Solo Reservas (PMS)' => 'pms_reserva',
                'Registro Manual / Walk-in' => 'manual',
            ])
            ->setRequired(false)
            ->setColumns(4);

        yield ChoiceField::new('allowedSources', 'Solo para estas Fuentes (OTAs)')
            ->setChoices($this->segmentationAggregator->getSourceChoices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setColumns(4);

        yield ChoiceField::new('allowedAgencies', 'Solo para Agencias (B2B)')
            ->setChoices($this->segmentationAggregator->getAgencyChoices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setColumns(4);

        // --- PANEL 3: WHATSAPP / META ---
        yield FormField::addPanel('Configuración WhatsApp / Meta')
            ->setIcon('fab fa-whatsapp')
            ->collapsible()
            ->renderCollapsed()
            ->setHelp('💡 <b>¡Importante!</b> Utiliza llaves dobles y nombres descriptivos para tus variables (ej. <code>{{guest_name}}</code> o <code>{{url_checkin}}</code>). El sistema las detectará y convertirá automáticamente al formato posicional que exige Meta.');
        yield Field::new('whatsappMetaTmpl', '')
            ->setFormType(WhatsappMetaTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('whatsappMetaTmpl', 'JSON Generado WhatsApp')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 4: BEDS24 ---
        yield FormField::addPanel('Configuración Beds24 / OTAs')
            ->setIcon('fa fa-bed')
            ->collapsible()
            ->renderCollapsed();

        yield Field::new('beds24Tmpl', '')
            ->setFormType(Beds24TemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('beds24Tmpl', 'JSON Generado Beds24')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 5: WHATSAPP LINK MANUAL ---
        yield FormField::addPanel('Configuración Enlace WhatsApp (Manual)')
            ->setIcon('fa fa-external-link-alt')
            ->collapsible()
            ->renderCollapsed();

        yield Field::new('whatsappLinkTmpl', '')
            ->setFormType(WhatsappLinkTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('whatsappLinkTmpl', 'JSON Generado Link Manual')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 6: EMAIL ---
        yield FormField::addPanel('Configuración Correo Electrónico')
            ->setIcon('fa fa-envelope')
            ->collapsible()
            ->renderCollapsed();

        yield Field::new('emailTmpl', '')
            ->setFormType(EmailTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('emailTmpl', 'JSON Generado Email')
            ->setLanguage('js')->onlyOnDetail()
            ->formatValue(fn($val) => empty($val) ? '' : (is_array($val) ? json_encode($val, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : $val));

        // --- PANEL 7: AUDITORÍA ---
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->collapsible()
            ->renderCollapsed()
            ->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }

    /**
     * Ejecuta la sincronización manual de plantillas oficiales desde WhatsApp Meta Cloud API.
     * * ¿Por qué existe? Permite al operador forzar la actualización de plantillas (nuevas o cambios de estado)
     * directamente desde la interfaz de EasyAdmin sin esperar a procesos en segundo plano.
     * Delega toda la responsabilidad de conexión, extracción de credenciales y mapeo de componentes
     * (Header, Body, Footer, Buttons) al servicio especializado.
     *
     * @param AdminContext $context Contexto actual de la petición en EasyAdmin.
     * @param WhatsappMetaTemplateSyncService $syncService Orquestador unificado de sincronización de Meta.
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs para la redirección post-ejecución.
     * @return Response Redirección al listado principal del CRUD.
     */
    public function executeMetaSync(
        AdminContext $context,
        WhatsappMetaTemplateSyncService $syncService,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        try {
            // El servicio ahora encapsula toda la lógica HTTP y de base de datos internamente.
            $result = $syncService->sync();

            $created = $result['created'] ?? 0;
            $updated = $result['updated'] ?? 0;
            $total = $created + $updated;

            if ($total > 0) {
                $this->addFlash(
                    'success',
                    sprintf('Sincronización exitosa. Se crearon %d y se actualizaron %d plantillas oficiales de Meta.', $created, $updated)
                );
            } else {
                $this->addFlash('info', 'Plantillas verificadas. Todo se encuentra sincronizado y al día con Meta.');
            }

        } catch (Throwable $e) {
            $this->addFlash('danger', 'Error crítico al sincronizar plantillas con Meta: ' . $e->getMessage());
        }

        // Redirigimos de vuelta a la lista del CRUD después de ejecutar la acción
        $targetUrl = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($targetUrl);
    }

    /**
     * Fuerz el envío (Push) de la estructura JSON de esta plantilla hacia Meta
     * para su creación o actualización en múltiples idiomas, bypaseando la web de Facebook.
     *
     * @param AdminContext $context Contexto actual de la petición en EasyAdmin.
     * @param WhatsappMetaTemplatePushService $pushService Servicio encargado de formatear y subir la plantilla a Meta.
     * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs.
     * @return Response Redirección a la vista previa.
     */
    public function executePushToMeta(
        AdminContext $context,
        WhatsappMetaTemplatePushService $pushService,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        $template = $context->getEntity()->getInstance();

        if (!$template instanceof MessageTemplate) {
            $this->addFlash('danger', 'Error interno: No se pudo obtener la entidad de la plantilla.');
            return $this->redirect($context->getReferrer() ?? $adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        try {
            // Ejecutamos el servicio que armamos, que procesa todos los idiomas
            $results = $pushService->pushTemplateToMeta($template);

            if (empty($results)) {
                $this->addFlash('warning', 'La plantilla local no tiene un JSON de WhatsApp Meta válido o no contiene idiomas configurados.');
            } else {
                $successCount = 0;
                $errorMessages = [];

                // Analizamos los resultados por idioma
                foreach ($results as $lang => $result) {
                    if ($result['status'] === 'success') {
                        $successCount++;
                    } else {
                        $errorMessages[] = strtoupper($lang) . ': ' . $result['message'];
                    }
                }

                // Generamos el feedback al usuario
                if ($successCount > 0) {
                    $this->addFlash('success', sprintf('✅ Se enviaron a revisión en Meta %d idiomas exitosamente.', $successCount));
                }

                if (!empty($errorMessages)) {
                    $this->addFlash('danger', '❌ Ocurrieron errores en algunos idiomas: <br>' . implode('<br>', $errorMessages));
                }
            }

        } catch (Throwable $e) {
            $this->addFlash('danger', 'Error crítico al hacer Push a Meta: ' . $e->getMessage());
        }

        // Retornamos a la misma vista donde el usuario hizo clic (Index, Detail o Edit)
        $referrer = $context->getReferrer();
        if ($referrer) {
            return $this->redirect($referrer);
        }

        return $this->redirect($adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }
}