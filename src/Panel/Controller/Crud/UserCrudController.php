<?php

namespace App\Panel\Controller\Crud;

use App\Entity\User;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
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
 * Controlador CRUD para la gestión de usuarios.
 * Integra el hashing de contraseñas mediante eventos de formulario para soportar campos no mapeados.
 */
class UserCrudController extends AbstractCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private UserPasswordHasherInterface $userPasswordHasher
    ) {
        // El constructor padre de AbstractCrudController no requiere argumentos,
        // pero mantenemos las dependencias inyectadas para uso interno.
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Usuario')
            ->setEntityLabelInPlural('Usuarios')
            ->setSearchFields(['username', 'email', 'firstname', 'lastname'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        // --- CREDENCIALES ---
        yield FormField::addPanel('Credenciales de Acceso')->setIcon('fa fa-key');

        yield TextField::new('username', 'Usuario')
            ->setColumns(6);

        yield EmailField::new('email', 'Email')
            ->setColumns(6);

        /*
         * Campo de Contraseña.
         * Configurado como 'mapped' => false para evitar que Symfony intente escribir
         * el texto plano en la entidad User. La lógica de hashing se maneja en el Listener.
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

        /*
         * Selector de Roles.
         * allowMultipleChoices() garantiza que el valor devuelto sea un array,
         * cumpliendo con el tipado estricto de User::setRoles(array $roles).
         * Nota: Requiere que la clase App\Security\Roles exista y tenga el método getChoices().
         */
        yield ChoiceField::new('roles', 'Permisos')
            ->setChoices(Roles::getChoices())
            ->allowMultipleChoices()
            ->renderAsBadges()
            ->setHelp('Selecciona los roles asignados al usuario.')
            ->setColumns(12);

        yield BooleanField::new('enabled', 'Activo');

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

    /**
     * Override para inyectar lógica de Hashing en la CREACIÓN.
     * Se intercepta el constructor del formulario para añadir el Listener.
     */
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        return $this->addPasswordEventListener($formBuilder);
    }

    /**
     * Override para inyectar lógica de Hashing en la EDICIÓN.
     */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
        return $this->addPasswordEventListener($formBuilder);
    }

    /**
     * Agrega un Event Listener al formulario para procesar la contraseña plana.
     *
     * Usamos FormEvents::POST_SUBMIT porque necesitamos acceder al campo 'plainPassword'
     * (que no está mapeado en la entidad) y hashearlo antes de que Doctrine persista los cambios.
     *
     * @param FormBuilderInterface $formBuilder
     * @return FormBuilderInterface
     */
    private function addPasswordEventListener(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        return $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var User $user */
            $user = $form->getData();

            // Obtenemos la contraseña plana del campo "mapped: false"
            $plainPassword = $form->get('plainPassword')->getData();

            // Solo actualizamos la contraseña si el usuario escribió algo en el campo
            if (!empty($plainPassword)) {
                $hashedPassword = $this->userPasswordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }
        });
    }
}