<?php

declare(strict_types=1);

namespace App\Agent\Controller\Crud;

use App\Agent\Action\BotActionHandlerInterface;
use App\Agent\Entity\AutoResponderRule;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\RequestStack;

class AutoResponderRuleCrudController extends BaseCrudController
{
    /**
     * @param iterable<BotActionHandlerInterface> $actionHandlers Iterador dinámico de Handlers registrados.
     */
    public function __construct(
        #[AutowireIterator('app.bot_action_handler')]
        private iterable $actionHandlers,
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    /**
     * Devuelve el FQCN de la entidad que gestiona este CRUD.
     */
    public static function getEntityFqcn(): string
    {
        return AutoResponderRule::class;
    }

    /**
     * Configuración general del CRUD (Títulos, orden, campos de búsqueda).
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Regla de Auto-Respuesta')
            ->setEntityLabelInPlural('Reglas de Auto-Respuesta')
            ->setSearchFields(['triggerValue', 'actionType'])
            ->setDefaultSort(['isActive' => 'DESC', 'triggerValue' => 'ASC'])
            ->showEntityActionsInlined();
    }

    /**
     * Configuración de los permisos y botones de acción según el rol del usuario.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE);
    }

    /**
     * Configuración de los filtros laterales de EasyAdmin.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('isActive')
            ->add('actionType');
    }

    /**
     * Configuración de los campos que se renderizan en las distintas vistas (Index, Detail, Form).
     */
    public function configureFields(string $pageName): iterable
    {
        // 1. Recopilamos dinámicamente las acciones disponibles desde los servicios tageados.
        $choices = [];
        foreach ($this->actionHandlers as $handler) {
            // EasyAdmin espera un array asociativo: ['Etiqueta Visible' => 'valor_interno']
            $choices[$handler->getActionLabel()] = $handler->getActionKey();
        }

        yield IdField::new('id')->hideOnForm();

        yield BooleanField::new('isActive', 'Activa')
            ->setHelp('Si se desactiva, el Router ignorará esta regla y evaluará el mensaje mediante IA (si aplica).');

        yield TextField::new('triggerValue', 'Trigger (Disparador)')
            ->setColumns(6)
            ->setHelp('Código exacto que viene del Webhook. Ej: BTN_WIFI, ERR_131049.');

        // 2. Desplegable dinámico construido a partir de los Handlers detectados.
        yield ChoiceField::new('actionType', 'Acción a Ejecutar')
            ->setColumns(6)
            ->setChoices($choices)
            ->setHelp('Elige qué lógica ejecutará el sistema cuando detecte el Trigger.');

        // 3. Campo mágico mapeado a la PROPIEDAD VIRTUAL (actionParametersJson)
        // Esto evita el error de Array to String conversion al renderizar el formulario.
        yield CodeEditorField::new('actionParametersJson', 'Parámetros de la Acción (JSON)')
            ->setLanguage('js') // Activa el syntax highlighter para JSON/JS en Ace Editor
            ->setNumOfRows(10)
            ->setHelp('Parámetros específicos que necesita la acción. Ej: {"tag": "WA_BLOCKED"} o {"template_code": "TPL_WIFI_01"}')
            ->hideOnIndex(); // Ocultamos el bloque JSON del listado principal para ahorrar espacio
    }
}