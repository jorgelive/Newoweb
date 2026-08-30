<?php

declare(strict_types=1);

namespace App\Exchange\Controller\Crud;

use App\Exchange\Entity\EmailConfig;
use App\Panel\Controller\Crud\BaseCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * El buzón desde el que sale el correo.
 *
 * ⚠️ **Aquí NO hay credenciales.** El DSN de Microsoft Graph vive en `MAILER_DSN` y ahí se
 * queda: un secreto en una fila de base de datos acaba en un volcado, en una copia de seguridad
 * y en la pantalla de alguien. Ver `docs/CorreoSaliente.md`.
 *
 * Esto guarda sólo lo que sí es configuración de negocio: quién firma el correo.
 *
 * @extends BaseCrudController<EmailConfig>
 */
class EmailConfigCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return EmailConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Buzón de salida')
            ->setEntityLabelInPlural('Correo saliente')
            ->setHelp(
                Crud::PAGE_EDIT,
                'El remitente tiene que ser un <strong>buzón real</strong> del tenant, no un alias: '
                . 'Microsoft Graph rechaza enviar «como» un alias y el fallo no aparece al guardar, '
                . 'sino al intentar mandar el primer correo.'
            )
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setMaxLength(40)->onlyOnDetail();

        yield FormField::addPanel('Quién firma el correo')->setIcon('fa fa-envelope');

        yield TextField::new('nombre', 'Nombre interno')
            ->setHelp('Para reconocerlo en esta lista. No lo ve nadie de fuera.')
            ->setColumns(8);

        yield BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true)
            ->setColumns(4)
            ->setHelp('Con el remitente vacío no lo actives: los correos fallarían de uno en uno.');

        yield EmailField::new('remitente', 'Buzón remitente')
            ->setColumns(6)
            ->setHelp('Un buzón <strong>real</strong> del tenant. Un alias no sirve.');

        yield TextField::new('remitenteNombre', 'Nombre visible')
            ->setColumns(6)
            ->setHelp('Lo que el destinatario ve antes de la dirección. Ej.: «OpenPeru».');

        yield EmailField::new('responderA', 'Responder a')
            ->setColumns(6)
            ->setHelp('A dónde contestan. Vacío = al mismo remitente.')
            ->hideOnIndex();
    }
}
