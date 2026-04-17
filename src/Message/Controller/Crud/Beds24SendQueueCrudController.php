<?php

declare(strict_types=1);

namespace App\Message\Controller\Crud;

use App\Message\Entity\Beds24SendQueue;
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
 * Beds24SendQueueCrudController.
 * Gestión de la cola de salida de mensajes hacia Beds24.
 * Ordenado por actualización para seguimiento de logs en tiempo real.
 */
class Beds24SendQueueCrudController extends BaseCrudController
{
    /**
     * Constructor de la clase Beds24SendQueueCrudController.
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
        return Beds24SendQueue::class;
    }

    /**
     * Configuración de acciones.
     * Mantiene la restricción de no creación/edición manual para integridad de la cola.
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
     * Configuración del CRUD.
     * Se establece el orden por updatedAt DESC para ver lo último procesado arriba.
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cola Beds24')
            ->setEntityLabelInPlural('Colas Beds24')
            // OJO: 'message.conversation.guestName' se eliminó de aquí porque causa
            // un "[Semantical Error]" en Doctrine. La búsqueda por nombre se maneja
            // manualmente en createIndexQueryBuilder().
            ->setSearchFields(['targetBookId', 'status', 'failedReason'])
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    /**
     * Sobrescribe la consulta base para el Index (Listado) y la búsqueda global.
     * * Doctrine no soporta buscar en propiedades anidadas a dos niveles de profundidad
     * (Queue -> Message -> Conversation) dentro de setSearchFields() sin lanzar una excepción.
     * Para solventarlo, interceptamos el QueryBuilder, hacemos los JOIN explícitos y
     * validamos si el usuario ingresó texto en la barra de búsqueda para filtrar por el cliente.
     *
     * @param SearchDto        $searchDto DTO con el término ingresado en la barra superior.
     * @param EntityDto        $entityDto Metadatos de la entidad actual.
     * @param FieldCollection  $fields    Campos configurados en el CRUD.
     * @param FilterCollection $filters   Filtros aplicados desde el panel lateral.
     * * @return QueryBuilder
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // Obtenemos la consulta original generada por EasyAdmin (que ya incluye la búsqueda básica de setSearchFields)
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        // Agregamos explícitamente los JOINs necesarios para navegar hasta la Conversación.
        // Verificamos que no existan para evitar colisiones si EasyAdmin los añade en un futuro.
        $aliases = $qb->getAllAliases();

        if (!in_array('msg', $aliases, true)) {
            $qb->leftJoin('entity.message', 'msg');
        }

        if (!in_array('conv', $aliases, true)) {
            $qb->leftJoin('msg.conversation', 'conv');
        }

        // Si el usuario ingresó un texto en la barra de búsqueda superior, añadimos la condición manualmente.
        if (null !== $searchDto->getQuery() && $searchDto->getQuery() !== '') {
            $searchTerm = '%' . $searchDto->getQuery() . '%';

            // Usamos orWhere para sumar esta condición a las que ya generó EasyAdmin
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
        yield IdField::new('id')
            ->setMaxLength(40)
            ->hideOnForm();

        // --- PANEL 1: ESTADO DEL ENVÍO ---
        yield FormField::addPanel('Estado del Envío')->setIcon('fa fa-exchange-alt');

        // Renderizado de propiedad profunda: Funciona sin problemas gracias a PropertyAccess
        yield TextField::new('message.conversation.guestName', 'Cliente')
            ->setColumns(6)
            ->hideOnForm();

        yield TextField::new('targetBookId', 'ID Reserva (Beds24)')
            ->setColumns(6);

        yield ChoiceField::new('status', 'Estado Worker')
            ->setChoices([
                'Pendiente' => Beds24SendQueue::STATUS_PENDING,
                'Procesando' => Beds24SendQueue::STATUS_PROCESSING,
                'Completado' => Beds24SendQueue::STATUS_SUCCESS,
                'Fallido' => Beds24SendQueue::STATUS_FAILED,
                'Cancelado' => Beds24SendQueue::STATUS_CANCELLED,
            ])
            ->renderAsBadges([
                Beds24SendQueue::STATUS_PENDING => 'warning',
                Beds24SendQueue::STATUS_PROCESSING => 'info',
                Beds24SendQueue::STATUS_SUCCESS => 'success',
                Beds24SendQueue::STATUS_FAILED => 'danger',
                Beds24SendQueue::STATUS_CANCELLED => 'dark',
            ])
            ->setColumns(6);

        yield DateTimeField::new('runAt', 'Programado para')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setColumns(6);

        yield IntegerField::new('retryCount', 'Reintentos')
            ->setColumns(3);

        yield IntegerField::new('lastHttpCode', 'HTTP Code')
            ->setColumns(3);

        // --- PANEL 2: REFERENCIAS ---
        yield FormField::addPanel('Relaciones')->setIcon('fa fa-link');
        yield AssociationField::new('message', 'Mensaje Original');
        yield AssociationField::new('config', 'Configuración PMS');
        yield AssociationField::new('endpoint', 'Endpoint Utilizado');

        // --- PANEL 3: TRAZA TÉCNICA ---
        yield FormField::addPanel('Auditoría Técnica (JSON/Raw)')
            ->setIcon('fa fa-code')
            ->renderCollapsed();

        yield TextField::new('failedReason', 'Razón del Fallo')->onlyOnDetail();

        yield CodeEditorField::new('executionResult', 'Resultado Ejecución (JSON)')
            ->setLanguage('js')
            ->onlyOnDetail()
            ->formatValue(fn ($value) => $value ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '');

        yield CodeEditorField::new('lastRequestRaw', 'Último Request (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        yield CodeEditorField::new('lastResponseRaw', 'Última Respuesta (Raw)')
            ->setLanguage('js')
            ->onlyOnDetail();

        // --- PANEL DE AUDITORÍA DE SISTEMA ---
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