<?php

declare(strict_types=1);

namespace App\Front\Comun\Crud;

use App\Front\Comun\Entity\LibroReclamacion;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Libro de Reclamaciones de la web pública, en el panel: leer las hojas y responderlas.
 *
 * Fuera de `src/Front/Comun/Controller/` a propósito: esa carpeta se carga como rutas de los hosts públicos.
 *
 * ⚠️ **Sin alta y sin borrado.** Una hoja la presenta el consumidor desde la web y es un registro
 * legal: lo único que se edita es la respuesta y si quedó atendida. Ver docs/WebPublica.md §5.
 *
 * @extends BaseCrudController<LibroReclamacion>
 */
class LibroReclamacionCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return LibroReclamacion::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hoja de reclamación')
            ->setEntityLabelInPlural('Libro de Reclamaciones')
            ->setSearchFields(['correlativo', 'consumidorNombre', 'consumidorEmail', 'consumidorDocumentoNumero'])
            ->setDefaultSort(['fecha' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Plazo legal de respuesta: 15 días hábiles desde la fecha de la hoja. La respuesta se escribe aquí y se envía al consumidor por correo.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Hoja')->setIcon('fa fa-book');
        yield TextField::new('correlativo', 'N.º')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('fecha', 'Fecha')->setFormat('dd/MM/yyyy HH:mm')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('venceRespuesta', 'Responder antes del')->setFormat('dd/MM/yyyy')->setSortable(false)->hideOnForm();
        yield TextField::new('sitio', 'Web')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('consumidorNombre', 'Consumidor')->setFormTypeOption('disabled', true);
        yield TextField::new('consumidorEmail', 'Correo')->setFormTypeOption('disabled', true);
        yield TextField::new('consumidorTelefono', 'Teléfono')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('consumidorDocumentoNumero', 'Documento')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('consumidorDomicilio', 'Domicilio')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('apoderadoNombre', 'Apoderado (menor de edad)')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextareaField::new('bienDescripcion', 'Bien contratado')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('bienMonto', 'Monto reclamado')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextareaField::new('detalle', 'Detalle')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextareaField::new('pedido', 'Pedido')->hideOnIndex()->setFormTypeOption('disabled', true);

        yield FormField::addPanel('Respuesta del proveedor')->setIcon('fa fa-reply');
        yield TextareaField::new('respuesta', 'Respuesta')->hideOnIndex()
            ->setHelp('Se guarda la fecha de la primera respuesta; es la que cuenta para el plazo legal. Envíala también por correo al consumidor.');
        yield DateTimeField::new('fechaRespuesta', 'Respondida el')->setFormat('dd/MM/yyyy HH:mm')->hideOnForm();
        yield BooleanField::new('atendida', 'Atendida')->renderAsSwitch($pageName === Crud::PAGE_EDIT);
    }
}
