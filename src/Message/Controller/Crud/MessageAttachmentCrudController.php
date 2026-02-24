<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageAttachment;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichFileType;

class MessageAttachmentCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return MessageAttachment::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::NEW) // Los adjuntos se crean desde el Chat o Webhook
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('message', 'Mensaje Original');

        yield TextField::new('file', 'Archivo Físico')
            ->setFormType(VichFileType::class)
            ->onlyOnForms();

        yield ImageField::new('fileName', 'Vista Previa')
            ->setBasePath('/uploads/chat_attachments')
            ->onlyOnIndex();

        yield TextField::new('originalName', 'Nombre Original');
        yield TextField::new('mimeType', 'Tipo MIME');
        yield IntegerField::new('fileSize', 'Tamaño (Bytes)')->formatValue(fn($v) => round($v / 1024, 2) . ' KB');
    }
}