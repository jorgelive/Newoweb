<?php

namespace App\Admin;

use App\Entity\ReservaUnitcaracteristica;
use App\Entity\ReservaUnitmedio;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\FieldDescription\FieldDescriptionInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\TranslationBundle\Filter\TranslationFieldFilter;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class ReservaUnitmedioAdmin extends AbstractAdmin
{
    public function configure(): void
    {
        $this->classnameLabel = 'Multimedia';
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE] = 1;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY] = 'id';
    }

    /**
     * Mantiene ?caracteristica=<id> para filtrar/precargar en create.
     */
    protected function configurePersistentParameters(): array
    {
        if (!$this->getRequest()) {
            return [];
        }

        $cid = $this->getRequest()->get('caracteristica');

        return $cid ? ['caracteristica' => (int) $cid] : [];
    }

    protected function configureDefaultFilterValues(array &$filterValues): void
    {
        $cid = $this->getRequest()?->get('caracteristica');
        if ($cid) {
            $filterValues['unitcaracteristica'] = ['value' => (int) $cid];
        }
    }

    protected function configureActionButtons(array $buttonList, string $action, ?object $object = null): array
    {
        $buttonList['carga'] = ['template' => 'reserva_unitmedio_admin/carga_button.html.twig'];
        return $buttonList;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('unitcaracteristica', null, [
                'label' => 'Característica',
            ])
            ->add('unitcaracteristica.unittipocaracteristica', null, [
                'label' => 'Tipo',
            ])
            ->add('nombre')
            ->add('titulo', TranslationFieldFilter::class, [
                'label' => 'Título',
            ])
            ->add('enlace')
            ->add('prioridad');

        $params = $this->configurePersistentParameters();
        if (isset($params['caracteristica'])) {
            $this->datagridValues = array_merge([
                'unitcaracteristica' => ['value' => (int) $params['caracteristica']],
            ], $this->datagridValues ?? []);
        }
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->add('id')
            ->add('unitcaracteristica', null, [
                'label' => 'Característica',
                'associated_property' => function (?ReservaUnitcaracteristica $c) {
                    return $c ? ($c->getNombre() ?: ('#'.$c->getId())) : '—';
                },
            ])
            ->add('webThumbPath', 'string', [
                'label' => 'Archivo',
                'template' => 'base_sonata_admin/list_image.html.twig',
            ])
            ->add('nombre', null, ['editable' => true])
            ->add('titulo', null, ['label' => 'Título', 'editable' => true])
            ->add('prioridad', null, ['label' => 'Prioridad', 'editable' => true])
            ->add(ListMapper::NAME_ACTIONS, null, [
                'label' => 'Acciones',
                'actions' => [
                    'show' => [],
                    'edit' => [],
                    'delete' => [],
                    'traducir' => [
                        'template' => 'reserva_unitmedio_admin/list__action_traducir.html.twig'
                    ],
                ],
            ]);
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $cid = $this->configurePersistentParameters()['caracteristica'] ?? null;

        if ($this->getRoot()->getClass() != \App\Entity\ReservaUnitcaracteristica::class) {
            $form->add('unitcaracteristica', EntityType::class, [
                'class' => ReservaUnitcaracteristica::class,
                'label' => 'Característica',
                'choice_label' => function (ReservaUnitcaracteristica $c) {
                    $tipo = $c->getUnittipocaracteristica()?->getNombre() ?? '';
                    return trim(($c->getNombre() ?: ('#' . $c->getId())) . ($tipo ? " — {$tipo}" : ''));
                },
                'placeholder' => '— seleccionar —',
                'required' => true,
                'disabled' => (bool) $cid,
            ]);
        }

        // Preparar thumbnail en el form (debajo del campo archivo)
        $subject  = $this->getSubject();
        $thumbUrl = ($subject && method_exists($subject, 'getWebThumbPath')) ? $subject->getWebThumbPath() : null;

// ID único para la <img> de preview
        $imgId = 'unitmedio-thumb-' . $this->getUniqid();

        $previewHtml = $thumbUrl
            ? sprintf(
                '<a href="%1$s" target="_blank" rel="noopener">
            <img id="%2$s" src="%1$s" alt="Vista previa" style="max-width:180px;max-height:180px;object-fit:cover;border-radius:6px;box-shadow:0 0 4px rgba(0,0,0,.15);" />
           </a><br/><small>No hay imagen.</small>',
                htmlspecialchars((string) $thumbUrl, ENT_QUOTES),
                htmlspecialchars($imgId, ENT_QUOTES)
            )
            : sprintf(
                '<img id="%1$s" src="" alt="Vista previa" style="display:none;max-width:180px;max-height:180px;object-fit:cover;border-radius:6px;box-shadow:0 0 4px rgba(0,0,0,.15);" />
         <br/><small>No hay archivo cargado aún.</small>',
                htmlspecialchars($imgId, ENT_QUOTES)
            );

        $form
            ->add('prioridad')
            ->add('archivo', FileType::class, [
                'required'  => false,
                'help'      => $previewHtml,   // 👈 miniatura aquí
                'help_html' => true,
                // 👇 el input sabrá a qué <img> actualizar
                'attr'      => ['data-preview-img' => $imgId],
            ])
            ->add('nombre')
            ->add('titulo', null, ['label' => 'Título'])
            ->add('enlace')
        ;

        $widthModifier = function (FormInterface $form) {
            $form
                ->add('nombre', null, [
                    'label' => 'Nombre',
                    'attr'  => ['style' => 'min-width: 120px;'],
                ])
                ->add('titulo', null, [
                    'label' => 'Título',
                    'attr'  => ['style' => 'min-width: 120px;'],
                ])
                ->add('enlace', null, [
                    'label' => 'Enlace',
                    'attr'  => ['style' => 'min-width: 150px;'],
                ])
                ->add('prioridad', null, [
                    'label' => 'Prioridad',
                    'attr'  => ['style' => 'width: 50px;'],
                ]);
        };

        $formBuilder = $form->getFormBuilder();
        $formBuilder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($widthModifier) {
                if ($event->getData()) {
                    $widthModifier($event->getForm());
                }
            }
        );
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('unitcaracteristica', null, ['label' => 'Característica'])
            ->add('webThumbPath', null, [
                'label' => 'Archivo',
                'template' => 'base_sonata_admin/show_image.html.twig',
            ])
            ->add('enlace')
            ->add('nombre')
            ->add('titulo', null, ['label' => 'Título'])
            ->add('prioridad');
    }

    public function prePersist($object): void
    {
        $this->maybeSetCaracteristicaFromQuery($object);
        $this->manageFileUpload($object);
    }

    public function preUpdate($object): void
    {
        $this->manageFileUpload($object);
    }

    private function maybeSetCaracteristicaFromQuery(ReservaUnitmedio $medio): void
    {
        if ($medio->getUnitcaracteristica()) {
            return;
        }
        $cid = $this->configurePersistentParameters()['caracteristica'] ?? null;
        if ($cid) {
            $car = $this->getModelManager()->find(ReservaUnitcaracteristica::class, (int) $cid);
            if ($car) {
                $medio->setUnitcaracteristica($car);
            }
        }
    }

    private function manageFileUpload(ReservaUnitmedio $medio): void
    {
        if (method_exists($medio, 'getArchivo') && $medio->getArchivo()) {
            if (method_exists($medio, 'refreshModificado')) {
                $medio->refreshModificado();
            } elseif (method_exists($medio, 'setModificado')) {
                $medio->setModificado(new \DateTime());
            }
        }
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection->add('traducir', $this->getRouterIdParameter() . '/traducir');
        $collection->add('carga', 'carga');
        $collection->add('ajaxcrear', 'ajaxcrear');
        // Rutas por defecto: list, create, edit, show, delete, export
    }
}
