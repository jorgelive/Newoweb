<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelComponente;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class TravelComponenteCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelComponente::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Componente Logístico')
            ->setEntityLabelInPlural('Componentes Logísticos')
            ->setSearchFields(['id', 'nombre', 'tipo'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // 🔥 Botón de Clonar usando Stimulus y SweetAlert2
        $cloneAction = Action::new('cloneAction', 'Clonar', 'fa fa-copy')
            ->linkToCrudAction('cloneComponente')
            ->setCssClass('btn btn-info')
            ->setHtmlAttributes([
                // 1. Conectamos el controlador y el evento click
                'data-controller' => 'panel--confirm',
                'data-action' => 'click->panel--confirm#ask',

                // 2. Pasamos los valores personalizados para SweetAlert2
                'data-panel--confirm-title-value' => '¿Clonar componente?',
                'data-panel--confirm-text-value' => 'Se duplicará el componente con todas sus tarifas e ítems internos. Podrás editarlo a continuación.',
                'data-panel--confirm-icon-value' => 'question',
                'data-panel--confirm-confirm-button-text-value' => 'Sí, clonar',
                'data-panel--confirm-confirm-color-value' => '#0ea5e9' // Un tono azul/celeste que combine con el btn-info
            ]);

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cloneAction)
            ->add(Crud::PAGE_DETAIL, $cloneAction)
            ->add(Crud::PAGE_EDIT, $cloneAction);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE)
            ->setPermission('cloneAction', Roles::MAESTROS_WRITE);
    }

    /**
     * 🔥 Deep Clone mediante llamada a __clone() en el Dominio.
     */
    public function cloneComponente(
        AdminContext $context,
        EntityManagerInterface $em,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var TravelComponente $original */
        $original = $context->getEntity()->getInstance();

        // ¡Toda la magia recursiva ocurre aquí gracias a las entidades!
        $clon = clone $original;

        $em->persist($clon);
        $em->flush();

        $this->addFlash('success', 'Componente y tarifas clonadas exitosamente.');

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($clon->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Identidad del Componente')->setIcon('fa fa-box');

        yield TextField::new('nombre', 'Nombre Interno (Administrativo)')
            ->setColumns(6)
            ->setHelp('Ej: BTI - Boleto Turístico Integral');

        yield ChoiceField::new('tipo', 'Categoría Operativa')
            ->setChoices(array_reduce(ComponenteTipoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof ComponenteTipoEnum ? $value->value : $value)
            ->setColumns(6);

        yield FormField::addPanel('Título Público (Lo que ve el cliente)')->setIcon('fa fa-language')
            ->setHelp('Si este componente no es un "Pool" y se muestra directamente al cliente, aquí defines cómo se lee en su PDF.');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título Comercial')
            ->setEntryType(TranslationTextType::class)
            ->setRequired(false)
            ->setColumns(12);

        yield FormField::addPanel('Configuración Operativa')->setIcon('fa fa-cogs');

        yield NumberField::new('duracion', 'Duración (Horas)')
            ->setNumDecimals(1)
            ->setColumns(4);

        yield IntegerField::new('anticipacionalerta', 'Alerta Temprana (Días)')
            ->setColumns(4)
            ->hideOnIndex();

        yield FormField::addPanel('Ítems y Upsells (Lo que incluye)')->setIcon('fa fa-list-check');

        yield CollectionField::new('componenteItems', 'Detalle de Inclusiones')
            ->useEntryCrudForm(TravelComponenteItemCrudController::class)
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('required', false)
            ->setColumns(12)
            ->setHelp('Para paquetes (Pools), añade aquí los servicios que incluye (Bus, Guía, etc). Si este componente es un servicio atómico (Ej: Ticket de Tren), puedes dejar esto completamente vacío.');

        yield FormField::addPanel('Tarifario Base')->setIcon('fa fa-money-bill-wave');

        yield CollectionField::new('tarifas', 'Costos Maestros')
            ->useEntryCrudForm(TravelTarifaCrudController::class)
            ->setFormTypeOption('by_reference', false)
            ->setColumns(12)
            ->setHelp('Agrega las tarifas para este componente (Adultos, Niños, Extranjeros, etc).');

        yield FormField::addPanel('Trazabilidad')->setIcon('fa fa-link')->onlyOnDetail();

        yield CollectionField::new('servicios', 'Servicios (Tours) que usan este insumo')
            ->onlyOnDetail()
            ->formatValue(static function ($value, $entity) {
                $servicios = [];

                foreach ($entity->getServicios() as $servicio) {
                    $servicios[] = sprintf('<li>%s</li>', htmlspecialchars((string) $servicio->getNombreInterno()));
                }

                if (empty($servicios)) {
                    return '<span class="text-muted">No está vinculado a ningún servicio (tour) actualmente.</span>';
                }

                return sprintf('<ul style="padding-left: 15px; margin: 0;">%s</ul>', implode('', $servicios));
            });
    }
}