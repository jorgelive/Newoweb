<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

use App\Panel\Form\Type\VideoItemType;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaItemGaleria;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PmsGuiaItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PmsGuiaItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Ítem de Contenido')
            ->setEntityLabelInPlural('Biblioteca de Ítems')
            ->setSearchFields(['id', 'nombreInterno', 'titulo', 'tipo'])
            ->setDefaultSort(['updatedAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        return $actions
            ->setPermission(Action::INDEX, Roles::RESERVAS_SHOW)
            ->setPermission(Action::DETAIL, Roles::RESERVAS_SHOW)
            ->setPermission(Action::NEW, Roles::RESERVAS_WRITE)
            ->setPermission(Action::EDIT, Roles::RESERVAS_WRITE)
            ->setPermission(Action::DELETE, Roles::RESERVAS_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        // ============================================================
        // 1. CONFIGURACIÓN GENERAL
        // ============================================================
        yield FormField::addPanel('Configuración')->setIcon('fa fa-cog');

        yield TextField::new('nombreInterno', 'Nombre Interno (Admin)')
            ->setHelp('Identificador para ti. Ej: "Wifi Lobby" o "Manual Cafetera"')
            ->setColumns(8);

        yield ChoiceField::new('tipo', 'Formato Visual')
            ->setChoices([
                '📄 Tarjeta (Texto + Video + Fotos)' => PmsGuiaItem::TIPO_TARJETA,
                '📸 Álbum (Solo Fotos)'              => PmsGuiaItem::TIPO_ALBUM,
                '📍 Ubicación (GPS)'                 => PmsGuiaItem::TIPO_LOCATION,
                '📶 WiFi (Visual)'                   => PmsGuiaItem::TIPO_WIFI,
                '📞 Contacto'                        => PmsGuiaItem::TIPO_CONTACTO,
            ])
            ->setRequired(true)
            ->setColumns(4);

        // ============================================================
        // 2. CAMPOS ESPECÍFICOS (METADATA)
        // ============================================================

        // --- 📍 UBICACIÓN (LOCATION) ---
        yield FormField::addPanel('Datos de Ubicación')
            ->setIcon('fa fa-map-marked-alt')
            ->renderCollapsed()
            ->setHelp('Llenar solo si el tipo es "Ubicación".');

        yield TextField::new('locationAddress', 'Dirección Visual')
            ->setColumns(12)
            ->setHelp('La dirección escrita que leerá el huésped. Ej: "Calle San Agustín 307".');

        yield FormField::addRow();

        yield TextField::new('locationLat', 'Latitud')
            ->setColumns(3)->setHelp('Ej: -13.51686');
        yield TextField::new('locationLng', 'Longitud')
            ->setColumns(3)->setHelp('Ej: -71.97935');
        yield TextField::new('locationLink', 'Link Externo (Opcional)')
            ->setColumns(6)->setHelp('Deja vacío para auto-generar Waze/Maps.');

        // --- [SECCIÓN WIFI ELIMINADA] ---
        // Los datos de WiFi se inyectan dinámicamente vía variables {{ wifi_pass }}

        // --- 📞 CONTACTO ---
        yield FormField::addPanel('Datos de Contacto')
            ->setIcon('fa fa-address-card')
            ->setHelp('Llenar solo si el tipo es "Contacto".')
            ->renderCollapsed();

        yield TextField::new('contactoWhatsapp', 'WhatsApp')
            ->setHelp('Ej: 51984000000')
            ->setColumns(6);
        yield TextField::new('contactoEmail', 'Email')
            ->setColumns(6);

        // ============================================================
        // 3. VIDEOS PARA MANUALES (Embeds)
        // ============================================================
        yield FormField::addPanel('Videos para Manuales')
            ->setIcon('fa fa-play-circle')
            ->renderCollapsed()
            ->setHelp('Sube videos aquí y úsalos en el texto con <b>{{ video1 }}</b>, <b>{{ video2 }}</b>.');

        yield CollectionField::new('videos', 'Lista de Videos')
            ->setEntryType(VideoItemType::class)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex(true)
            ->renderExpanded()
            ->setColumns(12);

        // ============================================================
        // 4. CONTENIDO TEXTUAL (Con Ayuda de Variables)
        // ============================================================
        yield FormField::addPanel('Contenido')->setIcon('fa fa-align-left');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield CollectionField::new('descripcion', 'Cuerpo')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12)
            ->setHelp('
                <div class="small text-muted mt-2" style="background:#f8f9fa; padding:10px; border-radius:5px; border:1px solid #dee2e6;">
                    <strong class="text-indigo-600"><i class="fas fa-shield-alt"></i> Variables Protegidas (Inglés Técnico):</strong>
                    <p class="mb-1" style="font-size: 0.85em;">Usa <b>doble llave</b> para evitar errores de traducción automática.</p>
                    <ul class="mb-0 ps-3" style="column-count: 2; font-family: monospace; font-size: 1.1em;">
                        <li><code>{{ door_code }}</code> : Puerta Principal</li>
                        <li><code>{{ safe_code }}</code> : Caja Fuerte</li>
                        <li><code>{{ keybox_main }}</code> : Caja Llaves (Principal)</li>
                        <li><code>{{ keybox_sec }}</code> : Caja Llaves (Sec)</li>
                        
                        <li><code>{{ wifi_pass }}</code> : Password Wifi</li>
                        <li><code>{{ wifi_ssid }}</code> : Nombre Wifi</li>
                        
                        <li><code>{{ guest_name }}</code> : Nombre Huésped</li>
                        <li><code>{{ booking_ref }}</code> : Localizador</li>
                        <li><code>{{ check_in }}</code> / <code>{{ check_out }}</code></li>

                        <li><code>{{ video1 }}</code> : Insertar Video 1</li>
                    </ul>
                </div>
            ');

        yield CollectionField::new('labelBoton', 'Texto Botón')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        // ============================================================
        // 5. GALERÍA DE FOTOS (Visual)
        // ============================================================
        yield FormField::addPanel('Galería de Fotos')
            ->setIcon('fa fa-images')
            ->setHelp('Fotos decorativas para carrusel o grilla.');

        yield CollectionField::new('galeria', 'Fotos')
            ->useEntryCrudForm(PmsGuiaItemGaleriaCrudController::class)
            ->setFormTypeOption('prototype_data', new PmsGuiaItemGaleria())
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->setColumns(12);
    }
}