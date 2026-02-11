<?php

declare(strict_types=1);

namespace App\Pms\Controller\Crud;

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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
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

        // 1. CONFIGURACIÓN
        yield FormField::addPanel('Configuración')->setIcon('fa fa-cog');

        yield TextField::new('nombreInterno', 'Nombre Interno (Admin)')
            ->setHelp('Identificador para ti. Ej: "Wifi Lobby" o "Manual Cafetera"')
            ->setColumns(8);

        yield ChoiceField::new('tipo', 'Formato Visual')
            ->setChoices([
                '📄 Tarjeta (Texto + Video + Fotos)' => PmsGuiaItem::TIPO_TARJETA,
                '📸 Álbum (Solo Fotos)'              => PmsGuiaItem::TIPO_ALBUM,
                '⚠️ Aviso Importante'                => PmsGuiaItem::TIPO_AVISO,
            ])
            ->setRequired(true)
            ->setColumns(4);

        // 2. CONTENIDO
        yield FormField::addPanel('Contenido Dinámico')
            ->setIcon('fa fa-align-left');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield CollectionField::new('descripcion', 'Cuerpo / Instrucciones')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12)
            ->setHelp('
                <div class="small text-muted mt-2" style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #dee2e6;">
                    <strong class="text-primary"><i class="fas fa-magic"></i> Variables Dinámicas:</strong>
                    <p class="mb-2" style="font-size: 0.85em;">Copia y pega estos códigos. El sistema los reemplazará por los datos reales de la reserva.</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-family: monospace; font-size: 1.1em;">
                        <div>
                            <div class="text-dark fw-bold mb-1">📅 Reserva & Info</div>
                            <div><code>{{ guest_name }}</code> : Nombre Huésped</div>
                            <div><code>{{ booking_ref }}</code> : Localizador</div>
                            <div><code>{{ unit_name }}</code> : Nombre Casita/Hab</div>
                            <div><code>{{ hotel_name }}</code> : Nombre Hotel</div>
                            <div class="mt-1"><code>{{ check_in }}</code> : Hora Entrada</div>
                            <div><code>{{ check_out }}</code> : Hora Salida</div>
                            <div><code>{{ start_date }}</code> : Fecha Llegada</div>
                            <div><code>{{ end_date }}</code> : Fecha Salida</div>
                        </div>

                        <div>
                            <div class="text-dark fw-bold mb-1">🔐 Acceso & Seguridad</div>
                            <div><code>{{ door_code }}</code> : Código Puerta</div>
                            <div><code>{{ safe_code }}</code> : Caja Fuerte</div>
                            <div><code>{{ keybox_main }}</code> : Caja Llaves (P)</div>
                            <div><code>{{ keybox_sec }}</code> : Caja Llaves (S)</div>
                            
                            <div class="text-dark fw-bold mt-2 mb-1">📶 Conectividad</div>
                            <div><code>{{ wifi_ssid }}</code> : Red WiFi</div>
                            <div><code>{{ wifi_pass }}</code> : Clave WiFi</div>
                        </div>
                    </div>

                    <hr class="my-2">
                    
                    <strong class="text-danger"><i class="fab fa-youtube"></i> Insertar Video:</strong><br>
                    Usa este formato exacto en cualquier parte del texto:<br>
                    <code style="font-size:1.1em; color:#d63384;">{{ video:https://www.youtube.com/shorts/TU_ID }}</code>
                </div>
            ');

        // 3. BOTÓN DE ACCIÓN (CON LA NUEVA LÓGICA)
        yield FormField::addPanel('Botón de Acción (Opcional)')
            ->setIcon('fa fa-link');

        yield CollectionField::new('labelBoton', 'Texto Botón')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(8)
            ->setHelp('Ej: "Ver Mapa", "Abrir WhatsApp"');

        // 🔥 AQUÍ ESTÁ EL NUEVO CAMPO (Se guarda en metadata)
        yield TextField::new('urlBoton', 'URL o Acción')
            ->setColumns(4)
            ->setHelp('Web: <code>https://...</code> | Tel: <code>tel:+51...</code> | Wsp: <code>https://wa.me/...</code>');

        // 4. GALERÍA
        yield FormField::addPanel('Galería de Fotos')
            ->setIcon('fa fa-images')
            ->setHelp('Sube fotos aquí para crear un carrusel o grilla.');

        yield CollectionField::new('galeria', 'Fotos')
            ->useEntryCrudForm(PmsGuiaItemGaleriaCrudController::class)
            ->setFormTypeOption('prototype_data', new PmsGuiaItemGaleria())
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->setColumns(12);
        
        yield FormField::addPanel('Auditoría')->setIcon('fa fa-shield-alt')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true); // Visible pero readonly en form

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->hideOnIndex()
            ->setFormat('yyyy/MM/dd HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}