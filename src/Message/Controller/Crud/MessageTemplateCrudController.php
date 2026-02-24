<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageTemplate;
use App\Message\Form\Type\Beds24TemplateType;
use App\Message\Form\Type\EmailTemplateType;
use App\Message\Form\Type\WhatsappGupshupTemplateType;
use App\Message\Form\Type\WhatsappLinkTemplateType;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MessageTemplateCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return MessageTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $crud = parent::configureCrud($crud);

        return $crud
            ->setEntityLabelInSingular('Plantilla')
            ->setEntityLabelInPlural('Plantillas de Mensaje')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gestión de Plantillas')
            ->showEntityActionsInlined();
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addCssFile('panel/styles/message/message_template/flat-collection.css');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL);

        $actions = parent::configureActions($actions);

        $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setIcon('fa fa-plus')->setLabel('Crear Plantilla'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action->setIcon('fa fa-edit')->setLabel('Editar'))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $action) => $action->setIcon('fa fa-eye')->setLabel('Ver'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => $action->setIcon('fa fa-trash-alt')->setLabel('Eliminar'))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel('Guardar Plantilla'))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel('Guardar Cambios'));

        return $actions
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::NEW, Roles::MENSAJES_WRITE)
            ->setPermission(Action::EDIT, Roles::MENSAJES_WRITE)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        // --- PANEL 1: GENERAL (Se mantiene abierto por ser la info principal) ---
        yield FormField::addPanel('Información General')
            ->setIcon('fa fa-info-circle');

        yield TextField::new('code', 'Código Interno')
            ->setColumns(12)
            ->setHelp('Llave única para el sistema. <b>No usar espacios, tildes ni mayúsculas</b> (ej: <code>booking_confirmation</code>).');

        yield TextField::new('name', 'Nombre Comercial')
            ->setColumns(12)
            ->setHelp('Nombre descriptivo y amigable para el equipo (ej: <b>Confirmación de Reserva Oficial</b>).');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Auto')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir')->onlyOnForms()->setColumns(6);


        // --- PANEL 2: WHATSAPP / META ---
        yield FormField::addPanel('Configuración WhatsApp / Meta')
            ->setIcon('fab fa-whatsapp')
            ->collapsible()
            ->renderCollapsed() // 🔥 Plegado por defecto
            ->setHelp('💡 <b>Nota:</b> Meta utiliza formato numérico para las variables. Utiliza <code>{1}</code>, <code>{2}</code> en los textos de referencia.');

        yield Field::new('whatsappGupshupTmpl', '')
            ->setFormType(WhatsappGupshupTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('whatsappGupshupTmpl', 'JSON Generado WhatsApp (Solo Lectura)')
            ->setLanguage('js')
            ->onlyOnDetail()
            ->formatValue(function ($value) {
                if (empty($value)) return '';
                return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;
            });


        // --- PANEL 3: BEDS24 ---
        yield FormField::addPanel('Configuración Beds24 / OTAs')
            ->setIcon('fa fa-bed')
            ->collapsible()
            ->renderCollapsed() // 🔥 Plegado por defecto
            ->setHelp('💡 <b>Variables Universales:</b> Utiliza el formato <code>{{ nombre_variable }}</code> (ej: <code>Hola {{ guest_name }}</code>). El sistema lo mapeará automáticamente para Beds24.');

        yield Field::new('beds24Tmpl', '')
            ->setFormType(Beds24TemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('beds24Tmpl', 'JSON Generado Beds24 (Solo Lectura)')
            ->setLanguage('js')
            ->onlyOnDetail()
            ->formatValue(function ($value) {
                if (empty($value)) return '';
                return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;
            });


        // --- PANEL 4: WHATSAPP LINK MANUAL ---
        yield FormField::addPanel('Configuración Enlace WhatsApp (Manual)')
            ->setIcon('fa fa-external-link-alt')
            ->collapsible()
            ->renderCollapsed() // 🔥 Plegado por defecto
            ->setHelp('💡 <b>Variables Universales:</b> Utiliza el formato <code>{{ nombre_variable }}</code>. Estos textos generan botones (wa.me) para envíos manuales desde el panel.');

        yield Field::new('whatsappLinkTmpl', '')
            ->setFormType(WhatsappLinkTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('whatsappLinkTmpl', 'JSON Generado Link Manual (Solo Lectura)')
            ->setLanguage('js')
            ->onlyOnDetail()
            ->formatValue(function ($value) {
                if (empty($value)) return '';
                return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;
            });


        // --- PANEL 5: EMAIL ---
        yield FormField::addPanel('Configuración Correo Electrónico')
            ->setIcon('fa fa-envelope')
            ->collapsible()
            ->renderCollapsed() // 🔥 Plegado por defecto
            ->setHelp('💡 <b>Variables Universales:</b> Utiliza el formato <code>{{ nombre_variable }}</code> tanto en el Asunto del correo como dentro del diseño HTML.');

        yield Field::new('emailTmpl', '')
            ->setFormType(EmailTemplateType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CodeEditorField::new('emailTmpl', 'JSON Generado Email (Solo Lectura)')
            ->setLanguage('js')
            ->onlyOnDetail()
            ->formatValue(function ($value) {
                if (empty($value)) return '';
                return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;
            });


        // --- PANEL 6: AUDITORÍA ---
        yield FormField::addPanel('Auditoría')
            ->setIcon('fa fa-shield-alt')
            ->collapsible()
            ->renderCollapsed()
            ->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Creado')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizado')->onlyOnDetail();
    }
}