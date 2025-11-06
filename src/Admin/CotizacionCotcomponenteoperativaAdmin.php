<?php
namespace App\Admin;

use App\Entity\CotizacionCotcomponenteoperativa;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Form\Type\ModelType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;

final class CotizacionCotcomponenteoperativaAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('horarecojoinicial', TimeType::class, [
                'label' => 'Hora Recojo (Inicial)',
                'widget' => 'single_text',
                'input' => 'datetime',
            ])
            ->add('horarecojofinal', TimeType::class, [
                'label' => 'Hora Recojo (Final)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime',
            ])
            ->add('tolerancia', null, [
                'label' => 'Tolerancia (min)',
                'required' => false,
            ])
            ->add('contacto', ModelType::class, [
                'label'       => 'Conductor / Contacto',
                'required'    => false,
                'btn_add'     => 'Agregar Contacto',                 // ← ahora sí funciona
                'class'       => \App\Entity\MaestroContacto::class, // target de la relación
                'placeholder' => '— seleccionar —',
                // 'admin_code' => 'app.admin.maestrocontacto', // solo si necesitas forzar el admin asociado
            ])
            ->add('notas', null, [
                'label' => 'Notas',
                'required' => false,
            ])
        ;
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('contacto', null, ['label' => 'Contacto'])
            ->add('horarecojoinicial', null, ['label' => 'Recojo inicial'])
            ->add('horarecojofinal', null, ['label' => 'Recojo final'])
            ->add('tolerancia', null, ['label' => 'Tolerancia (min)'])
        ;
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('contacto', null, ['label' => 'Conductor'])
            ->add('horarecojoinicial', null, ['label' => 'Recojo inicial'])
            ->add('horarecojofinal', null, ['label' => 'Recojo final'])
            ->add('tolerancia', null, ['label' => 'Tol. (min)'])
            ->add('creado', null, ['label' => 'Creado'])
            ->add('modificado', null, ['label' => 'Modificado'])
        ;
    }
}
