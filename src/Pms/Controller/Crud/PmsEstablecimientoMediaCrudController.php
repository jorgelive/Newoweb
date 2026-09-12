<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Pms\Entity\PmsEstablecimientoMedia;
use App\Pms\Enum\PmsEstablecimientoMediaTipo;
use App\Service\Config\Parametro;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Dónde se suben las fotos y los vídeos de las dos cajas fuertes del pasaje.
 *
 * ⚠️ **Las dos cajas no hacen lo mismo.** La de las LLAVES la abre el huésped al llegar y sus
 * medios salen en su guía dentro de la ventana de 30 h. La del DINERO **no sale nunca en la
 * guía**: se la entrega un operador cuando toca dejar un pago en efectivo. Eso no lo decide esta
 * pantalla, lo decide el tipo ({@see PmsEstablecimientoMediaTipo::visibilidad()}), y lo respeta
 * `PmsGuiaContexto::construir()`, que es la única puerta a la guía.
 *
 * @extends BaseCrudController<PmsEstablecimientoMedia>
 */
class PmsEstablecimientoMediaCrudController extends BaseCrudController
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
        return PmsEstablecimientoMedia::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Medio del establecimiento')
            ->setEntityLabelInPlural('Medios del establecimiento')
            ->setDefaultSort(['tipo' => 'ASC'])
            ->setHelp(
                'index',
                'Las dos cajas fuertes del pasaje. Las de la caja de las <strong>llaves</strong> '
                . 'salen en la guía del huésped 30 h antes de su llegada; las de la caja del '
                . '<strong>dinero</strong> <strong>no salen nunca</strong> en la guía — se las '
                . 'envía un operador con una plantilla cuando hace falta.'
            );
    }

    public function configureFields(string $pageName): iterable
    {
        $pathRelativo = Parametro::texto(
            $this->params->get('pms.path.establecimiento_images'),
            'pms.path.establecimiento_images'
        );
        $basePath = '/' . ltrim($pathRelativo, '/');

        // `attr.required` además de `setRequired()`: es el atributo del widget el que le quita la
        // ✕ de limpiar. Sin él se ofrece vaciar un campo que no admite vacío.
        yield AssociationField::new('establecimiento', 'Establecimiento')
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setSortable(true);

        yield ChoiceField::new('tipo', 'Tipo')
            ->setChoices(PmsEstablecimientoMediaTipo::opciones())
            ->setRequired(true)
            ->setFormTypeOption('attr', ['required' => true])
            ->setSortable(true)
            ->setHelp('En la guía se piden con <code>{{ video_caja_llaves }}</code> y '
                . '<code>{{ foto_caja_llaves }}</code>. Los del dinero no tienen marcador de guía: '
                . 'viajan por plantilla.');

        yield LiipImageField::new('imageUrl', 'Vista previa')
            ->onlyOnIndex()
            ->setSortable(false);

        yield TextField::new('imageFile', 'Archivo (foto)')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms()
            ->setColumns(12)
            ->setHelp('Sólo para los tipos de foto. Un vídeo va en el campo de abajo.');

        yield UrlField::new('url', 'URL de YouTube')
            ->setColumns(12)
            ->setHelp('Sólo para los tipos de vídeo. Tiene que ser de YouTube: es lo que el front '
                . 'sabe incrustar.');

        yield IntegerField::new('orden', 'Orden')
            ->setColumns(3)
            ->hideOnIndex();

        yield ImageField::new('imageName', 'Archivo')
            ->setBasePath($basePath)
            ->onlyOnDetail();
    }
}
