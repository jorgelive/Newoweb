<?php

declare(strict_types=1);

namespace App\Panel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Entity\User;
use App\Security\Roles;
use App\Twig\Extension\PhoneExtension;
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
 *
 * @extends BaseCrudController<User>
 */
class UserCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private UserPasswordHasherInterface $userPasswordHasher,
        // El mismo formateador que usa el CRUD de reservas para el teléfono del huésped.
        private readonly PhoneExtension $phoneExtension,
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
            ->setDefaultSort(['id' => 'DESC'])
            ->showEntityActionsInlined();
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

        // Independiente de «Cuenta Activa» a propósito: quien cobra en la casita no necesita
        // entrar al sistema (§11.5.1 de PmsBeds24ReservasSync). Sólo surte efecto junto al
        // rol «Puede cobrar al huésped»; se espera UNA sola persona marcada.
        yield BooleanField::new('esCobradorPrincipal', 'Cobra por defecto (recepción)')
            ->setHelp('Al registrar un pago sin decir quién lo recibió, se le atribuye a esta persona. Requiere el rol «Puede cobrar al huésped».')
            ->renderAsSwitch(true);

        // Mismo patrón que el cobrador principal: el defecto es un DATO, no una constante con
        // el nombre de nadie dentro. El día que quien limpia hoy tome otro camino, se marca
        // aquí a otra persona y las estancias nuevas la cogen sin desplegar nada.
        yield BooleanField::new('esLimpiezaPorDefecto', 'Limpia por defecto')
            // El texto decía «debe estar habilitada»: era verdad durante unas horas y dejó de
            // serlo al quitar ese filtro del listener (quien limpia no tiene login, así que
            // exigirlo dejaba la asignación automática sin hacer nada). Un help que miente
            // hace perder más tiempo que uno que falta.
            ->setHelp('Cada estancia nueva se le asigna sola a esta persona. Es un punto de '
                . 'partida: luego se le añaden o quitan personas en el propio evento —desde el '
                . 'calendario de reservas—, y una casita grande puede llevar dos. No hace falta '
                . 'que esté habilitada: quien limpia no entra al panel. Se espera UNA sola marcada.')
            ->renderAsSwitch(true);

        // --- DATOS PERSONALES ---
        yield FormField::addPanel('Información Personal')->setIcon('fa fa-user');

        yield TextField::new('firstname', 'Nombre')->setColumns(6);
        yield TextField::new('lastname', 'Apellido')->setColumns(6);

        // Mismo trato que el teléfono del huésped en PmsReservaCrudController: se GUARDA en
        // crudo (sólo dígitos, lo normaliza UserIntegrityListener) y se MUESTRA formateado
        // con el filtro de Twig. Así el operador teclea como quiera y lee un número legible,
        // pero en la columna queda la forma que se compara con el remitente de WhatsApp.
        yield TextField::new('telefono', 'Móvil (identifica en el chat)')
            ->setColumns(6)
            ->formatValue(fn (?string $val) => $val ? $this->phoneExtension->formatPhone($val) : null)
            ->setHelp(
                'Desde este número el sistema lo reconoce al escribir por WhatsApp. '
                . 'Escríbelo como quieras (+51 987 654 321 o 987654321): se guarda normalizado. '
                . 'Sin código de país se asume Perú.'
            );

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