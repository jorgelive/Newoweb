<?php
namespace App\Oweb\Admin;

use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Sonata\AdminBundle\Show\ShowMapper;

final class MaestroContactoAdmin extends AbstractSecureAdmin
{
    public function getModulePrefix(): string
    {
        return 'MAESTROS';
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->with('Datos del Contacto', ['class' => 'col-md-6'])
                ->add('nombre', null, ['label' => 'Nombre'])
                ->add('tipodocumento', null, ['label' => 'Tipo de documento'])
                ->add('numerodocumento', null, ['label' => 'N° Documento'])
                ->add('telefono', null, ['label' => 'Teléfono'])
                ->add('tipocontacto', ModelType::class, [
                    'label'       => 'Tipo de contacto',
                    'required'    => true,
                    'property'    => 'nombre',
                    'btn_add'     => 'Agregar Tipo',                 // ← ahora sí funciona
                    'class'       => \App\Oweb\Entity\MaestroTipocontacto::class, // target de la relación
                    'placeholder' => '— seleccionar —',
                    // 'admin_code' => 'app.admin.maestrotipocontacto', // solo si necesitas forzar el admin asociado
                ])
            ->end()
            ->with('Datos del Vehículo', ['class' => 'col-md-6'])
                ->add('vehiculoplaca', null, ['label' => 'Placa'])
                ->add('vehiculomarca', null, ['label' => 'Marca'])
                ->add('vehiculomodelo', null, ['label' => 'Modelo'])
                ->add('vehiculocolor', null, ['label' => 'Color'])
                ->add('vehiculoanio', null, [
                    'label' => 'Año',
                    'attr' => ['min' => 1990, 'max' => date('Y') + 1],
                ])
            ->end()
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('nombre', null, ['label' => 'Nombre'])
            ->add('tipodocumento', null, ['label' => 'Tipo Documento'])
            ->add('numerodocumento', null, ['label' => 'N° Documento'])
            ->add('vehiculoplaca', null, ['label' => 'Placa'])
            ->add('tipocontacto', null, ['label' => 'Tipo de Contacto']);
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nombre', null, ['label' => 'Nombre'])
            ->add('tipodocumento', null, ['label' => 'Tipo Doc.'])
            ->add('numerodocumento', null, ['label' => 'N° Doc.'])
            ->add('telefono', null, ['label' => 'Teléfono'])
            ->add('vehiculoplaca', null, ['label' => 'Placa'])
            ->add('vehiculomarca', null, ['label' => 'Marca'])
            ->add('vehiculomodelo', null, ['label' => 'Modelo'])
            ->add('vehiculocolor', null, ['label' => 'Color'])
            ->add('vehiculoanio', null, ['label' => 'Año'])
            ->add('tipocontacto', null, ['label' => 'Tipo Contacto'])
            ->add('creado', null, ['label' => 'Creado'])
            ->add('modificado', null, ['label' => 'Modificado'])
            ->add('_action', null, [
                'actions' => [
                    'edit' => [],
                    'delete' => [],
                    'show' => [],
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->with('Datos del Contacto')
                ->add('nombre', null, ['label' => 'Nombre'])
                ->add('tipodocumento', null, ['label' => 'Tipo Documento'])
                ->add('numerodocumento', null, ['label' => 'N° Documento'])
                ->add('telefono', null, ['label' => 'Teléfono'])
                ->add('tipocontacto', null, ['label' => 'Tipo de Contacto'])
            ->end()
            ->with('Datos del Vehículo')
                ->add('vehiculoplaca', null, ['label' => 'Placa'])
                ->add('vehiculomarca', null, ['label' => 'Marca'])
                ->add('vehiculomodelo', null, ['label' => 'Modelo'])
                ->add('vehiculocolor', null, ['label' => 'Color'])
                ->add('vehiculoanio', null, ['label' => 'Año'])
            ->end()
            ->with('Trazabilidad')
                ->add('creado', null, ['label' => 'Creado'])
                ->add('modificado', null, ['label' => 'Modificado'])
            ->end();
    }
}
