<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Pms\Entity\PmsUnidadMedia;
use App\Pms\Enum\PmsUnidadMediaTipo;
use App\Service\Config\Parametro;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Dónde se suben el croquis, la foto de la puerta y el vídeo del ingreso de cada casita.
 *
 * ⚠️ **El croquis es UNO POR CASITA y numera sólo SU puerta.** El viejo numeraba las siete y
 * estaba copiado dentro de cada ítem de guía; repartirlo entero deshace la razón por la que las
 * puertas no se numeran físicamente. Ver `docs/PmsGuiaHuesped.md` §3.c.
 *
 * El formulario enseña archivo o URL según el tipo —los dos campos están siempre, porque EasyAdmin
 * no cambia el formulario al vuelo—, y la entidad valida que la URL sea de YouTube: el front la
 * incrusta como vídeo, así que otra cosa no daría error, pintaría un reproductor vacío delante del
 * huésped.
 *
 * ⚠️ **Esta es la única pantalla**: no se incrusta dentro de la casita. Tuvo una rama para el caso
 * incrustado y se retiró con el editor que la usaba (12/09/2026); una rama que nadie recorre sólo
 * sirve para que alguien la mantenga sin motivo.
 *
 * @extends BaseCrudController<PmsUnidadMedia>
 */
class PmsUnidadMediaCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly ParameterBagInterface $params,
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsUnidadMedia::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Medio de la casita')
            ->setEntityLabelInPlural('Medios de las casitas')
            ->setDefaultSort(['unidad' => 'ASC', 'tipo' => 'ASC'])
            ->setHelp(
                'index',
                'El croquis, la foto de la puerta y el vídeo del ingreso de cada casita. '
                . 'El croquis de cada una debe numerar <strong>sólo su puerta</strong>.'
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('unidad', 'Casita'));
    }

    public function configureFields(string $pageName): iterable
    {
        $pathRelativo = Parametro::texto($this->params->get('pms.path.unidad_images'), 'pms.path.unidad_images');
        $basePath = '/' . ltrim($pathRelativo, '/');

        // ⚠️ `attr.required` además de `setRequired()`, como el resto de los desplegables
        // obligatorios del panel (`PmsEstablecimiento`, `PmsReserva`…). `setRequired()` sólo valida
        // al enviar; es el atributo del widget el que le quita la **✕ de limpiar**. Sin él se
        // ofrece vaciar un campo que no admite vacío: la ✕ deja el formulario en un estado que
        // luego rebota, y el que la pulsa se entera al guardar.
        yield AssociationField::new('unidad', 'Casita')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setSortable(true);

        yield ChoiceField::new('tipo', 'Tipo')
            ->setChoices(PmsUnidadMediaTipo::opciones())
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setSortable(true)
            ->setHelp('En la guía se pide con <code>{{ croquis }}</code>, '
                . '<code>{{ foto_puerta }}</code> o <code>{{ video_ingreso }}</code>, según el tipo.');

        yield LiipImageField::new('imageUrl', 'Vista previa')
            ->onlyOnIndex()
            ->setSortable(false);

        yield TextField::new('imageFile', 'Archivo (croquis o foto)')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(12)
            ->setHelp('Sólo para los tipos de imagen. Un vídeo va en el campo de abajo.');

        yield UrlField::new('url', 'URL de YouTube')
            ->setColumns(12)
            ->setHelp('Sólo para el vídeo del ingreso. Tiene que ser de YouTube: es lo que el '
                . 'front sabe incrustar.');

        yield IntegerField::new('orden', 'Orden')
            ->setColumns(3)
            ->hideOnIndex();

        yield ImageField::new('imageName', 'Archivo')
            ->setBasePath($basePath)
            ->onlyOnDetail();
    }
}
