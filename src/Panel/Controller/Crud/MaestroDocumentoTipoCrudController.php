<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

// ✅ Restauramos la herencia de tu BaseCrudController
use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\Maestro\MaestroDocumentoTipo;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Pms\Controller\Crud\Beds24ConfigCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * MaestroDocumentoTipoCrudController.
 * Gestión de tipos de documentos de identidad (DNI, Pasaporte, CE).
 * Basado en códigos oficiales de SUNAT como identificadores naturales.
 * Hereda de BaseCrudController para lógica transversal del panel.
 */
class MaestroDocumentoTipoCrudController extends BaseCrudController
{
    /**
     * Mantenemos el constructor con las dependencias de tu BaseCrudController.
     */
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return MaestroDocumentoTipo::class;
    }

    /**
     * Configuración de permisos y acciones.
     * Utiliza la clase Roles para definir quién puede ver, crear, editar o borrar.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_DELETE)
            ->setPermission(Action::BATCH_DELETE, Roles::MAESTROS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tipo de Documento')
            ->setEntityLabelInPlural('Tipos de Documento')
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['id', 'nombre']);
    }

    /**
     * Definición de campos.
     * El ID es el código SUNAT (Ej: '01', '04', '07').
     */
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Información de Identidad')->setIcon('fa fa-id-card');

        // El ID es el código SUNAT. Se ingresa manualmente al crear (GeneratedValue: NONE).
        $id = TextField::new('id', 'Código SUNAT (ID)')
            ->setHelp('Código de 2 dígitos. Ej: 01 (DNI), 04 (CE), 07 (Pasaporte).')
            ->setFormTypeOption('attr', [
                'maxlength' => 2,
                'placeholder' => '01'
            ]);

        // Bloqueamos edición de ID para mantener integridad referencial en Huespedes
        if (Crud::PAGE_EDIT === $pageName) {
            $id->setFormTypeOption('disabled', true);
        }

        yield $id;
        yield TextField::new('nombre', 'Nombre del Documento');

        yield FormField::addPanel('Mapeos de Sistemas Externos')->setIcon('fa fa-exchange-alt');

        yield IntegerField::new('codigoMc', 'Cód. Cultura (MC)')
            ->setHelp('Código enviado a la plataforma de Machu Picchu.')
            ->hideOnIndex();

        yield IntegerField::new('codigoConsettur', 'Cód. Consettur')
            ->setHelp('Código para despacho de buses.')
            ->hideOnIndex();

        // ✅ Panel de Auditoría utilizando TimestampTrait
        yield FormField::addPanel('Tiempos de Registro')->setIcon('fa fa-clock')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Registrado')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última Modificación')
            ->onlyOnDetail();
    }
}