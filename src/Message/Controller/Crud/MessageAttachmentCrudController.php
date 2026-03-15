<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\MessageAttachment;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Field\LiipImageField;
use App\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Form\Type\VichFileType;

class MessageAttachmentCrudController extends BaseCrudController
{
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack,
        private readonly ParameterBagInterface $params
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

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

        // --- COLUMNA 1: VISTA PREVIA (Usa LiipImageField y el MediaTrait) ---
        yield LiipImageField::new('fileUrl', 'Vista Previa')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(function ($value, $entity) {
                if ($entity instanceof MessageAttachment) {
                    // Usamos el isImage() propio de la entidad que lee el MimeType (más seguro que la extensión)
                    if (!$entity->isImage()) {
                        // Si es PDF, Word, etc., delegamos al MediaTrait para obtener tu icono personalizado
                        return $entity->getIconPathFor($entity->getFileName());
                    }
                }
                return $value;
            });

        // --- COLUMNA 2: SUBIDA DE ARCHIVO (Físico) ---
        yield TextField::new('file', 'Archivo Físico')
            // Usamos VichFileType (y no VichImageType) porque aquí sí permitimos PDFs, DOCXs, etc.
            ->setFormType(VichFileType::class)
            ->setFormTypeOptions([
                'allow_delete' => true,
                'download_uri' => true, // Habilita un link de descarga en EasyAdmin si no es imagen
            ])
            ->onlyOnForms();

        yield TextField::new('originalName', 'Nombre Original');

        yield TextField::new('mimeType', 'Tipo MIME');

        yield IntegerField::new('fileSize', 'Tamaño (Bytes)')
            ->formatValue(fn($v) => round($v / 1024, 2) . ' KB');
    }
}