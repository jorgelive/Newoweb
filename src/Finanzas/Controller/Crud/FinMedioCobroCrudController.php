<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Crud;

use App\Finanzas\Entity\FinMedioCobro;
use App\Finanzas\Enum\FinAudienciaCobro;
use App\Finanzas\Enum\FinMedioCobroTipo;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationTextType;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * El catálogo de cuentas por las que cobramos.
 *
 * ⚠️ **Esta pantalla es la única fuente de los números de cobro.** Lo que se teclee aquí se lo
 * dice el asistente al cliente literalmente, y el cliente manda dinero a ese destino: no hay
 * nadie revisando entre una cosa y la otra. Un dígito de más en un CCI es una transferencia
 * perdida y no siempre recuperable.
 *
 * Hoy lo guarda `MAESTROS_WRITE`, como el resto de la sección de Configuración. Es lo que hay,
 * no lo ideal: quien puede editar el catálogo de idiomas puede cambiar la cuenta a la que llega
 * el dinero. Si algún día se separa, el rol nuevo se cambia aquí y en el menú del dashboard.
 *
 * @extends BaseCrudController<FinMedioCobro>
 */
final class FinMedioCobroCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return FinMedioCobro::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Medio de cobro')
            ->setEntityLabelInPlural('Medios de cobro')
            // Por `orden` y no por fecha: es el orden en que se le enseñan al cliente.
            ->setDefaultSort(['orden' => 'ASC'])
            ->showEntityActionsInlined()
            ->setHelp(
                'index',
                'Por aquí cobra el negocio. El asistente le da estos datos al cliente tal cual, '
                . 'así que un número mal escrito es dinero que se pierde. Los medios sin número '
                . 'no se ofrecen (salvo el efectivo).'
            )
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::MAESTROS_SHOW)
            ->setPermission(Action::DETAIL, Roles::MAESTROS_SHOW)
            ->setPermission(Action::NEW, Roles::MAESTROS_WRITE)
            ->setPermission(Action::EDIT, Roles::MAESTROS_WRITE)
            ->setPermission(Action::DELETE, Roles::MAESTROS_WRITE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Medio')->setIcon('fa fa-hand-holding-dollar');

        yield ChoiceField::new('tipo', 'Medio')
            ->setChoices(FinMedioCobroTipo::opciones())
            ->renderExpanded(false);

        yield TextField::new('banco', 'Banco')
            ->setRequired(false)
            ->setHelp('Sólo en transferencias: BCP, BBVA, Interbank, Scotiabank.');

        yield TextField::new('moneda', 'Moneda')
            ->setRequired(false)
            ->setHelp('PEN o USD. Vacío si no aplica, como en el efectivo.');

        yield TextField::new('numero', 'Número')
            ->setRequired(false)
            ->setHelp(
                '⚠️ El número de cuenta, o el teléfono en Yape y Plin. <strong>Revísalo dos '
                . 'veces.</strong> Si se deja vacío, el medio no se le ofrece a nadie.'
            );

        yield FormField::addPanel('Titular')->setIcon('fa fa-user');

        yield TextField::new('titular', 'Titular')
            ->setRequired(false)
            ->setHelp('A nombre de quién está. No es el mismo en todos: el Western Union va a '
                . 'nombre de quien recoge el giro, no de quien tiene las cuentas.');

        yield TextField::new('titularAlterno', 'Cómo lo escribe el banco')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Para la banca que no admite tildes ni «ñ»: «Susan Acuna Romero». El '
                . 'cliente que ve un nombre distinto al que le dimos se para y pregunta.');

        yield TextField::new('cci', 'CCI')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Código interbancario. Déjalo vacío si no lo tienes a mano: un CCI copiado '
                . 'de otra cuenta manda el dinero a un tercero.');

        yield FormField::addPanel('A quién y cómo se le ofrece')->setIcon('fa fa-users');

        yield ChoiceField::new('audiencia', '¿A quién se le ofrece?')
            ->setChoices(FinAudienciaCobro::opciones())
            ->renderExpanded(false)
            ->setHelp(
                'No es un permiso, es dinero: las cuentas peruanas van en «Sólo desde Perú» '
                . 'porque una transferencia internacional se come en comisiones buena parte de '
                . 'un adelanto, y el Western Union va en «Sólo desde el extranjero» porque a un '
                . 'peruano le cobra un giro que no necesita.'
            );

        // Los DOS controles de traducción, juntos y antes del campo que gobiernan, como en
        // PmsGuiaItem y compañía. Son distintos y hacen falta los dos:
        //   · «Traducir Auto» (`ejecutarTraduccion`) — apaga el proceso entero al guardar.
        //     Es un flag VIRTUAL, no una columna: no persiste, vale para ese guardado.
        //   · «Sobrescribir» (`sobreescribirTraduccion`) — sí es columna, y decide si se
        //     rehacen los idiomas que YA tienen texto. Sin él, el modo seguro respeta lo
        //     escrito y corregir el español no cambia nada más.
        // El servicio devuelve el segundo a `false` solo tras usarlo, para que no se quede
        // pegado. Ver docs/FinanzasEnlacesPago.md §14.5.
        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        // i18n de verdad y no español a secas como el resto de textos que ve el modelo: esta
        // nota también la pinta la app del cliente, donde no hay ningún modelo que la redacte.
        yield CollectionField::new('nota', 'Nota para el cliente')
            ->setEntryType(TranslationTextType::class)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setColumns(12)
            ->setHelp('Escribe el español y guarda: el resto de idiomas se traducen solos.');

        yield TextField::new('notaEsVisual', 'Nota')
            ->hideOnForm()
            ->formatValue(fn ($value, ?FinMedioCobro $entity) => $entity?->getNotaEn('es') ?? '—');

        yield BooleanField::new('activo', 'Activo')
            ->renderAsSwitch(true)
            ->setHelp('Apagarlo lo retira de todas partes sin perder el número.');

        yield IntegerField::new('orden', 'Orden')
            ->setHelp('En qué orden se le enseñan al cliente. Lo primero es lo que más se usa.');
    }
}
