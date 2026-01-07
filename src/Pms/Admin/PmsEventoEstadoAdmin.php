<?php

namespace App\Pms\Admin;

use App\Pms\Entity\PmsEventoEstado;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

class PmsEventoEstadoAdmin extends AbstractAdmin
{
    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('codigo', TextType::class, [
                'label' => 'Código interno',
            ])
            ->add('nombre', TextType::class, [
                'label' => 'Nombre visible',
            ])
            ->add('color', TextType::class, [
                'required' => false,
                'label' => 'Color (HEX)',
                'help' => 'Formato #RRGGBB (6 dígitos hexadecimales). Ejemplo: #FFB300',
                'attr' => [
                    'maxlength' => 7,
                    'pattern' => '^#[0-9A-Fa-f]{6}$',
                    'placeholder' => '#RRGGBB',
                ],
            ])
            ->add('codigoBeds24', TextType::class, [
                'required' => false,
                'label' => 'Código Beds24',
                'help' => 'Ej: confirmed, cancelled, pending, noshow',
            ])
            ->add('colorOverride', CheckboxType::class, [
                'required' => false,
                'label' => 'Forzar color del estado',
                'help' => 'Si está activo, el color del estado prevalece sobre el estado de pago',
            ])
            ->add('orden', IntegerType::class, [
                'required' => false,
                'label' => 'Orden',
            ]);
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('codigo')
            ->add('nombre')
            ->add('codigoBeds24');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('codigo')
            ->add('nombre', null, [
                'editable' => true,
            ])
            ->add('codigoBeds24', null, [
                'editable' => true,
            ])
            ->add('colorOverride', null, [
                'editable' => true,
            ])
            ->add('orden', null, [
                'editable' => true,
            ])
            ->add('color', null, [
                'editable' => true,
                'help' => '#RRGGBB',
                'attributes' => [
                    'maxlength' => 7,
                    'pattern' => '^#[0-9A-Fa-f]{6}$',
                ],
            ]);
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('id')
            ->add('codigo')
            ->add('nombre')
            ->add('color')
            ->add('codigoBeds24')
            ->add('colorOverride')
            ->add('orden')
            ->add('created', null, ['format' => 'Y-m-d H:i'])
            ->add('updated', null, ['format' => 'Y-m-d H:i']);
    }
}
