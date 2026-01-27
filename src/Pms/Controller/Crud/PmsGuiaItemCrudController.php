<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Pms\Entity\PmsGuiaItem;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Controlador para la gestión detallada de ítems de la guía.
 * Soporta múltiples tipos de contenido y traducción automática controlada.
 */
class PmsGuiaItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsGuiaItem::class;
    }

    /**
     * Configuración de permisos según la fuente única de verdad (Roles).
     */
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            // Visualización: Lectura para reservas [cite: 2026-01-14]
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)

            // Gestión: Escritura y borrado según roles específicos [cite: 2026-01-14]
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
        yield IdField::new('id')->hideOnForm();

        // --- BLOQUE 1: IDENTIFICACIÓN Y TIPO ---
        yield FormField::addPanel('Clasificación');

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
            ->setHelp('Define la posición dentro de la sección.');

        // --- BLOQUE 2: CONTROL DE TRADUCCIÓN ---
        yield FormField::addPanel('Traducción Global');

        yield BooleanField::new('ejecutarTraduccion', 'Disparar Google Translate')
            ->setHelp('Traducirá título, descripción y labels si se marca al guardar.')
            ->onlyOnForms()
            ->setPermission(Roles::RESERVAS_WRITE);

        // --- BLOQUE 3: TEXTOS MULTIIDIOMA ---
        yield FormField::addPanel('Textos (JSON)');

        yield CollectionField::new('titulo', 'Título (es, en, pt...)')
            ->setHelp('Obligatorio: Llave "es" para traducir automáticamente.');

        yield CollectionField::new('descripcion', 'Descripción (HTML)')
            ->setHelp('Soporta etiquetas HTML. Google respetará el formato.')
            ->hideOnIndex();

        yield CollectionField::new('labelBoton', 'Etiqueta del Botón')
            ->setHelp('Texto para el botón de acción (opcional).')
            ->hideOnIndex();

        // --- BLOQUE 4: INTEGRACIONES Y MEDIA ---
        yield FormField::addPanel('Datos Complementarios');

        yield AssociationField::new('maestroContacto', 'Contacto Vinculado')
            ->setHelp('Asocie un contacto del sistema para tipos "Contacto".')
            ->setRequired(false);

        yield MoneyField::new('precio', 'Precio del Servicio')
            ->setCurrencyPropertyPath('moneda.codigo') // Asumiendo que MaestroMoneda tiene getCodigo()
            ->setRequired(false)
            ->hideOnIndex();

        yield AssociationField::new('moneda', 'Moneda')
            ->setRequired(false)
            ->hideOnIndex();

        yield FormField::addPanel('Galería de Imágenes');

        yield CollectionField::new('galeria', 'Imágenes / Fotos')
            ->useEntryCrudForm() // Requiere PmsGuiaItemGaleriaCrudController
            ->setHelp('Añada fotos para ítems de tipo Álbum.');
    }
}