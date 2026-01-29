<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\User;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * UserCrudController.
 * Gestión de usuarios con soporte UUID y seguridad basada en Roles.
 * Hereda de BaseCrudController para preservar la lógica transversal del panel.
 */
class UserCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private UserPasswordHasherInterface $userPasswordHasher
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    /**
     * Configuración de permisos y acciones.
     * ✅ Se integran las constantes de la clase Roles para restringir el acceso.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        // Aplicamos permisos de tu clase Roles sobre las acciones del padre
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
            ->setEntityLabelInSingular('Usuario')
            ->setEntityLabelInPlural('Usuarios')
            ->setSearchFields(['username', 'email', 'firstname', 'lastname'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // UUID para visualización técnica en detalle
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(fn($value) => (string) $value);

        // --- CREDENCIALES ---
        yield FormField::addPanel('Credenciales de Acceso')->setIcon('fa fa-key');

        yield TextField::new('username', 'Usuario')
            ->setColumns(6);

        yield EmailField::new('email', 'Email')
            ->setColumns(6);

        /**
         * Lógica de Password (mapped => false).
         * Mantenida íntegramente para soportar el hashing vía POST_SUBMIT.
         */
        yield TextField::new('plainPassword', 'Contraseña')
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Contraseña'],
                'second_options' => ['label' => 'Repetir Contraseña'],
                'mapped' => false,
            ])
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->onlyOnForms()
            ->setColumns(12);

        yield ChoiceField::new('roles', 'Permisos de Sistema')
            ->setChoices(Roles::getChoices())
            ->allowMultipleChoices()
            ->renderAsBadges()
            ->setColumns(12);

        yield BooleanField::new('enabled', 'Cuenta Activa')
            ->renderAsSwitch(true);

        // --- DATOS PERSONALES ---
        yield FormField::addPanel('Información Personal')->setIcon('fa fa-user');

        yield TextField::new('firstname', 'Nombre')->setColumns(6);
        yield TextField::new('lastname', 'Apellido')->setColumns(6);

        // --- ORGANIZACIÓN ---
        yield FormField::addPanel('Organización')->setIcon('fa fa-building');

        yield AssociationField::new('dependencia', 'Dependencia')
            ->setColumns(6);

        yield AssociationField::new('area', 'Área')
            ->setColumns(6);
    }

    /*
     * -------------------------------------------------------------------------
     * LÓGICA DE HASHING (EVENT LISTENERS)
     * -------------------------------------------------------------------------
     */

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        return $this->addPasswordEventListener($formBuilder);
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
        return $this->addPasswordEventListener($formBuilder);
    }

    private function addPasswordEventListener(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        return $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var User $user */
            $user = $form->getData();

            $plainPassword = $form->get('plainPassword')->getData();

            if (!empty($plainPassword)) {
                $hashedPassword = $this->userPasswordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }
        });
    }
}