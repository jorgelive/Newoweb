<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Pms\Entity\PmsGuiaItem;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PmsGuiaItemCrudController.
 * Gestión detallada de ítems de contenido para las guías del sistema.
 * Hereda de BaseCrudController y aplica seguridad por Roles prioritarios.
 */
class PmsGuiaItemCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return PmsGuiaItem::class;
    }

    /**
     * Configuración de acciones y permisos.
     * ✅ Se aplica la herencia y LUEGO se imponen los permisos de Roles para prioridad absoluta.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        // Primero obtenemos la configuración global del panel
        $actions = parent::configureActions($actions);

        // Aplicamos los permisos después para que la clase Roles sea la "Fuente de Verdad"
        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ítem de Contenido')
            ->setEntityLabelInPlural('Ítems de Contenido')
            ->setDefaultSort(['orden' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // ✅ Manejo de UUID (IdTrait) para visualización técnica
        yield TextField::new('id', 'UUID')
            ->onlyOnDetail()
            ->formatValue(static fn($value) => (string) $value);

        // --- BLOQUE 1: CLASIFICACIÓN ---
        yield FormField::addPanel('Clasificación')->setIcon('fa fa-tags');

        yield ChoiceField::new('tipo', 'Tipo de Contenido')
            ->setChoices([
                'Tarjeta Informativa' => PmsGuiaItem::TIPO_TARJETA,
                'WiFi (Claves)'       => PmsGuiaItem::TIPO_WIFI,
                'Galería / Álbum'    => PmsGuiaItem::TIPO_ALBUM,
                'Video Tutorial'     => PmsGuiaItem::TIPO_VIDEO,
                'Ubicación / Mapa'    => PmsGuiaItem::TIPO_MAPA,
                'Contacto Directo'    => PmsGuiaItem::TIPO_CONTACTO,
                'Servicio de Pago'    => PmsGuiaItem::TIPO_SERVICIO,
            ])
            ->renderAsBadges();

        yield NumberField::new('orden', 'Orden de Aparición')
            ->setHelp('Posición relativa dentro de su sección.');

        // --- BLOQUE 2: TRADUCCIÓN ---
        yield FormField::addPanel('Traducción Automática')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Disparar Traducción')
            ->setHelp('Sincronizará con Google Translate al guardar.')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE);

        // --- BLOQUE 3: TEXTOS MULTIIDIOMA ---
        yield FormField::addPanel('Contenidos Multilingües')->setIcon('fa fa-file-alt');

        yield CollectionField::new('titulo', 'Títulos (es, en, pt...)')
            ->setHelp('Se requiere la llave "es" para el motor de traducción.');

        yield CollectionField::new('descripcion', 'Descripción (HTML)')
            ->hideOnIndex();

        yield CollectionField::new('labelBoton', 'Etiqueta del Botón')
            ->hideOnIndex();

        // --- BLOQUE 4: INTEGRACIONES ---
        yield FormField::addPanel('Datos Complementarios')->setIcon('fa fa-plus-circle');

        yield AssociationField::new('maestroContacto', 'Contacto Vinculado')
            ->setRequired(false);

        // ✅ Se mantiene el uso de moneda.id ya que MaestroMoneda usa el código (PEN, USD) como ID
        yield MoneyField::new('precio', 'Precio del Servicio')
            ->setCurrencyPropertyPath('moneda.id')
            ->setRequired(false)
            ->hideOnIndex();

        yield AssociationField::new('moneda', 'Moneda')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addPanel('Galería de Imágenes')->setIcon('fa fa-images');

        yield CollectionField::new('galeria', 'Imágenes / Fotos')
            ->useEntryCrudForm()
            ->setHelp('Añada fotos para ítems de tipo Álbum.');

        // --- BLOQUE 5: AUDITORÍA (TimestampTrait) ---
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-clock')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Registrado')
            ->onlyOnDetail();

        yield DateTimeField::new('updatedAt', 'Última actualización')
            ->onlyOnDetail();
    }
}