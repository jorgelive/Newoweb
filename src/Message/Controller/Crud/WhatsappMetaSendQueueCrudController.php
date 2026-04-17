<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\WhatsappMetaSendQueue;
use App\Panel\Controller\Crud\BaseCrudController;
use App\Security\Roles;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * WhatsappMetaSendQueueCrudController.
 * Gestión de la cola de salida de mensajes vía WhatsApp Meta API.
 * Optimizado para seguimiento de estados de entrega y auditoría de payloads.
 */
final class WhatsappMetaSendQueueCrudController extends BaseCrudController
{
    /**
     * Constructor de la clase WhatsappMetaSendQueueCrudController.
     * * @param AdminUrlGenerator $adminUrlGenerator Generador de URLs para EasyAdmin.
     * @param RequestStack      $requestStack      Pila de peticiones de Symfony.
     */
    public function __construct(
        protected AdminUrlGenerator $adminUrlGenerator,
        protected RequestStack $requestStack
    ) {
        parent::__construct($adminUrlGenerator, $requestStack);
    }

    /**
     * Devuelve el FQCN de la entidad gestionada por este controlador.
     */
    public static function getEntityFqcn(): string
    {
        return WhatsappMetaSendQueue::class;
    }

    /**
     * Configuración del CRUD.
     * Se mantiene la búsqueda nativa en campos de primer nivel y se delega
     * la búsqueda profunda (nombre del cliente) al QueryBuilder personalizado.
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola WhatsApp')
            ->setEntityLabelInPlural('Cola WhatsApp (Meta)')
            ->setSearchFields(['destinationPhone', 'status', 'deliveryStatus', 'wamId'])
            // ✅ Ordenado por actualización más reciente
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    /**
     * Configuración de acciones.
     * Activamos Detail y deshabilitamos edición/creación manual para proteger la auditoría.
     */
    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);

        return parent::configureActions($actions)
            ->setPermission(Action::INDEX, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DETAIL, Roles::MENSAJES_SHOW)
            ->setPermission(Action::DELETE, Roles::MENSAJES_DELETE);
    }

    /**
     * Sobrescribe la consulta base para el Index (Listado) y la búsqueda global.
     * Intercepta el QueryBuilder para hacer los JOIN explícitos hacia la Conversación
     * permitiendo buscar por el nombre del cliente sin desencadenar excepciones de Doctrine.
     *
     * @param SearchDto        $searchDto DTO con el término ingresado en la barra superior.
     * @param EntityDto        $entityDto Metadatos de la entidad actual.
     * @param FieldCollection  $fields    Campos configurados en el CRUD.
     * @param FilterCollection $filters   Filtros aplicados desde el panel lateral.
     * * @return QueryBuilder
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $aliases = $qb->getAllAliases();

        // Unimos el mensaje original
        if (!in_array('msg', $aliases, true)) {
            $qb->leftJoin('entity.message', 'msg');
        }

        // Unimos la conversación asociada al mensaje
        if (!in_array('conv', $aliases, true)) {
            $qb->leftJoin('msg.conversation', 'conv');
        }

        // Si hay un término de búsqueda, agregamos la condición sobre el nombre del huésped
        if (null !== $searchDto->getQuery() && $searchDto->getQuery() !== '') {
            $searchTerm = '%' . $searchDto->getQuery() . '%';

            $qb->orWhere('conv.guestName LIKE :custom_search_guest')
                ->setParameter('custom_search_guest', $searchTerm);
        }

        return $qb;
    }

    /**
     * Configuración de los campos expuestos en las diferentes vistas de EasyAdmin.
     */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'UUID')
            ->onlyOnDetail();

        // --- PANEL 1: DESTINO Y ESTADO ---
        yield FormField::addPanel('Estado del Envío')->setIcon('fa fa-whatsapp');

        // ✅ Nuevo campo: Mostrar nombre del cliente basado en la relación profunda (Queue -> Message -> Conversation)
        yield TextField::new('message.conversation.guestName', 'Cliente')
            ->setColumns(6)
            ->hideOnForm();

        yield TextField::new('destinationPhone', 'Destinatario')
            ->setColumns(6);

        yield ChoiceField::new('status', 'Estado Worker')
            ->setChoices([
                'Pendiente' => 'pending',
                'Éxito' => 'success',
                'Fallido' => 'failed',
            ])
            ->renderAsBadges([
                'pending' => 'warning',
                'success' => 'success',
                'failed' => 'danger'
            ])
            ->setColumns(6);

        yield ChoiceField::new('deliveryStatus', 'WhatsApp Status (Meta)')
            ->setChoices([
                'Submitted' => 'submitted',
                'Delivered' => 'delivered',
                'Read' => 'read',
                'Failed' => 'failed'
            ])
            ->renderAsBadges([
                'read' => 'info',
                'delivered' => 'primary',
                'submitted' => 'secondary',
                'failed' => 'danger'
            ])
            ->setColumns(6);

        yield DateTimeField::new('runAt', 'Programado para')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setColumns(6);

        yield TextField::new('wamId', 'WhatsApp Message ID (WAMID)')
            ->onlyOnDetail();

        // --- PANEL 2: RELACIONES ---
        yield FormField::addPanel('Relaciones')->setIcon('fa fa-link');
        yield AssociationField::new('message', 'Mensaje PMS');

        // Exponemos la conversación completa en la vista de detalle
        yield AssociationField::new('message.conversation', 'Conversación Completa')
            ->onlyOnDetail();

        yield AssociationField::new('config', 'Configuración Meta');

        // --- PANEL 3: AUDITORÍA TÉCNICA ---
        yield FormField::addPanel('Trazabilidad y Payloads')
            ->setIcon('fa fa-terminal')
            ->onlyOnDetail();

        yield IntegerField::new('retryCount', 'Intentos realizados');
        yield TextField::new('failedReason', 'Razón del Fallo');

        yield CodeEditorField::new('lastRequestRaw', 'Payload Enviado (JSON)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Respuesta API Meta')
            ->setLanguage('js')
            ->onlyOnDetail();

        // --- PANEL DE SISTEMA ---
        yield FormField::addPanel('Tiempos de Sistema')->setIcon('fa fa-clock')->renderCollapsed();

        yield DateTimeField::new('createdAt', 'Creado')
            ->hideOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);

        yield DateTimeField::new('updatedAt', 'Actualizado')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);
    }
}