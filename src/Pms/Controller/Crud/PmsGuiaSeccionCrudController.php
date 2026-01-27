<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Pms\Entity\PmsGuiaSeccion;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Controlador para gestionar las secciones (bloques) de la guía digital.
 * Permite definir contenidos comunes o específicos por unidad.
 */
class PmsGuiaSeccionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsGuiaSeccion::class;
    }

    /**
     * Configuración de permisos basada en la clase global Roles.
     */
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            // Lectura: Personal de reservas puede consultar contenidos
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)

            // Escritura: Solo personal autorizado puede crear o editar secciones
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)

            // Borrado: Restringido según la política de seguridad
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sección de Guía')
            ->setEntityLabelInPlural('Secciones de Guía')
            ->setSearchFields(['id', 'titulo', 'icono'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración Básica');

        yield IdField::new('id')->hideOnForm();

        yield BooleanField::new('esComun', 'Sección Común')
            ->setHelp('Si se activa, esta sección podrá ser añadida a cualquier guía de unidad.')
            ->renderAsSwitch(true);

        yield TextField::new('icono', 'Icono (FontAwesome)')
            ->setHelp('Ejemplo: fa-wifi, fa-info-circle, fa-utensils.')
            ->setRequired(false);

        yield FormField::addPanel('Contenido Multiidioma');

        // Flag virtual del Trait global para el núcleo de traducción
        yield BooleanField::new('ejecutarTraduccion', 'Traducir Título Automáticamente')
            ->setHelp('Llama a Google Translate al guardar para completar idiomas prioritarios.')
            ->onlyOnForms()
            ->hideOnIndex()
            ->setPermission(Roles::RESERVAS_WRITE);

        yield CollectionField::new('titulo', 'Título por Idioma')
            ->setHelp('Ingrese el título en español ("es") para disparar la traducción automática.')
            ->allowAdd()
            ->allowDelete();

        yield FormField::addPanel('Ítems de Contenido');

        // Permite gestionar los ítems (tarjetas, wifi, etc.) directamente desde la sección
        yield CollectionField::new('items', 'Ítems en esta Sección')
            ->useEntryCrudForm()
            ->setHelp('Añada y ordene los elementos que verá el huésped en esta sección.');
    }
}